<?php
/**
 * 商城前端资源。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Assets
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
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        // Elementor 编辑器（iframe）内也要能预览商城小工具样式
        add_action('elementor/editor/before_enqueue_scripts', array($this, 'enqueue_editor'));
        // 列表/网格列数随后台设置变化，注入响应式 CSS
        add_action('wp_head', array($this, 'layout_css'), 20);
    }

    private static function asset_version($rel)
    {
        // CSS/JS 版本号跟随文件 mtime，浏览器永远拿到最新版本（避免 wp_enqueue_style 因
        // 同一插件版本号导致旧 CSS 被强制缓存看不到我们的修改）。
        $path = MLSHOP_PLUGIN_DIR . $rel;
        if (file_exists($path)) {
            return (string) filemtime($path);
        }
        return MLSHOP_VERSION;
    }

    public function enqueue()
    {
        $has = false;
        if (is_singular()) {
            $content = get_post_field('post_content', get_the_ID());
            $codes   = array('mlshop_products', 'mlshop_cart', 'mlshop_checkout', 'mlshop_orders', 'mlshop_downloads', 'mlshop_order', 'mluc_account');
            foreach ($codes as $c) {
                if (has_shortcode($content, $c)) {
                    $has = true;
                    break;
                }
            }
        }
        // 支持商品 CPT 歸檔頁（主題啟用 mlshop_product 歸檔時仍可正常加載樣式與腳本）
        if (!$has && is_post_type_archive('mlshop_product')) {
            $has = true;
        }
        // 商品分类归档页同样加载商城资源
        if (!$has && is_tax('mlshop_product_cat')) {
            $has = true;
        }
        // 商品标签归档页同样加载商城资源
        if (!$has && is_tax('mlshop_product_tag')) {
            $has = true;
        }
        // 商品详情页（付费墙 UI 需要脚本与样式）
        if (!$has && is_singular('mlshop_product')) {
            $has = true;
        }
        // Elementor 构建的页面（预览/已发布）可能放置商城小工具，统一加载资源
        if (!$has && is_singular() && class_exists('Elementor\\Plugin')) {
            $has = true;
        }
        // Elementor 编辑器 iframe 内预览
        if (!$has && did_action('elementor/editor/before_enqueue_scripts')) {
            $has = true;
        }

        if (!$has) {
            // 页眉购物车 / 收藏按钮全站显示，因此前端资源也全站加载（脚本仅对已有 DOM 生效，无副作用）
            $has = true;
        }

        wp_enqueue_style('mlshop-style', MLSHOP_PLUGIN_URL . 'assets/css/mlshop.css', array(), self::asset_version('assets/css/mlshop.css'));
        wp_enqueue_style('mlshop-product', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-product.css', array('mlshop-style'), self::asset_version('assets/css/mlshop-product.css'));

        // 购买按钮样式（后台「商城设置 → 外观」可调）：以 CSS 变量下发给全部按钮
        $btn_size   = max(10, min(22, (int) mlshop_get_option('btn_font_size', 14)));
        $btn_radius = max(0, min(24, (int) mlshop_get_option('btn_radius', 8)));
        $btn_color  = (string) mlshop_get_option('btn_color', '#2271b1');
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $btn_color)) {
            $btn_color = '#2271b1';
        }
        wp_add_inline_style('mlshop-style', sprintf(
            ':root{--mlshop-btn-size:%dpx;--mlshop-btn-radius:%dpx;--mlshop-btn-color:%s;}',
            $btn_size, $btn_radius, $btn_color
        ));
        wp_enqueue_script('mlshop-script', MLSHOP_PLUGIN_URL . 'assets/js/mlshop.js', array('jquery'), self::asset_version('assets/js/mlshop.js'), true);
        wp_localize_script('mlshop-script', 'MLSHOP', mlshop_ajax_data());
        wp_localize_script('mlshop-script', 'mlshop_i18n', array(
            'network_error'       => __('网络错误，请重试', 'at8-moonlight-shop'),
            'added'               => mlshop_buy_text('added'),
            'add_to_cart'         => mlshop_buy_text('add'),
            'enter_coupon'        => __('请输入优惠码', 'at8-moonlight-shop'),
            'select_recharge_pkg' => __('请选择充值套餐', 'at8-moonlight-shop'),
            'address_required'     => __('请填写收件人、聯絡電話、省市與收件地址', 'at8-moonlight-shop'),
            'pickup_required'      => __('请填写提货人姓名与手机号', 'at8-moonlight-shop'),
            'guest_email_required' => __('请填写有效的电子邮箱，订单确认与虚拟商品将发送到该邮箱。', 'at8-moonlight-shop'),
            'pickup_free'          => __('到店自提（免運費）', 'at8-moonlight-shop'),
            'pickup_note'          => __('到店自提免運費，請憑提貨人手機號到店領取。', 'at8-moonlight-shop'),
            'addr_update'          => __('更新地址', 'at8-moonlight-shop'),
            'addr_confirm_delete'  => __('确定删除该收货地址？', 'at8-moonlight-shop'),
            'favorite'            => __('收藏', 'at8-moonlight-shop'),
            'faved'               => __('已收藏', 'at8-moonlight-shop'),
            'select_gallery'      => __('选择商品相册', 'at8-moonlight-shop'),
            'confirm_album'       => __('确认相册', 'at8-moonlight-shop'),
            'confirm_delivery'    => __('确定已收到商品？确认后订单将完成。', 'at8-moonlight-shop'),
            'apply_refund_confirm' => __('确定提交售后申请？提交后请等待管理员处理。', 'at8-moonlight-shop'),
            'refund_reason_required' => __('请填写售后原因。', 'at8-moonlight-shop'),
        ));
    }

    /**
     * Elementor 编辑器内强制加载商城前端样式（编辑器 iframe 不含 wp_enqueue_scripts 检测）。
     */
    public function enqueue_editor()
    {
        wp_enqueue_style('mlshop-style', MLSHOP_PLUGIN_URL . 'assets/css/mlshop.css', array(), self::asset_version('assets/css/mlshop.css'));
        wp_enqueue_style('mlshop-product', MLSHOP_PLUGIN_URL . 'assets/css/mlshop-product.css', array('mlshop-style'), self::asset_version('assets/css/mlshop-product.css'));
    }

    /**
     * 输出动态列数 / 间距 CSS（响应后台的"商品列表布局"设置）。
     * 仅在真正用到商城样式的页面输出；不依赖 !important。
     */
    public function layout_css()
    {
        if (!function_exists('mlshop_get_option')) {
            return;
        }
        // 仅归档/分类/搜索结果/Elementor 单页输出，避免无意义注入
        if (!(is_post_type_archive('mlshop_product') || is_tax('mlshop_product_cat') || is_tax('mlshop_product_tag') || is_search() || is_singular('mlshop_product') || is_singular())) {
            return;
        }
        $cols_d = max(1, (int) mlshop_get_option('archive_cols_desktop', 4));
        $cols_t = max(1, (int) mlshop_get_option('archive_cols_tablet', 2));
        $cols_m = max(1, (int) mlshop_get_option('archive_cols_mobile', 1));
        $gap    = max(0, (int) mlshop_get_option('archive_gap_px', 20));
        $css = sprintf(
            '.mlshop-archive-wrap .mlshop-archive-grid,.mlshop-archive-wrap .mlshop-related-grid,.mlshop-archive-wrap .mlshop-favorites-grid,.mlshop-single .mlshop-related-grid{--mlshop-cols:%1$d;gap:%4$dpx;}' .
            'body .mlshop-archive-grid,body .mlshop-related-grid,body .mlshop-favorites-grid,body .mlshop-products{grid-template-columns:repeat(%1$d,1fr);}' .
            '@media (max-width:900px){body .mlshop-archive-grid,body .mlshop-related-grid,body .mlshop-favorites-grid,body .mlshop-products{grid-template-columns:repeat(%2$d,1fr);}}' .
            '@media (max-width:520px){body .mlshop-archive-grid,body .mlshop-related-grid,body .mlshop-favorites-grid,body .mlshop-products{grid-template-columns:repeat(%3$d,1fr);}}',
            $cols_d, $cols_t, $cols_m, $gap
        );

        // 側邊欄位置（右/左/隱藏）：面包屑永遠佔首行，left 用 order 把側邊欄提前到主內容左側。
        $pos = mlshop_get_option('sidebar_position', 'right');
        if (!in_array($pos, array('left', 'right', 'none'), true)) {
            $pos = 'right';
        }
        $sidebar_css = '.mlshop-breadcrumb{order:-3;}';
        if ($pos === 'left') {
            $sidebar_css .= '.mlshop-single-wrap>.mlshop-sidebar,.mlshop-archive-wrap>.mlshop-sidebar{order:-1;}';
        } elseif ($pos === 'none') {
            $sidebar_css .= '.mlshop-sidebar{display:none;}' .
                '.mlshop-single-main,.mlshop-archive-main{flex:1 1 100%;max-width:100%;}';
        }

        // 側邊欄懸停（吸頂）：開啟且非「隱藏」時，滾動頁面側欄固定。
        // align-self:flex-start 避免 flex 拉伸使其與主內容等高而失去懸停空間；
        // top 預留管理條(32px)+吸頂頭部，避免被主題固定頭部遮住。
        if ($pos !== 'none' && (int) mlshop_get_option('sidebar_sticky', 1) === 1) {
            $sidebar_css .= '.mlshop-single-wrap>.mlshop-sidebar,.mlshop-archive-wrap>.mlshop-sidebar{position:sticky;top:90px;align-self:flex-start;}';
        }

        echo '<style id="mlshop-layout-css">' . esc_html($css) . esc_html($sidebar_css) . '</style>';
    }
}
