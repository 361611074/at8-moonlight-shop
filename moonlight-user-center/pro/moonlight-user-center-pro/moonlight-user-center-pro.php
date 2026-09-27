<?php
/**
 * Plugin Name:      漫步白月光用户中心 Pro
 * Description:      moonlight-user-center 的 Pro 扩展：License 激活管理、Elementor 会员状态卡、订单 CSV 导出等 Pro 功能。必须先安装并启用「漫步白月光用户中心」。
 * Version:          2.0.0
 * Author:           漫步白月光
 * Author URI:       https://www.at8.fun/
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      moonlight-user-center-pro
 * Domain Path:      /languages
 *
 * @package           Moonlight_User_Center_Pro
 */

// 阻止直接访问
if (!defined('ABSPATH')) {
    exit;
}

define('MLUCP_VERSION', '2.0.0');
define('MLUCP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLUCP_PLUGIN_FILE', __FILE__);
define('MLUCP_PRODUCT', 'moonlight-user-center-pro');

/**
 * 自动加载 Pro 内部类（MLUCP_ 前缀，与 Free 的 MLUC_ 隔离，避免冲突）。
 */
spl_autoload_register(function ($class) {
    $prefix = 'MLUCP_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = MLUCP_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// 加载翻译
add_action('init', function () {
    load_plugin_textdomain('moonlight-user-center-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * 初始化：强依赖 Free 插件（依赖方向 Pro → Free，单向）。
 * Free 未启用时不做任何事，仅提示；Free 的功能不受本插件影响。
 */
add_action('plugins_loaded', function () {
    if (!defined('MLUC_VERSION') || !class_exists('MLUC_License_Manager')) {
        add_action('admin_notices', function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>' .
                esc_html__('「漫步白月光用户中心 Pro」需要先安装并启用「漫步白月光用户中心」（Free 版）才能工作。', 'moonlight-user-center-pro') .
                '</p></div>';
        });
        return;
    }

    MLUCP_License_Client::get_instance();
    MLUCP_Order_Export::get_instance();
    MLUCP_Elementor_Integration::get_instance();

    /**
     * Pro 扩展加载完成。
     */
    do_action('mlucp_loaded');
}, 20);
