<?php
/**
 * 付费按钮 / 行动区：根据登录态、购买权限、支付类型渲染不同 CTA。
 *
 * 变量由 MLSHOP_Pay_Access::get_pay_button_data() 提供。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}

$is_logged_in   = !empty($is_logged_in);
$can_purchase   = !empty($can_purchase);
$pay_type       = isset($pay_type) ? $pay_type : 'money';
$credit_name     = isset($credit_name) ? $credit_name : '积分';
$money_price     = isset($money_price) ? (float) $money_price : 0;
$original_price = isset($original_price) ? (float) $original_price : 0;
$credit_price    = isset($credit_price) ? (float) $credit_price : 0;
$credit_balance  = isset($credit_balance) ? (float) $credit_balance : 0;
$gateways        = isset($gateways) ? $gateways : array();
$default_gateway = isset($default_gateway) ? $default_gateway : 'cod';
$login_url       = isset($login_url) ? $login_url : wp_login_url();
$auth_message    = isset($auth_message) ? $auth_message : '';
$post_id         = isset($post_id) ? (int) $post_id : 0;
?>
<div class="mlshop-paywall-action">
    <?php if (!$is_logged_in) : ?>
        <p class="mlshop-paywall-login">
            <?php
            printf(
                /* translators: %s: 值 */
                esc_html__('请先 %s 后购买此内容。', 'at8-moonlight-shop'),
                '<a href="' . esc_url($login_url) . '">' . esc_html__('登录', 'at8-moonlight-shop') . '</a>'
            );
            ?>
        </p>

    <?php elseif (!$can_purchase) : ?>
        <p class="mlshop-paywall-gated"><?php echo esc_html($auth_message); ?></p>

    <?php else : ?>
        <div class="mlshop-paywall-price">
            <?php if ('credit' === $pay_type) : ?>
                <span class="mlshop-paywall-cost"><?php echo esc_html($credit_price); ?> <?php echo esc_html($credit_name); ?></span>
                <span class="mlshop-paywall-balance"><?php /* translators: 1: 当前余额, 2: 积分名称 */ printf(esc_html__('您的余额：%1$s %2$s', 'at8-moonlight-shop'), esc_html($credit_balance), esc_html($credit_name)); ?></span>
            <?php else : ?>
                <span class="mlshop-paywall-cost"><?php echo esc_html(mlshop_format_price($money_price)); ?></span>
                <?php if ($original_price > $money_price) : ?>
                    <span class="mlshop-paywall-origin"><?php echo esc_html(mlshop_format_price($original_price)); ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ('credit' === $pay_type) : ?>
            <button type="button" class="mlshop-btn mlshop-pay-unlock" data-post-id="<?php echo esc_attr($post_id); ?>" data-type="credit">
                <?php /* translators: 1: 积分价格, 2: 积分名称 */ printf(esc_html__('使用 %1$s %2$s 解锁', 'at8-moonlight-shop'), esc_html($credit_price), esc_html($credit_name)); ?>
            </button>
        <?php else : ?>
            <form class="mlshop-paywall-form" onsubmit="return false;">
                <?php foreach ($gateways as $g) : ?>
                    <label class="mlshop-gateway">
                        <input type="radio" name="mlshop_paywall_gateway" value="<?php echo esc_attr($g->get_id()); ?>" <?php checked($g->get_id(), $default_gateway); ?>>
                        <span class="mlshop-gateway-title"><?php echo esc_html($g->get_label()); ?></span>
                        <?php if ($g->get_description()) : ?>
                            <span class="mlshop-gateway-desc"><?php echo esc_html($g->get_description()); ?></span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <button type="button" class="mlshop-btn mlshop-pay-unlock" data-post-id="<?php echo esc_attr($post_id); ?>" data-type="money">
                    <?php esc_html_e('立即购买', 'at8-moonlight-shop'); ?>
                </button>
            </form>
        <?php endif; ?>

        <p class="mlshop-paywall-msg" aria-live="polite" style="display:none;"></p>
    <?php endif; ?>
</div>

