<?php
/**
 * 卡密库存服务（加密批次模型）。
 *
 * 存储模型：
 *  - 批次 CPT `mlshop_card_batch`：post_parent=商品 ID，post_status publish=启用 / draft=停用；
 *    meta `_mlshop_batch_note`（备注）、`_mlshop_batch_expires`（整批过期时间戳，0=永不）。
 *  - 卡密本体：批次文章的 postmeta 行，一条卡密一行（postmeta 允许同键多行）：
 *      `_mlshop_card_a` = available，`_mlshop_card_s` = sold，
 *      `_mlshop_card_u` = used，`_mlshop_card_x` = expired/disabled。
 *    meta_value = JSON {"seq","h","e","iv","o","u","t","ex"}：
 *      h = sha256(明文) hex（去重 + 校验）；e = base64(AES-256-CBC 密文)；
 *      iv = 16 字节 hex；o/u/t = 订单/用户/售出时间；ex = 单条过期（0=随批次）。
 *  - 加密 key = hash('sha256', wp_salt('auth') . '|mlshop-card-v1', true)（32 字节原始二进制）。
 *    明文绝不落库。依赖 PHP openssl 扩展（与支付宝网关同一依赖）。
 *
 * 可用计数不维护冗余计数器，直接对 postmeta 做 COUNT。
 *
 * pop 的原子性：先 SELECT 一条 available 行，再以 CAS（UPDATE ... WHERE meta_id=X AND
 * meta_value=旧JSON）认领为 sold；affected=0 表示被并发请求抢走，重读重试（最多 5 次）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Card_Stock
{
    /** 批次 CPT。 */
    const CPT = 'mlshop_card_batch';

    /** postmeta 状态键：available / sold / used / expired。 */
    const ST_AVAILABLE = '_mlshop_card_a';
    const ST_SOLD      = '_mlshop_card_s';
    const ST_USED      = '_mlshop_card_u';
    const ST_EXPIRED   = '_mlshop_card_x';

    /** 全部状态键（批次统计 / 行查询共用）。 */
    const STATUS_KEYS = array(self::ST_AVAILABLE, self::ST_SOLD, self::ST_USED, self::ST_EXPIRED);

    /** 审计日志 option（环形 100 条）。 */
    const AUDIT_OPTION = '_mlshop_card_audit';

    /** 库存预警阈值（pop 后 available 低于该值触发），可被过滤器覆盖。 */
    const LOW_STOCK_THRESHOLD = 10;

    /** 低库存预警防重复 transient 前缀（6 小时内同一商品只发一次邮件）。 */
    const LOW_STOCK_TRANSIENT_PREFIX = 'mlshop_card_low_stock_';

    /* ---------------- 初始化 ---------------- */

    /**
     * 注册钩子（plugins_loaded 调用）。CPT 在 init 注册。
     */
    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_post_type'));
    }

    /**
     * 注册批次 CPT：非公开、无 UI，仅作为卡密批次的存储容器。
     */
    public static function register_post_type()
    {
        if (post_type_exists(self::CPT)) {
            return;
        }
        register_post_type(self::CPT, array(
            'labels'              => array(
                'name'          => __('卡密批次', 'moonlight-shop'),
                'singular_name' => __('卡密批次', 'moonlight-shop'),
            ),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'supports'            => array('title'),
            'capability_type'     => 'post',
        ));
    }

    /* ---------------- 核心 API ---------------- */

    /**
     * 导入一批卡密：新建批次并把每条卡密加密为一行 postmeta。
     *
     * 去重：同明文（按 sha256 比对）在「本批内」与「该商品已有任意状态卡密」中
     * 只保留一条，其余计入 duplicates。全部重复时不建空批次（batch_id=0）。
     *
     * @param int   $product_id 商品 ID。
     * @param array $keys_array 卡密明文数组（每条一项）。
     * @param string $batch_name 批次名（空则用「导入 Ymd-His」）。
     * @param int    $expires    整批过期时间戳（0=永不）。
     * @return array {batch_id:int, imported:int, duplicates:int}
     */
    public static function import($product_id, $keys_array, $batch_name = '', $expires = 0)
    {
        $product_id = (int) $product_id;
        $expires    = (int) $expires;
        if ($product_id <= 0 || !is_array($keys_array)) {
            return array('batch_id' => 0, 'imported' => 0, 'duplicates' => 0);
        }
        if (!function_exists('openssl_encrypt')) {
            // 无 openssl 时拒绝导入（宁可空手也不落明文），交由调用方提示。
            return array('batch_id' => 0, 'imported' => 0, 'duplicates' => 0);
        }

        // 归一化：去空白、去空行。
        $lines = array();
        foreach ((array) $keys_array as $line) {
            $line = trim((string) $line);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }
        if (empty($lines)) {
            return array('batch_id' => 0, 'imported' => 0, 'duplicates' => 0);
        }

        // 既有卡密指纹（该商品全部批次、任意状态）：跨批次去重。
        $existing = array();
        foreach (static::find_all_batches($product_id) as $b) {
            foreach (static::view_rows((int) $b['ID'], 0) as $row) {
                $rec = json_decode((string) $row['meta_value'], true);
                if (is_array($rec) && isset($rec['h'])) {
                    $existing[(string) $rec['h']] = true;
                }
            }
        }

        $duplicates = 0;
        $unique     = array();
        foreach ($lines as $line) {
            $h = hash('sha256', $line);
            if (isset($existing[$h])) {
                $duplicates++;
                continue;
            }
            $existing[$h] = true;
            $unique[]     = $line;
        }

        if (empty($unique)) {
            return array('batch_id' => 0, 'imported' => 0, 'duplicates' => $duplicates);
        }

        $batch_name = trim((string) $batch_name);
        if ('' === $batch_name) {
            $batch_name = '导入 ' . date('Ymd-His');
        }

        $batch_id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_title'  => sanitize_text_field($batch_name),
            'post_status' => 'publish',
            'post_parent' => $product_id,
        ));
        if (!$batch_id || is_wp_error($batch_id)) {
            return array('batch_id' => 0, 'imported' => 0, 'duplicates' => $duplicates);
        }
        update_post_meta($batch_id, '_mlshop_batch_expires', $expires);

        $seq  = 0;
        $done = 0;
        foreach ($unique as $line) {
            $seq++;
            $record = array_merge(array('seq' => $seq), self::encrypt($line), array(
                'o'  => 0,
                'u'  => 0,
                't'  => 0,
                'ex' => 0,
            ));
            $json = wp_json_encode($record);
            if (!is_string($json) || !static::add_card_row($batch_id, self::ST_AVAILABLE, $json)) {
                continue;
            }
            $done++;
        }

        return array('batch_id' => (int) $batch_id, 'imported' => $done, 'duplicates' => $duplicates);
    }

    /**
     * 该商品全部「启用且未过期」批次的 available 总数。
     * 兼容过渡：商品完全没有批次但遗留明文池非空时，按池行数计（迁移完成前不丢单）。
     *
     * @param int $product_id
     * @return int
     */
    public static function available($product_id)
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return 0;
        }
        $all = static::find_all_batches($product_id);
        if (empty($all)) {
            return self::legacy_available($product_id);
        }
        $batch_ids = self::filter_active_batches($all);
        if (empty($batch_ids)) {
            return 0;
        }
        return (int) static::count_rows($batch_ids, self::ST_AVAILABLE);
    }

    /**
     * 原子弹出一条可用卡密并解密返回明文；失败返回 false。
     *
     * @param int $product_id 商品 ID。
     * @param int $order_id   订单 ID（写入售出记录）。
     * @param int $user_id    用户 ID（写入售出记录）。
     * @return string|false
     */
    public static function pop($product_id, $order_id = 0, $user_id = 0)
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return false;
        }

        $all = static::find_all_batches($product_id);
        if (empty($all)) {
            // 迁移完成前的兼容路径：沿用旧明文池 CAS 弹出（明文池耗尽后自然失效）。
            return static::legacy_pool_pop($product_id);
        }
        $batch_ids = self::filter_active_batches($all);
        if (empty($batch_ids)) {
            return false;
        }

        // CAS 循环：并发购买时若目标行被其他请求抢先认领（affected=0），重读重试。
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = static::find_first_available_row($batch_ids);
            if (!$row) {
                return false; // 池空。
            }
            $meta_id = (int) $row['meta_id'];
            $old     = (string) $row['meta_value'];
            $record  = json_decode($old, true);
            if (!is_array($record) || !isset($record['h'], $record['e'], $record['iv'])) {
                // 损坏行：CAS 移到 expired 状态移出可用队列（value 保留供排查），继续试下一条。
                static::move_row($meta_id, $old, self::ST_EXPIRED);
                self::audit($meta_id, 'corrupt', array('product' => $product_id));
                continue;
            }

            // 状态改 sold：meta_key 换 _mlshop_card_s，写入 o/u/t。
            $record['o'] = (int) $order_id;
            $record['u'] = (int) $user_id;
            $record['t'] = time();
            $new_json = wp_json_encode($record);

            // 原子认领：仅当 meta_value 仍等于旧 JSON 时才改为 sold（仿 mlshop_cas_post_meta）。
            if (!static::claim_row($meta_id, $old, $new_json)) {
                continue; // 被并发抢走，重读再试，同一张卡不会发两个人。
            }

            $plain = self::decrypt_record($record);
            if (false === $plain || !hash_equals((string) $record['h'], hash('sha256', $plain))) {
                // 解密失败 / 校验不一致：记审计并继续试下一条（该行已标 sold，不会再次被弹出）。
                self::audit($meta_id, 'corrupt', array('product' => $product_id));
                continue;
            }

            self::after_pop($product_id);
            return $plain;
        }
        return false;
    }

    /**
     * 管理员单条解密（仅 manage_options）。写审计日志（action=reveal）。
     *
     * @param int $meta_id 卡密行 meta_id。
     * @return string|false 明文。
     */
    public static function reveal($meta_id)
    {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $meta_id = (int) $meta_id;
        $row     = static::get_meta_row($meta_id);
        if (!$row || !in_array((string) $row['meta_key'], self::STATUS_KEYS, true)) {
            return false;
        }
        $record = json_decode((string) $row['meta_value'], true);
        if (!is_array($record) || !isset($record['h'], $record['e'], $record['iv'])) {
            return false;
        }
        $plain = self::decrypt_record($record);
        if (false === $plain || !hash_equals((string) $record['h'], hash('sha256', $plain))) {
            return false;
        }
        self::audit($meta_id, 'reveal', array('batch' => (int) $row['post_id']));
        return $plain;
    }

    /**
     * 批次列表（含统计）。
     *
     * @param int $product_id
     * @return array[] {id,name,status,expires,total,available,sold,disabled,date}
     */
    public static function batches($product_id)
    {
        $out = array();
        foreach (static::find_all_batches((int) $product_id) as $b) {
            $counts = static::batch_row_counts((int) $b['ID']);
            $a      = (int) ($counts[self::ST_AVAILABLE] ?? 0);
            $s      = (int) ($counts[self::ST_SOLD] ?? 0);
            $u      = (int) ($counts[self::ST_USED] ?? 0);
            $x      = (int) ($counts[self::ST_EXPIRED] ?? 0);
            $out[]  = array(
                'id'        => (int) $b['ID'],
                'name'      => (string) $b['post_title'],
                'status'    => (string) $b['post_status'],
                'expires'   => (int) get_post_meta((int) $b['ID'], '_mlshop_batch_expires', true),
                'total'     => $a + $s + $u + $x,
                'available' => $a,
                'sold'      => $s + $u, // 已售含已核销（sold + used）。
                'used'      => $u,
                'disabled'  => ('publish' !== (string) $b['post_status']),
                'date'      => (string) $b['post_date'],
            );
        }
        return $out;
    }

    /**
     * 启用 / 停用批次。停用（draft）批次的 available 不再参与 pop 与 available 计数。
     *
     * @param int    $batch_id 批次 ID。
     * @param string $status   publish|draft。
     * @return bool
     */
    public static function set_batch_status($batch_id, $status)
    {
        $batch_id = (int) $batch_id;
        $status   = sanitize_key((string) $status);
        if ($batch_id <= 0 || !in_array($status, array('publish', 'draft'), true)) {
            return false;
        }
        $id = wp_update_post(array('ID' => $batch_id, 'post_status' => $status));
        return $id && !is_wp_error($id);
    }

    /* ---------------- 后台查看 ---------------- */

    /**
     * 批次卡密掩码预览（前 100 条，任意状态，按 meta_id 升序）。
     * 每次调用（= 每次展开）写审计日志（action=mask）。
     *
     * @param int $batch_id 批次 ID。
     * @param int $limit    最多返回条数（默认 100，0=不限）。
     * @return array[]|false {meta_id,status,masked} 列表；无权限返回 false。
     */
    public static function preview($batch_id, $limit = 100)
    {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $batch_id = (int) $batch_id;
        $rows     = static::view_rows($batch_id, (int) $limit);
        $out      = array();
        foreach ($rows as $row) {
            $record = json_decode((string) $row['meta_value'], true);
            $plain  = is_array($record) ? self::decrypt_record($record) : false;
            $out[]  = array(
                'meta_id' => (int) $row['meta_id'],
                'status'  => (string) $row['meta_key'],
                'masked'  => (false === $plain) ? __('（解密失败）', 'moonlight-shop') : self::mask_key($plain),
            );
        }
        self::audit(0, 'mask', array('batch' => $batch_id, 'count' => count($out)));
        return $out;
    }

    /**
     * 掩码：明文前 4 位 + **** + 后 4 位；过短明文（<12 字符）整体打码避免泄漏。
     *
     * @param string $plain
     * @return string
     */
    public static function mask_key($plain)
    {
        $plain = (string) $plain;
        $len   = strlen($plain);
        if (0 === $len) {
            return '';
        }
        if ($len < 12) {
            return str_repeat('•', min($len, 12));
        }
        return substr($plain, 0, 4) . '****' . substr($plain, -4);
    }

    /* ---------------- 审计日志 ---------------- */

    /**
     * 追加一条审计记录（环形 100 条）。
     * 格式：{at,user,meta_id,action,ip} + 可选附加字段（batch/count/product）。
     *
     * @param int    $meta_id 相关卡密行 meta_id（批量掩码预览为 0）。
     * @param string $action  mask|reveal|corrupt。
     * @param array  $extra   附加字段。
     * @return bool
     */
    public static function audit($meta_id, $action, $extra = array())
    {
        $log = get_option(self::AUDIT_OPTION, array());
        if (!is_array($log)) {
            $log = array();
        }
        $entry = array(
            'at'      => current_time('mysql'),
            'user'    => (int) get_current_user_id(),
            'meta_id' => (int) $meta_id,
            'action'  => sanitize_key((string) $action),
            'ip'      => isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9a-fA-F:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '',
        );
        if (is_array($extra) && !empty($extra)) {
            $entry = array_merge($entry, $extra);
        }
        $log[] = $entry;
        // 环形缓冲：只保留最近 100 条。
        return (bool) update_option(self::AUDIT_OPTION, array_slice($log, -100));
    }

    /* ---------------- 加密 ---------------- */

    /**
     * AES-256-CBC 密钥：hash('sha256', wp_salt('auth') . '|mlshop-card-v1', true)。
     *
     * @return string 32 字节原始二进制。
     */
    private static function key()
    {
        static $key = null;
        if (null === $key) {
            $key = hash('sha256', wp_salt('auth') . '|mlshop-card-v1', true);
        }
        return $key;
    }

    /**
     * 加密明文：随机 16 字节 IV，AES-256-CBC，返回 {'h','e','iv'}。
     *
     * @param string $plain
     * @return array
     */
    private static function encrypt($plain)
    {
        $iv = random_bytes(16);
        $e  = openssl_encrypt((string) $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
        return array(
            'h'  => hash('sha256', (string) $plain),
            'e'  => base64_encode((string) $e),
            'iv' => bin2hex($iv),
        );
    }

    /**
     * 解密记录；任何异常返回 false。
     *
     * @param array $record
     * @return string|false
     */
    private static function decrypt_record($record)
    {
        if (!isset($record['e'], $record['iv'])) {
            return false;
        }
        $iv    = @hex2bin((string) $record['iv']);
        $raw   = base64_decode((string) $record['e'], true);
        if (false === $iv || false === $raw || 16 !== strlen($iv)) {
            return false;
        }
        return openssl_decrypt($raw, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv);
    }

    /* ---------------- 内部流程 ---------------- */

    /**
     * 从批次列表（find_all_batches 结果）筛选「启用且未过期」的批次 ID。
     *
     * @param array $all
     * @return int[]
     */
    private static function filter_active_batches($all)
    {
        $now  = time();
        $out  = array();
        foreach ((array) $all as $b) {
            if ('publish' !== (string) $b['post_status']) {
                continue; // 停用批次不参与。
            }
            $expires = (int) get_post_meta((int) $b['ID'], '_mlshop_batch_expires', true);
            if ($expires > 0 && $expires <= $now) {
                continue; // 已过期整批。
            }
            $out[] = (int) $b['ID'];
        }
        return $out;
    }

    /**
     * pop 成功后的低库存预警：available 低于阈值时触发动作 + 邮件站长
     * （transient 6 小时防重复轰炸）。
     *
     * @param int $product_id
     */
    private static function after_pop($product_id)
    {
        $available = self::available($product_id);
        /**
         * 卡密库存预警阈值（available < 阈值时预警；0 = 关闭预警）。
         *
         * @param int  $threshold  默认 10。
         * @param int  $product_id 商品 ID。
         */
        $threshold = (int) apply_filters('moonlight_card_stock_low_threshold', self::LOW_STOCK_THRESHOLD, $product_id);
        if ($threshold <= 0 || $available >= $threshold) {
            return;
        }
        do_action('moonlight_card_stock_low', $product_id, $available);

        $flag = self::LOW_STOCK_TRANSIENT_PREFIX . $product_id;
        if (get_transient($flag)) {
            return; // 6 小时内已提醒过，防重复轰炸。
        }
        set_transient($flag, time(), 6 * HOUR_IN_SECONDS);
        $to      = get_option('admin_email');
        $subject = __('卡密库存预警', 'moonlight-shop');
        $body    = sprintf(
            __('商品「%1$s」卡密库存不足，仅剩 %2$d 条，请及时补充（进入后台 → 商城 → 卡密库存 导入新批次）。', 'moonlight-shop'),
            get_the_title($product_id),
            $available
        );
        wp_mail($to, $subject, $body);
    }

    /* ---------------- 兼容过渡：旧明文池 ---------------- */

    /**
     * 旧明文池剩余条数（仅当商品尚无任何批次时用于兼容计数）。
     *
     * @param int $product_id
     * @return int
     */
    protected static function legacy_available($product_id)
    {
        $pool  = (string) get_post_meta($product_id, '_mlshop_cardkeys', true);
        $lines = array_filter(array_map('trim', explode("\n", $pool)));
        return count($lines);
    }

    /**
     * 旧明文池 CAS 弹出（迁移完成前的兼容发货路径，逻辑与原 pop_cardkey 一致，
     * 但不含 decrease_stock——计数器扣减仍由调用方 MLSHOP_Download 负责）。
     *
     * @param int $product_id
     * @return string|false
     */
    protected static function legacy_pool_pop($product_id)
    {
        for ($i = 0; $i < 3; $i++) {
            $pool  = (string) get_post_meta($product_id, '_mlshop_cardkeys', true);
            $lines = array_filter(array_map('trim', explode("\n", $pool)));
            if (empty($lines)) {
                return false;
            }
            $key         = array_shift($lines);
            $leftover    = implode("\n", $lines);
            if (!mlshop_cas_post_meta($product_id, '_mlshop_cardkeys', $pool, $leftover)) {
                continue; // 期间被并发修改，重读池子再试。
            }
            return $key;
        }
        return false;
    }

    /* ---------------- 数据访问原语（protected static，测试可子类覆盖） ----------------
     *
     * 所有直接触碰 $wpdb 的 SQL 收敛为下列小方法；单元测试通过子类覆盖这些原语
     * 接到行模型桩上，上层业务逻辑（去重 / CAS 重试 / 加解密 / 审计 / 预警）保持同一份代码。
     */

    /**
     * 商品全部批次（任意状态），按 ID 升序。
     *
     * @param int $product_id
     * @return array[] {ID,post_title,post_status,post_date}
     */
    protected static function find_all_batches($product_id)
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_parent = %d
                 ORDER BY ID ASC",
                self::CPT,
                (int) $product_id
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * 统计若干批次中某状态键的行数。
     *
     * @param int[]  $batch_ids
     * @param string $meta_key
     * @return int
     */
    protected static function count_rows($batch_ids, $meta_key)
    {
        global $wpdb;
        $batch_ids = array_map('intval', (array) $batch_ids);
        if (empty($batch_ids)) {
            return 0;
        }
        $ids = implode(',', $batch_ids);
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta}
                 WHERE post_id IN ($ids) AND meta_key = %s",
                $meta_key
            )
        );
    }

    /**
     * 取最早一条 available 行（CAS 认领候选）。
     *
     * @param int[] $batch_ids
     * @return array|null {meta_id, meta_value}
     */
    protected static function find_first_available_row($batch_ids)
    {
        global $wpdb;
        $batch_ids = array_map('intval', (array) $batch_ids);
        if (empty($batch_ids)) {
            return null;
        }
        $ids = implode(',', $batch_ids);
        $row = $wpdb->get_row(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ($ids) AND meta_key = '" . self::ST_AVAILABLE . "'
             ORDER BY meta_id ASC LIMIT 1",
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * 原子认领（CAS）：仅当 meta_value 仍等于 $old 时才把行改为 sold（meta_key 换
     * `_mlshop_card_s`、meta_value 换新 JSON）。并发冲突返回 false。
     *
     * @param int    $meta_id
     * @param string $old_json 期望的当前 meta_value。
     * @param string $new_json 新 meta_value。
     * @return bool
     */
    protected static function claim_row($meta_id, $old_json, $new_json)
    {
        global $wpdb;
        $meta_id = (int) $meta_id;
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta}
                 SET meta_key = %s, meta_value = %s
                 WHERE meta_id = %d AND meta_value = %s",
                self::ST_SOLD,
                (string) $new_json,
                $meta_id,
                (string) $old_json
            )
        );
        if ($affected) {
            $row = static::get_meta_row($meta_id);
            if ($row) {
                wp_cache_delete((int) $row['post_id'], 'post_meta');
            }
        }
        return (bool) $affected;
    }

    /**
     * 损坏行隔离：把指定行移动到目标状态键（不改 meta_value）。
     *
     * @param int    $meta_id
     * @param string $expected 当前 meta_value（CAS 语义，防止并发误移）。
     * @param string $meta_key 目标状态键。
     * @return bool
     */
    protected static function move_row($meta_id, $expected, $meta_key)
    {
        global $wpdb;
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_key = %s
                 WHERE meta_id = %d AND meta_value = %s",
                $meta_key,
                (int) $meta_id,
                (string) $expected
            )
        );
        return (bool) $affected;
    }

    /**
     * 向批次追加一条卡密行（同键多行）。
     *
     * @param int    $batch_id
     * @param string $meta_key
     * @param string $json
     * @return bool
     */
    protected static function add_card_row($batch_id, $meta_key, $json)
    {
        return false !== add_post_meta((int) $batch_id, $meta_key, (string) $json, false);
    }

    /**
     * 按 meta_id 取单行。
     *
     * @param int $meta_id
     * @return array|null {meta_id,post_id,meta_key,meta_value}
     */
    protected static function get_meta_row($meta_id)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d",
                (int) $meta_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * 批次各状态行计数。
     *
     * @param int $batch_id
     * @return array<string,int> meta_key => count
     */
    protected static function batch_row_counts($batch_id)
    {
        global $wpdb;
        $keys = "'" . implode("','", self::STATUS_KEYS) . "'";
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, COUNT(*) AS c FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key IN ($keys)
                 GROUP BY meta_key",
                (int) $batch_id
            ),
            ARRAY_A
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $out[(string) $r['meta_key']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * 批次卡密行（任意状态，meta_id 升序）。
     *
     * @param int $batch_id
     * @param int $limit 0=不限。
     * @return array[] {meta_id,meta_key,meta_value}
     */
    protected static function view_rows($batch_id, $limit)
    {
        global $wpdb;
        $keys  = "'" . implode("','", self::STATUS_KEYS) . "'";
        $sql   = "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
                  WHERE post_id = %d AND meta_key IN ($keys)
                  ORDER BY meta_id ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $rows = $wpdb->get_results($wpdb->prepare($sql, (int) $batch_id, (int) $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare($sql, (int) $batch_id), ARRAY_A);
        }
        return is_array($rows) ? $rows : array();
    }
}
