<?php
/**
 * 微信支付網關（API v3 自實現，無 SDK，PHP OpenSSL）。
 *
 * 設計基準：docs/PAYMENT.md 第一節網關清單 + 第四節回調安全矩陣；
 * 文件組織 / 日誌方式 / is_available 幣種門控對齊 class-gateway-alipay.php。
 *
 * 驗簽雙模式（回調 Wechatpay-Serial 選擇公鑰）：
 *  - 微信支付公鑰模式（新商戶推薦）：wechat_pub_serial + wechat_pub_key 配置即用；
 *  - 平台證書模式：未配置公鑰時自動 GET /v3/certificates 下載平台證書，
 *    用 APIv3 密鑰 AES-256-GCM 解密 encrypt_certificate，transient 緩存 12h，
 *    serial 不在已知集合時強制刷新一次。
 *
 * 流程：本地 pending 訂單 → 服務端構建支付（native = code_url 二維碼 /
 * h5 = 跳轉連結，out_trade_no = 本地訂單號）→ 用戶在微信完成支付 →
 * 異步 notify（平台公鑰驗簽 + APIv3 解密 + 訂單號 / 商戶 / 金額（分，嚴格相等）/
 * 狀態多重校驗 → mark_paid 原子完單）為唯一開通依據；
 * native 無同步回跳，訂單頁提供「我已完成支付」按鈕觸發服務端 query() 複核兜底。
 *
 * API 端點（均帶 WECHATPAY2-SHA256-RSA2048 簽名 Authorization）：
 *  - POST /v3/pay/transactions/native  下單（掃碼）
 *  - POST /v3/pay/transactions/h5     下單（H5 跳轉）
 *  - GET  /v3/pay/transactions/out-trade-no/{no}?mchid=  查單
 *  - POST /v3/pay/transactions/out-trade-no/{no}/close  關單
 *  - POST /v3/refund/domestic/refunds  退款
 *  - GET  /v3/certificates             平台證書列表
 *
 * 依賴：PHP OpenSSL 擴展（RSA-SHA256 簽名驗簽 + AES-256-GCM），
 * is_available() 缺失時網關自動隱藏。
 * 安全：私鑰 / APIv3 密鑰僅存 options（後台脫敏保存），日誌不記錄密鑰。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Gateway_WeChat extends MLSHOP_Gateway
{
    /** 微信支付 v3 API 生产网关。 */
    const API_BASE = 'https://api.mch.weixin.qq.com';

    const REST_NOTIFY = 'mlshop/v1/wechat/notify';

    /** 回调时间戳容差（秒）：微信官方建议 ±5 分钟。 */
    const NOTIFY_TS_TOLERANCE = 300;

    public function get_id()
    {
        return 'wechat';
    }

    public function get_title()
    {
        return __('微信支付 WeChat Pay', 'moonlight-shop');
    }

    public function get_description()
    {
        return __('微信掃碼（Native）/ H5 支付（僅支持人民幣 CNY 計價訂單）。', 'moonlight-shop');
    }

    /**
     * 是否启用（后台配置齐全才算启用）。
     *
     * 驗簽公鑰不在此強制：未配置微信支付公鑰時回退平台證書模式（自動下載）。
     */
    public static function enabled()
    {
        return (bool) mlshop_get_option('wechat_enabled', 0)
            && '' !== trim((string) mlshop_get_option('wechat_mchid', ''))
            && '' !== trim((string) mlshop_get_option('wechat_appid', ''))
            && '' !== trim((string) mlshop_get_option('wechat_serial_no', ''))
            && '' !== trim((string) mlshop_get_option('wechat_private_key', ''))
            && '' !== trim((string) mlshop_get_option('wechat_apiv3_key', ''));
    }

    /**
     * OpenSSL 是否可用（RSA 簽名 / AES-256-GCM 解密依賴）。
     */
    public static function openssl_available()
    {
        return function_exists('openssl_sign')
            && function_exists('openssl_verify')
            && function_exists('openssl_pkey_get_private')
            && function_exists('openssl_decrypt');
    }

    /**
     * 是否可用：配置齐全 + OpenSSL 存在 + 商城货币为 CNY（微信支付仅支持人民币）。
     */
    public function is_available()
    {
        if (!self::enabled() || !self::openssl_available()) {
            return false;
        }
        return 'CNY' === strtoupper((string) mlshop_get_option('currency', 'HKD'));
    }

    /* ---------------- RSA-SHA256 请求签名 ---------------- */

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
     * 取商户私钥资源（依次尝试 PKCS#8 / PKCS#1 封装）。
     *
     * @return resource|false
     */
    private static function private_key()
    {
        $raw = (string) mlshop_get_option('wechat_private_key', '');
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
     * 构造 v3 签名原文（五段式，末尾必带换行；body 为空时是空行）：
     *   METHOD\n{URL_PATH+QUERY}\n{timestamp}\n{nonce}\n{body}\n
     *
     * @param string $method    HTTP 方法（GET / POST）。
     * @param string $path      URL 路径 + 查询串（如 /v3/pay/...?mchid=...）。
     * @param string $timestamp Unix 秒级时间戳（字符串）。
     * @param string $nonce     随机串。
     * @param string $body      请求体（GET 为空串）。
     * @return string
     */
    public static function sign_message($method, $path, $timestamp, $nonce, $body)
    {
        return strtoupper((string) $method) . "\n"
            . (string) $path . "\n"
            . (string) $timestamp . "\n"
            . (string) $nonce . "\n"
            . (string) $body . "\n";
    }

    /**
     * 商户私钥 RSA-SHA256 签名（请求侧）。
     *
     * @param string $message 待签名原文。
     * @return string|WP_Error base64 签名。
     */
    private static function sign_with_mch_key($message)
    {
        $key = self::private_key();
        if (!$key) {
            return new WP_Error('mlshop_wx_key', __('微信支付商戶私鑰無效，請檢查格式（支持 PKCS#1 / PKCS#8）。', 'moonlight-shop'));
        }
        $signature = '';
        if (!openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('mlshop_wx_sign', __('微信支付請求簽名失敗，請檢查商戶私鑰。', 'moonlight-shop'));
        }
        return base64_encode($signature);
    }

    /**
     * RSA-SHA256 验签（公钥 PEM 文本）。
     *
     * 注：RSA 验签不能用 hash_equals（签名含随机成分，逐次不同），
     * 必须用 openssl_verify === 1。
     *
     * @param string $message       待验签原文。
     * @param string $signature_b64 base64 签名。
     * @param string $pem           公钥 PEM 文本（可含/不含头尾行）。
     * @return bool
     */
    public static function verify_signature($message, $signature_b64, $pem)
    {
        $signature_b64 = trim((string) $signature_b64);
        $pem           = (string) $pem;
        if ('' === $signature_b64 || '' === $pem) {
            return false;
        }
        $pub = openssl_pkey_get_public($pem); // 平台证书 PEM 可直接提取公钥
        if (!$pub) {
            $pub = openssl_pkey_get_public(self::to_pem($pem, 'PUBLIC KEY')); // 粘贴的无头裸公钥
        }
        if (!$pub) {
            return false;
        }
        $ok = openssl_verify((string) $message, (string) base64_decode($signature_b64), $pub, OPENSSL_ALGO_SHA256);
        return 1 === $ok;
    }

    /**
     * 构造 Authorization 头（WECHATPAY2-SHA256-RSA2048）。
     *
     * @param string $method HTTP 方法。
     * @param string $path   URL 路径 + 查询串。
     * @param string $body   请求体（GET 为空串）。
     * @return string|WP_Error
     */
    private static function build_authorization($method, $path, $body = '')
    {
        $mchid     = trim((string) mlshop_get_option('wechat_mchid', ''));
        $serial_no = trim((string) mlshop_get_option('wechat_serial_no', ''));
        $timestamp = (string) time();
        $nonce     = strtoupper(bin2hex(random_bytes(8)));
        $signature = self::sign_with_mch_key(self::sign_message($method, $path, $timestamp, $nonce, $body));
        if (is_wp_error($signature)) {
            return $signature;
        }
        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
            $mchid,
            $nonce,
            $signature,
            $timestamp,
            $serial_no
        );
    }

    /* ---------------- APIv3 密钥 AES-256-GCM ---------------- */

    /**
     * 解密 v3 回调 / 平台证书的 resource 密文（AES-256-GCM）。
     *
     * ciphertext = base64(密文 + 16 字节 tag)，tag 在末尾；
     * openssl_decrypt 的 $tag 参数按引用传入期望 tag（PHP 7.1+ 用法）。
     *
     * @param string $ciphertext base64 密文。
     * @param string $apiv3_key  APIv3 密钥（32 字节）。
     * @param string $nonce      resource.nonce。
     * @param string $aad        resource.associated_data。
     * @return string 明文（失败返回空串）。
     */
    public static function aes_gcm_decrypt($ciphertext, $apiv3_key, $nonce, $aad)
    {
        $raw  = base64_decode((string) $ciphertext, true);
        $key  = (string) $apiv3_key;
        if (false === $raw || strlen($raw) <= 16 || 32 !== strlen($key) || '' === (string) $nonce) {
            return '';
        }
        $tag   = substr($raw, -16);
        $plain = openssl_decrypt(substr($raw, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, (string) $nonce, $tag, (string) $aad);
        return false === $plain ? '' : $plain;
    }

    /* ---------------- 平台证书 / 回调验签公钥 ---------------- */

    /**
     * 平台证书列表（serial => 公钥 PEM），transient 缓存 12h。
     *
     * GET /v3/certificates → data[].encrypt_certificate 用 APIv3 密钥解密。
     *
     * @param bool $force_refresh true 时忽略缓存强制刷新（serial 不匹配时调用一次）。
     * @return array serial => pem（失败时返回已缓存内容或空数组）。
     */
    public static function platform_certs($force_refresh = false)
    {
        $key = 'mlshop_wechat_platform_certs';
        if (!$force_refresh) {
            $cached = get_transient($key);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }
        $res = self::api_request('GET', '/v3/certificates', null);
        if (is_wp_error($res) || empty($res['data']['data']) || !is_array($res['data']['data'])) {
            return array();
        }
        $apiv3_key = (string) mlshop_get_option('wechat_apiv3_key', '');
        $map = array();
        foreach ($res['data']['data'] as $item) {
            $serial = isset($item['serial_no']) ? (string) $item['serial_no'] : '';
            $enc    = isset($item['encrypt_certificate']) && is_array($item['encrypt_certificate']) ? $item['encrypt_certificate'] : array();
            if ('' === $serial || empty($enc['ciphertext'])) {
                continue;
            }
            $plain = self::aes_gcm_decrypt(
                (string) $enc['ciphertext'],
                $apiv3_key,
                isset($enc['nonce']) ? (string) $enc['nonce'] : '',
                isset($enc['associated_data']) ? (string) $enc['associated_data'] : 'certificate'
            );
            if ('' !== $plain && false !== strpos($plain, '-----BEGIN')) {
                $map[$serial] = $plain; // 平台证书 / 公钥 PEM（openssl_pkey_get_public 均可提取公钥）
            }
        }
        if (!empty($map)) {
            set_transient($key, $map, 12 * HOUR_IN_SECONDS);
        }
        return $map;
    }

    /**
     * 按回调 Wechatpay-Serial 取验签公钥 PEM。
     *
     * 优先微信支付公钥模式（wechat_pub_serial 匹配），否则平台证书；
     * serial 不在已知集合时强制刷新平台证书一次。
     *
     * @param string $serial 回调携带的证书 / 公钥序列号。
     * @return string 公钥 PEM（取不到返回空串）。
     */
    public static function verify_key_pem($serial)
    {
        $pub_serial = trim((string) mlshop_get_option('wechat_pub_serial', ''));
        $pub_key    = trim((string) mlshop_get_option('wechat_pub_key', ''));
        if ('' !== $pub_serial && '' !== $pub_key && (string) $serial === $pub_serial) {
            return $pub_key;
        }
        $certs = self::platform_certs();
        if (!isset($certs[(string) $serial])) {
            // serial 不匹配已知证书：刷新一次（轮换窗口期证书可能刚更新）
            $certs = self::platform_certs(true);
        }
        return isset($certs[(string) $serial]) ? $certs[(string) $serial] : '';
    }

    /* ---------------- API 请求 ---------------- */

    /**
     * 带 v3 签名的 API 请求。
     *
     * @param string     $method GET / POST。
     * @param string     $path   路径 + 查询串（不含域名）。
     * @param array|null $body   请求体（GET 传 null）。
     * @return array|WP_Error array{code:int, data:array}
     */
    protected static function api_request($method, $path, $body = null)
    {
        $body_json = (null === $body) ? '' : (string) wp_json_encode($body);
        $auth      = self::build_authorization($method, $path, $body_json);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $auth,
                'Accept'        => 'application/json',
                'User-Agent'    => 'moonlight-shop/' . MLSHOP_VERSION,
            ),
        );
        if (null !== $body) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = $body_json;
        }
        $res = ('GET' === strtoupper($method)) ? wp_remote_get(self::API_BASE . $path, $args) : wp_remote_post(self::API_BASE . $path, $args);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = array();
        }
        if ($code < 200 || $code > 299) {
            $msg = isset($data['message']) ? (string) $data['message'] : '';
            $err = isset($data['code']) ? (string) $data['code'] : '';
            return new WP_Error('mlshop_wx_api', trim('HTTP ' . $code . ($err ? ' ' . $err : '') . ($msg ? ' ' . $msg : '')));
        }
        return array('code' => $code, 'data' => $data);
    }

    /* ---------------- 金额 ---------------- */

    /**
     * 元 → 分（微信 v3 一律整数分）：(int) round(total * 100)。
     *
     * @param float $amount 元。
     * @return int 分。
     */
    public static function to_fen($amount)
    {
        return (int) round(((float) $amount) * 100);
    }

    /* ---------------- 下单 ---------------- */

    /**
     * 商城契约下单：
     *  - native：取 code_url 存入订单 meta，redirect 回订单页展示二维码（qr = true）；
     *  - h5：取 h5_url 直接 redirect 跳转微信支付页。
     *
     * @return array {success:bool, message:string, redirect:string, qr?:bool}
     */
    public function process_payment($order_id)
    {
        $order_id = (int) $order_id;
        $scene    = self::resolve_scene();

        if ('native' === $scene) {
            $code_url = self::create_transaction($order_id, 'native');
            if (is_wp_error($code_url)) {
                self::log($order_id, 'process', 'fail', array('scene' => 'native', 'message' => $code_url->get_error_message()));
                return array(
                    'success'  => false,
                    'message'  => $code_url->get_error_message(),
                    'redirect' => '',
                );
            }
            // 二維碼內容落 meta：訂單頁渲染 QR 圖片（code_url 不進 URL 之外的前端通道）
            update_post_meta($order_id, '_mlshop_wechat_code_url', (string) $code_url);
            update_post_meta($order_id, '_mlshop_wechat_scene', 'native');
            self::log($order_id, 'process', 'redirect', array('scene' => 'native'));

            return array(
                'success'  => true,
                'message'  => __('請使用微信掃描二維碼完成付款。', 'moonlight-shop'),
                'redirect' => $this->order_url($order_id),
                'qr'       => true,
            );
        }

        $h5_url = self::create_transaction($order_id, 'h5');
        if (is_wp_error($h5_url)) {
            self::log($order_id, 'process', 'fail', array('scene' => 'h5', 'message' => $h5_url->get_error_message()));
            return array(
                'success'  => false,
                'message'  => $h5_url->get_error_message(),
                'redirect' => '',
            );
        }
        update_post_meta($order_id, '_mlshop_wechat_scene', 'h5');
        self::log($order_id, 'process', 'redirect', array('scene' => 'h5'));

        return array(
            'success'  => true,
            'message'  => __('正在跳轉至微信支付…', 'moonlight-shop'),
            'redirect' => (string) $h5_url,
        );
    }

    /**
     * 解析支付场景：wechat_scene = auto 时移动端 h5 / 桌面 native。
     */
    public static function resolve_scene()
    {
        $scene = (string) mlshop_get_option('wechat_scene', 'auto');
        if (!in_array($scene, array('native', 'h5', 'auto'), true)) {
            $scene = 'auto';
        }
        if ('auto' === $scene) {
            $scene = (function_exists('wp_is_mobile') && wp_is_mobile()) ? 'h5' : 'native';
        }
        return $scene;
    }

    /**
     * 创建微信支付订单（native / h5）。
     *
     * @param int    $order_id 本地订单 ID。
     * @param string $scene    native / h5。
     * @return string|WP_Error native = code_url；h5 = h5_url。
     */
    public static function create_transaction($order_id, $scene)
    {
        $order_id = (int) $order_id;
        $total    = (float) get_post_meta($order_id, '_mlshop_total', true);
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order' || $total <= 0) {
            return new WP_Error('mlshop_wx_order', __('本地訂單無效。', 'moonlight-shop'));
        }
        if (!self::enabled()) {
            return new WP_Error('mlshop_wx_disabled', __('微信支付未啟用。', 'moonlight-shop'));
        }
        // 微信支付仅支持人民币：商城货币非 CNY 时拒绝发起（is_available() 同步隐藏网关）。
        if ('CNY' !== strtoupper((string) mlshop_get_option('currency', 'HKD'))) {
            return new WP_Error('mlshop_wx_currency', __('微信支付僅支持人民幣（CNY）計價訂單。', 'moonlight-shop'));
        }

        // 订单号：服务端生成（老订单兼容补齐），作为微信 out_trade_no。
        $out_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ('' === $out_no) {
            $out_no = class_exists('Moonlight_Migrations')
                ? Moonlight_Migrations::generate_order_no()
                : 'ML' . gmdate('Ymd') . strtoupper(bin2hex(random_bytes(4)));
            update_post_meta($order_id, '_mlshop_order_no', $out_no);
        }

        // 商品名：优先后台配置的展示名，否则取订单首件商品标题。
        $item_label = trim((string) mlshop_get_option('wechat_description', ''));
        if ('' === $item_label) {
            $items = get_post_meta($order_id, '_mlshop_items', true);
            if (is_array($items) && !empty($items)) {
                $first = reset($items);
                $item_label = isset($first['title']) ? (string) $first['title'] : '';
                if ('' === $item_label && !empty($first['id'])) {
                    $item_label = (string) get_the_title((int) $first['id']);
                }
            }
        }
        $description = mb_substr('' !== $item_label ? $item_label : sprintf('%s - %s', get_bloginfo('name'), $out_no), 0, 127);

        $payload = array(
            'appid'        => trim((string) mlshop_get_option('wechat_appid', '')),
            'mchid'        => trim((string) mlshop_get_option('wechat_mchid', '')),
            'description'  => $description,
            'out_trade_no' => $out_no,
            'notify_url'   => self::notify_url(),
            'amount'       => array(
                'total'    => self::to_fen($total),
                'currency' => 'CNY',
            ),
        );
        $path = '';
        if ('native' === $scene) {
            $path = '/v3/pay/transactions/native';
        } else {
            $path = '/v3/pay/transactions/h5';
            $ip   = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
            $payload['scene_info'] = array(
                'payer_client_ip' => $ip,
                'h5_info'         => array('type' => 'Wap'),
            );
        }

        // 记录商户单号（审计 + notify / 退款反查兜底）。
        update_post_meta($order_id, '_mlshop_wechat_out_trade_no', $out_no);

        $res = self::api_request('POST', $path, $payload);
        if (is_wp_error($res)) {
            return $res;
        }
        if ('native' === $scene) {
            $code_url = isset($res['data']['code_url']) ? (string) $res['data']['code_url'] : '';
            if ('' === $code_url) {
                return new WP_Error('mlshop_wx_native', __('微信支付下單失敗（未返回二維碼鏈接）。', 'moonlight-shop'));
            }
            return $code_url;
        }
        $h5_url = isset($res['data']['h5_url']) ? (string) $res['data']['h5_url'] : '';
        if ('' === $h5_url) {
            return new WP_Error('mlshop_wx_h5', __('微信支付下單失敗（未返回 H5 支付鏈接）。', 'moonlight-shop'));
        }
        return $h5_url;
    }

    /**
     * 异步通知地址（REST）。
     */
    public static function notify_url()
    {
        return rest_url(self::REST_NOTIFY);
    }

    /* ---------------- 服务端查询 ---------------- */

    /**
     * 调用查单 API：GET /v3/pay/transactions/out-trade-no/{out_trade_no}?mchid=...
     *
     * @param string $out_trade_no 本地商户单号。
     * @return array|WP_Error array{state, transaction_id, total(分), out_trade_no}
     */
    public static function query_trade($out_trade_no)
    {
        if (!self::enabled()) {
            return new WP_Error('mlshop_wx_disabled', __('微信支付未啟用。', 'moonlight-shop'));
        }
        $mchid = trim((string) mlshop_get_option('wechat_mchid', ''));
        $path  = '/v3/pay/transactions/out-trade-no/' . rawurlencode((string) $out_trade_no) . '?mchid=' . rawurlencode($mchid);
        $res   = self::api_request('GET', $path, null);
        if (is_wp_error($res)) {
            return $res;
        }
        return array(
            'state'          => isset($res['data']['trade_state']) ? (string) $res['data']['trade_state'] : '',
            'transaction_id' => isset($res['data']['transaction_id']) ? (string) $res['data']['transaction_id'] : '',
            'total'          => isset($res['data']['amount']['total']) ? (int) $res['data']['amount']['total'] : 0,
            'out_trade_no'   => isset($res['data']['out_trade_no']) ? (string) $res['data']['out_trade_no'] : '',
        );
    }

    /**
     * 状态映射：查单 / notify 的 trade_state → 本地语义。
     *
     * @return string paid / pending / failed
     */
    public static function map_trade_state($trade_state)
    {
        $state = strtoupper((string) $trade_state);
        if ('SUCCESS' === $state) {
            return 'paid';
        }
        if (in_array($state, array('CLOSED', 'REVOKED', 'PAYERROR'), true)) {
            return 'failed';
        }
        // NOTPAY / USERPAYING / READY / ACCEPT / UNKNOWN 等一律视为待支付
        return 'pending';
    }

    /**
     * 查询订单支付语义状态（回跳复核 / AJAX「我已完成支付」）：
     * trade_state === SUCCESS 且 amount.total 与本地严格相等才标记 paid。
     *
     * @param int $order_id 本地订单 ID。
     * @return array|WP_Error array{status, transaction_id, amount_ok}
     */
    public function query($order_id)
    {
        $order_id = (int) $order_id;
        $out_no   = self::resolve_out_trade_no($order_id);
        if ('' === $out_no) {
            return new WP_Error('mlshop_wx_no_order_no', __('訂單缺少微信支付商戶單號。', 'moonlight-shop'));
        }
        $q = self::query_trade($out_no);
        if (is_wp_error($q)) {
            return $q;
        }
        $status  = self::map_trade_state($q['state']);
        $amount_ok = false;
        if ('paid' === $status) {
            // 服务端复核：金额（分）必须与本地订单严格相等。
            $total = (float) get_post_meta($order_id, '_mlshop_total', true);
            $amount_ok = ((int) $q['total'] === self::to_fen($total));
        }
        return array(
            'status'         => ($status === 'paid' && !$amount_ok) ? 'mismatch' : $status,
            'transaction_id' => (string) $q['transaction_id'],
            'amount_total'   => (int) $q['total'],
        );
    }

    /**
     * 解析本地商户单号（微信 out_trade_no）：
     * 优先 _mlshop_order_no，老订单回退 _mlshop_wechat_out_trade_no。
     */
    public static function resolve_out_trade_no($order_id)
    {
        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ('' !== $order_no) {
            return $order_no;
        }
        return (string) get_post_meta($order_id, '_mlshop_wechat_out_trade_no', true);
    }

    /* ---------------- 异步通知 ---------------- */

    /**
     * 注册 REST 异步通知端点（由 MLSHOP_Payment 构造器在 rest_api_init 挂载）。
     * permission_callback 恒真：安全完全依赖平台公钥验签 + APIv3 解密 +
     * 商户号 / 订单号 / 金额（分，严格相等）/ 状态多重校验。
     */
    public static function register_notify()
    {
        register_rest_route('mlshop/v1', '/wechat/notify', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_notify'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * 异步通知处理：
     *  1) 头四件套齐全（Timestamp/Nonce/Signature/Serial）+ 时间戳 ±300s 容差；
     *  2) 平台公钥（公钥模式 / 平台证书）RSA-SHA256 验签原文 {ts}\n{nonce}\n{body}\n；
     *  3) APIv3 密钥 AES-256-GCM 解密 resource；
     *  4) 商户 / appid 比对（防跨商户伪造）；
     *  5) out_trade_no 反查订单（绝不信任回传本地 ID）；
     *  6) trade_state === SUCCESS 且 amount.total（分）与本地严格相等 → mark_paid。
     * 重复通知天然幂等（mark_paid 走商城状态机：同状态短路、非法流转被拒）。
     */
    public static function handle_notify(WP_REST_Request $request)
    {
        $fail = function ($status, $message) {
            return new WP_REST_Response(array('code' => 'FAIL', 'message' => (string) $message), (int) $status);
        };

        $body      = (string) $request->get_body();
        $timestamp = (string) $request->get_header('Wechatpay-Timestamp');
        $nonce     = (string) $request->get_header('Wechatpay-Nonce');
        $signature = (string) $request->get_header('Wechatpay-Signature');
        $serial    = (string) $request->get_header('Wechatpay-Serial');

        if ('' === $body || '' === $timestamp || '' === $nonce || '' === $signature || '' === $serial) {
            return $fail(400, 'missing headers or body');
        }
        // 重放防护：时间戳容差 ±300 秒（微信官方窗口）。
        if (abs(time() - (int) $timestamp) > self::NOTIFY_TS_TOLERANCE) {
            self::log(0, 'notify', 'fail', array('code' => 'timestamp'));
            return $fail(400, 'timestamp out of tolerance');
        }

        // 1. 验签公钥：公钥模式 / 平台证书（serial 未知时自动刷新一次）。
        $pem = self::verify_key_pem($serial);
        if ('' === $pem) {
            self::log(0, 'notify', 'fail', array('code' => 'unknown_serial'));
            return $fail(500, 'no verify key for serial');
        }
        // 2. 验签（RSA 无法 hash_equals，openssl_verify === 1）。
        if (!self::verify_signature($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature, $pem)) {
            self::log(0, 'notify', 'fail', array('code' => 'signature'));
            return $fail(401, 'signature verification failed');
        }

        // 3. 解密 resource（APIv3 密钥 AES-256-GCM）。
        $payload = json_decode($body, true);
        if (!is_array($payload) || empty($payload['resource']) || !is_array($payload['resource'])) {
            return $fail(400, 'malformed payload');
        }
        $resource = $payload['resource'];
        $plain    = self::aes_gcm_decrypt(
            isset($resource['ciphertext']) ? (string) $resource['ciphertext'] : '',
            (string) mlshop_get_option('wechat_apiv3_key', ''),
            isset($resource['nonce']) ? (string) $resource['nonce'] : '',
            isset($resource['associated_data']) ? (string) $resource['associated_data'] : ''
        );
        if ('' === $plain) {
            self::log(0, 'notify', 'fail', array('code' => 'decrypt'));
            return $fail(500, 'resource decrypt failed');
        }
        $data = json_decode($plain, true);
        if (!is_array($data) || empty($data['out_trade_no'])) {
            return $fail(400, 'malformed resource');
        }

        // 4. 商户身份：appid / mchid 必须与本站配置一致（防跨商户伪造）。
        $appid = trim((string) mlshop_get_option('wechat_appid', ''));
        $mchid = trim((string) mlshop_get_option('wechat_mchid', ''));
        if ((isset($data['appid']) && (string) $data['appid'] !== $appid)
            || (isset($data['mchid']) && (string) $data['mchid'] !== $mchid)) {
            return $fail(400, 'merchant mismatch');
        }

        // 5. 订单定位：out_trade_no = 本地订单号（绝不信任回传的本地 ID）。
        $order_id = (int) static::find_order_by_order_no((string) $data['out_trade_no']);
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order') {
            return $fail(404, 'order not found');
        }
        if ('wechat' !== (string) get_post_meta($order_id, '_mlshop_gateway', true)) {
            return $fail(400, 'gateway mismatch');
        }

        $state  = self::map_trade_state(isset($data['trade_state']) ? $data['trade_state'] : '');
        $txn_id = isset($data['transaction_id']) ? (string) $data['transaction_id'] : '';

        self::log($order_id, 'notify', $state, array(
            'transaction_id' => $txn_id,
            'state'          => isset($data['trade_state']) ? (string) $data['trade_state'] : '',
        ));

        // 6. 成功状态才完单；金额（分）与本地严格相等，不符绝不完单（记录审计 meta）。
        if ('paid' === $state) {
            $total      = (float) get_post_meta($order_id, '_mlshop_total', true);
            $notify_fen = isset($data['amount']['total']) ? (int) $data['amount']['total'] : -1;
            if ($notify_fen !== self::to_fen($total)) {
                update_post_meta($order_id, '_mlshop_pay_amount_mismatch', sprintf('wechat:%s', (string) $notify_fen));
                self::log($order_id, 'notify', 'fail', array('code' => 'amount', 'amount' => (string) $notify_fen));
                return $fail(400, 'amount mismatch');
            }
            // 幂等由商城状态机保证（pending→paid / processing→paid，重复通知短路）。
            MLSHOP_Order::mark_paid($order_id, 'wechat', $txn_id);
        }

        // 非 SUCCESS 状态（NOTPAY 等中间态）同样回 SUCCESS 停止重试。
        return new WP_REST_Response(array('code' => 'SUCCESS'), 200);
    }

    /**
     * 按商户单号反查订单（_mlshop_order_no；protected static 便于测试注入桩）。
     */
    protected static function find_order_by_order_no($order_no)
    {
        global $wpdb;
        if ('' === $order_no || strlen($order_no) > 64) {
            return 0;
        }
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mlshop_order_no' AND meta_value = %s LIMIT 1",
            (string) $order_no
        ));
        return (int) $found;
    }

    /* ---------------- 退款 ---------------- */

    /**
     * 微信支付退款（POST /v3/refund/domestic/refunds）。
     *
     * @param int    $order_id 订单 ID。
     * @param float  $amount   退款金额（0 = 全额退）。
     * @param string $reason   退款原因（写入 reason）。
     * @return array {success:bool, refund_id?:string, message:string}
     */
    public function refund($order_id, $amount = 0, $reason = '')
    {
        $order_id  = (int) $order_id;
        $out_no    = self::resolve_out_trade_no($order_id);
        $total_fen = self::to_fen((float) get_post_meta($order_id, '_mlshop_total', true));
        if ('' === $out_no || $total_fen <= 0) {
            return array('success' => false, 'message' => __('訂單缺少微信支付商戶單號，無法退款。', 'moonlight-shop'));
        }
        $refund_fen = ($amount > 0) ? self::to_fen($amount) : $total_fen;
        if ($refund_fen <= 0 || $refund_fen > $total_fen) {
            return array('success' => false, 'message' => __('退款金額不能超過訂單金額。', 'moonlight-shop'));
        }

        // 商户退款单号：RF + 订单号 + 4 位随机（同一订单多次部分退款需唯一）。
        $out_refund_no = 'RF' . $out_no . strtoupper(bin2hex(random_bytes(2)));

        $body = array(
            'out_trade_no'  => $out_no,
            'out_refund_no' => $out_refund_no,
            'amount'        => array(
                'refund'   => $refund_fen,
                'total'    => $total_fen,
                'currency' => 'CNY',
            ),
        );
        $reason = trim((string) $reason);
        if ('' !== $reason) {
            $body['reason'] = mb_substr($reason, 0, 80);
        }

        $res = self::api_request('POST', '/v3/refund/domestic/refunds', $body);
        if (is_wp_error($res)) {
            self::log($order_id, 'refund', 'fail', array('message' => $res->get_error_message()));
            return array('success' => false, 'message' => $res->get_error_message());
        }
        $refund_id = isset($res['data']['refund_id']) ? (string) $res['data']['refund_id'] : '';
        if ('' === $refund_id) {
            self::log($order_id, 'refund', 'fail', array('message' => 'no refund_id'));
            return array('success' => false, 'message' => __('微信退款失敗（未返回退款單號）。', 'moonlight-shop'));
        }
        self::log($order_id, 'refund', 'ok', array('refund_id' => $refund_id, 'amount' => (string) ($refund_fen / 100)));
        update_post_meta($order_id, '_mlshop_wechat_refund_no', $out_refund_no);
        do_action('mlshop_wechat_refunded', $order_id, $refund_fen / 100, $out_refund_no);
        return array(
            'success'   => true,
            'refund_id' => $refund_id,
            'message'   => __('退款成功。', 'moonlight-shop'),
        );
    }

    /* ---------------- 关单 ---------------- */

    /**
     * 关闭微信侧订单（POST /v3/pay/transactions/out-trade-no/{no}/close）。
     *
     * @param int $order_id 订单 ID。
     * @return bool 是否成功（best-effort，失败仅记日志）。
     */
    public function close($order_id)
    {
        $order_id = (int) $order_id;
        $out_no   = self::resolve_out_trade_no($order_id);
        if ('' === $out_no || !self::enabled()) {
            return false;
        }
        $res = self::api_request('POST', '/v3/pay/transactions/out-trade-no/' . rawurlencode($out_no) . '/close', array(
            'mchid' => trim((string) mlshop_get_option('wechat_mchid', '')),
        ));
        if (is_wp_error($res)) {
            self::log($order_id, 'close', 'fail', array('message' => $res->get_error_message()));
            return false;
        }
        self::log($order_id, 'close', 'ok', array());
        return true;
    }

    /**
     * 订单取消（pending 超时过期 / 管理员取消）时关闭微信侧订单：
     * 由 MLSHOP_Payment 在 mlshop_order_cancelled 钩子调用（网关类懒加载，
     * 自身挂钩来不及）。已付款订单（有 payment_id）绝不关单。
     */
    public static function maybe_close_order($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0
            || 'wechat' !== (string) get_post_meta($order_id, '_mlshop_gateway', true)
            || '' !== (string) get_post_meta($order_id, '_mlshop_payment_id', true)) {
            return;
        }
        if (!self::enabled()) {
            return;
        }
        $gateway = new self();
        $gateway->close($order_id);
    }

    /* ---------------- 日志 ---------------- */

    /**
     * 轻量日志（对齐支付宝网关）：
     * WP_DEBUG 开启时写 error_log，并触发 mlshop_wechat_log 钩子供外部审计。
     * 只记录订单号 / 交易号 / 金额等白名单字段，绝不记录密钥。
     */
    private static function log($order_id, $scene, $status, array $context = array())
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[mlshop-wechat] order=%d scene=%s status=%s ctx=%s', (int) $order_id, (string) $scene, (string) $status, wp_json_encode($context)));
        }
        do_action('mlshop_wechat_log', (int) $order_id, (string) $scene, (string) $status, $context);
    }
}
