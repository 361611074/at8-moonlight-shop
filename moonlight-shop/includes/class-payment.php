<?php
/**
 * 支付管理器：网关注册、结算短代码、下单 AJAX。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Payment
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
        add_shortcode('mlshop_checkout', array($this, 'shortcode_checkout'));
        add_action('wp_ajax_mlshop_place_order', array($this, 'ajax_place_order'));
        add_action('wp_ajax_nopriv_mlshop_place_order', array($this, 'ajax_place_order'));
        add_action('init', array($this, 'maybe_handle_return'), 5);
    }

    /**
     * 获取已注册网关（可被其他插件扩展）。
     *
     * `mlshop_payment_gateways` filter 之后还会按「商城設定 → 前台公開支付方式」白名单
     * 再过滤一次。语义：
     *  - 管理员从未保存过（DB 为 NULL）→ 所有已注册网关联动可用（向后兼容 + 第三方扩展生效）；
     *  - 管理员保存过（包含空数组）→ 严格按白名单过滤；空数组意味着「全部关闭」。
     */
    public function get_gateways()
    {
        $gateways = array(
            new MLSHOP_Gateway_COD(),
            new MLSHOP_Gateway_Balance(),
            new MLSHOP_Gateway_Manual(),
            new MLSHOP_Gateway_Stripe(),
            new MLSHOP_Gateway_PayPal(),
        );
        $gateways = apply_filters('mlshop_payment_gateways', $gateways);
        $stored   = get_option('mlshop_enabled_gateways', null);
        if (null === $stored) {
            return $gateways;
        }
        $enabled_ids = $this->get_enabled_gateway_ids();
        $filtered = array();
        foreach ($gateways as $g) {
            if (in_array($g->get_id(), $enabled_ids, true)) {
                $filtered[] = $g;
            }
        }
        return $filtered;
    }

    /**
     * 当前允许前台公开的网关 ID 列表。
     *
     * 优先级：
     *  - 管理员从未保存过（DB 中为 NULL）→ 默认全开所有内建网关，向后兼容；
     *  - 管理员保存过（即使是空数组）→ 严格按保存值（含空数组 = 全部关闭）。
     *  - 非数组异常值 → 兜底默认全开。
     */
    public function get_enabled_gateway_ids()
    {
        $stored = get_option('mlshop_enabled_gateways', null);
        $builtin = array('cod', 'balance', 'manual', 'stripe', 'paypal');
        if (null === $stored) {
            return $builtin;
        }
        if (!is_array($stored)) {
            return $builtin;
        }
        $stored = array_values(array_filter(array_map('strval', $stored), function ($id) {
            return (bool) $id;
        }));
        return $stored;
    }

    public function get_gateway($id)
    {
        foreach ($this->get_gateways() as $g) {
            if ($g->get_id() === $id) {
                return $g;
            }
        }
        return null;
    }

    public function shortcode_checkout()
    {
        if (!is_user_logged_in()) {
            $login = function_exists('mluc_get_account_url') ? mluc_get_account_url() : wp_login_url(mlshop_get_page_url('checkout'));
            return '<p class="mlshop-message">' .
                sprintf(esc_html__('请先 %s 后再结算。', 'moonlight-shop'), '<a href="' . esc_url($login) . '">' . esc_html__('登录', 'moonlight-shop') . '</a>') .
                '</p>';
        }

        $order_id = isset($_GET['order']) ? (int) $_GET['order'] : 0;
        if ($order_id && get_post_type($order_id) === 'mlshop_order') {
            // 归属校验：仅订单所有者（或管理员）可查看订单详情，
            // 防止登录用户枚举 order ID 泄露他人卡密 / 下载链接 / 收货地址。
            if (!current_user_can('edit_posts')
                && (int) get_post_meta($order_id, '_mlshop_user_id', true) !== get_current_user_id()) {
                return '<p class="mlshop-message">' . esc_html__('无权查看该订单。', 'moonlight-shop') . '</p>';
            }
            MLSHOP_Order::maybe_expire($order_id);
            ob_start();
            mlshop_get_template('order', array(
                'order_id' => $order_id,
                'back_url' => mlshop_get_orders_url(),
            ));
            return ob_get_clean();
        }

        $cart = MLSHOP_Cart::get_instance();
        $items = $cart->get_items();
        if (empty($items)) {
            return '<p class="mlshop-message">' . esc_html__('购物车为空，请先选购商品。', 'moonlight-shop') . '</p>';
        }

        $subtotal    = $cart->get_total();
        $shipping    = $cart->get_shipping();
        $grand_total = round($subtotal + $shipping, 2);
        $has_physical = $cart->has_physical();

        ob_start();
        mlshop_get_template('checkout', array(
            'items'       => $items,
            'subtotal'    => $subtotal,
            'shipping'    => $shipping,
            'total'       => $grand_total,
            'has_physical' => $has_physical,
            'gateways'    => $this->get_gateways(),
        ));
        return ob_get_clean();
    }

    public function ajax_place_order()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mlshop_send_json(false, __('请先登录。', 'moonlight-shop'));
        }

        $gateway_id = isset($_POST['gateway']) ? sanitize_key($_POST['gateway']) : '';
        $gateway = $this->get_gateway($gateway_id);
        if (!$gateway) {
            mlshop_send_json(false, __('支付方式无效。', 'moonlight-shop'));
        }
        $enabled_ids = $this->get_enabled_gateway_ids();
        if (!in_array($gateway_id, $enabled_ids, true)) {
            mlshop_send_json(false, __('该支付方式暂未开放。', 'moonlight-shop'));
        }

        $coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field($_POST['coupon_code']) : '';

        // 收集收货地址（仅实物訂單需要）
        $shipping_address = array();
        if (isset($_POST['shipping_name'])) {
            $shipping_address = array(
                'name'    => sanitize_text_field($_POST['shipping_name']),
                'phone'   => sanitize_text_field($_POST['shipping_phone']),
                'address' => sanitize_textarea_field($_POST['shipping_address']),
                'note'    => isset($_POST['shipping_note']) ? sanitize_textarea_field($_POST['shipping_note']) : '',
            );
        }

        $order_id = MLSHOP_Order::create_from_cart(get_current_user_id(), $gateway_id, $coupon_code, $shipping_address);
        if (is_wp_error($order_id)) {
            mlshop_send_json(false, is_wp_error($order_id) ? $order_id->get_error_message() : __('下单失败。', 'moonlight-shop'));
        }

        $result = $gateway->process_payment($order_id);

        if (!empty($result['redirect'])) {
            $result['data'] = array('redirect' => $result['redirect']);
        }
        mlshop_send_json($result['success'], $result['message'], isset($result['data']) ? $result['data'] : array());
    }

    /**
     * 处理 PayPal / Stripe 回跳与 Stripe webhook。
     */
    public function maybe_handle_return()
    {
        // Stripe webhook
        if (isset($_GET['mlshop_stripe_webhook']) && '1' === $_GET['mlshop_stripe_webhook']) {
            $gateway = $this->get_gateway('stripe');
            if ($gateway && method_exists($gateway, 'handle_webhook')) {
                $gateway->handle_webhook();
            }
            exit;
        }
        // PayPal webhook（兜底：用户付款後未回跳也能標記訂單 paid）
        if (isset($_GET['mlshop_paypal_webhook']) && '1' === $_GET['mlshop_paypal_webhook']) {
            $gateway = $this->get_gateway('paypal');
            if ($gateway && method_exists($gateway, 'handle_webhook')) {
                $gateway->handle_webhook();
            }
            exit;
        }
        // PayPal 回跳：?mlshop_order=ID&gateway=paypal&action=capture&token=PAYPAL_ORDER_ID
        if (isset($_GET['mlshop_order'], $_GET['gateway']) && 'paypal' === $_GET['gateway'] && isset($_GET['action']) && 'capture' === $_GET['action']) {
            $order_id = (int) $_GET['mlshop_order'];
            if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
                return;
            }
            // 归属校验：仅订单所有者（或管理员）可触发 capture 回跳
            if (!current_user_can('edit_posts')
                && (int) get_post_meta($order_id, '_mlshop_user_id', true) !== get_current_user_id()) {
                wp_safe_redirect($this->order_url($order_id));
                exit;
            }
            $stored = (string) get_post_meta($order_id, '_mlshop_paypal_order', true);
            $token  = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
            // token 防伪：必须与下单时保存的 PayPal 单号一致，
            // 防止用他人（或自己另一笔小额）订单的 token 伪造 capture。
            if ($stored && $token && $stored !== $token) {
                wp_safe_redirect($this->order_url($order_id));
                exit;
            }
            $paypal_order_id = $stored ? $stored : $token;
            if (!$paypal_order_id) {
                return;
            }
            $gateway = $this->get_gateway('paypal');
            if (!$gateway || !method_exists($gateway, 'capture')) {
                return;
            }
            $result = $gateway->capture($paypal_order_id);
            if (!is_wp_error($result) && isset($result['status']) && 'COMPLETED' === $result['status']
                && $this->paypal_capture_matches($result, $order_id)) {
                $current = get_post_meta($order_id, '_mlshop_status', true);
                if (!in_array($current, array('paid', 'processing', 'completed', 'refunded'), true)) {
                    MLSHOP_Order::mark_paid($order_id, 'paypal', $paypal_order_id);
                }
            }
            wp_safe_redirect($this->order_url($order_id));
            exit;
        }
        // Stripe 回跳：?mlshop_order=ID&gateway=stripe&session_id=XXX
        if (isset($_GET['mlshop_order'], $_GET['gateway']) && 'stripe' === $_GET['gateway'] && isset($_GET['session_id'])) {
            $order_id = (int) $_GET['mlshop_order'];
            if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
                return;
            }
            // 归属校验：仅订单所有者（或管理员）可触发回跳确认
            if (!current_user_can('edit_posts')
                && (int) get_post_meta($order_id, '_mlshop_user_id', true) !== get_current_user_id()) {
                wp_safe_redirect($this->order_url($order_id));
                exit;
            }
            $session_id = sanitize_text_field($_GET['session_id']);
            $saved = get_post_meta($order_id, '_mlshop_stripe_session', true);
            if ($saved && $saved === $session_id) {
                // 等 webhook 标记 paid；这里做兜底：若 webhook 未到，主动查询 session 状态
                $this->maybe_confirm_stripe_session($order_id, $session_id);
            }
            wp_safe_redirect($this->order_url($order_id));
            exit;
        }
    }

    /**
     * 校验 PayPal capture 返回的收款与订单一致（金额 / 币种 / 订单号）。
     * 缺一不可：即使 PayPal 单号匹配，金额不符（如用小额订单冒充）也拒绝标记 paid。
     */
    private function paypal_capture_matches($result, $order_id)
    {
        if (empty($result['purchase_units'][0]['payments']['captures'][0])) {
            return false;
        }
        $cap = $result['purchase_units'][0]['payments']['captures'][0];
        $expect_total = number_format((float) get_post_meta($order_id, '_mlshop_total', true), 2, '.', '');
        $expect_cur   = strtoupper((string) mlshop_get_option('currency', 'HKD'));
        $amount = isset($cap['amount']['value']) ? (string) $cap['amount']['value'] : '';
        $cur    = isset($cap['amount']['currency_code']) ? strtoupper((string) $cap['amount']['currency_code']) : '';
        if ('' === $amount || $amount !== $expect_total || $cur !== $expect_cur) {
            return false;
        }
        // 下单时已写入 custom_id / invoice_id，回传不符视为冒用
        $custom  = isset($cap['custom_id']) ? (string) $cap['custom_id'] : '';
        $invoice = isset($cap['invoice_id']) ? (string) $cap['invoice_id'] : '';
        if ('' !== $custom && $custom !== (string) $order_id) {
            return false;
        }
        if ('' !== $invoice && $invoice !== 'ML-' . $order_id) {
            return false;
        }
        return true;
    }

    /**
     * 兜底：若 webhook 未及时到，主动查询 Stripe session 状态。
     */
    private function maybe_confirm_stripe_session($order_id, $session_id)
    {
        $secret = trim((string) mlshop_get_option($this->is_stripe_test_mode() ? 'stripe_test_secret' : 'stripe_secret', ''));
        if (!$secret) {
            return;
        }
        $response = wp_remote_get('https://api.stripe.com/v1/checkout/sessions/' . $session_id, array(
            'headers' => array('Authorization' => 'Bearer ' . $secret),
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            return;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['payment_status'])) {
            return;
        }
        if ('paid' === $data['payment_status']) {
            $current = get_post_meta($order_id, '_mlshop_status', true);
            if (!in_array($current, array('paid', 'processing', 'completed', 'refunded'), true)) {
                MLSHOP_Order::mark_paid($order_id, 'stripe', isset($data['payment_intent']) ? $data['payment_intent'] : '');
            }
        }
    }

    private function is_stripe_test_mode()
    {
        return (bool) mlshop_get_option('stripe_test_mode', 1);
    }

    private function order_url($order_id)
    {
        return mlshop_get_page_url('checkout') . '?order=' . $order_id;
    }
}
