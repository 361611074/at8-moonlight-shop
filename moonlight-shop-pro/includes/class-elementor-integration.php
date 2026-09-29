<?php
/**
 * Elementor「会员状态卡」组件集成（自 MLUCP 并入，MERGE-USER-CENTER.md Phase D）。
 *
 * 原 moonlight-user-center-pro 的 MLUCP_Elementor_Integration 原样收编：
 * - 类名保留 MLUCP_ 前缀（组件名 mlucp_membership_card / 存量模板引用不变）；
 * - 旧 Pro（moonlight-user-center-pro）仍激活时本副本整体让位：enabled() 返回
 *   false，不注册任何钩子，其自带的同名组件照常工作（避免重复注册）；
 * - 旧版经旧 Free 的 mluc_elementor_widgets 过滤器挂载；并入后商城不再注册
 *   Elementor 组件，故本类直接挂 elementor/widgets/register 自行注册（与旧
 *   Free MLUC_Elementor 同钩子共存，组件名不同互不冲突）；
 * - Widget 类延迟到 elementor/widgets/register 钩子内引入（此时
 *   \Elementor\Widget_Base 已就绪，避免 fatal error）；
 * - 渲染依赖 MLUC_Membership——已并入 moonlight-shop，独立运行可用；
 *   License 未激活时不注册组件（门禁与 Pro 其余模块一致）。
 *
 * @package Moonlight_Shop_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

// 类名守卫：旧 MLUCP Pro 激活时其自动加载器可能已加载同名类，本副本让位。
if (class_exists('MLUCP_Elementor_Integration', false)) {
    return;
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

    /**
     * 集成是否启用（静态可测）：旧 MLUCP Pro 让位 + Elementor 条件加载保留。
     *
     * @return bool
     */
    public static function enabled()
    {
        // 旧 MLUCP Pro 激活（主文件顶层 define MLUCP_VERSION，先于 plugins_loaded
        // 可见）：其自带同款组件，避免重复注册。
        if (defined('MLUCP_VERSION') || class_exists('MLUCP_License_Client', false)) {
            return false;
        }
        // Elementor 条件加载（did_action('elementor/loaded') 的类存在等价探测，
        // 与收编前一致：相关类存在才挂钩子，Widget 类延迟到注册时引入）。
        return class_exists('\Elementor\Plugin') || class_exists('\Elementor\Widget_Base');
    }

    private function __construct()
    {
        if (!self::enabled()) {
            return;
        }
        add_action('elementor/elements/categories_registered', array($this, 'register_category'));
        add_action('elementor/widgets/register', array($this, 'register_widgets'));
    }

    public function register_category($elements_manager)
    {
        // 旧 Free 已注册 moonlight 分类时跳过（收编自 MLUCP 的兜底逻辑）。
        $categories = $elements_manager->get_categories();
        if (!isset($categories['moonlight'])) {
            $elements_manager->add_category('moonlight', array(
                'title' => __('漫步白月光', 'moonlight-shop-pro'),
                'icon'  => 'eicon-plug',
            ));
        }
    }

    /**
     * 直接注册 Pro 组件（License 未激活时不注册）。
     *
     * @param object $widgets_manager Elementor Widgets Manager。
     */
    public function register_widgets($widgets_manager)
    {
        if (!class_exists('\Elementor\Widget_Base') || !MLPRO_License_Client::is_active()) {
            return;
        }
        require_once MLPRO_PLUGIN_DIR . 'includes/elementor-membership-card.php';
        $widgets_manager->register(new MLUCP_Membership_Card());
    }
}
