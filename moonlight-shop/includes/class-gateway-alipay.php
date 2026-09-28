<?php
/**
 * 支付寶網關：電腦網站支付（alipay.trade.page.pay，跳轉式），RSA2 簽名。
 *
 * 移植來源：moonlight-user-center/includes/class-gateway-alipay.php
 * （MLUC_Gateway_Alipay，實現 MLUC_Payment_Gateway_Interface）。
 * RSA2 簽名 / 驗簽、PEM 歸一化、響應體驗簽邏輯逐行保留；
 * 以下為本次適配點：
 *  - 改為 extends MLSHOP_Gateway（商城網關基類契約：
 *    get_id / get_title / get_description / process_payment）。
 *  - 訂單模型換為商城鍵：_mlshop_order_no（out_trade_no）、_mlshop_total（金額，
 *    ±0.01 比對）、_mlshop_gateway、_mlshop_payment_id（支付寶交易號）、
 *    _mlshop_status（狀態機）；另記 _mlshop_alipay_out_trade_no 作反查兜底。
 *  - 完單改調 MLSHOP_Order::mark_paid($order_id, 'alipay', $trade_no)，
 *    冪等由商城狀態機保證（同狀態短路、非法流轉被拒）；不再調用 MLUC_* 類。
 *  - notify 路由註冊到 REST mlshop/v1/alipay/notify（由 MLSHOP_Payment 構造器
 *    統一掛載 rest_api_init——網關類為自動加載懶載入，自身掛鉤會來不及）。
 *  - 回跳適配為商城模式 ?mlshop_order=ID&gateway=alipay（GET 跳轉 URL），
 *    由 MLSHOP_Payment::maybe_handle_return 分發（歸屬校驗在分發層），
 *    本類 confirm_return() 負責驗簽 + alipay.trade.query 服務端複核。
 *  - 退款簽名適配商城習慣：refund($order_id, $amount = 0, $reason = '')
 *    返回 array{success, refund_id?, message}。
 *  - 配置讀取改 mlshop_get_option()（alipay_enabled / alipay_app_id /
 *    alipay_private_key / alipay_public_key / alipay_mode）；商場貨幣非 CNY 時
 *    is_available() 返回 false（支付寶僅支持人民幣結算）。
 *  - 日誌：MLUC_Payment_Log 改為本類輕量 log()（WP_DEBUG 時 error_log +
 *    mlshop_alipay_log 鉤子），只記訂單號 / 交易號等白名單字段。
 *
 * 流程：本地 pending 訂單 → 服務端構建跳轉 URL（out_trade_no = 本地訂單號）
 * → 用戶在支付寶完成支付 → 異步 notify（驗簽 + 金額 + 訂單號 + 狀態四重校驗
 * → mark_paid 原子完單）為唯一開通依據；
 * 瀏覽器回跳僅驗簽 + 服務端 alipay.trade.query 複核後展示結果，不直接開通。
 *
 * 依賴：PHP OpenSSL 擴展（RSA2 簽名/驗簽），is_available() 缺失時網關自動隱藏。
 * 安全：私鑰僅存 options，日誌不記錄密鑰。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_Alipay extends MLSHOP_Gateway
{
    /** 生产网关。 */
    const GATEWAY_PRODUCTION = 'https://openapi.alipay.com/gateway.do';
    /** 沙盒网关（支付宝开放平台沙箱环境）。 */
    const GATEWAY_SANDBOX = 'https://openapi-sandbox.dl.alipaydev.com/gateway.do';

    const REST_NOTIFY = 'mlshop/v1/alipay/notify';

    public function get_id()
    {
        return 'alipay';
    }

    public function get_title()
    {
        return __('支付寶 Alipay', 'moonlight-shop');
    }

    public function get_description()
    {
        return __('跳轉至支付寶完成付款（僅支持人民幣 CNY 計價訂單）。', 'moonlight-shop');
    }

    /**
     * 是否启用（后台配置齐全才算启用）。
     */
    public static function enabled()
    {
        return (bool) mlshop_get_option('alipay_enabled', 0)
            && '' !== trim((string) mlshop_get_option('alipay_app_id', ''))
            && '' !== trim((string) mlshop_get_option('alipay_private_key', ''))
            && '' !== trim((string) mlshop_get_option('alipay_public_key', ''));
    }

    /**
     * OpenSSL 是否可用（RSA2 依赖）。
     */
    public static function openssl_available()
    {
        return function_exists('openssl_sign') && function_exists('openssl_verify') && function_exists('openssl_pkey_get_private');
    }

    /**
     * 是否可用：配置齐全 + OpenSSL 存在 + 商城货币为 CNY（支付宝仅支持人民币）。
     */
    public function is_available()
    {
        if (!self::enabled() || !self::openssl_available()) {
            return false;
        }
        return 'CNY' === strtoupper((string) mlshop_get_option('currency', 'HKD'));
    }

    /**
     * 网关地址（沙盒 / 生产，可经过滤器替换）。
     */
    public static function gateway_url()
    {
        $mode = ('sandbox' === mlshop_get_option('alipay_mode', 'sandbox')) ? self::GATEWAY_SANDBOX : self::GATEWAY_PRODUCTION;
        return apply_filters('mlshop_alipay_gateway_url', $mode, mlshop_get_option('alipay_mode', 'sandbox'));
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
        $raw = (string) mlshop_get_option('alipay_private_key', '');
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
        $pem = self::to_pem((string) mlshop_get_option('alipay_public_key', ''), 'PUBLIC KEY');
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
            return new WP_Error('mlshop_ali_key', __('支付寶應用私鑰無效，請檢查格式（支持 PKCS#1 / PKCS#8）。', 'moonlight-shop'));
        }
        $signature = '';
        if (!openssl_sign(self::sign_content($params), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('mlshop_ali_sign', __('支付寶請求簽名失敗，請檢查應用私鑰。', 'moonlight-shop'));
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

    /**
     * 商城契约下单：返回 array{success, message, redirect?}，redirect 为
     * alipay.trade.page.pay 的 GET 跳转 URL（前台 JS 拿 data.redirect 跳转）。
     */
    public function process_payment($order_id)
    {
        $url = self::build_pay_url((int) $order_id);
        if (is_wp_error($url)) {
            self::log((int) $order_id, 'process', 'fail', array('code' => 'build_url', 'message' => $url->get_error_message()));
            return array(
                'success'  => false,
                'message'  => $url->get_error_message(),
                'redirect' => '',
            );
        }
        self::log((int) $order_id, 'process', 'redirect');

        return array(
            'success'  => true,
            'message'  => __('正在跳轉至支付寶…', 'moonlight-shop'),
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
        $total    = (float) get_post_meta($order_id, '_mlshop_total', true);
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order' || $total <= 0) {
            return new WP_Error('mlshop_ali_order', __('本地訂單無效。', 'moonlight-shop'));
        }
        if (!self::enabled()) {
            return new WP_Error('mlshop_ali_disabled', __('支付寶支付未啟用。', 'moonlight-shop'));
        }
        // 支付宝仅支持人民币：商城货币非 CNY 时拒绝发起（is_available() 同步隐藏网关）。
        if ('CNY' !== strtoupper((string) mlshop_get_option('currency', 'HKD'))) {
            return new WP_Error('mlshop_ali_currency', __('支付寶僅支持人民幣（CNY）計價訂單。', 'moonlight-shop'));
        }

        // 订单号：服务端生成（老订单兼容补齐），作为支付宝 out_trade_no。
        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ('' === $order_no) {
            $order_no = class_exists('Moonlight_Migrations')
                ? Moonlight_Migrations::generate_order_no()
                : 'ML' . gmdate('Ymd') . strtoupper(bin2hex(random_bytes(4)));
            update_post_meta($order_id, '_mlshop_order_no', $order_no);
        }

        // 商品名：取订单首件商品标题（充值/升级订单则为其条目名）。
        $item_label = '';
        $items = get_post_meta($order_id, '_mlshop_items', true);
        if (is_array($items) && !empty($items)) {
            $first = reset($items);
            $item_label = isset($first['title']) ? (string) $first['title'] : '';
            if ('' === $item_label && !empty($first['id'])) {
                $item_label = (string) get_the_title((int) $first['id']);
            }
        }

        $params = array(
            'app_id'      => trim((string) mlshop_get_option('alipay_app_id', '')),
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
                'total_amount' => number_format($total, 2, '.', ''),
                'subject'      => mb_substr(sprintf('%s - %s', get_bloginfo('name'), $item_label), 0, 120),
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $sign = self::sign($params);
        if (is_wp_error($sign)) {
            return $sign;
        }
        $params['sign'] = $sign;

        // 记录商户单号（审计 + notify / 退款反查兜底）。
        update_post_meta($order_id, '_mlshop_alipay_out_trade_no', $order_no);
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
     * 支付完成回跳地址（与 Stripe / PayPal 同一分发入口：
     * MLSHOP_Payment::maybe_handle_return，归属校验在分发层完成）。
     */
    private static function return_url($order_id)
    {
        $args = array(
            'mlshop_order' => (int) $order_id,
            'gateway'      => 'alipay',
        );
        // 游客订单回跳携带访问令牌（回跳授权校验用；验签前会被 mlshop 前缀剔除，不影响签名）
        $gt = (string) get_post_meta($order_id, '_mlshop_guest_token', true);
        if ('' !== $gt) {
            $args['mlshop_gt'] = $gt;
        }
        return add_query_arg($args, home_url('/'));
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
            return new WP_Error('mlshop_ali_disabled', __('支付寶支付未啟用。', 'moonlight-shop'));
        }
        $params = array(
            'app_id'      => trim((string) mlshop_get_option('alipay_app_id', '')),
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
            return new WP_Error('mlshop_ali_query', __('支付寶訂單查詢失敗。', 'moonlight-shop'));
        }
        // 响应验签：不信任未签名结果（提取原始 JSON 子串按支付宝公钥复核）。
        if (!self::verify_response($raw, 'alipay_trade_query_response')) {
            return new WP_Error('mlshop_ali_query_sig', __('支付寶查詢響應驗簽失敗。', 'moonlight-shop'));
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

    /**
     * 查询订单支付语义状态（paid / pending / failed），供后台与调试使用。
     */
    public function query_payment($order_id)
    {
        $order_id = (int) $order_id;
        $out_no   = self::resolve_out_trade_no($order_id);
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

    /**
     * 支付宝回跳处理（由 MLSHOP_Payment::maybe_handle_return 在 init 分发，
     * 订单归属校验在分发层完成）：
     * 验签 → 服务端 alipay.trade.query 复核真实状态 → 金额一致才完单。
     * 浏览器回跳参数绝不直接作为开通依据。
     *
     * @param int $order_id
     * @return bool 最终订单是否处于已付款语义状态
     */
    public function confirm_return($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order'
            || 'alipay' !== (string) get_post_meta($order_id, '_mlshop_gateway', true)) {
            return false;
        }

        // 回跳参数验签（防伪造回跳），随后仍以服务端查询结果为准。
        $params = array();
        foreach ($_GET as $k => $v) {
            $params[(string) $k] = sanitize_text_field(wp_unslash($v));
        }
        // 剔除站方自有参数（审计 F4）：支付宝只对「它回传的参数」签名，
        // return_url 上商户自带的 query 参数不在签名原文内，混入会导致验签恒失败。
        // 仅剔除本插件与常见路由参数；其他插件注入的参数仍可能导致验签失败
        //（fail-closed，完单主通道为异步 notify，不受影响）。
        foreach (array_keys($params) as $k) {
            if (0 === strpos((string) $k, 'mlshop') || in_array((string) $k, array('gateway', 'action', 'p', 'page_id', 'preview'), true)) {
                unset($params[$k]);
            }
        }
        if (!self::verify($params)) {
            self::log($order_id, 'return', 'fail', array('code' => 'signature'));
            return false;
        }

        $out_no = self::resolve_out_trade_no($order_id);
        if ('' === $out_no) {
            return false;
        }
        $q = self::query_trade($out_no);
        if (is_wp_error($q)) {
            self::log($order_id, 'return', 'fail', array('code' => 'query', 'message' => $q->get_error_message()));
            return false;
        }
        if ('paid' === self::map_status($q['status'])) {
            // 完单前金额复核：查询到的金额必须与本地订单一致（±0.01）。
            $total = (float) get_post_meta($order_id, '_mlshop_total', true);
            if ($q['amount'] > 0 && abs($q['amount'] - $total) <= 0.01) {
                // 幂等由商城状态机保证（mark_paid → set_status）。
                $res = MLSHOP_Order::mark_paid($order_id, 'alipay', $q['trade_no']);
                if (is_wp_error($res)) {
                    self::log($order_id, 'return', 'fail', array('code' => 'transition', 'message' => $res->get_error_message()));
                }
            } else {
                update_post_meta($order_id, '_mlshop_pay_amount_mismatch', sprintf('alipay:%s', (string) $q['amount']));
            }
        }
        self::log($order_id, 'return', (string) MLSHOP_Order::get_status($order_id), array(
            'trade_no' => isset($q['trade_no']) ? (string) $q['trade_no'] : '',
        ));

        return in_array((string) MLSHOP_Order::get_status($order_id), array('paid', 'processing', 'completed'), true);
    }

    /**
     * 解析本地商户单号（支付宝 out_trade_no）：
     * 优先 _mlshop_order_no，老订单回退 _mlshop_alipay_out_trade_no。
     */
    private static function resolve_out_trade_no($order_id)
    {
        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ('' !== $order_no) {
            return $order_no;
        }
        return (string) get_post_meta($order_id, '_mlshop_alipay_out_trade_no', true);
    }

    /* ---------------- 异步通知 ---------------- */

    /**
     * 注册 REST 异步通知端点（由 MLSHOP_Payment 构造器在 rest_api_init 挂载）。
     * permission_callback 恒真：安全完全依赖 RSA2 验签 + app_id + 订单号 + 金额四重校验。
     */
    public static function register_notify()
    {
        register_rest_route('mlshop/v1', '/alipay/notify', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_notify'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * 异步通知处理：验签 → 商户 / 订单 / 金额 / 状态四重校验 → mark_paid 原子完单 → success。
     * 重复通知天然幂等（mark_paid 内部走商城状态机：同状态短路、非法流轉被拒）。
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
            self::log(0, 'notify', 'fail', array('code' => 'signature'));
            return new WP_REST_Response('fail', 200);
        }

        // 2. 商户身份：app_id 必须与本站配置一致（防跨商户伪造）。
        $app_id = trim((string) mlshop_get_option('alipay_app_id', ''));
        if (!isset($params['app_id']) || (string) $params['app_id'] !== $app_id) {
            return new WP_REST_Response('fail', 200);
        }

        // 3. 订单定位：out_trade_no = 本地订单号（绝不信任回传的本地 ID）。
        global $wpdb;
        $order_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mlshop_order_no' AND meta_value = %s LIMIT 1",
            (string) $params['out_trade_no']
        ));
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order') {
            // 兼容兜底：按下单时写入的商户单号反查（老订单 order_no 缺失场景）。
            $order_id = self::find_order_by_ref((string) $params['out_trade_no']);
        }
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order') {
            return new WP_REST_Response('fail', 200);
        }
        if ('alipay' !== (string) get_post_meta($order_id, '_mlshop_gateway', true)) {
            return new WP_REST_Response('fail', 200);
        }

        $status = self::map_status(isset($params['trade_status']) ? $params['trade_status'] : '');

        // 4. 金额复核：通知金额必须与本地订单一致（防篡改，±0.01）。
        $total  = (float) get_post_meta($order_id, '_mlshop_total', true);
        $amount = isset($params['total_amount']) ? (float) $params['total_amount'] : -1;
        if ($amount < 0 || abs($amount - $total) > 0.01) {
            update_post_meta($order_id, '_mlshop_pay_amount_mismatch', sprintf('alipay:%s', (string) $amount));
            self::log($order_id, 'notify', 'fail', array('code' => 'amount', 'amount' => (string) $amount));
            return new WP_REST_Response('fail', 200);
        }

        self::log($order_id, 'notify', $status, array(
            'trade_no' => isset($params['trade_no']) ? (string) $params['trade_no'] : '',
            'amount'   => (string) $amount,
        ));

        // 5. 成功状态才完单（TRADE_SUCCESS / TRADE_FINISHED）；closed 等回复 success 停止重试。
        //    幂等由商城状态机保证：pending→paid / processing→paid 胜出，
        //    paid→paid 短路返回 true，completed/refunded/cancelled→paid 被状态机拒绝。
        if ('paid' === $status) {
            $txn = isset($params['trade_no']) ? (string) $params['trade_no'] : '';
            MLSHOP_Order::mark_paid($order_id, 'alipay', $txn);
        }

        return new WP_REST_Response('success', 200);
    }

    /**
     * 按商户单号兜底查找订单（历史订单 order_no 缺失时）。
     */
    private static function find_order_by_ref($ref)
    {
        global $wpdb;
        if ('' === $ref || strlen($ref) > 64) {
            return 0;
        }
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mlshop_alipay_out_trade_no' AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
            $ref
        ));
        return (int) $found;
    }

    /* ---------------- 退款 ---------------- */

    /**
     * 支付宝退款（alipay.trade.refund）。
     *
     * @param int    $order_id 订单 ID。
     * @param float  $amount   退款金额（0 = 全额退）。
     * @param string $reason   退款原因（写入 refund_reason）。
     * @return array {success:bool, refund_id?:string, message:string}
     */
    public function refund($order_id, $amount = 0, $reason = '')
    {
        $order_id = (int) $order_id;
        $out_no   = self::resolve_out_trade_no($order_id);
        $total    = (float) get_post_meta($order_id, '_mlshop_total', true);
        if ('' === $out_no || $total <= 0) {
            return array('success' => false, 'message' => __('訂單缺少支付寶商戶單號，無法退款。', 'moonlight-shop'));
        }
        $refund_amount = ($amount > 0) ? (float) $amount : $total;
        if ($refund_amount > $total) {
            return array('success' => false, 'message' => __('退款金額不能超過訂單金額。', 'moonlight-shop'));
        }

        // 交易号：优先取完单时写入的 _mlshop_payment_id（alipay 交易号），
        // 缺失时用 alipay.trade.query 反查；仍取不到则回退 out_trade_no 发起。
        $trade_no = (string) get_post_meta($order_id, '_mlshop_payment_id', true);
        if ('' === $trade_no) {
            $q = self::query_trade($out_no);
            if (!is_wp_error($q) && !empty($q['trade_no'])) {
                $trade_no = (string) $q['trade_no'];
            }
        }

        $out_request_no = $out_no . 'R' . strtoupper(bin2hex(random_bytes(3)));
        $biz = array(
            'out_request_no' => $out_request_no,
            'refund_amount'  => number_format($refund_amount, 2, '.', ''),
        );
        if ('' !== $trade_no) {
            $biz['trade_no'] = $trade_no;
        } else {
            $biz['out_trade_no'] = $out_no;
        }
        $reason = trim((string) $reason);
        if ('' !== $reason) {
            $biz['refund_reason'] = mb_substr($reason, 0, 256);
        }

        $params = array(
            'app_id'      => trim((string) mlshop_get_option('alipay_app_id', '')),
            'method'      => 'alipay.trade.refund',
            'format'      => 'JSON',
            'charset'     => 'UTF-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => gmdate('Y-m-d H:i:s', time() + 8 * HOUR_IN_SECONDS),
            'version'     => '1.0',
            'biz_content' => wp_json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $sign = self::sign($params);
        if (is_wp_error($sign)) {
            return array('success' => false, 'message' => $sign->get_error_message());
        }
        $params['sign'] = $sign;

        $res = wp_remote_post(self::gateway_url(), array(
            'timeout' => 30,
            'body'    => $params,
        ));
        if (is_wp_error($res)) {
            return array('success' => false, 'message' => $res->get_error_message());
        }
        $raw  = (string) wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        $node = isset($data['alipay_trade_refund_response']) ? $data['alipay_trade_refund_response'] : array();
        if (!self::verify_response($raw, 'alipay_trade_refund_response')) {
            return array('success' => false, 'message' => __('支付寶退款響應驗簽失敗。', 'moonlight-shop'));
        }
        if (empty($node) || '10000' !== (string) (isset($node['code']) ? $node['code'] : '')) {
            $msg = isset($node['sub_msg']) ? (string) $node['sub_msg'] : __('支付寶退款失敗。', 'moonlight-shop');
            self::log($order_id, 'refund', 'fail', array('message' => $msg));
            return array('success' => false, 'message' => $msg);
        }
        self::log($order_id, 'refund', 'ok', array('amount' => (string) $refund_amount, 'refund_no' => $out_request_no));
        update_post_meta($order_id, '_mlshop_alipay_refunded', current_time('mysql'));
        update_post_meta($order_id, '_mlshop_alipay_refund_no', $out_request_no);
        do_action('mlshop_alipay_refunded', $order_id, $refund_amount, $out_request_no);
        return array(
            'success'   => true,
            'refund_id' => $out_request_no,
            'message'   => __('退款成功。', 'moonlight-shop'),
        );
    }

    /* ---------------- 日志 ---------------- */

    /**
     * 轻量日志（替代用户中心 MLUC_Payment_Log）：
     * WP_DEBUG 开启时写 error_log，并触发 mlshop_alipay_log 钩子供外部审计。
     * 只记录订单号 / 交易号 / 金额等白名单字段，绝不记录密钥。
     */
    private static function log($order_id, $scene, $status, array $context = array())
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[mlshop-alipay] order=%d scene=%s status=%s ctx=%s', (int) $order_id, (string) $scene, (string) $status, wp_json_encode($context)));
        }
        do_action('mlshop_alipay_log', (int) $order_id, (string) $scene, (string) $status, $context);
    }
}
