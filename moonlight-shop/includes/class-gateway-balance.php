<?php
/**
 * 余额支付网关。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_Balance extends MLSHOP_Gateway
{
    public function get_id()
    {
        return 'balance';
    }

    public function get_title()
    {
        return __('余额支付', 'moonlight-shop');
    }

    public function get_description()
    {
        return __('使用账户余额支付（需站点开通余额功能）。', 'moonlight-shop');
    }

    public function process_payment($order_id)
    {
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $total   = (float) get_post_meta($order_id, '_mlshop_total', true);
        $balance = (float) get_user_meta($user_id, '_mlshop_balance', true);

        // 幂等：订单已付款（或已退款等终态）时不再重复扣款，避免重复回调 / 手动重试造成二次扣费。
        $current_status = (string) get_post_meta($order_id, '_mlshop_status', true);
        if (in_array($current_status, array('paid', 'processing', 'completed', 'refunded'), true)) {
            return array(
                'success'  => true,
                'message'  => __('订单已完成付款。', 'moonlight-shop'),
                'redirect' => $this->order_url($order_id),
                'status'   => $current_status,
            );
        }

        // 原子扣减：由 SQL 条件保证余额充足才扣，杜绝并发请求同时通过校验导致超额扣款。
        $ok = mlshop_atomic_decrement_user_meta($user_id, '_mlshop_balance', $total);
        if (!$ok) {
            $balance = (float) get_user_meta($user_id, '_mlshop_balance', true);
            return array(
                'success' => false,
                'message' => sprintf(__('余额不足，当前余额 %s。', 'moonlight-shop'), mlshop_format_price($balance)),
            );
        }

        // 扣款凭据（审计 C1）：退款/取消时状态机按该值原路回补钱包。
        // 旧版只写 _mlshop_payment_gateway 不写任何凭据 → 回补条件永假，
        // 余额订单退款时用户静默损失全部货款。
        update_post_meta($order_id, '_mlshop_balance_spent', $total);

        $paid = MLSHOP_Order::mark_paid($order_id, 'balance');
        if (is_wp_error($paid)) {
            // 完单失败（如订单恰被过期取消）：立刻回补余额，保证「扣了钱必开通」。
            // 审计 H3：与状态机回补抢同一旗标（maybe_reverse_funds），
            // 并发取消已先行回补时此处跳过，杜绝双倍退款。
            delete_post_meta($order_id, '_mlshop_balance_spent');
            if (add_post_meta($order_id, '_mlshop_funds_reversed', 'gateway-comp', true)) {
                mlshop_atomic_increment_user_meta($user_id, '_mlshop_balance', $total);
            }
            return array(
                'success' => false,
                'message' => $paid->get_error_message(),
            );
        }
        return array(
            'success'  => true,
            'message'  => __('支付成功，订单已生效。', 'moonlight-shop'),
            'redirect' => $this->order_url($order_id),
            'status'   => 'paid',
        );
    }
}
