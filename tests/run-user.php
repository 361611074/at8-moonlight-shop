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

/* ---------- 2) 商城主文件：MLUC_ 自动加载器（includes/user/）+ merged helpers ---------- */
require __DIR__ . '/../moonlight-shop/moonlight-shop.php';

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

define('MLUC_LEGACY_ACTIVE', true);
check('定义 MLUC_LEGACY_ACTIVE → 商城侧让位', false === mlshop_user_modules_should_boot());
$__sc_count = count($GLOBALS['__test_shortcodes']);
mlshop_boot_user_modules();
check('让位后重复启动不重复注册短代码', count($GLOBALS['__test_shortcodes']) === $__sc_count);
check('让位后 mluc_loaded 不再触发', 1 === count($GLOBALS['__test_actions']['mluc_loaded']));

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
 * Phase B：旧插件激活让位分支（MLUC_LEGACY_ACTIVE 已在上文定义）
 * ========================================================================== */

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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
