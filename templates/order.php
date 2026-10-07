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
// 微信 Native 扫码：下单时服务端写入的 code_url（订单页渲染二维码）
$wechat_code_url = ('wechat' === $gateway_id) ? (string) get_post_meta($order_id, '_mlshop_wechat_code_url', true) : '';
$coupon_code = get_post_meta($order_id, '_mlshop_coupon_code', true);
$coupon_discount = (float) get_post_meta($order_id, '_mlshop_coupon_discount', true);
$subtotal = (float) get_post_meta($order_id, '_mlshop_subtotal', true);
if (!$subtotal && $coupon_discount > 0) {
    $subtotal = (float) $total + $coupon_discount;
}
$shipping     = (float) get_post_meta($order_id, '_mlshop_shipping', true);
$has_physical = (bool) get_post_meta($order_id, '_mlshop_has_physical', true);
$shipping_address = (array) get_post_meta($order_id, '_mlshop_shipping_address', true);

// 游客订单：下单邮箱 + 付款后推荐注册
$is_guest_order = mlshop_is_guest_order($order_id);
$guest_email    = $is_guest_order ? (string) get_post_meta($order_id, '_mlshop_guest_email', true) : '';
$paid_statuses  = MLSHOP_Order::get_revenue_statuses();
$show_register_cta = $is_guest_order && $guest_email && in_array($status, $paid_statuses, true);

