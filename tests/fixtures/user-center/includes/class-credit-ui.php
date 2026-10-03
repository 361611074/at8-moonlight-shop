<?php
/**
 * 积分与余额前端：账户中心「积分余额」Tab、充值下单 AJAX、积分兑换余额 AJAX。
 *
 * - 充值：套餐（积分|金额）或自定义积分数；金额一律服务端按「充值比例」/ 套餐配置计算，
 *   客户端只传积分数与网关标识；
 * - 充值订单复用 mluc_order 统一订单模型（_mluc_pay_type=recharge），付款完成后
 *   由本类监听 mluc_payment_completed 入账积分（幂等）；
 * - 兑换：按后台「兑换比例」把积分即时换成余额（原子扣积分 + 原子加余额）；
 * - 与 moonlight-shop 同装时，商城积分模块若已注册 credit Tab，本类自动让位。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Credit_UI
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('mluc_account_tabs', array($this, 'register_tab'));
        add_action('wp_ajax_mluc_create_recharge', array($this, 'ajax_create_recharge'));
        add_action('wp_ajax_mluc_exchange_credit', array($this, 'ajax_exchange_credit'));
        // 充值订单付款完成后入账积分（在线网关走 paid；线下转账管理员确认走 complete_order 同一钩子）。
        add_action('mluc_payment_completed', array($this, 'grant_recharge'), 20, 3);
    }

    /**
     * 注册「积分余额」Tab。商城积分模块已注册同名 Tab 时让位（避免双积分体系并列）。
     */
    public function register_tab($tabs)
    {
        if (!mluc_credit_enabled() || !class_exists('MLUC_Credit')) {
            return $tabs;
        }
        if (isset($tabs['credit'])) {
            return $tabs;
        }
        $tabs['credit'] = array(
            'title'    => mluc_ui_label('cr_tab', __('Points & Balance', 'moonlight-user-center')),
            'icon'     => 'dashicons-tickets-alt',
            'callback' => array($this, 'render_tab'),
        );
        return $tabs;
    }

    /**
     * Tab 内容：余额卡片 + 充值 + 兑换 + 双流水。
     */
    public function render_tab()
    {
        if (!mluc_credit_enabled() || !class_exists('MLUC_Credit')) {
            return;
        }
        $user_id    = get_current_user_id();
        $gateways   = array();
        $paypal_on  = class_exists('MLUC_PayPal') && MLUC_PayPal::enabled();

        // 充值可用网关：线下转账 + 在线网关；排除余额 / 积分（不可用账本购买账本）。
        foreach (MLUC_Payment_Manager::get_instance()->get_all() as $id => $gateway) {
            if (in_array($id, array('balance', 'credit'), true)) {
                continue;
            }
            if ($gateway->is_available()) {
                $gateways[$id] = $gateway->get_name();
            }
        }

        mluc_get_template('account-credit', array(
            'credit_balance' => MLUC_Credit::get_balance($user_id),
            'balance'        => MLUC_Wallet::get_balance($user_id),
            'credit_name'    => mluc_get_credit_name(),
            'symbol'         => MLUC_Payments::get_currency_symbol(),
            'credit_ledger'  => MLUC_Credit::get_ledger($user_id, 15),
            'balance_ledger' => MLUC_Wallet::get_ledger($user_id, 15),
            'packages'       => mluc_get_recharge_packages(),
            'rate'           => mluc_get_credit_rate(),
            'limits'         => mluc_get_recharge_custom_limits(),
            'exchange_on'    => !empty(mluc_get_option('credit_exchange_enabled', 0)),
            'exchange_rate'  => mluc_get_credit_exchange_rate(),
            'exchange_min'   => max(1, (int) mluc_get_option('credit_exchange_min', 100)),
            'gateways'       => $gateways,
            'nonce'          => wp_create_nonce('mluc_nonce'),
            'paypal_on'      => $paypal_on,
            'paypal_sdk'     => $paypal_on ? MLUC_PayPal::sdk_url() : '',
            'pay_notice'     => isset($_GET['mluc_pay_notice']) ? sanitize_key(wp_unslash($_GET['mluc_pay_notice'])) : '',
            'checkin_on'     => class_exists('MLUC_Checkin') && MLUC_Checkin::enabled(),
            'checkin_done'   => class_exists('MLUC_Checkin') ? MLUC_Checkin::checked_today($user_id) : false,
            'checkin_streak' => class_exists('MLUC_Checkin') ? MLUC_Checkin::get_streak($user_id) : 0,
        ));
    }

    /**
     * 充值下单 AJAX：服务端定价建单 → 统一网关分派。
     */
    public function ajax_create_recharge()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, mluc_ui_label('buy_login_required', 'Please log in first.'));
        }
        if (!mluc_credit_enabled() || !class_exists('MLUC_Credit')) {
            mluc_send_json(false, __('积分模块未启用。', 'moonlight-user-center'));
        }

        $user_id     = get_current_user_id();
        $credit      = 0.0;
        $price       = 0.0;
        $pkg_index   = isset($_POST['package']) ? trim((string) wp_unslash($_POST['package'])) : '';
        $custom_raw  = isset($_POST['custom_credit']) ? trim((string) wp_unslash($_POST['custom_credit'])) : '';

        if ('' !== $pkg_index && 'custom' !== $pkg_index) {
            // 套餐：金额以后台配置为唯一来源。
            $packages = mluc_get_recharge_packages();
            $idx      = (int) $pkg_index;
            if (!isset($packages[$idx])) {
                mluc_send_json(false, __('充值套餐无效。', 'moonlight-user-center'));
            }
            $credit = (float) $packages[$idx]['credit'];
            $price  = (float) $packages[$idx]['price'];
        } else {
            // 自定义：服务端按充值比例换算，并校验后台限额。
            $credit = (float) $custom_raw;
            if ($credit < 1 || floor($credit) !== $credit) {
                mluc_send_json(false, sprintf(__('请输入有效的正整数%s数。', 'moonlight-user-center'), mluc_get_credit_name()));
            }
            $limits = mluc_get_recharge_custom_limits();
            if ($credit < $limits['min']) {
                mluc_send_json(false, sprintf(__('单次最少充值 %d%s。', 'moonlight-user-center'), $limits['min'], mluc_get_credit_name()));
            }
            if ($limits['max'] > 0 && $credit > $limits['max']) {
                mluc_send_json(false, sprintf(__('单次最多充值 %d%s。', 'moonlight-user-center'), $limits['max'], mluc_get_credit_name()));
            }
            $price = round($credit / mluc_get_credit_rate(), 2);
        }

        if ($credit <= 0 || $price <= 0) {
            mluc_send_json(false, __('充值金额无效，请检查充值比例设置。', 'moonlight-user-center'));
        }

        // 网关：仅线下转账 + 在线网关（余额 / 积分不可用于充值）。
        $gateway_id = isset($_POST['gateway']) ? sanitize_key(wp_unslash($_POST['gateway'])) : '';
        if (in_array($gateway_id, array('balance', 'credit'), true)) {
            mluc_send_json(false, __('充值不可使用余额或积分支付。', 'moonlight-user-center'));
        }
        $gateway = MLUC_Payment_Manager::get_instance()->get_available_gateway($gateway_id);
        if (is_wp_error($gateway)) {
            mluc_send_json(false, 'mluc_invalid_gateway' === $gateway->get_error_code()
                ? mluc_ui_label('buy_invalid_gateway', 'Invalid payment method.')
                : $gateway->get_error_message());
        }

        // 限流：同一用户 10 分钟内最多 10 单（与会员购买一致）。
        $rl_key = 'mluc_recharge_rl_' . $user_id;
        $hits   = (int) get_transient($rl_key);
        if ($hits >= 10) {
            mluc_send_json(false, mluc_ui_label('buy_too_many', 'Too many orders in a short period. Please try again later.'));
        }
        set_transient($rl_key, $hits + 1, 10 * MINUTE_IN_SECONDS);

        $order_id = wp_insert_post(array(
            'post_type'   => MLUC_Payments::CPT,
            'post_status' => 'publish',
            'post_title'  => sprintf('RC-%s-%d', date_i18n('YmdHis'), (int) $credit),
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id) || !$order_id) {
            mluc_send_json(false, mluc_ui_label('buy_order_failed', 'Failed to create the order. Please try again later.'));
        }
        update_post_meta($order_id, '_mluc_pay_user', $user_id);
        update_post_meta($order_id, '_mluc_pay_type', 'recharge');
        update_post_meta($order_id, '_mluc_pay_credit', $credit);
        update_post_meta($order_id, '_mluc_pay_price', $price);
        update_post_meta($order_id, '_mluc_pay_gateway', $gateway_id);
        update_post_meta($order_id, '_mluc_pay_status', 'pending');
        update_post_meta($order_id, '_mluc_pay_order_no', MLUC_Payment_Manager::generate_order_no());
        update_post_meta($order_id, '_mluc_pay_currency', MLUC_Payments::currency_code());
        update_post_meta($order_id, '_mluc_pay_title', sprintf('%s %s', $credit, mluc_get_credit_name()));

        do_action('mluc_recharge_order_created', $order_id, $user_id, $credit, $price, $gateway_id);

        $result = $gateway->process_payment($order_id);
        if (is_wp_error($result)) {
            update_post_meta($order_id, '_mluc_pay_status', 'cancelled');
            MLUC_Payment_Log::write($order_id, $gateway_id, 'order', 'fail', array('message' => $result->get_error_message()));
            mluc_send_json(false, $result->get_error_message());
        }
        MLUC_Payment_Log::write($order_id, $gateway_id, 'order', 'pending');

        $data = array('order_id' => (int) $order_id);
        foreach (array('flow', 'redirect', 'instructions') as $key) {
            if (isset($result[$key])) {
                $data[$key] = $result[$key];
            }
        }
        mluc_send_json(true, isset($result['message']) ? $result['message'] : '', $data);
    }

    /**
     * 积分兑换余额 AJAX：原子扣积分 + 原子加余额，比例全部来自后台配置。
     */
    public function ajax_exchange_credit()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, mluc_ui_label('buy_login_required', 'Please log in first.'));
        }
        if (!mluc_credit_enabled() || empty(mluc_get_option('credit_exchange_enabled', 0)) || !class_exists('MLUC_Credit') || !mluc_balance_enabled()) {
            mluc_send_json(false, __('积分兑换功能未启用。', 'moonlight-user-center'));
        }

        $user_id     = get_current_user_id();
        $credit_name = mluc_get_credit_name();
        $points      = isset($_POST['points']) ? (float) wp_unslash($_POST['points']) : 0.0;
        $min         = max(1, (int) mluc_get_option('credit_exchange_min', 100));

        if ($points <= 0 || floor($points) !== $points) {
            mluc_send_json(false, sprintf(__('请输入有效的正整数%s数。', 'moonlight-user-center'), $credit_name));
        }
        if ($points < $min) {
            mluc_send_json(false, sprintf(__('单次最少兑换 %d%s。', 'moonlight-user-center'), $min, $credit_name));
        }
        if (!MLUC_Credit::can_spend($user_id, $points)) {
            mluc_send_json(false, sprintf(
                /* translators: 1: 积分名称，2: 当前积分 */
                __('%1$s不足，当前 %2$s。', 'moonlight-user-center'),
                $credit_name,
                number_format(MLUC_Credit::get_balance($user_id))
            ));
        }

        $rate  = mluc_get_credit_exchange_rate();
        $money = round($points / $rate, 2);
        if ($money < 0.01) {
            mluc_send_json(false, sprintf(__('兑换比例过小，%d%s 不足 0.01 货币单位，请增加兑换数量。', 'moonlight-user-center'), $points, $credit_name));
        }

        // 原子转出积分；失败（并发不足）直接报错，绝不产生负数。
        if (false === MLUC_Credit::spend($user_id, $points, sprintf(__('兑换余额（%s 货币）', 'moonlight-user-center'), number_format($money, 2)))) {
            mluc_send_json(false, sprintf(__('%s不足，请刷新后重试。', 'moonlight-user-center'), $credit_name));
        }
        // 转入余额（原子累加，必成功）；两本账各留一条互相指向的流水。
        MLUC_Wallet::add($user_id, $money, sprintf(__('%s兑换转入（%s %s）', 'moonlight-user-center'), $credit_name, number_format($points), $credit_name));

        do_action('mluc_credit_exchanged', $user_id, $points, $money);

        mluc_send_json(true, sprintf(
            /* translators: 1: 积分数，2: 积分名称，3: 金额 */
            __('兑换成功：%1$s %2$s 已转入余额 %3$s。', 'moonlight-user-center'),
            number_format($points),
            $credit_name,
            MLUC_Payments::get_currency_symbol() . number_format($money, 2)
        ), array(
            'credit_balance' => MLUC_Credit::get_balance($user_id),
            'balance'        => MLUC_Wallet::get_balance($user_id),
        ));
    }

    /**
     * 充值订单付款完成后入账积分（幂等：paid / completed 或重复回调只入账一次）。
     *
     * @param int    $order_id 订单 ID。
     * @param int    $user_id  用户 ID。
     * @param string $level    等级或 'recharge' / 'paywall:x'。
     */
    public function grant_recharge($order_id, $user_id, $level)
    {
        if ('recharge' !== (string) $level) {
            return;
        }
        if (get_post_meta($order_id, '_mluc_pay_credit_granted', true)) {
            return;
        }
        $credit = (float) get_post_meta($order_id, '_mluc_pay_credit', true);
        $uid    = (int) $user_id;
        if (!$uid || $credit <= 0 || !class_exists('MLUC_Credit')) {
            return;
        }
        MLUC_Credit::add($uid, $credit, sprintf(__('充值到账（订单 #%d）', 'moonlight-user-center'), $order_id));
        update_post_meta($order_id, '_mluc_pay_credit_granted', current_time('mysql'));
    }
}
