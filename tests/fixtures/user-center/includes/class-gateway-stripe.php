<?php
/**
 * Stripe 网关适配器：包装既有 MLUC_Stripe 静态实现（行为零变更）。
 *
 * 流程保持不变：本地订单 → 服务端创建 Checkout Session → 跳转 Stripe 托管页
 * → 回跳服务端复核 + Webhook 兜底 → complete_order 原子开通。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Gateway_Stripe implements MLUC_Payment_Gateway_Interface
{
    public function get_id()
    {
        return 'stripe';
    }

    public function get_name()
    {
        return mluc_ui_label('buy_gateway_stripe', 'Stripe (Credit Card)');
    }

    public function is_available()
    {
        return class_exists('MLUC_Stripe') && MLUC_Stripe::enabled();
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
        // 与原流程一致：在下单请求内直接创建 Checkout Session，失败则作废订单。
        $session = MLUC_Stripe::create_session((int) $order_id);
        if (is_wp_error($session)) {
            MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'fail', array('code' => 'session_create', 'message' => $session->get_error_message()));
            return $session;
        }
        update_post_meta($order_id, '_mluc_pay_txn_ref', $session['id']);
        MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'redirect', array('txn_ref' => $session['id']));

        return array(
            'flow'    => 'stripe',
            'message' => mluc_ui_label('buy_order_stripe', 'Order created. Redirecting to Stripe…'),
            'redirect' => $session['url'],
        );
    }

    public function handle_return($order_id)
    {
        // 回跳处理由 MLUC_Stripe::maybe_handle_return 统一完成（init 钩子）。
        return false;
    }

    public function query_payment($order_id)
    {
        $order_id = (int) $order_id;
        $session_id = (string) get_post_meta($order_id, '_mluc_pay_txn_ref', true);
        if ('' === $session_id || !class_exists('MLUC_Stripe')) {
            return 'pending';
        }
        $session = MLUC_Stripe::retrieve_session($session_id);
        if (is_wp_error($session)) {
            return 'pending';
        }
        return ('paid' === (string) (isset($session['payment_status']) ? $session['payment_status'] : '')) ? 'paid' : 'pending';
    }

    public function refund($order_id, $amount = 0)
    {
        return new WP_Error('mluc_st_refund', __('Stripe 退款请登录 Stripe Dashboard 操作。', 'moonlight-user-center'));
    }
}
