<?php
/**
 * Minimal WordPress function stubs for standalone unit tests (php tests/run.php).
 * Only what the tested code paths touch; NOT a WordPress emulator.
 *
 * postmeta / posts 采用「行模型」：postmeta 支持同键多行（卡密库存池按行存卡密）、
 * 按 meta_id 定位（Moonlight_Card_Stock 的 CAS 认领按 meta_id + 旧值比对更新）。
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

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
function do_action(...$args) {
    $tag = array_shift($args);
    $GLOBALS['__test_actions'][(string) $tag][] = $args;
}
function get_current_user_id() { return $GLOBALS['__test_user_id'] ?? 0; }
function current_time($type) { return '2026-09-27 12:00:00'; }
function get_option($key, $default = false) { return $GLOBALS['__test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['__test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['__test_options'][$key]); return true; }
function wp_generate_password($len, $special = true, $extra = true) { return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, $len); }
function add_action(...$args) {}
function wp_count_posts($cpt = null) { $o = new stdClass(); return $o; }
function post_type_exists($t) { return false; }
function register_post_type($t, $args = array()) { $GLOBALS['__test_post_types'][$t] = $args; return null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p && isset($p->post_title) && '' !== $p->post_title ? $p->post_title : 'Product ' . (int) $id; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_salt($scheme = 'auth') { return 'mlshop-test-salt-fixed-string'; }
function current_user_can($cap, ...$args) { return !empty($GLOBALS['__test_user_can']); }
function wp_cache_delete(...$args) { return true; }
function wp_mail($to, $subject, $body, $headers = array(), $attachments = array()) {
    $GLOBALS['__test_mails'][] = array('to' => $to, 'subject' => $subject, 'body' => $body);
    return true;
}
function set_transient($key, $value, $ttl = 0) { $GLOBALS['__test_transients'][$key] = array($value, $ttl); return true; }
function get_transient($key) { return $GLOBALS['__test_transients'][$key][0] ?? false; }
function delete_transient($key) { unset($GLOBALS['__test_transients'][$key]); return true; }
function sanitize_text_field($value) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $value))); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function __($text, $domain = 'default') { return $text; }
function _e($text, $domain = 'default') { echo $text; }
function esc_html__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES); }

/* ---------------- posts（行模型） ---------------- */

function wp_insert_post($args, $wp_error = false)
{
    $id = $GLOBALS['__test_posts_next_id']++;
    $GLOBALS['__test_posts'][$id] = array(
        'ID'          => $id,
        'post_title'  => isset($args['post_title']) ? (string) $args['post_title'] : '',
        'post_status' => isset($args['post_status']) ? (string) $args['post_status'] : 'draft',
        'post_type'   => isset($args['post_type']) ? (string) $args['post_type'] : 'post',
        'post_parent' => isset($args['post_parent']) ? (int) $args['post_parent'] : 0,
        'post_date'   => current_time('mysql'),
        'post_content' => isset($args['post_content']) ? (string) $args['post_content'] : '',
    );
    return $id;
}

function wp_update_post($args)
{
    $id = isset($args['ID']) ? (int) $args['ID'] : 0;
    if (!$id || !isset($GLOBALS['__test_posts'][$id])) {
        return 0;
    }
    foreach (array('post_title', 'post_status', 'post_parent', 'post_type', 'post_date', 'post_content') as $f) {
        if (isset($args[$f])) {
            $GLOBALS['__test_posts'][$id][$f] = $args[$f];
        }
    }
    return $id;
}

function get_post($id)
{
    $id = (int) $id;
    if (!$id || !isset($GLOBALS['__test_posts'][$id])) {
        return null;
    }
    return (object) $GLOBALS['__test_posts'][$id]; // 支持 post_parent / post_status / post_title 等属性访问
}

function get_posts($args = array())
{
    $status = isset($args['post_status']) ? $args['post_status'] : 'publish';
    $type   = isset($args['post_type']) ? $args['post_type'] : 'post';
    $parent = array_key_exists('post_parent', $args) ? (int) $args['post_parent'] : null;
    $number = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : (isset($args['numberposts']) ? (int) $args['numberposts'] : 5);
    $out = array();
    foreach ($GLOBALS['__test_posts'] as $p) {
        if ('any' !== $status && $p['post_status'] !== $status) { continue; }
        $type_ok = is_array($type) ? in_array($p['post_type'], $type, true) : ($p['post_type'] === $type);
        if (!$type_ok) { continue; }
        if (null !== $parent && (int) $p['post_parent'] !== $parent) { continue; }
        $out[] = $p;
    }
    usort($out, function ($a, $b) { return $a['ID'] <=> $b['ID']; });
    if ($number > 0) { $out = array_slice($out, 0, $number); }
    if (isset($args['fields']) && 'ids' === $args['fields']) {
        return array_map(function ($p) { return $p['ID']; }, $out);
    }
    return array_map(function ($p) { return (object) $p; }, $out);
}

