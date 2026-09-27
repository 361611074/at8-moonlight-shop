<?php
/**
 * 找回密码表单模板。
 */
if (!defined('ABSPATH')) {
    exit;
}
$base = esc_url(remove_query_arg('mluc_view'));
?>
<div class="mluc-form-wrap mluc-lostpassword">
    <h2 class="mluc-title"><?php esc_html_e('找回密码', 'moonlight-user-center'); ?></h2>
    <form class="mluc-form" data-action="lost_password">
        <p class="mluc-msg" role="alert"></p>
        <div class="mluc-field">
            <label for="mluc_lost_user"><?php esc_html_e('用户名或邮箱', 'moonlight-user-center'); ?></label>
            <input type="text" id="mluc_lost_user" name="user_login" required>
        </div>
        <button type="submit" class="mluc-btn"><?php esc_html_e('发送重置链接', 'moonlight-user-center'); ?></button>
    </form>
    <p class="mluc-links">
        <a href="<?php echo esc_url(mluc_get_login_url()); ?>"><?php esc_html_e('返回登录', 'moonlight-user-center'); ?></a>
    </p>
</div>
