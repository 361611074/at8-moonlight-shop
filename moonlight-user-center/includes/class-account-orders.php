<?php
/**
 * 账户中心「我的订单」Tab：当前用户的订单历史（会员 + 付费墙统一）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Account_Orders
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('mluc_account_tabs', array($this, 'register_tab'));
    }

    public function register_tab($tabs)
    {
        $tabs['orders'] = array(
            'title'    => mluc_ui_label('tab_orders', __('My Orders', 'moonlight-user-center')),
            'icon'     => 'dashicons-list-view',
            'callback' => array($this, 'render_tab'),
        );
        return $tabs;
    }

    /**
     * 用户订单（含订单号 / 网关 / 类型），供模板渲染。
     */
    public static function get_user_order_rows($user_id, $limit = 20)
    {
        $q = get_posts(array(
            'post_type'      => MLUC_Payments::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array('key' => '_mluc_pay_user', 'value' => (int) $user_id, 'compare' => '='),
            ),
        ));
        $rows = array();
        foreach ($q as $oid) {
            $oid  = (int) $oid;
            $type = (string) get_post_meta($oid, '_mluc_pay_type', true);
            $item = '';
            if ('paywall' === $type) {
                $pw_post = (int) get_post_meta($oid, '_mluc_pay_post', true);
                $item    = $pw_post ? get_the_title($pw_post) : __('付费内容', 'moonlight-user-center');
            } else {
                $level = (string) get_post_meta($oid, '_mluc_pay_level', true);
                $item  = class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label($level) : $level;
            }
            $rows[] = array(
                'id'       => $oid,
                'order_no' => (string) get_post_meta($oid, '_mluc_pay_order_no', true),
                'title'    => get_the_title($oid),
                'item'     => $item,
                'type'     => $type,
                'price'    => (float) get_post_meta($oid, '_mluc_pay_price', true),
                'currency' => (string) get_post_meta($oid, '_mluc_pay_currency', true),
                'gateway'  => (string) get_post_meta($oid, '_mluc_pay_gateway', true),
                'status'   => (string) get_post_meta($oid, '_mluc_pay_status', true),
                'date'     => get_the_date('', $oid),
            );
        }
        return $rows;
    }

    public function render_tab()
    {
        mluc_get_template('account-orders', array(
            'orders' => self::get_user_order_rows(get_current_user_id()),
            'symbol' => MLUC_Payments::get_currency_symbol(),
        ));
    }
}
