<?php
/**
 * 通用辅助函数。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -----------------------------------------------------------------
 * 基础辅助（与商城 includes/functions.php 的合并区块同名单）：
 * 全部 function_exists 守卫——两插件同装且商城先加载时，商城侧已定义
 * 同名函数（其区块在其加载期探测 MLUC_LEGACY_ACTIVE 为时尚早，必然定义），
 * 此处必须让位，否则「Cannot redeclare」全站致命。两侧实现兼容，
 * 谁先加载谁生效；商城版 mluc_get_template 为本版超集（多一层候选）。
 * ----------------------------------------------------------------- */

if (!function_exists('mluc_get_option')) {
    /**
     * 读取插件选项。
     */
    function mluc_get_option($key, $default = '')
    {
        $options = get_option('mluc_options', array());
        return isset($options[$key]) ? $options[$key] : $default;
    }
}

if (!function_exists('mluc_ui_label')) {
    /**
     * 获取账户中心界面文案：后台「用户中心 → 设置 → 界面文案」可自定义，
     * 未填写或留空时回退到内置默认（可传英文等其他语言）。
     */
    function mluc_ui_label($key, $default = '')
    {
        $labels = mluc_get_option('ui_labels', array());
        if (is_array($labels) && isset($labels[$key]) && '' !== trim((string) $labels[$key])) {
            return (string) $labels[$key];
        }
        return $default;
    }
}

if (!function_exists('mluc_get_account_url')) {
    /**
     * 获取账户中心页面 URL。
     */
    function mluc_get_account_url()
    {
        $page_id = (int) mluc_get_option('account_page_id', 0);
        if ($page_id) {
            return get_permalink($page_id);
        }
        return home_url('/account/');
    }
}

if (!function_exists('mluc_get_login_url')) {
    /**
     * 获取登录页 URL（无专用页面时回退到 WP 登录页并带回跳）。
     *
     * @param string $redirect 登录后回跳地址（同站 URL）。留空则回跳账户中心。
     */
    function mluc_get_login_url($redirect = '')
    {
        $page_id = (int) mluc_get_option('login_page_id', 0);
        if ($page_id && get_post($page_id)) {
            $url = get_permalink($page_id);
            if ($redirect) {
                $url = add_query_arg('redirect_to', $redirect, $url);
            }
            return $url;
        }
        return wp_login_url($redirect ? $redirect : mluc_get_account_url());
    }
}

if (!function_exists('mluc_get_register_url')) {
    /**
     * 获取注册页 URL（无专用页面时回退到 WP 注册页）。
     */
    function mluc_get_register_url()
    {
        $page_id = (int) mluc_get_option('register_page_id', 0);
        if ($page_id && get_post($page_id)) {
            return get_permalink($page_id);
        }
        return wp_registration_url();
    }
}

if (!function_exists('mluc_get_lostpassword_url')) {
    /**
     * 获取找回密码页 URL（无专用页面时回退到登录页内的找回密码视图）。
     */
    function mluc_get_lostpassword_url()
    {
        $page_id = (int) mluc_get_option('lostpassword_page_id', 0);
        if ($page_id && get_post($page_id)) {
            return get_permalink($page_id);
        }
        return add_query_arg('mluc_view', 'lostpassword', mluc_get_login_url());
    }
}

if (!function_exists('mluc_get_template')) {
    /**
     * 加载模板文件，主题可通过同名覆盖。
     *
     * @param string $slug 模板标识（不含 .php）
     * @param array  $args 传给模板的变量
     */
    function mluc_get_template($slug, $args = array())
    {
        if (is_array($args)) {
            extract($args);
        }

        $theme_file  = get_stylesheet_directory() . '/mluc/' . $slug . '.php';
        $plugin_file = MLUC_PLUGIN_DIR . 'templates/' . $slug . '.php';

        $file = file_exists($theme_file) ? $theme_file : $plugin_file;
        if (file_exists($file)) {
            include $file;
        }
    }
}

if (!function_exists('mluc_ajax_data')) {
    /**
     * 生成前端 AJAX 用的 nonce 与 action。
     */
    function mluc_ajax_data()
    {
        return array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mluc_nonce'),
        );
    }
}

if (!function_exists('mluc_count_user_comments')) {
    /**
     * 统计用户已通过审核的评论数。
     */
    function mluc_count_user_comments($user_id)
    {
        $count = get_comments(array(
            'user_id' => $user_id,
            'status'  => 'approve',
            'count'   => true,
        ));
        return $count ? (int) $count : 0;
    }
}

if (!function_exists('mluc_send_json')) {
    /**
     * 判断请求是否来自 AJAX 并返回 JSON 错误。
     */
    function mluc_send_json($success, $message, $data = array())
    {
        wp_send_json(array(
            'success' => (bool) $success,
            'message' => $message,
            'data'    => $data,
        ));
    }
}

/* -----------------------------------------------------------------
 * 积分与余额（v2.1.0）：原子账本辅助 + 充值/兑换比例配置读取。
 * 比例全部来自后台「用户中心 → 设置 → 积分与余额」，前台绝不传价。
 * ----------------------------------------------------------------- */

/**
 * 积分体系是否启用。
 */
function mluc_credit_enabled()
{
    return !empty(mluc_get_option('credit_enabled', 0));
}

