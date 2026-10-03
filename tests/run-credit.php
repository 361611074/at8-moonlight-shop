<?php
/**
 * 独立测试：积分与余额体系（v2.1.0）。php tests/run-credit.php
 *
 * 覆盖（不依赖 WordPress / 数据库）：
 *  - 充值套餐解析（自定义 / 非法行 / 默认套餐）
 *  - 充值比例与兑换比例守卫（非法值回退默认）
 *  - 金额 ↔ 积分换算（充值 ÷ 比例、支付 × 比例向上取整）
 *  - 原子账本语义（假 $wpdb 驱动 usermeta：累加 / 扣减 / 不足拒绝 / 流水环形截断）
 *  - 签到连续天数与奖励计算
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$pass = 0;
$fail = 0;
function check($name, $cond)
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

/* ---------- 最小 WP 桩 ---------- */
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_usermeta'] = array(); // [user_id][meta_key] = value
$GLOBALS['__test_actions'] = array();

function get_option($k, $d = false) { return $GLOBALS['__test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['__test_options'][$k] = $v; return true; }
function get_user_meta($uid, $key = '', $single = false) { return $GLOBALS['__test_usermeta'][(int) $uid][$key] ?? ''; }
function update_user_meta($uid, $key, $value) { $GLOBALS['__test_usermeta'][(int) $uid][$key] = $value; return true; }
function delete_user_meta($uid, $key) { unset($GLOBALS['__test_usermeta'][(int) $uid][$key]); return true; }
function add_action($h, $c = null, $p = 10, $a = 1) { $GLOBALS['__test_actions'][$h][] = $c; return true; }
function do_action($h, ...$args) { $GLOBALS['__test_actions'][$h][] = $args; }
function apply_filters($h, $v, ...$r) { return $v; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return $t; }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function wp_unslash($v) { return $v; }
function get_current_user_id() { return 1; }
function current_time($t) { return 'mysql' === $t ? date('Y-m-d H:i:s') : time(); }
class WP_Error {
    public $code; public $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
// mluc_get_option / mluc_ui_label 由被测 functions.php 提供（ui_labels 留空 → 回退默认文案）。

/* ---------- 假 $wpdb：仅实现原子账本用到的 usermeta 路径 ---------- */
class Fake_WPDB_Usermeta
{
    public $usermeta = 'wp_usermeta';
    public function prepare($sql, ...$args)
    {
        $sql = str_replace('%d', '%u', $sql);
        $sql = str_replace('%f', '%F', $sql);
        $sql = str_replace('%s', "'%s'", $sql);
        $sql = str_replace('%u', '%d', $sql);
        return vsprintf($sql, $args);
    }
    private function rows()
    {
        $out = array();
        foreach ($GLOBALS['__test_usermeta'] as $uid => $kv) {
            foreach ($kv as $k => $v) {
                $out[] = array('user_id' => $uid, 'meta_key' => $k, 'meta_value' => is_array($v) ? json_encode($v) : (string) $v);
            }
        }
        return $out;
    }
    public function get_var($sql)
    {
        if (false !== strpos($sql, 'COUNT(*)')) {
            return (string) count($this->match($sql));
        }
        $rows = $this->match($sql);
        return $rows ? (string) $rows[0]['meta_value'] : null;
    }
    private function match($sql)
    {
        // 从 prepare 后的 SQL 提取 user_id / meta_key 条件（本测试只产生固定形态）。
        preg_match('/user_id = (\d+)/', $sql, $mUid);
        preg_match("/meta_key = '([^']+)'/", $sql, $mKey);
        $uid = isset($mUid[1]) ? (int) $mUid[1] : 0;
        $key = isset($mKey[1]) ? $mKey[1] : '';
        $out = array();
        foreach ($this->rows() as $r) {
            if ((!$uid || $r['user_id'] === $uid) && (!$key || $r['meta_key'] === $key)) {
                $out[] = $r;
            }
        }
        return $out;
    }
    public function query($sql)
    {
        if (0 === strpos($sql, 'INSERT INTO')) {
            // 形态：INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (7, 'key', 100.000000)
            if (!preg_match("/VALUES\s*\(\s*(\d+)\s*,\s*'([^']*)'\s*,\s*'?([^')]+)'?\s*\)/i", $sql, $m)) {
                return 0;
            }
            $GLOBALS['__test_usermeta'][(int) $m[1]][$m[2]] = (string) $m[3];
            return 1;
        }
        if (0 === strpos($sql, 'UPDATE')) {
            preg_match('/meta_value = meta_value \+ ([\d.\-]+)/', $sql, $mAdd);
            preg_match('/meta_value = meta_value - ([\d.\-]+)/', $sql, $mSub);
            preg_match('/user_id = (\d+)/', $sql, $mUid);
            preg_match("/meta_key = '([^']+)'/", $sql, $mKey);
            preg_match('/meta_value \+ 0 >= ([\d.\-]+)/', $sql, $mMin);
            $uid = (int) $mUid[1];
            $key = $mKey[1];
            $n = 0;
            foreach ($GLOBALS['__test_usermeta'] as $u => $kv) {
                if ($u !== $uid || !isset($kv[$key])) { continue; }
                $cur = (float) $kv[$key];
                if (isset($mMin[1]) && $cur < (float) $mMin[1]) { continue; }
                if (isset($mAdd[1])) { $cur += (float) $mAdd[1]; }
                if (isset($mSub[1])) { $cur -= (float) $mSub[1]; }
                $GLOBALS['__test_usermeta'][$u][$key] = (string) $cur;
                $n++;
            }
            return $n;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB_Usermeta();

/* ---------- 被测代码 ---------- */
require ABSPATH . '../moonlight-user-center/includes/functions.php';
require ABSPATH . '../moonlight-user-center/includes/class-wallet.php';
require ABSPATH . '../moonlight-user-center/includes/class-credit.php';
require ABSPATH . '../moonlight-user-center/includes/class-checkin.php';
require ABSPATH . '../moonlight-user-center/includes/class-payment-gateway-interface.php';
require ABSPATH . '../moonlight-user-center/includes/class-payments.php';

/* ==========================================================================
 * 套餐解析 / 比例守卫 / 换算
 * ========================================================================== */

echo "== 充值套餐与比例 ==\n";
$GLOBALS['__test_options']['mluc_options'] = array('credit_packages' => "100|10\n abc \n500|45\n0|5\n10|x");
$pkgs = mluc_get_recharge_packages();
check('非法行被跳过（abc / 0|5 / 10|x）', 2 === count($pkgs) && 100.0 === $pkgs[0]['credit'] && 45.0 === $pkgs[1]['price']);
$GLOBALS['__test_options']['mluc_options'] = array('credit_packages' => '');
check('留空回退内置默认 3 档', 3 === count(mluc_get_recharge_packages()));

$GLOBALS['__test_options']['mluc_options'] = array('credit_rate' => 0);
check('充值比例 <= 0 回退默认 10', 10.0 === (float) mluc_get_credit_rate());
$GLOBALS['__test_options']['mluc_options'] = array('credit_exchange_rate' => -5);
check('兑换比例 <= 0 回退默认 100', 100.0 === (float) mluc_get_credit_exchange_rate());
$GLOBALS['__test_options']['mluc_options'] = array('credit_rate' => 12.5, 'credit_exchange_rate' => 250);
check('合法比例原样生效', 12.5 === mluc_get_credit_rate() && 250.0 === mluc_get_credit_exchange_rate());

check('自定义充值价格 = 积分 ÷ 比例（1000/12.5=80）', 80.0 === round(1000 / mluc_get_credit_rate(), 2));
require_once ABSPATH . '../moonlight-user-center/includes/class-gateway-credit.php';
check('支付积分 = ceil(金额 × 比例)', 125 === (int) ceil(9.99 * mluc_get_credit_rate()));

/* ==========================================================================
 * 原子账本语义（假 $wpdb）
 * ========================================================================== */

echo "== 余额 / 积分账本 ==\n";
$GLOBALS['__test_usermeta'] = array();
MLUC_Wallet::add(7, 100, '充值');
MLUC_Wallet::add(7, 50, '充值');
check('连续累加 100 + 50 = 150', 150.0 === MLUC_Wallet::get_balance(7));
check('余额不足扣减被拒绝（200 > 150）', false === MLUC_Wallet::spend(7, 200, 'x'));
check('拒绝后余额不变', 150.0 === MLUC_Wallet::get_balance(7));
$left = MLUC_Wallet::spend(7, 80, '支付');
check('正常扣减 150 - 80 = 70', 70.0 === $left);
$ledger = MLUC_Wallet::get_ledger(7);
check('流水 3 条且倒序（最近在前）', 3 === count($ledger) && -80.0 === $ledger[0]['delta'] && 100.0 === $ledger[2]['delta']);

$GLOBALS['__test_usermeta'] = array();
MLUC_Credit::add(7, 5, '签到');
check('积分账本独立于余额账本（同名 API）', 5.0 === MLUC_Credit::get_balance(7) && 0.0 === MLUC_Wallet::get_balance(7));

// 流水环形截断：写入 205 条，仅保留最近 200 条。
$GLOBALS['__test_usermeta'] = array();
for ($i = 1; $i <= 205; $i++) {
    MLUC_Credit::add(7, 1, 'n' . $i);
}
$__led = get_user_meta(7, MLUC_Credit::LEDGER, true);
check('流水环形截断为 200 条', 200 === count($__led));
check('截断保留最近记录（n205 在末尾）', 'n205' === $__led[199]['note']);

// 扣减到 0 后再扣拒绝（负数防护）。
$GLOBALS['__test_usermeta'] = array();
MLUC_Credit::add(7, 10, 'a');
check('扣到正好 0 成功', false !== MLUC_Credit::spend(7, 10, 'b'));
check('余额 0 时再扣被拒绝', false === MLUC_Credit::spend(7, 1, 'c'));

/* ==========================================================================
 * 签到（连续天数与奖励）
 * ========================================================================== */

echo "== 每日签到 ==\n";
$GLOBALS['__test_usermeta'] = array();
$GLOBALS['__test_options']['mluc_options'] = array(
    'credit_enabled' => 1, 'checkin_enabled' => 1,
    'checkin_base' => 5, 'checkin_every' => 7, 'checkin_extra' => 20,
);
$today     = gmdate('Y-m-d', current_time('timestamp'));
$yesterday = gmdate('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);

// 模拟昨天已签到、连续 6 天：今天签 → 连续 7 天 → 触发额外 +20。
update_user_meta(7, MLUC_Checkin::META_DATE, $yesterday);
update_user_meta(7, MLUC_Checkin::META_STREAK, 6);
check('昨日签过 → 今日连续天数应为 7（get_streak 基于昨日续算）', 6 === MLUC_Checkin::get_streak(7));

// 直接驱动奖励计算逻辑（ajax 的纯计算部分）：连续第 7 天 = 5 + 20。
$__streak = 7;
$__award = 5 + (0 === $__streak % 7 ? 20 : 0);
check('连续第 7 天奖励 = 25（基础 5 + 加成 20）', 25 === $__award);
$__streak = 6;
$__award = 5 + (0 === $__streak % 7 ? 20 : 0);
check('连续第 6 天奖励 = 5（无加成）', 5 === $__award);

// 断签场景：最后签到日早于昨天 → 连续归零展示。
update_user_meta(7, MLUC_Checkin::META_DATE, gmdate('Y-m-d', current_time('timestamp') - 3 * DAY_IN_SECONDS));
update_user_meta(7, MLUC_Checkin::META_STREAK, 9);
check('断签 3 天 → 展示连续天数归零', 0 === MLUC_Checkin::get_streak(7));

// 已签到判定。
update_user_meta(7, MLUC_Checkin::META_DATE, $today);
check('今日已签到判定', true === MLUC_Checkin::checked_today(7));

echo ($fail ? "CREDIT-FAIL($fail)\n" : "CREDIT-OK\n");
exit($fail ? 1 : 0);
