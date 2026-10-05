<?php
/**
 * 商品归档 / 分类页模板：网格 + 排序工具条 + 分页 + 侧栏。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}
get_header();

$term   = (is_tax('mlshop_product_cat') || is_tax('mlshop_product_tag')) ? get_queried_object() : null;
$search = get_search_query();
?>
<div class="mlshop-archive-wrap">
    <div class="mlshop-archive-main">
        <header class="mlshop-archive-header">
            <h1 class="mlshop-archive-title">
                <?php if ($search) : ?>
                    /* translators: %s: 值 */
                    <?php printf(esc_html__('商品搜索：%s', 'moonlight-shop'), esc_html($search)); ?>
                <?php elseif ($term) : ?>
                    <?php echo esc_html($term->name); ?>
                <?php else : ?>
                    <?php esc_html_e('全部商品', 'moonlight-shop'); ?>
                <?php endif; ?>
            </h1>
            <?php if ($term && !empty($term->description)) : ?>
                <div class="mlshop-archive-desc"><?php echo wp_kses_post(term_description($term)); ?></div>
            <?php endif; ?>
        </header>

        <?php if (have_posts()) : ?>
            <div class="mlshop-toolbar">
                <span class="mlshop-result-count">
                    <?php
                    global $wp_query;
                    $total = $wp_query->found_posts;
                    /* translators: %d: 数量 */
                    printf(esc_html__('共 %d 件商品', 'moonlight-shop'), (int) $total);
                    ?>
                </span>
                <form class="mlshop-orderby-form" method="get" action="<?php echo esc_url($term ? get_term_link($term) : get_post_type_archive_link('mlshop_product')); ?>">
                    <?php if ($search) : ?><input type="hidden" name="s" value="<?php echo esc_attr($search); ?>"><?php endif; ?>
                    <?php if (isset($_GET['min_price'])) : ?><input type="hidden" name="min_price" value="<?php echo esc_attr($_GET['min_price']); ?>"><?php endif; ?>
                    <?php if (isset($_GET['max_price'])) : ?><input type="hidden" name="max_price" value="<?php echo esc_attr($_GET['max_price']); ?>"><?php endif; ?>
                    <label><?php esc_html_e('排序：', 'moonlight-shop'); ?>
                        <select name="orderby" onchange="this.form.submit()" class="mlshop-orderby">
                            <option value="date" <?php selected(isset($_GET['orderby']) ? $_GET['orderby'] : '', 'date'); ?>><?php esc_html_e('最新', 'moonlight-shop'); ?></option>
                            <option value="price_asc" <?php selected(isset($_GET['orderby']) ? $_GET['orderby'] : '', 'price_asc'); ?>><?php esc_html_e('价格从低到高', 'moonlight-shop'); ?></option>
                            <option value="price_desc" <?php selected(isset($_GET['orderby']) ? $_GET['orderby'] : '', 'price_desc'); ?>><?php esc_html_e('价格从高到低', 'moonlight-shop'); ?></option>
                            <option value="title" <?php selected(isset($_GET['orderby']) ? $_GET['orderby'] : '', 'title'); ?>><?php esc_html_e('按名称', 'moonlight-shop'); ?></option>
                        </select>
                    </label>
                </form>
            </div>

            <div class="mlshop-products mlshop-archive-grid">
                <?php while (have_posts()) : the_post(); ?>
                    <?php mlshop_get_template('product-item', array('product_id' => get_the_ID())); ?>
                <?php endwhile; ?>
            </div>

            <nav class="mlshop-pagination" aria-label="<?php esc_attr_e('商品分頁', 'moonlight-shop'); ?>">
                <?php
                $current = max(1, get_query_var('paged'));
                $pages   = (int) $wp_query->max_num_pages;
                if ($pages > 1) {
                    echo paginate_links(array(
                        'base'      => add_query_arg('paged', '%#%'),
                        'format'    => '',
                        'total'     => $pages,
                        'current'   => $current,
                        'mid_size'  => 1,
                        'prev_text' => esc_html__('‹ 上一頁', 'moonlight-shop'),
                        'next_text' => esc_html__('下一頁 ›', 'moonlight-shop'),
                        'type'      => 'plain',
                        'add_args'  => array_intersect_key($_GET, array_flip(array('s', 'orderby', 'min_price', 'max_price'))),
                    ));
                }
                ?>
            </nav>
        <?php else : ?>
            <p class="mlshop-empty"><?php esc_html_e('暂无商品。', 'moonlight-shop'); ?></p>
        <?php endif; ?>
    </div>

    <aside class="mlshop-sidebar">
        <?php dynamic_sidebar('mlshop-shop'); ?>
    </aside>
</div>

<?php
get_footer();
