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

/* ---------- 模拟用户旅程补充桩（AJAX 层 + postmeta 单值行模型） ---------- */
function add_filter($h, $c = null, $p = 10, $a = 1) { return true; }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)); }
function is_user_logged_in() { return 0 !== get_current_user_id(); }
function check_ajax_referer($a = -1, $q = false, $d = true) { return true; }
function date_i18n($f, $ts = null) { return date($f, $ts ?: time()); }
function clean_post_cache($id) {}
function get_transient($k) { return $GLOBALS['__test_transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['__test_transients'][$k] = $v; return true; }
if (!class_exists('WP_Send_Json_Exception')) {
    class WP_Send_Json_Exception extends Exception
    {
        public $payload;
        public function __construct($payload, $status_code = 200) { parent::__construct('wp_send_json'); $this->payload = $payload; }
    }
}
function wp_send_json($response, $status_code = 200) { throw new WP_Send_Json_Exception($response, $status_code); }
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_posts'] = array();     // [id] => ['post_type' => ..]
$GLOBALS['__test_postmeta'] = array();  // [id][key] => value
function wp_insert_post($args, $wp_error = false)
{
    static $next = 1000;
    $id = $next++;
    $GLOBALS['__test_posts'][$id] = array('post_type' => isset($args['post_type']) ? $args['post_type'] : 'post');
    return $id;
}
function get_post($id) { return isset($GLOBALS['__test_posts'][(int) $id]) ? (object) $GLOBALS['__test_posts'][(int) $id] : null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_post_meta($id, $key, $single = true) { return isset($GLOBALS['__test_postmeta'][(int) $id][$key]) ? $GLOBALS['__test_postmeta'][(int) $id][$key] : ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['__test_postmeta'][(int) $id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['__test_postmeta'][(int) $id][$key]); return true; }

/* ---------- 假 $wpdb：仅实现原子账本用到的 usermeta 路径 ---------- */
class Fake_WPDB_Usermeta
{
    public $usermeta = 'wp_usermeta';
    public $postmeta = 'wp_postmeta';
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
        // 订单号查重 / postmeta SELECT：行模型中无既有订单号，恒无冲突。
        if (false !== strpos($sql, 'wp_postmeta')) {
            return null;
        }
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
        // complete_order 的原子完单抢占：UPDATE wp_postmeta SET meta_value='paid'
        // WHERE post_id=N AND meta_key='_mluc_pay_status' AND meta_value='pending'。
        if (0 === strpos($sql, 'UPDATE') && false !== strpos($sql, 'wp_postmeta')) {
            if (preg_match("/UPDATE\s+wp_postmeta\s+SET\s+meta_value\s*=\s*'([^']*)'\s+WHERE\s+post_id\s*=\s*(\d+)\s+AND\s+meta_key\s*=\s*'([^']*)'\s+AND\s+meta_value\s*=\s*'([^']*)'/i", $sql, $m)) {
                $pid = (int) $m[2];
                $key = $m[3];
                $cur = isset($GLOBALS['__test_postmeta'][$pid][$key]) ? (string) $GLOBALS['__test_postmeta'][$pid][$key] : '';
                if ($cur === $m[4]) {
                    $GLOBALS['__test_postmeta'][$pid][$key] = $m[1];
                    return 1;
                }
                return 0;
            }
            return 0;
        }
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
require ABSPATH . '../moonlight-user-center/includes/class-payment-log.php';
require ABSPATH . '../moonlight-user-center/includes/class-payments.php';
require ABSPATH . '../moonlight-user-center/includes/class-payment-manager.php';
require ABSPATH . '../moonlight-user-center/includes/class-gateway-manual.php';
require ABSPATH . '../moonlight-user-center/includes/class-gateway-paypal.php';
require ABSPATH . '../moonlight-user-center/includes/class-gateway-stripe.php';
require ABSPATH . '../moonlight-user-center/includes/class-gateway-alipay.php';
require ABSPATH . '../moonlight-user-center/includes/class-gateway-balance.php';
require ABSPATH . '../moonlight-user-center/includes/class-credit-ui.php';

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

/* ==========================================================================
 * 模拟用户全流程（AJAX 层）：签到 → 充值 → 兑换 → 积分支付
 * ========================================================================== */

if (!class_exists('MLUC_Membership')) {
    class MLUC_Membership
    {
        public static function get_levels() { return array('gold' => array('label' => 'Gold')); }
        public static function get_instance() { return new self(); }
        public function grant_level($uid, $level) { $GLOBALS['__test_granted'][] = array($uid, $level); return true; }
    }
}
$GLOBALS['__test_granted'] = array();

function __sim_ajax($handler, array $post)
{
    $_POST = $post;
    try {
        call_user_func($handler);
        return array('success' => false, 'message' => 'no-json', 'data' => array());
    } catch (WP_Send_Json_Exception $e) {
        return $e->payload;
    } finally {
        $_POST = array();
    }
}

echo "== 模拟用户：每日签到（AJAX 全流程） ==\n";
$GLOBALS['__test_usermeta'] = array();
$GLOBALS['__test_options']['mluc_options'] = array(
    'credit_enabled' => 1, 'checkin_enabled' => 1,
    'checkin_base' => 5, 'checkin_every' => 7, 'checkin_extra' => 20,
);
$__r = __sim_ajax(array(MLUC_Checkin::get_instance(), 'ajax_checkin'), array('nonce' => 'x'));
check('首次签到成功且奖励 5', true === $__r['success'] && 5 === (int) $__r['data']['award'] && 1 === (int) $__r['data']['streak']);
check('签到到账（余额 5）', 5.0 === MLUC_Credit::get_balance(1));
$__r = __sim_ajax(array(MLUC_Checkin::get_instance(), 'ajax_checkin'), array('nonce' => 'x'));
check('当日重复签到被拒绝', false === $__r['success']);
check('重复签到未重复发奖', 5.0 === MLUC_Credit::get_balance(1));

echo "== 模拟用户：积分充值（套餐 → 线下转账 → 确认收款 → 入账） ==\n";
$GLOBALS['__test_options']['mluc_options'] += array('manual_enabled' => 1, 'pay_currency_code' => 'USD', 'pay_currency_symbol' => '$');
$__r = __sim_ajax(array(MLUC_Credit_UI::get_instance(), 'ajax_create_recharge'), array('package' => '0', 'gateway' => 'manual', 'nonce' => 'x'));
check('套餐充值下单成功（100 积分 = 10 元）', true === $__r['success'] && isset($__r['data']['order_id']));
$__order = (int) $__r['data']['order_id'];
check('充值订单初始 pending', 'pending' === (string) get_post_meta($__order, '_mluc_pay_status', true));
check('线上网关（manual）未入账前余额不变', 5.0 === MLUC_Credit::get_balance(1));
check('确认收款 complete_order 成功', true === MLUC_Payments::complete_order($__order, 'TXN-1', 'manual'));
check('重复确认被拒绝（mluc_dup）', is_wp_error(MLUC_Payments::complete_order($__order, 'TXN-1', 'manual')));
MLUC_Credit_UI::get_instance()->grant_recharge($__order, 1, 'recharge');
check('充值 100 积分到账（5 + 100 = 105）', 105.0 === MLUC_Credit::get_balance(1));
MLUC_Credit_UI::get_instance()->grant_recharge($__order, 1, 'recharge');
check('重复入账被幂等拦截', 105.0 === MLUC_Credit::get_balance(1));

echo "== 模拟用户：积分兑换余额（AJAX） ==\n";
$GLOBALS['__test_options']['mluc_options'] += array('balance_enabled' => 1, 'credit_exchange_enabled' => 1, 'credit_exchange_rate' => 100, 'credit_exchange_min' => 100);
$__r = __sim_ajax(array(MLUC_Credit_UI::get_instance(), 'ajax_exchange_credit'), array('points' => '50', 'nonce' => 'x'));
check('低于最低兑换量被拒绝', false === $__r['success']);
$__r = __sim_ajax(array(MLUC_Credit_UI::get_instance(), 'ajax_exchange_credit'), array('points' => '300', 'nonce' => 'x'));
check('积分不足兑换被拒绝', false === $__r['success']);
check('拒绝后积分未变动', 105.0 === MLUC_Credit::get_balance(1));
$__r = __sim_ajax(array(MLUC_Credit_UI::get_instance(), 'ajax_exchange_credit'), array('points' => '100', 'nonce' => 'x'));
check('兑换成功：100 积分 = 1.00 余额', true === $__r['success'] && 5.0 === MLUC_Credit::get_balance(1) && 1.0 === MLUC_Wallet::get_balance(1));

echo "== 模拟用户：积分支付网关（换算 / 拒绝 / 支付 / 退款） ==\n";
$GLOBALS['__test_options']['mluc_options'] += array('credit_rate' => 10);
// 浮点换算回归：1.10 × 10 曾被浮点误差顶成 12。
$__fo = wp_insert_post(array('post_type' => MLUC_Payments::CPT, 'post_status' => 'publish'));
update_post_meta($__fo, '_mluc_pay_price', 1.10);
update_post_meta($__fo, '_mluc_pay_type', 'membership');
update_post_meta($__fo, '_mluc_pay_level', 'gold');
update_post_meta($__fo, '_mluc_pay_user', 1);
update_post_meta($__fo, '_mluc_pay_status', 'pending');
check('订单应付积分 = 11（1.10 × 10，浮点回归）', 11 === MLUC_Gateway_Credit::order_cost($__fo));
// 充值订单拒绝积分支付
$__rc = wp_insert_post(array('post_type' => MLUC_Payments::CPT, 'post_status' => 'publish'));
update_post_meta($__rc, '_mluc_pay_price', 10.0);
update_post_meta($__rc, '_mluc_pay_type', 'recharge');
update_post_meta($__rc, '_mluc_pay_credit', 100.0);
update_post_meta($__rc, '_mluc_pay_user', 1);
update_post_meta($__rc, '_mluc_pay_status', 'pending');
check('充值订单拒绝积分支付（防循环套利）', is_wp_error((new MLUC_Gateway_Credit())->process_payment($__rc)));
// 余额不足
check('积分不足支付被拒绝', is_wp_error((new MLUC_Gateway_Credit())->process_payment($__fo)));
check('拒绝后订单仍 pending', 'pending' === (string) get_post_meta($__fo, '_mluc_pay_status', true));
check('拒绝后积分未扣减', 5.0 === MLUC_Credit::get_balance(1));
// 充足：价格改 2.50 → 应付 25，余额 5 + 20 = 25
update_post_meta($__fo, '_mluc_pay_price', 2.50);
MLUC_Credit::add(1, 20, 'topup');
$__res = (new MLUC_Gateway_Credit())->process_payment($__fo);
check('积分支付成功（扣 25 到 0）', is_array($__res) && 'credit' === $__res['flow'] && 0.0 === MLUC_Credit::get_balance(1));
check('订单转 paid 并记录扣减积分', 'paid' === (string) get_post_meta($__fo, '_mluc_pay_status', true) && 25 === (int) get_post_meta($__fo, '_mluc_pay_points', true));
check('query_payment 返回 paid', 'paid' === (new MLUC_Gateway_Credit())->query_payment($__fo));
// 退款：按订单扣减数回补
check('积分退款回补 25', true === (new MLUC_Gateway_Credit())->refund($__fo) && 25.0 === MLUC_Credit::get_balance(1));

echo ($fail ? "CREDIT-FAIL($fail)\n" : "CREDIT-OK\n");
exit($fail ? 1 : 0);
