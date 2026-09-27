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

// 删除插件创建的选项
delete_option('mluc_options');
