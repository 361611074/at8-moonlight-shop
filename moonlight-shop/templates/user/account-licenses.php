<?php
/**
 * 账户中心「我的 License」Tab 模板。
 *
 * @var array $licenses License 行（key/product/status/site/expires/date）
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}

$status_labels = array(
    'active'    => mluc_ui_label('lic_st_active', __('Active', 'moonlight-shop')),
    'inactive'  => mluc_ui_label('lic_st_inactive', __('Inactive', 'moonlight-shop')),
    'expired'   => mluc_ui_label('lic_st_expired', __('Expired', 'moonlight-shop')),
    'revoked'   => mluc_ui_label('lic_st_revoked', __('Revoked', 'moonlight-shop')),
    'suspended' => mluc_ui_label('lic_st_suspended', __('Suspended', 'moonlight-shop')),
);
?>
<div class="mluc-licenses">

    <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('lic_title', __('My Licenses', 'moonlight-shop'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('lic_title', __('My Licenses', 'moonlight-shop'))); ?></h3>
        <?php if (empty($licenses)) : ?>
            <p class="mluc-empty"><?php echo esc_html(mluc_ui_label('lic_empty', __('You have no licenses yet. Purchase a Pro plan to get one.', 'moonlight-shop'))); ?></p>
        <?php else : ?>
            <div class="mluc-table-scroll">
                <table class="mluc-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html(mluc_ui_label('lic_th_key', __('License', 'moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('lic_th_product', __('Product', 'moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('th_status', __('Status', 'moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('lic_th_site', __('Site', 'moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('lic_th_expires', __('Expires', 'moonlight-shop'))); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($licenses as $lic) :
                            $st = isset($status_labels[$lic['status']]) ? $status_labels[$lic['status']] : $lic['status'];
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($lic['key']); ?></code></td>
                                <td><?php echo esc_html($lic['product']); ?></td>
                                <td><span class="mluc-pill mluc-pill-<?php echo esc_attr('active' === $lic['status'] ? 'paid' : $lic['status']); ?>"><?php echo esc_html($st); ?></span></td>
                                <td><?php echo esc_html('' !== $lic['site'] ? $lic['site'] : '—'); ?></td>
                                <td><?php echo esc_html($lic['expires'] ? date_i18n(get_option('date_format', 'Y-m-d'), $lic['expires']) : mluc_ui_label('mb_permanent', __('Lifetime member', 'moonlight-shop'))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>
