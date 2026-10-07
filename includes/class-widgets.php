<?php
/**
 * 商城侧栏与小工具：搜索 / 分类 / 标签 / 最新商品 / 价格筛选 / 收藏。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Widgets
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
        add_action('widgets_init', array($this, 'register_sidebar'));
        add_action('widgets_init', array($this, 'register_widgets'));
    }

    public function register_sidebar()
    {
        register_sidebar(array(
            'id'            => 'mlshop-shop',
            'name'          => __('商城侧栏', 'at8-moonlight-shop'),
            'description'   => __('商品列表页与商品详情页右侧显示的小工具。', 'at8-moonlight-shop'),
            'before_widget' => '<div class="mlshop-widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h2 class="mlshop-widget-title">',
            'after_title'   => '</h2>',
        ));
    }

    public function register_widgets()
    {
        register_widget('MLSHOP_Widget_Product_Search');
        register_widget('MLSHOP_Widget_Product_Categories');
        register_widget('MLSHOP_Widget_Product_Tags');
        register_widget('MLSHOP_Widget_Recent_Products');
        register_widget('MLSHOP_Widget_Price_Filter');
        register_widget('MLSHOP_Widget_Favorites');
    }
}

/**
 * 商品搜索。
 */
class MLSHOP_Widget_Product_Search extends WP_Widget
{
    public function __construct()
    {
        parent::__construct('mlshop_product_search', __('商品搜索', 'at8-moonlight-shop'), array('classname' => 'mlshop-widget-product-search'));
    }

    public function widget($args, $instance)
    {
        $title = apply_filters('widget_title', empty($instance['title']) ? __('商品搜索', 'at8-moonlight-shop') : $instance['title']);
        echo esc_html($args['before_widget']);
        echo esc_html($args['before_title']) . esc_html($title) . esc_html($args['after_title']);
        $action = get_post_type_archive_link('mlshop_product');
        ?>
        <form class="mlshop-search-form" role="search" method="get" action="<?php echo esc_url($action); ?>">
            <input type="search" class="mlshop-search-field" name="s" placeholder="<?php esc_attr_e('搜索商品…', 'at8-moonlight-shop'); ?>" value="<?php echo esc_attr(get_search_query()); ?>">
            <button type="submit" class="mlshop-btn mlshop-search-btn"><?php esc_html_e('搜索', 'at8-moonlight-shop'); ?></button>
        </form>
        <?php
        echo esc_html($args['after_widget']);
    }

    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label><?php esc_html_e('标题:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
            </label>
        </p>
        <?php
    }

    public function update($new, $old)
    {
        return array('title' => sanitize_text_field($new['title']));
    }
}

/**
 * 商品分类。
 */
class MLSHOP_Widget_Product_Categories extends WP_Widget
{
    public function __construct()
    {
        parent::__construct('mlshop_product_categories', __('商品分类', 'at8-moonlight-shop'), array('classname' => 'mlshop-widget-product-categories'));
    }

    public function widget($args, $instance)
    {
        $title = apply_filters('widget_title', empty($instance['title']) ? __('商品分类', 'at8-moonlight-shop') : $instance['title']);
        $count = !empty($instance['count']);
        echo esc_html($args['before_widget']);
        echo esc_html($args['before_title']) . esc_html($title) . esc_html($args['after_title']);
        $terms = get_terms(array('taxonomy' => 'mlshop_product_cat', 'hide_empty' => true));
        if (!empty($terms) && !is_wp_error($terms)) {
            echo '<ul class="mlshop-cat-list">';
            foreach ($terms as $t) {
                printf(
                    '<li><a href="%s">%s%s</a></li>',
                    esc_url(get_term_link($t)),
                    esc_html($t->name),
                    $count ? ' <span class="mlshop-cat-count">(' . (int) $t->count . ')</span>' : ''
                );
            }
            echo '</ul>';
        } else {
            echo '<p class="mlshop-empty">' . esc_html__('暂无分类。', 'at8-moonlight-shop') . '</p>';
        }
        echo esc_html($args['after_widget']);
    }

    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : '';
        $count = !empty($instance['count']);
        ?>
        <p>
            <label><?php esc_html_e('标题:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
            </label>
        </p>
        <p>
            <label><input type="checkbox" name="<?php echo esc_attr($this->get_field_name('count')); ?>" value="1" <?php checked($count); ?>> <?php esc_html_e('显示商品数', 'at8-moonlight-shop'); ?></label>
        </p>
        <?php
    }

