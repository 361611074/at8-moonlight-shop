<?php
/**
 * 账户中心「我的订单」Tab 模板。
 *
 * @var array  $orders 订单行（id/order_no/item/price/currency/gateway/status/date）
 * @var string $symbol 货币符号
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}

$gateways = class_exists('MLUC_Payment_Manager') ? MLUC_Payment_Manager::get_instance()->get_all() : array();
$status_labels = array(
    'pending'   => mluc_ui_label('buy_st_pending', __('Pending', 'at8-moonlight-shop')),
    'paid'      => mluc_ui_label('buy_st_paid', __('Paid', 'at8-moonlight-shop')),
    'cancelled' => mluc_ui_label('buy_st_cancelled', __('Cancelled', 'at8-moonlight-shop')),
);
?>
<div class="mluc-orders">

    <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('od_title', __('My Orders', 'at8-moonlight-shop'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('od_title', __('My Orders', 'at8-moonlight-shop'))); ?></h3>
        <?php if (empty($orders)) : ?>
            <p class="mluc-empty"><?php echo esc_html(mluc_ui_label('od_empty', __('You have no orders yet.', 'at8-moonlight-shop'))); ?></p>
        <?php else : ?>
            <div class="mluc-table-scroll">
                <table class="mluc-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html(mluc_ui_label('od_th_no', __('Order No.', 'at8-moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('od_th_item', __('Item', 'at8-moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('buy_th_amount', __('Amount', 'at8-moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('od_th_gateway', __('Method', 'at8-moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('th_status', __('Status', 'at8-moonlight-shop'))); ?></th>
                            <th><?php echo esc_html(mluc_ui_label('buy_th_date', __('Date', 'at8-moonlight-shop'))); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o) :
                            $gw_title = isset($gateways[$o['gateway']]) ? $gateways[$o['gateway']]->get_name() : $o['gateway'];
                            $st = isset($status_labels[$o['status']]) ? $status_labels[$o['status']] : $o['status'];
                            ?>
                            <tr>
                                <td><?php echo esc_html('' !== $o['order_no'] ? $o['order_no'] : '#' . $o['id']); ?></td>
                                <td><?php echo esc_html($o['item']); ?></td>
                                <td><?php echo esc_html($symbol . number_format($o['price'], 2)); ?></td>
                                <td><?php echo esc_html($gw_title); ?></td>
                                <td><span class="mluc-pill mluc-pill-<?php echo esc_attr($o['status']); ?>"><?php echo esc_html($st); ?></span></td>
                                <td><?php echo esc_html($o['date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>
