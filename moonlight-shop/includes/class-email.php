<?php
/**
 * 订单确认邮件。
 *
 * 监听 mlshop_order_processing 钩子（貨到付款下單、以及線上付款後轉處理中均會觸發），
 * 發送 HTML 郵件給用戶，含訂單號、商品列表、實物寄送說明、虛擬下載連結 / 卡密。
 * 已付款與待付款（貨到付款）會區分郵件標題與正文。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Email
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
        // 邮件在「已付款(paid)」与「处理中(processing)」均发送，靠 _mlshop_email_sent 去重：
        // - 含实物即时付款：paid→(发货钩子)processing，邮件在 processing 触发
        // - 纯虚拟/卡密/会员/充值（无实物不进 processing）：邮件在 paid 触发，否则永不发送
        // - COD：仅 processing（不 mark_paid），邮件在 processing 触发
        add_action('mlshop_order_paid', array($this, 'send_order_paid_email'), 20);
        add_action('mlshop_order_processing', array($this, 'send_order_paid_email'), 20);
    }

    /**
     * 主入口：发送订单付款完成邮件。
     *
     * @param int    $order_id
     * @param string $override_email 测试/预览时发往此邮箱（留空则发往客户）
     * @param bool   $force          忽略「已发送」去重标记（测试用）
     */
    public function send_order_paid_email($order_id, $override_email = '', $force = false)
    {
        $sent = get_post_meta($order_id, '_mlshop_email_sent', true);
        if ($sent && !$force) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $user    = get_userdata($user_id);
        if (!$user || empty($user->user_email)) {
            return;
        }
        $to = ($override_email && is_email($override_email)) ? $override_email : $user->user_email;

        // 后台「订单/下载确认邮件」总开关（默认开启）
        if (!mlshop_get_option('order_email_enabled', 1)) {
            return;
        }

        // 可配置发件人（名称 / 邮箱）
        $from_name  = mlshop_get_option('from_name', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $from_email = mlshop_get_option('from_email', mlshop_get_option('store_email', get_option('admin_email')));
        $items = (array) get_post_meta($order_id, '_mlshop_items', true);
        $total = (float) get_post_meta($order_id, '_mlshop_total', true);
        $currency = mlshop_get_option('currency_symbol', 'HK$');
        $delivery = get_post_meta($order_id, '_mlshop_delivery', true);
        if (!is_array($delivery)) {
            $delivery = array();
        }
        $shipping = (float) get_post_meta($order_id, '_mlshop_shipping', true);
        $has_physical = (bool) get_post_meta($order_id, '_mlshop_has_physical', true);
        $shipping_address = get_post_meta($order_id, '_mlshop_shipping_address', true);
        if (!is_array($shipping_address)) {
            $shipping_address = array();
        }
        // 是否已付款：貨到付款（cod）在處理中階段尚未收款；其餘網關走到 processing 即代表已付款。
        $is_paid = ('cod' !== (string) get_post_meta($order_id, '_mlshop_gateway', true));

        if ($has_physical) {
            $subject = $is_paid
                ? sprintf(
                    /* translators: %s: 订单号 */
                    __('【訂單確認】#%s 付款完成 — 實物商品寄送', 'moonlight-shop'),
                    get_the_title($order_id)
                )
                : sprintf(
                    /* translators: %s: 订单号 */
                    __('【訂單確認】#%s 訂單已提交 — 實物商品待發貨', 'moonlight-shop'),
                    get_the_title($order_id)
                );
        } else {
            $subject = $is_paid
                ? sprintf(
                    /* translators: %s: 订单号 */
                    __('【訂單確認】#%s 付款完成 — 教材下載連結', 'moonlight-shop'),
                    get_the_title($order_id)
                )
                : sprintf(
                    /* translators: %s: 订单号 */
                    __('【訂單確認】#%s 訂單已提交 — 待付款', 'moonlight-shop'),
                    get_the_title($order_id)
                );
        }

        // 后台自定义主题（支持 {order_number} 占位符，回退到内置逻辑）
        $subject_setting = (string) mlshop_get_option('order_email_subject', '');
        if ('' !== $subject_setting) {
            $subject = str_replace('{order_number}', get_the_title($order_id), $subject_setting);
        }

        $order_type = get_post_meta($order_id, '_mlshop_type', true);
        $rows = '';
        foreach ($items as $it) {
            // 充值 / 升级订单的 items 无 id/price 键，需用 isset 兜底，避免 PHP Warning（Undefined array key）。
            $pid   = isset($it['id']) ? (int) $it['id'] : 0;
            $title = $pid ? get_the_title($pid) : '';
            if (!$title && isset($it['title'])) {
                $title = $it['title'];
            }
            $type = $pid ? get_post_meta($pid, '_mlshop_type', true) : '';
            if (!$type && isset($it['subtotal'])) {
                // 充值 / 升级订单商品行：用订单类型推断文案（充值 / 会员升级）
                $type = $order_type;
            }
            $price_val = isset($it['price']) ? (float) $it['price'] : (isset($it['subtotal']) ? (float) $it['subtotal'] : 0);
            $price = $currency . number_format($price_val, 2);
            $qty   = isset($it['qty']) ? (int) $it['qty'] : 1;
            $rows .= '<tr>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e4e9f0;">' . esc_html($title) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e4e9f0;text-align:center;">' . esc_html($this->type_label($type)) . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e4e9f0;text-align:center;">' . $qty . '</td>'
                . '<td style="padding:8px 12px;border-bottom:1px solid #e4e9f0;text-align:right;">' . esc_html($price) . '</td>'
                . '</tr>';
        }

        // 會員升級（若訂單含會員等級商品，或本訂單本身是會員升級訂單）
        // 改用后台动态等级定义判断，避免硬编码 monthly/premium 遗漏其它等级。
        $mblv = array();
        $levels     = class_exists('MLUC_Membership') ? MLUC_Membership::get_levels() : array();
        foreach ($items as $it) {
            $pid = isset($it['id']) ? (int) $it['id'] : 0;
            $lv  = $pid ? get_post_meta($pid, '_mlshop_membership_level', true) : '';
            if (!$lv && 'membership' === $order_type) {
                $lv = get_post_meta($order_id, '_mlshop_membership_target', true);
            }
            if ($lv && 'free' !== $lv && isset($levels[$lv])) {
                $mblv[] = $lv;
            }
        }

        // 實物商品寄送說明（如有收件地址一併列出）
        $physical_html = '';
        if ($has_physical) {
            $addr_labels = array(
                'name'    => __('收件人', 'moonlight-shop'),
                'phone'   => __('電話', 'moonlight-shop'),
                'address' => __('地址', 'moonlight-shop'),
                'note'    => __('備註', 'moonlight-shop'),
            );
            $addr_lines = '';
            foreach ($addr_labels as $k => $label) {
                if (!empty($shipping_address[$k])) {
                    $addr_lines .= '<li style="margin:4px 0;">' . esc_html($label) . '：' . esc_html($shipping_address[$k]) . '</li>';
                }
            }
            $addr_html = $addr_lines ? '<ul style="padding-left:18px;margin:6px 0;">' . $addr_lines . '</ul>' : '';
            $physical_html = '<h3 style="font-size:15px;margin:18px 0 8px;">' . esc_html__('實物商品寄送', 'moonlight-shop') . '</h3>'
                . '<p style="margin:6px 0;">' . esc_html(sprintf(__('本訂單含實物商品，將由 %s 安排寄送，付款後約 2–3 個工作天出貨。', 'moonlight-shop'), MLSHOP_Shipping::carrier())) . '</p>'
                . $addr_html;
        }

        // 交付明细：下载链接 / 卡密
        $delivery_html = '';
        if (!empty($delivery)) {
            $lines = '';
            foreach ($delivery as $d) {
                $product = get_post((int) $d['product_id']);
                $title   = $product ? $product->post_title : '';
                if ($d['type'] === 'download') {
                    $url = add_query_arg('mlshop_download', $d['token'], home_url('/'));
                    $lines .= '<li style="margin:6px 0;">'
                        . '<strong>' . esc_html($title) . '</strong> — '
                        . '<a href="' . esc_url($url) . '" style="color:#2563eb;text-decoration:underline;">' . esc_html__('點此下載', 'moonlight-shop') . '</a>'
                        . '<div style="color:#6b7280;font-size:12px;word-break:break-all;">' . esc_html($url) . '</div>'
                        . '</li>';
                } elseif ($d['type'] === 'cardkey') {
                    $lines .= '<li style="margin:6px 0;">'
                        . '<strong>' . esc_html($title) . '</strong> — '
                        . esc_html__('卡密：', 'moonlight-shop') . '<code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">' . esc_html($d['key']) . '</code>'
                        . '</li>';
                }
            }
            $delivery_html = '<h3 style="font-size:15px;margin:18px 0 8px;">' . esc_html__('您的教材 / 卡密', 'moonlight-shop') . '</h3>'
                . '<ul style="padding-left:18px;margin:0;">' . $lines . '</ul>';
        }
        // 純會員升級且無實物：無交付內容
        if (empty($delivery) && !$has_physical && empty($mblv)) {
            $delivery_html = '<p style="color:#6b7280;margin:12px 0;">' . esc_html__('本次訂單不包含可下載或卡密類商品。', 'moonlight-shop') . '</p>';
        }

        $mb_html = '';
        if (!empty($mblv)) {
            $mb_map = array(
                'monthly' => __('月費會員', 'moonlight-shop'),
                'premium' => __('高級會員', 'moonlight-shop'),
            );
            $names = array();
            foreach (array_unique($mblv) as $lv) {
                $names[] = isset($mb_map[$lv]) ? $mb_map[$lv] : $lv;
            }
            $mb_html = '<h3 style="font-size:15px;margin:18px 0 8px;">' . esc_html__('會員升級', 'moonlight-shop') . '</h3>'
                . '<p style="margin:6px 0;">' . esc_html(sprintf(__('感謝您的支持！您的會員等級已升級為：%s，相關權限立即生效。', 'moonlight-shop'), implode('、', $names))) . '</p>';
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $total_str = $currency . number_format($total, 2);

        // 運費明細行（實物訂單）
        $shipping_row = '';
        if ($has_physical) {
            $ship_val = $shipping > 0 ? $currency . number_format($shipping, 2) : __('免費', 'moonlight-shop');
            $shipping_row = '<tfoot><tr style="border-top:2px solid #e4e9f0;">'
                . '<td colspan="3" style="padding:8px 12px;">' . esc_html__('運費', 'moonlight-shop') . '</td>'
                . '<td style="padding:8px 12px;text-align:right;">' . esc_html($ship_val) . '</td>'
                . '</tr></tfoot>';
        }

        $body = $this->wrap_html(
            $site_name,
            sprintf(
                /* translators: %s: 用户名 */
                esc_html__('%s 您好，感謝您的訂購！', 'moonlight-shop'),
                esc_html($user->display_name)
            ) . '<p style="color:#6b7280;font-size:14px;margin:0 0 14px;">' . ($is_paid
                ? esc_html__('您的訂單已付款完成，相關教材下載連結如下。請妥善保存。', 'moonlight-shop')
                : esc_html__('您的訂單已提交，我們將盡快為您處理；貨到付款訂單將於驗貨後安排寄送。', 'moonlight-shop')) . '</p>'
            . '<h3 style="font-size:15px;margin:18px 0 8px;">' . esc_html__('訂單詳情', 'moonlight-shop') . '</h3>'
            . '<p style="margin:6px 0;">' . esc_html__('訂單號：', 'moonlight-shop') . '<strong>#' . esc_html(get_the_title($order_id)) . '</strong></p>'
            . '<p style="margin:6px 0;">' . esc_html__('付款時間：', 'moonlight-shop') . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp'))) . '</p>'
            . '<p style="margin:6px 0;">' . esc_html__('訂單金額：', 'moonlight-shop') . '<strong>' . esc_html($total_str) . '</strong></p>'
            . '<table style="width:100%;border-collapse:collapse;margin:12px 0;font-size:14px;">'
            . '<thead><tr style="background:#f9fafb;">'
            . '<th style="padding:8px 12px;text-align:left;">' . esc_html__('商品', 'moonlight-shop') . '</th>'
            . '<th style="padding:8px 12px;">' . esc_html__('類型', 'moonlight-shop') . '</th>'
            . '<th style="padding:8px 12px;">' . esc_html__('數量', 'moonlight-shop') . '</th>'
            . '<th style="padding:8px 12px;text-align:right;">' . esc_html__('小計', 'moonlight-shop') . '</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>' . $shipping_row . '</table>'
            . $delivery_html
            . $physical_html
            . $mb_html
            . '<p style="color:#6b7280;font-size:12px;margin-top:24px;">' . esc_html__('本郵件由系統自動發送，請勿直接回覆。', 'moonlight-shop') . '</p>'
        );

        // 后台自定义页脚（支持换行）
        $footer = mlshop_get_option('email_footer', '');
        if ($footer) {
            $body .= '<div style="max-width:600px;margin:0 auto;padding:0 20px 24px;">'
                . '<p style="color:#6b7280;font-size:12px;margin:0;border-top:1px solid #eef1f5;padding-top:12px;">' . nl2br(esc_html($footer)) . '</p>'
                . '</div>';
        }

        $sent_ok = mlshop_send_html_mail($to, $subject, $body, $from_name, $from_email);
        if ($sent_ok) {
            update_post_meta($order_id, '_mlshop_email_sent', current_time('mysql'));
        }
    }

    private function type_label($type)
    {
        $map = array(
            'physical' => __('實物', 'moonlight-shop'),
            'virtual'  => __('虛擬下載', 'moonlight-shop'),
            'cardkey'  => __('卡密', 'moonlight-shop'),
        );
        return isset($map[$type]) ? $map[$type] : $type;
    }

    private function wrap_html($site_name, $content)
    {
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;color:#2d2d2d;">'
            . '<div style="max-width:600px;margin:0 auto;padding:20px;">'
            . '<div style="background:#fff;border-radius:8px;padding:24px 28px;border:1px solid #e4e9f0;">'
            . '<h1 style="font-size:20px;margin:0 0 16px;color:#1f2937;">' . esc_html($site_name) . '</h1>'
            . $content
            . '</div>'
            . '<p style="text-align:center;color:#9ca3af;font-size:12px;margin:14px 0 0;">&copy; ' . esc_html(date('Y')) . ' ' . esc_html($site_name) . '</p>'
            . '</div></body></html>';
    }
}
