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

// 插件路径常量（class-shipping.php 显式 require Provider 文件时使用）
if (!defined('MLSHOP_PLUGIN_DIR')) {
    define('MLSHOP_PLUGIN_DIR', dirname(__DIR__) . '/moonlight-shop/');
}
if (!defined('MLSHOP_VERSION')) {
    define('MLSHOP_VERSION', 'test');
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
// 生产语义：current_time('timestamp') 返回本地时间戳（int），'mysql' 返回 Y-m-d H:i:s 串。
// 物流第二批的 auto_complete / 同步节流都基于该语义做时间比较。
function current_time($type) {
    return 'timestamp' === $type ? strtotime('2026-09-27 12:00:00') : '2026-09-27 12:00:00';
}
function get_option($key, $default = false) { return $GLOBALS['__test_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['__test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['__test_options'][$key]); return true; }
function wp_generate_password($len, $special = true, $extra = true) { return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, $len); }
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_count_posts($cpt = null) { $o = new stdClass(); return $o; }
function post_type_exists($t) { return false; }
function register_post_type($t, $args = array()) { $GLOBALS['__test_post_types'][$t] = $args; return null; }
function register_post_status($slug, $args = array()) { $GLOBALS['__test_post_statuses'][(string) $slug] = $args; return true; }
function _n_noop($singular, $plural, $domain = 'default') { return array($singular, $plural); }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p && isset($p->post_title) && '' !== $p->post_title ? $p->post_title : 'Product ' . (int) $id; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_salt($scheme = 'auth') { return 'mlshop-test-salt-fixed-string'; }
function current_user_can($cap, ...$args) { return !empty($GLOBALS['__test_user_can']); }
function wp_cache_delete(...$args) { return true; }
function get_userdata($user_id) { return null; } // 售后邮件「用户行」测试环境无用户表，回退 #id
function get_bloginfo($show = '') { return 'Test Blog'; }
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
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }

/* ---------------- posts（行模型） ---------------- */

/**
 * 最小 $wpdb shim：真实 includes/functions.php（run.php 加载）中的
 * mlshop_cas_post_meta 走 $wpdb->query(prepare(...))，这里把该 CAS UPDATE
 * 映射到行模型；未识别的 SQL 一律返回 0（只读方法返回空）。
 */
class __Test_wpdb
{
    public $rows_affected = 0;
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $usermeta = 'wp_usermeta';
    public $options = 'wp_options';

