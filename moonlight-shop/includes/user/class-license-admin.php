<?php
/**
 * 后台「License 管理」：签发 / 列表 / 撤销 / 恢复 / 续期。
 *
 * 权限：全部操作要求 manage_options + nonce（§32/§34）。
 * 路径：用户中心 → License 管理。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_License_Admin
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
        add_action('admin_post_mluc_lic_issue', array($this, 'handle_issue'));
        add_action('admin_post_mluc_lic_action', array($this, 'handle_row_action'));
        add_action('admin_notices', array($this, 'render_notice'));
    }

    public function register_menu()
    {
        add_submenu_page(
            'mluc-settings',
            __('License 管理', 'moonlight-user-center'),
            __('License 管理', 'moonlight-user-center'),
            'manage_options',
            'mluc-licenses',
            array($this, 'render_page')
        );
    }

    /**
     * 操作结果提示（transient 承载，重定向后展示一次）。
     */
    public function render_notice()
    {
        $notice = get_transient('mluc_lic_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('mluc_lic_notice_' . get_current_user_id());
        $class = empty($notice['error']) ? 'notice-success' : 'notice-error';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    private static function set_notice($message, $error = false)
    {
        set_transient('mluc_lic_notice_' . get_current_user_id(), array(
            'message' => $message,
            'error'   => (bool) $error,
        ), 60);
    }

    /**
     * 查询 License 列表（支持按 Key / 用户 / 产品搜索）。
     */
    private function get_licenses($search = '')
    {
        $args = array(
            'post_type'      => MLUC_License_Manager::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        );
        $search = trim((string) $search);
        if ('' !== $search) {
            $args['s'] = $search;
        }
        $posts = get_posts($args);
        $rows = array();
        foreach ($posts as $p) {
            $user = $p->post_author ? get_user_by('id', (int) $p->post_author) : false;
            $rows[] = array(
                'id'       => (int) $p->ID,
                'key'      => (string) $p->post_title,
                'product'  => (string) get_post_meta($p->ID, '_mluc_license_product', true),
                'status'   => MLUC_License_Manager::effective_status($p->ID),
                'user'     => $user ? $user->user_login . ' (#' . $user->ID . ')' : '—',
                'site'     => (string) get_post_meta($p->ID, '_mluc_license_site', true),
                'limit'    => (int) get_post_meta($p->ID, '_mluc_license_limit', true),
                'count'    => (int) get_post_meta($p->ID, '_mluc_license_count', true),
                'expires'  => (int) get_post_meta($p->ID, '_mluc_license_expires', true),
                'order_id' => (int) get_post_meta($p->ID, '_mluc_license_order', true),
                'date'     => get_the_date('', $p),
            );
        }
        return $rows;
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $rows   = $this->get_licenses($search);
        $action_url = admin_url('admin-post.php');
        $users = get_users(array('fields' => array('ID', 'user_login'), 'number' => 200));
        $status_labels = array(
            'active'    => __('有效', 'moonlight-user-center'),
            'inactive'  => __('未激活', 'moonlight-user-center'),
            'expired'   => __('已过期', 'moonlight-user-center'),
            'revoked'   => __('已撤销', 'moonlight-user-center'),
            'suspended' => __('已暂停', 'moonlight-user-center'),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('License 管理', 'moonlight-user-center'); ?></h1>

            <h2><?php esc_html_e('签发 License', 'moonlight-user-center'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>" style="margin-bottom:16px;">
                <?php wp_nonce_field('mluc_lic_issue'); ?>
                <input type="hidden" name="action" value="mluc_lic_issue">
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="mluc_lic_user"><?php esc_html_e('绑定用户', 'moonlight-user-center'); ?></label></th>
                        <td>
                            <select name="user_id" id="mluc_lic_user">
                                <option value="0"><?php esc_html_e('（不绑定用户）', 'moonlight-user-center'); ?></option>
                                <?php foreach ($users as $u) : ?>
                                    <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html($u->user_login . ' (#' . $u->ID . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mluc_lic_product"><?php esc_html_e('产品', 'moonlight-user-center'); ?></label></th>
                        <td>
                            <input type="text" id="mluc_lic_product" class="regular-text" name="product"
                                   value="<?php echo esc_attr(MLUC_License_Manager::PRODUCT_PRO); ?>">
                            <p class="description"><?php esc_html_e('默认为 Pro 扩展产品标识，一般无需修改。', 'moonlight-user-center'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mluc_lic_days"><?php esc_html_e('有效期（天）', 'moonlight-user-center'); ?></label></th>
                        <td>
                            <input type="number" id="mluc_lic_days" class="small-text" name="days" value="365" min="0">
                            <span class="description"><?php esc_html_e('0 = 永久；License 创建后立即绑定当前站点并生效。', 'moonlight-user-center'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mluc_lic_limit"><?php esc_html_e('激活数量上限', 'moonlight-user-center'); ?></label></th>
                        <td><input type="number" id="mluc_lic_limit" class="small-text" name="limit" value="1" min="1"></td>
                    </tr>
                </table>
                <?php submit_button(__('签发 License', 'moonlight-user-center'), 'primary', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('License 列表', 'moonlight-user-center'); ?></h2>
            <form method="get">
                <input type="hidden" name="page" value="mluc-licenses">
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>">
                    <?php submit_button(__('搜索', 'moonlight-user-center'), '', '', false); ?>
                </p>
            </form>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('License Key', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('产品', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('用户', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('状态', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('绑定站点', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('激活', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('到期', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('关联订单', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('操作', 'moonlight-user-center'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('暂无 License。', 'moonlight-user-center'); ?></td></tr>
                <?php else : foreach ($rows as $r) : ?>
                    <tr>
                        <td><code><?php echo esc_html($r['key']); ?></code></td>
                        <td><?php echo esc_html($r['product']); ?></td>
                        <td><?php echo esc_html($r['user']); ?></td>
                        <td><?php echo esc_html(isset($status_labels[$r['status']]) ? $status_labels[$r['status']] : $r['status']); ?></td>
                        <td><?php echo esc_html('' !== $r['site'] ? $r['site'] : '—'); ?></td>
                        <td><?php echo esc_html($r['count'] . ' / ' . $r['limit']); ?></td>
                        <td><?php echo esc_html($r['expires'] ? wp_date('Y-m-d', $r['expires']) : __('永久', 'moonlight-user-center')); ?></td>
                        <td><?php echo $r['order_id'] ? '<a href="' . esc_url(get_edit_post_link($r['order_id'])) . '">#' . (int) $r['order_id'] . '</a>' : '—'; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline;">
                                <?php wp_nonce_field('mluc_lic_action'); ?>
                                <input type="hidden" name="action" value="mluc_lic_action">
                                <input type="hidden" name="license_id" value="<?php echo (int) $r['id']; ?>">
                                <?php if ('revoked' === $r['status'] || 'suspended' === $r['status'] || 'inactive' === $r['status']) : ?>
                                    <button type="submit" name="op" value="restore" class="button button-small"><?php esc_html_e('恢复', 'moonlight-user-center'); ?></button>
                                <?php else : ?>
                                    <button type="submit" name="op" value="revoke" class="button button-small"
                                            onclick="return confirm('<?php echo esc_js(__('确认撤销该 License？撤销后立即失效。', 'moonlight-user-center')); ?>');"><?php esc_html_e('撤销', 'moonlight-user-center'); ?></button>
                                <?php endif; ?>
                            </form>
                            <form method="post" action="<?php echo esc_url($action_url); ?>" style="display:inline;">
                                <?php wp_nonce_field('mluc_lic_action'); ?>
                                <input type="hidden" name="action" value="mluc_lic_action">
                                <input type="hidden" name="license_id" value="<?php echo (int) $r['id']; ?>">
                                <input type="number" name="days" value="365" min="0" class="small-text" style="width:64px;">
                                <button type="submit" name="op" value="renew" class="button button-small"><?php esc_html_e('续期(天)', 'moonlight-user-center'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 签发处理。
     */
    public function handle_issue()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_lic_issue')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-user-center'));
        }
        $redirect = admin_url('admin.php?page=mluc-licenses');
        $user_id  = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $product  = isset($_POST['product']) ? sanitize_key(wp_unslash($_POST['product'])) : MLUC_License_Manager::PRODUCT_PRO;
        $days     = isset($_POST['days']) ? max(0, (int) $_POST['days']) : 0;
        $limit    = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 1;

        $created = MLUC_License_Manager::create(array(
            'user_id' => $user_id,
            'product' => $product,
            'days'    => $days,
            'limit'   => $limit,
        ));
        if (is_wp_error($created)) {
            self::set_notice($created->get_error_message(), true);
        } else {
            $key = (string) get_post($created)->post_title;
            self::set_notice(sprintf(
                /* translators: %s: License Key */
                __('License 已签发：%s（已绑定当前站点并生效）。', 'moonlight-user-center'),
                $key
            ));
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 行操作：撤销 / 恢复 / 续期。
     */
    public function handle_row_action()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_lic_action')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-user-center'));
        }
        $redirect = admin_url('admin.php?page=mluc-licenses');
        $license_id = isset($_POST['license_id']) ? (int) $_POST['license_id'] : 0;
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

        $result = new WP_Error('mluc_lic_op', __('未知操作。', 'moonlight-user-center'));
        if ('revoke' === $op) {
            $result = MLUC_License_Manager::revoke($license_id);
        } elseif ('restore' === $op) {
            $result = MLUC_License_Manager::restore($license_id);
        } elseif ('renew' === $op) {
            $days   = isset($_POST['days']) ? max(0, (int) $_POST['days']) : 0;
            $result = MLUC_License_Manager::renew($license_id, $days);
        }

        if (is_wp_error($result)) {
            self::set_notice($result->get_error_message(), true);
        } else {
            self::set_notice(__('License 操作成功。', 'moonlight-user-center'));
        }
        wp_safe_redirect($redirect);
        exit;
    }
}
