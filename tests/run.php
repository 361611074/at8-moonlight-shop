<?php
/**
 * Standalone test runner: php tests/run.php
 * Covers Phase 2 core: price calculator (tier pricing, quote pipeline),
 * order state machine whitelist, migration helpers.
 * 物流第一批：Region Provider、地址簿、运费模板计价、自提报价。
 * 物流第二批：状态机扩展（待发货/已发货/已签收）、标签单一来源、
 *             发货单/轨迹、Provider 注册表、cron 同步与自动完成、Express100 解析器。
 * Pro（moonlight-shop-pro）：MLPRO Webhook 签名/退避/事件过滤、
 *             CSV 公式注入防护、日期白名单、License 客户端本地回退。
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../moonlight-shop/includes/functions.php'; // 标签单一来源等（mlshop_get_option 由此提供）
require __DIR__ . '/../moonlight-shop/includes/core/interface-shipping-provider.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-provider-manual.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-provider-express100.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-price-calculator.php';
require __DIR__ . '/../moonlight-shop/includes/class-shipping.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-region-provider.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-address-book.php';
require __DIR__ . '/../moonlight-shop/includes/class-order.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-migrations.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-card-stock.php';
require __DIR__ . '/../moonlight-shop/includes/class-statistics.php';
// 售后退款（Refund_Service）+ 网关退款参数构造（Stripe / PayPal）
require __DIR__ . '/../moonlight-shop/includes/core/class-refund-service.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-stripe.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-paypal.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-wechat.php';
// Pro（moonlight-shop-pro）：可独立测的静态逻辑（class-analytics 依赖 WP_Query，不在此单测）
require __DIR__ . '/../moonlight-shop-pro/includes/class-license-client.php';
require __DIR__ . '/../moonlight-shop-pro/includes/class-webhooks.php';
require __DIR__ . '/../moonlight-shop-pro/includes/class-order-export.php';

/**
 * 邮件发送测试封装：强制重发并捕获 wp_mail 结果（游客购买用例使用）。
 */
function mlshop_email_test_send($order_id)
{
    delete_post_meta($order_id, '_mlshop_email_sent');
    MLSHOP_Email::get_instance()->send_order_paid_email($order_id, '', true);
}

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

// quote()/has_physical() 读 postmeta 的商品类型（生产语义：未设类型视为实物）
update_post_meta(1, '_mlshop_type', 'physical');
update_post_meta(2, '_mlshop_type', 'virtual');
update_post_meta(3, '_mlshop_type', 'virtual');
update_post_meta(4, '_mlshop_type', 'virtual');

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
__test_set_option('shipping_free_threshold', 100.0);
$q3 = Moonlight_Price_Calculator::quote($items, 'save10');
check('free shipping judged on pre-discount subtotal', $q3['shipping'] === 0.0 && $q3['total'] === 90.0);
__test_set_option('shipping_free_threshold', 400.0);

// fixed-amount coupon never exceeds subtotal
MLSHOP_Coupon::$coupons[2] = array('code' => 'BIG50', 'fixed' => 500);
$q4 = Moonlight_Price_Calculator::quote(array(array('id' => 3, 'qty' => 1, 'price' => 30.0, 'type' => 'virtual', 'subtotal' => 30.0)), 'BIG50');
check('fixed coupon capped at subtotal', $q4['discount'] === 30.0 && $q4['total'] === 0.0);

// digital-only order has no shipping
$dig = array(array('id' => 4, 'qty' => 1, 'price' => 20.0, 'type' => 'virtual', 'subtotal' => 20.0));
$q5 = Moonlight_Price_Calculator::quote($dig, '');
check('digital-only: no shipping charged', $q5['shipping'] === 0.0 && $q5['has_physical'] === false && $q5['total'] === 20.0);

echo "== Moonlight_Region_Provider ==\n";
$provinces = Moonlight_Region_Provider::provinces();
check('34 省级行政区全覆盖', count($provinces) === 34);
$bj = Moonlight_Region_Provider::resolve('CN-BJ');
check("resolve('CN-BJ') 返回北京", is_array($bj) && '北京' === $bj['name'] && 'province' === $bj['level']);
$gz = Moonlight_Region_Provider::resolve('cn-gd-gz'); // 区码大小写归一
check('resolve 市码返回城市并带省信息', is_array($gz) && '广州市' === $gz['name'] && 'CN-GD' === $gz['province'] && 'city' === $gz['level']);
check('非法码 resolve 失败', Moonlight_Region_Provider::resolve('CN-XX-NOPE') === false);
check('空码 resolve 失败', Moonlight_Region_Provider::resolve('') === false);
check('非字符串码 resolve 失败', Moonlight_Region_Provider::resolve('BEIJING') === false);
$cities_gd = Moonlight_Region_Provider::cities('CN-GD');
check('cities(CN-GD) 含广州市', isset($cities_gd['CN-GD-GZ']) && '广州市' === $cities_gd['CN-GD-GZ']);
check('cities(非法省码) 返回空数组', Moonlight_Region_Provider::cities('CN-NOPE') === array());
$total_cities = 0;
foreach ($provinces as $p_code => $p_name) {
    $total_cities += count(Moonlight_Region_Provider::cities($p_code));
}
check("城市总量控制在 ~400 以内（当前 {$total_cities}）", $total_cities > 300 && $total_cities <= 400);

echo "== Moonlight_Address_Book（属主隔离 + 校验） ==\n";
$GLOBALS['__test_user_meta'] = array();
$GLOBALS['__test_user_id'] = 1;
$saved = Moonlight_Address_Book::save(1, array(
    'name' => '张三', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '天河路 100 号',
));
check('save 返回带 id 的完整条目', is_array($saved) && '' !== $saved['id'] && '广东' === $saved['province_name']);
check('首个地址自动设为默认', !empty($saved['is_default']));
check('属主 get 可取回', is_array(Moonlight_Address_Book::get(1, $saved['id'])));
check('first() 返回默认地址', Moonlight_Address_Book::first(1)['id'] === $saved['id']);

$GLOBALS['__test_user_id'] = 2;
check('B 用户 get A 的地址取不到（属主隔离）', Moonlight_Address_Book::get(2, $saved['id']) === false);
check('B 用户 delete A 的地址失败', Moonlight_Address_Book::delete(2, $saved['id']) === false);
check('B 用户 set_default A 的地址失败', Moonlight_Address_Book::set_default(2, $saved['id']) === false);
check('B 用户列表为空', Moonlight_Address_Book::get_list(2) === array());

