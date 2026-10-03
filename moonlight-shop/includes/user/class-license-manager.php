<?php
/**
 * License 管理器：License 签发 / 激活 / 停用 / 验证 / 宽限期 / 撤销 / 续期。
 *
 * - 存储：CPT mluc_license（post_title = License Key）+ postmeta（product/status/site/expires…），
 *   不新建数据表；订单与 License 严格分离（一单可续期同一 License，续费不新建）。
 * - 验证：本地校验（状态 + 到期 + 站点绑定）为主；配置 license_server_url 后走远程
 *   License Server（结果缓存 12h，网络失败进入 Grace Period 默认 7 天），
 *   任何情况下 License Server 故障不影响 Free 功能。
 * - Key 规则：MLUC-PRO-XXXX-XXXX-XXXX-XXXX，random_bytes 安全随机，不可预测。
 * - Pro 功能判断统一走 is_product_active()，全站不散落 if 判断。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_License_Manager
{
    const CPT = 'mluc_license';

    /** Pro 产品标识（与 Pro 插件一致）。 */
    const PRODUCT_PRO = 'moonlight-user-center-pro';

    const STATUS_ACTIVE    = 'active';
    const STATUS_INACTIVE  = 'inactive';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_REVOKED   = 'revoked';
    const STATUS_SUSPENDED = 'suspended';

    /** 远程验证结果缓存时长（秒）。 */
    const REMOTE_CACHE_TTL = 12 * HOUR_IN_SECONDS;
    /** 宽限期（天）：远程验证失败后允许继续运行的时长。 */
    const GRACE_DAYS = 7;

    private static $instance = null;

    /** @var array 请求内缓存（product => bool） */
    private $product_cache = array();

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_cpt'));
        // 支付完成 → 按后台配置自动颁发 / 续期 License（支付 → 订单 → License → Pro 链路）。
        add_action('mluc_payment_completed', array($this, 'maybe_issue_for_order'), 10, 3);
        // Phase C（支付并线）：商城订单付款 → License 自动颁发，替换 mluc_payment_completed 链
        // （会员购买统一下单到 mlshop_order 后旧链不再触发）。仅商城独立运行时启用；
        // 旧插件激活（LEGACY）时该链仍由旧插件按原逻辑负责。
        if (!defined('MLUC_LEGACY_ACTIVE')) {
            add_action('mlshop_order_paid', array($this, 'maybe_issue_for_shop_order'), 18);
        }
        // 退款 → 撤销该订单颁发的 License（§44）。
        add_action('mluc_order_refunded', array($this, 'revoke_for_order'));
        // 账户中心「我的 License」Tab。
        add_filter('mluc_account_tabs', array($this, 'register_tab'));
    }

    /**
     * License CPT（非公开；后台列表由 MLUC_License_Admin 自定义页面渲染）。
     */
    public function register_cpt()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => __('License 授权', 'moonlight-user-center'),
                'singular_name' => __('License 授权', 'moonlight-user-center'),
            ),
            'public'          => false,
            'show_ui'         => false,
            'show_in_rest'    => false,
            'supports'        => array('title'),
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ));
    }

    /* ---------------- Key / 签发 ---------------- */

    /**
     * 生成 License Key：MLUC-PRO-XXXX-XXXX-XXXX-XXXX（安全随机，查重重试）。
     *
     * @return string
     */
    public static function generate_key()
    {
        for ($i = 0; $i < 5; $i++) {
            $key = 'MLUC-PRO-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4));
            if (!self::get_by_key($key)) {
                return $key;
            }
        }
        return 'MLUC-PRO-' . strtoupper(bin2hex(random_bytes(16)));
    }

    /**
     * 签发 License。默认创建即 active 并绑定当前站点（自动颁发 / 后台手工签发两条路径共用）。
     *
     * @param array $args user_id / product / days(0=永久) / limit(激活数上限) / order_id / email
     * @return int|WP_Error License post ID
     */
    public static function create($args = array())
    {
        $args = wp_parse_args($args, array(
            'user_id'  => 0,
            'product'  => self::PRODUCT_PRO,
            'days'     => 0,
            'limit'    => 1,
            'order_id' => 0,
            'email'    => '',
        ));
        $product = sanitize_key((string) $args['product']);
        if ('' === $product) {
            return new WP_Error('mluc_lic_product', __('License 产品标识无效。', 'moonlight-user-center'));
        }
        $user_id = (int) $args['user_id'];
        $email   = (string) $args['email'];
        if ($user_id && !$email) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $email = $user->user_email;
            }
        }
        $key  = self::generate_key();
        $site = self::current_site();

        $post_id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => $key,
            'post_author' => $user_id,
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $expires = ((int) $args['days'] > 0) ? (int) strtotime('+' . (int) $args['days'] . ' days', time()) : 0;
        update_post_meta($post_id, '_mluc_license_product', $product);
        update_post_meta($post_id, '_mluc_license_status', self::STATUS_ACTIVE);
        update_post_meta($post_id, '_mluc_license_site', $site['url']);
        update_post_meta($post_id, '_mluc_license_site_hash', $site['hash']);
        update_post_meta($post_id, '_mluc_license_limit', max(1, (int) $args['limit']));
        update_post_meta($post_id, '_mluc_license_count', 1);
        update_post_meta($post_id, '_mluc_license_expires', $expires);
        update_post_meta($post_id, '_mluc_license_order', (int) $args['order_id']);
        update_post_meta($post_id, '_mluc_license_email', sanitize_email($email));
        update_post_meta($post_id, '_mluc_license_last_check', current_time('mysql'));

        do_action('mluc_license_created', $post_id, $key, $product, $user_id);
        return $post_id;
    }

    /**
     * 支付完成后的自动颁发 / 续期（§21/§24）：
     * 后台「自动颁发 License 的会员等级」包含该等级时，为下单用户创建或续期 License。
     *
     * @param int    $order_id 订单 ID。
     * @param int    $user_id  用户 ID。
     * @param string $level    购买的会员等级。
     */
    public function maybe_issue_for_order($order_id, $user_id, $level)
    {
        if (!self::is_auto_level($level)) {
            return;
        }
        // 只统计已完单的会员购买（付费墙订单的 level 参数为 paywall:xx，天然不匹配）。
        if (0 === strpos((string) $level, 'paywall:')) {
            return;
        }
        self::issue_for_level($order_id, $user_id, $level);
    }

    /**
     * 商城订单付款后的自动颁发 / 续期（Phase C：mlshop_order_paid → License 链）。
     *
     * 仅商城独立运行（旧插件未激活）时生效；会员升级订单（_mlshop_type=membership，
     * 目标等级在 _mlshop_membership_target）且等级在「自动颁发 License 的会员等级」
     * 白名单内时签发 / 续期，复用与旧链完全一致的 product 语义与 user+product 复用逻辑。
     * 付费墙订单 / 商品订单 / 访客订单不触发。
     *
     * @param int $order_id mlshop_order 订单 ID。
     */
    public function maybe_issue_for_shop_order($order_id)
    {
        if (defined('MLUC_LEGACY_ACTIVE')) {
            return; // 旧插件激活：License 颁发链仍由 mluc_payment_completed 负责
        }
        $order_id = (int) $order_id;
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            return;
        }
        if ((string) get_post_meta($order_id, '_mlshop_type', true) !== 'membership') {
            return;
        }
        $level = (string) get_post_meta($order_id, '_mlshop_membership_target', true);
        if ('' === $level || !self::is_auto_level($level)) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if (!$user_id || !get_user_by('id', $user_id)) {
            return; // 孤立订单（用户已删除）不颁发
        }
        self::issue_for_level($order_id, $user_id, $level);
    }

    /**
     * 等级是否在后台「自动颁发 License 的会员等级」白名单内。
     */
    public static function is_auto_level($level)
    {
        $auto_levels = (array) mluc_get_option('license_auto_levels', array());
        return !empty($auto_levels) && in_array((string) $level, array_map('strval', $auto_levels), true);
    }

    /**
     * 按等级签发 / 续期 License（旧链与新商城链共用的落库逻辑）。
     *
     * product 语义保持与原 mluc_payment_completed 链一致：默认 PRODUCT_PRO，
     * 可由 mluc_order_license_product 过滤器按订单 / 等级改写；同一用户 + 同一
     * 产品复用既有 License（续费延长 expires_at，不新建）。
     */
    private static function issue_for_level($order_id, $user_id, $level)
    {
        $product = apply_filters('mluc_order_license_product', self::PRODUCT_PRO, $order_id, $level);
        $days    = MLUC_Membership::get_level_validity((string) $level);

        // 同一用户 + 同一产品复用既有 License（续费延长 expires_at，不新建）。
        $existing = self::find_user_product_license((int) $user_id, (string) $product);
        if ($existing) {
            self::renew($existing, $days, (int) $order_id);
            return;
        }
        $created = self::create(array(
            'user_id'  => (int) $user_id,
            'product'  => (string) $product,
            'days'     => $days,
            'order_id' => (int) $order_id,
        ));
        if (!is_wp_error($created)) {
            MLUC_Payment_Log::write($order_id, 'system', 'license_issued', 'ok', array('code' => (string) $product));
        }
    }

    /**
     * 查找用户在某产品下唯一的 License（无则返回 0）。
     */
    public static function find_user_product_license($user_id, $product)
    {
        $posts = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'author'         => (int) $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array('key' => '_mluc_license_product', 'value' => sanitize_key((string) $product)),
            ),
        ));
        return $posts ? (int) $posts[0] : 0;
    }

    /**
     * 退款后撤销由该订单颁发 / 续期的全部 License（§44：退款 → License revoked）。
     */
    public function revoke_for_order($order_id)
    {
        $posts = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array('key' => '_mluc_license_order', 'value' => (int) $order_id),
            ),
        ));
        foreach ($posts as $post_id) {
            if (self::STATUS_REVOKED !== (string) get_post_meta((int) $post_id, '_mluc_license_status', true)) {
                self::revoke((int) $post_id);
            }
        }
    }

    /* ---------------- 查询 ---------------- */

    /**
     * 按 Key 查询 License post。
     *
     * @return WP_Post|null
     */
    public static function get_by_key($key)
    {
        $key = trim(strtoupper((string) $key));
        if ('' === $key || strlen($key) > 64) {
            return null;
        }
        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status = 'publish' LIMIT 1",
            self::CPT,
            $key
        ));
        return $post_id ? get_post($post_id) : null;
    }

    /**
     * 生效状态计算：显式状态优先；active 但已过 expires_at → expired（并落库，便于后台筛选）。
     */
    public static function effective_status($post_id)
    {
        $post_id = (int) $post_id;
        $status  = (string) get_post_meta($post_id, '_mluc_license_status', true);
        if (in_array($status, array(self::STATUS_REVOKED, self::STATUS_SUSPENDED, self::STATUS_INACTIVE), true)) {
            return $status;
        }
        $expires = (int) get_post_meta($post_id, '_mluc_license_expires', true);
        if ($expires && $expires < time()) {
            if (self::STATUS_EXPIRED !== $status) {
                update_post_meta($post_id, '_mluc_license_status', self::STATUS_EXPIRED);
                do_action('mluc_license_expired', $post_id);
            }
            return self::STATUS_EXPIRED;
        }
        return (self::STATUS_ACTIVE === $status) ? self::STATUS_ACTIVE : self::STATUS_INACTIVE;
    }

    /**
     * 当前站点指纹（host + path 归一化，协议无关，兼容 http/https、www 变更）。
     *
     * @return array array(url, hash)
     */
    public static function current_site()
    {
        $url  = untrailingslashit(home_url());
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        if (0 === strpos($host, 'www.')) {
            $host = substr($host, 4);
        }
        $normalized = $host . ($path ? '/' . $path : '');
        return array(
            'url'  => $url,
            'hash' => md5($normalized),
        );
    }

    /**
     * License 是否对当前站点生效（本地规则：active + 站点绑定匹配 + 未过期）。
     */
    public static function is_bound_to_current_site($post_id)
    {
        $site = self::current_site();
        return (string) get_post_meta((int) $post_id, '_mluc_license_site_hash', true) === $site['hash'];
    }

    /**
     * 产品在当前站点是否处于有效授权状态（Pro 功能开关统一入口）。
     *
     * - 未配置远程 License Server：本地校验（状态 / 到期 / 站点绑定）。
     * - 配置了 license_server_url：远程验证（缓存 12h）；网络失败时按宽限期
     *   （默认 7 天）沿用最后一次已知有效结果，超期才判定失效；Server 故障
     *   绝不影响 Free 功能（本函数仅被 Pro 扩展调用）。
     *
     * @param string $product 产品标识。
     * @return bool
     */
    public function is_product_active($product = self::PRODUCT_PRO)
    {
        $product = sanitize_key((string) $product);
        if ('' === $product) {
            return false;
        }
        if (array_key_exists($product, $this->product_cache)) {
            return $this->product_cache[$product];
        }
        $active = $this->compute_product_active($product);
        $this->product_cache[$product] = $active;
        return $active;
    }

    private function compute_product_active($product)
    {
        $posts = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array('key' => '_mluc_license_product', 'value' => $product),
            ),
        ));

        $server = trim((string) mluc_get_option('license_server_url', ''));
        foreach ($posts as $post_id) {
            if (!self::is_bound_to_current_site($post_id)) {
                continue;
            }
            if (self::STATUS_ACTIVE !== self::effective_status($post_id)) {
                continue;
            }
            if ('' === $server) {
                return true; // 本地验证。
            }
            if ($this->remote_verify_ok($post_id, $product, $server)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 远程验证（含缓存 + Grace Period）。
     */
    private function remote_verify_ok($post_id, $product, $server)
    {
        $key     = (string) get_post($post_id)->post_title;
        $cache_k = 'mluc_lic_verify_' . md5($key . '|' . $product);

        $cached = get_transient($cache_k);
        if (is_array($cached) && array_key_exists('valid', $cached)) {
            return (bool) $cached['valid'];
        }

        $response = wp_remote_post(
            untrailingslashit($server) . '/wp-json/mluc-license/v1/verify',
            array(
                'timeout' => 10,
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode(array(
                    'license_key' => $key,
                    'product'     => $product,
                    'site_url'    => home_url(),
                )),
            )
        );

        if (is_wp_error($response)) {
            // 网络失败：宽限期内沿用最后一次成功验证的结果，绝不让 Server 故障即时禁用 Pro。
            $last_check = (int) get_post_meta($post_id, '_mluc_license_last_check_ts', true);
            $grace_end  = $last_check + self::GRACE_DAYS * DAY_IN_SECONDS;
            if ($last_check && time() < $grace_end) {
                return (bool) get_post_meta($post_id, '_mluc_license_last_valid', true);
            }
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $valid = !empty($body['valid']);
        update_post_meta($post_id, '_mluc_license_last_check', current_time('mysql'));
        update_post_meta($post_id, '_mluc_license_last_check_ts', time());
        update_post_meta($post_id, '_mluc_license_last_valid', $valid ? 1 : 0);
        set_transient($cache_k, array('valid' => $valid), self::REMOTE_CACHE_TTL);
        return $valid;
    }

    /* ---------------- 激活 / 停用 / 续期 / 撤销 ---------------- */

    /**
     * 激活（绑定当前站点）。已绑定本站时幂等成功；绑定其他站点时报错（域名迁移 = 先停用再激活）。
     *
     * @param string $key License Key。
     * @return true|WP_Error
     */
    public static function activate($key)
    {
        $post = self::get_by_key($key);
        if (!$post) {
            return new WP_Error('mluc_lic_notfound', __('License Key 不存在，请核对后重试。', 'moonlight-user-center'));
        }
        $status = self::effective_status($post->ID);
        if (self::STATUS_REVOKED === $status) {
            return new WP_Error('mluc_lic_revoked', __('该 License 已被撤销，请联系管理员。', 'moonlight-user-center'));
        }
        if (self::STATUS_SUSPENDED === $status) {
            return new WP_Error('mluc_lic_suspended', __('该 License 已被暂停，请联系管理员。', 'moonlight-user-center'));
        }
        if (self::STATUS_EXPIRED === $status) {
            return new WP_Error('mluc_lic_expired', __('该 License 已过期，请续费后重新激活。', 'moonlight-user-center'));
        }

        $site = self::current_site();
        $bound_hash = (string) get_post_meta($post->ID, '_mluc_license_site_hash', true);
        $count      = (int) get_post_meta($post->ID, '_mluc_license_count', true);
        $limit      = (int) get_post_meta($post->ID, '_mluc_license_limit', true);

        if ($bound_hash && $bound_hash !== $site['hash']) {
            return new WP_Error(
                'mluc_lic_bound',
                sprintf(
                    /* translators: %s: 已绑定的站点地址 */
                    __('该 License 已绑定到其他站点（%s）。如需迁移，请先在原站点停用。', 'moonlight-user-center'),
                    (string) get_post_meta($post->ID, '_mluc_license_site', true)
                )
            );
        }
        if (!$bound_hash && $count >= $limit) {
            return new WP_Error('mluc_lic_limit', __('该 License 的激活数量已达上限。', 'moonlight-user-center'));
        }

        if (!$bound_hash) {
            $count++;
        }
        update_post_meta($post->ID, '_mluc_license_site', $site['url']);
        update_post_meta($post->ID, '_mluc_license_site_hash', $site['hash']);
        update_post_meta($post->ID, '_mluc_license_count', $count);
        update_post_meta($post->ID, '_mluc_license_status', self::STATUS_ACTIVE);
        update_post_meta($post->ID, '_mluc_license_last_check', current_time('mysql'));
        update_post_meta($post->ID, '_mluc_license_last_check_ts', time());
        delete_transient('mluc_lic_verify_' . md5((string) $post->post_title));

        do_action('mluc_license_activated', $post->ID, $site['url']);
        return true;
    }

    /**
     * 停用（解绑当前站点，允许迁移到其他站点）。
     *
     * @param string $key License Key。
     * @return true|WP_Error
     */
    public static function deactivate($key)
    {
        $post = self::get_by_key($key);
        if (!$post) {
            return new WP_Error('mluc_lic_notfound', __('License Key 不存在，请核对后重试。', 'moonlight-user-center'));
        }
        $site = self::current_site();
        if ((string) get_post_meta($post->ID, '_mluc_license_site_hash', true) !== $site['hash']) {
            return new WP_Error('mluc_lic_site', __('该 License 未绑定当前站点。', 'moonlight-user-center'));
        }
        $count = (int) get_post_meta($post->ID, '_mluc_license_count', true);
        update_post_meta($post->ID, '_mluc_license_count', max(0, $count - 1));
        update_post_meta($post->ID, '_mluc_license_site', '');
        update_post_meta($post->ID, '_mluc_license_site_hash', '');
        update_post_meta($post->ID, '_mluc_license_status', self::STATUS_INACTIVE);
        delete_transient('mluc_lic_verify_' . md5((string) $post->post_title));

        do_action('mluc_license_deactivated', $post->ID, $site['url']);
        return true;
    }

    /**
     * 续期：在「现有有效期」与「当前时间」取较大者上叠加 N 天（0 = 转为永久）。
     * 续费不新建 License（§24）；续期后重置 expired → active 并清除到期提醒标记。
     */
    public static function renew($post_id, $days, $order_id = 0)
    {
        $post_id = (int) $post_id;
        if (!$post_id || get_post_type($post_id) !== self::CPT) {
            return new WP_Error('mluc_lic_notfound', __('License 不存在。', 'moonlight-user-center'));
        }
        $days    = max(0, (int) $days);
        $expires = (int) get_post_meta($post_id, '_mluc_license_expires', true);
        $base    = ($expires && $expires > time()) ? $expires : time();
        $new_exp = ($days > 0) ? (int) strtotime('+' . $days . ' days', $base) : 0;

        update_post_meta($post_id, '_mluc_license_expires', $new_exp);
        if (self::STATUS_EXPIRED === (string) get_post_meta($post_id, '_mluc_license_status', true)
            || self::STATUS_INACTIVE === (string) get_post_meta($post_id, '_mluc_license_status', true)) {
            update_post_meta($post_id, '_mluc_license_status', self::STATUS_ACTIVE);
        }
        delete_post_meta($post_id, '_mluc_license_reminded');
        if ($order_id) {
            update_post_meta($post_id, '_mluc_license_order', (int) $order_id);
        }
        do_action('mluc_license_renewed', $post_id, $new_exp, (int) $order_id);
        return true;
    }

    /**
     * 撤销（管理员操作）：立即失效，用户侧不可恢复。
     */
    public static function revoke($post_id)
    {
        $post_id = (int) $post_id;
        if (!$post_id || get_post_type($post_id) !== self::CPT) {
            return new WP_Error('mluc_lic_notfound', __('License 不存在。', 'moonlight-user-center'));
        }
        update_post_meta($post_id, '_mluc_license_status', self::STATUS_REVOKED);
        delete_transient('mluc_lic_verify_' . md5((string) get_post($post_id)->post_title));
        do_action('mluc_license_revoked', $post_id);
        return true;
    }

    /**
     * 恢复（撤销 / 暂停的 License 重新置为 active，有效期不变）。
     */
    public static function restore($post_id)
    {
        $post_id = (int) $post_id;
        if (!$post_id || get_post_type($post_id) !== self::CPT) {
            return new WP_Error('mluc_lic_notfound', __('License 不存在。', 'moonlight-user-center'));
        }
        update_post_meta($post_id, '_mluc_license_status', self::STATUS_ACTIVE);
        delete_transient('mluc_lic_verify_' . md5((string) get_post($post_id)->post_title));
        do_action('mluc_license_restored', $post_id);
        return true;
    }

    /* ---------------- 用户侧查询 / Tab ---------------- */

    /**
     * 用户自己的 License 列表。
     */
    public static function get_user_licenses($user_id)
    {
        $posts = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'author'         => (int) $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));
        $out = array();
        foreach ($posts as $p) {
            $out[] = array(
                'key'      => (string) $p->post_title,
                'product'  => (string) get_post_meta($p->ID, '_mluc_license_product', true),
                'status'   => self::effective_status($p->ID),
                'site'     => (string) get_post_meta($p->ID, '_mluc_license_site', true),
                'expires'  => (int) get_post_meta($p->ID, '_mluc_license_expires', true),
                'date'     => get_the_date('', $p),
            );
        }
        return $out;
    }

    /**
     * 账户中心「我的 License」Tab（mluc_account_tabs 过滤器挂载）。
     */
    /**
     * 账户中心「我的 License」Tab —— **已停用**。
     *
     * 授权（Pro 授权码的签发 / 绑定域名 / 到期 / 解绑）属于站点运营方的
     * 后台事务，前台账户中心不展示：普通会员看到「我的授权」只会困惑，
     * 管理员在前台看到也属于错位。管理入口改由 MLUC_Licenses_Tab 注册为
     * wp-admin 子菜单（仅当授权中心表存在时出现）。
     *
     * @param array $tabs
     * @return array
     */
    public function register_tab($tabs)
    {
        return $tabs;
    }

    public function render_tab()
    {
        mluc_get_template('account-licenses', array(
            'licenses' => self::get_user_licenses(get_current_user_id()),
        ));
    }
}
