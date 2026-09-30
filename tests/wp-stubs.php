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
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
// Cookie 常量（MLSHOP_Cart::write 的 setcookie 依赖；CLI 下 setcookie 为无副作用空操作）
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '');
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return false; }
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
// get_option 守卫化：tests/run-user.php（Phase A 子进程）在加载 moonlight-shop.php
// （其 functions.php 的 mluc_* 合并区块探测 active_plugins）之前需先定义本函数。
if (!function_exists('get_option')) {
function get_option($key, $default = false) { return $GLOBALS['__test_options'][$key] ?? $default; }
}
function update_option($key, $value) { $GLOBALS['__test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['__test_options'][$key]); return true; }
function wp_generate_password($len, $special = true, $extra = true) { return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, $len); }
// add_action / add_filter 守卫化：run-user.php 需在加载 moonlight-shop.php（先于本文件）
// 前预定义同签名空桩，避免重复声明致命。
if (!function_exists('add_action')) {
function add_action(...$args) {}
}
if (!function_exists('add_filter')) {
function add_filter(...$args) {}
}
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
function get_userdata($user_id) { return $GLOBALS['__test_users'][(int) $user_id] ?? null; } // 售后邮件「用户行」测试环境无用户表，回退 #id（游客购买用例可注入 __test_users）
function get_bloginfo($show = '') { return 'Test Blog'; }
function wp_mail($to, $subject, $body, $headers = array(), $attachments = array()) {
    $GLOBALS['__test_mails'][] = array('to' => $to, 'subject' => $subject, 'body' => $body);
    $GLOBALS['__test_wp_mail'][] = array('to' => $to, 'subject' => $subject, 'body' => $body);
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

    public function get_var($sql = null)
    {
        $sql = (string) $sql;
        // License get_by_key：SELECT ID FROM posts WHERE post_type = x AND post_title = y AND post_status = 'publish'
        if (preg_match("/SELECT ID FROM `?\w*posts`?\s+WHERE post_type = '((?:[^']|\\')*)'\s+AND post_title = '((?:[^']|\\')*)'\s+AND post_status = 'publish'\s+LIMIT 1/i", $sql, $m)) {
            $type = stripslashes($m[1]);
            $title = stripslashes($m[2]);
            foreach ($GLOBALS['__test_posts'] as $p) {
                if ($p['post_type'] === $type && $p['post_title'] === $title && 'publish' === $p['post_status']) {
                    return (string) $p['ID'];
                }
            }
            return null;
        }
        return null;
    }
    public function get_results($sql = null, $mode = null)
    {
        $sql = (string) $sql;
        // get_user_orders（R1 直连 SQL）：posts⋈postmeta 按用户取订单 ID，
        // 行模型过滤（meta_key/meta_value 精确匹配 + 类型/状态 + post_date 倒序 + LIMIT）。
        // 注意：本桩的正则串用双引号书写，\s \. 等 PHP 不识别保持原样，
        // 但 \' 会被吃成 '——因此一律用 [^']* 形式（测试数据不含引号，语义等价）。
        if (preg_match("/SELECT\s+p\.ID\s+FROM\s+`?\w*posts`?\s+p\s+INNER\s+JOIN\s+`?\w*postmeta`?\s+m\s+ON\s+m\.post_id\s*=\s*p\.ID\s+AND\s+m\.meta_key\s*=\s*'([^']*)'\s+AND\s+m\.meta_value\s*=\s*'([^']*)'\s+WHERE\s+p\.post_type\s*=\s*'([^']*)'\s+AND\s+p\.post_status\s+IN\s*\(([^)]*)\)\s+ORDER\s+BY\s+p\.post_date\s+DESC\s+LIMIT\s+(\d+)/is", $sql, $m)) {
            $mkey   = stripslashes($m[1]);
            $mvalue = stripslashes($m[2]);
            $ptype  = stripslashes($m[3]);
            preg_match_all("/'([^']*)'/", $m[4], $sm);
            $stati = array_map('stripslashes', $sm[1]);
            $limit = (int) $m[5];
            $out = array();
            foreach ($GLOBALS['__test_posts'] as $p) {
                if ($p['post_type'] !== $ptype || !in_array($p['post_status'], $stati, true)) {
                    continue;
                }
                $rows = find_meta_rows((int) $p['ID'], $mkey);
                $match = false;
                foreach ($rows as $r) {
                    if ((string) $r['meta_value'] === $mvalue) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
                $out[] = array('ID' => $p['ID'], 'post_date' => $p['post_date']);
            }
            usort($out, function ($a, $b) { return strcmp($b['post_date'], $a['post_date']); });
            fwrite(STDERR, '[WR] filtered=' . count($out) . ' mkey=' . var_export($mkey, true) . ' mvalue=' . var_export($mvalue, true) . ' ptype=' . var_export($ptype, true) . ' stati=' . json_encode($stati) . ' posts=' . count($GLOBALS['__test_posts']) . "\n");
            $out = array_slice($out, 0, $limit);
            return array_map(function ($r) { return array('ID' => (string) $r['ID']); }, $out);
        }
        return array();
    }
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
        // post_status 支持标量或数组（get_user_orders 等传状态数组）
        if ('any' !== $status) {
            $wanted = is_array($status) ? $status : array($status);
            if (!in_array($p['post_status'], $wanted, true)) { continue; }
        }
        $type_ok = is_array($type) ? in_array($p['post_type'], $type, true) : ($p['post_type'] === $type);
        if (!$type_ok) { continue; }
        if (null !== $parent && (int) $p['post_parent'] !== $parent) { continue; }
        // meta_key/meta_value 精确过滤（get_user_orders 按属主查询依赖）
        if (isset($args['meta_key']) && isset($args['meta_value'])) {
            $rows = find_meta_rows((int) $p['ID'], (string) $args['meta_key']);
            if (empty($rows) || (string) $rows[0]['meta_value'] !== (string) $args['meta_value']) { continue; }
        }
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
    $GLOBALS['__test_admin_menu'] = array('top' => array(), 'sub' => array());

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
    $GLOBALS['__test_is_singular'] = false;
    $GLOBALS['__test_queried_id'] = 0;
    $GLOBALS['__test_http_calls'] = array();
    $GLOBALS['__test_nonce_ok'] = false;
    $GLOBALS['__test_redirects'] = array();
    $GLOBALS['__test_referer'] = '';
    $GLOBALS['__test_admin_menu'] = array('top' => array(), 'sub' => array());
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
// MLUC_Membership 桩守卫化：run-user.php（Phase A 子进程）经商城自动加载器加载
// 真实 includes/user/class-membership.php 后，此桩自动让位，不再重复声明。
if (!class_exists('MLUC_Membership')) {

class MLUC_Membership
{
    public static array $levels = array(); // user_id => level
    public static function get_user_level($user_id)
    {
        return self::$levels[(int) $user_id] ?? 'free';
    }
    public static function get_levels()
    {
        return array('gold' => array('label' => 'Gold'), 'diamond' => array('label' => 'Diamond'));
    }
    public static function user_can_access($required, $user_id) { return true; }
    public static function get_level_label($level) { return $level; }
}

} // end MLUC_Membership stub guard

// MLSHOP_Product_Pay_Meta 桩守卫化（Phase C）：run-user.php 子进程先加载
// moonlight-shop.php（注册商城自动加载器），本桩声明前的 class_exists 会触发
// 自动加载器加载真实类（含 _mluc_pw_* 兼容读取），桩自动让位；
// run.php 进程无商城自动加载器，仍用本桩（paywall_price 用例依赖 $data）。
if (!class_exists('MLSHOP_Product_Pay_Meta')) {

class MLSHOP_Product_Pay_Meta
{
    public static array $data = array();
    public static function get($post_id, $key, $default = '')
    {
        return self::$data[(int) $post_id][$key] ?? $default;
    }
}

} // end MLSHOP_Product_Pay_Meta stub guard

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

/* ---------- 游客购买（guest checkout）测试补充 ---------- */
if (!function_exists('is_email')) {
function is_email($email) { return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email) ? $email : false; }
}
if (!function_exists('sanitize_email')) {
function sanitize_email($email) { $e = trim((string) $email); return preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e) ? $e : ''; }
}
if (!function_exists('wp_unslash')) {
function wp_unslash($v) { return is_array($v) ? array_map('wp_unslash', $v) : stripslashes((string) $v); }
}
if (!function_exists('home_url')) {
function home_url($path = '') { return 'http://example.test' . $path; }
}
if (!function_exists('wp_registration_url')) {
function wp_registration_url() { return 'http://example.test/wp-login.php?action=register'; }
}
if (!function_exists('wp_login_url')) {
function wp_login_url($redirect = '') { return 'http://example.test/wp-login.php'; }
}
if (!function_exists('is_user_logged_in')) {
function is_user_logged_in() { return 0 !== get_current_user_id(); }
}
// ---- 单文章上下文桩（Phase C：hidecontent payshow 单点探测用例）----
// __test_is_singular / __test_queried_id 由用例注入，__test_reset_card_env 归零。
if (!function_exists('is_singular')) {
function is_singular($types = array()) { return !empty($GLOBALS['__test_is_singular']); }
}
if (!function_exists('get_queried_object_id')) {
function get_queried_object_id() { return (int) ($GLOBALS['__test_queried_id'] ?? 0); }
}
if (!function_exists('get_permalink')) {
function get_permalink($post = 0) {
    $post = (int) $post ?: (int) ($GLOBALS['__test_queried_id'] ?? 0);
    return 'http://example.test/?p=' . $post;
}
}
if (!function_exists('shortcode_atts')) {
function shortcode_atts($defaults, $args, $handler = '') {
    $args = (array) $args;
    $out = array();
    foreach ((array) $defaults as $name => $default) {
        $out[$name] = isset($args[$name]) ? $args[$name] : $default;
    }
    return $out;
}
}
if (!function_exists('do_shortcode')) {
function do_shortcode($content) { return $content; } // 用例内容不含短代码，恒等返回即可
}
if (!function_exists('wp_specialchars_decode')) {
function wp_specialchars_decode($s, $q = ENT_QUOTES) { return htmlspecialchars_decode((string) $s, $q); }
}
if (!function_exists('mysql2date')) {
function mysql2date($format, $mysql) { return $mysql; }
}
if (!function_exists('esc_url')) {
function esc_url($url) { return (string) $url; }
}
if (!function_exists('add_query_arg')) {
function add_query_arg(...$args) {
    // 支持 (array, url) 与 (key, value, url) 两种调用形态（WP 语义：值不二次编码）
    if (isset($args[2])) {
        $args[0] = array($args[0] => $args[1]);
        $url = (string) $args[2];
    } else {
        $url = isset($args[1]) ? (string) $args[1] : '';
        if (!is_array($args[0])) { return $url; }
    }
    $parts = array();
    foreach ($args[0] as $k => $v) {
        $parts[] = rawurlencode((string) $k) . '=' . (string) $v; // 值不二次编码（WP 语义）
    }
    $sep = (false === strpos($url, '?')) ? '?' : '&';
    return $url . $sep . implode('&', $parts);
}
}
if (!function_exists('wp_safe_redirect')) {
function wp_safe_redirect($url) { $GLOBALS['__test_redirects'][] = $url; return true; }
}
if (!function_exists('date_i18n')) {
function date_i18n($format, $ts = null) { return date($format, $ts ?: time()); }
}

if (!function_exists('remove_filter')) {
function remove_filter($tag, $cb, $pri = 10) { return true; }
}
if (!function_exists('wp_html_split')) {
function wp_html_split($s) { return array((string) $s); }
}

/* ---------------- REST（微信支付 notify 测试桩） ----------------
 * 最小 WP_REST_Request / WP_REST_Response：header 不区分大小写、
 * get_body / get_body_params / get_params / get_header 与生产语义对齐。
 * 仅在测试环境定义（真实 WP 中使用核心类）。
 */

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        protected $headers = array();
        protected $body = '';
        protected $params = array();

        public function set_header($key, $value)
        {
            $this->headers[strtolower((string) $key)] = (string) $value;
        }

        public function get_header($key)
        {
            $k = strtolower((string) $key);
            return isset($this->headers[$k]) ? $this->headers[$k] : null;
        }

        public function set_body($body)
        {
            $this->body = (string) $body;
        }

        public function get_body()
        {
            return $this->body;
        }

        public function set_param($key, $value)
        {
            $this->params[(string) $key] = $value;
        }

        public function get_param($key)
        {
            $key = (string) $key;
            return isset($this->params[$key]) ? $this->params[$key] : null;
        }

        public function get_params()
        {
            return $this->params;
        }

        public function get_body_params()
        {
            return array();
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = (int) $status;
        }
        public function get_status()
        {
            return $this->status;
        }
        public function get_data()
        {
            return $this->data;
        }
    }
}

