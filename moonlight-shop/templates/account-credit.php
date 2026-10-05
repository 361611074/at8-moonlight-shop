<?php
/**
 * 用户中心「积分余额」Tab：余额、充值、流水。
 *
 * @var float  $balance
 * @var string $credit_name
 * @var string $symbol
 * @var array  $ledger
 * @var array  $packages
 * @var float  $rate
 * @var array  $gateways
 */
if (!defined('ABSPATH')) {
    exit;
}
$checkin_on     = !empty($checkin_on) && class_exists('MLUC_Checkin');
$checkin_done   = !empty($checkin_done);
$checkin_streak = isset($checkin_streak) ? (int) $checkin_streak : 0;
$checkin_ajax   = isset($checkin_ajax) && is_array($checkin_ajax) ? $checkin_ajax : array();
?>
<div class="mlshop-credit">
    <?php if ($checkin_on) : ?>
    <div class="mlshop-credit-checkin" id="mlshop-credit-checkin">
        <h3 class="mlshop-credit-section-title"><?php esc_html_e('每日签到', 'moonlight-shop'); ?></h3>
        <p class="mlshop-credit-checkin-info">
            <span data-checkin-streak-text><?php
            /* translators: %d = 连续签到天数 */
            printf(esc_html__('已连续签到 %d 天', 'moonlight-shop'), $checkin_streak);
            ?></span>
            <button type="button" class="mlshop-btn mlshop-btn-primary" data-checkin-btn <?php disabled($checkin_done); ?>>
                <?php echo $checkin_done ? esc_html__('今日已签到', 'moonlight-shop') : esc_html__('立即签到', 'moonlight-shop'); ?>
            </button>
        </p>
        <span class="mlshop-msg mlshop-checkin-msg" role="alert"></span>
        <script>
        (function () {
            var box = document.getElementById('mlshop-credit-checkin');
            if (!box || box.dataset.bound) { return; }
            box.dataset.bound = '1';
            var btn = box.querySelector('[data-checkin-btn]');
            var msg = box.querySelector('.mlshop-checkin-msg');
            var streakEl = box.querySelector('[data-checkin-streak-text]');
            btn.addEventListener('click', function () {
                btn.disabled = true;
                msg.textContent = '';
                var fd = new FormData();
                fd.append('action', 'mluc_checkin');
                fd.append('nonce', <?php echo wp_json_encode(isset($checkin_ajax['nonce']) ? $checkin_ajax['nonce'] : ''); ?>);
                fetch(<?php echo wp_json_encode(isset($checkin_ajax['ajax_url']) ? $checkin_ajax['ajax_url'] : admin_url('admin-ajax.php')); ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                }).then(function (r) { return r.json(); }).then(function (j) {
                    msg.textContent = j.message || '';
                    if (j && j.success) {
                        btn.textContent = <?php echo wp_json_encode(__('今日已签到', 'moonlight-shop')); ?>;
                        var bal = document.querySelector('.mlshop-credit-balance-value');
                        if (bal && j.data && typeof j.data.balance !== 'undefined') { bal.textContent = j.data.balance; }
                        if (streakEl && j.data && typeof j.data.streak !== 'undefined') {
                            /* translators: %1$$d: 数量, %2$$d: 数量 */
                            streakEl.textContent = <?php echo wp_json_encode(__('已连续签到 %1$d 天', 'moonlight-shop')); ?>.replace('%2$d', j.data.streak);
                        }
                    } else if (j && j.message && -1 !== j.message.indexOf('已经签到')) {
                        btn.textContent = <?php echo wp_json_encode(__('今日已签到', 'moonlight-shop')); ?>;
                        btn.disabled = true;
                    } else {
                        btn.disabled = false;
                    }
                }).catch(function () {
                    msg.textContent = <?php echo wp_json_encode(__('网络异常，请重试。', 'moonlight-shop')); ?>;
                    btn.disabled = false;
                });
            });
        })();
        </script>
    </div>
    <?php endif; ?>

    <div class="mlshop-credit-balance">
        <span class="mlshop-credit-balance-label"><?php echo esc_html($credit_name); ?><?php esc_html_e('余额', 'moonlight-shop'); ?></span>
        <span class="mlshop-credit-balance-value"><?php echo esc_html($balance); ?></span>
    </div>

    <div class="mlshop-credit-recharge">
        <h3 class="mlshop-credit-section-title"><?php esc_html_e('积分充值', 'moonlight-shop'); ?></h3>
        <form class="mlshop-recharge-form" method="post">
            <div class="mlshop-recharge-packages">
                <?php foreach ($packages as $i => $pkg) :
                    $pkg_rate = $pkg['price'] > 0 ? round($pkg['credit'] / $pkg['price'], 2) : 0;
                    ?>
                    <label class="mlshop-recharge-package">
                        <input type="radio" name="mlshop_recharge_pkg" value="<?php echo (int) $i; ?>" <?php checked(0, $i); ?>>
                        <span class="mlshop-recharge-pkg-credit"><?php echo esc_html($pkg['credit']); ?> <?php echo esc_html($credit_name); ?></span>
                        <span class="mlshop-recharge-pkg-price"><?php echo esc_html($symbol . number_format($pkg['price'], 2)); ?></span>
                        <?php if ($pkg_rate > 0 && $pkg_rate !== (float) $rate) : ?>
                            <span class="mlshop-recharge-pkg-rate"><?php
                            /* translators: %1$s = 该套餐隐含汇率, %2$s = 积分名称 */
                            printf(esc_html__('按 %1$s %2$s / 货币单位', 'moonlight-shop'), esc_html($pkg_rate), esc_html($credit_name));
                            ?></span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <label class="mlshop-recharge-package mlshop-recharge-package-custom">
                    <input type="radio" name="mlshop_recharge_pkg" value="custom">
                    <span class="mlshop-recharge-pkg-credit"><?php esc_html_e('自定义', 'moonlight-shop'); ?></span>
                </label>
            </div>

            <div class="mlshop-recharge-custom" style="display:none;">
                <input type="number" name="mlshop_recharge_custom" min="1" step="1" placeholder="<?php esc_attr_e('输入积分数', 'moonlight-shop'); ?>" class="mlshop-recharge-custom-input">
                <span class="mlshop-recharge-custom-hint" data-rate="<?php echo esc_attr($rate); ?>">
                    <?php
                    /* translators: %1$s = 汇率, %2$s = 积分名称 */
                    printf(esc_html__('按 %1$s %2$s / 1 货币单位计算', 'moonlight-shop'), esc_html($rate), esc_html($credit_name));
                    ?>
                </span>
                <span class="mlshop-recharge-custom-price"></span>
            </div>

            <?php if (!empty($gateways)) : ?>
                <div class="mlshop-recharge-gateway">
                    <label for="mlshop_recharge_gateway"><?php esc_html_e('支付方式', 'moonlight-shop'); ?></label>
                    <select name="mlshop_recharge_gateway" id="mlshop_recharge_gateway" class="mlshop-recharge-gateway-select">
                        <?php foreach ($gateways as $g) : ?>
                            <option value="<?php echo esc_attr($g->get_id()); ?>"><?php echo esc_html($g->get_label()); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <button type="submit" class="mlshop-btn mlshop-recharge-submit"><?php esc_html_e('去充值', 'moonlight-shop'); ?></button>
            <span class="mlshop-msg mlshop-recharge-msg"></span>
        </form>
    </div>

    <div class="mlshop-credit-ledger">
        <h3 class="mlshop-credit-section-title"><?php esc_html_e('积分流水', 'moonlight-shop'); ?></h3>
        <?php if (!empty($ledger)) : ?>
            <div class="mlshop-table-scroll">
            <table class="mlshop-credit-ledger-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('时间', 'moonlight-shop'); ?></th>
                        <th><?php esc_html_e('变动', 'moonlight-shop'); ?></th>
                        <th><?php esc_html_e('余额', 'moonlight-shop'); ?></th>
                        <th><?php esc_html_e('说明', 'moonlight-shop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledger as $row) :
                        $delta = (float) $row['delta'];
                        $cls   = $delta >= 0 ? 'mlshop-credit-plus' : 'mlshop-credit-minus';
                        $sign  = $delta >= 0 ? '+' : '';
                    ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), (int) $row['time'])); ?></td>
                            <td class="<?php echo esc_attr($cls); ?>"><?php echo esc_html($sign . $delta); ?></td>
                            <td><?php echo esc_html($row['balance']); ?></td>
                            <td><?php echo esc_html($row['note']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else : ?>
            <p class="mlshop-credit-empty"><?php esc_html_e('暂无积分流水。', 'moonlight-shop'); ?></p>
        <?php endif; ?>
    </div>
</div>
