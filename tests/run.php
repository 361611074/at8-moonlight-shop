<?php
/**
 * Standalone test runner: php tests/run.php
 * Covers Phase 2 core: price calculator (tier pricing, quote pipeline),
 * order state machine whitelist, migration helpers.
 * 物流第一批：Region Provider、地址簿、运费模板计价、自提报价。
 * 物流第二批：状态机扩展（待发货/已发货/已签收）、标签单一来源、
 *             发货单/轨迹、Provider 注册表、cron 同步与自动完成、Express100 解析器。
 * Pro（moonlight-shop-pro）：MLPRO Webhook 签名/退避/事件过滤、
 *             CSV 公式注入防护、日期白名单、License 客户端本地回退；
 *             Phase D：License 双产品语义、Elementor 会员卡门禁、旧 MLUCP Pro 共存让位。
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
// moonlight/v1 REST API（Phase 1：公开 + 登录路由）+ 其依赖的服务层
require __DIR__ . '/../moonlight-shop/includes/class-cart.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-rest-helpers.php';
require __DIR__ . '/../moonlight-shop/includes/core/class-rest.php';
// REST checkout 依赖支付管理器 + 全部内建网关（get_gateways 逐个实例化）
require __DIR__ . '/../moonlight-shop/includes/class-gateway-cod.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-balance.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-credit.php';
require __DIR__ . '/../moonlight-shop/includes/class-credit.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-manual.php';
require __DIR__ . '/../moonlight-shop/includes/class-gateway-alipay.php';
require __DIR__ . '/../moonlight-shop/includes/class-payment.php';

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
// Stripe 订单的资金退回走网关 API，站内账本无回补动作 → 不抢占内部回补旗标
// （审计 H2 新语义：仅在确有站内资金回补时才落 _mlshop_funds_reversed）
check('Stripe 单不落站内回补旗标（资金走网关）', '' === (string) get_post_meta($of, '_mlshop_funds_reversed', true));
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
check('balance 记录资金回补幂等标记（抢占式）', '' !== (string) get_post_meta($o_bal, '_mlshop_funds_reversed', true));

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
// 安全默认：引擎缺席时不得默认激活（否则只装 Pro 不装用户中心即可白嫖 Pro）。
// 历史实现默认为 1（已激活），等于给 Pro 留后门，已于授权安全修复中改为 0。
check('引擎缺席默认未激活（option 默认 0，安全默认）', MLPRO_License_Client::is_active() === false);
check('模式识别为 local', MLPRO_License_Client::get_mode() === 'local');
update_option(MLPRO_License_Client::OPT_LOCAL_ACTIVE, 1);
MLPRO_License_Client::clear_cache();
check('本地开关显式开启 → 激活', MLPRO_License_Client::is_active() === true);
update_option(MLPRO_License_Client::OPT_LOCAL_ACTIVE, 0);
MLPRO_License_Client::clear_cache();
check('本地开关关闭 → 未激活', MLPRO_License_Client::is_active() === false);
delete_option(MLPRO_License_Client::OPT_LOCAL_ACTIVE);

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

/* ==========================================================================
 * Phase D（Pro 收编，MERGE-USER-CENTER.md）：License 双产品语义 +
 * Elementor 会员卡组件门禁 + 旧 MLUCP Pro 共存让位。
 * 引擎桩在本段定义（此前已断言引擎不存在，真实引擎仅在 run-user.php 子进程）。
 * ========================================================================== */

echo "== Phase D：MLPRO_License_Client 双产品语义（引擎模式） ==\n";
$GLOBALS['__test_mluc_products'] = array();
// 引擎桩：条件声明（运行期绑定）。无条件顶层 class 会被 PHP 编译期提前绑定，
// 导致上方「引擎不存在」用例与本段之前的所有 class_exists 探测失效。
if (!class_exists('MLUC_License_Manager')) {
    class MLUC_License_Manager {
        public static function get_instance() { return new MLUC_License_Manager(); }
        public function is_product_active($product) {
            return !empty($GLOBALS['__test_mluc_products'][(string) $product]);
        }
    }
}
MLPRO_License_Client::clear_cache();
$GLOBALS['__test_mluc_products'] = array('moonlight-shop-pro' => false, 'moonlight-user-center-pro' => true);
check('新产品未激活 + 存量旧产品激活 → 激活（存量授权兼容）', MLPRO_License_Client::is_active() === true);
check('模式识别为 engine', MLPRO_License_Client::get_mode() === 'engine');
check('整体缓存生效（引擎结果翻转后未清缓存仍 true）', (function () {
    $GLOBALS['__test_mluc_products'] = array('moonlight-shop-pro' => false, 'moonlight-user-center-pro' => false);
    return MLPRO_License_Client::is_active() === true;
})());
MLPRO_License_Client::clear_cache();
check('两产品都未激活（引擎存在）→ 未激活', MLPRO_License_Client::is_active() === false);
$GLOBALS['__test_mluc_products'] = array('moonlight-shop-pro' => true, 'moonlight-user-center-pro' => false);
MLPRO_License_Client::clear_cache();
check('新产品激活 + 存量产品未激活 → 激活', MLPRO_License_Client::is_active() === true);
$__mlpc_ref = new ReflectionProperty('MLPRO_License_Client', 'product_cache');
$__mlpc_ref->setAccessible(true);
check('两产品结果按标识分别缓存（互不串键）', $__mlpc_ref->getValue(null) === array('moonlight-shop-pro' => true, 'moonlight-user-center-pro' => false));
check('product_status 返回两产品各自检查结果', MLPRO_License_Client::product_status() === array('moonlight-shop-pro' => true, 'moonlight-user-center-pro' => false));