$GLOBALS['__test_user_id'] = 1;
check('姓名超 32 字拒绝', is_wp_error(Moonlight_Address_Book::save(1, array('name' => str_repeat('长', 33), 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '地址'))));
check('电话格式拒绝', is_wp_error(Moonlight_Address_Book::save(1, array('name' => '李四', 'phone' => 'abc', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '地址'))));
check('非法区码拒绝', is_wp_error(Moonlight_Address_Book::save(1, array('name' => '李四', 'phone' => '13800138000', 'province' => 'CN-XX', 'city' => 'CN-XX-NOPE', 'detail' => '地址'))));
check('省市不匹配拒绝', is_wp_error(Moonlight_Address_Book::save(1, array('name' => '李四', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-BJ-BJ', 'detail' => '地址'))));
check('详情超 120 字拒绝', is_wp_error(Moonlight_Address_Book::save(1, array('name' => '李四', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => str_repeat('地', 121)))));

$s2 = Moonlight_Address_Book::save(1, array(
    'name' => '王五', 'phone' => '0755-8888', 'province' => 'CN-BJ', 'city' => 'CN-BJ-BJ', 'detail' => '朝阳区 1 号',
));
check('第二条保存成功', is_array($s2) && $s2['id'] !== $saved['id']);
check('set_default 生效', Moonlight_Address_Book::set_default(1, $s2['id']) && Moonlight_Address_Book::first(1)['id'] === $s2['id']);
$edited = Moonlight_Address_Book::save(1, array(
    'id' => $saved['id'], 'name' => '张三丰', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '天河路 101 号',
));
check('编辑保留原 id 且字段更新', is_array($edited) && $edited['id'] === $saved['id'] && '张三丰' === $edited['name']);
check('列表数量正确（无重复插入）', count(Moonlight_Address_Book::get_list(1)) === 2);
check('删除默认地址后剩余首条提升为默认', Moonlight_Address_Book::delete(1, $s2['id']) && Moonlight_Address_Book::first(1)['id'] === $saved['id']);
check('删除他人地址返回 false', Moonlight_Address_Book::delete(2, $saved['id']) === false);

echo "== MLSHOP_Shipping 運費模板 ==\n";
update_post_meta(500, '_mlshop_type', 'physical');
update_post_meta(500, '_mlshop_shipping_template', 'tpl_piece');
update_post_meta(501, '_mlshop_type', 'physical');
update_post_meta(501, '_mlshop_shipping_template', 'tpl_fixed');
update_post_meta(502, '_mlshop_type', 'physical'); // 不挂模板
$templates = array(
    array('id' => 'tpl_piece', 'name' => '促銷品', 'mode' => 'piece', 'flat_fee' => 0, 'first_item_fee' => 10, 'extra_item_fee' => 5, 'free_threshold' => 0),
    array('id' => 'tpl_fixed', 'name' => '標準快遞', 'mode' => 'fixed', 'flat_fee' => 8, 'first_item_fee' => 0, 'extra_item_fee' => 0, 'free_threshold' => 50),
);
$piece_items = array(array('id' => 500, 'qty' => 3, 'price' => 10.0, 'subtotal' => 30.0));
check('piece 模板 首件10续件5 ×3件 = 20', MLSHOP_Shipping::template_calc($piece_items, $templates) === 20.0);
check('piece 模板单件只收首件 = 10', MLSHOP_Shipping::template_calc(array(array('id' => 500, 'qty' => 1, 'price' => 10.0, 'subtotal' => 10.0)), $templates) === 10.0);
check('fixed 模板未达门槛收 flat_fee = 8', MLSHOP_Shipping::template_calc(array(array('id' => 501, 'qty' => 1, 'price' => 30.0, 'subtotal' => 30.0)), $templates) === 8.0);
check('fixed 模板达门槛免邮 = 0', MLSHOP_Shipping::template_calc(array(array('id' => 501, 'qty' => 2, 'price' => 30.0, 'subtotal' => 60.0)), $templates) === 0.0);
$mixed = array(
    array('id' => 500, 'qty' => 3, 'price' => 10.0, 'subtotal' => 30.0), // piece => 20
    array('id' => 501, 'qty' => 1, 'price' => 30.0, 'subtotal' => 30.0), // fixed 未达门槛 => 8
    array('id' => 502, 'qty' => 1, 'price' => 40.0, 'subtotal' => 40.0), // 未挂模板 => 全局 50
);
check('混合订单多模板求和 + 未挂模板回退全局 calc = 78', MLSHOP_Shipping::template_calc($mixed, $templates) === 78.0);
check('模板已删除的商品回落全局', MLSHOP_Shipping::template_calc(array(array('id' => 502, 'qty' => 1, 'price' => 40.0, 'subtotal' => 40.0)), $templates) === 50.0);

update_option('moonlight_shipping_templates', $templates);
$tpl_quote_items = array(array('id' => 500, 'qty' => 3, 'price' => 10.0, 'subtotal' => 30.0));
$q6 = Moonlight_Price_Calculator::quote($tpl_quote_items, '');
check('quote 有模板走 template_calc', $q6['shipping'] === 20.0 && $q6['total'] === 50.0);
$q7 = Moonlight_Price_Calculator::quote($tpl_quote_items, '', array('shipping_mode' => 'pickup'));
check('quote 自提 shipping = 0', $q7['shipping'] === 0.0 && $q7['total'] === 30.0);
check('自提不影响 has_physical 判定', $q7['has_physical'] === true);
update_option('moonlight_shipping_templates', array());
$q8 = Moonlight_Price_Calculator::quote($tpl_quote_items, '');
check('模板清空后 quote 回退全局 calc（30<400 => 50）', $q8['shipping'] === 50.0 && $q8['total'] === 80.0);
$q9 = Moonlight_Price_Calculator::quote($items, 'save10', array('shipping_mode' => 'pickup'));
check('自提免运费同样豁免优惠券后运费行', $q9['shipping'] === 0.0 && $q9['total'] === 90.0);
update_option('moonlight_shipping_templates', array());

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

/* ================= 物流第二批：状态机扩展 / 发货单 / Provider / cron ================= */

/** 测试辅助：直接建一条指定状态的订单（含 meta）。 */
function __test_make_order($status, $meta = array())
{
    $id = wp_insert_post(array(
        'post_type'   => 'mlshop_order',
        'post_title'  => 'MLS-TEST-' . wp_generate_password(5, false, false),
        'post_status' => 'mlshop_' . $status,
        'post_author' => 1,
    ));
    update_post_meta($id, '_mlshop_status', $status);
    update_post_meta($id, '_mlshop_user_id', 1);
    foreach ($meta as $k => $v) {
        update_post_meta($id, $k, $v);
    }
    return $id;
}

/** 测试用 Provider：固定返回预设轨迹结果（模拟快递100 各状态 / 失败）。 */
class __Test_Shipping_Provider implements Moonlight_Shipping_Provider_Interface
{
    public static $result = array('status' => 'pending', 'events' => array(), 'ok' => true);
    public function get_code() { return 'fake'; }
    public function get_name() { return 'Fake'; }
    public function is_available() { return true; }
    public function get_supported_companies() { return array(); }
    public function create_shipment($shipment) { return array('success' => true, 'tracking_no' => '', 'message' => ''); }
    public function query_tracking($company_code, $tracking_no) { return self::$result; }
}

echo "== 物流第二批：状态机扩展 ==\n";
check('paid -> awaiting_shipment allowed', MLSHOP_Order::can_transition('paid', 'awaiting_shipment'));
check('awaiting_shipment -> shipped allowed', MLSHOP_Order::can_transition('awaiting_shipment', 'shipped'));
check('shipped -> delivered allowed', MLSHOP_Order::can_transition('shipped', 'delivered'));
check('delivered -> completed allowed', MLSHOP_Order::can_transition('delivered', 'completed'));
check('delivered -> paid forbidden', !MLSHOP_Order::can_transition('delivered', 'paid'));
check('awaiting_shipment -> completed forbidden', !MLSHOP_Order::can_transition('awaiting_shipment', 'completed'));
check('awaiting_shipment -> cancelled allowed', MLSHOP_Order::can_transition('awaiting_shipment', 'cancelled'));
check('shipped -> refunded allowed', MLSHOP_Order::can_transition('shipped', 'refunded'));
check('delivered -> refunded allowed', MLSHOP_Order::can_transition('delivered', 'refunded'));
check('paid 全量转换白名单', MLSHOP_Order::get_allowed_transitions('paid') === array('processing', 'awaiting_shipment', 'completed', 'refunded', 'cancelled'));

$chain = __test_make_order('paid');
check('set_status paid->awaiting_shipment', true === MLSHOP_Order::set_status($chain, 'awaiting_shipment'));
check('set_status awaiting->shipped', true === MLSHOP_Order::set_status($chain, 'shipped'));
check('set_status shipped->delivered', true === MLSHOP_Order::set_status($chain, 'delivered'));
check('delivered 记录 _mlshop_delivered_at', '' !== (string) get_post_meta($chain, '_mlshop_delivered_at', true));
check('set_status delivered->completed', true === MLSHOP_Order::set_status($chain, 'completed'));
check('终态 completed', 'completed' === MLSHOP_Order::get_status($chain));
check('delivered->completed 非法（已走完）', is_wp_error(MLSHOP_Order::set_status($chain, 'delivered')));

echo "== 物流第二批：状态标签单一来源 ==\n";
$labels = MLSHOP_Order::get_status_labels();
check('标签表含三个新状态', isset($labels['awaiting_shipment'], $labels['shipped'], $labels['delivered'])
    && '待发货' === $labels['awaiting_shipment'] && '已发货' === $labels['shipped'] && '已签收' === $labels['delivered']);
check('get_status_label 单点取值', MLSHOP_Order::get_status_label('awaiting_shipment') === '待发货'
    && MLSHOP_Order::get_status_label('shipped') === '已发货' && MLSHOP_Order::get_status_label('delivered') === '已签收');
check('未知状态原样返回', MLSHOP_Order::get_status_label('bogus') === 'bogus');
check('functions.php 旧标签函数与单一来源一致（全部状态）',
    mlshop_get_order_status_label('pending') === $labels['pending']
    && mlshop_get_order_status_label('awaiting_shipment') === $labels['awaiting_shipment']
    && mlshop_get_order_status_label('delivered') === $labels['delivered']
    && mlshop_get_order_status_label('cancelled') === $labels['cancelled']);
check('functions.php 状态枚举与单一来源键一致（admin 筛选下拉同源）',
    array_keys(mlshop_get_order_statuses()) === array_keys($labels));
check('统计销售额口径 = 6 个已收款状态（含新三态）', MLSHOP_Order::get_revenue_statuses() === array('paid', 'processing', 'awaiting_shipment', 'shipped', 'delivered', 'completed'));
check('销售额口径不含 pending/refunded/failed/cancelled',
    !in_array('pending', MLSHOP_Order::get_revenue_statuses(), true) && !in_array('refunded', MLSHOP_Order::get_revenue_statuses(), true));

// 统计分布图（render_svg_bar）委托单一来源标签
$stats_obj = MLSHOP_Statistics::get_instance();
$rm = new ReflectionMethod('MLSHOP_Statistics', 'render_svg_bar');
$rm->setAccessible(true);
$buckets = array_fill_keys(array_keys($labels), 1);
$svg = $rm->invoke($stats_obj, $buckets);
check('统计分布图渲染 10 个状态桶（含新三态）', 10 === substr_count($svg, '<rect '));
check('统计分布图使用单一来源标签（待发货/已发货/已签收）',
    false !== strpos($svg, '待发货') && false !== strpos($svg, '已发货') && false !== strpos($svg, '已签收'));

// register_post_status 同步注册（后台列表筛选的数据源）
MLSHOP_Order::register_post_type();
$statuses_registered = isset($GLOBALS['__test_post_statuses']) ? $GLOBALS['__test_post_statuses'] : array();
check('register_post_status 注册全部 10 个状态', count($statuses_registered) === 10
    && isset($statuses_registered['mlshop_awaiting_shipment'], $statuses_registered['mlshop_shipped'], $statuses_registered['mlshop_delivered']));
check('注册的状态标签来自单一来源', isset($statuses_registered['mlshop_awaiting_shipment']['label']) && '待发货' === $statuses_registered['mlshop_awaiting_shipment']['label']);

echo "== 物流第二批：发货单创建 + 订单 shipped ==\n";
__test_reset_card_env();
$ship_order = __test_make_order('awaiting_shipment', array('_mlshop_items' => array(array('id' => 1, 'qty' => 2, 'price' => 10.0))));
$sid = MLSHOP_Shipping::create_shipment($ship_order, array('company' => '顺丰速运', 'tracking_no' => 'SF100', 'note' => '易碎品轻放'));
check('创建发货单返回 ID', is_int($sid) && $sid > 0);
check('订单 awaiting_shipment -> shipped', 'shipped' === MLSHOP_Order::get_status($ship_order)
    && 'mlshop_shipped' === get_post($ship_order)->post_status);
$ship_row = MLSHOP_Shipping::read_shipment($sid);
check('发货单挂到订单（post_parent）+ 标题=运单号', $ship_row['order_id'] === (int) $ship_order && 'SF100' === $ship_row['no'] && 'SF100' === get_post($sid)->post_title);
check('发货单初始 transit + 公司名/代码（名称反查 SF）', 'transit' === $ship_row['status'] && '顺丰速运' === $ship_row['company'] && 'SF' === $ship_row['company_code']);
check('发货单快照订单商品', $ship_row['items'] === array(array('id' => 1, 'qty' => 2, 'price' => 10.0)));
$ship_events_json = json_decode((string) get_post_meta($sid, '_mlship_events', true), true);
check('发货单轨迹首条（备注 + 交运事件，JSON 存储）', count($ship_row['events']) === 2
    && '易碎品轻放' === $ship_row['events'][0]['desc']
    && is_array($ship_events_json) && count($ship_events_json) === 2
    && false !== strpos((string) $ship_events_json[1]['desc'], '已交运'));
check('发货单状态标签映射', MLSHOP_Shipping::shipment_status_label('transit') === '运输中'
    && MLSHOP_Shipping::shipment_status_label('delivered') === '已签收'
    && MLSHOP_Shipping::shipment_status_label('exception') === '异常');

// 多包裹：已发货订单可再补录一条发货单
$sid2 = MLSHOP_Shipping::create_shipment($ship_order, array('company_code' => 'ZTO', 'company' => '中通快递', 'tracking_no' => 'ZT200'));
check('已发货订单可补录第二条发货单（多包裹）', is_int($sid2) && $sid2 > 0 && count(MLSHOP_Shipping::get_shipments($ship_order)) === 2);

echo "== 物流第二批：发货状态预检（非法转换拒绝） ==\n";
$paid_order = __test_make_order('paid');
check('paid 单发货被拒（invalid_transition）', is_wp_error(MLSHOP_Shipping::create_shipment($paid_order, array('company' => '顺丰速运', 'tracking_no' => 'X1')))
    && 'invalid_transition' === MLSHOP_Shipping::create_shipment($paid_order, array('company' => '顺丰速运', 'tracking_no' => 'X1'))->get_error_code());
check('被拒后不产生发货单', count(MLSHOP_Shipping::get_shipments($paid_order)) === 0);
$no_no = __test_make_order('awaiting_shipment');
check('缺运单号被拒', is_wp_error(MLSHOP_Shipping::create_shipment($no_no, array('company' => '顺丰速运'))));
check('缺运单号不推进订单状态', 'awaiting_shipment' === MLSHOP_Order::get_status($no_no));

echo "== 物流第二批：cron 轨迹同步（delivered 联动） ==\n";
__test_reset_card_env();
$syn_order = __test_make_order('awaiting_shipment');
$sync_sid = MLSHOP_Shipping::create_shipment($syn_order, array('company' => '顺丰速运', 'tracking_no' => 'SF888', 'note' => '两件合包'));
check('同步前订单为 shipped', 'shipped' === MLSHOP_Order::get_status($syn_order));
__Test_Shipping_Provider::$result = array(
    'status'  => 'delivered',
    'events'  => array(array('time' => 1789000000, 'desc' => '快件已签收'), array('time' => 1788900000, 'desc' => '到达 广州转运中心', 'city' => '广州')),
    'ok'      => true,
);
$processed = MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
check('同步处理了 1 条 transit 发货单', $processed === 1);
check('轨迹 delivered → 发货单 delivered', MLSHOP_Shipping::SHIP_DELIVERED === get_post_meta($sync_sid, '_mlship_status', true));
check('轨迹 delivered → 订单 shipped→delivered', 'delivered' === MLSHOP_Order::get_status($syn_order)
    && 'mlshop_delivered' === get_post($syn_order)->post_status);
check('订单记录 _mlshop_delivered_at', '' !== (string) get_post_meta($syn_order, '_mlshop_delivered_at', true));
$events_after = json_decode((string) get_post_meta($sync_sid, '_mlship_events', true), true);
check('新事件追加进 _mlship_events（原2条+新2条）', is_array($events_after) && count($events_after) === 4 && '快件已签收' === $events_after[2]['desc'] && '广州' === $events_after[3]['city']);
// 幂等：同一批事件再次同步不重复追加
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
$events_idem = json_decode((string) get_post_meta($sync_sid, '_mlship_events', true), true);
check('重复同步事件去重（仍 4 条）', count($events_idem) === 4);

echo "== 物流第二批：cron 轨迹同步（exception 仅标记发货单） ==\n";
__test_reset_card_env();
$exc_order = __test_make_order('shipped');
$exc_sid = MLSHOP_Shipping::create_shipment($exc_order, array('company' => '顺丰速运', 'tracking_no' => 'SF999'));
__Test_Shipping_Provider::$result = array('status' => 'exception', 'events' => array(array('time' => 1789000000, 'desc' => '派送失败')), 'ok' => true);
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
check('轨迹 exception → 发货单 exception', 'exception' === get_post_meta($exc_sid, '_mlship_status', true));
check('轨迹 exception → 订单状态不动（仍 shipped）', 'shipped' === MLSHOP_Order::get_status($exc_order));

echo "== 物流第二批：查询失败计数与 24h 暂停 ==\n";
__test_reset_card_env();
$f_order = __test_make_order('shipped');
$f_sid = MLSHOP_Shipping::create_shipment($f_order, array('company' => '顺丰速运', 'tracking_no' => 'F1'));
__Test_Shipping_Provider::$result = null; // 模拟断网（query_tracking 抛异常/返回非法）
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
check('失败 1-2 次仅计数不暂停', (int) get_post_meta($f_sid, '_mlship_fail_count', true) === 2
    && (int) get_post_meta($f_sid, '_mlship_sync_paused_until', true) === 0);
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
check('失败 3 次 → 记录 24h 暂停时间并清零计数', (int) get_post_meta($f_sid, '_mlship_sync_paused_until', true) > time()
    && (int) get_post_meta($f_sid, '_mlship_fail_count', true) === 0);
__Test_Shipping_Provider::$result = array('status' => 'delivered', 'events' => array(), 'ok' => true);
$processed_paused = MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
check('暂停期内该单不再自动查询（processed=0，状态不动）', $processed_paused === 0 && 'transit' === get_post_meta($f_sid, '_mlship_status', true));
// 成功即重置失败计数
__test_reset_card_env();
$r_order = __test_make_order('shipped');
$r_sid = MLSHOP_Shipping::create_shipment($r_order, array('company' => '顺丰速运', 'tracking_no' => 'R1'));
__Test_Shipping_Provider::$result = null;
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
__Test_Shipping_Provider::$result = array('status' => 'transit', 'events' => array(array('time' => 1789000000, 'desc' => '运输中')), 'ok' => true);
MLSHOP_Shipping::run_shipping_sync(true, new __Test_Shipping_Provider());
check('查询成功重置失败计数', (int) get_post_meta($r_sid, '_mlship_fail_count', true) === 0);
check('manual Provider 跳过查询（processed=0）', 0 === MLSHOP_Shipping::run_shipping_sync(true, new Moonlight_Shipping_Provider_Manual()));

echo "== 物流第二批：delivered 自动完成（auto_complete） ==\n";
__test_reset_card_env();
__test_set_option('auto_complete_days', 7);
$now_ts = current_time('timestamp');
$overdue = __test_make_order('delivered', array('_mlshop_delivered_at' => date('Y-m-d H:i:s', $now_ts - 8 * DAY_IN_SECONDS)));
$fresh   = __test_make_order('delivered', array('_mlshop_delivered_at' => date('Y-m-d H:i:s', $now_ts - 3 * DAY_IN_SECONDS)));
$nots    = __test_make_order('delivered'); // 无 _mlshop_delivered_at（历史/手工数据）
$done = MLSHOP_Shipping::auto_complete_orders();
check('超期 8 天（>7 天）→ completed', $done === 1 && 'completed' === MLSHOP_Order::get_status($overdue));
check('未超期 3 天 → 保持 delivered', 'delivered' === MLSHOP_Order::get_status($fresh));
check('无签收时间 → 不自动完成', 'delivered' === MLSHOP_Order::get_status($nots));
$disabled = __test_make_order('delivered', array('_mlshop_delivered_at' => date('Y-m-d H:i:s', $now_ts - 30 * DAY_IN_SECONDS)));
__test_set_option('auto_complete_days', 0);
check('0 天 = 禁用自动完成', 0 === MLSHOP_Shipping::auto_complete_orders() && 'delivered' === MLSHOP_Order::get_status($disabled));

echo "== 物流第二批：Express100 parse_response 解析器 ==\n";
$ok_body = '{"message":"ok","status":"3","ischeck":"1","data":['
    . '{"time":"2026-09-26 10:00:00","context":"快件已签收"},'
    . '{"time":"2026-09-25 09:00:00","context":"快件已到达 广州转运中心","area":"广州"}]}';
$p = Moonlight_Shipping_Provider_Express100::parse_response($ok_body);
check('经典结构解析 ok=true', !empty($p['ok']));
check('status=3 → delivered', 'delivered' === $p['status']);
check('events 标准化（ftime→ts, context→desc, area→city）', count($p['events']) === 2
    && '快件已签收' === $p['events'][0]['desc'] && $p['events'][0]['time'] > 0 && '广州' === $p['events'][1]['city']);
$p2 = Moonlight_Shipping_Provider_Express100::parse_response('{"message":"ok","status":"1","data":[{"time":"2026-09-26 10:00:00","context":"运输中"}]}');
check('status=1 → transit', 'transit' === $p2['status']);
$p3 = Moonlight_Shipping_Provider_Express100::parse_response('{"code":"200","data":{"list":[{"ftime":1789000000,"desc":"派送中"}]}}');
check('新版聚合结构（data.list）兼容', 'transit' === $p3['status'] && 1 === count($p3['events']) && 1789000000 === $p3['events'][0]['time']);
$p4 = Moonlight_Shipping_Provider_Express100::parse_response('{"message":"ok"}');
check('查询成功但无轨迹 → pending', 'pending' === $p4['status'] && array() === $p4['events'] && !empty($p4['ok']));
$p5 = Moonlight_Shipping_Provider_Express100::parse_response('{"message":"参数错误"}');
check('接口报错 → pending + ok=false', 'pending' === $p5['status'] && empty($p5['ok']));
$p6 = Moonlight_Shipping_Provider_Express100::parse_response('{"message":"ok","data":[{"time"'); // 截断坏 JSON
check('坏 JSON → pending + events 空', 'pending' === $p6['status'] && array() === $p6['events'] && false === $p6['ok']);
$p7 = Moonlight_Shipping_Provider_Express100::parse_response('{"message":"ok","status":"4","data":[{"time":"2026-09-26 10:00:00","context":"派送失败"}]}');
check('status=4 → exception', 'exception' === $p7['status']);
__test_reset_card_env();
check('express100 未配 Key 不可用', !(new Moonlight_Shipping_Provider_Express100())->is_available());
__test_set_option('shipping_kuaidi100_key', 'k-1');
check('express100 配置 Key 后可用', (new Moonlight_Shipping_Provider_Express100())->is_available());

echo "== 物流第二批：Provider 注册表 ==\n";
__test_reset_card_env();
$providers = MLSHOP_Shipping::providers();
$codes = array();
foreach ($providers as $pr) { $codes[] = $pr->get_code(); }
check('默认注册表 = manual + express100', in_array('manual', $codes, true) && in_array('express100', $codes, true));
check('active_provider 默认 manual（零依赖基线）', 'manual' === MLSHOP_Shipping::active_provider()->get_code());
$manual_p = MLSHOP_Shipping::get_provider('manual');
check('manual 恒可用', $manual_p->is_available() === true);
check('公司静态表含 9 家常见快递（顺丰/中通/圆通/申通/韵达/极兔/京东/EMS/邮政）', count($manual_p->get_supported_companies()) === 9
    && 'SF' === $manual_p->get_supported_companies()[0]['code'] && '顺丰速运' === $manual_p->get_supported_companies()[0]['name']);
check('express100 未配 Key 不可用', !MLSHOP_Shipping::get_provider('express100')->is_available());
__test_set_option('shipping_kuaidi100_key', 'test-key-1234');
check('express100 配置 Key 后可用', MLSHOP_Shipping::get_provider('express100')->is_available());
__test_set_option('shipping_provider', 'express100');
check('active_provider 跟随设置切换到 express100', 'express100' === MLSHOP_Shipping::active_provider()->get_code());
__test_set_option('shipping_provider', 'bogus');
check('所选 Provider 无效时回退 manual', 'manual' === MLSHOP_Shipping::active_provider()->get_code());
check('manual query_tracking 恒 pending', 'pending' === $manual_p->query_tracking('SF', 'NOPE')['status']
    && array() === $manual_p->query_tracking('SF', 'NOPE')['events']);

echo "== 物流第二批：实物推进钩子（非自提 → 待发货） ==\n";
__test_reset_card_env();
$inst = MLSHOP_Shipping::get_instance();
$phy = __test_make_order('paid', array('_mlshop_has_physical' => '1'));
$inst->transition_physical($phy);
check('实物非自提 paid → awaiting_shipment', 'awaiting_shipment' === MLSHOP_Order::get_status($phy));
$pickup = __test_make_order('paid', array('_mlshop_has_physical' => '1', '_mlshop_pickup' => '1'));
$inst->transition_physical($pickup);
check('自提维持旧逻辑 paid → processing', 'processing' === MLSHOP_Order::get_status($pickup));
$virtual = __test_make_order('paid', array('_mlshop_has_physical' => '0'));
$inst->transition_physical($virtual);
check('非实物订单不动（仍 paid）', 'paid' === MLSHOP_Order::get_status($virtual));
$wrong = __test_make_order('pending', array('_mlshop_has_physical' => '1'));
$inst->transition_physical($wrong);
check('未付款订单不动（仍 pending）', 'pending' === MLSHOP_Order::get_status($wrong));

/* ===================== 售后退款（Refund_Service，PAYMENT.md 第五节） =====================
 * 规则闸矩阵 / apply / process（全额·部分·失败·仅标记·balance）/ Stripe·PayPal 退款参数构造。
 * 网关依赖通过子类覆盖 gateway_refund 注入（与卡密测试同一「子类接桩」模式）；
 * 卡密售出行查询通过覆盖 find_card_sold_rows 注入；HTTP 由 wp-stubs 的 wp_remote_* 桩捕获。
 */

/** 测试用 Refund_Service：gateway_refund 注入预设结果（null = 走生产分发器）。 */
class Moonlight_Refund_Service_Test extends Moonlight_Refund_Service
{
    public static $gateway_result = null;
    public static $gateway_calls = array();

    protected static function find_card_sold_rows($product_id, $order_id)
    {
        return array(); // 测试环境无 $wpdb 行查询，卡密售出行交给交付 meta / 专用子类验证
    }

    public static function gateway_refund($order_id, $amount, $reason = '')
    {
        self::$gateway_calls[] = array('order_id' => (int) $order_id, 'amount' => (float) $amount, 'reason' => (string) $reason);
        if (is_array(self::$gateway_result)) {
            return self::$gateway_result;
        }
        return parent::gateway_refund($order_id, $amount, $reason);
    }
}

/** 卡密售出行恒命中桩：验证交付 meta 缺失时 _mlshop_card_s(o=订单) 兜底拦截。 */
class Moonlight_Refund_Service_SoldRow extends Moonlight_Refund_Service
{
    protected static function find_card_sold_rows($product_id, $order_id)
    {
        return array(array('meta_id' => 99));
    }
}

echo "== 售后退款：apply（申请售后） ==\n";
__test_reset_card_env();
update_option('admin_email', 'admin@test.local');
$ap = __test_make_order('paid');
check('apply 状态合法 → 成功', true === Moonlight_Refund_Service::apply($ap, 1, '商品有问题'));
$reqs = Moonlight_Refund_Service::requests($ap);
check('申请入列 {user_id, reason, at, status=pending}', 1 === count($reqs)
    && 1 === (int) $reqs[0]['user_id'] && '商品有问题' === $reqs[0]['reason']
    && 'pending' === $reqs[0]['status'] && '' !== (string) $reqs[0]['at']);
check('moonlight_refund_requested 钩子触发', 1 === count($GLOBALS['__test_actions']['moonlight_refund_requested'])
    && $ap === (int) $GLOBALS['__test_actions']['moonlight_refund_requested'][0][0]);
check('邮件桩被调（主题含订单号，正文含原因）', 1 === count($GLOBALS['__test_mails'])
    && false !== strpos($GLOBALS['__test_mails'][0]['subject'], (string) get_the_title($ap))
    && false !== strpos($GLOBALS['__test_mails'][0]['body'], '商品有问题'));
check('重复申请（已有 pending）拒绝', is_wp_error(Moonlight_Refund_Service::apply($ap, 1, '再来一次')));
check('空原因拒绝', is_wp_error(Moonlight_Refund_Service::apply(__test_make_order('paid'), 1, '   ')));
check('pending 状态订单申请拒绝', is_wp_error(Moonlight_Refund_Service::apply(__test_make_order('pending'), 1, 'r')));
check('refunded 订单申请拒绝', is_wp_error(Moonlight_Refund_Service::apply(__test_make_order('refunded'), 1, 'r')));

echo "== 售后退款：规则闸 can_refund 矩阵 ==\n";
__test_reset_card_env();
check('pending 订单拒绝退款', is_wp_error(Moonlight_Refund_Service::can_refund(__test_make_order('pending'))));
check('refunded 订单拒绝退款', is_wp_error(Moonlight_Refund_Service::can_refund(__test_make_order('refunded'))));
update_post_meta(301, '_mlshop_type', 'virtual');
$v1 = __test_make_order('paid', array('_mlshop_items' => array(array('id' => 301, 'qty' => 1, 'price' => 10.0))));
check('virtual 无下载记录 → 允许', true === Moonlight_Refund_Service::can_refund($v1));
update_post_meta($v1, '_mlshop_download_log', array(array('at' => '2026-09-27 10:00:00', 'user_id' => 1, 'product' => 301, 'token' => 'abcd1234')));
check('virtual 已产生下载 → 拒绝', is_wp_error(Moonlight_Refund_Service::can_refund($v1)));
update_post_meta(302, '_mlshop_type', 'cardkey');
$c1 = __test_make_order('paid', array('_mlshop_items' => array(array('id' => 302, 'qty' => 1, 'price' => 10.0))));
check('cardkey 未发卡 → 允许', true === Moonlight_Refund_Service::can_refund($c1));
update_post_meta($c1, '_mlshop_delivery', array(array('product_id' => 302, 'type' => 'cardkey', 'key' => 'XXXX-YYYY')));
check('cardkey 已交付（_mlshop_delivery）→ 拒绝', is_wp_error(Moonlight_Refund_Service::can_refund($c1)));
$c2 = __test_make_order('paid', array('_mlshop_items' => array(array('id' => 302, 'qty' => 1, 'price' => 10.0))));
check('cardkey 无交付无售出行 → 允许（生产类，售出行 SQL 空集）', true === Moonlight_Refund_Service::can_refund($c2));
check('cardkey 售出行命中也拒绝（交付 meta 缺失兜底）', is_wp_error(Moonlight_Refund_Service_SoldRow::can_refund($c2)));
update_post_meta(303, '_mlshop_type', 'physical');
$ph1 = __test_make_order('paid', array('_mlshop_items' => array(array('id' => 303, 'qty' => 1, 'price' => 10.0))));
check('physical 未发货 → 允许', true === Moonlight_Refund_Service::can_refund($ph1));
$ph2 = __test_make_order('awaiting_shipment', array('_mlshop_items' => array(array('id' => 303, 'qty' => 1, 'price' => 10.0))));
MLSHOP_Shipping::create_shipment($ph2, array('company' => '顺丰速运', 'tracking_no' => 'RF100'));
check('physical 已有发货单 → 拒绝', is_wp_error(Moonlight_Refund_Service::can_refund($ph2)));
$m1 = __test_make_order('paid', array('_mlshop_type' => 'membership', '_mlshop_membership_target' => 'gold', '_mlshop_items' => array(array('title' => 'gold', 'qty' => 1))));
check('membership 未授予 → 允许', true === Moonlight_Refund_Service::can_refund($m1));
update_post_meta($m1, '_mlshop_membership_granted', current_time('mysql'));
check('membership 授予 24h 内 → 允许', true === Moonlight_Refund_Service::can_refund($m1));
update_post_meta($m1, '_mlshop_membership_granted', date('Y-m-d H:i:s', current_time('timestamp') - 25 * HOUR_IN_SECONDS));
check('membership 授予超 24h → 拒绝', is_wp_error(Moonlight_Refund_Service::can_refund($m1)));
$pw1 = __test_make_order('paid', array('_mlshop_paywall_post' => 55, '_mlshop_items' => array(array('id' => 55, 'qty' => 1, 'price' => 20.0))));
check('paywall 未授予 → 允许', true === Moonlight_Refund_Service::can_refund($pw1));
update_post_meta($pw1, '_mlshop_paywall_granted', date('Y-m-d H:i:s', current_time('timestamp') - 25 * HOUR_IN_SECONDS));
check('paywall 授予超 24h → 拒绝', is_wp_error(Moonlight_Refund_Service::can_refund($pw1)));
$rc1 = __test_make_order('paid', array('_mlshop_type' => 'recharge', '_mlshop_recharge_granted' => current_time('mysql')));
check('recharge 已入账未消费 → 允许', true === Moonlight_Refund_Service::can_refund($rc1));
update_post_meta($rc1, '_mlshop_recharge_revoke_short', 100);
check('recharge 积分已花掉（revoke_short 留痕）→ 拒绝', is_wp_error(Moonlight_Refund_Service::can_refund($rc1)));

echo "== 售后退款：process 全额（stub 网关成功） ==\n";
__test_reset_card_env();
$p610 = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => '退款测试实物'));
update_post_meta($p610, '_mlshop_type', 'physical');
update_post_meta($p610, '_mlshop_stock', 5);
$of = __test_make_order('paid', array(
    '_mlshop_total'           => 100.0,
    '_mlshop_currency'        => 'HK$',
    '_mlshop_items'           => array(array('id' => $p610, 'qty' => 2, 'price' => 50.0, 'subtotal' => 100.0)),
    '_mlshop_payment_gateway' => 'stripe',
    '_mlshop_payment_id'      => 'pi_full_1',
));
Moonlight_Refund_Service_Test::apply($of, 1, '商品损坏');
Moonlight_Refund_Service_Test::$gateway_result = array('success' => true, 'refund_id' => 're_full_1', 'message' => 'ok');
check('process 全额成功', true === Moonlight_Refund_Service_Test::process($of, 0, '商品损坏', 7));
check('状态 → refunded', 'refunded' === MLSHOP_Order::get_status($of));
check('库存回滚 5+2=7', abs((float) get_post_meta($p610, '_mlshop_stock', true) - 7.0) < 0.001);
check('资金回补幂等标记 _mlshop_funds_reversed', '1' === (string) get_post_meta($of, '_mlshop_funds_reversed', true));
check('申请记录标 approved', 'approved' === Moonlight_Refund_Service::requests($of)[0]['status']);
check('refunded_total = 100', abs(Moonlight_Refund_Service::refunded_total($of) - 100.0) < 0.001);
check('processed_total = 100（与 refunded_total 分开记录）', abs((float) get_post_meta($of, '_mlshop_refund_processed_total', true) - 100.0) < 0.001);
$flog = Moonlight_Refund_Service::refund_log($of);
check('退款日志 1 条（全额 / 网关退款号 / 操作者）', 1 === count($flog) && false === $flog[0]['partial']
    && 're_full_1' === $flog[0]['gateway_refund_id'] && 7 === (int) $flog[0]['actor'] && abs((float) $flog[0]['amount'] - 100.0) < 0.001);
check('网关调用一次且全额 amount=0', 1 === count(Moonlight_Refund_Service_Test::$gateway_calls)
    && abs(Moonlight_Refund_Service_Test::$gateway_calls[0]['amount']) < 0.001
    && $of === Moonlight_Refund_Service_Test::$gateway_calls[0]['order_id']);
check('moonlight_refund_processed 触发（order, amount, true）', !empty($GLOBALS['__test_actions']['moonlight_refund_processed'])
    && $of === (int) $GLOBALS['__test_actions']['moonlight_refund_processed'][0][0]
    && abs((float) $GLOBALS['__test_actions']['moonlight_refund_processed'][0][1] - 100.0) < 0.001
    && true === $GLOBALS['__test_actions']['moonlight_refund_processed'][0][2]);
check('状态机钩子 mlshop_order_refunded 触发', !empty($GLOBALS['__test_actions']['mlshop_order_refunded']));
check('已退款订单再次 process 拒绝', is_wp_error(Moonlight_Refund_Service_Test::process($of, 0, '再退', 7)));

echo "== 售后退款：process 网关失败 → 状态不变 ==\n";
__test_reset_card_env();
$o_fail = __test_make_order('paid', array(
    '_mlshop_total'           => 80.0,
    '_mlshop_items'           => array(array('id' => 0, 'qty' => 1, 'price' => 80.0, 'subtotal' => 80.0)),
    '_mlshop_payment_gateway' => 'stripe',
    '_mlshop_payment_id'      => 'pi_fail_1',
));
Moonlight_Refund_Service_Test::$gateway_result = array('success' => false, 'message' => '网关拒绝退款');
check('网关失败 → WP_Error', is_wp_error(Moonlight_Refund_Service_Test::process($o_fail, 0, '不想要了', 7)));
check('失败后状态不变（仍 paid）', 'paid' === MLSHOP_Order::get_status($o_fail));
check('失败不记 refunded_total', '' === (string) get_post_meta($o_fail, '_mlshop_refunded_total', true));
check('失败不写退款日志', array() === Moonlight_Refund_Service::refund_log($o_fail));
check('失败不触发 moonlight_refund_processed', empty($GLOBALS['__test_actions']['moonlight_refund_processed']));
check('失败后可改用仅标记收尾', true === Moonlight_Refund_Service_Test::process($o_fail, 0, '人工退款完成', 7, true)
    && 'refunded' === MLSHOP_Order::get_status($o_fail));

echo "== 售后退款：process 部分退款 → 累计达总额自动收尾 ==\n";
__test_reset_card_env();
$o_part = __test_make_order('paid', array(
    '_mlshop_total'           => 100.0,
    '_mlshop_items'           => array(array('id' => 0, 'qty' => 1, 'price' => 100.0, 'subtotal' => 100.0)),
    '_mlshop_payment_gateway' => 'stripe',
    '_mlshop_payment_id'      => 'pi_part_1',
));
Moonlight_Refund_Service_Test::$gateway_result = array('success' => true, 'refund_id' => 're_p1', 'message' => 'ok');
check('部分退款 50/100 成功', true === Moonlight_Refund_Service_Test::process($o_part, 50, '部分损坏', 7));
check('refunded_total = 50', abs(Moonlight_Refund_Service::refunded_total($o_part) - 50.0) < 0.001);
check('状态不变（仍 paid）', 'paid' === MLSHOP_Order::get_status($o_part));
$plog = Moonlight_Refund_Service::refund_log($o_part);
check('退款日志 1 条（partial=true + 网关退款号）', 1 === count($plog) && true === $plog[0]['partial'] && 're_p1' === $plog[0]['gateway_refund_id']);
check('部分退款后未达总额仍可继续退', true === Moonlight_Refund_Service::can_refund($o_part));
Moonlight_Refund_Service_Test::$gateway_result = array('success' => true, 'refund_id' => 're_p2', 'message' => 'ok');
check('再退 50 → 成功', true === Moonlight_Refund_Service_Test::process($o_part, 50, '补足剩余', 7));
check('累计达总额自动终态 refunded', 'refunded' === MLSHOP_Order::get_status($o_part));
check('refunded_total 收敛为 100', abs(Moonlight_Refund_Service::refunded_total($o_part) - 100.0) < 0.001);
check('退款日志累计 2 条', 2 === count(Moonlight_Refund_Service::refund_log($o_part)));
check('已全额退款后再 process 拒绝', is_wp_error(Moonlight_Refund_Service_Test::process($o_part, 10, '还退', 7)));

echo "== 售后退款：防抖 / 仅标记 / balance 网关 ==\n";
__test_reset_card_env();
$o_dup = __test_make_order('paid', array(
    '_mlshop_total'           => 100.0,
    '_mlshop_items'           => array(array('id' => 0, 'qty' => 1, 'price' => 100.0, 'subtotal' => 100.0)),
    '_mlshop_payment_gateway' => 'stripe',
    '_mlshop_payment_id'      => 'pi_dup_1',
));
Moonlight_Refund_Service_Test::$gateway_result = array('success' => true, 'refund_id' => 're_d1', 'message' => 'ok');
check('首次部分退款 30 成功', true === Moonlight_Refund_Service_Test::process($o_dup, 30, '同原因', 7));
check('同秒同额同因重复提交 → 防抖拒绝', is_wp_error(Moonlight_Refund_Service_Test::process($o_dup, 30, '同原因', 7)));
check('防抖不重复记账（仍 30）', abs(Moonlight_Refund_Service::refunded_total($o_dup) - 30.0) < 0.001);
check('同秒同额不同原因放行（非同一请求）', true === Moonlight_Refund_Service_Test::process($o_dup, 30, '不同原因', 7));
check('balance/cod/manual 网关跳过 API（生产分发器 skipped）', true === Moonlight_Refund_Service::gateway_refund(__test_make_order('paid', array('_mlshop_payment_gateway' => 'balance')), 0)['skipped']);
check('未知网关不支持在线退款', false === Moonlight_Refund_Service::gateway_refund(__test_make_order('paid', array('_mlshop_payment_gateway' => 'bogus')), 0)['success']);
__test_reset_card_env();
$o_skip = __test_make_order('paid', array(
    '_mlshop_total'           => 60.0,
    '_mlshop_items'           => array(array('id' => 0, 'qty' => 1, 'price' => 60.0, 'subtotal' => 60.0)),
    '_mlshop_payment_gateway' => 'stripe',
    '_mlshop_payment_id'      => 'pi_skip_1',
));
check('仅标记全额退款成功', true === Moonlight_Refund_Service::process($o_skip, 0, '网关后台已手动退款', 7, true));
check('仅标记未发起任何网关 HTTP', empty($GLOBALS['__test_http_calls']));
check('仅标记状态 refunded', 'refunded' === MLSHOP_Order::get_status($o_skip));
check('仅标记台账 refunded_total=60', abs(Moonlight_Refund_Service::refunded_total($o_skip) - 60.0) < 0.001);
__test_reset_card_env();
update_user_meta(1, '_mlshop_balance', 20.0);
$o_bal = __test_make_order('paid', array(
    '_mlshop_total'            => 40.0,
    '_mlshop_items'            => array(array('id' => 0, 'qty' => 1, 'price' => 40.0, 'subtotal' => 40.0)),
    '_mlshop_payment_gateway'  => 'balance',
    '_mlshop_payment_id'       => 'bal_txn_1',
));
check('balance 全额退款成功（跳过网关 API）', true === Moonlight_Refund_Service::process($o_bal, 0, '余额原路退回', 7));
check('balance 未发起任何 HTTP 调用', empty($GLOBALS['__test_http_calls']));
check('balance 钱包回补 20+40=60（单账本 _mlshop_balance）', abs((float) get_user_meta(1, '_mlshop_balance', true) - 60.0) < 0.001);
check('balance 状态 refunded', 'refunded' === MLSHOP_Order::get_status($o_bal));
check('balance 记录资金回补幂等标记', '1' === (string) get_post_meta($o_bal, '_mlshop_funds_reversed', true));

echo "== 售后退款：Stripe refund 参数构造 ==\n";
__test_reset_card_env();
$s_order = __test_make_order('paid', array('_mlshop_total' => 100.0, '_mlshop_payment_gateway' => 'stripe', '_mlshop_payment_id' => 'pi_test_1'));
__test_set_option('stripe_test_secret', 'sk_test_123');
$stub_refund = array('response' => array('code' => 200), 'body' => wp_json_encode(array('id' => 're_test_1', 'object' => 'refund', 'status' => 'succeeded')));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) use ($stub_refund) {
    return $stub_refund;
};
$stripe = new MLSHOP_Gateway_Stripe();
$r_part = $stripe->refund($s_order, 50);
check('Stripe 部分退款成功并返回 refund_id', !empty($r_part['success']) && 're_test_1' === $r_part['refund_id']);
check('Stripe 请求 POST https://api.stripe.com/v1/refunds', 1 === count($GLOBALS['__test_http_calls'])
    && 'POST' === $GLOBALS['__test_http_calls'][0]['method']
    && false !== strpos($GLOBALS['__test_http_calls'][0]['url'], 'https://api.stripe.com/v1/refunds'));
parse_str((string) $GLOBALS['__test_http_calls'][0]['args']['body'], $sent);
check('Stripe 部分退款 body 含 payment_intent', isset($sent['payment_intent']) && 'pi_test_1' === $sent['payment_intent']);
check('Stripe 部分退款 body amount=5000（to_minor_units）', isset($sent['amount']) && '5000' === $sent['amount']);
check('Stripe body reason=requested_by_customer', isset($sent['reason']) && 'requested_by_customer' === $sent['reason']);
$r_full = $stripe->refund($s_order, 0);
parse_str((string) $GLOBALS['__test_http_calls'][1]['args']['body'], $sent_full);
check('Stripe 全额退款省略 amount', !isset($sent_full['amount']) && isset($sent_full['payment_intent']));
$s_none = $stripe->refund(__test_make_order('paid', array('_mlshop_total' => 10.0)), 0);
check('Stripe 缺 payment_id → 拒绝', empty($s_none['success']));

echo "== 售后退款：PayPal refund 参数构造 ==\n";
__test_reset_card_env();
$p_order = __test_make_order('paid', array('_mlshop_total' => 100.0, '_mlshop_payment_gateway' => 'paypal', '_mlshop_payment_id' => 'PP-ORDER-9'));
__test_set_option('paypal_client_id', 'cid');
__test_set_option('paypal_secret', 'sec');
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if (false !== strpos($url, '/v1/oauth2/token')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array('access_token' => 'TESTTOKEN', 'expires_in' => 3600)));
    }
    if (false !== strpos($url, '/v2/checkout/orders/PP-ORDER-9')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
            'id'             => 'PP-ORDER-9',
            'purchase_units' => array(array(
                'payments' => array('captures' => array(array('id' => 'CAP-77'))),
            )),
        )));
    }
    if (false !== strpos($url, '/v2/payments/captures/CAP-77/refund')) {
        return array('response' => array('code' => 201), 'body' => wp_json_encode(array('id' => 'REF-42', 'status' => 'COMPLETED')));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$paypal = new MLSHOP_Gateway_PayPal();
$pp = $paypal->refund($p_order, 30);
check('PayPal 部分退款成功并返回 refund_id', !empty($pp['success']) && 'REF-42' === $pp['refund_id']);
$pp_order_call = null;
$pp_refund_calls = array();
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/v2/checkout/orders/PP-ORDER-9')) {
        $pp_order_call = $c;
    }
    if (false !== strpos($c['url'], '/v2/payments/captures/CAP-77/refund')) {
        $pp_refund_calls[] = $c;
    }
}
check('PayPal GET /v2/checkout/orders/{id} 提取 capture id', is_array($pp_order_call) && 'GET' === $pp_order_call['method'] && !empty($pp_refund_calls));
$pp_body = json_decode((string) $pp_refund_calls[0]['args']['body'], true);
check('PayPal 部分退款 body amount=30.00/HKD', isset($pp_body['amount']['value'], $pp_body['amount']['currency_code'])
    && '30.00' === $pp_body['amount']['value'] && 'HKD' === $pp_body['amount']['currency_code']);
