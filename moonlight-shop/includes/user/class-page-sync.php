<?php
/**
 * 页面创建器（并入模块版）：单插件形态下负责创建 / 补齐前台页面。
 *
 * 背景：用户中心并入商城后，MLUC_Activator 未随迁（它属于「旧插件激活」范畴），
 * 导致单插件安装时不会自动创建「账户中心 / 登录 / 注册 / 找回密码」页面，
 * 商城设置里的页面 ID 也可能指向已删除的页面 → 前台 /login/ /account/ 直接 404。
 *
 * 本类在商城侧以「补齐缺失页面」的方式并入：已存在的页面绝不重复创建，
 * 只在缺失时创建并把 ID 写回 mluc_options（旧插件激活时不加载，不冲突）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Page_Sync
{
    /**
     * 需要保证存在的页面：[option_key => [标题, slug, 短代码]]
     */
    public static function page_map()
    {
        return array(
            'account_page_id'        => array('账户中心', 'account', '[mluc_account]'),
            'login_page_id'           => array('登录', 'login', '[mluc_login]'),
            'register_page_id'        => array('注册', 'register', '[mluc_register]'),
            'lostpassword_page_id'    => array('找回密码', 'lost-password', '[mluc_lostpassword]'),
        );
    }

    /**
     * 补齐缺失页面并修正失效的页面 ID 配置。
     *
     * @return array 变更摘要
     */
    public static function sync()
    {
        $summary = array('created' => array(), 'repaired' => array(), 'ok' => array());

        $options = (array) get_option('mluc_options', array());
        $changed = false;

        foreach (self::page_map() as $key => $cfg) {
            list($title, $slug, $shortcode) = $cfg;

            $page_id = isset($options[$key]) ? (int) $options[$key] : 0;
            $valid   = $page_id && get_post($page_id) && 'page' === get_post($page_id)->post_type
                && 'trash' !== get_post($page_id)->post_status;

            if ($valid) {
                $summary['ok'][$slug] = $page_id;
                continue;
            }

            // 配置缺失或指向已删除页面：先按 slug 找回，找不到再新建。
            $existing = get_page_by_path($slug);
            if ($existing) {
                $options[$key] = (int) $existing->ID;
                $changed = true;
                $summary['repaired'][$slug] = (int) $existing->ID;
                continue;
            }

            $new_id = wp_insert_post(array(
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_content' => $shortcode,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
            if ($new_id && !is_wp_error($new_id)) {
                $options[$key] = (int) $new_id;
                $changed = true;
                $summary['created'][$slug] = (int) $new_id;
            }
        }

        if ($changed) {
            update_option('mluc_options', $options);
        }
        return $summary;
    }

    /**
     * 商城自身的页面 ID 也要校验：配置指向已删除页面时按 slug 找回。
     */
    public static function repair_shop_pages()
    {
        $map = array(
            'products_page_id'   => array('store', '[mlshop_products]'),
            'cart_page_id'      => array('cart', '[mlshop_cart]'),
            'checkout_page_id'  => array('checkout', '[mlshop_checkout]'),
            'favorites_page_id' => array('favorites', '[mlshop_favorites]'),
        );
        $options = (array) get_option('mlshop_options', array());
        $changed = false;
        $fixed   = array();

        foreach ($map as $key => $cfg) {
            $page_id = isset($options[$key]) ? (int) $options[$key] : 0;
            if ($page_id && get_post($page_id) && 'page' === get_post($page_id)->post_type) {
                continue;
            }
            $existing = get_page_by_path($cfg[0]);
            if ($existing) {
                $options[$key] = (int) $existing->ID;
                $changed = true;
                $fixed[$key] = (int) $existing->ID;
                continue;
            }
            $new_id = wp_insert_post(array(
                'post_title'   => ucfirst($cfg[0]),
                'post_name'    => $cfg[0],
                'post_content' => $cfg[1],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
            if ($new_id && !is_wp_error($new_id)) {
                $options[$key] = (int) $new_id;
                $changed = true;
                $fixed[$key] = (int) $new_id;
            }
        }
        if ($changed) {
            update_option('mlshop_options', $options);
        }
        return $fixed;
    }

    /**
     * 入口：补齐页面 + 修正配置。permalink 不是 slug 形式时给出提示。
     */
    public static function boot()
    {
        $summary = self::sync();
        $shop    = self::repair_shop_pages();

        // 页面 URL 依赖 slug 形式的固定链接；结构异常时刷一次规则。
        $structure = (string) get_option('permalink_structure');
        if ('' === $structure || '%postname%' !== substr($structure, -9)) {
            flush_rewrite_rules(false);
        }

        return array($summary, $shop, $structure);
    }
}
