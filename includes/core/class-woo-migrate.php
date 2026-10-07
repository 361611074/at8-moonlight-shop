<?php
/**
 * WooCommerce 商品一键迁入（Moonlight_Shop）。
 *
 * 后台「商城 → 设置 → 商品标签」区的工具：把站内全部 WooCommerce 商品
 * 一次性迁成 mlshop_product，并在工具页显示迁移状态。
 *
 * 字段映射：
 *   标题/内容/摘要/特色图片 → 同名迁移；
 *   product_cat → mlshop_product_cat、product_tag → mlshop_product_tag（term 新建或复用）；
 *   _regular_price（缺省 _price）→ _mlshop_price；_stock（空=不限）→ _mlshop_stock；
 *   _virtual / downloadable → 虚拟商品；其余 → 实物；
 *   variable 商品迁为单商品，价格取最低变体价。
 *
 * 幂等：商品记录 _mlshop_woo_source_id，重复迁移时更新而非重建；
 * slug 冲突自动加 -woo 后缀，绝不覆盖已有的月光商品。
 *
 * 状态：option 'mlshop_woo_migration'，工具页渲染最近一次结果。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Woo_Migrate
{
    const STATE_OPTION = 'mlshop_woo_migration';

    public static function boot()
    {
        add_action('admin_post_mlshop_woo_migrate', array(__CLASS__, 'handle'));
    }

    public static function woo_active()
    {
        return post_type_exists('product');
    }

    /* ---------------- 入口 ---------------- */

    public static function handle()
    {
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        check_admin_referer('mlshop_woo_migrate');

        $result = self::migrate_all();

        wp_safe_redirect(add_query_arg(
            array('mlshop_woo_migrated' => 1),
            admin_url('edit.php?post_type=mlshop_product&page=mlshop-settings#mlshop-sec-tags')
        ));
        exit;
    }

    /* ---------------- 迁移 ---------------- */

    /** @return array{time:int,total:int,created:int,updated:int,skipped:int,errors:array} */
    public static function migrate_all()
    {
        $state = array(
            'time'    => time(),
            'total'   => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => array(),
        );

        if (!self::woo_active()) {
            $state['errors'][] = array('item' => '-', 'reason' => '未检测到 WooCommerce（product 类型不存在）');
            update_option(self::STATE_OPTION, $state, false);
            return $state;
        }

        $q = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => array('publish', 'draft', 'pending'),
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));

        foreach ($q->posts as $woo_id) {
            $state['total']++;
            try {
                $r = self::migrate_one((int) $woo_id);
                $state[$r]++;
            } catch (Exception $e) {
                $state['skipped']++;
                $state['errors'][] = array(
                    'item'   => get_the_title($woo_id) . ' (#' . $woo_id . ')',
                    'reason' => $e->getMessage(),
                );
            }
        }

        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    /** @return string 'created'|'updated'|'skipped' */
    private static function migrate_one($woo_id)
    {
        $woo = get_post($woo_id);
        if (!$woo) {
            throw new Exception('源商品不存在');
        }

        // 幂等：按 woo 源 ID 找已有迁入商品
        $exist = get_posts(array(
            'post_type'      => 'mlshop_product',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'post_status'    => 'any',
            'meta_query'     => array(
                array('key' => '_mlshop_woo_source_id', 'value' => (int) $woo_id),
            ),
        ));

        // 价格（variable 取 woo 维护的最低价；simple 取 regular→price）
        $price = (float) get_post_meta($woo_id, '_regular_price', true);
        if ($price <= 0) {
            $price = (float) get_post_meta($woo_id, '_price', true);
        }
        if ($price <= 0) {
            $price = self::min_variation_price($woo_id);
        }

        // 库存（woo 空串/空数组 = 不限 → -1）
        $stock_raw = get_post_meta($woo_id, '_stock', true);
        $stock = ($stock_raw === '' || $stock_raw === null || $stock_raw === array()) ? -1 : max(0, (int) $stock_raw);

        $type = (get_post_meta($woo_id, '_virtual', true) === 'yes' || get_post_meta($woo_id, '_downloadable', true) === 'yes') ? 'virtual' : 'physical';
        $sku  = (string) get_post_meta($woo_id, '_sku', true);

        $args = array(
            'post_title'   => $woo->post_title,
            'post_content' => $woo->post_content,
            'post_excerpt' => $woo->post_excerpt,
            'post_status'  => 'publish' === $woo->post_status ? 'publish' : 'draft',
        );

        if ($exist) {
            $pid = (int) $exist[0];
            $args['ID'] = $pid;
            wp_update_post($args);
            $result = 'updated';
        } else {
            // slug 冲突防覆盖：已被月光商品占用时加后缀
            $slug = $woo->post_name && !get_page_by_path($woo->post_name) ? $woo->post_name : $woo->post_name . '-woo';
            $args['post_name'] = $slug;
            $args['post_type'] = 'mlshop_product';
            $pid = (int) wp_insert_post($args);
            if (!$pid) {
                throw new Exception('创建商品失败');
            }
            update_post_meta($pid, '_mlshop_woo_source_id', (int) $woo_id);
            $result = 'created';
        }

        update_post_meta($pid, '_mlshop_price', (string) $price);
        update_post_meta($pid, '_mlshop_type', $type);
        update_post_meta($pid, '_mlshop_stock', (string) $stock);
        if ($sku !== '') {
            update_post_meta($pid, '_mlshop_sku', $sku);
        }

        // 特色图片
        $thumb = (int) get_post_meta($woo_id, '_thumbnail_id', true);
        if ($thumb) {
            set_post_thumbnail($pid, $thumb);
        }

        // 分类 / 标签（term 同名复用，不重复建）
        self::copy_terms($woo_id, $pid, 'product_cat', 'mlshop_product_cat');
        self::copy_terms($woo_id, $pid, 'product_tag', 'mlshop_product_tag');

        return $result;
    }

    private static function min_variation_price($woo_id)
    {
        $var = get_posts(array(
            'post_type'      => 'product_variation',
            'post_parent'    => $woo_id,
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));
        $min = 0.0;
        foreach ($var as $vid) {
            $p = (float) get_post_meta($vid, '_price', true);
            if ($p > 0 && ($min === 0.0 || $p < $min)) {
                $min = $p;
            }
        }
        return $min;
    }

    private static function copy_terms($from_id, $to_id, $from_tax, $to_tax)
    {
        if (!taxonomy_exists($to_tax)) {
            return;
        }
        $terms = wp_get_object_terms($from_id, $from_tax);
        if (is_wp_error($terms) || !$terms) {
            return;
        }
        $ids = array();
        foreach ($terms as $t) {
            $existing = term_exists($t->slug, $to_tax);
            if ($existing) {
                $ids[] = (int) $existing['term_id'];
                continue;
            }
            $created = wp_insert_term($t->name, $to_tax, array('slug' => $t->slug, 'description' => $t->description));
            $ids[] = is_wp_error($created) ? 0 : (int) $created['term_id'];
        }
        $ids = array_filter($ids);
        if ($ids) {
            wp_set_object_terms($to_id, $ids, $to_tax, true);
        }
    }

    /* ---------------- 工具页渲染 ---------------- */

    public static function render_tools()
    {
        if (!self::woo_active()) {
            echo '<p class="description">' . esc_html__('未检测到 WooCommerce，无需迁移。', 'at8-moonlight-shop') . '</p>';
            return;
        }

        $url = wp_nonce_url(admin_url('admin-post.php?action=mlshop_woo_migrate'), 'mlshop_woo_migrate');
        $count = (int) wp_count_posts('product')->publish;
        ?>
        <p class="description">
            <?php /* translators: %d: 已发布商品数 */ echo esc_html(sprintf(__('一键将站内全部 WooCommerce 商品（当前 %d 个已发布）迁入月光商城：标题/内容/图片/分类/标签/价格/库存/SKU 自动映射；可重复执行，已迁入的商品更新而非重建。', 'at8-moonlight-shop'), $count)); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($url); ?>" class="button button-primary"
               onclick="return confirm('开始迁移全部 WooCommerce 商品？商品较多时可能需要数十秒。');">
                <?php esc_html_e('一键迁入全部商品', 'at8-moonlight-shop'); ?>
            </a>
        </p>
        <?php
        $state = get_option(self::STATE_OPTION);
        if (is_array($state) && !empty($state['time'])) {
            echo '<h3 style="margin:14px 0 6px">' . esc_html__('最近一次迁移状态', 'at8-moonlight-shop') . '</h3>';
            echo '<table class="widefat striped" style="max-width:640px"><tbody>';
            echo '<tr><td style="width:160px">' . esc_html__('时间', 'at8-moonlight-shop') . '</td><td>' . esc_html(wp_date('Y-m-d H:i:s', (int) $state['time'])) . '</td></tr>';
            echo '<tr><td>' . esc_html__('扫描商品', 'at8-moonlight-shop') . '</td><td>' . esc_html((string) $state['total']) . '</td></tr>';
            echo '<tr><td>' . esc_html__('新建', 'at8-moonlight-shop') . '</td><td style="color:#00a32a">' . esc_html((string) $state['created']) . '</td></tr>';
            echo '<tr><td>' . esc_html__('更新', 'at8-moonlight-shop') . '</td><td style="color:#2271b1">' . esc_html((string) $state['updated']) . '</td></tr>';
            echo '<tr><td>' . esc_html__('跳过', 'at8-moonlight-shop') . '</td><td style="color:#b32d2e">' . esc_html((string) $state['skipped']) . '</td></tr>';
            echo '</tbody></table>';

            if (!empty($state['errors'])) {
                echo '<p style="color:#b32d2e;margin-bottom:0"><strong>' . esc_html__('失败明细：', 'at8-moonlight-shop') . '</strong></p>';
                echo '<ul style="margin-top:4px;color:#b32d2e;list-style:disc;padding-left:20px">';
                foreach (array_slice((array) $state['errors'], 0, 10) as $err) {
                    echo '<li>' . esc_html(($err['item'] ?? '') . '：' . ($err['reason'] ?? '')) . '</li>';
                }
                echo '</ul>';
            }
        }
    }
}