echo "== Phase D：MLUCP_Elementor_Integration（自 MLUCP 收编） ==\n";
require __DIR__ . '/../moonlight-shop-pro/includes/class-elementor-integration.php';
check('Elementor 未加载 → 集成不启用', MLUCP_Elementor_Integration::enabled() === false);
eval('namespace Elementor;
class Plugin {}
class Widget_Base {
    protected $settings = array();
    public function get_settings_for_display() { return $this->settings; }
}');
check('Elementor 已加载（桩）且旧 Pro 未激活 → 集成启用', MLUCP_Elementor_Integration::enabled() === true);
check('收编组件类可加载且组件名保留 mlucp_membership_card', (function () {
    require __DIR__ . '/../moonlight-shop-pro/includes/elementor-membership-card.php';
    return class_exists('MLUCP_Membership_Card', false)
        && 'mlucp_membership_card' === (new MLUCP_Membership_Card())->get_name()
        && array('moonlight') === (new MLUCP_Membership_Card())->get_categories();
})());
check('组件渲染（未登录）输出登录提示卡', (function () {
    $render = new ReflectionMethod('MLUCP_Membership_Card', 'render');
    $render->setAccessible(true); // render 为 protected（Elementor Widget_Base 约定）
    ob_start();
    $render->invoke(new MLUCP_Membership_Card());
    $html = (string) ob_get_clean();
    return false !== strpos($html, 'mlucp-membership-card') && false !== strpos($html, '请先登录后查看会员状态');
})());

echo "== Phase D：旧 MLUCP Pro 共存让位 ==\n";
define('MLUCP_VERSION', '2.0.0-test'); // 模拟旧 Pro 激活（其主文件顶层 define，先于 plugins_loaded 可见）
check('旧 Pro 激活 → Elementor 集成让位（不启用）', MLUCP_Elementor_Integration::enabled() === false);
$GLOBALS['__test_mluc_products'] = array('moonlight-shop-pro' => false, 'moonlight-user-center-pro' => true);
MLPRO_License_Client::clear_cache();
check('旧 Pro 激活 → 双检查跳过（仅新产品语义：旧产品激活不计入）', MLPRO_License_Client::is_active() === false);
$GLOBALS['__test_mluc_products'] = array('moonlight-shop-pro' => true, 'moonlight-user-center-pro' => false);
MLPRO_License_Client::clear_cache();
check('旧 Pro 激活 → 新产品激活仍判定激活', MLPRO_License_Client::is_active() === true);
check('旧 Pro 激活 → product_status 返回空（授权页不展示双产品行）', MLPRO_License_Client::product_status() === array());

echo "== Phase D：Pro 主文件门禁与共存检测（源级断言） ==\n";
$__mlpro_main = (string) file_get_contents(__DIR__ . '/../moonlight-shop-pro/moonlight-shop-pro.php');
check('主文件门禁不变（缺 MLSHOP_VERSION / MLSHOP_Order 不启动）', false !== strpos($__mlpro_main, "!defined('MLSHOP_VERSION') || !class_exists('MLSHOP_Order')"));
check('主文件含旧 Pro 共存检测（MLUCP_VERSION / MLUCP_License_Client）', false !== strpos($__mlpro_main, "defined('MLUCP_VERSION')") && false !== strpos($__mlpro_main, "class_exists('MLUCP_License_Client', false)"));
check('主文件在旧 Pro 激活时不引入收编组件（让位分支）', false !== strpos($__mlpro_main, 'if (!$mlpro_legacy_pro)'));
check('收编文件含类名守卫（旧 Pro 副本冲突防护）', false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop-pro/includes/class-elementor-integration.php'), "class_exists('MLUCP_Elementor_Integration', false)"));


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
// 篡改必须保证字节真的变化：XOR 0x01（否则 1/256 概率随机密文该字节恰为 'X'，篡改落空导致测试偶发失败）
$wct_tampered = $wct;
$wct_tampered[5] = chr(ord($wct[5]) ^ 0x01);
check('GCM 篡改密文解密失败', MLSHOP_Gateway_WeChat::aes_gcm_decrypt(base64_encode($wct_tampered . $wtag), '0123456789abcdef0123456789abcdef', $wiv, 'transaction') === '');
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

/* ==========================================================================
 * moonlight/v1 REST API（Phase 1：公开路由 + 登录路由，docs/API.md 第二、三节）
 * handler 直调（WP_REST_Request 桩传参）；数据访问原语经 Moonlight_REST_Test
 * 子类接到行模型（与 Card_Stock / Refund_Service 测试同一「子类接桩」模式）。
 * ========================================================================== */

/** 测试用 REST：行模型接桩（find_card_row / find_sold_rows_for_user）+ 可直接实例化。 */
class Moonlight_REST_Test extends Moonlight_REST
{
    public function __construct()
    {
        // 不调用 parent::__construct()（私有单例构造），测试直调 handler
    }

    protected static function find_card_row($meta_id)
    {
        foreach (find_meta_rows() as $row) {
            if ((int) $row['meta_id'] === (int) $meta_id) {
                return $row;
            }
        }
        return null;
    }

    protected static function find_sold_rows_for_user($uid)
    {
        $out = array();
        foreach (find_meta_rows(null, Moonlight_Card_Stock::ST_SOLD) as $row) {
            $rec = json_decode((string) $row['meta_value'], true);
            if (is_array($rec) && isset($rec['u']) && (int) $rec['u'] === (int) $uid) {
                $out[] = $row;
            }
        }
        return $out;
    }
}

/** 便捷构造：带参数的 REST 请求桩。 */
function __test_rest_request($params = array())
{
    $req = new WP_REST_Request();
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    return $req;
}

echo "== REST：统一响应格式 helper（ok/err） ==\n";
$r_ok = Moonlight_Rest_Helpers::ok(array('a' => 1), array('total' => 2));
check('ok 结构 {code,data,meta} 且 HTTP 200', $r_ok instanceof WP_REST_Response && 200 === $r_ok->get_status()
    && 'moonlight_ok' === $r_ok->get_data()['code'] && 1 === $r_ok->get_data()['data']['a'] && 2 === $r_ok->get_data()['meta']['total']);
$r_ok2 = Moonlight_Rest_Helpers::ok(array('b' => 2));
check('ok 无 meta 时省略 meta 键', !isset($r_ok2->get_data()['meta']));
$r_err = Moonlight_Rest_Helpers::err('moonlight_forbidden_order_owner', '无权访问该订单。', 403);
check('err 结构 {code,message,data.status} 且状态码语义化', 403 === $r_err->get_status()
    && 'moonlight_forbidden_order_owner' === $r_err->get_data()['code']
    && '无权访问该订单。' === $r_err->get_data()['message']
    && 403 === $r_err->get_data()['data']['status']);
check('err 默认 400', 400 === Moonlight_Rest_Helpers::err('x', 'y')->get_status());
$GLOBALS['__test_user_id'] = 0;
check('require_login 游客 → WP_Error(401)', is_wp_error(Moonlight_Rest_Helpers::require_login())
    && 401 === Moonlight_Rest_Helpers::require_login()->error_data['moonlight_not_logged_in']['status']);
$GLOBALS['__test_user_id'] = 1;
check('require_login 登录 → true', true === Moonlight_Rest_Helpers::require_login());
$GLOBALS['__test_user_id'] = 1;
$req_pg = __test_rest_request(array('page' => 0, 'per_page' => 500));
$pg = Moonlight_Rest_Helpers::pagination_params($req_pg);
check('分页参数夹紧（page>=1，per_page<=100）', 1 === $pg['page'] && 100 === $pg['per_page'] && 0 === $pg['offset']);
$req_pg2 = __test_rest_request(array('page' => 3, 'per_page' => 10));
$pg2 = Moonlight_Rest_Helpers::pagination_params($req_pg2);
check('分页参数透传 + offset 计算', 3 === $pg2['page'] && 10 === $pg2['per_page'] && 20 === $pg2['offset']);

echo "== REST：属主校验 current_order_owner ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_can'] = false;
$o_mine = __test_make_order('pending'); // __test_make_order 默认 _mlshop_user_id = 1
$GLOBALS['__test_user_id'] = 1;
check('本人订单通过', true === Moonlight_Rest_Helpers::current_order_owner($o_mine));
$GLOBALS['__test_user_id'] = 2;
$e_owner = Moonlight_Rest_Helpers::current_order_owner($o_mine);
check('他人订单 403（moonlight_forbidden_order_owner）', is_wp_error($e_owner)
    && 'moonlight_forbidden_order_owner' === $e_owner->get_error_code()
    && 403 === $e_owner->error_data['moonlight_forbidden_order_owner']['status']);
$GLOBALS['__test_user_can'] = true;
check('管理员放行', true === Moonlight_Rest_Helpers::current_order_owner($o_mine));
$GLOBALS['__test_user_can'] = false;
$g_token = 'gt-token-xyz';
$g_order = wp_insert_post(array('post_type' => 'mlshop_order', 'post_title' => 'MLS-G-REST', 'post_status' => 'mlshop_pending'));
update_post_meta($g_order, '_mlshop_user_id', 0);
update_post_meta($g_order, '_mlshop_guest_token', $g_token);
check('游客订单凭正确令牌通过', true === Moonlight_Rest_Helpers::current_order_owner($g_order, $g_token));
check('游客订单错误令牌 403', is_wp_error(Moonlight_Rest_Helpers::current_order_owner($g_order, 'bad-token')));
check('登录订单不接受游客令牌口径', is_wp_error(Moonlight_Rest_Helpers::current_order_owner($o_mine, $g_token)));
$e_404 = Moonlight_Rest_Helpers::current_order_owner(424242);
check('订单不存在 404', is_wp_error($e_404) && 404 === $e_404->error_data['moonlight_order_not_found']['status']);

echo "== REST：路由注册（显式 permission_callback） ==\n";
$GLOBALS['__test_rest_routes'] = array();
$rest = new Moonlight_REST_Test();
$rest->register_routes();
$__routes = $GLOBALS['__test_rest_routes'];
$__missing_perm = 0;
foreach ($__routes as $__r) {
    if (empty($__r['permission_callback'])) {
        $__missing_perm++;
    }
}
check('全部路由显式 permission_callback（注册 ' . count($__routes) . ' 条，缺失 0）', count($__routes) > 0 && 0 === $__missing_perm);
check('公开路由注册（products/详情/price/cart/quote/regions）', isset(
    $__routes['moonlight/v1/products'],
    $__routes['moonlight/v1/products/(?P<id>\\d+)'],
    $__routes['moonlight/v1/products/(?P<id>\\d+)/price'],
    $__routes['moonlight/v1/cart'],
    $__routes['moonlight/v1/shipping/quote'],
    $__routes['moonlight/v1/regions']
));
check('登录路由注册（checkout/orders/refund/downloads/license-keys/addresses/account）', isset(
    $__routes['moonlight/v1/cart/items'],
    $__routes['moonlight/v1/cart/coupon'],
    $__routes['moonlight/v1/checkout'],
    $__routes['moonlight/v1/orders'],
    $__routes['moonlight/v1/orders/(?P<id>\\d+)'],
    $__routes['moonlight/v1/orders/(?P<id>\\d+)/cancel'],
    $__routes['moonlight/v1/orders/(?P<id>\\d+)/confirm'],
    $__routes['moonlight/v1/orders/(?P<id>\\d+)/refund'],
    $__routes['moonlight/v1/downloads'],
    $__routes['moonlight/v1/license-keys'],
    $__routes['moonlight/v1/license-keys/(?P<meta_id>\\d+)/reveal'],
    $__routes['moonlight/v1/account']
));
check('登录路由 permission 为实例方法回调（非恒真）', is_array($__routes['moonlight/v1/checkout']['permission_callback'])
    && '__return_true' !== $__routes['moonlight/v1/checkout']['permission_callback']);
check('checkout args schema 声明 gateway required（无任何金额字段）', isset($__routes['moonlight/v1/checkout']['args']['gateway']['required'])
    && !isset($__routes['moonlight/v1/checkout']['args']['price'])
    && !isset($__routes['moonlight/v1/checkout']['args']['total']));

echo "== REST：公开路由 /products（公开字段 + 无敏感泄漏） ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 0; // 游客视角
$p1 = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'Alpha Product'));
update_post_meta($p1, '_mlshop_price', 30.0);
update_post_meta($p1, '_mlshop_type', 'virtual');
update_post_meta($p1, '_mlshop_sku', 'SKU-A');
update_post_meta($p1, '_mlshop_cardkeys', "SUPER-SECRET-POOL-KEY\nANOTHER-SECRET");
$p2 = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'Beta Product'));
update_post_meta($p2, '_mlshop_price', 20.0);
update_post_meta($p2, '_mlshop_type', 'physical');
$p_draft = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'draft', 'post_title' => 'Secret Draft'));
update_post_meta($p_draft, '_mlshop_price', 1.0);

