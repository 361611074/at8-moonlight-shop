<?php
/**
 * 商品自定义文章类型与元数据包。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Product
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
        // priority 20：等其他内容类型注册完毕，才能准确检测链接前缀是否被占用
        add_action('init', array($this, 'register_post_type'), 20);
        add_action('wp_loaded', array($this, 'maybe_flush_rewrite'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post_mlshop_product', array($this, 'save_meta'));
        add_shortcode('mlshop_products', array($this, 'shortcode_products'));
        add_filter('template_include', array($this, 'template_include'));
        add_action('pre_get_posts', array($this, 'archive_query'));

        // 后台列表：列定义 + Quick Edit 字段
        add_filter('manage_mlshop_product_posts_columns', array($this, 'add_columns'));
        add_action('manage_mlshop_product_posts_custom_column', array($this, 'render_columns'), 10, 2);
        add_action('quick_edit_custom_box', array($this, 'quick_edit_box'), 10, 2);
        add_action('save_post', array($this, 'sync_inline_edit_columns'), 20, 2);
        // Quick Edit AJAX 字段桥接：WP inline-edit-post.js 显式枚举字段，不会自动发 mlshop_*
        add_action('admin_print_footer_scripts-edit.php', array($this, 'print_quick_edit_inline_js'));

        // 后台「同步 WooCommerce 商品标签」工具
        add_action('admin_post_mlshop_sync_woo_tags', array($this, 'sync_woo_tags'));

        // 媒体库增加「ID」列：方便后台填「虚拟商品下载文件」时直接复制附件 ID。
        add_filter('manage_media_columns', array($this, 'add_media_id_column'));
        add_action('manage_media_custom_column', array($this, 'render_media_id_column'), 10, 2);
        add_action('admin_print_footer_scripts-upload.php', array($this, 'print_media_id_inline_js'));
    }

    /**
     * 链接前缀发生变化时刷新重写规则。
     *
     * 前缀受站内其他内容类型与后台设置影响，可能在运行期改变；
     * 不刷新会让商品单页/归档/分类页返回 404。此处仅在变化时执行一次。
     */
    public function maybe_flush_rewrite()
    {
        $current = implode('|', array(
            self::get_url_slug('single'),
            self::get_url_slug('archive'),
            self::get_url_slug('category'),
            self::get_url_slug('tag'),
        ));
        if (get_option('mlshop_url_slugs') !== $current) {
            update_option('mlshop_url_slugs', $current);
            flush_rewrite_rules();
        }
    }

    /**
     * 用插件自带模板接管商品单页与归档/分类页。
     */
    public function template_include($template)
    {
        if (is_singular('mlshop_product')) {
            // 商品若已用 Elementor 构建，则让出模板交由 Elementor 渲染
            $id = get_the_ID();
            if ($id && class_exists('Elementor\\Plugin')) {
                $doc = \Elementor\Plugin::instance()->documents->get($id);
                if ($doc && method_exists($doc, 'is_built_with_elementor') && $doc->is_built_with_elementor()) {
                    return $template;
                }
            }
            $t = MLSHOP_PLUGIN_DIR . 'templates/single-product.php';
            if (file_exists($t)) {
                return $t;
            }
        }
        if (is_post_type_archive('mlshop_product') || is_tax('mlshop_product_cat') || is_tax('mlshop_product_tag')) {
            $t = MLSHOP_PLUGIN_DIR . 'templates/archive-product.php';
            if (file_exists($t)) {
                return $t;
            }
        }
        return $template;
    }

    /**
     * 归档/分类页支持价格区间与排序筛选（来自侧栏小工具）。
     */
    public function archive_query($query)
    {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }
        if (!($query->is_post_type_archive('mlshop_product') || $query->is_tax('mlshop_product_cat') || $query->is_tax('mlshop_product_tag'))) {
            return;
        }
        $min = isset($_GET['min_price']) ? (float) $_GET['min_price'] : 0;
        $max = isset($_GET['max_price']) ? (float) $_GET['max_price'] : 0;
        // 后台设置的每页条数（无 ?per_page= 显式覆盖时生效）
        if (!$query->get('posts_per_page') && (function_exists('mlshop_get_option') ? (int) mlshop_get_option('archive_per_page', 12) : 12) > 0) {
            $query->set('posts_per_page', (int) mlshop_get_option('archive_per_page', 12));
        }
        if ($min > 0 || $max > 0) {
            $meta = array('key' => '_mlshop_price', 'type' => 'NUMERIC');
            if ($min > 0 && $max > 0) {
                $meta['value'] = array($min, $max);
                $meta['compare'] = 'BETWEEN';
            } elseif ($max > 0) {
                $meta['value'] = $max;
                $meta['compare'] = '<=';
            } else {
                $meta['value'] = $min;
                $meta['compare'] = '>=';
            }
            $query->set('meta_query', array($meta));
        }
        if (isset($_GET['orderby'])) {
            switch ($_GET['orderby']) {
                case 'price_asc':
                    $query->set('meta_key', '_mlshop_price');
                    $query->set('orderby', 'meta_value_num');
                    $query->set('order', 'ASC');
                    break;
                case 'price_desc':
                    $query->set('meta_key', '_mlshop_price');
                    $query->set('orderby', 'meta_value_num');
                    $query->set('order', 'DESC');
                    break;
                case 'title':
                    $query->set('orderby', 'title');
                    $query->set('order', 'ASC');
                    break;
                case 'date':
                    $query->set('orderby', 'date');
                    $query->set('order', 'DESC');
                    break;
            }
        }
    }

    /**
     * 商品链接前缀。
     *
     * 默认 product / products / product-category。若站内已有其他内容类型
     * 或分类法占用了同名前缀，重写规则会互相覆盖并导致页面 404，
     * 因此这里检测注册表后自动改用备用前缀。后台设置可显式覆盖。
     *
     * @param string $which single|archive|category
     * @return string
     */
    public static function get_url_slug($which)
    {
        $map = array(
            'single'   => array('opt' => 'slug_single',   'default' => 'product',          'fallback' => 'shop-item'),
            'archive'  => array('opt' => 'slug_archive',  'default' => 'products',         'fallback' => 'store'),
            'category' => array('opt' => 'slug_category', 'default' => 'product-category', 'fallback' => 'shop-category'),
            'tag'      => array('opt' => 'slug_tag',      'default' => 'product-tag',      'fallback' => 'shop-tag'),
        );
        if (!isset($map[$which])) {
            return '';
        }
        $conf = $map[$which];

        // 1. 后台显式设置优先
        $custom = function_exists('mlshop_get_option') ? mlshop_get_option($conf['opt'], '') : '';
        if (!empty($custom)) {
            return sanitize_title($custom);
        }

        // 2. 检测默认前缀是否已被其他内容类型/分类法占用
        //    注册表里的 slug 可能带前导斜杠（如 /product），比较前先归一化
        $taken = false;
        if (in_array($which, array('category', 'tag'), true)) {
            foreach (get_taxonomies(array(), 'objects') as $tax) {
                if (in_array($tax->name, array('mlshop_product_cat', 'mlshop_product_tag'), true)
                    || !is_array($tax->rewrite)
                    || empty($tax->rewrite['slug'])
                ) {
                    continue;
                }
                if (trim($tax->rewrite['slug'], '/') === $conf['default']) {
                    $taken = true;
                    break;
                }
            }
        } else {
            foreach (get_post_types(array(), 'objects') as $pt) {
                if ('mlshop_product' === $pt->name) {
                    continue;
                }
                $pt_slug = (is_array($pt->rewrite) && !empty($pt->rewrite['slug'])) ? trim($pt->rewrite['slug'], '/') : '';
                $pt_arch = is_string($pt->has_archive) ? trim($pt->has_archive, '/') : '';
                if ($conf['default'] === $pt_slug || $conf['default'] === $pt_arch) {
                    $taken = true;
                    break;
                }
            }
            // 同名顶层页面也会抢走该路径
            if (!$taken && get_page_by_path($conf['default'])) {
                $taken = true;
            }
        }

        return $taken ? $conf['fallback'] : $conf['default'];
    }

    public static function register_post_type()
    {
        register_post_type('mlshop_product', array(
            'labels'      => array(
                'name'                  => __('商品', 'moonlight-shop'),
                'singular_name'         => __('商品', 'moonlight-shop'),
                'menu_name'             => __('商品', 'moonlight-shop'),
                'name_admin_bar'        => __('商品', 'moonlight-shop'),
                'add_new'               => __('添加商品', 'moonlight-shop'),
                'add_new_item'          => __('添加商品', 'moonlight-shop'),
                'new_item'              => __('新商品', 'moonlight-shop'),
                'edit_item'             => __('编辑商品', 'moonlight-shop'),
                'view_item'             => __('查看商品', 'moonlight-shop'),
                'view_items'            => __('查看商品', 'moonlight-shop'),
                'all_items'             => __('全部商品', 'moonlight-shop'),
                'search_items'          => __('搜索商品', 'moonlight-shop'),
                'not_found'             => __('暂无商品', 'moonlight-shop'),
                'not_found_in_trash'    => __('回收站中暂无商品', 'moonlight-shop'),
                'featured_image'        => __('商品主图', 'moonlight-shop'),
                'set_featured_image'    => __('设置商品主图', 'moonlight-shop'),
                'remove_featured_image' => __('移除商品主图', 'moonlight-shop'),
                'use_featured_image'    => __('设为商品主图', 'moonlight-shop'),
                'archives'              => __('商品归档', 'moonlight-shop'),
                'attributes'            => __('商品属性', 'moonlight-shop'),
                'insert_into_item'      => __('插入到商品', 'moonlight-shop'),
                'uploaded_to_this_item' => __('上传到此商品', 'moonlight-shop'),
                'filter_items_list'     => __('筛选商品', 'moonlight-shop'),
                'items_list_navigation' => __('商品列表导航', 'moonlight-shop'),
                'items_list'            => __('商品列表', 'moonlight-shop'),
                'item_published'        => __('商品已发布。', 'moonlight-shop'),
                'item_published_privately' => __('商品已私密发布。', 'moonlight-shop'),
                'item_reverted_to_draft' => __('商品已恢复为草稿。', 'moonlight-shop'),
                'item_trashed'          => __('商品已删除。', 'moonlight-shop'),
                'item_scheduled'        => __('商品已排入发布计划。', 'moonlight-shop'),
                'item_updated'          => __('商品已更新。', 'moonlight-shop'),
                'item_link'             => __('商品链接', 'moonlight-shop'),
                'item_link_description' => __('目标商品的链接。', 'moonlight-shop'),
                'template_name'         => __('单个商品', 'moonlight-shop'),
            ),
            'public'      => true,
            'has_archive' => self::get_url_slug('archive'),
            'rewrite'     => array('slug' => self::get_url_slug('single')),
            'supports'    => array('title', 'editor', 'thumbnail', 'excerpt'),
            'menu_icon'   => 'dashicons-cart',
            'taxonomies'  => array('mlshop_product_cat', 'mlshop_product_tag'),
        ));

        // 商品分类（层级式）
        if (!taxonomy_exists('mlshop_product_cat')) {
            register_taxonomy('mlshop_product_cat', array('mlshop_product'), array(
                'labels'            => array(
                    'name'                       => __('商品分类', 'moonlight-shop'),
                    'singular_name'              => __('商品分类', 'moonlight-shop'),
                    'menu_name'                  => __('商品分类', 'moonlight-shop'),
                    'all_items'                  => __('全部商品分类', 'moonlight-shop'),
                    'edit_item'                  => __('编辑商品分类', 'moonlight-shop'),
                    'view_item'                  => __('查看商品分类', 'moonlight-shop'),
                    'update_item'                => __('更新商品分类', 'moonlight-shop'),
                    'add_new_item'               => __('新建商品分类', 'moonlight-shop'),
                    'new_item_name'              => __('新商品分类名称', 'moonlight-shop'),
                    'parent_item'                => __('父级商品分类', 'moonlight-shop'),
                    'parent_item_colon'          => __('父级商品分类：', 'moonlight-shop'),
                    'search_items'               => __('搜索商品分类', 'moonlight-shop'),
                    'popular_items'              => __('热门商品分类', 'moonlight-shop'),
                    'separate_items_with_commas' => __('用英文逗号分隔商品分类', 'moonlight-shop'),
                    'add_or_remove_items'        => __('添加或移除商品分类', 'moonlight-shop'),
                    'choose_from_most_used'      => __('从常用商品分类中选择', 'moonlight-shop'),
                    'not_found'                  => __('暂无商品分类', 'moonlight-shop'),
                    'back_to_items'              => __('返回商品分类', 'moonlight-shop'),
                ),
                'hierarchical'      => true,
                'public'            => true,
                'show_admin_column' => true,
                'rewrite'           => array('slug' => self::get_url_slug('category')),
            ));
        }

        // 商品标签（非层级式，类似文章标签）
        if (!taxonomy_exists('mlshop_product_tag')) {
            register_taxonomy('mlshop_product_tag', array('mlshop_product'), array(
                'labels'            => array(
                    'name'                       => __('商品标签', 'moonlight-shop'),
                    'singular_name'              => __('商品标签', 'moonlight-shop'),
                    'menu_name'                  => __('商品标签', 'moonlight-shop'),
                    'all_items'                  => __('全部商品标签', 'moonlight-shop'),
                    'edit_item'                  => __('编辑商品标签', 'moonlight-shop'),
                    'view_item'                  => __('查看商品标签', 'moonlight-shop'),
                    'update_item'                => __('更新商品标签', 'moonlight-shop'),
                    'add_new_item'               => __('新建商品标签', 'moonlight-shop'),
                    'new_item_name'              => __('新商品标签名称', 'moonlight-shop'),
                    'search_items'               => __('搜索商品标签', 'moonlight-shop'),
                    'popular_items'              => __('热门商品标签', 'moonlight-shop'),
                    'separate_items_with_commas' => __('用英文逗号分隔商品标签', 'moonlight-shop'),
                    'add_or_remove_items'        => __('添加或移除商品标签', 'moonlight-shop'),
                    'choose_from_most_used'      => __('从常用商品标签中选择', 'moonlight-shop'),
                    'not_found'                  => __('暂无商品标签', 'moonlight-shop'),
                    'back_to_items'              => __('返回商品标签', 'moonlight-shop'),
                ),
                'hierarchical'      => false,
                'public'            => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => array('slug' => self::get_url_slug('tag')),
            ));
        }
    }

    public function add_meta_box()
    {
        add_meta_box(
            'mlshop_product_meta',
            __('商品设置', 'moonlight-shop'),
            array($this, 'render_meta_box'),
            'mlshop_product',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('mlshop_product_meta', 'mlshop_product_meta_nonce');
        $price = get_post_meta($post->ID, '_mlshop_price', true);
        $type  = get_post_meta($post->ID, '_mlshop_type', true);
        // 默认空字符串（不预先设为 physical，让 admin 显式选择）；
        // 已存在的旧商品不会因为升级而回落到默认值——读到的就是写入时的值。
        $sku   = get_post_meta($post->ID, '_mlshop_sku', true);
        $stock = get_post_meta($post->ID, '_mlshop_stock', true);
        $stock = $stock === '' ? -1 : (int) $stock;
        $file  = get_post_meta($post->ID, '_mlshop_file', true);
        $limit = get_post_meta($post->ID, '_mlshop_download_limit', true);
        $limit = $limit === '' ? 7 : (int) $limit;
        $grant_level = get_post_meta($post->ID, '_mlshop_membership_level', true);
        $gallery     = get_post_meta($post->ID, '_mlshop_gallery', true);
        ?>
        <p>
            <label><?php esc_html_e('价格', 'moonlight-shop'); ?><br>
                <input type="number" step="0.01" min="0" name="mlshop_price" value="<?php echo esc_attr($price); ?>" class="widefat">
            </label>
        </p>
        <p>
            <label><?php esc_html_e('商品类型', 'moonlight-shop'); ?><br>
                <select name="mlshop_type" class="widefat">
                    <option value="" <?php selected($type, ''); ?>><?php esc_html_e('不选择（默认）', 'moonlight-shop'); ?></option>
                    <option value="physical" <?php selected($type, 'physical'); ?>><?php esc_html_e('实物商品', 'moonlight-shop'); ?></option>
                    <option value="virtual" <?php selected($type, 'virtual'); ?>><?php esc_html_e('虚拟下载', 'moonlight-shop'); ?></option>
                    <option value="cardkey" <?php selected($type, 'cardkey'); ?>><?php esc_html_e('卡密商品', 'moonlight-shop'); ?></option>
                </select>
                <span class="description"><?php esc_html_e('默认不选择。留空时按「实物」参与运费计算（保守兜底，避免漏算运费）；如商品不需要发货，请显式选择「虚拟下载」或「卡密商品」。', 'moonlight-shop'); ?></span>
            </label>
        </p>
        <?php
        /**
         * 「購買後授予會員等級」改為从用户中心插件（MLUC_Membership::get_levels()）动态读取。
         * 用户中心的会员等级是真相源；商品后台只是「下拉选项」，避免硬编码与用户中心不同步。
         * MLUC 未启用时回退内置 5 级（free/monthly/gold/premium/diamond），但「不授予」恒为第一项。
         */
        $levels = array();
        if (class_exists('MLUC_Membership') && method_exists('MLUC_Membership', 'get_levels')) {
            $levels = (array) call_user_func(array('MLUC_Membership', 'get_levels'));
        }
        if (empty($levels)) {
            $levels = array(
                'monthly' => array('label' => __('月費會員', 'moonlight-shop'), 'validity' => 30),
                'premium' => array('label' => __('高級會員', 'moonlight-shop'), 'validity' => 0),
            );
        }
        // 排除基座等级 free（free 不能由商品授予——免费注册即应得）
        unset($levels['free']);
        ?>
        <p>
            <label><?php esc_html_e('購買後授予會員等級', 'moonlight-shop'); ?><br>
                <select name="mlshop_membership_level" class="widefat">
                    <option value="" <?php selected($grant_level, ''); ?>><?php esc_html_e('不授予', 'moonlight-shop'); ?></option>
                    <?php
                    foreach ($levels as $lv_key => $lv_data) :
                        $lv_label = is_array($lv_data) ? (isset($lv_data['label']) ? $lv_data['label'] : $lv_key) : $lv_data;
                        ?>
                        <option value="<?php echo esc_attr($lv_key); ?>" <?php selected($grant_level, $lv_key); ?>><?php echo esc_html($lv_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php esc_html_e('選項與「用戶中心」設置頁的會員等級同步；用戶購買並付款此商品即自動升級對應等級（含天數/永久）。', 'moonlight-shop'); ?></span>
            </label>
        </p>
        <p>
            <label><?php esc_html_e('SKU / 货号', 'moonlight-shop'); ?><br>
                <input type="text" name="mlshop_sku" value="<?php echo esc_attr($sku); ?>" class="widefat">
            </label>
        </p>
        <p>
            <label><?php esc_html_e('库存（-1 表示无限，卡密类型填可用张数）', 'moonlight-shop'); ?><br>
                <input type="number" name="mlshop_stock" value="<?php echo esc_attr($stock); ?>" class="widefat">
            </label>
        </p>
        <p>
            <label><?php esc_html_e('商品相册（可多选，第一张作主图）', 'moonlight-shop'); ?><br>
                <input type="hidden" name="mlshop_gallery" id="mlshop_gallery" value="<?php echo esc_attr(mlshop_gallery_to_csv($gallery)); ?>">
                <button type="button" class="button" id="mlshop_gallery_btn"><?php esc_html_e('选择 / 管理图片', 'moonlight-shop'); ?></button>
                <span class="description"><?php esc_html_e('在媒体库框中选多张；留空则仅用特色图片。点击缩略图可移除。', 'moonlight-shop'); ?></span>
            </label>
            <div id="mlshop_gallery_preview" class="mlshop-gallery-preview">
                <?php
                // 服务端先渲染一次，避免页面加载瞬间相册看起来是空的
                foreach (mlshop_parse_gallery_ids($gallery) as $gid) {
                    $thumb = wp_get_attachment_image_url($gid, 'thumbnail');
                    if (!$thumb) {
                        continue;
                    }
                    printf(
                        '<img src="%1$s" data-id="%2$d" alt="" title="%3$s" />',
                        esc_url($thumb),
                        (int) $gid,
                        esc_attr__('点击移除', 'moonlight-shop')
                    );
                }
                ?>
            </div>
        </p>
        <p>
            <label><?php esc_html_e('虚拟商品下载文件（附件 ID）', 'moonlight-shop'); ?><br>
                <input type="number" name="mlshop_file" value="<?php echo esc_attr($file); ?>" class="widefat">
                <span class="description"><?php esc_html_e('在媒体库上传文件后填入其 ID。', 'moonlight-shop'); ?></span>
            </label>
        </p>
        <p>
            <label><?php esc_html_e('下载有效期（天）', 'moonlight-shop'); ?><br>
                <input type="number" name="mlshop_download_limit" value="<?php echo esc_attr($limit); ?>" class="widefat">
            </label>
        </p>
        <p>
            <label><?php esc_html_e('下载次数上限（0 = 不限）', 'moonlight-shop'); ?><br>
                <input type="number" min="0" name="mlshop_download_count" value="<?php echo esc_attr((int) get_post_meta($post->ID, '_mlshop_download_count', true)); ?>" class="widefat">
                <span class="description"><?php esc_html_e('每个下载链接允许下载的总次数；0 表示不限次数。', 'moonlight-shop'); ?></span>
            </label>
        </p>
        <p>
            <label><?php esc_html_e('新增卡密（每行一条，保存后加密入库存池，明文不落库）', 'moonlight-shop'); ?><br>
                <textarea name="mlshop_cardkeys" rows="6" class="widefat"></textarea>
                <span class="description">
                <?php
                // 卡密库存池统计（加密批次模型）。历史明文池由迁移步骤导入并清空。
                $card_batch_count = 0;
                $card_available   = 0;
                $card_migrated    = get_post_meta($post->ID, '_mlshop_cardkeys_migrated', true);
                if (class_exists('Moonlight_Card_Stock')) {
                    $card_batch_count = count(Moonlight_Card_Stock::batches($post->ID));
                    $card_available   = Moonlight_Card_Stock::available($post->ID);
                }
                if ($card_batch_count > 0 || $card_migrated) {
                    printf(
                        /* translators: %1$d：批次数；%2$d：可售卡密数 */
                        esc_html__('当前库存池：%1$d 个批次，可售 %2$d 条（在「商城 → 卡密库存」管理批次）。历史明文池已迁移并清空（标记 _mlshop_cardkeys_migrated）。', 'moonlight-shop'),
                        (int) $card_batch_count,
                        (int) $card_available
                    );
                } else {
                    esc_html_e('保存后每行卡密加密为一个新批次入库；存量明文卡密池将在升级迁移中自动导入并清空。', 'moonlight-shop');
                }
                ?>
                </span>
            </label>
        </p>
        <?php
    }

    public function save_meta($post_id)
    {
        // 两种合法的提交路径：
        //   1) 完整编辑页：携带 mlshop_product_meta_nonce。
        //   2) Quick Edit / Bulk Edit：携带 _inline_edit nonce，且 action=inline-save。
        // 这两条路径都能进入 save_post，必须同时接受；否则 Quick Edit 改了价格却落不到库。
        $is_full_edit = isset($_POST['mlshop_product_meta_nonce'])
            && wp_verify_nonce($_POST['mlshop_product_meta_nonce'], 'mlshop_product_meta');
        $is_inline_edit = isset($_POST['_inline_edit'])
            && wp_verify_nonce($_POST['_inline_edit'], 'inline-save')
            && isset($_POST['action'])
            && $_POST['action'] === 'inline-save'
            && isset($_POST['post_type'])
            && $_POST['post_type'] === 'mlshop_product';

        if (!$is_full_edit && !$is_inline_edit) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array(
            'mlshop_price'           => 'float',
            'mlshop_type'            => 'text',
            'mlshop_sku'             => 'text',
            'mlshop_stock'           => 'int',
            'mlshop_file'            => 'int',
            'mlshop_download_limit'  => 'int',
            'mlshop_download_count'  => 'int',
            'mlshop_membership_level' => 'key',
            'mlshop_gallery'         => 'gallery',
        );
        foreach ($fields as $field => $type) {
            // Quick Edit 用空白表示「不变」；完整编辑页用 isset 跳过未提交字段，等同。
            if (!isset($_POST[$field])) {
                continue;
            }
            $val = $_POST[$field];
            if ($type === 'float') {
                $val = (float) $val;
            } elseif ($type === 'int') {
                $val = (int) $val;
            } elseif ($type === 'key') {
                $val = sanitize_key($val);
            } elseif ($type === 'gallery') {
                // 逗号分隔的附件 ID，仅保留正整数
                $ids = array_filter(array_map('intval', explode(',', (string) $val)), function ($id) {
                    return $id > 0;
                });
                $val = implode(',', array_unique($ids));
            } else {
                $val = sanitize_textarea_field($val);
            }
            update_post_meta($post_id, '_' . $field, $val);
        }

        // 卡密：textarea 非空 = 追加一个新加密批次（明文不落库，导入后 textarea 保持空白）。
        // 仅完整编辑页（携带 meta nonce）处理；Quick Edit / Bulk Edit 不提交该字段，
        // 即使伪造提交也因缺 nonce 而被跳过，避免内联保存误触发导入。
        if ($is_full_edit && isset($_POST['mlshop_cardkeys']) && class_exists('Moonlight_Card_Stock')) {
            $raw_lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($_POST['mlshop_cardkeys']));
            $clean = array();
            foreach ((array) $raw_lines as $line) {
                $line = trim((string) $line);
                if ('' !== $line) {
                    $clean[] = $line;
                }
            }
            if (!empty($clean)) {
                Moonlight_Card_Stock::import($post_id, $clean, '商品编辑导入 ' . date('Ymd-His'));
            }
        }
    }

    /**
     * 判断指定文章/页面是否标记为「仅会员可看」。
     *
     * 与 class-pay-access 的 filter_content 配合：被标记的文章/页面在正文层
     * 对非会员做门禁（商品由购物车/模板处理购买权限，不走这里）。
     * meta 键 _mlshop_member_only 由后台编辑页保存；未设置视为 false（不锁）。
     *
     * @param int $post_id 文章 ID。
     * @return bool 是否仅会员可看。
     */
    public static function is_member_only($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return false;
        }
        return (bool) get_post_meta($post_id, '_mlshop_member_only', true);
    }

    /**
     * 后台商品列表：在「标题」「分类」「日期」之间插入价格、库存两列。
     *
     * @param array $columns 原列。
     * @return array 新列。
     */
    public function add_columns($columns)
    {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['mlshop_price']  = __('价格', 'moonlight-shop');
                $new['mlshop_stock']  = __('库存', 'moonlight-shop');
            }
        }
        return $new;
    }

    /**
     * 后台列表列内容渲染。price 列做空值回退，stock 列把 -1 显示成「∞」。
     *
     * @param string $column  当前列 key。
     * @param int    $post_id 当前商品 ID。
     */
    public function render_columns($column, $post_id)
    {
        if ($column === 'mlshop_price') {
            $price = get_post_meta($post_id, '_mlshop_price', true);
            if ($price === '' || $price === null) {
                echo '<span aria-hidden="true">—</span>';
                return;
            }
            echo '<span class="mlshop-col-price">' . esc_html(number_format((float) $price, 2, '.', '')) . '</span>';
            return;
        }
        if ($column === 'mlshop_stock') {
            $stock = get_post_meta($post_id, '_mlshop_stock', true);
            if ($stock === '' || $stock === null) {
                echo '<span aria-hidden="true">—</span>';
                return;
            }
            $stock = (int) $stock;
            echo $stock < 0
                ? '<span class="mlshop-col-stock">∞</span>'
                : '<span class="mlshop-col-stock">' . esc_html((string) $stock) . '</span>';
            return;
        }
    }

    /**
     * Quick Edit 面板字段输出。
     *
     * 只渲染一次（用 mlshop_price 列作为入口触发），含 价格 / SKU / 库存 / 商品类型 4 项。
     * 更复杂的字段（相册 / 卡密 / 会员等级 / 下载文件）不适合 inline 编辑，留在完整编辑页。
     *
     * @param string $column_name 当前触发的列名（与 render_columns 一致）。
     * @param string $post_type   当前 CPT slug。
     */
    public function quick_edit_box($column_name, $post_type)
    {
        if ($post_type !== 'mlshop_product' || $column_name !== 'mlshop_price') {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-left">
            <div class="inline-edit-group">
                <label class="inline-edit-group-label">
                    <span class="title"><?php esc_html_e('商品信息', 'moonlight-shop'); ?></span>
                </label>
                <p class="inline-edit-mlshop-price">
                    <label>
                        <span class="screen-reader-text"><?php esc_html_e('价格', 'moonlight-shop'); ?></span>
                        <input type="number" step="0.01" min="0" name="mlshop_price" placeholder="<?php esc_attr_e('价格', 'moonlight-shop'); ?>" class="inline-edit-mlshop-price-input" autocomplete="off">
                    </label>
                </p>
                <p class="inline-edit-mlshop-sku">
                    <label>
                        <span class="screen-reader-text"><?php esc_html_e('SKU', 'moonlight-shop'); ?></span>
                        <input type="text" name="mlshop_sku" placeholder="<?php esc_attr_e('SKU', 'moonlight-shop'); ?>" autocomplete="off">
                    </label>
                </p>
                <p class="inline-edit-mlshop-stock">
                    <label>
                        <span class="screen-reader-text"><?php esc_html_e('库存（-1 = 无限）', 'moonlight-shop'); ?></span>
                        <input type="number" step="1" name="mlshop_stock" placeholder="<?php esc_attr_e('库存（-1 = 无限）', 'moonlight-shop'); ?>" autocomplete="off">
                    </label>
                </p>
                <p class="inline-edit-mlshop-type">
                    <label>
                        <span class="screen-reader-text"><?php esc_html_e('商品类型', 'moonlight-shop'); ?></span>
                        <select name="mlshop_type">
                            <option value=""><?php esc_html_e('（不变）', 'moonlight-shop'); ?></option>
                            <option value="physical"><?php esc_html_e('实物商品', 'moonlight-shop'); ?></option>
                            <option value="virtual"><?php esc_html_e('虚拟下载', 'moonlight-shop'); ?></option>
                            <option value="cardkey"><?php esc_html_e('卡密商品', 'moonlight-shop'); ?></option>
                        </select>
                    </label>
                </p>
                <p class="description"><?php esc_html_e('空白字段将保持原值不变。', 'moonlight-shop'); ?></p>
            </div>
        </fieldset>
        <?php
    }

    /**
     * save_post 触发后将最新版价格/库存写回 postmeta，便于 inline-edit 列在不重渲染的情况下读到正确值。
     * 当前 save_meta 已处理写入，本方法作为兜底，确保 inline 自定义列被 WP 列表缓存识别。
     *
     * @param int     $post_id 当前文章 ID。
     * @param WP_Post $post    当前文章对象。
     */
    public function sync_inline_edit_columns($post_id, $post)
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        if ($post->post_type !== 'mlshop_product') {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        // save_meta 已写入 _mlshop_price / _mlshop_stock；此处不重复写，避免冲突。
        // 仅作为未来扩展点保留 hook。
    }

    /**
     * 后台媒体库新增「ID」列（紧跟在「文件名」之后）。
     *
     * 作用：商城商品「虚拟商品下载文件（附件 ID）」需要填附件 ID，
     * WP 默认媒体库（upload.php）只显示文件名/作者/日期，管理员必须点进单条媒体页才能看到 ID，
     * 找起来耗时。这里直接加 ID 列，单击即可复制。
     *
     * 列位置：紧跟「文件名」之后（最显眼），其他 WP 默认列保持不变。
     */
    public function add_media_id_column($cols)
    {
        $new = array();
        $inserted = false;
        foreach ($cols as $k => $v) {
            $new[$k] = $v;
            if ('title' === $k) {
                $new['mlshop_media_id'] = __('ID', 'moonlight-shop');
                $inserted = true;
            }
        }
        if (!$inserted) {
            $new['mlshop_media_id'] = __('ID', 'moonlight-shop');
        }
        return $new;
    }

    public function render_media_id_column($col_name, $post_id)
    {
        if ('mlshop_media_id' === $col_name) {
            printf(
                '<span class="mlshop-media-id" data-id="%1$d" title="%2$s" style="cursor:copy;font-family:Consolas,Menlo,monospace;">%1$d</span>',
                (int) $post_id,
                esc_attr__('点击复制', 'moonlight-shop')
            );
        }
    }

    /**
     * 媒体库页脚输出：点击 ID 自动复制到剪贴板。
     *
     * 旧管理员需在商品「虚拟下载文件」里粘贴附件 ID。媒体库默认没有 ID 列，
     * 我们加了 ID 列后单点复制即可，省去打开单条媒体再读 ID 的两步操作。
     */
    public function print_media_id_inline_js()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'upload') {
            return;
        }
        ?>
