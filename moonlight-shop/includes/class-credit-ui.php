<?php
/**
 * 积分体系前端：用户中心「积分余额」Tab、充值下单 AJAX、订单付款后积分入账。
 *
 * 依赖 moonlight-user-center 的账户 Tab 机制（mluc_account_tabs）。
 * 充值的积分余额来自 MLSHOP_Credit（Stage 2 基础层）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Credit_UI
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
        // 仅在用户中心可用时挂入账户 Tab
        add_filter('mluc_account_tabs', array($this, 'register_tab'));
        // 充值下单（需登录）
        add_action('wp_ajax_mlshop_create_recharge', array($this, 'ajax_create_recharge'));
        // 订单付款后入账积分（線上付款走 paid；貨到付款走 completed；grant_recharge 内部幂等保护）
        add_action('mlshop_order_paid', array($this, 'grant_recharge'), 30);
        add_action('mlshop_order_completed', array($this, 'grant_recharge'), 30);
    }

    /**
     * 在用户中心添加「积分余额」Tab。
     */
    public function register_tab($tabs)
    {
        if (!class_exists('MLSHOP_Credit')) {
            return $tabs;
        }
        $tabs['credit'] = array(
            'title'    => __('积分余额', 'moonlight-shop'),
            'icon'     => 'dashicons-tickets-alt',
            'callback' => array($this, 'tab_credit'),
        );
        return $tabs;
    }

    /**
     * 积分余额 Tab 内容。
     */
    public function tab_credit()
    {
        if (!class_exists('MLSHOP_Credit')) {
            return;
        }
        $user_id  = get_current_user_id();
        $balance  = MLSHOP_Credit::get_balance($user_id);
        $ledger   = MLSHOP_Credit::get_ledger($user_id, 30);
        $packages = mlshop_get_recharge_packages();
        $rate     = mlshop_get_credit_rate();
        $symbol   = mlshop_get_option('currency_symbol', 'HK$');
        $credit_name = mlshop_get_option('credit_name', '积分');

        // 充值可用网关（排除余额支付，避免用余额买余额的循环）
        $gateways = array();
        if (class_exists('MLSHOP_Payment')) {
            foreach (MLSHOP_Payment::get_instance()->get_gateways() as $g) {
                if ('balance' === $g->get_id()) {
                    continue;
                }
                $gateways[] = $g;
            }
        }

        mlshop_get_template('account-credit', array(
            'balance'     => $balance,
            'credit_name' => $credit_name,
            'symbol'      => $symbol,
            'ledger'      => $ledger,
            'packages'    => $packages,
            'rate'        => $rate,
            'gateways'    => $gateways,
        ));
    }

    /**
     * 充值下单 AJAX。
     */
    public function ajax_create_recharge()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mlshop_send_json(false, __('请先登录。', 'moonlight-shop'));
        }
        if (!class_exists('MLSHOP_Credit') || !class_exists('MLSHOP_Order') || !class_exists('MLSHOP_Payment')) {
            mlshop_send_json(false, __('充值模块不可用。', 'moonlight-shop'));
        }

        $user_id = get_current_user_id();
        $credit  = 0.0;
        $price   = 0.0;

        $package_idx = isset($_POST['package']) ? trim($_POST['package']) : '';
        if ('' !== $package_idx && 'custom' !== $package_idx) {
            $packages = mlshop_get_recharge_packages();
            $idx = (int) $package_idx;
            if (isset($packages[$idx])) {
                $credit = (float) $packages[$idx]['credit'];
                $price  = (float) $packages[$idx]['price'];
            }
        } else {
            $credit = (float) (isset($_POST['custom_credit']) ? $_POST['custom_credit'] : 0);
            $rate   = mlshop_get_credit_rate();
            if ($rate > 0) {
                $price = round($credit / $rate, 2);
            }
        }

        if ($credit <= 0) {
            mlshop_send_json(false, __('请输入有效的充值积分数。', 'moonlight-shop'));
        }
        if ($price <= 0) {
            mlshop_send_json(false, __('充值金额无效，请检查汇率设置。', 'moonlight-shop'));
        }

        $gateway_id = isset($_POST['gateway']) ? sanitize_key($_POST['gateway']) : '';
        if ('balance' === $gateway_id) {
            mlshop_send_json(false, __('充值不可使用余额支付。', 'moonlight-shop'));
        }
        $gateway = MLSHOP_Payment::get_instance()->get_gateway($gateway_id);
        if (!$gateway) {
            mlshop_send_json(false, __('支付方式无效。', 'moonlight-shop'));
        }

        $order_id = MLSHOP_Order::create_recharge($user_id, $credit, $price, $gateway_id);
        if (is_wp_error($order_id)) {
            mlshop_send_json(false, $order_id->get_error_message());
        }

        $result = $gateway->process_payment($order_id);
        $data = array('order_id' => $order_id);
        if (!empty($result['redirect'])) {
            $data['redirect'] = $result['redirect'];
        }
        mlshop_send_json(
            !empty($result['success']),
            isset($result['message']) ? $result['message'] : '',
            $data
        );
    }

    /**
     * 充值订单付款完成后入账积分。
     */
    public function grant_recharge($order_id)
    {
        if ('recharge' !== get_post_meta($order_id, '_mlshop_type', true)) {
            return;
        }
        // 幂等：避免 paid 與 completed 雙重觸發導致重複入賬
        if (get_post_meta($order_id, '_mlshop_recharge_granted', true)) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $credit  = (float) get_post_meta($order_id, '_mlshop_credit_amount', true);
        if ($user_id && $credit > 0 && class_exists('MLSHOP_Credit')) {
            MLSHOP_Credit::add($user_id, $credit, sprintf(__('充值到账（订单 #%s）', 'moonlight-shop'), $order_id));
            update_post_meta($order_id, '_mlshop_recharge_granted', current_time('mysql'));
        }
    }
}