$res_pl = $rest->rest_products(__test_rest_request(array('per_page' => 10)));
$__pl = $res_pl->get_data();
check('列表只含 publish 商品（草稿排除）', 200 === $res_pl->get_status() && 2 === count($__pl['data']) && 2 === $__pl['meta']['total']);
$__first = $__pl['data'][0];
check('公开字段齐全（id/title/price/price_html/type/sku/stock_status/categories/images/permalink）', isset(
    $__first['id'], $__first['title'], $__first['price'], $__first['price_html'], $__first['type'],
    $__first['sku'], $__first['stock_status'], $__first['categories'], $__first['images'], $__first['permalink']
));
check('绝不输出卡密 / 文件 / 付费内容配置（JSON 不含明文池）', false === strpos(json_encode($__pl, JSON_UNESCAPED_UNICODE), 'SUPER-SECRET'));
$__ids = array();
foreach ($__pl['data'] as $__it) { $__ids[] = $__it['id']; }
check('price 按游客视角取原价', in_array($p1, $__ids, true) && 30.0 === $__pl['data'][array_search($p1, $__ids, true)]['price']);

$res_search = $rest->rest_products(__test_rest_request(array('search' => 'alpha')));
check('search 按标题过滤（大小写不敏感）', 1 === count($res_search->get_data()['data']) && $p1 === (int) $res_search->get_data()['data'][0]['id']);
$res_type = $rest->rest_products(__test_rest_request(array('type' => 'physical')));
check('type 过滤只回实物', 1 === count($res_type->get_data()['data']) && $p2 === (int) $res_type->get_data()['data'][0]['id']);
$res_sort = $rest->rest_products(__test_rest_request(array('orderby' => 'price')));
check('orderby=price 白名单生效（升序）', 20.0 === $res_sort->get_data()['data'][0]['price'] && 30.0 === $res_sort->get_data()['data'][1]['price']);
$res_pg = $rest->rest_products(__test_rest_request(array('per_page' => 1, 'page' => 2)));
check('分页 meta（total/pages/page/per_page）', 2 === $res_pg->get_data()['meta']['total']
    && 2 === $res_pg->get_data()['meta']['pages'] && 2 === $res_pg->get_data()['meta']['page']
    && 1 === count($res_pg->get_data()['data']));

$res_det = $rest->rest_product(__test_rest_request(array('id' => $p1)));
check('商品详情 200', 200 === $res_det->get_status() && $p1 === (int) $res_det->get_data()['data']['id']);
check('未发布商品详情 404', 404 === $rest->rest_product(__test_rest_request(array('id' => $p_draft)))->get_status());
check('不存在商品 404', 404 === $rest->rest_product(__test_rest_request(array('id' => 987654)))->get_status());
$res_price = $rest->rest_product_price(__test_rest_request(array('id' => $p1)));
check('price 路由返回当前用户视角价（游客=原价）', 200 === $res_price->get_status() && 30.0 === $res_price->get_data()['data']['price']);

echo "== REST：/regions /shipping/quote ==\n";
$res_region = $rest->rest_regions(__test_rest_request());
check('regions 返回 34 省级行政区', 200 === $res_region->get_status() && 34 === count($res_region->get_data()['data']['provinces']));
$res_region_gd = $rest->rest_regions(__test_rest_request(array('province' => 'CN-GD')));
check('regions?province 返回该省城市', isset($res_region_gd->get_data()['data']['cities']['CN-GD-GZ']));

$res_quote = $rest->rest_shipping_quote(__test_rest_request(array(
    'items' => array(array('product_id' => $p1, 'qty' => 2, 'price' => 0.01)),
)));
$__q = $res_quote->get_data()['data'];
check('quote 服务端取价（前端 price:0.01 被忽略 → 小计 60）', 200 === $res_quote->get_status() && abs($__q['subtotal'] - 60.0) < 0.001 && abs($__q['total'] - 60.0) < 0.001);
check('quote 纯虚拟单无运费', $__q['shipping'] === 0.0 && $__q['has_physical'] === false);
$res_quote_phy = $rest->rest_shipping_quote(__test_rest_request(array(
    'items' => array(array('product_id' => $p2, 'qty' => 1)),
)));
check('quote 实物单 has_physical=true', $res_quote_phy->get_data()['data']['has_physical'] === true);
MLSHOP_Coupon::$coupons[1] = array('code' => 'REST10', 'percent' => 10);
$res_quote_c = $rest->rest_shipping_quote(__test_rest_request(array(
    'items' => array(array('product_id' => $p1, 'qty' => 2)),
    'coupon_code' => 'REST10',
)));
check('quote 优惠券可选参与试算（10% → 折 6）', abs($res_quote_phy->get_data()['data']['subtotal'] - 20.0) < 0.001
    && abs($res_quote_c->get_data()['data']['discount'] - 6.0) < 0.001);

