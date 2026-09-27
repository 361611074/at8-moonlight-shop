<?php
/**
 * 订单 CSV 导出：admin_post `mlpro_export_orders`（manage_options + nonce + License 门禁）。
 *
 * - 日期范围筛选：参数走 strtotime 白名单校验（Y-m-d 格式 + 回读一致才接受）；
 * - 列：订单号 / 状态 / 金额 / 币种 / 网关 / 客户ID / 客户邮箱 / 商品摘要 / 创建时间 / 支付时间；
 * - CSV 公式注入防护：以 = + - @ Tab 开头的单元格前置单引号（csv_cell，静态可测）；
 * - 流式输出 php://output（分页 500 一批，不整表驻留内存），UTF-8 BOM 保证 Excel 中文不乱码。
 *
 * @package Moonlight_Shop_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLPRO_Order_Export
{
    /** 导出动作名。 */
    const ACTION = 'mlpro_export_orders';
    /** nonce action。 */
    const NONCE = 'mlpro_export_orders';
    /** 每批查询订单数。 */
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
        add_action('admin_post_' . self::ACTION, array($this, 'handle_export'));
        // Free 订单列表页的导出入口（Pro 激活时才显示按钮）。
        add_action('restrict_manage_posts', array($this, 'render_export_button'), 10, 2);
    }

    /**
     * 订单列表页（edit.php?post_type=mlshop_order）导出按钮。
     */
    public function render_export_button($post_type, $which)
    {
        if ('mlshop_order' !== $post_type || !MLPRO_License_Client::is_active()) {
            return;
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION), self::NONCE);
        printf(
            '<a href="%s" class="button" style="margin:0 8px 8px 0;">%s</a>',
            esc_url($url),
            esc_html__('导出订单 CSV（Pro）', 'moonlight-shop-pro')
        );
    }

    /* ==================== 纯逻辑（静态，供单测） ==================== */

    /**
     * CSV 公式注入防护：以 = + - @ Tab 开头的单元格前置单引号，
     * 防止 Excel / WPS 打开时把单元格当公式执行（CSV Injection）。
     */
    public static function csv_cell($value)
    {
        $value = (string) $value;
        if ('' === $value) {
            return '';
        }
        $c = $value[0];
        if ('=' === $c || '+' === $c || '-' === $c || '@' === $c || "\t" === $c) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * 日期参数白名单校验：仅接受 Y-m-d 且 strtotime 回读一致的日期，否则回退默认值。
     *
     * @param string $value    原始输入。
     * @param string $fallback 校验失败时的默认值（Y-m-d）。
     * @return string Y-m-d
     */
    public static function parse_date($value, $fallback)
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return $fallback;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }
        $ts = strtotime($value);
        if (false === $ts || date('Y-m-d', $ts) !== $value) {
            return $fallback;
        }
        return $value;
    }

    /* ==================== 导出 ==================== */

    /**
     * 导出处理：权限 + nonce + 授权三重校验；CSV 流式输出。
     */
    public function handle_export()
    {
        if (!current_user_can('manage_options') || !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE)) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-shop-pro'));
        }
        if (!MLPRO_License_Client::is_active()) {
            wp_die(esc_html__('Pro 授权未激活，无法导出。', 'moonlight-shop-pro'));
        }

        $now_ts = current_time('timestamp');
        $from = self::parse_date(isset($_GET['from']) ? wp_unslash($_GET['from']) : '', date('Y-m-d', strtotime('-30 days', $now_ts)));
        $to   = self::parse_date(isset($_GET['to']) ? wp_unslash($_GET['to']) : '', date('Y-m-d', $now_ts));
        if (strtotime($to) < strtotime($from)) {
            $tmp  = $from;
            $from = $to;
            $to   = $tmp; // 起止颠倒：交换而非报错
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mlpro-orders-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        // BOM：Excel 打开中文不乱码。
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            __('订单号', 'moonlight-shop-pro'),
            __('状态', 'moonlight-shop-pro'),
            __('金额', 'moonlight-shop-pro'),
            __('币种', 'moonlight-shop-pro'),
            __('网关', 'moonlight-shop-pro'),
            __('客户ID', 'moonlight-shop-pro'),
            __('客户邮箱', 'moonlight-shop-pro'),
            __('商品摘要', 'moonlight-shop-pro'),
            __('创建时间', 'moonlight-shop-pro'),
            __('支付时间', 'moonlight-shop-pro'),
        ));

        $stati = array_map(function ($s) {
            return 'mlshop_' . $s;
        }, array_keys(MLSHOP_Order::get_status_labels()));

        $paged = 1;
        do {
            $q = new WP_Query(array(
                'post_type'              => 'mlshop_order',
                'post_status'            => $stati,
                'posts_per_page'         => self::BATCH,
                'paged'                  => $paged,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'date_query'             => array(array(
                    'after'     => $from . ' 00:00:00',
                    'before'    => $to . ' 23:59:59',
                    'inclusive' => true,
                )),
                'fields'                 => 'ids',
                'no_found_rows'          => false,
                'update_post_term_cache' => false,
            ));
            foreach ($q->posts as $oid) {
                fputcsv($out, $this->build_row((int) $oid));
            }
            $paged++;
        } while ($q->have_posts() && ($paged - 1) * self::BATCH < (int) $q->found_posts);

        fclose($out);
        exit;
    }

    /**
     * 单订单 → CSV 行（全部单元格过公式注入防护）。
     */
    private function build_row($order_id)
    {
        $order_id = (int) $order_id;
        $status   = (string) (get_post_meta($order_id, '_mlshop_status', true) ?: 'pending');

        $gateway = (string) get_post_meta($order_id, '_mlshop_payment_gateway', true);
        if ('' === $gateway) {
            $gateway = (string) get_post_meta($order_id, '_mlshop_gateway', true);
        }

        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $email   = '';
        if ($user_id) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->user_email)) {
                $email = (string) $user->user_email;
            }
        }

        // 商品摘要：「标题 x 数量」分号拼接（不含卡密 / 地址等敏感内容）。
        $parts  = array();
        $items  = get_post_meta($order_id, '_mlshop_items', true);
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $title = isset($it['title']) ? (string) $it['title'] : '';
                if ('' === $title && !empty($it['id'])) {
                    $title = (string) get_the_title((int) $it['id']);
                }
                $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
                $parts[] = $title . ' x' . $qty;
            }
        }

        $created = (string) get_post_meta($order_id, '_mlshop_created', true);
        if ('' === $created) {
            $created = (string) get_post_field('post_date', $order_id);
        }

        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ('' === $order_no) {
            $order_no = (string) get_post_field('post_title', $order_id);
        }

        return array(
            self::csv_cell($order_no),
            self::csv_cell(MLSHOP_Order::get_status_label($status)),
            self::csv_cell(number_format((float) get_post_meta($order_id, '_mlshop_total', true), 2, '.', '')),
            self::csv_cell((string) get_post_meta($order_id, '_mlshop_currency', true)),
            self::csv_cell($gateway),
            self::csv_cell((string) $user_id),
            self::csv_cell($email),
            self::csv_cell(implode('；', $parts)),
            self::csv_cell($created),
            self::csv_cell((string) get_post_meta($order_id, '_mlshop_paid_time', true)),
        );
    }
}
