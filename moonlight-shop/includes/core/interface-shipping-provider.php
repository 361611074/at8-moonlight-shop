<?php
/**
 * Shipping Provider 接口（物流第二批）。
 *
 * 语义约定（docs/SHIPPING.md 第二节）：
 *  - get_supported_companies() 返回二维数组：[ ['code'=>'SF','name'=>'顺丰速运'], ... ]
 *    code 供轨迹查询用（query_tracking 的 $company_code），name 供后台下拉 / 展示。
 *  - create_shipment($shipment) 预留电子面单：$shipment 为数组
 *    ['order_id','company','company_code','tracking_no','items']，
 *    返回 ['success'=>bool,'tracking_no'=>string,'message'=>string]。
 *    查询型 Provider（如快递100）无面单能力，直接返回 success=true。
 *  - query_tracking($company_code, $tracking_no) 返回标准化轨迹：
 *    [
 *      'status'  => 'transit|delivered|exception|pending',
 *      'events'  => [ ['time'=>ts, 'desc'=>string, 'city'=>?string], ... ],
 *      'message' => ?string,
 *      'ok'      => bool,  // 解析成功与否；false 时 status 恒为 pending
 *    ]
 *    查询失败（断网 / 解析失败）一律返回 status=pending 且不抛异常，
 *    **绝不能影响订单状态**（对齐计划书第六十八节）。
 *
 * 注册：apply_filters('moonlight_shipping_providers', array) 追加第三方 Provider。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

interface Moonlight_Shipping_Provider_Interface
{
    /**
     * Provider 唯一标识（manual / express100 / sf / kdbird ...）。
     */
    public function get_code();

    /**
     * 显示名（设置页下拉 / 后台展示）。
     */
    public function get_name();

    /**
     * 是否可用（如快递100 需 Key 已配置；manual 恒可用）。
     */
    public function is_available();

    /**
     * 支持的物流公司静态表：[ ['code'=>'SF','name'=>'顺丰速运'], ... ]。
     */
    public function get_supported_companies();

    /**
     * 创建发货单（预留电子面单；查询型 Provider 返回 success=true 即可）。
     *
     * @param array $shipment ['order_id','company','company_code','tracking_no','items']
     * @return array ['success'=>bool,'tracking_no'=>string,'message'=>string]
     */
    public function create_shipment($shipment);

    /**
     * 查询轨迹（标准化返回，失败一律 pending，绝不抛异常）。
     *
     * @param string $company_code 物流公司代码（如 SF）
     * @param string $tracking_no  运单号
     * @return array ['status'=>'transit|delivered|exception|pending','events'=>[],'message'=>?string,'ok'=>bool]
     */
    public function query_tracking($company_code, $tracking_no);
}
