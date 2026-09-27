<?php
/**
 * Elementor 组件集成（登录 / 注册 / 资料卡）。
 *
 * Widget 类定义拆到 class-elementor-widgets.php，仅在 Elementor 的
 * `elementor/widgets/register` 钩子（此时 \Elementor\Widget_Base 已就绪）内才引入，
 * 避免插件 require 本文件时因 Widget_Base 尚未加载而 fatal error。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Elementor
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
        // 仅当 Elementor 相关类存在时才挂接钩子；Widget 类延迟到注册时引入
        if (class_exists('\Elementor\Plugin') || class_exists('\Elementor\Widget_Base')) {
            add_action('elementor/elements/categories_registered', array($this, 'register_category'));
            add_action('elementor/widgets/register', array($this, 'register_widgets'));
        }
    }

    public function register_category($elements_manager)
    {
        $elements_manager->add_category(
            'moonlight',
            array(
                'title' => __('漫步白月光', 'moonlight-user-center'),
                'icon'  => 'eicon-plug',
            )
        );
    }

    public function register_widgets($widgets_manager)
    {
        // 此时 Elementor 已完全加载，Widget_Base 必然可用
        if (!class_exists('\Elementor\Widget_Base')) {
            return;
        }

        require_once __DIR__ . '/class-elementor-widgets.php';

        $widgets = apply_filters('mluc_elementor_widgets', array(
            new MLUC_Elementor_Login(),
            new MLUC_Elementor_Register(),
            new MLUC_Elementor_Profile(),
        ));
        foreach ($widgets as $widget) {
            if ($widget instanceof \Elementor\Widget_Base) {
                $widgets_manager->register($widget);
            }
        }
    }
}
