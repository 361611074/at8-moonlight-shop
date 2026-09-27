<?php
/**
 * Pro License 客户端：激活 / 停用 / 状态展示（后台「用户中心 → License（Pro）」）。
 *
 * - 授权判断统一走 Free 的 MLUC_License_Manager（不自带第二套授权逻辑）；
 * - Free 配置了 license_server_url 时由其内部走远程验证（含 12h 缓存 + 7 天宽限）；
 * - 激活 / 停用均要求 manage_options + nonce。
 *
 * @package Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUCP_License_Client
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_mlucp_license_activate', array($this, 'handle_activate'));
        add_action('admin_post_mlucp_license_deactivate', array($this, 'handle_deactivate'));
        add_action('admin_notices', array($this, 'render_notice'));
    }

    /**
     * Pro 是否处于有效授权（所有 Pro 功能统一入口）。
     */
    public static function is_active()
    {
        return MLUC_License_Manager::get_instance()->is_product_active(MLUCP_PRODUCT);
    }

    /**
     * 当前已保存的 License Key（仅本站管理员可见）。
     */
    public static function get_saved_key()
    {
        return (string) get_option('mlucp_license_key', '');
    }

    public function register_menu()
    {
        add_submenu_page(
            'mluc-settings',
            __('License（Pro）', 'moonlight-user-center-pro'),
            __('License（Pro）', 'moonlight-user-center-pro'),
            'manage_options',
            'mlucp-license',
            array($this, 'render_page')
        );
    }

    public function render_notice()
    {
        $notice = get_transient('mlucp_lic_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('mlucp_lic_notice_' . get_current_user_id());
        $class = empty($notice['error']) ? 'notice-success' : 'notice-error';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    private static function set_notice($message, $error = false)
    {
        set_transient('mlucp_lic_notice_' . get_current_user_id(), array(
            'message' => $message,
            'error'   => (bool) $error,
        ), 60);
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $active  = self::is_active();
        $key     = self::get_saved_key();
        $post    = $key ? MLUC_License_Manager::get_by_key($key) : null;
        $expires = $post ? (int) get_post_meta($post->ID, '_mluc_license_expires', true) : 0;
        $action_url = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('License（Pro）', 'moonlight-user-center-pro'); ?></h1>

            <table class="widefat striped" style="max-width:760px;">
                <tbody>
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('产品', 'moonlight-user-center-pro'); ?></th>
                        <td><code><?php echo esc_html(MLUCP_PRODUCT); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('授权状态', 'moonlight-user-center-pro'); ?></th>
                        <td>
                            <?php if ($active) : ?>
                                <span style="color:#00a32a;"><strong><?php esc_html_e('已激活', 'moonlight-user-center-pro'); ?></strong></span>
                            <?php else : ?>
                                <span style="color:#d63638;"><strong><?php esc_html_e('未激活', 'moonlight-user-center-pro'); ?></strong></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('License Key', 'moonlight-user-center-pro'); ?></th>
                        <td><code><?php echo esc_html('' !== $key ? $key : '—'); ?></code></td>
                    </tr>
                    <?php if ($post) : ?>
                        <tr>
                            <th><?php esc_html_e('到期时间', 'moonlight-user-center-pro'); ?></th>
                            <td><?php echo esc_html($expires ? wp_date(get_option('date_format', 'Y-m-d'), $expires) : __('永久', 'moonlight-user-center-pro')); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('绑定站点', 'moonlight-user-center-pro'); ?></th>
                            <td><?php echo esc_html((string) get_post_meta($post->ID, '_mluc_license_site', true)); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('激活 License', 'moonlight-user-center-pro'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>">
                <?php wp_nonce_field('mlucp_license_activate'); ?>
                <input type="hidden" name="action" value="mlucp_license_activate">
                <p>
                    <input type="text" class="regular-text code" name="license_key"
                           placeholder="MLUC-PRO-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
                    <?php submit_button(__('激活', 'moonlight-user-center-pro'), 'primary', 'submit', false); ?>
                </p>
                <p class="description"><?php echo esc_html__('输入购买获得的 License Key。激活后绑定当前站点；迁移域名请先停用再激活。', 'moonlight-user-center-pro'); ?></p>
            </form>

            <?php if ('' !== $key) : ?>
                <h2><?php esc_html_e('停用 License', 'moonlight-user-center-pro'); ?></h2>
                <form method="post" action="<?php echo esc_url($action_url); ?>" onsubmit="return confirm('<?php echo esc_js(__('停用后 Pro 功能将立即停止，确定继续？', 'moonlight-user-center-pro')); ?>');">
                    <?php wp_nonce_field('mlucp_license_deactivate'); ?>
                    <input type="hidden" name="action" value="mlucp_license_deactivate">
                    <?php submit_button(__('停用当前 License', 'moonlight-user-center-pro'), 'delete', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 激活：绑定当前站点并生效。
     */
    public function handle_activate()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlucp_license_activate')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-user-center-pro'));
        }
        $redirect = admin_url('admin.php?page=mlucp-license');
        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        if ('' === $key) {
            self::set_notice(__('请输入 License Key。', 'moonlight-user-center-pro'), true);
            wp_safe_redirect($redirect);
            exit;
        }
        $result = MLUC_License_Manager::activate($key);
        if (is_wp_error($result)) {
            self::set_notice($result->get_error_message(), true);
        } else {
            update_option('mlucp_license_key', strtoupper(trim($key)));
            self::set_notice(__('License 激活成功，Pro 功能已启用。', 'moonlight-user-center-pro'));
            do_action('mlucp_license_activated', strtoupper(trim($key)));
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 停用：解绑当前站点（域名迁移时先停用）。
     */
    public function handle_deactivate()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlucp_license_deactivate')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-user-center-pro'));
        }
        $key = self::get_saved_key();
        if ('' !== $key) {
            $result = MLUC_License_Manager::deactivate($key);
            if (is_wp_error($result)) {
                self::set_notice($result->get_error_message(), true);
            } else {
                self::set_notice(__('License 已停用，Pro 功能已关闭。', 'moonlight-user-center-pro'));
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=mlucp-license'));
        exit;
    }
}
