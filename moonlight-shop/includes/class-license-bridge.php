<?php
/**
 * AT8 授权中心桥接（P6）：商城支付成功 → 授权中心自动发码（规划书 §42/§43）。
 *
 * 流程：mlshop_order_paid → 读取订单商品上的授权映射（_at8lic_product/_at8lic_plan）
 *       → 签名调用授权中心 /admin/order/paid（幂等）→ 明文授权码邮件发给客户
 *       → 退款 mlshop_order_refunded → /admin/order/refund 自动吊销。
 *
 * 配置：设置 → 「AT8 授权桥接」：授权中心地址 + 管理密钥（见授权中心「设置」页）。
 * 商品映射：编辑商品（mlshop_product）→ 「授权中心映射」meta box。
 *
 * 不修改商城订单/支付核心；本桥接失败不影响订单状态（失败原因记录在订单 meta）。
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

    /* ---------------- 配置 ---------------- */

    public static function server_url()
    {
        $url = trim((string) get_option(self::OPT_SERVER, ''));
        return $url !== '' ? untrailingslashit($url) : untrailingslashit(home_url());
    }

    public static function admin_secret()
    {
        return (string) get_option(self::OPT_SECRET, '');
    }

    /* ---------------- 履约 ---------------- */

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
            $plan_code    = (string) get_post_meta($pid, '_at8lic_plan', true);
            if ($product_slug === '' || $plan_code === '') {
                continue;
            }
            $license_items[] = array(
                'product'  => $product_slug,
                'plan'     => sanitize_key($plan_code),
                'quantity' => max(1, isset($item['qty']) ? (int) $item['qty'] : 1),
                'price'    => (float) get_post_meta($pid, '_mlshop_price', true),
            );
        }
        if (!$license_items) {
            return;
        }

        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $user    = $user_id ? get_userdata($user_id) : null;
        if (!$user) {
            update_post_meta($order_id, self::ERROR_META, '订单无有效用户，无法签发授权');
            return;
        }

        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ($order_no === '') {
            $order_no = 'mlshop-' . $order_id;
        }

        $body = array(
            'order_no'       => $order_no,
            'source'         => 'shop',
            'user_email'     => $user->user_email,
            'amount'         => (float) get_post_meta($order_id, '_mlshop_total', true),
            'currency'       => (string) get_post_meta($order_id, '_mlshop_currency', true),
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
            if ($masked) {
                self::email_masked($user->user_email, $order_no, $masked);
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
        delete_post_meta($order_id, self::ERROR_META);

        self::email_keys($user->user_email, $order_no, $plain, $issued);
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

    /** 明文授权码邮件（下单即发，用户中心/订单页可再查掩码） */
    private static function email_keys($email, $order_no, $plain_lines, $issued)
    {
        if (!$plain_lines) {
            return;
        }
        $subject = sprintf('[%s] 您购买的授权码（订单 %s）', get_bloginfo('name'), $order_no);

        $rows = '';
        foreach ($issued as $item) {
            $rows .= '<tr><td style="padding:6px 12px;border:1px solid #ddd">' . esc_html($item['product']) . '</td>'
                   . '<td style="padding:6px 12px;border:1px solid #ddd"><code style="font-size:14px">' . esc_html($item['license_key']) . '</code></td></tr>';
        }

        $body = '<p>您好，</p>'
              . '<p>订单 <strong>' . esc_html($order_no) . '</strong> 已支付成功，以下是您的授权码（请妥善保管）：</p>'
              . '<table style="border-collapse:collapse">' . $rows . '</table>'
              . '<p>激活方式：网站后台 → 对应插件的「授权管理」页 → 粘贴授权码 → 激活。</p>'
              . '<p>感谢您的支持！<br>' . esc_html(get_bloginfo('name')) . '</p>';

        wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
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

    /* ---------------- 签名 HTTP（与授权中心管理签名一致） ---------------- */

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

    /* ---------------- 后台：设置 + 商品映射 ---------------- */

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
                   value="<?php echo esc_attr($plan); ?>" placeholder="yearly / lifetime">
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
    }
}
