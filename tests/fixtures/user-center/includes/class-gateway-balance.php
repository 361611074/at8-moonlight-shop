<?php
/**
 * 余额支付网关：账户余额即时扣款开通（需后台启用余额钱包）。
 *
 * 流程：原子扣减余额 → complete_order 原子完单；完单失败自动回补余额，
 * 保证「扣了钱必开通、没开通必退钱」，杜绝两边不一致。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Gateway_Balance implements MLUC_Payment_Gateway_Interface
{
    public function get_id()
    {
        return 'balance';
    }

    public function get_name()
    {
        return mluc_ui_label('buy_gateway_balance', 'Account Balance');
    }

    public function is_available()
    {
        return mluc_balance_enabled();
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
        $order_id = (int) $order_id;
        $user_id  = (int) get_post_meta($order_id, '_mluc_pay_user', true);
        $total    = (float) get_post_meta($order_id, '_mluc_pay_price', true);

        // 充值订单不可用余额支付（余额买余额无意义且可循环刷单）。
        if ('recharge' === (string) get_post_meta($order_id, '_mluc_pay_type', true)) {
            return new WP_Error('mluc_balance_recharge', __('充值不可使用余额支付。', 'moonlight-user-center'));
        }
        if ($order_id <= 0 || !$user_id || $total <= 0) {
            return new WP_Error('mluc_balance_order', __('本地订单无效。', 'moonlight-user-center'));
        }

        // 原子扣减：SQL 条件保证余额充足才扣，并发请求只有一次成功。
        if (false === MLUC_Wallet::spend($user_id, $total, sprintf(__('余额支付订单 #%d', 'moonlight-user-center'), $order_id))) {
            $left = MLUC_Wallet::get_balance($user_id);
            MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'fail', array('code' => 'insufficient', 'amount' => (string) $left));
            return new WP_Error(
                'mluc_balance_poor',
                sprintf(
                    /* translators: %s: 当前余额 */
                    __('余额不足，当前余额 %s，请先充值。', 'moonlight-user-center'),
                    number_format($left, 2)
                )
            );
        }

        $done = MLUC_Payments::complete_order($order_id, '', 'balance');
        if (is_wp_error($done)) {
            // 完单失败（如等级校验不过）：立刻回补余额，账本记回补流水。
            MLUC_Wallet::add($user_id, $total, sprintf(__('订单 #%d 开通失败回补', 'moonlight-user-center'), $order_id));
            MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'fail', array('code' => 'complete', 'message' => $done->get_error_message()));
            return $done;
        }

        MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'paid');
        return array(
            'flow'    => 'balance',
            'message' => mluc_ui_label('buy_order_balance', 'Payment successful via account balance.'),
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
        $order_id = (int) $order_id;
        $user_id  = (int) get_post_meta($order_id, '_mluc_pay_user', true);
        $total    = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        if (!$user_id || $total <= 0) {
            return new WP_Error('mluc_balance_refund', __('订单无效，无法退款。', 'moonlight-user-center'));
        }
        $amount = ($amount > 0) ? (float) $amount : $total;
        if ($amount > $total) {
            return new WP_Error('mluc_balance_refund', __('退款金额不能超过订单金额。', 'moonlight-user-center'));
        }
        MLUC_Wallet::add($user_id, $amount, sprintf(__('订单 #%d 退回余额', 'moonlight-user-center'), $order_id));
        MLUC_Payment_Log::write($order_id, $this->get_id(), 'refund', 'ok', array('amount' => (string) $amount));
        return true;
    }
}
