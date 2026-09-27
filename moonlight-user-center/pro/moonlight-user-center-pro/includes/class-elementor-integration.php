<?php
/**
 * Pro 功能：Elementor「会员状态卡」组件集成。
 *
 * Widget 类延迟到 elementor/widgets/register 钩子内引入（与 Free 的做法一致，
 * 避免 Widget_Base 未加载时 fatal error）；组件注册经 Free 的
 * mluc_elementor_widgets 过滤器挂载（Free 不感知 Pro 存在）。
 *
 * @package Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUCP_Elementor_Integration
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
        if (class_exists('\Elementor\Plugin') || class_exists('\Elementor\Widget_Base')) {
            add_action('elementor/elements/categories_registered', array($this, 'register_category'));
            add_filter('mluc_elementor_widgets', array($this, 'filter_widgets'));
        }
    }

    public function register_category($elements_manager)
    {
        // Free 已注册 moonlight 分类；此处兜底（Free 未启用场景已被主文件拦截）。
        $categories = $elements_manager->get_categories();
        if (!isset($categories['moonlight'])) {
            $elements_manager->add_category('moonlight', array(
                'title' => __('漫步白月光', 'moonlight-user-center-pro'),
                'icon'  => 'eicon-plug',
            ));
        }
    }

    /**
     * 向 Free 的组件列表追加 Pro 组件（License 未激活时不追加）。
     */
    public function filter_widgets($widgets)
    {
        if (!is_array($widgets) || !MLUCP_License_Client::is_active()) {
            return $widgets;
        }
        require_once MLUCP_PLUGIN_DIR . 'includes/elementor-membership-card.php';
        $widgets[] = new MLUCP_Membership_Card();
        return $widgets;
    }
}
