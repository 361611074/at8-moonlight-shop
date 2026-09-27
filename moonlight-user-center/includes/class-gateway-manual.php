<?php
/**
 * 线下转账网关：管理员确认收款后开通（MLUC_Payments::admin_confirm）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Gateway_Manual implements MLUC_Payment_Gateway_Interface
{
    public function get_id()
    {
        return 'manual';
    }

    public function get_name()
    {
        return mluc_ui_label('buy_gateway_manual', 'Bank Transfer (admin confirms payment)');
    }

    public function is_available()
    {
        return !empty(mluc_get_option('manual_enabled', 1));
    }

    public function get_capabilities()
    {
        return array(
            'currencies' => array(),
            'recurring'  => false,
            'refund'     => false,
            'query'      => false,
        );
    }

    public function process_payment($order_id)
    {
        MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'pending');

        return array(
            'flow'         => 'manual',
            'message'      => mluc_ui_label('buy_order_manual', 'Order created. Please complete the transfer as instructed. Your membership will be activated once the administrator confirms your payment.'),
            'instructions' => MLUC_Payments::get_instructions(),
        );
    }

    public function handle_return($order_id)
    {
        return false;
    }

    public function query_payment($order_id)
    {
        $status = (string) get_post_meta((int) $order_id, '_mluc_pay_status', true);
        return ('paid' === $status) ? 'paid' : 'pending';
    }

    public function refund($order_id, $amount = 0)
    {
        return new WP_Error('mluc_manual_refund', __('线下转账请在银行端操作退款，并在订单页人工标记。', 'moonlight-user-center'));
    }
}