/**
 * 余额钱包是否启用（余额支付 + 积分兑换的目标账本）。
 */
function mluc_balance_enabled()
{
    return !empty(mluc_get_option('balance_enabled', 0));
}

/**
 * 积分名称（积分 / 金币 / Z币 等）。
 */
function mluc_get_credit_name()
{
    $name = trim((string) mluc_get_option('credit_name', ''));
    return '' !== $name ? $name : __('积分', 'moonlight-user-center');
}

/**
 * 充值比例：1 个货币单位 = N 积分（N > 0，默认 10）。
 */
function mluc_get_credit_rate()
{
    $rate = (float) mluc_get_option('credit_rate', 10);
    return $rate > 0 ? $rate : 10;
}

/**
 * 积分兑换余额比例：N 积分 = 1 个货币单位余额（N > 0，默认 100）。
 */
function mluc_get_credit_exchange_rate()
{
    $rate = (float) mluc_get_option('credit_exchange_rate', 100);
    return $rate > 0 ? $rate : 100;
}

/**
 * 充值套餐：后台每行一条「积分|金额」（例：100|10），留空回退内置默认。
 *
 * @return array<int,array{credit:float,price:float}>
 */
function mluc_get_recharge_packages()
{
    $raw   = (string) mluc_get_option('credit_packages', '');
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out   = array();
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ('' === $line || false === strpos($line, '|')) {
                continue;
            }
            list($credit, $price) = array_map('trim', explode('|', $line, 2));
            $credit = (float) $credit;
            $price  = (float) $price;
            if ($credit > 0 && $price > 0) {
                $out[] = array('credit' => $credit, 'price' => $price);
            }
        }
    }
    if (empty($out)) {
        $out = array(
            array('credit' => 100, 'price' => 10),
            array('credit' => 500, 'price' => 45),
            array('credit' => 1000, 'price' => 80),
        );
    }
    return apply_filters('mluc_recharge_packages', $out);
}

/**
 * 自定义充值限额：array(min, max)，max = 0 表示不限制。
 *
 * @return array{min:int,max:int}
 */
function mluc_get_recharge_custom_limits()
{
    return array(
        'min' => max(1, (int) mluc_get_option('credit_custom_min', 10)),
        'max' => max(0, (int) mluc_get_option('credit_custom_max', 0)),
    );
}

/**
 * 原子累加数值型 user meta（并发充值 / 回补不丢更新）。
 * usermeta 无唯一索引：先确保单行存在（缺行时补插并合并重复行），再单条 UPDATE 累加。
 *
 * @param int    $user_id  用户 ID。
 * @param string $meta_key meta 键。
 * @param float  $amount   增量（> 0）。
 * @return float 更新后的值。
 */
function mluc_atomic_increment_user_meta($user_id, $meta_key, $amount)
{
    global $wpdb;
    $user_id = (int) $user_id;
    $amount  = (float) $amount;
    if ($user_id <= 0 || '' === $meta_key || $amount == 0.0) {
        return (float) get_user_meta($user_id, $meta_key, true);
    }

    // 1) 确保恰有一行：无则补插；多行（历史脏数据）合并求和后清理。
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
        $user_id,
        $meta_key
    ));
    if (0 === $count) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES (%d, %s, %f)",
            $user_id,
            $meta_key,
            $amount
        ));
        return (float) get_user_meta($user_id, $meta_key, true);
    }
    if ($count > 1) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} m
             JOIN (
                 SELECT MIN(meta_id) AS keep_id, SUM(meta_value + 0) AS total
                 FROM {$wpdb->usermeta}
                 WHERE user_id = %d AND meta_key = %s
             ) agg ON TRUE
             SET m.meta_value = agg.total
             WHERE m.meta_id = agg.keep_id",
            $user_id,
            $meta_key
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta}
             WHERE user_id = %d AND meta_key = %s
               AND meta_id <> (SELECT MIN(meta_id) FROM (SELECT meta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s) x)",
            $user_id,
            $meta_key,
            $user_id,
            $meta_key
        ));
    }

    // 2) 单条 UPDATE 原子累加。
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->usermeta} SET meta_value = meta_value + %f WHERE user_id = %d AND meta_key = %s",
        $amount,
        $user_id,
        $meta_key
    ));
    return (float) get_user_meta($user_id, $meta_key, true);
}

/**
 * 原子扣减数值型 user meta：SQL 条件保证「余额充足才扣」，
 * 并发请求同时消费时只有充足的那次成功，杜绝扣成负数。
 *
 * @param int    $user_id  用户 ID。
 * @param string $meta_key meta 键。
 * @param float  $amount   扣减量（> 0）。
 * @return bool 是否扣减成功（false = 余额不足或记录不存在）。
 */
function mluc_atomic_decrement_user_meta($user_id, $meta_key, $amount)
{
    global $wpdb;
    $user_id = (int) $user_id;
    $amount  = (float) $amount;
    if ($user_id <= 0 || '' === $meta_key || $amount <= 0) {
        return false;
    }
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->usermeta} SET meta_value = meta_value - %f
         WHERE user_id = %d AND meta_key = %s AND meta_value + 0 >= %f",
        $amount,
        $user_id,
        $meta_key,
        $amount
    ));
    return (int) $updated > 0;
}
