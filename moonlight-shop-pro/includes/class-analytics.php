<?php
/**
 * Pro 统计：近 7/30/90 天销售趋势（线图）、商品销量 Top 10、支付渠道占比。
 *
 * - 单页三块，全部服务端纯 SVG 渲染，零外部依赖；
 *   绘图 helper 改自 moonlight-shop/includes/class-statistics.php 的
 *   render_svg_line / render_svg_bar（该方法为 private 不可直接复用，
 *   绘图代码非业务逻辑，按 FREE-PRO 约定复制到 Pro 并注明来源）。
 * - 销售额口径：MLSHOP_Order::get_revenue_statuses()（paid 起全部已收款状态）。
 * - 订单聚合用 WP_Query 分页（每批 500）防 posts_per_page=-1 全量扫描；
 * - 按天聚合结果缓存到 option `mlpro_analytics_cache_{range}`，1 小时过期。
 *
 * @package Moonlight_Shop_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLPRO_Analytics
{
    /** 允许的时间范围（天）。 */
    const RANGES = array(7, 30, 90);
    /** 缓存时长（秒）。 */
    const CACHE_TTL = 3600;
    /** 每批扫描订单数（分页聚合防 -1 全量）。 */
    const BATCH = 500;

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
    }

    public function register_menu()
    {
        add_submenu_page(
            'mlpro-license',
            __('Pro 统计', 'moonlight-shop-pro'),
            __('Pro 统计', 'moonlight-shop-pro'),
            'manage_options',
            'mlpro-analytics',
            array($this, 'render_page')
        );
    }

    /* ==================== 数据 ==================== */

    /**
     * 时间范围白名单（7/30/90 天）。
     */
    private function parse_range()
    {
        $r = isset($_GET['range']) ? (int) $_GET['range'] : 30;
        return in_array($r, self::RANGES, true) ? $r : 30;
    }

    /**
     * 读取统计（option 缓存 1 小时，避免每次全量扫描）。
     */
    private function get_data($range)
    {
        $key    = 'mlpro_analytics_cache_' . (int) $range;
        $cached = get_option($key, array());
        if (is_array($cached) && isset($cached['ts'], $cached['data'])
            && (time() - (int) $cached['ts']) < self::CACHE_TTL) {
            return $cached['data'];
        }
        $data = $this->compute($range);
        update_option($key, array('ts' => time(), 'data' => $data));
        return $data;
    }

    /**
     * 扫描时间范围内的订单并按天 / 商品 / 网关聚合。
     */
    private function compute($range)
    {
        $range   = (int) $range;
        $now_ts  = current_time('timestamp');
        $from_ts = strtotime('-' . ($range - 1) . ' days', $now_ts);
        $from    = date('Y-m-d 00:00:00', $from_ts);
        $to      = date('Y-m-d 23:59:59', $now_ts);

        $revenue_statuses = MLSHOP_Order::get_revenue_statuses();
        $stati = array_map(function ($s) {
            return 'mlshop_' . $s;
        }, array_keys(MLSHOP_Order::get_status_labels()));

        // 趋势桶：范围内逐天初始化（缺失天补 0）。
        $trend = array();
        for ($i = 0; $i < $range; $i++) {
            $trend[date('Y-m-d', $from_ts + $i * DAY_IN_SECONDS)] = 0.0;
        }

        $products   = array(); // pid => qty
        $gateways   = array(); // gateway => 订单数
        $revenue    = 0.0;
        $paid_count = 0;
        $order_count = 0;

        // 分页聚合：每批 500，直到扫完 found_posts。
        $paged = 1;
        $found = 0;
        do {
            $q = new WP_Query(array(
                'post_type'              => 'mlshop_order',
                'post_status'            => $stati,
                'posts_per_page'         => self::BATCH,
                'paged'                  => $paged,
                'orderby'                => 'date',
                'order'                  => 'ASC',
                'date_query'             => array(array(
                    'after'     => $from,
                    'before'    => $to,
                    'inclusive' => true,
                )),
                'fields'                 => 'ids',
                'no_found_rows'          => false,
                'update_post_term_cache' => false,
            ));
            $found = (int) $q->found_posts;

            foreach ($q->posts as $oid) {
                $oid = (int) $oid;
                $order_count++;

                $st    = (string) get_post_meta($oid, '_mlshop_status', true);
                if ('' === $st) {
                    $st = 'pending';
                }
                $total = (float) get_post_meta($oid, '_mlshop_total', true);

                // 销售额口径：全部已收款状态（get_revenue_statuses）。
                if (in_array($st, $revenue_statuses, true)) {
                    $revenue    += $total;
                    $paid_count++;
                    $raw = get_post_field('post_date', $oid);
                    $day = $raw ? mysql2date('Y-m-d', $raw) : '';
                    if ($day && isset($trend[$day])) {
                        $trend[$day] += $total;
                    }
                }

                // 支付渠道：mark_paid 写 _mlshop_payment_gateway，缺失回退建单时的 _mlshop_gateway。
                $gw = (string) get_post_meta($oid, '_mlshop_payment_gateway', true);
                if ('' === $gw) {
                    $gw = (string) get_post_meta($oid, '_mlshop_gateway', true);
                }
                if ('' === $gw) {
                    $gw = 'unknown';
                }
                $gateways[$gw] = (isset($gateways[$gw]) ? $gateways[$gw] : 0) + 1;

                // 商品销量聚合。
                $items = get_post_meta($oid, '_mlshop_items', true);
                if (is_array($items)) {
                    foreach ($items as $it) {
                        if (!is_array($it)) {
                            continue;
                        }
                        $pid = isset($it['id']) ? (int) $it['id'] : 0;
                        $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
                        if ($pid && $qty > 0) {
                            $products[$pid] = (isset($products[$pid]) ? $products[$pid] : 0) + $qty;
                        }
                    }
                }
            }
            $paged++;
        } while ($q->have_posts() && ($paged - 1) * self::BATCH < $found);

        arsort($products);
        $top = array();
        $i   = 0;
        foreach ($products as $pid => $qty) {
            if ($i >= 10) {
                break;
            }
            $p = get_post($pid);
            if (!$p || 'mlshop_product' !== $p->post_type) {
                continue;
            }
            $top[] = array(
                'id'    => $pid,
                'title' => (string) $p->post_title,
                'qty'   => (int) $qty,
            );
            $i++;
        }

        arsort($gateways);

        return array(
            'from'        => date('Y-m-d', $from_ts),
            'to'          => date('Y-m-d', $now_ts),
            'revenue'     => $revenue,
            'order_count' => $order_count,
            'paid_count'  => $paid_count,
            'trend'       => $trend,
            'top'         => $top,
            'gateways'    => $gateways,
        );
    }

    /* ==================== 页面 ==================== */

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $range = $this->parse_range();
        $data  = $this->get_data($range);
        $base  = admin_url('admin.php?page=mlpro-analytics');

        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=mlpro_export_orders&from=' . rawurlencode($data['from']) . '&to=' . rawurlencode($data['to'])),
            'mlpro_export_orders'
        );
        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e('Moonlight Pro — 统计', 'moonlight-shop-pro'); ?>
                <span style="font-size:13px;color:#6b7280;font-weight:normal;">
                    <?php echo esc_html($data['from']); ?> → <?php echo esc_html($data['to']); ?>
                </span>
                <a href="<?php echo esc_url($export_url); ?>" class="button" style="margin-left:12px;">
                    <?php esc_html_e('导出订单 CSV（同范围）', 'moonlight-shop-pro'); ?>
                </a>
            </h1>

            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="mlpro-analytics">
                <label>
                    <?php esc_html_e('时间范围', 'moonlight-shop-pro'); ?>
                    <select name="range" onchange="this.form.submit();">
                        <?php foreach (self::RANGES as $r) : ?>
                            <option value="<?php echo (int) $r; ?>" <?php selected($range, $r); ?>>
                                <?php echo esc_html(sprintf(__('近 %d 天', 'moonlight-shop-pro'), $r)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e('应用', 'moonlight-shop-pro'); ?></button>
            </form>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:200px;">
                    <div style="color:#6b7280;font-size:12px;"><?php esc_html_e('销售额（已收款口径）', 'moonlight-shop-pro'); ?></div>
                    <div style="font-size:22px;font-weight:600;"><?php echo esc_html(mlshop_format_price($data['revenue'])); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:200px;">
                    <div style="color:#6b7280;font-size:12px;"><?php esc_html_e('订单数（全部状态）', 'moonlight-shop-pro'); ?></div>
                    <div style="font-size:22px;font-weight:600;"><?php echo esc_html(number_format_i18n($data['order_count'])); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:200px;">
                    <div style="color:#6b7280;font-size:12px;"><?php esc_html_e('已收款订单数', 'moonlight-shop-pro'); ?></div>
                    <div style="font-size:22px;font-weight:600;"><?php echo esc_html(number_format_i18n($data['paid_count'])); ?></div>
                </div>
            </div>

            <div class="card" style="max-width:980px;padding:8px 18px 18px;">
                <h2 style="margin:12px 0;"><?php esc_html_e('销售趋势（按日，已收款口径）', 'moonlight-shop-pro'); ?></h2>
                <?php echo $this->render_svg_line($data['trend']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG 内部已逐项转义 ?>
            </div>

            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin-top:16px;">
                <div class="card" style="flex:1;min-width:420px;max-width:560px;padding:8px 18px 18px;">
                    <h2 style="margin:12px 0;"><?php esc_html_e('商品销量 Top 10', 'moonlight-shop-pro'); ?></h2>
                    <?php if (!$data['top']) : ?>
                        <p class="description"><?php esc_html_e('时间范围内暂无销量数据。', 'moonlight-shop-pro'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:60%;"><?php esc_html_e('商品', 'moonlight-shop-pro'); ?></th>
                                    <th style="width:20%;" class="num"><?php esc_html_e('销量', 'moonlight-shop-pro'); ?></th>
                                    <th style="width:20%;" class="num"><?php esc_html_e('商品 ID', 'moonlight-shop-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data['top'] as $row) : ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>"><?php echo esc_html($row['title']); ?></a></td>
                                        <td class="num"><?php echo (int) $row['qty']; ?></td>
                                        <td class="num"><?php echo (int) $row['id']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="card" style="flex:1;min-width:420px;max-width:560px;padding:8px 18px 18px;">
                    <h2 style="margin:12px 0;"><?php esc_html_e('支付渠道占比（订单数）', 'moonlight-shop-pro'); ?></h2>
                    <?php echo $this->render_svg_bar($data['gateways']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG 内部已逐项转义 ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ==================== SVG 绘图 helper ====================
     * 以下两个方法改自 moonlight-shop/includes/class-statistics.php
     * 的 render_svg_line / render_svg_bar（private，故按 FREE-PRO 约定
     * 复制到 Pro；绘图代码非业务逻辑，class 前缀 / 渐变 ID 改为 mlpro- 防冲突）。
     */

    /**
     * 折线 SVG。$series 为 key(日期)=>value(销售额)。
     */
    private function render_svg_line($series)
    {
        if (!$series || !array_sum(array_map('floatval', $series))) {
            return '<p class="description">' . esc_html__('时间范围内无数据。', 'moonlight-shop-pro') . '</p>';
        }
        $vals   = array_map('floatval', array_values($series));
        $keys   = array_values(array_keys($series));
        $max    = max($vals);
        $max    = $max > 0 ? $max : 1; // 防 0
        $w      = 880;
        $h      = 220;
        $pad_x  = 48;
        $pad_y  = 28;
        $n      = max(2, count($keys));
        $step_x = ($w - 2 * $pad_x) / ($n - 1);

        $points = array();
        foreach ($keys as $i => $k) {
            $x = $pad_x + $i * $step_x;
            $y = $h - $pad_y - (($vals[$i] / $max) * ($h - 2 * $pad_y));
            $points[] = sprintf('%.2f,%.2f', $x, $y);
        }
        $poly = implode(' ', $points);

        $y0   = $h - $pad_y;
        $y1   = $pad_y + ($h - 2 * $pad_y) * 0.5;
        $y2   = $pad_y;
        $lbl0 = '0';
        $lbl1 = number_format($max * 0.5, 2);
        $lbl2 = number_format($max, 2);
        $x_last = $pad_x + ($n - 1) * $step_x;
        $x_mid  = $pad_x + ($n - 1) * $step_x * 0.5;
        $key_first = $keys[0];
        $key_last  = $keys[count($keys) - 1];
        $key_mid   = $keys[(int) floor(($n - 1) * 0.5)];

        $line_color = '#4f46e5';
        $grid_color = '#e4e9f0';
        $svg  = sprintf(
            '<svg viewBox="0 0 %1$d %2$d" class="mlpro-stat-chart" role="img" aria-label="%3$s" style="width:100%%;height:auto;">',
            $w, $h, esc_attr__('销售趋势', 'moonlight-shop-pro')
        );
        $svg .= '<g class="mlpro-stat-grid">';
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad_x, $y0, $w - $pad_x, $y0, $grid_color);
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad_x, $y1, $w - $pad_x, $y1, $grid_color);
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad_x, $y2, $w - $pad_x, $y2, $grid_color);
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>', $pad_x - 6, $y0, esc_html($lbl0));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>', $pad_x - 6, $y1, esc_html($lbl1));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>', $pad_x - 6, $y2, esc_html($lbl2));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', $pad_x, $h - 10, esc_html($key_first));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', (int) $x_mid, $h - 10, esc_html($key_mid));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', (int) $x_last, $h - 10, esc_html($key_last));
        $svg .= '</g>';
        $svg .= '<defs><linearGradient id="mlpro-stat-fill" x1="0" x2="0" y1="0" y2="1">'
              . '<stop offset="0%" stop-color="' . $line_color . '" stop-opacity="0.25"/>'
              . '<stop offset="100%" stop-color="' . $line_color . '" stop-opacity="0.02"/>'
              . '</linearGradient></defs>';
        $baseline    = $h - $pad_y;
        $polygon_pts = $pad_x . ',' . $baseline . ' ' . $poly . ' ' . $x_last . ',' . $baseline;
        $svg .= '<polygon points="' . esc_attr($polygon_pts) . '" fill="url(#mlpro-stat-fill)"/>';
        $svg .= '<polyline points="' . esc_attr($poly) . '" fill="none" stroke="' . $line_color . '" stroke-width="2"/>';
        foreach ($points as $p) {
            list($x, $y) = array_map('floatval', explode(',', $p));
            $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="3" fill="#fff" stroke="%s" stroke-width="2"/>', $x, $y, $line_color);
        }
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * 柱状 SVG（支付渠道占比）。$data 为 key(网关)=>value(订单数)。
     */
    private function render_svg_bar($data)
    {
        if (!$data) {
            return '<p class="description">' . esc_html__('时间范围内无数据。', 'moonlight-shop-pro') . '</p>';
        }
        $data = array_map('intval', $data);
        $total = array_sum($data);
        $max   = max(1, max($data));
        $w     = 460;
        $h     = 220;
        $pad_l = 8;
        $pad_r = 16;
        $pad_t = 16;
        $pad_b = 36;
        $gap   = 12;
        $count = count($data);
        $bw    = ($w - $pad_l - $pad_r - $gap * ($count - 1)) / $count;
        $inner_h = $h - $pad_t - $pad_b;

        $colors = array('#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#8b5cf6', '#14b8a6', '#ef4444', '#94a3b8');
        $svg = sprintf(
            '<svg viewBox="0 0 %d %d" class="mlpro-stat-chart mlpro-stat-bar" role="img" aria-label="%s" style="width:100%%;height:auto;">',
            $w, $h, esc_attr__('支付渠道占比', 'moonlight-shop-pro')
        );
        $i = 0;
        foreach ($data as $gw => $v) {
            $bar_h = ($v / $max) * $inner_h;
            $x = $pad_l + $i * ($bw + $gap);
            $y = $pad_t + ($inner_h - $bar_h);
            $color = $colors[$i % count($colors)];
            $svg .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="4" fill="%s"/>', $x, $y, $bw, $bar_h, $color);
            $svg .= sprintf('<text x="%.2f" y="%.2f" font-size="11" fill="#374151" text-anchor="middle">%d</text>', $x + $bw / 2, max($y - 4, $pad_t + 10), $v);
            $pct = $total > 0 ? round($v / $total * 100, 1) : 0;
            $svg .= sprintf('<text x="%.2f" y="%.2f" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', $x + $bw / 2, $h - 14, esc_html($gw . ' ' . $pct . '%'));
            $i++;
        }
        $svg .= '</svg>';
        return $svg;
    }
}
