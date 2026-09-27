<?php
/**
 * 虚拟商品交付：下载文件 + 卡密分配。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Download
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
        add_action('mlshop_order_paid', array($this, 'deliver'));
        // 貨到付款(COD)訂單不經 paid，管理員標 completed 後才觸發交付，確保虛擬/卡密商品也能發貨
        add_action('mlshop_order_completed', array($this, 'deliver'));
        add_action('init', array($this, 'handle_download'));
        add_shortcode('mlshop_downloads', array($this, 'shortcode_downloads'));
    }

    /**
     * 订单支付完成后交付虚拟商品 / 分配卡密。
     */
    public function deliver($order_id)
    {
        // 幂等：線上付款(paid)與貨到付款(completed)可能雙重觸發，已交付過則跳過，
        // 避免重複發貨、重複彈出卡密（卡密一旦彈出即扣庫存，重複將造成超賣）
        $delivered = get_post_meta($order_id, '_mlshop_delivery', true);
        if (is_array($delivered) && !empty($delivered)) {
            return;
        }
        // 付费内容订单由 MLSHOP_Pay_Access 负责解锁与下载按钮，不在此重复发货
        if (get_post_meta($order_id, '_mlshop_paywall_post', true)) {
            return;
        }
        // 积分充值订单无实物交付，由 MLSHOP_Credit_UI 负责入账
        if ('recharge' === get_post_meta($order_id, '_mlshop_type', true)) {
            return;
        }
        // 会员升级订单无实物交付，由 MLSHOP_Membership_UI 负责授予等级
        if ('membership' === get_post_meta($order_id, '_mlshop_type', true)) {
            return;
        }
        $items    = get_post_meta($order_id, '_mlshop_items', true);
        $user_id  = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if (!is_array($items)) {
            return;
        }
        $delivery = array();

        foreach ($items as $item) {
            $pid  = (int) $item['id'];
            $type = get_post_meta($pid, '_mlshop_type', true);

            if ($type === 'virtual') {
                $file_id = (int) get_post_meta($pid, '_mlshop_file', true);
                $limit   = (int) get_post_meta($pid, '_mlshop_download_limit', true);
                $limit   = $limit > 0 ? $limit : 7;
                if ($file_id) {
                    $token = wp_generate_password(32, false);
                    set_transient('mlshop_dl_' . $token, array(
                        'order_id'   => $order_id,
                        'product_id' => $pid,
                        'user_id'    => $user_id,
                        'file_id'    => $file_id,
                    ), $limit * DAY_IN_SECONDS);
                    $delivery[] = array('product_id' => $pid, 'type' => 'download', 'token' => $token);
                }
            } elseif ($type === 'cardkey') {
                $key = $this->pop_cardkey($pid);
                if ($key) {
                    $delivery[] = array('product_id' => $pid, 'type' => 'cardkey', 'key' => $key);
                }
            }
        }

        if ($delivery) {
            update_post_meta($order_id, '_mlshop_delivery', $delivery);
        }
    }

    /**
     * 从卡密池弹出一条并扣减库存。
     */
    private function pop_cardkey($product_id)
    {
        // CAS 循环：并发购买时若池子被其他请求改动则重读重试，确保同一张卡密不会发给两个人。
        for ($i = 0; $i < 3; $i++) {
            $pool  = (string) get_post_meta($product_id, '_mlshop_cardkeys', true);
            $lines = array_filter(array_map('trim', explode("\n", $pool)));
            if (empty($lines)) {
                return false;
            }
            $key         = array_shift($lines);
            $leftover    = implode("\n", $lines);
            $cas_applied = mlshop_cas_post_meta($product_id, '_mlshop_cardkeys', $pool, $leftover);
            if (!$cas_applied) {
                continue; // 期间被并发修改，重读池子再试
            }
            $this->decrease_stock($product_id, 1);
            return $key;
        }
        return false;
    }

    /**
     * 扣减库存（原子）。_mlshop_stock 为空或 0 视为不限量。
     */
    private function decrease_stock($product_id, $qty)
    {
        $stock = (int) get_post_meta($product_id, '_mlshop_stock', true);
        if ($stock <= 0) {
            return;
        }
        if (!mlshop_atomic_decrement_post_meta($product_id, '_mlshop_stock', (int) $qty)) {
            update_post_meta($product_id, '_mlshop_stock', 0);
        }
    }

    /**
     * 安全下载端点：?mlshop_download=TOKEN
     */
    public function handle_download()
    {
        if (empty($_GET['mlshop_download'])) {
            return;
        }
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        $token = sanitize_text_field($_GET['mlshop_download']);
        $data  = get_transient('mlshop_dl_' . $token);
        // 明确的 403 / 404 状态码：避免正常业务拦截被误判为服务器错误（wp_die 默认 500）
        if (!$data || (int) $data['user_id'] !== get_current_user_id()) {
            wp_die(__('下载链接无效或已过期。', 'moonlight-shop'), '', array('response' => 403));
        }

        $file_id = isset($data['file_id']) ? (int) $data['file_id'] : 0;
        $file    = $file_id ? get_attached_file($file_id) : '';
        if (!$file || !file_exists($file)) {
            wp_die(__('文件不存在。', 'moonlight-shop'), '', array('response' => 404));
        }

        header('Content-Type: application/octet-stream');
        $download_name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file));
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * 当前用户的已交付下载列表。
     */
    public function shortcode_downloads()
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('请先登录。', 'moonlight-shop') . '</p>';
        }
        $orders = MLSHOP_Order::get_user_orders(get_current_user_id(), 50);
        ob_start();
        echo '<div class="mlshop-downloads">';
        $found = false;
        foreach ($orders as $order) {
            $delivery = get_post_meta($order->ID, '_mlshop_delivery', true);
            if (!is_array($delivery)) {
                continue;
            }
            foreach ($delivery as $d) {
                if ($d['type'] !== 'download') {
                    continue;
                }
                $found = true;
                $product = get_post($d['product_id']);
                $url = add_query_arg('mlshop_download', $d['token'], home_url());
                echo '<div class="mlshop-download-item">';
                echo '<span>' . esc_html($product ? $product->post_title : '') . '</span>';
                echo ' <a class="mlshop-btn" href="' . esc_url($url) . '">' . esc_html__('下载', 'moonlight-shop') . '</a>';
                echo '</div>';
            }
        }
        if (!$found) {
            echo '<p>' . esc_html__('暂无可下载内容。', 'moonlight-shop') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
