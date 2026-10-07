<?php
/**
 * 结构化支付日志：按订单落库（postmeta _mluc_pay_log 环形截断）。
 *
 * - 记录字段固定为 order_id / gateway / event / status / created_at（§45），
 *   绝不记录密钥、完整凭证、密码等敏感信息；
 * - debug 级上下文仅在后台开启「调试日志」时写入；
 * - 日志随订单删除自动清理，无额外数据表。
 *
 * 自 moonlight-user-center v2.0.0 并入（at8-moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Payment_Log
{
    const MAX_ENTRIES = 50;

    /**
     * 写入一条支付日志。
     *
     * @param int    $order_id 本地订单 ID。
     * @param string $gateway  网关标识（manual / paypal / stripe / alipay）。
     * @param string $event    事件（order_created / process / notify / return / query / refund / complete / fail）。
     * @param string $status   结果状态（可选）。
     * @param array  $context  上下文（仅白名单键，debug 开启时才写入）。
     */
    public static function write($order_id, $gateway, $event, $status = '', $context = array())
    {
        $order_id = (int) $order_id;
        // 并入隔离（Phase A）：MLUC_Payments 不随本阶段并入，类缺席时静默跳过
        // （避免访问未定义类的常量致命；旧插件激活时由旧插件同名类接管）。
        if (!class_exists('MLUC_Payments')) {
            return;
        }
        if ($order_id <= 0 || get_post_type($order_id) !== MLUC_Payments::CPT) {
            return;
        }

        $entry = array(
            'gateway'    => sanitize_key($gateway),
            'event'      => sanitize_key($event),
            'status'     => sanitize_text_field((string) $status),
            'created_at' => current_time('mysql'),
        );

        // debug 上下文：仅白名单键，防敏感信息落入日志。
        if (!empty($context) && self::debug_enabled()) {
            $allowed = array('txn_ref', 'trade_no', 'flow', 'amount', 'currency', 'code', 'message');
            $clean   = array();
            foreach ($context as $k => $v) {
                if (in_array((string) $k, $allowed, true)) {
                    $clean[(string) $k] = (string) $v;
                }
            }
            if ($clean) {
                $entry['context'] = $clean;
            }
        }

        $log = get_post_meta($order_id, '_mluc_pay_log', true);
        if (!is_array($log)) {
            $log = array();
        }
        $log[] = $entry;
        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, -1 * self::MAX_ENTRIES);
        }
        update_post_meta($order_id, '_mluc_pay_log', $log);

        // 开启 WP_DEBUG 时同步写入 error_log，便于服务端排查。
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[MLUC-Pay] order=%d gateway=%s event=%s status=%s',
                $order_id,
                $entry['gateway'],
                $entry['event'],
                $entry['status']
            ));
        }
    }

    /**
     * 读取订单日志。
     *
     * @param int $order_id 订单 ID。
     * @return array
     */
    public static function read($order_id)
    {
        $log = get_post_meta((int) $order_id, '_mluc_pay_log', true);
        return is_array($log) ? $log : array();
    }

    /**
     * 调试上下文开关（后台「支付调试日志」）。
     */
    public static function debug_enabled()
    {
        return (bool) mluc_get_option('pay_debug_log', 0);
    }
}
