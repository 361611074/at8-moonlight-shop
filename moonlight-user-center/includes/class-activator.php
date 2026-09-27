<?php
/**
 * 激活 / 停用逻辑。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Activator
{
    /**
     * 插件激活：创建账户中心页面、初始化选项、刷新重写规则。
     */
    public static function activate()
    {
        $options = get_option('mluc_options', array());

        if (empty($options['account_page_id']) || !get_post($options['account_page_id'])) {
            $page_id = wp_insert_post(array(
                'post_title'   => '账户中心',
                'post_name'    => 'account',
                'post_content' => '[mluc_account]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
            if ($page_id && !is_wp_error($page_id)) {
                $options['account_page_id'] = $page_id;
            }
        }

        // 自动创建登录 / 注册页，供账户中心跳转与独立访问。
        $auth_pages = array(
            'login_page_id'        => array('登录', 'login', '[mluc_login]'),
            'register_page_id'     => array('注册', 'register', '[mluc_register]'),
            'lostpassword_page_id' => array('找回密码', 'lost-password', '[mluc_lostpassword]'),
        );
        foreach ($auth_pages as $opt_key => $cfg) {
            if (empty($options[$opt_key]) || !get_post($options[$opt_key])) {
                $page_id = wp_insert_post(array(
                    'post_title'   => $cfg[0],
                    'post_name'    => $cfg[1],
                    'post_content' => $cfg[2],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ));
                if ($page_id && !is_wp_error($page_id)) {
                    $options[$opt_key] = $page_id;
                }
            }
        }

        if (!isset($options['enable_avatar'])) {
            $options['enable_avatar'] = 1;
        }
        if (!isset($options['redirect_after_login'])) {
            $options['redirect_after_login'] = '';
        }

        update_option('mluc_options', $options);

        // 默认角色能力无需改动，使用 WordPress 原生。
        flush_rewrite_rules();
    }

    /**
     * 插件停用：仅刷新规则，不删除用户数据。
     */
    public static function deactivate()
    {
        flush_rewrite_rules();
    }
}
