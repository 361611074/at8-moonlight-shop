<?php
/**
 * 用户中心独立支付：未启用 moonlight-shop 时，提供内置的会员购买与收款流程。
 *
 * - 网关：内置「線下轉帳 / 管理員確認收款」（mluc_payment_gateways 过滤器可扩展）。
 * - 订单：CPT mluc_order（非公开），状态存 meta _mluc_pay_status：pending / paid / cancelled。
 * - 开通：管理员在订单编辑页「確認收款並開通」→ MLUC_Membership::grant_level（只升不降、按等级有效期）。
 * - 双插件同装时不启用本流程：账户中心会员 Tab 自动改走商城升级购买（MLSHOP_Membership_UI）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Payments
{
    const CPT = 'mluc_order';

    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_cpt'));
        add_action('wp_ajax_mluc_buy_level', array($this, 'ajax_buy_level'));
        add_action('add_meta_boxes_' . self::CPT, array($this, 'register_meta_box'));
        add_action('admin_post_mluc_pay_confirm', array($this, 'admin_confirm'));
        add_action('admin_post_mluc_pay_cancel', array($this, 'admin_cancel'));
        // 商城未启用时接管账户中心「升级购买」区
        add_action('mluc_membership_purchase', array($this, 'render_purchase_card'));
    }

    /**
     * 商城是否接管支付（双插件同装时为 true）。
     */
    public static function shop_active()
    {
        return class_exists('MLSHOP_Membership_UI');
    }

    /**
     * 订单 CPT。商城同装时不显示后台菜单（避免双订单入口），但记录仍可经直链管理。
     */
    public function register_cpt()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => __('会员订单', 'moonlight-user-center'),
                'singular_name' => __('会员订单', 'moonlight-user-center'),
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => self::shop_active() ? false : true,
            'show_in_rest'        => false,
            'menu_icon'           => 'dashicons-tickets-alt',
            'menu_position'       => 58,
            'supports'            => array('title'),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ));
    }

    /**
     * 可用网关（id => title）。内置线下转账；可经过滤器扩展在线网关。
     */
    public static function get_gateways()
    {
        $gateways = array(
            'manual' => __('線下轉帳（管理員確認收款）', 'moonlight-user-center'),
        );
        return apply_filters('mluc_payment_gateways', $gateways);
    }

    /**
     * 付款说明（后台可配）。
     */
    public static function get_instructions()
    {
        $default = __('請按後台設定的付款說明完成轉帳，管理員確認收款後會員等級自動開通。', 'moonlight-user-center');
        $txt = trim((string) mluc_get_option('pay_manual_instructions', ''));
        return '' !== $txt ? $txt : $default;
    }

    /**
     * 货币符号（后台可配，独立支付流程展示用）。
     */
    public static function get_currency_symbol()
    {
        $sym = trim((string) mluc_get_option('pay_currency_symbol', ''));
        return '' !== $sym ? $sym : 'HK$';
    }

    /**
     * 账户中心会员 Tab 的独立购买卡片（商城未启用时由 mluc_membership_purchase 钩子触发）。
     */
    public function render_purchase_card()
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('请先登录。', 'moonlight-user-center') . '</p>';
            return;
        }
        mluc_get_template('membership-purchase', array(
            'levels'   => MLUC_Membership::get_levels(),
            'gateways' => self::get_gateways(),
            'orders'   => $this->get_user_orders(get_current_user_id(), 5),
            'symbol'   => self::get_currency_symbol(),
            'instructions' => self::get_instructions(),
            'nonce'    => wp_create_nonce('mluc_nonce'),
        ));
    }

    /**
     * 用户自己的订单（按时间倒序）。
     */
    public function get_user_orders($user_id, $limit = 5)
    {
        $q = get_posts(array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => (int) $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => array(
                array('key' => '_mluc_pay_user', 'value' => (int) $user_id, 'compare' => '='),
            ),
        ));
        $out = array();
        foreach ($q as $oid) {
            $out[] = array(
                'id'      => (int) $oid,
                'title'   => get_the_title($oid),
                'level'   => (string) get_post_meta($oid, '_mluc_pay_level', true),
                'price'   => (float) get_post_meta($oid, '_mluc_pay_price', true),
                'status'  => (string) get_post_meta($oid, '_mluc_pay_status', true),
                'date'    => get_the_date('', $oid),
            );
        }
        return $out;
    }

    /**
     * 前端购买 AJAX：创建 pending 订单，返回付款说明。
     */
    public function ajax_buy_level()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, __('请先登录。', 'moonlight-user-center'));
        }
        if (self::shop_active()) {
            mluc_send_json(false, __('請使用商城升級流程購買會員。', 'moonlight-user-center'));
        }
        if (!class_exists('MLUC_Membership')) {
            mluc_send_json(false, __('會員模組未載入。', 'moonlight-user-center'));
        }

        $user_id = get_current_user_id();
        $level   = isset($_POST['level']) ? sanitize_key(wp_unslash($_POST['level'])) : '';
        $gw      = isset($_POST['gateway']) ? sanitize_key(wp_unslash($_POST['gateway'])) : '';

        $levels = MLUC_Membership::get_levels();
        if ('' === $level || 'free' === $level || !isset($levels[$level])) {
            mluc_send_json(false, __('無效的會員等級。', 'moonlight-user-center'));
        }
        $price = (float) MLUC_Membership::get_level_price($level);
        if ($price <= 0) {
            mluc_send_json(false, __('該等級未設定價格，暫不可購買。', 'moonlight-user-center'));
        }
        $gateways = self::get_gateways();
        if ('' === $gw || !isset($gateways[$gw])) {
            mluc_send_json(false, __('支付方式無效。', 'moonlight-user-center'));
        }

        $title = sprintf('MB-%s-%s', date_i18n('YmdHis'), $levels[$level]['label']);
        $order_id = wp_insert_post(array(
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id) || !$order_id) {
            mluc_send_json(false, __('訂單建立失敗，請稍後重試。', 'moonlight-user-center'));
        }
        update_post_meta($order_id, '_mluc_pay_user', $user_id);
        update_post_meta($order_id, '_mluc_pay_level', $level);
        update_post_meta($order_id, '_mluc_pay_price', $price);
        update_post_meta($order_id, '_mluc_pay_gateway', $gw);
        update_post_meta($order_id, '_mluc_pay_status', 'pending');

        do_action('mluc_payment_order_created', $order_id, $user_id, $level, $gw);

        mluc_send_json(true, __('訂單已建立，請按付款說明完成轉帳，管理員確認收款後自動開通。', 'moonlight-user-center'), array(
            'order_id' => (int) $order_id,
            'instructions' => self::get_instructions(),
        ));
    }

    /**
     * 订单编辑页 meta box：信息 + 管理员确认收款 / 取消。
     */
    public function register_meta_box($post)
    {
        add_meta_box('mluc_pay_meta', __('收款資訊', 'moonlight-user-center'), array($this, 'render_meta_box'), self::CPT, 'normal', 'high');
    }

    public function render_meta_box($post)
    {
        wp_nonce_field('mluc_pay_meta', 'mluc_pay_meta_nonce');
        $user_id = (int) get_post_meta($post->ID, '_mluc_pay_user', true);
        $level   = (string) get_post_meta($post->ID, '_mluc_pay_level', true);
        $price   = (float) get_post_meta($post->ID, '_mluc_pay_price', true);
        $gw      = (string) get_post_meta($post->ID, '_mluc_pay_gateway', true);
        $status  = (string) get_post_meta($post->ID, '_mluc_pay_status', true);
        $granted = (string) get_post_meta($post->ID, '_mluc_pay_granted', true);

        $user = $user_id ? get_user_by('id', $user_id) : false;
        $gateways = self::get_gateways();
        $status_labels = array(
            'pending'   => __('待確認', 'moonlight-user-center'),
            'paid'      => __('已收款', 'moonlight-user-center'),
            'cancelled' => __('已取消', 'moonlight-user-center'),
        );
        ?>
        <table class="form-table" role="presentation">
            <tr><th><?php esc_html_e('用戶', 'moonlight-user-center'); ?></th>
                <td><?php echo $user ? esc_html($user->user_login . ' (#' . $user->ID . ')') : '—'; ?></td></tr>
            <tr><th><?php esc_html_e('會員等級', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html('' !== $level ? MLUC_Membership::get_level_label($level) . ' (' . $level . ')' : '—'); ?></td></tr>
            <tr><th><?php esc_html_e('金額', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html(self::get_currency_symbol() . number_format($price, 2)); ?></td></tr>
            <tr><th><?php esc_html_e('支付方式', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html(isset($gateways[$gw]) ? $gateways[$gw] : $gw); ?></td></tr>
            <tr><th><?php esc_html_e('狀態', 'moonlight-user-center'); ?></th>
                <td><?php echo esc_html(isset($status_labels[$status]) ? $status_labels[$status] : $status); ?>
                    <?php if ($granted) : ?>｜<?php esc_html_e('已開通於', 'moonlight-user-center'); ?> <?php echo esc_html($granted); ?><?php endif; ?>
                </td></tr>
        </table>
        <?php if ('pending' === $status) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <?php wp_nonce_field('mluc_pay_confirm'); ?>
                <input type="hidden" name="action" value="mluc_pay_confirm">
                <input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
                <button type="submit" class="button button-primary"><?php esc_html_e('確認收款並開通會員', 'moonlight-user-center'); ?></button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <?php wp_nonce_field('mluc_pay_cancel'); ?>
                <input type="hidden" name="action" value="mluc_pay_cancel">
                <input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
                <button type="submit" class="button"><?php esc_html_e('取消訂單', 'moonlight-user-center'); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * 管理员确认收款 → 开通会员。
     */
    public function admin_confirm()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_pay_confirm')) {
            wp_die(esc_html__('權限不足或校驗失敗。', 'moonlight-user-center'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $redirect = wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit');
        if ($order_id && get_post_type($order_id) === self::CPT
            && 'pending' === get_post_meta($order_id, '_mluc_pay_status', true)) {
            $user_id = (int) get_post_meta($order_id, '_mluc_pay_user', true);
            $level   = (string) get_post_meta($order_id, '_mluc_pay_level', true);
            $ok = false;
            if ($user_id && class_exists('MLUC_Membership') && isset(MLUC_Membership::get_levels()[$level])) {
                $ok = MLUC_Membership::get_instance()->grant_level($user_id, $level);
            }
            if ($ok) {
                update_post_meta($order_id, '_mluc_pay_status', 'paid');
                update_post_meta($order_id, '_mluc_pay_granted', current_time('mysql'));
                do_action('mluc_payment_completed', $order_id, $user_id, $level);
            }
            set_transient('mluc_admin_notice_' . get_current_user_id(), array(
                'success' => $ok,
                'message' => $ok
                    ? __('已確認收款，會員等級開通成功。', 'moonlight-user-center')
                    : __('開通失敗：會員等級不存在或用戶等級不低於目標等級。', 'moonlight-user-center'),
            ), 60);
        }
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 管理员取消订单。
     */
    public function admin_cancel()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mluc_pay_cancel')) {
            wp_die(esc_html__('權限不足或校驗失敗。', 'moonlight-user-center'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=' . self::CPT);
        if ($order_id && get_post_type($order_id) === self::CPT) {
            update_post_meta($order_id, '_mluc_pay_status', 'cancelled');
        }
        wp_safe_redirect($redirect);
        exit;
    }
}