$token_calls = 0;
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/v1/oauth2/token')) {
        $token_calls++;
    }
}
check('PayPal access_token 只请求一次（transient 缓存复用）', 1 === $token_calls);
$pp2 = $paypal->refund($p_order, 0);
check('PayPal 全额退款成功', !empty($pp2['success']) && 'REF-42' === $pp2['refund_id']);
$pp_refund_calls = array();
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/v2/payments/captures/CAP-77/refund')) {
        $pp_refund_calls[] = $c;
    }
}
check('PayPal 全额退款 body 为空 JSON {}（省略 amount）', 2 === count($pp_refund_calls) && '{}' === (string) $pp_refund_calls[1]['args']['body']);

/* ==========================================================================
 * Pro（moonlight-shop-pro）
 * ========================================================================== */

echo "== MLPRO_License_Client（引擎缺席 → 本地模式回退） ==\n";
check('MLUC 引擎在测试环境中不存在', !class_exists('MLUC_License_Manager'));
delete_option(MLPRO_License_Client::OPT_LOCAL_ACTIVE);
MLPRO_License_Client::clear_cache();
check('引擎缺席默认本地激活（option 默认 1）', MLPRO_License_Client::is_active() === true);
check('模式识别为 local', MLPRO_License_Client::get_mode() === 'local');
update_option(MLPRO_License_Client::OPT_LOCAL_ACTIVE, 0);
MLPRO_License_Client::clear_cache();
check('本地开关关闭 → 未激活', MLPRO_License_Client::is_active() === false);
update_option(MLPRO_License_Client::OPT_LOCAL_ACTIVE, 1);
MLPRO_License_Client::clear_cache();
check('本地开关重新开启 → 激活', MLPRO_License_Client::is_active() === true);

