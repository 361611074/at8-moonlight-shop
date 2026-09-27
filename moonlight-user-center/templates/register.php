<?php
/**
 * 注册表单模板。
 */
if (!defined('ABSPATH')) {
    exit;
}
$base = esc_url(remove_query_arg('mluc_view'));
?>
<div class="mluc-form-wrap mluc-register">
    <h2 class="mluc-title"><?php esc_html_e('注册账户', 'moonlight-user-center'); ?></h2>
    <form class="mluc-form" data-action="register">
        <p class="mluc-msg" role="alert"></p>
        <div class="mluc-field">
            <label for="mluc_reg_user"><?php esc_html_e('用户名', 'moonlight-user-center'); ?></label>
            <input type="text" id="mluc_reg_user" name="user_login" required>
        </div>
        <div class="mluc-field">
            <label for="mluc_reg_email"><?php esc_html_e('邮箱', 'moonlight-user-center'); ?></label>
            <input type="email" id="mluc_reg_email" name="email" autocomplete="email" required>
        </div>
        <div class="mluc-field">
            <label for="mluc_reg_pass"><?php esc_html_e('密码', 'moonlight-user-center'); ?></label>
            <input type="password" id="mluc_reg_pass" name="password" autocomplete="new-password" required>
        </div>
        <button type="submit" class="mluc-btn"><?php esc_html_e('注册', 'moonlight-user-center'); ?></button>
    </form>
    <p class="mluc-links">
        <a href="<?php echo esc_url(add_query_arg('mluc_view', 'login', $base)); ?>"><?php esc_html_e('已有账户？去登录', 'moonlight-user-center'); ?></a>
    </p>
</div>
