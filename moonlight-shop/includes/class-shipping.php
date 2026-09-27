<?php
/**
 * 实物商品运费计算与实物订单状态流转。
 *
 * 规则（后台「商城設定 → 運費設定」可配置，默认值即用户需求）：
 *   - 啟用後，購物車含任意實物商品才會計算運費；
 *   - 商品小計 ≥ 滿額門檻（默認 400）免運費；
 *   - 否則收取固定運費（默認 50，順豐速運）。
 *
 * 付款完成後，含實物商品的訂單由 paid 自動轉為 processing（待發貨），
 * 由管理員確認出貨後標記為已完成。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Shipping
{
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // 付款完成後，將含實物商品的訂單轉為「處理中（待發貨）」
        add_action('mlshop_order_paid', array($this, 'transition_physical'), 40);
    }

    /**
     * 是否啟用運費。
     */
    public static function enabled()
    {
        return (bool) mlshop_get_option('shipping_enabled', 1);
    }

    /**
     * 滿額包郵門檻（訂單商品小計）。
     */
    public static function free_threshold()
    {
        return (float) mlshop_get_option('shipping_free_threshold', 400);
    }

    /**
     * 固定運費。
     */
    public static function flat_rate()
    {
        return (float) mlshop_get_option('shipping_flat_rate', 50);
    }

    /**
     * 承運商名稱（如「順豐速運」）。
     */
    public static function carrier()
    {
        $name = (string) mlshop_get_option('shipping_carrier', '順豐速運');
        return $name !== '' ? $name : __('順豐速運', 'moonlight-shop');
    }

    /**
     * 購物車/訂單條目是否含實物商品。
     *
     * @param array $items MLSHOP_Cart::get_items() 或 _mlshop_items 結構
     */
    public static function has_physical($items)
    {
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $it) {
            $pid = isset($it['id']) ? (int) $it['id'] : 0;
            if (!$pid) {
                continue;
            }
            $type = get_post_meta($pid, '_mlshop_type', true);
            // 未設定類型視為實物，避免漏算運費
            if ('' === $type || 'physical' === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * 計算運費。
     *
     * @param float $subtotal      商品小計（優惠後）
     * @param bool  $has_physical  購物車是否含實物商品
     * @return float
     */
    public static function calc($subtotal, $has_physical)
    {
        if (!self::enabled() || !$has_physical) {
            return 0.0;
        }
        if ($subtotal >= self::free_threshold()) {
            return 0.0;
        }
        return (float) self::flat_rate();
    }

    /**
     * 運費說明文案（用於前端提示與郵件）。
     *
     * @param float $subtotal 商品小計
     */
    public static function note($subtotal)
    {
        $threshold = self::free_threshold();
        $carrier   = self::carrier();
        if ($subtotal >= $threshold) {
            return sprintf(
                /* translators: %1$s 承運商, %2$s 門檻金額 */
                __('已滿 %2$s，享 %1$s 免運費。', 'moonlight-shop'),
                esc_html($carrier),
                mlshop_format_price($threshold)
            );
        }
        return sprintf(
            /* translators: %1$s 承運商, %2$s 運費, %3$s 滿額門檻 */
            __('未滿 %3$s，需加收 %1$s 運費 %2$s。', 'moonlight-shop'),
            esc_html($carrier),
            mlshop_format_price(self::flat_rate()),
            mlshop_format_price($threshold)
        );
    }

    /**
     * 付款完成後：含實物商品的訂單轉為處理中（待發貨）。
     *
     * @param int $order_id
     */
    public function transition_physical($order_id)
    {
        if (!class_exists('MLSHOP_Order')) {
            return;
        }
        $has_physical = (bool) get_post_meta($order_id, '_mlshop_has_physical', true);
        if (!$has_physical) {
            return;
        }
        if ('paid' === MLSHOP_Order::get_status($order_id)) {
            MLSHOP_Order::mark_processing($order_id);
        }
    }
}
