<?php
/**
 * Phase 12 性能基准脚本（在真实 WordPress 环境运行）。
 *
 * 用法一（WP-CLI / CLI，推荐）：
 *   php tests/perf-benchmark.php --products=10000 --orders=100000 --users=10000
 *
 * 用法二（浏览器，需在 wp-config.php 定义 MLSHOP_BENCH_TOKEN）：
 *   https://example.com/?mlshop_bench=<token>&products=1000&orders=10000
 *
 * 脚本会灌入测试数据（商品/用户/订单 post + meta），测量五类查询耗时
 * 与 WordPress 查询计数，结束后删除全部测试数据（前缀 MLSBENCH_）。
 * 仅建议在预发布/压测环境运行。
 *
 * @package Moonlight_Shop
 */

if (PHP_SAPI === 'cli') {
    $args = array();
    foreach (array_slice($argv, 1) as $kv) {
        if (0 === strpos($kv, '--')) {
            $p = explode('=', substr($kv, 2), 2);
            $args[$p[0]] = isset($p[1]) ? (int) $p[1] : 0;
        }
    }
    // CLI：加载 WordPress
    $wp_load = isset($_SERVER['PWD']) ? $_SERVER['PWD'] . '/wp-load.php' : 'wp-load.php';
    if (!file_exists($wp_load)) {
        $wp_load = dirname(__DIR__, 3) . '/wp-load.php'; // tests/ → 插件目录 → wp-content → WP 根
    }
    if (!file_exists($wp_load)) {
        fwrite(STDERR, "找不到 wp-load.php，请在 WordPress 根目录执行，或用 -- 传入路径。\n");
        exit(1);
    }
    require $wp_load;
} else {
    if (!defined('MLSHOP_BENCH_TOKEN') || !isset($_GET['mlshop_bench'])
        || !hash_equals(MLSHOP_BENCH_TOKEN, (string) $_GET['mlshop_bench'])) {
        http_response_code(403);
        exit('forbidden');
    }
    require dirname(__DIR__, 3) . '/wp-load.php';
    $args = array(
        'products' => isset($_GET['products']) ? (int) $_GET['products'] : 1000,
        'orders'   => isset($_GET['orders']) ? (int) $_GET['orders'] : 10000,
        'users'    => isset($_GET['users']) ? (int) $_GET['users'] : 1000,
    );
}

if (!defined('ABSPATH')) {
    exit('需要在 WordPress 环境中运行。');
}

$PRODUCTS = max(1, (int) (isset($args['products']) ? $args['products'] : 1000));
$ORDERS   = max(1, (int) (isset($args['orders']) ? $args['orders'] : 10000));
$USERS    = max(1, (int) (isset($args['users']) ? $args['users'] : 1000));

echo "Moonlight Shop 性能基准 products={$PRODUCTS} orders={$ORDERS} users={$USERS}\n";

$created_products = array();
$created_orders   = array();
$created_users    = array();

function mlbench_qcount()
{
    global $wpdb;
    return (int) $wpdb->num_queries;
}

function mlbench_step($label, $fn)
{
    global $wpdb;
    $wpdb->queries = array();
    $q0 = mlbench_qcount();
    $t0 = microtime(true);
    $out = $fn();
    $ms = round((microtime(true) - $t0) * 1000, 1);
    $qn = mlbench_qcount() - $q0;
    printf("%-28s %8.1f ms  %5d queries\n", $label, $ms, $qn);
    return $out;
}

/* ---------- 灌入测试数据 ---------- */

mlbench_step("seed {$USERS} users", function () use ($USERS, &$created_users) {
    for ($i = 1; $i <= $USERS; $i++) {
        $uid = wp_insert_user(array(
            'user_login'   => "mlbench_u{$i}_" . wp_generate_password(6, false),
            'user_pass'    => wp_generate_password(20),
            'user_email'   => "mlbench_u{$i}@bench.invalid",
            'display_name' => "Bench User {$i}",
        ));
        if (!is_wp_error($uid)) {
            $created_users[] = (int) $uid;
        }
    }
});

mlbench_step("seed {$PRODUCTS} products", function () use ($PRODUCTS, &$created_products) {
    for ($i = 1; $i <= $PRODUCTS; $i++) {
        $pid = wp_insert_post(array(
            'post_title'  => "MLBENCH 商品 {$i}",
            'post_type'   => 'mlshop_product',
            'post_status' => 'publish',
        ));
        if ($pid) {
            update_post_meta($pid, '_mlshop_price', 10 + ($i % 90));
            $created_products[] = (int) $pid;
        }
    }
});

