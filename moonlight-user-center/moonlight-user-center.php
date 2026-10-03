<?php
/**
 * Plugin Name:      漫步白月光用户中心
 * Plugin URI:       https://www.at8.fun/
 * Description:       轻量、主题无关的 WordPress 用户中心，兼容 Astra 主题与 Elementor 页面构建器。提供前端登录、注册、找回密码、账户仪表盘、资料编辑、头像上传等功能。
 * Version:          2.1.0
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

// 守卫式定义（Phase E 事故修复）：插件加载顺序由 active_plugins 决定，
// 商城侧 v3.0.1 起只在旧插件缺席时才补齐这些常量；此处再加守卫，
// 保证任何加载顺序下零警告、零冲突。
if (!defined('MLUC_VERSION')) {
    define('MLUC_VERSION', '2.1.0');
}
if (!defined('MLUC_PLUGIN_DIR')) {
    define('MLUC_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('MLUC_PLUGIN_URL')) {
    define('MLUC_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('MLUC_PLUGIN_FILE')) {
    define('MLUC_PLUGIN_FILE', __FILE__);
}

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

    // 积分与余额（v2.1.0）：充值比例 / 兑换比例全部后台可自定义；
    // 与 Moonlight Shop 同装时商城积分模块优先，本模块自动让位。
    MLUC_Credit_UI::get_instance();
    MLUC_Credit_Admin::get_instance();
    MLUC_Checkin::get_instance();

    if (did_action('elementor/loaded')) {
        MLUC_Elementor::get_instance();
    }

    /**
     * 插件加载完成：Pro 扩展与第三方在此挂载（Pro 通过本钩子介入，Free 不反向依赖）。
     */
    do_action('mluc_loaded');
});

/* -----------------------------------------------------------------
 * 退役提示（Phase E）：功能已并入 Moonlight Shop 2.2+。
 * 仅当商城插件已激活时提示；数据全部保留，停用本插件后商城自动接管。
 * ----------------------------------------------------------------- */
add_action('admin_notices', function () {
    if (!class_exists('MLSHOP_Order') || !current_user_can('manage_options')) {
        return;
    }
    if (get_user_meta(get_current_user_id(), 'mluc_retire_notice_dismissed', true)) {
        return;
    }
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=mluc_retire_notice_dismiss'), 'mluc_retire_notice_dismiss');
    ?>
    <div class="notice notice-info is-dismissible">
        <p>
            <?php esc_html_e('「用户中心」功能已并入 Moonlight Shop 2.2+：账户、会员、License 等由商城插件直接提供。建议在「迁移状态」页核对数据后停用本插件——停用不会删除任何数据。', 'moonlight-user-center'); ?>
            <a class="button button-small" style="margin-left:8px;" href="<?php echo esc_url($dismiss); ?>"><?php esc_html_e('不再提示', 'moonlight-user-center'); ?></a>
        </p>
    </div>
    <?php
});

add_action('admin_post_mluc_retire_notice_dismiss', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('mluc_retire_notice_dismiss')) {
        wp_die(__('权限不足。', 'moonlight-user-center'), '', array('response' => 403));
    }
    update_user_meta(get_current_user_id(), 'mluc_retire_notice_dismissed', 1);
    wp_safe_redirect(wp_get_referer() ?: admin_url('plugins.php'));
    exit;
});
