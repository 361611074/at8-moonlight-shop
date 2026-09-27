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
    <h2 class="mluc-title"><?php echo esc_html(mluc_ui_label('rg_title', __('Create Account', 'moonlight-user-center'))); ?></h2>
    <form class="mluc-form" data-action="register">
        <p class="mluc-msg" role="alert"></p>
        <div class="mluc-field">
            <label for="mluc_reg_user"><?php echo esc_html(mluc_ui_label('rg_user', __('Username', 'moonlight-user-center'))); ?></label>
            <input type="text" id="mluc_reg_user" name="user_login" required>
        </div>
        <div class="mluc-field">
            <label for="mluc_reg_email"><?php echo esc_html(mluc_ui_label('rg_email', __('Email', 'moonlight-user-center'))); ?></label>
            <input type="email" id="mluc_reg_email" name="email" autocomplete="email" required>
        </div>
        <div class="mluc-field">
            <label for="mluc_reg_pass"><?php echo esc_html(mluc_ui_label('rg_pass', __('Password', 'moonlight-user-center'))); ?></label>
            <input type="password" id="mluc_reg_pass" name="password" autocomplete="new-password" required>
        </div>
        <button type="submit" class="mluc-btn"><?php echo esc_html(mluc_ui_label('rg_btn', __('Register', 'moonlight-user-center'))); ?></button>
        <input type="text" name="mluc_hp" value="" class="mluc-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <?php
        // 一次性渲染令牌：服务端签发，提交时校验最短填写时间并即焚（防机器人与重放）。
        $mluc_reg_tk = wp_generate_password(24, false, false);
        set_transient('mluc_reg_tk_' . md5($mluc_reg_tk), time(), HOUR_IN_SECONDS);
        ?>
        <input type="hidden" name="mluc_tk" value="<?php echo esc_attr($mluc_reg_tk); ?>">
    </form>
    <p class="mluc-links">
        <a href="<?php echo esc_url(add_query_arg('mluc_view', 'login', $base)); ?>"><?php echo esc_html(mluc_ui_label('rg_login', __('Already have an account? Log in', 'moonlight-user-center'))); ?></a>
    </p>
</div>
