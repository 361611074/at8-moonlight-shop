<?php
/**
 * 积分体系（基础）：用户积分余额与流水。
 *
 * 存储：
 *   - user meta `mlshop_credit_balance`：当前余额（float）
 *   - user meta `mlshop_credit_ledger`：最近 200 条流水（array）
 *
 * 这是 Stage 2 的"积分基础"层，供付费内容（积分商品）扣减与后续充值/流水页使用。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Credit
{
    private static $instance;

    const KEY    = 'mlshop_credit_balance';
    const LEDGER = 'mlshop_credit_ledger';

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * 当前积分余额。
     */
    public static function get_balance($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return 0;
        }
        return (float) get_user_meta($user_id, self::KEY, true);
    }

    /**
     * 积分是否充足。
     */
    public static function can_spend($user_id, $amount)
    {
        return self::get_balance($user_id) >= (float) $amount;
    }

    /**
     * 增加积分（充值 / 赠送 / 返还）。
     */
    public static function add($user_id, $amount, $note = '')
    {
        $user_id = (int) $user_id;
        $amount  = (float) $amount;
        if ($amount <= 0) {
            return self::get_balance($user_id);
        }
        // 原子累加：并发充值 / 退款回补时不丢更新（读改写会互相覆盖）。
        mlshop_atomic_increment_user_meta($user_id, self::KEY, $amount);
        $balance = self::get_balance($user_id);
        self::log($user_id, $amount, $balance, $note);
        do_action('mlshop_credit_added', $user_id, $amount, $balance, $note);
        return $balance;
    }

    /**
     * 扣减积分（消费）。余额不足返回 false。
     */
    public static function spend($user_id, $amount, $note = '')
    {
        $user_id = (int) $user_id;
        $amount  = (float) $amount;
        if ($amount <= 0) {
            return self::get_balance($user_id);
        }
        // 原子扣减：并发请求同时消费时，只有余额充足的那次会成功，避免积分被扣成负数。
        if (!mlshop_atomic_decrement_user_meta($user_id, self::KEY, $amount)) {
            return false;
        }
        $new = self::get_balance($user_id);
        self::log($user_id, -$amount, $new, $note);
        do_action('mlshop_credit_spent', $user_id, $amount, $new, $note);
        return $new;
    }

    /**
     * 最近流水（倒序）。
     */
    public static function get_ledger($user_id = 0, $limit = 50)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return array();
        }
        $ledger = get_user_meta($user_id, self::LEDGER, true);
        if (!is_array($ledger)) {
            return array();
        }
        return array_slice(array_reverse($ledger), 0, (int) $limit);
    }

    private static function log($user_id, $delta, $balance, $note)
    {
        // 审计 M1：ledger 是「读数组 → 追加 → 整体覆盖写」，余额本身原子但流水
        // 在并发下会被后写者覆盖（丢审计行）。以每用户命名锁串行化该段；
        // 锁不可用（GET_LOCK 返回 NULL）时优雅降级为无锁写入（丢流水好过阻塞付款）。
        global $wpdb;
        $lock_name = 'mlshop_credit_ledger_' . $user_id;
        $lock      = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock_name));
        $locked    = ('1' === (string) $lock);
        $ledger = get_user_meta($user_id, self::LEDGER, true);
        if (!is_array($ledger)) {
            $ledger = array();
        }
        $ledger[] = array(
            'time'    => time(),
            'delta'   => $delta,
            'balance' => $balance,
            'note'    => $note,
        );
        if (count($ledger) > 200) {
            $ledger = array_slice($ledger, -200);
        }
        update_user_meta($user_id, self::LEDGER, $ledger);
        if ($locked) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }
}