    public function prepare($sql, ...$args)
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        $sql = (string) $sql;
        // 逐个按占位符在 SQL 中的出现顺序替换（%s/%d/%f 就近匹配）
        foreach ($args as $a) {
            $best = false;
            foreach (array('%s', '%d', '%f') as $ph) {
                $pos = strpos($sql, $ph);
                if (false !== $pos && (false === $best || $pos < $best[0])) {
                    $best = array($pos, $ph);
                }
            }
            if (false === $best) {
                break;
            }
            list($pos, $ph) = $best;
            if ('%s' === $ph) {
                $rep = "'" . addslashes((string) $a) . "'";
            } elseif ('%d' === $ph) {
                $rep = (string) (int) $a;
            } else {
                $rep = (string) (float) $a;
            }
            $sql = substr_replace($sql, $rep, $pos, 2);
        }
        return $sql;
    }

    public function query($sql)
    {
        $sql = (string) $sql;
        // CAS UPDATE postmeta（mlshop_cas_post_meta）：值匹配才更新（行模型）。
        // BINARY 关键字可选（审计 F5 修复后生产 SQL 为 `meta_value = BINARY %s`）。
        if (preg_match("/UPDATE\s+`?\w*postmeta`?\s+SET\s+meta_value\s*=\s*'((?:[^']|\\')*)'\s+WHERE\s+post_id\s*=\s*(\d+)\s+AND\s+meta_key\s*=\s*'((?:[^']|\\')*)'\s+AND\s+meta_value\s*=\s*(?:BINARY\s+)?'((?:[^']|\\')*)'\s*$/i", $sql, $m)) {
            $new  = stripslashes($m[1]);
            $pid  = (int) $m[2];
            $key  = stripslashes($m[3]);
            $old  = stripslashes($m[4]);
            $this->rows_affected = 0;
            foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
                $row = $GLOBALS['__test_meta_rows'][$i];
                if ((int) $row['post_id'] === $pid && $row['meta_key'] === $key && (string) $row['meta_value'] === $old) {
                    $GLOBALS['__test_meta_rows'][$i]['meta_value'] = $new;
                    $this->rows_affected++;
                }
            }
            return $this->rows_affected;
        }
        // 原子算术 UPDATE postmeta（mlshop_atomic_increment/decrement_post_meta，
        // 库存回滚 / 退款回补走这里）：按行模型对当前值做 +/-，条件不满足（扣减透支）不改行。
        // 行缺失时增量视为「新建该行」（对齐生产 UPDATE 0 行 → INSERT 兜底语义）。
        if (preg_match("/UPDATE\s+`?\w*postmeta`?\s+SET\s+meta_value\s*=\s*CAST\(meta_value\s+AS\s+DECIMAL\(20,4\)\)\s*(\+|-)\s*([\d.]+)\s+WHERE\s+post_id\s*=\s*(\d+)\s+AND\s+meta_key\s*=\s*'((?:[^']|\\')*)'(?:\s+AND\s+CAST\(meta_value\s+AS\s+DECIMAL\(20,4\)\)\s*>=\s*([\d.]+))?\s*$/i", $sql, $m)) {
            $op  = $m[1];
            $amt = (float) $m[2];
            $pid = (int) $m[3];
            $key = stripslashes($m[4]);
            $this->rows_affected = 0;
            $rows = find_meta_rows($pid, $key);
            if (empty($rows)) {
                if ('+' === $op) {
                    __test_add_meta_row($pid, $key, (string) $amt);
                    $this->rows_affected = 1;
                }
                return $this->rows_affected;
            }
            foreach ($rows as $row) {
                $cur = (float) $row['meta_value'];
                if ('-' === $op) {
                    if ($cur < $amt) { continue; } // 透支：条件不满足，行不动（rows_affected=0 → 上层返回 false）
                    $new = $cur - $amt;
                } else {
                    $new = $cur + $amt;
                }
                $this->set_meta_row_value((int) $row['meta_id'], (string) $new);
                $this->rows_affected++;
            }
            return $this->rows_affected;
        }
        // 原子算术 UPDATE usermeta（mlshop_atomic_increment/decrement_user_meta，
        // 余额回补 / 积分回收走这里）：单值模型，行缺失时增量直接落值。
        if (preg_match("/UPDATE\s+`?\w*usermeta`?\s+SET\s+meta_value\s*=\s*CAST\(meta_value\s+AS\s+DECIMAL\(20,4\)\)\s*(\+|-)\s*([\d.]+)\s+WHERE\s+user_id\s*=\s*(\d+)\s+AND\s+meta_key\s*=\s*'((?:[^']|\\')*)'(?:\s+AND\s+CAST\(meta_value\s+AS\s+DECIMAL\(20,4\)\)\s*>=\s*([\d.]+))?\s*$/i", $sql, $m)) {
            $op  = $m[1];
            $amt = (float) $m[2];
            $uid = (int) $m[3];
            $key = stripslashes($m[4]);
            $cur = (float) (isset($GLOBALS['__test_user_meta'][$uid][$key]) ? $GLOBALS['__test_user_meta'][$uid][$key] : 0);
            if ('-' === $op) {
                if ($cur < $amt) {
                    $this->rows_affected = 0;
                    return 0;
                }
                $GLOBALS['__test_user_meta'][$uid][$key] = $cur - $amt;
            } else {
                $GLOBALS['__test_user_meta'][$uid][$key] = $cur + $amt;
            }
            $this->rows_affected = 1;
            return 1;
        }
        $this->rows_affected = 0;
        return 0;
    }

    /** 行模型辅助：按 meta_id 改写 meta_value。 */
    private function set_meta_row_value($meta_id, $value)
    {
        foreach (array_keys($GLOBALS['__test_meta_rows']) as $i) {
            if ((int) $GLOBALS['__test_meta_rows'][$i]['meta_id'] === $meta_id) {
                $GLOBALS['__test_meta_rows'][$i]['meta_value'] = $value;
                return true;
            }
        }
        return false;
    }

    public function get_var($sql = null) { return null; }
    public function get_results($sql = null, $mode = null) { return array(); }
    public function get_row($sql = null, $mode = null) { return null; }
    public function get_col($sql = null) { return array(); }
    public function insert($table, $data, $format = array()) { return 1; }
}

