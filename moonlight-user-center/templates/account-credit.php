<?php
/**
 * 账户中心「积分余额」Tab：余额卡片、积分充值（套餐 / 自定义）、积分兑换余额、双流水。
 *
 * 支付比例与套餐全部来自后台设置，前端 JS 仅做展示换算，提交后服务端重新计价。
 *
 * @var float  $credit_balance 积分余额
 * @var float  $balance        账户余额（货币）
 * @var string $credit_name    积分名称
 * @var string $symbol         货币符号
 * @var array  $credit_ledger  积分流水
 * @var array  $balance_ledger 余额流水
 * @var array  $packages       充值套餐 [credit, price]
 * @var float  $rate           充值比例（1 货币 = N 积分）
 * @var array  $limits         自定义充值限额 [min, max]
 * @var bool   $exchange_on    是否启用兑换
 * @var float  $exchange_rate  兑换比例（N 积分 = 1 货币）
 * @var int    $exchange_min   单次最少兑换积分
 * @var array  $gateways       充值可用网关（id => name，不含余额 / 积分）
 * @var string $nonce          AJAX nonce
 * @var bool   $paypal_on      PayPal 是否启用
 * @var string $paypal_sdk     PayPal SDK 地址
 * @var string $pay_notice     回跳提示（paid / failed / cancelled）
 * @var bool   $checkin_on     签到功能是否启用
 * @var bool   $checkin_done   今日是否已签到
 * @var int    $checkin_streak 当前连续签到天数
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}
$notice_map = array(
    'paid'      => array('mluc-buy-notice-ok', mluc_ui_label('buy_ok', __('Payment successful. Your membership has been activated.', 'moonlight-user-center'))),
    'failed'    => array('mluc-buy-notice-err', mluc_ui_label('buy_failed', __('Payment was not completed or verification failed. Please try again or contact the administrator.', 'moonlight-user-center'))),
    'cancelled' => array('', mluc_ui_label('buy_cancelled', __('Payment cancelled. The order remains pending.', 'moonlight-user-center'))),
);
?>
<div class="mluc-credit">

    <?php if (isset($notice_map[$pay_notice])) : ?>
        <p class="mluc-buy-notice <?php echo esc_attr($notice_map[$pay_notice][0]); ?>"><?php echo esc_html($notice_map[$pay_notice][1]); ?></p>
    <?php endif; ?>

    <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('cr_tab', __('Points & Balance', 'moonlight-user-center'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('cr_tab', __('Points & Balance', 'moonlight-user-center'))); ?></h3>
        <div class="mluc-credit-cards">
            <div class="mluc-credit-card">
                <span class="mluc-credit-card-label"><?php echo esc_html($credit_name); ?></span>
                <span class="mluc-credit-card-value" data-mluc-credit-balance><?php echo esc_html(number_format($credit_balance, 2)); ?></span>
            </div>
            <div class="mluc-credit-card">
                <span class="mluc-credit-card-label"><?php echo esc_html(mluc_ui_label('cr_balance_money', __('Account Balance', 'moonlight-user-center'))); ?></span>
                <span class="mluc-credit-card-value" data-mluc-money-balance><?php echo esc_html($symbol . number_format($balance, 2)); ?></span>
            </div>
        </div>
        <?php if ($checkin_on) : ?>
            <div class="mluc-credit-checkin">
                <button type="button" class="mluc-btn" data-mluc-checkin-btn <?php disabled($checkin_done); ?>>
                    <?php echo esc_html($checkin_done
                        ? mluc_ui_label('cr_checkin_done', __('Checked in today', 'moonlight-user-center'))
                        : mluc_ui_label('cr_checkin_btn', __('Check In', 'moonlight-user-center'))); ?>
                </button>
                <?php if ($checkin_streak > 0) : ?>
                    <span class="mluc-credit-checkin-streak"><?php echo esc_html(sprintf(mluc_ui_label('cr_checkin_streak', __('%d-day streak', 'moonlight-user-center')), $checkin_streak)); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('cr_recharge_title', __('Top Up', 'moonlight-user-center'))); ?>">
        <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('cr_recharge_title', __('Top Up', 'moonlight-user-center'))); ?></h3>
        <form class="mluc-credit-recharge" onsubmit="return false;">
            <div class="mluc-credit-packages">
                <?php foreach ($packages as $i => $pkg) : ?>
                    <label class="mluc-credit-pkg">
                        <input type="radio" name="mluc_recharge_pkg" value="<?php echo (int) $i; ?>" <?php checked(0, $i); ?>>
                        <span class="mluc-credit-pkg-credit"><?php echo esc_html(number_format($pkg['credit'], 0)); ?> <?php echo esc_html($credit_name); ?></span>
                        <span class="mluc-credit-pkg-price"><?php echo esc_html($symbol . number_format($pkg['price'], 2)); ?></span>
                    </label>
                <?php endforeach; ?>
                <label class="mluc-credit-pkg mluc-credit-pkg-custom">
                    <input type="radio" name="mluc_recharge_pkg" value="custom">
                    <span><?php echo esc_html(mluc_ui_label('cr_custom', __('Custom', 'moonlight-user-center'))); ?></span>
                </label>
            </div>
            <div class="mluc-credit-custom" hidden>
                <input type="number" min="1" step="1" class="mluc-credit-custom-input" data-mluc-custom-input
                       placeholder="<?php echo esc_attr(sprintf('%d - %s', $limits['min'], $limits['max'] > 0 ? (string) $limits['max'] : '∞')); ?>">
                <span class="mluc-credit-custom-hint">
                    <?php echo esc_html(sprintf(mluc_ui_label('cr_rate_hint', __('Rate: %2$s %1$s per currency unit', 'moonlight-user-center')), $credit_name, $rate)); ?>
                </span>
                <span class="mluc-credit-custom-price" data-mluc-custom-price></span>
            </div>
            <?php if ($gateways) : ?>
                <div class="mluc-credit-gateways">
                    <?php $mluc_cr_first = array_key_first($gateways); ?>
                    <?php foreach ($gateways as $gid => $gname) : ?>
                        <label class="mluc-buy-gateway">
                            <input type="radio" name="mluc_recharge_gateway" value="<?php echo esc_attr($gid); ?>" <?php checked($gid, $mluc_cr_first); ?>>
                            <span><?php echo esc_html($gname); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="mluc-credit-no-gw"><?php echo esc_html(mluc_ui_label('buy_no_gateway', __('No payment method is available. Please contact the administrator.', 'moonlight-user-center'))); ?></p>
            <?php endif; ?>
            <button type="button" class="mluc-btn mluc-credit-recharge-btn" data-mluc-recharge-btn <?php echo $gateways ? '' : 'disabled'; ?>>
                <?php echo esc_html(mluc_ui_label('cr_btn_recharge', __('Top Up', 'moonlight-user-center'))); ?>
            </button>
            <p class="mluc-credit-msg" data-mluc-credit-msg role="alert"></p>
            <div class="mluc-pp-mount" hidden aria-label="PayPal"></div>
        </form>
    </section>

    <?php if ($exchange_on) : ?>
        <section class="mluc-card" aria-label="<?php echo esc_attr(mluc_ui_label('cr_exchange_title', __('Exchange to Balance', 'moonlight-user-center'))); ?>">
            <h3 class="mluc-card-title"><?php echo esc_html(mluc_ui_label('cr_exchange_title', __('Exchange to Balance', 'moonlight-user-center'))); ?></h3>
            <p class="mluc-credit-exchange-hint">
                <?php echo esc_html(sprintf(mluc_ui_label('cr_exchange_hint', __('Rate: %1$s points = 1 currency unit', 'moonlight-user-center')), $exchange_rate)); ?>
            </p>
            <form class="mluc-credit-exchange" onsubmit="return false;">
                <input type="number" min="<?php echo (int) $exchange_min; ?>" step="1" class="mluc-credit-exchange-input" data-mluc-exchange-input
                       placeholder="<?php echo esc_attr(sprintf('%s ≥ %d', $credit_name, $exchange_min)); ?>">
                <span class="mluc-credit-exchange-price" data-mluc-exchange-price></span>
                <button type="button" class="mluc-btn" data-mluc-exchange-btn>
                    <?php echo esc_html(mluc_ui_label('cr_btn_exchange', __('Exchange Now', 'moonlight-user-center'))); ?>
                </button>
                <p class="mluc-credit-msg" data-mluc-exchange-msg role="alert"></p>
            </form>
        </section>
    <?php endif; ?>

    <?php
    // 双流水（结构一致，循环渲染）。
    $ledgers = array(
        array(mluc_ui_label('cr_ledger_credit', __('Points History', 'moonlight-user-center')), $credit_ledger),
        array(mluc_ui_label('cr_ledger_balance', __('Balance History', 'moonlight-user-center')), $balance_ledger),
    );
    foreach ($ledgers as $ledger_pair) :
        list($ledger_title, $ledger_rows) = $ledger_pair;
        ?>
        <section class="mluc-card">
            <h3 class="mluc-card-title"><?php echo esc_html($ledger_title); ?></h3>
            <?php if (!empty($ledger_rows)) : ?>
                <div class="mluc-table-scroll">
                    <table class="mluc-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html(mluc_ui_label('cr_th_time', __('Time', 'moonlight-user-center'))); ?></th>
                                <th><?php echo esc_html(mluc_ui_label('cr_th_delta', __('Change', 'moonlight-user-center'))); ?></th>
                                <th><?php echo esc_html(mluc_ui_label('cr_th_balance', __('Balance', 'moonlight-user-center'))); ?></th>
                                <th><?php echo esc_html(mluc_ui_label('cr_th_note', __('Note', 'moonlight-user-center'))); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledger_rows as $row) :
                                $delta = (float) $row['delta'];
                                ?>
                                <tr>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $row['time'])); ?></td>
                                    <td class="<?php echo esc_attr($delta >= 0 ? 'mluc-credit-plus' : 'mluc-credit-minus'); ?>"><?php echo esc_html(($delta >= 0 ? '+' : '') . number_format($delta, 2)); ?></td>
                                    <td><?php echo esc_html(number_format((float) $row['balance'], 2)); ?></td>
                                    <td><?php echo esc_html($row['note']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="mluc-empty"><?php echo esc_html(mluc_ui_label('cr_empty_ledger', __('No records yet.', 'moonlight-user-center'))); ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php if ($paypal_on && $paypal_sdk) : ?>
    <script src="<?php echo esc_url($paypal_sdk); ?>" data-partner-attribution-id="moonlight_user_center"></script>
<?php endif; ?>
<script>
(function () {
    var root = document.querySelector('.mluc-credit');
    if (!root) { return; }
    var msg = root.querySelector('[data-mluc-credit-msg]');
    var rate = <?php echo esc_js((string) (0 + $rate)); ?>;
    var exRate = <?php echo esc_js((string) (0 + $exchange_rate)); ?>;
    var customInput = root.querySelector('[data-mluc-custom-input]');
    var customBox = root.querySelector('.mluc-credit-custom');
    var customPrice = root.querySelector('[data-mluc-custom-price]');
    var exInput = root.querySelector('[data-mluc-exchange-input]');
    var exPrice = root.querySelector('[data-mluc-exchange-price]');
    var ppMount = root.querySelector('.mluc-pp-mount');
    var ppOrder = null;

    function say(text) { if (msg) { msg.textContent = text || ''; } }
    function sayEx(text) {
        var m = root.querySelector('[data-mluc-exchange-msg]');
        if (m) { m.textContent = text || ''; }
    }

    function post(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', '<?php echo esc_js($nonce); ?>');
        if (extra) { Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); }); }
        return fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    // 套餐 / 自定义切换 + 自定义金额实时计价（仅展示，提交后服务端重新计价）。
    root.addEventListener('change', function (e) {
        if (!e.target || e.target.name !== 'mluc_recharge_pkg') { return; }
        var isCustom = 'custom' === e.target.value;
        if (customBox) { customBox.hidden = !isCustom; }
        if (isCustom && customInput) { customInput.focus(); }
        updateCustomPrice();
    });
    if (customInput) { customInput.addEventListener('input', updateCustomPrice); }
    function updateCustomPrice() {
        if (!customInput || !customPrice) { return; }
        var v = parseFloat(customInput.value);
        customPrice.textContent = (v > 0 && rate > 0) ? '≈ <?php echo esc_js($symbol); ?>' + (v / rate).toFixed(2) : '';
    }

    // 兑换金额实时展示。
    if (exInput) { exInput.addEventListener('input', updateExPrice); }
    function updateExPrice() {
        if (!exInput || !exPrice) { return; }
        var v = parseFloat(exInput.value);
        exPrice.textContent = (v > 0 && exRate > 0) ? '≈ <?php echo esc_js($symbol); ?>' + (v / exRate).toFixed(2) : '';
    }

    function mountPayPal(orderId) {
        if (!window.paypal || !ppMount) {
            say('<?php echo esc_js(mluc_ui_label('buy_pp_load_fail', __('Failed to load PayPal. Please refresh the page and try again.', 'moonlight-user-center'))); ?>');
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
                say('<?php echo esc_js(mluc_ui_label('buy_pp_confirming', __('Confirming payment...', 'moonlight-user-center'))); ?>');
                return post('mluc_paypal_capture', { order_id: orderId, pp_order_id: ppOrder }).then(function (res) {
                    say(res.message);
                    if (res.success) { setTimeout(function () { window.location.reload(); }, 1500); }
                });
            },
            onError: function () {
                say('<?php echo esc_js(mluc_ui_label('buy_pp_error', __('PayPal payment error. Please try again or contact the administrator.', 'moonlight-user-center'))); ?>');
            }
        }).render(ppMount);
    }

    var rechargeBtn = root.querySelector('[data-mluc-recharge-btn]');
    if (rechargeBtn) {
        rechargeBtn.addEventListener('click', function () {
            say('');
            var pkgEl = root.querySelector('input[name="mluc_recharge_pkg"]:checked');
            var gwEl = root.querySelector('input[name="mluc_recharge_gateway"]:checked');
            var payload = { package: pkgEl ? pkgEl.value : '', gateway: gwEl ? gwEl.value : '' };
            if ('custom' === payload.package) {
                if (!customInput || !(parseFloat(customInput.value) >= 1)) { return; }
                payload.custom_credit = customInput.value;
            }
            rechargeBtn.disabled = true;
            post('mluc_create_recharge', payload)
                .then(function (res) {
                    if (!res.success) { say(res.message); return; }
                    var flow = res.data && res.data.flow;
                    if ('paypal' === flow) {
                        say('<?php echo esc_js(mluc_ui_label('buy_pp_goto', __('Please complete the PayPal payment below.', 'moonlight-user-center'))); ?>');
                        mountPayPal(res.data.order_id);
                    } else if (res.data.redirect) {
                        window.location.href = res.data.redirect;
                    } else {
                        say(res.message);
                        if (res.data && res.data.reload) { setTimeout(function () { window.location.reload(); }, 1200); }
                    }
                })
                .catch(function () {
                    say('<?php echo esc_js(mluc_ui_label('buy_net_error', __('Network error. Please try again later.', 'moonlight-user-center'))); ?>');
                })
                .finally(function () { rechargeBtn.disabled = false; });
        });
    }

    var exchangeBtn = root.querySelector('[data-mluc-exchange-btn]');
    if (exchangeBtn) {
        exchangeBtn.addEventListener('click', function () {
            sayEx('');
            if (!exInput || !(parseFloat(exInput.value) >= 1)) { return; }
            exchangeBtn.disabled = true;
            post('mluc_exchange_credit', { points: exInput.value })
                .then(function (res) {
                    sayEx(res.message);
                    if (res.success) {
                        setTimeout(function () { window.location.reload(); }, 1200);
                    }
                })
                .catch(function () {
                    sayEx('<?php echo esc_js(mluc_ui_label('buy_net_error', __('Network error. Please try again later.', 'moonlight-user-center'))); ?>');
                })
                .finally(function () { exchangeBtn.disabled = false; });
        });
    }

    var checkinBtn = root.querySelector('[data-mluc-checkin-btn]');
    if (checkinBtn) {
        checkinBtn.addEventListener('click', function () {
            checkinBtn.disabled = true;
            post('mluc_checkin', {})
                .then(function (res) {
                    if (res.success) {
                        setTimeout(function () { window.location.reload(); }, 1200);
                    } else {
                        checkinBtn.disabled = false;
                    }
                    if (msg) { msg.textContent = res.message || ''; }
                })
                .catch(function () {
                    checkinBtn.disabled = false;
                    say('<?php echo esc_js(mluc_ui_label('buy_net_error', __('Network error. Please try again later.', 'moonlight-user-center'))); ?>');
                });
        });
    }
})();
</script>
