<?php
/**
 * 支付网关统一接口：PayPal / Stripe / Alipay 及未来渠道的公共契约。
 *
 * 网关实现要点：
 * - 本地订单统一为 mluc_order CPT（MLUC_Payments 创建，金额/货币/归属均为服务端数据）；
 * - process_payment() 在本地订单创建后调用，负责发起支付（返回跳转地址 / 流程标记）；
 * - 浏览器回跳（handle_return）与服务端查询（query_payment）永远不作为开通依据，
 *   最终开通必须经 MLUC_Payments::complete_order() 原子完单；
 * - 异步通知（verify_notify）必须验证签名 + 订单号 + 金额 + 商户身份后才可完单。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

interface MLUC_Payment_Gateway_Interface
{
    /**
     * 网关唯一标识（小写字母数字，存入订单 _mluc_pay_gateway）。
     */
    public function get_id();

    /**
     * 前台展示名称（走 mluc_ui_label，支持后台自定义）。
     */
    public function get_name();

    /**
     * 是否可用（启用 + 配置齐全 + 环境依赖满足）。
     */
    public function is_available();

    /**
     * 能力矩阵：currencies（支持币种，空数组 = 不限）、recurring、refund、query。
     */
    public function get_capabilities();

    /**
     * 为本地订单发起支付（订单已由 MLUC_Payments 创建，价格/货币服务端可信）。
     *
     * @param int $order_id 本地订单 ID。
     * @return array|WP_Error 至少包含 flow；跳转式网关附带 redirect。
     */
    public function process_payment($order_id);

    /**
     * 浏览器回跳处理（仅展示用，开通以 notify / 服务端查询为准）。
     * 网关需自行处理重定向与提示；无回跳流程的网关返回 false。
     *
     * @param int $order_id 本地订单 ID。
     * @return bool|WP_Error true = 已处理并完成重定向；false = 本网关无回跳流程。
     */
    public function handle_return($order_id);

    /**
     * 服务端查询支付结果（回跳复核 / 对账用）。
     *
     * @param int $order_id 本地订单 ID。
     * @return string|WP_Error paid / pending / failed。
     */
    public function query_payment($order_id);

    /**
     * 退款（第一版仅具备能力位，具体退款政策见产品文档）。
     *
     * @param int   $order_id 本地订单 ID。
     * @param float $amount   退款金额，0 = 全额。
     * @return true|WP_Error
     */
    public function refund($order_id, $amount = 0);
}
