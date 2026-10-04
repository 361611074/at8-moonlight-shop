<?php
/**
 * Plugin Name:      Moonlight Shop Pro
 * Description:      漫步白月光电子商城（moonlight-shop）的 Pro 扩展：License 授权门禁（双产品语义，存量授权兼容）、Elementor 会员状态卡、出站 Webhook（HMAC 签名 + 重试退避）、Pro 统计（趋势 / Top10 / 渠道占比）与订单 CSV 导出。必须先安装并启用「漫步白月光电子商城」。
 * Version:          3.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:           漫步白月光
 * Author URI:       https://www.at8.fun/
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      moonlight-shop-pro
 * Domain Path:      /languages
 *
 * 依赖（Requires）：moonlight-shop（漫步白月光电子商城）Free 2.0+。
 * 授权引擎：moonlight-shop 并入的 MLUC_License_Manager（引擎缺席时回退本地
 * 开关 mlpro_local_active）；License 双产品语义：moonlight-shop-pro 与存量
 * moonlight-user-center-pro 任一激活即激活。旧 MLUCP Pro 仍激活时本插件的
 * Elementor 会员卡与 License 双检查让位（共存保护）。
 *
 * 依赖方向 Pro → Free 单向；零复制 Free 业务代码，仅调用其公开 API / 钩子。
 *
 * @package           Moonlight_Shop_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MLPRO_VERSION', '3.0.0');
define('MLPRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MLPRO_PLUGIN_FILE', __FILE__);
/** Pro 产品标识（与 License 引擎 is_product_active() 的产品参数一致）。 */
define('MLPRO_PRODUCT', 'moonlight-shop-pro');
/**
 * 自动加载 Pro 内部类（MLPRO_ 前缀 → includes/class-*.php，与 Free 的 MLSHOP_ 隔离）。
 */
spl_autoload_register(function ($class) {
    $prefix = 'MLPRO_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = MLPRO_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// cron 自定义间隔必须在文件作用域注册：插件激活请求里 register_activation_hook
// 在 plugins_loaded 之后执行，此时 Webhooks 实例尚不存在（照抄 Free class-activator 模式）。
add_filter('cron_schedules', array('MLPRO_Webhooks', 'cron_schedules'));

// 加载翻译
add_action('init', function () {
    load_plugin_textdomain('moonlight-shop-pro', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Webhook 重试调度：Pro 插件自己的 activation/deactivation 钩子（模式照抄 Free class-activator）
register_activation_hook(__FILE__, array('MLPRO_Webhooks', 'activate'));
register_deactivation_hook(__FILE__, array('MLPRO_Webhooks', 'deactivate'));

/**
 * 初始化：强依赖 Free 插件（依赖方向 Pro → Free，单向）。
 * Free 未启用时不启动任何模块，仅后台提示；Free 功能不受本插件影响。
 */
add_action('plugins_loaded', function () {
    if (!defined('MLSHOP_VERSION') || !class_exists('MLSHOP_Order')) {
        add_action('admin_notices', function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>' .
                esc_html__('「Moonlight Shop Pro」需要先安装并启用「漫步白月光电子商城」（moonlight-shop）才能工作。', 'moonlight-shop-pro') .
                '</p></div>';
        });
        return;
    }

    // License 客户端始终注册：授权状态页是（重新）激活 Pro 的唯一入口，
    // 不能被门禁自身挡住（对齐 MLUCP 模式）。
    MLPRO_License_Client::get_instance();

    // 旧 MLUCP Pro（moonlight-user-center-pro）共存保护（MERGE-USER-CENTER.md Phase D）：
    // MLUCP_VERSION 由旧 Pro 主文件顶层 define（此处所有插件已加载，必然可见）。
    // 旧 Pro 激活时：收编的 Elementor 会员卡与 License 双产品检查由旧 Pro 负责
    // （License 客户端内部自动切回单产品语义）；Webhook / 统计 / 订单导出为
    // MLPro 独有（旧 Pro 无此能力），照常工作。
    $mlpro_legacy_pro = defined('MLUCP_VERSION') || class_exists('MLUCP_License_Client', false);

    if (!$mlpro_legacy_pro) {
        // Elementor 会员卡（自 MLUCP 收编）：MLPRO_ 自动加载器不覆盖 MLUCP_ 前缀，
        // 文件在此显式引入（文件内另有 class_exists 守卫双保险）。
        require_once MLPRO_PLUGIN_DIR . 'includes/class-elementor-integration.php';
        MLUCP_Elementor_Integration::get_instance();
    }

    if (MLPRO_License_Client::is_active()) {
        // 出站 Webhook：前台 / AJAX / cron 都要挂（订单事件不只发生在后台）。
        MLPRO_Webhooks::get_instance();
        if (is_admin()) {
            // Pro 统计 + 订单导出：纯后台模块。
            MLPRO_Analytics::get_instance();
            MLPRO_Order_Export::get_instance();
            // 卡密管理（兑换卡密池：余额/会员/积分/自定义，系统生成或导入）。
            // 属 Pro 能力，未激活时不建表、不出菜单。
            MLPRO_Card_Codes::init();
            MLPRO_Card_Codes_Page::boot();
        }
    } else {
        // FREE-PRO §五：未激活只注册 admin notice，不注册任何前台行为。
        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                esc_html__('Moonlight Shop Pro 尚未激活，Pro 功能（Webhook / 统计 / 导出 / 卡密管理）未启用。', 'moonlight-shop-pro') .
                ' <a href="' . esc_url(admin_url('admin.php?page=mlpro-license')) . '">' .
                esc_html__('前往「Pro 授权」页', 'moonlight-shop-pro') . '</a></p></div>';
        });
    }

    /**
     * Pro 扩展加载完成（Free 侧 / 其他扩展可在此介入）。
     */
    do_action('mlpro_loaded');
}, 20);
