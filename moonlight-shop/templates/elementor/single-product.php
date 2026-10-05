<?php
/**
 * 商品单品区块（供 Elementor「商品单页」小工具复用）。
 * 直接渲染相册 + 摘要 + 加购 + 收藏 + Tab，不调用 get_header/sidebar。
 *
 * 传入参数（由 Elementor widget 提供）：
 *   $product_id        int    商品 ID
 *   $show_gallery      bool   显示主图 + 相册缩略图
 *   $show_summary      bool   显示标题 / 价格 / 类型 / 描述 / 加购 / 收藏
 *   $show_add_to_cart  bool   显示数量 + 加入购物车
 *   $show_favorite     bool   显示收藏按钮
 *   $show_tabs         bool   显示「描述 / 更多信息 / 评价」标签页
 *   $show_reviews     bool   单独控制「评价」标签（默认跟随后台「商品评价」开关）
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}

$product_id = isset($product_id) ? (int) $product_id : get_the_ID();
if (!$product_id) {
    return;
}

$show_gallery     = isset($show_gallery) ? (bool) $show_gallery : true;
$show_summary     = isset($show_summary) ? (bool) $show_summary : true;
$show_add_to_cart = isset($show_add_to_cart) ? (bool) $show_add_to_cart : true;
$show_favorite    = isset($show_favorite) ? (bool) $show_favorite : true;
$show_tabs        = isset($show_tabs) ? (bool) $show_tabs : true;
$show_reviews     = isset($show_reviews) ? (bool) $show_reviews : ((int) mlshop_get_option('reviews_enabled', 1) === 1);

$price      = (float) get_post_meta($product_id, '_mlshop_price', true);
$type       = get_post_meta($product_id, '_mlshop_type', true);
$sku        = get_post_meta($product_id, '_mlshop_sku', true);
$stock      = get_post_meta($product_id, '_mlshop_stock', true);
$stock      = $stock === '' ? -1 : (int) $stock;
$type_label = array(
    'physical' => __('实物商品', 'moonlight-shop'),
    'virtual'  => __('虚拟下载', 'moonlight-shop'),
    'cardkey'  => __('卡密商品', 'moonlight-shop'),
);
$gallery    = mlshop_get_product_gallery($product_id);
$gallery_full = array();
foreach ($gallery as $gid) {
    $u = wp_get_attachment_image_url((int) $gid, 'large');
    if ($u) {
        $gallery_full[] = $u;
    }
}
$main_img      = !empty($gallery_full) ? $gallery_full[0] : (has_post_thumbnail($product_id) ? get_the_post_thumbnail_url($product_id, 'large') : '');
$main_img_full = $main_img;
if (!empty($gallery)) {
    $full = wp_get_attachment_image_url((int) $gallery[0], 'full');
    if ($full) { $main_img_full = $full; }
} elseif (has_post_thumbnail($product_id)) {
    $full = get_the_post_thumbnail_url($product_id, 'full');
    if ($full) { $main_img_full = $full; }
}
$cats       = get_the_terms($product_id, 'mlshop_product_cat');
$tags       = get_the_terms($product_id, 'mlshop_product_tag');
$gallery_count = count($gallery_full);

$show_breadcrumbs = (int) mlshop_get_option('show_breadcrumbs', 1);
$archive_link     = get_post_type_archive_link('mlshop_product');
?>

<?php if ($show_breadcrumbs) : ?>
<nav class="mlshop-breadcrumb" aria-label="<?php esc_attr_e('面包屑', 'moonlight-shop'); ?>">
    <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('首頁', 'moonlight-shop'); ?></a>
    <span class="sep">›</span>
    <?php if ($archive_link) : ?>
        <a href="<?php echo esc_url($archive_link); ?>"><?php esc_html_e('商城', 'moonlight-shop'); ?></a>
        <span class="sep">›</span>
    <?php endif; ?>
    <?php if (!empty($cats) && !is_wp_error($cats)) : ?>
        <?php
        $crumb_chunks = array();
        foreach ($cats as $c) {
            $link = get_term_link($c);
            if (!is_wp_error($link)) {
                $crumb_chunks[] = '<a href="' . esc_url($link) . '">' . esc_html($c->name) . '</a>';
            }
        }
        if ($crumb_chunks) {
            echo implode('<span class="sep">›</span>', esc_html($crumb_chunks));
            echo '<span class="sep">›</span>';
        }
        ?>
    <?php endif; ?>
    <span class="is-current"><?php echo esc_html(get_the_title($product_id)); ?></span>
</nav>
<?php endif; ?>

<div class="mlshop-single mlshop-single-block" itemscope itemtype="https://schema.org/Product">
    <?php if ($show_gallery) : ?>
        <div class="mlshop-single-gallery">
            <div class="mlshop-gallery" data-mlshop-gallery data-count="<?php echo (int) $gallery_count; ?>" data-index="0">
                <div class="mlshop-gallery-main">
                    <?php if ($main_img) : ?>
                        <img src="<?php echo esc_url($main_img); ?>" alt="<?php echo esc_attr(get_the_title($product_id)); ?>" class="mlshop-gallery-main-img" data-zoom="<?php echo esc_url($main_img_full); ?>">
                    <?php else : ?>
                        <div class="mlshop-no-thumb mlshop-no-thumb-lg"></div>
                    <?php endif; ?>
                    <?php if ($gallery_count > 1) : ?>
                        <button type="button" class="mlshop-gallery-arrow mlshop-gallery-prev" aria-label="<?php echo esc_attr__('上一张', 'moonlight-shop'); ?>">‹</button>
                        <button type="button" class="mlshop-gallery-arrow mlshop-gallery-next" aria-label="<?php echo esc_attr__('下一张', 'moonlight-shop'); ?>">›</button>
                        <div class="mlshop-gallery-dots">
                            <?php foreach ($gallery_full as $i => $u) : ?>
                                /* translators: %d: 数量 */
                                <button type="button" class="mlshop-gallery-dot<?php echo $i === 0 ? ' is-active' : ''; ?>" data-index="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr(sprintf(__('第 %d 张', 'moonlight-shop'), $i + 1)); ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($gallery_count > 1) : ?>
                    <div class="mlshop-gallery-thumbs">
                        <?php foreach ($gallery_full as $i => $u) : ?>
                            <button type="button" class="mlshop-gallery-thumb<?php echo $i === 0 ? ' is-active' : ''; ?>" data-index="<?php echo (int) $i; ?>" data-full="<?php echo esc_url($u); ?>">
                                <?php echo wp_get_attachment_image($gallery[$i], 'thumbnail'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($show_summary) : ?>
        <div class="mlshop-single-summary">
            <h1 class="mlshop-product-title" itemprop="name"><?php echo esc_html(get_the_title($product_id)); ?></h1>

            <div class="mlshop-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                <?php echo esc_html(mlshop_format_price($price)); ?>
            </div>

            <?php if (isset($type_label[$type])) : ?>
                <span class="mlshop-type-badge"><?php echo esc_html($type_label[$type]); ?></span>
            <?php endif; ?>

            <?php if (has_excerpt($product_id)) : ?>
                <div class="mlshop-short-desc"><?php echo wp_kses_post(get_the_excerpt($product_id)); ?></div>
            <?php endif; ?>

            <?php if (!empty($tags) && !is_wp_error($tags)) : ?>
                <div class="mlshop-tags">
                    <span class="mlshop-tags-label"><?php esc_html_e('标签：', 'moonlight-shop'); ?></span>
                    <?php foreach ($tags as $t) :
                        $tlink = get_term_link($t);
                        if (is_wp_error($tlink)) { continue; }
                    ?>
                        <a href="<?php echo esc_url($tlink); ?>" class="mlshop-tag"><?php echo esc_html($t->name); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_add_to_cart) : ?>
                <form class="mlshop-buy-form" onsubmit="return false;">
                    <div class="mlshop-qty-wrap">
                        <label><?php esc_html_e('数量', 'moonlight-shop'); ?></label>
                        <input type="number" class="mlshop-qty" name="qty" value="1" min="1" max="<?php echo $stock > 0 ? (int) $stock : ''; ?>">
                    </div>
                    <button type="button" class="mlshop-btn mlshop-add-to-cart mlshop-btn-primary" data-product-id="<?php echo (int) $product_id; ?>">
                        <?php esc_html_e('加入购物车', 'moonlight-shop'); ?>
                    </button>
                    <?php if ($show_favorite && function_exists('mlshop_favorite_button')) { mlshop_favorite_button($product_id); } ?>
                </form>
            <?php elseif ($show_favorite && function_exists('mlshop_favorite_button')) : ?>
                <?php mlshop_favorite_button($product_id); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($show_tabs) : ?>
    <div class="mlshop-tabs">
        <ul class="mlshop-tab-nav">
            <li class="is-active" data-tab="desc"><?php esc_html_e('商品描述', 'moonlight-shop'); ?></li>
            <li data-tab="info"><?php esc_html_e('更多信息', 'moonlight-shop'); ?></li>
            <?php if ($show_reviews) : ?>
                <li data-tab="reviews"><?php esc_html_e('评价', 'moonlight-shop'); ?></li>
            <?php endif; ?>
        </ul>
        <div class="mlshop-tab-panel is-active" id="tab-desc">
            <div class="mlshop-product-content"><?php echo wp_kses_post(get_post_field('post_content', $product_id)); ?></div>
        </div>
        <div class="mlshop-tab-panel" id="tab-info">
            <table class="mlshop-info-table">
                <tr><th><?php esc_html_e('价格', 'moonlight-shop'); ?></th><td><?php echo esc_html(mlshop_format_price($price)); ?></td></tr>
                <tr><th><?php esc_html_e('类型', 'moonlight-shop'); ?></th><td><?php echo isset($type_label[$type]) ? esc_html($type_label[$type]) : esc_html($type); ?></td></tr>
                <?php if ($sku) : ?><tr><th><?php esc_html_e('货号', 'moonlight-shop'); ?></th><td><?php echo esc_html($sku); ?></td></tr><?php endif; ?>
                <tr><th><?php esc_html_e('库存', 'moonlight-shop'); ?></th><td><?php echo $stock < 0 ? esc_html__('充足', 'moonlight-shop') : (int) $stock; ?></td></tr>
                <tr><th><?php esc_html_e('分类', 'moonlight-shop'); ?></th><td><?php echo !empty($cats) && !is_wp_error($cats) ? esc_html(join('、', wp_list_pluck($cats, 'name'))) : esc_html__('未分类', 'moonlight-shop'); ?></td></tr>
                <?php if (!empty($tags) && !is_wp_error($tags)) : ?>
                    <tr><th><?php esc_html_e('标签', 'moonlight-shop'); ?></th><td><?php echo esc_html(join('、', wp_list_pluck($tags, 'name'))); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
        <?php if ($show_reviews) : ?>
        <div class="mlshop-tab-panel" id="tab-reviews">
            <?php
            global $post;
            $post = get_post($product_id);
            setup_postdata($post);
            comments_template();
            wp_reset_postdata();
            ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
