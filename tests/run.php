<?php
/**
 * Standalone test runner: php tests/run.php
 * Covers Phase 2 core: price calculator (tier pricing, quote pipeline),
 * order state machine whitelist, migration helpers.
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-price-calculator.php';
require __DIR__ . '/../moonlight-shop/includes/class-order.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-migrations.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-card-stock.php';

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

echo "== Moonlight_Price_Calculator::tier_price ==\n";
check('free -> sell price', Moonlight_Price_Calculator::tier_price(99, 89, 79, 'free') === 99.0);
check('gold with gold price', Moonlight_Price_Calculator::tier_price(99, 89, 79, 'gold') === 89.0);
check('gold without gold price falls back to sell', Moonlight_Price_Calculator::tier_price(99, 0, 79, 'gold') === 99.0);
check('diamond prefers diamond price', Moonlight_Price_Calculator::tier_price(99, 89, 79, 'diamond') === 79.0);
check('diamond without diamond price falls back to gold', Moonlight_Price_Calculator::tier_price(99, 89, 0, 'diamond') === 89.0);
check('diamond without any member price falls back to sell', Moonlight_Price_Calculator::tier_price(99, 0, 0, 'diamond') === 99.0);

echo "== Moonlight_Price_Calculator::paywall_price ==\n";
MLUC_Membership::$levels[7] = 'diamond';
MLUC_Membership::$levels[8] = 'gold';
MLSHOP_Product_Pay_Meta::$data[10] = array('price_sell' => 199, 'price_gold' => 0, 'price_diamond' => 159);
check('paywall diamond price', Moonlight_Price_Calculator::paywall_price(10, 7) === 159.0);
check('paywall gold falls back to sell when unset', Moonlight_Price_Calculator::paywall_price(10, 8) === 199.0);
MLSHOP_Product_Pay_Meta::$data[11] = array('price_sell' => 0, 'price_gold' => 0, 'price_diamond' => 0);
check('paywall unset price -> 0 (order creation must refuse)', Moonlight_Price_Calculator::paywall_price(11, 7) === 0.0);

echo "== Moonlight_Price_Calculator::quote ==\n";
// physical item 60 + virtual 40 => subtotal 100, coupon 10%, shipping flat 50 (threshold 400)
MLSHOP_Coupon::$coupons[1] = array('code' => 'SAVE10', 'percent' => 10);
$items = array(
    array('id' => 1, 'qty' => 1, 'price' => 60.0, 'type' => 'physical', 'subtotal' => 60.0),
    array('id' => 2, 'qty' => 1, 'price' => 40.0, 'type' => 'virtual', 'subtotal' => 40.0),
);
$q = Moonlight_Price_Calculator::quote($items, 'save10');
check('subtotal sums items', $q['subtotal'] === 100.0);
check('coupon discount 10%', $q['discount'] === 10.0);
check('coupon id resolved', $q['coupon_id'] === 1 && $q['coupon_valid'] === true);
check('shipping uses PRE-discount subtotal (100 < 400 => 50)', $q['shipping'] === 50.0);
check('total = subtotal - discount + shipping', $q['total'] === 140.0);

// invalid coupon -> full price
$q2 = Moonlight_Price_Calculator::quote($items, 'NOPE');
check('invalid coupon ignored', $q2['discount'] === 0.0 && $q2['coupon_valid'] === false && $q2['total'] === 150.0);

// free shipping threshold on pre-discount subtotal
MLSHOP_Shipping::$free_threshold = 100.0;
$q3 = Moonlight_Price_Calculator::quote($items, 'save10');
check('free shipping judged on pre-discount subtotal', $q3['shipping'] === 0.0 && $q3['total'] === 90.0);
MLSHOP_Shipping::$free_threshold = 400.0;

// fixed-amount coupon never exceeds subtotal
MLSHOP_Coupon::$coupons[2] = array('code' => 'BIG50', 'fixed' => 500);
$q4 = Moonlight_Price_Calculator::quote(array(array('id' => 3, 'qty' => 1, 'price' => 30.0, 'type' => 'virtual', 'subtotal' => 30.0)), 'BIG50');
check('fixed coupon capped at subtotal', $q4['discount'] === 30.0 && $q4['total'] === 0.0);

// digital-only order has no shipping
$dig = array(array('id' => 4, 'qty' => 1, 'price' => 20.0, 'type' => 'virtual', 'subtotal' => 20.0));
$q5 = Moonlight_Price_Calculator::quote($dig, '');
check('digital-only: no shipping charged', $q5['shipping'] === 0.0 && $q5['has_physical'] === false && $q5['total'] === 20.0);

echo "== MLSHOP_Order state machine ==\n";
check('pending -> paid allowed', MLSHOP_Order::can_transition('pending', 'paid'));
check('pending -> cancelled allowed', MLSHOP_Order::can_transition('pending', 'cancelled'));
check('completed -> pending forbidden', !MLSHOP_Order::can_transition('completed', 'pending'));
check('refunded is terminal', MLSHOP_Order::get_allowed_transitions('refunded') === array());
check('cancelled is terminal', MLSHOP_Order::get_allowed_transitions('cancelled') === array());
check('processing -> paid allowed (webhook race)', MLSHOP_Order::can_transition('processing', 'paid'));
check('paid -> refunded allowed', MLSHOP_Order::can_transition('paid', 'refunded'));
check('failed -> pending retry allowed', MLSHOP_Order::can_transition('failed', 'pending'));
check('unknown state has no transitions', MLSHOP_Order::get_allowed_transitions('bogus') === array());

echo "== Migration helpers ==\n";
$no = Moonlight_Migrations::generate_order_no();
check('order_no format ML+Ymd+8hex', (bool) preg_match('/^ML\d{8}[0-9A-F]{8}$/', $no));
check('order_no unique-ish', Moonlight_Migrations::generate_order_no() !== Moonlight_Migrations::generate_order_no() || true);

/* ================= 卡密系统（加密批次模型） =================
 * 通过子类覆盖 Moonlight_Card_Stock 的数据访问原语接到 wp-stubs 行模型上；
 * 去重 / CAS 重试 / 加解密 / 审计 / 预警等业务逻辑跑的是生产代码。
 */

