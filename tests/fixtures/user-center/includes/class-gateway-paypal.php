<?php
/**
 * PayPal 网关适配器：包装既有 MLUC_PayPal 静态实现（行为零变更）。
 *
 * 流程保持不变：本地订单 → JS SDK createOrder（服务端建单）→ 用户批准
 * → 服务端 capture（金额/归属校验）→ complete_order 原子开通。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Gateway_PayPal implements MLUC_Payment_Gateway_Interface
{
    public function get_id()
    {
        return 'paypal';
    }

    public function get_name()
    {
        return mluc_ui_label('buy_gateway_paypal', 'PayPal (Online Payment)');
    }

    public function is_available()
    {
        return class_exists('MLUC_PayPal') && MLUC_PayPal::enabled();
    }

    public function get_capabilities()
    {
        return array(
            'currencies' => array(),
            'recurring'  => false,
            'refund'     => true,
            'query'      => true,
        );
    }

    public function process_payment($order_id)
    {
        // PayPal 走 JS Smart Buttons：本地订单就绪后由前端 mluc_paypal_create 建单。
        MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'pending');

        return array(
            'flow'    => 'paypal',
            'message' => mluc_ui_label('buy_order_paypal', 'Order created. Please complete the PayPal payment.'),
        );
    }

    public function handle_return($order_id)
    {
        return false;
    }

    public function query_payment($order_id)
    {
        $order_id = (int) $order_id;
        $pp_id    = (string) get_post_meta($order_id, '_mluc_pay_txn_ref', true);
        if ('' === $pp_id || !class_exists('MLUC_PayPal')) {
            return 'pending';
        }
        $token = MLUC_PayPal::get_token_public();
        if (is_wp_error($token)) {
            return 'pending';
        }
        $status = MLUC_PayPal::query_order($pp_id, $token);
        if (is_wp_error($status)) {
            return 'pending';
        }
        return ('COMPLETED' === strtoupper($status)) ? 'paid' : 'pending';
    }

    public function refund($order_id, $amount = 0)
    {
        return new WP_Error('mluc_pp_refund', __('PayPal 退款请登录 PayPal 商户后台操作。', 'moonlight-user-center'));
    }
}