/* ---------------- postmeta（行模型：同键多行 + meta_id 定位） ----------------
 *
 * 行结构：array('meta_id' => int, 'post_id' => int, 'meta_key' => string, 'meta_value' => mixed)
 */

function __test_add_meta_row($post_id, $key, $value)
{
    $mid = $GLOBALS['__test_meta_next_id']++;
    $GLOBALS['__test_meta_rows'][] = array(
        'meta_id'    => $mid,
        'post_id'    => (int) $post_id,
        'meta_key'   => (string) $key,
        'meta_value' => $value,
    );
    return $mid;
}

/** 测试专用：按 post_id / meta_key 过滤全部行（null = 不过滤该维）。 */
function find_meta_rows($post_id = null, $key = null)
{
    $out = array();
    foreach ($GLOBALS['__test_meta_rows'] as $row) {
        if (null !== $post_id && (int) $row['post_id'] !== (int) $post_id) { continue; }
        if (null !== $key && $row['meta_key'] !== (string) $key) { continue; }
        $out[] = $row;
    }
    return $out;
}

/** 测试专用：按 meta_id 删除行。 */
function delete_meta($meta_id)
{
    foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
        if ((int) $GLOBALS['__test_meta_rows'][$i]['meta_id'] === (int) $meta_id) {
            unset($GLOBALS['__test_meta_rows'][$i]);
            return true;
        }
    }
    return false;
}

function get_post_meta($id, $key, $single = true)
{
    $rows = find_meta_rows((int) $id, $key);
    if (!$rows) {
        return '';
    }
    if ($single) {
        return $rows[0]['meta_value'];
    }
    return array_map(function ($r) { return $r['meta_value']; }, $rows);
}

function update_post_meta($id, $key, $value)
{
    $rows  = find_meta_rows((int) $id, $key);
    $ids   = array_map(function ($r) { return $r['meta_id']; }, $rows);
    $found = false;
    foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
        if (in_array($GLOBALS['__test_meta_rows'][$i]['meta_id'], $ids, true)) {
            $GLOBALS['__test_meta_rows'][$i]['meta_value'] = $value;
            $found = true;
        }
    }
    if (!$found) {
        __test_add_meta_row($id, $key, $value);
    }
    return true;
}

function add_post_meta($id, $key, $value, $unique = false)
{
    if ($unique && find_meta_rows((int) $id, $key)) {
        return false;
    }
    return __test_add_meta_row($id, $key, $value);
}

function delete_post_meta($id, $key, $value = null)
{
    $deleted = false;
    foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
        $row = $GLOBALS['__test_meta_rows'][$i];
        if ((int) $row['post_id'] !== (int) $id || $row['meta_key'] !== (string) $key) { continue; }
        if (null !== $value && $row['meta_value'] !== $value) { continue; }
        unset($GLOBALS['__test_meta_rows'][$i]);
        $deleted = true;
    }
    return $deleted;
}

/** 插件自带 CAS 助手（functions.php）的行模型版：值匹配才更新。 */
function mlshop_cas_post_meta($post_id, $meta_key, $expected, $new_value)
{
    $matched = false;
    foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
        $row = $GLOBALS['__test_meta_rows'][$i];
        if ((int) $row['post_id'] === (int) $post_id
            && $row['meta_key'] === (string) $meta_key
            && (string) $row['meta_value'] === (string) $expected) {
            $GLOBALS['__test_meta_rows'][$i]['meta_value'] = (string) $new_value;
            $matched = true;
        }
    }
    return $matched;
}

$GLOBALS['__test_options'] = array();
$GLOBALS['__test_meta_rows'] = array();
$GLOBALS['__test_meta_next_id'] = 1;
$GLOBALS['__test_posts'] = array();
$GLOBALS['__test_posts_next_id'] = 1;
$GLOBALS['__test_mails'] = array();
$GLOBALS['__test_actions'] = array();
$GLOBALS['__test_transients'] = array();
$GLOBALS['__test_post_types'] = array();

/** 测试专用：重置卡密相关测试环境（选项 / 行 / 文章 / 邮件 / 动作 / transient）。 */
function __test_reset_card_env()
{
    $GLOBALS['__test_options'] = array();
    $GLOBALS['__test_meta_rows'] = array();
    $GLOBALS['__test_meta_next_id'] = 1;
    $GLOBALS['__test_posts'] = array();
    $GLOBALS['__test_posts_next_id'] = 1;
    $GLOBALS['__test_mails'] = array();
    $GLOBALS['__test_actions'] = array();
    $GLOBALS['__test_transients'] = array();
    $GLOBALS['__test_user_can'] = false;
    $GLOBALS['__test_user_id'] = 0;
}

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
