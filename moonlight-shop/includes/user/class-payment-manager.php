<?php
/**
 * 支付网关注册表：统一登记 / 查询网关实例，生成本地订单号。
 *
 * - 内置网关（manual / paypal / stripe / alipay）由 MLUC_Payments 注册；
 * - 第三方与 Pro 可通过 mluc_payment_gateways_registered 过滤器追加实例；
 * - 订单号规则（MLUC + 日期 + 8 位随机十六进制）：服务端生成、唯一、不可预测，
 *   支付宝 out_trade_no 等外部单号一律使用本值，不暴露自增 ID。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Payment_Manager
{
    /**
     * @var MLUC_Payment_Gateway_Interface[]
     */
    private $gateways = array();

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
    }

    /**
     * 注册网关实例（同 ID 覆盖：允许第三方替换内置实现）。
     */
    public function register(MLUC_Payment_Gateway_Interface $gateway)
    {
        $this->gateways[$gateway->get_id()] = $gateway;
    }

    /**
     * 按需注册内置网关（首次调用时执行，第三方过滤器随后生效）。
     */
    public function ensure_defaults()
    {
        if (!empty($this->gateways)) {
            return;
        }
        // 并入隔离（Phase A）：网关实现类不随本阶段并入，缺席时不注册（Phase C 支付并线后恢复）。
        if (class_exists('MLUC_Gateway_Manual')) {
            $this->register(new MLUC_Gateway_Manual());
        }
        if (class_exists('MLUC_Gateway_PayPal')) {
            $this->register(new MLUC_Gateway_PayPal());
        }
        if (class_exists('MLUC_Gateway_Stripe')) {
            $this->register(new MLUC_Gateway_Stripe());
        }
        if (class_exists('MLUC_Gateway_Alipay')) {
            $this->register(new MLUC_Gateway_Alipay());
        }
        $this->gateways = apply_filters('mluc_payment_gateways_registered', $this->gateways);
    }

    /**
     * 全部网关实例。
     *
     * @return MLUC_Payment_Gateway_Interface[]
     */
    public function get_all()
    {
        $this->ensure_defaults();
        return $this->gateways;
    }

    /**
     * 按 ID 取网关实例。
     *
     * @param string $id 网关标识。
     * @return MLUC_Payment_Gateway_Interface|null
     */
    public function get($id)
    {
        $this->ensure_defaults();
        $id = sanitize_key((string) $id);
        return isset($this->gateways[$id]) ? $this->gateways[$id] : null;
    }

    /**
     * 前台可选网关（id => 名称，仅含当前可用的）。
     *
     * @return array
     */
    public function get_available()
    {
        $out = array();
        foreach ($this->get_all() as $id => $gateway) {
            if ($gateway->is_available()) {
                $out[$id] = $gateway->get_name();
            }
        }
        return $out;
    }

    /**
     * 校验并返回可用网关（不可用 / 币种不符时返回 WP_Error）。
     *
     * @param string $id 网关标识。
     * @return MLUC_Payment_Gateway_Interface|WP_Error
     */
    public function get_available_gateway($id)
    {
        $gateway = $this->get($id);
        if (!$gateway) {
            return new WP_Error('mluc_invalid_gateway', __('支付方式无效。', 'moonlight-user-center'));
        }
        if (!$gateway->is_available()) {
            return new WP_Error('mluc_gateway_unavailable', __('该支付方式当前不可用。', 'moonlight-user-center'));
        }
        $currency = class_exists('MLUC_Payments') ? MLUC_Payments::currency_code() : '';
        $caps     = $gateway->get_capabilities();
        $allowed  = isset($caps['currencies']) ? (array) $caps['currencies'] : array();
        if ($allowed && !in_array($currency, $allowed, true)) {
            return new WP_Error(
                'mluc_gateway_currency',
                sprintf(
                    /* translators: 1: 网关名称，2: 当前货币代码 */
                    __('%1$s 仅支持 %2$s 结算，请先在后台调整货币代码。', 'moonlight-user-center'),
                    $gateway->get_name(),
                    implode(' / ', $allowed)
                )
            );
        }
        return $gateway;
    }

    /**
     * 生成本地订单号：MLUC + yyyymmdd + 8 位安全随机十六进制。
     * 冲突时重试，最多 5 次。
     *
     * @return string
     */
    public static function generate_order_no()
    {
        global $wpdb;
        for ($i = 0; $i < 5; $i++) {
            $no = 'MLUC' . gmdate('Ymd') . strtoupper(bin2hex(random_bytes(4)));
            $dup = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_mluc_pay_order_no' AND meta_value = %s LIMIT 1",
                $no
            ));
            if (null === $dup) {
                return $no;
            }
        }
        // 兜底：随机到极小概率仍冲突时附加时间戳。
        return 'MLUC' . gmdate('Ymd') . strtoupper(bin2hex(random_bytes(8)));
    }
}
