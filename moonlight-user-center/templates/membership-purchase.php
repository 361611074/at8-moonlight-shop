<?php
/**
 * 独立会员购买卡片（仅装用户中心、未启用商城时显示）。
 *
 * @var array  $levels
 * @var array  $gateways
 * @var array  $orders
 * @var string $symbol
 * @var string $instructions
 * @var string $nonce
 *
 * @package Moonlight_User_Center
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mluc-buy">
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
                    <?php echo $validity > 0
                        ? sprintf(esc_html__('有效期 %d 天', 'moonlight-user-center'), $validity)
                        : esc_html__('永久有效', 'moonlight-user-center'); ?>
                </div>
                <?php if ($price > 0) : ?>
                    <div class="mluc-buy-actions">
                        <?php foreach ($gateways as $gid => $gtitle) : ?>
                            <label class="mluc-buy-gateway">
                                <input type="radio" name="mluc_buy_gateway_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($gid); ?>" <?php checked($gid, 'manual'); ?>>
                                <span><?php echo esc_html($gtitle); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="mluc-btn-buy" data-level="<?php echo esc_attr($key); ?>"><?php esc_html_e('購買', 'moonlight-user-center'); ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="mluc-buy-msg" role="alert"></p>
    <div class="mluc-buy-instructions">
        <strong><?php esc_html_e('付款說明', 'moonlight-user-center'); ?></strong>
        <p><?php echo esc_html($instructions); ?></p>
    </div>

    <?php if (!empty($orders)) : ?>
        <div class="mluc-order-list">
            <strong><?php esc_html_e('我的購買記錄', 'moonlight-user-center'); ?></strong>
            <table class="mluc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('訂單', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('等級', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('金額', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('狀態', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('日期', 'moonlight-user-center'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o) :
                        $status_labels = array(
                            'pending'   => __('待確認', 'moonlight-user-center'),
                            'paid'      => __('已收款', 'moonlight-user-center'),
                            'cancelled' => __('已取消', 'moonlight-user-center'),
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
<script>
(function () {
    var root = document.querySelector('.mluc-buy');
    if (!root) { return; }
    var msg = root.querySelector('.mluc-buy-msg');
    root.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.mluc-btn-buy') : null;
        if (!btn) { return; }
        e.preventDefault();
        var level = btn.getAttribute('data-level');
        var gwEl = root.querySelector('input[name="mluc_buy_gateway_' + level + '"]:checked');
        if (msg) { msg.textContent = ''; }
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'mluc_buy_level');
        fd.append('nonce', '<?php echo esc_js($nonce); ?>');
        fd.append('level', level);
        fd.append('gateway', gwEl ? gwEl.value : '');
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (msg) { msg.textContent = res.message || ''; }
            })
            .catch(function () {
                if (msg) { msg.textContent = '<?php echo esc_js(__('網絡異常，請稍後重試。', 'moonlight-user-center')); ?>'; }
            })
            .finally(function () { btn.disabled = false; });
    });
})();
</script>
