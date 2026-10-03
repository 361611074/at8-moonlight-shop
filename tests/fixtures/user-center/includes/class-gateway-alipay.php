<?php
/**
 * 支付宝网关：电脑网站支付（alipay.trade.page.pay，跳转式），RSA2 签名。
 *
 * 流程：本地 pending 订单 → 服务端构建跳转 URL（out_trade_no = 本地订单号）
 * → 用户在支付宝完成支付 → 异步 notify（验签 + 金额 + 订单号 + 状态四重校验
 * → complete_order 原子完单）为唯一开通依据；
 * 浏览器回跳仅验签 + 服务端 alipay.trade.query 复核后展示结果，不直接开通。
 *
 * 依赖：PHP OpenSSL 扩展（RSA2 签名/验签），is_available() 缺失时网关自动隐藏。
 * 安全：私钥仅存 options，日志只记录订单号 / 交易号等白名单字段（见 MLUC_Payment_Log）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Gateway_Alipay implements MLUC_Payment_Gateway_Interface
{
    /** 生产网关。 */
    const GATEWAY_PRODUCTION = 'https://openapi.alipay.com/gateway.do';
    /** 沙盒网关（支付宝开放平台沙箱环境）。 */
    const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    const REST_NOTIFY = 'mluc/v1/alipay/notify';

    public function get_id()
    {
        return 'alipay';
    }

    public function get_name()
    {
        return mluc_ui_label('buy_gateway_alipay', 'Alipay (支付宝)');
    }

    public function get_capabilities()
    {
        return array(
            'currencies' => array('CNY'),
            'recurring'  => false,
            'refund'     => true,
            'query'      => true,
        );
    }

    /**
     * 是否启用（后台配置齐全才算启用）。
     */
    public static function enabled()
    {
        return (bool) mluc_get_option('alipay_enabled', 0)
            && '' !== trim((string) mluc_get_option('alipay_app_id', ''))
            && '' !== trim((string) mluc_get_option('alipay_private_key', ''))
            && '' !== trim((string) mluc_get_option('alipay_public_key', ''));
    }

    /**
     * OpenSSL 是否可用（RSA2 依赖）。
     */
    public static function openssl_available()
    {
        return function_exists('openssl_sign') && function_exists('openssl_verify') && function_exists('openssl_pkey_get_private');
    }

    public function is_available()
    {
        return self::enabled() && self::openssl_available();
    }

    /**
     * 网关地址（沙盒 / 生产，可经过滤器替换）。
     */
    public static function gateway_url()
    {
        $mode = ('sandbox' === mluc_get_option('alipay_mode', 'sandbox')) ? self::GATEWAY_SANDBOX : self::GATEWAY_PRODUCTION;
        return apply_filters('mluc_alipay_gateway_url', $mode, mluc_get_option('alipay_mode', 'sandbox'));
    }

    /* ---------------- RSA2 签名 / 验签 ---------------- */

    /**
     * 归一化密钥为 PEM（兼容粘贴 PKCS#1 / PKCS#8 / 无头裸 base64）。
     */
    private static function to_pem($raw, $type)
    {
        $body = preg_replace('/-----[A-Z ]+-----/', '', (string) $raw);
        $body = preg_replace('/\s+/', '', $body);
        if ('' === $body || !preg_match('/^[A-Za-z0-9+\/=]+$/', $body)) {
            return '';
        }
        return "-----BEGIN {$type}-----\n" . chunk_split($body, 64, "\n") . "-----END {$type}-----\n";
    }

    /**
     * 取应用私钥资源（依次尝试 PKCS#8 / PKCS#1 封装）。
     *
     * @return resource|false
     */
    private static function private_key()
    {
        $raw = (string) mluc_get_option('alipay_private_key', '');
        foreach (array('PRIVATE KEY', 'RSA PRIVATE KEY') as $type) {
            $pem = self::to_pem($raw, $type);
            if ('' === $pem) {
                return false;
            }
            $res = openssl_pkey_get_private($pem);
            if ($res) {
                return $res;
            }
        }
        return false;
    }

    /**
     * 取支付宝公钥资源。
     *
     * @return resource|false
     */
    private static function public_key()
    {
        $pem = self::to_pem((string) mluc_get_option('alipay_public_key', ''), 'PUBLIC KEY');
        if ('' === $pem) {
            return false;
        }
        return openssl_pkey_get_public($pem);
    }

    /**
     * 构造待签名内容：按 key 升序 k=v& 拼接（不含 sign / sign_type，空值剔除，不做 URL 编码）。
     */
    private static function sign_content($params)
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params, SORT_STRING);
        $pairs = array();
        foreach ($params as $k => $v) {
            $v = (string) $v;
            if ('' === $v) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return implode('&', $pairs);
    }

    /**
     * RSA2 请求签名（SHA256）。
     *
     * @return string|WP_Error base64 签名。
     */
    private static function sign($params)
    {
        $key = self::private_key();
        if (!$key) {
            return new WP_Error('mluc_ali_key', __('支付宝应用私钥无效，请检查格式（支持 PKCS#1 / PKCS#8）。', 'moonlight-user-center'));
        }
        $signature = '';
        if (!openssl_sign(self::sign_content($params), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('mluc_ali_sign', __('支付宝请求签名失败，请检查应用私钥。', 'moonlight-user-center'));
        }
        return base64_encode($signature);
    }

    /**
     * RSA2 验签（支付宝公钥）。
     *
     * @param array  $params 原始参数（含 sign）。
     * @param string $raw    可选，直接给定待验签原文（响体验签用）。
     * @return bool
     */
    private static function verify($params, $raw = '')
    {
        $pub = self::public_key();
        if (!$pub) {
            return false;
        }
        $sign = isset($params['sign']) ? (string) $params['sign'] : '';
        if ('' === $sign) {
            return false;
        }
        $content = ('' !== $raw) ? $raw : self::sign_content($params);
        $ok = openssl_verify($content, base64_decode($sign), $pub, OPENSSL_ALGO_SHA256);
        return 1 === $ok;
    }

    /* ---------------- 下单 / 跳转 ---------------- */

    public function process_payment($order_id)
    {
        $url = self::build_pay_url((int) $order_id);
        if (is_wp_error($url)) {
            MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'fail', array('code' => 'build_url', 'message' => $url->get_error_message()));
            return $url;
        }
        MLUC_Payment_Log::write($order_id, $this->get_id(), 'process', 'redirect');

        return array(
            'flow'    => 'alipay',
            'message' => mluc_ui_label('buy_order_alipay', 'Order created. Redirecting to Alipay…'),
            'redirect' => $url,
        );
    }

    /**
     * 构建 alipay.trade.page.pay 跳转 URL。
     *
     * @param int $order_id 本地订单 ID。
     * @return string|WP_Error
     */
    public static function build_pay_url($order_id)
    {
        $order_id = (int) $order_id;
        $price    = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        if ($order_id <= 0 || $price <= 0) {
            return new WP_Error('mluc_ali_order', __('本地订单无效。', 'moonlight-user-center'));
        }
        if (!self::enabled()) {
            return new WP_Error('mluc_ali_disabled', __('支付宝支付未启用。', 'moonlight-user-center'));
        }

        // 订单号：服务端生成（老订单兼容补齐），作为支付宝 out_trade_no。
        $order_no = (string) get_post_meta($order_id, '_mluc_pay_order_no', true);
        if ('' === $order_no) {
            $order_no = MLUC_Payment_Manager::generate_order_no();
            update_post_meta($order_id, '_mluc_pay_order_no', $order_no);
        }

        // 商品名：付费墙订单用文章标题，会员订单用等级名。
        $item_label = (string) get_post_meta($order_id, '_mluc_pay_title', true);
        if ('' === $item_label && class_exists('MLUC_Membership')) {
            $item_label = MLUC_Membership::get_level_label((string) get_post_meta($order_id, '_mluc_pay_level', true));
        }

        $params = array(
            'app_id'      => trim((string) mluc_get_option('alipay_app_id', '')),
            'method'      => 'alipay.trade.page.pay',
            'format'      => 'JSON',
            'charset'     => 'UTF-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => gmdate('Y-m-d H:i:s', time() + 8 * HOUR_IN_SECONDS),
            'version'     => '1.0',
            'notify_url'  => self::notify_url(),
            'return_url'  => self::return_url($order_id),
            'biz_content' => wp_json_encode(array(
                'out_trade_no' => $order_no,
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
                'total_amount' => MLUC_Payments::api_amount($price),
                'subject'      => mb_substr(sprintf('%s - %s', get_bloginfo('name'), $item_label), 0, 120),
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $sign = self::sign($params);
        if (is_wp_error($sign)) {
            return $sign;
        }
        $params['sign'] = $sign;

        update_post_meta($order_id, '_mluc_pay_txn_ref', $order_no);
        return self::gateway_url() . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    }

    /**
     * 异步通知地址（REST）。
     */
    public static function notify_url()
    {
        return rest_url(self::REST_NOTIFY);
    }

    /**
     * 支付完成回跳地址（会员订单回账户中心，付费墙订单回文章页）。
     */
    private static function return_url($order_id)
    {
        $base = mluc_get_account_url();
        if ('paywall' === (string) get_post_meta($order_id, '_mluc_pay_type', true)) {
            $pw_post = (int) get_post_meta($order_id, '_mluc_pay_post', true);
            if ($pw_post && get_permalink($pw_post)) {
                $base = get_permalink($pw_post);
            }
        }
        return add_query_arg(array(
            'mluc_alipay_return' => 1,
            'mluc_order'         => (int) $order_id,
        ), $base);
    }

    /* ---------------- 服务端查询 ---------------- */

    /**
     * 调用 alipay.trade.query 查询真实支付状态。
     *
     * @param string $out_trade_no 本地订单号。
     * @return array|WP_Error array(status, trade_no, amount)
     */
    public static function query_trade($out_trade_no)
    {
        if (!self::enabled()) {
            return new WP_Error('mluc_ali_disabled', __('支付宝支付未启用。', 'moonlight-user-center'));
        }
        $params = array(
            'app_id'      => trim((string) mluc_get_option('alipay_app_id', '')),
            'method'      => 'alipay.trade.query',
            'format'      => 'JSON',
            'charset'     => 'UTF-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => gmdate('Y-m-d H:i:s', time() + 8 * HOUR_IN_SECONDS),
            'version'     => '1.0',
            'biz_content' => wp_json_encode(array('out_trade_no' => (string) $out_trade_no), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $sign = self::sign($params);
        if (is_wp_error($sign)) {
            return $sign;
        }
        $params['sign'] = $sign;

        $res = wp_remote_post(self::gateway_url(), array(
            'timeout' => 30,
            'body'    => $params,
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $raw = (string) wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        $node = isset($data['alipay_trade_query_response']) ? $data['alipay_trade_query_response'] : array();
        if (empty($node)) {
            return new WP_Error('mluc_ali_query', __('支付宝订单查询失败。', 'moonlight-user-center'));
        }
        // 响应验签：不信任未签名结果（提取原始 JSON 子串按支付宝公钥复核）。
        if (!self::verify_response($raw, 'alipay_trade_query_response')) {
            return new WP_Error('mluc_ali_query_sig', __('支付宝查询响应验签失败。', 'moonlight-user-center'));
        }
        return array(
            'code'     => isset($node['code']) ? (string) $node['code'] : '',
            'status'   => isset($node['trade_status']) ? (string) $node['trade_status'] : '',
            'trade_no' => isset($node['trade_no']) ? (string) $node['trade_no'] : '',
            'amount'   => isset($node['total_amount']) ? (float) $node['total_amount'] : 0,
        );
    }

    /**
     * 响应体签名核验：提取 {method}_response 原始 JSON 子串，用支付宝公钥验签。
     */
    private static function verify_response($raw, $node_key)
    {
        $raw = (string) $raw;
        if (!preg_match('/"' . preg_quote($node_key, '/') . '"\s*:\s*(\{.*\})\s*,\s*"sign"\s*:\s*"([^"]+)"/s', $raw, $m)) {
            return false;
        }
        return self::verify(array('sign' => $m[2]), $m[1]);
    }

    /**
     * 状态映射：alipay.trade.query / notify 的 trade_status → 本地语义。
     *
     * @return string paid / pending / failed
     */
    private static function map_status($trade_status)
    {
        $trade_status = strtoupper((string) $trade_status);
        if (in_array($trade_status, array('TRADE_SUCCESS', 'TRADE_FINISHED'), true)) {
            return 'paid';
        }
        if ('TRADE_CLOSED' === $trade_status) {
            return 'failed';
        }
        return 'pending';
    }

    public function query_payment($order_id)
    {
        $order_id  = (int) $order_id;
        $order_no  = (string) get_post_meta($order_id, '_mluc_pay_order_no', true);
        $ref       = (string) get_post_meta($order_id, '_mluc_pay_txn_ref', true);
        $out_no    = ('' !== $order_no) ? $order_no : $ref;
        if ('' === $out_no) {
            return 'pending';
        }
        $q = self::query_trade($out_no);
        if (is_wp_error($q)) {
            return 'pending';
        }
        return self::map_status($q['status']);
    }

    /* ---------------- 回跳处理 ---------------- */

    public function handle_return($order_id)
    {
        // 统一入口见 maybe_handle_return()（init 钩子），此处仅为接口实现。
        return false;
    }

    /**
     * 支付宝回跳处理（init 钩子）：
     * 验签 → 服务端 alipay.trade.query 复核真实状态 → 原子完单 → 重定向提示。
     * 浏览器回跳参数绝不直接作为开通依据。
     */
    public static function maybe_handle_return()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (empty($_GET['mluc_alipay_return'])) {
            return;
        }
        $base = mluc_get_account_url();
        $order_id = isset($_GET['mluc_order']) ? (int) $_GET['mluc_order'] : 0;
        if ($order_id > 0 && 'paywall' === (string) get_post_meta($order_id, '_mluc_pay_type', true)) {
            $pw_post = (int) get_post_meta($order_id, '_mluc_pay_post', true);
            if ($pw_post && get_permalink($pw_post)) {
                $base = get_permalink($pw_post);
            }
        }
        $ok_url   = add_query_arg(array('mluc_pay_notice' => 'paid'), $base);
        $fail_url = add_query_arg(array('mluc_pay_notice' => 'failed'), $base);

        if ($order_id <= 0 || get_post_type($order_id) !== MLUC_Payments::CPT
            || (int) get_post_meta($order_id, '_mluc_pay_user', true) !== get_current_user_id()
            || 'alipay' !== (string) get_post_meta($order_id, '_mluc_pay_gateway', true)) {
            wp_safe_redirect($fail_url);
            exit;
        }

        // 回跳参数验签（防伪造回跳），随后仍以服务端查询结果为准。
        $params = array();
        foreach ($_GET as $k => $v) {
            $params[(string) $k] = sanitize_text_field(wp_unslash($v));
        }
        $signed = self::verify($params);
        if (!$signed) {
            MLUC_Payment_Log::write($order_id, 'alipay', 'return', 'fail', array('code' => 'signature'));
            wp_safe_redirect($fail_url);
            exit;
        }

        $out_no = (string) get_post_meta($order_id, '_mluc_pay_order_no', true);
        $ref    = (string) get_post_meta($order_id, '_mluc_pay_txn_ref', true);
        $q      = self::query_trade(('' !== $out_no) ? $out_no : $ref);
        if (is_wp_error($q)) {
            MLUC_Payment_Log::write($order_id, 'alipay', 'return', 'fail', array('code' => 'query', 'message' => $q->get_error_message()));
            wp_safe_redirect($fail_url);
            exit;
        }
        if ('paid' === self::map_status($q['status'])) {
            // 完单前金额复核：查询到的金额必须与本地订单一致。
            $price = (float) get_post_meta($order_id, '_mluc_pay_price', true);
            if ($q['amount'] > 0 && abs($q['amount'] - $price) <= 0.01) {
                MLUC_Payments::complete_order($order_id, $q['trade_no'], 'alipay');
            }
        }
        MLUC_Payment_Log::write($order_id, 'alipay', 'return', (string) get_post_meta($order_id, '_mluc_pay_status', true), array('trade_no' => $q['trade_no']));

        wp_safe_redirect('paid' === (string) get_post_meta($order_id, '_mluc_pay_status', true) ? $ok_url : $fail_url);
        exit;
    }

    /* ---------------- 异步通知 ---------------- */

    /**
     * 注册 REST 异步通知端点（由 MLUC_Payments 在 rest_api_init 挂载）。
     */
    public static function register_notify()
    {
        register_rest_route('mluc/v1', '/alipay/notify', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_notify'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * 异步通知处理：验签 → 商户 / 订单 / 金额 / 状态四重校验 → 原子完单 → success。
     * 重复通知天然幂等（complete_order 仅 pending→paid 一次胜出）。
     */
    public static function handle_notify(WP_REST_Request $request)
    {
        $params = array_merge((array) $request->get_body_params(), (array) $request->get_params());
        unset($params['rest_route']);

        if (empty($params['out_trade_no'])) {
            return new WP_REST_Response('fail', 200);
        }

        // 1. 验签（支付宝公钥，RSA2）。
        if (!self::verify($params)) {
            MLUC_Payment_Log::write(0, 'alipay', 'notify', 'fail', array('code' => 'signature'));
            return new WP_REST_Response('fail', 200);
        }

        // 2. 商户身份：app_id 必须与本站配置一致（防跨商户伪造）。
        $app_id = trim((string) mluc_get_option('alipay_app_id', ''));
        if (!isset($params['app_id']) || (string) $params['app_id'] !== $app_id) {
            return new WP_REST_Response('fail', 200);
        }

        // 3. 订单定位：out_trade_no = 本地订单号（绝不信任回传的本地 ID）。
        global $wpdb;
        $order_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mluc_pay_order_no' AND meta_value = %s LIMIT 1",
            (string) $params['out_trade_no']
        ));
        if ($order_id <= 0 || get_post_type($order_id) !== MLUC_Payments::CPT) {
            // 兼容老订单：订单号缺失时回退 txn_ref（历史 out_trade_no）。
            $order_id = self::find_order_by_ref((string) $params['out_trade_no']);
        }
        if ($order_id <= 0 || get_post_type($order_id) !== MLUC_Payments::CPT) {
            return new WP_REST_Response('fail', 200);
        }
        if ('alipay' !== (string) get_post_meta($order_id, '_mluc_pay_gateway', true)) {
            return new WP_REST_Response('fail', 200);
        }

        $status = self::map_status(isset($params['trade_status']) ? $params['trade_status'] : '');

        // 4. 金额复核：通知金额必须与本地订单一致（防篡改）。
        $price  = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        $amount = isset($params['total_amount']) ? (float) $params['total_amount'] : -1;
        if ($amount < 0 || abs($amount - $price) > 0.01) {
            MLUC_Payment_Log::write($order_id, 'alipay', 'notify', 'fail', array('code' => 'amount', 'amount' => (string) $amount));
            return new WP_REST_Response('fail', 200);
        }

        MLUC_Payment_Log::write($order_id, 'alipay', 'notify', $status, array(
            'trade_no' => isset($params['trade_no']) ? (string) $params['trade_no'] : '',
            'amount'   => (string) $amount,
        ));

        // 5. 成功状态才完单（TRADE_SUCCESS / TRADE_FINISHED）；closed 等回复 success 停止重试。
        if ('paid' === $status) {
            $txn = isset($params['trade_no']) ? (string) $params['trade_no'] : '';
            MLUC_Payments::complete_order($order_id, $txn, 'alipay');
        }

        return new WP_REST_Response('success', 200);
    }

    /**
     * 按 txn_ref 查找订单（历史订单兜底）。
     */
    private static function find_order_by_ref($ref)
    {
        global $wpdb;
        if ('' === $ref || strlen($ref) > 64) {
            return 0;
        }
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mluc_pay_txn_ref' AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
            $ref
        ));
        return (int) $found;
    }

    /* ---------------- 退款 ---------------- */

    public function refund($order_id, $amount = 0)
    {
        $order_id = (int) $order_id;
        $order_no = (string) get_post_meta($order_id, '_mluc_pay_order_no', true);
        $ref      = (string) get_post_meta($order_id, '_mluc_pay_txn_ref', true);
        $out_no   = ('' !== $order_no) ? $order_no : $ref;
        $price    = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        if ('' === $out_no || $price <= 0) {
            return new WP_Error('mluc_ali_refund', __('订单缺少支付宝交易单号，无法退款。', 'moonlight-user-center'));
        }
        $refund_amount = ($amount > 0) ? (float) $amount : $price;
        if ($refund_amount > $price) {
            return new WP_Error('mluc_ali_refund', __('退款金额不能超过订单金额。', 'moonlight-user-center'));
        }

        $params = array(
            'app_id'      => trim((string) mluc_get_option('alipay_app_id', '')),
            'method'      => 'alipay.trade.refund',
            'format'      => 'JSON',
            'charset'     => 'UTF-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => gmdate('Y-m-d H:i:s', time() + 8 * HOUR_IN_SECONDS),
            'version'     => '1.0',
            'biz_content' => wp_json_encode(array(
                'out_trade_no'   => $out_no,
                'refund_amount'  => number_format($refund_amount, 2, '.', ''),
                'out_request_no' => $out_no . 'R' . strtoupper(bin2hex(random_bytes(3))),
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $sign = self::sign($params);
        if (is_wp_error($sign)) {
            return $sign;
        }
        $params['sign'] = $sign;

        $res = wp_remote_post(self::gateway_url(), array(
            'timeout' => 30,
            'body'    => $params,
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $raw  = (string) wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        $node = isset($data['alipay_trade_refund_response']) ? $data['alipay_trade_refund_response'] : array();
        if (!self::verify_response($raw, 'alipay_trade_refund_response')) {
            return new WP_Error('mluc_ali_refund_sig', __('支付宝退款响应验签失败。', 'moonlight-user-center'));
        }
        if (empty($node) || '10000' !== (string) (isset($node['code']) ? $node['code'] : '')) {
            $msg = isset($node['sub_msg']) ? (string) $node['sub_msg'] : __('支付宝退款失败。', 'moonlight-user-center');
            MLUC_Payment_Log::write($order_id, 'alipay', 'refund', 'fail', array('message' => $msg));
            return new WP_Error('mluc_ali_refund', $msg);
        }
        MLUC_Payment_Log::write($order_id, 'alipay', 'refund', 'ok', array('amount' => (string) $refund_amount));
        update_post_meta($order_id, '_mluc_pay_refunded', current_time('mysql'));
        do_action('mluc_payment_refunded', $order_id, $refund_amount);
        return true;
    }
}
