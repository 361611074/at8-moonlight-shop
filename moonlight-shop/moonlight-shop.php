<?php
/**
 * Plugin Name:      漫步白月光电子商城
 * Plugin URI:       https://www.at8.fun/
 * Description:       轻量、主题无关的电子商城系统，兼容 Astra 主题与 Elementor。支持实物、虚拟下载、卡密商品，提供购物车、结算、订单与可扩展支付网关。与「漫步白月光用户中心」无缝集成。
 * Version:          1.7.4
 * Author:           漫步白月光
 * Author URI:       https://www.at8.fun/
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      moonlight-shop
 * Domain Path:      /languages
 *
 * @package           Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MLSHOP_VERSION', '1.7.4');
define('MLSHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLSHOP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MLSHOP_PLUGIN_FILE', __FILE__);

spl_autoload_register(function ($class) {
    $prefix = 'MLSHOP_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = MLSHOP_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// 新架构核心层（Phase 2 起）：Moonlight_* 类统一放 includes/core/，
// 旧 MLSHOP_* 类保持原路径不动，迁移期两套命名并存。
spl_autoload_register(function ($class) {
    $prefix = 'Moonlight_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = MLSHOP_PLUGIN_DIR . 'includes/core/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

require_once MLSHOP_PLUGIN_DIR . 'includes/functions.php';

// 加载翻译：跟随 WordPress 系统语言设定（get_locale），不写死语言。
// 英文站点：前台与 AJAX 加载英文翻译，wp-admin 后台保持中文源文案。
add_action('init', function () {
    $is_admin_screen = is_admin() && !wp_doing_ajax();
    if ($is_admin_screen && 0 === strpos(get_locale(), 'en')) {
        return;
    }
    load_plugin_textdomain('moonlight-shop', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

register_activation_hook(__FILE__, array('MLSHOP_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('MLSHOP_Activator', 'deactivate'));

add_action('plugins_loaded', function () {
    // 新架构核心层（DB_VERSION 升级机制等）
    Moonlight_DB_Migrator::init();

    MLSHOP_Product::get_instance();
    MLSHOP_Product_Pay_Meta::get_instance();
    MLSHOP_Credit::get_instance();
    MLSHOP_Credit_UI::get_instance();
    MLSHOP_Pay_Access::get_instance();
    MLSHOP_Cart::get_instance();
    MLSHOP_Order::get_instance();
    MLSHOP_Payment::get_instance();
    MLSHOP_Coupon::get_instance();
    MLSHOP_Membership_UI::get_instance();
    MLSHOP_Download::get_instance();
    MLSHOP_Shipping::get_instance();
    MLSHOP_Ajax::get_instance();
    MLSHOP_Favorite::get_instance();
    MLSHOP_Widgets::get_instance();
    MLSHOP_Account_Tab::get_instance();
    MLSHOP_Assets::get_instance();
    MLSHOP_Header_Actions::get_instance();
    MLSHOP_Email::get_instance();
    MLSHOP_Elementor::get_instance();
    MLSHOP_Bulk_Email::get_instance();
    // 订单统计：自带子菜单 + 服务端 SVG 图表，零外部依赖（2026-08-28）
    if (is_admin()) {
        MLSHOP_Statistics::get_instance();
        MLSHOP_Admin::get_instance();
    }
});