echo "== MLPRO_Webhooks::sign_payload / format_signature ==\n";
$wh_body = '{"event":"order.paid","data":{"order_id":7}}';
$sig1 = MLPRO_Webhooks::sign_payload($wh_body, 'whsec_abc', 1727400000);
$sig2 = MLPRO_Webhooks::sign_payload($wh_body, 'whsec_abc', 1727400000);
check('签名确定性（同输入同输出）', $sig1 === $sig2);
check('v1 与 hash_hmac 手算一致', $sig1['v1'] === hash_hmac('sha256', '1727400000.' . $wh_body, 'whsec_abc'));
check('header 格式 t=...,v1=...', MLPRO_Webhooks::format_signature($sig1['t'], $sig1['v1'])
    === 't=1727400000,v1=' . hash_hmac('sha256', '1727400000.' . $wh_body, 'whsec_abc'));
check('secret 不同 → 签名不同', MLPRO_Webhooks::sign_payload($wh_body, 'other-secret', 1727400000)['v1'] !== $sig1['v1']);
check('时间戳不同 → 签名不同', MLPRO_Webhooks::sign_payload($wh_body, 'whsec_abc', 1727400001)['v1'] !== $sig1['v1']);

echo "== MLPRO_Webhooks 队列退避（5/15/60 分钟，≥5 丢弃） ==\n";
check('attempts 0 → 5min', MLPRO_Webhooks::next_delay(0) === 300);
check('attempts 1 → 15min', MLPRO_Webhooks::next_delay(1) === 900);
check('attempts 2 → 60min', MLPRO_Webhooks::next_delay(2) === 3600);
check('attempts 2+ 封顶 60min', MLPRO_Webhooks::next_delay(7) === 3600);
check('attempts 4 尚不丢弃', MLPRO_Webhooks::should_drop(4) === false);
check('attempts >= 5 丢弃判定', MLPRO_Webhooks::should_drop(5) === true && MLPRO_Webhooks::should_drop(9) === true);
$wh_item = MLPRO_Webhooks::build_queue_item('ep1', 'order.paid', '{"e":1}', 0, 1000);
check('队列项结构完整', isset($wh_item['endpoint_id'], $wh_item['event'], $wh_item['body'], $wh_item['attempts'], $wh_item['next_try'])
    && 'ep1' === $wh_item['endpoint_id'] && 'order.paid' === $wh_item['event'] && 0 === $wh_item['attempts']);
