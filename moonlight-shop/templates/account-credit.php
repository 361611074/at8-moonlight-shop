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
?>
<div class="mlshop-credit">
    <div class="mlshop-credit-balance">
        <span class="mlshop-credit-balance-label"><?php echo esc_html($credit_name); ?><?php esc_html_e('余额', 'moonlight-shop'); ?></span>
        <span class="mlshop-credit-balance-value"><?php echo esc_html($balance); ?></span>
    </div>

    <div class="mlshop-credit-recharge">
        <h3 class="mlshop-credit-section-title"><?php esc_html_e('积分充值', 'moonlight-shop'); ?></h3>
        <form class="mlshop-recharge-form" method="post">
            <div class="mlshop-recharge-packages">
                <?php foreach ($packages as $i => $pkg) : ?>
                    <label class="mlshop-recharge-package">
                        <input type="radio" name="mlshop_recharge_pkg" value="<?php echo (int) $i; ?>" <?php checked(0, $i); ?>>
                        <span class="mlshop-recharge-pkg-credit"><?php echo esc_html($pkg['credit']); ?> <?php echo esc_html($credit_name); ?></span>
                        <span class="mlshop-recharge-pkg-price"><?php echo esc_html($symbol . number_format($pkg['price'], 2)); ?></span>
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
                    /* translators: %s = 汇率 */
                    printf(esc_html__('按 %s %s / 1 货币单位计算', 'moonlight-shop'), esc_html($rate), esc_html($credit_name));
                    ?>
                </span>
                <span class="mlshop-recharge-custom-price"></span>
            </div>

            <?php if (!empty($gateways)) : ?>
                <div class="mlshop-recharge-gateway">
                    <label for="mlshop_recharge_gateway"><?php esc_html_e('支付方式', 'moonlight-shop'); ?></label>
                    <select name="mlshop_recharge_gateway" id="mlshop_recharge_gateway" class="mlshop-recharge-gateway-select">
                        <?php foreach ($gateways as $g) : ?>
                            <option value="<?php echo esc_attr($g->get_id()); ?>"><?php echo esc_html($g->get_title()); ?></option>
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
