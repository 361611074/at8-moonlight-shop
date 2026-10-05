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
            'unsupported'     => __('Unsupported login method.', 'moonlight-shop'),
            'cancelled'       => __('Authorization cancelled. Please try again or use another method.', 'moonlight-shop'),
            'state'           => __('Security check failed (invalid state). Please try again.', 'moonlight-shop'),
            'token'           => __('Token exchange failed. Please try again later.', 'moonlight-shop'),
            'profile'         => __('Failed to retrieve account info. Please try again.', 'moonlight-shop'),
            'register_closed' => __('Registration is closed and no matching account was found.', 'moonlight-shop'),
            'register_fail'   => __('Automatic registration failed. Please try again later or contact the administrator.', 'moonlight-shop'),
        );
        $oauth_err_msg = isset($oauth_err_map[$ec]) ? $oauth_err_map[$ec] : __('Third-party login failed. Please try again.', 'moonlight-shop');
        ?>
        <p class="mluc-msg mluc-error" role="alert"><?php echo esc_html($oauth_err_msg); ?></p>
    <?php endif; ?>
    <h2 class="mluc-title"><?php echo esc_html(mluc_ui_label('lg_title', __('Log In', 'moonlight-shop'))); ?></h2>
    <form class="mluc-form" data-action="login">
        <p class="mluc-msg" role="alert"></p>
        <?php if ($redirect_to) : ?>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
        <?php endif; ?>
        <div class="mluc-field">
            <label for="mluc_login_user"><?php echo esc_html(mluc_ui_label('lg_user', __('Username or Email', 'moonlight-shop'))); ?></label>
            <input type="text" id="mluc_login_user" name="user_login" autocomplete="username" required>
        </div>
        <div class="mluc-field">
            <label for="mluc_login_pass"><?php echo esc_html(mluc_ui_label('lg_pass', __('Password', 'moonlight-shop'))); ?></label>
            <input type="password" id="mluc_login_pass" name="password" autocomplete="current-password" required>
        </div>
        <label class="mluc-checkbox">
            <input type="checkbox" name="remember" value="1"> <?php echo esc_html(mluc_ui_label('lg_remember', __('Remember Me', 'moonlight-shop'))); ?>
        </label>
        <button type="submit" class="mluc-btn"><?php echo esc_html(mluc_ui_label('lg_btn', __('Log In', 'moonlight-shop'))); ?></button>
    </form>
    <?php do_action('mluc_oauth_buttons'); ?>
    <p class="mluc-links">
        <a href="<?php echo esc_url(mluc_get_lostpassword_url()); ?>"><?php echo esc_html(mluc_ui_label('lg_forgot', __('Forgot password?', 'moonlight-shop'))); ?></a>
        <?php if (get_option('users_can_register')) : ?>
            <span class="mluc-sep">·</span>
            <a href="<?php echo esc_url(add_query_arg('mluc_view', 'register', $base)); ?>"><?php echo esc_html(mluc_ui_label('lg_register', __('Quick Sign Up (Email Only)', 'moonlight-shop'))); ?></a>
        <?php endif; ?>
    </p>
</div>
