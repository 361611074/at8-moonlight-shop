<?php
/**
 * Elementor Widget 类定义。
 *
 * 该文件仅在 Elementor 的 \Elementor\Widget_Base 已加载后，
 * 由 class-elementor.php 的 register_widgets() 在 elementor/widgets/register 钩子中引入，
 * 避免插件 require 阶段过早引用 Widget_Base 导致 fatal error。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class MLUC_Elementor_Widget_Base extends \Elementor\Widget_Base
{
    abstract protected function shortcode_tag();

    public function get_icon()
    {
        return 'eicon-form-horizontal';
    }

    public function get_categories()
    {
        return array('moonlight');
    }

    protected function register_controls()
    {
        $this->start_controls_section('section_content', array('label' => __('内容', 'moonlight-user-center')));
        $this->add_control('note', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw'  => __('该组件直接渲染对应前端表单，样式由主题配合。', 'moonlight-user-center'),
        ));
        $this->end_controls_section();
    }

    protected function render()
    {
        echo do_shortcode('[' . $this->shortcode_tag() . ']');
    }
}

class MLUC_Elementor_Login extends MLUC_Elementor_Widget_Base
{
    public function get_name()
    {
        return 'mluc_login';
    }

    public function get_title()
    {
        return __('漫步白月光 · 登录表单', 'moonlight-user-center');
    }

    protected function shortcode_tag()
    {
        return 'mluc_login';
    }
}

class MLUC_Elementor_Register extends MLUC_Elementor_Widget_Base
{
    public function get_name()
    {
        return 'mluc_register';
    }

    public function get_title()
    {
        return __('漫步白月光 · 注册表单', 'moonlight-user-center');
    }

    protected function shortcode_tag()
    {
        return 'mluc_register';
    }
}

class MLUC_Elementor_Profile extends MLUC_Elementor_Widget_Base
{
    public function get_name()
    {
        return 'mluc_profile';
    }

    public function get_title()
    {
        return __('漫步白月光 · 用户资料卡', 'moonlight-user-center');
    }

    protected function shortcode_tag()
    {
        return 'mluc_account';
    }
}