    public function update($new, $old)
    {
        return array(
            'title' => sanitize_text_field($new['title']),
            'count' => !empty($new['count']) ? 1 : 0,
        );
    }
}

/**
 * 商品标签云。
 */
class MLSHOP_Widget_Product_Tags extends WP_Widget
{
    public function __construct()
    {
        parent::__construct('mlshop_product_tags', __('商品标签', 'at8-moonlight-shop'), array('classname' => 'mlshop-widget-product-tags'));
    }

    public function widget($args, $instance)
    {
        $title  = apply_filters('widget_title', empty($instance['title']) ? __('商品标签', 'at8-moonlight-shop') : $instance['title']);
        $number = !empty($instance['number']) ? max(0, (int) $instance['number']) : 0; // 0 = 全部
        $count  = !empty($instance['count']);
        $terms  = get_terms(array(
            'taxonomy'   => 'mlshop_product_tag',
            'hide_empty' => true,
            'number'     => $number,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ));
        echo esc_html($args['before_widget']);
        echo esc_html($args['before_title']) . esc_html($title) . esc_html($args['after_title']);
        if (!empty($terms) && !is_wp_error($terms)) {
            echo '<div class="mlshop-tag-cloud">';
            foreach ($terms as $t) {
                printf(
                    '<a class="mlshop-tag" href="%s">%s%s</a>',
                    esc_url(get_term_link($t)),
                    esc_html($t->name),
                    $count ? ' <span class="mlshop-tag-count">(' . (int) $t->count . ')</span>' : ''
                );
            }
            echo '</div>';
        } else {
            echo '<p class="mlshop-empty">' . esc_html__('暂无标签。', 'at8-moonlight-shop') . '</p>';
        }
        echo esc_html($args['after_widget']);
    }

    public function form($instance)
    {
        $title  = isset($instance['title']) ? $instance['title'] : '';
        $number = isset($instance['number']) ? (int) $instance['number'] : 0;
        $count  = !empty($instance['count']);
        ?>
        <p>
            <label><?php esc_html_e('标题:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
            </label>
        </p>
        <p>
            <label><?php esc_html_e('显示数量 (0 = 全部):', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('number')); ?>" type="number" min="0" value="<?php echo esc_attr($number); ?>">
            </label>
        </p>
        <p>
            <label><input type="checkbox" name="<?php echo esc_attr($this->get_field_name('count')); ?>" value="1" <?php checked($count); ?>> <?php esc_html_e('显示商品数', 'at8-moonlight-shop'); ?></label>
        </p>
        <?php
    }

    public function update($new, $old)
    {
        return array(
            'title'  => sanitize_text_field($new['title']),
            'number' => max(0, (int) $new['number']),
            'count'  => !empty($new['count']) ? 1 : 0,
        );
    }
}
class MLSHOP_Widget_Recent_Products extends WP_Widget
{
    public function __construct()
    {
        parent::__construct('mlshop_recent_products', __('最新商品', 'at8-moonlight-shop'), array('classname' => 'mlshop-widget-recent-products'));
    }

    public function widget($args, $instance)
    {
        $title = apply_filters('widget_title', empty($instance['title']) ? __('最新商品', 'at8-moonlight-shop') : $instance['title']);
        $number = max(1, (int) (!empty($instance['number']) ? $instance['number'] : 5));
        echo esc_html($args['before_widget']);
        echo esc_html($args['before_title']) . esc_html($title) . esc_html($args['after_title']);
        $q = new WP_Query(array('post_type' => 'mlshop_product', 'posts_per_page' => $number, 'post_status' => 'publish'));
        if ($q->have_posts()) {
            echo '<ul class="mlshop-recent-list">';
            while ($q->have_posts()) {
                $q->the_post();
                echo '<li><a href="' . esc_url(get_permalink()) . '">' . get_the_post_thumbnail(get_the_ID(), 'thumbnail') . '<span>' . esc_html(get_the_title()) . '</span></a></li>';
            }
            wp_reset_postdata();
            echo '</ul>';
        } else {
            echo '<p class="mlshop-empty">' . esc_html__('暂无商品。', 'at8-moonlight-shop') . '</p>';
        }
        echo esc_html($args['after_widget']);
    }

    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : '';
        $number = isset($instance['number']) ? (int) $instance['number'] : 5;
        ?>
        <p>
            <label><?php esc_html_e('标题:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
            </label>
        </p>
        <p>
            <label><?php esc_html_e('显示数量:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('number')); ?>" type="number" min="1" value="<?php echo esc_attr($number); ?>">
            </label>
        </p>
        <?php
    }

    public function update($new, $old)
    {
        return array(
            'title'  => sanitize_text_field($new['title']),
            'number' => max(1, (int) $new['number']),
        );
    }
}

/**
 * 价格筛选。
 */
class MLSHOP_Widget_Price_Filter extends WP_Widget
{
    /** 价格上下限缓存 key */
    const BOUNDS_KEY = 'mlshop_price_bounds_v1';