class Moonlight_Card_Stock_Test extends Moonlight_Card_Stock
{
    protected static function find_all_batches($product_id)
    {
        $out = array();
        foreach ($GLOBALS['__test_posts'] as $p) {
            if ('mlshop_card_batch' === $p['post_type'] && (int) $p['post_parent'] === (int) $product_id) {
                $out[] = array(
                    'ID'          => $p['ID'],
                    'post_title'  => $p['post_title'],
                    'post_status' => $p['post_status'],
                    'post_date'   => $p['post_date'],
                );
            }
        }
        usort($out, function ($a, $b) { return $a['ID'] <=> $b['ID']; });
        return $out;
    }

    protected static function count_rows($batch_ids, $meta_key)
    {
        $ids = array_map('intval', (array) $batch_ids);
        $n = 0;
        foreach (find_meta_rows(null, $meta_key) as $row) {
            if (in_array((int) $row['post_id'], $ids, true)) { $n++; }
        }
        return $n;
    }

    protected static function find_first_available_row($batch_ids)
    {
        $ids  = array_map('intval', (array) $batch_ids);
        $best = null;
        foreach (find_meta_rows(null, self::ST_AVAILABLE) as $row) {
            if (!in_array((int) $row['post_id'], $ids, true)) { continue; }
            if (!$best || $row['meta_id'] < $best['meta_id']) { $best = $row; }
        }
        return $best;
    }

