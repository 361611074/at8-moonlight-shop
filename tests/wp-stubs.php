<?php
/**
 * Minimal WordPress function stubs for standalone unit tests (php tests/run.php).
 * Only what the tested code paths touch; NOT a WordPress emulator.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

error_reporting(E_ALL & ~E_DEPRECATED);

class WP_Error
{
    public $errors = array();
    public $error_data = array();
    public function __construct($code = '', $message = '', $data = array())
    {
        if ($code) {
            $this->errors[$code] = array($message);
        }
        if (!empty($data)) {
            $this->error_data[$code] = $data;
        }
    }
    public function get_error_code() { $codes = array_keys($this->errors); return $codes ? $codes[0] : ''; }
    public function get_error_message($code = '') { $codes = array_keys($this->errors); $code = $code ?: $codes[0]; return isset($this->errors[$code][0]) ? $this->errors[$code][0] : ''; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }
function apply_filters($tag, $value) { return $value; } // return first arg (all extra args ignored)
function do_action(...$args) {}
function get_current_user_id() { return 0; }
function get_post($id) { return null; }
function get_the_title($id) { return 'Product ' . (int) $id; }
function current_time($type) { return '2026-09-27 12:00:00'; }
function get_option($key, $default = false) { return $GLOBALS['__test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['__test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['__test_options'][$key]); return true; }
function wp_generate_password($len, $special = true, $extra = true) { return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, $len); }
function add_action(...$args) {}
function wp_count_posts($cpt = null) { $o = new stdClass(); return $o; }
function post_type_exists($t) { return false; }
function get_post_type($id) { return ''; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['__test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['__test_meta'][(int) $id][$key] = $value; return true; }
function wp_insert_post($args) { return 0; }
function wp_cache_delete(...$args) { return true; }

$GLOBALS['__test_options'] = array();
$GLOBALS['__test_meta'] = array();

// ---- Test-scoped plugin stubs (behavior mirrors production semantics) ----

class MLUC_Membership
{
    public static array $levels = array(); // user_id => level
    public static function get_user_level($user_id)
    {
        return self::$levels[(int) $user_id] ?? 'free';
    }
    public static function user_can_access($required, $user_id) { return true; }
    public static function get_level_label($level) { return $level; }
}

class MLSHOP_Product_Pay_Meta
{
    public static array $data = array();
    public static function get($post_id, $key, $default = '')
    {
        return self::$data[(int) $post_id][$key] ?? $default;
    }
}

class MLSHOP_Shipping
{
    public static bool $enabled = true;
    public static float $free_threshold = 400.0;
    public static float $flat_fee = 50.0;
    public static function has_physical($items)
    {
        foreach ((array) $items as $it) {
            if (!isset($it['type']) || 'cardkey' === $it['type']) {
                if (isset($it['type']) && 'cardkey' === $it['type']) { continue; }
                return true; // 未设类型默认按实物（生产语义）
            }
            if ('physical' === $it['type']) { return true; }
        }
        return false;
    }
    public static function enabled() { return self::$enabled; }
    public static function calc($subtotal, $ignore = false)
    {
        if ($subtotal >= self::$free_threshold) { return 0.0; }
        return self::$flat_fee;
    }
}

class MLSHOP_Coupon
{
    public static array $coupons = array(); // id => ['percent'=>x, 'fixed'=>y]
    public static function validate($code, $subtotal, $items)
    {
        foreach (self::$coupons as $id => $c) {
            if (0 === strcasecmp($c['code'], $code)) { return $id; }
        }
        return new WP_Error('invalid', 'bad coupon');
    }
    public static function compute_discount($cid, $subtotal)
    {
        $c = self::$coupons[$cid] ?? null;
        if (!$c) { return 0.0; }
        if (isset($c['percent'])) { return round($subtotal * $c['percent'] / 100, 2); }
        return min((float) $c['fixed'], $subtotal);
    }
    public static function reserve($cid) { return self::$coupons[$cid]['reservable'] ?? true; }
    public static function release($code) { return true; }
}
