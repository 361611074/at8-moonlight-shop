<?php
/**
 * Plugin Name:      漫步白月光电子商城
 * Plugin URI:       https://www.at8.fun/
 * Description:       轻量、主题无关的电子商城系统，兼容 Astra 主题与 Elementor。支持实物 / 虚拟下载 / 卡密商品，提供购物车、结算、订单全流程；支付网关内置支付宝 / 微信（预留）、PayPal、Stripe、余额、积分、货到付款与线下转账；支持运费模板、物流轨迹查询与售后退款；与「漫步白月光用户中心」账户中心无缝集成。
 * Version:          3.1.1
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

define('MLSHOP_VERSION', '3.1.1');
define('MLSHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLSHOP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MLSHOP_PLUGIN_FILE', __FILE__);

/**
 * 会员中心并入（Phase A → 事故修复）：
 *
 * MLUC_* 兼容常量与 MLUC_ 自动加载器**不能在文件作用域定义**——
 * 插件文件的加载顺序由 active_plugins 数组决定，若商城先于旧插件加载，
 * 此处抢注常量（指向商城目录）会让旧插件随后的 define 全部失败，
 * 旧插件自己的自动加载器随之指向错误目录，全站致命（v3.0 线上事故）。
 *
 * 正确时机是 plugins_loaded：此时所有插件文件均已执行完毕，
 * MLUC_LEGACY_ACTIVE（旧插件文件头定义）已可靠——据此判定：
 *  - 旧插件激活 → 什么都不做（旧插件自己的常量与加载器接管）；
 *  - 仅装商城   → 补齐 MLUC_* 常量并注册 MLUC_ 加载器（includes/user/）。
 */
function mlshop_register_mluc_compat()
{
    if (defined('MLUC_LEGACY_ACTIVE')) {
        return;
    }
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
}
add_action('plugins_loaded', 'mlshop_register_mluc_compat', 5);

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
require_once MLSHOP_PLUGIN_DIR . 'includes/buy-texts.php';

// AT8 授权中心桥接（P6）：支付成功 → 自动发授权码；退款 → 自动吊销。
MLSHOP_License_Bridge::boot();

// WooCommerce 商品一键迁入工具（后台「设置 → 商品标签」区）。
Moonlight_Woo_Migrate::boot();

// 用户中心「我的授权」Tab（P7）：展示授权/绑定站点/自助解绑。
require_once MLSHOP_PLUGIN_DIR . 'includes/user/class-licenses-tab.php';
MLUC_Licenses_Tab::boot();

// 前台 HTML 页面禁用 CDN/代理缓存（真机教训：改版后旧页面在 CDN 长期滞留，
// 用户看不到新外观/新功能；静态资源带版本号不受影响，照常走 CDN 加速）。
add_action('send_headers', function () {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || defined('WP_CLI')) {
        return;
    }
    nocache_headers();
});

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
 * - MLUC_Payment_Manager 不再启动（支付统一由商城网关提供，详见下方注释）；
 * - 页面创建（原 MLUC_Activator）Phase B 再并入。
 */
function mlshop_boot_user_modules()
{
    if (!mlshop_user_modules_should_boot()) {
        return;
    }
    // 积分 / 余额的旧类名别名：并入模块的 MLUC_Checkin 等仍按旧名调用，
    // 用 class_alias 指向商城实现（同一个类，不产生第二份实例与重复钩子）。
    if (!class_exists('MLUC_Credit', false) && class_exists('MLSHOP_Credit')) {
        class_alias('MLSHOP_Credit', 'MLUC_Credit');
    }
    if (!class_exists('MLUC_Payment_Log', false) && class_exists('MLSHOP_Payment_Log')) {
        class_alias('MLSHOP_Payment_Log', 'MLUC_Payment_Log');
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
    // MLUC_Payment_Manager 不再启动：并入态下 UC 独立支付网关未随迁，
    // 空注册表只会让设置页出现「填了也不生效」的死配置（支付统一走商城网关）。
    // 独立用户中心插件激活时由其自身启动，行为不变。
    MLUC_Migration_Status::get_instance();
    // 并入：已购教材 / 每日签到 / 余额钱包（此前只在独立用户中心插件里，
    // 商城单独安装时会缺失这三个模块，导致 [mluc_purchases] 无输出、
    // 签到与钱包入口不可用）。现在单插件即可完整覆盖。
    // class_exists + method_exists 守卫：离线测试桩（tests/wp-stubs.php）里
    // MLUC_Wallet 等可能是无 get_instance 的空壳占位类，跳过即可。
    foreach (array('MLUC_Purchases', 'MLUC_Checkin', 'MLUC_Wallet') as $mluc_user_class) {
        if (class_exists($mluc_user_class) && method_exists($mluc_user_class, 'get_instance')) {
            call_user_func(array($mluc_user_class, 'get_instance'));
        }
    }
    // 补齐前台页面并修正失效的页面 ID 配置（单插件形态下没有独立插件的激活器了）。
    // 必须延后到 wp_loaded 且仅在后台执行：wp_insert_post() 会调用 get_permalink()，
    // 而 rewrite 规则在 plugins_loaded 阶段尚未就绪，提前建页会直接 Fatal。
    // 前台零开销：只在后台、且固定链接结构变化后才同步一次。
    add_action('wp_loaded', function () {
        if (!is_admin() || !class_exists('MLUC_Page_Sync')) {
            return;
        }
        $sig = (string) get_option('permalink_structure');
        if ((string) get_option('mluc_pages_synced_stamp') === $sig) {
            return; // 本结构下已同步过
        }
        MLUC_Page_Sync::boot();
        update_option('mluc_pages_synced_stamp', $sig);
    }, 20);
    do_action('mluc_loaded');
}

add_action('plugins_loaded', function () {
    // 会员中心并入模块（Phase A）：旧插件激活时整体让位（见 mlshop_boot_user_modules）
    mlshop_boot_user_modules();

    // 新架构核心层（DB_VERSION 升级机制等）
    Moonlight_DB_Migrator::init();

    // 卡密库存服务（加密批次模型）：注册批次 CPT 等钩子
    Moonlight_Card_Stock::init();
    // 卡密自动生成（后台「卡密库存」页一键批量生成，走同一加密批次入库）
    Moonlight_Card_Generator::init();

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
