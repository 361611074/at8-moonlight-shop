<?php
/**
 * 余额钱包：用户余额账本（货币单位）。
 *
 * 存储：
 *   - user meta `mluc_balance`：当前余额（float，货币单位）
 *   - user meta `mluc_balance_ledger`：最近 200 条流水（array）
 *
 * 全部增减走 SQL 原子操作（mluc_atomic_increment/decrement_user_meta），
 * 并发支付 / 兑换 / 回补不会互相覆盖，也不会扣成负数。
 * 积分账本（MLUC_Credit）继承本类，仅更换存储键。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Wallet
{
    /** @var string 余额存储键（子类覆盖）。 */
    const KEY    = 'mluc_balance';
    /** @var string 流水存储键（子类覆盖）。 */
    const LEDGER = 'mluc_balance_ledger';
    /** @var string 动作前缀（mluc_wallet_added / mluc_wallet_spent）。 */
    const HOOK   = 'mluc_wallet';

    /**
     * 当前余额。
     */
    public static function get_balance($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return 0.0;
        }
        return (float) get_user_meta($user_id, static::KEY, true);
    }

    /**
     * 余额是否充足。
     */
    public static function can_spend($user_id, $amount)
    {
        return self::get_balance($user_id) >= (float) $amount;
    }

    /**
     * 增加（充值到账 / 兑换转入 / 退款回补 / 管理员调整）。
     *
     * @return float 新余额。
     */
    public static function add($user_id, $amount, $note = '')
    {
        $user_id = (int) $user_id;
        $amount  = (float) $amount;
        if ($user_id <= 0 || $amount <= 0) {
            return self::get_balance($user_id);
        }
        $balance = mluc_atomic_increment_user_meta($user_id, static::KEY, $amount);
        self::log($user_id, $amount, $balance, $note);
        do_action(static::HOOK . '_added', $user_id, $amount, $balance, $note);
        return $balance;
    }

    /**
     * 扣减（消费 / 兑换转出 / 管理员调整）。余额不足返回 false。
     *
     * @return float|false 新余额，失败时 false。
     */
    public static function spend($user_id, $amount, $note = '')
    {
        $user_id = (int) $user_id;
        $amount  = (float) $amount;
        if ($user_id <= 0 || $amount <= 0) {
            return false;
        }
        if (!mluc_atomic_decrement_user_meta($user_id, static::KEY, $amount)) {
            return false;
        }
        $balance = self::get_balance($user_id);
        self::log($user_id, -$amount, $balance, $note);
        do_action(static::HOOK . '_spent', $user_id, $amount, $balance, $note);
        return $balance;
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
        $ledger = get_user_meta($user_id, static::LEDGER, true);
        if (!is_array($ledger)) {
            return array();
        }
        return array_slice(array_reverse($ledger), 0, (int) $limit);
    }

    /**
     * 追加流水（环形截断 200 条，随 usermeta mluc_% 在卸载时清理）。
     */
    private static function log($user_id, $delta, $balance, $note)
    {
        $ledger = get_user_meta($user_id, static::LEDGER, true);
        if (!is_array($ledger)) {
            $ledger = array();
        }
        $ledger[] = array(
            'time'    => time(),
            'delta'   => (float) $delta,
            'balance' => (float) $balance,
            'note'    => sanitize_text_field((string) $note),
        );
        if (count($ledger) > 200) {
            $ledger = array_slice($ledger, -200);
        }
        update_user_meta($user_id, static::LEDGER, $ledger);
    }
}
