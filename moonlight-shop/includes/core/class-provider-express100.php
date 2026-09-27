<?php
/**
 * 快递100 聚合查询 Provider（物流第二批）。
 *
 * 查询型 Provider：不做电子面单（create_shipment 恒成功），仅提供轨迹查询。
 *
 * 容错约定（对齐计划书第六十八节「物流 API 不可用时订单不能崩溃」）：
 *  - 请求构造整体走 apply_filters('moonlight_shipping_express100_request', $params, ...)
 *    —— 官方接口参数结构可能变化，站长可挂过滤器校正，无需改代码；
 *  - 断网 / HTTP 错误 / 响应解析失败 一律返回 ['status'=>'pending','events'=>[]]
 *    并 do_action('moonlight_shipping_query_failed', $shipment_id(0), $response)，
 *    绝不影响订单状态；cron 侧另有失败计数退避（3 次失败暂停 24h）。
 *  - 响应解析独立为静态 parse_response()，便于单元测试；
 *    经典 poll（message/status/data）与新版聚合（data.list）两种结构都兼容。
 *
 * 配置（商城設定 → 運費設定，密钥脱敏保存）：
 *  - shipping_kuaidi100_key       授权 key
 *  - shipping_kuaidi100_customer  授权 customer 编号
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Shipping_Provider_Express100 implements Moonlight_Shipping_Provider_Interface
{
    public function get_code()
    {
        return 'express100';
    }

    public function get_name()
    {
        return __('快递100（聚合查询）', 'moonlight-shop');
    }

    /**
     * Key 已配置才可用（customer 缺失时多数接口仍可查询，故只强校验 key）。
     */
    public function is_available()
    {
        return '' !== trim((string) mlshop_get_option('shipping_kuaidi100_key', ''));
    }

    public function get_supported_companies()
    {
        // 与手工 Provider 共用同一张静态表（代码一致，切换 Provider 后运单公司代码可直接复用）
        return Moonlight_Shipping_Provider_Manual::default_companies();
    }

    /**
     * 查询型 Provider：无面单能力，恒成功（发货单由本地创建）。
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
     * 实时轨迹查询。任何异常都收敛为 status=pending，不抛异常、不返回 WP_Error。
     *
     * @param string $company_code 物流公司代码（如 SF）
     * @param string $tracking_no  运单号
     * @return array 标准化轨迹
     */
    public function query_tracking($company_code, $tracking_no)
    {
        $key = trim((string) mlshop_get_option('shipping_kuaidi100_key', ''));
        if ('' === $key || '' === trim((string) $tracking_no)) {
            return array('status' => 'pending', 'events' => array(), 'message' => __('快递100 未配置或运单号为空。', 'moonlight-shop'), 'ok' => true);
        }

        /**
         * 请求参数整体可被过滤器校正（官方参数结构可能变化，实现细节留给站长）。
         *
         * @param array  $params      默认请求参数（customer/key/num/company）
         * @param string $company_code 物流公司代码
         * @param string $tracking_no  运单号
         */
        $params = apply_filters('moonlight_shipping_express100_request', array(
            'customer' => trim((string) mlshop_get_option('shipping_kuaidi100_customer', '')),
            'key'      => $key,
            'num'      => (string) $tracking_no,
            'company'  => (string) $company_code,
        ), $company_code, $tracking_no);

        $response = wp_remote_post('https://p.kuaidi100.com/apicenter/kdquerytools.do', array(
            'timeout' => 15,
            'body'    => $params,
        ));
        if (is_wp_error($response)) {
            do_action('moonlight_shipping_query_failed', 0, $response);
            return array('status' => 'pending', 'events' => array(), 'message' => $response->get_error_message(), 'ok' => false);
        }

        $body = wp_remote_retrieve_body($response);
        $result = self::parse_response($body);
        if (empty($result['ok'])) {
            do_action('moonlight_shipping_query_failed', 0, $body);
        }
        return $result;
    }

    /**
     * 响应解析器（静态，独立便于单元测试）。
     *
     * 兼容结构：
     *  - 经典 poll：{"message":"ok","status":"3","data":[{"time":"...","context":"..."},...]}
     *    status 数字：1 在途 / 2 派件 / 3 签收 / 4 派件失败(退回)
     *  - 新版聚合：{"code":"200","data":{"list":[...]}} 或 {"list":[...]}
     *
     * @param string $body 原始响应体
     * @return array ['status'=>..., 'events'=>[['time'=>ts,'desc'=>..,'city'=>?]], 'message'=>.., 'ok'=>bool]
     */
    public static function parse_response($body)
    {
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return array('status' => 'pending', 'events' => array(), 'message' => __('响应解析失败', 'moonlight-shop'), 'ok' => false);
        }

        $message = '';
        if (isset($data['message'])) {
            $message = (string) $data['message'];
        } elseif (isset($data['msg'])) {
            $message = (string) $data['msg'];
        }

        // 取轨迹原始数组：经典 data 直接是数组；新版 data.list / 顶层 list
        $raw_events = array();
        if (isset($data['data']) && is_array($data['data'])) {
            if (empty($data['data']) || isset($data['data'][0])) {
                $raw_events = $data['data'];
            } elseif (isset($data['data']['list']) && is_array($data['data']['list'])) {
                $raw_events = $data['data']['list'];
            }
        } elseif (isset($data['list']) && is_array($data['list'])) {
            $raw_events = $data['list'];
        }

        // message 非 ok 且完全无轨迹结构 → 查询失败（如 key 无效 / 单号不存在）
        $has_structure = isset($data['data']) || isset($data['list']);
        if ('ok' !== strtolower($message) && !$has_structure) {
            return array('status' => 'pending', 'events' => array(), 'message' => ('' !== $message ? $message : __('查询失败', 'moonlight-shop')), 'ok' => false);
        }

        $events = array();
        foreach ($raw_events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $time_raw = isset($ev['ftime']) ? $ev['ftime'] : (isset($ev['time']) ? $ev['time'] : '');
            if (is_numeric($time_raw)) {
                $ts = (int) $time_raw;
            } else {
                $ts = ('' !== (string) $time_raw) ? (int) strtotime((string) $time_raw) : 0;
            }
            $desc = '';
            if (isset($ev['context'])) {
                $desc = (string) $ev['context'];
            } elseif (isset($ev['desc'])) {
                $desc = (string) $ev['desc'];
            } elseif (isset($ev['info'])) {
                $desc = (string) $ev['info'];
            }
            $city = '';
            if (isset($ev['area'])) {
                $city = trim((string) $ev['area']);
            } elseif (isset($ev['city'])) {
                $city = trim((string) $ev['city']);
            }
            $item = array('time' => $ts, 'desc' => $desc);
            if ('' !== $city) {
                $item['city'] = $city;
            }
            $events[] = $item;
        }

        // 状态映射：显式状态码优先，最后一条事件文案兜底
        $status = empty($events) ? 'pending' : 'transit';
        $code = '';
        if (isset($data['status'])) {
            $code = (string) $data['status'];
        } elseif (isset($data['state'])) {
            $code = (string) $data['state'];
        }
        if ('3' === $code) {
            $status = 'delivered';
        } elseif ('4' === $code) {
            $status = 'exception';
        } elseif (!empty($events)) {
            $last_desc = (string) $events[0]['desc']; // 快递100 轨迹新事件在前
            if (self::contains($last_desc, '签收') || self::contains($last_desc, '妥投') || self::contains($last_desc, '已代收')) {
                $status = 'delivered';
            } elseif (self::contains($last_desc, '派送失败') || self::contains($last_desc, '退回')) {
                $status = 'exception';
            }
        }

        return array('status' => $status, 'events' => $events, 'message' => $message, 'ok' => true);
    }

    /**
     * 子串包含判断（mbstring 缺失时回退 strpos：UTF-8 字节级匹配对这些关键词安全）。
     */
    private static function contains($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle   = (string) $needle;
        if ('' === $needle) {
            return false;
        }
        if (function_exists('mb_strpos')) {
            return false !== mb_strpos($haystack, $needle, 0, 'UTF-8');
        }
        return false !== strpos($haystack, $needle);
    }
}
