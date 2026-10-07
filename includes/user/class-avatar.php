<?php
/**
 * 会员头像库：管理员在后台「会员头像库」CPT 上传可选头像，
 * 用户在前端「个人资料」页从列表中选择一个作为自己的头像。
 *
 * 用户不再自行上传头像，避免随意文件 / 隐私问题。
 *
 * 自 moonlight-user-center v2.0.0 并入（at8-moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Avatar
{
    public const CPT = 'mluc_avatar';
    /** 用户已选头像（attachment id，绑到 user_meta）。 */
    public const META_SELECTED = '_mluc_selected_avatar_id';
    /** 旧版上传头像的 URL（兼容已有数据）。 */
    public const META_LEGACY_URL = '_mluc_avatar';

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
        add_action('init', array($this, 'register_cpt'));
        add_action('add_meta_boxes', array($this, 'register_metabox'));
        add_action('admin_notices', array($this, 'maybe_render_notice'));

        add_action('wp_ajax_mluc_select_avatar', array($this, 'ajax_select'));
        add_filter('get_avatar_url', array($this, 'filter_avatar_url'), 10, 3);
    }

    /**
     * 注册头像库 CPT：纯后台管理用，前台不可见、不可搜索、不可 RSS。
     */
    public function register_cpt()
    {
        $labels = array(
            'name'                  => __('会员头像库', 'at8-moonlight-shop'),
            'singular_name'         => __('会员头像', 'at8-moonlight-shop'),
            'add_new'               => __('添加头像', 'at8-moonlight-shop'),
            'add_new_item'          => __('添加头像', 'at8-moonlight-shop'),
            'edit_item'             => __('编辑头像', 'at8-moonlight-shop'),
            'new_item'              => __('新头像', 'at8-moonlight-shop'),
            'view_item'             => __('查看头像', 'at8-moonlight-shop'),
            'view_items'            => __('查看头像', 'at8-moonlight-shop'),
            'search_items'          => __('搜索头像', 'at8-moonlight-shop'),
            'not_found'             => __('暂无头像', 'at8-moonlight-shop'),
            'not_found_in_trash'    => __('回收站中暂无头像', 'at8-moonlight-shop'),
            'all_items'             => __('所有头像', 'at8-moonlight-shop'),
            'archives'              => __('会员头像库', 'at8-moonlight-shop'),
            'attributes'            => __('头像属性', 'at8-moonlight-shop'),
            'insert_into_item'      => __('插入到头像', 'at8-moonlight-shop'),
            'uploaded_to_this_item' => __('上传到此头像', 'at8-moonlight-shop'),
            'menu_name'             => __('会员头像库', 'at8-moonlight-shop'),
            'name_admin_bar'        => __('会员头像', 'at8-moonlight-shop'),
            'item_published'        => __('头像已添加。', 'at8-moonlight-shop'),
            'item_updated'          => __('头像已更新。', 'at8-moonlight-shop'),
            'item_trashed'          => __('头像已移至回收站。', 'at8-moonlight-shop'),
        );

        register_post_type(self::CPT, array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            // 会员头像库不再独立成菜单项；入口合并到「用户中心 → 设置」页内"会员头像库"卡片。
            // 直访 URL：/wp-admin/edit.php?post_type=mluc_avatar
            'show_in_menu'        => false,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'supports'            => array('title', 'thumbnail'),
            'menu_icon'           => 'dashicons-format-image',
            'menu_position'       => 25,
        ));
    }

    /**
     * 覆盖默认头像：优先取用户已选（attachment id），回落到旧版 URL meta，再回落到 WP 默认。
     */
    public function filter_avatar_url($url, $id_or_email, $args)
    {
        $user_id = 0;
        if (is_numeric($id_or_email)) {
            $user_id = (int) $id_or_email;
        } elseif (is_string($id_or_email) && ($user = get_user_by('email', $id_or_email))) {
            $user_id = $user->ID;
        } elseif ($id_or_email instanceof WP_User) {
            $user_id = $id_or_email->ID;
        }
        if (!$user_id) {
            return $url;
        }

        $selected = (int) get_user_meta($user_id, self::META_SELECTED, true);
        if ($selected) {
            $thumb = wp_get_attachment_image_url($selected, 'medium');
            if ($thumb) {
                return $thumb;
            }
        }

        $legacy = get_user_meta($user_id, self::META_LEGACY_URL, true);
        return $legacy ? $legacy : $url;
    }

    /**
     * 取得头像库中的全部头像（按菜单顺序）。
     *
     * @return array<int, array{id:int, url:string, title:string}>
     */
    public static function get_library()
    {
        $posts = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => array('menu_order' => 'ASC', 'date' => 'DESC'),
            'no_found_rows'  => true,
        ));

        $out = array();
        foreach ($posts as $p) {
            $thumb_id = (int) get_post_thumbnail_id($p->ID);
            if (!$thumb_id) {
                continue;
            }
            $src = wp_get_attachment_image_url($thumb_id, 'medium');
            if (!$src) {
                continue;
            }
            $out[] = array(
                'id'    => (int) $p->ID,
                'thumb' => (int) $thumb_id,
                'url'   => $src,
                'title' => $p->post_title,
            );
        }
        return $out;
    }

    /**
     * AJAX：用户从头像库选择一个。
     */
    public function ajax_select()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, __('请先登录。', 'at8-moonlight-shop'));
        }
        if (!mluc_get_option('enable_avatar', 1)) {
            mluc_send_json(false, __('头像功能未启用。', 'at8-moonlight-shop'));
        }

        $avatar_id = isset($_POST['avatar_id']) ? (int) $_POST['avatar_id'] : 0;
        if (!$avatar_id || get_post_type($avatar_id) !== self::CPT) {
            mluc_send_json(false, __('所选头像无效。', 'at8-moonlight-shop'));
        }

        $thumb_id = (int) get_post_thumbnail_id($avatar_id);
        if (!$thumb_id) {
            mluc_send_json(false, __('该头像尚未设置图片。', 'at8-moonlight-shop'));
        }
        $url = wp_get_attachment_image_url($thumb_id, 'medium');
        if (!$url) {
            mluc_send_json(false, __('头像图片加载失败。', 'at8-moonlight-shop'));
        }

        update_user_meta(get_current_user_id(), self::META_SELECTED, $thumb_id);

        mluc_send_json(true, __('头像已更新。', 'at8-moonlight-shop'), array(
            'url'        => $url,
            'avatar_id'  => $avatar_id,
            'attachment' => $thumb_id,
        ));
    }

    /**
     * 后台 metabox：「使用情况」展示已选此头像的用户数。
     */
    public function register_metabox()
    {
        add_meta_box(
            'mluc_avatar_usage',
            __('使用情况', 'at8-moonlight-shop'),
            array($this, 'render_usage_metabox'),
            self::CPT,
            'side',
            'default'
        );
    }

    public function render_usage_metabox($post)
    {
        $thumb_id = (int) get_post_thumbnail_id($post->ID);
        if (!$thumb_id) {
            echo '<p style="margin:0;color:#666;">' . esc_html__('请先设置「特色图片」再发布。', 'at8-moonlight-shop') . '</p>';
            return;
        }
        $users = get_users(array(
            'meta_key'   => self::META_SELECTED,
            'meta_value' => $thumb_id,
            'number'     => 100,
            'fields'     => array('ID', 'display_name'),
        ));
        $count = is_array($users) ? count($users) : 0;
        echo '<p style="margin:0 0 8px;font-size:13px;">' .
            esc_html(sprintf(
                /* translators: %d: 选用人数 */
                __('当前 %d 位用户在用此头像。', 'at8-moonlight-shop'),
                $count
            )) . '</p>';
        if ($count) {
            $names = array_slice(array_map(function ($u) { return $u->display_name; }, $users), 0, 8);
            echo '<ul style="margin:0;padding-left:18px;color:#555;font-size:12px;">';
            foreach ($names as $n) {
                echo '<li>' . esc_html($n) . '</li>';
            }
            echo '</ul>';
            if ($count > 8) {
                echo '<p style="margin:6px 0 0;color:#888;font-size:11px;">' .
                    esc_html(sprintf(
                        /* translators: %d: 总人数 */
                        __('…等共 %d 位用户。', 'at8-moonlight-shop'),
                        $count
                    )) . '</p>';
            }
        }
    }

    /**
     * 公告：提醒管理员为头像设置特色图片。
     */
    public function maybe_render_notice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::CPT) {
            return;
        }
        if ($screen->action === 'add' || (isset($screen->post->ID) && !has_post_thumbnail($screen->post->ID))) {
            echo '<div class="notice notice-info"><p>' .
                esc_html__('请为头像设置「特色图片」（≥256×256 的 PNG/JPG/WebP），未设置时不会出现在用户的选择列表中。', 'at8-moonlight-shop') .
                '</p></div>';
        }
    }
}

