<?php
/**
 * 积分支付网关：按后台「充值比例」把订单金额换算为积分即时扣减开通。
 *
 * - 换算唯一来源：服务端订单金额 × mluc_get_credit_rate()（向上取整到整数积分），
 *   客户端只选网关，不传任何金额；
 * - 流程：原子扣积分 → complete_order 原子完单；完单失败自动回补积分。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Gateway_Credit implements MLUC_Payment_Gateway_Interface
{
    public function get_id()
    {
        return 'credit';
    }

    public function get_name()
    {
        return mluc_ui_label('buy_gateway_credit', 'Points Payment');
    }

    public function is_available()
    {
        return mluc_credit_enabled();
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

    /**
     * 订单应付积分（服务端计算：金额 × 充值比例，向上取整）。
     */
    public static function order_cost($order_id)
    {
        $price = (float) get_post_meta((int) $order_id, '_mluc_pay_price', true);
        if ($price <= 0) {
            return 0;
        }
        return (int) ceil($price * mluc_get_credit_rate());
    }

    public function process_payment($order_id)
    {
        $order_id       = (int) $order_id;
        $user_id        = (int) get_post_meta($order_id, '_mluc_pay_user', true);
        $credit_name    = mluc_get_credit_name();

        // 充值订单不可用积分支付（积分买积分可循环刷单）。
        if ('recharge' === (string) get_post_meta($order_id, '_mluc_pay_type', true)) {
            return new WP_Error('mluc_credit_recharge', sprintf(__('充值不可使用%s支付。', 'moonlight-user-center'), $credit_name));
        }
        if ($order_id <= 0 || !$user_id) {
            return new WP_Error('mluc_credit_order', __('本地订单无效。', 'moonlight-user-center'));
        }

        $cost = self::order_cost($order_id);
        if ($cost <= 0) {
            return new WP_Error('mluc_credit_cost', __('订单金额无效，无法换算积分。', 'moonlight-user-center'));
        }

        // 原子扣减：积分不足（或记录不存在）直接失败，不产生负数。
        if (false === MLUC_Credit::spend($user_id, $cost, sprintf(__('%1$s支付订单 #%2$d', 'moonlight-user-center'), $credit_name, $order_id))) {
            $left = MLUC_Credit::get_balance($user_id);
            MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'fail', array('code' => 'insufficient', 'amount' => (string) $left));
            return new WP_Error(
                'mluc_credit_poor',
                sprintf(
                    /* translators: 1: 积分名称，2: 所需积分，3: 当前积分 */
                    __('%1$s不足，需 %2$s，当前 %3$s，请先充值。', 'moonlight-user-center'),
                    $credit_name,
                    number_format($cost),
                    number_format($left)
                )
            );
        }
        update_post_meta($order_id, '_mluc_pay_points', $cost);

        $done = MLUC_Payments::complete_order($order_id, '', 'credit');
        if (is_wp_error($done)) {
            // 完单失败：回补积分，账本记回补流水。
            MLUC_Credit::add($user_id, $cost, sprintf(__('订单 #%d 开通失败回补', 'moonlight-user-center'), $order_id));
            delete_post_meta($order_id, '_mluc_pay_points');
            MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'fail', array('code' => 'complete', 'message' => $done->get_error_message()));
            return $done;
        }

        MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'paid', array('amount' => (string) $cost));
        return array(
            'flow'    => 'credit',
            'message' => mluc_ui_label('buy_order_credit', 'Payment successful via points.'),
            'reload'  => 1,
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
        // 积分退款：按原订单扣减的积分整数额全额回补（部分退款走管理员线下协商）。
        $order_id = (int) $order_id;
        $user_id  = (int) get_post_meta($order_id, '_mluc_pay_user', true);
        $cost     = (int) get_post_meta($order_id, '_mluc_pay_points', true);
        if (!$user_id || $cost <= 0) {
            return new WP_Error('mluc_credit_refund', __('订单无积分支付记录，无法退款。', 'moonlight-user-center'));
        }
        MLUC_Credit::add($user_id, $cost, sprintf(__('订单 #%d 退回%s', 'moonlight-user-center'), $order_id, mluc_get_credit_name()));
        MLUC_Payment_Log::write($order_id, $this->get_id(), 'refund', 'ok', array('amount' => (string) $cost));
        return true;
    }
}
