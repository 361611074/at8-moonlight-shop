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
        // 支付宝异步 notify（REST）：网关类为自动加载懒加载，必须由常驻的支付管理器
        // 在 rest_api_init 挂载，否则 notify 请求到达时该类从未被引用、路由注册不生效。
        // permission_callback 恒真，安全由 MLSHOP_Gateway_Alipay::handle_notify 的
        // RSA2 验签 + app_id + 订单号 + 金额四重校验保证。
        add_action('rest_api_init', function () {
            if (class_exists('MLSHOP_Gateway_Alipay')) {
                MLSHOP_Gateway_Alipay::register_notify();
            }
            // 微信支付 v3 异步 notify（同上：网关类懒加载，必须由常驻管理器挂载）。
            // permission_callback 恒真，安全由 MLSHOP_Gateway_WeChat::handle_notify 的
            // 平台公钥验签 + APIv3 解密 + 商户 / 订单号 / 金额（分）/ 状态多重校验保证。
            if (class_exists('MLSHOP_Gateway_WeChat')) {
                MLSHOP_Gateway_WeChat::register_notify();
            }
        });
        // 微信支付：pending 订单被取消（超时过期 / 管理员取消）时关闭微信侧订单
        // （best-effort；已付款订单绝不关单）。网关类懒加载，钩子由常驻管理器转发。
        add_action('mlshop_order_cancelled', function ($order_id) {
            if (class_exists('MLSHOP_Gateway_WeChat')) {
                MLSHOP_Gateway_WeChat::maybe_close_order($order_id);
            }
        });
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
            new MLSHOP_Gateway_Alipay(),
            new MLSHOP_Gateway_WeChat(),
        );
        // 积分支付：受「付费/会员/积分 → 允许订单使用积分支付」总开关控制，
        // 再叠加 mlshop_payment_gateways filter 与前台公开白名单的双重过滤。
        if (mlshop_credit_pay_enabled() && class_exists('MLSHOP_Credit')) {
            $gateways[] = new MLSHOP_Gateway_Credit();
        }
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
        $builtin = array('cod', 'balance', 'credit', 'manual', 'stripe', 'paypal', 'alipay', 'wechat');
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
        // 缓存兼容：结算 / 订单详情为用户态内容，禁止页面缓存（计划书第五十九节）
        mlshop_no_cache();

        $guest_allowed = !is_user_logged_in() && mlshop_guest_checkout_enabled();
        if (!is_user_logged_in() && !$guest_allowed) {
            $login = function_exists('mluc_get_account_url') ? mluc_get_account_url() : wp_login_url(mlshop_get_page_url('checkout'));
            return '<p class="mlshop-message">' .
                /* translators: %s: 值 */
                sprintf(esc_html__('请先 %s 后再结算。', 'moonlight-shop'), '<a href="' . esc_url($login) . '">' . esc_html__('登录', 'moonlight-shop') . '</a>') .
                '</p>';
        }

        $order_id = isset($_GET['order']) ? (int) $_GET['order'] : 0;
        if ($order_id && get_post_type($order_id) === 'mlshop_order') {
            // 归属校验：订单所有者 / 管理员 / 游客订单令牌持有者可查看，
            // 防止枚举 order ID 泄露他人卡密 / 下载链接 / 收货地址。
            if (!$this->can_view_order($order_id)) {
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
            'addresses'   => class_exists('Moonlight_Address_Book')
                ? Moonlight_Address_Book::get_list(get_current_user_id())
                : array(),
            'pickup_enabled' => MLSHOP_Shipping::pickup_enabled(),
            'is_guest'    => !is_user_logged_in(),
        ));
        return ob_get_clean();
    }

    public function ajax_place_order()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');

        // 游客购买：未注册用户凭邮箱即可下单（可在设置中关闭）。
        $is_guest = !is_user_logged_in();
        if ($is_guest && !mlshop_guest_checkout_enabled()) {
            mlshop_send_json(false, __('请先登录。', 'moonlight-shop'));
        }
        $uid = get_current_user_id();

        $guest_email = '';
        if ($is_guest) {
            // 防滥用：按 IP 限流（默认每小时 10 单，可过滤覆盖）
            if (!$this->guest_rate_limit_ok()) {
                mlshop_send_json(false, __('操作过于频繁，请稍后再试。', 'moonlight-shop'));
            }
            $guest_email = isset($_POST['guest_email']) ? sanitize_email(wp_unslash($_POST['guest_email'])) : '';
            if (!$guest_email || !is_email($guest_email)) {
                mlshop_send_json(false, __('请填写有效的电子邮箱，订单确认与虚拟商品将发送到该邮箱。', 'moonlight-shop'));
            }
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
        // 余额支付依赖用户钱包，游客不可用
        if ($is_guest && 'balance' === $gateway_id) {
            mlshop_send_json(false, __('余额支付需登录后使用。', 'moonlight-shop'));
        }
        // 积分支付同样依赖登录态与积分账本，游客不可用
        if ($is_guest && 'credit' === $gateway_id) {
            mlshop_send_json(false, __('积分支付需登录后使用。', 'moonlight-shop'));
        }

        $coupon_code = isset($_POST['coupon_code']) ? sanitize_text_field($_POST['coupon_code']) : '';
        // 优惠码按用户口径核销与限用，游客下单暂不支持
        if ($is_guest && '' !== $coupon_code) {
            mlshop_send_json(false, __('优惠码需登录后使用。', 'moonlight-shop'));
        }

        // 配送方式：快递配送 / 到店自提（自提开关未开启时忽略客户端值）。
        // 是否含实物以服务端购物车条目判定，绝不信任前端。
        $items = MLSHOP_Cart::get_instance()->get_items();
        $has_physical = MLSHOP_Shipping::has_physical($items);
        $shipping_mode = 'ship';
        if ($has_physical
            && isset($_POST['shipping_mode'])
            && 'pickup' === sanitize_key($_POST['shipping_mode'])
            && MLSHOP_Shipping::pickup_enabled()) {
            $shipping_mode = 'pickup';
        }

        $shipping_address = array();
        if ($has_physical) {
            $note = isset($_POST['shipping_note']) ? sanitize_textarea_field(wp_unslash($_POST['shipping_note'])) : '';
            if ('pickup' === $shipping_mode) {
                // 自提：仅需提货人姓名 + 手机号，免运费
                $pickup_name  = isset($_POST['pickup_name']) ? sanitize_text_field(wp_unslash($_POST['pickup_name'])) : '';
                $pickup_phone = isset($_POST['pickup_phone']) ? sanitize_text_field(wp_unslash($_POST['pickup_phone'])) : '';
                if ('' === $pickup_name || '' === $pickup_phone) {
                    mlshop_send_json(false, __('请填写提货人姓名与手机号。', 'moonlight-shop'));
                }
                $shipping_address = array(
                    'name'  => $pickup_name,
                    'phone' => $pickup_phone,
                    'note'  => $note,
                    'pickup' => 1,
                );
            } else {
                // 快递配送：服务端强制校验收货字段（修复审计 M3，不再信任前端 JS 必填）
                $name     = isset($_POST['shipping_name']) ? sanitize_text_field(wp_unslash($_POST['shipping_name'])) : '';
                $phone    = isset($_POST['shipping_phone']) ? sanitize_text_field(wp_unslash($_POST['shipping_phone'])) : '';
                $detail   = isset($_POST['shipping_address']) ? sanitize_textarea_field(wp_unslash($_POST['shipping_address'])) : '';
                $province = isset($_POST['shipping_province']) ? sanitize_text_field($_POST['shipping_province']) : '';
                $city     = isset($_POST['shipping_city']) ? sanitize_text_field($_POST['shipping_city']) : '';

                // 地址簿覆盖：选了地址簿 id 时，同名表单字段一律以地址簿数据为准（防篡改）。
                // 游客无地址簿，忽略该字段。
                $address_id = isset($_POST['address_id']) ? sanitize_text_field($_POST['address_id']) : '';
                if ('' !== $address_id && $uid > 0 && class_exists('Moonlight_Address_Book')) {
                    $saved = Moonlight_Address_Book::get($uid, $address_id);
                    if (!$saved) {
                        mlshop_send_json(false, __('所选地址不存在，请重新选择。', 'moonlight-shop'));
                    }
                    $name     = $saved['name'];
                    $phone    = $saved['phone'];
                    $province = $saved['province'];
                    $city     = $saved['city'];
                    $detail   = $saved['detail'];
                }

                // 完整性校验：name / phone / 省 / 市 / detail 缺一不可
                $missing = array();
                if ('' === $name) {
                    $missing[] = __('收件人', 'moonlight-shop');
                }
                if ('' === $phone) {
                    $missing[] = __('联络电话', 'moonlight-shop');
                }
                if ('' === $province) {
                    $missing[] = __('省份', 'moonlight-shop');
                }
                if ('' === $city) {
                    $missing[] = __('城市', 'moonlight-shop');
                }
                if ('' === $detail) {
                    $missing[] = __('详细地址', 'moonlight-shop');
                }
                if (!empty($missing)) {
                    /* translators: %s: 值 */
                    mlshop_send_json(false, sprintf(__('请完整填写收货信息：%s。', 'moonlight-shop'), implode('、', $missing)));
                }

                // 区码必须能 resolve（省 + 市，且市须属于省），订单同时存区码与名称快照
                $rp = class_exists('Moonlight_Region_Provider') ? Moonlight_Region_Provider::resolve($province) : false;
                if (!$rp || 'province' !== $rp['level']) {
                    mlshop_send_json(false, __('收货省份无效，请重新选择。', 'moonlight-shop'));
                }
                $rc = class_exists('Moonlight_Region_Provider') ? Moonlight_Region_Provider::resolve($city) : false;
                if (!$rc || 'city' !== $rc['level'] || $rc['province'] !== $rp['code']) {
                    mlshop_send_json(false, __('收货城市无效，请重新选择。', 'moonlight-shop'));
                }

                $shipping_address = array(
                    'name'          => $name,
                    'phone'         => $phone,
                    'province'      => $rp['code'],
                    'province_name' => $rp['name'],
                    'city'          => $rc['code'],
                    'city_name'     => $rc['name'],
                    'address'       => $detail,
                    'note'          => $note,
                );
            }
        }

        $order_id = MLSHOP_Order::create_from_cart($uid, $gateway_id, $coupon_code, $shipping_address, array(
            'shipping_mode' => $shipping_mode,
        ));
        if (is_wp_error($order_id)) {
            mlshop_send_json(false, is_wp_error($order_id) ? $order_id->get_error_message() : __('下单失败。', 'moonlight-shop'));
        }

        // 游客订单：写入联系邮箱 + 访问令牌（订单页 / 下载 / 邮件链接均凭令牌），并计入限流
        if ($is_guest) {
            $token = wp_generate_password(48, false, false);
            update_post_meta($order_id, '_mlshop_guest_email', $guest_email);
            update_post_meta($order_id, '_mlshop_guest_token', $token);
            update_post_meta($order_id, '_mlshop_guest_created', current_time('mysql'));
            $this->guest_rate_limit_count();
        }

        $result = $gateway->process_payment($order_id);

        if (!empty($result['redirect'])) {
            $result['data'] = array('redirect' => $result['redirect']);
        } else {
            // 无跳转网关（COD / 线下等）：游客直接引导到带令牌的订单页查看结果与交付内容
            $result['data'] = array('order_url' => mlshop_order_view_url($order_id));
        }
        mlshop_send_json($result['success'], $result['message'], isset($result['data']) ? $result['data'] : array());
    }

    /**
     * 游客下单 IP 限流检查（滑动窗口：默认每小时 10 单）。
     *
     * @return bool
     */
    private function guest_rate_limit_ok()
    {
        $max = (int) apply_filters('mlshop_guest_order_rate_limit', (int) mlshop_get_option('guest_order_rate_limit', 10));
        if ($max <= 0) {
            return true; // 0 = 不限流
        }
        $count = (int) get_transient($this->guest_rate_limit_key());
        return $count < $max;
    }

    /**
     * 游客下单成功后累加限流计数（窗口 1 小时）。
     */
    private function guest_rate_limit_count()
    {
        $key = $this->guest_rate_limit_key();
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }

    private function guest_rate_limit_key()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return 'mlshop_guest_ord_' . md5($ip);
    }

    /**
     * 订单详情查看授权：管理员 / 订单所有者 / 游客订单令牌持有者。
     *
     * 游客订单（user_id = 0）不依赖登录态，凭 URL 中的访问令牌（时序安全比较）放行；
     * 登录用户订单维持「所有者或管理员」口径。
     *
     * @param int $order_id
     * @return bool
     */
    private function can_view_order($order_id)
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $owner = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if ($owner > 0) {
            return $owner === get_current_user_id();
        }
        // 游客订单：必须携带有效令牌（无令牌一律拒绝，含登录用户）
        $token = isset($_GET['token']) ? wp_unslash($_GET['token']) : '';
        return mlshop_verify_guest_token($order_id, $token);
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
            // 归属校验：订单所有者 / 管理员 / 游客订单（凭访问令牌）可触发 capture 回跳
            if (!$this->can_confirm_return($order_id)) {
                wp_safe_redirect($this->order_url($order_id));
                exit;
            }
            $stored = (string) get_post_meta($order_id, '_mlshop_paypal_order', true);
            $token  = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
            // token 防伪（修复审计 L2）：必须与下单时保存的 PayPal 单号严格一致。
            // 本地无保存值（极端遗留数据）时不再信任客户端 token，放弃 capture——
            // 仍可靠 webhook 或管理员确认完成收款，绝不因信任 URL 而扩大伪造面。
            if (!$stored || !$token || $stored !== $token) {
                wp_safe_redirect($this->order_url($order_id));
                exit;
            }
            $paypal_order_id = $stored;
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
            // 归属校验：订单所有者 / 管理员 / 游客订单（凭访问令牌）可触发回跳确认
            if (!$this->can_confirm_return($order_id)) {
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
        // 支付宝回跳：?mlshop_order=ID&gateway=alipay（支付寶同步回跳附帶簽名參數）。
        // 验签 + 服务端 alipay.trade.query 复核 + 金额比对都在网关 confirm_return() 内完成，
        // 浏览器回跳参数绝不直接作为开通依据。
        if (isset($_GET['mlshop_order'], $_GET['gateway']) && 'alipay' === $_GET['gateway']) {
            $order_id = (int) $_GET['mlshop_order'];
            if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
                return;
            }
            // 归属校验：订单所有者 / 管理员 / 游客订单（凭访问令牌）可触发回跳确认（对齐 PayPal / Stripe 分支）
            if (!$this->can_confirm_return($order_id)) {
                wp_safe_redirect($this->order_url($order_id));
                exit;
            }
            $gateway = $this->get_gateway('alipay');
            if ($gateway && method_exists($gateway, 'confirm_return')) {
                $gateway->confirm_return($order_id);
            }
            wp_safe_redirect($this->order_url($order_id));
            exit;
        }
    }

    /**
     * 支付回跳授权：管理员 / 订单所有者 / 游客订单（URL 需带有效访问令牌）。
     *
     * @param int $order_id
     * @return bool
     */
    private function can_confirm_return($order_id)
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $owner = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if ($owner > 0) {
            return $owner === get_current_user_id();
        }
        $token = isset($_GET['mlshop_gt']) ? wp_unslash($_GET['mlshop_gt']) : '';
        return mlshop_verify_guest_token($order_id, $token);
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
        return mlshop_order_view_url($order_id);
    }
}
