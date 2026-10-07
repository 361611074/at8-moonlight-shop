<?php
/**
 * Elementor 集成入口：注册「漫步白月光商城」小工具分类，并在 Elementor 可用时
 * 加载一组商城小工具。本文件不依赖 Elementor 类，可随时安全加载；
 * 真正的小工具类（extends Elementor\Widget_Base）放在 elementor-widgets.php，
 * 仅在 Elementor 触发 register 时才载入，避免 Elementor 未加载时报错。
 *
 * 零第三方电商依赖：此处不引用任何外部电商插件的符号。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Elementor
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
        // 仅在 Elementor 可用时挂接；Elementor 未启用则完全不介入
        if (did_action('elementor/loaded')) {
            $this->bootstrap();
        } else {
            add_action('elementor/loaded', array($this, 'bootstrap'));
        }
    }

    public function bootstrap()
    {
        // 允许用 Elementor 编辑商品
        add_post_type_support('mlshop_product', 'elementor');

        add_action('elementor/widgets/register', array($this, 'register_widgets'));
        add_action('elementor/elements/categories_registered', array($this, 'register_category'));
    }

    /**
     * 注册专属小工具分类，并载入小工具类。
     */
    public function register_category($elements_manager)
    {
        $elements_manager->add_category('at8-moonlight-shop', array(
            'title' => __('漫步白月光商城', 'at8-moonlight-shop'),
            'icon'  => 'eicon-cart',
        ));
    }

    public function register_widgets($widgets_manager)
    {
        $file = MLSHOP_PLUGIN_DIR . 'includes/elementor-widgets.php';
        if (!file_exists($file)) {
            return;
        }
        require_once $file;
        $widgets_manager->register(new MLSHOP_Elementor_Products());
        $widgets_manager->register(new MLSHOP_Elementor_Product_Single());
        $widgets_manager->register(new MLSHOP_Elementor_Add_To_Cart());
        $widgets_manager->register(new MLSHOP_Elementor_Categories());
        $widgets_manager->register(new MLSHOP_Elementor_Cart());
        $widgets_manager->register(new MLSHOP_Elementor_Favorites());
        $widgets_manager->register(new MLSHOP_Elementor_Search());
    }

    /**
     * 商品下拉选项（含「当前商品」）。
     */
    public static function product_options()
    {
        $opts = array('current' => __('当前商品（循环中）', 'at8-moonlight-shop'));
        $posts = get_posts(array('post_type' => 'mlshop_product', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'));
        foreach ($posts as $p) {
            $opts[(string) $p->ID] = mb_substr($p->post_title, 0, 40);
        }
        return $opts;
    }

    /**
     * 商品分类下拉选项。
     */
    public static function category_options()
    {
        $opts = array('' => __('全部', 'at8-moonlight-shop'));
        foreach (get_terms(array('taxonomy' => 'mlshop_product_cat', 'hide_empty' => false)) as $t) {
            $opts[$t->slug] = $t->name;
        }
        return $opts;
    }

    /**
     * 解析「当前商品」为真实 ID。
     */
    public static function resolve_product_id($val)
    {
        if (empty($val) || $val === 'current') {
            return get_the_ID();
        }
        return (int) $val;
    }
}
