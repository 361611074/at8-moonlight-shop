<?php
/**
 * 统一 option 访问器（收敛商城「独立 option × N + mlshop_options 数组」双路径）。
 *
 * 读取顺序：新结构数组键 → 旧独立 option（mlshop_$key）→ 旧数组（mlshop_options[$key]）
 *           → 会员中心 mluc_options[$key]（Phase B 并入，最低优先级）。
 * 写入：过渡期双写（新结构 + 旧独立键），保证旧代码/旧设置页继续工作；
 *       Phase 9 新设置页上线后切为只写新结构。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Options
{
    const STORE = 'moonlight_shop_options';

    /**
     * 读配置（带旧路径回退）。
     *
     * @param string $key     配置键（新键名）
     * @param mixed  $default 默认值
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $store = get_option(self::STORE, array());
        if (is_array($store) && array_key_exists($key, $store)) {
            return $store[$key];
        }
        // 旧独立 option（class-admin register_setting 的 mlshop_$key 系列）
        $legacy_single = get_option('mlshop_' . $key, null);
        if (null !== $legacy_single) {
            return $legacy_single;
        }
        // 旧数组 option（页面 ID / default_gateway 等）
        $legacy_array = get_option('mlshop_options', array());
        if (is_array($legacy_array) && array_key_exists($key, $legacy_array)) {
            return $legacy_array[$key];
        }
        // 会员中心并入（Phase B）：mluc_options 数组最低优先级回退（同键直读，不迁移数据）。
        // 仅影响 Moonlight_Options 的调用方；MLUC_Settings 等用户模块仍走 mluc_get_option('mluc_options')，互不干扰。
        $mluc = get_option('mluc_options', array());
        if (is_array($mluc) && array_key_exists($key, $mluc)) {
            return $mluc[$key];
        }
        return $default;
    }

    /**
     * 写配置（过渡期双写新结构 + 旧独立键；$legacy=true 时才写旧键，
     * 新增键（旧结构没有的）只写新结构）。
     *
     * @param string $key
     * @param mixed  $value
     * @param bool   $legacy 是否同步写旧独立键
     */
    public static function set($key, $value, $legacy = true)
    {
        $store         = get_option(self::STORE, array());
        $store         = is_array($store) ? $store : array();
        $store[$key]   = $value;
        update_option(self::STORE, $store);
        if ($legacy && null !== get_option('mlshop_' . $key, null)) {
            update_option('mlshop_' . $key, $value);
        }
    }
}
