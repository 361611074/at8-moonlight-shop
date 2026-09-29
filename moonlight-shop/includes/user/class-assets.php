<?php
/**
 * 前端资源（CSS / JS）加载与本地化。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Assets
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
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
    }

    public function enqueue()
    {
        // 站点通用样式：hidecontent 锁定卡 + 账户中心基础样式。
        // 全站加载（~32KB），避免 Elementor 模板 / 可复用区块等场景下条件检测漏检导致卡片无样式。
        wp_enqueue_style('mluc-style', MLUC_PLUGIN_URL . 'assets/css/mluc.css', array(), MLUC_VERSION);

        // 交互资源（dashicons + mluc.js）仅在真正用到账户中心交互的页面加载。
        $has = false;
        if (is_singular()) {
            $content = get_post_field('post_content', get_the_ID());
            $has = has_shortcode($content, 'mluc_login')
                || has_shortcode($content, 'mluc_register')
                || has_shortcode($content, 'mluc_lostpassword')
                || has_shortcode($content, 'mluc_account')
                || has_shortcode($content, 'mluc_videos')
                || has_shortcode($content, 'mluc_video')
                || has_shortcode($content, 'mluc_materials')
                || has_shortcode($content, 'mluc_downloads')
                || has_shortcode($content, 'mluc_purchases');
        }
        // CPT 归錄页加载（主题启用 mluc_demo_video / mluc_material 归錄时仍可正常加載樣式與腳本）
        if (!$has && is_post_type_archive(array('mluc_demo_video', 'mluc_material'))) {
            $has = true;
        }

        if (!$has) {
            return;
        }

        // 前台账户中心侧栏使用 dashicons 字体；前台默认未启用，需显式 enqueue
        // 避免图标在前端显示为方块。
        wp_enqueue_style('dashicons');
        wp_enqueue_script('mluc-script', MLUC_PLUGIN_URL . 'assets/js/mluc.js', array('jquery'), MLUC_VERSION, true);
        wp_localize_script('mluc-script', 'MLUC', mluc_ajax_data());
        wp_localize_script('mluc-script', 'mluc_i18n', array(
            'op_failed'           => mluc_ui_label('av_op_failed', 'Operation failed.'),
            'network_error_retry' => mluc_ui_label('av_network_retry', 'Network error. Please retry.'),
            'uploading'           => mluc_ui_label('av_uploading', 'Uploading…'),
            'upload_failed'       => mluc_ui_label('av_upload_failed', 'Upload failed.'),
            'network_error'       => mluc_ui_label('av_network_error', 'Network error.'),
            'switching'           => mluc_ui_label('av_switching', 'Switching…'),
            'avatar_updated'      => mluc_ui_label('av_avatar_updated', 'Avatar updated.'),
        ));
    }

    /**
     * 后台编辑器页面：将「隐藏内容」TinyMCE 下拉按钮所需文案写到 head 内联变量。
     *
     * 改为内联输出而非 wp_localize_script('wp-editor', ...)，避免依赖 WP 核心 handle
     * 的注册顺序（post.php 上 _WP_Editors 与本钩子都挂在 admin_enqueue_scripts p10，
     * 存在竞争）。实际 JS 在 MLUC_Editor_Button 注册的 mce_external_plugins 时机加载。
     */
    public function enqueue_admin($hook)
    {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        add_action('admin_head', array($this, 'print_tinymce_i18n'));

        // 「付费功能（用户中心）」Meta Box 交互（文章 / 页面编辑器）。
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->post_type) && in_array($screen->post_type, array('post', 'page'), true)) {
            wp_enqueue_media();
            wp_enqueue_script('mluc-pw-admin', MLUC_PLUGIN_URL . 'assets/js/mluc-pw-admin.js', array('jquery'), MLUC_VERSION, true);
        }
    }

    /**
     * admin_head 输出 mluc_tinymce_i18n 全局变量。
     */
    public function print_tinymce_i18n()
    {
        $types = array();
        if (class_exists('MLUC_Hidecontent')) {
            foreach (MLUC_Hidecontent::$types as $key => $info) {
                $types[] = array(
                    'value' => $key,
                    'label' => isset($info['label']) ? __((string) $info['label'], 'moonlight-user-center') : $key,
                );
            }
        }
        $payload = array(
            'button_label' => __('隐藏内容', 'moonlight-user-center'),
            'inserting'    => __('请选择一种隐藏类型…', 'moonlight-user-center'),
            'types'        => $types,
        );
        echo '<script>window.mluc_tinymce_i18n = ' . wp_json_encode($payload) . ';</script>';
    }
}