$GLOBALS['wpdb'] = new __Test_wpdb();

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
 *
 * 注：mlshop_get_option / mlshop_cas_post_meta 不再在此定义——
 * run.php 在本文件之后 require 真实 includes/functions.php（同名函数语义与生产一致），
 * 避免重复声明冲突。
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

/** 插件自带 CAS 助手：生产实现由 includes/functions.php 提供（run.php 加载），此处不再重复定义。 */

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
    $GLOBALS['__test_user_meta'] = array();
    $GLOBALS['__test_user_can'] = false;
    $GLOBALS['__test_user_id'] = 0;
    $GLOBALS['__test_http_calls'] = array();
    unset($GLOBALS['__test_http_handler']);
    unset($GLOBALS['__test_http_response']);
}

/* ---------------- HTTP（wp_remote_post / wp_remote_get 桩，网关退款参数构造测试） ----------------
 *
 * 记录每次调用（method/url/args）到 __test_http_calls；返回值由
 * __test_http_handler 回调（function ($method, $url, $args) → response array）决定，
 * 未设置 handler 时回退 __test_http_response（默认 200 + '{}'）。
 */

function wp_remote_post($url, $args = array()) { return __test_http('POST', $url, $args); }
function wp_remote_get($url, $args = array()) { return __test_http('GET', $url, $args); }

function __test_http($method, $url, $args)
{
    $GLOBALS['__test_http_calls'][] = array('method' => $method, 'url' => (string) $url, 'args' => $args);
    $handler = isset($GLOBALS['__test_http_handler']) ? $GLOBALS['__test_http_handler'] : null;
    if (is_callable($handler)) {
        return call_user_func($handler, $method, (string) $url, $args);
    }
    return isset($GLOBALS['__test_http_response']) ? $GLOBALS['__test_http_response'] : array('body' => '{}', 'response' => array('code' => 200));
}

function wp_remote_retrieve_response_code($response)
{
    return isset($response['response']['code']) ? (int) $response['response']['code'] : 200;
}

function wp_remote_retrieve_body($response)
{
    return isset($response['body']) ? (string) $response['body'] : '';
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

/* ---------------- usermeta（单值模型，地址簿等用） ---------------- */

$GLOBALS['__test_user_meta'] = array(); // uid => [key => value]

function get_user_meta($user_id, $key = '', $single = false)
{
    $user_id = (int) $user_id;
    if (!isset($GLOBALS['__test_user_meta'][$user_id][$key])) {
        return '';
    }
    return $GLOBALS['__test_user_meta'][$user_id][$key];
}

function update_user_meta($user_id, $meta_key, $meta_value, $prev_value = '')
{
    $GLOBALS['__test_user_meta'][(int) $user_id][$meta_key] = $meta_value;
    return true;
}

function delete_user_meta($user_id, $meta_key, $meta_value = '')
{
    $deleted = isset($GLOBALS['__test_user_meta'][(int) $user_id][$meta_key]);
    unset($GLOBALS['__test_user_meta'][(int) $user_id][$meta_key]);
    return $deleted;
}

/**
 * 插件配置读取：生产实现由 includes/functions.php 提供（run.php 加载），此处不再重复定义。
 *
 * 测试专用：设置商城配置（mlshop_get_option 能读到的独立 option 形态）。
 */
function __test_set_option($key, $value)
{
    $GLOBALS['__test_options']['mlshop_' . $key] = $value;
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
