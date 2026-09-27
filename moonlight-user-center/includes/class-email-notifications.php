<?php
/**
 * 邮件通知（第一版范围，§47）：购买成功 + License 到期前提醒。
 *
 * - 购买成功：mluc_payment_completed 钩子触发，收件人 = 下单用户；
 * - License 到期提醒：每日 cron，License 到期前 7 天提醒一次（reminded 标记防重复）；
 * - 均可后台开关；不记录 / 不发送任何敏感凭证。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Email_Notifications
{
    private static $instance = null;

    const CRON_HOOK = 'mluc_license_expiry_check';
    /** License 到期前提醒提前量（天）。 */
    const REMIND_DAYS = 7;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('mluc_payment_completed', array($this, 'send_purchase_email'), 20, 3);
        add_action('init', array($this, 'schedule_cron'));
        add_action(self::CRON_HOOK, array($this, 'run_expiry_check'));
    }

    /**
     * 每日到期提醒任务：开关开启时排程，关闭时注销。
     */
    public function schedule_cron()
    {
        if (!empty(mluc_get_option('email_license_reminder_enabled', 1))) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 600, 'daily', self::CRON_HOOK);
            }
        } elseif (wp_next_scheduled(self::CRON_HOOK)) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /**
     * 购买成功邮件。
     *
     * @param int    $order_id 订单 ID。
     * @param int    $user_id  用户 ID。
     * @param string $level    等级或 paywall:文章ID。
     */
    public function send_purchase_email($order_id, $user_id, $level)
    {
        if (empty(mluc_get_option('email_purchase_enabled', 1))) {
            return;
        }
        $user = get_user_by('id', (int) $user_id);
        if (!$user || !is_email($user->user_email)) {
            return;
        }

        $order_no  = (string) get_post_meta($order_id, '_mluc_pay_order_no', true);
        $price     = (float) get_post_meta($order_id, '_mluc_pay_price', true);
        $symbol    = MLUC_Payments::get_currency_symbol();
        $is_pw     = 0 === strpos((string) $level, 'paywall:');
        if ($is_pw) {
            $item = get_the_title((int) substr((string) $level, strlen('paywall:')));
        } else {
            $item = class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label((string) $level) : (string) $level;
        }

        $subject = sprintf(
            /* translators: 1: 站点名，2: 商品名 */
            __('[%1$s] 购买成功：%2$s', 'moonlight-user-center'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $item
        );
        $lines = array(
            sprintf(__('您好，%s：', 'moonlight-user-center'), $user->display_name),
            '',
            sprintf(__('您在「%1$s」的购买已完成支付。', 'moonlight-user-center'), get_bloginfo('name')),
            __('商品：', 'moonlight-user-center') . $item,
            __('金额：', 'moonlight-user-center') . $symbol . number_format($price, 2),
            __('订单号：', 'moonlight-user-center') . $order_no,
            __('时间：', 'moonlight-user-center') . current_time('mysql'),
            '',
            __('感谢您的支持！', 'moonlight-user-center'),
        );
        wp_mail($user->user_email, $subject, implode("\n", $lines));
    }

    /**
     * License 到期前提醒（每日 cron）。
     */
    public function run_expiry_check()
    {
        if (empty(mluc_get_option('email_license_reminder_enabled', 1))) {
            return;
        }
        $posts = get_posts(array(
            'post_type'      => MLUC_License_Manager::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'no_found_rows'  => true,
            'meta_query'     => array(
                array('key' => '_mluc_license_status', 'value' => MLUC_License_Manager::STATUS_ACTIVE),
                array('key' => '_mluc_license_expires', 'value' => 0, 'compare' => '>'),
            ),
        ));
        $window_end = time() + self::REMIND_DAYS * DAY_IN_SECONDS;
        foreach ($posts as $p) {
            $expires = (int) get_post_meta($p->ID, '_mluc_license_expires', true);
            if (!$expires || $expires > $window_end || $expires < time()) {
                continue;
            }
            if (get_post_meta($p->ID, '_mluc_license_reminded', true)) {
                continue; // 已提醒过，防重复。
            }
            $user = $p->post_author ? get_user_by('id', (int) $p->post_author) : false;
            $to   = $user && is_email($user->user_email) ? $user->user_email : (string) get_post_meta($p->ID, '_mluc_license_email', true);
            if (!$to || !is_email($to)) {
                continue;
            }
            $subject = sprintf(
                /* translators: 1: 站点名 */
                __('[%1$s] License 即将到期提醒', 'moonlight-user-center'),
                wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
            );
            $lines = array(
                sprintf(__('您的 License（%1$s，产品：%2$s）将于 %3$s 到期。', 'moonlight-user-center'), $p->post_title,
                    (string) get_post_meta($p->ID, '_mluc_license_product', true),
                    wp_date(get_option('date_format', 'Y-m-d'), $expires)),
                __('到期后对应 Pro 功能将停止，请及时续费。', 'moonlight-user-center'),
            );
            if (wp_mail($to, $subject, implode("\n", $lines))) {
                update_post_meta($p->ID, '_mluc_license_reminded', current_time('mysql'));
            }
        }
    }
}
