<?php
/**
 * Pro 功能：订单 CSV 导出（用户中心 → 会员订单 列表页）。
 * 受 License 授权门禁：未激活时按钮不渲染、导出请求拒绝。
 *
 * @package Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUCP_Order_Export
{
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
        add_action('restrict_manage_posts', array($this, 'render_export_button'), 10, 2);
        add_action('admin_post_mlucp_export_orders', array($this, 'handle_export'));
    }

    /**
     * 订单列表页导出按钮（仅 mluc_order 且 Pro 已激活）。
     */
    public function render_export_button($post_type, $which)
    {
        if (MLUC_Payments::CPT !== $post_type || !MLUCP_License_Client::is_active()) {
            return;
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=mlucp_export_orders'), 'mlucp_export_orders');
        printf(
            '<a href="%s" class="button" style="margin:0 8px 8px 0;">%s</a>',
            esc_url($url),
            esc_html__('导出 CSV（Pro）', 'moonlight-user-center-pro')
        );
    }

    /**
     * 导出处理：权限 + nonce + 授权三重校验；CSV 流式输出。
     */
    public function handle_export()
    {
        if (!current_user_can('manage_options') || !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'mlucp_export_orders')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-user-center-pro'));
        }
        if (!MLUCP_License_Client::is_active()) {
            wp_die(esc_html__('Pro 授权未激活，无法导出。', 'moonlight-user-center-pro'));
        }

        $orders = get_posts(array(
            'post_type'      => MLUC_Payments::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mluc-orders-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        // BOM：Excel 打开中文不乱码。
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array(
            __('订单号', 'moonlight-user-center-pro'),
            __('订单标题', 'moonlight-user-center-pro'),
            __('用户', 'moonlight-user-center-pro'),
            __('商品', 'moonlight-user-center-pro'),
            __('金额', 'moonlight-user-center-pro'),
            __('币种', 'moonlight-user-center-pro'),
            __('支付方式', 'moonlight-user-center-pro'),
            __('状态', 'moonlight-user-center-pro'),
            __('交易号', 'moonlight-user-center-pro'),
            __('创建时间', 'moonlight-user-center-pro'),
            __('开通时间', 'moonlight-user-center-pro'),
        ));

        foreach ($orders as $order) {
            $user = $order->post_author ? get_user_by('id', (int) $order->post_author) : false;
            $level = (string) get_post_meta($order->ID, '_mluc_pay_level', true);
            $is_pw = 'paywall' === (string) get_post_meta($order->ID, '_mluc_pay_type', true);
            $item  = $is_pw ? get_the_title((int) get_post_meta($order->ID, '_mluc_pay_post', true))
                : (class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label($level) : $level);
            fputcsv($out, array(
                (string) get_post_meta($order->ID, '_mluc_pay_order_no', true),
                $order->post_title,
                $user ? $user->user_login : '',
                $item,
                (string) get_post_meta($order->ID, '_mluc_pay_price', true),
                (string) get_post_meta($order->ID, '_mluc_pay_currency', true),
                (string) get_post_meta($order->ID, '_mluc_pay_gateway', true),
                (string) get_post_meta($order->ID, '_mluc_pay_status', true),
                (string) get_post_meta($order->ID, '_mluc_pay_txn', true),
                $order->post_date,
                (string) get_post_meta($order->ID, '_mluc_pay_granted', true),
            ));
        }
        fclose($out);
        exit;
    }
}
