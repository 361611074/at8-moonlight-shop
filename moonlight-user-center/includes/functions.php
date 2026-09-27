<?php
/**
 * 通用辅助函数。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 读取插件选项。
 */
function mluc_get_option($key, $default = '')
{
    $options = get_option('mluc_options', array());
    return isset($options[$key]) ? $options[$key] : $default;
}

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
