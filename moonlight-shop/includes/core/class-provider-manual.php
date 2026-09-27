<?php
/**
 * 手工发货 Provider（物流第二批）：零 API 依赖的默认实现。
 *
 *  - query_tracking 恒返回 pending（无轨迹数据），cron 同步遇到 manual 直接跳过；
 *  - get_supported_companies 返回常见快递公司静态表，供后台发货下拉选择，
 *    公司代码沿用快递100 聚合平台惯例（SF/ZTO/YTO...），切到查询型 Provider 时无缝复用；
 *  - create_shipment 无电子面单能力，直接返回 success=true（发货单由本地创建）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Shipping_Provider_Manual implements Moonlight_Shipping_Provider_Interface
{
    /**
     * 常见快递公司静态表（顺丰/中通/圆通/申通/韵达/极兔/京东/EMS/邮政）。
     *
     * code 与快递100 平台代码一致，管理员换成 express100 后已录入的公司代码可直接查询。
     *
     * @return array
     */
    public static function default_companies()
    {
        return array(
            array('code' => 'SF',   'name' => __('顺丰速运', 'moonlight-shop')),
            array('code' => 'ZTO',  'name' => __('中通快递', 'moonlight-shop')),
            array('code' => 'YTO',  'name' => __('圆通速递', 'moonlight-shop')),
            array('code' => 'STO',  'name' => __('申通快递', 'moonlight-shop')),
            array('code' => 'YD',   'name' => __('韵达快递', 'moonlight-shop')),
            array('code' => 'JT',   'name' => __('极兔速递', 'moonlight-shop')),
            array('code' => 'JD',   'name' => __('京东物流', 'moonlight-shop')),
            array('code' => 'EMS',  'name' => __('EMS', 'moonlight-shop')),
            array('code' => 'POST', 'name' => __('中国邮政', 'moonlight-shop')),
        );
    }

    public function get_code()
    {
        return 'manual';
    }

    public function get_name()
    {
        return __('手工发货', 'moonlight-shop');
    }

    public function is_available()
    {
        return true;
    }

    public function get_supported_companies()
    {
        return self::default_companies();
    }

    /**
     * 手工发货无面单能力：本地创建发货单即可，恒成功。
     */
    public function create_shipment($shipment)
    {
        $no = '';
        if (is_array($shipment) && isset($shipment['tracking_no'])) {
            $no = (string) $shipment['tracking_no'];
        }
        return array('success' => true, 'tracking_no' => $no, 'message' => '');
    }

    /**
     * 手工模式无轨迹查询：恒 pending（cron 同步会跳过 manual Provider）。
     */
    public function query_tracking($company_code, $tracking_no)
    {
        return array(
            'status'  => 'pending',
            'events'  => array(),
            'message' => __('手工发货模式不提供轨迹查询。', 'moonlight-shop'),
            'ok'      => true,
        );
    }
}
