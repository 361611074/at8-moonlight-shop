<?php
/**
 * 商品单页模板：主图+相册 / 摘要 / Tab / 相关商品 / 侧栏。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}
get_header();
?>

<div class="mlshop-single-wrap">
    <?php
    $show_breadcrumbs  = (int) mlshop_get_option('show_breadcrumbs', 1);
    $show_product_meta = (int) mlshop_get_option('show_product_meta', 0);
    $show_reviews      = (int) mlshop_get_option('reviews_enabled', 1);
    $archive_link      = get_post_type_archive_link('mlshop_product');
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
                    echo implode('<span class="sep">›</span>', $crumb_chunks);
                    echo '<span class="sep">›</span>';
                }
                ?>
            <?php endif; ?>
            <span class="is-current"><?php the_title(); ?></span>
        </nav>
    <?php endif; ?>

    <div class="mlshop-single-main">
        <?php while (have_posts()) : the_post(); ?>
            <?php
            $product_id = get_the_ID();
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
            $main_img      = !empty($gallery_full) ? $gallery_full[0] : (has_post_thumbnail() ? get_the_post_thumbnail_url($product_id, 'large') : '');
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
            ?>

            <div class="mlshop-single" itemscope itemtype="https://schema.org/Product">
                <!-- 左：主图 + 相册（幻灯片） -->
                <div class="mlshop-single-gallery">
                    <div class="mlshop-gallery" data-mlshop-gallery data-count="<?php echo (int) $gallery_count; ?>" data-index="0">
                        <div class="mlshop-gallery-main">
                            <?php if ($main_img) : ?>
                                <img src="<?php echo esc_url($main_img); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" class="mlshop-gallery-main-img" data-zoom="<?php echo esc_url($main_img_full); ?>">
                            <?php else : ?>
                                <div class="mlshop-no-thumb mlshop-no-thumb-lg"></div>
                            <?php endif; ?>
                            <?php if ($gallery_count > 1) : ?>
                                <button type="button" class="mlshop-gallery-arrow mlshop-gallery-prev" aria-label="<?php echo esc_attr__('上一张', 'moonlight-shop'); ?>">‹</button>
                                <button type="button" class="mlshop-gallery-arrow mlshop-gallery-next" aria-label="<?php echo esc_attr__('下一张', 'moonlight-shop'); ?>">›</button>
                                <div class="mlshop-gallery-dots">
                                    <?php foreach ($gallery_full as $i => $u) : ?>
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

                <!-- 右：摘要 -->
                <div class="mlshop-single-summary">
                    <h1 class="mlshop-product-title" itemprop="name"><?php the_title(); ?></h1>

                    <div class="mlshop-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                        <?php echo esc_html(mlshop_format_price($price)); ?>
                    </div>

                    <?php
                    // 可变商品（授权套餐选择）：选中变体联动显示价与加购参数
                    $at8lic_variants = get_post_meta($product_id, '_at8lic_variants', true);
                    if (is_array($at8lic_variants) && $at8lic_variants && class_exists('MLSHOP_License_Bridge')) :
                    ?>
                        <div class="mlshop-variants" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 4px">
                            <?php $vi = 0; foreach ($at8lic_variants as $v) :
                                $vplan  = isset($v['plan']) ? sanitize_key($v['plan']) : '';
                                if ($vplan === '') { continue; }
                                $vprice = (float) MLSHOP_License_Bridge::variant_price($product_id, $vplan);
                                $vlabel = isset($v['label']) && $v['label'] !== '' ? $v['label'] : MLSHOP_License_Bridge::variant_label($vplan);
                                $vbadge = isset($v['badge']) ? (string) $v['badge'] : '';
                            ?>
                            <label class="mlshop-variant<?php echo 0 === $vi ? ' is-active' : ''; ?>"
                                   style="display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #dcdcde;border-radius:8px;cursor:pointer;background:<?php echo 0 === $vi ? '#f0f6fc' : '#fff'; ?>">
                                <input type="radio" name="mlshop_variant" value="<?php echo esc_attr($vplan); ?>"
                                       data-price-text="<?php echo esc_attr(mlshop_format_price($vprice)); ?>"
                                       data-badge="<?php echo esc_attr($vbadge); ?>" <?php checked(0, $vi); ?> style="accent-color:#2271b1">
                                <span><strong><?php echo esc_html($vlabel); ?></strong>
                                <b style="margin-left:6px"><?php echo esc_html(mlshop_format_price($vprice)); ?></b>
                                <?php if ($vbadge !== '') : ?><em style="font-style:normal;color:#d63384;margin-left:6px"><?php echo esc_html($vbadge); ?></em><?php endif; ?>
                                </span>
                            </label>
                            <?php $vi++; endforeach; ?>
                        </div>
                        <script>
                        (function () {
                            var box = document.querySelector('.mlshop-variants');
                            if (!box) { return; }
                            var priceEl = document.querySelector('.mlshop-price');
                            var badgeEl = document.querySelector('.mlshop-variant-badge-dyn');
                            box.addEventListener('change', function (e) {
                                var input = e.target;
                                if (!input || input.name !== 'mlshop_variant') { return; }
                                box.querySelectorAll('.mlshop-variant').forEach(function (l) {
                                    l.classList.toggle('is-active', l.querySelector('input').checked);
                                    l.style.background = l.querySelector('input').checked ? '#f0f6fc' : '#fff';
                                });
                                if (priceEl && input.dataset.priceText) { priceEl.textContent = input.dataset.priceText; }
                            });
                        })();
                        </script>
                    <?php endif; ?>

                    <?php if (isset($type_label[$type])) : ?>
                        <span class="mlshop-type-badge"><?php echo esc_html($type_label[$type]); ?></span>
                    <?php endif; ?>

                    <?php if (has_excerpt()) : ?>
                        <div class="mlshop-short-desc"><?php the_excerpt(); ?></div>
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

                    <form class="mlshop-buy-form" onsubmit="return false;">
                        <div class="mlshop-qty-wrap">
                            <label><?php esc_html_e('数量', 'moonlight-shop'); ?></label>
                            <div class="mlshop-qty-stepper">
                                <button type="button" class="mlshop-qty-btn mlshop-qty-minus" aria-label="<?php echo esc_attr__('减少数量', 'moonlight-shop'); ?>">−</button>
                                <input type="number" class="mlshop-qty" name="qty" value="1" min="1" max="<?php echo $stock > 0 ? (int) $stock : ''; ?>">
                                <button type="button" class="mlshop-qty-btn mlshop-qty-plus" aria-label="<?php echo esc_attr__('增加数量', 'moonlight-shop'); ?>">+</button>
                            </div>
                        </div>
                        <button type="button" class="mlshop-btn mlshop-add-to-cart mlshop-btn-primary" data-product-id="<?php the_ID(); ?>">
                            <?php esc_html_e('加入购物车', 'moonlight-shop'); ?>
                        </button>
                        <?php mlshop_favorite_button($product_id); ?>
                    </form>

                    <?php if ($show_product_meta) : ?>
                    <div class="mlshop-product-meta">
                        <?php if ($sku) : ?>
                            <span class="mlshop-meta-row"><span class="mlshop-meta-key"><?php esc_html_e('货号', 'moonlight-shop'); ?></span>：<span class="mlshop-meta-val"><?php echo esc_html($sku); ?></span></span>
                        <?php endif; ?>
                        <span class="mlshop-meta-row"><span class="mlshop-meta-key"><?php esc_html_e('分类', 'moonlight-shop'); ?></span>：
                            <span class="mlshop-meta-val">
                                <?php if (!empty($cats) && !is_wp_error($cats)) : ?>
                                    <?php echo esc_html(join('、', wp_list_pluck($cats, 'name'))); ?>
                                <?php else : ?>
                                    <?php esc_html_e('未分类', 'moonlight-shop'); ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="mlshop-meta-row"><span class="mlshop-meta-key"><?php esc_html_e('库存', 'moonlight-shop'); ?></span>：
                            <span class="mlshop-meta-val"><?php echo $stock < 0 ? esc_html__('充足', 'moonlight-shop') : (int) $stock . ' ' . esc_html__('件', 'moonlight-shop'); ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab：描述 / 更多信息 / 评价 -->
            <div class="mlshop-tabs">
                <ul class="mlshop-tab-nav">
                    <li class="is-active" data-tab="desc"><?php esc_html_e('商品描述', 'moonlight-shop'); ?></li>
                    <li data-tab="info"><?php esc_html_e('更多信息', 'moonlight-shop'); ?></li>
                    <?php if ($show_reviews) : ?>
                        <li data-tab="reviews"><?php esc_html_e('评价', 'moonlight-shop'); ?></li>
                    <?php endif; ?>
                </ul>
                <div class="mlshop-tab-panel is-active" id="tab-desc">
                    <div class="mlshop-product-content"><?php the_content(); ?></div>
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
                    <?php comments_template(); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 相关商品 -->
            <?php
            $related_args = array(
                'post_type'      => 'mlshop_product',
                'posts_per_page' => 4,
                'post_status'    => 'publish',
                'post__not_in'   => array($product_id),
                'orderby'        => 'rand',
            );
            if (!empty($cats) && !is_wp_error($cats)) {
                $related_args['tax_query'] = array(array('taxonomy' => 'mlshop_product_cat', 'field' => 'term_id', 'terms' => wp_list_pluck($cats, 'term_id')));
            }
            $related = new WP_Query($related_args);
            if ($related->have_posts()) :
            ?>
                <div class="mlshop-related">
                    <h2 class="mlshop-related-title"><?php esc_html_e('相关商品', 'moonlight-shop'); ?></h2>
                    <div class="mlshop-products mlshop-related-grid">
                        <?php while ($related->have_posts()) : $related->the_post(); ?>
                            <?php mlshop_get_template('product-item', array('product_id' => get_the_ID())); ?>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; wp_reset_postdata(); ?>

        <?php endwhile; ?>
    </div>

    <!-- 侧栏 -->
    <aside class="mlshop-sidebar">
        <?php dynamic_sidebar('mlshop-shop'); ?>
    </aside>
</div>

<?php
get_footer();
