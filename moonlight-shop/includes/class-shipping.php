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
     * 到店自提是否開啟（設置頁「運費設定」）。
     *
     * 開啟後結算頁實物訂單可選「到店自提」：免運費，地址表單收起為提貨人姓名 + 手機號。
     */
    public static function pickup_enabled()
    {
        return (bool) mlshop_get_option('pickup_enabled', 0);
    }

    /**
     * 運費模板列表。
     *
     * 存儲：option `moonlight_shipping_templates` = 數組 of
     *   ['id','name','mode'=>'fixed'|'piece','flat_fee','free_threshold','first_item_fee','extra_item_fee']
     * 空數組 = 未配置，全站回退「全局固定運費 + 滿額包郵」（完全向後兼容）。
     *
     * @return array
     */
    public static function templates()
    {
        $templates = get_option('moonlight_shipping_templates', array());
        $templates = is_array($templates) ? $templates : array();
        /**
         * 替換 / 擴展運費模板（與 moonlight_regions 同風格的數據源過濾器）。
         *
         * @param array $templates
         */
        $filtered = apply_filters('moonlight_shipping_templates', $templates);
        return is_array($filtered) ? $filtered : $templates;
    }

    /**
     * 按 id 查找模板。
     *
     * @param array  $templates
     * @param string $id
     * @return array|null
     */
    public static function find_template($templates, $id)
    {
        $id = (string) $id;
        if ('' === $id || !is_array($templates)) {
            return null;
        }
        foreach ($templates as $tpl) {
            if (is_array($tpl) && isset($tpl['id']) && (string) $tpl['id'] === $id) {
                return $tpl;
            }
        }
        return null;
    }

    /**
     * 按購物車條目計運費：模板感知的統一入口。
     *
     * 有任何商品掛了運費模板 → 走 template_calc（掛模板的逐商品按模板計費，
     * 未掛模板的合併後仍按全局規則計一次）；否則走原全局 calc()。
     *
     * @param array $items    Cart::get_items() 結構
     * @param float $subtotal 商品小計（用於全局規則的滿額門檻判斷）
     * @return float
     */
    public static function calc_for_items($items, $subtotal)
    {
        if (!self::enabled() || !self::has_physical($items)) {
            return 0.0;
        }
        $templates = self::templates();
        if (!empty($templates)) {
            return (float) self::template_calc($items, $templates);
        }
        return (float) self::calc($subtotal, true);
    }

    /**
     * 運費模板計價引擎：逐商品按其模板計費求和。
     *
     * 規則（free_threshold 僅在 > 0 時生效，0/空 = 不享受免郵）：
     *   - fixed：該商品小計達其 free_threshold → 免運費；否則收 flat_fee；
     *   - piece：該商品小計達其 free_threshold → 免運費；
     *     否則 first_item_fee + (qty - 1) * extra_item_fee（按該商品自身件數）。
     *   - 未掛模板 / 模板已刪除的商品：小計累加後按「全局固定運費 + 滿額包郵」
     *     統一計一次（避免雙重收費）。
     *
     * @param array $items     Cart::get_items() 結構（需含 id / qty / subtotal）
     * @param array $templates self::templates() 結構
     * @return float
     */
    public static function template_calc($items, $templates)
    {
        $fee  = 0.0;
        $rest = 0.0; // 未掛模板商品的小計，走全局規則
        foreach ((array) $items as $it) {
            $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
            $sub = isset($it['subtotal'])
                ? (float) $it['subtotal']
                : ((float) (isset($it['price']) ? $it['price'] : 0) * $qty);
            $pid = isset($it['id']) ? (int) $it['id'] : 0;
            $tid = $pid ? (string) get_post_meta($pid, '_mlshop_shipping_template', true) : '';
            $tpl = self::find_template($templates, $tid);
            if (!$tpl) {
                $rest += $sub;
                continue;
            }
            $threshold = isset($tpl['free_threshold']) ? (float) $tpl['free_threshold'] : 0.0;
            if ($threshold > 0 && $sub >= $threshold) {
                continue; // 達到該模板的免郵門檻，該商品免運費
            }
            $mode = isset($tpl['mode']) ? (string) $tpl['mode'] : 'fixed';
            if ('piece' === $mode) {
                if ($qty <= 0) {
                    continue;
                }
                $first = isset($tpl['first_item_fee']) ? (float) $tpl['first_item_fee'] : 0.0;
                $extra = isset($tpl['extra_item_fee']) ? (float) $tpl['extra_item_fee'] : 0.0;
                $fee += $first + ($qty - 1) * $extra;
            } else {
                $fee += isset($tpl['flat_fee']) ? (float) $tpl['flat_fee'] : 0.0;
            }
        }
        if ($rest > 0) {
            $fee += (float) self::calc($rest, true);
        }
        return round($fee, 2);
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