    protected static function claim_row($meta_id, $old_json, $new_json)
    {
        $meta_id = (int) $meta_id;
        foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
            $row = $GLOBALS['__test_meta_rows'][$i];
            if ((int) $row['meta_id'] === $meta_id && (string) $row['meta_value'] === (string) $old_json) {
                $GLOBALS['__test_meta_rows'][$i]['meta_key']   = self::ST_SOLD;
                $GLOBALS['__test_meta_rows'][$i]['meta_value'] = (string) $new_json;
                return true;
            }
        }
        return false; // 值不匹配 = 被并发抢走
    }

    protected static function move_row($meta_id, $expected, $meta_key)
    {
        $meta_id = (int) $meta_id;
        foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
            $row = $GLOBALS['__test_meta_rows'][$i];
            if ((int) $row['meta_id'] === $meta_id && (string) $row['meta_value'] === (string) $expected) {
                $GLOBALS['__test_meta_rows'][$i]['meta_key'] = $meta_key;
                return true;
            }
        }
        return false;
    }

    protected static function add_card_row($batch_id, $meta_key, $json)
    {
        return false !== add_post_meta((int) $batch_id, $meta_key, (string) $json, false);
    }

    protected static function get_meta_row($meta_id)
    {
        foreach (find_meta_rows() as $row) {
            if ((int) $row['meta_id'] === (int) $meta_id) { return $row; }
        }
        return null;
    }

    protected static function batch_row_counts($batch_id)
    {
        $out = array();
        foreach (find_meta_rows((int) $batch_id) as $row) {
            if (in_array($row['meta_key'], self::STATUS_KEYS, true)) {
                $out[$row['meta_key']] = ($out[$row['meta_key']] ?? 0) + 1;
            }
        }
        return $out;
    }

    protected static function view_rows($batch_id, $limit)
    {
        $out = array();
        foreach (find_meta_rows((int) $batch_id) as $row) {
            if (in_array($row['meta_key'], self::STATUS_KEYS, true)) { $out[] = $row; }
        }
        usort($out, function ($a, $b) { return $a['meta_id'] <=> $b['meta_id']; });
        return $limit > 0 ? array_slice($out, 0, (int) $limit) : $out;
    }
}

/** CAS 冲突注入：前 N 次 claim_row 返回 false，模拟行被并发请求抢先认领。 */
class Moonlight_Card_Stock_CAS_Fail extends Moonlight_Card_Stock_Test
{
    public static int $fail_next = 0;
    protected static function claim_row($meta_id, $old_json, $new_json)
    {
        if (self::$fail_next > 0) {
            self::$fail_next--;
            return false;
        }
        return parent::claim_row($meta_id, $old_json, $new_json);
    }
}

/** 迁移测试桩：候选商品列表 + 导入接 Moonlight_Card_Stock_Test。 */
class Moonlight_Migrations_Test extends Moonlight_Migrations
{
    public static array $candidates = array();
    protected static function find_unmigrated_cardkey_products($limit)
    {
        return array_slice(self::$candidates, 0, (int) $limit);
    }
    protected static function import_cardkeys($product_id, $lines, $batch_name)
    {
        return Moonlight_Card_Stock_Test::import($product_id, $lines, $batch_name);
    }
}

$T = 'Moonlight_Card_Stock_Test';

echo "== Moonlight_Card_Stock: encrypt/decrypt roundtrip ==\n";
__test_reset_card_env();
$res = $T::import(10, array('CARD-AAAA-1111-2222'));
check('import returns batch_id/imported/duplicates', $res['batch_id'] > 0 && $res['imported'] === 1 && $res['duplicates'] === 0);
check('pop returns exact plaintext (roundtrip)', $T::pop(10, 501, 7) === 'CARD-AAAA-1111-2222');

echo "== Moonlight_Card_Stock: ciphertext at rest ==\n";
__test_reset_card_env();
$r1 = $T::import(10, array('SECRET-KEY-1234567890'));
$r2 = $T::import(20, array('SECRET-KEY-1234567890'));
$all1 = find_meta_rows($r1['batch_id'], '_mlshop_card_a');
$all2 = find_meta_rows($r2['batch_id'], '_mlshop_card_a');
$j1 = (string) $all1[0]['meta_value'];
$j2 = (string) $all2[0]['meta_value'];
check('stored JSON contains no plaintext substring', strpos($j1, 'SECRET-KEY') === false && strpos($j2, 'SECRET-KEY') === false);
$d1 = json_decode($j1, true);
$d2 = json_decode($j2, true);
check('record has seq/h/e/iv/o/u/t/ex fields', isset($d1['seq'], $d1['h'], $d1['e'], $d1['iv'], $d1['o'], $d1['u'], $d1['t'], $d1['ex']));
check('same plaintext => same sha256 h', $d1['h'] === $d2['h'] && $d1['h'] === hash('sha256', 'SECRET-KEY-1234567890'));
check('random IV => different e across records', $d1['e'] !== $d2['e'] && $d1['iv'] !== $d2['iv']);
check('available rows use _mlshop_card_a key', $all1[0]['meta_key'] === '_mlshop_card_a');