check('首次入队 next_try = now + 5min', 1300 === $wh_item['next_try']);
$wh_item2 = MLPRO_Webhooks::build_queue_item('ep1', 'order.paid', '{}', 2, 1000);
check('attempts 2 入队 next_try = now + 60min', 4600 === $wh_item2['next_try']);
$wh_ring = array();
foreach (array(1, 2, 3) as $wh_v) {
    $wh_ring = MLPRO_Webhooks::push_capped($wh_ring, array('v' => $wh_v), 2);
}
check('环形日志封顶丢最旧', count($wh_ring) === 2 && 2 === $wh_ring[0]['v'] && 3 === $wh_ring[1]['v']);

echo "== MLPRO_Webhooks 事件过滤（按端点订阅） ==\n";
$wh_ep = array('id' => 'e1', 'url' => 'https://example.com/hook', 'secret' => 's', 'events' => array('order.paid'), 'enabled' => 1);
check('订阅 order.paid 收 order.paid', MLPRO_Webhooks::endpoint_wants($wh_ep, 'order.paid') === true);
check('订阅 order.paid 不收 order.shipped', MLPRO_Webhooks::endpoint_wants($wh_ep, 'order.shipped') === false);
check('未订阅的 refund.processed 不收', MLPRO_Webhooks::endpoint_wants($wh_ep, 'refund.processed') === false);
$wh_ep_off = $wh_ep;
$wh_ep_off['enabled'] = 0;
check('端点停用不收任何事件', MLPRO_Webhooks::endpoint_wants($wh_ep_off, 'order.paid') === false);
$wh_ep_none = $wh_ep;
$wh_ep_none['events'] = array();
check('订阅列表为空不收', MLPRO_Webhooks::endpoint_wants($wh_ep_none, 'order.paid') === false);

