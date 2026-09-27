<?php
/**
 * 商城激活 / 停用。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Activator
{
    public static function activate()
    {
        $options = get_option('mlshop_options', array());

        $pages = array(
            'products' => '商品列表',
            'cart'     => '购物车',
            'checkout' => '结算',
        );
        foreach ($pages as $key => $title) {
            if (empty($options[$key . '_page_id']) || !get_post($options[$key . '_page_id'])) {
                $tag = 'mlshop_' . $key;
                $page_id = wp_insert_post(array(
                    'post_title'   => $title,
                    'post_name'    => $key,
                    'post_content' => '[' . $tag . ']',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ));
                if ($page_id && !is_wp_error($page_id)) {
                    $options[$key . '_page_id'] = $page_id;
                }
            }
        }

        if (!isset($options['currency_symbol'])) {
            $options['currency_symbol'] = 'HK$';
        }
        if (!isset($options['default_gateway'])) {
            $options['default_gateway'] = 'cod';
        }

        update_option('mlshop_options', $options);

        // 注册 CPT 后刷新规则
        MLSHOP_Product::register_post_type();
        MLSHOP_Order::register_post_type();
        flush_rewrite_rules();

        // 预置商城侧栏小工具（仅当侧栏为空）
        self::populate_shop_sidebar();

        // 调度未支付订单自动取消的定时任务
        if (!wp_next_scheduled('mlshop_expire_pending_orders')) {
            wp_schedule_event(time(), 'hourly', 'mlshop_expire_pending_orders');
        }

        // 物流第二批：15 分钟自动物流轨迹查询（mlshop_15min 间隔由 MLSHOP_Shipping 的
        // cron_schedules 过滤器注册；init 上的 maybe_schedule_sync 亦会自愈补注册）
        if (!wp_next_scheduled('moonlight_shipping_sync')) {
            wp_schedule_event(time(), 'mlshop_15min', 'moonlight_shipping_sync');
        }
    }

    /**
     * 商城侧栏（mlshop-shop）为空时，预置默认小工具，使商品列表/单页侧栏开箱即用。
     */
    public static function populate_shop_sidebar()
    {
        $sidebar = 'mlshop-shop';
        $sidebars = wp_get_sidebars_widgets();
        if (!is_array($sidebars) || !empty($sidebars[$sidebar])) {
            return;
        }

        $defaults = array(
            'mlshop_product_search'     => array('title' => '商品搜索'),
            'mlshop_product_categories' => array('title' => '商品分类', 'count' => 1),
            'mlshop_price_filter'       => array('title' => '价格筛选'),
            'mlshop_recent_products'    => array('title' => '最新商品', 'number' => 5),
            'mlshop_favorites'          => array('title' => '我的收藏'),
        );

        $assigned = array();
        foreach ($defaults as $id_base => $instance) {
            $opt_name = 'widget_' . $id_base;
            $opts = get_option($opt_name, array());
            if (!is_array($opts)) {
                $opts = array();
            }
            $nums = array_filter(array_keys($opts), 'is_int');
            $next = $nums ? max($nums) + 1 : 1;
            $opts[$next] = $instance;
            $opts['_multiwidget'] = 1;
            update_option($opt_name, $opts);
            $assigned[] = $id_base . '-' . $next;
        }

        $sidebars[$sidebar] = $assigned;
        wp_set_sidebars_widgets($sidebars);
    }

    public static function deactivate()
    {
        $timestamp = wp_next_scheduled('mlshop_expire_pending_orders');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'mlshop_expire_pending_orders');
        }
        // 物流第二批：反注册自动物流查询调度
        wp_clear_scheduled_hook('moonlight_shipping_sync');
        flush_rewrite_rules();
    }
}