if (!function_exists('rest_url')) {
function rest_url($path = '') { return home_url('/wp-json/' . ltrim((string) $path, '/')); }
}
if (!function_exists('register_rest_route')) {
function register_rest_route($ns, $route, $args = array()) {
    $GLOBALS['__test_rest_routes']["{$ns}{$route}"] = $args;
}
}
if (!function_exists('wp_is_mobile')) {
function wp_is_mobile() { return !empty($GLOBALS['__test_is_mobile']); }
}
if (!function_exists('add_shortcode')) {
function add_shortcode($tag, $cb) { $GLOBALS['__test_shortcodes'][(string) $tag] = $cb; return true; }
}
if (!function_exists('shortcode_exists')) {
function shortcode_exists($tag) { return isset($GLOBALS['__test_shortcodes'][(string) $tag]); }
}

/* ---------------- Phase B（设置与页面接管）测试补充 ----------------
 * 后台菜单注册桩：记录 add_menu_page / add_submenu_page 调用，
 * 供 run-user.php 断言 MLUC_Settings 挂载模式（top|submenu）与 License/系统状态同组。 */

if (!isset($GLOBALS['__test_admin_menu'])) {
    $GLOBALS['__test_admin_menu'] = array('top' => array(), 'sub' => array());
}
if (!function_exists('add_menu_page')) {
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null) {
    $GLOBALS['__test_admin_menu']['top'][] = array(
        'slug'     => (string) $menu_slug,
        'title'    => (string) $menu_title,
        'position' => $position,
    );
    return '';
}
}
if (!function_exists('add_submenu_page')) {
function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '') {
    $GLOBALS['__test_admin_menu']['sub'][] = array(
        'parent' => (string) $parent_slug,
        'slug'   => (string) $menu_slug,
        'title'  => (string) $menu_title,
    );
    return '';
}
}