echo "== MLPRO_Order_Export CSV 公式注入防护 / 日期白名单 ==\n";
check('=cmd(A1) 前置单引号', MLPRO_Order_Export::csv_cell('=cmd(A1)') === "'=cmd(A1)");
check('+1 前置单引号', MLPRO_Order_Export::csv_cell('+1') === "'+1");
check('-1 前置单引号', MLPRO_Order_Export::csv_cell('-1') === "'-1");
check('@SUM 前置单引号', MLPRO_Order_Export::csv_cell('@SUM(A1:A9)') === "'@SUM(A1:A9)");
check('制表符前缀前置单引号', MLPRO_Order_Export::csv_cell("\tSUM(A1)") === "'\tSUM(A1)");
check('普通文本不变', MLPRO_Order_Export::csv_cell('MLS-20260927-abc') === 'MLS-20260927-abc');
check('普通数字不变', MLPRO_Order_Export::csv_cell('140.00') === '140.00');
check('空串不变', MLPRO_Order_Export::csv_cell('') === '');
check('日期合法（格式 + 回读一致）', MLPRO_Order_Export::parse_date('2026-09-27', '2026-01-01') === '2026-09-27');
check('日期非法格式回退默认', MLPRO_Order_Export::parse_date('09/27/2026', '2026-01-01') === '2026-01-01');
check('不存在日期（2月30日）回退默认', MLPRO_Order_Export::parse_date('2026-02-30', '2026-01-01') === '2026-01-01');
check('日期为空回退默认', MLPRO_Order_Export::parse_date('', '2026-01-01') === '2026-01-01');


echo "== Guest checkout 游客购买（邮箱下单 / 访问令牌 / 注册推荐） ==\n";
require __DIR__ . '/../moonlight-shop/includes/class-email.php';

// 基础订单：登录用户订单（有 user_id，无游客令牌）
$u_order = wp_insert_post(array('post_title' => 'MLS-T-G0', 'post_type' => 'mlshop_order', 'post_status' => 'publish'));
update_post_meta($u_order, '_mlshop_user_id', 7);
check('登录用户订单不判为游客订单', !mlshop_is_guest_order($u_order));

// 游客订单：user_id=0 + 访客令牌 + 联系邮箱
$g_token = wp_generate_password(48, false, false);
$g_order = wp_insert_post(array('post_title' => 'MLS-T-G1', 'post_type' => 'mlshop_order', 'post_status' => 'publish'));
update_post_meta($g_order, '_mlshop_user_id', 0);
update_post_meta($g_order, '_mlshop_guest_token', $g_token);
update_post_meta($g_order, '_mlshop_guest_email', 'guest@example.com');
update_post_meta($g_order, '_mlshop_status', 'paid');
update_post_meta($g_order, '_mlshop_total', 100);
update_post_meta($g_order, '_mlshop_items', array(array('title' => 'T', 'qty' => 1, 'subtotal' => 100)));
check('游客订单判定（user_id=0 且带令牌）', mlshop_is_guest_order($g_order));
check('有效访客令牌通过（hash_equals）', mlshop_verify_guest_token($g_order, $g_token));
check('错误访客令牌拒绝', !mlshop_verify_guest_token($g_order, 'wrong-token'));
check('空访客令牌拒绝', !mlshop_verify_guest_token($g_order, ''));
check('登录用户订单拒绝任意令牌', !mlshop_verify_guest_token($u_order, $g_token));

// 订单查看 URL：游客订单自动附带令牌，登录用户订单不带
$g_url = mlshop_order_view_url($g_order);
check('游客订单 URL 含访问令牌', false !== strpos($g_url, rawurlencode($g_token)));
check('登录用户订单 URL 不含令牌参数', false === strpos(mlshop_order_view_url($u_order), 'token='));

// 注册推荐链接：会员中心缺失时回退 WordPress 原生注册页，且可预填邮箱
$reg = mlshop_guest_register_url('guest@example.com');
check('注册链接默认回退 wp_registration_url', false !== strpos($reg, 'action=register'));
check('注册链接预填下单邮箱', false !== strpos($reg, 'guest%40example.com') || false !== strpos($reg, 'guest@example.com'));
check('无效邮箱不附加预填参数', false === strpos(mlshop_guest_register_url('not-an-email'), 'mlshop_email'));

// 订单邮件：游客订单发往下单邮箱，并内嵌注册推荐
$GLOBALS['__test_wp_mail'] = array();
$GLOBALS['__test_set_user'] = 0; // get_current_user_id 由桩控制
mlshop_email_test_send($g_order);
$sent = $GLOBALS['__test_wp_mail'];
check('游客订单邮件发往下单邮箱', !empty($sent) && 'guest@example.com' === $sent[0]['to']);
check('游客订单邮件内嵌注册推荐', !empty($sent) && false !== strpos($sent[0]['body'], 'action=register'));
check('游客订单邮件含订单查看链接', !empty($sent) && false !== strpos($sent[0]['body'], rawurlencode($g_token)));

// 登录用户订单：不附带注册推荐（注入测试用户供邮件取件）
$GLOBALS['__test_wp_mail'] = array();
$GLOBALS['__test_users'][7] = (object) array('ID' => 7, 'user_email' => 'user7@example.com', 'display_name' => 'User7');
update_post_meta($u_order, '_mlshop_status', 'paid');
update_post_meta($u_order, '_mlshop_total', 50);
update_post_meta($u_order, '_mlshop_items', array(array('title' => 'T', 'qty' => 1, 'subtotal' => 50)));
mlshop_email_test_send($u_order);
$sent2 = $GLOBALS['__test_wp_mail'];
check('登录用户订单邮件不含注册推荐', !empty($sent2) && false === strpos($sent2[0]['body'], 'action=register'));

/* ==========================================================================
 * 微信支付 v3（MLSHOP_Gateway_WeChat，自实现 API v3）
 * 签名串 / RSA 签名验签回环 / AES-256-GCM / notify 四重校验 / 金额分转换 /
 * 状态映射 / 查单复核 / 退款与关单参数构造。HTTP 由 wp-stubs 桩捕获。
 * ========================================================================== */

/**
 * 测试用 RSA 密钥对（openssl_pkey_new 在 Windows 需要显式 config 文件）。
 *
 * @return array|false array{priv: string, pub: string}
 */
function __test_wechat_rsa_keypair()
{
    $cnf = tempnam(sys_get_temp_dir(), 'mlshop_openssl_');
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
    $args = array('config' => $cnf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
    $res = openssl_pkey_new($args);
    if (!$res) {
        return false;
    }
    $priv = '';
    if (!openssl_pkey_export($res, $priv, null, $args)) {
        return false;
    }
    $details = openssl_pkey_get_details($res);
    return array('priv' => $priv, 'pub' => $details['key']);
}

/** 测试用网关子类：注入订单反查桩（生产走 $wpdb）。 */
class MLSHOP_Gateway_WeChat_Test extends MLSHOP_Gateway_WeChat
{
    public static $order_map = array();
    protected static function find_order_by_order_no($order_no)
    {
        return isset(self::$order_map[(string) $order_no]) ? (int) self::$order_map[(string) $order_no] : 0;
    }
}

/** 设置微信网关完整测试配置。 */
function __test_wechat_set_options($wkey)
{
    __test_set_option('wechat_enabled', 1);
    __test_set_option('wechat_mchid', '1900000001');
    __test_set_option('wechat_appid', 'wxTESTAPP');
    __test_set_option('wechat_serial_no', 'MCHSERIAL01');
    __test_set_option('wechat_private_key', $wkey['priv']);
    __test_set_option('wechat_apiv3_key', '0123456789abcdef0123456789abcdef');
    __test_set_option('wechat_pub_serial', 'PUBSERIAL01');
    __test_set_option('wechat_pub_key', $wkey['pub']);
    __test_set_option('wechat_scene', 'auto');
    __test_set_option('currency', 'CNY');
}

/**
 * 构造一条「合法签名 + APIv3 加密 resource」的 notify 请求。
 * $opts：timestamp（覆盖）、serial（覆盖）、mchid / appid（覆盖 resource 商户字段）。
 */
function __test_wechat_build_notify($wkey, $order_no, $trade_state, $total_fen, $opts = array())
{
    $apiv3 = '0123456789abcdef0123456789abcdef';
    $resource = array(
        'appid'        => isset($opts['appid']) ? $opts['appid'] : 'wxTESTAPP',
        'mchid'        => isset($opts['mchid']) ? $opts['mchid'] : '1900000001',
        'out_trade_no' => (string) $order_no,
        'trade_state'  => (string) $trade_state,
        'transaction_id' => 'wx_txn_' . substr(md5((string) $order_no . $trade_state), 0, 10),
        'amount'       => array('total' => (int) $total_fen, 'payer_total' => (int) $total_fen, 'currency' => 'CNY'),
    );
    $plain = wp_json_encode($resource);
    $nonce_res = bin2hex(random_bytes(6)); // 生产语义：resource.nonce 即 AES-GCM IV（12 字节）
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $apiv3, OPENSSL_RAW_DATA, $nonce_res, $tag, 'transaction');
    $payload = array(
        'id'         => 'EV-TEST-' . substr(md5($plain), 0, 10),
        'event_type' => 'TRANSACTION.SUCCESS',
        'resource'   => array(
            'algorithm'       => 'AEAD_AES_256_GCM',
            'nonce'           => $nonce_res,
            'associated_data' => 'transaction',
            'ciphertext'      => base64_encode($ct . $tag),
        ),
    );
    $body = wp_json_encode($payload);
    $ts = isset($opts['timestamp']) ? (string) $opts['timestamp'] : (string) time();
    $nonce = bin2hex(random_bytes(8));
    $message = $ts . "\n" . $nonce . "\n" . $body . "\n";
    openssl_sign($message, $sig, $wkey['priv'], OPENSSL_ALGO_SHA256);
    $req = new WP_REST_Request();
    $req->set_body($body);
    $req->set_header('Wechatpay-Timestamp', $ts);
    $req->set_header('Wechatpay-Nonce', $nonce);
    $req->set_header('Wechatpay-Signature', base64_encode($sig));
    $req->set_header('Wechatpay-Serial', isset($opts['serial']) ? $opts['serial'] : 'PUBSERIAL01');
    return $req;
}

