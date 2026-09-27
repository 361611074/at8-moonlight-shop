<?php
/**
 * 单个订单详情。
 *
 * @var int    $order_id
 * @var string $back_url
 */
if (!defined('ABSPATH')) {
    exit;
}

$items     = get_post_meta($order_id, '_mlshop_items', true);
$total     = get_post_meta($order_id, '_mlshop_total', true);
$status    = MLSHOP_Order::get_status($order_id);
$delivery  = get_post_meta($order_id, '_mlshop_delivery', true);
$gateway_id = get_post_meta($order_id, '_mlshop_gateway', true);
$txn_id    = get_post_meta($order_id, '_mlshop_payment_id', true);
$created   = get_post_meta($order_id, '_mlshop_created', true);
$is_manual = in_array($gateway_id, array('cod', 'manual'), true);
$coupon_code = get_post_meta($order_id, '_mlshop_coupon_code', true);
$coupon_discount = (float) get_post_meta($order_id, '_mlshop_coupon_discount', true);
$subtotal = (float) get_post_meta($order_id, '_mlshop_subtotal', true);
if (!$subtotal && $coupon_discount > 0) {
    $subtotal = (float) $total + $coupon_discount;
}
$shipping     = (float) get_post_meta($order_id, '_mlshop_shipping', true);
$has_physical = (bool) get_post_meta($order_id, '_mlshop_has_physical', true);
$shipping_address = (array) get_post_meta($order_id, '_mlshop_shipping_address', true);

$back_url = isset($back_url) ? $back_url : mlshop_get_orders_url();
$created_display = $created ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $created) : '';

