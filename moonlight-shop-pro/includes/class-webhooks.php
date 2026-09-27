<?php
/**
 * 出站 Webhook：事件订阅 / HMAC 签名投递 / 失败队列退避重试 / 环形日志。
 *
 * - 存储：option `mlpro_webhook_endpoints`（id => ['id','url','secret','events'=>[],'enabled']）、
 *   队列 `mlpro_webhook_queue`、投递日志 `mlpro_webhook_log`（≤100 条环形）。
 * - 事件：order.created / order.paid / order.awaiting_shipment / order.shipped /
 *   order.delivered / order.completed / order.cancelled / order.refunded /
 *   refund.processed / order.failed（Free mark_failed → payment.failed 的语义并入 order.failed）。
 *   实际挂载钩子（见 register_event_hooks）：mlshop_order_created（预留，Free 现版本未触发）、
 *   added/updated_post_meta（meta key = _mlshop_created，现行 Free 建单的同步检测点）、
 *   mlshop_order_<status>（8 个状态）、moonlight_refund_processed。
 * - 签名：header `X-Moonlight-Signature: t=<unix秒>,v1=<hash_hmac('sha256', t.'.'.body, secret)>`。
 * - 重试：立即投递失败（WP_Error 或非 2xx）入队；cron `mlpro_webhook_retry`（5 分钟）重试，
 *   退避 5/15/60 分钟；attempts ≥ 5 丢弃并 do_action('mlpro_webhook_dropped', $item)。
 * - Payload 绝不包含卡密明文 / 密钥 / 收货地址等敏感数据（客户 email 允许）。
 *
 * 可独立测试的纯逻辑抽成静态方法：sign_payload / format_signature /
 * next_delay / should_drop / build_queue_item / endpoint_wants / push_capped。
 *
 * @package Moonlight_Shop_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLPRO_Webhooks
{
    const OPT_ENDPOINTS = 'mlpro_webhook_endpoints';
    const OPT_QUEUE     = 'mlpro_webhook_queue';
    const OPT_LOG       = 'mlpro_webhook_log';

    /** 重试 cron 钩子名（调度注册/反注册照抄 Free class-activator 模式）。 */
    const CRON_HOOK = 'mlpro_webhook_retry';

    /** 最大重试次数（attempts ≥ 5 丢弃）。 */
    const MAX_ATTEMPTS = 5;
    /** 退避序列（秒）：attempts 0→5min、1→15min、2+→60min。 */
    const RETRY_DELAYS = array(300, 900, 3600);
    /** 投递超时（秒）。 */
    const TIMEOUT = 10;
    /** 环形日志上限。 */
    const MAX_LOG = 100;
    /** 队列长度上限（防端点永久宕机时无限膨胀；超出丢最旧）。 */
    const MAX_QUEUE = 500;

    /** 可订阅事件白名单。 */
    const EVENTS = array(
        'order.created',
        'order.paid',
        'order.awaiting_shipment',
        'order.shipped',
        'order.delivered',
        'order.completed',
        'order.cancelled',
        'order.refunded',
        'refund.processed',
        'order.failed',
    );

    /**
     * Free 订单状态机 → Webhook 事件（mlshop_order_<status> 钩子逐个挂载）。
     * failed：mark_failed 的支付失败语义并入 order.failed。
     */
    const STATUS_EVENTS = array(
        'paid'              => 'order.paid',
        'awaiting_shipment' => 'order.awaiting_shipment',
        'shipped'           => 'order.shipped',
        'delivered'         => 'order.delivered',
        'completed'         => 'order.completed',
        'cancelled'         => 'order.cancelled',
        'refunded'          => 'order.refunded',
        'failed'            => 'order.failed',
    );

    private static $instance = null;

    /** @var array 请求内 order.created 去重（建单双检测点共用） */
    private static $created_sent = array();

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->register_event_hooks();

        add_action(self::CRON_HOOK, array($this, 'process_queue'));
        add_action('admin_init', array($this, 'maybe_reschedule'));

        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_post_mlpro_webhook_save', array($this, 'handle_save'));
        add_action('admin_post_mlpro_webhook_delete', array($this, 'handle_delete'));
        add_action('admin_notices', array($this, 'render_notice'));
    }

    /* ==================== 调度（照抄 Free class-activator 模式） ==================== */

    /**
     * cron 自定义间隔（文件作用域注册，激活请求内可用）。
     */
    public static function cron_schedules($schedules)
    {
        if (!isset($schedules['mlpro_5min'])) {
            $schedules['mlpro_5min'] = array(
                'interval' => 300,
                'display'  => __('每 5 分钟（Moonlight Pro Webhook 重试）', 'moonlight-shop-pro'),
            );
        }
        return $schedules;
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'mlpro_5min', self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * 自愈：调度被外力清空时补注册（模式同 Free MLSHOP_Shipping::maybe_schedule_sync）。
     */
    public function maybe_reschedule()
    {
        if (wp_doing_ajax()) {
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'mlpro_5min', self::CRON_HOOK);
        }
    }

    /* ==================== 事件挂载 ==================== */

    private function register_event_hooks()
    {
        // order.created（预留）：Free 当前版本不触发该钩子；将来 Free 加上即自动生效。
        add_action('mlshop_order_created', array($this, 'on_order_created'), 10, 1);

        // 现行 Free 建单同步检测：create_from_cart / create_for_paywall / create_recharge /
        // create_membership 均在写完 items/total/gateway/status 之后最后写入
        // `_mlshop_created` meta，首次落库触发 added_post_meta —— 此时 payload 数据完备。
        // （updated 分支只为兜底异常流程，去重保证同一订单只发一次。）
        add_action('added_post_meta', array($this, 'on_order_created_meta'), 10, 4);
        add_action('updated_post_meta', array($this, 'on_order_created_meta'), 10, 4);

        // 状态机事件：set_status 先写 _mlshop_status 再触发 mlshop_order_<status>，
        // 因此 handler 内读到的状态是最新值。
        foreach (self::STATUS_EVENTS as $status => $event) {
            add_action('mlshop_order_' . $status, function ($order_id) use ($event) {
                $this->dispatch($event, (int) $order_id);
            }, 10, 1);
        }

        // 售后退款（Refund_Service 全额 / 部分退款收尾时触发）：
        // moonlight_refund_processed($order_id, $effective_amount, $flag)。
        add_action('moonlight_refund_processed', array($this, 'on_refund_processed'), 10, 2);
    }

    /**
     * 建单检测（added/updated_post_meta 路径）：只认 _mlshop_created。
     */
    public function on_order_created_meta($meta_id, $post_id, $meta_key, $meta_value)
    {
        if ('_mlshop_created' !== (string) $meta_key) {
            return;
        }
        if (get_post_type((int) $post_id) !== 'mlshop_order') {
            return;
        }
        $this->on_order_created((int) $post_id);
    }

    /**
     * order.created 入口（两个检测点共用，按订单去重）。
     */
    public function on_order_created($order_id)
    {
        $order_id = (int) $order_id;
        if (isset(self::$created_sent[$order_id])) {
            return;
        }
        self::$created_sent[$order_id] = true;
        $this->dispatch('order.created', $order_id);
    }

    /**
     * refund.processed：data 附 amount（本次实际退款金额）。
     */
    public function on_refund_processed($order_id, $effective_amount)
    {
        $this->dispatch('refund.processed', (int) $order_id, array(
            'amount' => round((float) $effective_amount, 2),
        ));
    }

    /* ==================== 投递管线 ==================== */

    /**
     * 向所有订阅了 $event 的启用端点投递；失败入队等待 cron 重试。
     *
     * @param string $event    事件名（EVENTS 白名单内）。
     * @param int    $order_id 订单 ID。
     * @param array  $extra    附加 data 字段（如退款 amount）。
     * @return bool 是否有端点被投递（含入队）。
     */
    public function dispatch($event, $order_id, array $extra = array())
    {
        $event = (string) $event;
        if ('' === $event || !in_array($event, self::EVENTS, true)) {
            return false;
        }
        $targets = array();
        foreach (self::get_endpoints() as $endpoint) {
            if (self::endpoint_wants($endpoint, $event)) {
                $targets[] = $endpoint;
            }
        }
        if (!$targets) {
            return false;
        }
        $body = self::build_payload($event, self::build_order_data((int) $order_id, $extra));
        foreach ($targets as $endpoint) {
            $res = self::deliver($endpoint, $event, $body);
            if (empty($res['success'])) {
                self::enqueue($endpoint['id'], $event, $body);
            }
        }
        return true;
    }

    /**
     * 失败入队（attempts 从 0 起，首次重试在 5 分钟后）。
     */
    private static function enqueue($endpoint_id, $event, $body)
    {
        $queue = get_option(self::OPT_QUEUE, array());
        if (!is_array($queue)) {
            $queue = array();
        }
        $queue = self::push_capped($queue, self::build_queue_item($endpoint_id, $event, $body, 0), self::MAX_QUEUE);
        update_option(self::OPT_QUEUE, $queue);
    }

    /**
     * cron mlpro_webhook_retry：处理到期待重队列。
     */
    public function process_queue()
    {
        $queue = get_option(self::OPT_QUEUE, array());
        if (!is_array($queue) || !$queue) {
            return;
        }
        $endpoints = self::get_endpoints();
        $now       = time();
        $remaining = array();
        $changed   = false;

        foreach ($queue as $item) {
            if (!is_array($item) || (int) $item['next_try'] > $now) {
                $remaining[] = $item;
                continue;
            }
            // 端点已删除 / 取消订阅该事件：队列项直接作废（不再补发）。
            if (!isset($endpoints[$item['endpoint_id']])
                || !self::endpoint_wants($endpoints[$item['endpoint_id']], $item['event'])) {
                $changed = true;
                continue;
            }
            $res = self::deliver($endpoints[$item['endpoint_id']], $item['event'], $item['body']);
            if (!empty($res['success'])) {
                $changed = true; // 成功：出队
                continue;
            }
            $item['attempts'] = (int) $item['attempts'] + 1;
            if (self::should_drop($item['attempts'])) {
                /**
                 * Webhook 重试达到上限被丢弃（运维可监听此钩子告警）。
                 *
                 * @param array $item {endpoint_id, event, body, attempts, next_try}
                 */
                do_action('mlpro_webhook_dropped', $item);
                $changed = true;
                continue;
            }
            $item['next_try'] = time() + self::next_delay($item['attempts']);
            $remaining[]      = $item;
            $changed          = true;
        }

        if ($changed) {
            update_option(self::OPT_QUEUE, $remaining);
        }
    }

    /* ==================== 纯逻辑（静态，供单测） ==================== */

    /**
     * HMAC 签名：hash_hmac('sha256', t.'.'.body, secret)。
     *
     * @param string   $body      原始 JSON body。
     * @param string   $secret    端点 secret。
     * @param int|null $timestamp 签名时间戳（null = 当前时间；测试传固定值）。
     * @return array array('t' => int, 'v1' => string)
     */
    public static function sign_payload($body, $secret, $timestamp = null)
    {
        $t = (null === $timestamp) ? time() : (int) $timestamp;
        return array(
            't'  => $t,
            'v1' => hash_hmac('sha256', $t . '.' . (string) $body, (string) $secret),
        );
    }

    /**
     * 签名 header 值：`t=<unix秒>,v1=<hex>`。
     */
    public static function format_signature($t, $v1)
    {
        return 't=' . (int) $t . ',v1=' . (string) $v1;
    }

    /**
     * 退避时长（秒）：attempts 0→300、1→900、2+→3600。
     *
     * @param int $attempts 已失败次数（队列项当前 attempts）。
     * @return int
     */
    public static function next_delay($attempts)
    {
        $attempts = max(0, (int) $attempts);
        $i = min($attempts, count(self::RETRY_DELAYS) - 1);
        return (int) self::RETRY_DELAYS[$i];
    }

    /**
     * 是否达到丢弃阈值（attempts ≥ 5）。
     */
    public static function should_drop($attempts)
    {
        return (int) $attempts >= self::MAX_ATTEMPTS;
    }

    /**
     * 构造队列项：next_try = $now + next_delay($attempts)。
     *
     * @param string   $endpoint_id 端点 ID。
     * @param string   $event       事件名。
     * @param string   $body        原始 JSON body。
     * @param int      $attempts    已失败次数。
     * @param int|null $now         当前时间戳（null = time()；测试传固定值）。
     * @return array {endpoint_id, event, body, attempts, next_try}
     */
    public static function build_queue_item($endpoint_id, $event, $body, $attempts = 0, $now = null)
    {
        $attempts = max(0, (int) $attempts);
        $now = (null === $now) ? time() : (int) $now;
        return array(
            'endpoint_id' => (string) $endpoint_id,
            'event'       => (string) $event,
            'body'        => (string) $body,
            'attempts'    => $attempts,
            'next_try'    => $now + self::next_delay($attempts),
        );
    }

    /**
     * 端点是否接收某事件（未启用 / 未订阅 → false）。
     *
     * @param array  $endpoint 规范化端点。
     * @param string $event    事件名。
     * @return bool
     */
    public static function endpoint_wants(array $endpoint, $event)
    {
        if (empty($endpoint['enabled'])) {
            return false;
        }
        $events = isset($endpoint['events']) && is_array($endpoint['events'])
            ? array_map('strval', $endpoint['events'])
            : array();
        return in_array((string) $event, $events, true);
    }

    /**
     * 追加并封顶（环形）：超出 $max 丢最旧。日志 / 队列共用。
     */
    public static function push_capped(array $list, array $item, $max)
    {
        $list[] = $item;
        $max = max(1, (int) $max);
        if (count($list) > $max) {
            $list = array_slice($list, -$max);
        }
        return $list;
    }

    /* ==================== Payload ==================== */

    /**
     * 订单摘要（不含卡密明文 / 密钥 / 收货地址；客户 email 允许）。
     *
     * @param int   $order_id
     * @param array $extra 附加字段（如退款 amount）。
     * @return array
     */
    public static function build_order_data($order_id, array $extra = array())
    {
        $order_id = (int) $order_id;
        $items = get_post_meta($order_id, '_mlshop_items', true);
        $out_items = array();
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $pid = isset($it['id']) ? (int) $it['id'] : 0;
                $title = isset($it['title']) ? (string) $it['title'] : '';
                if ('' === $title && $pid) {
                    $title = (string) get_the_title($pid);
                }
                $price = isset($it['price']) ? (float) $it['price'] : (isset($it['subtotal']) ? (float) $it['subtotal'] : 0.0);
                $out_items[] = array(
                    'product_id' => $pid,
                    'title'      => $title,
                    'qty'        => isset($it['qty']) ? (int) $it['qty'] : 0,
                    'price'      => $price,
                );
            }
        }

        $gateway = (string) get_post_meta($order_id, '_mlshop_payment_gateway', true);
        if ('' === $gateway) {
            $gateway = (string) get_post_meta($order_id, '_mlshop_gateway', true);
        }

        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $email   = '';
        if ($user_id) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->user_email)) {
                $email = (string) $user->user_email;
            }
        }

        $data = array(
            'order_id'       => $order_id,
            'order_no'       => (string) get_post_meta($order_id, '_mlshop_order_no', true),
            'status'         => (string) (get_post_meta($order_id, '_mlshop_status', true) ?: 'pending'),
            'total'          => round((float) get_post_meta($order_id, '_mlshop_total', true), 2),
            'currency'       => (string) get_post_meta($order_id, '_mlshop_currency', true),
            'gateway'        => $gateway,
            'items'          => $out_items,
            'customer_id'    => $user_id,
            'customer_email' => $email,
        );
        return array_merge($data, $extra);
    }

    /**
     * 完整 payload JSON：{event, site, created_at(iso8601), data}。
     */
    public static function build_payload($event, array $data)
    {
        return (string) wp_json_encode(array(
            'event'      => (string) $event,
            'site'       => home_url(),
            'created_at' => gmdate('c'),
            'data'       => (object) $data,
        ));
    }

    /* ==================== 端点存取 / 投递 / 日志 ==================== */

    /**
     * 全部端点（id => 规范化数组）。
     */
    public static function get_endpoints()
    {
        $raw = get_option(self::OPT_ENDPOINTS, array());
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $key => $ep) {
            if (!is_array($ep)) {
                continue;
            }
            $id = isset($ep['id']) && '' !== (string) $ep['id'] ? (string) $ep['id'] : (string) $key;
            $out[$id] = array(
                'id'      => $id,
                'url'     => isset($ep['url']) ? (string) $ep['url'] : '',
                'secret'  => isset($ep['secret']) ? (string) $ep['secret'] : '',
                'events'  => isset($ep['events']) && is_array($ep['events'])
                    ? array_values(array_map('strval', $ep['events']))
                    : array(),
                'enabled' => !empty($ep['enabled']),
            );
        }
        return $out;
    }

    /**
     * 单次投递：HMAC 签名 + 10s 超时 POST JSON；写环形日志。
     *
     * @return array array('success' => bool, 'code' => int, 'error' => string)
     */
    private static function deliver(array $endpoint, $event, $body)
    {
        $sig = self::sign_payload($body, $endpoint['secret']);
        $response = wp_remote_post($endpoint['url'], array(
            'timeout'     => self::TIMEOUT,
            'blocking'    => true,
            'headers'     => array(
                'Content-Type'          => 'application/json; charset=utf-8',
                'X-Moonlight-Event'     => (string) $event,
                'X-Moonlight-Signature' => self::format_signature($sig['t'], $sig['v1']),
            ),
            'body'        => $body,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            $error = (string) $response->get_error_message();
            self::log_delivery($endpoint['id'], $event, $error, false);
            return array('success' => false, 'code' => 0, 'error' => $error);
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $success = ($code >= 200 && $code < 300);
        self::log_delivery($endpoint['id'], $event, $code, $success);
        return array(
            'success' => $success,
            'code'    => $code,
            'error'   => $success ? '' : ('http_' . $code),
        );
    }

    /**
     * 环形投递日志（≤100 条）：{at, endpoint, event, code|error, ok}。
     */
    public static function log_delivery($endpoint_id, $event, $detail, $ok)
    {
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        $entry = array(
            'at'       => gmdate('c'),
            'endpoint' => (string) $endpoint_id,
            'event'    => (string) $event,
            'ok'       => (bool) $ok,
        );
        if ($ok) {
            $entry['code'] = (int) $detail;
        } else {
            $entry['error'] = (string) $detail;
        }
        update_option(self::OPT_LOG, self::push_capped($log, $entry, self::MAX_LOG));
    }

    /* ==================== 后台：Webhook 设置页 ==================== */

    public function register_menu()
    {
        add_submenu_page(
            'mlpro-license',
            __('Webhook', 'moonlight-shop-pro'),
            __('Webhook', 'moonlight-shop-pro'),
            'manage_options',
            'mlpro-webhooks',
            array($this, 'render_page')
        );
    }

    public function render_notice()
    {
        $notice = get_transient('mlpro_wh_notice_' . get_current_user_id());
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient('mlpro_wh_notice_' . get_current_user_id());
        $class = empty($notice['error']) ? 'notice-success' : 'notice-error';
        printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($notice['message']));
    }

    private static function set_notice($message, $error = false)
    {
        set_transient('mlpro_wh_notice_' . get_current_user_id(), array(
            'message' => $message,
            'error'   => (bool) $error,
        ), 60);
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $endpoints  = self::get_endpoints();
        $edit_id    = isset($_GET['endpoint']) ? sanitize_key(wp_unslash($_GET['endpoint'])) : '';
        $editing    = ('' !== $edit_id && isset($endpoints[$edit_id])) ? $endpoints[$edit_id] : null;
        $queue      = get_option(self::OPT_QUEUE, array());
        $log        = get_option(self::OPT_LOG, array());
        $log        = is_array($log) ? array_slice(array_reverse($log), 0, 20) : array();
        $action_url = admin_url('admin-post.php');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Moonlight Pro — Webhook', 'moonlight-shop-pro'); ?></h1>
            <p class="description">
                <?php esc_html_e('订单生命周期事件将 POST JSON 到启用端点，请求头携带 HMAC-SHA256 签名（X-Moonlight-Signature: t=…,v1=…）。投递失败自动重试（5/15/60 分钟退避，5 次后丢弃）。', 'moonlight-shop-pro'); ?>
            </p>

            <h2><?php echo $editing ? esc_html__('编辑端点', 'moonlight-shop-pro') : esc_html__('添加端点', 'moonlight-shop-pro'); ?></h2>
            <form method="post" action="<?php echo esc_url($action_url); ?>" style="max-width:760px;">
                <?php wp_nonce_field('mlpro_webhook_save'); ?>
                <input type="hidden" name="action" value="mlpro_webhook_save">
                <input type="hidden" name="endpoint_id" value="<?php echo esc_attr($editing ? $editing['id'] : ''); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="mlpro-wh-url"><?php esc_html_e('Payload URL', 'moonlight-shop-pro'); ?></label></th>
                        <td>
                            <input type="url" id="mlpro-wh-url" class="large-text code" name="endpoint_url"
                                   value="<?php echo esc_attr($editing ? $editing['url'] : ''); ?>"
                                   placeholder="https://example.com/hooks/moonlight" required>
                            <p class="description"><?php esc_html_e('仅允许 http/https。', 'moonlight-shop-pro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="mlpro-wh-secret"><?php esc_html_e('Secret', 'moonlight-shop-pro'); ?></label></th>
                        <td>
                            <input type="text" id="mlpro-wh-secret" class="large-text code" name="endpoint_secret"
                                   value="<?php echo esc_attr($editing ? $editing['secret'] : ''); ?>" autocomplete="off">
                            <button type="button" class="button" onclick="(function(b){var a=new Uint8Array(24);if(window.crypto&&window.crypto.getRandomValues){window.crypto.getRandomValues(a);b.value=Array.prototype.map.call(a,function(x){return('0'+x.toString(16)).slice(-2)}).join('')}else{b.value='';for(var i=0;i<48;i++){b.value+='0123456789abcdef'[Math.floor(Math.random()*16)]}}})(document.getElementById('mlpro-wh-secret'))">
                                <?php esc_html_e('随机生成', 'moonlight-shop-pro'); ?>
                            </button>
                            <p class="description"><?php esc_html_e('用于 HMAC 签名，请妥善保管；编辑时留空表示保持不变。', 'moonlight-shop-pro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('订阅事件', 'moonlight-shop-pro'); ?></th>
                        <td>
                            <?php foreach (self::EVENTS as $ev) : ?>
                                <label style="display:inline-block;margin-right:16px;">
                                    <input type="checkbox" name="endpoint_events[]" value="<?php echo esc_attr($ev); ?>"
                                        <?php checked($editing ? in_array($ev, $editing['events'], true) : true, true); ?>>
                                    <code><?php echo esc_html($ev); ?></code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('不勾选任何事件则不会向该端点投递。', 'moonlight-shop-pro'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('启用', 'moonlight-shop-pro'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="endpoint_enabled" value="1" <?php checked($editing ? !empty($editing['enabled']) : true, true); ?>>
                                <?php esc_html_e('启用该端点', 'moonlight-shop-pro'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button($editing ? __('保存修改', 'moonlight-shop-pro') : __('添加端点', 'moonlight-shop-pro')); ?>
                <?php if ($editing) : ?>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=mlpro-webhooks')); ?>"><?php esc_html_e('取消编辑，改为新增', 'moonlight-shop-pro'); ?></a></p>
                <?php endif; ?>
            </form>

            <h2><?php esc_html_e('端点列表', 'moonlight-shop-pro'); ?></h2>
            <table class="widefat striped" style="max-width:960px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('URL', 'moonlight-shop-pro'); ?></th>
                        <th><?php esc_html_e('事件', 'moonlight-shop-pro'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('状态', 'moonlight-shop-pro'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('操作', 'moonlight-shop-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$endpoints) : ?>
                    <tr><td colspan="4"><?php esc_html_e('暂无端点。', 'moonlight-shop-pro'); ?></td></tr>
                <?php else : foreach ($endpoints as $ep) : ?>
                    <tr>
                        <td><code><?php echo esc_html($ep['url']); ?></code></td>
                        <td><?php echo esc_html($ep['events'] ? implode(', ', $ep['events']) : '—'); ?></td>
                        <td><?php echo $ep['enabled']
                            ? '<span style="color:#00a32a;">' . esc_html__('启用', 'moonlight-shop-pro') . '</span>'
                            : '<span style="color:#d63638;">' . esc_html__('停用', 'moonlight-shop-pro') . '</span>'; ?></td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mlpro-webhooks&endpoint=' . rawurlencode($ep['id']))); ?>"><?php esc_html_e('编辑', 'moonlight-shop-pro'); ?></a> |
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlpro_webhook_delete&endpoint_id=' . rawurlencode($ep['id'])), 'mlpro_webhook_delete')); ?>"
                               onclick="return confirm('<?php echo esc_js(__('确定删除该端点？', 'moonlight-shop-pro')); ?>');"><?php esc_html_e('删除', 'moonlight-shop-pro'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <p>
                <?php echo esc_html(sprintf(__('待重试队列：%d 项（上限 %d）。', 'moonlight-shop-pro'), is_array($queue) ? count($queue) : 0, self::MAX_QUEUE)); ?>
            </p>

            <h2><?php esc_html_e('最近投递日志', 'moonlight-shop-pro'); ?></h2>
            <table class="widefat striped" style="max-width:960px;">
                <thead>
                    <tr>
                        <th style="width:180px;"><?php esc_html_e('时间', 'moonlight-shop-pro'); ?></th>
                        <th><?php esc_html_e('端点', 'moonlight-shop-pro'); ?></th>
                        <th><?php esc_html_e('事件', 'moonlight-shop-pro'); ?></th>
                        <th style="width:200px;"><?php esc_html_e('结果', 'moonlight-shop-pro'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$log) : ?>
                    <tr><td colspan="4"><?php esc_html_e('暂无投递记录。', 'moonlight-shop-pro'); ?></td></tr>
                <?php else : foreach ($log as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['at']); ?></td>
                        <td><code><?php echo esc_html($row['endpoint']); ?></code></td>
                        <td><code><?php echo esc_html($row['event']); ?></code></td>
                        <td>
                            <?php if (!empty($row['ok'])) : ?>
                                <span style="color:#00a32a;">HTTP <?php echo (int) $row['code']; ?></span>
                            <?php else : ?>
                                <span style="color:#d63638;"><?php echo esc_html((string) $row['error']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 端点保存 / 更新（URL 校验 esc_url_raw + scheme 限 http/https）。
     */
    public function handle_save()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlpro_webhook_save')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-shop-pro'));
        }
        $redirect = admin_url('admin.php?page=mlpro-webhooks');

        $id  = isset($_POST['endpoint_id']) ? sanitize_key(wp_unslash($_POST['endpoint_id'])) : '';
        $url = isset($_POST['endpoint_url']) ? esc_url_raw(wp_unslash($_POST['endpoint_url'])) : '';
        $scheme = $url ? wp_parse_url($url, PHP_URL_SCHEME) : '';
        if ('' === $url || !in_array((string) $scheme, array('http', 'https'), true)) {
            self::set_notice(__('Webhook URL 无效（仅允许 http/https）。', 'moonlight-shop-pro'), true);
            wp_safe_redirect($redirect);
            exit;
        }

        $raw = get_option(self::OPT_ENDPOINTS, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        $editing = ('' !== $id && isset($raw[$id]) && is_array($raw[$id]));

        $secret = isset($_POST['endpoint_secret']) ? trim((string) wp_unslash($_POST['endpoint_secret'])) : '';
        if ('' === $secret && !$editing) {
            $secret = self::generate_secret(); // 新端点未填：自动生成
        }
        if ('' === $secret && $editing) {
            $secret = (string) $raw[$id]['secret']; // 编辑留空：保持不变
        }

        // 事件白名单过滤：array_intersect 对 EVENTS 白名单，非法值天然被丢弃。
        $events = isset($_POST['endpoint_events']) && is_array($_POST['endpoint_events'])
            ? array_values(array_intersect(array_map('strval', wp_unslash($_POST['endpoint_events'])), self::EVENTS))
            : array();
        $enabled = !empty($_POST['endpoint_enabled']) ? 1 : 0;

        if ('' === $id) {
            $id = self::generate_id();
        }
        $raw[$id] = array(
            'id'      => $id,
            'url'     => $url,
            'secret'  => $secret,
            'events'  => $events,
            'enabled' => $enabled,
        );
        update_option(self::OPT_ENDPOINTS, $raw);

        self::set_notice($editing ? __('端点已更新。', 'moonlight-shop-pro') : __('端点已添加。', 'moonlight-shop-pro'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * 端点删除。
     */
    public function handle_delete()
    {
        if (!current_user_can('manage_options') || !check_admin_referer('mlpro_webhook_delete')) {
            wp_die(esc_html__('权限不足或校验失败。', 'moonlight-shop-pro'));
        }
        $id = isset($_GET['endpoint_id']) ? sanitize_key(wp_unslash($_GET['endpoint_id'])) : '';
        if ('' !== $id) {
            $raw = get_option(self::OPT_ENDPOINTS, array());
            if (is_array($raw) && isset($raw[$id])) {
                unset($raw[$id]);
                update_option(self::OPT_ENDPOINTS, $raw);
                self::set_notice(__('端点已删除。', 'moonlight-shop-pro'));
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=mlpro-webhooks'));
        exit;
    }

    /**
     * 生成端点 ID。
     */
    private static function generate_id()
    {
        return 'ep_' . substr(md5(uniqid('', true)), 0, 12);
    }

    /**
     * 生成端点 Secret（CSPRNG，48 位 hex）。
     */
    public static function generate_secret()
    {
        return bin2hex(random_bytes(24));
    }
}