<script>
(function () {
    if (typeof document === 'undefined') { return; }
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('mlshop-media-id')) {
            return;
        }
        var id = t.getAttribute('data-id');
        if (!id) { return; }
        var done = function () {
            var old = t.textContent;
            t.textContent = '\u5DF2\u590D\u5236 ' + id;
            setTimeout(function () { t.textContent = old; }, 1200);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(id).then(done, done);
        } else {
            var ta = document.createElement('textarea');
            ta.value = id;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (err) { /* fail silent */ }
            document.body.removeChild(ta);
            done();
        }
        e.preventDefault();
    });
})();
</script>
        <?php
    }

    /**
     * 后台商品列表 Quick Edit / Bulk Edit 期间，把 mlshop_* 自定义字段附加到 inline-save AJAX 请求。
     *
     * WP 自带的 inline-edit-post.js 用 `fields = { post_title, post_status, ... }` 显式枚举收集 POST，
     * 不会 .serialize() 整张表单。我的 quick_edit_custom_box 输出的 <input> 即便在 inline-edit row 内，
     * 也不会被自动发出去。ajaxSend 拦截最可靠：识别 action=inline-save 后，在请求离开前补一份 mlshop_* 字段。
     */
    public function print_quick_edit_inline_js()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'mlshop_product' || $screen->base !== 'edit') {
            return;
        }
        ?>
