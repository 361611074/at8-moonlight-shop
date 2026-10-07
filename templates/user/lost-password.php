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
    <h2 class="mluc-title"><?php echo esc_html(mluc_ui_label('lp_title', __('Lost Password', 'at8-moonlight-shop'))); ?></h2>
    <form class="mluc-form" data-action="lost_password">
        <p class="mluc-msg" role="alert"></p>
        <div class="mluc-field">
            <label for="mluc_lost_user"><?php echo esc_html(mluc_ui_label('lp_user', __('Username or Email', 'at8-moonlight-shop'))); ?></label>
            <input type="text" id="mluc_lost_user" name="user_login" required>
        </div>
        <button type="submit" class="mluc-btn"><?php echo esc_html(mluc_ui_label('lp_btn', __('Send Reset Link', 'at8-moonlight-shop'))); ?></button>
    </form>
    <p class="mluc-links">
        <a href="<?php echo esc_url(mluc_get_login_url()); ?>"><?php echo esc_html(mluc_ui_label('lp_back', __('Back to Log In', 'at8-moonlight-shop'))); ?></a>
    </p>
</div>
