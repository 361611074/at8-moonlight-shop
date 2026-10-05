<?php
/**
 * 账户概览 Tab：月光风格 hero + 快捷动作 + 统计卡。
 * 按 .mluc-card 卡片化，与后台设置页风格保持一致。
 *
 * @var WP_User $user
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}

// 月光主题：紫蓝渐变 + 暖色调强调
$_level     = class_exists('MLUC_Membership') ? MLUC_Membership::get_user_level($user->ID) : '';
$_level_lbl = class_exists('MLUC_Membership')
    ? ('' !== $_level ? MLUC_Membership::get_level_label($_level) : mluc_ui_label('no_member', __('Non-member', 'moonlight-shop')))
    : mluc_ui_label('no_member', __('Non-member', 'moonlight-shop'));
$_expires   = class_exists('MLUC_Membership') ? MLUC_Membership::get_user_expires($user->ID) : 0;
$_expired   = class_exists('MLUC_Membership') ? MLUC_Membership::is_user_expired($user->ID) : false;

$_display_name = $user->display_name ? $user->display_name : $user->user_login;

// 时段问候（后台「界面文案」可自定义）
$_hour  = (int) date('G');
if ($_hour < 5) {
    $_greet = mluc_ui_label('greet_dawn', __('Good early morning', 'moonlight-shop'));
} elseif ($_hour < 11) {
    $_greet = mluc_ui_label('greet_morning', __('Good morning', 'moonlight-shop'));
} elseif ($_hour < 13) {
    $_greet = mluc_ui_label('greet_noon', __('Good midday', 'moonlight-shop'));
} elseif ($_hour < 18) {
    $_greet = mluc_ui_label('greet_afternoon', __('Good afternoon', 'moonlight-shop'));
} else {
    $_greet = mluc_ui_label('greet_evening', __('Good evening', 'moonlight-shop'));
}

$_avatar       = get_avatar_url($user->ID, array('size' => 160));
$_phone        = get_user_meta($user->ID, 'phone', true);
$_count_posts  = (int) count_user_posts($user->ID);
$_count_cmts   = (int) mluc_count_user_comments($user->ID);
$_reg_date     = date_i18n(get_option('date_format'), strtotime($user->user_registered));
?>
<div class="mluc-overview">

    <section class="mluc-overview-hero" aria-label="<?php esc_attr_e('账户概览', 'moonlight-shop'); ?>">
        <div class="mluc-overview-hero__bg" aria-hidden="true"></div>
        <div class="mluc-overview-hero__inner">
            <div class="mluc-overview-hero__avatar">
                <img class="mluc-avatar" src="<?php echo esc_url($_avatar); ?>" alt="<?php echo esc_attr($_display_name); ?>">
            </div>
            <div class="mluc-overview-hero__meta">
                <p class="mluc-overview-hero__greet"><?php echo esc_html($_greet); ?>，</p>
                <h3 class="mluc-overview-hero__name"><?php echo esc_html($_display_name); ?></h3>
                <p class="mluc-overview-hero__email"><?php echo esc_html($user->user_email); ?></p>
                <?php if ($_phone) : ?>
                    <p class="mluc-overview-hero__email"><?php echo esc_html(mluc_ui_label('ov_phone_prefix', __('Phone: ', 'moonlight-shop'))) . esc_html($_phone); ?></p>
                <?php endif; ?>
                <div class="mluc-overview-hero__chips">
                    <span class="mluc-level-badge mluc-level-<?php echo esc_attr($_level); ?>">
                        <?php echo esc_html($_level_lbl); ?>
                    </span>
                    <?php if ('' !== $_level) : ?>
                        <span class="mluc-overview-hero__expire">
                            <?php if ($_expires) : ?>
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %s: 值 */
                                    mluc_ui_label('ov_expire', __('Expires %s', 'moonlight-shop')),
                                    $_expired
                                        ? esc_html(mluc_ui_label('ov_expired', __('Expired', 'moonlight-shop')))
                                        : date_i18n(get_option('date_format', 'Y-m-d'), $_expires)
                                ));
                                ?>
                            <?php else : ?>
                                <?php echo esc_html(mluc_ui_label('permanent', __('Permanent', 'moonlight-shop'))); ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <span class="mluc-overview-hero__role">
                        <?php echo esc_html(implode('、', array_map('translate_user_role', $user->roles))); ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <?php
    // 允许其他插件向「快捷动作」插入附加卡片（如订单/优惠券/下载等）。
    // 用法：do_action('mluc_account_quick_actions', $user); 输出 <a class="mluc-quick-card">...</a>
    ?>
    <section class="mluc-card mluc-overview-card" aria-label="<?php echo esc_attr(mluc_ui_label('ov_quick_title', __('Quick Actions', 'moonlight-shop'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('ov_quick_title', __('Quick Actions', 'moonlight-shop'))); ?></h3>
        <div class="mluc-quick-actions">
            <a class="mluc-quick-card mluc-quick-card--primary" href="<?php echo esc_url(add_query_arg('tab', 'profile', mluc_get_account_url())); ?>">
                <span class="mluc-quick-card__icon dashicons dashicons-edit"></span>
                <span class="mluc-quick-card__body">
                    <span class="mluc-quick-card__title"><?php echo esc_html(mluc_ui_label('ov_qa_profile_t', __('Edit Profile', 'moonlight-shop'))); ?></span>
                    <span class="mluc-quick-card__sub"><?php echo esc_html(mluc_ui_label('ov_qa_profile_s', __('Nickname / Phone / Bio', 'moonlight-shop'))); ?></span>
                </span>
            </a>
            <a class="mluc-quick-card" href="<?php echo esc_url(add_query_arg('tab', 'membership', mluc_get_account_url())); ?>">
                <span class="mluc-quick-card__icon dashicons dashicons-star-filled"></span>
                <span class="mluc-quick-card__body">
                    <span class="mluc-quick-card__title"><?php echo esc_html(mluc_ui_label('ov_qa_member_t', __('Membership', 'moonlight-shop'))); ?></span>
                    <span class="mluc-quick-card__sub"><?php echo esc_html(mluc_ui_label('ov_qa_member_s', __('Benefits / Upgrade', 'moonlight-shop'))); ?></span>
                </span>
            </a>
            <a class="mluc-quick-card" href="<?php echo esc_url(mluc_get_login_url(mluc_get_account_url())); ?>">
                <span class="mluc-quick-card__icon dashicons dashicons-lock"></span>
                <span class="mluc-quick-card__body">
                    <span class="mluc-quick-card__title"><?php echo esc_html(mluc_ui_label('ov_qa_security_t', __('Account Security', 'moonlight-shop'))); ?></span>
                    <span class="mluc-quick-card__sub"><?php echo esc_html(mluc_ui_label('ov_qa_security_s', __('Password / Login', 'moonlight-shop'))); ?></span>
                </span>
            </a>
            <?php do_action('mluc_account_quick_actions', $user); ?>
        </div>
    </section>

    <section class="mluc-card mluc-overview-card" aria-label="<?php echo esc_attr(mluc_ui_label('ov_stats_title', __('Account Stats', 'moonlight-shop'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('ov_stats_title', __('Account Stats', 'moonlight-shop'))); ?></h3>
        <div class="mluc-stats-grid">
            <div class="mluc-stat-card">
                <div class="mluc-stat-card__icon dashicons dashicons-calendar-alt"></div>
                <div class="mluc-stat-card__body">
                    <span class="mluc-stat-num"><?php echo esc_html($_reg_date); ?></span>
                    <span class="mluc-stat-label"><?php echo esc_html(mluc_ui_label('ov_stat_reg', __('Registered on', 'moonlight-shop'))); ?></span>
                </div>
            </div>
            <div class="mluc-stat-card">
                <div class="mluc-stat-card__icon dashicons dashicons-admin-post"></div>
                <div class="mluc-stat-card__body">
                    <span class="mluc-stat-num"><?php echo (int) $_count_posts; ?></span>
                    <span class="mluc-stat-label"><?php echo esc_html(mluc_ui_label('ov_stat_posts', __('Posts', 'moonlight-shop'))); ?></span>
                </div>
            </div>
            <div class="mluc-stat-card">
                <div class="mluc-stat-card__icon dashicons dashicons-admin-comments"></div>
                <div class="mluc-stat-card__body">
                    <span class="mluc-stat-num"><?php echo (int) $_count_cmts; ?></span>
                    <span class="mluc-stat-label"><?php echo esc_html(mluc_ui_label('ov_stat_comments', __('Comments', 'moonlight-shop'))); ?></span>
                </div>
            </div>
        </div>
    </section>

    <?php do_action('mluc_account_overview_after', $user); ?>
</div>