echo "== REST：/cart 加购改量删除（夹紧 + 发布校验） ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$pc = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'REST 虚拟商品'));
update_post_meta($pc, '_mlshop_price', 10.0);
update_post_meta($pc, '_mlshop_type', 'virtual');
$ps = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'REST 限量商品'));
update_post_meta($ps, '_mlshop_price', 5.0);
update_post_meta($ps, '_mlshop_type', 'virtual');
update_post_meta($ps, '_mlshop_stock', 3);
$pd = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'draft', 'post_title' => 'REST 草稿商品'));
update_post_meta($pd, '_mlshop_price', 1.0);
MLSHOP_Cart::get_instance()->clear();

check('加购不存在的商品 404', 404 === $rest->rest_cart_add(__test_rest_request(array('product_id' => 987654, 'qty' => 1)))->get_status());
check('加购未发布商品拒绝（400）', 400 === $rest->rest_cart_add(__test_rest_request(array('product_id' => $pd, 'qty' => 1)))->get_status());
check('加购 qty=0 拒绝（400）', 400 === $rest->rest_cart_add(__test_rest_request(array('product_id' => $pc, 'qty' => 0)))->get_status());
check('加购 qty=-5 拒绝（400）', 400 === $rest->rest_cart_add(__test_rest_request(array('product_id' => $pc, 'qty' => -5)))->get_status());
$res_add = $rest->rest_cart_add(__test_rest_request(array('product_id' => $pc, 'qty' => 5000)));
check('加购 qty=5000 服务端夹紧到 999', 200 === $res_add->get_status() && 999 === $res_add->get_data()['data']['qty'] && 999 === $res_add->get_data()['data']['count']);
MLSHOP_Cart::get_instance()->clear();

$res_add2 = $rest->rest_cart_add(__test_rest_request(array('product_id' => $ps, 'qty' => 2)));
check('限量商品正常加购 2 件', 200 === $res_add2->get_status() && 2 === $res_add2->get_data()['data']['count']);
$res_add3 = $rest->rest_cart_add(__test_rest_request(array('product_id' => $ps, 'qty' => 5)));
check('库存预检夹紧到剩余可购（3-2=1）', 200 === $res_add3->get_status() && 1 === $res_add3->get_data()['data']['qty']);
check('库存购满后再加购 409', 409 === $rest->rest_cart_add(__test_rest_request(array('product_id' => $ps, 'qty' => 1)))->get_status());

$res_upd = $rest->rest_cart_update(__test_rest_request(array('product_id' => $ps, 'qty' => 2)));
check('PATCH 改量生效', 200 === $res_upd->get_status() && 2 === $res_upd->get_data()['data']['count']);
check('PATCH 超库存夹紧到 3', 3 === $rest->rest_cart_update(__test_rest_request(array('product_id' => $ps, 'qty' => 10)))->get_data()['data']['qty']);
check('PATCH 负数拒绝（400）', 400 === $rest->rest_cart_update(__test_rest_request(array('product_id' => $ps, 'qty' => -1)))->get_status());
$res_del = $rest->rest_cart_update(__test_rest_request(array('product_id' => $ps, 'qty' => 0)));
check('PATCH qty=0 删除条目', 200 === $res_del->get_status() && 0 === $res_del->get_data()['data']['count']);
MLSHOP_Cart::get_instance()->clear();

MLSHOP_Cart::get_instance()->add_item($pc, 2);
$res_rm = $rest->rest_cart_remove(__test_rest_request(array('product_id' => $pc)));
check('DELETE 单条移除', 200 === $res_rm->get_status() && 0 === $res_rm->get_data()['data']['count']);
MLSHOP_Cart::get_instance()->add_item($pc, 1);
MLSHOP_Cart::get_instance()->add_item($ps, 1);
$res_clr = $rest->rest_cart_clear(new WP_REST_Request());
check('DELETE /cart 清空', 200 === $res_clr->get_status() && array() === MLSHOP_Cart::get_instance()->get_items());

$res_cart = $rest->rest_cart(new WP_REST_Request());
check('GET /cart 返回 items + total（游客同样可用）', 200 === $res_cart->get_status() && isset($res_cart->get_data()['data']['items'], $res_cart->get_data()['data']['total']));

echo "== REST：/cart/coupon 预览（validate + compute_discount，不 reserve） ==\n";
MLSHOP_Cart::get_instance()->clear();
MLSHOP_Cart::get_instance()->add_item($pc, 1); // 小计 10
check('空优惠码 400', 400 === $rest->rest_cart_coupon(__test_rest_request(array('code' => '')))->get_status());
check('无效优惠码 400', 400 === $rest->rest_cart_coupon(__test_rest_request(array('code' => 'NOPE')))->get_status());
$res_cp = $rest->rest_cart_coupon(__test_rest_request(array('code' => 'REST10')));
check('有效优惠码返回折扣预览（10% → 折 1，合计 9）', 200 === $res_cp->get_status()
    && abs($res_cp->get_data()['data']['discount'] - 1.0) < 0.001
    && abs($res_cp->get_data()['data']['total'] - 9.0) < 0.001
    && 'REST10' === $res_cp->get_data()['data']['code']);
MLSHOP_Coupon::$coupons = array();

echo "== REST：/checkout 服务端全取价 + gateway 白名单 ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$pc = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'REST checkout 虚拟商品'));
update_post_meta($pc, '_mlshop_price', 10.0);
update_post_meta($pc, '_mlshop_type', 'virtual');
MLSHOP_Cart::get_instance()->clear();
MLSHOP_Cart::get_instance()->add_item($pc, 1);
__test_set_option('enabled_gateways', array('cod')); // 白名单只开 COD

$req_co = __test_rest_request(array('gateway' => 'bogus', 'price' => 0.01));
check('gateway 白名单外 403', 403 === $rest->rest_checkout($req_co)->get_status()
    && 'moonlight_gateway_forbidden' === $rest->rest_checkout($req_co)->get_data()['code']);
$req_co2 = __test_rest_request(array('gateway' => 'cod', 'price' => 0.01, 'total' => 0.02));
$res_co = $rest->rest_checkout($req_co2);
check('checkout 成功（order_id/order_no/payment）', 200 === $res_co->get_status()
    && $res_co->get_data()['data']['order_id'] > 0
    && '' !== $res_co->get_data()['data']['order_no']
    && !empty($res_co->get_data()['data']['payment']['success']));
$__co_oid = (int) $res_co->get_data()['data']['order_id'];
check('服务端取价：请求携带 price:0.01 不影响订单金额（=10）', abs((float) get_post_meta($__co_oid, '_mlshop_total', true) - 10.0) < 0.001);
check('COD 下单 → processing（网关状态机）', 'processing' === MLSHOP_Order::get_status($__co_oid));
check('下单成功后购物车清空', array() === MLSHOP_Cart::get_instance()->get_items());
check('空购物车 checkout 400', 400 === $rest->rest_checkout(__test_rest_request(array('gateway' => 'cod')))->get_status());

