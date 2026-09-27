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
        <section class="mluc-card" aria-label="<?php esc_attr_e('头像选择', 'moonlight-user-center'); ?>">
            <h3 class="mluc-card-title"><?php esc_html_e('頭像', 'moonlight-user-center'); ?></h3>
            <p class="mluc-card-subtitle">
                <?php esc_html_e('從下方頭像庫中選擇一個作為你的頭像；頭像由管理員統一維護。', 'moonlight-user-center'); ?>
            </p>

            <div class="mluc-profile-avatar">
                <div class="mluc-profile-avatar__preview">
                    <img class="mluc-avatar mluc-avatar-lg" id="mluc_avatar_preview" src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                </div>
                <div class="mluc-profile-avatar__meta">
                    <h3><?php esc_html_e('當前頭像', 'moonlight-user-center'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('點擊下方任意頭像即可即時切換，無需保存。', 'moonlight-user-center'); ?>
                    </p>
                    <p class="mluc-profile-avatar__msg" id="mluc_avatar_msg" role="status" aria-live="polite"></p>
                </div>
            </div>

            <?php if (empty($library)) : ?>
                <div class="mluc-empty">
                    <?php esc_html_e('管理員尚未上傳任何頭像，請聯絡管理員補充後再選擇。', 'moonlight-user-center'); ?>
                </div>
            <?php else : ?>
                <div class="mluc-avatar-picker" role="radiogroup" aria-label="<?php esc_attr_e('选择头像', 'moonlight-user-center'); ?>">
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

    <section class="mluc-card" aria-label="<?php esc_attr_e('基础资料', 'moonlight-user-center'); ?>">
        <h3 class="mluc-card-title"><?php esc_html_e('基礎資料', 'moonlight-user-center'); ?></h3>
        <p class="mluc-card-subtitle">
            <?php esc_html_e('這些資料會顯示在你的個人主頁與文章署名中。', 'moonlight-user-center'); ?>
        </p>

        <form class="mluc-form" data-action="update_profile" novalidate>
            <p class="mluc-msg" role="alert"></p>
            <div class="mluc-field">
                <label for="mluc_email"><?php esc_html_e('電郵', 'moonlight-user-center'); ?></label>
                <input type="email" id="mluc_email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" disabled>
                <p class="description"><?php esc_html_e('如需修改電郵，請聯絡管理員。', 'moonlight-user-center'); ?></p>
            </div>
            <div class="mluc-field">
                <label for="mluc_display_name"><?php esc_html_e('顯示名稱', 'moonlight-user-center'); ?></label>
                <input type="text" id="mluc_display_name" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" required>
            </div>
            <div class="mluc-field">
                <label for="mluc_nickname"><?php esc_html_e('昵稱', 'moonlight-user-center'); ?></label>
                <input type="text" id="mluc_nickname" name="nickname" value="<?php echo esc_attr($user->nickname); ?>">
            </div>
            <div class="mluc-field">
                <label for="mluc_phone"><?php esc_html_e('電話', 'moonlight-user-center'); ?></label>
                <input type="tel" id="mluc_phone" name="phone" value="<?php echo esc_attr(get_user_meta($user->ID, 'phone', true)); ?>" placeholder="<?php esc_attr_e('例如：9123 4567', 'moonlight-user-center'); ?>">
            </div>
            <div class="mluc-field">
                <label for="mluc_url"><?php esc_html_e('個人網站', 'moonlight-user-center'); ?></label>
                <input type="url" id="mluc_url" name="user_url" value="<?php echo esc_attr($user->user_url); ?>">
            </div>
            <div class="mluc-field">
                <label for="mluc_desc"><?php esc_html_e('個人簡介', 'moonlight-user-center'); ?></label>
                <textarea id="mluc_desc" name="description" rows="4"><?php echo esc_textarea($user->description); ?></textarea>
            </div>
            <button type="submit" class="mluc-btn"><?php esc_html_e('保存資料', 'moonlight-user-center'); ?></button>
        </form>
    </section>

    <section class="mluc-card" aria-label="<?php esc_attr_e('修改密码', 'moonlight-user-center'); ?>">
        <h3 class="mluc-card-title"><?php esc_html_e('修改密碼', 'moonlight-user-center'); ?></h3>
        <p class="mluc-card-subtitle">
            <?php esc_html_e('為了帳戶安全，建議定期更換密碼。修改成功後需重新登入。', 'moonlight-user-center'); ?>
        </p>

        <form class="mluc-form mluc-password-form" data-action="change_password" novalidate>
            <p class="mluc-msg" role="alert"></p>
            <div class="mluc-field">
                <label for="mluc_old_pass"><?php esc_html_e('原密碼', 'moonlight-user-center'); ?></label>
                <input type="password" id="mluc_old_pass" name="old_password" autocomplete="current-password" required>
            </div>
            <div class="mluc-field">
                <label for="mluc_new_pass"><?php esc_html_e('新密碼', 'moonlight-user-center'); ?></label>
                <input type="password" id="mluc_new_pass" name="new_password" autocomplete="new-password" required>
                <p class="description"><?php esc_html_e('至少 6 位字符。', 'moonlight-user-center'); ?></p>
            </div>
            <button type="submit" class="mluc-btn"><?php esc_html_e('更新密碼', 'moonlight-user-center'); ?></button>
        </form>
    </section>
</div>
