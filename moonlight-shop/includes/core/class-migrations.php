<?php
/**
 * 具体迁移步骤（MIGRATION_PLAN.md 执行清单）。所有步骤必须幂等、失败返回 WP_Error。
 *
 * 决策记录（Phase 2）：
 *  - 原 M4「_mlshop_balance 并入积分账本」取消：两个账本单位不同（货币 vs 积分），
 *    正确修法是退款回补到原钱包（见 class-order.php maybe_reverse_funds），余额网关保留独立账本，
 *    Phase 3 补充值入口激活该钱包。因此无卡密/余额数值迁移风险面。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Migrations
{
    const LOG_OPTION = 'moonlight_migration_log';

    /* ---------------- 快照 / 日志 ---------------- */

    /**
     * 追加迁移日志（含计数快照，回滚依据）。
     *
     * @param string $step 步骤标识
     * @param array  $meta 附加信息
     */
    public static function snapshot($step, $meta = array())
    {
        $log   = get_option(self::LOG_OPTION, array());
        $log[] = array(
            'step'       => (string) $step,
            'at'         => current_time('mysql'),
            'counts'     => array(
                'mlshop_orders'   => self::count_cpt_status('mlshop_order'),
                'mluc_orders'     => self::count_mluc_orders(),
                'products'        => self::count_cpt_status('mlshop_product'),
            ),
            'meta'       => $meta,
        );
        // 只保留最近 50 条，防止无限增长
        $log = array_slice($log, -50);
        update_option(self::LOG_OPTION, $log);
    }

    private static function count_cpt_status($cpt, $status = 'publish')
    {
        $counts = wp_count_posts($cpt);
        return ($counts && isset($counts->$status)) ? (int) $counts->$status : 0;
    }

    private static function count_mluc_orders()
    {
        $cpt = wp_count_posts('mluc_order');
        if (!$cpt) {
            return 0;
        }
        $n = 0;
        foreach ((array) $cpt as $status => $count) {
            if (0 === strpos((string) $status, 'mluc_') || 'publish' === $status || 'private' === $status || 'draft' === $status) {
                $n += (int) $count;
            }
        }
        return $n;
    }

    private static function log($step, $message)
    {
        $log   = get_option(self::LOG_OPTION, array());
        $log[] = array('step' => $step, 'at' => current_time('mysql'), 'message' => $message);
        $log   = array_slice($log, -50);
        update_option(self::LOG_OPTION, $log);
    }

    /* ---------------- 2.0.0 初始迁移（M1 + M2 + M3[受门控]） ---------------- */

    /**
     * M1：旧 option 归组到 moonlight_shop_options（只预填新结构，不删旧键）。
     * 幂等：已存在的新结构键不覆盖。
     */
    public static function m1_prime_options()
    {
        $store = get_option(Moonlight_Options::STORE, array());
        $store = is_array($store) ? $store : array();

        $migrated = 0;
        // 旧独立 option：mlshop_$key → 键 $key
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'mlshop\\_%' AND option_name <> 'mlshop_options'"
        );
        if ($rows) {
            foreach ($rows as $row) {
                $key = substr($row->option_name, strlen('mlshop_'));
                if ('' === $key) {
                    continue;
                }
                if (!array_key_exists($key, $store)) {
                    $store[$key] = maybe_unserialize($row->option_value);
                    $migrated++;
                }
            }
        }
        // 旧数组：mlshop_options（页面 ID / default_gateway 等）
        $legacy_array = get_option('mlshop_options', array());
        if (is_array($legacy_array)) {
            foreach ($legacy_array as $key => $value) {
                if (!array_key_exists((string) $key, $store)) {
                    $store[(string) $key] = $value;
                    $migrated++;
                }
            }
        }
        update_option(Moonlight_Options::STORE, $store);
        self::log('m1_prime_options', sprintf('primed %d keys into %s', $migrated, Moonlight_Options::STORE));
        return true;
    }

    /**
     * M2：存量订单回填 _mlshop_order_no（服务端订单号）与 _mlshop_customer（user_id int）。
     * 幂等：已有键的订单跳过；分块循环直到完成。
     */
    public static function m2_backfill_orders()
    {
        global $wpdb;
        $done = 0;
        do {
            $post_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     WHERE p.post_type = 'mlshop_order'
                       AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_mlshop_order_no')
                     LIMIT %d",
                    500
                )
            );
            $chunk = count((array) $post_ids);
            foreach ((array) $post_ids as $oid) {
                $oid = (int) $oid;
                update_post_meta($oid, '_mlshop_order_no', self::generate_order_no());
                $uid = (int) get_post_meta($oid, '_mlshop_user_id', true);
                if ($uid > 0) {
                    update_post_meta($oid, '_mlshop_customer', $uid);
                }
                $done++;
            }
        } while ($chunk >= 500);
        self::log('m2_backfill_orders', sprintf('backfilled %d orders', $done));
        return true;
    }

    /**
     * M3：用户中心订单（mluc_order）复制为商城订单（mlshop_order）。
     * 门控：仅在管理员明确同意（option moonlight_consent_migrate_mluc）后执行；
     * 复制不删除原数据，旧插件可继续工作。
     * 幂等：按 _mluc_migrated_to 标记跳过已复制订单。
     *
     * @return bool|WP_Error true=完成（或未门控跳过）
     */
    public static function m3_migrate_mluc_orders()
    {
        if (!get_option('moonlight_consent_migrate_mluc', 0)) {
            self::log('m3_migrate_mluc_orders', 'skipped (no consent)');
            return true;
        }
        // 注：不再要求 mluc_order CPT 已注册——仅装商城（旧插件已停用）时该 CPT
        // 不存在，但 posts 表里的旧数据仍在，显式状态数组直查即可完成迁移（Phase E）。
        $copied = 0;
        // 分页循环到没有未迁移订单为止（审计 F12：>200 条站点只迁一半的缺陷）。
        // 显式状态数组：仅装商城时 mluc_* 状态未注册，post_status='any' 会漏掉它们。
        $statuses = array('mluc_pending', 'mluc_paid', 'mluc_cancelled', 'publish', 'private', 'draft');
        do {
            $orders = get_posts(array(
                'post_type'      => 'mluc_order',
                'posts_per_page' => 200,
                'post_status'    => $statuses,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'ASC',
                // 已迁移的带标记，meta 查询排除后翻页才不会卡在同一批
                'meta_query'     => array(
                    array(
                        'key'     => '_mluc_migrated_to',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            ));
            $chunk = count((array) $orders);
            foreach ((array) $orders as $oid) {
                $oid = (int) $oid;
                if (get_post_meta($oid, '_mluc_migrated_to', true)) {
                    continue;
                }
                $new_id = self::copy_mluc_order($oid);
                if (is_wp_error($new_id)) {
                    return $new_id;
                }
                if ($new_id > 0) {
                    update_post_meta($oid, '_mluc_migrated_to', $new_id);
                    $copied++;
                }
            }
        } while ($chunk >= 200);
        self::log('m3_migrate_mluc_orders', sprintf('copied %d orders', $copied));
        return true;
    }

    /**
     * 单条 mluc_order → mlshop_order 映射复制（DATABASE.md 2.2 映射表）。
     *
     * @return int|WP_Error 新订单 ID（已迁移过的返回 0）
     */
    public static function copy_mluc_order($old_id)
    {
        $old_id = (int) $old_id;
        $old    = get_post($old_id);
        if (!$old || 'mluc_order' !== $old->post_type) {
            return 0;
        }
        $status  = get_post_meta($old_id, '_mluc_pay_status', true);
        $status  = $status ? (string) $status : 'pending';
        $map     = array('pending' => 'pending', 'paid' => 'completed', 'cancelled' => 'cancelled');
        $status  = isset($map[$status]) ? $map[$status] : 'pending';

        $uid     = (int) get_post_meta($old_id, '_mluc_pay_user', true);
        $gateway = (string) get_post_meta($old_id, '_mluc_pay_gateway', true);
        $price   = (float) get_post_meta($old_id, '_mluc_pay_price', true);
        $level   = (string) get_post_meta($old_id, '_mluc_pay_level', true);
        $post_id = (int) get_post_meta($old_id, '_mluc_pay_post', true);

        $new_id = wp_insert_post(array(
            'post_title'  => 'ML-M-' . date('Ymd', strtotime($old->post_date)) . '-' . wp_generate_password(5, false, false),
            'post_type'   => 'mlshop_order',
            'post_status' => 'mlshop_' . $status,
            'post_author' => $uid,
            'post_date'   => $old->post_date,
        ));
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        $items = array();
        if ($post_id) {
            $items[] = array('id' => $post_id, 'qty' => 1, 'price' => $price, 'title' => get_the_title($post_id));
        } elseif ($level) {
            $items[] = array('title' => sprintf(__('会员升级：%s', 'moonlight-shop'), $level), 'qty' => 1, 'subtotal' => $price);
        }

        update_post_meta($new_id, '_mlshop_user_id', $uid);
        update_post_meta($new_id, '_mlshop_customer', $uid);
        update_post_meta($new_id, '_mlshop_order_no', self::generate_order_no());
        update_post_meta($new_id, '_mlshop_items', $items);
        update_post_meta($new_id, '_mlshop_total', $price);
        update_post_meta($new_id, '_mlshop_gateway', $gateway ? $gateway : 'manual');
        update_post_meta($new_id, '_mlshop_status', $status);
        update_post_meta($new_id, '_mlshop_currency', mlshop_get_option('currency_symbol', 'HK$'));
        update_post_meta($new_id, '_mlshop_created', $old->post_date);
        update_post_meta($new_id, '_mlshop_source', 'mluc_order:' . $old_id);
        // 幂等标记映射
        if (get_post_meta($old_id, '_mluc_pay_granted', true)) {
            update_post_meta($new_id, '_mlshop_membership_granted', '1');
        }
        if (get_post_meta($old_id, '_mluc_pay_refunded', true)) {
            update_post_meta($new_id, '_mlshop_funds_reversed', '1');
        }
        if (get_post_meta($old_id, '_mluc_pay_type', true) === 'paywall' && $post_id) {
            update_post_meta($new_id, '_mlshop_paywall_post', $post_id);
            update_post_meta($new_id, '_mlshop_paywall_granted', '1');
        }
        // 支付日志搬运
        $paylog = get_post_meta($old_id, '_mluc_pay_log', true);
        if ($paylog) {
            update_post_meta($new_id, '_mlshop_payment_log', $paylog);
        }
        return $new_id;
    }

    /**
     * M4：存量明文卡密池（_mlshop_cardkeys，每行一条）导入加密批次模型（Moonlight_Card_Stock）。
     *
     * 幂等：仅处理「meta 非空 且 无 _mlshop_cardkeys_migrated 标记」的商品；
     * 导入完成后写标记并把原 meta 置空（明文不落库）。分块 200 商品/次，循环到完成。
     *
     * @return bool
     */
    public static function m4_migrate_cardkeys()
    {
        // 前置条件：加密依赖 OpenSSL。缺失时中止迁移（失败即停 + 后台修复入口），
        // 绝不允许在无法加密的情况下清空明文池（审计 F1：数据丢失面）。
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            return new WP_Error('moonlight_migration_no_openssl', 'OpenSSL 扩展不可用，卡密加密迁移已中止（明文池保持原样）');
        }
        $done    = 0;
        $imported_total = 0;
        do {
            $post_ids = static::find_unmigrated_cardkey_products(200);
            $chunk    = count((array) $post_ids);
            foreach ((array) $post_ids as $pid) {
                $pid  = (int) $pid;
                $pool = (string) get_post_meta($pid, '_mlshop_cardkeys', true);
                $lines = array_values(array_filter(array_map('trim', explode("\n", $pool))));
                $complete = true;
                $imported = 0;
                if (!empty($lines)) {
                    $res = static::import_cardkeys($pid, $lines, '迁移 ' . date('Ymd-His'));
                    if (is_array($res) && (int) $res['batch_id'] > 0
                        && ((int) $res['imported'] + (int) $res['duplicates']) === count($lines)) {
                        $imported = (int) $res['imported'];
                    } else {
                        // 导入不完整（建批次失败/部分行写入失败）：保留明文池，
                        // 不写迁移标记（下次升级自动重试），绝不丢失存量卡密。
                        $complete = false;
                        self::log('m4_migrate_cardkeys', sprintf('product #%d import incomplete, plaintext kept', $pid));
                    }
                }
                if ($complete) {
                    // 幂等标记 + 明文池置空（明文已全部进入加密批次或池本为空）。
                    update_post_meta($pid, '_mlshop_cardkeys_migrated', 1);
                    update_post_meta($pid, '_mlshop_cardkeys', '');
                    $done++;
                    $imported_total += $imported;
                }
            }
        } while ($chunk >= 200);
        self::log('m4_migrate_cardkeys', sprintf('migrated %d products, imported %d cardkeys', $done, $imported_total));
        return true;
    }

    /**
     * 卡密导入入口（@internal 供单元测试以子类覆盖接行模型桩；
     * 生产路径即 Moonlight_Card_Stock::import，无任何额外逻辑）。
     *
     * @param int    $product_id
     * @param array  $lines
     * @param string $batch_name
     * @return array {batch_id, imported, duplicates}
     */
    protected static function import_cardkeys($product_id, $lines, $batch_name)
    {
        return Moonlight_Card_Stock::import($product_id, $lines, $batch_name);
    }

    /**
     * 查找待迁移商品（存在非空 _mlshop_cardkeys 且无 _mlshop_cardkeys_migrated 标记）。
     * protected static：供单元测试以行模型桩子类覆盖（不触 $wpdb）。
     *
     * @param int $limit 分块大小。
     * @return int[]
     */
    protected static function find_unmigrated_cardkey_products($limit)
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_mlshop_cardkeys' AND m.meta_value <> ''
                 WHERE p.post_type = 'mlshop_product'
                   AND NOT EXISTS (
                       SELECT 1 FROM {$wpdb->postmeta} f
                       WHERE f.post_id = p.ID AND f.meta_key = '_mlshop_cardkeys_migrated'
                   )
                 LIMIT %d",
                (int) $limit
            )
        );
        return array_map('intval', (array) $ids);
    }

    /**
     * 2.0.0 总入口。
     */
    public static function run_initial()
    {
        $r1 = self::m1_prime_options();
        if (is_wp_error($r1)) {
            return $r1;
        }
        $r2 = self::m2_backfill_orders();
        if (is_wp_error($r2)) {
            return $r2;
        }
        $r3 = self::m3_migrate_mluc_orders();
        if (is_wp_error($r3)) {
            return $r3;
        }
        $r4 = self::m4_migrate_cardkeys();
        if (is_wp_error($r4)) {
            return $r4;
        }
        return true;
    }

    /**
     * 服务端订单号：ML + Ymd + 8 位随机 hex（与用户中心 generate_order_no 同型）。
     */
    public static function generate_order_no()
    {
        return 'ML' . date('Ymd') . strtoupper(bin2hex(random_bytes(4)));
    }
}