<script>
(function ($) {
    if (!window.jQuery) { return; }
    $(document).ajaxSend(function (ev, xhr, opts) {
        try {
            var data = '';
            if (typeof opts.data === 'string') {
                data = opts.data;
            } else if (opts.data && typeof opts.data === 'object') {
                // jQuery 在某些情况下会把 data 转成对象，分支兼容
                data = $.param(opts.data);
            }
            if (!data || data.indexOf('action=inline-save') === -1) {
                return;
            }
            var m = data.match(/post_ID=(\d+)/);
            if (!m) { return; }
            var id = m[1];
            var $row = $('#inline-edit-' + id);
            if (!$row.length) { return; }
            var seen = {};
            data.split('&').forEach(function (kv) {
                var pair = kv.split('=');
                if (pair[0]) { seen[decodeURIComponent(pair[0])] = true; }
            });
            var extra = [];
            $row.find('input[name^="mlshop_"], select[name^="mlshop_"]').each(function () {
                var n = $(this).attr('name');
                var v = $(this).val();
                if (!n || seen[n]) { return; }
                // skip empty optional fields so server-side "保持原值不变" 生效
                if (v === '' || v === null) { return; }
                extra.push(encodeURIComponent(n) + '=' + encodeURIComponent(v));
            });
            if (extra.length) {
                var newData = data + (data.length ? '&' : '') + extra.join('&');
                if (typeof opts.data === 'string') {
                    opts.data = newData;
                } else if (opts.data && typeof opts.data === 'object') {
                    $.each(extra.join('&').split('&'), function (i, kv) {
                        var pair = kv.split('=');
                        if (pair[0]) { opts.data[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || ''); }
                    });
                }
            }
        } catch (e) { /* noop, fallback to nothing */ }
    });
})(jQuery);
</script>
        <?php
    }

    /**
     * 后台「同步 WooCommerce 商品标签」工具入口（admin_post）。
     *
     * 仅做权限 / nonce 校验与结果通知，实际同步逻辑在 do_sync_woo_tags()。
     */
    public function sync_woo_tags()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlshop_sync_woo_tags')) {
            wp_die(esc_html__('权限不足。', 'moonlight-shop'));
        }
        $result = $this->do_sync_woo_tags();
        $this->sync_notice($result['success'], $result['message']);
    }

    /**
     * 将站内 WooCommerce 商品的标签（product_tag）整批汇入月光「商品标签」
     * （mlshop_product_tag），并按 SKU（优先）/ 标题把对应标签打到月光商品上。
     * 幂等：重复执行不会重复建 term，也不会重复打标。
     *
     * 返回数组供后台通知与 CLI 校验复用。
     *
     * @return array {success:bool, message:string, terms_imported:int, products_tagged:int}
     */
    public function do_sync_woo_tags()
    {
        if (!taxonomy_exists('product_tag')) {
            return array(
                'success'        => false,
                'message'        => __('未检测到 WooCommerce 商品标签（product_tag），无可同步数据。', 'moonlight-shop'),
                'terms_imported'  => 0,
                'products_tagged' => 0,
            );
        }

        $woo_terms = get_terms(array('taxonomy' => 'product_tag', 'hide_empty' => false));
        if (is_wp_error($woo_terms) || empty($woo_terms)) {
            return array(
                'success'        => true,
                'message'        => __('WooCommerce 没有商品标签，无需同步。', 'moonlight-shop'),
                'terms_imported'  => 0,
                'products_tagged' => 0,
            );
        }

        $terms_imported  = 0;
        $products_tagged = 0;

        foreach ($woo_terms as $woo_term) {
            $existing = get_term_by('slug', $woo_term->slug, 'mlshop_product_tag');
            if ($existing && !is_wp_error($existing)) {
                $ml_term_id = (int) $existing->term_id;
            } else {
                $ins = wp_insert_term($woo_term->name, 'mlshop_product_tag', array(
                    'slug'        => $woo_term->slug,
                    'description' => $woo_term->description,
                ));
                if (is_wp_error($ins)) {
                    continue;
                }
                $ml_term_id = (int) $ins['term_id'];
                $terms_imported++;
            }

            $woo_products = get_posts(array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => array(array(
                    'taxonomy' => 'product_tag',
                    'field'    => 'term_id',
                    'terms'    => $woo_term->term_id,
                )),
            ));

            foreach ($woo_products as $woo_id) {
                $ml_id = 0;
                $sku   = get_post_meta($woo_id, '_sku', true);
                if ($sku) {
                    $hit = get_posts(array(
                        'post_type'      => 'mlshop_product',
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                        'meta_query'     => array(array('key' => '_mlshop_sku', 'value' => $sku)),
                    ));
                    if (!empty($hit)) {
                        $ml_id = (int) $hit[0];
                    }
                }
                if (!$ml_id) {
                    $hit = get_posts(array(
                        'post_type'      => 'mlshop_product',
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                        'title'          => get_the_title($woo_id),
                    ));
                    if (!empty($hit)) {
                        $ml_id = (int) $hit[0];
                    }
                }
                if ($ml_id) {
                    $set = wp_set_object_terms($ml_id, $ml_term_id, 'mlshop_product_tag', true);
                    if (!is_wp_error($set)) {
                        $products_tagged++;
                    }
                }
            }
        }

        $message = sprintf(
            /* translators: %1$d 导入的标签数；%2$d 被标注标签的月光商品数 */
            __('同步完成：共导入 %1$d 个商品标签，为 %2$d 件月光商品标注标签。', 'moonlight-shop'),
            $terms_imported,
            $products_tagged
        );
        return array(
            'success'         => true,
            'message'         => $message,
            'terms_imported'  => $terms_imported,
            'products_tagged' => $products_tagged,
        );
    }

    /**
     * 同步工具结果通知 + 跳回设置页（复用后台统一通知逻辑）。
     */
    private function sync_notice($success, $message)
    {
        set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
            'gateway' => 'tags',
            'success' => (bool) $success,
            'message' => $message,
        ), 60);
        wp_safe_redirect(admin_url('edit.php?post_type=mlshop_product&page=mlshop-settings'));
        exit;
    }

    /**
     * 商品列表短代码。
     */
    public function shortcode_products($atts)
    {
        $atts = shortcode_atts(array('cat' => '', 'limit' => 12), $atts, 'mlshop_products');
        $args = array(
            'post_type'      => 'mlshop_product',
            'posts_per_page' => (int) $atts['limit'],
            'post_status'    => 'publish',
        );
        $query = new WP_Query($args);

        ob_start();
        echo '<div class="mlshop-products">';
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                mlshop_get_template('product-item', array('product_id' => get_the_ID()));
            }
            wp_reset_postdata();
        } else {
            echo '<p>' . esc_html__('暂无商品。', 'moonlight-shop') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
