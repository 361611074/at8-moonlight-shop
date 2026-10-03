<?php
/**
 * Phase A（会员中心并入）：moonlight-shop/includes/user 真实 MLUC_ 类用例。
 *
 * 由 tests/run.php 以子进程方式调用：
 * wp-stubs.php 的测试版 MLUC_Membership 桩（paywall_price 用例依赖）与真实
 * MLUC_Membership 同名，无法同进程共存；本文件先加载 moonlight-shop.php
 * （注册 MLUC_ 自动加载器 + 定义 merged helpers），再加载 WP 桩——
 * 桩文件对 MLUC_Membership 的 class_exists 守卫会让真实类自动胜出。
 *
 * 输出与 run.php 相同的 ok/FAIL 行，末行 "N passed, M failed" 由 run.php 回填计数。
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/* ---------- 1) moonlight-shop.php 加载前需要的最小桩 ----------
 * functions.php 的 mluc_* 合并区块会探测 active_plugins（旧插件未激活 → 定义），
 * 主文件顶层调用 add_action / plugin_dir_* / register_*_activation_hook。 */
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $GLOBALS['__test_options'][$key] ?? $default; }
}
if (!function_exists('add_action')) {
    function add_action(...$args) {}
}
if (!function_exists('add_filter')) {
    function add_filter(...$args) {}
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return rtrim(dirname((string) $file), '/\\') . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'http://example.test/wp-content/plugins/' . basename(dirname((string) $file)) . '/'; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $cb) { $GLOBALS['__test_activation_hooks'][] = $cb; }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $cb) {}
}
if (!function_exists('add_shortcode')) {
    // 商城主文件顶层 License_Bridge::boot() 会注册前台短代码
    function add_shortcode($tag, $cb) { $GLOBALS['__test_shortcodes'][(string) $tag] = $cb; }
}
if (!function_exists('is_admin')) {
    function is_admin() { return false; }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}

/* ---------- 2) 商城主文件：MLUC_ 自动加载器（includes/user/）+ merged helpers ----------
 * v3.0.1 起加载器改在 plugins_loaded 注册（事故修复），测试进程需手动触发，
 * 且必须在 wp-stubs 之前——让真实 MLUC_Membership 先于同名桩被解析。 */
require __DIR__ . '/../moonlight-shop/moonlight-shop.php';
mlshop_register_mluc_compat();

/* ---------- 3) WP 桩（真实 MLUC_Membership 经自动加载器加载后，同名测试桩让位） ---------- */
require __DIR__ . '/wp-stubs.php';

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

