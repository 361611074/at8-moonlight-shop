<?php
/**
 * 支付网关抽象基類（可扩展接口）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class MLSHOP_Gateway
{
    abstract public function get_id();
    abstract public function get_title();
    abstract public function get_description();

    /**
     * 处理支付。
     *
     * @return array {success:bool, message:string, redirect:string, status:string, qr?:bool}
     */
    abstract public function process_payment($order_id);

    protected function order_url($order_id)
    {
        // 游客订单自动附带访问令牌（mlshop_order_view_url），登录用户订单不带令牌
        return mlshop_order_view_url($order_id);
    }
}
