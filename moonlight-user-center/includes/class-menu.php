<?php
/**
 * 菜单登录状态支持：菜单项按登录状态显示 + 魔法链接。
 *
 * 使用方法（外观 → 菜单）：
 * 1. 显示条件：每个菜单项可选「始终显示 / 仅登录用户 / 仅未登录访客」，
 *    据此实现「登录 / 注册」仅访客可见、「账户中心 / 退出」仅登录用户可见。
 * 2. 魔法链接：添加「自定义链接」，URL 填以下占位符，标题随意（如「登录」「退出」），
 *    前台自动替换为对应真实链接：
 *    - #mluc-login         登录页（登录后回跳当前页）
 *    - #mluc-register      注册页
 *    - #mluc-account       账户中心
 *    - #mluc-lostpassword  找回密码页
 *    - #mluc-logout        退出登录（退出后回跳当前页）
 *
 * 兼容 wp_nav_menu 输出的所有菜单（主题菜单、Astra Header Builder、Elementor Nav Menu 等）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Menu
{
    /** 菜单项可见性 meta key */
    const META_KEY = '_mluc_visibility';

    const NONCE_ACTION = 'mluc_menu_meta_save';
    const NONCE_NAME   = 'mluc_menu_meta_nonce';

    /** @var MLUC_Menu|null */
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
        // 后台菜单项编辑界面：显示条件字段
        add_action('wp_nav_menu_item_custom_fields', array($this, 'render_admin_fields'), 10, 5);
        add_action('wp_update_nav_menu_item', array($this, 'save_item_visibility'), 10, 2);

        // 前台：按状态过滤 + 魔法链接替换
        add_filter('wp_nav_menu_objects', array($this, 'filter_menu_objects'), 20);
    }

    /**
     * 菜单项编辑界面：渲染「显示条件」单选组。
     *
     * @param int    $item_id 菜单项 ID。
     * @param object $item    菜单项对象。
     */
    public function render_admin_fields($item_id, $item)
    {
        $vis = get_post_meta($item_id, self::META_KEY, true);
        if (!in_array($vis, array('logged_in', 'logged_out'), true)) {
            $vis = '';
        }
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME, false);
        ?>
        <p class="field-mluc-visibility description description-wide">
            <strong><?php esc_html_e('用户中心 · 显示条件', 'moonlight-user-center'); ?></strong><br>
            <label style="margin-right:12px;">
                <input type="radio" name="mluc-menu-visibility[<?php echo (int) $item_id; ?>]" value="" <?php checked('', $vis); ?>>
                <?php esc_html_e('始终显示', 'moonlight-user-center'); ?>
            </label>
            <label style="margin-right:12px;">
                <input type="radio" name="mluc-menu-visibility[<?php echo (int) $item_id; ?>]" value="logged_in" <?php checked('logged_in', $vis); ?>>
                <?php esc_html_e('仅登录用户', 'moonlight-user-center'); ?>
            </label>
            <label>
                <input type="radio" name="mluc-menu-visibility[<?php echo (int) $item_id; ?>]" value="logged_out" <?php checked('logged_out', $vis); ?>>
                <?php esc_html_e('仅未登录访客', 'moonlight-user-center'); ?>
            </label>
            <br>
            <span class="description"><?php esc_html_e('注意：父项被隐藏时其整组子菜单一并隐藏。魔法链接：#mluc-login / #mluc-register / #mluc-account / #mluc-lostpassword / #mluc-logout（自定义链接 URL 填此占位符，前台自动替换为真实地址）', 'moonlight-user-center'); ?></span>
        </p>
        <?php
    }

    /**
     * 保存菜单项显示条件。
     *
     * @param int $menu_id       菜单 ID。
     * @param int $menu_item_db_id 菜单项 ID。
     */
    public function save_item_visibility($menu_id, $menu_item_db_id)
    {
        if (!current_user_can('edit_theme_options')) {
            return;
        }
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce 已在上方校验
        $map = isset($_POST['mluc-menu-visibility']) && is_array($_POST['mluc-menu-visibility']) ? wp_unslash($_POST['mluc-menu-visibility']) : array();
        $val = isset($map[$menu_item_db_id]) ? sanitize_key((string) $map[$menu_item_db_id]) : '';

        if (in_array($val, array('logged_in', 'logged_out'), true)) {
            update_post_meta($menu_item_db_id, self::META_KEY, $val);
        } else {
            delete_post_meta($menu_item_db_id, self::META_KEY);
        }
    }

    /**
     * 前台菜单对象：按登录状态过滤 + 替换魔法链接。
     *
     * 规则：
     * - 项自身可见性不满足 → 隐藏；
     * - 父级链上任一祖先被隐藏 → 其整棵子树一起隐藏（孤儿清理）。
     *
     * @param array $items 排序后的菜单项对象数组。
     * @return array
     */
    public function filter_menu_objects($items)
    {
        if (is_admin()) {
            return $items;
        }
        $logged_in = is_user_logged_in();

        // 原始 ID 集合（用于区分「被隐藏的父级」与「本来就孤立的项」）
        $orig_ids = array();
        foreach ($items as $it) {
            $orig_ids[(int) $it->ID] = true;
        }

        // 第一遍：自身可见性过滤 + 魔法链接替换
        $kept = array();
        foreach ($items as $item) {
            $vis = get_post_meta($item->ID, self::META_KEY, true);
            if (('logged_in' === $vis && !$logged_in) || ('logged_out' === $vis && $logged_in)) {
                continue;
            }
            $item->url        = $this->maybe_replace_url($item->url);
            $kept[(int) $item->ID] = $item;
        }

        // 第二遍：孤儿清理——父级被隐藏的项连带隐藏
        $out = array();
        foreach ($kept as $item) {
            $pid     = (int) $item->menu_item_parent;
            $visible = true;
            $guard   = 0;
            while ($pid && isset($orig_ids[$pid]) && $guard++ < 50) {
                if (!isset($kept[$pid])) {
                    $visible = false;
                    break;
                }
                $pid = (int) $kept[$pid]->menu_item_parent;
            }
            if ($visible) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * 把 #mluc-* 魔法占位符替换为真实链接。
     *
     * @param string $url 菜单项 URL。
     * @return string
     */
    private function maybe_replace_url($url)
    {
        if (!is_string($url)) {
            return $url;
        }
        $frag = strtolower(trim($url));
        switch ($frag) {
            case '#mluc-login':
                return mluc_get_login_url($this->current_url());
            case '#mluc-register':
                return mluc_get_register_url();
            case '#mluc-account':
                return mluc_get_account_url();
            case '#mluc-lostpassword':
                return mluc_get_lostpassword_url();
            case '#mluc-logout':
                return wp_logout_url($this->current_url());
            default:
                return $url;
        }
    }

    /**
     * 当前页面完整 URL（登录 / 退出后回跳用）。
     *
     * @return string
     */
    private function current_url()
    {
        $req = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        return home_url($req);
    }
}
