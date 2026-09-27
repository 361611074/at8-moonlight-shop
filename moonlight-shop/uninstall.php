<?php
/**
 * 卸载脚本：仅在 WordPress 标准卸载流程下执行。
 *
 * @package Moonlight_Shop
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 先读取选项（再删除），用于清理自动创建的页面。
$options = get_option('mlshop_options', array());

// 删除自动创建的页面（仅当内容仍是本插件短代码时）
foreach (array('products', 'cart', 'checkout') as $key) {
    $page_id = isset($options[$key . '_page_id']) ? (int) $options[$key . '_page_id'] : 0;
    if ($page_id) {
        $page = get_post($page_id);
        if ($page && strpos($page->post_content, 'mlshop_') !== false) {
            wp_delete_post($page_id, true);
        }
    }
}

// 清理设置项（独立 option + 数组）
delete_option('mlshop_options');
$single_keys = array(
    'currency', 'currency_symbol', 'store_email',
    'stripe_test_mode', 'stripe_test_publishable', 'stripe_test_secret',
    'stripe_publishable', 'stripe_secret', 'stripe_webhook_secret',
    'paypal_sandbox', 'paypal_client_id', 'paypal_secret', 'paypal_webhook_id',
);
foreach ($single_keys as $k) {
    delete_option('mlshop_' . $k);
}
