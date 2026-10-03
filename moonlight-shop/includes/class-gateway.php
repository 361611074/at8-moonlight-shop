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
     * 前台展示用名称：后台「前台公開支付方式」可逐个网关覆盖标题 / 说明。
     *
     * 存储于 option `mlshop_gateway_labels[<gateway_id>]['title'|'desc']`，
     * 未配置或留空时回退到网关自带标题，保证向后兼容。
     *
     * @return string
     */
    public function get_label()
    {
        $labels = mlshop_get_option('gateway_labels', array());
        $id     = $this->get_id();
        if (is_array($labels) && isset($labels[$id]['title']) && '' !== trim((string) $labels[$id]['title'])) {
            return (string) $labels[$id]['title'];
        }
        return $this->get_title();
    }

    /**
     * 前台展示用说明（可后台覆盖）。
     *
     * @return string
     */
    public function get_label_desc()
    {
        $labels = mlshop_get_option('gateway_labels', array());
        $id     = $this->get_id();
        if (is_array($labels) && isset($labels[$id]['desc']) && '' !== trim((string) $labels[$id]['desc'])) {
            return (string) $labels[$id]['desc'];
        }
        return $this->get_description();
    }

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