    public function __construct()
    {
        parent::__construct('mlshop_price_filter', __('价格筛选', 'at8-moonlight-shop'), array('classname' => 'mlshop-widget-price-filter'));
        // 商品保存/删除时让价格上下限缓存失效，确保新价立即反映到滑块
        add_action('save_post_mlshop_product', array($this, 'clear_bounds_cache'));
        add_action('before_delete_post', array($this, 'clear_bounds_cache'));
    }

    /** 让缓存失效（公开方法便于外部调用） */
    public function clear_bounds_cache()
    {
        delete_transient(self::BOUNDS_KEY);
    }

    /**
     * 查所有已发布商品的 _mlshop_price 上下限；6h 缓存。
     * 返回 array('min' => float, 'max' => float)；若无商品则返回 0/1000 兜底。
     */
    private function get_price_bounds()
    {
        $cached = get_transient(self::BOUNDS_KEY);
        if (is_array($cached) && isset($cached['min'], $cached['max']) && $cached['max'] > 0) {
            return $cached;
        }
        global $wpdb;
        $sql = "SELECT MIN(CAST(pm.meta_value AS DECIMAL(12,2))) AS lo, " .
               "       MAX(CAST(pm.meta_value AS DECIMAL(12,2))) AS hi " .
               "FROM {$wpdb->postmeta} pm " .
               "INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id " .
               "WHERE pm.meta_key = '_mlshop_price' " .
               "  AND pm.meta_value <> '' " .
               "  AND p.post_type = 'mlshop_product' " .
               "  AND p.post_status = 'publish'";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 动态构建但已参数化
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- 参数已白名单化
$row = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 动态构建但已参数化
        $min = ($row && $row['lo'] !== null) ? (float) $row['lo'] : 0;
        $max = ($row && $row['hi'] !== null) ? (float) $row['hi'] : 0;
        if ($max <= 0) {
            $min = 0;
            $max = 1000;
        }
        $out = array('min' => $min, 'max' => $max);
        set_transient(self::BOUNDS_KEY, $out, 6 * HOUR_IN_SECONDS);
        return $out;
    }

