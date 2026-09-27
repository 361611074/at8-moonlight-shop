<?php
/**
 * 收货地址簿（账户中心 Tab / [mlshop_address] 短代码共用）。
 *
 * @var array $addresses 当前用户地址列表（默认地址在前）
 */
if (!defined('ABSPATH')) {
    exit;
}
$addresses = isset($addresses) && is_array($addresses) ? $addresses : array();
?>
<div class="mlshop-addresses">
    <?php if (empty($addresses)) : ?>
        <p class="mlshop-message"><?php esc_html_e('您还没有保存收货地址，请在下方新增。', 'moonlight-shop'); ?></p>
    <?php else : ?>
        <ul class="mlshop-address-list">
            <?php foreach ($addresses as $a) :
                $region_text = '';
                if (!empty($a['province_name']) && !empty($a['city_name'])) {
                    $region_text = $a['province_name'] . ' ' . $a['city_name'];
                } elseif (class_exists('Moonlight_Region_Provider')) {
                    $r = Moonlight_Region_Provider::resolve($a['city']);
                    if ($r && isset($r['province_name'], $r['name'])) {
                        $region_text = $r['province_name'] . ' ' . $r['name'];
                    }
                }
                ?>
                <li class="mlshop-address-item<?php echo !empty($a['is_default']) ? ' is-default' : ''; ?>">
                    <div class="mlshop-address-info">
                        <strong><?php echo esc_html($a['name']); ?></strong>
                        <span class="mlshop-address-phone"><?php echo esc_html($a['phone']); ?></span>
                        <?php if (!empty($a['is_default'])) : ?>
                            <span class="mlshop-address-badge"><?php esc_html_e('默認', 'moonlight-shop'); ?></span>
                        <?php endif; ?>
                        <div class="mlshop-address-detail">
                            <?php echo esc_html(trim($region_text . ' ' . $a['detail'])); ?>
                        </div>
                    </div>
                    <div class="mlshop-address-actions">
                        <button type="button" class="mlshop-btn mlshop-addr-edit"
                            data-id="<?php echo esc_attr($a['id']); ?>"
                            data-name="<?php echo esc_attr($a['name']); ?>"
                            data-phone="<?php echo esc_attr($a['phone']); ?>"
                            data-province="<?php echo esc_attr($a['province']); ?>"
                            data-city="<?php echo esc_attr($a['city']); ?>"
                            data-detail="<?php echo esc_attr($a['detail']); ?>"
                            data-default="<?php echo !empty($a['is_default']) ? '1' : '0'; ?>"><?php esc_html_e('編輯', 'moonlight-shop'); ?></button>
                        <button type="button" class="mlshop-btn mlshop-addr-del" data-id="<?php echo esc_attr($a['id']); ?>"><?php esc_html_e('刪除', 'moonlight-shop'); ?></button>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form class="mlshop-address-form">
        <h3><?php esc_html_e('新增 / 編輯收貨地址', 'moonlight-shop'); ?></h3>
        <input type="hidden" name="id" class="mlshop-addr-id" value="">
        <p class="mlshop-msg mlshop-addr-msg" role="alert"></p>
        <div class="mlshop-field">
            <label for="mlshop_addr_name"><?php esc_html_e('收件人', 'moonlight-shop'); ?> <span class="required">*</span></label>
            <input type="text" id="mlshop_addr_name" class="mlshop-addr-name" maxlength="32" required>
        </div>
        <div class="mlshop-field">
            <label for="mlshop_addr_phone"><?php esc_html_e('聯絡電話', 'moonlight-shop'); ?> <span class="required">*</span></label>
            <input type="tel" id="mlshop_addr_phone" class="mlshop-addr-phone" required>
        </div>
        <div class="mlshop-field">
            <label><?php esc_html_e('所在省市', 'moonlight-shop'); ?> <span class="required">*</span></label>
            <?php mlshop_render_region_selects('mlshop_addr'); ?>
        </div>
        <div class="mlshop-field">
            <label for="mlshop_addr_detail"><?php esc_html_e('詳細地址', 'moonlight-shop'); ?> <span class="required">*</span></label>
            <textarea id="mlshop_addr_detail" class="mlshop-addr-detail" rows="2" maxlength="120" required></textarea>
        </div>
        <div class="mlshop-field">
            <label><input type="checkbox" class="mlshop-addr-default" value="1"> <?php esc_html_e('設為默認地址', 'moonlight-shop'); ?></label>
        </div>
        <button type="submit" class="mlshop-btn mlshop-addr-submit"><?php esc_html_e('保存地址', 'moonlight-shop'); ?></button>
    </form>
</div>
