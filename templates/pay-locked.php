<?php
/**
 * 付费墙（锁定态）包裹层：标题、说明、备注，并嵌入付费按钮。
 *
 * 变量由 MLSHOP_Pay_Access::get_pay_button_data() 提供。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}

$mode_label = isset($mode_label) ? $mode_label : __('付费内容', 'at8-moonlight-shop');
$title      = isset($title) ? $title : __('付费内容', 'at8-moonlight-shop');
$desc       = isset($desc) ? $desc : '';
?>
<div class="mlshop-paywall" role="region" aria-label="<?php echo esc_attr__('付费内容', 'at8-moonlight-shop'); ?>">
    <div class="mlshop-paywall-head">
        <span class="mlshop-paywall-badge"><?php echo esc_html($mode_label); ?></span>
        <h3 class="mlshop-paywall-title"><?php echo esc_html($title); ?></h3>
    </div>
    <?php if ($desc) : ?>
        <p class="mlshop-paywall-desc"><?php echo esc_html($desc); ?></p>
    <?php endif; ?>

    <?php
    $notes = array_filter(array(
        isset($sales_text) ? $sales_text : '',
        isset($expire_text) ? $expire_text : '',
        isset($aff_text) ? $aff_text : '',
        isset($coupon_text) ? $coupon_text : '',
        isset($free_dl_text) ? $free_dl_text : '',
    ));
    if ($notes) : ?>
        <ul class="mlshop-paywall-notes">
            <?php foreach ($notes as $n) : ?>
                <li><?php echo esc_html($n); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php
    mlshop_get_template('pay-button', array(
        'post_id'         => $post_id,
        'pay_type'        => $pay_type,
        'credit_name'     => $credit_name,
        'is_logged_in'    => $is_logged_in,
        'can_purchase'    => $can_purchase,
        'auth_message'    => $auth_message,
        'money_price'     => $money_price,
        'original_price'  => $original_price,
        'credit_price'    => $credit_price,
        'credit_balance'  => $credit_balance,
        'gateways'        => $gateways,
        'default_gateway' => $default_gateway,
        'login_url'       => $login_url,
    ));
    ?>
</div>
