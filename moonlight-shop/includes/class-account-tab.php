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
    }

    public function add_tab($tabs)
    {
        $tabs['orders'] = array(
            'title'    => __('我的订单', 'moonlight-shop'),
            'icon'     => 'dashicons-cart',
            'callback' => array($this, 'render_tab'),
        );
        if (class_exists('MLSHOP_Download')) {
            $tabs['downloads'] = array(
                'title'    => __('我的下载', 'moonlight-shop'),
                'icon'     => 'dashicons-download',
                'callback' => array($this, 'render_downloads'),
            );
        }
        if (class_exists('MLSHOP_Coupon')) {
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