    public function widget($args, $instance)
    {
        $title = apply_filters('widget_title', empty($instance['title']) ? __('价格筛选', 'at8-moonlight-shop') : $instance['title']);
        // 仅商品列表/分类页显示
        if (!(is_post_type_archive('mlshop_product') || is_tax('mlshop_product_cat'))) {
            return;
        }
        $action = is_tax('mlshop_product_cat') ? get_term_link(get_queried_object()) : get_post_type_archive_link('mlshop_product');
        $req_min = isset($_GET['min_price']) ? (float) $_GET['min_price'] : null;
        $req_max = isset($_GET['max_price']) ? (float) $_GET['max_price'] : null;

        $bounds = $this->get_price_bounds();
        $lo = (float) $bounds['min'];
        $hi = (float) $bounds['max'];
        if ($hi <= $lo) {
            $hi = $lo + max(1.0, $lo * 0.2);
        }
        // 把 URL 传入的价格钳到上下限内
        $cur_min = ($req_min !== null) ? max($lo, min($hi, $req_min)) : $lo;
        $cur_max = ($req_max !== null) ? max($lo, min($hi, $req_max)) : $hi;
        if ($cur_min > $cur_max) {
            $cur_min = $cur_max;
        }
        // 步长：跨度 > 500 时用粗步长（约 200 个可拖动位置），否则 1
        $range = $hi - $lo;
        $step = ($range > 500) ? max(1, (int) round($range / 200)) : 1;

        $fmt = function ($v) {
            $v = (float) $v;
            return (floor($v * 100) === (float) $v * 100 && floor($v) === $v) ? (string) (int) $v : number_format($v, 2, '.', '');
        };

        // 初始 hidden 值：未传参时留空（避免空过滤）
        $hmin_val = ($req_min !== null) ? $fmt($cur_min) : '';
        $hmax_val = ($req_max !== null) ? $fmt($cur_max) : '';

        echo esc_html($args['before_widget']);
        echo esc_html($args['before_title']) . esc_html($title) . esc_html($args['after_title']);
        ?>
        <form class="mlshop-price-filter-form" method="get" action="<?php echo esc_url($action); ?>">
            <div class="mlshop-price-slider"
                 data-min="<?php echo esc_attr($lo); ?>"
                 data-max="<?php echo esc_attr($hi); ?>"
                 data-step="<?php echo esc_attr($step); ?>">
                <div class="mlshop-price-slider-track"></div>
                <div class="mlshop-price-slider-range" aria-hidden="true"></div>
                <input type="range" class="mlshop-price-slider-min" min="<?php echo esc_attr($lo); ?>" max="<?php echo esc_attr($hi); ?>" step="<?php echo esc_attr($step); ?>" value="<?php echo esc_attr($cur_min); ?>" aria-label="<?php esc_attr_e('最低价格', 'at8-moonlight-shop'); ?>">
                <input type="range" class="mlshop-price-slider-max" min="<?php echo esc_attr($lo); ?>" max="<?php echo esc_attr($hi); ?>" step="<?php echo esc_attr($step); ?>" value="<?php echo esc_attr($cur_max); ?>" aria-label="<?php esc_attr_e('最高价格', 'at8-moonlight-shop'); ?>">
            </div>
            <div class="mlshop-price-display" aria-live="polite">
                <span class="mlshop-price-display-min"><?php echo esc_html($fmt($cur_min)); ?></span>
                <span class="mlshop-price-display-sep">—</span>
                <span class="mlshop-price-display-max"><?php echo esc_html($fmt($cur_max)); ?></span>
            </div>
            <input type="hidden" name="min_price" class="mlshop-price-hidden-min" value="<?php echo esc_attr($hmin_val); ?>">
            <input type="hidden" name="max_price" class="mlshop-price-hidden-max" value="<?php echo esc_attr($hmax_val); ?>">
            <button type="submit" class="mlshop-btn mlshop-price-btn"><?php esc_html_e('筛选', 'at8-moonlight-shop'); ?></button>
            <?php
            // 透传其它 GET 参数（如 orderby、paged 等），但排除 min_price/max_price
            foreach ($_GET as $k => $v) {
                if (in_array($k, array('min_price', 'max_price'), true)) {
                    continue;
                }
                if (is_array($v)) {
                    continue;
                }
                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
            }
            ?>
        </form>
        <?php
        echo esc_html($args['after_widget']);
    }

    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label><?php esc_html_e('标题:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
            </label>
        </p>
        <?php
    }

    public function update($new, $old)
    {
        return array('title' => sanitize_text_field($new['title']));
    }
}

/**
 * 我的收藏。
 */
class MLSHOP_Widget_Favorites extends WP_Widget
{
    public function __construct()
    {
        parent::__construct('mlshop_favorites', __('我的收藏', 'at8-moonlight-shop'), array('classname' => 'mlshop-widget-favorites'));
    }

    public function widget($args, $instance)
    {
        if (!class_exists('MLSHOP_Favorite')) {
            return;
        }
        $title = apply_filters('widget_title', empty($instance['title']) ? __('我的收藏', 'at8-moonlight-shop') : $instance['title']);
        echo esc_html($args['before_widget']);
        // 内部标题 + ul/empty 一律走 fragment helper，保证与 ajax 实时刷新输出完全一致
        echo wp_kses_post(mlshop_render_favorites_widget_inner($title));
        echo esc_html($args['after_widget']);
    }

    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label><?php esc_html_e('标题:', 'at8-moonlight-shop'); ?>
                <input class="widefat" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
            </label>
        </p>
        <?php
    }

    public function update($new, $old)
    {
        return array('title' => sanitize_text_field($new['title']));
    }
}