// 待付订单的剩余支付时间
$expire_min = (int) mlshop_get_option('order_expire_minutes', 0);
$remain_sec = 0;
if ($status === 'pending' && $expire_min > 0 && $created) {
    $ts = strtotime($created);
    if ($ts) {
        $remain_sec = max(0, $expire_min * 60 - (current_time('timestamp') - $ts));
    }
}
?>
<div class="mlshop-order">
    <a class="mlshop-btn mlshop-order-back" href="<?php echo esc_url($back_url); ?>"><?php esc_html_e('返回订单列表', 'moonlight-shop'); ?></a>

    <h2 class="mlshop-title">
        <?php echo esc_html(get_the_title($order_id)); ?>
        <span class="mlshop-order-status status-<?php echo esc_attr($status); ?>"><?php echo esc_html(mlshop_get_order_status_label($status)); ?></span>
    </h2>

    <ul class="mlshop-order-meta">
        <li><span><?php esc_html_e('下单时间', 'moonlight-shop'); ?></span><?php echo esc_html($created_display); ?></li>
        <li><span><?php esc_html_e('支付方式', 'moonlight-shop'); ?></span><?php echo esc_html(mlshop_get_gateway_title($gateway_id)); ?></li>
        <?php if ($txn_id) : ?>
            <li><span><?php esc_html_e('交易号', 'moonlight-shop'); ?></span><code><?php echo esc_html($txn_id); ?></code></li>
        <?php endif; ?>
        <?php if ($coupon_code) : ?>
            <li><span><?php esc_html_e('优惠码', 'moonlight-shop'); ?></span><code><?php echo esc_html($coupon_code); ?></code></li>
        <?php endif; ?>
    </ul>

    <div class="mlshop-table-scroll">
    <table class="mlshop-order-items">
        <thead>
            <tr>
                <th><?php esc_html_e('商品', 'moonlight-shop'); ?></th>
                <th><?php esc_html_e('数量', 'moonlight-shop'); ?></th>
                <th><?php esc_html_e('金额', 'moonlight-shop'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ((array) $items as $item) : ?>
                <tr>
                    <td><?php echo esc_html($item['title']); ?></td>
                    <td><?php echo (int) ($item['qty'] ?? 1); ?></td>
                    <td><?php echo esc_html(mlshop_format_price($item['subtotal'] ?? 0)); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <?php if ($coupon_code && $coupon_discount > 0) : ?>
                <tr>
                    <td colspan="2"><?php esc_html_e('商品小计', 'moonlight-shop'); ?></td>
                    <td><?php echo esc_html(mlshop_format_price($subtotal)); ?></td>
                </tr>
                <tr>
                    <td colspan="2"><?php esc_html_e('优惠减免', 'moonlight-shop'); ?></td>
                    <td>−<?php echo esc_html(mlshop_format_price($coupon_discount)); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ($has_physical) : ?>
                <tr>
                    <td colspan="2"><?php esc_html_e('運費', 'moonlight-shop'); ?></td>
                    <td><?php echo $shipping > 0 ? esc_html(mlshop_format_price($shipping)) : esc_html__('免費', 'moonlight-shop'); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td colspan="2"><?php esc_html_e('合计', 'moonlight-shop'); ?></td>
                <td><?php echo esc_html(mlshop_format_price($total)); ?></td>
            </tr>
        </tfoot>
    </table>
    </div>

    <?php if ($has_physical && !empty($shipping_address)) : ?>
        <div class="mlshop-shipping-info">
            <h3><?php esc_html_e('收件資料', 'moonlight-shop'); ?></h3>
            <ul class="mlshop-order-meta">
                <?php if (!empty($shipping_address['name'])) : ?>
                    <li><span><?php esc_html_e('收件人', 'moonlight-shop'); ?></span><?php echo esc_html($shipping_address['name']); ?></li>
                <?php endif; ?>
                <?php if (!empty($shipping_address['phone'])) : ?>
                    <li><span><?php esc_html_e('電話', 'moonlight-shop'); ?></span><?php echo esc_html($shipping_address['phone']); ?></li>
                <?php endif; ?>
                <?php if (!empty($shipping_address['address'])) : ?>
                    <li><span><?php esc_html_e('地址', 'moonlight-shop'); ?></span><?php echo esc_html($shipping_address['address']); ?></li>
                <?php endif; ?>
                <?php if (!empty($shipping_address['note'])) : ?>
                    <li><span><?php esc_html_e('備註', 'moonlight-shop'); ?></span><?php echo esc_html($shipping_address['note']); ?></li>
                <?php endif; ?>
            </ul>
            <p class="mlshop-shipping-note"><?php echo esc_html(MLSHOP_Shipping::note($subtotal)); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($status === 'pending') : ?>
        <div class="mlshop-qr-placeholder">
            <?php if ($expire_min > 0) : ?>
                <?php if ($remain_sec > 0) : ?>
                    <p class="mlshop-order-expire">
                        <?php
                        /* translators: %d = 剩余分钟数 */
                        printf(esc_html__('请在 %d 分钟内完成支付，逾期订单将自动取消。', 'moonlight-shop'), ceil($remain_sec / 60));
                        ?>
                    </p>
                <?php else : ?>
                    <p class="mlshop-order-expire mlshop-order-expired"><?php esc_html_e('支付时限已过期，请重新下单。', 'moonlight-shop'); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_manual) : ?>
                <p><?php esc_html_e('请扫码付款，并在备注中填写订单号。管理员确认到账后自动发货。', 'moonlight-shop'); ?></p>
                <div class="mlshop-qr-box"><span><?php esc_html_e('付款二维码占位', 'moonlight-shop'); ?></span></div>
                <p class="mlshop-order-no"><?php esc_html_e('订单号：', 'moonlight-shop'); ?><?php echo esc_html(get_the_title($order_id)); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('订单已提交，请按支付网关提示完成付款。支付成功后系统会自动更新状态。', 'moonlight-shop'); ?></p>
            <?php endif; ?>
        </div>
    <?php elseif ($status === 'failed') : ?>
        <p class="mlshop-order-failed-note"><?php esc_html_e('支付失败，请重新下单或更换支付方式。', 'moonlight-shop'); ?></p>
    <?php endif; ?>

    <?php if (is_array($delivery) && $delivery) : ?>
        <div class="mlshop-delivery">
            <h3><?php esc_html_e('交付内容', 'moonlight-shop'); ?></h3>
            <?php foreach ($delivery as $d) : ?>
                <?php if ($d['type'] === 'download') : ?>
                    <?php $product = get_post($d['product_id']); ?>
                    <p>
                        <strong><?php echo esc_html($product ? $product->post_title : ''); ?>：</strong>
                        <a class="mlshop-btn" href="<?php echo esc_url(add_query_arg('mlshop_download', $d['token'], home_url())); ?>"><?php esc_html_e('下载文件', 'moonlight-shop'); ?></a>
                    </p>
                <?php elseif ($d['type'] === 'cardkey') : ?>
                    <?php $product = get_post($d['product_id']); ?>
                    <p>
                        <strong><?php echo esc_html($product ? $product->post_title : ''); ?> <?php esc_html_e('卡密', 'moonlight-shop'); ?>：</strong>
                        <code class="mlshop-cardkey"><?php echo esc_html($d['key']); ?></code>
                    </p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
