<?php
/**
 * 我的订单列表（用于用户中心 Tab 或独立短代码）。
 *
 * @var array $orders
 */
if (!defined('ABSPATH')) {
    exit;
}
$checkout_url = mlshop_get_page_url('checkout');
?>
<div class="mlshop-account-orders">
    <?php if (empty($orders)) : ?>
        <p class="mlshop-message"><?php esc_html_e('您还没有订单。', 'moonlight-shop'); ?></p>
    <?php else : ?>
        <div class="mlshop-table-scroll">
        <table class="mlshop-orders-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('订单号', 'moonlight-shop'); ?></th>
                    <th><?php esc_html_e('金额', 'moonlight-shop'); ?></th>
                    <th><?php esc_html_e('状态', 'moonlight-shop'); ?></th>
                    <th><?php esc_html_e('操作', 'moonlight-shop'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order) : ?>
                    <?php $status = get_post_meta($order->ID, '_mlshop_status', true); ?>
                    <tr>
                        <td><?php echo esc_html($order->post_title); ?></td>
                        <td><?php echo esc_html(mlshop_format_price(get_post_meta($order->ID, '_mlshop_total', true))); ?></td>
                        <td><span class="mlshop-order-status status-<?php echo esc_attr($status); ?>"><?php echo esc_html(mlshop_get_order_status_label($status)); ?></span></td>
                        <td><a href="<?php echo esc_url($checkout_url . '?order=' . $order->ID); ?>"><?php esc_html_e('查看', 'moonlight-shop'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
