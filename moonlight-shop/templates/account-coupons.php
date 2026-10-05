<?php
/**
 * 我的优惠券 Tab：列出当前用户历史订单中使用过的优惠码。
 *
 * @var array $used  code => 使用次数
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mlshop-account-coupons">
    <?php if (!empty($used)) : ?>
        <ul class="mlshop-coupon-list">
            <?php foreach ($used as $code => $count) : ?>
                <li class="mlshop-coupon-list-item">
                    <span class="mlshop-coupon-list-code"><?php echo esc_html($code); ?></span>
                    /* translators: %d: 数量 */
                    <span class="mlshop-coupon-list-count"><?php echo sprintf(esc_html__('已使用 %d 次', 'moonlight-shop'), (int) $count); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="mlshop-coupon-empty"><?php esc_html_e('您还没有使用过优惠码。', 'moonlight-shop'); ?></p>
    <?php endif; ?>
</div>
