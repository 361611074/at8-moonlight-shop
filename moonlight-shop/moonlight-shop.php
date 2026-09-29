<?php
/**
 * Plugin Name:      漫步白月光电子商城
 * Plugin URI:       https://www.at8.fun/
 * Description:       轻量、主题无关的电子商城系统，兼容 Astra 主题与 Elementor。支持实物 / 虚拟下载 / 卡密商品，提供购物车、结算、订单全流程；支付网关内置支付宝 / 微信（预留）、PayPal、Stripe、余额、积分、货到付款与线下转账；支持运费模板、物流轨迹查询与售后退款；与「漫步白月光用户中心」账户中心无缝集成。
 * Version:          3.0.0
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

define('MLSHOP_VERSION', '3.0.0');
define('MLSHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLSHOP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MLSHOP_PLUGIN_FILE', __FILE__);

/**
 * 会员中心并入（Phase A）：旧「漫步白月光用户中心」插件激活时由其文件头定义
 * MLUC_LEGACY_ACTIVE，并入模块整体让位；未激活时由商城侧补齐 MLUC_* 兼容常量，
 * 使并入的 MLUC_ 类（includes/user/）在仅装商城时同样可用。
 */
if (!defined('MLUC_VERSION')) {
    define('MLUC_VERSION', MLSHOP_VERSION);
}
if (!defined('MLUC_PLUGIN_DIR')) {
    define('MLUC_PLUGIN_DIR', MLSHOP_PLUGIN_DIR);
}
if (!defined('MLUC_PLUGIN_URL')) {
    define('MLUC_PLUGIN_URL', MLSHOP_PLUGIN_URL);
}
if (!defined('MLUC_PLUGIN_FILE')) {
    define('MLUC_PLUGIN_FILE', MLSHOP_PLUGIN_FILE);
}

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

// 会员中心并入模块（Phase A）：MLUC_ 前缀 → includes/user/class-*.php。
// 旧插件激活时其自带自动加载器与本映射指向等价实现（先注册者先命中，单实例加载，无冲突）。
spl_autoload_register(function ($class) {
    $prefix = 'MLUC_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = MLSHOP_PLUGIN_DIR . 'includes/user/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
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
    // 并入的用户模块沿用原 text domain（moonlight-user-center），复用随包分发的 .po/.mo；
    // 旧插件激活时由旧插件自行注册，不重复加载。
    if (!defined('MLUC_LEGACY_ACTIVE')) {
        load_plugin_textdomain('moonlight-user-center', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
});

register_activation_hook(__FILE__, array('MLSHOP_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('MLSHOP_Activator', 'deactivate'));

/**
 * 并入的用户模块是否应当由商城侧启动。
 *
 * 旧「漫步白月光用户中心」插件激活时（其文件头定义 MLUC_LEGACY_ACTIVE）
 * 或 MLUC_Auth 已被旧插件加载时，商城侧全部让位，两插件共存行为与现状一致。
 *
 * @return bool
 */
function mlshop_user_modules_should_boot()
{
    if (defined('MLUC_LEGACY_ACTIVE')) {
        return false;
    }
    if (class_exists('MLUC_Auth', false)) {
        return false;
    }
    return true;
}

/**
 * 启动并入的用户模块单例（Phase A，无行为变更并入）。
 *
 * 说明：
 * - MLUC_Payments / MLUC_Purchases / MLUC_Paywall / MLUC_Account_Orders / PayPal·Stripe·Alipay
 *   网关类不随 Phase A 并入（Phase C/D 处理），此处不实例化；
 * - MLUC_Payment_Manager 保留（注册表无内置网关可注册，过滤器仍对第三方开放）；
 * - 页面创建（原 MLUC_Activator）Phase B 再并入。
 */
function mlshop_boot_user_modules()
{
    if (!mlshop_user_modules_should_boot()) {
        return;
    }
    MLUC_Auth::get_instance();
    MLUC_Account::get_instance();
    MLUC_Avatar::get_instance();
    MLUC_Assets::get_instance();
    MLUC_Membership::get_instance();
    MLUC_Video::get_instance();
    MLUC_Material::get_instance();
    MLUC_Settings::get_instance();
    MLUC_OAuth::get_instance();
    MLUC_Hidecontent::get_instance();
    MLUC_Editor_Button::get_instance();
    MLUC_Menu::get_instance();
    MLUC_License_Manager::get_instance();
    MLUC_License_Admin::get_instance();
    MLUC_Email_Notifications::get_instance();
    MLUC_System_Status::get_instance();
    MLUC_Payment_Manager::get_instance();
    MLUC_Migration_Status::get_instance();
    do_action('mluc_loaded');
}

add_action('plugins_loaded', function () {
    // 会员中心并入模块（Phase A）：旧插件激活时整体让位（见 mlshop_boot_user_modules）
    mlshop_boot_user_modules();

    // 新架构核心层（DB_VERSION 升级机制等）
    Moonlight_DB_Migrator::init();

    // 卡密库存服务（加密批次模型）：注册批次 CPT 等钩子
    Moonlight_Card_Stock::init();

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
    // moonlight/v1 REST API（公开 + 登录路由；admin / 回调路由见 PAYMENT.md，另行挂载）
    Moonlight_REST::get_instance();
    // 订单统计：自带子菜单 + 服务端 SVG 图表，零外部依赖（2026-08-28）
    // 商城仪表盘（Phase 9）：概览卡片 + 卡密库存预警，重排为商城菜单默认首页
    if (is_admin()) {
        MLSHOP_Dashboard::get_instance();
        MLSHOP_Statistics::get_instance();
        MLSHOP_Admin::get_instance();
    }
});
