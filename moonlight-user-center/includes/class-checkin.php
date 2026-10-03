<?php
/**
 * 每日签到送积分：基础奖励 + 连续签到加成，全部后台可配置。
 *
 * - 连续判定：昨天签到过 → 连续天数 +1，否则重置为 1（按站点时区自然日）；
 * - 奖励：基础积分；连续天数每满 N 天额外加成一次；
 * - 入账走 MLUC_Credit 原子账本并留流水；一天仅可签到一次（服务端日期判定）。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Checkin
{
    const META_DATE   = 'mluc_checkin_date';
    const META_STREAK = 'mluc_checkin_streak';

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_mluc_checkin', array($this, 'ajax_checkin'));
    }

    /**
     * 签到功能是否可用（总开关 + 积分体系）。
     */
    public static function enabled()
    {
        return mluc_credit_enabled() && !empty(mluc_get_option('checkin_enabled', 0)) && class_exists('MLUC_Credit');
    }

    /**
     * 当前连续签到天数。
     */
    public static function get_streak($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return 0;
        }
        $streak = (int) get_user_meta($user_id, self::META_STREAK, true);
        $last   = (string) get_user_meta($user_id, self::META_DATE, true);
        // 断签（最后一次签到早于昨天）后展示的连续天数归零。
        $yesterday = gmdate('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);
        return ('' !== $last && ($last === gmdate('Y-m-d', current_time('timestamp')) || $last === $yesterday)) ? $streak : 0;
    }

    /**
     * 今日是否已签到。
     */
    public static function checked_today($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        return gmdate('Y-m-d', current_time('timestamp')) === (string) get_user_meta($user_id, self::META_DATE, true);
    }

    /**
     * 签到 AJAX。
     */
    public function ajax_checkin()
    {
        check_ajax_referer('mluc_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mluc_send_json(false, mluc_ui_label('buy_login_required', 'Please log in first.'));
        }
        if (!self::enabled()) {
            mluc_send_json(false, __('签到功能未启用。', 'moonlight-user-center'));
        }

        $user_id = get_current_user_id();
        $today   = gmdate('Y-m-d', current_time('timestamp'));
        if (self::checked_today($user_id)) {
            mluc_send_json(false, __('今天已经签到过了，明天再来吧。', 'moonlight-user-center'));
        }

        // 并发防护：每用户命名锁（GET_LOCK 立取不等待），双击 / 并发请求只有一个能进入发奖段。
        // 锁不可用（返回 NULL，如非 MySQL 环境）时优雅降级为无锁行为。
        global $wpdb;
        $lock_name = 'mluc_checkin_' . $user_id;
        $lock      = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name));
        if ('0' === (string) $lock) {
            mluc_send_json(false, __('签到正在处理中，请勿重复提交。', 'moonlight-user-center'));
        }
        $locked = ('1' === (string) $lock);

        if (self::checked_today($user_id)) {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
            mluc_send_json(false, __('今天已经签到过了，明天再来吧。', 'moonlight-user-center'));
        }

        $yesterday  = gmdate('Y-m-d', current_time('timestamp') - DAY_IN_SECONDS);
        $last       = (string) get_user_meta($user_id, self::META_DATE, true);
        $streak     = ($last === $yesterday) ? ((int) get_user_meta($user_id, self::META_STREAK, true) + 1) : 1;

        $base  = max(0, (int) mluc_get_option('checkin_base', 5));
        $every = max(0, (int) mluc_get_option('checkin_every', 7));
        $extra = max(0, (int) mluc_get_option('checkin_extra', 20));
        $award = $base;
        if ($every > 0 && $extra > 0 && 0 === $streak % $every) {
            $award += $extra;
        }
        if ($award <= 0) {
            if ($locked) {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
            mluc_send_json(false, __('签到奖励配置无效，请联系管理员。', 'moonlight-user-center'));
        }

        update_user_meta($user_id, self::META_DATE, $today);
        update_user_meta($user_id, self::META_STREAK, $streak);
        $balance = MLUC_Credit::add($user_id, $award, sprintf(__('每日签到（连续 %1$d 天）', 'moonlight-user-center'), $streak));

        if ($locked) {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }

        do_action('mluc_checkin', $user_id, $award, $streak);

        mluc_send_json(true, sprintf(
            /* translators: 1: 积分名称，2: 奖励数，3: 连续天数 */
            __('签到成功，获得 %1$s %2$s！已连续签到 %3$d 天。', 'moonlight-user-center'),
            mluc_get_credit_name(),
            number_format($award),
            $streak
        ), array(
            'award'  => $award,
            'streak' => $streak,
            'balance' => $balance,
        ));
    }
}
