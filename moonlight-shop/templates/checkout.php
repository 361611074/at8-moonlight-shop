<?php
/**
 * 结算页面。
 *
 * @var array $items
 * @var float $subtotal
 * @var float $shipping
 * @var float $total
 * @var bool  $has_physical
 * @var array $gateways
 */
if (!defined('ABSPATH')) {
    exit;
}
$default_gateway = mlshop_get_option('default_gateway', 'cod');
?>
<div class="mlshop-checkout">
    <h2 class="mlshop-title"><?php esc_html_e('订单确认', 'moonlight-shop'); ?></h2>
    <table class="mlshop-checkout-items">
        <tbody>
            <?php foreach ($items as $item) : ?>
                <tr>
                    <td><?php echo esc_html($item['title']); ?> × <?php echo (int) $item['qty']; ?></td>
                    <td class="mlshop-align-right"><?php echo esc_html(mlshop_format_price($item['subtotal'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="mlshop-checkout-subtotal">
                <td><?php esc_html_e('商品小计', 'moonlight-shop'); ?></td>
                <td class="mlshop-align-right"><?php echo esc_html(mlshop_format_price($subtotal)); ?></td>
            </tr>
            <tr class="mlshop-checkout-discount" style="display:none">
                <td><?php esc_html_e('优惠减免', 'moonlight-shop'); ?> <span class="mlshop-coupon-tag"></span></td>
                <td class="mlshop-align-right">−<span class="mlshop-coupon-discount-text"></span></td>
            </tr>
            <?php if ($has_physical) : ?>
                <tr class="mlshop-checkout-shipping">
                    <td>
                        <?php esc_html_e('運費', 'moonlight-shop'); ?>
                        <span class="mlshop-shipping-note"><?php echo esc_html(MLSHOP_Shipping::note($subtotal)); ?></span>
                    </td>
                    <td class="mlshop-align-right mlshop-checkout-shipping-text"><?php echo esc_html(mlshop_format_price($shipping)); ?></td>
                </tr>
            <?php endif; ?>
            <tr class="mlshop-checkout-total">
                <td><?php esc_html_e('应付总额', 'moonlight-shop'); ?></td>
                <td class="mlshop-align-right mlshop-checkout-total-text"><?php echo esc_html(mlshop_format_price($total)); ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($has_physical) : ?>
        <div class="mlshop-shipping-box">
            <h3><?php esc_html_e('收件資料（實物商品寄送）', 'moonlight-shop'); ?></h3>
            <p class="mlshop-msg mlshop-shipping-msg" role="alert"></p>
            <div class="mlshop-field">
                <label for="mlshop_ship_name"><?php esc_html_e('收件人', 'moonlight-shop'); ?> <span class="required">*</span></label>
                <input type="text" id="mlshop_ship_name" name="shipping_name" class="mlshop-ship-name" required>
            </div>
            <div class="mlshop-field">
                <label for="mlshop_ship_phone"><?php esc_html_e('聯絡電話', 'moonlight-shop'); ?> <span class="required">*</span></label>
                <input type="tel" id="mlshop_ship_phone" name="shipping_phone" class="mlshop-ship-phone" required>
            </div>
            <div class="mlshop-field">
                <label for="mlshop_ship_address"><?php esc_html_e('收件地址', 'moonlight-shop'); ?> <span class="required">*</span></label>
                <textarea id="mlshop_ship_address" name="shipping_address" class="mlshop-ship-address" rows="3" required></textarea>
            </div>
            <div class="mlshop-field">
                <label for="mlshop_ship_note"><?php esc_html_e('備註（選填）', 'moonlight-shop'); ?></label>
                <input type="text" id="mlshop_ship_note" name="shipping_note" class="mlshop-ship-note">
            </div>
        </div>
    <?php endif; ?>

    <div class="mlshop-coupon-box">
        <label for="mlshop-coupon-input"><?php esc_html_e('优惠码', 'moonlight-shop'); ?></label>
        <div class="mlshop-coupon-row">
            <input type="text" id="mlshop-coupon-input" class="mlshop-coupon-input" placeholder="<?php esc_attr_e('如有优惠码请在此输入', 'moonlight-shop'); ?>" autocomplete="off">
            <button type="button" class="mlshop-btn mlshop-apply-coupon"><?php esc_html_e('应用', 'moonlight-shop'); ?></button>
        </div>
        <p class="mlshop-coupon-msg" role="alert"></p>
    </div>

    <h3><?php esc_html_e('选择支付方式', 'moonlight-shop'); ?></h3>
    <form class="mlshop-checkout-form" data-action="place_order">
        <p class="mlshop-msg" role="alert"></p>
        <?php foreach ($gateways as $g) : ?>
            <label class="mlshop-gateway">
                <input type="radio" name="gateway" value="<?php echo esc_attr($g->get_id()); ?>" <?php checked($g->get_id(), $default_gateway); ?>>
                <span class="mlshop-gateway-title"><?php echo esc_html($g->get_title()); ?></span>
                <span class="mlshop-gateway-desc"><?php echo esc_html($g->get_description()); ?></span>
            </label>
        <?php endforeach; ?>
        <input type="hidden" name="coupon_code" class="mlshop-coupon-code" value="">
        <button type="submit" class="mlshop-btn mlshop-place-order"><?php esc_html_e('提交订单', 'moonlight-shop'); ?></button>
    </form>
</div>
