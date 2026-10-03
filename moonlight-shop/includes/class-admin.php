<?php
/**
 * 管理员设置：支付网关凭证、货币、邮件等。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Admin
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
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_mlshop_test_stripe', array($this, 'test_stripe'));
        add_action('admin_post_mlshop_test_paypal', array($this, 'test_paypal'));
        add_action('admin_post_mlshop_test_order_email', array($this, 'test_order_email'));
        add_action('admin_post_mlshop_order_set_status', array($this, 'order_set_status'));
        add_action('admin_post_mlshop_order_refund', array($this, 'order_refund'));
        add_action('admin_post_mlshop_card_batch_status', array($this, 'card_batch_status'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
    }

    /**
     * 后台编辑商品时加载相册上传器所需的脚本与样式；
     * 设置页 / 群发邮件页加载美化样式与导航脚本；
     * 文章 / 页面 / 商品 编辑器加载「付费功能」Meta Box 交互脚本。
     */
    public function enqueue_admin($hook)
    {
        // 文章 / 页面 / 商品 编辑器：付费功能 Meta Box
        if (in_array($hook, array('post.php', 'post-new.php'), true)) {
            $screen = get_current_screen();
            $pt = $screen ? $screen->post_type : '';
            if (in_array($pt, array('mlshop_product', 'post', 'page'), true)) {
                wp_enqueue_media();
                // 商品编辑页：相册上传器（仅 mlshop_product 用 .mlshop_gallery 旧字段）
                if ($pt === 'mlshop_product') {
                    wp_enqueue_style('mlshop-admin', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-admin.css', array(), MLSHOP_VERSION);
                    wp_enqueue_script('mlshop-admin', MLSHOP_PLUGIN_URL . 'assets/js/mlshop-admin.js', array('jquery', 'media-editor'), MLSHOP_VERSION, true);
                }
                // 付费功能 Meta Box 交互（tab 切换 / 条件显隐 / repeater / 图集 / 视频封面）
                wp_enqueue_script('mlshop-pay-meta', MLSHOP_PLUGIN_URL . 'assets/js/mlshop-pay-meta.js', array('jquery', 'media-editor'), MLSHOP_VERSION, true);
            }
            // 优惠券/订单 CPT 编辑页：套用设置页同样的卡片化外观
            if (in_array($pt, array('mlshop_coupon', 'mlshop_order'), true)) {
                wp_enqueue_style('mlshop-settings', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-settings.css', array(), MLSHOP_VERSION);
            }
        }

        // 列表页（edit.php）：订单 / 优惠券列表 + 设置/群发邮件页加载美化样式
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        $is_cpt_list = ('edit.php' === $hook) && in_array($post_type, array('mlshop_order', 'mlshop_coupon'), true);
        if ($is_cpt_list) {
            wp_enqueue_style('mlshop-settings', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-settings.css', array(), MLSHOP_VERSION);
        }

        // 设置页 / 群发邮件页 / 卡密库存页：美化样式 + 导航脚本
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ('mlshop-settings' === $page || 'mlshop-bulk-email' === $page || 'mlshop-card-stock' === $page) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_style('mlshop-settings', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-settings.css', array('wp-color-picker'), MLSHOP_VERSION);
            wp_enqueue_script('mlshop-admin', MLSHOP_PLUGIN_URL . 'assets/js/mlshop-admin.js', array('jquery', 'wp-color-picker'), MLSHOP_VERSION, true);
        }
        if ('mlshop-bulk-email' === $page) {
            wp_enqueue_script('mlshop-bulk-email', MLSHOP_PLUGIN_URL . 'assets/js/mlshop-bulk-email.js', array('jquery'), MLSHOP_VERSION, true);
            wp_localize_script('mlshop-bulk-email', 'mlshopBulk', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('mlshop_bulk_email'),
            ));
        }
    }

    public function add_menu()
    {
        add_submenu_page(
            'edit.php?post_type=mlshop_product',
            __('商城設定', 'moonlight-shop'),
            __('商城設定', 'moonlight-shop'),
            'manage_options',
            'mlshop-settings',
            array($this, 'render_settings')
        );
        // 卡密库存（加密批次模型）：批次列表 / 批量导入 / 掩码查看与单条解密
        add_submenu_page(
            'edit.php?post_type=mlshop_product',
            __('卡密库存', 'moonlight-shop'),
            __('卡密库存', 'moonlight-shop'),
            'manage_options',
            'mlshop-card-stock',
            array($this, 'render_card_stock')
        );
    }

    public function register_settings()
    {
        $group = 'mlshop_settings_group';
        $keys = array(
            'currency'                => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'currency_symbol'         => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'guest_checkout_enabled'  => array('type' => 'integer', 'sanitize' => 'absint'),
            'guest_order_rate_limit'  => array('type' => 'integer', 'sanitize' => 'absint'),
            'store_email'             => array('type' => 'string',  'sanitize' => 'sanitize_email'),
            'stripe_test_mode'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'stripe_test_publishable' => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'stripe_test_secret'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'stripe_publishable'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'stripe_secret'           => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'stripe_webhook_secret'   => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'paypal_sandbox'          => array('type' => 'integer', 'sanitize' => 'absint'),
            'paypal_client_id'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'paypal_secret'           => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'paypal_webhook_id'       => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'alipay_enabled'          => array('type' => 'integer', 'sanitize' => 'absint'),
            'alipay_mode'             => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'alipay_app_id'           => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'alipay_private_key'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'alipay_public_key'       => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            // 微信支付 v3（自实现）：商户凭证 + 私钥 / APIv3 密钥脱敏保存；
            // 验签公钥（微信支付公钥模式）虽非高敏，仍按 secret 脱敏以防误粘贴覆盖。
            'wechat_enabled'          => array('type' => 'integer', 'sanitize' => 'absint'),
            'wechat_mchid'            => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'wechat_appid'            => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'wechat_serial_no'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'wechat_private_key'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'wechat_apiv3_key'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'wechat_pub_serial'       => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'wechat_pub_key'          => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'wechat_scene'            => array('type' => 'string',  'sanitize' => 'sanitize_key'),
            'wechat_description'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'pay_enabled'             => array('type' => 'integer', 'sanitize' => 'absint'),
            'credit_name'             => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'pay_popup_default_title' => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'recharge_packages'       => array('type' => 'string',  'sanitize' => 'sanitize_textarea_field'),
            'credit_rate'             => array('type' => 'number',  'sanitize' => 'mlshop_sanitize_float'),
            // 积分支付网关 / 积分价自动换算总开关（credit_rate 为全局兑换比例）
            'credit_pay_enabled'      => array('type' => 'integer', 'sanitize' => 'absint'),
            'credit_auto_price'       => array('type' => 'integer', 'sanitize' => 'absint'),
            'default_gateway'         => array('type' => 'string',  'sanitize' => 'sanitize_key'),
            'order_expire_minutes'    => array('type' => 'integer', 'sanitize' => 'absint'),
            'shipping_enabled'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'shipping_free_threshold' => array('type' => 'number',  'sanitize' => 'mlshop_sanitize_float'),
            'shipping_flat_rate'      => array('type' => 'number',  'sanitize' => 'mlshop_sanitize_float'),
            'shipping_carrier'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'pickup_enabled'          => array('type' => 'integer', 'sanitize' => 'absint'),
            // 物流第二批：Shipping Provider + 自动轨迹查询 + 签收自动完成
            'shipping_provider'       => array('type' => 'string',  'sanitize' => 'sanitize_key'),
            'shipping_kuaidi100_key'  => array('type' => 'string',  'sanitize' => 'sanitize_text_field', 'secret' => true),
            'shipping_kuaidi100_customer' => array('type' => 'string', 'sanitize' => 'sanitize_text_field', 'secret' => true),
            'shipping_auto_sync'      => array('type' => 'integer', 'sanitize' => 'absint'),
            'auto_complete_days'      => array('type' => 'integer', 'sanitize' => 'absint'),
            'slug_single'             => array('type' => 'string',  'sanitize' => 'sanitize_title'),
            'slug_archive'            => array('type' => 'string',  'sanitize' => 'sanitize_title'),
            'slug_category'           => array('type' => 'string',  'sanitize' => 'sanitize_title'),
            'slug_tag'               => array('type' => 'string',  'sanitize' => 'sanitize_title'),
            'archive_cols_desktop'    => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_cols_tablet'     => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_cols_mobile'     => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_per_page'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_gap_px'          => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_show_type_badge' => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_show_tags'       => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_title_font_size'  => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_title_font_weight'=> array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'archive_title_align'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'archive_title_line_clamp' => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_price_font_size'  => array('type' => 'integer', 'sanitize' => 'absint'),
            'archive_price_font_weight'=> array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'archive_price_color'      => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'btn_font_size'            => array('type' => 'integer', 'sanitize' => 'absint'),
            'btn_radius'               => array('type' => 'integer', 'sanitize' => 'absint'),
            'btn_color'                => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'buy_text_add'             => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'buy_text_favorite'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'buy_text_select'          => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'buy_text_added'           => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'buy_text_adding'          => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'show_breadcrumbs'        => array('type' => 'integer', 'sanitize' => 'absint'),
            'show_product_meta'       => array('type' => 'integer', 'sanitize' => 'absint'),
            'reviews_enabled'         => array('type' => 'integer', 'sanitize' => 'absint'),
            'sidebar_position'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'sidebar_sticky'          => array('type' => 'integer', 'sanitize' => 'absint'),
            'order_email_enabled'     => array('type' => 'integer', 'sanitize' => 'absint'),
            'from_name'               => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'from_email'              => array('type' => 'string',  'sanitize' => 'sanitize_email'),
            'order_email_subject'     => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            'email_footer'            => array('type' => 'string',  'sanitize' => 'sanitize_textarea_field'),
        );
        foreach ($keys as $key => $conf) {
            $sanitize = $conf['sanitize'];
            if (!empty($conf['secret'])) {
                // 密钥字段：脱敏保存（空提交 = 保持原值），修复审计 M4
                $sk = $key;
                $sanitize = function ($value) use ($sk) {
                    return mlshop_sanitize_secret_keep($sk, $value);
                };
            }
            register_setting($group, 'mlshop_' . $key, array(
                'type'              => $conf['type'],
                'sanitize_callback' => $sanitize,
                'default'           => '',
            ));
        }

        // 前台公开支付方式（每个网关 ID 独立开关，可被其他插件扩展）。
        register_setting($group, 'mlshop_enabled_gateways', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_enabled_gateways'),
            'default'           => array('cod', 'balance', 'credit', 'manual', 'stripe', 'paypal', 'alipay', 'wechat'),
        ));

        // 運費模板（数组存储；表单提交的是逐行文本，由 sanitize 回调解析为结构化数组）
        register_setting($group, 'moonlight_shipping_templates', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_shipping_templates'),
            'default'           => array(),
        ));
    }

    /**
     * 解析運費模板表单输入（每行：模板名|模式|首件|续件|免邮门槛）。
     *
     * 示例：
     *   順豐標準|fixed|50|0|400   → fixed：小计未达 400 收 50
     *   促銷品|piece|10|5|0      → piece：首件 10，续件每件 +5
     *
     * - mode 仅接受 fixed / piece（默认 fixed）；
     * - fixed 模式「首件」列即固定运费（flat_fee），续件列忽略；
     * - id 由模板名派生（md5 前 8 位），同名模板跨保存保持 id 稳定，
     *   改名 = 新模板（旧商品回落默认全局运费）；
     * - 也可接受程序化数组输入（REST / 代码写入），逐行规范化。
     *
     * @param string|array $value
     * @return array
     */
    public function sanitize_shipping_templates($value)
    {
        $lines = array();
        if (is_array($value)) {
            foreach ($value as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines[] = implode('|', array(
                    isset($row['name']) ? $row['name'] : '',
                    isset($row['mode']) ? $row['mode'] : 'fixed',
                    isset($row['first']) ? $row['first'] : (isset($row['flat_fee']) ? $row['flat_fee'] : 0),
                    isset($row['extra']) ? $row['extra'] : (isset($row['extra_item_fee']) ? $row['extra_item_fee'] : 0),
                    isset($row['threshold']) ? $row['threshold'] : (isset($row['free_threshold']) ? $row['free_threshold'] : 0),
                ));
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        }

        $out  = array();
        $seen = array();
        foreach ((array) $lines as $line) {
            $line = trim((string) $line);
            if ('' === $line || 0 === strpos($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $name = sanitize_text_field(isset($parts[0]) ? $parts[0] : '');
            if ('' === $name) {
                continue;
            }
            $mode = isset($parts[1]) ? sanitize_key($parts[1]) : 'fixed';
            if (!in_array($mode, array('fixed', 'piece'), true)) {
                $mode = 'fixed';
            }
            $first     = isset($parts[2]) ? mlshop_sanitize_float($parts[2]) : 0.0;
            $extra     = isset($parts[3]) ? mlshop_sanitize_float($parts[3]) : 0.0;
            $threshold = isset($parts[4]) ? mlshop_sanitize_float($parts[4]) : 0.0;

            if ('piece' === $mode) {
                if ($first <= 0 && $extra <= 0) {
                    continue; // 首件/续件全 0 的按件模板无意义
                }
                $row = array(
                    'id'             => '',
                    'name'           => $name,
                    'mode'           => 'piece',
                    'flat_fee'       => 0.0,
                    'first_item_fee' => $first,
                    'extra_item_fee' => $extra,
                    'free_threshold' => $threshold,
                );
            } else {
                if ($first <= 0) {
                    continue; // fixed 模式「首件」列即固定运费，0 = 无效行
                }
                $row = array(
                    'id'             => '',
                    'name'           => $name,
                    'mode'           => 'fixed',
                    'flat_fee'       => $first,
                    'first_item_fee' => 0.0,
                    'extra_item_fee' => 0.0,
                    'free_threshold' => $threshold,
                );
            }

            // id 由名称派生并去重（md5 摘要保证中文名也有稳定 ASCII id）
            $id = 'tpl_' . substr(md5($row['name']), 0, 8);
            $n  = 1;
            while (isset($seen[$id])) {
                $id = 'tpl_' . substr(md5($row['name']), 0, 8) . '_' . (++$n);
            }
            $seen[$id]  = true;
            $row['id']  = $id;
            // 模板名限长（mbstring 缺失时按字节截断兜底）
            $row['name'] = function_exists('mb_substr')
                ? mb_substr($row['name'], 0, 40, 'UTF-8')
                : substr($row['name'], 0, 40);
            $out[]      = $row;
        }
        return $out;
    }

    /**
     * 运费模板数组 → 逐行文本（设置页 textarea 回显）。
     *
     * @param array $templates
     * @return string
     */
    public static function templates_to_text($templates)
    {
        $lines = array();
        foreach ((array) $templates as $t) {
            if (!is_array($t) || empty($t['name'])) {
                continue;
            }
            $mode = isset($t['mode']) && 'piece' === $t['mode'] ? 'piece' : 'fixed';
            if ('piece' === $mode) {
                $first = isset($t['first_item_fee']) ? $t['first_item_fee'] : 0;
                $extra = isset($t['extra_item_fee']) ? $t['extra_item_fee'] : 0;
            } else {
                $first = isset($t['flat_fee']) ? $t['flat_fee'] : 0;
                $extra = 0;
            }
            $lines[] = implode('|', array(
                $t['name'],
                $mode,
                rtrim(rtrim(number_format((float) $first, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) $extra, 2, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) (isset($t['free_threshold']) ? $t['free_threshold'] : 0), 2, '.', ''), '0'), '.'),
            ));
        }
        return implode("\n", $lines);
    }

    /**
     * 净化前台公开支付方式：只接受 sanitize_key 化后的非空 id，未勾选不会在 $_POST 出现。
     */
    public function sanitize_enabled_gateways($value)
    {
        if (!is_array($value)) {
            return array();
        }
        $clean = array();
        foreach ($value as $raw_id) {
            $id = sanitize_key($raw_id);
            if ($id !== '') {
                $clean[] = $id;
            }
        }
        return array_values(array_unique($clean));
    }

    public function render_settings()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $webhook_url = home_url('/?mlshop_stripe_webhook=1');
        $paypal_return = home_url('/?gateway=paypal&action=capture');
        // 支付宝异步通知地址（REST，构建下单请求时自动携带 notify_url）。
        $alipay_notify_url = class_exists('MLSHOP_Gateway_Alipay')
            ? MLSHOP_Gateway_Alipay::notify_url()
            : rest_url('mlshop/v1/alipay/notify');
        // 微信支付异步通知地址（REST，构建下单请求时自动携带 notify_url）。
        $wechat_notify_url = class_exists('MLSHOP_Gateway_WeChat')
            ? MLSHOP_Gateway_WeChat::notify_url()
            : rest_url('mlshop/v1/wechat/notify');
        ?>
        <div class="wrap mlshop-admin-settings mlshop-settings-layout">
            <h1><?php esc_html_e('漫步白月光電子商城 設定', 'moonlight-shop'); ?></h1>
            <nav class="mlshop-settings-nav" aria-label="<?php esc_attr_e('设置页导航', 'moonlight-shop'); ?>">
                <div class="mlshop-settings-nav-title"><?php esc_html_e('设置导航', 'moonlight-shop'); ?></div>
                <ul class="mlshop-settings-nav-list">
                    <li><a href="#mlshop-sec-basic"><?php esc_html_e('基本設定', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-slugs"><?php esc_html_e('商品連結', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-archive"><?php esc_html_e('商品列表布局', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-sidebar"><?php esc_html_e('側邊欄', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-list"><?php esc_html_e('列表顯示', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-single"><?php esc_html_e('單頁顯示', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-tags"><?php esc_html_e('商品标签', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-paywall"><?php esc_html_e('付费/会员/积分', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-order"><?php esc_html_e('订单设置', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-shipping"><?php esc_html_e('運費設定', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-mail"><?php esc_html_e('郵件設置', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-stripe"><?php esc_html_e('Stripe 支付', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-pmethods"><?php esc_html_e('前台公開支付方式', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-paypal"><?php esc_html_e('PayPal 支付', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-alipay"><?php esc_html_e('支付寶支付', 'moonlight-shop'); ?></a></li>
                    <li><a href="#mlshop-sec-wechat"><?php esc_html_e('微信支付', 'moonlight-shop'); ?></a></li>
                </ul>
            </nav>
            <form method="post" action="options.php">
                <?php settings_fields('mlshop_settings_group'); ?>

                <h2 id="mlshop-sec-basic" class="mlshop-card-title"><?php esc_html_e('基本設定', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mlshop_currency"><?php esc_html_e('貨幣代碼（ISO 4217）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_currency" name="mlshop_currency" value="<?php echo esc_attr(mlshop_get_option('currency', 'HKD')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('例：HKD / USD / CNY / EUR', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_currency_symbol"><?php esc_html_e('貨幣符號（顯示用）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_currency_symbol" name="mlshop_currency_symbol" value="<?php echo esc_attr(mlshop_get_option('currency_symbol', 'HK$')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_store_email"><?php esc_html_e('商店聯絡電郵', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="email" id="mlshop_store_email" name="mlshop_store_email" value="<?php echo esc_attr(mlshop_get_option('store_email', get_option('admin_email'))); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_guest_checkout_enabled"><?php esc_html_e('允許訪客購買', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="hidden" name="mlshop_guest_checkout_enabled" value="0">
                            <label><input type="checkbox" id="mlshop_guest_checkout_enabled" name="mlshop_guest_checkout_enabled" value="1" <?php checked((int) mlshop_get_option('guest_checkout_enabled', 1), 1); ?>> <?php esc_html_e('未註冊訪客填寫電子郵箱即可下單', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('訪客需填寫有效郵箱接收訂單確認與虛擬商品；付款完成後將在訂單頁與郵件中推薦其註冊成為網站用戶。關閉後結算頁僅對已登錄用戶開放。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_guest_order_rate_limit"><?php esc_html_e('訪客下單限流', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" id="mlshop_guest_order_rate_limit" name="mlshop_guest_order_rate_limit" min="0" max="999" class="small-text" value="<?php echo esc_attr((string) (int) mlshop_get_option('guest_order_rate_limit', 10)); ?>">
                            <p class="description"><?php esc_html_e('同一 IP 每小時最多下單次數，0 表示不限流（防灌單濫用）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-slugs" class="mlshop-card-title"><?php esc_html_e('商品連結', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mlshop_slug_single"><?php esc_html_e('商品單頁前綴', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_slug_single" name="mlshop_slug_single" value="<?php echo esc_attr(mlshop_get_option('slug_single', '')); ?>" class="regular-text" placeholder="product">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s：当前生效的链接前缀 */
                                    esc_html__('留空自動選用。目前生效：%s', 'moonlight-shop'),
                                    '<code>/' . esc_html(MLSHOP_Product::get_url_slug('single')) . '/…/</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_slug_archive"><?php esc_html_e('商品列表頁前綴', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_slug_archive" name="mlshop_slug_archive" value="<?php echo esc_attr(mlshop_get_option('slug_archive', '')); ?>" class="regular-text" placeholder="products">
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('留空自動選用。目前生效：%s', 'moonlight-shop'),
                                    '<code>/' . esc_html(MLSHOP_Product::get_url_slug('archive')) . '/</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_slug_category"><?php esc_html_e('商品分類前綴', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_slug_category" name="mlshop_slug_category" value="<?php echo esc_attr(mlshop_get_option('slug_category', '')); ?>" class="regular-text" placeholder="product-category">
                        <p class="description">
                            <?php
                            printf(
                                esc_html__('留空自動選用。目前生效：%s', 'moonlight-shop'),
                                '<code>/' . esc_html(MLSHOP_Product::get_url_slug('category')) . '/…/</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="mlshop_slug_tag"><?php esc_html_e('商品标签前缀', 'moonlight-shop'); ?></label></th>
                    <td>
                        <input type="text" id="mlshop_slug_tag" name="mlshop_slug_tag" value="<?php echo esc_attr(mlshop_get_option('slug_tag', '')); ?>" class="regular-text" placeholder="product-tag">
                        <p class="description">
                            <?php
                            printf(
                                esc_html__('留空自動選用。目前生效：%s', 'moonlight-shop'),
                                '<code>/' . esc_html(MLSHOP_Product::get_url_slug('tag')) . '/</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('說明', 'moonlight-shop'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('若站內已有其他內容類型佔用相同前綴，商城會自動改用備用前綴，避免頁面出現 404。修改後系統會自動更新連結規則，無需手動重新儲存永久連結。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-archive" class="mlshop-card-title"><?php esc_html_e('商品列表布局', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mlshop_archive_cols_desktop"><?php esc_html_e('桌面端列数', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $dcol = (int) mlshop_get_option('archive_cols_desktop', 4); ?>
                            <select id="mlshop_archive_cols_desktop" name="mlshop_archive_cols_desktop">
                                <?php for ($i = 2; $i <= 6; $i++) : ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($dcol, $i); ?>><?php echo (int) $i; ?> 列</option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_archive_cols_tablet"><?php esc_html_e('平板端列数（≤900px）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $tcol = (int) mlshop_get_option('archive_cols_tablet', 2); ?>
                            <select id="mlshop_archive_cols_tablet" name="mlshop_archive_cols_tablet">
                                <?php for ($i = 1; $i <= 4; $i++) : ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($tcol, $i); ?>><?php echo (int) $i; ?> 列</option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_archive_cols_mobile"><?php esc_html_e('手机端列数（≤520px）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $mcol = (int) mlshop_get_option('archive_cols_mobile', 1); ?>
                            <select id="mlshop_archive_cols_mobile" name="mlshop_archive_cols_mobile">
                                <?php for ($i = 1; $i <= 3; $i++) : ?>
                                    <option value="<?php echo esc_attr($i); ?>" <?php selected($mcol, $i); ?>><?php echo (int) $i; ?> 列</option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_archive_gap_px"><?php esc_html_e('商品间距（像素）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="0" max="48" step="2" id="mlshop_archive_gap_px" name="mlshop_archive_gap_px" value="<?php echo esc_attr((int) mlshop_get_option('archive_gap_px', 20)); ?>" class="small-text"> px
                            <p class="description"><?php esc_html_e('调整每张商品卡之间的空隙。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_archive_per_page"><?php esc_html_e('每页商品数', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="1" max="120" step="1" id="mlshop_archive_per_page" name="mlshop_archive_per_page" value="<?php echo esc_attr((int) mlshop_get_option('archive_per_page', 12)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('商品列表页、分类页、搜索结果页每页显示的商品数量。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('商品标题排版', 'moonlight-shop'); ?></th>
                        <td>
                            <?php
                            // 防御性 sanitize：旧值若混入了非白名单字重 / 对齐，强制回退默认。
                            $allowed_weights = array('300', '400', '500', '600', '700', '800');
                            $allowed_aligns  = array('left', 'center', 'right');
                            $cur_w = (string) mlshop_get_option('archive_title_font_weight', '600');
                            $cur_a = (string) mlshop_get_option('archive_title_align', 'center');
                            if (!in_array($cur_w, $allowed_weights, true)) { $cur_w = '600'; }
                            if (!in_array($cur_a, $allowed_aligns, true))   { $cur_a = 'center'; }
                            ?>
                            <fieldset class="mlshop-inline-fieldset">
                                <label><?php esc_html_e('字号', 'moonlight-shop'); ?>
                                    <input type="number" min="10" max="32" step="1" name="mlshop_archive_title_font_size" value="<?php echo esc_attr((int) mlshop_get_option('archive_title_font_size', 16)); ?>" class="small-text"> px
                                </label>
                                <label><?php esc_html_e('字重', 'moonlight-shop'); ?>
                                    <select name="mlshop_archive_title_font_weight">
                                        <?php foreach ($allowed_weights as $w) : ?>
                                            <option value="<?php echo esc_attr($w); ?>" <?php selected($cur_w, $w); ?>><?php echo esc_html($w); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?php esc_html_e('对齐', 'moonlight-shop'); ?>
                                    <select name="mlshop_archive_title_align">
                                        <option value="left" <?php selected($cur_a, 'left'); ?>><?php esc_html_e('左', 'moonlight-shop'); ?></option>
                                        <option value="center" <?php selected($cur_a, 'center'); ?>><?php esc_html_e('居中', 'moonlight-shop'); ?></option>
                                        <option value="right" <?php selected($cur_a, 'right'); ?>><?php esc_html_e('右', 'moonlight-shop'); ?></option>
                                    </select>
                                </label>
                                <label><?php esc_html_e('最多显示行数', 'moonlight-shop'); ?>
                                    <input type="number" min="1" max="6" step="1" name="mlshop_archive_title_line_clamp" value="<?php echo esc_attr((int) mlshop_get_option('archive_title_line_clamp', 2)); ?>" class="small-text">
                                </label>
                                <span class="mlshop-fieldset-hint"><?php esc_html_e('（超出自动省略，省略号结尾）', 'moonlight-shop'); ?></span>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('商品价格排版', 'moonlight-shop'); ?></th>
                        <td>
                            <?php
                            $cur_pw = (string) mlshop_get_option('archive_price_font_weight', '700');
                            if (!in_array($cur_pw, $allowed_weights, true)) { $cur_pw = '700'; }
                            $cur_pc = (string) mlshop_get_option('archive_price_color', '#e23b3b');
                            if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $cur_pc)) { $cur_pc = '#e23b3b'; }
                            ?>
                            <fieldset class="mlshop-inline-fieldset">
                                <label><?php esc_html_e('字号', 'moonlight-shop'); ?>
                                    <input type="number" min="10" max="32" step="1" name="mlshop_archive_price_font_size" value="<?php echo esc_attr((int) mlshop_get_option('archive_price_font_size', 17)); ?>" class="small-text"> px
                                </label>
                                <label><?php esc_html_e('字重', 'moonlight-shop'); ?>
                                    <select name="mlshop_archive_price_font_weight">
                                        <?php foreach ($allowed_weights as $w) : ?>
                                            <option value="<?php echo esc_attr($w); ?>" <?php selected($cur_pw, $w); ?>><?php echo esc_html($w); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><?php esc_html_e('颜色', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_archive_price_color" value="<?php echo esc_attr($cur_pc); ?>" class="mlshop-color-field" data-default-color="#e23b3b">
                                </label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('影响商品列表页（含分类、搜索、Elementor 网格）卡片上的价格显示；商品详情页不受此设置控制。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" style="padding-top:4px;">
                            <p class="description" style="margin:0;"><?php esc_html_e('所有商品列表页（含分类、搜索、Elementor 网格）共用上方两套排版。如需单独微调某处，可在模板或 Elementor 设置中覆盖。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('购买按钮样式', 'moonlight-shop'); ?></th>
                        <td>
                            <?php
                            $cur_btn_color = (string) mlshop_get_option('btn_color', '#2271b1');
                            if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $cur_btn_color)) { $cur_btn_color = '#2271b1'; }
                            ?>
                            <fieldset class="mlshop-inline-fieldset">
                                <label><?php esc_html_e('文字大小', 'moonlight-shop'); ?>
                                    <input type="number" min="10" max="22" step="1" name="mlshop_btn_font_size" value="<?php echo esc_attr((int) mlshop_get_option('btn_font_size', 14)); ?>" class="small-text"> px
                                </label>
                                <label><?php esc_html_e('圆角', 'moonlight-shop'); ?>
                                    <input type="number" min="0" max="24" step="1" name="mlshop_btn_radius" value="<?php echo esc_attr((int) mlshop_get_option('btn_radius', 8)); ?>" class="small-text"> px
                                </label>
                                <label><?php esc_html_e('主色', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_btn_color" value="<?php echo esc_attr($cur_btn_color); ?>" class="mlshop-color-field" data-default-color="#2271b1">
                                </label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('作用于全部「加入购物车 / 选择套餐」按钮（商品卡片、相关商品、详情页），改完保存刷新前台即见。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('购买文案', 'moonlight-shop'); ?></th>
                        <td>
                            <fieldset class="mlshop-inline-fieldset">
                                <label><?php esc_html_e('加购按钮', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_buy_text_add" value="<?php echo esc_attr(mlshop_buy_text('add')); ?>" class="small-text" placeholder="加入购物车">
                                </label>
                                <label><?php esc_html_e('收藏按钮', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_buy_text_favorite" value="<?php echo esc_attr(mlshop_buy_text('favorite')); ?>" class="small-text" placeholder="收藏">
                                </label>
                                <label><?php esc_html_e('已加入提示', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_buy_text_added" value="<?php echo esc_attr(mlshop_buy_text('added')); ?>" class="small-text" placeholder="已加入">
                                </label>
                                <label><?php esc_html_e('加入中提示', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_buy_text_adding" value="<?php echo esc_attr(mlshop_buy_text('adding')); ?>" class="small-text" placeholder="正在加入…">
                                </label>
                                <label><?php esc_html_e('套餐按钮（预留）', 'moonlight-shop'); ?>
                                    <input type="text" name="mlshop_buy_text_select" value="<?php echo esc_attr(mlshop_buy_text('select')); ?>" class="small-text" placeholder="选择套餐">
                                </label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('留空使用默认值。加购按钮 / 加入中 / 已加入提示全站生效（含商品卡片、详情页、相关商品）；刷新前台即见。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-sidebar" class="mlshop-card-title"><?php esc_html_e('側邊欄', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mlshop_sidebar_position"><?php esc_html_e('側邊欄位置', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $pos = mlshop_get_option('sidebar_position', 'right'); ?>
                            <select id="mlshop_sidebar_position" name="mlshop_sidebar_position">
                                <option value="right" <?php selected($pos, 'right'); ?>><?php esc_html_e('右側（默认）', 'moonlight-shop'); ?></option>
                                <option value="left" <?php selected($pos, 'left'); ?>><?php esc_html_e('左側', 'moonlight-shop'); ?></option>
                                <option value="none" <?php selected($pos, 'none'); ?>><?php esc_html_e('隱藏', 'moonlight-shop'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('設定商品單頁與商品列表頁側邊欄出現在左邊、右邊，或完全隱藏（隱藏後內容區自動佔滿整行）。手機端（≤600px）一律自動堆疊在內容下方，不受此設定影響。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_sidebar_sticky"><?php esc_html_e('側邊欄懸停', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="hidden" name="mlshop_sidebar_sticky" value="0">
                            <label><input type="checkbox" id="mlshop_sidebar_sticky" name="mlshop_sidebar_sticky" value="1" <?php checked((int) mlshop_get_option('sidebar_sticky', 1), 1); ?>> <?php esc_html_e('滾動頁面時讓側邊欄固定在視窗（吸頂），內容過長時小工具始終可見', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('關閉後側邊欄隨內容正常流動。手機端（≤600px）自動停用懸停。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-list" class="mlshop-card-title"><?php esc_html_e('列表顯示', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('商品类型徽章（实物/虚拟/卡密）', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_archive_show_type_badge" value="0">
                            <label><input type="checkbox" name="mlshop_archive_show_type_badge" value="1" <?php checked((int) mlshop_get_option('archive_show_type_badge', 0), 1); ?>> <?php esc_html_e('在商品列表卡片上顯示「实物 / 虚拟 / 卡密」類型徽章', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('關閉後，列表卡片上不再出現類型標籤，只顯示圖片、標題、價格、按鈕。如需針對單個 Elementor 網格區塊顯示，可在區塊設定裡覆蓋。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('商品标签云', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_archive_show_tags" value="0">
                            <label><input type="checkbox" name="mlshop_archive_show_tags" value="1" <?php checked((int) mlshop_get_option('archive_show_tags', 0), 1); ?>> <?php esc_html_e('在商品列表卡片上顯示商品標籤（小藥丸樣式）', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('關閉後，列表卡片上不再渲染標籤雲，版面更簡潔。如需針對單個 Elementor 網格區塊顯示，可在區塊設定裡覆蓋。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-single" class="mlshop-card-title"><?php esc_html_e('單頁顯示', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('頂部面包屑導航', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_show_breadcrumbs" value="0">
                            <label><input type="checkbox" name="mlshop_show_breadcrumbs" value="1" <?php checked((int) mlshop_get_option('show_breadcrumbs', 1), 1); ?>> <?php esc_html_e('在商品單頁頂部顯示「首頁 › 商城 › 分類 › 商品」面包屑', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('關閉後，商品單頁頂部只保留主圖。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('商品摘要 meta 區塊', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_show_product_meta" value="0">
                            <label><input type="checkbox" name="mlshop_show_product_meta" value="1" <?php checked((int) mlshop_get_option('show_product_meta', 0), 1); ?>> <?php esc_html_e('在摘要下方顯示「貨號 / 分類 / 庫存」三行', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('關閉後，摘要區只保留標題 / 價格 / 類型標籤 / 描述 / 加購 / 收藏，更簡潔。「更多信息」標籤頁仍會包含完整資訊表。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('商品评价', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_reviews_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_reviews_enabled" value="1" <?php checked((int) mlshop_get_option('reviews_enabled', 1), 1); ?>> <?php esc_html_e('在商品单页底部「评价」标签页启用 WordPress 評論區', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('關閉後，「评价」標籤頁與評論區不再渲染，標籤欄只保留「商品描述」與「更多信息」。已發表的歷史評論仍保留在資料庫，重新開啟後即恢復顯示。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-tags" class="mlshop-card-title"><?php esc_html_e('商品标签', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('从 WooCommerce 同步', 'moonlight-shop'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('将站内 WooCommerce 商品的标签（product_tag）整批汇入月光「商品标签」，并按 SKU / 标题对应到月光商品打标。可重复执行，不会重复建标签或重复打标。', 'moonlight-shop'); ?></p>
                            <p>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlshop_sync_woo_tags'), 'mlshop_sync_woo_tags')); ?>" class="button"><?php esc_html_e('同步 WooCommerce 商品标签', 'moonlight-shop'); ?></a>
                            </p>
                            <?php if (class_exists('Moonlight_Woo_Migrate')) : ?>
                            <hr style="margin:14px 0">
                            <p class="description" style="font-weight:600;margin-bottom:6px"><?php esc_html_e('商品一键迁入（WooCommerce → 月光商城）', 'moonlight-shop'); ?></p>
                            <?php Moonlight_Woo_Migrate::render_tools(); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-paywall" class="mlshop-card-title"><?php esc_html_e('付费/会员/积分', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('启用付费功能', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_pay_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_pay_enabled" value="1" <?php checked((int) mlshop_get_option('pay_enabled', 1), 1); ?>> <?php esc_html_e('启用产品付费阅读/下载/积分购买等功能', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('关闭后，产品的付费 Meta Box 不生效。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_credit_name"><?php esc_html_e('积分名称', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_credit_name" name="mlshop_credit_name" value="<?php echo esc_attr(mlshop_get_option('credit_name', '积分')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('如：积分 / Z币 / 金币。显示在产品 Meta Box 和前端。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_pay_popup_default_title"><?php esc_html_e('付费弹窗默认标题', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_pay_popup_default_title" name="mlshop_pay_popup_default_title" value="<?php echo esc_attr(mlshop_get_option('pay_popup_default_title', '')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('产品 Meta Box 未填写"商品标题"时的回退文案。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_recharge_packages"><?php esc_html_e('积分充值套餐', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_recharge_packages" name="mlshop_recharge_packages" rows="4" class="large-text code mlshop-admin-packages"><?php echo esc_textarea(mlshop_get_option('recharge_packages', '')); ?></textarea>
                            <p class="description"><?php esc_html_e('每行一个套餐，格式：积分|金额（例：100|10）。留空则使用默认套餐。注意：套餐为独立定价（可做充值优惠），不随上方兑换比例自动变化。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_credit_rate"><?php esc_html_e('积分兑换比例', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_credit_rate" name="mlshop_credit_rate" value="<?php echo esc_attr(mlshop_get_option('credit_rate', 10)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('全局兑换比例：多少积分 = 1 个货币单位。例：10 表示 10 积分 = 1 元。同时作用于：自定义充值金额、积分支付订单换算、以及未手填积分价的商品/付费内容自动换算（见下方开关）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('允许订单使用积分支付', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_credit_pay_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_credit_pay_enabled" value="1" <?php checked((int) mlshop_get_option('credit_pay_enabled', 1), 1); ?>> <?php esc_html_e('结算页提供「积分支付」方式，按上方比例全额扣除积分（需在「前台公開支付方式」同时勾选）', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('订单所需积分 = 订单金额 × 比例（向上取整）。余额不足时无法提交，支持积分+其他支付方式的订单分别独立。充值订单不可用积分支付。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('积分价自动换算', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_credit_auto_price" value="0">
                            <label><input type="checkbox" name="mlshop_credit_auto_price" value="1" <?php checked((int) mlshop_get_option('credit_auto_price', 1), 1); ?>> <?php esc_html_e('商品/付费内容未手填积分价时，按「货币价 × 兑换比例」自动换算（向上取整）', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('手填过积分价（含会员档）的商品仍以手填值优先。修改兑换比例后，所有自动换算的商品积分价即时生效，无需逐个调整。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-order" class="mlshop-card-title"><?php esc_html_e('订单设置', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="mlshop_order_expire_minutes"><?php esc_html_e('未支付订单自动取消（分钟）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="mlshop_order_expire_minutes" name="mlshop_order_expire_minutes" value="<?php echo esc_attr(mlshop_get_option('order_expire_minutes', 0)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('超过该时长仍未付款的订单将自动取消并回滚库存。0 表示永不自动取消。即使未配置服务器计划任务，用户查看订单时也会惰性过期。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-shipping" class="mlshop-card-title"><?php esc_html_e('運費設定', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('啟用實物商品運費', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_shipping_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_shipping_enabled" value="1" <?php checked((int) mlshop_get_option('shipping_enabled', 1), 1); ?>> <?php esc_html_e('購物車含實物商品時計算運費', 'moonlight-shop'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_shipping_free_threshold"><?php esc_html_e('滿額包郵門檻', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="mlshop_shipping_free_threshold" name="mlshop_shipping_free_threshold" value="<?php echo esc_attr(mlshop_get_option('shipping_free_threshold', 400)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('商品小計達到此金額即免運費（例：400 表示滿 400 包郵）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_shipping_flat_rate"><?php esc_html_e('固定運費', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="mlshop_shipping_flat_rate" name="mlshop_shipping_flat_rate" value="<?php echo esc_attr(mlshop_get_option('shipping_flat_rate', 50)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('未達免運門檻時收取的統一運費（例：50 表示順豐統一運費 50）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_shipping_carrier"><?php esc_html_e('承運商名稱', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_shipping_carrier" name="mlshop_shipping_carrier" value="<?php echo esc_attr(mlshop_get_option('shipping_carrier', '順豐速運')); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('顯示於結算頁、訂單與郵件中的運送說明（例：順豐速運）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('到店自提', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_pickup_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_pickup_enabled" value="1" <?php checked((int) mlshop_get_option('pickup_enabled', 0), 1); ?>> <?php esc_html_e('結算頁實物訂單允許選擇「到店自提」（免運費，收件資料收起為提貨人姓名 + 手機號）', 'moonlight-shop'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="moonlight_shipping_templates"><?php esc_html_e('運費模板', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="moonlight_shipping_templates" name="moonlight_shipping_templates" rows="4" class="large-text code" placeholder="<?php esc_attr_e('標準快遞|fixed|50|0|400', 'moonlight-shop'); ?>"><?php echo esc_textarea(self::templates_to_text(MLSHOP_Shipping::templates())); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('按商品計費的運費模板，每行一條：模板名|模式|首件|續件|免郵門檻。模式 fixed = 固定運費（小計未達門檻收「首件」列金額）；piece = 按件計費（首件 + (件數-1) × 續件）。門檻 > 0 且商品小計達標時該商品免運費。留空 = 未配置，全部商品走上方全局固定運費 + 滿額包郵。', 'moonlight-shop'); ?>
                                <br><?php esc_html_e('示例：順豐標準|fixed|50|0|400 ／ 促銷品|piece|10|5|0。配置後在商品編輯頁「運費模板」下拉為每個實物商品選擇模板；未選擇的商品仍按全局規則計費。', 'moonlight-shop'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_shipping_provider"><?php esc_html_e('物流 Provider', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $cur_provider = (string) mlshop_get_option('shipping_provider', 'manual'); ?>
                            <select id="mlshop_shipping_provider" name="mlshop_shipping_provider">
                                <?php if (class_exists('MLSHOP_Shipping')) : ?>
                                    <?php foreach (MLSHOP_Shipping::providers() as $p) :
                                        if (!is_object($p) || !method_exists($p, 'get_code')) { continue; } ?>
                                        <option value="<?php echo esc_attr($p->get_code()); ?>" <?php selected($cur_provider, $p->get_code()); ?>><?php echo esc_html($p->get_name()); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php esc_html_e('手工發貨 = 零依賴（默認，無軌跡查詢）；快遞100 = 聚合軌跡查詢（需配置下方 Key）。選中的 Provider 不可用時自動回退手工發貨。查詢失敗絕不影響訂單狀態。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('快递100 授权 Key', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="password" name="mlshop_shipping_kuaidi100_key" value="" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更换请输入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('shipping_kuaidi100_key', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('快递100 Customer 编号', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="password" name="mlshop_shipping_kuaidi100_customer" value="" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更换请输入新值。官方接口参数结构变化时，可用过滤器 moonlight_shipping_express100_request 校正请求。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('shipping_kuaidi100_customer', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('自动轨迹查询', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_shipping_auto_sync" value="0">
                            <label><input type="checkbox" name="mlshop_shipping_auto_sync" value="1" <?php checked((int) mlshop_get_option('shipping_auto_sync', 1), 1); ?>> <?php esc_html_e('每 15 分钟自动查询运输中发货单的物流轨迹（需服务器 WP-Cron；签收后订单自动转为「已签收」）', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('同一发货单连续查询失败 3 次会自动暂停 24 小时，避免无效请求。手工发货 Provider 无轨迹能力，此开关仅对查询型 Provider 生效。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_auto_complete_days"><?php esc_html_e('签收后自动完成（天）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="mlshop_auto_complete_days" name="mlshop_auto_complete_days" value="<?php echo esc_attr(mlshop_get_option('auto_complete_days', 7)); ?>" class="small-text">
                            <p class="description"><?php esc_html_e('订单「已签收」超过该天数且用户未确认收货时，自动转为「已完成」。0 = 不自动完成（仅用户手动确认）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-mail" class="mlshop-card-title"><?php esc_html_e('郵件設置', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('訂單 / 下載確認郵件', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_order_email_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_order_email_enabled" value="1" <?php checked((int) mlshop_get_option('order_email_enabled', 1), 1); ?>> <?php esc_html_e('購買完成後自動向客戶發送訂單與虛擬商品下載連結郵件', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('虛擬下載 / 卡密類商品會在郵件中附上下載連結或卡密；關閉後不再自動發信。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_from_name"><?php esc_html_e('發件人名称', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_from_name" name="mlshop_from_name" value="<?php echo esc_attr(mlshop_get_option('from_name', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES))); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('客戶收到郵件時看到的「發件人」名字，留空用站點名稱。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_from_email"><?php esc_html_e('發件人郵箱', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="email" id="mlshop_from_email" name="mlshop_from_email" value="<?php echo esc_attr(mlshop_get_option('from_email', mlshop_get_option('store_email', get_option('admin_email')))); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('建議使用與網域一致的郵箱（如 no-reply@yourdomain.com）以提升送達率，留空用商店聯絡郵箱。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_order_email_subject"><?php esc_html_e('郵件主題', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_order_email_subject" name="mlshop_order_email_subject" value="<?php echo esc_attr(mlshop_get_option('order_email_subject', '')); ?>" class="large-text" placeholder="<?php esc_attr_e('【訂單確認】#{order_number} 付款完成', 'moonlight-shop'); ?>">
                            <p class="description"><?php esc_html_e('留空使用內建主題。支持佔位符 {order_number}（訂單號）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_email_footer"><?php esc_html_e('郵件頁腳', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_email_footer" name="mlshop_email_footer" rows="3" class="large-text"><?php echo esc_textarea(mlshop_get_option('email_footer', '')); ?></textarea>
                            <p class="description"><?php esc_html_e('顯示在郵件底部的補充說明（如客服聯絡方式、社群連結），支持換行。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('測試發送', 'moonlight-shop'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlshop_test_order_email'), 'mlshop_test_order_email')); ?>" class="button"><?php esc_html_e('發送測試郵件到管理員', 'moonlight-shop'); ?></a>
                            <p class="description"><?php esc_html_e('用最近的訂單模擬一封完整郵件（含下載連結）發送到您的後台管理員郵箱，確認發信與模板正常。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-stripe" class="mlshop-card-title"><?php esc_html_e('Stripe 支付', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('測試模式', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_stripe_test_mode" value="0">
                            <label><input type="checkbox" name="mlshop_stripe_test_mode" value="1" <?php checked(mlshop_get_option('stripe_test_mode', 1), 1); ?>> <?php esc_html_e('啟用（使用 Test Key，不會真實扣款）', 'moonlight-shop'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Test Publishable Key', 'moonlight-shop'); ?></th>
                        <td><input type="text" name="mlshop_stripe_test_publishable" value="<?php echo esc_attr(mlshop_get_option('stripe_test_publishable', '')); ?>" class="regular-text" placeholder="pk_test_..."></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Test Secret Key', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="password" name="mlshop_stripe_test_secret" value="" class="regular-text" placeholder="sk_test_..." autocomplete="new-password">
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更换请输入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('stripe_test_secret', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Live Publishable Key', 'moonlight-shop'); ?></th>
                        <td><input type="text" name="mlshop_stripe_publishable" value="<?php echo esc_attr(mlshop_get_option('stripe_publishable', '')); ?>" class="regular-text" placeholder="pk_live_..."></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Live Secret Key', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="password" name="mlshop_stripe_secret" value="" class="regular-text" placeholder="sk_live_..." autocomplete="new-password">
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更换请输入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('stripe_secret', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Webhook Signing Secret', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="password" name="mlshop_stripe_webhook_secret" value="" class="regular-text" placeholder="whsec_..." autocomplete="new-password">
                            <p class="description">
                                <?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('stripe_webhook_secret', '')))); ?><br>
                                <?php esc_html_e('在 Stripe Dashboard → Developers → Webhooks 新增端點：', 'moonlight-shop'); ?>
                                <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?php echo esc_url($webhook_url); ?></code>
                            </p>
                        </td>
                    </tr>
                </table>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlshop_test_stripe'), 'mlshop_test_stripe')); ?>" class="button"><?php esc_html_e('測試 Stripe 連線', 'moonlight-shop'); ?></a>
                </p>

                <h2 id="mlshop-sec-pmethods" class="mlshop-card-title"><?php esc_html_e('前台公開支付方式', 'moonlight-shop'); ?></h2>
                <p class="description"><?php esc_html_e('勾選需要在前台結算頁公開的支付方式；未勾選的方式在前台「選擇支付方式」列表中不會顯示，且 AJAX 下單會被拒絕。如需新增支付方式（例如獨立的 APP 跳轉型網關）請單獨告訴我對接。', 'moonlight-shop'); ?></p>
                <?php
                $enabled = get_option('mlshop_enabled_gateways', null);
                if (!is_array($enabled)) {
                    // 第一次进入设置页或尚未保存：默认全部内建网关为启用。
                    $enabled = array('cod', 'balance', 'credit', 'manual', 'stripe', 'paypal', 'alipay', 'wechat');
                }
                // 用支付管理器拉出全量候选（含第三方扩展），便于管理员按需开启。
                $all_gateways = array();
                if (class_exists('MLSHOP_Payment')) {
                    $all_gateways = MLSHOP_Payment::get_instance()->get_gateways();
                    // 让 filter 也跑过一遍得到真正的"全部候选"，再含未过滤的用于本面板。
                    $all_gateways = apply_filters(
                        'mlshop_payment_gateways',
                        array(
                            new MLSHOP_Gateway_COD(),
                            new MLSHOP_Gateway_Balance(),
                            new MLSHOP_Gateway_Credit(),
                            new MLSHOP_Gateway_Manual(),
                            new MLSHOP_Gateway_Stripe(),
                            new MLSHOP_Gateway_PayPal(),
                            new MLSHOP_Gateway_Alipay(),
                            new MLSHOP_Gateway_WeChat(),
                        )
                    );
                }
                ?>
                <table class="form-table">
                    <?php foreach ($all_gateways as $g) :
                        $gid = $gid_esc = '';
                        $gid = $g->get_id();
                        $gid_esc = esc_attr($gid);
                        $checked = in_array($gid, $enabled, true);
                        ?>
                        <tr>
                            <th><?php echo esc_html($g->get_title()); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mlshop_enabled_gateways[]" value="<?php echo $gid_esc; ?>" <?php checked($checked); ?>>
                                    <?php echo esc_html($g->get_title()); ?> — <?php echo esc_html($g->get_description()); ?>
                                </label>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: gateway id */
                                        esc_html__('網關標識：%s', 'moonlight-shop'),
                                        '<code>' . esc_html($gid) . '</code>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2 id="mlshop-sec-paypal" class="mlshop-card-title"><?php esc_html_e('PayPal 支付', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('沙盒模式', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_paypal_sandbox" value="0">
                            <label><input type="checkbox" name="mlshop_paypal_sandbox" value="1" <?php checked(mlshop_get_option('paypal_sandbox', 1), 1); ?>> <?php esc_html_e('啟用（使用 Sandbox 環境）', 'moonlight-shop'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Client ID', 'moonlight-shop'); ?></th>
                        <td><input type="text" name="mlshop_paypal_client_id" value="<?php echo esc_attr(mlshop_get_option('paypal_client_id', '')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Secret', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="password" name="mlshop_paypal_secret" value="" class="regular-text" autocomplete="new-password">
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更换请输入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('paypal_secret', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Webhook ID', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="text" name="mlshop_paypal_webhook_id" value="<?php echo esc_attr(mlshop_get_option('paypal_webhook_id', '')); ?>" class="regular-text" placeholder="WH-...">
                            <p class="description">
                                <?php esc_html_e('在 PayPal Developer → Webhooks 新增端點，並填寫該端點的 Webhook ID：', 'moonlight-shop'); ?>
                                <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?php echo esc_url(home_url('/?mlshop_paypal_webhook=1')); ?></code>
                            </p>
                        </td>
                    </tr>
                </table>
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlshop_test_paypal'), 'mlshop_test_paypal')); ?>" class="button"><?php esc_html_e('測試 PayPal 連線', 'moonlight-shop'); ?></a>
                </p>
                <p class="description">
                    <?php esc_html_e('PayPal 回跳 URL（用於 return_url，可在 PayPal 應用設定中登記）：', 'moonlight-shop'); ?>
                    <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?php echo esc_url($paypal_return); ?></code>
                </p>

                <h2 id="mlshop-sec-alipay" class="mlshop-card-title"><?php esc_html_e('支付寶支付', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('啟用支付寶', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_alipay_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_alipay_enabled" value="1" <?php checked((int) mlshop_get_option('alipay_enabled', 0), 1); ?>> <?php esc_html_e('啟用（AppID、應用私鑰、支付寶公鑰齊全後前台可用）', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('支付寶僅支持人民幣（CNY）計價：商城貨幣代碼非 CNY 時，支付寶在前台不會出現。需伺服器啟用 PHP OpenSSL 擴展（RSA2 簽名）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_alipay_mode"><?php esc_html_e('環境模式', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $alipay_mode = mlshop_get_option('alipay_mode', 'sandbox'); ?>
                            <select id="mlshop_alipay_mode" name="mlshop_alipay_mode">
                                <option value="sandbox" <?php selected($alipay_mode, 'sandbox'); ?>><?php esc_html_e('沙盒（Sandbox，不會真實扣款）', 'moonlight-shop'); ?></option>
                                <option value="production" <?php selected($alipay_mode, 'production'); ?>><?php esc_html_e('正式（生產環境）', 'moonlight-shop'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_alipay_app_id"><?php esc_html_e('AppID（應用編號）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_alipay_app_id" name="mlshop_alipay_app_id" value="<?php echo esc_attr(mlshop_get_option('alipay_app_id', '')); ?>" class="regular-text" placeholder="2021xxxxxxxxxxxx">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_alipay_private_key"><?php esc_html_e('應用私鑰', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_alipay_private_key" name="mlshop_alipay_private_key" rows="6" class="large-text code" placeholder="<?php esc_attr_e('貼上應用私鑰（支持 PKCS#1 / PKCS#8 / 無頭裸 base64）', 'moonlight-shop'); ?>"></textarea>
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更換請輸入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('alipay_private_key', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_alipay_public_key"><?php esc_html_e('支付寶公鑰', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_alipay_public_key" name="mlshop_alipay_public_key" rows="6" class="large-text code" placeholder="<?php esc_attr_e('貼上支付寶公鑰（非應用公鑰）', 'moonlight-shop'); ?>"></textarea>
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更換請輸入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('alipay_public_key', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('異步通知 URL', 'moonlight-shop'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('構建支付請求時會自動攜帶 notify_url，無需在支付寶後台另行登記；如需在開放平台除錯可用以下地址：', 'moonlight-shop'); ?></p>
                            <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?php echo esc_url($alipay_notify_url); ?></code>
                        </td>
                    </tr>
                </table>

                <h2 id="mlshop-sec-wechat" class="mlshop-card-title"><?php esc_html_e('微信支付', 'moonlight-shop'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('啟用微信支付', 'moonlight-shop'); ?></th>
                        <td>
                            <input type="hidden" name="mlshop_wechat_enabled" value="0">
                            <label><input type="checkbox" name="mlshop_wechat_enabled" value="1" <?php checked((int) mlshop_get_option('wechat_enabled', 0), 1); ?>> <?php esc_html_e('啟用（商戶號、AppID、證書序列號、私鑰、APIv3 密鑰齊全後前台可用）', 'moonlight-shop'); ?></label>
                            <p class="description"><?php esc_html_e('微信支付 API v3（Native 掃碼 / H5），僅支持人民幣（CNY）計價：商城貨幣代碼非 CNY 時，微信支付在前台不會出現。需伺服器啟用 PHP OpenSSL 擴展（RSA-SHA256 簽名 + AES-256-GCM 解密）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_mchid"><?php esc_html_e('商戶號（mchid）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_wechat_mchid" name="mlshop_wechat_mchid" value="<?php echo esc_attr(mlshop_get_option('wechat_mchid', '')); ?>" class="regular-text" placeholder="16xxxxxxxx">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_appid"><?php esc_html_e('AppID（mch 綁定的公眾號 / 小程序 / APP）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_wechat_appid" name="mlshop_wechat_appid" value="<?php echo esc_attr(mlshop_get_option('wechat_appid', '')); ?>" class="regular-text" placeholder="wxXXXXXXXXXXXXXXXX">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_serial_no"><?php esc_html_e('商戶證書序列號', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_wechat_serial_no" name="mlshop_wechat_serial_no" value="<?php echo esc_attr(mlshop_get_option('wechat_serial_no', '')); ?>" class="large-text" placeholder="<?php esc_attr_e('商戶 API 證書序列號（商戶平台 → API 安全）', 'moonlight-shop'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_private_key"><?php esc_html_e('應用私鑰', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_wechat_private_key" name="mlshop_wechat_private_key" rows="6" class="large-text code" placeholder="<?php esc_attr_e('貼上商户 API 證書私鑰 apiclient_key.pem 內容（支持 PKCS#1 / PKCS#8 / 無頭裸 base64）', 'moonlight-shop'); ?>"></textarea>
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；如需更換請輸入新值。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('wechat_private_key', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_apiv3_key"><?php esc_html_e('APIv3 密鑰（32 位）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="password" id="mlshop_wechat_apiv3_key" name="mlshop_wechat_apiv3_key" value="" class="large-text" autocomplete="new-password" placeholder="<?php esc_attr_e('商戶平台設置的 APIv3 密鑰（32 位）', 'moonlight-shop'); ?>">
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改；用於回調 resource（AES-256-GCM）與平台證書解密。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('wechat_apiv3_key', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_pub_serial"><?php esc_html_e('驗簽模式：微信支付公鑰序列號', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_wechat_pub_serial" name="mlshop_wechat_pub_serial" value="<?php echo esc_attr(mlshop_get_option('wechat_pub_serial', '')); ?>" class="large-text" placeholder="PUB_KEY_ID_XXXXXXXX（留空 = 平台證書模式自動下載）">
                            <p class="description"><?php esc_html_e('新商戶推薦「微信支付公鑰模式」：同時填寫下方公鑰內容。兩項均留空時回退平台證書模式（自動 GET /v3/certificates 下載並緩存 12 小時）。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_pub_key"><?php esc_html_e('微信支付公鑰', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_wechat_pub_key" name="mlshop_wechat_pub_key" rows="5" class="large-text code" placeholder="<?php esc_attr_e('貼上微信支付公鑰（非商戶證書公鑰）', 'moonlight-shop'); ?>"></textarea>
                            <p class="description"><?php echo esc_html(sprintf(__('已保存（%s）。留空表示不修改。', 'moonlight-shop'), mlshop_mask_secret(mlshop_get_option('wechat_pub_key', '')))); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_scene"><?php esc_html_e('支付場景', 'moonlight-shop'); ?></label></th>
                        <td>
                            <?php $wechat_scene = mlshop_get_option('wechat_scene', 'auto'); ?>
                            <select id="mlshop_wechat_scene" name="mlshop_wechat_scene">
                                <option value="auto" <?php selected($wechat_scene, 'auto'); ?>><?php esc_html_e('自動（移動端 H5 / 桌面 Native 掃碼）', 'moonlight-shop'); ?></option>
                                <option value="native" <?php selected($wechat_scene, 'native'); ?>><?php esc_html_e('Native（PC 掃碼）', 'moonlight-shop'); ?></option>
                                <option value="h5" <?php selected($wechat_scene, 'h5'); ?>><?php esc_html_e('H5（手機瀏覽器跳轉）', 'moonlight-shop'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_wechat_description"><?php esc_html_e('前台展示名（可選）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_wechat_description" name="mlshop_wechat_description" value="<?php echo esc_attr(mlshop_get_option('wechat_description', '')); ?>" class="regular-text" placeholder="<?php esc_attr_e('留空時取訂單首件商品標題', 'moonlight-shop'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('異步通知 URL', 'moonlight-shop'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('構建支付請求時會自動攜帶 notify_url，無需在商戶平台另行登記；如需在商戶平台除錯可用以下地址：', 'moonlight-shop'); ?></p>
                            <code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?php echo esc_url($wechat_notify_url); ?></code>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function test_stripe()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlshop_test_stripe')) {
            wp_die(esc_html__('權限不足。', 'moonlight-shop'));
        }
        $stripe = new MLSHOP_Gateway_Stripe();
        $key = $stripe->is_test_mode() ? trim((string) mlshop_get_option('stripe_test_secret', '')) : trim((string) mlshop_get_option('stripe_secret', ''));
        if (!$key) {
            $this->redirect_with_notice('stripe', false, '請先填寫 Stripe Secret Key。');
        }
        $response = wp_remote_get('https://api.stripe.com/v1/balance', array(
            'headers' => array('Authorization' => 'Bearer ' . $key),
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            $this->redirect_with_notice('stripe', false, $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $this->redirect_with_notice('stripe', true, 'Stripe 連線成功！');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $msg = isset($body['error']['message']) ? $body['error']['message'] : 'HTTP ' . $code;
        $this->redirect_with_notice('stripe', false, $msg);
    }

    public function test_paypal()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlshop_test_paypal')) {
            wp_die(esc_html__('權限不足。', 'moonlight-shop'));
        }
        $paypal = new MLSHOP_Gateway_PayPal();
        $token = $this->get_paypal_token($paypal);
        if (is_wp_error($token)) {
            $this->redirect_with_notice('paypal', false, $token->get_error_message());
        }
        $this->redirect_with_notice('paypal', true, 'PayPal 認證成功！');
    }

    private function get_paypal_token($gateway)
    {
        $client_id = trim((string) mlshop_get_option('paypal_client_id', ''));
        $secret    = trim((string) mlshop_get_option('paypal_secret', ''));
        if (!$client_id || !$secret) {
            return new WP_Error('mlshop_paypal_auth', '請先填寫 PayPal Client ID 與 Secret。');
        }
        $base = (bool) mlshop_get_option('paypal_sandbox', 1) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $response = wp_remote_post($base . '/v1/oauth2/token', array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => 'grant_type=client_credentials',
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || empty($data['access_token'])) {
            $msg = isset($data['error_description']) ? $data['error_description'] : 'HTTP ' . $code;
            return new WP_Error('mlshop_paypal_auth', $msg);
        }
        return $data['access_token'];
    }

    public function test_order_email()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlshop_test_order_email')) {
            wp_die(esc_html__('權限不足。', 'moonlight-shop'));
        }
        $admin_email = get_option('admin_email');
        $orders = get_posts(array(
            'post_type'      => 'mlshop_order',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        if (!empty($orders)) {
            // 审计 F7：测试邮件不应把真实订单的卡密明文送出站——
            // 发送前临时掩码卡密行，发送后立即恢复原值。
            $order_id = (int) $orders[0]->ID;
            $orig_delivery = get_post_meta($order_id, '_mlshop_delivery', true);
            if (is_array($orig_delivery)) {
                $masked = $orig_delivery;
                foreach ($masked as $i => $d) {
                    if (isset($d['type']) && 'cardkey' === $d['type'] && !empty($d['key'])) {
                        $masked[$i]['key'] = '****-MASKED-****';
                    }
                }
                update_post_meta($order_id, '_mlshop_delivery', $masked);
            }
            $ok = MLSHOP_Email::get_instance()->send_order_paid_email($order_id, $admin_email, true);
            if (isset($orig_delivery) && is_array($orig_delivery)) {
                update_post_meta($order_id, '_mlshop_delivery', $orig_delivery);
            }
            if ($ok) {
                $this->redirect_with_notice(
                    'TEST',
                    true,
                    sprintf(__('測試郵件已發送到 %s（使用訂單 #%s 模擬，卡密已掩碼，含下載連結）。', 'moonlight-shop'), $admin_email, $orders[0]->post_title)
                );
            }
            $this->redirect_with_notice('TEST', false, __('郵件發送失敗，請檢查伺服器郵件配置（wp_mail）。', 'moonlight-shop'));
        }
        // 沒有訂單：發送一封最簡示例確認發信通道可用
        $site    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = __('【測試】訂單 / 下載確認郵件', 'moonlight-shop');
        $body    = '<div style="max-width:600px;margin:0 auto;padding:24px;background:#fff;border:1px solid #e4e9f0;border-radius:8px;">'
            . '<h1 style="font-size:20px;margin:0 0 16px;color:#1f2937;">' . esc_html($site) . '</h1>'
            . '<p style="color:#374151;">' . esc_html__('這是一封測試郵件，確認您的發信配置正常。購買虛擬商品後，系統會自動把下載連結發送到客戶郵箱。', 'moonlight-shop') . '</p>'
            . '</div>';
        $ok = mlshop_send_html_mail($admin_email, $subject, $body, mlshop_get_option('from_name', $site), mlshop_get_option('from_email', ''));
        $this->redirect_with_notice('TEST', $ok, $ok ? sprintf(__('測試郵件已發送到 %s。', 'moonlight-shop'), $admin_email) : __('郵件發送失敗。', 'moonlight-shop'));
    }

    private function redirect_with_notice($gateway, $success, $msg)
    {
        set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
            'gateway' => $gateway,
            'success' => $success,
            'message' => $msg,
        ), 60);
        wp_safe_redirect(admin_url('edit.php?post_type=mlshop_product&page=mlshop-settings'));
        exit;
    }

    /**
     * 后台手动变更订单状态（admin_post）。
     *
     * 校验管理员权限与 nonce 后调用 MLSHOP_Order::set_status，
     * 由状态机统一触发交付 / 退款回补 / 优惠券释放等副作用。
     */
    public function order_set_status()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlshop_order_set_status')) {
            wp_die(esc_html__('權限不足或校验失敗。', 'moonlight-shop'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $new      = isset($_POST['mlshop_status']) ? sanitize_key((string) $_POST['mlshop_status']) : '';
        $redirect = wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit');

        if ($order_id && $new && class_exists('MLSHOP_Order')) {
            $res = MLSHOP_Order::set_status($order_id, $new);
            if (is_wp_error($res)) {
                set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
                    'gateway' => 'ORDER',
                    'success' => false,
                    'message' => $res->get_error_message(),
                ), 60);
            } else {
                set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
                    'gateway' => 'ORDER',
                    'success' => true,
                    'message' => sprintf(__('訂單狀態已更新為「%s」。', 'moonlight-shop'), mlshop_get_order_status_label($new)),
                ), 60);
            }
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 执行退款（admin_post mlshop_order_refund，订单编辑页「售后与退款」表单）。
     *
     * 校验链：manage_options + check_admin_referer + 规则闸 can_refund + 
     * Moonlight_Refund_Service::process()（网关退款 / 仅标记 / 部分退款）。
     * 结果经 mlshop_admin_notice_{uid} transient + PRG 跳转提示（与状态变更同一模式）。
     */
    public function order_refund()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlshop_order_refund')) {
            wp_die(esc_html__('權限不足或校验失敗。', 'moonlight-shop'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $redirect = wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit');

        $amount = isset($_POST['refund_amount']) ? mlshop_sanitize_float(wp_unslash($_POST['refund_amount'])) : 0.0;
        $reason = isset($_POST['refund_reason']) ? sanitize_textarea_field(wp_unslash($_POST['refund_reason'])) : '';
        $skip   = !empty($_POST['skip_gateway']);

        $res = null;
        if (!$order_id || !class_exists('Moonlight_Refund_Service')) {
            $res = new WP_Error('moonlight_refund_order', __('订单不存在或售后模块不可用。', 'moonlight-shop'));
        } else {
            // 规则闸：拒绝（如已下载/已发卡/已发货/授予超窗）时不执行退款，
            // 提示走协商；过滤器 moonlight_refund_allowed 可在闸内放行。
            $gate = Moonlight_Refund_Service::can_refund($order_id);
            if (is_wp_error($gate)) {
                $res = $gate;
            } else {
                $res = Moonlight_Refund_Service::process($order_id, $amount, $reason, get_current_user_id(), $skip);
            }
        }

        if (is_wp_error($res)) {
            set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
                'gateway' => 'REFUND',
                'success' => false,
                'message' => $res->get_error_message(),
            ), 60);
        } else {
            set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
                'gateway' => 'REFUND',
                'success' => true,
                'message' => $amount > 0
                    ? sprintf(__('已处理退款 %s。', 'moonlight-shop'), number_format($amount, 2, '.', ''))
                    : __('全额退款已处理，订单已标记为已退款。', 'moonlight-shop'),
            ), 60);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /* ---------------- 卡密库存（加密批次模型） ---------------- */

    /** 单次导入行数上限。 */
    const CARD_IMPORT_MAX_LINES = 5000;

    /**
     * 卡密库存管理页：商品选择器 + 批次表 + 批量导入 + 掩码查看 / 单条解密。
     *
     * 解密查看全部写审计日志（option _mlshop_card_audit，环形 100 条）：
     * 掩码展开 action=mask、单条明文 action=reveal、损坏行 action=corrupt。
     */
    public function render_card_stock()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page_url = admin_url('edit.php?post_type=mlshop_product&page=mlshop-card-stock');

        // ---- 批量导入（POST → PRG 跳转）----
        if (isset($_POST['mlshop_card_import'])) {
            check_admin_referer('mlshop_card_import');
            $this->handle_card_import($page_url); // 失败也 redirect（统一走通知），不返回。
        }

        // ---- 单条「查看完整」（POST + nonce + 二次确认字段 confirm=1）----
        $reveal_plain = '';
        $reveal_error = '';
        $reveal_meta_id = 0;
        if (isset($_POST['mlshop_card_reveal'])) {
            check_admin_referer('mlshop_card_reveal');
            $reveal_meta_id = isset($_POST['meta_id']) ? absint($_POST['meta_id']) : 0;
            $confirm = isset($_POST['confirm']) ? sanitize_key(wp_unslash($_POST['confirm'])) : '';
            if ('1' !== $confirm) {
                $reveal_error = __('未通过二次确认（confirm），已取消查看。', 'moonlight-shop');
            } elseif (!$reveal_meta_id) {
                $reveal_error = __('参数无效。', 'moonlight-shop');
            } else {
                $plain = Moonlight_Card_Stock::reveal($reveal_meta_id);
                if (false === $plain) {
                    $reveal_error = __('解密失败：记录不存在、已损坏或权限不足。', 'moonlight-shop');
                } else {
                    $reveal_plain = $plain; // 仅本次响应内展示，不落任何存储。
                }
            }
        }

        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $view_batch = isset($_GET['view_batch']) ? absint($_GET['view_batch']) : 0;
        // 展开校验：批次必须属于当前所选商品，防止跨商品探测。
        if ($view_batch && $product_id) {
            $batch_post = get_post($view_batch);
            if (!$batch_post || (int) $batch_post->post_parent !== $product_id) {
                $view_batch = 0;
            }
        } else {
            $view_batch = 0;
        }
        $view_rows = $view_batch ? Moonlight_Card_Stock::preview($view_batch, 100) : array();
        ?>
        <div class="wrap mlshop-admin-settings">
            <h1><?php esc_html_e('卡密库存', 'moonlight-shop'); ?></h1>
            <p class="description"><?php esc_html_e('卡密以加密批次入库（AES-256-CBC，明文不落库）。停用批次后其卡密不再参与发货；每次查看（含掩码展开）都会记录审计日志。', 'moonlight-shop'); ?></p>

            <h2 class="mlshop-card-title"><?php esc_html_e('选择商品', 'moonlight-shop'); ?></h2>
            <form method="get">
                <input type="hidden" name="post_type" value="mlshop_product">
                <input type="hidden" name="page" value="mlshop-card-stock">
                <select name="product_id">
                    <?php echo $this->card_product_options($product_id); // phpcs:ignore WordPress.Security.EscapeOutput -- 内部已 esc_html/esc_attr。 ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('查看批次', 'moonlight-shop'); ?></button>
            </form>

            <?php if ($reveal_plain || $reveal_error) : ?>
                <div class="notice <?php echo $reveal_plain ? 'notice-success' : 'notice-error'; ?>">
                    <?php if ($reveal_plain) : ?>
                        <p><strong><?php esc_html_e('卡密明文（已记录审计）：', 'moonlight-shop'); ?></strong>
                            <code style="font-size:14px;"><?php echo esc_html($reveal_plain); ?></code>
                            <span class="description">(meta_id: <?php echo (int) $reveal_meta_id; ?>)</span></p>
                        <p class="description"><?php esc_html_e('请立即复制保存；此明文不再展示（刷新后消失）。', 'moonlight-shop'); ?></p>
                    <?php else : ?>
                        <p><?php echo esc_html($reveal_error); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($product_id) : ?>
                <?php $batches = Moonlight_Card_Stock::batches($product_id); ?>
                <h2 class="mlshop-card-title"><?php esc_html_e('批次列表', 'moonlight-shop'); ?></h2>
                <?php if (empty($batches)) : ?>
                    <p class="description"><?php esc_html_e('该商品暂无卡密批次。', 'moonlight-shop'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('批次名', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('总数', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('可售', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('已售', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('状态', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('过期时间', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('创建时间', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('操作', 'moonlight-shop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($batches as $b) :
                            $toggle_status = $b['disabled'] ? 'publish' : 'draft';
                            $toggle_url = wp_nonce_url(
                                admin_url('admin-post.php?action=mlshop_card_batch_status&batch_id=' . (int) $b['id'] . '&status=' . $toggle_status),
                                'mlshop_card_batch_status_' . (int) $b['id']
                            );
                            $view_url = add_query_arg(array('product_id' => $product_id, 'view_batch' => (int) $b['id']), $page_url);
                            if ((int) $b['id'] === $view_batch) {
                                $view_url = add_query_arg(array('product_id' => $product_id, 'view_batch' => 0), $page_url);
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html($b['name']); ?></strong></td>
                                <td><?php echo (int) $b['total']; ?></td>
                                <td><?php echo (int) $b['available']; ?></td>
                                <td><?php echo (int) $b['sold']; ?></td>
                                <td><?php echo esc_html($b['disabled'] ? __('已停用', 'moonlight-shop') : __('启用中', 'moonlight-shop')); ?></td>
                                <td>
                                    <?php
                                    if ($b['expires'] > 0) {
                                        echo esc_html($b['expires'] <= time()
                                            ? __('已过期', 'moonlight-shop')
                                            : date_i18n('Y-m-d H:i', $b['expires']));
                                    } else {
                                        esc_html_e('永不', 'moonlight-shop');
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($b['date']); ?></td>
                                <td>
                                    <a class="button" href="<?php echo esc_url($toggle_url); ?>">
                                        <?php echo esc_html($b['disabled'] ? __('启用', 'moonlight-shop') : __('停用', 'moonlight-shop')); ?>
                                    </a>
                                    <a class="button" href="<?php echo esc_url($view_url); ?>">
                                        <?php echo ((int) $b['id'] === $view_batch) ? esc_html__('收起', 'moonlight-shop') : esc_html__('查看卡密', 'moonlight-shop'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('过期批次自动停止售卖；停用批次可随时重新启用。', 'moonlight-shop'); ?></p>
                <?php endif; ?>

                <?php if ($view_batch && is_array($view_rows)) : ?>
                    <h3 class="mlshop-card-title"><?php esc_html_e('卡密列表（掩码，前 100 条）', 'moonlight-shop'); ?></h3>
                    <?php if (empty($view_rows)) : ?>
                        <p class="description"><?php esc_html_e('该批次暂无卡密。', 'moonlight-shop'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:5em;">#</th>
                                    <th><?php esc_html_e('卡密（掩码）', 'moonlight-shop'); ?></th>
                                    <th><?php esc_html_e('状态', 'moonlight-shop'); ?></th>
                                    <th style="width:10em;"><?php esc_html_e('操作', 'moonlight-shop'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i = 0; foreach ($view_rows as $row) : $i++; ?>
                                <tr>
                                    <td><?php echo (int) $i; ?></td>
                                    <td><code><?php echo esc_html($row['masked']); ?></code></td>
                                    <td><?php echo esc_html($this->card_status_label($row['status'])); ?></td>
                                    <td>
                                        <form method="post" style="display:inline;"
                                              onsubmit="return confirm('<?php echo esc_attr__('确定查看完整卡密？此操作将记入审计日志。', 'moonlight-shop'); ?>');">
                                            <input type="hidden" name="meta_id" value="<?php echo (int) $row['meta_id']; ?>">
                                            <input type="hidden" name="confirm" value="1">
                                            <?php wp_nonce_field('mlshop_card_reveal'); ?>
                                            <button type="submit" name="mlshop_card_reveal" value="1" class="button-link">
                                                <?php esc_html_e('查看完整', 'moonlight-shop'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <h2 class="mlshop-card-title"><?php esc_html_e('批量导入卡密', 'moonlight-shop'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('mlshop_card_import'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="mlshop_card_product"><?php esc_html_e('商品', 'moonlight-shop'); ?></label></th>
                        <td>
                            <select name="product_id" id="mlshop_card_product">
                                <?php echo $this->card_product_options($product_id); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_card_batch_name"><?php esc_html_e('批次名', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="text" id="mlshop_card_batch_name" name="mlshop_card_batch_name" class="regular-text"
                                   placeholder="<?php esc_attr_e('留空自动命名（导入 Ymd-His）', 'moonlight-shop'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_card_expires_days"><?php esc_html_e('过期天数', 'moonlight-shop'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="mlshop_card_expires_days" name="mlshop_card_expires_days" value="0" class="small-text">
                            <p class="description"><?php esc_html_e('0 = 永不过期；大于 0 时整批卡密在该天数后自动停止售卖。', 'moonlight-shop'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlshop_card_keys"><?php esc_html_e('卡密（每行一条）', 'moonlight-shop'); ?></label></th>
                        <td>
                            <textarea id="mlshop_card_keys" name="mlshop_card_keys" rows="10" class="large-text code"></textarea>
                            <p class="description">
                                <?php printf(esc_html__('每行一条，单次最多 %d 行；保存后立即加密，明文不落库。与已有卡密重复的行会被自动跳过。', 'moonlight-shop'), (int) self::CARD_IMPORT_MAX_LINES); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="mlshop_card_import" value="1" class="button button-primary">
                        <?php esc_html_e('导入卡密', 'moonlight-shop'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * 批量导入 POST 处理：校验 → import() → PRG 跳转（结果走统一通知）。
     *
     * @param string $page_url 回跳地址。
     */
    private function handle_card_import($page_url)
    {
        $pid  = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $days = isset($_POST['mlshop_card_expires_days']) ? absint($_POST['mlshop_card_expires_days']) : 0;
        $name = isset($_POST['mlshop_card_batch_name']) ? sanitize_text_field(wp_unslash($_POST['mlshop_card_batch_name'])) : '';
        $raw  = isset($_POST['mlshop_card_keys']) ? (string) wp_unslash($_POST['mlshop_card_keys']) : '';

        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim((string) $line);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        $redirect = add_query_arg('product_id', $pid, $page_url);
        if (!$pid || !get_post($pid)) {
            $this->card_notice(false, __('请选择有效商品后再导入。', 'moonlight-shop'), $redirect);
        }
        if (empty($lines)) {
            $this->card_notice(false, __('卡密内容为空，未导入。', 'moonlight-shop'), $redirect);
        }
        if (count($lines) > self::CARD_IMPORT_MAX_LINES) {
            $this->card_notice(false, sprintf(__('单次最多导入 %d 行（当前 %d 行），请分批导入。', 'moonlight-shop'), self::CARD_IMPORT_MAX_LINES, count($lines)), $redirect);
        }

        $expires = $days > 0 ? time() + $days * DAY_IN_SECONDS : 0;
        $res = Moonlight_Card_Stock::import($pid, $lines, $name, $expires);
        if (is_array($res) && $res['imported'] > 0) {
            $msg = sprintf(
                /* translators: %1$d：导入数；%2$d：重复跳过数；%3$d：批次 ID */
                __('导入成功：%1$d 条入池，跳过重复 %2$d 条（批次 #%3$d）。', 'moonlight-shop'),
                (int) $res['imported'],
                (int) $res['duplicates'],
                (int) $res['batch_id']
            );
            $this->card_notice(true, $msg, $redirect);
        }
        $this->card_notice(false, __('没有导入任何新卡密（可能全部与库存池中已有卡密重复）。', 'moonlight-shop'), $redirect);
    }

    /**
     * 批次启用 / 停用（admin_post，GET + nonce）。
     */
    public function card_batch_status()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'moonlight-shop'));
        }
        $batch_id = isset($_GET['batch_id']) ? absint($_GET['batch_id']) : 0;
        $status   = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        check_admin_referer('mlshop_card_batch_status_' . $batch_id);

        $pid    = 0;
        $batch  = $batch_id ? get_post($batch_id) : null;
        if ($batch && Moonlight_Card_Stock::CPT === $batch->post_type) {
            $pid = (int) $batch->post_parent;
        }
        $ok = Moonlight_Card_Stock::set_batch_status($batch_id, $status);
        set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
            'gateway' => 'CARD',
            'success' => (bool) $ok,
            'message' => $ok
                ? ($status === 'publish' ? __('批次已启用。', 'moonlight-shop') : __('批次已停用。', 'moonlight-shop'))
                : __('批次状态更新失败。', 'moonlight-shop'),
        ), 60);
        $referer = wp_get_referer();
        $fallback = admin_url('edit.php?post_type=mlshop_product&page=mlshop-card-stock' . ($pid ? '&product_id=' . $pid : ''));
        wp_safe_redirect($referer ?: $fallback);
        exit;
    }

    /**
     * 商品下拉选项（卡密商品优先，其余商品附后）。
     *
     * @param int $selected 当前选中 ID。
     * @return string HTML（已转义）。
     */
    private function card_product_options($selected)
    {
        $cardkey_ids = get_posts(array(
            'post_type'      => 'mlshop_product',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(array('key' => '_mlshop_type', 'value' => 'cardkey')),
        ));
        $all_ids = get_posts(array(
            'post_type'      => 'mlshop_product',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        $others = array_values(array_diff(array_map('intval', (array) $all_ids), array_map('intval', (array) $cardkey_ids)));

        $html = '<option value="0">' . esc_html__('— 选择商品 —', 'moonlight-shop') . '</option>';
        foreach (array(
            __('卡密商品', 'moonlight-shop') => (array) $cardkey_ids,
            __('其他商品', 'moonlight-shop') => $others,
        ) as $label => $ids) {
            if (empty($ids)) {
                continue;
            }
            $html .= '<optgroup label="' . esc_attr($label) . '">';
            foreach ($ids as $id) {
                $title = get_the_title($id);
                if (!$title) {
                    $title = '#' . (int) $id;
                }
                $html .= '<option value="' . esc_attr($id) . '" ' . selected((int) $selected, (int) $id, false) . '>'
                    . esc_html($title . ' (#' . (int) $id . ')') . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }

    /**
     * 卡密行状态键 → 显示名。
     *
     * @param string $meta_key
     * @return string
     */
    private function card_status_label($meta_key)
    {
        $map = array(
            Moonlight_Card_Stock::ST_AVAILABLE => __('可售', 'moonlight-shop'),
            Moonlight_Card_Stock::ST_SOLD      => __('已售', 'moonlight-shop'),
            Moonlight_Card_Stock::ST_USED      => __('已核销', 'moonlight-shop'),
            Moonlight_Card_Stock::ST_EXPIRED   => __('已过期/停用', 'moonlight-shop'),
        );
        return isset($map[$meta_key]) ? $map[$meta_key] : (string) $meta_key;
    }

    /**
     * 卡密页操作结果通知（transient + PRG 跳转）。
     *
     * @param bool   $success
     * @param string $message
     * @param string $redirect
     */
    private function card_notice($success, $message, $redirect)
    {
        set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
            'gateway' => 'CARD',
            'success' => (bool) $success,
            'message' => $message,
        ), 60);
        wp_safe_redirect($redirect);
        exit;
    }
}

// 显示管理员通知
add_action('admin_notices', function () {
    $user_id = get_current_user_id();
    $notice = get_transient('mlshop_admin_notice_' . $user_id);
    if (!$notice) {
        return;
    }
    delete_transient('mlshop_admin_notice_' . $user_id);
    $class = $notice['success'] ? 'notice-success' : 'notice-error';
    echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
        . '<strong>' . esc_html(strtoupper($notice['gateway'])) . '：</strong> '
        . esc_html($notice['message'])
        . '</p></div>';
});
