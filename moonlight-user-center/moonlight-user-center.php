<?php
/**
 * Plugin Name:      漫步白月光用户中心
 * Plugin URI:       https://www.at8.fun/
 * Description:       轻量、主题无关的 WordPress 用户中心，兼容 Astra 主题与 Elementor 页面构建器。提供前端登录、注册、找回密码、账户仪表盘、资料编辑、头像上传等功能。
 * Version:          2.0.0
 * Author:           漫步白月光
 * Author URI:       https://www.at8.fun/
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      moonlight-user-center
 * Domain Path:      /languages
 *
 * @package           Moonlight_User_Center
 */

// 阻止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 并入兼容标记（v3.0 Phase A）：本插件激活时置位，moonlight-shop 中的并入模块
// （includes/user/ 的 MLUC_ 单例）据此整体让位，两插件共存时行为与现状完全一致。
define('MLUC_LEGACY_ACTIVE', true);

define('MLUC_VERSION', '2.0.0');
define('MLUC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLUC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MLUC_PLUGIN_FILE', __FILE__);

/**
 * 自动加载插件内部类。
 */
spl_autoload_register(function ($class) {
    $prefix = 'MLUC_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = MLUC_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// 加载函数辅助
require_once MLUC_PLUGIN_DIR . 'includes/functions.php';

// 加载翻译：跟随 WordPress 系统语言设定（get_locale），不写死语言。
// 英文站点：前台与 AJAX 加载英文翻译，wp-admin 后台保持中文源文案。
add_action('init', function () {
    $is_admin_screen = is_admin() && !wp_doing_ajax();
    if ($is_admin_screen && 0 === strpos(get_locale(), 'en')) {
        return;
    }
    load_plugin_textdomain('moonlight-user-center', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// 激活 / 停用
register_activation_hook(__FILE__, array('MLUC_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('MLUC_Activator', 'deactivate'));

// 初始化各模块
add_action('plugins_loaded', function () {
    MLUC_Auth::get_instance();
    MLUC_Account::get_instance();
    MLUC_Avatar::get_instance();
    MLUC_Assets::get_instance();
    MLUC_Membership::get_instance();
    MLUC_Payments::get_instance();
    MLUC_Video::get_instance();
    MLUC_Material::get_instance();
    MLUC_Purchases::get_instance();
    MLUC_Settings::get_instance();
    MLUC_OAuth::get_instance();
    MLUC_Hidecontent::get_instance();
    MLUC_Editor_Button::get_instance();
    MLUC_Paywall::get_instance();
    MLUC_Menu::get_instance();

    // 支付 / License / 通知扩展模块（v2.0.0：Free + Pro 商业化体系）
    MLUC_License_Manager::get_instance();
    MLUC_License_Admin::get_instance();
    MLUC_Account_Orders::get_instance();
    MLUC_Email_Notifications::get_instance();
    MLUC_System_Status::get_instance();

    if (did_action('elementor/loaded')) {
        MLUC_Elementor::get_instance();
    }

    /**
     * 插件加载完成：Pro 扩展与第三方在此挂载（Pro 通过本钩子介入，Free 不反向依赖）。
     */
    do_action('mluc_loaded');
});
