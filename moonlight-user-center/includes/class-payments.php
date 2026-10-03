<?php
/**
 * 用户中心独立支付：未启用 moonlight-shop 时，提供内置的会员购买与收款流程。
 *
 * - 网关：统一经 MLUC_Payment_Manager 注册表分派（manual / paypal / stripe / alipay，
 *   MLUC_Payment_Gateway_Interface 实现，mluc_payment_gateways_registered 可扩展）。
 * - 订单：CPT mluc_order（非公开），状态存 meta _mluc_pay_status：pending / paid / cancelled；
 *   统一订单要素 _mluc_pay_order_no（服务端订单号）与 _mluc_pay_currency（币种）。
 * - 开通：complete_order 原子完单（回跳 / webhook / 管理员确认共用），支付日志落 _mluc_pay_log。
 * - 双插件同装时不启用本流程：账户中心会员 Tab 自动改走商城升级购买（MLSHOP_Membership_UI）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Payments
{
    const CPT = 'mluc_order';

    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_cpt'));
        add_action('wp_ajax_mluc_buy_level', array($this, 'ajax_buy_level'));
        add_action('add_meta_boxes_' . self::CPT, array($this, 'register_meta_box'));
        add_action('admin_post_mluc_pay_confirm', array($this, 'admin_confirm'));
        add_action('admin_post_mluc_pay_cancel', array($this, 'admin_cancel'));
        add_action('admin_post_mluc_alipay_refund', array($this, 'admin_alipay_refund'));
        // 商城未启用时接管账户中心「升级购买」区
        add_action('mluc_membership_purchase', array($this, 'render_purchase_card'));
        // 在线网关（PayPal / Stripe / Alipay）
        add_filter('mluc_payment_gateways', array($this, 'filter_gateways'));
        add_action('wp_ajax_mluc_paypal_create', array('MLUC_PayPal', 'ajax_create'));
        add_action('wp_ajax_mluc_paypal_capture', array('MLUC_PayPal', 'ajax_capture'));
        add_action('wp_ajax_mluc_stripe_checkout', array('MLUC_Stripe', 'ajax_checkout'));
        add_action('rest_api_init', array('MLUC_Stripe', 'register_webhook'));
        add_action('init', array('MLUC_Stripe', 'maybe_handle_return'));
        // 支付宝：异步通知 + 回跳复核（网关实例按需经注册表加载）
        add_action('rest_api_init', array('MLUC_Gateway_Alipay', 'register_notify'));
        add_action('init', array('MLUC_Gateway_Alipay', 'maybe_handle_return'));
    }

    /**
     * 网关列表追加 PayPal / Stripe（启用且配置齐全时）。
     * 名称已并入 get_gateways()（mluc_ui_label 英文默认），此处保留过滤器兼容第三方扩展。
     */
    public function filter_gateways($gateways)
    {
        return $gateways;
    }

    /**
     * ISO 货币代码（PayPal / Stripe 用，后台可配）。
     */
    public static function currency_code()
    {
        $code = strtoupper(trim((string) mluc_get_option('pay_currency_code', 'USD')));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
    }

    /**
     * 是否零小数货币（JPY / KRW 等）。
     */
    public static function is_zero_decimal($code = '')
    {
        $code = $code ? strtoupper($code) : self::currency_code();
        return in_array($code, array('BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'), true);
    }

    /**
     * PayPal 金额字符串：10.00（零小数货币为整数）。
     */
    public static function api_amount($amount)
    {
        $amount = (float) $amount;
        return self::is_zero_decimal() ? (string) (int) round($amount) : number_format($amount, 2, '.', '');
    }

    /**
     * Stripe 最小货币单位整数：1000（零小数货币原值）。
     */
    public static function api_amount_minor($amount)
    {
        $amount = (float) $amount;
        return self::is_zero_decimal() ? (int) round($amount) : (int) round($amount * 100);
    }

    /**
     * 订单支付完成统一入口：开通会员 + 状态落库 + 动作钩子。
     *
     * @param int    $order_id 本地订单 ID
     * @param string $txn      网关交易号（PayPal capture id / Stripe payment intent）
     * @param string $gateway  完成网关（paypal / stripe / manual）
     *
     * @return true|WP_Error
     */
    public static function complete_order($order_id, $txn = '', $gateway = '')
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || get_post_type($order_id) !== self::CPT) {
            return new WP_Error('mluc_bad_order', __('訂單不存在。', 'moonlight-user-center'));
        }
        if ('pending' !== (string) get_post_meta($order_id, '_mluc_pay_status', true)) {
            return new WP_Error('mluc_dup', __('訂單已完成或已取消。', 'moonlight-user-center'));
        }
        $user_id = (int) get_post_meta($order_id, '_mluc_pay_user', true);
        $level   = (string) get_post_meta($order_id, '_mluc_pay_level', true);
        $paytype = (string) get_post_meta($order_id, '_mluc_pay_type', true);

        // 积分充值订单（v2.1.0）：付款完成后由 MLUC_Credit_UI 监听 mluc_payment_completed
        // 按订单记录的积分数入账（内部幂等），此处只负责原子完单。
        if ('recharge' === $paytype) {
            $credit = (float) get_post_meta($order_id, '_mluc_pay_credit', true);
            if (!$user_id || $credit <= 0 || !class_exists('MLUC_Credit')) {
                return new WP_Error('mluc_bad_recharge', __('充值订单无效。', 'moonlight-user-center'));
            }
            global $wpdb;
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = 'paid' WHERE post_id = %d AND meta_key = '_mluc_pay_status' AND meta_value = 'pending'",
                $order_id
            ));
            if (!$claimed) {
                return new WP_Error('mluc_dup', __('訂單已完成或已取消。', 'moonlight-user-center'));
            }
            clean_post_cache($order_id);
            update_post_meta($order_id, '_mluc_pay_granted', current_time('mysql'));
            if ('' !== $txn) {
                update_post_meta($order_id, '_mluc_pay_txn', $txn);
            }
            if ('' !== $gateway) {
                update_post_meta($order_id, '_mluc_pay_gateway_paid', $gateway);
            }
            MLUC_Payment_Log::write($order_id, $gateway, 'complete', 'paid', array('trade_no' => $txn, 'amount' => (string) $credit));
            do_action('mluc_payment_completed', $order_id, $user_id, 'recharge');
            return true;
        }

        // 付费墙订单：不涉及会员等级，付款完成后解锁对应文章（见 MLUC_Paywall）。
        if ('paywall' === $paytype) {
            $pw_post = (int) get_post_meta($order_id, '_mluc_pay_post', true);
            if (!$user_id || !$pw_post || !class_exists('MLUC_Paywall')) {
                return new WP_Error('mluc_bad_pw', __('付費內容訂單無效。', 'moonlight-user-center'));
            }
            global $wpdb;
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = 'paid' WHERE post_id = %d AND meta_key = '_mluc_pay_status' AND meta_value = 'pending'",
                $order_id
            ));
            if (!$claimed) {
                return new WP_Error('mluc_dup', __('訂單已完成或已取消。', 'moonlight-user-center'));
            }
            clean_post_cache($order_id);
            if (!MLUC_Paywall::grant_for_order($order_id)) {
                update_post_meta($order_id, '_mluc_pay_status', 'pending');
                return new WP_Error('mluc_grant', __('解鎖失敗：付費內容不存在或用戶無效。', 'moonlight-user-center'));
            }
            update_post_meta($order_id, '_mluc_pay_granted', current_time('mysql'));
            if ('' !== $txn) {
                update_post_meta($order_id, '_mluc_pay_txn', $txn);
            }
            if ('' !== $gateway) {
                update_post_meta($order_id, '_mluc_pay_gateway_paid', $gateway);
            }
            MLUC_Payment_Log::write($order_id, $gateway, 'complete', 'paid', array('trade_no' => $txn));
            do_action('mluc_payment_completed', $order_id, $user_id, 'paywall:' . $pw_post);
            return true;
        }

        if (!$user_id || !class_exists('MLUC_Membership') || !isset(MLUC_Membership::get_levels()[$level])) {
            return new WP_Error('mluc_bad_level', __('會員等級不存在或用戶無效。', 'moonlight-user-center'));
        }
        // 原子抢占：单条 UPDATE 仅在仍为 pending 时置为 paid，回跳与 webhook 并发时只有一个胜出。
        global $wpdb;
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = 'paid' WHERE post_id = %d AND meta_key = '_mluc_pay_status' AND meta_value = 'pending'",
            $order_id
        ));
        if (!$claimed) {
            return new WP_Error('mluc_dup', __('訂單已完成或已取消。', 'moonlight-user-center'));
        }
        clean_post_cache($order_id);
        if (!MLUC_Membership::get_instance()->grant_level($user_id, $level)) {
            // 开通失败回滚状态，允许后续重试。
            update_post_meta($order_id, '_mluc_pay_status', 'pending');
            return new WP_Error('mluc_grant', __('開通失敗：用戶等級不低於目標等級。', 'moonlight-user-center'));
        }
        update_post_meta($order_id, '_mluc_pay_granted', current_time('mysql'));
        if ('' !== $txn) {
            update_post_meta($order_id, '_mluc_pay_txn', $txn);
        }
        if ('' !== $gateway) {
            update_post_meta($order_id, '_mluc_pay_gateway_paid', $gateway);
        }
        MLUC_Payment_Log::write($order_id, $gateway, 'complete', 'paid', array('trade_no' => $txn));
        do_action('mluc_payment_completed', $order_id, $user_id, $level);
        return true;
    }

    /**
     * 商城是否接管支付（双插件同装时为 true）。
     */
    public static function shop_active()
    {
        return class_exists('MLSHOP_Membership_UI');
    }

    /**
     * 订单 CPT。商城同装时不显示后台菜单（避免双订单入口），但记录仍可经直链管理。
     */
    public function register_cpt()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'               => __('会员订单', 'moonlight-user-center'),
                'singular_name'      => __('会员订单', 'moonlight-user-center'),
                'menu_name'          => __('会员订单', 'moonlight-user-center'),
                'all_items'          => __('会员订单', 'moonlight-user-center'),
                'add_new'            => __('新建订单', 'moonlight-user-center'),
                'add_new_item'       => __('新建会员订单', 'moonlight-user-center'),
                'new_item'           => __('新会员订单', 'moonlight-user-center'),
                'edit_item'          => __('编辑会员订单', 'moonlight-user-center'),
                'view_item'          => __('查看会员订单', 'moonlight-user-center'),
                'view_items'         => __('查看会员订单', 'moonlight-user-center'),
                'search_items'       => __('搜索会员订单', 'moonlight-user-center'),
                'not_found'          => __('暂无订单', 'moonlight-user-center'),
                'not_found_in_trash' => __('回收站中暂无订单', 'moonlight-user-center'),
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => self::shop_active() ? false : true,
            'show_in_rest'        => false,
            'menu_icon'           => 'dashicons-tickets-alt',
            'menu_position'       => 58,
            'supports'            => array('title'),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ));

        // 自动关闭计划任务：开启时确保每小时排队，关闭时注销。
        $hours = (int) mluc_get_option('order_auto_close_hours', 72);
        if ($hours > 0 && !wp_next_scheduled('mluc_pay_autoclose')) {
            wp_schedule_event(time() + 3600, 'hourly', 'mluc_pay_autoclose');
        } elseif ($hours <= 0 && wp_next_scheduled('mluc_pay_autoclose')) {
            wp_clear_scheduled_hook('mluc_pay_autoclose');
        }
    }

    /**
     * 超时自动关闭 Pending 订单（会员购买与付费墙通用）。
     * 仅把「仍为 pending 且创建时间早于阈值」的订单置为 cancelled；
     * 已 paid / cancelled 的订单不受影响，绝不误伤在线支付正在进行的订单
     * （建议阈值不小于 24 小时，覆盖 Stripe Checkout 最长会话有效期）。
     */
    public function autoclose_orders()
    {
        $hours = (int) mluc_get_option('order_auto_close_hours', 72);
        if ($hours <= 0) {
            return;
        }
        global $wpdb;
        $q = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'date_query'     => array(
                array(
                    'column' => 'post_date_gmt',
                    'before' => gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS),
                ),
            ),
            'meta_query'     => array(
                array('key' => '_mluc_pay_status', 'value' => 'pending'),
            ),
        ));
        foreach ($q as $oid) {
            // 原子抢占：仅当仍为 pending 时置为 cancelled，避免与确认收款并发竞争。
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = 'cancelled' WHERE post_id = %d AND meta_key = '_mluc_pay_status' AND meta_value = 'pending'",
                $oid
            ));
            if ($claimed) {
                clean_post_cache($oid);
                update_post_meta($oid, '_mluc_pay_autoclosed', current_time('mysql'));
                do_action('mluc_order_autoclosed', (int) $oid);
            }
        }
    }

    /**
     * 可用网关（id => title）。来自 MLUC_Payment_Manager 注册表（内置 + 第三方实例），
     * mluc_payment_gateways 过滤器保留兼容第三方扩展。
     * 网关名称前台可见，走 mluc_ui_label 英文默认 + 后台可自定义。
     */
    public static function get_gateways()
    {
        $gateways = MLUC_Payment_Manager::get_instance()->get_available();
        return apply_filters('mluc_payment_gateways', $gateways);
    }

    /**
     * 付款说明（后台「付款说明」字段优先；未填写时回退到界面文案默认值，前台恒英文默认）。
     */
    public static function get_instructions()
    {
        $default = mluc_ui_label('buy_instructions_body', 'Please complete the transfer as instructed below. Your membership will be activated once the administrator confirms your payment.');
        $txt = trim((string) mluc_get_option('pay_manual_instructions', ''));
        return '' !== $txt ? $txt : $default;
    }

    /**
     * 货币符号（后台可配，独立支付流程展示用）。
     */
    public static function get_currency_symbol()
    {
        $sym = trim((string) mluc_get_option('pay_currency_symbol', ''));
        return '' !== $sym ? $sym : '$';
    }

    /**
     * 账户中心会员 Tab 的独立购买卡片（商城未启用时由 mluc_membership_purchase 钩子触发）。
     */
    public function render_purchase_card()
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html(mluc_ui_label('purchases_login', 'Please log in first to view your purchased content.')) . '</p>';
            return;
        }
        $paypal_on = class_exists('MLUC_PayPal') && MLUC_PayPal::enabled();
        mluc_get_template('membership-purchase', array(
            'levels'   => MLUC_Membership::get_levels(),
            'gateways' => self::get_gateways(),
            'orders'   => $this->get_user_orders(get_current_user_id(), 5),
            'symbol'   => self::get_currency_symbol(),
            'instructions' => self::get_instructions(),
            'nonce'    => wp_create_nonce('mluc_nonce'),
            'paypal_on'    => $paypal_on,
            'paypal_sdk'   => $paypal_on ? MLUC_PayPal::sdk_url() : '',
            'stripe_on'    => class_exists('MLUC_Stripe') && MLUC_Stripe::enabled(),
            'pay_notice'   => isset($_GET['mluc_pay_notice']) ? sanitize_key(wp_unslash($_GET['mluc_pay_notice'])) : '',
        ));
    }

    /**
     * 用户自己的订单（按时间倒序）。
     */
    public function get_user_orders($user_id, $limit = 5)
    {
        $q = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_mluc_pay_user', 'value' => (int) $user_id, 'compare' => '='),
            ),
        ));
        $out = array();
        foreach ($q as $oid) {
            $out[] = array(
                'id'      => (int) $oid,
                'title'   => get_the_title($oid),
                'level'   => (string) get_post_meta($oid, '_mluc_pay_level', true),
                'price'   => (float) get_post_meta($oid, '_mluc_pay_price', true),
                'status'  => (string) get_post_meta($oid, '_mluc_pay_status', true),
                'date'    => get_the_date('', $oid),
            );
        }
        return $out;
    }

    /**
     * 前端购买 AJAX：创建 pending 订单，返回付款说明。
     */
    public function ajax_buy_level()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, mluc_ui_label('buy_login_required', 'Please log in first.'));
        }
        if (self::shop_active()) {
            mluc_send_json(false, mluc_ui_label('buy_use_shop', 'Please use the shop upgrade flow to purchase membership.'));
        }
        if (!class_exists('MLUC_Membership')) {
            mluc_send_json(false, mluc_ui_label('buy_module_missing', 'Membership module is not loaded.'));
        }

        $user_id = get_current_user_id();
        $level   = isset($_POST['level']) ? sanitize_key(wp_unslash($_POST['level'])) : '';
        $gw      = isset($_POST['gateway']) ? sanitize_key(wp_unslash($_POST['gateway'])) : '';

        $levels = MLUC_Membership::get_levels();
        if ('' === $level || 'free' === $level || !isset($levels[$level])) {
            mluc_send_json(false, mluc_ui_label('buy_invalid_level', 'Invalid membership level.'));
        }
        $price = (float) MLUC_Membership::get_level_price($level);
        if ($price <= 0) {
            mluc_send_json(false, mluc_ui_label('buy_no_price', 'This level has no price set and cannot be purchased yet.'));
        }
        // 已是同等或更高等级：开通必然失败，禁止下单（避免扣款后无法开通）。
        $current = MLUC_Membership::get_user_level($user_id);
        if (MLUC_Membership::get_level_sort_order($current) >= MLUC_Membership::get_level_sort_order($level)) {
            mluc_send_json(false, mluc_ui_label('buy_level_too_low', 'Your current membership level is not lower than the selected one. No need to purchase.'));
        }
        $gateways = self::get_gateways();
        if ('' === $gw || !isset($gateways[$gw])) {
            mluc_send_json(false, mluc_ui_label('buy_invalid_gateway', 'Invalid payment method.'));
        }
        // 网关可用性 + 能力（币种等）校验：下单前失败，避免遗留孤儿订单。
        $gateway = MLUC_Payment_Manager::get_instance()->get_available_gateway($gw);
        if (is_wp_error($gateway)) {
            mluc_send_json(false, 'mluc_invalid_gateway' === $gateway->get_error_code()
                ? mluc_ui_label('buy_invalid_gateway', 'Invalid payment method.')
                : $gateway->get_error_message());
        }
        // 限流：同一用户 10 分钟内最多 10 单，防止脚本反复点击刷出大量 pending 订单。
        $rl_key = 'mluc_buy_rl_' . $user_id;
        $hits   = (int) get_transient($rl_key);
        if ($hits >= 10) {
            mluc_send_json(false, mluc_ui_label('buy_too_many', 'Too many orders in a short period. Please try again later.'));
        }
        set_transient($rl_key, $hits + 1, 10 * MINUTE_IN_SECONDS);

        $title = sprintf('MB-%s-%s', date_i18n('YmdHis'), $levels[$level]['label']);
        $order_id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id) || !$order_id) {
            mluc_send_json(false, mluc_ui_label('buy_order_failed', 'Failed to create the order. Please try again later.'));
        }
        update_post_meta($order_id, '_mluc_pay_user', $user_id);
        update_post_meta($order_id, '_mluc_pay_level', $level);
        update_post_meta($order_id, '_mluc_pay_price', $price);
        update_post_meta($order_id, '_mluc_pay_gateway', $gw);
        update_post_meta($order_id, '_mluc_pay_status', 'pending');
        // 统一订单要素：服务端生成的订单号（支付宝 out_trade_no 等）+ 结算币种。
        update_post_meta($order_id, '_mluc_pay_order_no', MLUC_Payment_Manager::generate_order_no());
        update_post_meta($order_id, '_mluc_pay_currency', self::currency_code());

        do_action('mluc_payment_order_created', $order_id, $user_id, $level, $gw);

        // 统一网关分派：process_payment 返回 flow / 提示文案 / 跳转地址等，核心不再硬编码网关分支。
        $result = $gateway->process_payment($order_id);
        if (is_wp_error($result)) {
            // 网关侧发起失败：订单作废，避免遗留孤儿 pending 单。
            update_post_meta($order_id, '_mluc_pay_status', 'cancelled');
            MLUC_Payment_Log::write($order_id, $gw, 'order', 'fail', array('message' => $result->get_error_message()));
            mluc_send_json(false, $result->get_error_message());
        }
        MLUC_Payment_Log::write($order_id, $gw, 'order', 'pending');

        $data = array('order_id' => (int) $order_id);
        foreach (array('flow', 'redirect', 'instructions') as $key) {
            if (isset($result[$key])) {
                $data[$key] = $result[$key];
            }
        }
        mluc_send_json(true, isset($result['message']) ? $result['message'] : '', $data);
    }

    /**
     * 订单编辑页 meta box：信息 + 管理员确认收款 / 取消。
     */
    public function register_meta_box($post)
    {
        add_meta_box('mluc_pay_meta', __('收款資訊', 'moonlight-user-center'), array($this, 'render_meta_box'), self::CPT, 'normal', 'high');
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('mluc_pay_meta', 'mluc_pay_meta_nonce');
        $user_id = (int) get_post_meta($post->ID, '_mluc_pay_user', true);
        $level   = (string) get_post_meta($post->ID, '_mluc_pay_level', true);
        $price   = (float) get_post_meta($post->ID, '_mluc_pay_price', true);
        $gw      = (string) get_post_meta($post->ID, '_mluc_pay_gateway', true);
        $status  = (string) get_post_meta($post->ID, '_mluc_pay_status', true);
        $granted = (string) get_post_meta($post->ID, '_mluc_pay_granted', true);
        $paytype = (string) get_post_meta($post->ID, '_mluc_pay_type', true);
        $pw_post = (int) get_post_meta($post->ID, '_mluc_pay_post', true);
        $is_pw   = ('paywall' === $paytype);

        $user = $user_id ? get_user_by('id', $user_id) : false;
        $gateways = self::get_gateways();
        $status_labels = array(
            'pending'   => __('待確認', 'moonlight-user-center'),
            'paid'      => __('已收款', 'moonlight-user-center'),
            'cancelled' => __('已取消', 'moonlight-user-center'),
        );
        ?>
        <table class="form-table" role="presentation">
            <tr><th><?php esc_html_e('用戶', 'moonlight-user-center'); ?></th>
                <td><?php echo $user ? esc_html($user->user_login . ' (#' . $user->ID . ')') : '—'; ?></td></tr>
            <tr><th><?php esc_html_e('會員等級', 'moonlight-user-center'); ?></th>
                <td><?php
                    if ($is_pw) {
                        $pw_title = $pw_post ? get_the_title($pw_post) : '';
                        echo $pw_post
                            ? '<a href="' . esc_url(get_edit_post_link($pw_post)) . '">' . esc_html($pw_title . ' (#' . $pw_post . ')') . '</a>（' . esc_html__('付費內容解鎖', 'moonlight-user-center') . '）'
                            : '—';
                    } else {
                        echo esc_html('' !== $level ? MLUC_Membership::get_level_label($level) . ' (' . $level . ')' : '—');
                    }
                ?></td></tr>
            <tr><th><?php esc_html_e('金額', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html(self::get_currency_symbol() . number_format($price, 2)); ?></td></tr>
            <tr><th><?php esc_html_e('支付方式', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html(isset($gateways[$gw]) ? $gateways[$gw] : $gw); ?></td></tr>
            <tr><th><?php esc_html_e('狀態', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status); ?>
                    <?php if ($granted) : ?>｜<?php esc_html_e('已開通於', 'moonlight-user-center'); ?> <?php echo esc_html($granted); ?><?php endif; ?>
                </td></tr>
        </table>
        <?php if ('pending' === $status) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <?php wp_nonce_field('mluc_pay_confirm'); ?>
                <input type="hidden" name="action" value="mluc_pay_confirm">
                <input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
                <button type="submit" class="button button-primary"><?php $is_pw ? esc_html_e('確認收款並解鎖內容', 'moonlight-user-center') : esc_html_e('確認收款並開通會員', 'moonlight-user-center'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <?php wp_nonce_field('mluc_pay_cancel'); ?>
                <input type="hidden" name="action" value="mluc_pay_cancel">
                <input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
                <button type="submit" class="button"><?php esc_html_e('取消訂單', 'moonlight-user-center'); ?></button>
            </form>
        <?php endif; ?>

        <?php if ('paid' === $status && 'alipay' === $gw) : ?>
            <?php $refunded = (string) get_post_meta($post->ID, '_mluc_pay_refunded', true); ?>
            <?php if ('' === $refunded) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;"
                      onsubmit="return confirm('<?php echo esc_js(__('确认通过支付宝原路全额退款？', 'moonlight-user-center')); ?>');">
                    <?php wp_nonce_field('mluc_alipay_refund'); ?>
                    <input type="hidden" name="action" value="mluc_alipay_refund">
                    <input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
                    <button type="submit" class="button button-link-delete"><?php esc_html_e('支付宝退款（全额，原路退回）', 'moonlight-user-center'); ?></button>
                </form>
            <?php else : ?>
                <p style="margin-top:8px;color:#d63638;"><?php echo esc_html(sprintf(__('已于 %s 退款。', 'moonlight-user-center'), $refunded)); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        // 支付日志（只读，最近 10 条）。
        $pay_log = array_slice(array_reverse(MLUC_Payment_Log::read($post->ID)), 0, 10);
        if ($pay_log) :
            ?>
            <h4 style="margin:14px 0 4px;"><?php esc_html_e('支付日志', 'moonlight-user-center'); ?></h4>
            <table class="widefat striped" style="max-width:640px;">
                <tbody>
                <?php foreach ($pay_log as $entry) : ?>
                    <tr>
                        <td style="width:150px;"><?php echo esc_html($entry['created_at']); ?></td>
                        <td style="width:90px;"><?php echo esc_html($entry['gateway']); ?></td>
                        <td style="width:90px;"><?php echo esc_html($entry['event']); ?></td>
                        <td><?php echo esc_html($entry['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * 管理员确认收款 → 开通会员。
     */
    public function admin_confirm()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_pay_confirm')) {
            wp_die(esc_html__('權限不足或校驗失敗。', 'moonlight-user-center'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $redirect = wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit');
        if ($order_id && get_post_type($order_id) === self::CPT
            && 'pending' === get_post_meta($order_id, '_mluc_pay_status', true)) {
            $ok = self::complete_order($order_id);
            $is_pw = 'paywall' === (string) get_post_meta($order_id, '_mluc_pay_type', true);
            set_transient('mluc_admin_notice_' . get_current_user_id(), array(
                'success' => !is_wp_error($ok),
                'message' => !is_wp_error($ok)
                    ? ($is_pw ? __('已確認收款，內容已解鎖。', 'moonlight-user-center') : __('已確認收款，會員等級開通成功。', 'moonlight-user-center'))
                    : $ok->get_error_message(),
            ), 60);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 管理员取消订单。
     */
    public function admin_cancel()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_pay_cancel')) {
            wp_die(esc_html__('權限不足或校驗失敗。', 'moonlight-user-center'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=' . self::CPT);
        if ($order_id && get_post_type($order_id) === self::CPT) {
            update_post_meta($order_id, '_mluc_pay_status', 'cancelled');
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 管理员发起支付宝退款（仅支付宝已支付订单；退款后按产品政策撤销关联 License）。
     */
    public function admin_alipay_refund()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_alipay_refund')) {
            wp_die(esc_html__('權限不足或校驗失敗。', 'moonlight-user-center'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $redirect = wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit');
        if ($order_id && get_post_type($order_id) === self::CPT
            && 'paid' === (string) get_post_meta($order_id, '_mluc_pay_status', true)
            && 'alipay' === (string) get_post_meta($order_id, '_mluc_pay_gateway', true)) {
            $gateways = MLUC_Payment_Manager::get_instance()->get_all();
            $result = isset($gateways['alipay'])
                ? $gateways['alipay']->refund($order_id)
                : new WP_Error('mluc_no_gateway', __('支付宝网关不可用。', 'moonlight-user-center'));
            if (is_wp_error($result)) {
                set_transient('mluc_admin_notice_' . get_current_user_id(), array(
                    'success' => false,
                    'message' => $result->get_error_message(),
                ), 60);
            } else {
                // 退款 → License 撤销（§44：退款后 License revoked，具体规则见产品政策）。
                do_action('mluc_order_refunded', $order_id);
                set_transient('mluc_admin_notice_' . get_current_user_id(), array(
                    'success' => true,
                    'message' => __('退款成功，订单已标记退款。', 'moonlight-user-center'),
                ), 60);
            }
        }
        wp_safe_redirect($redirect);
        exit;
    }
}
