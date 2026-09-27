<?php
/**
 * 卸载脚本：仅在 WordPress 标准卸载流程下执行。
 *
 * @package Moonlight_User_Center
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 先读取选项（再删除），用于清理自动创建的页面。
$options  = get_option('mluc_options', array());

// 可选：删除自动创建的页面（仅当页面内容仍是本插件短代码时）
foreach (array('account_page_id', 'login_page_id', 'register_page_id') as $opt) {
    $page_id = isset($options[$opt]) ? (int) $options[$opt] : 0;
    if (!$page_id) {
        continue;
    }
    $page = get_post($page_id);
    if ($page && strpos($page->post_content, 'mluc_') !== false) {
        wp_delete_post($page_id, true);
    }
}

// 取消计划任务（Pending 订单自动关闭 / License 到期提醒）
if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('mluc_pay_autoclose');
    wp_clear_scheduled_hook('mluc_license_expiry_check');
}

// 删除订单数据（mluc_order 为交易凭证，随插件一同移除）
$orders = get_posts(array(
    'post_type'      => 'mluc_order',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
));
foreach ($orders as $order_id) {
    wp_delete_post((int) $order_id, true);
}

// 删除 License 数据（mluc_license 随插件一同移除）
$licenses = get_posts(array(
    'post_type'      => 'mluc_license',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
));
foreach ($licenses as $license_id) {
    wp_delete_post((int) $license_id, true);
}

// 清理本插件写入的用户资料（会员等级 / 到期 / 付费解锁 / 第三方绑定 / 头像 / 下载计数）
global $wpdb;
// 注意：不清理通用键（如 phone），避免误删其他插件写入的用户资料。
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mluc_%'"
);

// 清理 transient（限流 / OAuth state / 下载令牌 / 后台提示）
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_mluc\_%'
        OR option_name LIKE '\_transient\_timeout\_mluc\_%'"
);

// 删除插件创建的选项
delete_option('mluc_options');

// 说明：教材（mluc_material）/ 影片（mluc_video）/ 头像库（mluc_avatar）属于站长创建的内容，
// 卸载时保留不删，避免误删站点内容；如需彻底清理请在后台手动删除。
