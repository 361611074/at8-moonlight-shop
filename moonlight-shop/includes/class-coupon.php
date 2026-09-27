<?php
/**
 * 优惠券系统：CPT 定义、后台编辑、校验与折扣计算、用量自增。
 *
 * 优惠码通过 post_title 呈现，核心规则存于元字段；下单时按购物车小计计算
 * 折扣并写入订单 meta，支付成功后自增用量。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Coupon
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
        add_action('init', array($this, 'register_post_type'));
        if (is_admin()) {
            add_action('add_meta_boxes', array($this, 'add_meta_box'));
            add_action('save_post_mlshop_coupon', array($this, 'save_meta'), 20, 2);
        }
        // 支付成功后自增用量（線上付款走 paid；貨到付款走 completed；increment_usage 内部幂等保护）
        add_action('mlshop_order_paid', array($this, 'increment_usage'), 5);
        add_action('mlshop_order_completed', array($this, 'increment_usage'), 5);
        // 前台「我的优惠券」短代码：列出当前可用优惠券（登录用户，Phase 10 补全）
        add_shortcode('mlshop_coupons', array($this, 'shortcode_coupons'));
    }

    public static function register_post_type()
    {
        register_post_type('mlshop_coupon', array(
            'labels' => array(
                'name'          => __('优惠券', 'moonlight-shop'),
                'singular_name' => __('优惠券', 'moonlight-shop'),
                'add_new'       => __('新建优惠券', 'moonlight-shop'),
                'add_new_item'  => __('新建优惠券', 'moonlight-shop'),
                'edit_item'     => __('编辑优惠券', 'moonlight-shop'),
                'search_items'  => __('搜索优惠券', 'moonlight-shop'),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'edit.php?post_type=mlshop_product',
            'supports'     => array('title'),
            'rewrite'      => false,
        ));
    }

    public function add_meta_box()
    {
        add_meta_box(
            'mlshop_coupon_meta',
            __('优惠券规则', 'moonlight-shop'),
            array($this, 'render_meta_box'),
            'mlshop_coupon',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post)
    {
        $type   = get_post_meta($post->ID, '_mlshop_coupon_type', true);
        $value  = (float) get_post_meta($post->ID, '_mlshop_coupon_value', true);
        $min    = (float) get_post_meta($post->ID, '_mlshop_coupon_min', true);
        $max    = (float) get_post_meta($post->ID, '_mlshop_coupon_max', true);
        $limit  = (int) get_post_meta($post->ID, '_mlshop_coupon_limit', true);
        $used   = (int) get_post_meta($post->ID, '_mlshop_coupon_used', true);
        $expire = get_post_meta($post->ID, '_mlshop_coupon_expire', true);
        $active = get_post_meta($post->ID, '_mlshop_coupon_active', true);
        $active = '' === $active ? 1 : (int) $active;
        wp_nonce_field('mlshop_coupon_meta', 'mlshop_coupon_meta_nonce');
        ?>
        <table class="form-table mlshop-coupon-fields">
            <tr>
                <th><label for="mlshop_coupon_code"><?php esc_html_e('优惠码', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="text" id="mlshop_coupon_code" name="mlshop_coupon_code" value="<?php echo esc_attr($post->post_title); ?>" class="regular-text" style="text-transform:uppercase">
                    <p class="description"><?php esc_html_e('保存后作为优惠码，建议全大写、无空格。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_coupon_type"><?php esc_html_e('优惠类型', 'moonlight-shop'); ?></label></th>
                <td>
                    <select id="mlshop_coupon_type" name="mlshop_coupon_type">
                        <option value="percent" <?php selected($type, 'percent'); ?>><?php esc_html_e('百分比折扣（%）', 'moonlight-shop'); ?></option>
                        <option value="fixed" <?php selected($type, 'fixed'); ?>><?php esc_html_e('固定金额减免', 'moonlight-shop'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_coupon_value"><?php esc_html_e('优惠数值', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="text" id="mlshop_coupon_value" name="mlshop_coupon_value" value="<?php echo esc_attr($value); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('百分比填 0–100；固定金额填金额（货币单位）。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_coupon_min"><?php esc_html_e('最低消费（小计门槛）', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="text" id="mlshop_coupon_min" name="mlshop_coupon_min" value="<?php echo esc_attr($min); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('订单小计需达到该金额才可用，0 表示不限。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_coupon_max"><?php esc_html_e('最高减免（百分比用）', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="text" id="mlshop_coupon_max" name="mlshop_coupon_max" value="<?php echo esc_attr($max); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('仅百分比类型生效，0 表示不限。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_coupon_limit"><?php esc_html_e('使用次数上限', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="number" min="0" id="mlshop_coupon_limit" name="mlshop_coupon_limit" value="<?php echo esc_attr($limit); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('0 表示不限次数。当前已用：%d', 'moonlight-shop'); echo ' ' . (int) $used; ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_coupon_expire"><?php esc_html_e('过期日期', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="date" id="mlshop_coupon_expire" name="mlshop_coupon_expire" value="<?php echo esc_attr($expire); ?>" class="regular-text" style="max-width:220px;">
                    <p class="description"><?php esc_html_e('留空表示长期有效（YY-MM-DD）。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('启用', 'moonlight-shop'); ?></th>
                <td>
                    <input type="hidden" name="mlshop_coupon_active" value="0">
                    <label><input type="checkbox" name="mlshop_coupon_active" value="1" <?php checked(1, $active); ?>> <?php esc_html_e('启用此优惠券', 'moonlight-shop'); ?></label>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['mlshop_coupon_meta_nonce']) || !wp_verify_nonce($_POST['mlshop_coupon_meta_nonce'], 'mlshop_coupon_meta')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $code = isset($_POST['mlshop_coupon_code']) ? strtoupper(trim(sanitize_text_field($_POST['mlshop_coupon_code']))) : '';
        if ('' === $code) {
            $code = strtoupper(substr($post->post_name ?: 'CP' . $post_id, 0, 16));
        }
        // 去重：避免与其它优惠券同码
        $dup = $this->get_by_code($code);
        if ($dup && (int) $dup !== (int) $post_id) {
            $code = $code . '-' . $post_id;
        }
        wp_update_post(array('ID' => $post_id, 'post_title' => $code, 'post_name' => sanitize_title($code)));

        update_post_meta($post_id, '_mlshop_coupon_type', isset($_POST['mlshop_coupon_type']) ? sanitize_key($_POST['mlshop_coupon_type']) : 'percent');
        update_post_meta($post_id, '_mlshop_coupon_value', mlshop_sanitize_float(isset($_POST['mlshop_coupon_value']) ? $_POST['mlshop_coupon_value'] : 0));
        update_post_meta($post_id, '_mlshop_coupon_min', mlshop_sanitize_float(isset($_POST['mlshop_coupon_min']) ? $_POST['mlshop_coupon_min'] : 0));
        update_post_meta($post_id, '_mlshop_coupon_max', mlshop_sanitize_float(isset($_POST['mlshop_coupon_max']) ? $_POST['mlshop_coupon_max'] : 0));
        update_post_meta($post_id, '_mlshop_coupon_limit', isset($_POST['mlshop_coupon_limit']) ? absint($_POST['mlshop_coupon_limit']) : 0);
        update_post_meta($post_id, '_mlshop_coupon_expire', isset($_POST['mlshop_coupon_expire']) ? sanitize_text_field($_POST['mlshop_coupon_expire']) : '');
        update_post_meta($post_id, '_mlshop_coupon_active', isset($_POST['mlshop_coupon_active']) ? 1 : 0);
    }

    /**
     * 按优惠码查找优惠券 ID。
     */
    public function get_by_code($code)
    {
        $code = strtoupper(trim((string) $code));
        if ('' === $code) {
            return 0;
        }
        $posts = get_posts(array(
            'post_type'      => 'mlshop_coupon',
            'title'          => $code,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => array('publish', 'pending', 'draft'),
        ));
        return !empty($posts) ? (int) $posts[0] : 0;
    }

    /**
     * 校验优惠券是否可应用于当前购物车。
     *
     * @param string $code
     * @param float  $subtotal 购物车小计
     * @param array  $items   购物车条目（含 id）
     * @return int|WP_Error 成功返回优惠券 ID，失败返回 WP_Error
     */
    public static function validate($code, $subtotal, $items = array())
    {
        $id = (new self())->get_by_code($code);
        if (!$id) {
            return new WP_Error('not_found', __('优惠码不存在。', 'moonlight-shop'));
        }
        if (!get_post_meta($id, '_mlshop_coupon_active', true)) {
            return new WP_Error('inactive', __('该优惠码已停用。', 'moonlight-shop'));
        }
        $min = (float) get_post_meta($id, '_mlshop_coupon_min', true);
        if ($min > 0 && $subtotal < $min) {
            return new WP_Error('min', sprintf(__('订单满 %s 才可使用此优惠码。', 'moonlight-shop'), mlshop_format_price($min)));
        }
        $limit = (int) get_post_meta($id, '_mlshop_coupon_limit', true);
        $used  = (int) get_post_meta($id, '_mlshop_coupon_used', true);
        if ($limit > 0 && $used >= $limit) {
            return new WP_Error('limit', __('该优惠码已达到使用上限。', 'moonlight-shop'));
        }
        $expire = get_post_meta($id, '_mlshop_coupon_expire', true);
        if ($expire && strtotime($expire . ' 23:59:59') < current_time('timestamp')) {
            return new WP_Error('expired', __('该优惠码已过期。', 'moonlight-shop'));
        }
        // 商品级开关：购物车中至少有一个允许使用优惠券的商品才可用
        if (!empty($items)) {
            $allowed = false;
            foreach ($items as $it) {
                $pid = isset($it['id']) ? (int) $it['id'] : 0;
                if ($pid && class_exists('MLSHOP_Product_Pay_Meta') && MLSHOP_Product_Pay_Meta::get($pid, 'allow_coupon', 0)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return new WP_Error('product', __('当前购物车商品不参与优惠活动。', 'moonlight-shop'));
            }
        }
        return $id;
    }

    /**
     * 计算折扣金额（已通过 validate 后调用）。
     */
    public static function compute_discount($coupon_id, $subtotal)
    {
        $coupon_id = (int) $coupon_id;
        $type  = get_post_meta($coupon_id, '_mlshop_coupon_type', true);
        $value = (float) get_post_meta($coupon_id, '_mlshop_coupon_value', true);
        $max   = (float) get_post_meta($coupon_id, '_mlshop_coupon_max', true);

        if ('fixed' === $type) {
            $discount = min($value, $subtotal);
        } else {
            $discount = $subtotal * ($value / 100);
            if ($max > 0) {
                $discount = min($discount, $max);
            }
        }
        return round(max(0.0, $discount), 2);
    }

    /**
     * 原子预留一个使用名额（防并发超发）。
     *
     * 仅当已用量 < 上限时把 _mlshop_coupon_used +1 并返回 true；上限为 0 表示不限。
     * 失败（名额已满）返回 false。基于数据库层条件 UPDATE，并发安全。
     *
     * @param int $coupon_id
     * @return bool
     */
    public static function reserve($coupon_id)
    {
        global $wpdb;
        $coupon_id = (int) $coupon_id;
        if ($coupon_id <= 0) {
            return false;
        }
        $key   = '_mlshop_coupon_used';
        $limit = (int) get_post_meta($coupon_id, '_mlshop_coupon_limit', true);
        if ($limit > 0) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1
                 WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d",
                $coupon_id,
                $key,
                $limit
            ));
            return (int) $affected > 0;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1
             WHERE post_id = %d AND meta_key = %s",
            $coupon_id,
            $key
        ));
        return true;
    }

    /**
     * 释放一个使用名额（订单取消 / 过期 / 退款时回补，避免名额被占死）。
     *
     * @param string $coupon_code
     */
    public static function release($coupon_code)
    {
        global $wpdb;
        $code = strtoupper(trim((string) $coupon_code));
        if ('' === $code) {
            return;
        }
        $id = (new self())->get_by_code($code);
        if (!$id) {
            return;
        }
        $key = '_mlshop_coupon_used';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, CAST(meta_value AS UNSIGNED) - 1)
             WHERE post_id = %d AND meta_key = %s",
            $id,
            $key
        ));
    }

    /**
     * 支付成功后自增用量。
     */
    public function increment_usage($order_id)
    {
        // 下单时已原子预留名额（reserve）的订单，用量已计入，避免重复累加
        if (get_post_meta($order_id, '_mlshop_coupon_reserved', true)) {
            update_post_meta($order_id, '_mlshop_coupon_incremented', '1');
            return;
        }
        // 幂等：避免 paid 與 completed 雙重觸發導致優惠碼用量重複累加
        if (get_post_meta($order_id, '_mlshop_coupon_incremented', true)) {
            return;
        }
        $code = get_post_meta($order_id, '_mlshop_coupon_code', true);
        if (!$code) {
            return;
        }
        $id = $this->get_by_code($code);
        if (!$id) {
            return;
        }
        global $wpdb;
        $key   = '_mlshop_coupon_used';
        $limit = (int) get_post_meta($id, '_mlshop_coupon_limit', true);
        if ($limit > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1
                 WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d",
                $id,
                $key,
                $limit
            ));
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1
                 WHERE post_id = %d AND meta_key = %s",
                $id,
                $key
            ));
        }
        update_post_meta($order_id, '_mlshop_coupon_incremented', '1');
    }

    /**
     * [mlshop_coupons] 短代码：当前用户可用的优惠券列表。
     *
     * 扫描 mlshop_coupon CPT 的有效券（已发布 + 启用 + 名额未用尽 + 未过期），
     * 展示优惠码、面值、最低消费与有效期。此处仅做展示级过滤，
     * 结算时仍以 MLSHOP_Coupon::validate() 的服务端校验为准。
     */
    public function shortcode_coupons()
    {
        // 缓存兼容：优惠券列表随登录态变化，禁止页面缓存（计划书第五十九节）
        mlshop_no_cache();
        if (!is_user_logged_in()) {
            return '<p class="mlshop-message">' . esc_html__('请先登录查看优惠券。', 'moonlight-shop') . '</p>';
        }

        $now = current_time('timestamp');
        $posts = get_posts(array(
            'post_type'      => 'mlshop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ));

        $rows = array();
        foreach ($posts as $c) {
            if (!get_post_meta($c->ID, '_mlshop_coupon_active', true)) {
                continue; // 已停用
            }
            $limit = (int) get_post_meta($c->ID, '_mlshop_coupon_limit', true);
            $used  = (int) get_post_meta($c->ID, '_mlshop_coupon_used', true);
            if ($limit > 0 && $used >= $limit) {
                continue; // 名额已用尽
            }
            $expire = (string) get_post_meta($c->ID, '_mlshop_coupon_expire', true);
            if ($expire && strtotime($expire . ' 23:59:59') < $now) {
                continue; // 已过期
            }
            $type  = (string) get_post_meta($c->ID, '_mlshop_coupon_type', true);
            $value = (float) get_post_meta($c->ID, '_mlshop_coupon_value', true);
            $min   = (float) get_post_meta($c->ID, '_mlshop_coupon_min', true);
            $rows[] = array(
                'code'    => (string) $c->post_title,
                'value'   => self::coupon_value_label($type, $value),
                'min'     => $min > 0 ? mlshop_format_price($min) : '',
                'expire'  => $expire,
            );
        }

        ob_start();
        echo '<div class="mlshop-coupons">';
        if (empty($rows)) {
            echo '<p class="mlshop-message">' . esc_html__('暂无可用优惠券。', 'moonlight-shop') . '</p>';
            return ob_get_clean();
        }
        echo '<table class="mlshop-coupon-list"><thead><tr>'
            . '<th>' . esc_html__('优惠码', 'moonlight-shop') . '</th>'
            . '<th>' . esc_html__('面值', 'moonlight-shop') . '</th>'
            . '<th>' . esc_html__('最低消费', 'moonlight-shop') . '</th>'
            . '<th>' . esc_html__('有效期至', 'moonlight-shop') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td><code class="mlshop-coupon-code">' . esc_html($r['code']) . '</code></td>';
            echo '<td>' . esc_html($r['value']) . '</td>';
            echo '<td>' . ('' !== $r['min'] ? esc_html($r['min']) : esc_html__('无门槛', 'moonlight-shop')) . '</td>';
            echo '<td>' . ('' !== $r['expire'] ? esc_html($r['expire']) : esc_html__('长期有效', 'moonlight-shop')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * 面值展示文案：percent → "10%"；fixed → 货币金额。
     *
     * @param string $type  优惠类型（percent / fixed）
     * @param float  $value 优惠数值
     * @return string
     */
    public static function coupon_value_label($type, $value)
    {
        $value = (float) $value;
        if ('fixed' === $type) {
            return mlshop_format_price($value);
        }
        $num = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        return $num . '%';
    }
}
