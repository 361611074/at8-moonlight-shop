<?php
/**
 * 后台商城仪表盘（Phase 9）：运营概览卡片 + 卡密库存预警表。
 *
 * 子菜单挂在 edit.php?post_type=mlshop_product 下（与「卡密库存」「订单统计」同级），
 * 并重排为商城菜单第一项——顶级菜单点击直达仪表盘（WooCommerce 同款模式）。
 *
 * 数据口径（全部复用现有聚合口径，不新增存储、不写 meta）：
 * - 今日 / 本月销售额 = MLSHOP_Order::get_revenue_statuses() 全部已收款状态的
 *   _mlshop_total 合计（当日 / 当月按订单 post_date 过滤，分批查询 ≤500/批，
 *   对齐 Pro 统计 MLPRO_Analytics::BATCH 的分页聚合模式）；
 * - 订单总数 / 待发货（awaiting_shipment）：全量订单分批扫描（≤500/批）；
 * - 待处理售后 = 最近 200 单内 _mlshop_refund_requests 含 status=pending 的订单数
 *   （环形申请列表见 Moonlight_Refund_Service::META_REQUESTS）；
 * - 商品数 = wp_count_posts('mlshop_product')->publish；
 * - 会员数 = usermeta mluc_membership_level 非空的用户数（$wpdb 单条 COUNT DISTINCT）；
 * - 库存预警表 = 卡密商品 Moonlight_Card_Stock::available() < 10（最多遍历 200 个卡密商品）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Dashboard
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
        // 优先级 9：早于统计页 / 设置页（默认 10）注册，紧跟「全部商品」之后。
        add_action('admin_menu', array($this, 'add_menu'), 9);
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * 注册「仪表盘」子菜单，并把它重排为商城菜单第一项。
     *
     * 重排后 $submenu 第一项 = 顶级菜单点击落点，即商城后台默认首页；
     * 「全部商品」退居其后（与 WooCommerce「首页」子菜单同一模式）。
     */
    public function add_menu()
    {
        add_submenu_page(
            'edit.php?post_type=mlshop_product',
            __('商城仪表盘', 'at8-moonlight-shop'),
            __('商城仪表盘', 'at8-moonlight-shop'),
            'manage_options',
            'mlshop-dashboard',
            array($this, 'render_page')
        );
        add_action('admin_menu', array($this, 'reorder_submenu'), 999);
    }

    /**
     * 把 mlshop-dashboard 提到商城子菜单首位（成为顶级菜单默认落点）。
     */
    public function reorder_submenu()
    {
        global $submenu;
        $key = 'edit.php?post_type=mlshop_product';
        if (!isset($submenu[$key]) || !is_array($submenu[$key])) {
            return;
        }
        foreach ($submenu[$key] as $i => $item) {
            if (is_array($item) && isset($item[2]) && 'mlshop-dashboard' === $item[2]) {
                unset($submenu[$key][$i]);
                array_unshift($submenu[$key], $item);
                return;
            }
        }
    }

    /**
     * 仪表盘页加载既有后台样式（设置页卡片 + 统计页指标卡），零新增静态资源。
     */
    public function enqueue($hook)
    {
        if ('mlshop_product_page_mlshop-dashboard' !== $hook) {
            return;
        }
        wp_enqueue_style('mlshop-settings', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-settings.css', array(), MLSHOP_VERSION);
        wp_enqueue_style('mlshop-statistics', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-statistics.css', array('mlshop-settings'), MLSHOP_VERSION);
    }

    /* ==================== 数据聚合 ==================== */

    /**
     * 分批拉取订单 ID（≤500/批，do-while 翻页，防 posts_per_page=-1 全量查询）。
     *
     * @param array $args post_status / date_query 等查询参数。
     * @param callable $row_cb 每个订单 ID 的回调（return false 可提前终止扫描）。
     * @return int 扫描的订单总数。
     */
    private function each_order_id($args, $row_cb)
    {
        $args = array_merge(array(
            'post_type'              => 'mlshop_order',
            'posts_per_page'         => 500,
            'paged'                  => 1,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ), $args);

        $scanned = 0;
        $batch   = 0;
        $paged   = 1;
        do {
            $args['paged'] = $paged;
            $q = new WP_Query($args);
            $batch = count($q->posts);
            foreach ($q->posts as $oid) {
                $scanned++;
                if (false === call_user_func($row_cb, (int) $oid)) {
                    return $scanned;
                }
            }
            $paged++;
        } while ($batch === 500);
        return $scanned;
    }

    /**
     * 时间窗内已收款销售额（口径 = get_revenue_statuses，post_date 过滤，分批 ≤500）。
     *
     * @param string $from 'Y-m-d 00:00:00'（站点本地时间，与统计页 current_time 口径一致）
     * @param string $to   'Y-m-d 23:59:59'
     * @return float
     */
    private function sum_revenue($from, $to)
    {
        $stati = array_map(function ($s) {
            return 'mlshop_' . $s;
        }, MLSHOP_Order::get_revenue_statuses());

        $revenue = 0.0;
        $this->each_order_id(array(
            'post_status' => $stati,
            'order'       => 'ASC',
            'date_query'  => array(array('after' => $from, 'before' => $to, 'inclusive' => true)),
        ), function ($oid) use (&$revenue) {
            $revenue += (float) get_post_meta($oid, '_mlshop_total', true);
            return true;
        });
        return $revenue;
    }

    /**
     * 全量订单概览：订单总数、待发货数、待处理售后数（售后只扫最近 200 单）。
     *
     * @return array{total:int, awaiting_shipment:int, pending_refunds:int}
     */
    private function scan_orders()
    {
        $stati = array_map(function ($s) {
            return 'mlshop_' . $s;
        }, array_keys(MLSHOP_Order::get_status_labels()));

        $total = 0;
        $awaiting = 0;
        $pending_refunds = 0;
        $refund_scanned = 0;

        $this->each_order_id(array('post_status' => $stati), function ($oid) use (&$total, &$awaiting, &$pending_refunds, &$refund_scanned) {
            $total++;
            // 待处理售后：仅扫描最近 200 单的售后申请列表（Limit 200），防全量 meta 读。
            if ($refund_scanned < 200) {
                $refund_scanned++;
                $reqs = get_post_meta($oid, '_mlshop_refund_requests', true);
                if (is_array($reqs)) {
                    foreach ($reqs as $r) {
                        if (is_array($r) && isset($r['status']) && 'pending' === $r['status']) {
                            $pending_refunds++;
                            break;
                        }
                    }
                }
            }
            // 待发货：以单一来源状态 meta 判定（与统计页同口径）。
            $st = (string) get_post_meta($oid, '_mlshop_status', true);
            if ('' === $st) {
                $st = 'pending';
            }
            if ('awaiting_shipment' === $st) {
                $awaiting++;
            }
            return true;
        });

        return array(
            'total'             => $total,
            'awaiting_shipment' => $awaiting,
            'pending_refunds'   => $pending_refunds,
        );
    }

    /**
     * 会员数：usermeta mluc_membership_level 非空的用户数（$wpdb 单条 COUNT）。
     */
    private function count_members()
    {
        global $wpdb;
        $n = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'mluc_membership_level' AND meta_value <> ''"
        );
        return (int) $n;
    }

    /**
     * 卡密库存预警：available < 10 的卡密商品列表（最多遍历 200 个卡密商品）。
     *
     * @return array[] 每行 id / title / available
     */
    private function low_stock_products()
    {
        if (!class_exists('Moonlight_Card_Stock')) {
            return array();
        }
        $threshold = (int) apply_filters('moonlight_card_stock_alert_threshold', 10);
        $max_scan  = 200;

        $rows = array();
        $scanned = 0;
        $paged = 1;
        do {
            $ids = get_posts(array(
                'post_type'      => 'mlshop_product',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'paged'          => $paged,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
                // 卡密商品按类型 meta 过滤；数量大时分批（≤100/批）扫描。
                'meta_query'     => array(array('key' => '_mlshop_type', 'value' => 'cardkey')),
            ));
            $batch = count($ids);
            foreach ($ids as $pid) {
                $scanned++;
                $pid = (int) $pid;
                $available = (int) Moonlight_Card_Stock::available($pid);
                if ($available < $threshold) {
                    $title = get_the_title($pid);
                    $rows[] = array(
                        'id'        => $pid,
                        'title'     => $title ? $title : ('#' . $pid),
                        'available' => $available,
                    );
                }
                if ($scanned >= $max_scan) {
                    return $rows;
                }
            }
            $paged++;
        } while ($batch === 100);
        return $rows;
    }

    /**
     * 聚合全部指标（一次渲染只算一遍）。
     */
    private function compute()
    {
        $now_ts = current_time('timestamp');
        return array(
            'today_revenue' => $this->sum_revenue(wp_date('Y-m-d 00:00:00', $now_ts), wp_date('Y-m-d 23:59:59', $now_ts)),
            'month_revenue' => $this->sum_revenue(wp_date('Y-m-01 00:00:00', $now_ts), wp_date('Y-m-d 23:59:59', $now_ts)),
            'orders'        => $this->scan_orders(),
            'product_count' => $this->count_products(),
            'member_count'  => $this->count_members(),
            'low_stock'     => $this->low_stock_products(),
        );
    }

    /**
     * 已发布商品数（wp_count_posts 单查询，无自定义状态噪声）。
     */
    private function count_products()
    {
        $counts = wp_count_posts('mlshop_product');
        return ($counts && isset($counts->publish)) ? (int) $counts->publish : 0;
    }

    /* ==================== 渲染 ==================== */

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $data  = $this->compute();
        $cards = array(
            array(
                'label' => __('今日销售额', 'at8-moonlight-shop'),
                'value' => mlshop_format_price($data['today_revenue']),
                'sub'   => __('（已收款状态口径，与订单统计一致）', 'at8-moonlight-shop'),
            ),
            array(
                'label' => __('本月销售额', 'at8-moonlight-shop'),
                'value' => mlshop_format_price($data['month_revenue']),
                'sub'   => __('（本月 1 日起，已收款状态口径）', 'at8-moonlight-shop'),
            ),
            array(
                'label' => __('订单总数', 'at8-moonlight-shop'),
                'value' => number_format_i18n($data['orders']['total']),
                'sub'   => __('（含未付款 / 已取消）', 'at8-moonlight-shop'),
            ),
            array(
                'label' => __('待发货订单', 'at8-moonlight-shop'),
                'value' => number_format_i18n($data['orders']['awaiting_shipment']),
                'sub'   => $data['orders']['awaiting_shipment'] > 0
                    ? __('（有实物订单等待发货，请及时处理）', 'at8-moonlight-shop')
                    : __('（暂无待发货）', 'at8-moonlight-shop'),
            ),
            array(
                'label' => __('待处理售后', 'at8-moonlight-shop'),
                'value' => number_format_i18n($data['orders']['pending_refunds']),
                'sub'   => __('（最近 200 单内 status=pending 的售后申请）', 'at8-moonlight-shop'),
            ),
            array(
                'label' => __('商品数', 'at8-moonlight-shop'),
                'value' => number_format_i18n($data['product_count']),
                'sub'   => __('（已发布商品）', 'at8-moonlight-shop'),
            ),
            array(
                'label' => __('会员数', 'at8-moonlight-shop'),
                'value' => number_format_i18n($data['member_count']),
                'sub'   => __('（拥有会员等级的用户）', 'at8-moonlight-shop'),
            ),
        );
        ?>
        <div class="wrap mlshop-statistics-page">
            <h1 class="mlshop-page-title"><?php esc_html_e('商城仪表盘', 'at8-moonlight-shop'); ?></h1>

            <div class="mlshop-stat-cards">
                <?php foreach ($cards as $c) : ?>
                    <div class="mlshop-stat-card">
                        <div class="mlshop-stat-card-label"><?php echo esc_html($c['label']); ?></div>
                        <div class="mlshop-stat-card-value"><?php echo esc_html($c['value']); ?></div>
                        <div class="mlshop-stat-card-sub"><?php echo esc_html($c['sub']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mlshop-card">
                <h2 class="mlshop-card-title"><?php esc_html_e('卡密库存预警（可售 < 10）', 'at8-moonlight-shop'); ?></h2>
                <?php if (empty($data['low_stock'])) : ?>
                    <p class="description"><?php esc_html_e('所有卡密商品库存充足。', 'at8-moonlight-shop'); ?></p>
                <?php else : ?>
                    <table class="widefat striped mlshop-stat-top">
                        <thead>
                            <tr>
                                <th style="width:50%;"><?php esc_html_e('商品', 'at8-moonlight-shop'); ?></th>
                                <th style="width:20%;" class="num"><?php esc_html_e('可售卡密', 'at8-moonlight-shop'); ?></th>
                                <th style="width:30%;"><?php esc_html_e('操作', 'at8-moonlight-shop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['low_stock'] as $row) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($row['id'])); ?>">
                                            <?php echo esc_html($row['title']); ?>
                                        </a>
                                    </td>
                                    <td class="num"><strong><?php echo esc_html(number_format_i18n($row['available'])); ?></strong></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=mlshop_product&page=mlshop-card-stock')); ?>">
                                            <?php esc_html_e('补充卡密', 'at8-moonlight-shop'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="mlshop-card">
                <h2 class="mlshop-card-title"><?php esc_html_e('快捷入口', 'at8-moonlight-shop'); ?></h2>
                <p>
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=mlshop_product&page=mlshop-statistics')); ?>"><?php esc_html_e('订单统计', 'at8-moonlight-shop'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=mlshop_product&page=mlshop-card-stock')); ?>"><?php esc_html_e('卡密库存', 'at8-moonlight-shop'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=mlshop_product&page=mlshop-settings')); ?>"><?php esc_html_e('商城設定', 'at8-moonlight-shop'); ?></a>
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=mlshop_order')); ?>"><?php esc_html_e('全部订单', 'at8-moonlight-shop'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }
}
