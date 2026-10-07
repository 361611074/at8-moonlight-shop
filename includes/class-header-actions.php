<?php
/**
 * Astra 页眉购物车 / 收藏按钮（带实时数量角标）。
 *
 * 通过 Astra 的 astra_header 钩子把按钮插入页眉右侧，
 * 数量随加购 / 收藏 / 改数量实时更新（见 assets/js/mlshop.js）。
 * 未登录用户访问购物车 / 收藏页时跳登录，登录后回跳原页（见 maybe_redirect_guest）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Header_Actions
{
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Astra 页眉：旧 Header Builder (`astra_header`) 与新 Header Builder 各挂一次。
        // - `astra_render_header_column` (primary, right) → 插入到 right section 内最末（最贴近最终位置）
        // - `astra_header` → 旧架构 fallback
        // - `astra_header_primary_container_after` → 新架构 row 末尾 fallback（JS 也会把按钮 move 到 right）
        // 渲染次数由 self::$rendered 守护，保证只有一个副本。
        add_action('astra_render_header_column', array($this, 'render'), 15, 2);
        add_action('astra_header', array($this, 'render'), 15);
        add_action('astra_header_primary_container_after', array($this, 'render'), 15);
        // 懒创建「我的收藏」页面，保证收藏按钮链接可用（无需重新激活插件）
        add_action('init', array($this, 'ensure_favorites_page'), 5);
        // 未登录访问购物车 / 收藏页 → 跳登录并在登录后回跳
        add_action('template_redirect', array($this, 'maybe_redirect_guest'));
        // 非生产环境（开发 / staging）：禁止浏览器缓存前台 HTML，避免匿名访客
        // 看到「页眉按钮上线前」的陈旧快照（M5：不再按域名硬编码判断测试站）。
        // 默认 wp_get_environment_type() !== 'production' 时启用；
        // 可通过过滤器 moonlight_disable_cache 强制开关（返回 true = 禁缓存）。
        add_action('send_headers', array($this, 'prevent_front_cache'), 5);
        // 外观自定义：把后台设置输出为 CSS 变量（桌面 / 移动端共用同一套）
        add_action('wp_head', array($this, 'render_style_vars'), 20);
    }

    /**
     * 默认外观值（后台未配置时使用）。
     *
     * @return array
     */
    public static function defaults()
    {
        return array(
            'size'         => 36,
            'icon_size'    => 22,
            'radius'       => 50,
            'gap'          => 10,
            'margin_left'  => 14,
            'color'        => '',
            'bg'           => 'rgba(120, 120, 120, .08)',
            'bg_hover'     => 'rgba(34, 113, 177, .18)',
            'badge_bg'     => '#e53935',
            'badge_text'   => '#ffffff',
            'show_badge'   => 1,
            'mobile_top'   => 0,
            'mobile_right' => 0,
        );
    }

    /**
     * 读取后台配置并与默认值合并。
     *
     * @return array
     */
    public static function get_style()
    {
        $saved = get_option('mlshop_header_actions', array());
        $saved = is_array($saved) ? $saved : array();
        return wp_parse_args($saved, self::defaults());
    }

    /**
     * 输出 CSS 变量。值已在保存时 sanitize，这里再做一次数值收敛后拼进 style。
     */
    public function render_style_vars()
    {
        if (is_admin()) {
            return;
        }
        $s = self::get_style();
        $d = self::defaults();

        // 与默认值一致的项不输出：避免每页多输出无意义样式，
        // 也避免把默认值写死成 inline 变量后 CSS 里的 fallback 永远生效不到。
        $num = function ($key, $min, $max) use ($s, $d) {
            $v = (float) $s[$key];
            if ($v <= 0 || (string) $s[$key] === (string) $d[$key]) {
                return '';
            }
            return max($min, min($max, $v)) . 'px';
        };
        $color = function ($key) use ($s, $d) {
            $v = trim((string) $s[$key]);
            $def = trim((string) $d[$key]);
            if ('' === $v || $v === $def) {
                return '';
            }
            // 仅允许安全的颜色写法（hex 或 rgba()/rgb()/var()），杜绝任意字符串注入
            if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)
                || preg_match('/^rgba?\(\s*[\d.\s,%]+\)$/', $v)
                || preg_match('/^var\(--[a-zA-Z0-9_-]+\)$/', $v)) {
                return $v;
            }
            return '';
        };

        $vars = array();
        $map  = array(
            '--mlshop-ha-size'       => $num('size', 20, 80),
            '--mlshop-ha-icon'       => $num('icon_size', 12, 60),
            '--mlshop-ha-radius'     => $num('radius', 0, 60),
            '--mlshop-ha-gap'        => $num('gap', 0, 60),
            '--mlshop-ha-margin'     => $num('margin_left', 0, 200),
            '--mlshop-ha-color'      => $color('color'),
            '--mlshop-ha-bg'         => $color('bg'),
            '--mlshop-ha-bg-hover'   => $color('bg_hover'),
            '--mlshop-ha-badge-bg'   => $color('badge_bg'),
            '--mlshop-ha-badge-text' => $color('badge_text'),
        );
        foreach ($map as $k => $v) {
            if ('' !== $v) {
                $vars[] = $k . ':' . $v;
            }
        }

        $css = '';
        if (!empty($vars)) {
            $css = ':root{' . implode(';', $vars) . '}';
        }
        if ((int) $s['show_badge'] !== 1) {
            $css .= '.mlshop-header-count{display:none;}';
        }
        // 移动端固定位置：仅在后台显式填写（>0）时覆盖 JS 的自动对齐
        $mt = (float) $s['mobile_top'];
        $mr = (float) $s['mobile_right'];
        $dmt = (float) $d['mobile_top'];
        $dmr = (float) $d['mobile_right'];
        if ($mt > 0 || $mr > 0) {
            $css .= '.mlshop-header-mobile-float{';
            if ($mt > 0 && $mt !== $dmt) {
                $css .= 'top:' . max(0, min(400, $mt)) . 'px;';
            }
            if ($mr > 0 && $mr !== $dmr) {
                $css .= 'right:' . max(0, min(400, $mr)) . 'px;';
            }
            $css .= '}';
        }

        if ('' === $css) {
            return; // 全默认 → 不输出任何 style
        }
        echo '<style id="mlshop-header-actions-vars">' . esc_html($css) . '</style>' . "\n";
    }

    /**
     * 非生产环境前台 GET 请求关闭浏览器缓存。
     *
     * 现象：匿名首页服务端正确输出了页眉按钮，但因响应无 Cache-Control / 无 Set-Cookie，
     * 浏览器会启发式缓存该页；登录态带 cookie 不会被缓存 → 登录看得到、未登录看不到旧快照。
     * 这里显式 no-cache，保证每次都是最新渲染。后台 / AJAX / 非 GET 一律不动。
     *
     * 判定（修复审计 M5，移除硬编码测试域名）：
     *   wp_get_environment_type() !== 'production' → 默认禁缓存；
     *   过滤器 moonlight_disable_cache 可按站点覆盖（生产站需要时也能开启）。
     */
    public function prevent_front_cache()
    {
        if (is_admin() || wp_doing_ajax() || defined('DOING_AJAX') || defined('DOING_CRON')) {
            return;
        }
        if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        if (!apply_filters('moonlight_disable_cache', wp_get_environment_type() !== 'production')) {
            return;
        }
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /** @var bool 防止两个钩子重复输出时双发按钮 */
    private $rendered = false;

    /**
     * 确保「我的收藏」页面存在（仅当尚未配置时创建一次，已存在则复用避免重复）。
     */
    public function ensure_favorites_page()
    {
        $page_id = (int) mlshop_get_option('favorites_page_id', 0);
        if ($page_id && get_post($page_id)) {
            return;
        }
        // 审计 M-1：按 slug 找回前校验内容确属本插件短代码（防 slug 抢占劫持入口）。
        $existing = get_page_by_path('favorites');
        if ($existing && false !== strpos((string) $existing->post_content, '[mlshop_favorites]')) {
            $page_id = $existing->ID;
        } else {
            $created = wp_insert_post(array(
                'post_title'   => __('我的收藏', 'at8-moonlight-shop'),
                'post_name'    => 'favorites',
                'post_content' => '[mlshop_favorites]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ));
            $page_id = (is_wp_error($created)) ? 0 : $created;
        }
        if ($page_id > 0) {
            $options = get_option('mlshop_options', array());
            $options['favorites_page_id'] = $page_id;
            update_option('mlshop_options', $options);
        }
    }

    /**
     * 未登录用户访问购物车 / 收藏页时，跳转到登录页，并在登录后回跳原页面。
     *
     * 挂在 template_redirect（头部发送前），可安全调用 wp_redirect。
     * 通过 cart/favorites 页面 ID 判定当前页，避免误伤其它页面。
     */
    public function maybe_redirect_guest()
    {
        if (is_user_logged_in()) {
            return;
        }
        $cart_id = (int) mlshop_get_option('cart_page_id', 0);
        $fav_id  = (int) mlshop_get_option('favorites_page_id', 0);
        if (!$cart_id && !$fav_id) {
            return;
        }
        $current = (int) get_queried_object_id();
        $is_target = ($cart_id && $current === $cart_id) || ($fav_id && $current === $fav_id);
        if (!$is_target) {
            return;
        }
        $redirect = get_permalink($current);
        $login    = function_exists('mluc_get_login_url')
            ? mluc_get_login_url($redirect)
            : wp_login_url($redirect);
        wp_redirect($login);
        exit;
    }

    /**
     * 渲染页眉购物车 / 收藏按钮（含实时数量角标）。
     *
     * 触发源：
     * - `astra_header` / `astra_header_primary_container_after` → 0 参数旧钩子 / row 末尾钩子 → 渲染
     * - `astra_render_header_column` → 2 参数，行内 section 名（left/center/right/...） → 仅在 right 才渲染
     *
     * 由 `$rendered` 守护，保证按钮只有一个副本。
     */
    public function render($row = null, $section = null)
    {
        if ($this->rendered) {
            return;
        }
        // `astra_render_header_column` 同时在 left / center / right 三个 slot 都触发，仅响应 right。
        // 其它钩子（传 0 个参数）视为行级入口，正常渲染。
        if (func_num_args() >= 2 && !in_array($section, array('right', 'mobile_right'), true)) {
            return;
        }
        $this->rendered = true;
        $cart_count = MLSHOP_Cart::get_instance()->get_count();
        $fav_count  = mlshop_favorite_count();
        $cart_url   = mlshop_get_page_url('cart');
        $fav_url    = mlshop_get_page_url('favorites');
        ?>
        <div class="mlshop-header-actions" role="navigation" aria-label="<?php esc_attr_e('购物车与收藏', 'at8-moonlight-shop'); ?>">
            <a class="mlshop-header-btn mlshop-header-cart" href="<?php echo esc_url($cart_url); ?>" aria-label="<?php esc_attr_e('购物车', 'at8-moonlight-shop'); ?>">
                <span class="mlshop-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="21" r="1"></circle>
                        <circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                </span>
                <span class="mlshop-header-count mlshop-header-cart-count<?php echo $cart_count > 0 ? '' : ' is-empty'; ?>"><?php echo (int) $cart_count; ?></span>
            </a>
            <a class="mlshop-header-btn mlshop-header-fav" href="<?php echo esc_url($fav_url); ?>" aria-label="<?php esc_attr_e('收藏', 'at8-moonlight-shop'); ?>">
                <span class="mlshop-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                    </svg>
                </span>
                <span class="mlshop-header-count mlshop-header-fav-count<?php echo $fav_count > 0 ? '' : ' is-empty'; ?>"><?php echo (int) $fav_count; ?></span>
            </a>
            <?php
            // 会员中心按钮：未登录显示「登录 / 注册」，登录后显示「会员中心 / 登出」。
            // 依赖 moonlight-user-center 提供的 mluc_* 辅助函数；未启用时回退到 WP 原生地址。
            $mlshop_account_url  = function_exists('mluc_get_account_url')
                ? mluc_get_account_url()
                : home_url('/account/');
            $mlshop_login_url    = function_exists('mluc_get_login_url')
                ? mluc_get_login_url()
                : wp_login_url();
            $mlshop_register_url = function_exists('mluc_get_register_url')
                ? mluc_get_register_url()
                : wp_registration_url();
            $mlshop_logout_url   = function_exists('mluc_get_account_url')
                ? wp_nonce_url(add_query_arg('mluc_logout', '1', home_url()), 'mluc_logout')
                : wp_logout_url(home_url());
            ?>
            <div class="mlshop-header-account">
                <button type="button" class="mlshop-header-btn mlshop-header-member" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e('会员中心', 'at8-moonlight-shop'); ?>">
                    <span class="mlshop-header-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                </button>
                <div class="mlshop-member-menu" role="menu">
                    <?php if (is_user_logged_in()) : ?>
                        <a class="mlshop-member-item" role="menuitem" href="<?php echo esc_url($mlshop_account_url); ?>"><?php echo esc_html__('会员中心', 'at8-moonlight-shop'); ?></a>
                        <a class="mlshop-member-item mlshop-member-logout" role="menuitem" href="<?php echo esc_url($mlshop_logout_url); ?>"><?php echo esc_html__('登出', 'at8-moonlight-shop'); ?></a>
                    <?php else : ?>
                        <a class="mlshop-member-item" role="menuitem" href="<?php echo esc_url($mlshop_login_url); ?>"><?php echo esc_html__('登录', 'at8-moonlight-shop'); ?></a>
                        <a class="mlshop-member-item" role="menuitem" href="<?php echo esc_url($mlshop_register_url); ?>"><?php echo esc_html__('注册', 'at8-moonlight-shop'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
