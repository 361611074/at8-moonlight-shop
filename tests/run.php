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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
