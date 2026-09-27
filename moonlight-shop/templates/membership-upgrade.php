<?php
/**
 * 会员等级升级卡片。
 *
 * @var string $current
 * @var array  $levels
 * @var array  $gateways
 * @var string $symbol
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mlshop-membership-upgrade">
    <div class="mlshop-membership-grid">
        <?php foreach ($levels as $key => $lv) : ?>
            <?php
            $price    = isset($lv['price']) ? (float) $lv['price'] : 0;
            $validity = isset($lv['validity']) ? (int) $lv['validity'] : 0;
            $color    = isset($lv['color']) ? $lv['color'] : '#2f6fed';
            $is_current = ($key === $current);
            if ('free' === $key) {
                continue;
            }
            ?>
            <div class="mlshop-membership-card" style="border-top-color:<?php echo esc_attr($color); ?>">
                <div class="mlshop-membership-name" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($lv['label']); ?></div>
                <div class="mlshop-membership-price"><?php echo esc_html($symbol . number_format($price, 2)); ?></div>
                <div class="mlshop-membership-validity">
                    <?php echo $validity > 0 ? sprintf(esc_html__('有效期 %d 天', 'moonlight-shop'), $validity) : esc_html__('永久有效', 'moonlight-shop'); ?>
                </div>
                <div class="mlshop-membership-actions">
                    <?php if ($is_current) : ?>
                        <span class="mlshop-membership-current"><?php esc_html_e('当前等级', 'moonlight-shop'); ?></span>
                    <?php else : ?>
                        <div class="mlshop-membership-gateways">
                            <?php foreach ($gateways as $g) : ?>
                                <label class="mlshop-membership-gateway">
                                    <input type="radio" name="mlshop_mb_gateway_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($g->get_id()); ?>" <?php checked($g->get_id(), 'cod'); ?>>
                                    <span><?php echo esc_html($g->get_title()); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button class="mlshop-btn mlshop-buy-membership" data-level="<?php echo esc_attr($key); ?>"><?php esc_html_e('升级', 'moonlight-shop'); ?></button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="mlshop-membership-msg" role="alert"></p>
</div>