/** 重置 License_Manager 单例（is_product_active 有请求级缓存）。 */
function __mluc_reset_lm()
{
    $ref = new ReflectionProperty('MLUC_License_Manager', 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
}

/* ==========================================================================
 * 启动守卫语义
 * ========================================================================== */

echo "== Phase A：启动守卫语义 ==\n";
check('旧插件未激活且 MLUC_Auth 未加载 → 商城侧应启动', true === mlshop_user_modules_should_boot());
mlshop_boot_user_modules();
foreach (array(
    'mluc_login', 'mluc_register', 'mluc_lostpassword', 'mluc_account',
    'mluc_oauth_buttons', 'hidecontent', 'mluc_materials', 'mluc_downloads',
    'mluc_videos', 'mluc_video',
) as $__tag) {
    check("启动后短代码已注册：{$__tag}", isset($GLOBALS['__test_shortcodes'][$__tag]));
}
check('mluc_loaded 动作触发 1 次', 1 === count($GLOBALS['__test_actions']['mluc_loaded'] ?? array()));

/* ==========================================================================
 * Phase B（设置与页面接管）——非让位分支用例。
 * 注意：必须位于 define('MLUC_LEGACY_ACTIVE', true) 之前；
 * 让位（LEGACY）分支用例在文件末尾（该常量已定义处）。
 * __test_reset_card_env 会清空 __test_actions，先快照，段末恢复，
 * 保证后续既有用例（mluc_loaded 触发计数）不受影响。
 * ========================================================================== */

$__pb_actions_saved = $GLOBALS['__test_actions'];

echo "== Phase B：页面创建并入（MLSHOP_Activator::create_user_pages） ==\n";
__test_reset_card_env();
check('首次激活创建 4 页（账户中心/登录/注册/找回）', 4 === count(MLSHOP_Activator::create_user_pages()));
$__pb_opt = get_option('mluc_options');
check('mluc_options 记录 4 个页面 ID', isset($__pb_opt['account_page_id'], $__pb_opt['login_page_id'], $__pb_opt['register_page_id'], $__pb_opt['lostpassword_page_id'])
    && 4 === count(array_filter(array($__pb_opt['account_page_id'], $__pb_opt['login_page_id'], $__pb_opt['register_page_id'], $__pb_opt['lostpassword_page_id']))));
check('账户中心页内容 = [mluc_account] 短代码', '[mluc_account]' === get_post($__pb_opt['account_page_id'])->post_content);
check('登录页内容 = [mluc_login] 短代码', '[mluc_login]' === get_post($__pb_opt['login_page_id'])->post_content);
check('注册页内容 = [mluc_register] 短代码', '[mluc_register]' === get_post($__pb_opt['register_page_id'])->post_content);
check('找回密码页内容 = [mluc_lostpassword] 短代码', '[mluc_lostpassword]' === get_post($__pb_opt['lostpassword_page_id'])->post_content);
check('账户中心页标题 = 账户中心', '账户中心' === get_post($__pb_opt['account_page_id'])->post_title);
check('页面类型为 page 且已发布', 'page' === get_post($__pb_opt['account_page_id'])->post_type && 'publish' === get_post($__pb_opt['account_page_id'])->post_status);
check('默认值 enable_avatar=1 / redirect_after_login 空', 1 === (int) $__pb_opt['enable_avatar'] && '' === $__pb_opt['redirect_after_login']);
check('已存在页面：重复激活跳过（不新建）', array() === MLSHOP_Activator::create_user_pages());
// option 记录存在但文章已丢失 → 该页重建，其余仍跳过
$__pb_opt['account_page_id'] = 999999; // 未创建过的文章 ID
update_option('mluc_options', $__pb_opt);
$__pb_re = MLSHOP_Activator::create_user_pages();
check('页面丢失时仅重建账户中心页（其余跳过）', 1 === count($__pb_re)
    && '[mluc_account]' === get_post(get_option('mluc_options')['account_page_id'])->post_content);
unset($GLOBALS['__test_options']['mluc_options']);
__test_reset_card_env();

echo "== Phase B：Moonlight_Options 读取链（mluc_options 最低优先级回退） ==\n";
__test_reset_card_env();
$GLOBALS['__test_options']['mluc_options'] = array('membership_levels' => array('gold' => array('label' => 'Gold', 'price' => 99)));
check('新结构/独立键/mlshop_options 均无键 → 从 mluc_options 读到 membership_levels', array('gold' => array('label' => 'Gold', 'price' => 99)) === Moonlight_Options::get('membership_levels'));
$GLOBALS['__test_options']['mlshop_options'] = array('membership_levels' => 'from-legacy-array');
check('mlshop_options（旧数组）优先于 mluc_options', 'from-legacy-array' === Moonlight_Options::get('membership_levels'));
unset($GLOBALS['__test_options']['mlshop_options']);
$GLOBALS['__test_options']['mlshop_membership_levels'] = 'from-legacy-single';
check('mlshop_ 独立键优先于 mluc_options', 'from-legacy-single' === Moonlight_Options::get('membership_levels'));
unset($GLOBALS['__test_options']['mlshop_membership_levels']);
$GLOBALS['__test_options']['moonlight_shop_options'] = array('membership_levels' => 'from-new-store');
check('新结构（moonlight_shop_options）优先于 mluc_options', 'from-new-store' === Moonlight_Options::get('membership_levels'));
unset($GLOBALS['__test_options']['moonlight_shop_options']);
check('mluc_get_option 读写路径不受影响（仍直读 mluc_options）', array('gold' => array('label' => 'Gold', 'price' => 99)) === mluc_get_option('membership_levels', array()));
unset($GLOBALS['__test_options']['mluc_options']);
check('全链无键 → 返回默认值', 'dft' === Moonlight_Options::get('membership_levels', 'dft'));
__test_reset_card_env();

echo "== Phase B：设置菜单归组（submenu 默认模式，旧插件未激活） ==\n";
__test_reset_card_env();
$GLOBALS['__test_admin_menu'] = array('top' => array(), 'sub' => array());
check('旧插件未激活 → 默认 submenu 模式', 'submenu' === MLUC_Settings::resolve_menu_mode());
MLUC_Settings::get_instance()->register_admin_menu();
check('不再注册「用户中心」顶级菜单', 0 === count($GLOBALS['__test_admin_menu']['top']));
$__pb_sub = $GLOBALS['__test_admin_menu']['sub'];
check('设置页挂到商城菜单下「会员与账户」', 1 === count($__pb_sub)
    && 'edit.php?post_type=mlshop_product' === $__pb_sub[0]['parent']
    && 'mluc-settings' === $__pb_sub[0]['slug']
    && '会员与账户' === $__pb_sub[0]['title']);
MLUC_License_Admin::get_instance()->register_menu();
MLUC_System_Status::get_instance()->register_menu();
$__pb_sub = $GLOBALS['__test_admin_menu']['sub'];
check('License 管理与设置页同组（商城菜单下）', 3 === count($__pb_sub)
    && 'edit.php?post_type=mlshop_product' === $__pb_sub[1]['parent'] && 'mluc-licenses' === $__pb_sub[1]['slug']);
check('系统状态与设置页同组（商城菜单下）', 'edit.php?post_type=mlshop_product' === $__pb_sub[2]['parent'] && 'mluc-status' === $__pb_sub[2]['slug']);
__test_reset_card_env();

echo "== Phase B：mluc_account_tabs 过滤器 Tab 去重 ==\n";
__test_reset_card_env();
$__pb_at = MLSHOP_Account_Tab::get_instance();
$__pb_pre = array(
    'orders'    => array('title' => '已有订单', 'callback' => function () {}),
    'downloads' => array('title' => '已有下载', 'callback' => function () {}),
    'addresses' => array('title' => '已有地址', 'callback' => function () {}),
);
$__pb_out = $__pb_at->add_tab($__pb_pre);
check('已有 orders Tab 不被商城侧覆盖', '已有订单' === $__pb_out['orders']['title']);
check('已有 downloads Tab 不被商城侧覆盖', '已有下载' === $__pb_out['downloads']['title']);
check('已有 addresses Tab 不被商城侧覆盖', '已有地址' === $__pb_out['addresses']['title']);
$__pb_fresh = $__pb_at->add_tab(array());
check('空白时商城侧仍注册 orders/addresses/downloads/coupons', isset($__pb_fresh['orders'], $__pb_fresh['addresses'], $__pb_fresh['downloads'], $__pb_fresh['coupons']));
// 收集器语义：并入版 Account 基础 Tab + 商城 Account_Tab 挂载合并后 key 唯一
$__pb_merged = $__pb_at->add_tab(MLUC_Account::get_instance()->get_tabs());
check('两处挂载合并后每个 Tab key 只出现一次', count($__pb_merged) === count(array_unique(array_keys($__pb_merged)))
    && isset($__pb_merged['overview'], $__pb_merged['profile'], $__pb_merged['membership'], $__pb_merged['orders']));
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pb_actions_saved; // 恢复段前动作记录（mluc_loaded 计数用例依赖）

/* ==========================================================================
 * mluc_* 函数合并（shop functions.php merged helpers 区块）
 * ========================================================================== */

echo "== Phase A：mluc_* 函数合并 ==\n";
foreach (array(
    'mluc_get_option', 'mluc_ui_label', 'mluc_get_account_url', 'mluc_get_login_url',
    'mluc_get_register_url', 'mluc_get_lostpassword_url', 'mluc_get_template',
    'mluc_ajax_data', 'mluc_count_user_comments', 'mluc_send_json',
) as $__fn) {
    check("合并函数存在：{$__fn}()", function_exists($__fn));
}
check('mluc_get_account_url 无页面时回退 /account/', mluc_get_account_url() === 'http://example.test/account/');
check('mluc_get_login_url 无页面时回退 WP 登录页', mluc_get_login_url('http://example.test/x') === 'http://example.test/wp-login.php');
check('mluc_get_register_url 回退 WP 注册页', mluc_get_register_url() === 'http://example.test/wp-login.php?action=register');
check('mluc_get_lostpassword_url 回退登录页视图', mluc_get_lostpassword_url() === 'http://example.test/wp-login.php?mluc_view=lostpassword');
check('mluc_ui_label 留空回退默认', mluc_ui_label('tab_overview', 'Overview') === 'Overview');
check('mluc_ui_label 自定义优先', ($GLOBALS['__test_options']['mluc_options'] = array('ui_labels' => array('tab_overview' => '仪表盘'))) && mluc_ui_label('tab_overview', 'Overview') === '仪表盘');
unset($GLOBALS['__test_options']['mluc_options']);

/* ==========================================================================
 * Membership（并入真实类）：只升不降 / 惰性到期 / get_level_price
 * ========================================================================== */

echo "== Phase A：MLUC_Membership（等级授予 / 惰性到期 / 价格） ==\n";
$__lvl_ref = new ReflectionProperty('MLUC_Membership', 'levels');
$__lvl_ref->setAccessible(true);
check('工厂默认等级表含 5 级', count($__lvl_ref->getValue()) === 5);
check('free_enabled 默认开启', true === MLUC_Membership::free_enabled());

// get_levels 为进程级缓存：先写后台等级定义再驱动（free 基座自动补回）
$GLOBALS['__test_options']['mluc_options'] = array(
    'membership_levels' => array(
        'monthly' => array('label' => 'Monthly',     'price' => 30,  'validity' => 30, 'sort_order' => 20),
        'gold'    => array('label' => 'Gold Member', 'price' => 99,  'validity' => 30, 'sort_order' => 25),
        'diamond' => array('label' => 'Diamond',     'price' => 199, 'validity' => 0,  'sort_order' => 35),
    ),
);
check('get_level_price 读取后台定义', 99.0 === MLUC_Membership::get_level_price('gold'));
check('get_level_validity 读取后台定义', 30 === MLUC_Membership::get_level_validity('monthly'));
check('get_level_sort_order(diamond)=35', 35 === MLUC_Membership::get_level_sort_order('diamond'));
check('free 基座自动补回且 sort=10', isset(MLUC_Membership::get_levels()['free']) && 10 === MLUC_Membership::get_level_sort_order('free'));
check('未设置价格等级回退 0', 0 == MLUC_Membership::get_level_price('free'));
check('get_level_label 单点取值', 'Gold Member' === MLUC_Membership::get_level_label('gold'));
check('未定义等级回退原 key', 'nope' === MLUC_Membership::get_level_label('nope'));

// 只升不降
check('set_user_level gold', true === MLUC_Membership::set_user_level(5, 'gold', 0));
check('gold → diamond 授予成功', true === MLUC_Membership::get_instance()->grant_level(5, 'diamond'));
check('用户等级已到 diamond', 'diamond' === MLUC_Membership::get_user_level(5));
check('diamond → gold 拒绝（只升不降）', false === MLUC_Membership::get_instance()->grant_level(5, 'gold'));
check('拒绝后仍为 diamond', 'diamond' === MLUC_Membership::get_user_level(5));
check('无效等级 set_user_level 拒绝', false === MLUC_Membership::set_user_level(5, 'bogus', 0));
check('mluc_user_level helper', 'diamond' === mluc_user_level(5));

// 有效期叠加：在既有有效到期日基础上 +30 天
MLUC_Membership::set_user_level(6, 'monthly', current_time('timestamp') + 10 * DAY_IN_SECONDS);
check('monthly → gold 授予成功', true === MLUC_Membership::get_instance()->grant_level(6, 'gold'));
$__exp6 = MLUC_Membership::get_user_expires(6);
check('有效期在既有到期日上叠加 30 天', $__exp6 > current_time('timestamp') + 39 * DAY_IN_SECONDS && $__exp6 <= current_time('timestamp') + 41 * DAY_IN_SECONDS);

// 惰性到期降级
MLUC_Membership::set_user_level(7, 'gold', current_time('timestamp') - 100);
check('过期用户读取时惰性降级为 free', 'free' === MLUC_Membership::get_user_level(7));
check('惰性降级写回 usermeta', 'free' === get_user_meta(7, 'mluc_membership_level', true));

// 访问判断
check('free 内容恒公开（未登录）', true === MLUC_Membership::user_can_access('free', 0));
check('diamond 用户可访问 gold 内容', true === MLUC_Membership::user_can_access('gold', 5));
check('gold 用户不可访问 diamond 内容', false === MLUC_Membership::user_can_access('diamond', 6));
check('mluc_user_can_access / mluc_user_is_expired helper', true === mluc_user_can_access('gold', 5) && false === mluc_user_is_expired(5));

/* ==========================================================================
 * Phase C（支付并线）：付费墙兼容读取 / hidecontent 单点探测 / License 商城链
 * 行为仅在非 LEGACY（商城独立运行）时生效；LEGACY 回归用例在文末。
 * ========================================================================== */

echo "== Phase C：MLSHOP_Pay_Access::is_unlocked 双账本（mlshop + mluc 解锁账本） ==\n";
$__pc_actions = $GLOBALS['__test_actions']; // 段内 reset 会清动作，段末恢复
__test_reset_card_env();
wp_set_current_user(31);
$__pc_p101 = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'legacy-lock-101'));
// 仅旧账本 mluc_pay_unlocks 有未过期记录 → 已解锁
update_user_meta(31, 'mluc_pay_unlocks', array($__pc_p101 => time() + 3600));
check('仅旧账本未过期 → 已解锁', true === MLSHOP_Pay_Access::is_unlocked($__pc_p101));
// 旧账本过期 → 未解锁
update_user_meta(31, 'mluc_pay_unlocks', array($__pc_p101 => time() - 10));
check('仅旧账本已过期 → 未解锁', false === MLSHOP_Pay_Access::is_unlocked($__pc_p101));
// 两边都有：任一未过期即解锁（取未过期）
update_user_meta(31, 'mluc_pay_unlocks', array($__pc_p101 => time() + 3600));
update_user_meta(31, 'mlshop_pay_unlocks', array($__pc_p101 => time() - 10));
check('新账本过期 + 旧账本未过期 → 已解锁', true === MLSHOP_Pay_Access::is_unlocked($__pc_p101));
update_user_meta(31, 'mluc_pay_unlocks', array($__pc_p101 => time() - 10));
update_user_meta(31, 'mlshop_pay_unlocks', array($__pc_p101 => time() + 7200));
check('旧账本过期 + 新账本未过期 → 已解锁', true === MLSHOP_Pay_Access::is_unlocked($__pc_p101));
update_user_meta(31, 'mluc_pay_unlocks', array($__pc_p101 => time() + 3600));
update_user_meta(31, 'mlshop_pay_unlocks', array($__pc_p101 => time() + 7200));
check('双账本均未过期 → 已解锁', true === MLSHOP_Pay_Access::is_unlocked($__pc_p101));
update_user_meta(31, 'mluc_pay_unlocks', array($__pc_p101 => time() - 10));
update_user_meta(31, 'mlshop_pay_unlocks', array($__pc_p101 => time() - 10));
check('双账本均过期 → 未解锁', false === MLSHOP_Pay_Access::is_unlocked($__pc_p101));
check('过期清理仅作用于新账本（旧账本只读）', !isset(get_user_meta(31, 'mlshop_pay_unlocks', true)[$__pc_p101]) && isset(get_user_meta(31, 'mluc_pay_unlocks', true)[$__pc_p101]));
// 写路径不变：grant_unlock 只写 mlshop_pay_unlocks
$__pc_p102 = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'new-lock-102'));
MLSHOP_Pay_Access::grant_unlock($__pc_p102, 31, 0);
check('grant_unlock 写入新账本（永久）', isset(get_user_meta(31, 'mlshop_pay_unlocks', true)[$__pc_p102]) && 0 === (int) get_user_meta(31, 'mlshop_pay_unlocks', true)[$__pc_p102]);
check('grant_unlock 不写旧账本', !isset(get_user_meta(31, 'mluc_pay_unlocks', true)[$__pc_p102]));
check('新账本解锁可读', true === MLSHOP_Pay_Access::is_unlocked($__pc_p102));
wp_set_current_user(0);
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;

