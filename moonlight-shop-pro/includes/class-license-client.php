<?php
/**
 * Pro License 客户端：is_active() 门禁统一入口 + 后台「Moonlight Pro → Pro 授权」页。
 *
 * - 引擎优先：class_exists('MLUC_License_Manager') 时直调
 *   MLUC_License_Manager::get_instance()->is_product_active($product)
 *   （站点 hash 绑定 / 12h 验证缓存 / 7 天宽限全部由引擎负责，Pro 零授权逻辑）；
 * - 双产品语义（MERGE-USER-CENTER.md Phase D）：依次检查新产品
 *   moonlight-shop-pro 与存量产品 moonlight-user-center-pro（Phase C 起商城
 *   订单签发的 License 沿用该标识，旧 Pro 存量授权亦为该标识）——任一激活
 *   即视为 Pro 激活，存量旧授权继续可用；旧 MLUCP Pro 仍激活时跳过双检查，
 *   仅查新产品（授权门禁由旧 Pro 负责，见 moonlight-shop-pro.php）；
 * - 引擎缺席（License 引擎未并入）：回退本地开关 option `mlpro_local_active`
 *   （默认 1）；
 * - 结果静态缓存：整体结果每请求一次，引擎模式下两个产品的检查结果分别
 *   缓存（product_cache 按产品标识分键）；授权状态可能变化的入口
 *   （保存设置）调用 clear_cache()。
 *
 * @package Moonlight_Shop_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLPRO_License_Client
{
    /** 本地模式开关 option 键（引擎缺席时生效，默认 1 = 激活）。 */
    const OPT_LOCAL_ACTIVE = 'mlpro_local_active';

    /** 新产品标识（引擎 is_product_active() 的产品参数）。 */
    const PRODUCT_NEW = 'moonlight-shop-pro';

    /** 存量产品标识（旧 MLUCP Pro 授权 + Phase C 起商城订单签发的 License）。 */
    const PRODUCT_LEGACY = 'moonlight-user-center-pro';

    private static $instance = null;

    /** @var bool|null 请求内缓存（null = 未计算） */
    private static $active_cache = null;

    /** @var string|null 'engine' | 'local'（随最近一次 is_active() 计算写入） */
    private static $mode_cache = null;

    /** @var array<string,bool> 引擎模式下按产品分别缓存（两产品互不串键） */
    private static $product_cache = array();

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_mlpro_license_save', array($this, 'handle_save'));
        add_action('admin_notices', array($this, 'render_notice'));
    }

    /**
     * Pro 是否处于有效授权（所有 Pro 模块启动前统一检查）。
     *
     * 引擎模式：新产品 / 存量产品任一激活即激活（存量旧授权继续可用）；
     * 旧 MLUCP Pro 激活时跳过双检查，仅查新产品。
     */
    public static function is_active()
    {
        if (null !== self::$active_cache) {
            return self::$active_cache;
        }
        if (class_exists('MLUC_License_Manager')) {
            // 引擎模式：签发 / 激活 / 站点绑定 / 宽限期 / 撤销全部由 MLUC 负责。
            self::$mode_cache = 'engine';
            if (self::legacy_pro_active()) {
                // 旧 MLUCP Pro 激活：保持单产品语义（旧 Pro 自身门禁查存量产品，
                // 本插件仅查新产品，互不纠缠）。
                self::$active_cache = self::engine_product_active(self::PRODUCT_NEW);
            } else {
                // 双产品语义：两产品都检查（不短路），结果按产品分别缓存。
                $active_new    = self::engine_product_active(self::PRODUCT_NEW);
                $active_legacy = self::engine_product_active(self::PRODUCT_LEGACY);
                self::$active_cache = $active_new || $active_legacy;
            }
        } else {
            // 本地模式：option 开关（默认 1）。
            self::$active_cache = ((int) get_option(self::OPT_LOCAL_ACTIVE, 1) === 1);
            self::$mode_cache   = 'local';
        }
        return self::$active_cache;
    }

    /**
     * 旧 MLUCP Pro（moonlight-user-center-pro）是否仍处于激活状态。
     *
     * MLUCP_VERSION 由旧 Pro 主文件顶层 define（先于 plugins_loaded 可见），
     * 是最可靠的激活标记；class_exists(..., false) 兜底非标准加载场景。
     */
    public static function legacy_pro_active()
    {
        return defined('MLUCP_VERSION') || class_exists('MLUCP_License_Client', false);
    }

    /**
     * 引擎模式下单产品授权检查（按产品分别缓存，两产品互不串键）。
     *
     * @param string $product 产品标识。
     * @return bool
     */
    private static function engine_product_active($product)
    {
        if (!array_key_exists($product, self::$product_cache)) {
            self::$product_cache[$product] = (bool) MLUC_License_Manager::get_instance()->is_product_active($product);
        }
        return self::$product_cache[$product];
    }

    /**
     * 两产品各自的引擎检查结果（设置页授权状态展示用）。
     *
     * 仅引擎模式且旧 MLUCP Pro 未激活时返回两产品明细；其余场景返回空数组
     * （本地模式无产品概念，旧 Pro 激活时不重复展示）。
     *
     * @return array<string,bool> 产品标识 => 是否激活
     */
    public static function product_status()
    {
        if (!class_exists('MLUC_License_Manager') || self::legacy_pro_active()) {
            return array();
        }
        return array(
            self::PRODUCT_NEW    => self::engine_product_active(self::PRODUCT_NEW),
            self::PRODUCT_LEGACY => self::engine_product_active(self::PRODUCT_LEGACY),
        );
    }

    /**
     * 当前授权来源：'engine'（License 引擎）| 'local'（本地开关）。
     */
    public static function get_mode()
    {
        if (null === self::$mode_cache) {
            self::is_active();
        }
        return self::$mode_cache;
    }

    /**
     * 清空请求内缓存（授权状态变化后调用；测试环境用同一入口重置）。
     */
    public static function clear_cache()
    {
        self::$active_cache  = null;
        self::$mode_cache    = null;
        self::$product_cache = array();
    }

    /* ---------------- 后台：Pro 授权页 ---------------- */

    public function register_menu()
    {
        // 顶级菜单（第一个子页指向本页，作为默认落地页）。
        add_menu_page(
            __('Moonlight Shop Pro', 'moonlight-shop-pro'),
            __('Moonlight Pro', 'moonlight-shop-pro'),
            'manage_options',
            'mlpro-license',
            array($this, 'render_page'),
            'dashicons-awards',
            56
        );
        add_submenu_page(
            'mlpro-license',
            __('Pro 授权', 'moonlight-shop-pro'),
            __('Pro 授权', 'moonlight-shop-pro'),
            'manage_options',
            'mlpro-license',
            array($this, 'render_page')
        );
    }

    /**
     * 操作结果 notice（transient 承载，重定向后展示一次，模式同 MLUCP）。
     */
    public function render_notice()
    {
        $notice = get_transient('mlpro_lic_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('mlpro_lic_notice_' . get_current_user_id());
        $class = empty($notice['error']) ? 'notice-success' : 'notice-error';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    private static function set_notice($message, $error = false)
    {
        set_transient('mlpro_lic_notice_' . get_current_user_id(), array(
            'message' => $message,
            'error'   => (bool) $error,
        ), 60);
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $active      = self::is_active();
        $mode        = self::get_mode();
        $has_engine  = class_exists('MLUC_License_Manager');
        $legacy_pro  = self::legacy_pro_active();
        $local_on    = ((int) get_option(self::OPT_LOCAL_ACTIVE, 1) === 1);
        $action_url  = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Moonlight Shop Pro — 授权', 'moonlight-shop-pro'); ?></h1>

            <table class="widefat striped" style="max-width:760px;">
                <tbody>
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('产品标识', 'moonlight-shop-pro'); ?></th>
                        <td><code><?php echo esc_html(MLPRO_PRODUCT); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('授权模式', 'moonlight-shop-pro'); ?></th>
                        <td>
                            <?php if ('engine' === $mode) : ?>
                                <strong><?php esc_html_e('License 引擎（moonlight-user-center）', 'moonlight-shop-pro'); ?></strong>
                            <?php else : ?>
                                <strong><?php esc_html_e('本地模式（本地开关）', 'moonlight-shop-pro'); ?></strong>
                                — <span class="description"><?php esc_html_e('会员中心并线后将自动切换到 License 引擎，无需修改配置。', 'moonlight-shop-pro'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('授权状态', 'moonlight-shop-pro'); ?></th>
                        <td>
                            <?php if ($active) : ?>
                                <span style="color:#00a32a;"><strong><?php esc_html_e('已激活', 'moonlight-shop-pro'); ?></strong></span>
                            <?php else : ?>
                                <span style="color:#d63638;"><strong><?php esc_html_e('未激活', 'moonlight-shop-pro'); ?></strong></span>
                                — <span class="description"><?php esc_html_e('未激活时 Pro 功能（Webhook / 统计 / 导出）不注册任何行为，Free 商城不受影响。', 'moonlight-shop-pro'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($has_engine && !$legacy_pro) : ?>
                        <?php foreach (self::product_status() as $__product => $__product_active) : ?>
                            <tr>
                                <th><?php echo esc_html(sprintf(/* translators: %s: 产品标识 */ __('产品检查：%s', 'moonlight-shop-pro'), $__product)); ?></th>
                                <td>
                                    <?php if ($__product_active) : ?>
                                        <span style="color:#00a32a;"><?php esc_html_e('有有效 License', 'moonlight-shop-pro'); ?></span>
                                    <?php else : ?>
                                        <span style="color:#d63638;"><?php esc_html_e('无有效 License', 'moonlight-shop-pro'); ?></span>
                                    <?php endif; ?>
                                    <span class="description"><?php esc_html_e('任一产品有有效 License 即视为 Pro 激活（存量授权兼容）。', 'moonlight-shop-pro'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php elseif ($legacy_pro) : ?>
                        <tr>
                            <th><?php esc_html_e('旧版 Pro', 'moonlight-shop-pro'); ?></th>
                            <td><span class="description"><?php esc_html_e('检测到旧版「用户中心 Pro」仍处于激活状态：Elementor 会员卡与 License 检查由旧版负责，本插件不重复接管。', 'moonlight-shop-pro'); ?></span></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($has_engine) : ?>
                <h2><?php esc_html_e('激活 License', 'moonlight-shop-pro'); ?></h2>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('mlpro_license_save'); ?>
                    <input type="hidden" name="action" value="mlpro_license_save">
                    <p>
                        <input type="text" class="regular-text code" name="license_key"
                               placeholder="MLUC-PRO-XXXX-XXXX-XXXX-XXXX" autocomplete="off">
                        <?php submit_button(__('激活', 'moonlight-shop-pro'), 'primary', 'submit', false); ?>
                    </p>
                    <p class="description"><?php echo esc_html__('输入购买获得的 License Key，激活后由 License 引擎完成站点绑定与验证（含宽限期与撤销联动）。迁移域名请先在原站点停用。', 'moonlight-shop-pro'); ?></p>
                </form>
            <?php else : ?>
                <h2><?php esc_html_e('本地模式', 'moonlight-shop-pro'); ?></h2>
                <form method="post" action="<?php echo esc_url($action_url); ?>">
                    <?php wp_nonce_field('mlpro_license_save'); ?>
                    <input type="hidden" name="action" value="mlpro_license_save">
                    <p>
                        <label>
                            <input type="checkbox" name="mlpro_local_active" value="1" <?php checked($local_on, true); ?>>
                            <?php esc_html_e('本地激活（当前引擎缺席时的回退开关，默认开启）', 'moonlight-shop-pro'); ?>
                        </label>
                    </p>
                    <p class="description"><?php echo esc_html__('未检测到 License 引擎（moonlight-user-center）。会员中心并线后将自动切换到 License 引擎，此开关随即失效。', 'moonlight-shop-pro'); ?></p>
                    <?php submit_button(__('保存', 'moonlight-shop-pro'), 'primary', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 保存：本地开关（本地模式）+ License 激活（引擎存在且填写了 Key 时）。
     */
    public function handle_save()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlpro_license_save')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-shop-pro'));
        }
        $redirect = admin_url('admin.php?page=mlpro-license');

        // 本地开关：引擎缺席时才允许写（引擎模式下开关无意义，保持原值）。
        if (!class_exists('MLUC_License_Manager')) {
            $on = !empty($_POST['mlpro_local_active']);
            update_option(self::OPT_LOCAL_ACTIVE, $on ? 1 : 0);
            self::clear_cache();
            self::set_notice($on
                ? __('本地激活已开启，Pro 功能已启用。', 'moonlight-shop-pro')
                : __('本地激活已关闭，Pro 功能停用（Free 商城不受影响）。', 'moonlight-shop-pro'));
        }

        // 引擎模式：激活码输入（占位说明见 render_page；此处直调引擎完成站点绑定）。
        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        if ('' !== $key) {
            if (!class_exists('MLUC_License_Manager')) {
                self::set_notice(__('License 引擎不可用，无法激活。', 'moonlight-shop-pro'), true);
            } else {
                $result = MLUC_License_Manager::activate($key);
                if (is_wp_error($result)) {
                    self::set_notice($result->get_error_message(), true);
                } else {
                    self::clear_cache();
                    self::set_notice(__('License 激活成功，Pro 功能已启用。', 'moonlight-shop-pro'));
                    do_action('mlpro_license_activated', strtoupper(trim($key)));
                }
            }
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