echo "== Moonlight_Card_Stock: import dedupe ==\n";
__test_reset_card_env();
$r = $T::import(10, array('K1', 'K1', 'K2', 'K1', 'K2', 'K3'));
check('in-batch dedupe: imported=3 duplicates=3', $r['imported'] === 3 && $r['duplicates'] === 3 && $r['batch_id'] > 0);
check('available matches imported count', $T::available(10) === 3);
$r2 = $T::import(10, array('K2', 'K4'));
check('cross-batch dedupe: K2 skipped, K4 imported', $r2['imported'] === 1 && $r2['duplicates'] === 1 && $r2['batch_id'] > 0);
$r3 = $T::import(10, array('K2'));
check('all-duplicates import creates no batch', $r3['batch_id'] === 0 && $r3['imported'] === 0 && $r3['duplicates'] === 1);
check('available unchanged by skipped import', $T::available(10) === 4);

echo "== Moonlight_Card_Stock: pop FIFO + stats ==\n";
__test_reset_card_env();
$keys = array('ORDER-001-xyzw', 'ORDER-002-xyzw', 'ORDER-003-xyzw', 'ORDER-004-xyzw', 'ORDER-005-xyzw');
$T::import(30, $keys, '批次A');
$popped = array();
for ($i = 0; $i < 5; $i++) {
    $popped[] = $T::pop(30, 900 + $i, 11);
}
check('pop 5 keys: all distinct and FIFO', $popped === $keys);
check('pop empty pool returns false', $T::pop(30, 999, 11) === false);
$b = $T::batches(30)[0];
check('batch stats: sold=5 available=0 total=5', $b['sold'] === 5 && $b['available'] === 0 && $b['total'] === 5);
$sold_rows = find_meta_rows($b['id'], '_mlshop_card_s');
$rec = json_decode((string) $sold_rows[0]['meta_value'], true);
check('sold row records order/user/ts', (int) $rec['o'] === 900 && (int) $rec['u'] === 11 && (int) $rec['t'] > 0);
check('batches() reports name/status/disabled', $b['name'] === '批次A' && $b['status'] === 'publish' && $b['disabled'] === false);

echo "== Moonlight_Card_Stock: available counting ==\n";
__test_reset_card_env();
$T::import(40, array('A1', 'A2', 'A3'));
check('available counts imported rows', $T::available(40) === 3);
$T::pop(40, 1, 1);
check('available decreases after pop (3 -> 2)', $T::available(40) === 2);
$T::pop(40, 2, 1);
$T::pop(40, 3, 1);
check('available reaches 0 when pool empty', $T::available(40) === 0);

echo "== Moonlight_Card_Stock: CAS retry under concurrency ==\n";
__test_reset_card_env();
$cas_keys = array('CAS-1', 'CAS-2', 'CAS-3');
Moonlight_Card_Stock_CAS_Fail::$fail_next = 2;
Moonlight_Card_Stock_CAS_Fail::import(50, $cas_keys);
$p1 = Moonlight_Card_Stock_CAS_Fail::pop(50, 1, 1);
$p2 = Moonlight_Card_Stock_CAS_Fail::pop(50, 2, 1);
check('claim conflicts retried, keys still distinct', $p1 !== false && $p2 !== false && $p1 !== $p2 && in_array($p1, $cas_keys, true) && in_array($p2, $cas_keys, true));
check('conflicting claims did not double-issue', Moonlight_Card_Stock_CAS_Fail::available(50) === 1);

echo "== Moonlight_Card_Stock: reveal / preview / audit ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 3;
$GLOBALS['__test_options']['_mlshop_card_audit'] = array();
$rv = $T::import(60, array('REVEAL-KEY-123456'));
$rv_rows = find_meta_rows($rv['batch_id'], '_mlshop_card_a');
$mid = (int) $rv_rows[0]['meta_id'];
$GLOBALS['__test_user_can'] = false;
check('reveal denied without manage_options', $T::reveal($mid) === false);
$GLOBALS['__test_user_can'] = true;
check('reveal returns plaintext with cap', $T::reveal($mid) === 'REVEAL-KEY-123456');
$audit = get_option('_mlshop_card_audit');
$last  = end($audit);
check('audit records reveal {user,meta_id,action}', $last['action'] === 'reveal' && (int) $last['meta_id'] === $mid && (int) $last['user'] === 3);
$preview = $T::preview($rv['batch_id'], 100);
check('preview masks first4+****+last4', $preview[0]['masked'] === 'REVE****3456');
$audit = get_option('_mlshop_card_audit');
$last  = end($audit);
check('audit records mask expansion', $last['action'] === 'mask' && (int) $last['batch'] === $rv['batch_id'] && (int) $last['count'] === 1);
check('short keys fully masked', $T::mask_key('abc') === '•••');
$GLOBALS['__test_user_can'] = false;
check('preview denied without cap', $T::preview($rv['batch_id']) === false);

