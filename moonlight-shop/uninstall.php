<?php
/**
 * 卸载脚本：仅在 WordPress 标准卸载流程下执行。
 *
 * @package Moonlight_Shop
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

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
    'alipay_enabled', 'alipay_mode', 'alipay_app_id', 'alipay_private_key', 'alipay_public_key',
);
foreach ($single_keys as $k) {
    delete_option('mlshop_' . $k);
}

/* ---------------- 卡密系统（加密批次模型）清理 ---------------- */

// 1) 删除卡密批次 CPT（mlshop_card_batch）：强制删除（不入回收站），
//    wp_delete_post 会连带删除批次下的卡密 postmeta 行。
$batch_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
        'mlshop_card_batch'
    )
);
foreach ((array) $batch_ids as $batch_id) {
    wp_delete_post((int) $batch_id, true);
}

// 2) 兜底清扫残留卡密 postmeta（user 无关）：
//    _mlshop_card_a/_s/_u/_x（卡密本体）、_mlshop_cardkeys（旧明文池）、
//    _mlshop_cardkeys_migrated（迁移标记）、_mlshop_batch_note/_mlshop_batch_expires（批次 meta）。
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
     WHERE meta_key LIKE '\\_mlshop\\_card\\_%'
        OR meta_key IN ('_mlshop_batch_note', '_mlshop_batch_expires')"
);

// 3) 审计日志 option（含解密查看记录，一并清除）。
delete_option('_mlshop_card_audit');

// 3.5) 游客购买：游客令牌 / 联系邮箱 / 限流计数 transient（成对删除）。
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
     WHERE meta_key IN ('_mlshop_guest_email', '_mlshop_guest_token', '_mlshop_guest_created')"
);
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_mlshop\_guest\_ord\_%'
        OR option_name LIKE '\_transient\_timeout\_mlshop\_guest\_ord\_%'"
);

// 5) 物流第一批：运费模板 option + 到店自提开关 + 用户地址簿 usermeta。
delete_option('moonlight_shipping_templates');
delete_option('mlshop_pickup_enabled');
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'moonlight_addresses'"
);

// 6) 物流第二批：Provider 配置 / 自动查询 / 签收自动完成 option + 发货单 CPT（连带 _mlship_* meta）。
$shipping_single_keys = array(
    'shipping_provider', 'shipping_kuaidi100_key', 'shipping_kuaidi100_customer',
    'shipping_auto_sync', 'auto_complete_days',
);
foreach ($shipping_single_keys as $k) {
    delete_option('mlshop_' . $k);
}
delete_option('moonlight_shipping_last_sync');
$shipment_ids = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
        'mlshop_shipment'
    )
);
foreach ((array) $shipment_ids as $shipment_id) {
    wp_delete_post((int) $shipment_id, true);
}
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_mlshop\\_ship\\_%' OR meta_key LIKE '\\_mlship\\_%'"
);

// 4) 卡密库存预警防重复 transient（_transient_ 与 _transient_timeout_ 成对删除）。
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_mlshop\\_card\\_low\\_stock\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_mlshop\\_card\\_low\\_stock\\_%'"
);

/* ---------------- 新架构核心层清理（审计 F8） ---------------- */

// 新结构 option（归组配置 / DB 版本 / 迁移日志与标记）
delete_option('moonlight_shop_options');
delete_option('moonlight_db_version');
delete_option('moonlight_db_upgrade_error');
delete_option('moonlight_migration_log');
delete_option('moonlight_consent_migrate_mluc');
delete_option('moonlight_shipping_last_sync');
delete_option('mlshop_enabled_gateways');
delete_option('mlshop_url_slugs');

// 下载 token / 付费墙下载 / 群发队列 / 价格缓存 等 transient（成对删除）
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_mlshop\\_dl\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_mlshop\\_dl\\_%'
        OR option_name LIKE '\\_transient\\_mlshop\\_pw\\_dl\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_mlshop\\_pw\\_dl\\_%'
        OR option_name LIKE '\\_transient\\_mlshop\\_bulk\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_mlshop\\_bulk\\_%'
        OR option_name = '_transient_mlshop_price_bounds_v1'
        OR option_name = '_transient_timeout_mlshop_price_bounds_v1'
        OR option_name LIKE '\\_transient\\_mlshop\\_paypal\\_token%'
        OR option_name LIKE '\\_transient\\_timeout\\_mlshop\\_paypal\\_token%'"
);
