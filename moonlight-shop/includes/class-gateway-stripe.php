<?php
/**
 * Stripe 支付网关（基于 Stripe Checkout Session）。
 *
 * 下单：服务端创建 Checkout Session → 重定向用户至 Stripe 托管页。
 * 回跳：webhook 监听 checkout.session.completed → 标记订单 paid → 触发 mlshop_order_paid。
 *
 * 后台配置：Publishable key / Secret key / Webhook secret / 启用测试模式。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_Stripe extends MLSHOP_Gateway
{
    /** Stripe API 基础地址 */
    const API_BASE_LIVE    = 'https://api.stripe.com/v1/';
    const API_BASE_TEST    = 'https://api.stripe.com/v1/'; // 同地址，用 test mode key 区分

    public function get_id()
    {
        return 'stripe';
    }

    public function get_title()
    {
        return __('信用卡 / Apple Pay / Google Pay（Stripe）', 'moonlight-shop');
    }

    public function get_description()
    {
        return __('透過 Stripe 安全的支付頁面以信用卡、Apple Pay 或 Google Pay 完成付款。', 'moonlight-shop');
    }

    private function is_test_mode()
    {
        return (bool) mlshop_get_option('stripe_test_mode', 1);
    }

    private function get_secret_key()
    {
        if ($this->is_test_mode()) {
            return trim((string) mlshop_get_option('stripe_test_secret', ''));
        }
        return trim((string) mlshop_get_option('stripe_secret', ''));
    }

    private function get_publishable_key()
    {
        if ($this->is_test_mode()) {
            return trim((string) mlshop_get_option('stripe_test_publishable', ''));
        }
        return trim((string) mlshop_get_option('stripe_publishable', ''));
    }

    /**
     * 下单处理：创建 Stripe Checkout Session 并返回跳转 URL。
     */
    public function process_payment($order_id)
    {
        $secret = $this->get_secret_key();
        if (!$secret) {
            return array(
                'success'   => false,
                'message'   => __('Stripe 尚未配置，請聯絡管理員。', 'moonlight-shop'),
                'redirect'  => '',
            );
        }

        $order = get_post($order_id);
        if (!$order) {
            return array('success' => false, 'message' => __('訂單不存在。', 'moonlight-shop'), 'redirect' => '');
        }

        $total   = (float) get_post_meta($order_id, '_mlshop_total', true);
        $subtotal = (float) get_post_meta($order_id, '_mlshop_subtotal', true);
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $user    = get_userdata($user_id);
        $email   = $user ? $user->user_email : '';
        $items   = (array) get_post_meta($order_id, '_mlshop_items', true);
        $shipping = (float) get_post_meta($order_id, '_mlshop_shipping', true);

        // 构造 line_items（按商品拆分）
        $line_items = array();
        foreach ($items as $it) {
            $title = get_the_title((int) $it['id']);
            $line_items[] = array(
                'price_data' => array(
                    'currency'     => strtolower(mlshop_get_option('currency', 'HKD')),
                    'unit_amount'  => (int) round(((float) $it['price']) * 100),
                    'product_data' => array(
                        'name' => $title ? $title : __('商品', 'moonlight-shop'),
                    ),
                ),
                'quantity'   => max(1, (int) $it['qty']),
            );
        }

        // 優惠券折扣攤提至商品 line_items，確保 Stripe 實收金額與訂單 _mlshop_total 一致
        $discount = round($subtotal - ($total - $shipping), 2);
        if ($discount > 0 && !empty($line_items)) {
            $remaining = (int) round($discount * 100);
            foreach ($line_items as &$li) {
                if ($remaining <= 0) {
                    break;
                }
                $li_cents = $li['price_data']['unit_amount'] * $li['quantity'];
                $reduce   = min($remaining, $li_cents);
                if ($li['quantity'] > 0) {
                    $li['price_data']['unit_amount'] = (int) round(($li_cents - $reduce) / $li['quantity']);
                }
                $remaining -= $reduce;
            }
            unset($li);
        }

        // 实物商品運費作為獨立 line_item 計入實收金額
        if ($shipping > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency'     => strtolower(mlshop_get_option('currency', 'HKD')),
                    'unit_amount'  => (int) round($shipping * 100),
                    'product_data' => array(
                        'name' => MLSHOP_Shipping::carrier() . ' ' . __('運費', 'moonlight-shop'),
                    ),
                ),
                'quantity'   => 1,
            );
        }

        $return_args = array(
            'mlshop_order' => $order_id,
            'gateway'      => 'stripe',
            'session_id'   => '{CHECKOUT_SESSION_ID}',
        );
        // 游客订单回跳携带访问令牌（回跳授权校验用）
        $gt = (string) get_post_meta($order_id, '_mlshop_guest_token', true);
        if ('' !== $gt) {
            $return_args['mlshop_gt'] = $gt;
        }
        $return_url = add_query_arg($return_args, home_url('/'));

        $params = array(
            'mode'                => 'payment',
            'payment_method_types'=> array('card'),
            'line_items'          => $line_items,
            'success_url'         => $return_url,
            'cancel_url'          => $this->order_url($order_id),
            'client_reference_id' => (string) $order_id,
            'metadata'            => array(
                'order_id' => (string) $order_id,
            ),
        );
        if ($email) {
            $params['customer_email'] = $email;
        }

        $response = $this->api_request('checkout/sessions', $params, $secret);

        if (is_wp_error($response)) {
            return array(
                'success'  => false,
                'message'  => $response->get_error_message(),
                'redirect' => '',
            );
        }
        if (empty($response['id']) || empty($response['url'])) {
            return array(
                'success'  => false,
                'message'  => __('Stripe 創建會話失敗，請稍後重試。', 'moonlight-shop'),
                'redirect' => '',
            );
        }

        // 保存 session id 供 webhook 比对
        update_post_meta($order_id, '_mlshop_stripe_session', $response['id']);

        return array(
            'success'  => true,
            'message'  => __('正在跳轉至 Stripe 支付頁面…', 'moonlight-shop'),
            'redirect' => $response['url'],
        );
    }

    /**
     * Stripe API 请求（application/x-www-form-urlencoded）。
     *
     * @return array|WP_Error
     */
    private function api_request($endpoint, $params, $secret_key)
    {
        $body = http_build_query($params);
        $response = wp_remote_post(self::API_BASE_LIVE . $endpoint, array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            /* translators: %d: 数量 */
            $msg = isset($data['error']['message']) ? $data['error']['message'] : sprintf(__('Stripe 錯誤（HTTP %d）', 'moonlight-shop'), $code);
            return new WP_Error('mlshop_stripe_api', $msg);
        }
        return $data;
    }

    /**
     * 处理 Stripe 异步通知：标记订单 paid。
     */
    public function handle_webhook()
    {
        $payload   = file_get_contents('php://input');
        $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        $secret    = trim((string) mlshop_get_option('stripe_webhook_secret', ''));
        if (!$secret) {
            status_header(400);
            exit;
        }
        $event = $this->verify_webhook_signature($payload, $sig_header, $secret);
        if (is_wp_error($event)) {
            status_header(400);
            echo $event->get_error_message();
            exit;
        }
        if (!isset($event['type']) || 'checkout.session.completed' !== $event['type']) {
            status_header(200);
            exit;
        }
        $session = $event['data']['object'];
        $order_id = isset($session['client_reference_id']) ? (int) $session['client_reference_id'] : 0;
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            status_header(200);
            exit;
        }
        // 金额复核（对齐计划书第二十三节「必须验证金额」）：session 金额（最小单位）
        // 必须与本地订单总额一致才标记 paid；不符则拒绝并留痕，回 200 避免 Stripe 无限重试。
        $amount_total = isset($session['amount_total']) ? (int) $session['amount_total'] : -1;
        $expect_minor = self::to_minor_units((float) get_post_meta($order_id, '_mlshop_total', true));
        if ($amount_total < 0 || $amount_total !== $expect_minor) {
            update_post_meta($order_id, '_mlshop_pay_amount_mismatch', sprintf('stripe:%s', (string) $amount_total));
            status_header(200);
            exit;
        }
        $current = get_post_meta($order_id, '_mlshop_status', true);
        if ('paid' === $current || in_array($current, array('processing', 'completed', 'refunded'), true)) {
            status_header(200);
            exit;
        }
        $payment_intent = isset($session['payment_intent']) ? $session['payment_intent'] : '';
        MLSHOP_Order::mark_paid($order_id, 'stripe', $payment_intent);
        status_header(200);
        echo 'OK';
        exit;
    }

    /**
     * 金额 → 最小单位（分）。HKD/USD 等两位小数货币 ×100；JPY/KRW 零小数货币 ×1。
     */
    public static function to_minor_units($amount)
    {
        $zero_decimal = in_array(strtoupper((string) mlshop_get_option('currency', 'HKD')), array('JPY', 'KRW'), true);
        return (int) round((float) $amount * ($zero_decimal ? 1 : 100));
    }

    /**
     * Stripe 退款（Refunds API）。
     *
     * 依据订单 `_mlshop_payment_id`（payment_intent，由 webhook 完单时写入）发起：
     *   POST https://api.stripe.com/v1/refunds
     *   body: payment_intent + reason=requested_by_customer（+ amount=最小单位，仅部分退款）
     * 全额退款省略 amount（Stripe 默认退回全部可退金额）；部分退款传
     * to_minor_units($amount)。密钥读取沿用本类现有模式（test/live）。
     *
     * @param int    $order_id 订单 ID。
     * @param float  $amount   退款金额（0 = 全额退）。
     * @param string $reason   退款原因（当前仅留本地日志用途；API 固定 reason=requested_by_customer）。
     * @return array {success:bool, refund_id?:string, message:string}
     */
    public function refund($order_id, $amount = 0, $reason = '')
    {
        $order_id = (int) $order_id;
        $payment_intent = (string) get_post_meta($order_id, '_mlshop_payment_id', true);
        if ('' === $payment_intent) {
            return array('success' => false, 'message' => __('訂單缺少 Stripe Payment Intent，無法退款。', 'moonlight-shop'));
        }
        $secret = $this->get_secret_key();
        if (!$secret) {
            return array('success' => false, 'message' => __('Stripe 尚未配置，無法退款。', 'moonlight-shop'));
        }

        $params = array(
            'payment_intent' => $payment_intent,
            'reason'         => 'requested_by_customer',
        );
        $amount = (float) $amount;
        if ($amount > 0) {
            // 部分退款：金额以最小单位（分）传递；全额退款省略 amount
            $params['amount'] = (string) self::to_minor_units($amount);
        }

        $response = $this->api_request('refunds', $params, $secret);
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        if (empty($response['id'])) {
            return array('success' => false, 'message' => __('Stripe 退款失敗（未返回退款 ID）。', 'moonlight-shop'));
        }
        return array(
            'success'   => true,
            'refund_id' => (string) $response['id'],
            'message'   => __('退款成功。', 'moonlight-shop'),
        );
    }

    /**
     * 校验 Stripe Webhook 签名。
     */
    private function verify_webhook_signature($payload, $sig_header, $secret)
    {
        if (!$sig_header) {
            return new WP_Error('mlshop_stripe_sig', 'Missing signature');
        }
        $parts = array();
        foreach (explode(',', $sig_header) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq !== false) {
                $parts[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
            }
        }
        if (empty($parts['t']) || empty($parts['v1'])) {
            return new WP_Error('mlshop_stripe_sig', 'Malformed signature');
        }
        // 重放防护：签名时间戳与当前时间偏差超过 5 分钟则拒绝
        if (abs((int) $parts['t'] - time()) > 300) {
            return new WP_Error('mlshop_stripe_sig', 'Timestamp out of tolerance');
        }
        $signed_payload = $parts['t'] . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, $secret);
        if (!hash_equals($expected, $parts['v1'])) {
            return new WP_Error('mlshop_stripe_sig', 'Signature mismatch');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['type'])) {
            return new WP_Error('mlshop_stripe_sig', 'Invalid payload');
        }
        return $event;
    }
}
