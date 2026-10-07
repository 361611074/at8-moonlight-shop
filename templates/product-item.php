<?php
/**
 * 单个商品卡片（在商品循环中调用，全局 $post 已设置）。
 *
 * 可通过 $args 覆盖默认行为：
 *   - 'show_type_badge' (bool) 是否显示「实物 / 虚拟 / 卡密」徽章；默认跟随后台「列表显示」设置。
 *   - 'show_tags'       (bool) 是否显示标签云；默认跟随后台「列表显示」设置。
 */
if (!defined('ABSPATH')) {
    exit;
}

// 调用方覆盖：$args['show_type_badge'|'show_tags']
$override_type = isset($args) && is_array($args) && array_key_exists('show_type_badge', $args) ? (bool) $args['show_type_badge'] : null;
$override_tags = isset($args) && is_array($args) && array_key_exists('show_tags', $args) ? (bool) $args['show_tags'] : null;

$show_type_badge = ($override_type !== null) ? $override_type : ((int) mlshop_get_option('archive_show_type_badge', 0) === 1);
$show_tags       = ($override_tags !== null) ? $override_tags : ((int) mlshop_get_option('archive_show_tags', 0) === 1);

// 产品标题排版（后台「商品列表布局 → 商品标题排版」控制，全局可调）
// 关键坑：-webkit-line-clamp 必须和 display:-webkit-box 容器同元素，否则 clamp 失效。
// CSS 里 .mlshop-product-title a 才是 webkit-box 容器，所以 font-size/font-weight/clamp 全部内联到 a；
// text-align 留在 h3 才能正确居中 a 块。
$title_size   = (int)  mlshop_get_option('archive_title_font_size', 16);
$title_size   = max(10, min(32, $title_size)); // 防御性：UI min/max 之外时夹紧
$title_weight = (string) mlshop_get_option('archive_title_font_weight', '600');
$allowed_w    = array('300', '400', '500', '600', '700', '800');
if (!in_array($title_weight, $allowed_w, true)) { $title_weight = '600'; }
$title_align  = (string) mlshop_get_option('archive_title_align', 'center');
$allowed_a    = array('left', 'center', 'right');
if (!in_array($title_align, $allowed_a, true)) { $title_align = 'center'; }
$title_clamp  = (int)  mlshop_get_option('archive_title_line_clamp', 2);
$title_clamp  = max(1, min(6, $title_clamp)); // 1-6 行限制
$title_style_h3 = sprintf('text-align:%s;', $title_align);
$title_style_a  = sprintf(
    'font-size:%dpx;font-weight:%s;-webkit-line-clamp:%d;',
    $title_size,
    $title_weight,
    $title_clamp
);

// 商品价格排版（字号/字重/颜色，UI 在「商品列表布局 → 商品价格排版」）
$price_size   = (int)  mlshop_get_option('archive_price_font_size', 17);
$price_size   = max(10, min(32, $price_size));
$price_weight = (string) mlshop_get_option('archive_price_font_weight', '700');
$allowed_pw   = array('400', '500', '600', '700', '800');
if (!in_array($price_weight, $allowed_pw, true)) { $price_weight = '700'; }
$price_color  = (string) mlshop_get_option('archive_price_color', '#e23b3b');
if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $price_color)) { $price_color = '#e23b3b'; }
$price_style = sprintf(
    'font-size:%dpx;font-weight:%s;color:%s;',
    $price_size,
    $price_weight,
    $price_color
);

$price = (float) get_post_meta(get_the_ID(), '_mlshop_price', true);
$type  = get_post_meta(get_the_ID(), '_mlshop_type', true);
$type_label = array(
    'physical' => __('实物', 'at8-moonlight-shop'),
    'virtual'  => __('虚拟', 'at8-moonlight-shop'),
    'cardkey'  => __('卡密', 'at8-moonlight-shop'),
);
?>
<article class="mlshop-product-item">
    <a href="<?php the_permalink(); ?>" class="mlshop-product-thumb">
        <?php if (has_post_thumbnail()) { the_post_thumbnail('medium'); } else { echo '<div class="mlshop-no-thumb"></div>'; } ?>
    </a>
    <div class="mlshop-product-body">
        <h3 class="mlshop-product-title" style="<?php echo esc_attr($title_style_h3); ?>"><a href="<?php the_permalink(); ?>" style="<?php echo esc_attr($title_style_a); ?>"><?php the_title(); ?></a></h3>
        <div class="mlshop-product-meta">
            <span class="mlshop-price" style="<?php echo esc_attr($price_style); ?>"><?php echo esc_html(mlshop_format_price($price)); ?></span>
            <?php if ($show_type_badge && isset($type_label[$type])) : ?>
                <span class="mlshop-type-badge"><?php echo esc_html($type_label[$type]); ?></span>
            <?php endif; ?>
        </div>
        <?php if ($show_tags) :
            $tags = get_the_terms(get_the_ID(), 'mlshop_product_tag');
            if (!empty($tags) && !is_wp_error($tags)) : ?>
                <div class="mlshop-product-tags">
                    <?php foreach ($tags as $t) :
                        $tl = get_term_link($t);
                        if (is_wp_error($tl)) { continue; }
                    ?>
                        <a href="<?php echo esc_url($tl); ?>" class="mlshop-tag mlshop-tag-sm"><?php echo esc_html($t->name); ?></a>
                    <?php endforeach; ?>
                </div>
        <?php endif; endif; ?>
        <?php
        // 可变商品：卡片加购默认选第一个套餐（一年），详情页可换
        $default_key = (string) get_the_ID();
        $variants_meta = get_post_meta(get_the_ID(), '_at8lic_variants', true);
        if (is_array($variants_meta) && $variants_meta) {
            $first_plan = isset($variants_meta[0]['plan']) ? sanitize_key($variants_meta[0]['plan']) : '';
            if ($first_plan !== '') {
                $default_key = get_the_ID() . '_' . $first_plan;
            }
        }
        ?>
        <div class="mlshop-product-actions">
            <button class="mlshop-btn mlshop-add-to-cart" data-product-id="<?php echo esc_attr($default_key); ?>"><?php echo esc_html(mlshop_buy_text('add')); ?></button>
            <?php if (function_exists('mlshop_favorite_button')) { mlshop_favorite_button(get_the_ID()); } ?>
        </div>
    </div>
</article>