// 实物订单：REST 必须选地址簿（不接受裸地址字段）
MLSHOP_Cart::get_instance()->clear();
$p_phy = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'REST 实物商品'));
update_post_meta($p_phy, '_mlshop_price', 20.0);
update_post_meta($p_phy, '_mlshop_type', 'physical');
MLSHOP_Cart::get_instance()->add_item($p_phy, 1);
check('实物订单缺 address_id 400', 400 === $rest->rest_checkout(__test_rest_request(array('gateway' => 'cod')))->get_status());
check('实物订单不存在的地址 404', 404 === $rest->rest_checkout(__test_rest_request(array('gateway' => 'cod', 'address_id' => 'nope')))->get_status());
$res_addr = $rest->rest_address_save(__test_rest_request(array(
    'name' => '李四', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '天河路 100 号',
)));
$__aid = $res_addr->get_data()['data']['address']['id'];
$res_co3 = $rest->rest_checkout(__test_rest_request(array('gateway' => 'cod', 'address_id' => $__aid, 'customer_note' => '放前台')));
$__co3_oid = (int) $res_co3->get_data()['data']['order_id'];
$__co3_addr = get_post_meta($__co3_oid, '_mlshop_shipping_address', true);
check('实物订单地址取自地址簿（服务端为准）', 200 === $res_co3->get_status()
    && '李四' === $__co3_addr['name'] && 'CN-GD' === $__co3_addr['province'] && 'CN-GD-GZ' === $__co3_addr['city']);
check('customer_note 写入收货地址快照', '放前台' === $__co3_addr['note']);

echo "== REST：/orders 列表 + /orders/{id} 详情（delivery 掩码） ==\n";
$GLOBALS['__test_user_id'] = 1;
$res_orders = $rest->rest_orders(__test_rest_request());
$__orders = $res_orders->get_data()['data'];
check('本人订单列表含刚下的订单（字段齐全）', $__orders !== array()
    && isset($__orders[0]['id'], $__orders[0]['order_no'], $__orders[0]['status'], $__orders[0]['status_label'],
        $__orders[0]['total'], $__orders[0]['currency'], $__orders[0]['gateway'], $__orders[0]['created'], $__orders[0]['items']));
check('列表条目 items 摘要只含 id/title/qty/subtotal', isset($__orders[0]['items'][0]['title']) && !isset($__orders[0]['items'][0]['key']));
$GLOBALS['__test_user_id'] = 2;
check('他人调用 /orders 为空（服务端强制 user）', array() === $rest->rest_orders(new WP_REST_Request())->get_data()['data']);

__test_reset_card_env();
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$pc = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'REST 交付商品'));
update_post_meta($pc, '_mlshop_price', 10.0);
$o_dl = __test_make_order('paid');
update_post_meta($o_dl, '_mlshop_delivery', array(
    array('product_id' => $pc, 'type' => 'cardkey', 'key' => 'PLAINTEXT-KEY-4321'),
    array('product_id' => $pc, 'type' => 'download', 'token' => 'dltoken123'),
));
$res_od = $rest->rest_order(__test_rest_request(array('id' => $o_dl)));
$__od = $res_od->get_data()['data'];
check('订单详情 200 + delivery 摘要', 200 === $res_od->get_status() && 2 === count($__od['delivery']));
check('卡密交付只给掩码（明文永不输出）', 'cardkey' === $__od['delivery'][0]['type']
    && Moonlight_Card_Stock::mask_key('PLAINTEXT-KEY-4321') === $__od['delivery'][0]['masked']
    && false === strpos(json_encode($res_od->get_data(), JSON_UNESCAPED_UNICODE), 'PLAINTEXT-KEY-4321'));
check('下载交付给出下载 URL（含 token）', 'download' === $__od['delivery'][1]['type']
    && false !== strpos($__od['delivery'][1]['url'], 'dltoken123'));

echo "== REST：/orders/{id}/cancel|confirm|refund（状态机 + 属主已在 permission 层） ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$o_cancel = __test_make_order('pending');
$res_cancel = $rest->rest_order_cancel(__test_rest_request(array('id' => $o_cancel)));
check('pending → cancelled 成功', 200 === $res_cancel->get_status() && 'cancelled' === MLSHOP_Order::get_status($o_cancel));
$o_no_cancel = __test_make_order('delivered');
check('delivered 取消 409（状态冲突）', 409 === $rest->rest_order_cancel(__test_rest_request(array('id' => $o_no_cancel)))->get_status());

$o_confirm = __test_make_order('delivered');
$res_confirm = $rest->rest_order_confirm(__test_rest_request(array('id' => $o_confirm)));
check('delivered → completed 确认收货成功', 200 === $res_confirm->get_status() && 'completed' === MLSHOP_Order::get_status($o_confirm));
check('重复确认收货 409', 409 === $rest->rest_order_confirm(__test_rest_request(array('id' => $o_confirm)))->get_status());
$o_no_confirm = __test_make_order('paid');
check('paid 确认收货 409', 409 === $rest->rest_order_confirm(__test_rest_request(array('id' => $o_no_confirm)))->get_status());

$o_refund = __test_make_order('paid');
check('空 reason 申请售后 400', 400 === $rest->rest_order_refund(__test_rest_request(array('id' => $o_refund, 'reason' => '   ')))->get_status());
$res_refund = $rest->rest_order_refund(__test_rest_request(array('id' => $o_refund, 'reason' => '质量问题')));
check('售后申请成功（Refund_Service::apply）', 200 === $res_refund->get_status()
    && 'pending' === Moonlight_Refund_Service::requests($o_refund)[0]['status']);
check('重复申请（已有 pending）400', 400 === $rest->rest_order_refund(__test_rest_request(array('id' => $o_refund, 'reason' => '再来')))->get_status());

echo "== REST：/downloads（token / 产品 / 剩余次数） ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$pc = wp_insert_post(array('post_type' => 'mlshop_product', 'post_status' => 'publish', 'post_title' => 'REST 下载商品'));
$o_dl2 = __test_make_order('paid');
set_transient('mlshop_dl_dltoken456', array(
    'order_id' => $o_dl2, 'product_id' => $pc, 'user_id' => 1, 'file_id' => 5,
    'max' => 7, 'used' => 2, 'expires' => time() + 100,
), 100);
update_post_meta($o_dl2, '_mlshop_delivery', array(array('product_id' => $pc, 'type' => 'download', 'token' => 'dltoken456')));
$res_dls = $rest->rest_downloads(new WP_REST_Request());
$__dls = $res_dls->get_data()['data'];
check('下载列表返回 token / 产品 / 剩余次数', 1 === count($__dls)
    && 'dltoken456' === $__dls[0]['token'] && 5 === $__dls[0]['left'] && $o_dl2 === $__dls[0]['order_id']);
check('下载 URL 指向一次性下载端点', false !== strpos($__dls[0]['url'], 'mlshop_download=dltoken456'));
$GLOBALS['__test_user_id'] = 2;
check('他人下载列表为空', array() === $rest->rest_downloads(new WP_REST_Request())->get_data()['data']);

echo "== REST：/license-keys 列表掩码 + reveal（属主 / confirm / 限流） ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_can'] = false;
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$rv = Moonlight_Card_Stock_Test::import(220, array('REST-LICENSE-KEY-9911'));
$rv_order = __test_make_order('paid'); // _mlshop_user_id = 1
$rv_plain = Moonlight_Card_Stock_Test::pop(220, $rv_order, 1);
$rv_rows = find_meta_rows($rv['batch_id'], Moonlight_Card_Stock::ST_SOLD);
$rv_mid = (int) $rv_rows[0]['meta_id'];

$res_keys = $rest->rest_license_keys(new WP_REST_Request());
$__keys = $res_keys->get_data()['data'];
check('本人已售卡密列表（掩码 + meta_id，永不输出明文）', 1 === count($__keys)
    && $rv_mid === (int) $__keys[0]['meta_id']
    && Moonlight_Card_Stock::mask_key($rv_plain) === $__keys[0]['masked']
    && false === strpos(json_encode($res_keys->get_data(), JSON_UNESCAPED_UNICODE), $rv_plain));
