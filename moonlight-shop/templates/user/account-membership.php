<?php
/**
 * 會員等級 Tab：当前等级 + 等级对照（升级块由 class-account.php 统一包入 .mluc-card 渲染）。
 *
 * @var int    $user_id
 * @var string $level
 * @var int    $expires
 * @var bool   $expired
 * @var array  $levels
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mluc-membership">

    <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('mb_current_title', __('Current Membership', 'moonlight-user-center'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('mb_current_title', __('Current Membership', 'moonlight-user-center'))); ?></h3>
        <p class="mluc-membership-level">
            <span class="mluc-level-badge mluc-level-<?php echo esc_attr($level); ?>">
                <?php
                echo esc_html('' !== $level
                    ? MLUC_Membership::get_level_label($level)
                    : mluc_ui_label('no_member', __('Non-member', 'moonlight-user-center')));
                ?>
            </span>
        </p>
        <?php if ('' === $level || 'free' === $level) : ?>
            <p class="mluc-membership-tip">
                <?php if ('free' === $level) : ?>
                    <?php echo esc_html(mluc_ui_label('mb_free_tip', __('You are currently a Free member. Upgrade to Monthly or Premium to unlock more materials and demo videos.', 'moonlight-user-center'))); ?>
                <?php else : ?>
                    <?php echo esc_html(mluc_ui_label('mb_none_tip', __('You do not have a membership yet. Upgrade to unlock more materials and demo videos.', 'moonlight-user-center'))); ?>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p class="mluc-membership-tip">
                <?php if ($expires) : ?>
                    <?php if ($expired) : ?>
                        <?php echo esc_html(mluc_ui_label('mb_expired_tip', __('Your membership has expired. Please renew to continue.', 'moonlight-user-center'))); ?>
                    <?php else : ?>
                        <?php
                        $expire_str = date_i18n(get_option('date_format', 'Y-m-d'), $expires);
                        echo esc_html(
                            sprintf(
                                mluc_ui_label('mb_expire_date', __('Expires: %s', 'moonlight-user-center')),
                                $expire_str
                            )
                        );
                        ?>
                    <?php endif; ?>
                <?php else : ?>
                    <?php echo esc_html(mluc_ui_label('mb_permanent', __('Lifetime member', 'moonlight-user-center'))); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('mb_table_title', __('Membership Levels', 'moonlight-user-center'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('mb_table_title', __('Membership Levels', 'moonlight-user-center'))); ?></h3>
        <p class="mluc-card-subtitle">
            <?php echo esc_html(mluc_ui_label('mb_table_sub', __('Compare the benefits and your current status of each level.', 'moonlight-user-center'))); ?>
        </p>
        <div class="mluc-table-scroll">
            <table class="mluc-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html(mluc_ui_label('th_level', __('Level', 'moonlight-user-center'))); ?></th>
                        <th><?php echo esc_html(mluc_ui_label('mb_th_desc', __('Description', 'moonlight-user-center'))); ?></th>
                        <th><?php echo esc_html(mluc_ui_label('th_status', __('Status', 'moonlight-user-center'))); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $key => $lv) :
                        $description = isset($lv['description']) && $lv['description'] !== ''
                            ? $lv['description']
                            : __('Browse basic content and purchase paid materials.', 'moonlight-user-center');
                        ?>
                        <tr class="<?php echo $key === $level ? 'is-current' : ''; ?>">
                            <td>
                                <span class="mluc-level-badge mluc-level-<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html(MLUC_Membership::get_level_label($key)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($description); ?></td>
                            <td>
                                <?php if ($key === $level) : ?>
                                    <span class="mluc-level-current"><?php echo esc_html(mluc_ui_label('mb_st_current', __('Current', 'moonlight-user-center'))); ?></span>
                                <?php elseif (MLUC_Membership::get_level_sort_order($key) < MLUC_Membership::get_level_sort_order($level)) : ?>
                                    <span class="mluc-level-included"><?php echo esc_html(mluc_ui_label('mb_st_included', __('Included', 'moonlight-user-center'))); ?></span>
                                <?php else : ?>
                                    <span class="mluc-level-locked"><?php echo esc_html(mluc_ui_label('mb_st_locked', __('Locked', 'moonlight-user-center'))); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>
