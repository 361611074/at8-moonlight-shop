<?php
/**
 * 漫步白月光商城 · Elementor 小工具类。
 * 仅在 Elementor 已加载时由 class-elementor.php 引入（extends Elementor 基类）。
 *
 * @package Moonlight_Shop
 */

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/* ============================================================
 * 1) 商品网格
 * ========================================================== */
class MLSHOP_Elementor_Products extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_products';
    }

    public function get_title()
    {
        return __('商城·商品网格', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-products';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', array('label' => __('内容', 'at8-moonlight-shop')));
        $this->add_control('cat', array(
            'label'   => __('分类', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::SELECT,
            'options' => MLSHOP_Elementor::category_options(),
            'default' => '',
        ));
        $this->add_control('limit', array(
            'label'   => __('显示数量', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::NUMBER,
            'default' => 12,
            'min'     => 1,
            'max'     => 48,
        ));
        $this->add_control('columns', array(
            'label'   => __('每行列数', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::SELECT,
            'options' => array('2' => '2', '3' => '3', '4' => '4'),
            'default' => '4',
        ));
        $this->add_control('orderby', array(
            'label'   => __('排序', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::SELECT,
            'options' => array(
                'date'       => __('最新', 'at8-moonlight-shop'),
                'title'      => __('标题', 'at8-moonlight-shop'),
                'price_asc'  => __('价格升序', 'at8-moonlight-shop'),
                'price_desc' => __('价格降序', 'at8-moonlight-shop'),
            ),
            'default' => 'date',
        ));
        $this->add_control('show_type_badge', array(
            'label'        => __('显示类型徽章', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'description'  => __('关闭后此区域内的列表卡片不显示「实物 / 虚拟 / 卡密」徽章；留「默认」则跟随后台「列表显示」设置。', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => '',
        ));
        $this->add_control('show_tags', array(
            'label'        => __('显示商品标签', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'description'  => __('关闭后此区域内的列表卡片不渲染标签云；留「默认」则跟随后台「列表显示」设置。', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => '',
        ));
        $this->end_controls_section();

        /* 样式：标题字号 / 颜色 / 字重 / 行高，均可单独调 */
        $this->start_controls_section('style_title', array(
            'label' => __('卡片标题', 'at8-moonlight-shop'),
            'tab'   => Controls_Manager::TAB_STYLE,
        ));
        $this->add_group_control(
            Group_Control_Typography::get_type(),
            array(
                'name'     => 'title_typography',
                'selector' => '{{WRAPPER}} .mlshop-product-title',
            )
        );
        $this->add_control('title_color', array(
            'label'     => __('标题颜色', 'at8-moonlight-shop'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlshop-product-title a' => 'color: {{VALUE}};',
            ),
        ));
        $this->add_control('title_hover_color', array(
            'label'     => __('悬停颜色', 'at8-moonlight-shop'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .mlshop-product-title a:hover' => 'color: {{VALUE}};',
            ),
        ));
        $this->add_responsive_control('title_spacing', array(
            'label'      => __('与下方间距', 'at8-moonlight-shop'),
            'type'       => Controls_Manager::SLIDER,
            'size_units' => array('px', 'em'),
            'range'      => array(
                'px' => array('min' => 0, 'max' => 60, 'step' => 1),
                'em' => array('min' => 0, 'max' => 4,  'step' => 0.1),
            ),
            'selectors'  => array(
                '{{WRAPPER}} .mlshop-product-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
            ),
        ));
        $this->end_controls_section();
    }

    protected function render()
    {
        $s     = $this->get_settings_for_display();
        $cols  = (int) ($s['columns'] ?? 4);
        $limit = (int) ($s['limit'] ?? 12);
        $args  = array(
            'post_type'      => 'mlshop_product',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
        );
        if (!empty($s['cat'])) {
            $args['tax_query'] = array(array('taxonomy' => 'mlshop_product_cat', 'field' => 'slug', 'terms' => $s['cat']));
        }
        if ($s['orderby'] === 'price_asc') {
            $args['meta_key'] = '_mlshop_price';
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'ASC';
        } elseif ($s['orderby'] === 'price_desc') {
            $args['meta_key'] = '_mlshop_price';
            $args['orderby']  = 'meta_value_num';
            $args['order']    = 'DESC';
        } else {
            $args['orderby'] = $s['orderby'];
        }
        $q = new WP_Query($args);

        // 仅当用户在 widget 里明确打开开关时才覆盖模板（默认态/关闭态 → 跟随后台，由模板读 mlshop_get_option）。
        $item_args = array('product_id' => 0);
        if (!empty($s['show_type_badge'])) {
            $item_args['show_type_badge'] = true;
        }
        if (!empty($s['show_tags'])) {
            $item_args['show_tags'] = true;
        }

        echo '<div class="mlshop-products mlshop-archive-grid" style="--mlshop-cols:' . esc_attr($cols) . ';">';
        if ($q->have_posts()) {
            while ($q->have_posts()) {
                $q->the_post();
                $item_args['product_id'] = get_the_ID();
                mlshop_get_template('product-item', $item_args);
            }
        } else {
            echo '<p class="mlshop-empty">' . esc_html__('暂无商品。', 'at8-moonlight-shop') . '</p>';
        }
        echo '</div>';
        wp_reset_postdata();
    }
}

/* ============================================================
 * 2) 商品单页（含相册）
 * ========================================================== */
class MLSHOP_Elementor_Product_Single extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_product_single';
    }

    public function get_title()
    {
        return __('商城·商品单页', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-product-images';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', array('label' => __('内容', 'at8-moonlight-shop')));
        $this->add_control('product_id', array(
            'label'   => __('选择商品', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::SELECT,
            'options' => MLSHOP_Elementor::product_options(),
            'default' => 'current',
        ));
        $this->add_control('show_gallery', array(
            'label'        => __('显示相册（主图+缩略图）', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->add_control('show_add_to_cart', array(
            'label'        => __('显示加入购物车', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->add_control('show_favorite', array(
            'label'        => __('显示收藏按钮', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->add_control('show_tabs', array(
            'label'        => __('显示描述/信息/评价标签', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->add_control('show_reviews', array(
            'label'        => __('显示评价标签', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'description'  => __('关闭后只保留「商品描述 / 更多信息」两个标签。可在商城设置里关闭「商品评价」总开关后此项自动隐藏。', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => array('show_tabs' => 'yes'),
        ));
        $this->end_controls_section();
    }

    protected function render()
    {
        $s          = $this->get_settings_for_display();
        $product_id = MLSHOP_Elementor::resolve_product_id($s['product_id'] ?? 'current');
        if (!$product_id) {
            echo '<p class="mlshop-empty">' . esc_html__('请在「选择商品」中指定一个商品。', 'at8-moonlight-shop') . '</p>';
            return;
        }
        mlshop_get_template('elementor/single-product', array(
            'product_id'       => $product_id,
            'show_gallery'     => ($s['show_gallery'] ?? 'yes') === 'yes',
            'show_summary'     => true,
            'show_add_to_cart' => ($s['show_add_to_cart'] ?? 'yes') === 'yes',
            'show_favorite'    => ($s['show_favorite'] ?? 'yes') === 'yes',
            'show_tabs'        => ($s['show_tabs'] ?? 'yes') === 'yes',
            'show_reviews'     => ($s['show_reviews'] ?? 'yes') === 'yes' && (int) mlshop_get_option('reviews_enabled', 1) === 1,
        ));
    }
}

/* ============================================================
 * 3) 加入购物车
 * ========================================================== */
class MLSHOP_Elementor_Add_To_Cart extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_add_to_cart';
    }

    public function get_title()
    {
        return __('商城·加入购物车', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-button';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', array('label' => __('内容', 'at8-moonlight-shop')));
        $this->add_control('product_id', array(
            'label'   => __('选择商品', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::SELECT,
            'options' => MLSHOP_Elementor::product_options(),
            'default' => 'current',
        ));
        $this->add_control('button_text', array(
            'label'   => __('按钮文字', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::TEXT,
            'default' => __('加入购物车', 'at8-moonlight-shop'),
        ));
        $this->add_control('show_qty', array(
            'label'        => __('显示数量输入', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->add_control('show_favorite', array(
            'label'        => __('显示收藏按钮', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'no',
        ));
        $this->end_controls_section();
    }

    protected function render()
    {
        $s          = $this->get_settings_for_display();
        $product_id = MLSHOP_Elementor::resolve_product_id($s['product_id'] ?? 'current');
        if (!$product_id) {
            echo '<p class="mlshop-empty">' . esc_html__('请在「选择商品」中指定一个商品。', 'at8-moonlight-shop') . '</p>';
            return;
        }
        $price = (float) get_post_meta($product_id, '_mlshop_price', true);
        $stock = get_post_meta($product_id, '_mlshop_stock', true);
        $stock = $stock === '' ? -1 : (int) $stock;
        ?>
        <div class="mlshop-single-summary">
            <div class="mlshop-price"><?php echo esc_html(mlshop_format_price($price)); ?></div>
            <form class="mlshop-buy-form" onsubmit="return false;">
                <?php if (($s['show_qty'] ?? 'yes') === 'yes') : ?>
                    <div class="mlshop-qty-wrap">
                        <label><?php esc_html_e('数量', 'at8-moonlight-shop'); ?></label>
                        <input type="number" class="mlshop-qty" name="qty" value="1" min="1" max="<?php echo $stock > 0 ? (int) $stock : ''; ?>">
                    </div>
                <?php endif; ?>
                <button type="button" class="mlshop-btn mlshop-add-to-cart mlshop-btn-primary" data-product-id="<?php echo (int) $product_id; ?>">
                    <?php echo esc_html($s['button_text'] ?? __('加入购物车', 'at8-moonlight-shop')); ?>
                </button>
                <?php if (($s['show_favorite'] ?? 'no') === 'yes' && function_exists('mlshop_favorite_button')) { mlshop_favorite_button($product_id); } ?>
            </form>
        </div>
        <?php
    }
}

/* ============================================================
 * 4) 商品分类
 * ========================================================== */
class MLSHOP_Elementor_Categories extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_categories';
    }

    public function get_title()
    {
        return __('商城·商品分类', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-folder';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', array('label' => __('内容', 'at8-moonlight-shop')));
        $this->add_control('layout', array(
            'label'   => __('布局', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::SELECT,
            'options' => array('list' => __('列表', 'at8-moonlight-shop'), 'grid' => __('网格', 'at8-moonlight-shop')),
            'default' => 'list',
        ));
        $this->add_control('show_count', array(
            'label'        => __('显示商品数', 'at8-moonlight-shop'),
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => __('显示', 'at8-moonlight-shop'),
            'label_off'    => __('隐藏', 'at8-moonlight-shop'),
            'return_value' => 'yes',
            'default'      => 'yes',
        ));
        $this->end_controls_section();
    }

    protected function render()
    {
        $s      = $this->get_settings_for_display();
        $layout = $s['layout'] ?? 'list';
        $terms  = get_terms(array('taxonomy' => 'mlshop_product_cat', 'hide_empty' => false));
        if (empty($terms) || is_wp_error($terms)) {
            echo '<p class="mlshop-empty">' . esc_html__('暂无分类。', 'at8-moonlight-shop') . '</p>';
            return;
        }
        $cls = $layout === 'grid' ? 'mlshop-cat-grid' : 'mlshop-cat-list';
        echo '<ul class="mlshop-cat ' . esc_attr($cls) . '">';
        foreach ($terms as $t) {
            printf(
                '<li class="mlshop-cat-item"><a href="%s">%s%s</a></li>',
                esc_url(get_term_link($t)),
                esc_html($t->name),
                (($s['show_count'] ?? 'yes') === 'yes') ? ' <span class="mlshop-cat-count">(' . (int) $t->count . ')</span>' : ''
            );
        }
        echo '</ul>';
    }
}

/* ============================================================
 * 5) 购物车
 * ========================================================== */
class MLSHOP_Elementor_Cart extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_cart';
    }

    public function get_title()
    {
        return __('商城·购物车', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-cart';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function render()
    {
        echo do_shortcode('[mlshop_cart]');
    }
}

/* ============================================================
 * 6) 收藏
 * ========================================================== */
class MLSHOP_Elementor_Favorites extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_favorites';
    }

    public function get_title()
    {
        return __('商城·我的收藏', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-heart';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function render()
    {
        echo do_shortcode('[mlshop_favorites]');
    }
}

/* ============================================================
 * 7) 商品搜索
 * ========================================================== */
class MLSHOP_Elementor_Search extends Widget_Base
{
    public function get_name()
    {
        return 'mlshop_search';
    }

    public function get_title()
    {
        return __('商城·商品搜索', 'at8-moonlight-shop');
    }

    public function get_icon()
    {
        return 'eicon-search';
    }

    public function get_categories()
    {
        return array('at8-moonlight-shop');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content', array('label' => __('内容', 'at8-moonlight-shop')));
        $this->add_control('placeholder', array(
            'label'   => __('输入框占位', 'at8-moonlight-shop'),
            'type'    => Controls_Manager::TEXT,
            'default' => __('搜索商品…', 'at8-moonlight-shop'),
        ));
        $this->end_controls_section();
    }

    protected function render()
    {
        $s   = $this->get_settings_for_display();
        $url = mlshop_get_option('store_url', '');
        if (!$url) {
            $url = get_post_type_archive_link('mlshop_product');
        }
        ?>
        <form class="mlshop-search-form" role="search" method="get" action="<?php echo esc_url($url); ?>">
            <input type="search" class="mlshop-search-field" name="s" placeholder="<?php echo esc_attr($s['placeholder'] ?? __('搜索商品…', 'at8-moonlight-shop')); ?>">
            <button type="submit" class="mlshop-btn mlshop-search-btn"><?php esc_html_e('搜索', 'at8-moonlight-shop'); ?></button>
        </form>
        <?php
    }
}
