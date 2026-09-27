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

    <section class="mluc-card" aria-label="<?php esc_attr_e('当前等级', 'moonlight-user-center'); ?>">
        <h3 class="mluc-card-title"><?php esc_html_e('當前會員等級', 'moonlight-user-center'); ?></h3>
        <p class="mluc-membership-level">
            <span class="mluc-level-badge mluc-level-<?php echo esc_attr($level); ?>">
                <?php
                echo esc_html('' !== $level
                    ? MLUC_Membership::get_level_label($level)
                    : __('未開通會員', 'moonlight-user-center'));
                ?>
            </span>
        </p>
        <?php if ('' === $level || 'free' === $level) : ?>
            <p class="mluc-membership-tip">
                <?php if ('free' === $level) : ?>
                    <?php esc_html_e('您目前為 Free 會員，升級至月費或高級可解鎖更多教材與示範影片。', 'moonlight-user-center'); ?>
                <?php else : ?>
                    <?php esc_html_e('您尚未開通會員，升級即可解鎖更多教材與示範影片。', 'moonlight-user-center'); ?>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p class="mluc-membership-tip">
                <?php if ($expires) : ?>
                    <?php if ($expired) : ?>
                        <?php esc_html_e('您的會員資格已過期，請續期以繼續使用。', 'moonlight-user-center'); ?>
                    <?php else : ?>
                        <?php
                        $expire_str = date_i18n(get_option('date_format', 'Y-m-d'), $expires);
                        echo esc_html(
                            sprintf(
                                /* translators: %s: 到期日 */
                                __('到期日：%s', 'moonlight-user-center'),
                                $expire_str
                            )
                        );
                        ?>
                    <?php endif; ?>
                <?php else : ?>
                    <?php esc_html_e('永久會員', 'moonlight-user-center'); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="mluc-card" aria-label="<?php esc_attr_e('等级对照', 'moonlight-user-center'); ?>">
        <h3 class="mluc-card-title"><?php esc_html_e('會員等級對照', 'moonlight-user-center'); ?></h3>
        <p class="mluc-card-subtitle">
            <?php esc_html_e('查看每個等級的權益說明與當前狀態。', 'moonlight-user-center'); ?>
        </p>
        <div class="mluc-table-scroll">
            <table class="mluc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('等級', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('描述', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('狀態', 'moonlight-user-center'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $key => $lv) :
                        $description = isset($lv['description']) && $lv['description'] !== ''
                            ? $lv['description']
                            : __('可瀏覽基礎內容，購買付費教材。', 'moonlight-user-center');
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
                                    <span class="mluc-level-current"><?php esc_html_e('當前', 'moonlight-user-center'); ?></span>
                                <?php elseif (MLUC_Membership::get_level_sort_order($key) < MLUC_Membership::get_level_sort_order($level)) : ?>
                                    <span class="mluc-level-included"><?php esc_html_e('已包含', 'moonlight-user-center'); ?></span>
                                <?php else : ?>
                                    <span class="mluc-level-locked"><?php esc_html_e('未解鎖', 'moonlight-user-center'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>