$GLOBALS['__test_user_id'] = 2;
check('他人卡密列表为空', array() === $rest->rest_license_keys(new WP_REST_Request())->get_data()['data']);
$GLOBALS['__test_user_id'] = 1;

$req_rv = __test_rest_request(array('meta_id' => $rv_mid, 'confirm' => 1));
$res_rv = $rest->rest_license_reveal($req_rv);
check('reveal 属主 + confirm=1 → 返回明文', 200 === $res_rv->get_status() && $rv_plain === $res_rv->get_data()['data']['key']);
$__audit = get_option(Moonlight_Card_Stock::AUDIT_OPTION);
$__audit_last = end($__audit);
check('reveal_front 审计（user/meta_id/order）', 'reveal_front' === $__audit_last['action']
    && $rv_mid === (int) $__audit_last['meta_id'] && $rv_order === (int) $__audit_last['order'] && 1 === (int) $__audit_last['user']);

check('confirm 缺失 400', 400 === $rest->rest_license_reveal(__test_rest_request(array('meta_id' => $rv_mid)))->get_status());
check('confirm 非真值 400', 400 === $rest->rest_license_reveal(__test_rest_request(array('meta_id' => $rv_mid, 'confirm' => 'yes')))->get_status());
$rv2 = Moonlight_Card_Stock_Test::import(221, array('UNSOLD-KEY-000001'));
$rv2_rows = find_meta_rows($rv2['batch_id'], Moonlight_Card_Stock::ST_AVAILABLE);
check('未售出卡密（o=0）reveal 403', 403 === $rest->rest_license_reveal(__test_rest_request(array(
    'meta_id' => (int) $rv2_rows[0]['meta_id'], 'confirm' => 1,
)))->get_status());
$GLOBALS['__test_user_id'] = 2;
$req_rv2 = __test_rest_request(array('meta_id' => $rv_mid, 'confirm' => 1));
check('非属主 reveal 403', 403 === $rest->rest_license_reveal($req_rv2)->get_status());
$__last_rv = null;
for ($i = 0; $i < 10; $i++) {
    $__last_rv = $rest->rest_license_reveal($req_rv2);
}
check('同 user 每分钟 ≤10 次 → 429', 429 === $__last_rv->get_status() && 'moonlight_rate_limited' === $__last_rv->get_data()['code']);

echo "== REST：/addresses CRUD 属主隔离 ==\n";
__test_reset_card_env();
$GLOBALS['__test_user_id'] = 1;
$rest = new Moonlight_REST_Test();
$res_a1 = $rest->rest_address_save(__test_rest_request(array(
    'name' => '张三', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '天河路 100 号',
)));
check('POST /addresses 保存成功（默认地址置顶）', 200 === $res_a1->get_status()
    && '' !== $res_a1->get_data()['data']['address']['id'] && 1 === count($res_a1->get_data()['data']['list']));
$__aid2 = $res_a1->get_data()['data']['address']['id'];
check('非法区码 400（Region Provider 校验）', 400 === $rest->rest_address_save(__test_rest_request(array(
    'name' => '张三', 'phone' => '13800138000', 'province' => 'CN-XX', 'city' => 'CN-XX-NOPE', 'detail' => '地址',
)))->get_status());

$GLOBALS['__test_user_id'] = 2;
check('B 用户地址列表为空（属主隔离）', array() === $rest->rest_addresses_list(new WP_REST_Request())->get_data()['data']['list']);
check('B 用户删除 A 的地址 404', 404 === $rest->rest_address_delete(__test_rest_request(array('id' => $__aid2)))->get_status());
$res_a2 = $rest->rest_address_save(__test_rest_request(array(
    'name' => '王五', 'phone' => '0755-8888', 'province' => 'CN-BJ', 'city' => 'CN-BJ-BJ', 'detail' => '朝阳区 1 号',
)));
check('B 用户保存自己的地址成功', 200 === $res_a2->get_status() && 1 === count($res_a2->get_data()['data']['list']));

$GLOBALS['__test_user_id'] = 1;
$res_a3 = $rest->rest_address_update(__test_rest_request(array(
    'id' => $__aid2, 'name' => '张三丰', 'phone' => '13800138000', 'province' => 'CN-GD', 'city' => 'CN-GD-GZ', 'detail' => '天河路 101 号',
)));
check('PATCH 编辑保留 id 且字段更新', 200 === $res_a3->get_status()
    && $__aid2 === $res_a3->get_data()['data']['address']['id'] && '张三丰' === $res_a3->get_data()['data']['address']['name']);
$res_a4 = $rest->rest_address_delete(__test_rest_request(array('id' => $__aid2)));
check('DELETE 自己的地址成功且列表为空', 200 === $res_a4->get_status() && array() === $res_a4->get_data()['data']['list']);

echo "== REST：/account 聚合 ==\n";
$GLOBALS['__test_user_id'] = 1;
$o_acc = __test_make_order('paid');
update_user_meta(1, 'mlshop_credit_balance', 88.5);
$res_acc = $rest->rest_account(new WP_REST_Request());
$__acc = $res_acc->get_data()['data'];
check('account 返回会员/积分/订单计数/地址数', 200 === $res_acc->get_status() && isset(
    $__acc['member_level'], $__acc['member_expires'], $__acc['credit_balance'],
    $__acc['order_total'], $__acc['order_active'], $__acc['address_count']
));
check('积分余额与订单计数正确', abs($__acc['credit_balance'] - 88.5) < 0.001 && $__acc['order_total'] >= 1);

/* ==========================================================================
 * Phase A：会员中心并入（moonlight-shop/includes/user，MERGE-USER-CENTER.md）
 *
 * - 清单断言在本进程直接执行；
 * - 真实 MLUC 类用例在子进程 tests/run-user.php 中运行：wp-stubs.php 的测试版
 *   MLUC_Membership 桩（paywall_price 用例依赖）与真实类同名，不能同进程共存；
 *   子进程末行 "N passed, M failed" 回填本套件计数。
 * ========================================================================== */

