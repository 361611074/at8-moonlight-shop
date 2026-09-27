<?php
/**
 * 已購教材列表：从 moonlight-shop 的已付款订单中提取虚拟/卡密商品。
 *
 * 检测到 MLUC_Account 存在时自动添加 "已購教材" tab；否则用 [mluc_purchases] 短代码独立使用。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Purchases
{
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
        add_shortcode('mluc_purchases', array($this, 'shortcode_purchases'));

        if (class_exists('MLUC_Account')) {
            add_filter('mluc_account_tabs', array($this, 'register_tab'));
        }
    }

    public function register_tab($tabs)
    {
        $tabs['purchases'] = array(
            'title'    => __('已購教材', 'moonlight-user-center'),
            'icon'     => 'dashicons-cart',
            'callback' => array($this, 'tab_purchases'),
        );
        return $tabs;
    }

    public function tab_purchases()
    {
        echo $this->shortcode_purchases();
    }

    /**
     * 查询当前用户的已付款虚拟/卡密订单。
     */
    public static function get_user_purchases($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return array();
        }
        // 检测 moonlight-shop 是否启用
        if (!post_type_exists('mlshop_order')) {
            return array();
        }
        $orders = get_posts(array(
            'post_type'      => 'mlshop_order',
            // 订单使用自定义 WP 状态（mlshop_pending/paid/processing/...），不是 'publish'
            'post_status'    => array('mlshop_pending', 'mlshop_paid', 'mlshop_processing', 'mlshop_refunded', 'mlshop_cancelled'),
            'author'         => $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'   => '_mlshop_status',
                    'value' => array('paid', 'processing', 'completed'),
                ),
            ),
        ));
        $purchases = array();
        foreach ($orders as $order) {
            // 订单商品存于 _mlshop_items，每条键为 id / price / qty（见 MLSHOP_Cart::get_items）
            $items = (array) get_post_meta($order->ID, '_mlshop_items', true);
            $status = get_post_meta($order->ID, '_mlshop_status', true);
            foreach ($items as $item) {
                $product_id = isset($item['id']) ? (int) $item['id'] : 0;
                if (!$product_id) {
                    continue;
                }
                $type = get_post_meta($product_id, '_mlshop_type', true);
                if (!in_array($type, array('virtual', 'cardkey'), true)) {
                    continue;
                }
                $purchases[] = array(
                    'order_id'      => $order->ID,
                    'order_date'    => get_post_time('U', true, $order),
                    'order_status'  => $status,
                    'product_id'    => $product_id,
                    'product_title' => get_the_title($product_id),
                    'qty'           => isset($item['qty']) ? (int) $item['qty'] : 1,
                    'price'         => isset($item['price']) ? (float) $item['price'] : 0,
                    'type'          => $type,
                );
            }
        }
        return $purchases;
    }

    /**
     * 短代码 [mluc_purchases] 列出当前用户已购虚拟/卡密商品。
     */
    public function shortcode_purchases()
    {
        if (!is_user_logged_in()) {
            return '<p class="mluc-message">' . esc_html__('請先登入查看已購教材。', 'moonlight-user-center') . '</p>';
        }
        $purchases = self::get_user_purchases();

        ob_start();
        echo '<div class="mluc-purchases">';
        if (!empty($purchases)) {
            echo '<div class="mluc-table-scroll"><table class="mluc-table"><thead><tr>';
            echo '<th>' . esc_html__('教材', 'moonlight-user-center') . '</th>';
            echo '<th>' . esc_html__('類型', 'moonlight-user-center') . '</th>';
            echo '<th>' . esc_html__('購買日期', 'moonlight-user-center') . '</th>';
            echo '<th>' . esc_html__('操作', 'moonlight-user-center') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($purchases as $p) {
                echo '<tr>';
                echo '<td>' . esc_html($p['product_title']) . '</td>';
                echo '<td>' . esc_html($p['type'] === 'cardkey' ? __('卡密', 'moonlight-user-center') : __('虛擬下載', 'moonlight-user-center')) . '</td>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format', 'Y-m-d'), $p['order_date'])) . '</td>';
                echo '<td>';
                $view_url = add_query_arg(array('tab' => 'orders', 'order_id' => $p['order_id']), mluc_get_account_url());
                if (function_exists('mluc_get_account_url')) {
                    echo '<a class="mluc-btn mluc-btn-small" href="' . esc_url($view_url) . '">' . esc_html__('查看訂單', 'moonlight-user-center') . '</a>';
                } else {
                    echo '<a class="mluc-btn mluc-btn-small" href="' . esc_url(get_permalink($p['order_id'])) . '">' . esc_html__('查看訂單', 'moonlight-user-center') . '</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="mluc-empty">' . esc_html__('您暫無已購教材。', 'moonlight-user-center') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