mlbench_step("seed {$ORDERS} orders", function () use ($ORDERS, &$created_orders, $created_products, $created_users) {
    $statuses = array('mlshop_paid', 'mlshop_processing', 'mlshop_completed', 'mlshop_pending');
    $n = count($created_products) ?: 1;
    for ($i = 1; $i <= $ORDERS; $i++) {
        $oid = wp_insert_post(array(
            'post_title'  => 'MLBENCH-' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            'post_type'   => 'mlshop_order',
            'post_status' => $statuses[$i % count($statuses)],
            'post_date'   => date('Y-m-d H:i:s', time() - ($i % 3600) * 60),
        ));
        if (!$oid) {
            continue;
        }
        $uid = $created_users ? $created_users[$i % count($created_users)] : 0;
        update_post_meta($oid, '_mlshop_user_id', $uid);
        update_post_meta($oid, '_mlshop_status', str_replace('mlshop_', '', $statuses[$i % count($statuses)]));
        update_post_meta($oid, '_mlshop_total', 100 + ($i % 50));
        update_post_meta($oid, '_mlshop_items', array(array(
            'id' => $created_products[$i % $n], 'title' => 'BENCH', 'qty' => 1, 'subtotal' => 100,
        )));
        $created_orders[] = (int) $oid;
    }
});

/* ---------- 五类查询实测 ---------- */

// 1. 商品查询：归档页等价查询（第一页）
mlbench_step('商品查询（归档第一页）', function () {
    $q = new WP_Query(array(
        'post_type'      => 'mlshop_product',
        'post_status'    => 'publish',
        'posts_per_page' => 24,
        'paged'          => 1,
    ));
    return $q->found_posts;
});

// 2. 订单查询：get_user_orders（中等活跃用户）
mlbench_step('订单查询（我的订单）', function () use ($created_users) {
    $uid = $created_users ? $created_users[0] : 0;
    return count(MLSHOP_Order::get_user_orders($uid, 20));
});

// 3. 后台订单：状态过滤分页查询
mlbench_step('后台订单（状态分页）', function () {
    $q = new WP_Query(array(
        'post_type'      => 'mlshop_order',
        'post_status'    => 'mlshop_paid',
        'posts_per_page' => 20,
        'paged'          => 1,
        'fields'         => 'ids',
    ));
    return $q->found_posts;
});

// 4. 卡密库存：pop（若池为空则预期返回 false，测的是查询路径）
mlbench_step('卡密 pop（查询路径）', function () use ($created_products) {
    if (!class_exists('Moonlight_Card_Stock') || !$created_products) {
        return 'skip';
    }
    return Moonlight_Card_Stock::pop($created_products[0], 0, 0) === false ? 'empty' : 'ok';
});

// 5. 物流查询：按订单取发货单
mlbench_step('物流查询（订单发货单）', function () use ($created_orders) {
    if (!class_exists('MLSHOP_Shipping') || !$created_orders) {
        return 'skip';
    }
    return count(MLSHOP_Shipping::get_shipments($created_orders[0]));
});

// 6. 统计聚合（Free）：单日窗口
mlbench_step('统计聚合（单日窗口）', function () {
    if (!class_exists('MLSHOP_Statistics')) {
        return 'skip';
    }
    $ref = new ReflectionClass('MLSHOP_Statistics');
    if (!$ref->hasMethod('compute')) {
        return 'skip';
    }
    $m = $ref->getMethod('compute');
    $m->setAccessible(true);
    $inst = method_exists('MLSHOP_Statistics', 'get_instance') ? MLSHOP_Statistics::get_instance() : new MLSHOP_Statistics();
    $m->invoke($inst, array('from' => date('Y-m-d'), 'to' => date('Y-m-d')));
    return 'ok';
});

/* ---------- 清理测试数据 ---------- */

mlbench_step('cleanup', function () use ($created_products, $created_orders, $created_users) {
    global $wpdb;
    $ids = array_merge($created_orders, $created_products);
    foreach (array_chunk($ids, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})");
    }
    foreach ($created_users as $uid) {
        if (function_exists('wp_delete_user')) {
            wp_delete_user($uid);
        }
    }
    return 'done';
});

echo "完成。请将结果回填 docs/PERFORMANCE_AUDIT.md 第五节对照表。\n";
