<?php
/**
 * 购物车相关 AJAX。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Ajax
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
        add_action('wp_ajax_nopriv_mlshop_add_to_cart', array($this, 'add_to_cart'));
        add_action('wp_ajax_mlshop_add_to_cart', array($this, 'add_to_cart'));
        add_action('wp_ajax_nopriv_mlshop_update_cart', array($this, 'update_cart'));
        add_action('wp_ajax_mlshop_update_cart', array($this, 'update_cart'));
        add_action('wp_ajax_nopriv_mlshop_remove_cart', array($this, 'remove_cart'));
        add_action('wp_ajax_mlshop_remove_cart', array($this, 'remove_cart'));
        add_action('wp_ajax_nopriv_mlshop_apply_coupon', array($this, 'apply_coupon'));
        add_action('wp_ajax_mlshop_apply_coupon', array($this, 'apply_coupon'));
        add_action('wp_ajax_nopriv_mlshop_get_counts', array($this, 'get_counts'));
        add_action('wp_ajax_mlshop_get_counts', array($this, 'get_counts'));
        add_action('wp_ajax_nopriv_mlshop_get_favorites_widget_fragment', array($this, 'get_favorites_widget_fragment'));
        add_action('wp_ajax_mlshop_get_favorites_widget_fragment', array($this, 'get_favorites_widget_fragment'));
    }

    public function add_to_cart()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $qty       = isset($_POST['qty']) ? (int) $_POST['qty'] : 1;

        if (!$product_id || get_post_type($product_id) !== 'mlshop_product') {
            mlshop_send_json(false, __('商品不存在。', 'moonlight-shop'));
        }
        // 兜底 1 件；同时夹紧上限 999，避免异常大数把订单金额 / 页面撑爆。
        $qty = max(1, min(999, (int) $qty));

        $cart = MLSHOP_Cart::get_instance();

        // 库存校验：_mlshop_stock 为空或 0 视为不限量；已加入购物车的数量要一并计入。
        $stock = (int) get_post_meta($product_id, '_mlshop_stock', true);
        if ($stock > 0) {
            $in_cart = 0;
            foreach ($cart->get_items() as $it) {
                if (isset($it['id']) && (int) $it['id'] === $product_id) {
                    $in_cart = (int) $it['qty'];
                    break;
                }
            }
            if ($in_cart >= $stock) {
                mlshop_send_json(false, sprintf(__('库存不足，仅剩 %d 件。', 'moonlight-shop'), $stock));
            }
            if ($in_cart + $qty > $stock) {
                $qty = $stock - $in_cart; // 夹紧到剩余可购数量
            }
        }

        $cart->add_item($product_id, $qty);
        mlshop_send_json(true, __('已加入购物车。', 'moonlight-shop'), array('count' => $cart->get_count()));
    }

    public function update_cart()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $qty       = isset($_POST['qty']) ? (int) $_POST['qty'] : 0;
        if ($product_id) {
            $qty = max(0, min(999, (int) $qty));
            // 库存校验：购物车页改数量同样不能超卖（_mlshop_stock 为空/0 视为不限量）
            $stock = (int) get_post_meta($product_id, '_mlshop_stock', true);
            if ($stock > 0 && $qty > $stock) {
                $qty = $stock;
            }
            MLSHOP_Cart::get_instance()->set_qty($product_id, $qty);
        }
        mlshop_send_json(true, __('已更新。', 'moonlight-shop'));
    }

    public function remove_cart()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if ($product_id) {
            MLSHOP_Cart::get_instance()->remove_item($product_id);
        }
        mlshop_send_json(true, __('已移除。', 'moonlight-shop'));
    }

    /**
     * 校验优惠码并返回折扣金额（用于结算页实时预览）。
     */
    public function apply_coupon()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mlshop_send_json(false, __('请先登录。', 'moonlight-shop'));
        }
        if (!class_exists('MLSHOP_Coupon') || !class_exists('MLSHOP_Cart')) {
            mlshop_send_json(false, __('优惠模块不可用。', 'moonlight-shop'));
        }
        $code = isset($_POST['coupon_code']) ? sanitize_text_field($_POST['coupon_code']) : '';
        if ('' === $code) {
            mlshop_send_json(false, __('请输入优惠码。', 'moonlight-shop'));
        }
        $cart     = MLSHOP_Cart::get_instance();
        $items    = $cart->get_items();
        $subtotal = $cart->get_total();
        $cid = MLSHOP_Coupon::validate($code, $subtotal, $items);
        if (is_wp_error($cid)) {
            mlshop_send_json(false, $cid->get_error_message());
        }
        $discount = MLSHOP_Coupon::compute_discount($cid, $subtotal);
        $after    = round($subtotal - $discount, 2);
        // 優惠後重新計算運費（可能跌破免運門檻）
        $has_physical = MLSHOP_Shipping::has_physical($items);
        $shipping     = ($has_physical && MLSHOP_Shipping::enabled()) ? MLSHOP_Shipping::calc($after, true) : 0.0;
        $final        = round($after + $shipping, 2);
        mlshop_send_json(true, __('优惠码已应用。', 'moonlight-shop'), array(
            'code'          => strtoupper(trim($code)),
            'discount'      => $discount,
            'subtotal'      => $subtotal,
            'shipping'      => $shipping,
            'total'         => $final,
            'discount_text' => mlshop_format_price($discount),
            'shipping_text' => mlshop_format_price($shipping),
            'shipping_note' => $has_physical ? MLSHOP_Shipping::note($after) : '',
            'total_text'    => mlshop_format_price($final),
        ));
    }

    /**
     * 返回当前购物车 / 收藏数量，供页眉角标实时同步（加购、收藏、改数量后调用）。
     */
    public function get_counts()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        mlshop_send_json(true, '', array(
            'cart' => MLSHOP_Cart::get_instance()->get_count(),
            'fav'  => mlshop_favorite_count(),
        ));
    }

    /**
     * 返回侧栏「我的收藏」widget 当前 inner HTML（含 h2 标题+count+ul/empty）。
     * 让前端在 toggle_favorite 后无需刷新整页就能即时同步侧栏内容。
     */
    public function get_favorites_widget_fragment()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        if (!function_exists('mlshop_render_favorites_widget_inner')) {
            mlshop_send_json(false, __('收藏组件未加载。', 'moonlight-shop'));
        }
        mlshop_send_json(true, '', array(
            'html'  => mlshop_render_favorites_widget_inner(),
            'count' => mlshop_favorite_count(),
        ));
    }
}