/* ---------------- Phase A（会员中心并入）测试补充 ----------------
 * 供 tests/run-user.php 使用；全部 function_exists / class_exists 守卫，
 * 对 run.php 既有 507 项用例零影响。 */

if (!isset($GLOBALS['__test_shortcodes'])) {
    $GLOBALS['__test_shortcodes'] = array();
}

if (!class_exists('WP_Send_Json_Exception')) {
    /** wp_send_json 桩载体：AJAX 响应在测试中表现为异常抛出。 */
    class WP_Send_Json_Exception extends Exception
    {
        public $payload;
        public $status_code;
        public function __construct($payload, $status_code = 200)
        {
            parent::__construct('wp_send_json');
            $this->payload = $payload;
            $this->status_code = (int) $status_code;
        }
    }
}

if (!function_exists('wp_send_json')) {
    function wp_send_json($response, $status_code = 200)
    {
        throw new WP_Send_Json_Exception($response, $status_code);
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
}

if (!function_exists('sanitize_user')) {
    function sanitize_user($username, $strict = false) { return trim(strip_tags((string) $username)); }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null) { return strlen((string) $s); }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array())
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (!is_array($args)) {
            $args = array();
        }
        return array_merge($defaults, $args);
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value) { return rtrim((string) $value, '/'); }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return basename(dirname((string) $file)) . '/' . basename((string) $file); }
}

