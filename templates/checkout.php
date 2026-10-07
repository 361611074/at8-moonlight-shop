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
 * @var array $addresses      当前用户地址簿（登录且有地址时非空）
 * @var bool  $pickup_enabled 到店自提开关
 */
if (!defined('ABSPATH')) {
    exit;
}
$default_gateway = mlshop_get_option('default_gateway', 'cod');
$pickup_enabled  = !empty($pickup_enabled);
$addresses       = isset($addresses) && is_array($addresses) ? $addresses : array();
$is_guest        = !empty($is_guest);
?>
<div class="mlshop-checkout">
    <h2 class="mlshop-title"><?php esc_html_e('订单确认', 'at8-moonlight-shop'); ?></h2>

    <?php if ($is_guest) : ?>
        <div class="mlshop-guest-box">
            <p class="mlshop-guest-hint">
                <?php esc_html_e('您正在以访客身份购买，无需注册。请填写电子邮箱：订单确认、虚拟商品下载链接与卡密将发送到该邮箱。', 'at8-moonlight-shop'); ?>
            </p>
            <div class="mlshop-field">
                <label for="mlshop_guest_email"><?php esc_html_e('电子邮箱', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                <input type="email" id="mlshop_guest_email" name="guest_email" class="mlshop-guest-email" autocomplete="email" required>
            </div>
            <p class="description"><?php esc_html_e('付款完成后，我们会在此页面推荐您注册成为网站用户，以便保存订单与再次下载。', 'at8-moonlight-shop'); ?></p>
        </div>
    <?php endif; ?>

    <table class="mlshop-checkout-items">
        <tbody>
            <?php foreach ($items as $item) : ?>
                <tr>
                    <td><?php echo esc_html($item['title']); ?> × <?php echo (int) $item['qty']; ?></td>
                    <td class="mlshop-align-right"><?php echo esc_html(mlshop_format_price($item['subtotal'])); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="mlshop-checkout-subtotal">
                <td><?php esc_html_e('商品小计', 'at8-moonlight-shop'); ?></td>
                <td class="mlshop-align-right"><?php echo esc_html(mlshop_format_price($subtotal)); ?></td>
            </tr>
            <tr class="mlshop-checkout-discount" style="display:none">
                <td><?php esc_html_e('优惠减免', 'at8-moonlight-shop'); ?> <span class="mlshop-coupon-tag"></span></td>
                <td class="mlshop-align-right">−<span class="mlshop-coupon-discount-text"></span></td>
            </tr>
            <?php if ($has_physical) : ?>
                <tr class="mlshop-checkout-shipping">
                    <td>
                        <?php esc_html_e('運費', 'at8-moonlight-shop'); ?>
                        <span class="mlshop-shipping-note"><?php echo esc_html(MLSHOP_Shipping::note($subtotal)); ?></span>
                    </td>
                    <td class="mlshop-align-right mlshop-checkout-shipping-text"><?php echo esc_html(mlshop_format_price($shipping)); ?></td>
                </tr>
            <?php endif; ?>
            <tr class="mlshop-checkout-total">
                <td><?php esc_html_e('应付总额', 'at8-moonlight-shop'); ?></td>
                <td class="mlshop-align-right mlshop-checkout-total-text"><?php echo esc_html(mlshop_format_price($total)); ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($has_physical) : ?>
        <div class="mlshop-shipping-box">
            <h3><?php esc_html_e('收件資料（實物商品寄送）', 'at8-moonlight-shop'); ?></h3>
            <p class="mlshop-msg mlshop-shipping-msg" role="alert"></p>

            <?php if ($pickup_enabled) : ?>
                <div class="mlshop-field mlshop-shipping-mode">
                    <label><input type="radio" name="mlshop_shipping_mode" class="mlshop-mode-ship" value="ship" checked> <?php esc_html_e('快遞配送', 'at8-moonlight-shop'); ?></label>
                    <label><input type="radio" name="mlshop_shipping_mode" class="mlshop-mode-pickup" value="pickup"> <?php esc_html_e('到店自提（免運費）', 'at8-moonlight-shop'); ?></label>
                </div>
            <?php endif; ?>

            <div class="mlshop-ship-fields">
                <?php if (!empty($addresses)) : ?>
                    <div class="mlshop-field">
                        <label for="mlshop_address_select"><?php esc_html_e('從地址簿選擇', 'at8-moonlight-shop'); ?></label>
                        <select id="mlshop_address_select" class="mlshop-address-select">
                            <option value=""><?php esc_html_e('— 手動填寫 —', 'at8-moonlight-shop'); ?></option>
                            <?php foreach ($addresses as $a) :
                                $region_text = '';
                                if (!empty($a['province_name']) && !empty($a['city_name'])) {
                                    // 保存时的名称快照（无需再查 provider）
                                    $region_text = $a['province_name'] . ' ' . $a['city_name'];
                                } elseif (class_exists('Moonlight_Region_Provider')) {
                                    $r = Moonlight_Region_Provider::resolve($a['city']);
                                    if ($r && isset($r['province_name'], $r['name'])) {
                                        $region_text = $r['province_name'] . ' ' . $r['name'];
                                    }
                                }
                                ?>
                                <option value="<?php echo esc_attr($a['id']); ?>"
                                    data-name="<?php echo esc_attr($a['name']); ?>"
                                    data-phone="<?php echo esc_attr($a['phone']); ?>"
                                    data-province="<?php echo esc_attr($a['province']); ?>"
                                    data-city="<?php echo esc_attr($a['city']); ?>"
                                    data-detail="<?php echo esc_attr($a['detail']); ?>">
                                    <?php echo esc_html(sprintf('%s %s %s%s', $a['name'], $a['phone'], $region_text ? $region_text . ' ' : '', $a['detail'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('選擇後自動填充下方表單，下單時以地址簿資料為準。', 'at8-moonlight-shop'); ?></p>
                    </div>
                <?php endif; ?>

                <div class="mlshop-field">
                    <label for="mlshop_ship_name"><?php esc_html_e('收件人', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                    <input type="text" id="mlshop_ship_name" name="shipping_name" class="mlshop-ship-name" required>
                </div>
                <div class="mlshop-field">
                    <label for="mlshop_ship_phone"><?php esc_html_e('聯絡電話', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                    <input type="tel" id="mlshop_ship_phone" name="shipping_phone" class="mlshop-ship-phone" required>
                </div>
                <div class="mlshop-field">
                    <label><?php esc_html_e('所在省市', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                    <?php mlshop_render_region_selects('shipping'); ?>
                </div>
                <div class="mlshop-field">
                    <label for="mlshop_ship_address"><?php esc_html_e('收件地址', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                    <textarea id="mlshop_ship_address" name="shipping_address" class="mlshop-ship-address" rows="3" required></textarea>
                </div>
                <div class="mlshop-field">
                    <label for="mlshop_ship_note"><?php esc_html_e('備註（選填）', 'at8-moonlight-shop'); ?></label>
                    <input type="text" id="mlshop_ship_note" name="shipping_note" class="mlshop-ship-note">
                </div>
            </div>

            <?php if ($pickup_enabled) : ?>
                <div class="mlshop-pickup-fields" style="display:none">
                    <div class="mlshop-field">
                        <label for="mlshop_pickup_name"><?php esc_html_e('提貨人姓名', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                        <input type="text" id="mlshop_pickup_name" name="pickup_name" class="mlshop-pickup-name">
                    </div>
                    <div class="mlshop-field">
                        <label for="mlshop_pickup_phone"><?php esc_html_e('提貨人手機號', 'at8-moonlight-shop'); ?> <span class="required">*</span></label>
                        <input type="tel" id="mlshop_pickup_phone" name="pickup_phone" class="mlshop-pickup-phone">
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$is_guest) : ?>
    <div class="mlshop-coupon-box">
        <label for="mlshop-coupon-input"><?php esc_html_e('优惠码', 'at8-moonlight-shop'); ?></label>
        <div class="mlshop-coupon-row">
            <input type="text" id="mlshop-coupon-input" class="mlshop-coupon-input" placeholder="<?php esc_attr_e('如有优惠码请在此输入', 'at8-moonlight-shop'); ?>" autocomplete="off">
            <button type="button" class="mlshop-btn mlshop-apply-coupon"><?php esc_html_e('应用', 'at8-moonlight-shop'); ?></button>
        </div>
        <p class="mlshop-coupon-msg" role="alert"></p>
    </div>
    <?php endif; ?>

    <h3><?php esc_html_e('选择支付方式', 'at8-moonlight-shop'); ?></h3>
    <form class="mlshop-checkout-form" data-action="place_order">
        <p class="mlshop-msg" role="alert"></p>
        <?php
        // 积分余额提示：仅在积分支付网关实际对前台公开时展示（白名单/开关均生效）。
        $mlshop_has_credit_gw = false;
        foreach ((array) $gateways as $mlshop_gw) {
            if ('credit' === $mlshop_gw->get_id()) {
                $mlshop_has_credit_gw = true;
                break;
            }
        }
        ?>
        <?php if ($mlshop_has_credit_gw && !$is_guest) : ?>
            <p class="mlshop-credit-balance-hint" data-credit-gateway="credit">
                <?php
                $credit_name  = mlshop_get_option('credit_name', __('积分', 'at8-moonlight-shop'));
                $credit_bal   = MLSHOP_Credit::get_balance();
                /* translators: 1: 积分名, 2: 当前余额, 3: 兑换比例 */
                echo esc_html(sprintf(__('当前%1$s余额：%2$s（%3$s %1$s = 1 货币单位）', 'at8-moonlight-shop'), $credit_name, $credit_bal, mlshop_get_credit_rate()));
                ?>
            </p>
        <?php endif; ?>
        <?php foreach ($gateways as $g) : ?>
            <label class="mlshop-gateway">
                <input type="radio" name="gateway" value="<?php echo esc_attr($g->get_id()); ?>" <?php checked($g->get_id(), $default_gateway); ?>>
                <span class="mlshop-gateway-title"><?php echo esc_html($g->get_label()); ?></span>
                <span class="mlshop-gateway-desc"><?php echo esc_html($g->get_label_desc()); ?></span>
            </label>
        <?php endforeach; ?>
        <input type="hidden" name="coupon_code" class="mlshop-coupon-code" value="">
        <button type="submit" class="mlshop-btn mlshop-place-order"><?php esc_html_e('提交订单', 'at8-moonlight-shop'); ?></button>
    </form>
</div>

