<?php
/**
 * PayPal 支付网关（基于 PayPal Orders API v2）。
 *
 * 流程：create order → 跳转 approve 链接 → 用户授权 → 回跳 capture → 标记订单 paid。
 * 后台配置：Client ID / Secret / sandbox 开关。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_PayPal extends MLSHOP_Gateway
{
    const API_LIVE = 'https://api-m.paypal.com';
    const API_SANDBOX = 'https://api-m.sandbox.paypal.com';

    public function get_id()
    {
        return 'paypal';
    }

    public function get_title()
    {
        return __('PayPal', 'moonlight-shop');
    }

    public function get_description()
    {
        return __('使用 PayPal 帳戶或信用卡完成付款。', 'moonlight-shop');
    }

    private function is_sandbox()
    {
        return (bool) mlshop_get_option('paypal_sandbox', 1);
    }

    private function api_base()
    {
        return $this->is_sandbox() ? self::API_SANDBOX : self::API_LIVE;
    }

    private function get_client_id()
    {
        return trim((string) mlshop_get_option('paypal_client_id', ''));
    }

    private function get_secret()
    {
        return trim((string) mlshop_get_option('paypal_secret', ''));
    }

    /**
     * 获取 OAuth access_token。
     */
    private function get_access_token()
    {
        $client_id = $this->get_client_id();
        $secret    = $this->get_secret();
        if (!$client_id || !$secret) {
            return new WP_Error('mlshop_paypal_auth', __('PayPal 尚未配置。', 'moonlight-shop'));
        }
        $cache_key = 'mlshop_paypal_token_' . md5($client_id . '|' . $this->api_base());
        $cached = get_transient($cache_key);
        if ($cached) {
            return $cached;
        }
        $response = wp_remote_post($this->api_base() . '/v1/oauth2/token', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => 'grant_type=client_credentials',
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['access_token'])) {
            /* translators: %d: 数量 */
            $msg = isset($data['error_description']) ? $data['error_description'] : sprintf(__('PayPal 認證失敗（HTTP %d）', 'moonlight-shop'), $code);
            return new WP_Error('mlshop_paypal_auth', $msg);
        }
        set_transient($cache_key, $data['access_token'], max(60, (int) $data['expires_in'] - 60));
        return $data['access_token'];
    }

    /**
     * 下单：创建 PayPal order，返回 approve 链接。
     */
    public function process_payment($order_id)
    {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message(), 'redirect' => '');
        }

        $total   = (float) get_post_meta($order_id, '_mlshop_total', true);
        $currency = strtoupper(mlshop_get_option('currency', 'HKD'));
        $items   = (array) get_post_meta($order_id, '_mlshop_items', true);

        $subtotal = (float) get_post_meta($order_id, '_mlshop_subtotal', true);
        if (!$subtotal) {
            $subtotal = $total;
        }
        $discount = (float) get_post_meta($order_id, '_mlshop_coupon_discount', true);
        $shipping = (float) get_post_meta($order_id, '_mlshop_shipping', true);

        $purchase_items = array();
        foreach ($items as $it) {
            $title = get_the_title((int) $it['id']);
            $purchase_items[] = array(
                'name'        => $title ? mb_substr($title, 0, 120) : __('商品', 'moonlight-shop'),
                'unit_amount' => array(
                    'currency_code' => $currency,
                    'value'         => number_format((float) $it['price'], 2, '.', ''),
                ),
                'quantity'    => (string) max(1, (int) $it['qty']),
            );
        }

        $return_args = array(
            'mlshop_order' => $order_id,
            'gateway'      => 'paypal',
            'action'       => 'capture',
        );
        // 游客订单回跳携带访问令牌（回跳授权校验用）； PayPal 自身的单号仍用 token 参数名
        $gt = (string) get_post_meta($order_id, '_mlshop_guest_token', true);
        if ('' !== $gt) {
            $return_args['mlshop_gt'] = $gt;
        }
        $return_url = add_query_arg($return_args, home_url('/'));
        $cancel_url = $this->order_url($order_id);

        // 金額拆分：商品小計 − 優惠 + 運費 = 訂單總額（與後台記錄一致）
        $breakdown = array(
            'item_total' => array(
                'currency_code' => $currency,
                'value'         => number_format($subtotal, 2, '.', ''),
            ),
        );
        if ($discount > 0) {
            $breakdown['discount'] = array(
                'currency_code' => $currency,
                'value'         => number_format($discount, 2, '.', ''),
            );
        }
        if ($shipping > 0) {
            $breakdown['shipping'] = array(
                'currency_code' => $currency,
                'value'         => number_format($shipping, 2, '.', ''),
            );
        }

        $payload = array(
            'intent'              => 'CAPTURE',
            'purchase_units'      => array(
                array(
                    'reference_id' => (string) $order_id,
                    'invoice_id'   => 'ML-' . $order_id,
                    'custom_id'    => (string) $order_id,
                    'amount'       => array(
                        'currency_code' => $currency,
                        'value'         => number_format($total, 2, '.', ''),
                        'breakdown'     => $breakdown,
                    ),
                    'items'        => $purchase_items,
                ),
            ),
            'application_context' => array(
                'return_url' => $return_url,
                'cancel_url' => $cancel_url,
                'user_action' => 'PAY_NOW',
            ),
        );

        $response = wp_remote_post($this->api_base() . '/v2/checkout/orders', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization'        => 'Bearer ' . $token,
                'Content-Type'         => 'application/json',
                'PayPal-Request-Id'    => 'mlshop-order-' . $order_id,
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message(), 'redirect' => '');
        }
        $code  = wp_remote_retrieve_response_code($response);
        $order = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($order['id'])) {
            /* translators: %d: 数量 */
            $msg = isset($order['message']) ? $order['message'] : sprintf(__('PayPal 創建訂單失敗（HTTP %d）', 'moonlight-shop'), $code);
            return array('success' => false, 'message' => $msg, 'redirect' => '');
        }
        update_post_meta($order_id, '_mlshop_paypal_order', $order['id']);
        // 找 approve 链接
        $approve = '';
        if (!empty($order['links'])) {
            foreach ($order['links'] as $link) {
                if (isset($link['rel']) && 'approve' === $link['rel']) {
                    $approve = $link['href'];
                    break;
                }
            }
        }
        if (!$approve) {
            return array('success' => false, 'message' => __('PayPal 未返回支付連結，請稍後重試。', 'moonlight-shop'), 'redirect' => '');
        }
        return array(
            'success'  => true,
            'message'  => __('正在跳轉至 PayPal…', 'moonlight-shop'),
            'redirect' => $approve,
        );
    }

    /**
     * Capture 一个已创建的 PayPal order。
     */
    public function capture($paypal_order_id)
    {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $response = wp_remote_post($this->api_base() . '/v2/checkout/orders/' . $paypal_order_id . '/capture', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => '{}',
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            /* translators: %d: 数量 */
            $msg = isset($data['message']) ? $data['message'] : sprintf(__('PayPal 確認收款失敗（HTTP %d）', 'moonlight-shop'), $code);
            return new WP_Error('mlshop_paypal_capture', $msg);
        }
        return $data;
    }

    /**
     * PayPal 退款（Payments API v2）。
     *
     * 依据订单 `_mlshop_payment_id`（PayPal order id，回跳 capture 完单时写入；
     * 兜底回退 `_mlshop_paypal_order`）：
     *   1) GET  /v2/checkout/orders/{id} 取 purchase_units[0].payments.captures[0].id；
     *   2) POST /v2/payments/captures/{capture_id}/refund
     *      body 部分退款含 {amount:{value:"x.xx", currency_code:商城货币}}（全额省略 amount）。
     * access token 复用本类现有 get_access_token()。
     *
     * @param int    $order_id 订单 ID。
     * @param float  $amount   退款金额（0 = 全额退）。
     * @param string $reason   退款原因（备注用途，不进 API body）。
     * @return array {success:bool, refund_id?:string, message:string}
     */
    public function refund($order_id, $amount = 0, $reason = '')
    {
        $order_id = (int) $order_id;
        $pp_order = (string) get_post_meta($order_id, '_mlshop_payment_id', true);
        if ('' === $pp_order) {
            $pp_order = (string) get_post_meta($order_id, '_mlshop_paypal_order', true);
        }
        if ('' === $pp_order) {
            return array('success' => false, 'message' => __('訂單缺少 PayPal 訂單號，無法退款。', 'moonlight-shop'));
        }
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }

        // 1) 查询 PayPal order，提取 capture id（退款必须针对 capture 而非 order）。
        $response = wp_remote_get($this->api_base() . '/v2/checkout/orders/' . rawurlencode($pp_order), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $capture_id = '';
        if ($code >= 200 && $code < 300
            && isset($data['purchase_units'][0]['payments']['captures'][0]['id'])) {
            $capture_id = (string) $data['purchase_units'][0]['payments']['captures'][0]['id'];
        }
        if ('' === $capture_id) {
            return array('success' => false, 'message' => __('未找到 PayPal 收款記錄（capture），無法退款。', 'moonlight-shop'));
        }

        // 2) 发起退款：全额省略 amount；部分退款带金额（商城货币，两位小数）。
        $amount = (float) $amount;
        if ($amount > 0) {
            $body = array(
                'amount' => array(
                    'currency_code' => strtoupper((string) mlshop_get_option('currency', 'HKD')),
                    'value'         => number_format($amount, 2, '.', ''),
                ),
            );
        } else {
            $body = new stdClass(); // 全额退款：空 JSON 对象 {}
        }
        $res2 = wp_remote_post($this->api_base() . '/v2/payments/captures/' . rawurlencode($capture_id) . '/refund', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ));
        if (is_wp_error($res2)) {
            return array('success' => false, 'message' => $res2->get_error_message());
        }
        $code2 = wp_remote_retrieve_response_code($res2);
        $data2 = json_decode(wp_remote_retrieve_body($res2), true);
        if ($code2 < 200 || $code2 >= 300 || empty($data2['id'])) {
            /* translators: %d: 数量 */
            $msg = isset($data2['message']) ? (string) $data2['message'] : sprintf(__('PayPal 退款失敗（HTTP %d）', 'moonlight-shop'), $code2);
            return array('success' => false, 'message' => $msg);
        }
        return array(
            'success'   => true,
            'refund_id' => (string) $data2['id'],
            'message'   => __('退款成功。', 'moonlight-shop'),
        );
    }

    /**
     * PayPal Webhook 兜底：即使用户付款後未回跳，也能標記訂單為已付款。
     * 端點：?mlshop_paypal_webhook=1
     */
    public function handle_webhook()
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            status_header(400);
            echo 'Empty body';
            return;
        }
        $event = json_decode($raw, true);
        if (!is_array($event) || empty($event['event_type'])) {
            status_header(400);
            echo 'Invalid event';
            return;
        }

        // 未配置 Webhook ID：無法驗證，直接 200 確認以免 PayPal 重試，但不處理事件。
        $webhook_id = trim((string) mlshop_get_option('paypal_webhook_id', ''));
        if (!$webhook_id) {
            status_header(200);
            echo 'OK (unconfigured)';
            return;
        }

        $verified = $this->verify_webhook_signature($raw);
        if (is_wp_error($verified)) {
            status_header(401);
            echo 'Signature verification failed';
            return;
        }

        if ('PAYMENT.CAPTURE.COMPLETED' === $event['event_type']) {
            $resource = isset($event['resource']) ? $event['resource'] : array();
            $order_id = 0;
            if (!empty($resource['custom_id'])) {
                $order_id = (int) $resource['custom_id'];
            } elseif (!empty($resource['supplementary_data']['related_ids']['order_id'])) {
                $pp_order = $resource['supplementary_data']['related_ids']['order_id'];
                $orders = get_posts(array(
                    'post_type'      => 'mlshop_order',
                    'posts_per_page' => 1,
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array('key' => '_mlshop_paypal_order', 'value' => $pp_order),
                    ),
                ));
                if (!empty($orders)) {
                    $order_id = (int) $orders[0];
                }
            }
            if ($order_id && get_post_type($order_id) === 'mlshop_order') {
                $current = get_post_meta($order_id, '_mlshop_status', true);
                if (!in_array($current, array('paid', 'processing', 'completed', 'refunded'), true)) {
                    MLSHOP_Order::mark_paid($order_id);
                }
            }
        }

        status_header(200);
        echo 'OK';
    }

    /**
     * 使用 PayPal 官方 verify-webhook-signature 接口校驗簽名。
     */
    private function verify_webhook_signature($raw)
    {
        $webhook_id = trim((string) mlshop_get_option('paypal_webhook_id', ''));
        if (!$webhook_id) {
            return new WP_Error('mlshop_paypal_wh', __('未配置 PayPal Webhook ID。', 'moonlight-shop'));
        }
        $headers = $this->get_webhook_headers();
        foreach (array('transmission_id', 'transmission_time', 'cert_url', 'auth_algo', 'signature') as $h) {
            if (empty($headers[$h])) {
                return new WP_Error('mlshop_paypal_wh', __('Webhook 頭部不完整。', 'moonlight-shop'));
            }
        }
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $payload = wp_json_encode(array(
            'transmission_id'   => $headers['transmission_id'],
            'transmission_time' => $headers['transmission_time'],
            'cert_url'          => $headers['cert_url'],
            'webhook_id'        => $webhook_id,
            'webhook_event'     => $raw,
        ));
        $response = wp_remote_post($this->api_base() . '/v1/notifications/verify-webhook-signature', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => $payload,
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['verification_status']) || 'SUCCESS' !== $data['verification_status']) {
            return new WP_Error('mlshop_paypal_wh', __('簽名驗證未通過。', 'moonlight-shop'));
        }
        return true;
    }

    /**
     * 讀取 PayPal Webhook 簽名所需的 HTTP 頭。
     */
    private function get_webhook_headers()
    {
        $map = array(
            'transmission_id'   => 'HTTP_PAYPAL_TRANSMISSION_ID',
            'transmission_time' => 'HTTP_PAYPAL_TRANSMISSION_TIME',
            'cert_url'          => 'HTTP_PAYPAL_CERT_URL',
            'auth_algo'         => 'HTTP_PAYPAL_AUTH_ALGO',
            'signature'         => 'HTTP_PAYPAL_SIGNATURE',
        );
        $out = array();
        foreach ($map as $key => $server_key) {
            $out[$key] = isset($_SERVER[$server_key]) ? sanitize_text_field(wp_unslash($_SERVER[$server_key])) : '';
        }
        return $out;
    }
}
