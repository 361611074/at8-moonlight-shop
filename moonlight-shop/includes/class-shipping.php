<?php
/**
 * 实物商品运费计算、发货单/轨迹与实物订单状态流转。
 *
 * 规则（后台「商城設定 → 運費設定」可配置，默认值即用户需求）：
 *   - 啟用後，購物車含任意實物商品才會計算運費；
 *   - 商品小計 ≥ 滿額門檻（默認 400）免運費；
 *   - 否則收取固定運費（默認 50，順豐速運）。
 *
 * 状态流转（物流第二批）：
 *   付款完成後，含實物商品且非自提的訂單由 paid 自動轉為 awaiting_shipment（待發貨），
 *   管理員在訂單編輯頁創建發貨單後轉為 shipped；軌跡推送（cron 查詢）到達後轉為
 *   delivered 並記 _mlshop_delivered_at；用戶確認收貨或超期 auto_complete 後轉 completed。
 *   自提訂單維持舊邏輯（paid → processing，管理員收尾）。
 *
 * Shipping Provider：
 *   - 注册表 providers() 走 apply_filters('moonlight_shipping_providers', ...)；
 *   - 内置 manual（零依赖，默认）与 express100（快递100 聚合查询）；
 *   - cron `moonlight_shipping_sync`（15 分钟）自动查询 transit 发货单轨迹，
 *     delivered → 发货单+订单联动；exception → 仅标记发货单，订单不动；
 *     失败 3 次暂停该单自动查询 24h。查询失败绝不影响订单状态。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

// Shipping Provider 接口与内置实现：类名不匹配自动加载规则（interface-* / provider-*），
// 随本文件显式加载，保证 providers() / filter 回调在任何上下文都能实例化。
require_once MLSHOP_PLUGIN_DIR . 'includes/core/interface-shipping-provider.php';
require_once MLSHOP_PLUGIN_DIR . 'includes/core/class-provider-manual.php';
require_once MLSHOP_PLUGIN_DIR . 'includes/core/class-provider-express100.php';

class MLSHOP_Shipping
{
    private static $instance;

    /** 发货单 CPT。post_parent = 订单 ID；post_title = 运单号。 */
    const SHIPMENT_CPT = 'mlshop_shipment';

    /** 发货单状态（meta _mlship_status）。 */
    const SHIP_CREATED   = 'created';
    const SHIP_TRANSIT   = 'transit';
    const SHIP_DELIVERED = 'delivered';
    const SHIP_EXCEPTION = 'exception';

    /** 每轮 cron 最多处理的发货单数。 */
    const SYNC_BATCH = 50;

    /** 连续失败 N 次后暂停该单自动查询。 */
    const SYNC_MAX_FAILURES = 3;

    /** 暂停时长（24h）。 */
    const SYNC_PAUSE_SECONDS = 86400;

    /** hourly 兜底与 15 分钟调度的最小间隔（节流）。 */
    const SYNC_MIN_INTERVAL = 600;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // 付款完成後，將含實物商品（非自提）的訂單轉為「待發貨」；自提維持舊邏輯轉「處理中」
        add_action('mlshop_order_paid', array($this, 'transition_physical'), 40);

        // 物流第二批：发货单 CPT 注册 + 15 分钟自动轨迹查询调度
        add_action('init', array($this, 'register_shipment_post_type'));
        add_action('init', array($this, 'maybe_schedule_sync'));
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action('moonlight_shipping_sync', array('MLSHOP_Shipping', 'run_shipping_sync_cron'));
    }

    /**
     * 是否啟用運費。
     */
    public static function enabled()
    {
        return (bool) mlshop_get_option('shipping_enabled', 1);
    }

    /**
     * 滿額包郵門檻（訂單商品小計）。
     */
    public static function free_threshold()
    {
        return (float) mlshop_get_option('shipping_free_threshold', 400);
    }

    /**
     * 固定運費。
     */
    public static function flat_rate()
    {
        return (float) mlshop_get_option('shipping_flat_rate', 50);
    }

    /**
     * 承運商名稱（如「順豐速運」）。
     */
    public static function carrier()
    {
        $name = (string) mlshop_get_option('shipping_carrier', '順豐速運');
        return $name !== '' ? $name : __('順豐速運', 'moonlight-shop');
    }

    /**
     * 到店自提是否開啟（設置頁「運費設定」）。
     *
     * 開啟後結算頁實物訂單可選「到店自提」：免運費，地址表單收起為提貨人姓名 + 手機號。
     */
    public static function pickup_enabled()
    {
        return (bool) mlshop_get_option('pickup_enabled', 0);
    }

    /**
     * 運費模板列表。
     *
     * 存儲：option `moonlight_shipping_templates` = 數組 of
     *   ['id','name','mode'=>'fixed'|'piece','flat_fee','free_threshold','first_item_fee','extra_item_fee']
     * 空數組 = 未配置，全站回退「全局固定運費 + 滿額包郵」（完全向後兼容）。
     *
     * @return array
     */
    public static function templates()
    {
        $templates = get_option('moonlight_shipping_templates', array());
        $templates = is_array($templates) ? $templates : array();
        /**
         * 替換 / 擴展運費模板（與 moonlight_regions 同風格的數據源過濾器）。
         *
         * @param array $templates
         */
        $filtered = apply_filters('moonlight_shipping_templates', $templates);
        return is_array($filtered) ? $filtered : $templates;
    }

    /**
     * 按 id 查找模板。
     *
     * @param array  $templates
     * @param string $id
     * @return array|null
     */
    public static function find_template($templates, $id)
    {
        $id = (string) $id;
        if ('' === $id || !is_array($templates)) {
            return null;
        }
        foreach ($templates as $tpl) {
            if (is_array($tpl) && isset($tpl['id']) && (string) $tpl['id'] === $id) {
                return $tpl;
            }
        }
        return null;
    }

    /**
     * 按購物車條目計運費：模板感知的統一入口。
     *
     * 有任何商品掛了運費模板 → 走 template_calc（掛模板的逐商品按模板計費，
     * 未掛模板的合併後仍按全局規則計一次）；否則走原全局 calc()。
     *
     * @param array $items    Cart::get_items() 結構
     * @param float $subtotal 商品小計（用於全局規則的滿額門檻判斷）
     * @return float
     */
    public static function calc_for_items($items, $subtotal)
    {
        if (!self::enabled() || !self::has_physical($items)) {
            return 0.0;
        }
        $templates = self::templates();
        if (!empty($templates)) {
            return (float) self::template_calc($items, $templates);
        }
        return (float) self::calc($subtotal, true);
    }

    /**
     * 運費模板計價引擎：逐商品按其模板計費求和。
     *
     * 規則（free_threshold 僅在 > 0 時生效，0/空 = 不享受免郵）：
     *   - fixed：該商品小計達其 free_threshold → 免運費；否則收 flat_fee；
     *   - piece：該商品小計達其 free_threshold → 免運費；
     *     否則 first_item_fee + (qty - 1) * extra_item_fee（按該商品自身件數）。
     *   - 未掛模板 / 模板已刪除的商品：小計累加後按「全局固定運費 + 滿額包郵」
     *     統一計一次（避免雙重收費）。
     *
     * @param array $items     Cart::get_items() 結構（需含 id / qty / subtotal）
     * @param array $templates self::templates() 結構
     * @return float
     */
    public static function template_calc($items, $templates)
    {
        $fee  = 0.0;
        $rest = 0.0; // 未掛模板商品的小計，走全局規則
        foreach ((array) $items as $it) {
            $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
            $sub = isset($it['subtotal'])
                ? (float) $it['subtotal']
                : ((float) (isset($it['price']) ? $it['price'] : 0) * $qty);
            $pid = isset($it['id']) ? (int) $it['id'] : 0;
            $tid = $pid ? (string) get_post_meta($pid, '_mlshop_shipping_template', true) : '';
            $tpl = self::find_template($templates, $tid);
            if (!$tpl) {
                $rest += $sub;
                continue;
            }
            $threshold = isset($tpl['free_threshold']) ? (float) $tpl['free_threshold'] : 0.0;
            if ($threshold > 0 && $sub >= $threshold) {
                continue; // 達到該模板的免郵門檻，該商品免運費
            }
            $mode = isset($tpl['mode']) ? (string) $tpl['mode'] : 'fixed';
            if ('piece' === $mode) {
                if ($qty <= 0) {
                    continue;
                }
                $first = isset($tpl['first_item_fee']) ? (float) $tpl['first_item_fee'] : 0.0;
                $extra = isset($tpl['extra_item_fee']) ? (float) $tpl['extra_item_fee'] : 0.0;
                $fee += $first + ($qty - 1) * $extra;
            } else {
                $fee += isset($tpl['flat_fee']) ? (float) $tpl['flat_fee'] : 0.0;
            }
        }
        if ($rest > 0) {
            $fee += (float) self::calc($rest, true);
        }
        return round($fee, 2);
    }

    /**
     * 購物車/訂單條目是否含實物商品。
     *
     * @param array $items MLSHOP_Cart::get_items() 或 _mlshop_items 結構
     */
    public static function has_physical($items)
    {
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $it) {
            $pid = isset($it['id']) ? (int) $it['id'] : 0;
            if (!$pid) {
                continue;
            }
            $type = get_post_meta($pid, '_mlshop_type', true);
            // 未設定類型視為實物，避免漏算運費
            if ('' === $type || 'physical' === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * 計算運費。
     *
     * @param float $subtotal      商品小計（優惠後）
     * @param bool  $has_physical  購物車是否含實物商品
     * @return float
     */
    public static function calc($subtotal, $has_physical)
    {
        if (!self::enabled() || !$has_physical) {
            return 0.0;
        }
        if ($subtotal >= self::free_threshold()) {
            return 0.0;
        }
        return (float) self::flat_rate();
    }

    /**
     * 運費說明文案（用於前端提示與郵件）。
     *
     * @param float $subtotal 商品小計
     */
    public static function note($subtotal)
    {
        $threshold = self::free_threshold();
        $carrier   = self::carrier();
        if ($subtotal >= $threshold) {
            return sprintf(
                /* translators: %1$s 承運商, %2$s 門檻金額 */
                __('已滿 %2$s，享 %1$s 免運費。', 'moonlight-shop'),
                esc_html($carrier),
                mlshop_format_price($threshold)
            );
        }
        return sprintf(
            /* translators: %1$s 承運商, %2$s 運費, %3$s 滿額門檻 */
            __('未滿 %3$s，需加收 %1$s 運費 %2$s。', 'moonlight-shop'),
            esc_html($carrier),
            mlshop_format_price(self::flat_rate()),
            mlshop_format_price($threshold)
        );
    }

    /**
     * 付款完成後：含實物商品且非自提的訂單轉為「待發貨」（awaiting_shipment）。
     *
     * 自提订单维持旧逻辑（paid → processing，管理員確認提貨後收尾）；
     * 非实物订单不动（虚拟交付流程处理）。
     *
     * @param int $order_id
     */
    public function transition_physical($order_id)
    {
        if (!class_exists('MLSHOP_Order')) {
            return;
        }
        $has_physical = (bool) get_post_meta($order_id, '_mlshop_has_physical', true);
        if (!$has_physical) {
            return;
        }
        if ('paid' !== MLSHOP_Order::get_status($order_id)) {
            return;
        }
        // 到店自提：免運費、無物流單，走舊「處理中」收尾流程
        if ('1' === (string) get_post_meta($order_id, '_mlshop_pickup', true)) {
            MLSHOP_Order::mark_processing($order_id);
            return;
        }
        // 快递配送：进入「待发货」，等待管理员创建发货单
        MLSHOP_Order::set_status($order_id, 'awaiting_shipment');
    }

    /* =====================================================
     * Shipping Provider 注册表（物流第二批）
     * ===================================================== */

    /**
     * 全部已注册 Provider（filter `moonlight_shipping_providers` 可扩展）。
     *
     * 默认：manual（零依赖，恒可用）+ express100（配置了 key 才算 available）。
     *
     * @return array Moonlight_Shipping_Provider_Interface[]
     */
    public static function providers()
    {
        /**
         * 替换 / 扩展物流 Provider 注册表。
         *
         * @param array $providers Moonlight_Shipping_Provider_Interface[]
         */
        $providers = apply_filters('moonlight_shipping_providers', array(
            new Moonlight_Shipping_Provider_Manual(),
            new Moonlight_Shipping_Provider_Express100(),
        ));
        return is_array($providers) ? $providers : array();
    }

    /**
     * 按 code 取 Provider（找不到返回 null）。
     *
     * @param string $code
     * @return object|null
     */
    public static function get_provider($code)
    {
        $code = (string) $code;
        if ('' === $code) {
            return null;
        }
        foreach (self::providers() as $p) {
            if (is_object($p) && method_exists($p, 'get_code') && $p->get_code() === $code) {
                return $p;
            }
        }
        return null;
    }

    /**
     * 当前生效 Provider（option shipping_provider，默认 manual）。
     *
     * 所选 Provider 不可用（如快递100 未配 Key）时回退 manual，永不返回 null
     * （除非第三方 filter 把 manual 也过滤掉了）。
     *
     * @return object|null
     */
    public static function active_provider()
    {
        $code = (string) mlshop_get_option('shipping_provider', 'manual');
        $p    = self::get_provider($code);
        if ($p && method_exists($p, 'is_available') && $p->is_available()) {
            return $p;
        }
        // 回退 manual（默认零依赖基线）
        return self::get_provider('manual');
    }

    /* =====================================================
     * 发货单 CPT + 发货（物流第二批）
     * ===================================================== */

    /**
     * 注册发货单 CPT（完全内部使用：public=false、show_ui=false）。
     *
     * post_parent = 订单 ID；post_title = 运单号；一个订单可挂多条发货单。
     */
    public function register_shipment_post_type()
    {
        register_post_type(self::SHIPMENT_CPT, array(
            'labels'              => array(
                'name'          => __('发货单', 'moonlight-shop'),
                'singular_name' => __('发货单', 'moonlight-shop'),
            ),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'supports'            => array('title'),
            'capability_type'     => 'post',
            'rewrite'             => false,
        ));
    }

    /**
     * 创建发货单并推进订单 shipped。
     *
     * meta：_mlship_items / _mlship_provider / _mlship_company / _mlship_company_code /
     *       _mlship_no / _mlship_status(=transit) / _mlship_events(JSON) / _mlship_created / _mlship_note
     *
     * 订单推进：awaiting_shipment / processing → shipped（走 set_status 白名单；
     * 非法转换（如 paid / delivered 单）直接拒绝，不产生半途数据，由调用方提示。
     * 已 shipped 的订单可再创建（多包裹补录，set_status 对同状态为幂等 no-op）。
     *
     * @param int   $order_id
     * @param array $args ['company'=>显示名, 'company_code'=>代码(可选), 'tracking_no'=>必填,
     *                    'note'=>可选, 'provider'=>provider code(可选，默认当前生效)]
     * @return int|WP_Error 成功返回发货单 ID
     */
    public static function create_shipment($order_id, $args = array())
    {
        if (!class_exists('MLSHOP_Order')) {
            return new WP_Error('no_order_class', __('订单模块不可用。', 'moonlight-shop'));
        }
        $order_id = (int) $order_id;
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            return new WP_Error('invalid_order', __('订单不存在。', 'moonlight-shop'));
        }
        $company      = isset($args['company']) ? sanitize_text_field($args['company']) : '';
        $company_code = isset($args['company_code']) ? sanitize_text_field($args['company_code']) : '';
        $no           = isset($args['tracking_no']) ? sanitize_text_field($args['tracking_no']) : '';
        $note         = isset($args['note']) ? sanitize_textarea_field($args['note']) : '';
        if ('' === $company && '' !== $company_code) {
            $company = $company_code; // 只有代码时显示名兜底为代码
        }
        if ('' === $company && '' === $company_code) {
            $company = self::carrier(); // 全局默认承运商兜底
        }
        if ('' === $no) {
            return new WP_Error('no_tracking_no', __('请填写运单号。', 'moonlight-shop'));
        }

        // 状态预检：仅 待发货/处理中 可首发货；已发货单可补录包裹；其余拒绝。
        $current = MLSHOP_Order::get_status($order_id);
        if (!in_array($current, array('awaiting_shipment', 'processing', 'shipped'), true)) {
            return new WP_Error(
                'invalid_transition',
                sprintf(
                    /* translators: %1$$s: 值 */
                    __('订单状态「%1$s」不允许发货（需为待发货/处理中/已发货补录）。', 'moonlight-shop'),
                    MLSHOP_Order::get_status_label($current)
                )
            );
        }

        // Provider（预留电子面单；查询型 Provider 恒成功）
        $provider = !empty($args['provider']) ? self::get_provider((string) $args['provider']) : self::active_provider();
        if (!$provider) {
            $provider = self::get_provider('manual');
        }
        $provider_code = 'manual';
        if ($provider && method_exists($provider, 'get_code')) {
            $provider_code = (string) $provider->get_code();
        }
        if ($provider && method_exists($provider, 'get_supported_companies')) {
            // 显示名 → 公司代码反查（轨迹查询用代码；代码缺失则 cron 查询回退用显示名）
            if ('' === $company_code && '' !== $company) {
                foreach ((array) $provider->get_supported_companies() as $c) {
                    if (is_array($c) && isset($c['name']) && $c['name'] === $company && !empty($c['code'])) {
                        $company_code = (string) $c['code'];
                        break;
                    }
                }
            }
            $res = $provider->create_shipment(array(
                'order_id'     => $order_id,
                'company'      => $company,
                'company_code' => $company_code,
                'tracking_no'  => $no,
                'items'        => get_post_meta($order_id, '_mlshop_items', true),
            ));
            if (!is_array($res) || empty($res['success'])) {
                $msg = (is_array($res) && isset($res['message']) && '' !== $res['message'])
                    ? $res['message']
                    : __('物流 Provider 下单失败。', 'moonlight-shop');
                return new WP_Error('provider_failed', $msg);
            }
        }

        $shipment_id = wp_insert_post(array(
            'post_type'   => self::SHIPMENT_CPT,
            'post_title'  => $no,
            'post_status' => 'publish',
            'post_parent' => $order_id,
        ));
        if (!$shipment_id || is_wp_error($shipment_id)) {
            return new WP_Error('insert_failed', __('发货单创建失败。', 'moonlight-shop'));
        }

        $created_ts = current_time('timestamp');
        update_post_meta($shipment_id, '_mlship_items', get_post_meta($order_id, '_mlshop_items', true));
        update_post_meta($shipment_id, '_mlship_provider', $provider_code);
        update_post_meta($shipment_id, '_mlship_company', $company);
        update_post_meta($shipment_id, '_mlship_company_code', $company_code);
        update_post_meta($shipment_id, '_mlship_no', $no);
        update_post_meta($shipment_id, '_mlship_status', self::SHIP_TRANSIT);
        update_post_meta($shipment_id, '_mlship_created', current_time('mysql'));
        if ('' !== $note) {
            update_post_meta($shipment_id, '_mlship_note', $note);
        }
        // 轨迹首条：发货备注（如有）+ 交运事件
        $events = array();
        if ('' !== $note) {
            $events[] = array('time' => $created_ts, 'desc' => $note);
        }
        $events[] = array(
            'time' => $created_ts,
            /* translators: %1$$s: 值, %2$$s: 值 */
            'desc' => sprintf(__('包裹已交运（%1$s %2$s）', 'moonlight-shop'), $company, $no),
        );
        update_post_meta($shipment_id, '_mlship_events', wp_json_encode($events));

        // 订单推进：awaiting_shipment / processing → shipped（预检已保证合法，仍防并发）
        if (in_array($current, array('awaiting_shipment', 'processing'), true)) {
            $res = MLSHOP_Order::set_status($order_id, 'shipped');
            if (is_wp_error($res)) {
                return $res;
            }
        }

        do_action('moonlight_shipment_created', $shipment_id, $order_id, $no);
        return (int) $shipment_id;
    }

    /**
     * 订单的全部发货单（按创建时间正序）。
     *
     * @param int $order_id
     * @return array 每条：id/order_id/provider/company/company_code/no/status/status_label/
     *                    events(数组)/created/last_sync/note
     */
    public static function get_shipments($order_id)
    {
        $posts = get_posts(array(
            'post_type'      => self::SHIPMENT_CPT,
            'post_status'    => 'publish',
            'post_parent'    => (int) $order_id,
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ));
        $out = array();
        foreach ($posts as $p) {
            $out[] = self::read_shipment((int) $p->ID);
        }
        return $out;
    }

    /**
     * 读取单条发货单（meta 归一化为数组）。
     *
     * @param int $shipment_id
     * @return array
     */
    public static function read_shipment($shipment_id)
    {
        $shipment_id = (int) $shipment_id;
        $events      = json_decode((string) get_post_meta($shipment_id, '_mlship_events', true), true);
        $status      = (string) get_post_meta($shipment_id, '_mlship_status', true);
        $post        = get_post($shipment_id);
        return array(
            'id'           => $shipment_id,
            'order_id'     => $post ? (int) $post->post_parent : 0,
            'provider'     => (string) get_post_meta($shipment_id, '_mlship_provider', true),
            'company'      => (string) get_post_meta($shipment_id, '_mlship_company', true),
            'company_code' => (string) get_post_meta($shipment_id, '_mlship_company_code', true),
            'no'           => (string) get_post_meta($shipment_id, '_mlship_no', true),
            'items'        => get_post_meta($shipment_id, '_mlship_items', true),
            'status'       => $status,
            'status_label' => self::shipment_status_label($status),
            'events'       => is_array($events) ? $events : array(),
            'created'      => (string) get_post_meta($shipment_id, '_mlship_created', true),
            'last_sync'    => (int) get_post_meta($shipment_id, '_mlship_last_sync', true),
            'note'         => (string) get_post_meta($shipment_id, '_mlship_note', true),
        );
    }

    /**
     * 发货单状态 → 中文标签。
     *
     * @param string $status
     * @return string
     */
    public static function shipment_status_label($status)
    {
        $map = array(
            self::SHIP_CREATED   => __('已创建', 'moonlight-shop'),
            self::SHIP_TRANSIT   => __('运输中', 'moonlight-shop'),
            self::SHIP_DELIVERED => __('已签收', 'moonlight-shop'),
            self::SHIP_EXCEPTION => __('异常', 'moonlight-shop'),
        );
        $status = (string) $status;
        return isset($map[$status]) ? $map[$status] : $status;
    }

    /**
     * 轨迹事件时间格式化（ts 数字 → 日期串；字符串原样返回）。
     *
     * @param int|string $time
     * @return string
     */
    public static function format_event_time($time)
    {
        if (is_numeric($time) && (int) $time > 0) {
            return wp_date('Y-m-d H:i', (int) $time);
        }
        return (string) $time;
    }

    /* =====================================================
     * cron 自动物流查询 + delivered 自动完成（物流第二批）
     * ===================================================== */

    /**
     * 注册 15 分钟调度（若尚未调度）。init 时自愈，激活钩子亦会注册。
     */
    public static function maybe_schedule_sync()
    {
        if (!wp_next_scheduled('moonlight_shipping_sync')) {
            wp_schedule_event(time(), 'mlshop_15min', 'moonlight_shipping_sync');
        }
    }

    /**
     * 自定义 cron 间隔：15 分钟。
     */
    public function add_cron_schedule($schedules)
    {
        if (!isset($schedules['mlshop_15min'])) {
            $schedules['mlshop_15min'] = array(
                'interval' => 15 * 60,
                'display'  => __('每 15 分钟（商城物流查询）', 'moonlight-shop'),
            );
        }
        return $schedules;
    }

    /**
     * cron 回调（moonlight_shipping_sync）：专用 15 分钟调度，不节流。
     */
    public static function run_shipping_sync_cron()
    {
        self::run_shipping_sync(true);
    }

    /**
     * 自动物流查询：取 transit 发货单（≤50 条）→ provider 查轨迹 → 联动订单。
     *
     * 轨迹 delivered → 发货单 delivered + 订单 shipped→delivered（记 _mlshop_delivered_at）；
     * 轨迹 exception → 发货单标记 exception（订单不动，由管理员人工介入）；
     * transit/pending → 有新事件则追加 _mlship_events；
     * 查询异常（WP_Error/异常/结构非法）→ 失败计数，3 次后暂停该单 24h。
     * 任何失败都不抛出、不影响订单状态。
     *
     * @param bool        $force    true = 15 分钟专用调度（不节流）；false = hourly 兜底（10 分钟节流）
     * @param object|null $provider 测试注入用；null 时取 active_provider()
     * @return int 本轮处理的发货单数
     */
    public static function run_shipping_sync($force = false, $provider = null)
    {
        // hourly 兜底节流：与 15 分钟专用调度错开，避免短时间重复请求外部 API
        if (!$force) {
            $last = (int) get_option('moonlight_shipping_last_sync', 0);
            if ($last && (time() - $last) < self::SYNC_MIN_INTERVAL) {
                return 0;
            }
        }
        update_option('moonlight_shipping_last_sync', time());

        if (!(bool) mlshop_get_option('shipping_auto_sync', 1)) {
            return 0;
        }
        if (null === $provider) {
            $provider = self::active_provider();
        }
        if (!$provider || !method_exists($provider, 'query_tracking')) {
            return 0;
        }
        // manual 无轨迹能力，跳过整个查询循环
        if (method_exists($provider, 'get_code') && 'manual' === $provider->get_code()) {
            return 0;
        }

        $posts = get_posts(array(
            'post_type'      => self::SHIPMENT_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => self::SYNC_BATCH,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ));

        $processed = 0;
        foreach ($posts as $sp) {
            $sid = (int) $sp->ID;
            if ((string) get_post_meta($sid, '_mlship_status', true) !== self::SHIP_TRANSIT) {
                continue;
            }
            // 失败退避：暂停期内的发货单跳过
            $paused_until = (int) get_post_meta($sid, '_mlship_sync_paused_until', true);
            if ($paused_until > time()) {
                continue;
            }
            $processed++;

            $company_code = (string) get_post_meta($sid, '_mlship_company_code', true);
            $company_name = (string) get_post_meta($sid, '_mlship_company', true);
            $no           = (string) get_post_meta($sid, '_mlship_no', true);

            try {
                $result = $provider->query_tracking('' !== $company_code ? $company_code : $company_name, $no);
            } catch (Throwable $e) {
                $result = null;
            }
            update_post_meta($sid, '_mlship_last_sync', time());

            if (is_wp_error($result) || !is_array($result) || empty($result['status'])) {
                self::record_sync_failure($sid);
                continue;
            }
            $events_in = isset($result['events']) && is_array($result['events']) ? $result['events'] : array();

            // 成功：清空失败计数
            update_post_meta($sid, '_mlship_fail_count', 0);
            if ($paused_until) {
                delete_post_meta($sid, '_mlship_sync_paused_until');
            }

            $status = (string) $result['status'];
            $order_id = $sp->post_parent ? (int) $sp->post_parent : 0;

            // 追加新事件（按 time+desc+city 去重，保留原顺序在前，新事件在后）
            self::merge_shipment_events($sid, $events_in);

            if (self::SHIP_DELIVERED === $status) {
                update_post_meta($sid, '_mlship_status', self::SHIP_DELIVERED);
                if ($order_id && class_exists('MLSHOP_Order')) {
                    if ('shipped' === MLSHOP_Order::get_status($order_id)) {
                        MLSHOP_Order::set_status($order_id, 'delivered'); // 记 _mlshop_delivered_at
                    } elseif ('' === (string) get_post_meta($order_id, '_mlshop_delivered_at', true)) {
                        update_post_meta($order_id, '_mlshop_delivered_at', current_time('mysql'));
                    }
                }
                do_action('moonlight_shipment_delivered', $sid, $order_id);
            } elseif (self::SHIP_EXCEPTION === $status) {
                // 仅标记发货单异常，订单状态不动
                update_post_meta($sid, '_mlship_status', self::SHIP_EXCEPTION);
                do_action('moonlight_shipment_exception', $sid, $order_id);
            }
        }
        return $processed;
    }

    /**
     * 记录一次查询失败：失败计数 ≥3 → 暂停该单自动查询 24h（meta 记暂停截止时间）。
     *
     * @param int $shipment_id
     */
    private static function record_sync_failure($shipment_id)
    {
        $count = (int) get_post_meta($shipment_id, '_mlship_fail_count', true) + 1;
        if ($count >= self::SYNC_MAX_FAILURES) {
            update_post_meta($shipment_id, '_mlship_sync_paused_until', time() + self::SYNC_PAUSE_SECONDS);
            update_post_meta($shipment_id, '_mlship_fail_count', 0); // 暂停结束后重新计数
        } else {
            update_post_meta($shipment_id, '_mlship_fail_count', $count);
        }
        do_action('moonlight_shipping_sync_failed', $shipment_id, $count);
    }

    /**
     * 合并发货单轨迹事件：仅追加从未出现过的事件（time+desc+city 签名去重）。
     *
     * @param int   $shipment_id
     * @param array $incoming 新事件数组 [['time'=>ts,'desc'=>..,'city'=>?]]
     * @return array 实际追加的事件
     */
    private static function merge_shipment_events($shipment_id, $incoming)
    {
        $stored = json_decode((string) get_post_meta($shipment_id, '_mlship_events', true), true);
        $stored = is_array($stored) ? $stored : array();

        $seen = array();
        foreach ($stored as $ev) {
            $seen[self::event_signature($ev)] = true;
        }
        $added = array();
        foreach ((array) $incoming as $ev) {
            if (!is_array($ev) || (empty($ev['desc']) && empty($ev['time']))) {
                continue;
            }
            $sig = self::event_signature($ev);
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $item = array(
                'time' => isset($ev['time']) ? $ev['time'] : 0,
                'desc' => (string) (isset($ev['desc']) ? $ev['desc'] : ''),
            );
            if (!empty($ev['city'])) {
                $item['city'] = (string) $ev['city'];
            }
            $stored[] = $item;
            $added[]  = $item;
        }
        if (!empty($added)) {
            update_post_meta($shipment_id, '_mlship_events', wp_json_encode($stored));
        }
        return $added;
    }

    /**
     * 事件去重签名（time + desc + city）。
     */
    private static function event_signature($ev)
    {
        return md5((string) (isset($ev['time']) ? $ev['time'] : '') . '|' . (string) (isset($ev['desc']) ? $ev['desc'] : '') . '|' . (string) (isset($ev['city']) ? $ev['city'] : ''));
    }

    /**
     * delivered 订单超期自动完成（同一 hourly 调度 / 物流 cron 兜底里调用）。
     *
     * option `auto_complete_days`（默认 7）：delivered 且 _mlshop_delivered_at
     * 超过 N 天 → completed；0 = 关闭自动完成。
     *
     * @return int 完成的订单数
     */
    public static function auto_complete_orders()
    {
        $days = (int) mlshop_get_option('auto_complete_days', 7);
        if ($days <= 0 || !class_exists('MLSHOP_Order')) {
            return 0;
        }
        $orders = get_posts(array(
            'post_type'      => 'mlshop_order',
            'post_status'    => 'mlshop_delivered',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ));
        $done = 0;
        $now  = current_time('timestamp');
        foreach ($orders as $oid) {
            $oid = (int) $oid;
            $delivered_at = (string) get_post_meta($oid, '_mlshop_delivered_at', true);
            if ('' === $delivered_at) {
                continue; // 无签收时间（历史数据/手工置状态）不自动完成
            }
            $ts = strtotime($delivered_at);
            if (!$ts) {
                continue;
            }
            if (($now - $ts) >= $days * DAY_IN_SECONDS) {
                $res = MLSHOP_Order::set_status($oid, 'completed');
                if (!is_wp_error($res)) {
                    $done++;
                }
            }
        }
        return $done;
    }
}