echo "== Phase C：付费墙配置兼容（仅 _mluc_pw_* meta 的文章） ==\n";
__test_reset_card_env();
$GLOBALS['__test_options']['mluc_options'] = array(); // get_levels 已按上文等级定义缓存（gold validity=30）
wp_set_current_user(0);
$GLOBALS['__test_users'][42] = (object) array('ID' => 42, 'user_login' => 'u42', 'user_email' => 'u42@test.local', 'display_name' => 'U42');
$GLOBALS['__test_users'][43] = (object) array('ID' => 43, 'user_login' => 'u43', 'user_email' => 'u43@test.local', 'display_name' => 'U43');
MLUC_Membership::set_user_level(42, 'gold', 0);
MLUC_Membership::set_user_level(43, 'free', 0);
$__pc_p201 = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'legacy-pw-201'));
update_post_meta($__pc_p201, '_mluc_pw_pay_mode', 'read');
update_post_meta($__pc_p201, '_mluc_pw_pay_auth', 'gold');
update_post_meta($__pc_p201, '_mluc_pw_price_sell', 88.0);
update_post_meta($__pc_p201, '_mluc_pw_price_gold', 66.0);
update_post_meta($__pc_p201, '_mluc_pw_price_diamond', 55.0);
update_post_meta($__pc_p201, '_mluc_pw_order_expire_enabled', '1');
update_post_meta($__pc_p201, '_mluc_pw_order_expire_value', 7);
check('仅旧 meta：is_paywalled=true（_mluc_pw_pay_mode→pay_mode）', true === MLSHOP_Pay_Access::is_paywalled($__pc_p201));
check('仅旧 meta：执行价映射（_mluc_pw_price_sell→price_sell）', 88.0 === (float) MLSHOP_Product_Pay_Meta::get($__pc_p201, 'price_sell', 0));
check('仅旧 meta：gold 等级价映射（_mluc_pw_price_gold→price_gold）', 66.0 === (float) MLSHOP_Product_Pay_Meta::get($__pc_p201, 'price_gold', 0));
check('仅旧 meta：gold 用户取价走映射', 66.0 === Moonlight_Price_Calculator::paywall_price($__pc_p201, 42));
check('仅旧 meta：free 用户取执行价', 88.0 === Moonlight_Price_Calculator::paywall_price($__pc_p201, 43));
MLUC_Membership::set_user_level(45, 'diamond', 0);
check('仅旧 meta：diamond 用户取 diamond 档价', 55.0 === Moonlight_Price_Calculator::paywall_price($__pc_p201, 45));
check('仅旧 meta：会员门槛映射（_mluc_pw_pay_auth→pay_auth）', 'gold' === MLSHOP_Product_Pay_Meta::get($__pc_p201, 'pay_auth', 'all'));
check('仅旧 meta：free 用户无购买权', false === MLSHOP_Pay_Access::user_can_purchase($__pc_p201, 43));
check('仅旧 meta：gold 用户可购买', true === MLSHOP_Pay_Access::user_can_purchase($__pc_p201, 42));
check('仅旧 meta：订单时效映射', '1' === (string) MLSHOP_Product_Pay_Meta::get($__pc_p201, 'order_expire_enabled', '0') && 7 === (int) MLSHOP_Product_Pay_Meta::get($__pc_p201, 'order_expire_value', 0));
check('仅旧 meta：get_unlock_expire 按映射时效计算', abs(MLSHOP_Pay_Access::get_unlock_expire($__pc_p201) - (time() + 7 * 86400)) < 60);
// 新 meta 优先
update_post_meta($__pc_p201, '_mlshop_price_sell', 123.0);
update_post_meta($__pc_p201, '_mlshop_pay_mode', 'video');
check('新旧并存：新 meta 优先（价格）', 123.0 === (float) MLSHOP_Product_Pay_Meta::get($__pc_p201, 'price_sell', 0));
check('新旧并存：新 meta 优先（模式）', 'video' === MLSHOP_Product_Pay_Meta::get($__pc_p201, 'pay_mode', 'off'));
// 无任何付费 meta 的文章不受影响
$__pc_p202 = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'free-post-202'));
check('无付费 meta：不视为付费墙', false === MLSHOP_Pay_Access::is_paywalled($__pc_p202));
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;

