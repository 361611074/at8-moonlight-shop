<?php
/**
 * 与「漫步白月光用户中心」集成：在账户中心添加「我的订单」Tab。
 * 若用户中心未启用，则提供独立短代码 [mlshop_orders]。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Account_Tab
{
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (class_exists('MLUC_Account')) {
            add_filter('mluc_account_tabs', array($this, 'add_tab'));
        }
        add_shortcode('mlshop_orders', array($this, 'shortcode_orders'));
        // 用户中心未启用时的地址簿回退短代码
        add_shortcode('mlshop_address', array($this, 'shortcode_address'));
    }

    public function add_tab($tabs)
    {
        // Phase B 去重：mluc_account_tabs 过滤器可能被多处挂载（如旧用户中心插件共存时，
        // 其 MLUC_Account_Orders / MLUC_Purchases 已注册 orders / purchases Tab）。
        // 已存在的同 key Tab 不覆盖——先挂载者胜出，避免同一 Tab 被两处重复注册。
        if (!isset($tabs['orders'])) {
            $tabs['orders'] = array(
                'title'    => __('我的订单', 'moonlight-shop'),
                'icon'     => 'dashicons-cart',
                'callback' => array($this, 'render_tab'),
            );
        }
        if (!isset($tabs['addresses'])) {
            $tabs['addresses'] = array(
                'title'    => __('收货地址', 'moonlight-shop'),
                'icon'     => 'dashicons-location',
                'callback' => array($this, 'render_addresses'),
            );
        }
        if (!isset($tabs['downloads']) && class_exists('MLSHOP_Download')) {
            $tabs['downloads'] = array(
                'title'    => __('我的下载', 'moonlight-shop'),
                'icon'     => 'dashicons-download',
                'callback' => array($this, 'render_downloads'),
            );
        }
        if (!isset($tabs['coupons']) && class_exists('MLSHOP_Coupon')) {
            $tabs['coupons'] = array(
                'title'    => __('我的优惠券', 'moonlight-shop'),
                'icon'     => 'dashicons-tickets-alt',
                'callback' => array($this, 'render_coupons'),
            );
        }
        return $tabs;
    }

    public function render_tab()
    {
        $this->render_orders();
    }

    public function shortcode_orders()
    {
        // 缓存兼容：订单列表为用户态内容，禁止页面缓存（计划书第五十九节）
        mlshop_no_cache();
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('请先登录查看订单。', 'moonlight-shop') . '</p>';
        }
        ob_start();
        $this->render_orders();
        return ob_get_clean();
    }

    private function render_orders()
    {
        $orders = MLSHOP_Order::get_user_orders(get_current_user_id(), 20);
        foreach ($orders as $o) {
            MLSHOP_Order::maybe_expire($o->ID);
        }
        mlshop_get_template('account-orders', array('orders' => $orders));
    }

    /**
     * 收货地址 Tab（用户中心挂载 + [mlshop_address] 短代码共用渲染）。
     */
    public function render_addresses()
    {
        if (!is_user_logged_in()) {
            echo '<p class="mlshop-message">' . esc_html__('请先登录管理收货地址。', 'moonlight-shop') . '</p>';
            return;
        }
        mlshop_get_template('account-addresses', array(
            'addresses' => class_exists('Moonlight_Address_Book')
                ? Moonlight_Address_Book::get_list(get_current_user_id())
                : array(),
        ));
    }

    /**
     * [mlshop_address] 短代码：用户中心未启用时的地址簿回退入口。
     */
    public function shortcode_address()
    {
        // 缓存兼容：地址簿为用户态内容，禁止页面缓存（计划书第五十九节）
        mlshop_no_cache();
        ob_start();
        $this->render_addresses();
        return ob_get_clean();
    }

    /**
     * 我的下载 Tab。
     */
    public function render_downloads()
    {
        if (!class_exists('MLSHOP_Download')) {
            return;
        }
        echo MLSHOP_Download::get_instance()->shortcode_downloads();
    }

    /**
     * 我的优惠券 Tab：列出当前用户历史订单中使用过的优惠码。
     */
    public function render_coupons()
    {
        $orders = MLSHOP_Order::get_user_orders(get_current_user_id(), 50);
        $used = array();
        foreach ($orders as $o) {
            $code = get_post_meta($o->ID, '_mlshop_coupon_code', true);
            if ($code) {
                $used[$code] = isset($used[$code]) ? $used[$code] + 1 : 1;
            }
        }
        mlshop_get_template('account-coupons', array('used' => $used));
    }
}
