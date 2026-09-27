<?php
/**
 * 统一价格计算器（计划书第十六节：禁止 cart/checkout/order 各自计算）。
 *
 * 所有取价入口最终汇聚到这里：
 *  - 付费内容金钱价 / 积分价（原 MLSHOP_Pay_Access 与 MLSHOP_Order::create_for_paywall
 *    的两份同源逻辑收敛为一份）；
 *  - 商品基础价（带 moonlight_product_price 过滤器，供第三方改价）；
 *  - 购物车报价（小计 → 优惠券 → 运费 → 合计；优惠券预留动作独立出来，保证报价纯函数）。
 *
 * 兼容策略：MLSHOP_Pay_Access::get_price_for_user 等旧入口改为委托本类，行为不变。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Price_Calculator
{
    /**
     * 解析用户的会员等级 key（free / gold / diamond / 后台自定义）。
     *
     * @param int $user_id
     * @return string
     */
    public static function member_level($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ($user_id && class_exists('MLUC_Membership')) {
            $level = MLUC_Membership::get_user_level($user_id);
            return $level ? (string) $level : 'free';
        }
        return 'free';
    }

    /**
     * 等级价解析：diamond 价 > gold 价 > 执行价（价 >0 才生效，否则回退）。
     *
     * @param float $sell    执行价（原价）
     * @param float $gold    gold 档价
     * @param float $diamond diamond 档价
     * @param string $level  会员等级 key
     * @return float
     */
    public static function tier_price($sell, $gold, $diamond, $level)
    {
        $sell    = (float) $sell;
        $gold    = (float) $gold;
        $diamond = (float) $diamond;
        $level   = (string) $level;

        $price = $sell;
        if ('diamond' === $level && $diamond > 0) {
            $price = $diamond;
        } elseif (in_array($level, array('gold', 'diamond'), true) && $gold > 0) {
            $price = $gold;
        }

        /**
         * 统一取价过滤器：第三方可按商品/用户上下文改价（计划书第五十七节）。
         *
         * @param float  $price  最终价
         * @param array  $ctx    ['kind'=>money|credit, 'level'=>..., 'sell'=>..., 'gold'=>..., 'diamond'=>...]
         */
        return (float) apply_filters('moonlight_tier_price', $price, compact('sell', 'gold', 'diamond', 'level'));
    }

    /**
     * 商品基础价（当前用户视角，预留会员价接入点）。
     *
     * @param int $product_id
     * @param int $user_id
     * @return float
     */
    public static function product_price($product_id, $user_id = 0)
    {
        $price = (float) get_post_meta((int) $product_id, '_mlshop_price', true);
        return (float) apply_filters(
            'moonlight_product_price',
            $price,
            get_post((int) $product_id),
            self::member_level($user_id)
        );
    }

    /**
     * 付费内容金钱价（会员等级价，原 get_price_for_user 同源逻辑的唯一实现）。
     *
     * @param int $post_id 商品/文章/页面 ID
     * @param int $user_id
     * @return float
     */
    public static function paywall_price($post_id, $user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $level   = self::member_level($user_id);
        $sell    = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'price_sell', 0);
        $gold    = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'price_gold', 0);
        $diamond = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'price_diamond', 0);
        return self::tier_price($sell, $gold, $diamond, $level);
    }

    /**
     * 付费内容积分价（会员等级价）。
     */
    public static function paywall_credit_price($post_id, $user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $level   = self::member_level($user_id);
        $sell    = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'credit_price', 0);
        $gold    = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'credit_price_gold', 0);
        $diamond = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'credit_price_diamond', 0);
        return self::tier_price($sell, $gold, $diamond, $level);
    }

    /**
     * 购物车报价（纯计算，不产生副作用；优惠券预留由 reserve_coupon() 单独执行）。
     *
     * 计算序列（计划书第十六节）：商品小计 → 优惠券折扣 → 运费 → 合计。
     * 运费按「优惠前小计」判免邮门槛（与结算页展示一致的历史取舍，见 order.php 注释）。
     *
     * @param array  $items       Cart::get_items() 结构
     * @param string $coupon_code 优惠码（可空）
     * @param array  $args        ['free_shipping_override'=>bool]
     * @return array{subtotal:float,discount:float,coupon_id:int,coupon_valid:bool,shipping:float,has_physical:bool,total:float}
     */
    public static function quote($items, $coupon_code = '', $args = array())
    {
        $subtotal = 0.0;
        foreach ((array) $items as $it) {
            $subtotal += isset($it['subtotal']) ? (float) $it['subtotal'] : ((float) $it['price'] * (int) $it['qty']);
        }
        $subtotal = round($subtotal, 2);

        $discount    = 0.0;
        $coupon_id   = 0;
        $coupon_ok   = false;
        $coupon_code = trim((string) $coupon_code);
        if ('' !== $coupon_code && class_exists('MLSHOP_Coupon')) {
            $cid = MLSHOP_Coupon::validate($coupon_code, $subtotal, $items);
            if (!is_wp_error($cid)) {
                $d = (float) MLSHOP_Coupon::compute_discount($cid, $subtotal);
                if ($d > 0) {
                    $discount  = $d;
                    $coupon_id = (int) $cid;
                    $coupon_ok = true;
                }
            }
        }

        $has_physical = MLSHOP_Shipping::has_physical($items);
        $shipping     = 0.0;
        if ($has_physical && MLSHOP_Shipping::enabled()) {
            $shipping = (float) MLSHOP_Shipping::calc($subtotal, true);
        }

        $total = round(max(0, $subtotal - $discount) + $shipping, 2);

        return array(
            'subtotal'     => $subtotal,
            'discount'     => round($discount, 2),
            'coupon_id'    => $coupon_id,
            'coupon_valid' => $coupon_ok,
            'shipping'     => round($shipping, 2),
            'has_physical' => $has_physical,
            'total'        => $total,
        );
    }

    /**
     * 原子预留优惠券名额（与报价分离，供下单侧在报价通过后调用）。
     *
     * @param int $coupon_id
     * @return bool
     */
    public static function reserve_coupon($coupon_id)
    {
        return class_exists('MLSHOP_Coupon') ? (bool) MLSHOP_Coupon::reserve($coupon_id) : false;
    }
}