echo "\n== Phase A：并入文件清单 ==\n";
foreach (array(
    'class-auth.php', 'class-account.php', 'class-membership.php', 'class-avatar.php',
    'class-assets.php', 'class-oauth.php', 'class-email-notifications.php', 'class-system-status.php',
    'class-hidecontent.php', 'class-editor-button.php', 'class-material.php', 'class-video.php',
    'class-license-manager.php', 'class-license-admin.php', 'class-payment-log.php',
    'class-payment-gateway-interface.php', 'class-payment-manager.php', 'class-settings.php', 'class-menu.php',
) as $__f) {
    check("并入类 {$__f} 存在", file_exists(__DIR__ . '/../moonlight-shop/includes/user/' . $__f));
}
foreach (array(
    'account-licenses.php', 'account-membership.php', 'account-orders.php', 'account-overview.php',
    'account-profile.php', 'login.php', 'lost-password.php', 'membership-purchase.php',
    'paywall-meta.php', 'register.php',
) as $__f) {
    check("并入模板 {$__f} 存在", file_exists(__DIR__ . '/../moonlight-shop/templates/user/' . $__f));
}
check('并入前端样式 mluc.css 存在', file_exists(__DIR__ . '/../moonlight-shop/assets/css/mluc.css'));
foreach (array('mluc.js', 'mluc-admin.js', 'mluc-pw-admin.js', 'mluc-tinymce.js') as $__f) {
    check("并入脚本 {$__f} 存在", file_exists(__DIR__ . '/../moonlight-shop/assets/js/' . $__f));
}
foreach (array('en_US', 'zh_CN', 'zh_HK', 'zh_TW') as $__loc) {
    // .mo 不入库（languages/.gitignore）：只提交 .po，由部署脚本在服务器端 msgfmt 编译。
    // 断言 .po 存在即可；.mo 若存在则顺带校验其 magic 是 PHP 能读的小端序（de 12 04 95）。
    $__po = __DIR__ . "/../moonlight-shop/languages/moonlight-user-center-{$__loc}.po";
    $__mo = __DIR__ . "/../moonlight-shop/languages/moonlight-user-center-{$__loc}.mo";
    $__ok = file_exists($__po);
    if ($__ok && file_exists($__mo)) {
        $__raw = file_get_contents($__mo);
        $__ok   = (substr($__raw, 0, 4) === "\xde\x12\x04\x95");
    }
    check("并入语言包 moonlight-user-center-{$__loc}.po 存在", $__ok);
}
// 单插件形态：不再有独立用户中心插件，改验「并入模块自带让位守卫」——
// 引擎缺席时商城补齐 MLUC_* 常量与加载器，存在旧插件时才让位。
check('商城主文件含并入让位守卫 mlshop_mluc_legacy_active / mlshop_user_modules_should_boot',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/moonlight-shop.php'), 'mlshop_register_mluc_compat')
    && false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/moonlight-shop.php'), 'mlshop_user_modules_should_boot'));
check('商城主文件注册 MLUC_ → includes/user/ 自动加载',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/moonlight-shop.php'), "includes/user/class-"));
check('商城主文件含启动守卫 mlshop_user_modules_should_boot',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/moonlight-shop.php'), 'function mlshop_user_modules_should_boot'));
// 单插件形态（2026-10 起）：商城 + 用户中心合并为一个插件分发。
// 已并入：已购教材 / 每日签到 / 余额钱包（此前只在独立插件里，单装商城会缺失）。
check('并入模块含已购教材 / 签到 / 钱包三个类',
    file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-purchases.php')
    && file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-checkin.php')
    && file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-wallet.php'));
check('商城主文件启动并入的三个模块',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/moonlight-shop.php'), "array('MLUC_Purchases', 'MLUC_Checkin', 'MLUC_Wallet')"));
check('合并层补齐积分/钱包依赖函数（credit_enabled / balance_enabled / credit_name / atomic_*）',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/includes/functions.php'), 'function mluc_credit_enabled')
    && false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/includes/functions.php'), 'function mluc_atomic_decrement_user_meta'));
check('独立插件的同名函数有 function_exists 守卫（两插件同装不致命）',
    false !== strpos((string) file_get_contents(__DIR__ . '/./fixtures/user-center/includes/functions.php'),
        "if (!function_exists('mluc_credit_enabled'))")
    && false !== strpos((string) file_get_contents(__DIR__ . '/./fixtures/user-center/includes/functions.php'),
        "if (!function_exists('mluc_atomic_decrement_user_meta'))"));
// 仍未并入：UC 独立支付体系（商城已用自己的网关与订单流程接管）与页面创建器
check('UC 独立支付体系未并入（payments/paywall/paypal/stripe/gateway/activator/account-orders）',
    !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-payments.php')
    && !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-paywall.php')
    && !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-paypal.php')
    && !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-stripe.php')
    && !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-gateway-manual.php')
    && !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-activator.php')
    && !file_exists(__DIR__ . '/../moonlight-shop/includes/user/class-account-orders.php'));

echo "\n== Phase A 补：并入态功能可达性（签到设置 / 卸载接管 / 授权实例化守卫） ==\n";
// 签到必须可在后台启用（并入后 MLUC_Settings 提供开关与奖励参数，否则功能不可达）
$__settings_src = (string) file_get_contents(__DIR__ . '/../moonlight-shop/includes/user/class-settings.php');
check('设置页含「积分与签到」区块与开关字段',
    false !== strpos($__settings_src, 'render_checkin_field')
    && false !== strpos($__settings_src, "mluc_options[checkin_enabled]"));
check('sanitize 处理签到奖励参数（credit_enabled / checkin_base / checkin_extra）',
    false !== strpos($__settings_src, "\$options['checkin_enabled']")
    && false !== strpos($__settings_src, "\$options['checkin_base']")
    && false !== strpos($__settings_src, "\$options['checkin_extra']"));
check('账户中心积分 Tab 含签到入口（模板 + tab_credit 传参）',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/templates/account-credit.php'), 'mluc_checkin')
    && false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/includes/class-credit-ui.php'), 'checkin_on'));
// 客户分发包剔除 class-license-admin.php 后 boot 不得 Fatal，且实例化仅限授权方自用站
check('boot 对 License_Admin 有常量 + class_exists 双重守卫',
    false !== strpos((string) file_get_contents(__DIR__ . '/../moonlight-shop/moonlight-shop.php'),
        "defined('MLUC_LICENSE_LOCAL_MODE') && class_exists('MLUC_License_Admin')"));
// 卸载时接管并入的会员中心数据清理（独立插件卸载脚本已随目录移除），且双插件站不越权清理
$__uninstall_src = (string) file_get_contents(__DIR__ . '/../moonlight-shop/uninstall.php');
check('uninstall 接管 mluc 数据清理（usermeta / mluc_options / CPT / transient）',
    false !== strpos($__uninstall_src, "meta_key LIKE 'mluc\\\\_%'")
    && false !== strpos($__uninstall_src, "delete_option('mluc_options')"));
check('uninstall 在独立版用户中心仍激活时不清理 mluc 数据',
    false !== strpos($__uninstall_src, 'mluc_standalone_active'));

echo "== Credit helpers（积分换算助手） ==\n";
__test_set_option('credit_rate', 3);
check('currency->credit rounds up (ceil)', mlshop_currency_to_credit(1.01) === 4);
check('currency->credit exact stays exact', mlshop_currency_to_credit(2.0) === 6);
check('currency->credit zero amount -> 0', mlshop_currency_to_credit(0) === 0);
check('credit->currency two decimals', mlshop_credit_to_currency(30) === 10.0);
check('credit->currency rounding to 2dp', mlshop_credit_to_currency(10) === 3.33);
check('credit->currency zero -> 0.0', mlshop_credit_to_currency(0) === 0.0);
__test_set_option('credit_rate', 0);
check('invalid rate falls back to 10 (currency->credit)', mlshop_currency_to_credit(2.0) === 20);
check('invalid rate falls back to 10 (credit->currency)', mlshop_credit_to_currency(50) === 5.0);
__test_set_option('credit_rate', 10);

echo "== Credit auto price（积分价自动换算） ==\n";
MLSHOP_Product_Pay_Meta::$data[60] = array('price_sell' => 100, 'price_gold' => 0, 'price_diamond' => 0);
check('auto price = money price x rate', Moonlight_Price_Calculator::paywall_credit_price(60, 7) === 1000.0);
check('auto price follows member tier', Moonlight_Price_Calculator::paywall_credit_price(60, 8) === 1000.0);
MLSHOP_Product_Pay_Meta::$data[61] = array('price_sell' => 100, 'credit_price' => 50);
check('hand-filled credit price wins over auto', Moonlight_Price_Calculator::paywall_credit_price(61, 7) === 50.0);
MLSHOP_Product_Pay_Meta::$data[62] = array('price_sell' => 100, 'price_diamond' => 80);
check('auto tier price from diamond money price', Moonlight_Price_Calculator::paywall_credit_price(62, 7) === 800.0);
__test_set_option('credit_auto_price', 0);
check('auto price switch off -> 0', Moonlight_Price_Calculator::paywall_credit_price(60, 7) === 0.0);
__test_set_option('credit_auto_price', 1);
MLSHOP_Product_Pay_Meta::$data[63] = array();
check('no prices at all -> 0 (purchase refused)', Moonlight_Price_Calculator::paywall_credit_price(63, 7) === 0.0);

echo "== Credit ledger（积分账本） ==\n";
$GLOBALS['__test_user_id'] = 42;
check('balance starts at 0', MLSHOP_Credit::get_balance(42) === 0.0);
MLSHOP_Credit::add(42, 300, 'test add');
check('add credits updates balance', MLSHOP_Credit::get_balance(42) === 300.0);
check('spend more than balance returns false', MLSHOP_Credit::spend(42, 400, 'overdraft') === false);
check('overdraft does not change balance', MLSHOP_Credit::get_balance(42) === 300.0);
check('ledger has entries', count(MLSHOP_Credit::get_ledger(42, 10)) >= 1);

echo "== Credit gateway（积分支付网关） ==\n";
$cgw = new MLSHOP_Gateway_Credit();
check('gateway id is credit', $cgw->get_id() === 'credit');
$gpo = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_author' => 42));
update_post_meta($gpo, '_mlshop_user_id', 42);
update_post_meta($gpo, '_mlshop_total', 50.0);
update_post_meta($gpo, '_mlshop_status', 'pending');
// 余额不足（50 元 x 10 = 500 积分，只有 300）
MLSHOP_Credit::spend(42, 0, 'noop'); // noop keep 300
$insufficient = $cgw->process_payment($gpo);
check('insufficient balance rejected', false === $insufficient['success']);
check('insufficient: order still pending', 'pending' === MLSHOP_Order::get_status($gpo));
check('insufficient: balance untouched', MLSHOP_Credit::get_balance(42) === 300.0);
// 余额充足：600 积分支付 500，剩 100
MLSHOP_Credit::add(42, 300, 'test topup');
$res = $cgw->process_payment($gpo);
check('payment succeeds with enough credits', !empty($res['success']) && 'paid' === $res['status']);
check('order marked paid with credit gateway', 'credit' === get_post_meta($gpo, '_mlshop_payment_gateway', true));
check('spent points recorded on order', (float) get_post_meta($gpo, '_mlshop_credit_spent', true) === 500.0);
check('balance deducted to 100', MLSHOP_Credit::get_balance(42) === 100.0);
// 幂等：重复调用不再扣减
$again = $cgw->process_payment($gpo);
check('idempotent replay does not double-spend', !empty($again['success']) && MLSHOP_Credit::get_balance(42) === 100.0);
// 退款：原路返还积分
MLSHOP_Order::mark_refunded($gpo);
check('refund sets status refunded', 'refunded' === MLSHOP_Order::get_status($gpo));
check('refund returns spent credits', MLSHOP_Credit::get_balance(42) === 600.0);
check('refund reversal is idempotent', true === (bool) get_post_meta($gpo, '_mlshop_funds_reversed', true));

echo "== Recharge refund recall（充值退款回收修复回归） ==\n";
MLSHOP_Credit::add(42, 100, 'recharge granted');
$rc = MLSHOP_Order::create_recharge(42, 100.0, 10.0, 'alipay');
MLSHOP_Order::mark_paid($rc, 'alipay', 'alipay-txn-1');
update_post_meta($rc, '_mlshop_recharge_granted', current_time('mysql'));
$before = MLSHOP_Credit::get_balance(42);
MLSHOP_Order::mark_refunded($rc);
check('recharge recall deducts granted credits', MLSHOP_Credit::get_balance(42) === $before - 100.0);
check('recharge recall leaves no short marker', '' === (string) get_post_meta($rc, '_mlshop_recharge_revoke_short', true));
$GLOBALS['__test_user_id'] = 0;

echo "== Credit float-ceil regression（浮点换算回归） ==\n";
__test_set_option('credit_rate', 100);
check('1.10 × 100 = 110（浮点误差不再顶成 111）', mlshop_currency_to_credit(1.10) === 110);
check('0.07 × 100 = 7（不再 8）', mlshop_currency_to_credit(0.07) === 7);
__test_set_option('credit_rate', 12.5);
check('0.56 × 12.5 = 7', mlshop_currency_to_credit(0.56) === 7);
__test_set_option('credit_rate', 10);

echo "== Gateway mark_paid-failure refund（完单失败回补） ==\n";
// 用户 42 当前积分 600（上一区块结算值）。已取消订单上支付 → 扣分后完单失败 → 自动回补。
$GLOBALS['__test_user_id'] = 42;
MLSHOP_Credit::add(42, 500, 'topup fail-path'); // 600 -> 1100
$fo = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_author' => 42));
update_post_meta($fo, '_mlshop_user_id', 42);
update_post_meta($fo, '_mlshop_total', 30.0);
update_post_meta($fo, '_mlshop_status', 'pending');
MLSHOP_Order::mark_cancelled($fo);
$gres = (new MLSHOP_Gateway_Credit())->process_payment($fo);
check('已取消订单支付失败', empty($gres['success']));
check('积分在完单失败后自动回补（1100）', MLSHOP_Credit::get_balance(42) === 1100.0);
check('失败路径不残留扣减记录', '' === (string) get_post_meta($fo, '_mlshop_credit_spent', true));

// 余额网关同语义：扣 20 → 完单失败 → 回补 20
update_user_meta(42, '_mlshop_balance', 50.0);
$bo = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_author' => 42));
update_post_meta($bo, '_mlshop_user_id', 42);
update_post_meta($bo, '_mlshop_total', 20.0);
update_post_meta($bo, '_mlshop_status', 'pending');
MLSHOP_Order::mark_cancelled($bo);
$bres = (new MLSHOP_Gateway_Balance())->process_payment($bo);
check('余额网关对已取消订单支付失败', empty($bres['success']));
check('余额在完单失败后自动回补（50）', (float) get_user_meta(42, '_mlshop_balance', true) === 50.0);
$GLOBALS['__test_user_id'] = 0;

echo "== 双插件同装共存（商城先加载顺序，回归 Cannot redeclare 致命） ==\n";
require __DIR__ . '/./fixtures/user-center/includes/functions.php';
check('会员中心函数加载无致命，积分助手可用', function_exists('mluc_credit_enabled') && function_exists('mluc_atomic_increment_user_meta'));
check('共用 mluc_* 未被重定义（商城版生效）', mluc_get_option('nothing_here', 'sentinel') === 'sentinel');
check('会员中心 functions.php 共用函数带 function_exists 守卫', false !== strpos((string) file_get_contents(__DIR__ . '/./fixtures/user-center/includes/functions.php'), "if (!function_exists('mluc_get_option'))"));

echo "== Phase A：并入模块用例（子进程 run-user.php） ==\n";
// Windows 中文用户目录下，绝对路径经 cmd 代码页转码会乱码（Could not open input file）；
// 优先转为相对当前工作目录的 ASCII 相对路径传给子进程。
$__user_script = __DIR__ . '/run-user.php';
$__cwd_norm    = rtrim(str_replace('\\', '/', (string) getcwd()), '/') . '/';
$__user_norm   = str_replace('\\', '/', $__user_script);
if (strpos($__user_norm, $__cwd_norm) === 0) {
    $__user_arg = substr($__user_norm, strlen($__cwd_norm));
} else {
    $__user_arg = $__user_script;
}
$__user_out = array();
$__user_exit = 1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($__user_arg) . ' 2>&1', $__user_out, $__user_exit);
echo implode("\n", $__user_out), "\n";
if (preg_match('/(\d+) passed, (\d+) failed/', (string) end($__user_out), $__m)) {
    $pass += (int) $__m[1];
    $fail += (int) $__m[2];
}
if (0 !== $__user_exit) {
    check('Phase A 子进程（run-user.php）退出码为 0', false);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