if (!function_exists('network_site_url')) {
    function network_site_url($path = '', $scheme = null) { return 'http://example.test/' . ltrim((string) $path, '/'); }
}

if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url($url) { return is_string($url) && preg_match('#^https?://#', (string) $url) ? $url : false; }
}

if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect($location, $default = '')
    {
        $location = trim((string) $location);
        return '' === $location ? $default : $location;
    }
}

if (!function_exists('get_password_reset_key')) {
    function get_password_reset_key($user) { return 'test-reset-key-1234'; }
}

if (!function_exists('wp_logout')) {
    function wp_logout() { $GLOBALS['__test_logouts'][] = get_current_user_id(); }
}

if (!function_exists('wp_set_current_user')) {
    function wp_set_current_user($user_id) { $GLOBALS['__test_user_id'] = (int) $user_id; return $user_id; }
}

if (!function_exists('wp_set_auth_cookie')) {
    function wp_set_auth_cookie($user_id, $remember = false) { $GLOBALS['__test_auth_cookies'][] = array((int) $user_id, (bool) $remember); }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value)
    {
        foreach ((array) ($GLOBALS['__test_users'] ?? array()) as $u) {
            $arr = is_object($u) ? (array) $u : (array) $u;
            if ('id' === (string) $field && (int) ($arr['ID'] ?? 0) === (int) $value) {
                return (object) $arr;
            }
            if ('login' === (string) $field && isset($arr['user_login']) && 0 === strcasecmp((string) $arr['user_login'], (string) $value)) {
                return (object) $arr;
            }
            if ('email' === (string) $field && isset($arr['user_email']) && 0 === strcasecmp((string) $arr['user_email'], (string) $value)) {
                return (object) $arr;
            }
        }
        return false;
    }
}