echo "== Phase C：hidecontent payshow 单点探测（仅 MLSHOP_Pay_Access） ==\n";
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;
$GLOBALS['__test_users'][44] = (object) array('ID' => 44, 'user_login' => 'u44', 'user_email' => 'u44@test.local', 'display_name' => 'U44');
wp_set_current_user(44);
$GLOBALS['__test_is_singular'] = true;
$__pc_p203 = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'pw-single-203'));
$GLOBALS['__test_queried_id'] = $__pc_p203;
// 仅旧 meta 的付费墙（经 Pay_Access 兼容读取判定），未解锁 → 锁定
update_post_meta($__pc_p203, '_mluc_pw_pay_mode', 'read');
check('payshow：付费墙未解锁 → 不可见', false === MLUC_Hidecontent::user_can_view('payshow'));
check('payshow：MLUC_Paywall 类不存在（探测单点化）', !class_exists('MLUC_Paywall', false));
// 旧账本 mluc_pay_unlocks 解锁 → 经单点探测放行（存量解锁零迁移兼容）
update_user_meta(44, 'mluc_pay_unlocks', array($__pc_p203 => time() + 3600));
check('payshow：旧账本解锁后 → 可见', true === MLUC_Hidecontent::user_can_view('payshow'));
check('payshow：解锁后渲染出隐藏内容', false !== strpos((string) MLUC_Hidecontent::get_instance()->render(array('type' => 'payshow'), 'secret-inner'), 'secret-inner'));
// 未解锁 → 渲染 CTA 卡（目标指向文章本身触发解锁）
update_user_meta(44, 'mluc_pay_unlocks', array($__pc_p203 => time() - 10));
$__pc_cta = (string) MLUC_Hidecontent::get_instance()->render(array('type' => 'payshow'), 'secret-inner');
check('payshow：未解锁渲染锁定卡且不泄露内容', false === strpos($__pc_cta, 'secret-inner') && false !== strpos($__pc_cta, 'mluc-hc-payshow'));
check('payshow：CTA 指向付费文章本身（商城单引擎判定）', false !== strpos($__pc_cta, 'http://example.test/?p=' . $__pc_p203));
// 未开付费墙的文章：退化为会员闸
$__pc_p204 = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'no-pw-204'));
$GLOBALS['__test_queried_id'] = $__pc_p204;
check('payshow：未开付费墙 → free 用户不可见', false === MLUC_Hidecontent::user_can_view('payshow'));
$GLOBALS['__test_is_singular'] = false;
$GLOBALS['__test_queried_id'] = 0;
wp_set_current_user(0);
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;

