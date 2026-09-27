<?php
/**
 * 经典编辑器「隐藏内容」菜单按钮（TinyMCE 插件 + 工具栏注册）。
 *
 * 仅在管理员能使用经典编辑器的页面（post / page 编辑页）按需 enqueue，
 * 不会污染前端。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Editor_Button
{
    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct()
    {
        // 仅在文章/页面编辑页注册，避免编辑器出现在不该出现的位置
        add_action('admin_head-post.php', array($this, 'register_button'));
        add_action('admin_head-post-new.php', array($this, 'register_button'));
    }

    /**
     * 注册 TinyMCE 插件与工具栏按钮（仅对 post / page 生效）。
     */
    public function register_button()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->post_type)) {
            return;
        }
        if (!in_array($screen->post_type, array('post', 'page'), true)) {
            return;
        }
        // 不让 Gutenberg 抢走（虽然 post.php 仍会加载 classic-editor 兼容层）
        add_filter('mce_external_plugins', array($this, 'add_tinymce_plugin'));
        add_filter('mce_buttons_2', array($this, 'register_toolbar_button'));
    }

    /**
     * 把 mluc-tinymce.js 作为 TinyMCE 插件注入。
     *
     * @param array $plugins
     * @return array
     */
    public function add_tinymce_plugin($plugins)
    {
        $plugins['mluc_hidecontent'] = MLUC_PLUGIN_URL . 'assets/js/mluc-tinymce.js?ver=' . MLUC_VERSION;
        return $plugins;
    }

    /**
     * 把按钮名加到第二行工具栏。
     *
     * @param array $buttons
     * @return array
     */
    public function register_toolbar_button($buttons)
    {
        $buttons[] = 'mluc_hidecontent';
        return $buttons;
    }
}
