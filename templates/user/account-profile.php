<?php
/**
 * 资料编辑 Tab：基础资料 + 修改密码 + 头像选择（管理员预上传的）。
 *
 * 按 .mluc-card 卡片化分隔，与后台「用户中心设置」风格保持一致。
 *
 * @var WP_User $user
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}

$avatar        = get_avatar_url($user->ID, array('size' => 160));
$enable_avatar = mluc_get_option('enable_avatar', 1);
$selected_id   = (int) get_user_meta($user->ID, MLUC_Avatar::META_SELECTED, true);
$library       = $enable_avatar ? MLUC_Avatar::get_library() : array();
?>
<div class="mluc-profile">

    <?php if ($enable_avatar) : ?>
        <section class="mluc-card" aria-label="<?php esc_attr_e('头像选择', 'at8-moonlight-shop'); ?>">
            <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('pf_avatar_title', __('Avatar', 'at8-moonlight-shop'))); ?></h3>
            <p class="mluc-card-subtitle">
                <?php echo esc_html(mluc_ui_label('pf_avatar_sub', __('Choose one from the avatar library below; avatars are maintained by the administrator.', 'at8-moonlight-shop'))); ?>
            </p>

            <div class="mluc-profile-avatar">
                <div class="mluc-profile-avatar__preview">
                    <img class="mluc-avatar mluc-avatar-lg" id="mluc_avatar_preview" src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                </div>
                <div class="mluc-profile-avatar__meta">
                    <h3><?php echo esc_html(mluc_ui_label('pf_avatar_current', __('Current Avatar', 'at8-moonlight-shop'))); ?></h3>
                    <p class="description">
                        <?php echo esc_html(mluc_ui_label('pf_avatar_tip', __('Click any avatar below to switch instantly, no save needed.', 'at8-moonlight-shop'))); ?>
                    </p>
                    <p class="mluc-profile-avatar__msg" id="mluc_avatar_msg" role="status" aria-live="polite"></p>
                </div>
            </div>

            <?php if (empty($library)) : ?>
                <div class="mluc-empty">
                    <?php echo esc_html(mluc_ui_label('pf_avatar_empty', __('No avatars have been uploaded yet. Please contact the administrator.', 'at8-moonlight-shop'))); ?>
                </div>
            <?php else : ?>
                <div class="mluc-avatar-picker" role="radiogroup" aria-label="<?php esc_attr_e('选择头像', 'at8-moonlight-shop'); ?>">
                    <?php foreach ($library as $item) :
                        $is_selected = ((int) $item['thumb'] === $selected_id);
                        ?>
                        <button type="button"
                            class="mluc-avatar-option<?php echo $is_selected ? ' is-selected' : ''; ?>"
                            role="radio"
                            aria-checked="<?php echo $is_selected ? 'true' : 'false'; ?>"
                            data-mluc-pick-avatar
                            data-avatar-id="<?php echo esc_attr((int) $item['id']); ?>"
                            data-avatar-thumb="<?php echo esc_attr((int) $item['thumb']); ?>"
                            title="<?php echo esc_attr($item['title']); ?>">
                            <img src="<?php echo esc_url($item['url']); ?>" alt="<?php echo esc_attr($item['title']); ?>">
                            <span class="mluc-avatar-option__check" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
                                    <path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/>
                                </svg>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="mluc-card" aria-label="<?php esc_attr_e('基础资料', 'at8-moonlight-shop'); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('pf_base_title', __('Basic Information', 'at8-moonlight-shop'))); ?></h3>
        <p class="mluc-card-subtitle">
            <?php echo esc_html(mluc_ui_label('pf_base_sub', __('This information appears on your public profile and article bylines.', 'at8-moonlight-shop'))); ?>
        </p>

        <form class="mluc-form" data-action="update_profile" novalidate>
            <p class="mluc-msg" role="alert"></p>
            <div class="mluc-field">
                <label for="mluc_email"><?php echo esc_html(mluc_ui_label('pf_email', __('Email', 'at8-moonlight-shop'))); ?></label>
                <input type="email" id="mluc_email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" disabled>
                <p class="description"><?php echo esc_html(mluc_ui_label('pf_email_sub', __('To change your email, please contact the administrator.', 'at8-moonlight-shop'))); ?></p>
            </div>
            <div class="mluc-field">
                <label for="mluc_display_name"><?php echo esc_html(mluc_ui_label('pf_display', __('Display Name', 'at8-moonlight-shop'))); ?></label>
                <input type="text" id="mluc_display_name" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" required>
            </div>
            <div class="mluc-field">
                <label for="mluc_nickname"><?php echo esc_html(mluc_ui_label('pf_nickname', __('Nickname', 'at8-moonlight-shop'))); ?></label>
                <input type="text" id="mluc_nickname" name="nickname" value="<?php echo esc_attr($user->nickname); ?>">
            </div>
            <div class="mluc-field">
                <label for="mluc_phone"><?php echo esc_html(mluc_ui_label('pf_phone', __('Phone', 'at8-moonlight-shop'))); ?></label>
                <input type="tel" id="mluc_phone" name="phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'phone', true)); ?>" placeholder="<?php echo esc_attr(mluc_ui_label('pf_phone_ph', __('e.g. 9123 4567', 'at8-moonlight-shop'))); ?>">
            </div>
            <div class="mluc-field">
                <label for="mluc_url"><?php echo esc_html(mluc_ui_label('pf_url', __('Website', 'at8-moonlight-shop'))); ?></label>
                <input type="url" id="mluc_url" name="user_url" value="<?php echo esc_attr($user->user_url); ?>">
            </div>
            <div class="mluc-field">
                <label for="mluc_desc"><?php echo esc_html(mluc_ui_label('pf_bio', __('Bio', 'at8-moonlight-shop'))); ?></label>
                <textarea id="mluc_desc" name="description" rows="4"><?php echo esc_textarea($user->description); ?></textarea>
            </div>
            <button type="submit" class="mluc-btn"><?php echo esc_html(mluc_ui_label('pf_save', __('Save Changes', 'at8-moonlight-shop'))); ?></button>
        </form>
    </section>

    <section class="mluc-card" aria-label="<?php esc_attr_e('修改密码', 'at8-moonlight-shop'); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('pf_pass_title', __('Change Password', 'at8-moonlight-shop'))); ?></h3>
        <p class="mluc-card-subtitle">
            <?php echo esc_html(mluc_ui_label('pf_pass_sub', __('For account security, change your password regularly. You will need to log in again after changing it.', 'at8-moonlight-shop'))); ?>
        </p>

        <form class="mluc-form mluc-password-form" data-action="change_password" novalidate>
            <p class="mluc-msg" role="alert"></p>
            <div class="mluc-field">
                <label for="mluc_old_pass"><?php echo esc_html(mluc_ui_label('pf_old_pass', __('Current Password', 'at8-moonlight-shop'))); ?></label>
                <input type="password" id="mluc_old_pass" name="old_password" autocomplete="current-password" required>
            </div>
            <div class="mluc-field">
                <label for="mluc_new_pass"><?php echo esc_html(mluc_ui_label('pf_new_pass', __('New Password', 'at8-moonlight-shop'))); ?></label>
                <input type="password" id="mluc_new_pass" name="new_password" autocomplete="new-password" required>
                <p class="description"><?php echo esc_html(mluc_ui_label('pf_new_pass_sub', __('At least 6 characters.', 'at8-moonlight-shop'))); ?></p>
            </div>
            <button type="submit" class="mluc-btn"><?php echo esc_html(mluc_ui_label('pf_pass_save', __('Update Password', 'at8-moonlight-shop'))); ?></button>
        </form>
    </section>
</div>