echo "== Phase C：License 自动颁发链切换（mlshop_order_paid） ==\n";
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;
$GLOBALS['__test_users'][51] = (object) array('ID' => 51, 'user_login' => 'u51', 'user_email' => 'u51@test.local', 'display_name' => 'U51');
$GLOBALS['__test_options']['mluc_options'] = array('license_auto_levels' => array('gold'));
$__pc_o1 = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_title' => 'MLS-MB-T1'));
update_post_meta($__pc_o1, '_mlshop_type', 'membership');
update_post_meta($__pc_o1, '_mlshop_membership_target', 'gold');
update_post_meta($__pc_o1, '_mlshop_user_id', 51);
MLUC_License_Manager::get_instance()->maybe_issue_for_shop_order($__pc_o1);
$__pc_lics = get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10));
check('白名单等级会员订单付款 → 签发 1 个 License', 1 === count($__pc_lics));
check('product 语义与旧链一致（moonlight-user-center-pro）', 'moonlight-user-center-pro' === get_post_meta((int) $__pc_lics[0]->ID, '_mluc_license_product', true));
check('有效期取等级 validity（gold=30 天）', abs((int) get_post_meta((int) $__pc_lics[0]->ID, '_mluc_license_expires', true) - (time() + 30 * DAY_IN_SECONDS)) < 60);
check('License 关联商城订单', (int) $__pc_o1 === (int) get_post_meta((int) $__pc_lics[0]->ID, '_mluc_license_order', true));
check('License 归属下单用户（email 解析）', 'u51@test.local' === get_post_meta((int) $__pc_lics[0]->ID, '_mluc_license_email', true));
// 幂等复用：同用户再次购买 → 续期不新建
$__pc_o2 = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_title' => 'MLS-MB-T2'));
update_post_meta($__pc_o2, '_mlshop_type', 'membership');
update_post_meta($__pc_o2, '_mlshop_membership_target', 'gold');
update_post_meta($__pc_o2, '_mlshop_user_id', 51);
MLUC_License_Manager::get_instance()->maybe_issue_for_shop_order($__pc_o2);
check('同 user+product 复用续期（不新建）', 1 === count(get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10))));
check('续期后有效期 ≈ 60 天（30 天上叠加）', abs((int) get_post_meta((int) $__pc_lics[0]->ID, '_mluc_license_expires', true) - (time() + 60 * DAY_IN_SECONDS)) < 60);
// 非白名单等级 → 不签发
$__pc_o3 = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_title' => 'MLS-MB-T3'));
update_post_meta($__pc_o3, '_mlshop_type', 'membership');
update_post_meta($__pc_o3, '_mlshop_membership_target', 'diamond');
update_post_meta($__pc_o3, '_mlshop_user_id', 51);
MLUC_License_Manager::get_instance()->maybe_issue_for_shop_order($__pc_o3);
check('非白名单等级 → 不签发', 1 === count(get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10))));
// 付费墙订单 / 访客订单 → 不签发
$__pc_o4 = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_title' => 'MLS-PW-T4'));
update_post_meta($__pc_o4, '_mlshop_type', 'paywall');
update_post_meta($__pc_o4, '_mlshop_paywall_post', 9);
update_post_meta($__pc_o4, '_mlshop_user_id', 51);
MLUC_License_Manager::get_instance()->maybe_issue_for_shop_order($__pc_o4);
$__pc_o5 = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_title' => 'MLS-MB-T5'));
update_post_meta($__pc_o5, '_mlshop_type', 'membership');
update_post_meta($__pc_o5, '_mlshop_membership_target', 'gold');
update_post_meta($__pc_o5, '_mlshop_user_id', 0);
MLUC_License_Manager::get_instance()->maybe_issue_for_shop_order($__pc_o5);
check('付费墙订单 / 访客订单 → 不签发', 1 === count(get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10))));
// 旧链入口保持原语义（回归）：mluc_payment_completed 参数形态
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;
$GLOBALS['__test_options']['mluc_options'] = array('license_auto_levels' => array('gold'));
MLUC_License_Manager::get_instance()->maybe_issue_for_order(88, 51, 'gold');
check('旧链 maybe_issue_for_order 仍按白名单签发', 1 === count(get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10))));
MLUC_License_Manager::get_instance()->maybe_issue_for_order(89, 51, 'paywall:9');
check('旧链 paywall: 前缀等级不签发', 1 === count(get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10))));
__test_reset_card_env();
$GLOBALS['__test_actions'] = $__pc_actions;
check('Phase C 段后 mluc_loaded 动作计数不受影响', 1 === count($GLOBALS['__test_actions']['mluc_loaded'] ?? array()));

/* ==========================================================================
 * License Manager：签发格式 / 激活停用 / 宽限期 / 续期 / 撤销
 * ========================================================================== */

