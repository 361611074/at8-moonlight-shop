<?php
/**
 * Stripe 网关：Checkout Session（跳转结账，免官方 SDK），回跳确认 + 可选 Webhook。
 *
 * 流程：创建本地 pending 订单 → 服务端创建 Checkout Session → 跳转 Stripe 托管页
 * → 支付完成回跳账户中心 → 服务端 retrieve session 校验后开通；
 * Webhook（checkout.session.completed）兜底，签名用 webhook secret 校验。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Stripe
{
    const API = 'https://api.stripe.com/v1';

    /**
     * 是否启用（后台配置齐全才算启用）。
     */
    public static function enabled()
    {
        return (bool) mluc_get_option('stripe_enabled', 0)
            && '' !== trim((string) mluc_get_option('stripe_sk', ''));
    }

    private static function auth_header()
    {
        return array('Authorization' => 'Bearer ' . trim((string) mluc_get_option('stripe_sk', '')));
    }

    /**
     * 创建 Checkout Session。
     *
     * @return array|WP_Error array(id, url)
     */
    public static function create_session($order_id)
    {
        $order_id = (int) $order_id;
        $price    = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        $level    = (string) get_post_meta($order_id, '_mluc_pay_level', true);
        $user_id  = (int) get_post_meta($order_id, '_mluc_pay_user', true);
        $user     = $user_id ? get_user_by('id', $user_id) : false;
        if ($order_id <= 0 || $price <= 0) {
            return new WP_Error('mluc_st_order', __('本地订单无效。', 'moonlight-user-center'));
        }

        // 商品名：付费墙订单用文章标题，会员订单用等级名。
        $item_label = (string) get_post_meta($order_id, '_mluc_pay_title', true);
        if ('' === $item_label && class_exists('MLUC_Membership')) {
            $item_label = MLUC_Membership::get_level_label($level);
        }

        // 回跳地址：付费墙订单回文章页，会员订单回账户中心。
        if ('paywall' === (string) get_post_meta($order_id, '_mluc_pay_type', true)) {
            $pw_post = (int) get_post_meta($order_id, '_mluc_pay_post', true);
            $base    = ($pw_post && get_permalink($pw_post)) ? get_permalink($pw_post) : home_url('/');
        } else {
            $base = mluc_get_account_url();
        }
        $success = add_query_arg(array(
            'mluc_stripe_return' => 1,
            'mluc_order'         => $order_id,
            'session_id'         => '{CHECKOUT_SESSION_ID}',
        ), $base);
        $cancel = add_query_arg(array('mluc_stripe_cancel' => 1), $base);

        $body = array(
            'mode'                    => 'payment',
            'success_url'             => $success,
            'cancel_url'              => $cancel,
            'client_reference_id'     => (string) $order_id,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]'            => strtolower(MLUC_Payments::currency_code()),
            'line_items[0][price_data][unit_amount]'         => (string) MLUC_Payments::api_amount_minor($price),
            'line_items[0][price_data][product_data][name]'  => sprintf(
                '%s - %s',
                get_bloginfo('name'),
                $item_label
            ),
        );
        if ($user && $user->user_email) {
            $body['customer_email'] = $user->user_email;
        }

        $res = wp_remote_post(self::API . '/checkout/sessions', array(
            'timeout' => 30,
            'headers' => self::auth_header(),
            'body'    => $body,
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) >= 300 || empty($data['id']) || empty($data['url'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : __('Stripe 会话创建失败，请检查 Secret Key。', 'moonlight-user-center');
            return new WP_Error('mluc_st_create', $msg);
        }
        return array('id' => (string) $data['id'], 'url' => (string) $data['url']);
    }

    /**
     * 检索 Session（回跳 / webhook 复核用）。
     *
     * @return array|WP_Error
     */
    public static function retrieve_session($session_id)
    {
        $session_id = sanitize_text_field($session_id);
        if ('' === $session_id || strlen($session_id) > 300) {
            return new WP_Error('mluc_st_sid', __('無效的结账会话。', 'moonlight-user-center'));
        }
        $res = wp_remote_get(self::API . '/checkout/sessions/' . rawurlencode($session_id), array(
            'timeout' => 30,
            'headers' => self::auth_header(),
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) >= 300 || empty($data['id'])) {
            return new WP_Error('mluc_st_get', __('无法获取 Stripe 会话信息。', 'moonlight-user-center'));
        }
        return $data;
    }

    /**
     * 支付成功统一入口：校验 session 与本地订单后开通。
     */
    private static function fulfill($session)
    {
        $order_id = isset($session['client_reference_id']) ? (int) $session['client_reference_id'] : 0;
        if ($order_id <= 0 || get_post_type($order_id) !== MLUC_Payments::CPT) {
            return;
        }
        // 幂等：已支付直接返回。
        if ('paid' === (string) get_post_meta($order_id, '_mluc_pay_status', true)) {
            return;
        }
        $payment_status = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
        if ('paid' !== $payment_status) {
            return;
        }
        // 金额校验（最小货币单位比对）。
        $price   = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        $amount  = isset($session['amount_total']) ? (int) $session['amount_total'] : -1;
        $expect  = MLUC_Payments::api_amount_minor($price);
        if ($amount < 0 || abs($amount - $expect) > 1) {
            return;
        }
        $txn = isset($session['payment_intent']) ? (string) $session['payment_intent'] : (string) $session['id'];
        MLUC_Payments::complete_order($order_id, $txn, 'stripe');
    }

    /**
     * 支付完成回跳处理（init 前端触发）。
     */
    public static function maybe_handle_return()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (empty($_GET['mluc_stripe_return']) && empty($_GET['mluc_stripe_cancel'])) {
            return;
        }
        $cancel_url = add_query_arg(array('mluc_pay_notice' => 'cancelled'), mluc_get_account_url());
        if (!empty($_GET['mluc_order'])) {
            $c_order = (int) $_GET['mluc_order'];
            if ('paywall' === (string) get_post_meta($c_order, '_mluc_pay_type', true)) {
                $c_post = (int) get_post_meta($c_order, '_mluc_pay_post', true);
                if ($c_post && get_permalink($c_post)) {
                    $cancel_url = add_query_arg(array('mluc_pay_notice' => 'cancelled'), get_permalink($c_post));
                }
            }
        }

        if (!empty($_GET['mluc_stripe_cancel'])) {
            wp_safe_redirect($cancel_url);
            exit;
        }

        $order_id   = isset($_GET['mluc_order']) ? (int) $_GET['mluc_order'] : 0;
        $session_id = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';

        // 回跳跳转基址：付费墙订单回文章页，会员订单回账户中心。
        $base = mluc_get_account_url();
        if ($order_id > 0 && 'paywall' === (string) get_post_meta($order_id, '_mluc_pay_type', true)) {
            $pw_post = (int) get_post_meta($order_id, '_mluc_pay_post', true);
            if ($pw_post && get_permalink($pw_post)) {
                $base = get_permalink($pw_post);
            }
        }
        $ok_url   = add_query_arg(array('mluc_pay_notice' => 'paid'), $base);
        $fail_url = add_query_arg(array('mluc_pay_notice' => 'failed'), $base);

        if ($order_id <= 0 || '' === $session_id
            || (int) get_post_meta($order_id, '_mluc_pay_user', true) !== get_current_user_id()) {
            wp_safe_redirect($fail_url);
            exit;
        }

        $session = self::retrieve_session($session_id);
        if (is_wp_error($session)) {
            wp_safe_redirect($fail_url);
            exit;
        }
        self::fulfill($session);

        // paid 状态可能仍需 webhook 补齐（极少），回跳统一提示。
        wp_safe_redirect('paid' === (string) get_post_meta($order_id, '_mluc_pay_status', true) ? $ok_url : $fail_url);
        exit;
    }

    /**
     * REST webhook：checkout.session.completed 兜底开通。
     */
    public static function register_webhook()
    {
        register_rest_route('mluc/v1', '/stripe-webhook', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle_webhook(WP_REST_Request $request)
    {
        $secret  = trim((string) mluc_get_option('stripe_webhook_secret', ''));
        $payload = $request->get_body();
        $sig     = $request->get_header('stripe-signature');

        if ('' !== $secret) {
            if (!$sig || !preg_match('/t=(\d+)/i', $sig, $tm) || !preg_match_all('/v1=([a-f0-9]+)/i', $sig, $vm)) {
                return new WP_REST_Response(array('error' => 'bad signature'), 400);
            }
            // 时间容差 5 分钟。
            if (abs(time() - (int) $tm[1]) > 300) {
                return new WP_REST_Response(array('error' => 'expired'), 400);
            }
            // 多个 v1 签名任一匹配即可。
            $expected_prefix = $tm[1] . '.';
            $ok = false;
            foreach ($vm[1] as $v1) {
                if (hash_equals(hash_hmac('sha256', $expected_prefix . $payload, $secret), strtolower($v1))) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return new WP_REST_Response(array('error' => 'signature mismatch'), 400);
            }
        }

        $event = json_decode($payload, true);
        if (empty($event['type'])) {
            return new WP_REST_Response(array('received' => true), 200);
        }
        // livemode 一致性：事件环境必须与站点配置的密钥模式一致（sk_live_ 前缀 = live）。
        if (isset($event['livemode'])) {
            $sk            = trim((string) mluc_get_option('stripe_sk', ''));
            $live_expected = (0 === strpos($sk, 'sk_live_'));
            if ((bool) $event['livemode'] !== $live_expected) {
                return new WP_REST_Response(array('error' => 'mode mismatch'), 400);
            }
        }
        if ('checkout.session.completed' === $event['type'] && !empty($event['data']['object']['id'])) {
            // 安全关键：不信任请求体数据，回查 Stripe API 取真实会话（未配置 secret 时同样防伪造）。
            $session = self::retrieve_session($event['data']['object']['id']);
            if (!is_wp_error($session)) {
                self::fulfill($session);
            }
        }
        return new WP_REST_Response(array('received' => true), 200);
    }

    /**
     * AJAX：购买时创建 Checkout Session 并返回跳转地址。
     */
    public static function ajax_checkout()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in() || !self::enabled()) {
            mluc_send_json(false, __('Stripe 支付未启用。', 'moonlight-user-center'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ($order_id <= 0 || get_post_type($order_id) !== MLUC_Payments::CPT
            || (int) get_post_meta($order_id, '_mluc_pay_user', true) !== get_current_user_id()
            || 'pending' !== (string) get_post_meta($order_id, '_mluc_pay_status', true)) {
            mluc_send_json(false, __('訂單無效或狀態已變更。', 'moonlight-user-center'));
        }
        $session = self::create_session($order_id);
        if (is_wp_error($session)) {
            mluc_send_json(false, $session->get_error_message());
        }
        update_post_meta($order_id, '_mluc_pay_txn_ref', $session['id']);
        mluc_send_json(true, __('正在跳轉到 Stripe…', 'moonlight-user-center'), array('redirect' => $session['url']));
    }
}
