<?php
/**
 * 扫码 / 线下付款网关（占位，可扩展为支付宝/微信）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_Manual extends MLSHOP_Gateway
{
    public function get_id()
    {
        return 'manual';
    }

    public function get_title()
    {
        return __('扫码 / 线下付款', 'at8-moonlight-shop');
    }

    public function get_description()
    {
        return __('生成订单后扫码付款，管理员确认到账后发货。', 'at8-moonlight-shop');
    }

    public function process_payment($order_id)
    {
        // 保持 pending，等待管理员确认；返回二维码占位信息。
        return array(
            'success'  => true,
            'message'  => __('订单已创建，请扫码付款，到账后自动发货。', 'at8-moonlight-shop'),
            'redirect' => $this->order_url($order_id),
            'status'   => 'pending',
            'qr'       => true,
        );
    }
}
