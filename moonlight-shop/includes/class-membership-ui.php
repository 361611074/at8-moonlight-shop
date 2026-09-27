<?php
/**
 * 会员等级升级：前端升级卡片 + 升级下单 + 付款后授予等级。
 *
 * 依赖 moonlight-user-center 的等级定义（MLUC_Membership::get_level_price/validity）。
 * 升级订单为专用伪商品订单（meta _mlshop_type=membership / _mlshop_membership_target）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Membership_UI
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
        add_shortcode('mlshop_membership', array($this, 'shortcode_upgrade'));
        add_action('wp_ajax_mlshop_buy_membership', array($this, 'ajax_buy_membership'));
        // 付款后授予会员等级（線上付款走 paid；貨到付款走 completed；已有 _mlshop_membership_granted 幂等保护）
        add_action('mlshop_order_paid', array($this, 'grant_membership'), 16);
        add_action('mlshop_order_completed', array($this, 'grant_membership'), 16);
    }

    /**
     * 升级卡片短代码（可嵌入用户中心会员 Tab）。
     */
    public function shortcode_upgrade()
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('请先登录。', 'moonlight-shop') . '</p>';
        }
        if (!class_exists('MLUC_Membership')) {
            return '<p>' . esc_html__('会员模块未启用。', 'moonlight-shop') . '</p>';
        }
        $user_id = get_current_user_id();
        $current = MLUC_Membership::get_user_level($user_id);
        $levels  = MLUC_Membership::get_levels();
        $gateways = array();
        if (class_exists('MLSHOP_Payment')) {
            $gateways = MLSHOP_Payment::get_instance()->get_gateways();
        }
        $symbol = mlshop_get_option('currency_symbol', 'HK$');

        ob_start();
        mlshop_get_template('membership-upgrade', array(
            'current'  => $current,
            'levels'   => $levels,
            'gateways' => $gateways,
            'symbol'   => $symbol,
        ));
        return ob_get_clean();
    }

    /**
     * 升级下单 AJAX。
     */
    public function ajax_buy_membership()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mlshop_send_json(false, __('请先登录。', 'moonlight-shop'));
        }
        if (!class_exists('MLSHOP_Order') || !class_exists('MLUC_Membership')) {
            mlshop_send_json(false, __('升级模块不可用。', 'moonlight-shop'));
        }

        $level = isset($_POST['level']) ? sanitize_key($_POST['level']) : '';
        $levels = MLUC_Membership::get_levels();
        if (!isset($levels[$level]) || 'free' === $level) {
            mlshop_send_json(false, __('无效的会员等级。', 'moonlight-shop'));
        }
        $price = (float) MLUC_Membership::get_level_price($level);
        if ($price <= 0) {
            mlshop_send_json(false, __('该等级未设置价格，暂不可购买。', 'moonlight-shop'));
        }

        $gateway_id = isset($_POST['gateway']) ? sanitize_key($_POST['gateway']) : '';
        $gateway = MLSHOP_Payment::get_instance()->get_gateway($gateway_id);
        if (!$gateway) {
            mlshop_send_json(false, __('支付方式无效。', 'moonlight-shop'));
        }

        $order_id = MLSHOP_Order::create_membership(get_current_user_id(), $level, $price, $gateway_id);
        if (is_wp_error($order_id)) {
            mlshop_send_json(false, $order_id->get_error_message());
        }

        $result = $gateway->process_payment($order_id);
        $data = array('order_id' => $order_id);
        if (!empty($result['redirect'])) {
            $data['redirect'] = $result['redirect'];
        }
        mlshop_send_json(
            !empty($result['success']),
            isset($result['message']) ? $result['message'] : '',
            $data
        );
    }

    /**
     * 付款后授予目标会员等级。
     */
    public function grant_membership($order_id)
    {
        $type = get_post_meta($order_id, '_mlshop_type', true);
        if ('membership' !== $type) {
            return;
        }
        $level = get_post_meta($order_id, '_mlshop_membership_target', true);
        if (!$level || !class_exists('MLUC_Membership')) {
            return;
        }
        if (get_post_meta($order_id, '_mlshop_membership_granted', true)) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $validity = (int) MLUC_Membership::get_level_validity($level);
        $expires = $validity > 0 ? (int) (current_time('timestamp') + $validity * DAY_IN_SECONDS) : 0;
        MLUC_Membership::set_user_level($user_id, $level, $expires);
        update_post_meta($order_id, '_mlshop_membership_granted', current_time('mysql'));
    }
}