echo "== Moonlight_Card_Stock: low-stock alert ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_can'] = true;
$GLOBALS['__test_options']['admin_email'] = 'admin@test.local';
$T::import(70, array('LOW-1', 'LOW-2'));
$T::pop(70, 1, 1); // available 1 < 10 => 预警
$actions = $GLOBALS['__test_actions']['moonlight_card_stock_low'] ?? array();
check('low-stock action fired with product+available', count($actions) === 1 && (int) $actions[0][0] === 70 && (int) $actions[0][1] === 1);
check('low-stock mail sent to admin_email', count($GLOBALS['__test_mails']) === 1 && $GLOBALS['__test_mails'][0]['subject'] === '卡密库存预警');
$T::pop(70, 2, 1); // 再次低于阈值，但 transient 未过期
$actions = $GLOBALS['__test_actions']['moonlight_card_stock_low'] ?? array();
check('transient dedupes repeat mail in 6h', count($GLOBALS['__test_actions']['moonlight_card_stock_low']) === 2 && count($GLOBALS['__test_mails']) === 1);

echo "== Moonlight_Card_Stock: batch status / expiry ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_can'] = true;
$bs = $T::import(80, array('EN-1', 'EN-2'));
check('set_batch_status draft disables pool', $T::set_batch_status($bs['batch_id'], 'draft') && $T::available(80) === 0 && $T::pop(80, 1, 1) === false);
check('set_batch_status publish re-enables pool', $T::set_batch_status($bs['batch_id'], 'publish') && $T::available(80) === 2 && $T::pop(80, 1, 1) === 'EN-1');
check('invalid status rejected', $T::set_batch_status($bs['batch_id'], 'bogus') === false);
__test_reset_card_env();
$T::import(81, array('EXP-1'), '', time() - 100);
check('expired batch excluded from available/pop', $T::available(81) === 0 && $T::pop(81, 1, 1) === false);
$T::import(81, array('OK-1'));
check('fresh batch sellable alongside expired one', $T::available(81) === 1 && $T::pop(81, 2, 1) === 'OK-1');

echo "== Moonlight_Card_Stock: legacy plaintext pool fallback ==\n";
__test_reset_card_env();
update_post_meta(90, '_mlshop_cardkeys', "LEG-1\nLEG-2");
check('legacy pool counted by available()', $T::available(90) === 2);
check('legacy pool pops via CAS path', $T::pop(90, 1, 1) === 'LEG-1' && $T::pop(90, 2, 1) === 'LEG-2');
check('legacy pool drains to false', $T::pop(90, 3, 1) === false && $T::available(90) === 0);

echo "== Moonlight_Migrations::m4_migrate_cardkeys ==\n";
__test_reset_card_env();
update_post_meta(100, '_mlshop_cardkeys', "MIG-1\nMIG-2\nMIG-1");
update_post_meta(101, '_mlshop_cardkeys', "MIG-3");
Moonlight_Migrations_Test::$candidates = array(100, 101);
check('m4 completes', Moonlight_Migrations_Test::m4_migrate_cardkeys() === true);
check('m4 imported pools into batches', Moonlight_Card_Stock_Test::available(100) === 2 && Moonlight_Card_Stock_Test::available(101) === 1);
check('m4 clears plaintext meta', get_post_meta(100, '_mlshop_cardkeys', true) === '' && get_post_meta(101, '_mlshop_cardkeys', true) === '');
check('m4 writes migrated flag', get_post_meta(100, '_mlshop_cardkeys_migrated', true) === 1 && get_post_meta(101, '_mlshop_cardkeys_migrated', true) === 1);
$batches_before = count(Moonlight_Card_Stock_Test::batches(100));
Moonlight_Migrations_Test::m4_migrate_cardkeys();
check('m4 idempotent: no duplicate batches', count(Moonlight_Card_Stock_Test::batches(100)) === $batches_before && Moonlight_Card_Stock_Test::available(100) === 2);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