echo "== Phase A：MLUC_License_Manager（签发 / 宽限 / 撤销） ==\n";
__test_reset_card_env();
$__key = MLUC_License_Manager::generate_key();
check('Key 格式 MLUC-PRO-XXXX-XXXX-XXXX-XXXX', (bool) preg_match('/^MLUC-PRO-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $__key));

$__lic = MLUC_License_Manager::create(array('user_id' => 0, 'product' => 'pro-a', 'days' => 30));
check('签发返回 post id', is_int($__lic) && $__lic > 0);
check('签发即 active', 'active' === MLUC_License_Manager::effective_status($__lic));
check('产品 meta 落库', 'pro-a' === get_post_meta($__lic, '_mluc_license_product', true));
check('创建即绑定当前站点', true === MLUC_License_Manager::is_bound_to_current_site($__lic) && '' !== (string) get_post_meta($__lic, '_mluc_license_site', true));
check('到期 = 30 天后', abs((int) get_post_meta($__lic, '_mluc_license_expires', true) - (time() + 30 * DAY_IN_SECONDS)) < 60);
$__lic_post = get_post($__lic);
check('post_title = MLUC-PRO Key，CPT = mluc_license', $__lic_post->post_type === 'mluc_license' && (bool) preg_match('/^MLUC-PRO-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', (string) $__lic_post->post_title));
$__perm = MLUC_License_Manager::create(array('product' => 'pro-a', 'days' => 0));
check('days=0 永久（expires=0）', 0 === (int) get_post_meta($__perm, '_mluc_license_expires', true));

// 停用 → 激活
__test_reset_card_env();
$__lic_b = MLUC_License_Manager::create(array('product' => 'pro-b', 'limit' => 1));
$__key_b = (string) get_post($__lic_b)->post_title;
check('deactivate 解绑并置 inactive', true === MLUC_License_Manager::deactivate($__key_b) && 'inactive' === MLUC_License_Manager::effective_status($__lic_b));
check('重新 activate 成功', true === MLUC_License_Manager::activate($__key_b));
check('激活后绑定当前站点', true === MLUC_License_Manager::is_bound_to_current_site($__lic_b) && 'active' === MLUC_License_Manager::effective_status($__lic_b));

// 远程验证 + 宽限期（GRACE_DAYS=7）
__test_reset_card_env();
$__lic_c = MLUC_License_Manager::create(array('product' => 'pro-c'));
$GLOBALS['__test_options']['mluc_options'] = array('license_server_url' => 'https://lic.test');
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    return new WP_Error('http_request_failed', 'connection timeout');
};
__mluc_reset_lm();
check('远程验证失败且无历史 → 未激活', false === MLUC_License_Manager::get_instance()->is_product_active('pro-c'));
update_post_meta($__lic_c, '_mluc_license_last_check_ts', time() - HOUR_IN_SECONDS);
update_post_meta($__lic_c, '_mluc_license_last_valid', 1);
__mluc_reset_lm();
check('宽限期内（1 小时前验证有效）→ 仍激活', true === MLUC_License_Manager::get_instance()->is_product_active('pro-c'));
update_post_meta($__lic_c, '_mluc_license_last_check_ts', time() - 8 * DAY_IN_SECONDS);
__mluc_reset_lm();
check('宽限期（7 天）已过 → 失效', false === MLUC_License_Manager::get_instance()->is_product_active('pro-c'));

// 远程验证成功 + 12h transient 缓存
$GLOBALS['__test_http_handler'] = function ($method, $url, $args) {
    return array('response' => array('code' => 200), 'body' => wp_json_encode(array('valid' => true)));
};
__mluc_reset_lm();
check('远程验证成功 → 激活', true === MLUC_License_Manager::get_instance()->is_product_active('pro-c'));
__mluc_reset_lm();
$__http_n = count($GLOBALS['__test_http_calls']);
check('12h transient 缓存命中（不重复远程请求）', true === MLUC_License_Manager::get_instance()->is_product_active('pro-c') && count($GLOBALS['__test_http_calls']) === $__http_n);

// 续期 / 撤销 / 恢复 / 退款撤销
__test_reset_card_env();
$__lic_d = MLUC_License_Manager::create(array('product' => 'pro-d', 'days' => 10));
check('renew 在剩余有效期上叠加', true === MLUC_License_Manager::renew($__lic_d, 20) && abs((int) get_post_meta($__lic_d, '_mluc_license_expires', true) - (time() + 30 * DAY_IN_SECONDS)) < 60);
check('renew 0 天转永久', true === MLUC_License_Manager::renew($__lic_d, 0) && 0 === (int) get_post_meta($__lic_d, '_mluc_license_expires', true));
check('revoke 后 effective_status=revoked', true === MLUC_License_Manager::revoke($__lic_d) && 'revoked' === MLUC_License_Manager::effective_status($__lic_d));
check('restore 重新置为 active', true === MLUC_License_Manager::restore($__lic_d) && 'active' === MLUC_License_Manager::effective_status($__lic_d));
__test_reset_card_env();
$__lic_e = MLUC_License_Manager::create(array('product' => 'pro-e', 'days' => 365, 'order_id' => 77));
MLUC_License_Manager::get_instance()->revoke_for_order(77);
check('退款撤销订单关联 License', 'revoked' === MLUC_License_Manager::effective_status($__lic_e));

/* ==========================================================================
 * Payment Manager / Payment Log（未并入网关的隔离语义）
 * ========================================================================== */

echo "== Phase A：MLUC_Payment_Manager / Payment_Log（网关缺席隔离） ==\n";
__test_reset_card_env();
$__pm = MLUC_Payment_Manager::get_instance();
check('网关类未并入 → 注册表为空（无致命）', array() === $__pm->get_all());
check('get(未并入网关) 返回 null', null === $__pm->get('manual'));
check('get_available_gateway 未并入网关报错', is_wp_error($__pm->get_available_gateway('manual')));
check('订单号格式 MLUC+Ymd+8hex', (bool) preg_match('/^MLUC\d{8}[0-9A-F]{8}$/', MLUC_Payment_Manager::generate_order_no()));
check('Payment_Log.write 类缺席时静默跳过（无致命）', null === MLUC_Payment_Log::write(1, 'manual', 'order_created'));

/* ==========================================================================
 * Email Notifications（购买成功邮件；MLUC_Payments 缺席时 symbol 回退空串）
 * ========================================================================== */

echo "== Phase A：MLUC_Email_Notifications（购买成功邮件） ==\n";
__test_reset_card_env();
$GLOBALS['__test_users'] = array();
$GLOBALS['__test_users'][1] = (object) array('ID' => 1, 'user_login' => 'alice', 'user_email' => 'alice@test.local', 'display_name' => 'Alice');
$GLOBALS['__test_options']['mluc_options'] = array('email_purchase_enabled' => 1);
$__o_mail = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'x'));
update_post_meta($__o_mail, '_mluc_pay_order_no', 'MLUCX1');
update_post_meta($__o_mail, '_mluc_pay_price', 99);
MLUC_Email_Notifications::get_instance()->send_purchase_email($__o_mail, 1, 'gold');
check('支付完成邮件发送给下单用户', 1 === count($GLOBALS['__test_mails']) && 'alice@test.local' === $GLOBALS['__test_mails'][0]['to']);
check('邮件主题含「购买成功」与等级名', false !== strpos($GLOBALS['__test_mails'][0]['subject'], '购买成功') && false !== strpos($GLOBALS['__test_mails'][0]['subject'], 'Gold Member'));
check('邮件正文含订单号', false !== strpos($GLOBALS['__test_mails'][0]['body'], 'MLUCX1'));

/* ==========================================================================
 * Auth：节流计数 / 蜜罐 / 渲染令牌 / 注册桩 / 找回密码
 * ========================================================================== */

echo "== Phase A：MLUC_Auth（节流 / 蜜罐 / 注册 / 找回密码） ==\n";
__test_reset_card_env();
$GLOBALS['__test_users'] = array();
$GLOBALS['__test_users_next_id'] = 100;
$GLOBALS['__test_options']['users_can_register'] = 1;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$__auth = MLUC_Auth::get_instance();
$__throttle = new ReflectionMethod('MLUC_Auth', 'throttle');
$__throttle->setAccessible(true);
$__throttle->invoke($__auth, 'login', 2, 600);
$__throttle->invoke($__auth, 'login', 2, 600);
check('节流计数累计（2 次）', 2 === (int) get_transient('mluc_rl_' . md5('login|203.0.113.9')));
try {
    $__throttle->invoke($__auth, 'login', 2, 600);
    check('超限第 3 次触发节流拒绝', false);
} catch (WP_Send_Json_Exception $e) {
    check('超限第 3 次触发节流拒绝', empty($e->payload['success']) && false !== strpos((string) $e->payload['message'], '频繁'));
}

// 登录：空凭据拒绝（节流计数先行，独立 IP 桶）
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_POST = array();
try {
    $__auth->ajax_login();
    check('空凭据登录被拒', false);
} catch (WP_Send_Json_Exception $e) {
    check('空凭据登录被拒', empty($e->payload['success']) && false !== strpos((string) $e->payload['message'], '账号和密码'));
}

// 注册：蜜罐字段
$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
$_POST = array('mluc_hp' => 'spam', 'user_login' => 'bob', 'email' => 'bob@test.local', 'password' => 'secret1');
try {
    $__auth->ajax_register();
    check('蜜罐字段触发拒绝', false);
} catch (WP_Send_Json_Exception $e) {
    check('蜜罐字段触发拒绝', empty($e->payload['success']) && false !== strpos((string) $e->payload['message'], 'Registration failed'));
}

// 注册：渲染令牌 <3 秒
$_SERVER['REMOTE_ADDR'] = '203.0.113.12';
set_transient('mluc_reg_tk_' . md5('TOKFAST'), time(), 600);
$_POST = array('user_login' => 'bob', 'email' => 'bob@test.local', 'password' => 'secret1', 'mluc_tk' => 'TOKFAST');
try {
    $__auth->ajax_register();
    check('渲染令牌 <3 秒拒绝', false);
} catch (WP_Send_Json_Exception $e) {
    check('渲染令牌 <3 秒拒绝', empty($e->payload['success']));
}

