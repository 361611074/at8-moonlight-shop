<?php
/**
 * Pro Elementor 组件：会员状态卡。
 *
 * 该文件仅在 \Elementor\Widget_Base 已加载后由 class-elementor-integration.php
 * 在 elementor/widgets/register 钩子中 require（文件名无 class- 前缀，不进自动加载）。
 *
 * @package Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUCP_Membership_Card extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'mlucp_membership_card';
    }

    public function get_title()
    {
        return __('漫步白月光 · 会员状态卡（Pro）', 'moonlight-user-center-pro');
    }

    public function get_icon()
    {
        return 'eicon-icon-box';
    }

    public function get_categories()
    {
        return array('moonlight');
    }

    protected function register_controls()
    {
        $this->start_controls_section('section_content', array('label' => __('内容', 'moonlight-user-center-pro')));

        $this->add_control('card_title', array(
            'label'   => __('卡片标题', 'moonlight-user-center-pro'),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => __('我的会员', 'moonlight-user-center-pro'),
            'label_block' => true,
        ));

        $this->add_control('show_expiry', array(
            'label'     => __('显示到期时间', 'moonlight-user-center-pro'),
            'type'      => \Elementor\Controls_Manager::SWITCHER,
            'default'   => 'yes',
        ));

        $this->add_control('btn_text', array(
            'label'   => __('升级按钮文字', 'moonlight-user-center-pro'),
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => __('升级会员', 'moonlight-user-center-pro'),
            'label_block' => true,
        ));

        $this->end_controls_section();
    }

    protected function render()
    {
        $settings = $this->get_settings_for_display();
        if (!is_user_logged_in() || !class_exists('MLUC_Membership')) {
            $login_url = function_exists('mluc_get_login_url') ? mluc_get_login_url() : wp_login_url();
            printf(
                '<div class="mlucp-membership-card"><p>%s</p></div>',
                sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($login_url),
                    esc_html__('请先登录后查看会员状态。', 'moonlight-user-center-pro')
                )
            );
            return;
        }

        $user_id = get_current_user_id();
        $level   = MLUC_Membership::get_user_level($user_id);
        $expired = MLUC_Membership::is_user_expired($user_id);
        $expires = MLUC_Membership::get_user_expires($user_id);

        $title = isset($settings['card_title']) ? $settings['card_title'] : __('我的会员', 'moonlight-user-center-pro');
        $out   = '<div class="mlucp-membership-card">';
        $out  .= '<h3 class="mlucp-membership-card-title">' . esc_html($title) . '</h3>';
        $out  .= '<div class="mlucp-membership-card-level">' . MLUC_Membership::get_level_badge($level) . '</div>';

        if ('yes' === ($settings['show_expiry'] ?? '') && $expires && !$expired) {
            $out .= '<p class="mlucp-membership-card-expiry">' . esc_html(sprintf(
                /* translators: %s: 到期日期 */
                __('到期：%s', 'moonlight-user-center-pro'),
                date_i18n(get_option('date_format', 'Y-m-d'), $expires)
            )) . '</p>';
        } elseif ($expired) {
            $out .= '<p class="mlucp-membership-card-expiry">' . esc_html(__('会员已过期', 'moonlight-user-center-pro')) . '</p>';
        }

        $btn_text = isset($settings['btn_text']) ? $settings['btn_text'] : __('升级会员', 'moonlight-user-center-pro');
        $account_url = function_exists('mluc_get_account_url') ? mluc_get_account_url() : home_url('/');
        $out .= '<p><a class="mluc-btn mluc-btn-small" href="' . esc_url(add_query_arg('tab', 'membership', $account_url)) . '">' . esc_html($btn_text) . '</a></p>';
        $out .= '</div>';

        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- 内部 HTML 全部已逐项转义。
    }
}
