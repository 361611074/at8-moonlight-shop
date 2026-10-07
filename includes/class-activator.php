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

        // 会员中心并入（Phase B）：创建用户中心 4 页（账户中心 / 登录 / 注册 / 找回）。
        // 旧「漫步白月光用户中心」插件激活时（其文件头定义 MLUC_LEGACY_ACTIVE）整体让位，
        // 不创建这 4 页（旧插件的 MLUC_Activator 已有页面时亦不会重复创建）。
        self::create_user_pages();

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
     * 会员中心并入（Phase B）：创建用户中心页面，页面 ID 写入 mluc_options。
     *
     * 自 MLUC_Activator::activate() 并入（moonlight-user-center 原激活逻辑）：
     * 账户中心 / 登录 / 注册 / 找回密码 4 页，post_content 为对应 [mluc_*] 短代码。
     * 已有页面（option 记录且文章仍存在）时跳过，幂等可重复执行；
     * 停用逻辑不动页面（不删除任何内容）。
     *
     * @return array 本次新建的 post ID 列表
     */
    public static function create_user_pages()
    {
        // 旧插件激活期间让位：不创建页面、不写 mluc_options。
        if (defined('MLUC_LEGACY_ACTIVE')) {
            return array();
        }

        $options = get_option('mluc_options', array());
        if (!is_array($options)) {
            $options = array();
        }

        $pages = array(
            'account_page_id'      => array('账户中心', 'account', '[mluc_account]'),
            'login_page_id'        => array('登录', 'login', '[mluc_login]'),
            'register_page_id'     => array('注册', 'register', '[mluc_register]'),
            'lostpassword_page_id' => array('找回密码', 'lost-password', '[mluc_lostpassword]'),
        );

        $created = array();
        foreach ($pages as $opt_key => $cfg) {
            if (!empty($options[$opt_key]) && get_post($options[$opt_key])) {
                continue; // 已存在页面：跳过
            }
            $page_id = wp_insert_post(array(
                'post_title'   => $cfg[0],
                'post_name'    => $cfg[1],
                'post_content' => $cfg[2],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
            if ($page_id && !is_wp_error($page_id)) {
                $options[$opt_key] = $page_id;
                $created[]         = (int) $page_id;
            }
        }

        // 与原 MLUC_Activator 一致的默认值
        if (!isset($options['enable_avatar'])) {
            $options['enable_avatar'] = 1;
        }
        if (!isset($options['redirect_after_login'])) {
            $options['redirect_after_login'] = '';
        }

        update_option('mluc_options', $options);
        return $created;
    }

    /**
     * 商城侧栏（mlshop-shop）为空时，预置默认小工具，使商品列表/单页侧栏开箱即用。
     */
    public static function populate_shop_sidebar()
    {
        $sidebar = 'mlshop-shop';
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- 激活器需要检测侧栏
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

    /**
     * 隐私政策声明（WordPress.org 隐私指引 / wp_add_privacy_policy_content）。
     *
     * 说明本插件收集与存储哪些个人数据、存多久、以及第三方服务（支付网关 /
     * 快递100 / 自建授权中心）的对外传输场景，供站长在隐私政策向导中一键采纳。
     */
    public static function add_privacy_policy_content()
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        $content = '<p>' . esc_html__(
            '本插件（商城）在访客下单时收集订单所需信息：联系邮箱，以及实物商品下单时填写的收货人姓名、电话与收货地址。这些数据以订单（mlshop_order）形式存储在站点数据库中，仅用于订单履约与售后；删除订单即删除对应数据。注册用户还会有账户资料、积分流水、收货地址簿与已购记录。',
            'at8-moonlight-shop'
        ) . '</p>'
            . '<p>' . esc_html__(
                '游客订单会生成一个随机的访问令牌（用于凭邮箱查看订单），令牌随订单长期有效；站长删除订单即同时使其失效。',
                'at8-moonlight-shop'
            ) . '</p>'
            . '<p>' . esc_html__(
                '对外传输仅发生在：使用支付宝 / 微信支付 / PayPal / Stripe 付款时按服务商要求传递订单号、金额与商品标题；启用快递100 轨迹查询时向其发送运单号；配置授权中心时向该服务器发送订单号、买家邮箱与商品/套餐代码。插件默认不向插件作者发送任何数据。',
                'at8-moonlight-shop'
            ) . '</p>';
        wp_add_privacy_policy_content('AT8 Moonlight Shop', wp_kses_post($content));
    }
}

