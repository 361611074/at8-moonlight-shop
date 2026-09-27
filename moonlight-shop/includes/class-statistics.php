<?php
/**
 * 商城订单统计：销售额、订单数、客单价、热销商品、按日/按月走势。
 *
 * 子菜单挂在 edit.php?post_type=mlshop_product 下，「商城設定」上方，命名 mlshop-statistics。
 *
 * 数据来源：CPT mlshop_order + post_meta _mlshop_status / _mlshop_total / _mlshop_items / _mlshop_user_id。
 * 不依赖第三方图表库；折线/柱状图由服务端纯 SVG 渲染，零外部请求。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Statistics
{
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    public function add_menu()
    {
        add_submenu_page(
            'edit.php?post_type=mlshop_product',
            __('订单统计', 'moonlight-shop'),
            __('订单统计', 'moonlight-shop'),
            'manage_options',
            'mlshop-statistics',
            array($this, 'render_page')
        );
    }

    public function enqueue($hook)
    {
        // 仅当访问本插件的统计页时加载；避免污染其他后台页
        if ($hook !== 'mlshop_product_page_mlshop-statistics') {
            return;
        }
        wp_enqueue_style(
            'mlshop-settings',
            MLSHOP_PLUGIN_URL . 'assets/css/mlshop-settings.css',
            array(),
            MLSHOP_VERSION
        );
        wp_enqueue_style(
            'mlshop-statistics',
            MLSHOP_PLUGIN_URL . 'assets/css/mlshop-statistics.css',
            array('mlshop-settings'),
            MLSHOP_VERSION
        );
    }

    /**
     * 解析当前请求的统计时间窗。
     */
    private function parse_range()
    {
        $preset = isset($_GET['preset']) ? sanitize_text_field(wp_unslash($_GET['preset'])) : 'this_month';
        $allowed = array('today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_30', 'custom');
        if (!in_array($preset, $allowed, true)) {
            $preset = 'this_month';
        }
        $from = isset($_GET['from']) ? preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '' : '';
        $to   = isset($_GET['to'])   ? preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : '' : '';
        $now  = current_time('timestamp');
        switch ($preset) {
            case 'today':
                $f = $t = date('Y-m-d', $now); break;
            case 'yesterday':
                $f = $t = date('Y-m-d', $now - 86400); break;
            case 'this_week':
                $f = date('Y-m-d', strtotime('monday this week', $now));
                $t = date('Y-m-d', $now); break;
            case 'last_week':
                $f = date('Y-m-d', strtotime('monday last week', $now));
                $t = date('Y-m-d', strtotime('sunday last week', $now)); break;
            case 'last_month':
                $f = date('Y-m-d', strtotime('first day of last month', $now));
                $t = date('Y-m-d', strtotime('last day of last month', $now)); break;
            case 'last_30':
                $f = date('Y-m-d', $now - 29 * 86400);
                $t = date('Y-m-d', $now); break;
            case 'custom':
                $f = $from ?: date('Y-m-d', strtotime('first day of this month', $now));
                $t = $to   ?: date('Y-m-d', $now);
                if (strtotime($t) < strtotime($f)) { $t = $f; }
                break;
            case 'this_month':
            default:
                $f = date('Y-m-d', strtotime('first day of this month', $now));
                $t = date('Y-m-d', $now);
                break;
        }
        return array('preset' => $preset, 'from' => $f, 'to' => $t);
    }

    /**
     * 计算统计指标，时间窗内订单聚合。
     */
    private function compute($range)
    {
        $from_ts = strtotime($range['from'] . ' 00:00:00');
        $to_ts   = strtotime($range['to']   . ' 23:59:59');

        // WP_Query 拉订单 ID 列表（不在循环里 COUNT，无 LIMIT）
        $order_post_stati = array_map(function ($k) { return 'mlshop_' . $k; }, array_keys(MLSHOP_Order::get_status_labels()));
        $ids = get_posts(array(
            'post_type'      => 'mlshop_order',
            'post_status'    => $order_post_stati,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'date_query'     => array(
                array(
                    'after'     => date('Y-m-d H:i:s', $from_ts),
                    'before'    => date('Y-m-d H:i:s', $to_ts),
                    'inclusive' => true,
                ),
            ),
            'no_found_rows'  => true,
        ));

        // 桶：天/状态/商品（状态桶 = 全部注册状态，键序即分布图展示顺序）
        $daily    = array();
        $statuses = array_fill_keys(array_keys(MLSHOP_Order::get_status_labels()), 0);
        $products = array();
        $user_set = array();

        $revenue      = 0.0; // 已收款状态合计（paid 起，见 get_revenue_statuses）
        $paid_count   = 0;  // 计入销售额的订单数
        $refund_total = 0.0;

        $revenue_statuses = MLSHOP_Order::get_revenue_statuses();

        foreach ($ids as $oid) {
            $oid = (int) $oid;
            $st  = (string) get_post_meta($oid, '_mlshop_status', true);
            if (!$st) { $st = 'pending'; }
            $total = (float) get_post_meta($oid, '_mlshop_total', true);
            $raw_date = get_post_field('post_date', $oid);
            $day = $raw_date ? mysql2date('Y-m-d', $raw_date) : '';
            $uid = (int) get_post_meta($oid, '_mlshop_user_id', true);

            $statuses[$st] = (isset($statuses[$st]) ? $statuses[$st] : 0) + 1;

            // 物流第二批：销售额口径扩为全部已收款状态（paid/processing/待发货/已发货/已签收/完成）
            $in_revenue = in_array($st, $revenue_statuses, true);
            if ($in_revenue) {
                $revenue += $total;
                $paid_count++;
            }
            if ('refunded' === $st) {
                $refund_total += $total;
            }

            if ($day) {
                $daily[$day] = (isset($daily[$day]) ? $daily[$day] : 0.0) + ($in_revenue ? $total : 0.0);
            }
            if ($uid) {
                $user_set[$uid] = true;
            }

            $items = get_post_meta($oid, '_mlshop_items', true);
            if (is_array($items)) {
                foreach ($items as $it) {
                    $pid = isset($it['id'])  ? (int) $it['id']  : 0;
                    $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
                    if ($pid && $qty > 0) {
                        $products[$pid] = (isset($products[$pid]) ? $products[$pid] : 0) + $qty;
                    }
                }
            }
        }

        // 排序商品销量 Top
        arsort($products);
        $top = array();
        $i = 0;
        foreach ($products as $pid => $qty) {
            if ($i >= 10) { break; }
            $p = get_post($pid);
            if (!$p || $p->post_type !== 'mlshop_product') { continue; }
            $top[] = array(
                'id'     => (int) $pid,
                'title'  => $p->post_title,
                'qty'    => (int) $qty,
                'price'  => (float) get_post_meta($pid, '_mlshop_price', true),
                'revenue'=> (float) $qty * (float) get_post_meta($pid, '_mlshop_price', true),
            );
            $i++;
        }

        // 客单价：revenue / paid_count
        $aov = $paid_count > 0 ? ($revenue / $paid_count) : 0.0;

        // 趋势桶：按时间窗长度决定粒度
        $days = (int) round(($to_ts - $from_ts) / 86400) + 1;
        $granularity = ($days <= 31) ? 'day' : 'month';
        $trend = $this->bucketize($daily, $from_ts, $to_ts, $granularity);

        return array(
            'range'         => $range,
            'order_count'   => count($ids),
            'paid_count'    => $paid_count,
            'revenue'       => $revenue,
            'refund_total'  => $refund_total,
            'aov'           => $aov,
            'user_count'    => count($user_set),
            'statuses'      => $statuses,
            'top'           => $top,
            'trend'         => $trend,
            'granularity'   => $granularity,
        );
    }

    /**
     * 将 daily 桶（day→revenue）按粒度（月或日）展开成连续时间序列，缺失补 0。
     */
    private function bucketize($daily, $from_ts, $to_ts, $granularity)
    {
        $out = array();
        if ('month' === $granularity) {
            $cur = strtotime(date('Y-m-01', $from_ts));
            $end = strtotime(date('Y-m-01', $to_ts));
            while ($cur <= $end) {
                $key = date('Y-m', $cur);
                $sum = 0.0;
                foreach ($daily as $d => $v) {
                    if (substr($d, 0, 7) === $key) { $sum += $v; }
                }
                $out[$key] = $sum;
                $cur = strtotime('+1 month', $cur);
            }
        } else {
            $cur = $from_ts;
            while ($cur <= $to_ts) {
                $key = date('Y-m-d', $cur);
                $out[$key] = isset($daily[$key]) ? (float) $daily[$key] : 0.0;
                $cur = strtotime('+1 day', $cur);
            }
        }
        return $out;
    }

    /**
     * 渲染整页。
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $range  = $this->parse_range();
        $stats  = $this->compute($range);
        $periods = array(
            'today'       => __('今天', 'moonlight-shop'),
            'yesterday'   => __('昨天', 'moonlight-shop'),
            'this_week'   => __('本周', 'moonlight-shop'),
            'last_week'   => __('上周', 'moonlight-shop'),
            'this_month'  => __('本月', 'moonlight-shop'),
            'last_month'  => __('上月', 'moonlight-shop'),
            'last_30'     => __('最近 30 天', 'moonlight-shop'),
            'custom'      => __('自定义', 'moonlight-shop'),
        );
        ?>
        <div class="wrap mlshop-statistics-page">
            <h1 class="mlshop-page-title">
                <?php esc_html_e('订单统计', 'moonlight-shop'); ?>
                <span class="mlshop-page-sub">
                    <?php echo esc_html($range['from']); ?> &nbsp;→&nbsp; <?php echo esc_html($range['to']); ?>
                </span>
            </h1>

            <div class="mlshop-card mlshop-stat-filters">
                <form method="get" class="mlshop-stat-presets">
                    <input type="hidden" name="post_type" value="mlshop_product">
                    <input type="hidden" name="page" value="mlshop-statistics">
                    <?php foreach ($periods as $k => $label) :
                        $active = ($range['preset'] === $k);
                        $url = add_query_arg(array('preset' => $k), admin_url('edit.php?post_type=mlshop_product&page=mlshop-statistics'));
                        ?>
                        <a href="<?php echo esc_url($url); ?>" class="mlshop-preset<?php echo $active ? ' is-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                    <span class="mlshop-custom-range">
                        <label><?php esc_html_e('从', 'moonlight-shop'); ?>
                            <input type="date" name="from" value="<?php echo esc_attr($range['from']); ?>" class="regular-text" style="min-width:160px;">
                        </label>
                        <label><?php esc_html_e('到', 'moonlight-shop'); ?>
                            <input type="date" name="to" value="<?php echo esc_attr($range['to']); ?>" class="regular-text" style="min-width:160px;">
                        </label>
                        <input type="hidden" name="preset" value="custom">
                        <button type="submit" class="button button-primary"><?php esc_html_e('应用范围', 'moonlight-shop'); ?></button>
                    </span>
                </form>
            </div>

            <div class="mlshop-stat-cards">
                <?php
                $cards = array(
                    array('label' => __('总销售额', 'moonlight-shop'),   'value' => mlshop_format_price($stats['revenue']),       'sub' => sprintf(__('（全部已收款状态：已付款→完成）', 'moonlight-shop'))),
                    array('label' => __('订单总数', 'moonlight-shop'),   'value' => number_format_i18n($stats['order_count']),    'sub' => sprintf(__('（含未付款/已取消）', 'moonlight-shop'))),
                    array('label' => __('已付款订单', 'moonlight-shop'), 'value' => number_format_i18n($stats['paid_count']),     'sub' => sprintf(__('客户数 %d', 'moonlight-shop'), $stats['user_count'])),
                    array('label' => __('客单价', 'moonlight-shop'),     'value' => mlshop_format_price($stats['aov']),           'sub' => sprintf(__('（AOV = 销售额 / 已付款订单）', 'moonlight-shop'))),
                );
                foreach ($cards as $c) : ?>
                    <div class="mlshop-stat-card">
                        <div class="mlshop-stat-card-label"><?php echo esc_html($c['label']); ?></div>
                        <div class="mlshop-stat-card-value"><?php echo esc_html($c['value']); ?></div>
                        <div class="mlshop-stat-card-sub"><?php echo esc_html($c['sub']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mlshop-card">
                <h2 class="mlshop-card-title">
                    <?php echo esc_html('month' === $stats['granularity']
                        ? __('销售额走势（按月）', 'moonlight-shop')
                        : __('销售额走势（按日）', 'moonlight-shop')); ?>
                </h2>
                <?php echo $this->render_svg_line($stats['trend']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if ($stats['refund_total'] > 0) : ?>
                    <p class="description">
                        <?php echo esc_html(sprintf(__('退款合计：%s', 'moonlight-shop'), mlshop_format_price($stats['refund_total']))); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="mlshop-stat-grid">
                <div class="mlshop-card">
                    <h2 class="mlshop-card-title"><?php esc_html_e('订单状态分布', 'moonlight-shop'); ?></h2>
                    <?php echo $this->render_svg_bar($stats['statuses']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
                <div class="mlshop-card">
                    <h2 class="mlshop-card-title"><?php esc_html_e('热销商品 Top 10', 'moonlight-shop'); ?></h2>
                    <?php if (!$stats['top']) : ?>
                        <p class="description"><?php esc_html_e('时间窗内暂无销量数据。', 'moonlight-shop'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped mlshop-stat-top">
                            <thead>
                                <tr>
                                    <th style="width:60%;"><?php esc_html_e('商品', 'moonlight-shop'); ?></th>
                                    <th style="width:20%;" class="num"><?php esc_html_e('销量', 'moonlight-shop'); ?></th>
                                    <th style="width:20%;" class="num"><?php esc_html_e('销售额', 'moonlight-shop'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['top'] as $row) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url(get_permalink($row['id'])); ?>" target="_blank">
                                                <?php echo esc_html($row['title']); ?>
                                            </a>
                                        </td>
                                        <td class="num"><?php echo (int) $row['qty']; ?></td>
                                        <td class="num"><?php echo esc_html(mlshop_format_price($row['revenue'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染折线/柱线 SVG。$series 为 key(string)=>value(float)。
     */
    private function render_svg_line($series)
    {
        if (!$series) {
            return '<p class="description">' . esc_html__('时间窗内无数据。', 'moonlight-shop') . '</p>';
        }
        $vals   = array_values($series);
        $keys   = array_keys($series);
        $max    = max($vals);
        $max    = $max > 0 ? $max : 1; // 防 0
        $w      = 880;
        $h      = 220;
        $pad_x  = 32;
        $pad_y  = 28;
        $n      = count($keys);
        if ($n < 2) { $n = 2; }
        $step_x = ($w - 2 * $pad_x) / ($n - 1);

        $points = array();
        foreach ($keys as $i => $k) {
            $x = $pad_x + $i * $step_x;
            $y = $h - $pad_y - (($vals[$i] / $max) * ($h - 2 * $pad_y));
            $points[] = sprintf('%.2f,%.2f', $x, $y);
        }
        $poly = implode(' ', $points);

        // 简易坐标标签：Y 轴最大值/一半/0；X 轴头尾+中
        $y0   = $h - $pad_y;
        $y1   = $pad_y + ($h - 2 * $pad_y) * 0.5;
        $y2   = $pad_y;
        $lbl0 = number_format(0);
        $lbl1 = $max > 0 ? number_format($max * 0.5, 2) : '0';
        $lbl2 = $max > 0 ? number_format($max, 2) : '0';
        $x_first = $pad_x;
        $x_last  = $pad_x + ($n - 1) * $step_x;
        $x_mid   = $pad_x + ($n - 1) * $step_x * 0.5;
        $key_first = $keys[0];
        $key_last  = end($keys);
        $key_mid   = $keys[(int) floor(($n - 1) * 0.5)];

        $line_color = '#4f46e5';
        $grid_color = '#e4e9f0';
        $svg  = sprintf(
            '<svg viewBox="0 0 %1$d %2$d" class="mlshop-stat-chart" role="img" aria-label="%3$s">',
            $w, $h, esc_attr__('销售额走势', 'moonlight-shop')
        );
        // 网格
        $svg .= '<g class="mlshop-stat-grid">';
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad_x, $y0, $w - $pad_x, $y0, $grid_color);
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad_x, $y1, $w - $pad_x, $y1, $grid_color);
        $svg .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad_x, $y2, $w - $pad_x, $y2, $grid_color);
        // Y 轴标签
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>', $pad_x - 6, $y0, esc_html($lbl0));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>', $pad_x - 6, $y1, esc_html($lbl1));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>', $pad_x - 6, $y2, esc_html($lbl2));
        // X 轴标签（仅头/中/尾，避免拥挤）
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', $x_first, $h - 10, esc_html($key_first));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', $x_mid, $h - 10, esc_html($key_mid));
        $svg .= sprintf('<text x="%d" y="%d" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', $x_last, $h - 10, esc_html($key_last));
        $svg .= '</g>';
        // 折线 + 渐变填充（闭合多边形：起点(左下) + 折线点 + 终点(右下)）
        $svg .= '<defs><linearGradient id="mlshop-stat-fill" x1="0" x2="0" y1="0" y2="1">'
              . '<stop offset="0%" stop-color="' . $line_color . '" stop-opacity="0.25"/>'
              . '<stop offset="100%" stop-color="' . $line_color . '" stop-opacity="0.02"/>'
              . '</linearGradient></defs>';
        $baseline = $h - $pad_y;
        $right_x  = $pad_x + ($n - 1) * $step_x;
        $polygon_pts = $pad_x . ',' . $baseline . ' ' . $poly . ' ' . $right_x . ',' . $baseline;
        $svg .= '<polygon points="' . esc_attr($polygon_pts) . '" fill="url(#mlshop-stat-fill)"/>';
        $svg .= '<polyline points="' . esc_attr($poly) . '" fill="none" stroke="' . $line_color . '" stroke-width="2"/>';
        // 数据点
        foreach ($points as $p) {
            list($x, $y) = array_map('floatval', explode(',', $p));
            $svg .= sprintf('<circle cx="%.2f" cy="%.2f" r="3" fill="#fff" stroke="%s" stroke-width="2"/>', $x, $y, $line_color);
        }
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * 渲染柱状 SVG（订单状态分布）。
     *
     * 物流第二批：标签委托 MLSHOP_Order::get_status_label（单一来源），
     * 并为 awaiting_shipment / shipped / delivered 补充配色。
     */
    private function render_svg_bar($statuses)
    {
        $labels = array();
        $colors = array(
            'pending'           => '#94a3b8',
            'paid'              => '#10b981',
            'processing'        => '#0ea5e9',
            'awaiting_shipment' => '#f59e0b',
            'shipped'           => '#8b5cf6',
            'delivered'         => '#14b8a6',
            'completed'         => '#4f46e5',
            'failed'            => '#f97316',
            'refunded'          => '#eab308',
            'cancelled'         => '#ef4444',
        );
        foreach (array_keys((array) $statuses) as $k) {
            $labels[$k] = MLSHOP_Order::get_status_label($k);
            if (!isset($colors[$k])) {
                $colors[$k] = '#94a3b8'; // 未知状态兜底灰
            }
        }
        $max = max(1, max($statuses));
        $w   = 460;
        $h   = 220;
        $pad_l = 8;
        $pad_r = 16;
        $pad_t = 16;
        $pad_b = 36;
        $gap   = 12;
        $bw    = ($w - $pad_l - $pad_r - $gap * (count($statuses) - 1)) / count($statuses);
        $inner_h = $h - $pad_t - $pad_b;

        $svg  = sprintf('<svg viewBox="0 0 %d %d" class="mlshop-stat-chart mlshop-stat-bar" role="img" aria-label="%s">', $w, $h, esc_attr__('订单状态分布', 'moonlight-shop'));
        $i = 0;
        foreach ($statuses as $k => $v) {
            $bar_h = ($v / $max) * $inner_h;
            $x = $pad_l + $i * ($bw + $gap);
            $y = $pad_t + ($inner_h - $bar_h);
            $svg .= sprintf('<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="4" fill="%s"/>', $x, $y, $bw, $bar_h, $colors[$k]);
            // 顶部数值
            $svg .= sprintf('<text x="%.2f" y="%.2f" font-size="11" fill="#374151" text-anchor="middle">%d</text>', $x + $bw / 2, max($y - 4, $pad_t + 10), $v);
            // 底部状态名
            $svg .= sprintf('<text x="%.2f" y="%.2f" font-size="11" fill="#6b7280" text-anchor="middle">%s</text>', $x + $bw / 2, $h - 14, esc_html($labels[$k]));
            $i++;
        }
        $svg .= '</svg>';
        return $svg;
    }
}
