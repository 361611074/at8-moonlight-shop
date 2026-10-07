<?php
/**
 * 产品付费 Meta Box。
 *
 * 字段：付费模式 / 购买权限 / 支付类型 / 积分售价 / 会员积分售价 /
 *       价格系列 / 销售浮动 / 优惠券 / 订单时效 / 商品信息 / 推广折扣
 *
 * 与现有 MLSHOP_Product 的"商品设置"Meta Box 完全独立：
 *   - 商品设置 = 商城基础（价格/类型/SKU/库存/下载/卡密）
 *   - 付费功能 = 本站原创的高级付费控制（模式 / 权限 / 价格 / 时效 / 资源）
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Product_Pay_Meta
{
    private static $instance;

    /** @var array<string,string> 付费模式 */
    public static $pay_modes = array(
        'off'      => '关闭',
        'read'     => '付费阅读',
        'download' => '付费下载',
        'image'    => '付费图片',
        'video'    => '付费视频',
    );

    /** @var array<string,string> 购买权限 */
    public static $pay_auths = array(
        'all'     => '所有人可购买',
        'gold'    => '黄金会员及以上可购买',
        'diamond' => '仅钻石会员可购买',
    );

    /** @var array<string,string> 支付类型 */
    public static $pay_types = array(
        'money'  => '普通商品（金钱购买）',
        'credit' => '积分商品（积分购买）',
    );

    /** @var array<string,string> 订单时效单位 */
    public static $expire_units = array(
        'hour'  => '小时',
        'day'   => '天',
        'month' => '个月',
    );

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        // priority 25：晚于 class-product.php 的 save_meta（默认 10），保证两个 Meta Box 互不冲突
        // 改用通用 save_post 以便在 商品 / 文章 / 页面 上都能保存付费字段
        add_action('save_post', array($this, 'save_meta'), 25);
    }

    public function register_meta_box()
    {
        // 商品、文章、页面均支持付费功能（影片/教材即文章，按分类区分，无需独立菜单）
        foreach (array('mlshop_product', 'post', 'page') as $pt) {
            add_meta_box(
                'mlshop_pay_meta',
                '付费功能',
                array($this, 'render'),
                $pt,
                'normal',
                'high'
            );
        }
    }

    public function render($post)
    {
        wp_nonce_field('mlshop_pay_meta', 'mlshop_pay_meta_nonce');

        $data = array();
        foreach (self::get_fields() as $name => $type) {
            $val = get_post_meta($post->ID, '_mlshop_' . $name, true);
            $data[$name] = ($val === '' || $val === false) ? self::default_for($name) : $val;
        }

        $symbol      = mlshop_get_option('currency_symbol', 'HK$');
        $credit_name = mlshop_get_option('credit_name', '积分');

        include MLSHOP_PLUGIN_DIR . 'templates/product-pay-meta.php';
    }

    public function save_meta($post_id)
    {
        if (!isset($_POST['mlshop_pay_meta_nonce'])) {
            return;
        }
        // 仅处理受支持的类型（商品 / 文章 / 页面）
        $type = get_post_type($post_id);
        if (!in_array($type, array('mlshop_product', 'post', 'page'), true)) {
            return;
        }
        if (!wp_verify_nonce($_POST['mlshop_pay_meta_nonce'], 'mlshop_pay_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        foreach (self::get_fields() as $name => $type) {
            $key = '_mlshop_' . $name;
            if ($type === 'bool') {
                // bool 字段：未勾选也要显式记 0，否则下次读不到会沿用旧值
                $val = (isset($_POST['mlshop_' . $name]) && $_POST['mlshop_' . $name]) ? '1' : '0';
                update_post_meta($post_id, $key, $val);
                continue;
            }
            if (!isset($_POST['mlshop_' . $name])) {
                continue;
            }
            $val = wp_unslash($_POST['mlshop_' . $name]);
            switch ($type) {
                case 'float':    $val = (float) $val; break;
                case 'int':      $val = (int) $val;   break;
                case 'key':      $val = sanitize_key($val); break;
                case 'json':     $val = self::sanitize_json($val); break;
                case 'jsonlist': $val = self::sanitize_json_value($val); break;
                default:         $val = sanitize_text_field($val); break;
            }
            // JSON 含反斜杠转义，wp_slash 抵消 update_post_meta 内部的 wp_unslash，避免中文/引号存储损坏
            if ($type === 'json' || $type === 'jsonlist') {
                $val = wp_slash($val);
            }
            update_post_meta($post_id, $key, $val);
        }
    }

    /**
     * 校验并清洗 JSON（用于 download_attrs）。
     * 仅允许 [string,string] 数组；其他回退为空字符串。
     */
    private static function sanitize_json($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return '';
        }
        $clean = array();
        foreach ($arr as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $k = sanitize_text_field((string) $pair[0]);
            $v = sanitize_text_field((string) $pair[1]);
            if ($k === '') {
                continue;
            }
            $clean[] = array($k, $v);
        }
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 校验并清洗通用 JSON 列表（用于 download_items / video_items / image_urls）。
     * 支持「对象数组」与「字符串数组」两种形态；非法值回退为空字符串。
     */
    private static function sanitize_json_value($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return '';
        }
        $clean = array();
        foreach ($arr as $el) {
            if (is_array($el)) {
                $item = array();
                foreach ($el as $k => $v) {
                    $item[sanitize_key((string) $k)] = is_array($v)
                        ? $v
                        : sanitize_text_field((string) $v);
                }
                $clean[] = $item;
            } elseif (is_string($el) || is_numeric($el)) {
                $clean[] = sanitize_text_field((string) $el);
            }
        }
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 全部字段定义。
     */
    public static function get_fields()
    {
        return array(
            // 主分类
            'pay_mode'             => 'key',
            'pay_auth'             => 'key',
            'pay_type'             => 'key',
            // 积分售价（积分商品）
            'credit_price'           => 'float',
            'credit_price_gold'      => 'float',
            'credit_price_diamond'   => 'float',
            // 价格系列（普通商品）
            'price_sell'             => 'float',
            'price_original'         => 'float',
            'price_gold'             => 'float',
            'price_diamond'          => 'float',
            // 免费下载（仅 download 模式）
            'free_downloads'         => 'int',
            'free_download_over_price' => 'float',
            // 销售与折扣
            'sales_offset'           => 'int',
            'aff_discount'           => 'float',
            // 优惠码
            'allow_coupon'           => 'bool',
            // 订单时效
            'order_expire_enabled'   => 'bool',
            'order_expire_value'     => 'int',
            'order_expire_unit'      => 'key',
            // 商品信息（付费弹窗）
            'pay_popup_title'        => 'text',
            'pay_popup_desc'         => 'text',
            // === 付费下载（仅 download 模式） ===
            'download_items'         => 'jsonlist', // JSON: [{url,label,copy_name,copy_content}]
            'download_note'          => 'text',
            'download_btn_icon'      => 'text',
            'download_btn_color'     => 'text',
            'download_attrs'         => 'json',     // JSON: [["k","v"],...]
            'demo_url'               => 'text',
            // === 付费图片（仅 image 模式） ===
            'image_gallery'          => 'text', // CSV 附件 ID（上传图，支持多张）
            'image_free_count'       => 'int',
            'image_urls'             => 'jsonlist', // JSON: ["url",...] 外链图片（资源链接地址）
            // === 付费视频（仅 video 模式） ===
            'video_items'            => 'jsonlist', // JSON: [{url,cover,title}]
        );
    }

    private static function default_for($name)
    {
        $defaults = array(
            'pay_mode'             => 'off',
            'pay_auth'             => 'all',
            'pay_type'             => 'money',
            'allow_coupon'         => '0',
            'order_expire_enabled' => '0',
            'order_expire_unit'    => 'day',
            'credit_price'           => 0,
            'credit_price_gold'      => 0,
            'credit_price_diamond'   => 0,
            'price_sell'             => 0,
            'price_original'         => 0,
            'price_gold'             => 0,
            'price_diamond'          => 0,
            'free_downloads'         => 0,
            'free_download_over_price' => 0,
            'sales_offset'           => 0,
            'aff_discount'           => 0,
            'order_expire_value'     => 0,
            'pay_popup_title'        => '',
            'pay_popup_desc'         => '',
            'download_items'         => '',
            'download_note'          => '',
            'download_btn_icon'      => 'fa-solid fa-download',
            'download_btn_color'     => '#2271b1',
            'download_attrs'         => '',
            'demo_url'               => '',
            'image_gallery'          => '',
            'image_free_count'       => 0,
            'image_urls'             => '',
            'video_items'            => '',
        );
        return isset($defaults[$name]) ? $defaults[$name] : '';
    }

    /**
     * 对外 helper：读一个字段（含默认）。
     *
     * Phase C（双付费墙合并）：新 meta（_mlshop_*）缺席时兼容读取旧「用户中心」
     * 付费墙 meta（_mluc_pw_*，映射见 get_legacy_value）——仅商城独立运行
     * （旧插件未激活，未定义 MLUC_LEGACY_ACTIVE）时生效；新 meta 优先，写路径不变。
     */
    public static function get($post_id, $name, $default = '')
    {
        if (!isset(self::get_fields()[$name])) {
            return $default;
        }
        $val = get_post_meta($post_id, '_mlshop_' . $name, true);
        if ($val === '' || $val === false) {
            if (!defined('MLUC_LEGACY_ACTIVE')) {
                $legacy = self::get_legacy_value($post_id, $name);
                if (null !== $legacy) {
                    return $legacy;
                }
            }
            return self::default_for($name);
        }
        return $val;
    }

    /**
     * 旧「用户中心」付费墙 meta 只读兼容（Phase C）。
     *
     * 真实映射（MLUC_Paywall::get_fields() 与本类 get_fields() 逐键比对后确定）：
     * 两版付费墙字段名几乎同名直映（meta 前缀 _mluc_pw_ → _mlshop_），包括
     * pay_mode / pay_auth / price_sell / price_original / sales_offset /
     * order_expire_* / pay_popup_* / download_* / demo_url / image_* / video_items；
     * 动态等级价 _mluc_pw_price_{level} → price_{level}（商城等级价体系只消费
     * gold / diamond 两档）。旧版独有字段（无对应）不映射：pay_type（旧版仅金钱
     * 支付，商城默认 money 已等价）、credit_price* / allow_coupon / aff_discount /
     * free_downloads（用户中心无积分/优惠码体系）。
     *
     * @return mixed|null 旧值；未映射或旧值不存在返回 null（调用方回退默认值）。
     */
    private static function get_legacy_value($post_id, $name)
    {
        static $map = array(
            'pay_mode', 'pay_auth', 'price_sell', 'price_original', 'sales_offset',
            'order_expire_enabled', 'order_expire_value', 'order_expire_unit',
            'pay_popup_title', 'pay_popup_desc',
            'download_items', 'download_note', 'download_btn_icon', 'download_btn_color',
            'download_attrs', 'demo_url',
            'image_gallery', 'image_free_count', 'image_urls',
            'video_items',
            // 动态等级价（_mluc_pw_price_gold / _mluc_pw_price_diamond）
            'price_gold', 'price_diamond',
        );
        if (!in_array($name, $map, true)) {
            return null;
        }
        $val = get_post_meta($post_id, '_mluc_pw_' . $name, true);
        return ('' === $val || false === $val) ? null : $val;
    }
}