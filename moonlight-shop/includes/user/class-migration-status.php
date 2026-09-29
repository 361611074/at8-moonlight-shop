<?php
/**
 * 迁移状态页（Phase E）：旧插件数据核对 + M3 门控迁移的 UI 出口。
 *
 * 仅装商城（并入态）时挂在「会员与账户」菜单组；旧插件激活时同样可用
 * （迁移需要用户显式授权，与共存守卫无关）。
 *
 * 数据说明：mluc_order / mluc_license CPT 在仅装商城时未注册，
 * 计数一律走 $wpdb 直查（可被子类覆盖接桩）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Migration_Status
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_mluc_migration_consent', array($this, 'handle_consent'));
        add_action('admin_notices', array($this, 'render_notice'));
    }

    public function register_menu()
    {
        $parent = method_exists('MLUC_Settings', 'submenu_parent_slug')
            ? MLUC_Settings::submenu_parent_slug()
            : 'mluc-settings';
        add_submenu_page(
            $parent,
            __('迁移状态', 'moonlight-user-center'),
            __('迁移状态', 'moonlight-user-center'),
            'manage_options',
            'mluc-migration-status',
            array($this, 'render_page')
        );
    }

    /* ---------------- 数据统计（protected 供测试覆盖） ---------------- */

    /**
     * 汇总统计：旧订单总量/已迁移数、会员用户数、License 数、旧配置存在性、当前授权状态。
     *
     * @return array
     */
    public function stats()
    {
        $order_stats = static::query_order_stats();
        return array(
            'legacy_orders'   => (int) $order_stats['total'],
            'migrated_orders' => (int) $order_stats['migrated'],
            'members'         => (int) static::query_member_count(),
            'licenses'        => (int) static::query_license_count(),
            'has_options'     => (bool) static::query_has_options(),
            'consent'         => get_option('moonlight_consent_migrate_mluc', 0),
        );
    }

    protected static function query_order_stats()
    {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN m.meta_value IS NOT NULL THEN 1 ELSE 0 END) AS migrated
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_mluc_migrated_to'
             WHERE p.post_type = 'mluc_order'",
            ARRAY_A
        );
        return array(
            'total'    => is_array($row) ? (int) $row['total'] : 0,
            'migrated' => is_array($row) ? (int) $row['migrated'] : 0,
        );
    }

    protected static function query_member_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'mluc_membership_level' AND meta_value <> ''"
        );
    }

    protected static function query_license_count()
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mluc_license'"
        );
    }

    protected static function query_has_options()
    {
        $opts = get_option('mluc_options', false);
        return !empty($opts);
    }

    /* ---------------- 授权动作（apply 逻辑独立成静态便于测试） ---------------- */

    /**
     * 处理授权/跳过。返回 array{ok:bool, message:string}；写 option 并在授权时立即执行迁移。
     *
     * @param string $mode 'authorize' | 'skip'
     * @return array
     */
    public static function apply_consent($mode)
    {
        if ('skip' === $mode) {
            update_option('moonlight_consent_migrate_mluc', 'skip');
            return array('ok' => true, 'message' => __('已跳过迁移。后续可在本页随时重新授权。', 'moonlight-user-center'));
        }
        if ('authorize' !== $mode) {
            return array('ok' => false, 'message' => __('未知操作。', 'moonlight-user-center'));
        }
        update_option('moonlight_consent_migrate_mluc', 1);
        $result = Moonlight_Migrations::m3_migrate_mluc_orders();
        if (is_wp_error($result)) {
            return array(
                'ok'      => false,
                'message' => sprintf(__('迁移执行出错：%s（数据未受影响，可重试）', 'moonlight-user-center'), $result->get_error_message()),
            );
        }
        $stats = get_option(Moonlight_Migrations::LOG_OPTION, array());
        $last  = '';
        foreach (array_reverse($stats) as $entry) {
            if (!empty($entry['step']) && 'm3_migrate_mluc_orders' === $entry['step'] && !empty($entry['message'])) {
                $last = $entry['message'];
                break;
            }
        }
        return array(
            'ok'      => true,
            'message' => $last ? sprintf(__('迁移已执行：%s。原订单保留未动。', 'moonlight-user-center'), $last) : __('迁移已执行。', 'moonlight-user-center'),
        );
    }

    public function handle_consent()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_migration_consent')) {
            wp_die(__('权限不足。', 'moonlight-user-center'), '', array('response' => 403));
        }
        $result = self::apply_consent(isset($_POST['consent']) ? sanitize_key(wp_unslash($_POST['consent'])) : '');
        set_transient(
            'mluc_migration_notice_' . get_current_user_id(),
            array('message' => $result['message'], 'error' => empty($result['ok'])),
            60
        );
        $back = admin_url('admin.php?page=mluc-migration-status');
        wp_safe_redirect($back);
        exit;
    }

    public function render_notice()
    {
        $notice = get_transient('mluc_migration_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('mluc_migration_notice_' . get_current_user_id());
        $class = empty($notice['error']) ? 'notice-success' : 'notice-error';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    /* ---------------- 页面渲染 ---------------- */

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足。', 'moonlight-user-center'), '', array('response' => 403));
        }
        $s = $this->stats();
        $consent_label = (1 === (int) $s['consent'])
            ? __('已授权（迁移已执行/可重复执行）', 'moonlight-user-center')
            : (('skip' === $s['consent']) ? __('已跳过', 'moonlight-user-center') : __('未授权', 'moonlight-user-center'));
        $log = get_option(Moonlight_Migrations::LOG_OPTION, array());
        $log = array_slice(array_reverse(is_array($log) ? $log : array()), 0, 10);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('迁移状态', 'moonlight-user-center'); ?></h1>
            <p class="description"><?php esc_html_e('「用户中心」功能已并入 Moonlight Shop。此处核对旧数据并决定是否把旧会员订单复制为商城订单；迁移只复制不删除，原数据始终保留。', 'moonlight-user-center'); ?></p>
            <table class="widefat striped" style="max-width:640px;">
                <tbody>
                    <tr><th><?php esc_html_e('旧会员订单（mluc_order）', 'moonlight-user-center'); ?></th><td><?php echo esc_html((string) $s['legacy_orders']); ?></td></tr>
                    <tr><th><?php esc_html_e('其中已复制为商城订单', 'moonlight-user-center'); ?></th><td><?php echo esc_html((string) $s['migrated_orders']); ?></td></tr>
                    <tr><th><?php esc_html_e('会员用户数', 'moonlight-user-center'); ?></th><td><?php echo esc_html((string) $s['members']); ?></td></tr>
                    <tr><th><?php esc_html_e('License 数', 'moonlight-user-center'); ?></th><td><?php echo esc_html((string) $s['licenses']); ?></td></tr>
                    <tr><th><?php esc_html_e('旧配置（mluc_options）', 'moonlight-user-center'); ?></th><td><?php echo esc_html($s['has_options'] ? __('存在（会员等级/OAuth 等配置由商城直接沿用）', 'moonlight-user-center') : __('无', 'moonlight-user-center')); ?></td></tr>
                    <tr><th><?php esc_html_e('迁移授权状态', 'moonlight-user-center'); ?></th><td><?php echo esc_html($consent_label); ?></td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                <?php wp_nonce_field('mluc_migration_consent'); ?>
                <input type="hidden" name="action" value="mluc_migration_consent">
                <button type="submit" name="consent" value="authorize" class="button button-primary"><?php esc_html_e('授权迁移（复制旧订单为商城订单）', 'moonlight-user-center'); ?></button>
                <button type="submit" name="consent" value="skip" class="button"><?php esc_html_e('跳过迁移', 'moonlight-user-center'); ?></button>
            </form>

            <h2><?php esc_html_e('迁移日志（最近 10 条）', 'moonlight-user-center'); ?></h2>
            <table class="widefat striped" style="max-width:640px;">
                <tbody>
                <?php if (empty($log)) : ?>
                    <tr><td><?php esc_html_e('暂无记录。', 'moonlight-user-center'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($log as $entry) : ?>
                        <tr>
                            <td style="width:150px;"><?php echo esc_html((string) ($entry['at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['step'] ?? '') . ' — ' . (string) ($entry['message'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