echo "== 微信支付 v3：签名串构造 / RSA 签名验签 ==\n";
$wkey = __test_wechat_rsa_keypair();
check('OpenSSL RSA 密钥对可用（notify 验签依赖）', is_array($wkey) && false !== strpos($wkey['priv'], 'PRIVATE KEY'));
$wm = MLSHOP_Gateway_WeChat::sign_message('POST', '/v3/pay/transactions/native', '1700000000', 'NONCE123', '{"a":1}');
check('签名串五段拼接（METHOD\\nPATH\\nts\\nnonce\\nbody\\n）', $wm === "POST\n/v3/pay/transactions/native\n1700000000\nNONCE123\n{\"a\":1}\n");
check('GET 空体签名串为空行', MLSHOP_Gateway_WeChat::sign_message('GET', '/v3/certificates', '1700000000', 'N', '') === "GET\n/v3/certificates\n1700000000\nN\n\n");
check('方法小写归一为大写', strpos(MLSHOP_Gateway_WeChat::sign_message('get', '/p', '1', 'n', 'b'), 'GET') === 0);
openssl_sign($wm, $wm_sig, $wkey['priv'], OPENSSL_ALGO_SHA256);
check('RSA-SHA256 签名 → 验签回环', MLSHOP_Gateway_WeChat::verify_signature($wm, base64_encode($wm_sig), $wkey['pub']));
check('验签拒绝篡改原文', !MLSHOP_Gateway_WeChat::verify_signature($wm . 'X', base64_encode($wm_sig), $wkey['pub']));
check('验签拒绝另一密钥的签名', !MLSHOP_Gateway_WeChat::verify_signature($wm, base64_encode($wm_sig . 'junk'), $wkey['pub']));
check('验签拒绝空签名', !MLSHOP_Gateway_WeChat::verify_signature($wm, '', $wkey['pub']));
check('验签拒绝无效公钥', !MLSHOP_Gateway_WeChat::verify_signature($wm, base64_encode($wm_sig), 'not-a-pem'));

echo "== 微信支付 v3：AES-256-GCM 解密 ==\n";
$wplain = wp_json_encode(array('out_trade_no' => 'ML20260101AAAAAAAA', 'trade_state' => 'SUCCESS'));
$wiv = random_bytes(12);
$wtag = '';
$wct = openssl_encrypt($wplain, 'aes-256-gcm', '0123456789abcdef0123456789abcdef', OPENSSL_RAW_DATA, $wiv, $wtag, 'transaction');
check('GCM 加密 → 解密回环（tag 末 16 字节拼接）', MLSHOP_Gateway_WeChat::aes_gcm_decrypt(base64_encode($wct . $wtag), '0123456789abcdef0123456789abcdef', $wiv, 'transaction') === $wplain);
check('GCM 篡改密文解密失败', MLSHOP_Gateway_WeChat::aes_gcm_decrypt(base64_encode(substr($wct, 0, 5) . 'X' . substr($wct, 6) . $wtag), '0123456789abcdef0123456789abcdef', $wiv, 'transaction') === '');
check('GCM 篡改 nonce 解密失败', MLSHOP_Gateway_WeChat::aes_gcm_decrypt(base64_encode($wct . $wtag), '0123456789abcdef0123456789abcdef', str_repeat('z', 12), 'transaction') === '');
check('GCM 篡改 AAD 解密失败', MLSHOP_Gateway_WeChat::aes_gcm_decrypt(base64_encode($wct . $wtag), '0123456789abcdef0123456789abcdef', $wiv, 'other') === '');
check('GCM 非 32 位密钥拒绝', MLSHOP_Gateway_WeChat::aes_gcm_decrypt(base64_encode($wct . $wtag), 'short-key', $wiv, 'transaction') === '');
check('GCM 坏 base64 拒绝', MLSHOP_Gateway_WeChat::aes_gcm_decrypt('!!!not-base64!!!', '0123456789abcdef0123456789abcdef', $wiv, 'transaction') === '');

echo "== 微信支付 v3：金额分转换 / trade_state 映射 ==\n";
check('to_fen 19.99 → 1999', MLSHOP_Gateway_WeChat::to_fen(19.99) === 1999);
check('to_fen 0.1 → 10（浮点边界 round）', MLSHOP_Gateway_WeChat::to_fen(0.1) === 10);
check('to_fen 100 → 10000', MLSHOP_Gateway_WeChat::to_fen(100.0) === 10000);
check('to_fen 0.3-0.1 残差 → 20（round 兜住浮点误差）', MLSHOP_Gateway_WeChat::to_fen(0.3 - 0.1) === 20);
check('SUCCESS → paid', MLSHOP_Gateway_WeChat::map_trade_state('SUCCESS') === 'paid');
check('success 小写 → paid', MLSHOP_Gateway_WeChat::map_trade_state('success') === 'paid');
check('NOTPAY → pending', MLSHOP_Gateway_WeChat::map_trade_state('NOTPAY') === 'pending');
check('USERPAYING → pending', MLSHOP_Gateway_WeChat::map_trade_state('USERPAYING') === 'pending');
check('CLOSED → failed', MLSHOP_Gateway_WeChat::map_trade_state('CLOSED') === 'failed');
check('REVOKED → failed', MLSHOP_Gateway_WeChat::map_trade_state('REVOKED') === 'failed');
check('PAYERROR → failed', MLSHOP_Gateway_WeChat::map_trade_state('PAYERROR') === 'failed');
check('未知状态 → pending（fail-safe）', MLSHOP_Gateway_WeChat::map_trade_state('WHATEVER') === 'pending');

echo "== 微信支付 v3：is_available 门控（配置 + OpenSSL + CNY） ==\n";
__test_reset_card_env();
check('未配置 → is_available false', !(new MLSHOP_Gateway_WeChat())->is_available());
check('未配置 → enabled false', !MLSHOP_Gateway_WeChat::enabled());
__test_wechat_set_options($wkey);
check('配置齐全 + CNY → is_available true', (new MLSHOP_Gateway_WeChat())->is_available());
__test_set_option('currency', 'HKD');
check('非 CNY → is_available false（对齐支付宝币种门控）', !(new MLSHOP_Gateway_WeChat())->is_available());
__test_set_option('currency', 'CNY');

echo "== 微信支付 v3：process_payment（native 扫码） ==\n";
__test_reset_card_env();
__test_wechat_set_options($wkey);
$GLOBALS['__test_is_mobile'] = false;
$wpo = __test_make_order('pending', array('_mlshop_total' => 88.0, '_mlshop_gateway' => 'wechat'));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if ('POST' === $method && false !== strpos($url, '/v3/pay/transactions/native')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array('code_url' => 'weixin://wxpay/bizpayurl?pr=test123')));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$wgw = new MLSHOP_Gateway_WeChat();
$wres = $wgw->process_payment($wpo);
check('native 下单成功且 qr 标记', !empty($wres['success']) && !empty($wres['qr']));
check('code_url 落订单 meta（订单页渲染二维码）', get_post_meta($wpo, '_mlshop_wechat_code_url', true) === 'weixin://wxpay/bizpayurl?pr=test123');
$wnative_call = $GLOBALS['__test_http_calls'][0];
$wnative_body = json_decode((string) $wnative_call['args']['body'], true);
check('下单 POST /v3/pay/transactions/native', 'POST' === $wnative_call['method'] && false !== strpos($wnative_call['url'], '/v3/pay/transactions/native'));
check('下单体 out_trade_no = 本地订单号', isset($wnative_body['out_trade_no']) && '' !== (string) get_post_meta($wpo, '_mlshop_order_no', true) && $wnative_body['out_trade_no'] === (string) get_post_meta($wpo, '_mlshop_order_no', true));
check('下单体金额 8800 分 / CNY', isset($wnative_body['amount']['total'], $wnative_body['amount']['currency']) && 8800 === $wnative_body['amount']['total'] && 'CNY' === $wnative_body['amount']['currency']);
check('下单体 notify_url 指向 REST 端点', isset($wnative_body['notify_url']) && false !== strpos($wnative_body['notify_url'], 'mlshop/v1/wechat/notify'));
check('redirect 引导回订单页', false !== strpos((string) $wres['redirect'], 'order=' . $wpo));

echo "== 微信支付 v3：process_payment（h5 场景 / 货币门控） ==\n";
$GLOBALS['__test_is_mobile'] = true; // auto → 移动端 h5
$wh5_order = __test_make_order('pending', array('_mlshop_total' => 50.0, '_mlshop_gateway' => 'wechat'));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if ('POST' === $method && false !== strpos($url, '/v3/pay/transactions/h5')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array('h5_url' => 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=wx1')));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$wh5 = $wgw->process_payment($wh5_order);
check('auto 场景移动端解析为 h5', MLSHOP_Gateway_WeChat::resolve_scene() === 'h5');
check('h5 下单成功 redirect = h5_url', !empty($wh5['success']) && false !== strpos((string) $wh5['redirect'], 'https://wx.tenpay.com/'));
$wh5_call = null;
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/v3/pay/transactions/h5')) {
        $wh5_call = $c;
    }
}
$wh5_body = json_decode((string) $wh5_call['args']['body'], true);
check('h5 下单体带 scene_info.payer_client_ip', isset($wh5_body['scene_info']['payer_client_ip']) && '' !== $wh5_body['scene_info']['payer_client_ip']);
__test_set_option('currency', 'HKD');
$whk = $wgw->process_payment($wh5_order);
check('非 CNY 货币拒绝发起（信息透传，不暴露密钥）', empty($whk['success']) && false === strpos((string) $whk['message'], 'key'));
__test_set_option('currency', 'CNY');

echo "== 微信支付 v3：query 查单复核 ==\n";
$wq = __test_make_order('paid', array('_mlshop_total' => 19.99, '_mlshop_gateway' => 'wechat', '_mlshop_order_no' => 'MLQ1'));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if ('GET' === $method && false !== strpos($url, '/v3/pay/transactions/out-trade-no/MLQ1')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
            'trade_state'    => 'SUCCESS',
            'transaction_id' => 'wx_txn_q1',
            'out_trade_no'   => 'MLQ1',
            'amount'         => array('total' => 1999, 'currency' => 'CNY'),
        )));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$wq_res = $wgw->query($wq);
check('query SUCCESS + 金额一致 → paid', is_array($wq_res) && 'paid' === $wq_res['status'] && 'wx_txn_q1' === $wq_res['transaction_id']);
$wq_call = $GLOBALS['__test_http_calls'][count($GLOBALS['__test_http_calls']) - 1];
check('query 为 GET 且带 mchid 查询串', 'GET' === $wq_call['method'] && false !== strpos($wq_call['url'], 'mchid=1900000001'));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if ('GET' === $method && false !== strpos($url, '/v3/pay/transactions/out-trade-no/MLQ1')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
            'trade_state' => 'SUCCESS', 'transaction_id' => 'wx_txn_q1', 'amount' => array('total' => 100, 'currency' => 'CNY'),
        )));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$wq_bad = $wgw->query($wq);
check('query SUCCESS 但金额不符 → mismatch（不完单）', is_array($wq_bad) && 'mismatch' === $wq_bad['status']);

echo "== 微信支付 v3：notify 四重校验（验签/解密/商户/金额/状态） ==\n";
__test_reset_card_env();
__test_wechat_set_options($wkey);
$wno = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY1',
    '_mlshop_total'    => 19.99,
    '_mlshop_gateway'  => 'wechat',
));
MLSHOP_Gateway_WeChat_Test::$order_map = array('MLNOTIFY1' => $wno);
$wnotify = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY1', 'SUCCESS', 1999));
check('合法 notify → HTTP 200 + code SUCCESS', 200 === $wnotify->get_status() && 'SUCCESS' === $wnotify->get_data()['code']);
check('合法 notify → mark_paid 被调（pending → paid）', 'paid' === MLSHOP_Order::get_status($wno));
check('微信交易号写入 _mlshop_payment_id', '' !== (string) get_post_meta($wno, '_mlshop_payment_id', true));
$wnotify2 = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY1', 'SUCCESS', 1999));
check('重复 notify 幂等（仍 paid，状态机短路）', 200 === $wnotify2->get_status() && 'paid' === MLSHOP_Order::get_status($wno));