$back_url = isset($back_url) ? $back_url : mlshop_get_orders_url();
$created_display = $created ? mysql2wp_date(get_option('date_format') . ' ' . get_option('time_format'), $created) : '';

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
    <?php if (!$is_guest_order) : ?>
        <a class="mlshop-btn mlshop-order-back" href="<?php echo esc_url($back_url); ?>"><?php esc_html_e('返回订单列表', 'at8-moonlight-shop'); ?></a>
    <?php endif; ?>

    <h2 class="mlshop-title">
        <?php echo esc_html(get_the_title($order_id)); ?>
        <span class="mlshop-order-status status-<?php echo esc_attr($status); ?>"><?php echo esc_html(mlshop_get_order_status_label($status)); ?></span>
    </h2>

    <ul class="mlshop-order-meta">
        <li><span><?php esc_html_e('下单时间', 'at8-moonlight-shop'); ?></span><?php echo esc_html($created_display); ?></li>
        <li><span><?php esc_html_e('支付方式', 'at8-moonlight-shop'); ?></span><?php echo esc_html(mlshop_get_gateway_title($gateway_id)); ?></li>
        <?php if ($guest_email) : ?>
            <li><span><?php esc_html_e('联系邮箱', 'at8-moonlight-shop'); ?></span><?php echo esc_html($guest_email); ?></li>
        <?php endif; ?>
        <?php if ($txn_id) : ?>
            <li><span><?php esc_html_e('交易号', 'at8-moonlight-shop'); ?></span><code><?php echo esc_html($txn_id); ?></code></li>
        <?php endif; ?>
        <?php if ($coupon_code) : ?>
            <li><span><?php esc_html_e('优惠码', 'at8-moonlight-shop'); ?></span><code><?php echo esc_html($coupon_code); ?></code></li>
        <?php endif; ?>
    </ul>

    <div class="mlshop-table-scroll">
    <table class="mlshop-order-items">
        <thead>
            <tr>
                <th><?php esc_html_e('商品', 'at8-moonlight-shop'); ?></th>
                <th><?php esc_html_e('数量', 'at8-moonlight-shop'); ?></th>
                <th><?php esc_html_e('金额', 'at8-moonlight-shop'); ?></th>
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
                    <td colspan="2"><?php esc_html_e('商品小计', 'at8-moonlight-shop'); ?></td>
                    <td><?php echo esc_html(mlshop_format_price($subtotal)); ?></td>
                </tr>
                <tr>
                    <td colspan="2"><?php esc_html_e('优惠减免', 'at8-moonlight-shop'); ?></td>
                    <td>−<?php echo esc_html(mlshop_format_price($coupon_discount)); ?></td>
                </tr>
            <?php endif; ?>
            <?php if ($has_physical) : ?>
                <tr>
                    <td colspan="2"><?php esc_html_e('運費', 'at8-moonlight-shop'); ?></td>
                    <td><?php echo $shipping > 0 ? esc_html(mlshop_format_price($shipping)) : esc_html__('免費', 'at8-moonlight-shop'); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td colspan="2"><?php esc_html_e('合计', 'at8-moonlight-shop'); ?></td>
                <td><?php echo esc_html(mlshop_format_price($total)); ?></td>
            </tr>
        </tfoot>
    </table>
    </div>

    <?php if ($has_physical && !empty($shipping_address)) : ?>
        <div class="mlshop-shipping-info">
            <h3><?php esc_html_e('收件資料', 'at8-moonlight-shop'); ?></h3>
            <ul class="mlshop-order-meta">
                <?php if (!empty($shipping_address['name'])) : ?>
                    <li><span><?php esc_html_e('收件人', 'at8-moonlight-shop'); ?></span><?php echo esc_html($shipping_address['name']); ?></li>
                <?php endif; ?>
                <?php if (!empty($shipping_address['phone'])) : ?>
                    <li><span><?php esc_html_e('電話', 'at8-moonlight-shop'); ?></span><?php echo esc_html($shipping_address['phone']); ?></li>
                <?php endif; ?>
                <?php if (!empty($shipping_address['address'])) : ?>
                    <li><span><?php esc_html_e('地址', 'at8-moonlight-shop'); ?></span><?php echo esc_html($shipping_address['address']); ?></li>
                <?php endif; ?>
                <?php if (!empty($shipping_address['note'])) : ?>
                    <li><span><?php esc_html_e('備註', 'at8-moonlight-shop'); ?></span><?php echo esc_html($shipping_address['note']); ?></li>
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
                        printf(esc_html__('请在 %d 分钟内完成支付，逾期订单将自动取消。', 'at8-moonlight-shop'), (int) ceil($remain_sec / 60));
                        ?>
                    </p>
                <?php else : ?>
                    <p class="mlshop-order-expire mlshop-order-expired"><?php esc_html_e('支付时限已过期，请重新下单。', 'at8-moonlight-shop'); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($is_manual) : ?>
                <p><?php esc_html_e('请扫码付款，并在备注中填写订单号。管理员确认到账后自动发货。', 'at8-moonlight-shop'); ?></p>
                <div class="mlshop-qr-box"><span><?php esc_html_e('付款二维码占位', 'at8-moonlight-shop'); ?></span></div>
                <p class="mlshop-order-no"><?php esc_html_e('订单号：', 'at8-moonlight-shop'); ?><?php echo esc_html(get_the_title($order_id)); ?></p>
            <?php elseif ('wechat' === $gateway_id && '' !== $wechat_code_url) : ?>
                <p><?php esc_html_e('请使用微信扫描二维码完成付款，支付成功后本页状态会自动更新（异步通知）；也可点击按钮主动复核。', 'at8-moonlight-shop'); ?></p>
                <div class="mlshop-qr-box">
                    <img class="mlshop-wechat-qr"
                        src="<?php echo esc_url(apply_filters('mlshop_wechat_qr_img_url', 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($wechat_code_url), $order_id, $wechat_code_url)); ?>"
                        width="200" height="200"
                        alt="<?php esc_attr_e('微信支付二维码', 'at8-moonlight-shop'); ?>">
                </div>
                <p class="mlshop-order-no"><?php esc_html_e('订单号：', 'at8-moonlight-shop'); ?><?php echo esc_html(get_the_title($order_id)); ?></p>
                <button type="button" class="mlshop-btn mlshop-wechat-check" data-order="<?php echo (int) $order_id; ?>">
                    <?php esc_html_e('我已完成支付', 'at8-moonlight-shop'); ?>
                </button>
                <p class="mlshop-wechat-check-msg mlshop-message" style="display:none;"></p>
            <?php elseif ('wechat' === $gateway_id) : ?>
                <p><?php esc_html_e('订单已提交，请按支付网关提示完成付款。支付成功后系统会自动更新状态。', 'at8-moonlight-shop'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('订单已提交，请按支付网关提示完成付款。支付成功后系统会自动更新状态。', 'at8-moonlight-shop'); ?></p>
            <?php endif; ?>
        </div>
    <?php elseif ($status === 'failed') : ?>
        <p class="mlshop-order-failed-note"><?php esc_html_e('支付失败，请重新下单或更换支付方式。', 'at8-moonlight-shop'); ?></p>
    <?php endif; ?>

    <?php
    // 物流第二批：发货单（公司 + 运单号 + 状态 + 轨迹时间线）
    $shipments = class_exists('MLSHOP_Shipping') ? MLSHOP_Shipping::get_shipments($order_id) : array();
    ?>
    <?php if (!empty($shipments)) : ?>
        <div class="mlshop-shipments">
            <h3><?php esc_html_e('物流信息', 'at8-moonlight-shop'); ?></h3>
            <?php foreach ($shipments as $ship) : ?>
                <div class="mlshop-shipment">
                    <p class="mlshop-shipment-line">
                        <strong><?php echo esc_html($ship['company'] !== '' ? $ship['company'] : '—'); ?></strong>
                        <?php esc_html_e('运单号：', 'at8-moonlight-shop'); ?>
                        <code><?php echo esc_html($ship['no']); ?></code>
                        <span class="mlshop-order-status status-<?php echo esc_attr($ship['status']); ?>"><?php echo esc_html($ship['status_label']); ?></span>
                    </p>
                    <?php if (!empty($ship['events'])) : ?>
                        <ul class="mlshop-shipment-timeline">
                            <?php foreach ($ship['events'] as $ev) : ?>
                                <li>
                                    <span class="mlshop-shipment-time"><?php echo esc_html(MLSHOP_Shipping::format_event_time(isset($ev['time']) ? $ev['time'] : '')); ?></span>
                                    <?php echo esc_html(isset($ev['desc']) ? $ev['desc'] : ''); ?>
                                    <?php if (!empty($ev['city'])) : ?>
                                        <span class="mlshop-shipment-city">（<?php echo esc_html($ev['city']); ?>）</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ('delivered' === $status && is_user_logged_in()) : ?>
        <div class="mlshop-confirm-delivery-wrap">
            <button type="button" class="mlshop-btn mlshop-confirm-delivery" data-order="<?php echo (int) $order_id; ?>">
                <?php esc_html_e('确认收货', 'at8-moonlight-shop'); ?>
            </button>
            <p class="mlshop-confirm-delivery-tip"><?php esc_html_e('收到商品请点击确认收货；超期未确认的订单将按商城设置自动完成。', 'at8-moonlight-shop'); ?></p>
        </div>
    <?php endif; ?>

    <?php
    // 售后退款（Refund_Service）：可退款状态展示「申请售后」；已申请显示处理中；
    // 已退款显示状态说明。规则闸（已下载/已发卡/已发货/授予超窗）不过关时不出现申请入口。
    $refund_states   = array('paid', 'processing', 'awaiting_shipment', 'shipped', 'delivered', 'completed');
    $refund_service  = class_exists('Moonlight_Refund_Service');
    $refund_requests = $refund_service ? Moonlight_Refund_Service::requests($order_id) : array();
    $refund_pending  = false;
    foreach ($refund_requests as $r) {
        if (is_array($r) && isset($r['status']) && 'pending' === $r['status']) {
            $refund_pending = true;
            break;
        }
    }
    $refund_can_apply = $refund_service
        && is_user_logged_in()
        && in_array($status, $refund_states, true)
        && !$refund_pending
        && true === Moonlight_Refund_Service::can_refund($order_id);
    ?>
    <?php if ('refunded' === $status) : ?>
        <div class="mlshop-refund-note">
            <?php esc_html_e('该订单已退款，退款金额将按原支付方式退回，如有疑问请联系站长。', 'at8-moonlight-shop'); ?>
        </div>
    <?php elseif ($refund_pending) : ?>
        <div class="mlshop-refund-note">
            <?php esc_html_e('售后申请处理中，管理员会尽快与您联系。', 'at8-moonlight-shop'); ?>
        </div>
    <?php elseif ($refund_can_apply) : ?>
        <div class="mlshop-refund-apply">
            <h3><?php esc_html_e('申请售后', 'at8-moonlight-shop'); ?></h3>
            <textarea class="mlshop-refund-reason" rows="3" placeholder="<?php esc_attr_e('请填写售后原因（必填）', 'at8-moonlight-shop'); ?>"></textarea>
            <button type="button" class="mlshop-btn mlshop-apply-refund" data-order="<?php echo (int) $order_id; ?>">
                <?php esc_html_e('申请售后', 'at8-moonlight-shop'); ?>
            </button>
        </div>
    <?php endif; ?>

    <?php if (is_array($delivery) && $delivery) : ?>
        <div class="mlshop-delivery">
            <h3><?php esc_html_e('交付内容', 'at8-moonlight-shop'); ?></h3>
            <?php foreach ($delivery as $d) : ?>
                <?php if ($d['type'] === 'download') : ?>
                    <?php $product = get_post($d['product_id']); ?>
                    <p>
                        <strong><?php echo esc_html($product ? $product->post_title : ''); ?>：</strong>
                        <a class="mlshop-btn" href="<?php echo esc_url(add_query_arg(array('mlshop_download' => $d['token']), mlshop_order_view_url($order_id))); ?>"><?php esc_html_e('下载文件', 'at8-moonlight-shop'); ?></a>
                    </p>
                <?php elseif ($d['type'] === 'cardkey') : ?>
                    <?php $product = get_post($d['product_id']); ?>
                    <p>
                        <strong><?php echo esc_html($product ? $product->post_title : ''); ?> <?php esc_html_e('卡密', 'at8-moonlight-shop'); ?>：</strong>
                        <code class="mlshop-cardkey"><?php echo esc_html((string) mlshop_delivery_cardkey_plaintext($d)); ?></code>
                    </p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($show_register_cta) : ?>
        <div class="mlshop-register-cta">
            <h3><?php esc_html_e('付款成功！推荐您注册成为网站用户', 'at8-moonlight-shop'); ?></h3>
            <p><?php esc_html_e('注册后可长期保存订单、随时重新下载虚拟商品、使用优惠码与积分，并享受更快的售后处理。', 'at8-moonlight-shop'); ?></p>
            <p>
                <a class="mlshop-btn" href="<?php echo esc_url(mlshop_guest_register_url($guest_email)); ?>"><?php esc_html_e('立即注册', 'at8-moonlight-shop'); ?></a>
                <?php
                $login_url = function_exists('mluc_get_account_url') ? mluc_get_account_url() : wp_login_url(mlshop_get_page_url('checkout'));
                ?>
                <a class="mlshop-btn mlshop-btn-ghost" href="<?php echo esc_url($login_url); ?>"><?php esc_html_e('已有账号？登录', 'at8-moonlight-shop'); ?></a>
            </p>
            <p class="description">
                <?php
                /* translators: %s = 游客下单邮箱 */
                printf(esc_html__('注册时填写下单邮箱 %s，方便站长为您关联本次订单。', 'at8-moonlight-shop'), '<strong>' . esc_html($guest_email) . '</strong>');
                ?>
            </p>
        </div>
    <?php endif; ?>
</div>

