<?php
/**
 * 卡密管理（独立兑换卡密池）—— 对齐子比「zibll商城中心 → 卡密管理」的模型。
 *
 * 与既有「商品卡密库存」（Moonlight_Card_Stock，发货用、按商品绑批次）是两套并存的能力：
 *   - 商品卡密库存：买家下单后系统自动分配一张卡密（虚拟商品发货），商家不能直接看到明文；
 *   - 本模块的兑换卡密：商家批量生成、**明文入库可查**，用户拿卡号+密码自行兑换
 *     （余额充值 / 会员兑换 / 积分兑换 / 自定义），属于营销与充值工具。
 *
 * 设计取舍（对齐子比截图）：
 *   - 卡密类型：balance 余额充值 / member 会员兑换 / credit 积分兑换 / custom 自定义；
 *   - 添加方式：系统自动生成 / 导入卡密（后台 Tab 切换）；
 *   - 标识：给这批卡密起一个名字，方便后期查找管理（子比命名为 cardpass_时间戳）；
 *   - 面额：单张卡密兑换的额度；
 *   - 高级选项：卡密位数（卡号）与密码位数分别可设，密码位数为 0 即「单密码模式」
 *     （卡号即密码，子比同款）。
 *
 * 存储：自建表 mlshop_card_codes，一行一张卡密（含状态与兑换记录）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLPRO_Card_Codes
{
    const DB_VERSION  = '1.0.0';
    const OPTION_KEY = 'mlshop_card_codes_db_version';
    const TABLE      = 'mlshop_card_codes';

    /** 卡密类型定义（用于后台 Tab 与兑换端） */
    public static function types()
    {
        return array(
            'balance' => array('label' => __('余额充值', 'moonlight-shop'), 'icon' => '💰'),
            'member'  => array('label' => __('会员兑换', 'moonlight-shop'), 'icon' => '👑'),
            'credit'  => array('label' => __('积分兑换', 'moonlight-shop'), 'icon' => '⭐'),
            'custom'  => array('label' => __('自定义卡密', 'moonlight-shop'), 'icon' => '🎁'),
        );
    }

    /**
     * 表名（带前缀）。
     */
    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function init()
    {
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'));
    }

    /**
     * 建表（幂等）。
     */
    public static function maybe_upgrade()
    {
        if (get_option(self::OPTION_KEY) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        // dbDelta 需要特定格式，注意两个空格与 PRIMARY KEY 写法
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'balance',
            batch varchar(100) NOT NULL DEFAULT '',
            code varchar(191) NOT NULL DEFAULT '',
            password varchar(191) NOT NULL DEFAULT '',
            hash char(64) NOT NULL DEFAULT '',
            face_value decimal(14,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'unused',
            used_by bigint(20) unsigned NOT NULL DEFAULT 0,
            used_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY hash (hash),
            KEY type_status (type,status),
            KEY batch (batch),
            KEY created_at (created_at)
        ) {$charset};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        update_option(self::OPTION_KEY, self::DB_VERSION);
    }

    /**
     * 生成标识：cardpass_YYYYmmddHHMMSS（对齐子比命名，方便后期按标识找批次）。
     *
     * @return string
     */
    public static function make_tag()
    {
        return 'cardpass_' . date('YmdHis');
    }

    /**
     * 批量生成卡密。
     *
     * @param array $args {
     *   @type string $type        卡密类型 key
     *   @type int    $count       数量
     *   @type string $batch       标识
     *   @type float  $face_value  面额
     *   @type int    $code_len    卡号位数（0 = 单密码模式）
     *   @type int    $pwd_len     密码位数
     *   @type int    $days        有效期（天），0 = 永久
     *   @type string $charset     字符集（留空用默认）
     * }
     * @return array|WP_Error
     */
    public static function generate($args)
    {
        $type = isset($args['type']) ? sanitize_key($args['type']) : 'balance';
        if (!isset(self::types()[$type])) {
            return new WP_Error('mlshop_cc_type', __('未知的卡密类型。', 'moonlight-shop'));
        }
        if (!function_exists('random_bytes')) {
            return new WP_Error('mlshop_cc_rng', __('服务器缺少 random_bytes，无法安全生成卡密。', 'moonlight-shop'));
        }

        $count      = isset($args['count']) ? (int) $args['count'] : 0;
        $code_len   = isset($args['code_len']) ? (int) $args['code_len'] : 20;
        $pwd_len    = isset($args['pwd_len']) ? (int) $args['pwd_len'] : 35;
        $face_value = isset($args['face_value']) ? (float) $args['face_value'] : 0;
        $batch      = isset($args['batch']) ? sanitize_text_field($args['batch']) : '';
        $days       = isset($args['days']) ? (int) $args['days'] : 0;

        if ($count < 1 || $count > 5000) {
            return new WP_Error('mlshop_cc_count', __('生成数量需在 1 – 5000 之间。', 'moonlight-shop'));
        }
        // 单密码模式：卡号位数为 0
        if ($code_len < 0 || $pwd_len < 4 || $pwd_len > 64) {
            return new WP_Error('mlshop_cc_len', __('密码位数需在 4 – 64 之间。', 'moonlight-shop'));
        }
        if ($code_len < 0 || $code_len > 64) {
            return new WP_Error('mlshop_cc_code_len', __('卡密位数需在 0 – 64 之间（0 表示单密码模式）。', 'moonlight-shop'));
        }
        if ($face_value < 0) {
            return new WP_Error('mlshop_cc_face', __('面额不能为负数。', 'moonlight-shop'));
        }
        if (false !== strpos($batch, 'shipped')) {
            return new WP_Error('mlshop_cc_batch', __('标识中不能包含 shipped 字符（系统已占用）。', 'moonlight-shop'));
        }

        if ('' === trim($batch)) {
            $batch = self::make_tag();
        }

        $set = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        if (!empty($args['charset'])) {
            $custom = str_replace(str_split('0O1lI'), '', (string) $args['charset']);
            $custom = preg_replace('/[^A-Za-z0-9]/', '', $custom);
            $custom = implode('', array_unique(str_split((string) $custom)));
            if (strlen($custom) >= 8) {
                $set = $custom;
            }
        }
        $max = strlen($set) - 1;

        // 已有指纹（防重复）
        $existing = array();
        $rows     = self::db()->get_col("SELECT hash FROM " . self::table());
        foreach ((array) $rows as $h) {
            $existing[(string) $h] = true;
        }

        global $wpdb;
        $now       = current_time('mysql', true);
        $expires   = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS) : null;
        $ok        = 0;
        $collided  = 0;
        $tries     = 0;
        $max_tries = $count * 30;

        while ($ok < $count && $tries < $max_tries) {
            $tries++;
            $code = '';
            for ($i = 0; $i < $code_len; $i++) {
                try {
                    $code .= $set[random_int(0, $max)];
                } catch (Exception $e) {
                    return new WP_Error('mlshop_cc_rng_fail', __('随机源异常，生成已中止。', 'moonlight-shop'));
                }
            }
            $pwd = '';
            for ($i = 0; $i < $pwd_len; $i++) {
                try {
                    $pwd .= $set[random_int(0, $max)];
                } catch (Exception $e) {
                    return new WP_Error('mlshop_cc_rng_fail', __('随机源异常，生成已中止。', 'moonlight-shop'));
                }
            }
            // 单密码模式：卡号留空，密码即凭证
            $hash = hash('sha256', $code . '|' . $pwd);
            if (isset($existing[$hash])) {
                $collided++;
                continue;
            }
            $existing[$hash] = true;
            $wpdb->insert(
                self::table(),
                array(
                    'type'       => $type,
                    'batch'      => $batch,
                    'code'       => $code,
                    'password'   => $pwd,
                    'hash'       => $hash,
                    'face_value' => $face_value,
                    'status'     => 'unused',
                    'used_by'    => 0,
                    'expires_at' => $expires,
                    'created_at' => $now,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s')
            );
            if ($wpdb->insert_id) {
                $ok++;
            }
        }

        if (!$ok) {
            return new WP_Error('mlshop_cc_collision', __('连续碰撞未能生成唯一卡密，请增加位数或更换字符集。', 'moonlight-shop'));
        }

        return array(
            'created'   => $ok,
            'collided'  => $collided,
            'batch'     => $batch,
            'type'      => $type,
            'face_value'=> $face_value,
            'code_len'  => $code_len,
            'pwd_len'   => $pwd_len,
        );
    }

    /**
     * 导入卡密（每行一个，支持「卡号 密码」「卡号,密码」两种分隔）。
     *
     * @param array $args 同 generate()，另需 lines
     * @return array|WP_Error
     */
    public static function import($args)
    {
        $lines = isset($args['lines']) ? (array) $args['lines'] : array();
        $type  = isset($args['type']) ? sanitize_key($args['type']) : 'balance';
        if (!isset(self::types()[$type])) {
            return new WP_Error('mlshop_cc_type', __('未知的卡密类型。', 'moonlight-shop'));
        }
        if (empty($lines)) {
            return new WP_Error('mlshop_cc_empty', __('卡密内容为空，未导入。', 'moonlight-shop'));
        }
        $batch      = !empty($args['batch']) ? sanitize_text_field($args['batch']) : self::make_tag();
        $face_value = isset($args['face_value']) ? (float) $args['face_value'] : 0;
        $days       = isset($args['days']) ? (int) $args['days'] : 0;

        global $wpdb;
        $now     = current_time('mysql', true);
        $expires = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS) : null;

        $existing = array();
        foreach ((array) self::db()->get_col("SELECT hash FROM " . self::table()) as $h) {
            $existing[(string) $h] = true;
        }

        $ok = 0;
        $dup = 0;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                continue;
            }
            $parts = preg_split('/[\s,\t]+/', $line);
            $code  = isset($parts[0]) ? $parts[0] : '';
            $pwd   = isset($parts[1]) ? $parts[1] : '';
            $code  = preg_replace('/[^A-Za-z0-9]/', '', $code);
            $pwd   = preg_replace('/[^A-Za-z0-9]/', '', $pwd);
            if ('' === $code) {
                continue;
            }
            $hash = hash('sha256', $code . '|' . $pwd);
            if (isset($existing[$hash])) {
                $dup++;
                continue;
            }
            $existing[$hash] = true;
            $wpdb->insert(
                self::table(),
                array(
                    'type'       => $type,
                    'batch'      => $batch,
                    'code'       => $code,
                    'password'   => $pwd,
                    'hash'       => $hash,
                    'face_value' => $face_value,
                    'status'     => 'unused',
                    'used_by'    => 0,
                    'expires_at' => $expires,
                    'created_at' => $now,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s')
            );
            if ($wpdb->insert_id) {
                $ok++;
            }
        }
        return array('created' => $ok, 'duplicates' => $dup, 'batch' => $batch);
    }

    /**
     * 统计。
     *
     * @param string $type
     * @return array{total:int,unused:int,used:int}
     */
    public static function stats($type = '')
    {
        $db = self::db();
        $t  = self::table();
        $sql = "SELECT status, COUNT(*) c FROM {$t}";
        $args = array();
        if ($type) {
            $sql .= ' WHERE type = %s';
            $args[] = $type;
        }
        $sql .= ' GROUP BY status';
        $rows = $db->get_results($db->prepare($sql, $args), ARRAY_A);

        $out = array('total' => 0, 'unused' => 0, 'used' => 0, 'expired' => 0);
        foreach ((array) $rows as $r) {
            $out['total'] += (int) $r['c'];
            if (isset($out[$r['status']])) {
                $out[$r['status']] += (int) $r['c'];
            }
        }
        return $out;
    }

    /**
     * 列出卡密（明文，运营后台需要查看与导出）。
     *
     * @param array $args type/batch/status/limit/offset
     * @return array
     */
    public static function list_codes($args = array())
    {
        $db = self::db();
        $t  = self::table();
        $where = array('1=1');
        $params = array();
        if (!empty($args['type'])) {
            $where[] = 'type = %s';
            $params[] = $args['type'];
        }
        if (!empty($args['batch'])) {
            $where[] = 'batch = %s';
            $params[] = $args['batch'];
        }
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        $limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $sql = "SELECT * FROM {$t} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        return (array) $db->get_results($db->prepare($sql, $params), ARRAY_A);
    }

    /**
     * 批次列表。
     */
    public static function batches()
    {
        $db = self::db();
        $t  = self::table();
        return (array) $db->get_results(
            "SELECT batch, type, COUNT(*) total,
                    SUM(CASE WHEN status='unused' THEN 1 ELSE 0 END) unused,
                    MIN(created_at) created_at
             FROM {$t} GROUP BY batch, type ORDER BY created_at DESC LIMIT 100", ARRAY_A
        );
    }

    /**
     * 核销（用户兑换时调用）：校验 → 标记已用 → 返回记录。
     *
     * 用 SQL 条件更新保证并发下同一张卡只会被核销一次。
     *
     * @param string $code
     * @param string $password
     * @param int    $user_id
     * @return array|WP_Error
     */
    public static function redeem($code, $password, $user_id)
    {
        $code = preg_replace('/[^A-Za-z0-9]/', '', (string) $code);
        $pwd  = preg_replace('/[^A-Za-z0-9]/', '', (string) $password);
        if ('' === $code) {
            return new WP_Error('mlshop_cc_input', __('请输入卡号。', 'moonlight-shop'));
        }
        $hash = hash('sha256', $code . '|' . $pwd);

        $db = self::db();
        $t  = self::table();
        $row = $db->get_row($db->prepare("SELECT * FROM {$t} WHERE hash = %s", $hash), ARRAY_A);
        if (!$row) {
            return new WP_Error('mlshop_cc_notfound', __('卡号或密码不正确。', 'moonlight-shop'));
        }
        if ('unused' !== $row['status']) {
            return new WP_Error('mlshop_cc_used', __('该卡密已被使用。', 'moonlight-shop'));
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return new WP_Error('mlshop_cc_expired', __('该卡密已过期。', 'moonlight-shop'));
        }

        $updated = $db->query($db->prepare(
            "UPDATE {$t} SET status='used', used_by=%d, used_at=%s WHERE id=%d AND status='unused'",
            (int) $user_id, current_time('mysql', true), (int) $row['id']
        ));
        if (!$updated) {
            return new WP_Error('mlshop_cc_race', __('该卡密刚刚已被使用，请换一张。', 'moonlight-shop'));
        }
        return $row;
    }

    /**
     * 删批次。
     */
    public static function delete_batch($batch, $type = '')
    {
        $db = self::db();
        $t  = self::table();
        if ($type) {
            return (int) $db->query($db->prepare("DELETE FROM {$t} WHERE batch=%s AND type=%s", $batch, $type));
        }
        return (int) $db->query($db->prepare("DELETE FROM {$t} WHERE batch=%s", $batch));
    }

    /**
     * 数据库句柄。
     */
    protected static function db()
    {
        global $wpdb;
        return $wpdb;
    }
}
