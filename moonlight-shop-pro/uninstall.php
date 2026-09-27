<?php
/**
 * 卸载脚本（Moonlight Shop Pro）：仅在 WordPress 标准卸载流程下执行，
 * 清除本插件产生的全部数据（option / cron / 用户级 transient）。
 *
 * @package Moonlight_Shop_Pro
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1) 本插件全部 option：
//    - mlpro_local_active                 License 本地激活开关（License 客户端本地回退）
//    - mlpro_webhook_endpoints            出站 Webhook 端点订阅配置
//    - mlpro_webhook_queue                投递失败待重试队列
//    - mlpro_webhook_log                  投递日志（环形 ≤100 条）
//    - mlpro_analytics_cache_7/30/90      Pro 统计 option 缓存（7/30/90 天窗口）
$mlpro_options = array(
    'mlpro_local_active',
    'mlpro_webhook_endpoints',
    'mlpro_webhook_queue',
    'mlpro_webhook_log',
    'mlpro_analytics_cache_7',
    'mlpro_analytics_cache_30',
    'mlpro_analytics_cache_90',
);
foreach ($mlpro_options as $mlpro_option) {
    delete_option($mlpro_option);
}

// 2) 反注册 Webhook 重试 cron（激活时 wp_schedule_event(time() + 60, 'mlpro_5min', 'mlpro_webhook_retry')）。
wp_clear_scheduled_hook('mlpro_webhook_retry');

// 3) License 客户端的用户级提示 transient（按用户 ID 生成，成对清理 _transient_ 与 _transient_timeout_）。
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_mlpro\\_lic\\_notice\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_mlpro\\_lic\\_notice\\_%'"
);
