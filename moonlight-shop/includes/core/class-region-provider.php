<?php
/**
 * 行政区划数据源（Region Provider）。
 *
 * 结算 / 地址簿的省市区码必须能被本类 resolve，否则服务端拒绝。
 * 内置数据为精简版（34 省级行政区 + 主要地级市，无区县），
 * 通过 `moonlight_regions` 过滤器可整体替换 / 扩展数据源（Pro 可接全量区县）。
 *
 * 用法：
 *   Moonlight_Region_Provider::provinces();          // [code => name]
 *   Moonlight_Region_Provider::cities('CN-GD');      // [code => name]
 *   Moonlight_Region_Provider::resolve('CN-GD-GZ');  // 数组 | false
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Region_Provider
{
    /**
     * 数据缓存（进程内）。
     *
     * @var array|null
     */
    private static $data = null;

    /**
     * 加载区划数据（带 moonlight_regions 过滤器）。
     *
     * 过滤器约定：返回与内置结构一致的数组
     * ['CN-XX' => ['name' => '省名', 'cities' => ['CN-XX-YY' => '市名', ...]], ...]。
     * 结构非法的返回值会被丢弃并回退内置数据，避免脏数据打断下单。
     *
     * @return array
     */
    public static function data()
    {
        if (null !== self::$data) {
            return self::$data;
        }
        $data = require __DIR__ . '/data/regions-cn.php';
        if (!is_array($data) || empty($data)) {
            $data = array();
        }
        /**
         * 替换 / 扩展行政区划数据源。
         *
         * @param array $data ['CN-XX'=>['name'=>..,'cities'=>[...]], ...]
         */
        $filtered = apply_filters('moonlight_regions', $data);
        if (is_array($filtered) && !empty($filtered)) {
            $data = $filtered;
        }
        self::$data = $data;
        return self::$data;
    }

    /**
     * 全部省级行政区。
     *
     * @return array [code => name]
     */
    public static function provinces()
    {
        $out = array();
        foreach (self::data() as $code => $row) {
            $out[$code] = is_array($row) && isset($row['name']) ? $row['name'] : '';
        }
        return $out;
    }

    /**
     * 某省级行政区下的地级市列表。
     *
     * @param string $province_code 省级区码（如 CN-GD）
     * @return array [code => name]；省码无效时返回空数组
     */
    public static function cities($province_code)
    {
        $province_code = strtoupper(trim((string) $province_code));
        $data = self::data();
        if (!isset($data[$province_code]) || !is_array($data[$province_code])) {
            return array();
        }
        $cities = isset($data[$province_code]['cities']) && is_array($data[$province_code]['cities'])
            ? $data[$province_code]['cities']
            : array();
        return $cities;
    }

    /**
     * 解析区码（省或市）。
     *
     * @param string $code 区码（CN-XX 或 CN-XX-YY）
     * @return array|false false = 区码不存在
     *   省：['code','name','level'=>'province','province','province_name']
     *   市：['code','name','level'=>'city','province'=>'所属省码','province_name'=>'所属省名']
     */
    public static function resolve($code)
    {
        $code = strtoupper(trim((string) $code));
        if ('' === $code) {
            return false;
        }
        $data = self::data();
        if (isset($data[$code]) && is_array($data[$code]) && isset($data[$code]['name'])) {
            return array(
                'code'          => $code,
                'name'          => (string) $data[$code]['name'],
                'level'         => 'province',
                'province'      => $code,
                'province_name' => (string) $data[$code]['name'],
            );
        }
        foreach ($data as $pcode => $row) {
            if (!is_array($row) || empty($row['cities']) || !is_array($row['cities'])) {
                continue;
            }
            if (isset($row['cities'][$code])) {
                return array(
                    'code'          => $code,
                    'name'          => (string) $row['cities'][$code],
                    'level'         => 'city',
                    'province'      => (string) $pcode,
                    'province_name' => isset($row['name']) ? (string) $row['name'] : '',
                );
            }
        }
        return false;
    }
}
