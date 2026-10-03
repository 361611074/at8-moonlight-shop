<?php
/**
 * 积分支付网关：按全局兑换比例（积分/货币单位）用积分全额支付订单。
 *
 * 换算口径：订单所需积分 = 订单金额 × credit_rate（向上取整，见
 * mlshop_currency_to_credit()）。实际扣减量落在订单 meta
 * `_mlshop_credit_spent`，退款 / 取消时的积分回补由订单状态机
 * （MLSHOP_Order::maybe_reverse_funds）读取该值原路返还。
 *
 * 安全语义与余额网关一致：
 *  - 终态幂等：订单已是 paid/processing/completed/refunded 时不再重复扣减；
 *  - 原子扣减：MLSHOP_Credit::spend() 走 SQL 条件更新，余额不足直接失败，
 *    并发请求不会把积分扣成负数。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_Credit extends MLSHOP_Gateway
{
    public function get_id()
    {
        return 'credit';
    }

    public function get_title()
    {
        $credit_name = mlshop_get_option('credit_name', __('积分', 'moonlight-shop'));
        return sprintf(__('%s支付', 'moonlight-shop'), $credit_name);
    }

    public function get_description()
    {
        return sprintf(
            __('使用账户%s按比例支付（%s %s = 1 货币单位）。', 'moonlight-shop'),
            mlshop_get_option('credit_name', __('积分', 'moonlight-shop')),
            mlshop_get_credit_rate(),
            mlshop_get_option('credit_name', __('积分', 'moonlight-shop'))
        );
    }

    public function process_payment($order_id)
    {
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $total   = (float) get_post_meta($order_id, '_mlshop_total', true);
        $credit_name = mlshop_get_option('credit_name', __('积分', 'moonlight-shop'));

        // 充值订单不可用积分支付（用积分买积分等于无限套利）
        if ('recharge' === get_post_meta($order_id, '_mlshop_type', true)) {
            return array(
                'success' => false,
                'message' => __('充值订单不可使用积分支付。', 'moonlight-shop'),
            );
        }

        // 游客订单没有积分账本可扣
        if (!$user_id) {
            return array(
                'success' => false,
                'message' => sprintf(__('%s支付需登录后使用。', 'moonlight-shop'), $credit_name),
            );
        }

        // 幂等：订单已付款（或已退款等终态）时不再重复扣减，避免重复回调 / 手动重试二次扣费。
        $current_status = (string) get_post_meta($order_id, '_mlshop_status', true);
        if (in_array($current_status, array('paid', 'processing', 'completed', 'refunded'), true)) {
            return array(
                'success'  => true,
                'message'  => __('订单已完成付款。', 'moonlight-shop'),
                'redirect' => $this->order_url($order_id),
                'status'   => $current_status,
            );
        }

        $points = mlshop_currency_to_credit($total);
        if ($points <= 0) {
            return array(
                'success' => false,
                'message' => __('订单金额无效，无法使用积分支付。', 'moonlight-shop'),
            );
        }

        // 原子扣减：由 SQL 条件保证余额充足才扣，并发请求不会双双通过校验。
        $remaining = MLSHOP_Credit::spend(
            $user_id,
            $points,
            sprintf(__('支付订单 #%1$s（%2$s %3$s）', 'moonlight-shop'), $order_id, $points, $credit_name)
        );
        if (false === $remaining) {
            $balance = MLSHOP_Credit::get_balance($user_id);
            return array(
                'success' => false,
                'message' => sprintf(
                    __('%1$s不足，本次需 %2$s，当前余额 %3$s。', 'moonlight-shop'),
                    $credit_name,
                    $points,
                    $balance
                ),
            );
        }

        // 记录实际扣减量：退款 / 取消时状态机按该值原路返还积分。
        update_post_meta($order_id, '_mlshop_credit_spent', $points);

        $paid = MLSHOP_Order::mark_paid($order_id, 'credit');
        if (is_wp_error($paid)) {
            // 完单失败（如订单恰被过期取消）：立刻回补积分，保证「扣了分必开通」。
            delete_post_meta($order_id, '_mlshop_credit_spent');
            MLSHOP_Credit::add($user_id, $points, sprintf(__('订单 #%1$s 支付失败回补', 'moonlight-shop'), $order_id));
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
