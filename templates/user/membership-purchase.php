<?php
/**
 * 独立会员购买卡片（仅装用户中心、未启用商城时显示）。
 * 支持网关：线下转账（manual）/ PayPal（Smart Buttons）/ Stripe（Checkout 跳转）。
 *
 * @var array  $levels
 * @var array  $gateways
 * @var array  $orders
 * @var string $symbol
 * @var string $instructions
 * @var string $nonce
 * @var bool   $paypal_on
 * @var string $paypal_sdk
 * @var bool   $stripe_on
 * @var string $pay_notice
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}

// PayPal SDK 经 wp_enqueue_script 加载时补回 data-partner-attribution-id 属性。
if (!function_exists('mluc_paypal_sdk_attribution')) {
    function mluc_paypal_sdk_attribution($tag, $handle)
    {
        if ('mluc-paypal-sdk' === $handle) {
            $tag = str_replace('<script ', '<script data-partner-attribution-id="moonlight_user_center" ', $tag);
        }
        return $tag;
    }
}
?>
<div class="mluc-buy">
    <?php if ('paid' === $pay_notice) : ?>
        <p class="mluc-buy-notice mluc-buy-notice-ok"><?php echo esc_html(mluc_ui_label('buy_ok', __('Payment successful. Your membership has been activated.', 'at8-moonlight-shop'))); ?></p>
    <?php elseif ('failed' === $pay_notice) : ?>
        <p class="mluc-buy-notice mluc-buy-notice-err"><?php echo esc_html(mluc_ui_label('buy_failed', __('Payment was not completed or verification failed. Please try again or contact the administrator.', 'at8-moonlight-shop'))); ?></p>
    <?php elseif ('cancelled' === $pay_notice) : ?>
        <p class="mluc-buy-notice"><?php echo esc_html(mluc_ui_label('buy_cancelled', __('Payment cancelled. The order remains pending.', 'at8-moonlight-shop'))); ?></p>
    <?php endif; ?>

    <div class="mluc-buy-grid">
        <?php foreach ($levels as $key => $lv) :
            if ('free' === $key || '' === $key) {
                continue;
            }
            $price    = isset($lv['price']) ? (float) $lv['price'] : 0;
            $validity = isset($lv['validity']) ? (int) $lv['validity'] : 0;
            $color    = isset($lv['color']) ? $lv['color'] : '#2f6fed';
            ?>
            <div class="mluc-buy-card" style="border-top-color:<?php echo esc_attr($color); ?>">
                <div class="mluc-buy-name" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($lv['label']); ?></div>
                <div class="mluc-buy-price"><?php echo esc_html($symbol . number_format($price, 2)); ?></div>
                <div class="mluc-buy-validity">
                    <?php /* translators: %d: validity days */ echo esc_html($validity > 0
                        ? sprintf(mluc_ui_label('buy_validity_days', __('Valid for %d days', 'at8-moonlight-shop')), (int) $validity)
                        : mluc_ui_label('permanent', __('Permanent', 'at8-moonlight-shop'))); ?>
                </div>
                <?php if ($price > 0) : ?>
                    <div class="mluc-buy-actions">
                        <?php if ($gateways) :
                            $mluc_first_gw = array_key_first($gateways); ?>
                            <?php foreach ($gateways as $gid => $gtitle) : ?>
                                <label class="mluc-buy-gateway">
                                    <input type="radio" name="mluc_buy_gateway_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($gid); ?>" <?php checked($gid, $mluc_first_gw); ?>>
                                    <span><?php echo esc_html($gtitle); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <span class="mluc-buy-gw-empty"><?php echo esc_html(mluc_ui_label('buy_no_gateway', __('No payment method is available. Please contact the administrator.', 'at8-moonlight-shop'))); ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="mluc-btn-buy" data-level="<?php echo esc_attr($key); ?>"<?php echo $gateways ? '' : ' disabled'; ?>><?php echo esc_html(mluc_ui_label('buy_btn', __('Buy', 'at8-moonlight-shop'))); ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="mluc-buy-msg" role="alert"></p>
    <div class="mluc-pp-mount" hidden aria-label="PayPal"></div>

    <?php if (isset($gateways['manual'])) : ?>
        <div class="mluc-buy-instructions">
            <strong><?php echo esc_html(mluc_ui_label('buy_instructions', __('Payment Instructions', 'at8-moonlight-shop'))); ?></strong>
            <p><?php echo esc_html($instructions); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($orders)) : ?>
        <div class="mluc-order-list">
            <strong><?php echo esc_html(mluc_ui_label('buy_history', __('My Purchase History', 'at8-moonlight-shop'))); ?></strong>
            <table class="mluc-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html(mluc_ui_label('buy_th_order', __('Order', 'at8-moonlight-shop'))); ?></th>
                        <th><?php echo esc_html(mluc_ui_label('th_level', __('Level', 'at8-moonlight-shop'))); ?></th>
                        <th><?php echo esc_html(mluc_ui_label('buy_th_amount', __('Amount', 'at8-moonlight-shop'))); ?></th>
                        <th><?php echo esc_html(mluc_ui_label('th_status', __('Status', 'at8-moonlight-shop'))); ?></th>
                        <th><?php echo esc_html(mluc_ui_label('buy_th_date', __('Date', 'at8-moonlight-shop'))); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o) :
                        $status_labels = array(
                            'pending'   => mluc_ui_label('buy_st_pending', __('Pending', 'at8-moonlight-shop')),
                            'paid'      => mluc_ui_label('buy_st_paid', __('Paid', 'at8-moonlight-shop')),
                            'cancelled' => mluc_ui_label('buy_st_cancelled', __('Cancelled', 'at8-moonlight-shop')),
                        );
                        $st = isset($status_labels[$o['status']]) ? $status_labels[$o['status']] : $o['status'];
                        ?>
                        <tr>
                            <td><?php echo esc_html($o['title']); ?></td>
                            <td><?php echo esc_html(MLUC_Membership::get_level_label($o['level'])); ?></td>
                            <td><?php echo esc_html($symbol . number_format($o['price'], 2)); ?></td>
                            <td><span class="mluc-pill mluc-pill-<?php echo esc_attr($o['status']); ?>"><?php echo esc_html($st); ?></span></td>
                            <td><?php echo esc_html($o['date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php if ($paypal_on && $paypal_sdk) :
    // PayPal SDK 必须经由 wp_enqueue_script 加载（Plugin Check 要求，不得在模板内直接输出 <script src>）
    wp_enqueue_script('mluc-paypal-sdk', esc_url_raw($paypal_sdk), array(), null, true);
    add_filter('script_loader_tag', 'mluc_paypal_sdk_attribution', 10, 2);
endif; ?>
<script>
(function () {
    var root = document.querySelector('.mluc-buy');
    if (!root) { return; }
    var msg = root.querySelector('.mluc-buy-msg');
    var ppMount = root.querySelector('.mluc-pp-mount');
    var ppOrder = null;

    function say(text) { if (msg) { msg.textContent = text || ''; } }

    function post(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', '<?php echo esc_js($nonce); ?>');
        if (extra) { Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); }); }
        return fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function mountPayPal(orderId) {
        if (!window.paypal || !ppMount) {
            say('<?php echo esc_js(mluc_ui_label('buy_pp_load_fail', __('Failed to load PayPal. Please refresh the page and try again.', 'at8-moonlight-shop'))); ?>');
            return;
        }
        ppOrder = orderId;
        ppMount.hidden = false;
        ppMount.innerHTML = '';
        window.paypal.Buttons({
            style: { layout: 'vertical', label: 'paypal' },
            createOrder: function () {
                return post('mluc_paypal_create', { order_id: orderId }).then(function (res) {
                    if (!res.success) { throw new Error(res.message || 'create failed'); }
                    return res.data.pp_order_id;
                });
            },
            onApprove: function () {
                say('<?php echo esc_js(mluc_ui_label('buy_pp_confirming', __('Confirming payment...', 'at8-moonlight-shop'))); ?>');
                return post('mluc_paypal_capture', { order_id: orderId, pp_order_id: ppOrder }).then(function (res) {
                    say(res.message);
                    if (res.success) { setTimeout(function () { window.location.reload(); }, 1500); }
                });
            },
            onError: function () {
                say('<?php echo esc_js(mluc_ui_label('buy_pp_error', __('PayPal payment error. Please try again or contact the administrator.', 'at8-moonlight-shop'))); ?>');
            }
        }).render(ppMount);
    }

    root.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.mluc-btn-buy') : null;
        if (!btn) { return; }
        e.preventDefault();
        var level = btn.getAttribute('data-level');
        var gwEl = root.querySelector('input[name="mluc_buy_gateway_' + level + '"]:checked');
        var gw = gwEl ? gwEl.value : '';
        say('');
        btn.disabled = true;
        post('mluc_buy_level', { level: level, gateway: gw })
            .then(function (res) {
                if (!res.success) { say(res.message); return; }
                var flow = res.data && res.data.flow;
                if ('paypal' === flow) {
                    say('<?php echo esc_js(mluc_ui_label('buy_pp_goto', __('Please complete the PayPal payment below.', 'at8-moonlight-shop'))); ?>');
                    mountPayPal(res.data.order_id);
                } else if (res.data.redirect) {
                    window.location.href = res.data.redirect;
                } else {
                    say(res.message);
                }
            })
            .catch(function () {
                say('<?php echo esc_js(mluc_ui_label('buy_net_error', __('Network error. Please try again later.', 'at8-moonlight-shop'))); ?>');
            })
            .finally(function () { btn.disabled = false; });
    });
})();
</script>
