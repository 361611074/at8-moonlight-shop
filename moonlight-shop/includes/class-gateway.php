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
        return mlshop_get_page_url('checkout') . '?order=' . $order_id;
    }
}
