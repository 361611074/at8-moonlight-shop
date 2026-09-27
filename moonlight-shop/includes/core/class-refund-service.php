<?php
/**
 * 售后退款服务（Refund_Service，对齐 docs/PAYMENT.md 第五节）。
 *
 * 职责：
 *  - apply()          用户申请售后（环形申请列表 + 动作钩子 + 邮件通知管理员）；
 *  - can_refund()     规则闸（virtual 下载 / cardkey 交付 / physical 发货单 /
 *                     会员·付费墙授予窗口 / recharge 已消费；可被过滤器
 *                     moonlight_refund_allowed 整体覆盖）；
 *  - process()        管理员执行退款（全额 / 部分 / 仅标记；网关联动）；
 *  - gateway_refund() 按 _mlshop_payment_gateway 分派网关 refund()。
 *
 * 金额与留痕（全部挂订单 meta）：
 *  - _mlshop_refund_requests        售后申请（环形 20 条，status=pending|approved）；
 *  - _mlshop_refunded_total         已退款累计（部分退款原子累加，达总额自动终态 refunded）；
 *  - _mlshop_refund_processed_total 处理总量（幂等审计，与 refunded_total 分开记录）；
 *  - _mlshop_refund_log             退款日志（环形 50 条）。
 *
 * 全额退款复用订单状态机 MLSHOP_Order::mark_refunded()：库存回滚 / 余额钱包回补
 * （H1 修复后单账本 _mlshop_balance）/ 充值积分回收 / 优惠券名额释放均由 set_status
 * 副作用完成；卡密不回滚（卡密库存由交付时管理）。余额网关订单全额退款时
 * process() 不调网关 API，钱包回补完全交给状态机，避免双重回补。
 *
 * 权限：apply 由 AJAX 层（登录 + nonce + 属主）校验；process 的 manage_options
 * 由调用方（admin_post handler）校验，本类不重复校验（保持纯服务语义，便于测试）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Refund_Service
{
    /** 售后申请 meta 键（环形 20 条）。 */
    const META_REQUESTS = '_mlshop_refund_requests';
    const REQUESTS_MAX  = 20;

    /** 已退款累计 meta 键。 */
    const META_REFUNDED_TOTAL = '_mlshop_refunded_total';
    /** 处理总量 meta 键（幂等审计，与 refunded_total 分开）。 */
    const META_PROCESSED_TOTAL = '_mlshop_refund_processed_total';
    /** 退款日志 meta 键（环形 50 条）。 */
    const META_REFUND_LOG = '_mlshop_refund_log';
    const LOG_MAX = 50;

    /** 可申请售后 / 可退款的订单状态（已收款口径，与统计销售额口径一致）。 */
    const REFUNDABLE_STATUSES = array('paid', 'processing', 'awaiting_shipment', 'shipped', 'delivered', 'completed');

    /** 无需调网关 API 的网关（原路回补 / 线下处理；空网关同此语义）。 */
    const NON_API_GATEWAYS = array('balance', 'credit', 'cod', 'manual');

    /* ==================== 用户申请售后 ==================== */

    /**
     * 用户申请售后（不改订单状态机）。
     *
     * 校验：订单存在 + 状态 ∈ REFUNDABLE_STATUSES + 无 pending 申请 + 原因非空。
     * 通过后追加申请记录（环形 20 条）、触发 moonlight_refund_requested 钩子、
     * wp_mail 通知管理员（含订单号 / 用户 / 原因）。
     *
     * @param int    $order_id
     * @param int    $user_id  申请人（属主校验由调用方完成）
     * @param string $reason   售后原因
     * @return true|WP_Error
     */
    public static function apply($order_id, $user_id, $reason)
    {
        $order_id = (int) $order_id;
        $user_id  = (int) $user_id;
        $reason   = trim(sanitize_textarea_field((string) $reason));
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order') {
            return new WP_Error('moonlight_refund_order', __('订单不存在。', 'moonlight-shop'));
        }
        if ($user_id <= 0) {
            return new WP_Error('moonlight_refund_user', __('用户无效。', 'moonlight-shop'));
        }
        if ('' === $reason) {
            return new WP_Error('moonlight_refund_reason', __('请填写售后原因。', 'moonlight-shop'));
        }
        $status = MLSHOP_Order::get_status($order_id);
        if (!in_array($status, self::REFUNDABLE_STATUSES, true)) {
            return new WP_Error(
                'moonlight_refund_status',
                sprintf(__('当前订单状态（%s）不支持申请售后。', 'moonlight-shop'), MLSHOP_Order::get_status_label($status))
            );
        }
        $requests = self::requests($order_id);
        foreach ($requests as $r) {
            if (is_array($r) && isset($r['status']) && 'pending' === $r['status']) {
                return new WP_Error('moonlight_refund_pending', __('已存在待处理的售后申请，请耐心等待。', 'moonlight-shop'));
            }
        }

        $requests[] = array(
            'user_id' => $user_id,
            'reason'  => $reason,
            'at'      => current_time('mysql'),
            'status'  => 'pending',
        );
        update_post_meta($order_id, self::META_REQUESTS, array_slice($requests, -self::REQUESTS_MAX));

        do_action('moonlight_refund_requested', $order_id, $user_id, $reason);
        self::notify_admin($order_id, $user_id, $reason);
        return true;
    }

    /**
     * 邮件通知管理员（含订单号 / 用户 / 原因；admin_email 未配置时静默跳过）。
     */
    private static function notify_admin($order_id, $user_id, $reason)
    {
        $to = get_option('admin_email');
        if (empty($to) || !is_string($to)) {
            return;
        }
        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        if ('' === $order_no) {
            $order_no = (string) get_the_title($order_id);
        }
        $user      = $user_id ? get_userdata($user_id) : null;
        $user_line = $user
            ? sprintf('%s（%s）#%d', $user->display_name, $user->user_email, $user->ID)
            : sprintf('#%d', $user_id);
        $subject = sprintf(__('[%1$s] 新售后申请：订单 %2$s', 'moonlight-shop'), get_bloginfo('name'), $order_no);
        $body = sprintf(
            __("收到新的售后申请：\n\n订单：%1\$s\n用户：%2\$s\n原因：%3\$s\n\n请进入后台订单编辑页处理。", 'moonlight-shop'),
            $order_no,
            $user_line,
            $reason
        );
        wp_mail($to, $subject, $body);
    }

    /* ==================== 规则闸 ==================== */

    /**
     * 退款规则闸：true = 允许，WP_Error = 拒绝（消息为全部拒绝原因拼接）。
     *
     * 每条规则（virtual 下载 / cardkey 交付 / physical 发货单 / 授予窗口 / recharge
     * 已消费）最终统一交给 moonlight_refund_allowed 过滤器裁决——过滤器返回 true
     * 可强行放行（如后台配置允许「已下载退款」）。过滤器签名：
     *   apply_filters('moonlight_refund_allowed', $allowed, $order_id, $items, $deny_reasons)
     */
    public static function can_refund($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order') {
            return new WP_Error('moonlight_refund_order', __('订单不存在。', 'moonlight-shop'));
        }
        $status = MLSHOP_Order::get_status($order_id);
        if (!in_array($status, self::REFUNDABLE_STATUSES, true)) {
            return new WP_Error(
                'moonlight_refund_status',
                sprintf(__('当前订单状态（%s）不支持退款。', 'moonlight-shop'), MLSHOP_Order::get_status_label($status))
            );
        }
        $total    = (float) get_post_meta($order_id, '_mlshop_total', true);
        $refunded = (float) get_post_meta($order_id, self::META_REFUNDED_TOTAL, true);
        if ($total > 0 && $refunded >= $total - 0.001) {
            return new WP_Error('moonlight_refund_done', __('订单已全额退款。', 'moonlight-shop'));
        }

        $items = get_post_meta($order_id, '_mlshop_items', true);
        $items = is_array($items) ? $items : array();
        $deny  = array();

        // 订单级事实一次读取：下载日志 / 交付记录 / 发货单。
        $dl_log = get_post_meta($order_id, '_mlshop_download_log', true);
        $has_download = is_array($dl_log) && !empty($dl_log);
        $delivery = get_post_meta($order_id, '_mlshop_delivery', true);
        $delivery = is_array($delivery) ? $delivery : array();
        $has_shipment = false;
        if (class_exists('MLSHOP_Shipping')) {
            foreach (MLSHOP_Shipping::get_shipments($order_id) as $s) {
                $has_shipment = true;
                break;
            }
        }

        foreach ($items as $it) {
            $pid = isset($it['id']) ? (int) $it['id'] : 0;
            if ($pid <= 0) {
                continue; // 充值 / 会员等伪商品条目无类型规则
            }
            $type = (string) get_post_meta($pid, '_mlshop_type', true);
            if ('virtual' === $type) {
                // 虚拟商品：订单存在任何下载记录 → 拒绝（后台可经过滤器放开）
                if ($has_download) {
                    $deny['download'] = __('虚拟商品已产生下载记录', 'moonlight-shop');
                }
            } elseif ('cardkey' === $type) {
                // 卡密商品：交付记录或卡密售出行（_mlshop_card_s 行 o=订单）任一命中即拒绝
                if (!isset($deny['cardkey'])) {
                    foreach ($delivery as $d) {
                        if (is_array($d) && isset($d['type']) && 'cardkey' === $d['type'] && (int) ($d['product_id'] ?? 0) === $pid) {
                            $deny['cardkey'] = __('卡密已交付', 'moonlight-shop');
                            break;
                        }
                    }
                }
                if (!isset($deny['cardkey']) && !empty(static::find_card_sold_rows($pid, $order_id))) {
                    $deny['cardkey'] = __('卡密已交付', 'moonlight-shop');
                }
            } elseif (('physical' === $type || '' === $type) && $has_shipment) {
                // 实物（未设类型视为实物）：存在发货单 → 拒绝（走售后协商）
                $deny['shipment'] = __('实物已发货，请走售后协商', 'moonlight-shop');
            }
        }

        // 会员 / 付费墙：已授予且超过授予窗口（默认 24h）→ 拒绝；窗口内允许
        $type_meta = (string) get_post_meta($order_id, '_mlshop_type', true);
        if ('membership' === $type_meta || get_post_meta($order_id, '_mlshop_paywall_post', true)) {
            $granted_at = self::grant_time($order_id);
            if ($granted_at > 0) {
                $window = (int) apply_filters('moonlight_refund_grant_window_hours', 24, $order_id);
                if ($window <= 0 || (current_time('timestamp') - $granted_at) > $window * HOUR_IN_SECONDS) {
                    $deny['granted'] = __('会员/付费内容已授予超过退款时限', 'moonlight-shop');
                }
            }
        }

        // 充值订单：已入账且回收时积分不足（_mlshop_recharge_revoke_short 留痕 = 已消费）→ 拒绝
        if ('recharge' === $type_meta
            && get_post_meta($order_id, '_mlshop_recharge_granted', true)
            && get_post_meta($order_id, '_mlshop_recharge_revoke_short', true)) {
            $deny['recharge'] = __('充值积分已消费，无法退款', 'moonlight-shop');
        }

        $deny = array_values(array_unique($deny));

        /**
         * 规则闸覆盖过滤器：收到 (允许?, 订单ID, 订单商品, 拒绝原因列表)。
         *
         * @param bool  $allowed      默认裁决（无拒绝原因 = true）
         * @param int   $order_id     订单 ID
         * @param array $items        订单商品
         * @param array $deny_reasons 拒绝原因列表（空数组 = 默认允许）
         */
        $allowed = apply_filters('moonlight_refund_allowed', empty($deny), $order_id, $items, $deny);
        if ($allowed) {
            return true;
        }
        return new WP_Error(
            'moonlight_refund_denied',
            $deny ? implode('；', $deny) : __('该订单不符合自助退款条件。', 'moonlight-shop')
        );
    }

    /**
     * 卡密售出行查询：该商品批次（mlshop_card_batch, post_parent=商品）下
     * `_mlshop_card_s` 行、JSON 记录 o=订单 ID 的行。protected static 以便
     * 单元测试子类化（与 Moonlight_Card_Stock 的数据访问原语同一模式）。
     *
     * 售出行 JSON 字段序固定为 {"seq",...,"o":<id>,"u":...}，以 '"o":<id>,'
     * （尾随逗号防前缀误命中）做 LIKE 精确匹配；h/e/iv 均为 hex/base64，不可能
     * 含引号冒号，无误报面。
     *
     * @param int $product_id
     * @param int $order_id
     * @return array[] 命中行（非空即视为已发卡）
     */
    protected static function find_card_sold_rows($product_id, $order_id)
    {
        global $wpdb;
        $product_id = (int) $product_id;
        $order_id   = (int) $order_id;
        if ($product_id <= 0 || $order_id <= 0 || !class_exists('Moonlight_Card_Stock')) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_id FROM {$wpdb->postmeta} AS pm
             INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_parent = %d
               AND pm.meta_key = %s
               AND pm.meta_value LIKE %s",
            Moonlight_Card_Stock::CPT,
            $product_id,
            Moonlight_Card_Stock::ST_SOLD,
            '%"o":' . $order_id . ',%'
        ));
        return is_array($rows) ? $rows : array();
    }

    /**
     * 会员 / 付费墙授予时间戳：授予标记 → _mlshop_paid_time 依次取第一个可解析值。
     * `_mlshop_membership_granted` 历史数据可能存 '1'（迁移脚本），strtotime 失败自动跳过。
     */
    private static function grant_time($order_id)
    {
        foreach (array('_mlshop_membership_granted', '_mlshop_paywall_granted', '_mlshop_paid_time') as $key) {
            $v = (string) get_post_meta($order_id, $key, true);
            if ('' === $v) {
                continue;
            }
            $ts = strtotime($v);
            if ($ts) {
                return $ts;
            }
        }
        return 0;
    }

    /* ==================== 管理员执行退款 ==================== */

    /**
     * 执行退款（管理员；capability manage_options 由调用方校验）。
     *
     * 金额语义：
     *  - $amount = 0 或 ≥ 订单总额 → 全额退款：网关退款成功 → mark_refunded()
     *    （状态机自动：库存回滚 / 余额回补 / 优惠券释放 / 卡密不回滚）→ 申请标 approved；
     *  - 0 < $amount < 总额 → 部分退款：网关退款 amount → refunded_total 原子累加
     *    （读 + 条件写）→ 状态不变；累计达总额自动走全额收尾；
     *  - $skip_gateway = true（「仅标记」）：跳过网关直接走状态机，适用于已在
     *    网关后台手动退款的场景。
     *
     * 网关退款失败：返回 WP_Error 且不改订单状态（提示人工到网关后台处理后可再点「仅标记」）。
     * 幂等：_mlshop_refund_processed_total 与 refunded_total 分开记录；
     * 同一秒内「同额 + 同因 + 同操作者」的重复提交直接拒绝（双击 / 重放防抖）。
     *
     * @param int    $order_id
     * @param float  $amount       退款金额（0 = 全额）
     * @param string $reason       退款原因
     * @param int    $actor_id     操作管理员 ID
     * @param bool   $skip_gateway true = 仅标记（不调网关）
     * @return true|WP_Error
     */
    public static function process($order_id, $amount, $reason = '', $actor_id = 0, $skip_gateway = false)
    {
        $order_id = (int) $order_id;
        $actor_id = (int) $actor_id;
        $reason   = trim(sanitize_textarea_field((string) $reason));
        $amount   = (float) $amount;
        if ($order_id <= 0 || get_post_type($order_id) !== 'mlshop_order') {
            return new WP_Error('moonlight_refund_order', __('订单不存在。', 'moonlight-shop'));
        }
        $status = MLSHOP_Order::get_status($order_id);
        if (!in_array($status, self::REFUNDABLE_STATUSES, true)) {
            return new WP_Error(
                'moonlight_refund_status',
                sprintf(__('当前订单状态（%s）不支持退款。', 'moonlight-shop'), MLSHOP_Order::get_status_label($status))
            );
        }
        $total = (float) get_post_meta($order_id, '_mlshop_total', true);
        if ($total <= 0) {
            return new WP_Error('moonlight_refund_amount', __('订单金额无效，无法退款。', 'moonlight-shop'));
        }
        if ((float) get_post_meta($order_id, self::META_REFUNDED_TOTAL, true) >= $total - 0.001) {
            return new WP_Error('moonlight_refund_done', __('订单已全额退款。', 'moonlight-shop'));
        }
        if ($amount < 0) {
            return new WP_Error('moonlight_refund_amount', __('退款金额无效。', 'moonlight-shop'));
        }

        $is_full   = ($amount <= 0 || $amount >= $total);
        $effective = $is_full ? $total : $amount;
        // 部分退款金额截断（审计 F2）：累计退款不得超过订单总额，防止超额出账。
        $refunded_so_far = (float) get_post_meta($order_id, self::META_REFUNDED_TOTAL, true);
        if (!$is_full) {
            $effective = min($effective, $total - $refunded_so_far);
            if ($effective <= 0.001) {
                return new WP_Error('moonlight_refund_amount', __('可退金额不足（累计退款已接近订单总额）。', 'moonlight-shop'));
            }
        }

        // 防抖：同一秒内同额 + 同因 + 同操作者 = 同一请求重复提交，拒绝
        $log  = self::refund_log($order_id);
        $last = $log ? end($log) : null;
        if ($last
            && isset($last['at'], $last['amount'], $last['reason'])
            && (string) $last['at'] === (string) current_time('mysql')
            && abs((float) $last['amount'] - $effective) < 0.001
            && (string) $last['reason'] === $reason
            && (!isset($last['actor']) || (int) $last['actor'] === $actor_id)) {
            return new WP_Error('moonlight_refund_duplicate', __('退款请求重复，请勿重复提交。', 'moonlight-shop'));
        }

        // 网关联动：仅标记跳过；balance/credit/cod/manual 原路回补型网关在
        // gateway_refund 内返回 skipped（余额全额退款的钱包回补由状态机负责，不重复调 API）。
        if ($skip_gateway) {
            $gw = array('success' => true, 'skipped' => true, 'refund_id' => '', 'message' => __('已跳过网关（仅标记退款）。', 'moonlight-shop'));
        } else {
            $gw = static::gateway_refund($order_id, $is_full ? 0.0 : $effective, $reason);
            if (empty($gw['success'])) {
                $msg = (isset($gw['message']) && '' !== (string) $gw['message'])
                    ? (string) $gw['message']
                    : __('网关退款失败。', 'moonlight-shop');
                return new WP_Error(
                    'moonlight_refund_gateway',
                    $msg . __('（可先到网关后台人工退款，再用「仅标记」完成收尾。）', 'moonlight-shop')
                );
            }
        }
        $refund_id = isset($gw['refund_id']) ? (string) $gw['refund_id'] : '';

        if ($is_full) {
            // 全额退款：状态机收尾（非法流转会被 set_status 拒绝，此处状态已预检）
            $res = MLSHOP_Order::mark_refunded($order_id);
            if (is_wp_error($res)) {
                return $res;
            }
            static::accumulate_meta($order_id, self::META_REFUNDED_TOTAL, 0, $total);
            static::accumulate_meta($order_id, self::META_PROCESSED_TOTAL, $effective);
            static::append_log($order_id, array(
                'at'                => current_time('mysql'),
                'actor'             => $actor_id,
                'amount'            => $effective,
                'gateway_refund_id' => $refund_id,
                'reason'            => $reason,
                'partial'           => false,
            ));
            static::mark_requests($order_id, 'approved');
            do_action('moonlight_refund_processed', $order_id, $effective, true);
            return true;
        }

        // 部分退款：状态不变，金额原子累加
        $new_total = static::accumulate_meta($order_id, self::META_REFUNDED_TOTAL, $effective);
        static::accumulate_meta($order_id, self::META_PROCESSED_TOTAL, $effective);
        static::append_log($order_id, array(
            'at'                => current_time('mysql'),
            'actor'             => $actor_id,
            'amount'            => $effective,
            'gateway_refund_id' => $refund_id,
            'reason'            => $reason,
            'partial'           => true,
        ));
        if ($new_total >= $total - 0.001) {
            // 累计达总额：自动走全额收尾（状态 refunded + 现有回滚机制）
            $res = MLSHOP_Order::mark_refunded($order_id);
            if (is_wp_error($res)) {
                return $res;
            }
            static::accumulate_meta($order_id, self::META_REFUNDED_TOTAL, 0, $total);
            static::mark_requests($order_id, 'approved');
        }
        do_action('moonlight_refund_processed', $order_id, $effective, true);
        return true;
    }

    /* ==================== 网关分发 ==================== */

    /**
     * 网关退款分发器：按 _mlshop_payment_gateway（缺失回退 _mlshop_gateway）分派。
     *
     *  - alipay / stripe / paypal → 网关 refund($order_id, $amount, $reason)，
     *    契约统一为 array{success:bool, refund_id?:string, message:string}；
     *    $amount = 0 表示全额（网关侧省略金额参数）；
     *  - balance / credit / cod / manual（及空网关）→ 返回 skipped=true，
     *    原路回补由订单状态机（maybe_reverse_funds）或线下流程负责；
     *  - 第三方网关：经支付管理器取实例，实现 refund() 即可参与退款。
     *
     * @param int    $order_id
     * @param float  $amount  0 = 全额
     * @param string $reason
     * @return array{success:bool, refund_id?:string, message:string, skipped?:bool}
     */
    public static function gateway_refund($order_id, $amount, $reason = '')
    {
        $order_id = (int) $order_id;
        $amount   = (float) $amount;
        $gateway_id = strtolower(trim((string) get_post_meta($order_id, '_mlshop_payment_gateway', true)));
        if ('' === $gateway_id) {
            $gateway_id = strtolower(trim((string) get_post_meta($order_id, '_mlshop_gateway', true)));
        }
        if ('' === $gateway_id || in_array($gateway_id, self::NON_API_GATEWAYS, true)) {
            return array(
                'success' => true,
                'skipped' => true,
                'refund_id' => '',
                'message' => __('该网关无需调用退款接口（原路回补 / 线下处理）。', 'moonlight-shop'),
            );
        }

        $gateway = null;
        switch ($gateway_id) {
            case 'stripe':
                if (class_exists('MLSHOP_Gateway_Stripe')) {
                    $gateway = new MLSHOP_Gateway_Stripe();
                }
                break;
            case 'paypal':
                if (class_exists('MLSHOP_Gateway_PayPal')) {
                    $gateway = new MLSHOP_Gateway_PayPal();
                }
                break;
            case 'alipay':
                if (class_exists('MLSHOP_Gateway_Alipay')) {
                    $gateway = new MLSHOP_Gateway_Alipay();
                }
                break;
            default:
                // 第三方网关扩展点：实现 refund() 即参与统一退款
                if (class_exists('MLSHOP_Payment')) {
                    $g = MLSHOP_Payment::get_instance()->get_gateway($gateway_id);
                    if ($g && method_exists($g, 'refund')) {
                        $gateway = $g;
                    }
                }
                break;
        }

        if (!$gateway || !method_exists($gateway, 'refund')) {
            return array(
                'success' => false,
                'refund_id' => '',
                'message' => sprintf(__('网关 %s 不支持在线退款，请人工到网关后台处理。', 'moonlight-shop'), $gateway_id),
            );
        }
        return (array) $gateway->refund($order_id, $amount, (string) $reason);
    }

    /* ==================== 读取接口（UI 用） ==================== */

    /**
     * 售后申请列表（环形 20 条，越新越靠后）。
     *
     * @param int $order_id
     * @return array[] 每条 {user_id, reason, at, status}
     */
    public static function requests($order_id)
    {
        $reqs = get_post_meta((int) $order_id, self::META_REQUESTS, true);
        return is_array($reqs) ? $reqs : array();
    }

    /**
     * 退款日志（环形 50 条，越新越靠后）。
     *
     * @param int $order_id
     * @return array[] 每条 {at, actor, amount, gateway_refund_id, reason, partial}
     */
    public static function refund_log($order_id)
    {
        $log = get_post_meta((int) $order_id, self::META_REFUND_LOG, true);
        return is_array($log) ? $log : array();
    }

    /**
     * 已退款累计金额。
     *
     * @param int $order_id
     * @return float
     */
    public static function refunded_total($order_id)
    {
        return (float) get_post_meta((int) $order_id, self::META_REFUNDED_TOTAL, true);
    }

    /* ==================== 内部：台账与留痕 ==================== */

    /**
     * 金额台账原子累加（读 + 条件写）：meta 值仍等于读到的旧值时才写入
     * （复用 mlshop_cas_post_meta），并发退款不丢累计量；meta 无行时直接落初值
     * （postmeta 无唯一索引，缺失即新建）。极端并发 CAS 连续失败时重读直写兜底。
     *
     * @param int         $order_id
     * @param string      $meta_key
     * @param float       $delta  增量
     * @param float|null  $exact  非空时直接收敛为该精确值（全额收尾用）
     * @return float 写入后的值
     */
    private static function accumulate_meta($order_id, $meta_key, $delta, $exact = null)
    {
        for ($i = 0; $i < 6; $i++) {
            $raw = get_post_meta($order_id, $meta_key, true);
            $cur = ('' === (string) $raw) ? 0.0 : (float) $raw;
            $new = (null !== $exact) ? (float) $exact : round($cur + $delta, 2);
            $val = number_format($new, 2, '.', '');
            if ('' === (string) $raw) {
                if ('' === (string) get_post_meta($order_id, $meta_key, true)) {
                    update_post_meta($order_id, $meta_key, $val);
                    return $new;
                }
                continue; // 期间被并发写入，重读走 CAS
            }
            if (mlshop_cas_post_meta($order_id, $meta_key, (string) $raw, $val)) {
                return $new;
            }
        }
        // 兜底：CAS 连续失败（极端并发），重读后直写，保证累计量不丢
        $raw = get_post_meta($order_id, $meta_key, true);
        $cur = ('' === (string) $raw) ? 0.0 : (float) $raw;
        $new = (null !== $exact) ? (float) $exact : round($cur + $delta, 2);
        update_post_meta($order_id, $meta_key, number_format($new, 2, '.', ''));
        return $new;
    }

    /**
     * 追加一条退款日志（环形 50 条）。
     */
    private static function append_log($order_id, array $entry)
    {
        $log = self::refund_log($order_id);
        $log[] = $entry;
        update_post_meta($order_id, self::META_REFUND_LOG, array_slice($log, -self::LOG_MAX));
    }

    /**
     * 把全部 pending 售后申请标记为目标状态（approved）。
     *
     * @param int    $order_id
     * @param string $status approved|rejected
     * @return bool 是否有变更
     */
    private static function mark_requests($order_id, $status)
    {
        $requests = self::requests($order_id);
        $changed  = false;
        foreach ($requests as $i => $r) {
            if (is_array($r) && isset($r['status']) && 'pending' === $r['status']) {
                $requests[$i]['status'] = (string) $status;
                $changed = true;
            }
        }
        if ($changed) {
            update_post_meta($order_id, self::META_REQUESTS, array_slice($requests, -self::REQUESTS_MAX));
        }
        return $changed;
    }
}
