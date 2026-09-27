<?php
/**
 * 账户中心仪表盘：路由 / 导航 / 各 Tab 内容。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Account
{
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('mluc_account', array($this, 'shortcode_account'));

        add_action('wp_ajax_mluc_update_profile', array($this, 'ajax_update_profile'));
        add_action('wp_ajax_mluc_change_password', array($this, 'ajax_change_password'));
    }

    /**
     * 注册 Tab（可被其他插件通过 mluc_account_tabs 过滤扩展）。
     */
    public function get_tabs()
    {
        $tabs = array(
            'overview'   => array(
                'title'    => __('概览', 'moonlight-user-center'),
                'icon'     => 'dashicons-dashboard',
                'callback' => array($this, 'tab_overview'),
            ),
            'profile'    => array(
                'title'    => __('个人资料', 'moonlight-user-center'),
                'icon'     => 'dashicons-edit',
                'callback' => array($this, 'tab_profile'),
            ),
            'membership' => array(
                'title'    => __('會員等級', 'moonlight-user-center'),
                'icon'     => 'dashicons-star-filled',
                'callback' => array($this, 'tab_membership'),
            ),
        );
        return apply_filters('mluc_account_tabs', $tabs);
    }

    public function shortcode_account()
    {
        if (!is_user_logged_in()) {
            return '<p class="mluc-message">' .
                sprintf(
                    esc_html__('请先 %s 后查看账户中心。', 'moonlight-user-center'),
                    '<a href="' . esc_url(mluc_get_login_url()) . '">' . esc_html__('登录', 'moonlight-user-center') . '</a>'
                ) . '</p>';
        }

        $tabs  = $this->get_tabs();
        $active = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? sanitize_key($_GET['tab']) : 'overview';

        ob_start();
        echo '<div class="mluc-account">';
        $this->render_nav($tabs, $active);
        echo '<div class="mluc-account-content">';
        if (isset($tabs[$active]) && is_callable($tabs[$active]['callback'])) {
            call_user_func($tabs[$active]['callback']);
        }
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    private function render_nav($tabs, $active)
    {
        $icons = function_exists('mluc_get_option') ? (array) mluc_get_option('nav_icons', array()) : array();
        echo '<nav class="mluc-account-nav"><ul>';
        foreach ($tabs as $key => $tab) {
            $url   = esc_url(add_query_arg('tab', $key, mluc_get_account_url()));
            $class = $key === $active ? ' class="active"' : '';
            // 后台设置的自定义图标优先，未设置则回退到插件内置默认
            $icon_html = '';
            if (isset($icons[$key]) && $icons[$key]) {
                $entry = $icons[$key];
                // 兼容旧裸字符串（直接是 dashicons 类名）
                if (is_string($entry)) {
                    $entry = array('type' => 'dashicon', 'value' => $entry);
                }
                if (is_array($entry) && isset($entry['type'])) {
                    if ('image' === $entry['type'] && !empty($entry['value'])) {
                        $img_url = wp_get_attachment_image_url((int) $entry['value'], 'thumbnail');
                        if (!$img_url) {
                            $img_url = wp_get_attachment_url((int) $entry['value']);
                        }
                        if ($img_url) {
                            $icon_html = '<img class="mluc-nav-icon-img" src="' . esc_url($img_url) . '" alt="">';
                        }
                    } elseif ('dashicon' === $entry['type'] && !empty($entry['value'])) {
                        $icon_html = '<span class="dashicons ' . esc_attr($entry['value']) . '"></span>';
                    }
                }
            }
            if (!$icon_html && !empty($tab['icon'])) {
                $icon_html = '<span class="dashicons ' . esc_attr($tab['icon']) . '"></span>';
            }
            echo '<li' . $class . '><a href="' . $url . '">' . $icon_html . esc_html($tab['title']) . '</a></li>';
        }
        $logout_url = wp_nonce_url(add_query_arg('mluc_logout', '1', home_url()), 'mluc_logout');
        echo '<li class="mluc-logout"><a href="' . esc_url($logout_url) . '"><span class="dashicons dashicons-migrate"></span>' . esc_html__('退出登录', 'moonlight-user-center') . '</a></li>';
        echo '</ul></nav>';
    }

    /**
     * 概览 Tab。
     */
    public function tab_overview()
    {
        $user = wp_get_current_user();
        mluc_get_template('account-overview', array('user' => $user));
    }

    /**
     * 资料编辑 Tab。
     */
    public function tab_profile()
    {
        $user = wp_get_current_user();
        mluc_get_template('account-profile', array('user' => $user));
    }

    /**
     * 會員等級 Tab。
     */
    public function tab_membership()
    {
        if (!class_exists('MLUC_Membership')) {
            return;
        }
        $user_id = get_current_user_id();
        $level   = MLUC_Membership::get_user_level($user_id);
        $expires = MLUC_Membership::get_user_expires($user_id);
        $expired = MLUC_Membership::is_user_expired($user_id);
        $levels  = MLUC_Membership::get_levels();
        mluc_get_template('account-membership', array(
            'user_id' => $user_id,
            'level'   => $level,
            'expires' => $expires,
            'expired' => $expired,
            'levels'  => $levels,
        ));

        // 升级购买。双插件同装时由 moonlight-shop 提供商城升级流程；
        // 仅装用户中心时由 MLUC_Payments 提供独立购买与收款确认流程。
        if (class_exists('MLSHOP_Membership_UI') && method_exists('MLSHOP_Membership_UI', 'shortcode_upgrade')) {
            echo '<section class="mluc-card mluc-membership-upgrade-card" aria-label="' . esc_attr__('升级会员', 'moonlight-user-center') . '">';
            echo '<h3 class="mluc-card-title">' . esc_html__('升級會員', 'moonlight-user-center') . '</h3>';
            echo MLSHOP_Membership_UI::get_instance()->shortcode_upgrade();
            echo '</section>';
        } elseif (class_exists('MLUC_Payments')) {
            echo '<section class="mluc-card mluc-membership-upgrade-card" aria-label="' . esc_attr__('升级会员', 'moonlight-user-center') . '">';
            echo '<h3 class="mluc-card-title">' . esc_html__('開通 / 升級會員', 'moonlight-user-center') . '</h3>';
            do_action('mluc_membership_purchase');
            echo '</section>';
        }
    }

    /**
     * AJAX 更新资料。
     */
    public function ajax_update_profile()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, __('请先登录。', 'moonlight-user-center'));
        }

        $user_id      = get_current_user_id();
        $display_name = sanitize_text_field(isset($_POST['display_name']) ? $_POST['display_name'] : '');
        $nickname     = sanitize_text_field(isset($_POST['nickname']) ? $_POST['nickname'] : '');
        $description  = sanitize_textarea_field(isset($_POST['description']) ? $_POST['description'] : '');
        $url          = esc_url_raw(isset($_POST['user_url']) ? $_POST['user_url'] : '');
        $phone        = sanitize_text_field(isset($_POST['phone']) ? $_POST['phone'] : '');

        if (empty($display_name)) {
            mluc_send_json(false, __('昵称不能为空。', 'moonlight-user-center'));
        }

        $args = array(
            'ID'           => $user_id,
            'display_name' => $display_name,
            'nickname'     => $nickname ? $nickname : $display_name,
            'description'  => $description,
            'user_url'     => $url,
        );
        $result = wp_update_user($args);
        if (is_wp_error($result)) {
            mluc_send_json(false, mluc_translate_wp_error($result));
        }

        update_user_meta($user_id, 'phone', $phone);
        do_action('mluc_after_profile_update', $user_id, array('phone' => $phone));
        mluc_send_json(true, __('资料已更新。', 'moonlight-user-center'));
    }

    /**
     * AJAX 修改密码。
     */
    public function ajax_change_password()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, __('请先登录。', 'moonlight-user-center'));
        }

        $old = isset($_POST['old_password']) ? $_POST['old_password'] : '';
        $new = isset($_POST['new_password']) ? $_POST['new_password'] : '';

        if (empty($old) || empty($new)) {
            mluc_send_json(false, __('请填写原密码和新密码。', 'moonlight-user-center'));
        }
        if (mb_strlen($new) < 6) {
            mluc_send_json(false, __('新密码至少 6 位。', 'moonlight-user-center'));
        }

        $user = wp_get_current_user();
        if (!wp_check_password($old, $user->user_pass, $user->ID)) {
            mluc_send_json(false, __('原密码不正确。', 'moonlight-user-center'));
        }

        wp_set_password($new, $user->ID);
        wp_set_auth_cookie($user->ID, true);
        mluc_send_json(true, __('密码已修改，请重新登录。', 'moonlight-user-center'));
    }
}