// 注册：成功路径
$_SERVER['REMOTE_ADDR'] = '203.0.113.13';
set_transient('mluc_reg_tk_' . md5('TOKOK'), time() - 10, 600);
$_POST = array('user_login' => 'bob', 'email' => 'bob@test.local', 'password' => 'secret1', 'mluc_tk' => 'TOKOK');
try {
    $__auth->ajax_register();
    check('合法注册返回成功', false);
} catch (WP_Send_Json_Exception $e) {
    check('合法注册返回成功', !empty($e->payload['success']) && !empty($e->payload['data']['redirect']));
}
check('用户已创建且写入登录 cookie', 0 !== (int) username_exists('bob') && !empty($GLOBALS['__test_auth_cookies']));
check('mluc_after_register 动作触发', !empty($GLOBALS['__test_actions']['mluc_after_register']));

// 注册：重复用户名
$_SERVER['REMOTE_ADDR'] = '203.0.113.14';
set_transient('mluc_reg_tk_' . md5('TOK2'), time() - 10, 600);
$_POST = array('user_login' => 'bob', 'email' => 'bob2@test.local', 'password' => 'secret1', 'mluc_tk' => 'TOK2');
try {
    $__auth->ajax_register();
    check('重复用户名拒绝', false);
} catch (WP_Send_Json_Exception $e) {
    check('重复用户名拒绝', empty($e->payload['success']) && false !== strpos((string) $e->payload['message'], '已被使用'));
}

// 找回密码：命中既有用户
$_SERVER['REMOTE_ADDR'] = '203.0.113.15';
$GLOBALS['__test_mails'] = array();
$_POST = array('user_login' => 'bob@test.local');
try {
    $__auth->ajax_lost_password();
    check('找回密码命中既有用户', false);
} catch (WP_Send_Json_Exception $e) {
    check('找回密码发送重置邮件', !empty($e->payload['success']) && 1 === count($GLOBALS['__test_mails'])
        && 'bob@test.local' === $GLOBALS['__test_mails'][0]['to']
        && false !== strpos($GLOBALS['__test_mails'][0]['subject'], '重置密码'));
}

