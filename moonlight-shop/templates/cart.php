<?php
/**
 * 购物车页面。
 *
 * @var array $items
 * @var float $total
 * @var float $shipping
 */
if (!defined('ABSPATH')) {
    exit;
}
$checkout_url = mlshop_get_page_url('checkout');
$has_physical = MLSHOP_Shipping::has_physical($items);
$grand_total  = round($total + (float) $shipping, 2);
?>
<div class="mlshop-cart">
    <?php if (empty($items)) : ?>
        <div class="mlshop-empty-state">
            <p class="mlshop-message"><?php esc_html_e('购物车是空白的。', 'moonlight-shop'); ?></p>
            <a class="mlshop-btn" href="<?php echo esc_url(mlshop_get_store_url()); ?>"><?php esc_html_e('去挑选商品', 'moonlight-shop'); ?></a>
        </div>
    <?php else : ?>
        <div class="mlshop-table-scroll">
        <table class="mlshop-cart-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('商品', 'moonlight-shop'); ?></th>
                    <th><?php esc_html_e('单价', 'moonlight-shop'); ?></th>
                    <th><?php esc_html_e('数量', 'moonlight-shop'); ?></th>
                    <th><?php esc_html_e('小计', 'moonlight-shop'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr data-product-id="<?php echo esc_attr($item['key']); ?>">
                        <td class="mlshop-cart-name"><?php echo esc_html($item['title']); ?></td>
                        <td><?php echo esc_html(mlshop_format_price($item['price'])); ?></td>
                        <td>
                            <div class="mlshop-qty-stepper mlshop-qty-stepper-sm">
                                <button type="button" class="mlshop-qty-btn mlshop-qty-minus" aria-label="<?php echo esc_attr__('减少数量', 'moonlight-shop'); ?>">−</button>
                                <input type="number" min="1" class="mlshop-qty" value="<?php echo esc_attr($item['qty']); ?>" data-product-id="<?php echo esc_attr($item['key']); ?>">
                                <button type="button" class="mlshop-qty-btn mlshop-qty-plus" aria-label="<?php echo esc_attr__('增加数量', 'moonlight-shop'); ?>">+</button>
                            </div>
                        </td>
                        <td><?php echo esc_html(mlshop_format_price($item['subtotal'])); ?></td>
                        <td><button class="mlshop-remove" data-product-id="<?php echo esc_attr($item['key']); ?>">×</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="mlshop-cart-total-label"><?php esc_html_e('商品小计', 'moonlight-shop'); ?></td>
                    <td colspan="2" class="mlshop-cart-total"><?php echo esc_html(mlshop_format_price($total)); ?></td>
                </tr>
                <?php if ($has_physical) : ?>
                    <tr class="mlshop-cart-shipping-row">
                        <td colspan="3" class="mlshop-cart-total-label">
                            <?php esc_html_e('運費', 'moonlight-shop'); ?>
                            <span class="mlshop-cart-shipping-note"><?php echo esc_html(MLSHOP_Shipping::note($total)); ?></span>
                        </td>
                        <td colspan="2" class="mlshop-cart-total"><?php echo esc_html(mlshop_format_price($shipping)); ?></td>
                    </tr>
                    <tr class="mlshop-cart-grand-row">
                        <td colspan="3" class="mlshop-cart-total-label"><?php esc_html_e('合計（含運費）', 'moonlight-shop'); ?></td>
                        <td colspan="2" class="mlshop-cart-total"><?php echo esc_html(mlshop_format_price($grand_total)); ?></td>
                    </tr>
                <?php endif; ?>
            </tfoot>
        </table>
        </div>
        <a class="mlshop-btn mlshop-checkout-btn" href="<?php echo esc_url($checkout_url); ?>"><?php esc_html_e('去结算', 'moonlight-shop'); ?></a>
    <?php endif; ?>
</div>
