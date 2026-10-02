<?php
/**
 * AT8 授权中心桥接（P6/P6+）：商城支付成功 → 授权中心自动发码（规划书 §42/§43）。
 *
 * 流程：mlshop_order_paid → 读取订单商品上的授权映射（_at8lic_product/_at8lic_plan）
 *       → 签名调用授权中心 /admin/order/paid（幂等）→ 授权码交付给客户
 *       → 退款 mlshop_order_refunded → /admin/order/refund 自动吊销。
 *
 * 交付规则：
 *   - 登录用户：邮件含明文授权码 + Pro 安装包下载链接（签名短时 URL）；
 *   - 游客订单：授权照常签发（绑定购买邮箱，授权中心自动建号），
 *     邮件不含明文码，引导客户「设置密码 / 登录」后到「我的授权」查看。
 *
 * 套餐选择与多货币：
 *   - [at8lic_buy product="at8-security-pro"] 渲染一年/三年/终身购买按钮
 *     （每个按钮对应一个隐藏履约商品，购物车/订单/履约链路零改动）；
 *   - [at8lic_store] 渲染展示商品列表；[at8lic_currency] 渲染 ¥/$ 切换；
 *   - USD 模式（cookie at8lic_currency=USD）经 moonlight_product_price 过滤器
 *     按商品 _at8lic_price_usd 结算，PayPal/Stripe 网关自动以 currency_code=USD 收款。
 *
 * 配置：设置 → 「AT8 授权桥接」：授权中心地址 + 管理密钥（见授权中心「设置」页）。
 * 商品映射：编辑商品（mlshop_product）→ 「授权中心映射」meta box。
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_License_Bridge
{
    const FULFILLED_META = '_at8lic_fulfilled';
    const ERROR_META     = '_at8lic_fulfill_error';
    const OPT_SERVER     = 'mlshop_at8lic_server';
    const OPT_SECRET     = 'mlshop_at8lic_secret';
    const CURRENCY_COOKIE = 'at8lic_currency';

    public static function boot()
    {
        $i = new self();
        add_action('mlshop_order_paid', array($i, 'fulfill_order'), 30);
        add_action('mlshop_order_refunded', array($i, 'refund_order'), 10);
        add_action('admin_menu', array($i, 'menu'));
        add_action('admin_init', array($i, 'settings'));
        add_action('add_meta_boxes', array($i, 'metabox'));
        add_action('save_post_mlshop_product', array($i, 'save_metabox'), 10, 2);
        // 履约失败的人工重试入口（订单列表行操作）
        add_filter('post_row_actions', array($i, 'row_action'), 20, 2);
        add_action('admin_post_mlshop_retry_fulfill', array($i, 'handle_retry'));

        // 套餐购买 / 商店展示 / 货币切换
        add_shortcode('at8lic_buy', array($i, 'shortcode_buy'));
        add_shortcode('at8lic_store', array($i, 'shortcode_store'));
        add_shortcode('at8lic_currency', array($i, 'shortcode_currency'));

        // USD 模式：价格与货币符号覆盖（商品无美元价时按汇率换算）
        add_filter('pre_option_mlshop_currency', array($i, 'filter_currency_code'));
        add_filter('pre_option_mlshop_currency_symbol', array($i, 'filter_currency_symbol'));
        add_filter('moonlight_product_price', array($i, 'filter_product_price'), 10, 2);

        // ?at8lic_currency=USD|CNY 切换（写 cookie，30 天）
        add_action('init', array($i, 'handle_currency_switch'));
    }

    public function handle_currency_switch()
    {
        if (!isset($_GET['at8lic_currency'])) {
            return;
        }
        $cur = strtoupper((string) $_GET['at8lic_currency']);
        if (!in_array($cur, array('CNY', 'USD'), true) || headers_sent()) {
            return;
        }
        setcookie(self::CURRENCY_COOKIE, $cur, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
        $_COOKIE[self::CURRENCY_COOKIE] = $cur;
    }

    /** 订单列表：履约失败且未成功的订单显示"重试授权发码" */
    public function row_action($actions, $post)
    {
        if (($post->post_type ?? '') !== 'mlshop_order' || !current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return $actions;
        }
        $error = get_post_meta((int) $post->ID, self::ERROR_META, true);
        if (!$error || get_post_meta((int) $post->ID, self::FULFILLED_META, true)) {
            return $actions;
        }
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=mlshop_retry_fulfill&order=' . (int) $post->ID),
            'mlshop_retry_' . (int) $post->ID
        );
        $actions['mlshop_retry_fulfill'] = '<a href="' . esc_url($url) . '" style="color:#b32d2e">重试授权发码</a>';
        return $actions;
    }

    public function handle_retry()
    {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        $order_id = isset($_GET['order']) ? (int) $_GET['order'] : 0;
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            wp_die('参数错误');
        }
        check_admin_referer('mlshop_retry_' . $order_id);

        delete_post_meta($order_id, self::ERROR_META);
        $this->fulfill_order($order_id);

        wp_safe_redirect(admin_url('edit.php?post_type=mlshop_order'));
        exit;
    }

    /* ================= 货币 ================= */

    public static function is_usd()
    {
        return isset($_COOKIE[self::CURRENCY_COOKIE]) && 'USD' === $_COOKIE[self::CURRENCY_COOKIE];
    }

    public function filter_currency_code($pre)
    {
        return self::is_usd() ? 'USD' : $pre;
    }

    public function filter_currency_symbol($pre)
    {
        return self::is_usd() ? '$' : $pre;
    }

    /** USD 模式：优先商品的 _at8lic_price_usd，否则按 CNY/7.2 换算 */
    public function filter_product_price($price, $product)
    {
        if (!self::is_usd() || !$product) {
            return $price;
        }
        $usd = (float) get_post_meta((int) $product->ID, '_at8lic_price_usd', true);
        if ($usd > 0) {
            return $usd;
        }
        return $price > 0 ? round($price / 7.2) : $price;
    }

    /* ================= 履约 ================= */

    public function fulfill_order($order_id)
    {
        $order_id = (int) $order_id;
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            return;
        }
        if (get_post_meta($order_id, self::FULFILLED_META, true)) {
            return; // 幂等（§43：重复回调只发一次码）
        }

        $items = get_post_meta($order_id, '_mlshop_items', true);
        if (!is_array($items)) {
            return;
        }

        // 只处理配置了授权映射的商品；未映射商品由商城原有交付链负责
        $license_items = array();
        foreach ($items as $item) {
            $pid = isset($item['id']) ? (int) $item['id'] : 0;
            if (!$pid) {
                continue;
            }
            $product_slug = (string) get_post_meta($pid, '_at8lic_product', true);
            if ($product_slug === '') {
                continue;
            }
            // 套餐：可变商品取下单时选中的变体；否则回落商品上的固定映射
            $plan_code = (isset($item['variant']) && $item['variant'] !== '')
                ? sanitize_key($item['variant'])
                : (string) get_post_meta($pid, '_at8lic_plan', true);
            if ($plan_code === '') {
                continue;
            }
            $license_items[] = array(
                'product'  => $product_slug,
                'plan'     => $plan_code,
                'quantity' => max(1, isset($item['qty']) ? (int) $item['qty'] : 1),
                'price'    => (float) (isset($item['price']) ? $item['price'] : get_post_meta($pid, '_mlshop_price', true)),
            );
        }
        if (!$license_items) {
            return;
        }

        // 收件人：登录用户取账号邮箱；游客订单取结算时填写的邮箱
        $user_id    = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $user       = $user_id ? get_userdata($user_id) : null;
        $guest_email = sanitize_email((string) get_post_meta($order_id, '_mlshop_guest_email', true));
        $is_guest   = (!$user && $guest_email !== '' && is_email($guest_email));

        if (!$user && !$is_guest) {
            update_post_meta($order_id, self::ERROR_META, '订单无有效用户/邮箱，无法签发授权');
            return;
        }
        $email = $user ? $user->user_email : $guest_email;

        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ($order_no === '') {
            $order_no = 'mlshop-' . $order_id;
        }

        $body = array(
            'order_no'       => $order_no,
            'source'         => 'shop',
            'user_email'     => $email,
            'amount'         => (float) get_post_meta($order_id, '_mlshop_total', true),
            'currency'       => self::is_usd() ? 'USD' : (string) get_post_meta($order_id, '_mlshop_currency', true),
            'payment_method' => (string) get_post_meta($order_id, '_mlshop_gateway', true),
            'items'          => $license_items,
        );

        $res = self::call('/admin/order/paid', $body);

        if (is_wp_error($res)) {
            update_post_meta($order_id, self::ERROR_META, '连接授权中心失败：' . $res->get_error_message());
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($res), true);
        $code = (int) wp_remote_retrieve_response_code($res);

        if ($code < 200 || $code >= 300 || empty($data['success'])) {
            $err = isset($data['message']) ? $data['message'] : ('HTTP ' . $code);
            update_post_meta($order_id, self::ERROR_META, '授权中心拒绝：' . $err);
            return;
        }

        // 幂等命中（服务器已发过码）：补发"掩码码 + 用户中心指引"邮件，不阻塞后续
        if (!empty($data['idempotent'])) {
            update_post_meta($order_id, self::FULFILLED_META, 'idempotent');
            delete_post_meta($order_id, self::ERROR_META);

            $masked = array();
            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $masked[] = array(
                        'product' => isset($item['product']) ? $item['product'] : '',
                        'license_key_masked' => isset($item['license_key_masked']) ? $item['license_key_masked'] : '',
                    );
                }
            }
            if ($masked && !$is_guest) {
                self::email_masked($email, $order_no, $masked);
            }
            return;
        }

        $issued = array();
        $plain  = array();
        if (!empty($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item) {
                $issued[] = array(
                    'product'     => isset($item['product']) ? $item['product'] : '',
                    'plan'        => isset($item['plan']) ? $item['plan'] : '',
                    'license_key' => isset($item['license_key']) ? $item['license_key'] : '',
                );
                $plain[] = (isset($item['product']) ? $item['product'] : '') . '：' . (isset($item['license_key']) ? $item['license_key'] : '');
            }
        }

        update_post_meta($order_id, self::FULFILLED_META, $issued);
        update_post_meta($order_id, '_at8lic_guest', $is_guest ? '1' : '0');
        delete_post_meta($order_id, self::ERROR_META);

        if ($is_guest) {
            self::email_guest($email, $order_no, $issued);
        } else {
            self::email_keys($email, $order_no, $plain, $issued);
        }
    }

    public function refund_order($order_id)
    {
        $order_id = (int) $order_id;
        if (!$order_id || get_post_meta($order_id, self::FULFILLED_META, true) === '') {
            return;
        }
        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ($order_no === '') {
            $order_no = 'mlshop-' . $order_id;
        }
        self::call('/admin/order/refund', array('order_no' => $order_no));
    }

    /** 登录用户：明文授权码邮件（含 Pro 包下载链接） */
    private static function email_keys($email, $order_no, $plain_lines, $issued)
    {
        if (!$plain_lines) {
            return;
        }
        $subject = sprintf('[%s] 您购买的授权码（订单 %s）', get_bloginfo('name'), $order_no);

        $rows = '';
        foreach ($issued as $item) {
            $pkg = self::package_url($item['product']);
            $pkg_cell = $pkg !== ''
                ? '<a href="' . esc_url($pkg) . '">下载 Pro 安装包</a>'
                : '';
            $rows .= '<tr>'
                   . '<td style="padding:6px 12px;border:1px solid #ddd">' . esc_html($item['product']) . '</td>'
                   . '<td style="padding:6px 12px;border:1px solid #ddd"><code style="font-size:14px">' . esc_html($item['license_key']) . '</code></td>'
                   . '<td style="padding:6px 12px;border:1px solid #ddd">' . $pkg_cell . '</td></tr>';
        }

        $body = '<p>您好，</p>'
              . '<p>订单 <strong>' . esc_html($order_no) . '</strong> 已支付成功，以下是您的授权码（请妥善保管）：</p>'
              . '<table style="border-collapse:collapse"><tr>'
              . '<th style="padding:6px 12px;border:1px solid #ddd;text-align:left">产品</th>'
              . '<th style="padding:6px 12px;border:1px solid #ddd;text-align:left">授权码</th>'
              . '<th style="padding:6px 12px;border:1px solid #ddd;text-align:left">安装包</th>'
              . '</tr>' . $rows . '</table>'
              . '<p>激活方式：网站后台 → 对应插件的「授权管理」页 → 粘贴授权码 → 激活。安装包链接 15 分钟内有效，过期请到账户中心「我的授权」重新获取。</p>'
              . '<p>感谢您的支持！<br>' . esc_html(get_bloginfo('name')) . '</p>';

        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /** 游客：不含明文码，引导设置密码后在「我的授权」查看 */
    private static function email_guest($email, $order_no, $issued)
    {
        $names = array();
        foreach ($issued as $item) {
            $names[] = $item['product'];
        }

        $account = function_exists('mluc_get_account_url') ? mluc_get_account_url() : home_url('/account/');
        $lost    = wp_lostpassword_url();

        $body = '<p>您好，</p>'
              . '<p>订单 <strong>' . esc_html($order_no) . '</strong> 已支付成功，您购买的 ' . esc_html(implode('、', $names)) . ' 授权已生成并绑定到本邮箱。</p>'
              . '<p><strong>查看授权码与下载安装包，只需两步：</strong></p>'
              . '<p>1. 点击 <a href="' . esc_url($lost) . '">设置账户密码</a>（我们已用本邮箱为您创建了账户；输入本邮箱，按邮件指引设置密码）<br>'
              . '2. 登录后进入 <a href="' . esc_url($account) . '">账户中心 → 我的授权</a>，即可查看授权码、绑定站点与下载安装包。</p>'
              . '<p>授权码也可随时在「我的授权」中查看，不会丢失。</p>'
              . '<p>感谢您的支持！<br>' . esc_html(get_bloginfo('name')) . '</p>';

        wp_mail($email, sprintf('[%s] 您的授权已生成（订单 %s）', get_bloginfo('name'), $order_no), $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /** 幂等场景补发：只有掩码码 + 用户中心入口（明文按设计仅首次发放） */
    private static function email_masked($email, $order_no, $items)
    {
        $subject = sprintf('[%s] 您的授权信息（订单 %s）', get_bloginfo('name'), $order_no);

        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td style="padding:6px 12px;border:1px solid #ddd">' . esc_html($item['product']) . '</td>'
                   . '<td style="padding:6px 12px;border:1px solid #ddd"><code>' . esc_html($item['license_key_masked']) . '</code></td></tr>';
        }

        $body = '<p>您好，</p>'
              . '<p>订单 <strong>' . esc_html($order_no) . '</strong> 的授权已生效。完整授权码此前已发送到本邮箱；'
              . '如未收到，请登录网站账户中心 →「我的授权」查看（支持站点绑定/解绑与续费）。</p>'
              . '<table style="border-collapse:collapse">' . $rows . '</table>'
              . '<p>' . esc_html(get_bloginfo('name')) . '</p>';

        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
    }

    /** 取产品的签名下载链接（授权中心同站时可用） */
    private static function package_url($product_slug)
    {
        if (!class_exists('AT8LIC_REST') || !class_exists('AT8LIC_Products')) {
            return '';
        }
        $p = AT8LIC_Products::get_by_slug((string) $product_slug);
        if (!$p || (string) ($p->download_path ?? '') === '') {
            return '';
        }
        return AT8LIC_REST::signed_download_url((string) $product_slug);
    }

    /* ================= 签名 HTTP（与授权中心管理签名一致） ================= */

    public static function server_url()
    {
        $url = trim((string) get_option(self::OPT_SERVER, ''));
        return $url !== '' ? untrailingslashit($url) : untrailingslashit(home_url());
    }

    public static function admin_secret()
    {
        return (string) get_option(self::OPT_SECRET, '');
    }

    private static function call($path, $body)
    {
        $secret = self::admin_secret();
        if ($secret === '') {
            return new WP_Error('at8lic_no_secret', '未配置管理密钥（设置 → AT8 授权桥接）');
        }

        $url    = self::server_url() . '/wp-json/at8-license/v1' . $path;
        $json   = wp_json_encode($body);
        $ts     = (string) time();
        $nonce  = wp_generate_uuid4();
        $ppath  = (string) wp_parse_url($url, PHP_URL_PATH);
        $raw    = implode("\n", array($ts, $nonce, 'POST', $ppath, hash('sha256', $json)));
        $sig    = base64_encode(hash_hmac('sha256', $raw, $secret, true));

        return wp_remote_post($url, array(
            'timeout'    => 15,
            'sslverify'  => true,
            'data_format' => 'body',
            'body'       => $json,
            'headers'    => array(
                'Content-Type'            => 'application/json',
                'X-AT8-Admin-Timestamp'   => $ts,
                'X-AT8-Admin-Nonce'       => $nonce,
                'X-AT8-Admin-Signature'   => $sig,
            ),
        ));
    }

    /* ================= 套餐购买 / 商店展示 ================= */

    /** 变体标签：yearly→一年 three_year→三年 lifetime→终身 */
    public static function variant_label($plan)
    {
        $map = array('yearly' => '一年', 'three_year' => '三年', 'lifetime' => '终身');
        return isset($map[$plan]) ? $map[$plan] : (string) $plan;
    }

    /**
     * 当前货币下的变体价格（USD 模式取变体 usd 价，否则 CNY 价）。
     * 供购物车 / 模板 / 报价链路调用；无变体数据返回 0（调用方回落基础价）。
     */
    public static function variant_price($product_id, $plan)
    {
        $variants = get_post_meta((int) $product_id, '_at8lic_variants', true);
        if (!is_array($variants)) {
            return 0.0;
        }
        $plan = sanitize_key((string) $plan);
        foreach ($variants as $v) {
            if (!isset($v['plan']) || sanitize_key($v['plan']) !== $plan) {
                continue;
            }
            if (self::is_usd() && isset($v['usd']) && (float) $v['usd'] > 0) {
                return (float) $v['usd'];
            }
            return isset($v['price']) ? (float) $v['price'] : 0.0;
        }
        return 0.0;
    }

    /** 某产品 slug 的可变商品（含 _at8lic_variants 的商品） */
    public static function product_by_slug($product_slug)
    {
        $q = new WP_Query(array(
            'post_type'      => 'mlshop_product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array('key' => '_at8lic_product', 'value' => sanitize_title((string) $product_slug)),
            ),
            'no_found_rows'  => true,
        ));
        return $q->posts ? $q->posts[0] : null;
    }

    /** 渲染某产品的套餐购买按钮组（可直接加购，可变商品详情页亦内嵌选择器） */
    public static function render_plan_buttons($product_slug)
    {
        $post = self::product_by_slug($product_slug);
        if (!$post) {
            return '';
        }
        $variants = get_post_meta((int) $post->ID, '_at8lic_variants', true);
        if (!is_array($variants) || !$variants) {
            return '';
        }

        $usd = self::is_usd();
        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('mlshop_nonce');
        $cart_url = function_exists('mlshop_get_page_url') ? mlshop_get_page_url('cart') : '';

        $out = '<div class="at8lic-buy" data-product="' . esc_attr($product_slug) . '">';
        foreach ($variants as $v) {
            $plan = isset($v['plan']) ? sanitize_key($v['plan']) : '';
            if ($plan === '') { continue; }
            $price = $usd && isset($v['usd']) && (float) $v['usd'] > 0 ? '$' . number_format((float) $v['usd'], 0) : '¥' . number_format((float) ($v['price'] ?? 0), 0);
            $badge = isset($v['badge']) ? (string) $v['badge'] : '';
            $out .= '<button type="button" class="at8lic-buy-btn at8lic-plan-' . esc_attr($plan) . '" data-key="' . esc_attr((int) $post->ID . '_' . $plan) . '" data-qty="1">'
                  . '<strong class="at8lic-plan-name">' . esc_html(self::variant_label($plan)) . '</strong>'
                  . '<span class="at8lic-plan-price">' . esc_html($price) . '</span>'
                  . ($badge !== '' ? '<span class="at8lic-plan-badge">' . esc_html($badge) . '</span>' : '')
                  . '</button>';
        }
        $out .= '<p class="at8lic-buy-msg" style="display:none"></p>';
        $adding = function_exists('mlshop_buy_text') ? mlshop_buy_text('adding') : '正在加入…';
        $added  = function_exists('mlshop_buy_text') ? mlshop_buy_text('added') : '已加入';
        $out .= '<p style="margin:8px 0 0;color:#646970;font-size:13px">'
              . ($cart_url ? '<span>已加入？<a href="' . esc_url($cart_url) . '">前往购物车结算 →</a></span>　' : '')
              . '<span>支付成功后授权码自动发送，绑定站点即可激活。</span></p>';
        $out .= '</div>';

        $out .= "<script>
(function () {
    var btns = document.querySelectorAll('.at8lic-buy[data-product=\"" . esc_js($product_slug) . "\"] .at8lic-buy-btn');
    var TXT_ADDING = " . wp_json_encode($adding) . ";
    var TXT_ADDED  = " . wp_json_encode($added . '。') . ";
    var CART_URL   = " . wp_json_encode($cart_url) . ";
    btns.forEach(function (btn) {
        if (btn.dataset.bound) { return; }
        btn.dataset.bound = '1';
        btn.addEventListener('click', function () {
            var msg = btn.closest('.at8lic-buy').querySelector('.at8lic-buy-msg');
            msg.style.display = 'block';
            msg.style.color = '#646970';
            msg.textContent = TXT_ADDING;
            var body = new URLSearchParams();
            body.append('action', 'mlshop_add_to_cart');
            body.append('nonce', '" . esc_js($nonce) . "');
            body.append('product_id', btn.dataset.key);
            body.append('qty', btn.dataset.qty);
            fetch('" . esc_js($ajax) . "', { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.success) {
                        msg.style.color = '#00a32a';
                        msg.innerHTML = TXT_ADDED + (CART_URL ? ' <a href=\"' + CART_URL + '\">前往购物车结算 →</a>' : '');
                    } else {
                        msg.style.color = '#b32d2e';
                        msg.textContent = (j && j.data && j.data.message) ? j.data.message : '加入失败，请重试';
                    }
                })
                .catch(function () { msg.textContent = '网络异常，请重试'; });
        });
    });
})();
</script>";

        return $out;
    }

    /** [at8lic_buy product="at8-security-pro"] */
    public function shortcode_buy($atts)
    {
        $atts = shortcode_atts(array('product' => ''), $atts, 'at8lic_buy');
        if ($atts['product'] === '') {
            return '';
        }
        return self::render_plan_buttons($atts['product']);
    }

    /** [at8lic_store]：可变商品列表 + 各自套餐按钮 */
    public function shortcode_store($atts)
    {
        $q = new WP_Query(array(
            'post_type'      => 'mlshop_product',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'meta_query'     => array(
                array('key' => '_at8lic_product', 'compare' => 'EXISTS'),
                array('key' => '_at8lic_variants', 'compare' => 'EXISTS'),
            ),
            'no_found_rows'  => true,
        ));

        $out = '<div class="at8lic-store">';
        foreach ($q->posts as $p) {
            $slug = (string) get_post_meta($p->ID, '_at8lic_product', true);
            if ($slug === '') { continue; }
            $desc = wp_strip_all_tags($p->post_content);
            $desc = function_exists('wp_html_excerpt') ? wp_html_excerpt($desc, 120, '…') : substr($desc, 0, 120);
            $out .= '<div class="at8lic-store-item">';
            $out .= '<h3 class="at8lic-store-title"><a href="' . esc_url(get_permalink($p)) . '">' . esc_html(get_the_title($p)) . '</a></h3>';
            $out .= '<p class="at8lic-store-desc">' . esc_html($desc) . '</p>';
            $out .= self::render_plan_buttons($slug);
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    /** [at8lic_currency]：¥/$ 切换 */
    public function shortcode_currency($atts)
    {
        $usd = self::is_usd();
        $url = esc_url(add_query_arg('at8lic_currency', $usd ? 'CNY' : 'USD'));
        $label = $usd ? '结算货币 USD $' : '结算货币 CNY ¥';
        return '<span class="at8lic-currency">' . esc_html($label)
             . '<a class="at8lic-currency-switch" href="' . $url . '" rel="nofollow">切换到 ' . ($usd ? 'CNY ¥' : 'USD $') . '</a></span>';
    }

    /* ================= 后台：设置 + 商品映射 ================= */

    public function menu()
    {
        add_options_page('AT8 授权桥接', 'AT8 授权桥接', 'manage_options', 'mlshop-at8lic', array($this, 'page'));
    }

    public function settings()
    {
        register_setting('mlshop_at8lic', self::OPT_SERVER, array('type' => 'string', 'sanitize_callback' => 'esc_url_raw'));
        register_setting('mlshop_at8lic', self::OPT_SECRET, array('type' => 'string', 'sanitize_callback' => array($this, 'sanitize_secret')));
    }

    public function sanitize_secret($v)
    {
        return preg_replace('/[^A-Za-z0-9]/', '', (string) $v);
    }

    public function page()
    {
        ?>
        <div class="wrap">
            <h1>AT8 授权桥接</h1>
            <p>配置后，商城中配置了「授权中心映射」的商品在支付成功时会自动从授权中心签发授权码，并邮件发送给客户。</p>
            <form method="post" action="options.php">
                <?php settings_fields('mlshop_at8lic'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>授权中心地址</th>
                        <td>
                            <input type="url" name="<?php echo esc_attr(self::OPT_SERVER); ?>" class="regular-text"
                                   value="<?php echo esc_attr(get_option(self::OPT_SERVER, home_url())); ?>">
                            <p class="description">同站部署时留默认值即可；独立部署填 https://license.at8.fun</p>
                        </td>
                    </tr>
                    <tr>
                        <th>管理密钥</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPT_SECRET); ?>" class="regular-text"
                                   value="<?php echo esc_attr(self::admin_secret()); ?>">
                            <p class="description">来自授权中心后台「AT8 授权 → 设置」页，两者必须一致</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function metabox()
    {
        add_meta_box('mlshop-at8lic', '授权中心映射（AT8 License）', array($this, 'render_metabox'), 'mlshop_product', 'side', 'default');
    }

    public function render_metabox($post)
    {
        wp_nonce_field('mlshop_at8lic_meta', 'mlshop_at8lic_nonce');
        $product = (string) get_post_meta($post->ID, '_at8lic_product', true);
        $plan    = (string) get_post_meta($post->ID, '_at8lic_plan', true);
        ?>
        <p>
            <label><strong>产品 slug</strong></label><br>
            <input type="text" name="at8lic_product" class="wide-text" style="width:100%"
                   value="<?php echo esc_attr($product); ?>" placeholder="at8-article-pro">
        </p>
        <p>
            <label><strong>套餐代码</strong></label><br>
            <input type="text" name="at8lic_plan" class="wide-text" style="width:100%"
                   value="<?php echo esc_attr($plan); ?>" placeholder="yearly / three_year / lifetime">
        </p>
        <p class="description">两者都填写时，该商品支付成功会自动发授权码；留空则走商城原有交付流程。</p>
        <?php
    }

    public function save_metabox($post_id, $post)
    {
        if (!isset($_POST['mlshop_at8lic_nonce']) || !wp_verify_nonce(sanitize_key($_POST['mlshop_at8lic_nonce']), 'mlshop_at8lic_meta')) {
            return;
        }
        if (!current_user_can('manage_product_terms') && !current_user_can('edit_posts')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $product = sanitize_title(isset($_POST['at8lic_product']) ? wp_unslash($_POST['at8lic_product']) : '');
        $plan    = sanitize_key(isset($_POST['at8lic_plan']) ? wp_unslash($_POST['at8lic_plan']) : '');

        if ($product !== '' && $plan !== '') {
            update_post_meta($post_id, '_at8lic_product', $product);
            update_post_meta($post_id, '_at8lic_plan', $plan);
        } else {
            delete_post_meta($post_id, '_at8lic_product');
            delete_post_meta($post_id, '_at8lic_plan');
        }

        // 可变商品：保存映射时自动从授权中心同步套餐（价格单一真相源在授权中心）
        if ($product !== '' && class_exists('AT8LIC_Products') && class_exists('AT8LIC_Plans')) {
            self::sync_variants($post_id, $product);
        }
    }

    /**
     * 从授权中心同步套餐到 _at8lic_variants（同站直连）。
     * usd 价缺省按 CNY/7.2 取整生成，管理员可在 USD 模式核对后调整。
     */
    public static function sync_variants($post_id, $product_slug)
    {
        global $wpdb;
        $ptable = $wpdb->prefix . 'at8lic_products';
        $ltable = $wpdb->prefix . 'at8lic_plans';

        $prod = $wpdb->get_row($wpdb->prepare("SELECT id FROM $ptable WHERE product_slug = %s", sanitize_title($product_slug)));
        if (!$prod) {
            return false;
        }
        $plans = $wpdb->get_results(
            $wpdb->prepare("SELECT plan_code, plan_name, price FROM $ltable WHERE product_id = %d AND status = 'active' ORDER BY FIELD(plan_code, 'yearly', 'three_year', 'lifetime'), duration_days ASC", (int) $prod->id)
        );
        if (!$plans) {
            return false;
        }

        $order = array('yearly' => 0, 'three_year' => 1, 'lifetime' => 2);
        $badges = array('lifetime' => '最受欢迎');
        $variants = array();
        foreach ($plans as $row) {
            $code = sanitize_key($row->plan_code);
            $cny = (float) $row->price;
            $variants[] = array(
                'plan'  => $code,
                'label' => self::variant_label($code),
                'price' => $cny,
                'usd'   => $cny > 0 ? round($cny / 7.2) : 0,
                'badge' => isset($badges[$code]) ? $badges[$code] : '',
            );
        }
        usort($variants, function ($a, $b) use ($order) {
            return ($order[$a['plan']] ?? 9) <=> ($order[$b['plan']] ?? 9);
        });

        update_post_meta((int) $post_id, '_at8lic_variants', $variants);
        // 基础显示价 = 最低档
        if ($variants && (float) $variants[0]['price'] > 0) {
            update_post_meta((int) $post_id, '_mlshop_price', (string) $variants[0]['price']);
        }
        return true;
    }
}
