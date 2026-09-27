<?php
/**
 * 货到付款网关。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_COD extends MLSHOP_Gateway
{
    public function get_id()
    {
        return 'cod';
    }

    public function get_title()
    {
        return __('货到付款', 'moonlight-shop');
    }

    public function get_description()
    {
        return __('下单后由客服安排发货，验货付款。', 'moonlight-shop');
    }

    public function process_payment($order_id)
    {
        $res = MLSHOP_Order::mark_processing($order_id);
        if (is_wp_error($res)) {
            return array(
                'success' => false,
                'message' => $res->get_error_message(),
            );
        }
        return array(
            'success'  => true,
            'message'  => __('订单已提交，我们会尽快安排发货。', 'moonlight-shop'),
            'redirect' => $this->order_url($order_id),
            'status'   => 'processing',
        );
    }
}
