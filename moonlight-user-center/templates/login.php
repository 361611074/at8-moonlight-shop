<?php
/**
 * 登录表单模板。
 */
if (!defined('ABSPATH')) {
    exit;
}
$base = esc_url(remove_query_arg('mluc_view'));
$redirect_to = isset($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : '';
?>
<div class="mluc-form-wrap mluc-login">
    <?php if (isset($_GET['oauth_error'])) :
        $ec = sanitize_key($_GET['oauth_error']);
        $oauth_err_map = array(
            'unsupported'     => __('不支持的登录方式。', 'moonlight-user-center'),
            'cancelled'       => __('已取消授权，请重试或使用其他方式登录。', 'moonlight-user-center'),
            'state'           => __('安全校验失败（state 无效），请重试。', 'moonlight-user-center'),
            'token'           => __('授权交换失败，请稍后重试。', 'moonlight-user-center'),
            'profile'         => __('获取第三方账号信息失败，请重试。', 'moonlight-user-center'),
            'register_closed' => __('站点已关闭注册，且未找到匹配的账号。', 'moonlight-user-center'),
            'register_fail'   => __('自动注册失败，请稍后重试或联系管理员。', 'moonlight-user-center'),
        );
        $oauth_err_msg = isset($oauth_err_map[$ec]) ? $oauth_err_map[$ec] : __('第三方登录失败，请重试。', 'moonlight-user-center');
        ?>
        <p class="mluc-msg mluc-error" role="alert"><?php echo esc_html($oauth_err_msg); ?></p>
    <?php endif; ?>
    <h2 class="mluc-title"><?php esc_html_e('登录', 'moonlight-user-center'); ?></h2>
    <form class="mluc-form" data-action="login">
        <p class="mluc-msg" role="alert"></p>
        <?php if ($redirect_to) : ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
        <?php endif; ?>
        <div class="mluc-field">
            <label for="mluc_login_user"><?php esc_html_e('用户名或邮箱', 'moonlight-user-center'); ?></label>
            <input type="text" id="mluc_login_user" name="user_login" autocomplete="username" required>
        </div>
        <div class="mluc-field">
            <label for="mluc_login_pass"><?php esc_html_e('密码', 'moonlight-user-center'); ?></label>
            <input type="password" id="mluc_login_pass" name="password" autocomplete="current-password" required>
        </div>
        <label class="mluc-checkbox">
            <input type="checkbox" name="remember" value="1"> <?php esc_html_e('记住我', 'moonlight-user-center'); ?>
        </label>
        <button type="submit" class="mluc-btn"><?php esc_html_e('登录', 'moonlight-user-center'); ?></button>
    </form>
    <?php do_action('mluc_oauth_buttons'); ?>
    <p class="mluc-links">
        <a href="<?php echo esc_url(mluc_get_lostpassword_url()); ?>"><?php esc_html_e('忘记密码？', 'moonlight-user-center'); ?></a>
        <?php if (get_option('users_can_register')) : ?>
            <span class="mluc-sep">·</span>
            <a href="<?php echo esc_url(add_query_arg('mluc_view', 'register', $base)); ?>"><?php esc_html_e('立即注册', 'moonlight-user-center'); ?></a>
        <?php endif; ?>
    </p>
</div>