if (!function_exists('username_exists')) {
    function username_exists($username)
    {
        $u = get_user_by('login', $username);
        return $u ? (int) $u->ID : null;
    }
}

if (!function_exists('email_exists')) {
    function email_exists($email)
    {
        $u = get_user_by('email', $email);
        return $u ? (int) $u->ID : null;
    }
}

if (!function_exists('wp_create_user')) {
    function wp_create_user($username, $password, $email = '')
    {
        if (!isset($GLOBALS['__test_users'])) {
            $GLOBALS['__test_users'] = array();
        }
        if (!isset($GLOBALS['__test_users_next_id'])) {
            $GLOBALS['__test_users_next_id'] = 100;
        }
        $id = $GLOBALS['__test_users_next_id']++;
        $GLOBALS['__test_users'][(int) $id] = (object) array(
            'ID'           => (int) $id,
            'user_login'   => (string) $username,
            'user_pass'    => (string) $password,
            'user_email'   => (string) $email,
            'display_name' => (string) $username,
        );
        return (int) $id;
    }
}

// 测试环境 PHP CLI 可能未启用 mbstring（生产网关代码使用 mb_substr 截断）：
// 字节级截断足够测试语义，guarded 以免与真实扩展冲突。
if (!function_exists('mb_substr')) {
    function mb_substr($str, $start, $length = null, $encoding = null)
    {
        return null === $length ? substr((string) $str, $start) : substr((string) $str, $start, $length);
    }
}

// ---- Phase E 桩：迁移状态页 / 退役提示 ----
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = false)
    {
        return !empty($GLOBALS['__test_nonce_ok']);
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array())
    {
        throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : ''));
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
    {
        return '';
    }
}
if (!function_exists('wp_get_referer')) {
    function wp_get_referer()
    {
        return $GLOBALS['__test_referer'] ?? '';
    }
}
if (!function_exists('esc_sql')) {
    function esc_sql($sql)
    {
        return addslashes((string) $sql);
    }
}
if (!function_exists('wp_list_pluck')) {
    function wp_list_pluck($list, $field, $index_key = null)
    {
        $out = array();
        foreach ((array) $list as $key => $item) {
            $v = is_object($item) ? (isset($item->$field) ? $item->$field : null) : (isset($item[$field]) ? $item[$field] : null);
            if (null !== $index_key) {
                $k = is_object($item) ? (isset($item->$index_key) ? $item->$index_key : null) : (isset($item[$index_key]) ? $item[$index_key] : null);
                $out[$k] = $v;
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }
}