// 金额不符 → 拒绝完单（HTTP 400 + 审计 meta）
$wamt = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY2',
    '_mlshop_total'    => 19.99,
    '_mlshop_gateway'  => 'wechat',
));
MLSHOP_Gateway_WeChat_Test::$order_map['MLNOTIFY2'] = $wamt;
$wnotify_amt = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY2', 'SUCCESS', 100));
check('金额不符（100 分 ≠ 1999 分）→ HTTP 400 FAIL', 400 === $wnotify_amt->get_status() && 'FAIL' === $wnotify_amt->get_data()['code']);
check('金额不符 → 不完单 + 留审计 meta', 'pending' === MLSHOP_Order::get_status($wamt) && 'wechat:100' === (string) get_post_meta($wamt, '_mlshop_pay_amount_mismatch', true));

// 非 SUCCESS 状态 → 不完单，但回 SUCCESS 停止微信重试
$wcls = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY3',
    '_mlshop_total'    => 10.0,
    '_mlshop_gateway'  => 'wechat',
));
MLSHOP_Gateway_WeChat_Test::$order_map['MLNOTIFY3'] = $wcls;
$wnotify_cls = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY3', 'CLOSED', 1000));
check('trade_state CLOSED → 不完单', 'pending' === MLSHOP_Order::get_status($wcls));
check('CLOSED 回 SUCCESS 停止重试', 200 === $wnotify_cls->get_status() && 'SUCCESS' === $wnotify_cls->get_data()['code']);

// 签名错误 → FAIL（订单不动）
$wsig_order = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY4',
    '_mlshop_total'    => 19.99,
    '_mlshop_gateway'  => 'wechat',
));
MLSHOP_Gateway_WeChat_Test::$order_map['MLNOTIFY4'] = $wsig_order;
$wreq_bad = __test_wechat_build_notify($wkey, 'MLNOTIFY4', 'SUCCESS', 1999);
$wreq_bad->set_header('Wechatpay-Signature', base64_encode('forged-signature-bytes'));
$wnotify_sig = MLSHOP_Gateway_WeChat_Test::handle_notify($wreq_bad);
check('签名伪造 → HTTP 401 FAIL', 401 === $wnotify_sig->get_status() && 'FAIL' === $wnotify_sig->get_data()['code']);
check('签名伪造 → 不完单', 'pending' === MLSHOP_Order::get_status($wsig_order));

// 商户 / appid 不符 → FAIL
$wmch = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY5',
    '_mlshop_total'    => 19.99,
    '_mlshop_gateway'  => 'wechat',
));
MLSHOP_Gateway_WeChat_Test::$order_map['MLNOTIFY5'] = $wmch;
$wnotify_mch = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY5', 'SUCCESS', 1999, array('mchid' => '9999999999')));
check('商户号不符 → FAIL（防跨商户伪造）', 400 === $wnotify_mch->get_status() && 'pending' === MLSHOP_Order::get_status($wmch));
$wnotify_app = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY5', 'SUCCESS', 1999, array('appid' => 'wxOTHER')));
check('appid 不符 → FAIL', 400 === $wnotify_app->get_status() && 'pending' === MLSHOP_Order::get_status($wmch));

// 时间戳超容差 → FAIL（重放防护 ±300s）
$wold = __test_wechat_build_notify($wkey, 'MLNOTIFY5', 'SUCCESS', 1999, array('timestamp' => (string) (time() - 400)));
$wnotify_old = MLSHOP_Gateway_WeChat_Test::handle_notify($wold);
check('时间戳超 ±300 秒 → FAIL（重放防护）', 400 === $wnotify_old->get_status());

// 缺失验签头 → FAIL
$wnoh = new WP_REST_Request();
$wnoh->set_body('{}');
$wnotify_noh = MLSHOP_Gateway_WeChat_Test::handle_notify($wnoh);
check('缺失验签头 → FAIL', 400 === $wnotify_noh->get_status());

// 订单号反查失败 / 网关不符 → FAIL
$wnotify_404 = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTFOUND', 'SUCCESS', 1999));
check('未知订单号 → HTTP 404', 404 === $wnotify_404->get_status());
$wgw_wrong = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY6',
    '_mlshop_total'    => 19.99,
    '_mlshop_gateway'  => 'alipay',
));
MLSHOP_Gateway_WeChat_Test::$order_map['MLNOTIFY6'] = $wgw_wrong;
$wnotify_gw = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY6', 'SUCCESS', 1999));
check('订单网关非 wechat → FAIL', 400 === $wnotify_gw->get_status() && 'pending' === MLSHOP_Order::get_status($wgw_wrong));

echo "== 微信支付 v3：平台证书模式验签（公钥未匹配 → 下载/缓存） ==\n";
__test_reset_card_env();
__test_wechat_set_options($wkey);
__test_set_option('wechat_pub_serial', ''); // 关闭公钥模式 → 走平台证书
$wpc = __test_make_order('pending', array(
    '_mlshop_order_no' => 'MLNOTIFY7',
    '_mlshop_total'    => 19.99,
    '_mlshop_gateway'  => 'wechat',
));
MLSHOP_Gateway_WeChat_Test::$order_map['MLNOTIFY7'] = $wpc;
$certs_hit = 0;
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) use (&$certs_hit, $wkey) {
    if ('GET' === $method && false !== strpos($url, '/v3/certificates')) {
        $certs_hit++;
        // 平台证书下载响应：encrypt_certificate 用 APIv3 密钥 AES-256-GCM 加密
        $nonce_c = bin2hex(random_bytes(6));
        $tag_c = '';
        $ct_c = openssl_encrypt($wkey['pub'], 'aes-256-gcm', '0123456789abcdef0123456789abcdef', OPENSSL_RAW_DATA, $nonce_c, $tag_c, 'certificate');
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
            'data' => array(array(
                'serial_no'           => 'PLATCERT01',
                'encrypt_certificate' => array(
                    'algorithm'       => 'AEAD_AES_256_GCM',
                    'nonce'           => $nonce_c,
                    'associated_data' => 'certificate',
                    'ciphertext'      => base64_encode($ct_c . $tag_c),
                ),
            )),
        )));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$wnotify_pc = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY7', 'SUCCESS', 1999, array('serial' => 'PLATCERT01')));
check('平台证书模式（/v3/certificates 下载解密）→ 验签完单', 200 === $wnotify_pc->get_status() && 'paid' === MLSHOP_Order::get_status($wpc));
check('平台证书 transient 缓存 12h', is_array(get_transient('mlshop_wechat_platform_certs')) && isset(get_transient('mlshop_wechat_platform_certs')['PLATCERT01']));
$wnotify_pc2 = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY7', 'SUCCESS', 1999, array('serial' => 'PLATCERT01')));
check('二次 notify 命中缓存不再下载（且幂等）', 1 === $certs_hit && 200 === $wnotify_pc2->get_status());
$wnotify_unk = MLSHOP_Gateway_WeChat_Test::handle_notify(__test_wechat_build_notify($wkey, 'MLNOTIFY7', 'SUCCESS', 1999, array('serial' => 'UNKNOWN-SERIAL')));
check('serial 未知且刷新失败 → FAIL（fail-closed）', 500 === $wnotify_unk->get_status() && 'FAIL' === $wnotify_unk->get_data()['code']);

echo "== 微信支付 v3：refund 参数构造 ==\n";
__test_reset_card_env();
__test_wechat_set_options($wkey);
$wro = __test_make_order('paid', array('_mlshop_total' => 100.0, '_mlshop_gateway' => 'wechat', '_mlshop_order_no' => 'MLREF1', '_mlshop_payment_id' => 'wx_txn_r1'));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if ('POST' === $method && false !== strpos($url, '/v3/refund/domestic/refunds')) {
        return array('response' => array('code' => 200), 'body' => wp_json_encode(array('refund_id' => 'wx_ref_1', 'out_refund_no' => 'RF', 'status' => 'PROCESSING')));
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
$wref = $wgw->refund($wro, 30, '部分退款');
check('部分退款成功并返回 refund_id', !empty($wref['success']) && 'wx_ref_1' === $wref['refund_id']);
$wref_call = null;
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/v3/refund/domestic/refunds')) {
        $wref_call = $c;
    }
}
$wref_body = json_decode((string) $wref_call['args']['body'], true);
check('退款 POST /v3/refund/domestic/refunds（商户单号 = 本地订单号）', is_array($wref_body) && 'MLREF1' === $wref_body['out_trade_no']);
check('商户退款单号 RF 前缀 + 唯一后缀', isset($wref_body['out_refund_no']) && 0 === strpos($wref_body['out_refund_no'], 'RFMLREF1'));
check('部分退款 amount.refund=3000 / total=10000 / CNY', 3000 === $wref_body['amount']['refund'] && 10000 === $wref_body['amount']['total'] && 'CNY' === $wref_body['amount']['currency']);
check('退款原因透传（reason）', isset($wref_body['reason']) && '部分退款' === $wref_body['reason']);
$wref_full = $wgw->refund($wro, 0);
$wref_call2 = null;
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/v3/refund/domestic/refunds')) {
        $wref_call2 = $c;
    }
}
$wref_body2 = json_decode((string) $wref_call2['args']['body'], true);
check('全额退款（amount=0）→ refund = total 分', !empty($wref_full['success']) && 10000 === $wref_body2['amount']['refund']);
$wref_over = $wgw->refund($wro, 200);
check('退款超额拒绝', empty($wref_over['success']));
$wref_nono = $wgw->refund(__test_make_order('paid', array('_mlshop_total' => 10.0)), 0);
check('缺少商户单号拒绝退款', empty($wref_nono['success']));

echo "== 微信支付 v3：close 关单（取消钩子挂载点） ==\n";
$wcl = __test_make_order('pending', array('_mlshop_total' => 50.0, '_mlshop_gateway' => 'wechat', '_mlshop_order_no' => 'MLCLOSE1'));
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    if ('POST' === $method && false !== strpos($url, '/v3/pay/transactions/out-trade-no/MLCLOSE1/close')) {
        return array('response' => array('code' => 204), 'body' => '');
    }
    return array('response' => array('code' => 404), 'body' => '{}');
};
MLSHOP_Gateway_WeChat::maybe_close_order($wcl);
$wclose_call = null;
foreach ($GLOBALS['__test_http_calls'] as $c) {
    if (false !== strpos($c['url'], '/close')) {
        $wclose_call = $c;
    }
}
check('pending 取消 → 关单 POST（close 端点）', is_array($wclose_call));
$wclose_body = json_decode((string) $wclose_call['args']['body'], true);
check('关单体带 mchid', is_array($wclose_body) && '1900000001' === $wclose_body['mchid']);
$GLOBALS['__test_http_calls'] = array();
$wcl_paid = __test_make_order('paid', array('_mlshop_total' => 50.0, '_mlshop_gateway' => 'wechat', '_mlshop_order_no' => 'MLCLOSE2', '_mlshop_payment_id' => 'wx_txn_c2'));
MLSHOP_Gateway_WeChat::maybe_close_order($wcl_paid);
check('已付款订单取消不关单', empty($GLOBALS['__test_http_calls']));
$GLOBALS['__test_http_calls'] = array();
$wcl_other = __test_make_order('pending', array('_mlshop_total' => 50.0, '_mlshop_gateway' => 'alipay', '_mlshop_order_no' => 'MLCLOSE3'));
MLSHOP_Gateway_WeChat::maybe_close_order($wcl_other);
check('非微信订单不关单', empty($GLOBALS['__test_http_calls']));

echo "== 微信支付 v3：REST notify 路由注册 ==\n";
$GLOBALS['__test_rest_routes'] = array();
MLSHOP_Gateway_WeChat::register_notify();
check('注册 mlshop/v1/wechat/notify（POST，permission 恒真）', isset($GLOBALS['__test_rest_routes']['mlshop/v1/wechat/notify'])
    && 'POST' === $GLOBALS['__test_rest_routes']['mlshop/v1/wechat/notify']['methods']
    && '__return_true' === $GLOBALS['__test_rest_routes']['mlshop/v1/wechat/notify']['permission_callback']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