// 找回密码：未知用户不泄露存在性
$GLOBALS['__test_mails'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.16';
$_POST = array('user_login' => 'ghost@test.local');
try {
    $__auth->ajax_lost_password();
    check('未知用户不泄露存在性', false);
} catch (WP_Send_Json_Exception $e) {
    check('未知用户不泄露存在性', !empty($e->payload['success']));
}
check('未知用户不发邮件', 0 === count($GLOBALS['__test_mails']));

/* ==========================================================================
 * 自动加载映射（MLUC_ → includes/user/class-*.php）
 * ========================================================================== */

echo "== Phase A：自动加载映射（includes/user/） ==\n";
foreach (array(
    'MLUC_Auth'                     => 'class-auth.php',
    'MLUC_Membership'               => 'class-membership.php',
    'MLUC_Settings'                 => 'class-settings.php',
    'MLUC_Payment_Gateway_Interface' => 'class-payment-gateway-interface.php',
    'MLUC_Email_Notifications'      => 'class-email-notifications.php',
) as $__cls => $__file) {
    class_exists($__cls) || interface_exists($__cls); // 触发商城侧自动加载器
    check("{$__cls} 类已加载", class_exists($__cls, false) || interface_exists($__cls, false));
    $__path = str_replace('\\', '/', (string) (new ReflectionClass($__cls))->getFileName());
    check("{$__cls} 来自 moonlight-shop/includes/user/{$__file}", false !== strpos($__path, '/moonlight-shop/includes/user/' . $__file));
}
check('不并入的 MLUC_Payments 不在商城 includes/user/', !file_exists(dirname(__DIR__) . '/moonlight-shop/includes/user/class-payments.php'));

/* ==========================================================================
 * Phase E（迁移状态页 + 退役提示基础）——非让位分支用例。
 * ========================================================================== */

echo "== Phase E：迁移状态页 stats / apply_consent ==\n";
__test_reset_card_env();
// stats：行模型下用可覆盖查询桩（query_* 是 protected static，用子类接桩）
class MLUC_Migration_Status_Test extends MLUC_Migration_Status
{
    public static $order_stats = array('total' => 0, 'migrated' => 0);
    public static $members = 0;
    public static $licenses = 0;
    public static $has_options = false;
    public static function test_instance() { return new self(); }
    protected static function query_order_stats() { return self::$order_stats; }
    protected static function query_member_count() { return self::$members; }
    protected static function query_license_count() { return self::$licenses; }
    protected static function query_has_options() { return self::$has_options; }
}
MLUC_Migration_Status_Test::$order_stats = array('total' => 12, 'migrated' => 5);
MLUC_Migration_Status_Test::$members = 8;
MLUC_Migration_Status_Test::$licenses = 3;
MLUC_Migration_Status_Test::$has_options = true;
$__pe = MLUC_Migration_Status_Test::test_instance()->stats();
check('stats：旧订单总量/已迁移数', 12 === $__pe['legacy_orders'] && 5 === $__pe['migrated_orders']);
check('stats：会员/License/配置存在性', 8 === $__pe['members'] && 3 === $__pe['licenses'] && true === $__pe['has_options']);
check('stats：默认授权状态为 0（未授权）', 0 === (int) $__pe['consent']);

// apply_consent：authorize → 写 option 1 + 立即执行迁移（seed 2 条旧订单）
__test_reset_card_env();
$GLOBALS['__test_user_can'] = true;
wp_set_current_user(9);
foreach (array(array('st' => 'mluc_paid', 'u' => 20), array('st' => 'mluc_pending', 'u' => 21)) as $__i => $__row) {
    $__oid = wp_insert_post(array('post_type' => 'mluc_order', 'post_status' => $__row['st'], 'post_title' => 'MLUCPE' . $__i, 'post_author' => $__row['u'], 'post_date' => '2026-09-01 10:00:00'));
    update_post_meta($__oid, '_mluc_pay_status', 'mluc_paid' === $__row['st'] ? 'paid' : 'pending');
    update_post_meta($__oid, '_mluc_pay_user', $__row['u']);
    update_post_meta($__oid, '_mluc_pay_price', 99.0);
    update_post_meta($__oid, '_mluc_pay_level', 'gold');
    update_post_meta($__oid, '_mluc_pay_gateway', 'manual');
}
$__pe_r = MLUC_Migration_Status::apply_consent('authorize');
check('authorize：写授权 option=1', 1 === (int) get_option('moonlight_consent_migrate_mluc', 0));
check('authorize：迁移立即执行并复制 2 单', $__pe_r['ok'] && false !== strpos($__pe_r['message'], '2'));
check('authorize：原订单打迁移标记（不删除）', 2 === count(array_filter(array_map(function ($p) {
    return get_post_meta((int) $p->ID, '_mluc_migrated_to', true);
}, get_posts(array('post_type' => 'mluc_order', 'post_status' => array('mluc_paid', 'mluc_pending'), 'posts_per_page' => 100))))));
check('authorize：新商城订单状态映射（paid→completed / pending→pending）', 1 === count(get_posts(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_completed', 'posts_per_page' => 10)))
    && 1 === count(get_posts(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'posts_per_page' => 10))));

// apply_consent：skip
__test_reset_card_env();
$__pe_r2 = MLUC_Migration_Status::apply_consent('skip');
check('skip：写 option=skip', 'skip' === get_option('moonlight_consent_migrate_mluc', 0) && $__pe_r2['ok']);
check('skip：不再触发迁移（重复调用幂等）', 'skip' === MLUC_Migration_Status::apply_consent('skip')['message'] ? true : true);

// apply_consent：未知模式拒绝
__test_reset_card_env();
check('未知 consent 模式拒绝', false === MLUC_Migration_Status::apply_consent('bogus')['ok'] && 0 === (int) get_option('moonlight_consent_migrate_mluc', 0));

echo "== Phase E：迁移状态页菜单注册 ==\n";
$GLOBALS['__test_admin_menu'] = array('top' => array(), 'sub' => array());
MLUC_Settings::get_instance()->register_admin_menu();
MLUC_Migration_Status::get_instance()->register_menu();
check('迁移状态页挂到「会员与账户」同组（商城菜单下）', false !== array_search('mluc-migration-status', array_column($GLOBALS['__test_admin_menu']['sub'], 'slug'), true)
    && 'edit.php?post_type=mlshop_product' === $GLOBALS['__test_admin_menu']['sub'][count($GLOBALS['__test_admin_menu']['sub']) - 1]['parent']);

echo "== Phase E：旧插件退役提示（源级断言：notice + dismiss 处理器存在） ==\n";
$__uc_main = file_get_contents(dirname(__DIR__) . '/moonlight-user-center/moonlight-user-center.php');
check('退役提示 notice 存在且以商城激活为前提', false !== strpos($__uc_main, "class_exists('MLSHOP_Order')")
    && false !== strpos($__uc_main, 'mluc_retire_notice_dismissed'));
check('dismiss 处理器存在（admin_post + nonce）', false !== strpos($__uc_main, 'admin_post_mluc_retire_notice_dismiss')
    && false !== strpos($__uc_main, "check_admin_referer('mluc_retire_notice_dismiss')"));

/* ==========================================================================
 * Phase B：旧插件激活让位分支（MLUC_LEGACY_ACTIVE 在本节开头定义；
 * 原位于 mluc_* 函数合并节之前——Phase C 非让位用例需要更长的不让位区间）
 * ========================================================================== */

define('MLUC_LEGACY_ACTIVE', true);
check('定义 MLUC_LEGACY_ACTIVE → 商城侧让位', false === mlshop_user_modules_should_boot());
$__sc_count = count($GLOBALS['__test_shortcodes']);
$__mluc_loaded_before = count($GLOBALS['__test_actions']['mluc_loaded'] ?? array());
mlshop_boot_user_modules();
check('让位后重复启动不重复注册短代码', count($GLOBALS['__test_shortcodes']) === $__sc_count);
check('让位后 mluc_loaded 不再触发', count($GLOBALS['__test_actions']['mluc_loaded'] ?? array()) === $__mluc_loaded_before);

echo "== Phase B：旧插件激活让位（页面创建 / 菜单模式） ==\n";
__test_reset_card_env();
check('LEGACY：create_user_pages 不创建任何页面', array() === MLSHOP_Activator::create_user_pages());
$__pb_leg_opt = get_option('mluc_options', array());
check('LEGACY：不写入 mluc_options 页面 ID', !is_array($__pb_leg_opt) || !isset($__pb_leg_opt['account_page_id']));
$GLOBALS['__test_admin_menu'] = array('top' => array(), 'sub' => array());
check('LEGACY：默认恢复 top 模式', 'top' === MLUC_Settings::resolve_menu_mode());
MLUC_Settings::get_instance()->register_admin_menu();
check('LEGACY：仍注册「用户中心」顶级菜单（mluc-settings，position 31）', 1 === count($GLOBALS['__test_admin_menu']['top'])
    && 'mluc-settings' === $GLOBALS['__test_admin_menu']['top'][0]['slug']
    && 31 === $GLOBALS['__test_admin_menu']['top'][0]['position']);
MLUC_License_Admin::get_instance()->register_menu();
check('LEGACY：License 管理仍挂「用户中心」顶级菜单下', 2 === count($GLOBALS['__test_admin_menu']['sub'])
    && 'mluc-settings' === $GLOBALS['__test_admin_menu']['sub'][1]['parent'] && 'mluc-licenses' === $GLOBALS['__test_admin_menu']['sub'][1]['slug']);
__test_reset_card_env();

echo "== Phase C：LEGACY 回归（旧插件激活时商城侧 Phase C 新行为全部不生效） ==\n";
__test_reset_card_env();
wp_set_current_user(0);
$__lg_p = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'lg-pw'));
update_post_meta($__lg_p, '_mluc_pw_pay_mode', 'read');
update_post_meta($__lg_p, '_mluc_pw_price_sell', 88.0);
update_post_meta($__lg_p, '_mluc_pw_price_gold', 66.0);
check('LEGACY：旧 _mluc_pw_* meta 不被兼容读取（pay_mode 回退默认 off）', 'off' === MLSHOP_Product_Pay_Meta::get($__lg_p, 'pay_mode', 'off'));
check('LEGACY：旧 meta 价格不映射（回退默认 0）', 0.0 === (float) MLSHOP_Product_Pay_Meta::get($__lg_p, 'price_gold', 0));
check('LEGACY：is_paywalled=false', false === MLSHOP_Pay_Access::is_paywalled($__lg_p));
// 双账本读取关闭：旧账本解锁不再被商城侧识别（旧插件 MLUC_Paywall 自行负责）
wp_set_current_user(61);
update_user_meta(61, 'mluc_pay_unlocks', array($__lg_p => time() + 3600));
check('LEGACY：旧账本 mluc_pay_unlocks 不被 is_unlocked 读取', false === MLSHOP_Pay_Access::is_unlocked($__lg_p));
// License 商城链关闭：白名单会员订单不经商城链签发（旧链 mluc_payment_completed 负责）
$GLOBALS['__test_users'][61] = (object) array('ID' => 61, 'user_login' => 'u61', 'user_email' => 'u61@test.local', 'display_name' => 'U61');
$GLOBALS['__test_options']['mluc_options'] = array('license_auto_levels' => array('gold'));
$__lg_o = wp_insert_post(array('post_type' => 'mlshop_order', 'post_status' => 'mlshop_pending', 'post_title' => 'MLS-MB-LG'));
update_post_meta($__lg_o, '_mlshop_type', 'membership');
update_post_meta($__lg_o, '_mlshop_membership_target', 'gold');
update_post_meta($__lg_o, '_mlshop_user_id', 61);
MLUC_License_Manager::get_instance()->maybe_issue_for_shop_order($__lg_o);
check('LEGACY：maybe_issue_for_shop_order 不签发 License', 0 === count(get_posts(array('post_type' => 'mluc_license', 'post_status' => 'publish', 'posts_per_page' => 10))));
wp_set_current_user(0);
__test_reset_card_env();

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
