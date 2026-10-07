<?php
/**
 * 订单管理。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Order
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
        add_action('init', array($this, 'maybe_schedule_cron'));
        add_action('mlshop_expire_pending_orders', array($this, 'expire_pending_orders'));
        add_shortcode('mlshop_order', array($this, 'shortcode_order'));

        // 后台订单编辑页：3 个 meta box（订单信息 / 订单商品 / 物流信息）。
        // 历史仅 supports=title，编辑页只剩标题与发布栏，看不到任何 meta（订单 meta 全在 _mlshop_* 字段）。
        // 2026-08-28：补齐——让管理员能看到付款时间、物流、金额构成等。
        if (is_admin()) {
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('save_post_mlshop_order', array($this, 'save_meta_box'), 20, 2);
        }

        /**
         * 修订单 CPT 默认 list 不显示订单的根因。
         *
         * WP 7.x 的 wp_edit_posts_query() 不再像旧版那样自动按
         * `show_in_admin_all_list=true` 把 internal status 注入默认查询，
         * 它直接把 post_status='' 传给 WP_Query。空字符串 = 「只查 public 状态」，
         * 而我们的 mlshop_* 全部 internal + public=false，于是 WP 默认把订单筛光。
         *
         * 解决：当 list 未显式指定 post_status 时，主动把全部
         * `show_in_admin_all_list=true` 的 status（含 publish/private + 我们的 custom）
         * 注入到主查询。函数内自身判断 post_type，避免污染其他 CPT 的查询。
         */
        add_action('pre_get_posts', array($this, 'fix_admin_list_status'));
    }

    /**
     * 默认 list 修复：把当前 CPT 的「admin-all 可见」状态注入默认查询。
     *
     * 仅作用于 (post_type=mlshop_order) 未显式筛选状态的情况。不强制 is_admin()：
     * 前端列表很少按 mlshop_order 拉全部；万一有，前端既看 publish 之外的也合理。
     * 不强制 is_main_query()：CLI/原生工具脚本走 new WP_Query 也常见。
     * 其他 list 调用（get_post 单条 / 按 ID 查）通常已传 post__in，不会被影响。
     */
    public function fix_admin_list_status($q)
    {
        if (!$q instanceof WP_Query) {
            return;
        }
        $pt = $q->get('post_type');
        if (is_array($pt)) {
            // WP 某些查询将 post_type 传为数组（如跨类型查询），逐元素匹配，避免 (string) 数组警告
            if (!in_array('mlshop_order', $pt, true)) {
                return;
            }
        } elseif ((string) $pt !== 'mlshop_order') {
            return;
        }
        // 用户已显式筛选某一状态：尊重之
        $ps = $q->get('post_status');
        if (is_array($ps)) {
            // WP 后台多状态勾选 / 计数查询会把 post_status 传成数组，先判定数组避免 (string) 数组警告
            return;
        }
        if ((string) $ps !== '') {
            return;
        }
        $statuses = get_post_stati(array('show_in_admin_all_list' => true));
        if (empty($statuses)) {
            return;
        }
        $slugs = array_keys($statuses);
        if (empty($slugs)) {
            return;
        }
        $q->set('post_status', $slugs);
    }

    /**
     * 后台订单编辑页 3 个 meta box。
     */
    public function add_meta_boxes()
    {
        add_meta_box(
            'mlshop_order_info',
            __('订单信息', 'at8-moonlight-shop'),
            array($this, 'render_meta_box_info'),
            'mlshop_order',
            'normal',
            'high'
        );
        add_meta_box(
            'mlshop_order_items',
            __('订单商品', 'at8-moonlight-shop'),
            array($this, 'render_meta_box_items'),
            'mlshop_order',
            'normal',
            'default'
        );
        add_meta_box(
            'mlshop_order_shipping',
            __('物流信息', 'at8-moonlight-shop'),
            array($this, 'render_meta_box_shipping'),
            'mlshop_order',
            'side',
            'default'
        );
        // 售后与退款（Refund_Service）：售后申请列表 + 退款操作表单 + 退款日志
        add_meta_box(
            'mlshop_order_refund',
            __('售后与退款', 'at8-moonlight-shop'),
            array($this, 'render_meta_box_refund'),
            'mlshop_order',
            'normal',
            'default'
        );
    }

    /**
     * meta box #1：订单摘要（客户/状态/时间/金额/网关/优惠券/下单时间/付款时间）。
     */
    public function render_meta_box_info($post)
    {
        wp_nonce_field('mlshop_order_meta', 'mlshop_order_meta_nonce');

        // 自我检查：粗略估计只在订单 CPT 上运行（防御性）
        if ($post->post_type !== 'mlshop_order') {
            return;
        }

        $user_id      = (int) get_post_meta($post->ID, '_mlshop_user_id', true);
        $user         = $user_id ? get_user_by('id', $user_id) : null;
        $status_raw   = (string) get_post_meta($post->ID, '_mlshop_status', true); // pending/paid/awaiting_shipment/...
        $status_label = self::get_status_label($status_raw);
        $currency     = (string) get_post_meta($post->ID, '_mlshop_currency', true);
        $subtotal     = (float)  get_post_meta($post->ID, '_mlshop_subtotal', true);
        $shipping     = (float)  get_post_meta($post->ID, '_mlshop_shipping', true);
        $total        = (float)  get_post_meta($post->ID, '_mlshop_total', true);
        $gateway      = (string) get_post_meta($post->ID, '_mlshop_gateway', true);
        $pay_gw       = (string) get_post_meta($post->ID, '_mlshop_payment_gateway', true);
        $pay_id       = (string) get_post_meta($post->ID, '_mlshop_payment_id', true);
        $coupon_code  = (string) get_post_meta($post->ID, '_mlshop_coupon_code', true);
        $coupon_disc  = (float)  get_post_meta($post->ID, '_mlshop_coupon_discount', true);
        $created      = (string) get_post_meta($post->ID, '_mlshop_created', true);
        $paid_at      = (string) get_post_meta($post->ID, '_mlshop_paid_time', true); // 由网关回调写入
        $fail_msg     = (string) get_post_meta($post->ID, '_mlshop_fail_message', true);
        $type         = (string) get_post_meta($post->ID, '_mlshop_type', true); // ''|'recharge'|'membership'|'paywall'
        $type_label   = self::type_label_zh($type);
        $extra        = self::read_extra_meta($post->ID);

        ?>
        <table class="form-table mlshop-order-meta">
            <tr>
                <th><?php esc_html_e('订单状态', 'at8-moonlight-shop'); ?></th>
                <td>
                    <span class="mlshop-pill mlshop-pill-<?php echo esc_attr($status_raw); ?>"><?php echo esc_html($status_label); ?></span>
                    <span class="description" style="margin-left:8px;">（原始: <code><?php echo esc_html($status_raw ?: 'pending'); ?></code>）</span>
                    <?php if (current_user_can('manage_options')) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <?php wp_nonce_field('mlshop_order_set_status'); ?>
                        <input type="hidden" name="action" value="mlshop_order_set_status">
                        <input type="hidden" name="order_id" value="<?php echo (int) $post->ID; ?>">
                        <select name="mlshop_status" aria-label="<?php esc_attr_e('变更订单状态', 'at8-moonlight-shop'); ?>">
                            <?php foreach (mlshop_get_order_statuses() as $k => $lbl) : ?>
                                <option value="<?php echo esc_attr($k); ?>" <?php selected($k, $status_raw); ?>><?php echo esc_html($lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button"><?php esc_html_e('更新状态', 'at8-moonlight-shop'); ?></button>
                    </form>
                    <p class="description"><?php esc_html_e('变更状态会触发对应副作用（发货/交付/退款回补/优惠券释放），并受状态流转规则约束。', 'at8-moonlight-shop'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('订单类型', 'at8-moonlight-shop'); ?></th>
                <td><?php echo esc_html($type_label); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('客户', 'at8-moonlight-shop'); ?></th>
                <td>
                    <?php if ($user) : ?>
                        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $user->ID)); ?>">
                            <?php echo esc_html($user->display_name); ?>
                        </a>
                        <span style="color:#6b7280;">（<?php echo esc_html($user->user_email); ?> / #<?php echo (int) $user->ID; ?>）</span>
                    <?php elseif (mlshop_is_guest_order($post->ID)) : ?>
                        <span class="mlshop-pill mlshop-pill-pending"><?php esc_html_e('访客订单', 'at8-moonlight-shop'); ?></span>
                        <?php $g_email = (string) get_post_meta($post->ID, '_mlshop_guest_email', true); ?>
                        <?php if ($g_email) : ?>
                            <span style="margin-left:6px;"><?php echo esc_html($g_email); ?></span>
                        <?php endif; ?>
                        <span style="color:#6b7280;">（<?php esc_html_e('未注册用户下单，确认邮件已发往上述邮箱', 'at8-moonlight-shop'); ?>）</span>
                    <?php else : ?>
                        <span style="color:#9ca3af;"><?php esc_html_e('（未关联用户，可能为访客下单或用户已删除）', 'at8-moonlight-shop'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('订单金额', 'at8-moonlight-shop'); ?></th>
                <td>
                    <?php if ($subtotal) : ?>
                        <?php echo esc_html($currency); ?> <?php echo esc_html(number_format($subtotal, 2)); ?> <?php esc_html_e('（小计）', 'at8-moonlight-shop'); ?>
                    <?php endif; ?>
                    <?php if ($coupon_disc > 0) : ?>
                        <span style="color:#16a34a;">− <?php echo esc_html($currency); ?> <?php echo esc_html(number_format($coupon_disc, 2)); ?>
                        <?php if ($coupon_code) : ?>（<?php echo esc_html($coupon_code); ?>）<?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($shipping > 0) : ?>
                        <span>＋ <?php echo esc_html($currency); ?> <?php echo esc_html(number_format($shipping, 2)); ?> <?php esc_html_e('（运费）', 'at8-moonlight-shop'); ?></span>
                    <?php endif; ?>
                    <strong style="margin-left:12px;font-size:1.1em;">
                        <?php esc_html_e('合计：', 'at8-moonlight-shop'); ?>
                        <?php echo esc_html($currency); ?> <?php echo esc_html(number_format($total, 2)); ?>
                    </strong>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('支付网关', 'at8-moonlight-shop'); ?></th>
                <td>
                    <?php echo esc_html(self::gateway_label_zh($gateway)); ?>
                    <?php if ($pay_gw) : ?>
                        <span style="color:#6b7280;"><?php esc_html_e('（实际通道：', 'at8-moonlight-shop'); echo esc_html(self::gateway_label_zh($pay_gw)); ?>）</span>
                    <?php endif; ?>
                    <?php if ($pay_id) : ?>
                        <br><code style="font-size:11px;"><?php echo esc_html($pay_id); ?></code>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('下单时间', 'at8-moonlight-shop'); ?></th>
                <td>
                    <?php if ($created) : ?>
                        <?php echo esc_html($created); ?>
                        <span style="color:#9ca3af;">（<?php echo esc_html(self::human_diff($created)); ?>）</span>
                    <?php else : ?>
                        <span style="color:#9ca3af;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('付款时间', 'at8-moonlight-shop'); ?></th>
                <td>
                    <?php if ($paid_at) : ?>
                        <?php echo esc_html($paid_at); ?>
                        <span style="color:#6b7280;">（<?php echo esc_html(self::human_diff($paid_at)); ?>）</span>
                    <?php else : ?>
                        <span style="color:#9ca3af;"><?php esc_html_e('（未支付或付款时间未记录）', 'at8-moonlight-shop'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($fail_msg) : ?>
                <tr>
                    <th><?php esc_html_e('失败信息', 'at8-moonlight-shop'); ?></th>
                    <td style="color:#dc2626;"><?php echo esc_html($fail_msg); ?></td>
                </tr>
            <?php endif; ?>
            <?php if (!empty($extra)) : ?>
                <tr>
                    <th><?php esc_html_e('其它标识', 'at8-moonlight-shop'); ?></th>
                    <td>
                        <?php foreach ($extra as $label => $val) : ?>
                            <div><span style="color:#6b7280;"><?php echo esc_html($label); ?>：</span><code style="font-size:11px;"><?php echo esc_html($val); ?></code></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * meta box #2：订单商品（只读，原始 _mlshop_items）。
     */
    public function render_meta_box_items($post)
    {
        $items = get_post_meta($post->ID, '_mlshop_items', true);
        $currency = (string) get_post_meta($post->ID, '_mlshop_currency', true);
        if (!is_array($items) || empty($items)) {
            echo '<p style="color:#9ca3af;">' . esc_html__('（该订单无商品记录，可能是充值/升级订单）', 'at8-moonlight-shop') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped mlshop-order-items-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('商品', 'at8-moonlight-shop'); ?></th>
                    <th style="width:80px;"><?php esc_html_e('单价', 'at8-moonlight-shop'); ?></th>
                    <th style="width:70px;"><?php esc_html_e('数量', 'at8-moonlight-shop'); ?></th>
                    <th style="width:100px;"><?php esc_html_e('小计', 'at8-moonlight-shop'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it) :
                $pid = isset($it['id']) ? (int) $it['id'] : 0;
                $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
                $sub = isset($it['subtotal']) ? (float) $it['subtotal'] : ((isset($it['price']) ? (float) $it['price'] : 0) * $qty);
                $title = isset($it['title']) ? $it['title'] : '';
                if (!$title && $pid) {
                    $title = get_the_title($pid);
                }
                ?>
                <tr>
                    <td>
                        <?php if ($pid && get_post_type($pid) === 'mlshop_product') : ?>
                            <a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html($title); ?></a>
                            <span style="color:#9ca3af;">（#<?php echo esc_html($pid); ?>）</span>
                        <?php else : ?>
                            <?php echo esc_html($title ?: '—'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $qty > 0 ? esc_html($currency . ' ' . number_format($sub / max(1, $qty), 2)) : '—'; ?></td>
                    <td><?php echo (int) $qty; ?></td>
                    <td><?php echo esc_html($currency . ' ' . number_format($sub, 2)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * meta box #3：物流信息（地址只读 + 4 字段可编辑保存）。
     */
    public function render_meta_box_shipping($post)
    {
        $addr   = get_post_meta($post->ID, '_mlshop_shipping_address', true);
        $pickup = (string) get_post_meta($post->ID, '_mlshop_pickup', true);
        $company = (string) get_post_meta($post->ID, '_mlshop_tracking_company', true);
        $no      = (string) get_post_meta($post->ID, '_mlshop_tracking_no', true);
        $time    = (string) get_post_meta($post->ID, '_mlshop_tracking_time', true);
        $remark  = (string) get_post_meta($post->ID, '_mlshop_tracking_remark', true);

        // 地址回填到下面的字段（一次性只读，避免和字段冲突）
        ?>
        <p><strong><?php esc_html_e('收货地址', 'at8-moonlight-shop'); ?></strong></p>
        <?php if ('1' === $pickup) : ?>
            <p style="margin:0 0 8px;"><span class="mlshop-pill" style="background:#ecfdf5;color:#047857;"><?php esc_html_e('到店自提（免运费）', 'at8-moonlight-shop'); ?></span></p>
        <?php endif; ?>
        <?php if (is_array($addr) && !empty($addr)) : ?>
            <div class="mlshop-order-shipping-addr" style="background:#f8fafc;border:1px solid #e4e9f0;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:12.5px;line-height:1.7;color:#374151;">
                <?php
                $name  = isset($addr['name'])  ? trim($addr['name'])  : '';
                $phone = isset($addr['phone']) ? trim($addr['phone']) : '';
                $addr1 = isset($addr['addr1']) ? trim($addr['addr1']) : (isset($addr['address']) ? trim($addr['address']) : '');
                $addr2 = isset($addr['addr2']) ? trim($addr['addr2']) : '';
                // 区码 + resolve 后名称快照（新版地址）；旧订单回退 state/city 文本
                $state = isset($addr['province_name']) ? trim($addr['province_name']) : (isset($addr['state']) ? trim($addr['state']) : '');
                $city  = '';
                if (isset($addr['city_name'])) {
                    $city = trim($addr['city_name']);
                } elseif (isset($addr['city'])) {
                    $city = trim((string) $addr['city']);
                    if (preg_match('/^CN-/i', $city) && class_exists('Moonlight_Region_Provider')) {
                        $r = Moonlight_Region_Provider::resolve($city);
                        $city = $r ? $r['name'] : '';
                    }
                }
                $zip   = isset($addr['zip'])   ? trim($addr['zip'])   : '';
                $country = isset($addr['country']) ? trim($addr['country']) : '';
                if ($name) echo '<div><strong>' . esc_html($name) . '</strong>';
                if ($phone) echo ' <span style="color:#6b7280;">' . esc_html($phone) . '</span>';
                if ($name) echo '</div>';
                $cityline = trim(implode(' ', array_filter(array($state, $city, $zip, $country))));
                if ($cityline) echo '<div>' . esc_html($cityline) . '</div>';
                if ($addr1) echo '<div>' . esc_html($addr1) . '</div>';
                if ($addr2) echo '<div>' . esc_html($addr2) . '</div>';
                ?>
            </div>
        <?php else : ?>
            <p style="color:#9ca3af;font-size:12.5px;"><?php esc_html_e('（非实物订单，无收货地址）', 'at8-moonlight-shop'); ?></p>
        <?php endif; ?>

        <p><strong><?php esc_html_e('物流公司', 'at8-moonlight-shop'); ?></strong></p>
        <input type="text" name="mlshop_tracking_company" value="<?php echo esc_attr($company); ?>" class="widefat" placeholder="顺丰 / 中通 / SF Express ...">
        <p><strong><?php esc_html_e('物流单号', 'at8-moonlight-shop'); ?></strong></p>
        <input type="text" name="mlshop_tracking_no" value="<?php echo esc_attr($no); ?>" class="widefat" placeholder="1234567890">
        <p><strong><?php esc_html_e('发货时间', 'at8-moonlight-shop'); ?></strong></p>
        <input type="datetime-local" name="mlshop_tracking_time" value="<?php echo esc_attr(preg_replace('/\s+/', 'T', $time)); ?>" class="widefat">
        <p><strong><?php esc_html_e('备注', 'at8-moonlight-shop'); ?></strong></p>
        <textarea name="mlshop_tracking_remark" rows="3" class="widefat" placeholder="例：已签收 / 客户改地址 / 退回原因 ..."><?php echo esc_textarea($remark); ?></textarea>

        <?php
        // ===== 物流第二批：发货单区块（可多条）=====
        $shipments = class_exists('MLSHOP_Shipping') ? MLSHOP_Shipping::get_shipments($post->ID) : array();
        if (!empty($shipments)) :
            ?>
            <p style="margin-top:14px;"><strong><?php esc_html_e('发货单', 'at8-moonlight-shop'); ?>（<?php echo count($shipments); ?>）</strong></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('物流公司', 'at8-moonlight-shop'); ?></th>
                        <th><?php esc_html_e('运单号', 'at8-moonlight-shop'); ?></th>
                        <th><?php esc_html_e('状态', 'at8-moonlight-shop'); ?></th>
                        <th><?php esc_html_e('创建时间', 'at8-moonlight-shop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($shipments as $ship) : ?>
                    <tr>
                        <td><?php echo esc_html($ship['company'] ?: '—'); ?></td>
                        <td><code><?php echo esc_html($ship['no']); ?></code></td>
                        <td><?php echo esc_html($ship['status_label']); ?></td>
                        <td><?php echo esc_html($ship['created']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <hr style="margin:14px 0 10px;">
        <p><strong><?php esc_html_e('创建发货单并标记已发货', 'at8-moonlight-shop'); ?></strong></p>
        <p>
            <select name="mlship_new_company_code" aria-label="<?php esc_attr_e('物流公司（Provider 支持列表）', 'at8-moonlight-shop'); ?>">
                <option value=""><?php esc_html_e('— 选择物流公司 —', 'at8-moonlight-shop'); ?></option>
                <?php
                $provider  = class_exists('MLSHOP_Shipping') ? MLSHOP_Shipping::active_provider() : null;
                $companies = $provider ? (array) $provider->get_supported_companies() : array();
                foreach ($companies as $c) {
                    if (empty($c['code']) || empty($c['name'])) {
                        continue;
                    }
                    echo '<option value="' . esc_attr($c['code']) . '">' . esc_html($c['name']) . '</option>';
                }
                ?>
            </select>
        </p>
        <input type="text" name="mlship_new_company" class="widefat" placeholder="<?php esc_attr_e('或手工输入公司名（非空时优先于下拉）', 'at8-moonlight-shop'); ?>">
        <p><strong><?php esc_html_e('运单号', 'at8-moonlight-shop'); ?></strong></p>
        <input type="text" name="mlship_new_no" class="widefat" placeholder="SF1234567890">
        <p><strong><?php esc_html_e('发货备注', 'at8-moonlight-shop'); ?></strong></p>
        <input type="text" name="mlship_new_note" class="widefat" placeholder="<?php esc_attr_e('可选，写入轨迹首条', 'at8-moonlight-shop'); ?>">
        <p style="margin-top:10px;">
            <button type="submit" name="mlshop_create_shipment" value="1" class="button button-primary">
                <?php esc_html_e('创建发货单并标记已发货', 'at8-moonlight-shop'); ?>
            </button>
        </p>
        <p class="description">
            <?php esc_html_e('订单需处于待发货/处理中状态；创建后订单自动转为「已发货」。同一订单可创建多条发货单（多包裹分开发货）。轨迹由自动查询（快递100 Provider）或人工维护。', 'at8-moonlight-shop'); ?>
        </p>
        <?php
    }

    /**
     * 保存物流 4 字段（订单信息与商品只读 meta 不可误改，由 set_status / 支付回调写入）。
     *
     * 1. nonce + 自删能力校验
     * 2. autosave 不处理
     * 3. 4 字段 sanitize
     */
    public function save_meta_box($post_id, $post)
    {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST['mlshop_order_meta_nonce']) || !wp_verify_nonce($_POST['mlshop_order_meta_nonce'], 'mlshop_order_meta')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if ($post->post_type !== 'mlshop_order') {
            return;
        }
        // 仅商品订单有物流意义（实物商品 _mlshop_has_physical=1）— 但管理员手动填写不强制，
        // 留口子让管理员手工补录充值/会员订单的备注场景。
        if (isset($_POST['mlshop_tracking_company'])) {
            update_post_meta($post_id, '_mlshop_tracking_company', sanitize_text_field(wp_unslash($_POST['mlshop_tracking_company'])));
        }
        if (isset($_POST['mlshop_tracking_no'])) {
            update_post_meta($post_id, '_mlshop_tracking_no', sanitize_text_field(wp_unslash($_POST['mlshop_tracking_no'])));
        }
        if (isset($_POST['mlshop_tracking_time'])) {
            $raw = sanitize_text_field(wp_unslash($_POST['mlshop_tracking_time']));
            // datetime-local: "2026-08-28T10:30" → 规范化为 "Y-m-d H:i:s" 存
            $ts = strtotime($raw);
            $val = $ts ? wp_date('Y-m-d H:i:s', $ts) : '';
            update_post_meta($post_id, '_mlshop_tracking_time', $val);
        }
        if (isset($_POST['mlshop_tracking_remark'])) {
            update_post_meta($post_id, '_mlshop_tracking_remark', sanitize_textarea_field(wp_unslash($_POST['mlshop_tracking_remark'])));
        }

        // ===== 物流第二批：创建发货单并标记已发货 =====
        // nonce（mlshop_order_meta）与 manage_options 均已在上方校验通过。
        if (isset($_POST['mlshop_create_shipment']) && class_exists('MLSHOP_Shipping')) {
            $company_code = isset($_POST['mlship_new_company_code']) ? sanitize_text_field(wp_unslash($_POST['mlship_new_company_code'])) : '';
            $company_text = isset($_POST['mlship_new_company']) ? sanitize_text_field(wp_unslash($_POST['mlship_new_company'])) : '';
            $ship_no      = isset($_POST['mlship_new_no']) ? sanitize_text_field(wp_unslash($_POST['mlship_new_no'])) : '';
            $ship_note    = isset($_POST['mlship_new_note']) ? sanitize_textarea_field(wp_unslash($_POST['mlship_new_note'])) : '';

            $res = MLSHOP_Shipping::create_shipment($post_id, array(
                // 手工输入公司名优先：非空时忽略下拉代码，避免「名字与代码不配对」
                'company_code' => ('' !== $company_text) ? '' : $company_code,
                'company'      => $company_text,
                'tracking_no'  => $ship_no,
                'note'         => $ship_note,
            ));
            if (is_wp_error($res)) {
                // 非法转换 / 参数缺失：后台提示（发货单不会半途创建）
                set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
                    'gateway' => 'SHIP',
                    'success' => false,
                    'message' => $res->get_error_message(),
                ), 60);
            } else {
                set_transient('mlshop_admin_notice_' . get_current_user_id(), array(
                    'gateway' => 'SHIP',
                    'success' => true,
                    /* translators: 1: 发货单 ID, 2: 运单号 */
                    'message' => sprintf(__('发货单 #%1$d 已创建（运单号 %2$s），订单已标记为已发货。', 'at8-moonlight-shop'), (int) $res, $ship_no),
                ), 60);
            }
        }
    }

    /* === meta box 辅助函数 === */

    /**
     * meta box #4：售后与退款（售后申请列表 + 退款操作表单 + 退款日志）。
     *
     * 退款入口（admin_post mlshop_order_refund）链路：manage_options + nonce +
     * 规则闸 can_refund + Moonlight_Refund_Service::process()；结果走
     * mlshop_admin_notice_{uid} transient 通知（与发货单/状态变更同一模式）。
     */
    public function render_meta_box_refund($post)
    {
        if ($post->post_type !== 'mlshop_order') {
            return;
        }
        $order_id = (int) $post->ID;
        if (!class_exists('Moonlight_Refund_Service')) {
            echo '<p style="color:#9ca3af;">' . esc_html__('（售后模块不可用。）', 'at8-moonlight-shop') . '</p>';
            return;
        }
        $status     = MLSHOP_Order::get_status($order_id);
        $currency   = (string) get_post_meta($order_id, '_mlshop_currency', true);
        $total      = (float) get_post_meta($order_id, '_mlshop_total', true);
        $refunded   = Moonlight_Refund_Service::refunded_total($order_id);
        $requests   = Moonlight_Refund_Service::requests($order_id);
        $log        = Moonlight_Refund_Service::refund_log($order_id);
        $gate       = Moonlight_Refund_Service::can_refund($order_id);
        $req_labels = array(
            'pending'  => __('待处理', 'at8-moonlight-shop'),
            'approved' => __('已批准', 'at8-moonlight-shop'),
            'rejected' => __('已拒绝', 'at8-moonlight-shop'),
        );

        if ($refunded > 0) {
            echo '<p>' . sprintf(
                /* translators: 1: 货币符号, 2: 已退金额, 3: 积分名称, 4: 已退积分 */
                esc_html__('已退款累计：%1$s %2$s / %3$s %4$s', 'at8-moonlight-shop'),
                esc_html($currency),
                esc_html(number_format($refunded, 2)),
                esc_html($currency),
                esc_html(number_format($total, 2))
            ) . '</p>';
        }

        // ---- 售后申请列表 ----
        echo '<p><strong>' . esc_html__('售后申请', 'at8-moonlight-shop') . '</strong></p>';
        if (empty($requests)) {
            echo '<p style="color:#9ca3af;">' . esc_html__('（暂无售后申请）', 'at8-moonlight-shop') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>'
                . '<th>' . esc_html__('用户', 'at8-moonlight-shop') . '</th>'
                . '<th>' . esc_html__('原因', 'at8-moonlight-shop') . '</th>'
                . '<th style="width:140px;">' . esc_html__('时间', 'at8-moonlight-shop') . '</th>'
                . '<th style="width:80px;">' . esc_html__('状态', 'at8-moonlight-shop') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($requests as $r) {
                $uid  = isset($r['user_id']) ? (int) $r['user_id'] : 0;
                $user = $uid ? get_userdata($uid) : null;
                $rstatus = isset($r['status']) ? (string) $r['status'] : 'pending';
                echo '<tr>'
                    . '<td>' . esc_html($user ? sprintf('%s (#%d)', $user->display_name, $uid) : sprintf('#%d', $uid)) . '</td>'
                    . '<td>' . esc_html(isset($r['reason']) ? (string) $r['reason'] : '') . '</td>'
                    . '<td>' . esc_html(isset($r['at']) ? (string) $r['at'] : '') . '</td>'
                    . '<td>' . esc_html(isset($req_labels[$rstatus]) ? $req_labels[$rstatus] : $rstatus) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }

        // ---- 退款日志（含全额/部分，已退款订单亦展示）----
        if (!empty($log)) {
            echo '<p style="margin-top:14px;"><strong>' . esc_html__('退款日志', 'at8-moonlight-shop') . '</strong></p>';
            echo '<table class="widefat striped"><thead><tr>'
                . '<th style="width:140px;">' . esc_html__('时间', 'at8-moonlight-shop') . '</th>'
                . '<th style="width:80px;">' . esc_html__('操作者', 'at8-moonlight-shop') . '</th>'
                . '<th style="width:100px;">' . esc_html__('金额', 'at8-moonlight-shop') . '</th>'
                . '<th style="width:110px;">' . esc_html__('网关退款号', 'at8-moonlight-shop') . '</th>'
                . '<th style="width:70px;">' . esc_html__('类型', 'at8-moonlight-shop') . '</th>'
                . '<th>' . esc_html__('原因', 'at8-moonlight-shop') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($log as $entry) {
                $actor = isset($entry['actor']) ? (int) $entry['actor'] : 0;
                echo '<tr>'
                    . '<td>' . esc_html(isset($entry['at']) ? (string) $entry['at'] : '') . '</td>'
                    . '<td>' . esc_html($actor ? '#' . $actor : '—') . '</td>'
                    . '<td>' . esc_html($currency . ' ' . number_format((float) (isset($entry['amount']) ? $entry['amount'] : 0), 2)) . '</td>'
                    . '<td><code>' . esc_html(isset($entry['gateway_refund_id']) && '' !== $entry['gateway_refund_id'] ? $entry['gateway_refund_id'] : '—') . '</code></td>'
                    . '<td>' . esc_html(!empty($entry['partial']) ? __('部分', 'at8-moonlight-shop') : __('全额', 'at8-moonlight-shop')) . '</td>'
                    . '<td>' . esc_html(isset($entry['reason']) ? (string) $entry['reason'] : '') . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }

        // ---- 退款操作表单（规则闸通过才显示；拒绝原因给管理员提示）----
        if (is_wp_error($gate)) {
            echo '<p class="description" style="margin-top:12px;">'
                /* translators: %s: 值 */
                . esc_html(sprintf(__('当前订单不可通过本表单退款：%s。如需协商退款，可使用上方「更新状态」改为已退款（仅标记，不调网关）。', 'at8-moonlight-shop'), $gate->get_error_message()))
                . '</p>';
            return;
        }

        echo '<hr style="margin:14px 0 10px;">';
        echo '<p><strong>' . esc_html__('执行退款', 'at8-moonlight-shop') . '</strong></p>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mlshop_order_refund'); ?>
            <input type="hidden" name="action" value="mlshop_order_refund">
            <input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
            <p>
                <label for="mlshop_refund_amount"><?php esc_html_e('退款金额', 'at8-moonlight-shop'); ?></label>
                <?php echo esc_html($currency); ?>
                <input type="text" id="mlshop_refund_amount" name="refund_amount" class="small-text" placeholder="<?php echo esc_attr(number_format($total, 2)); ?>">
                <span class="description"><?php /* translators: 1: 货币符号, 2: 订单总额 */ echo esc_html(sprintf(__('留空 = 全额（%1$s %2$s）', 'at8-moonlight-shop'), $currency, number_format($total, 2))); ?></span>
            </p>
            <p>
                <label for="mlshop_refund_reason"><?php esc_html_e('退款原因', 'at8-moonlight-shop'); ?></label><br>
                <textarea id="mlshop_refund_reason" name="refund_reason" rows="2" class="widefat"></textarea>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="skip_gateway" value="1">
                    <?php esc_html_e('已在网关后台手动退款（仅标记，跳过网关 API）', 'at8-moonlight-shop'); ?>
                </label>
            </p>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('执行退款', 'at8-moonlight-shop'); ?></button>
            </p>
            <p class="description">
                <?php esc_html_e('全额退款成功后订单转为「已退款」，自动回滚库存、回补余额/回收充值积分、释放优惠券名额；卡密不回滚。余额网关订单不调网关 API，由状态机回补钱包。', 'at8-moonlight-shop'); ?>
            </p>
        </form>
        <?php
    }

    private static function type_label_zh($raw)
    {
        $map = array(
            ''           => __('商品订单', 'at8-moonlight-shop'),
            'recharge'   => __('积分充值', 'at8-moonlight-shop'),
            'membership' => __('会员升级', 'at8-moonlight-shop'),
            'paywall'    => __('付费内容', 'at8-moonlight-shop'),
        );
        return isset($map[$raw]) ? $map[$raw] : $raw;
    }

    private static function gateway_label_zh($gw)
    {
        $map = array(
            'cod'      => __('货到付款', 'at8-moonlight-shop'),
            'balance'  => __('余额支付', 'at8-moonlight-shop'),
            'credit'   => __('积分支付', 'at8-moonlight-shop'),
            'manual'   => __('线下转账', 'at8-moonlight-shop'),
            'stripe'   => __('Stripe', 'at8-moonlight-shop'),
            'paypal'   => __('PayPal', 'at8-moonlight-shop'),
            'alipay'   => __('支付寶', 'at8-moonlight-shop'),
            'wechat'   => __('微信支付', 'at8-moonlight-shop'),
        );
        return isset($map[$gw]) ? $map[$gw] : ($gw ?: '—');
    }

    /**
     * 读「其它标识」meta（充值金额 / 升级目标 / 付费内容 ID）。
     */
    private static function read_extra_meta($post_id)
    {
        $out = array();
        $credit = get_post_meta($post_id, '_mlshop_credit_amount', true);
        if ($credit !== '') $out[__('充值积分', 'at8-moonlight-shop')] = $credit;
        $mb = get_post_meta($post_id, '_mlshop_membership_target', true);
        if ($mb) $out[__('升级目标等级', 'at8-moonlight-shop')] = $mb;
        $pw = get_post_meta($post_id, '_mlshop_paywall_post', true);
        if ($pw) {
            $t = get_the_title($pw);
            $out[__('付费内容', 'at8-moonlight-shop')] = '#' . $pw . ($t ? ' ' . $t : '');
        }
        return $out;
    }

    /**
     * mysql datetime → 「N 天前 / N 小时前」友好文案。
     */
    private static function human_diff($mysql_dt)
    {
        $ts = strtotime($mysql_dt);
        if (!$ts) return '';
        $diff = current_time('timestamp') - $ts;
        if ($diff < 60) return __('刚刚', 'at8-moonlight-shop');
        /* translators: %d: 数量 */
        if ($diff < 3600) return sprintf(__('%d 分钟前', 'at8-moonlight-shop'), (int) ($diff / 60));
        /* translators: %d: 数量 */
        if ($diff < 86400) return sprintf(__('%d 小时前', 'at8-moonlight-shop'), (int) ($diff / 3600));
        /* translators: %d: 数量 */
        if ($diff < 86400 * 30) return sprintf(__('%d 天前', 'at8-moonlight-shop'), (int) ($diff / 86400));
        return wp_date('Y-m-d', $ts);
    }

    public static function register_post_type()
    {
        /**
         * 订单 CPT labels 必须全量填写。
         * 仅填 name/singular_name 时，WP 会回退到内置 post 的标签，
         * 后台 list 表头会显示「写文章」「未找到文章」等与订单无关的字样。
         */
        register_post_type('mlshop_order', array(
            'labels'             => array(
                'name'                  => __('订单', 'at8-moonlight-shop'),
                'singular_name'         => __('订单', 'at8-moonlight-shop'),
                'menu_name'             => __('订单', 'at8-moonlight-shop'),
                'name_admin_bar'        => __('订单', 'at8-moonlight-shop'),
                'add_new'               => __('添加订单', 'at8-moonlight-shop'),
                'add_new_item'          => __('添加新订单', 'at8-moonlight-shop'),
                'new_item'              => __('新订单', 'at8-moonlight-shop'),
                'edit_item'             => __('编辑订单', 'at8-moonlight-shop'),
                'view_item'             => __('查看订单', 'at8-moonlight-shop'),
                'view_items'            => __('查看订单', 'at8-moonlight-shop'),
                'all_items'             => __('全部订单', 'at8-moonlight-shop'),
                'search_items'          => __('搜索订单', 'at8-moonlight-shop'),
                'not_found'             => __('暂无订单。', 'at8-moonlight-shop'),
                'not_found_in_trash'    => __('回收站中暂无订单。', 'at8-moonlight-shop'),
                'parent_item_colon'     => null,
                'archives'              => __('订单归档', 'at8-moonlight-shop'),
                'attributes'            => __('订单属性', 'at8-moonlight-shop'),
                'insert_into_item'      => __('插入至订单', 'at8-moonlight-shop'),
                'uploaded_to_this_item' => __('上传到本订单', 'at8-moonlight-shop'),
                'featured_image'        => __('订单封面图', 'at8-moonlight-shop'),
                'set_featured_image'    => __('设置订单封面图', 'at8-moonlight-shop'),
                'remove_featured_image' => __('移除订单封面图', 'at8-moonlight-shop'),
                'use_featured_image'    => __('设为订单封面图', 'at8-moonlight-shop'),
                'filter_items_list'     => __('筛选订单列表', 'at8-moonlight-shop'),
                'filter_by_date'        => __('按日期筛选订单', 'at8-moonlight-shop'),
                'items_list_navigation' => __('订单列表导航', 'at8-moonlight-shop'),
                'items_list'            => __('订单列表', 'at8-moonlight-shop'),
                'item_published'        => __('订单已发布。', 'at8-moonlight-shop'),
                'item_published_privately' => __('订单已私密发布。', 'at8-moonlight-shop'),
                'item_reverted_to_draft' => __('订单已恢复为草稿。', 'at8-moonlight-shop'),
                'item_trashed'          => __('订单已删除。', 'at8-moonlight-shop'),
                'item_scheduled'        => __('订单已排入计划。', 'at8-moonlight-shop'),
                'item_updated'          => __('订单已更新。', 'at8-moonlight-shop'),
                'item_link'             => __('订单链接', 'at8-moonlight-shop'),
                'item_link_description' => __('当前订单的链接。', 'at8-moonlight-shop'),
                'template_name'         => __('单个订单', 'at8-moonlight-shop'),
            ),
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=mlshop_product',
            'capability_type'    => 'post',
            'supports'           => array('title'),
            'rewrite'            => false,
        ));

        self::register_statuses();
    }

    /**
     * 注册订单自定义状态，使其在后台列表与筛选中正常显示。
     *
     * 关键：必须把 show_in_admin_all_list 也设为 true，否则 WP 6.x 后
     * `edit.php` 默认走的所有页（status 未传 = 'all'）不会包含这些 internal 状态，
     * 列表里 13 条订单但表格仍为「未找到订单」。count 链接显示 13 是因为后端
     * `wp_count_posts()` 用了 show_in_admin_status_list，但默认列表 query 不一样。
     */
    public static function register_statuses()
    {
        // 标签单一来源（物流第二批重构）：注册列表完全由 get_status_labels() 派生，
        // 后台列表筛选 / 统计分布 / 前台展示共用同一份映射。
        foreach (self::get_status_labels() as $key => $label) {
            register_post_status('mlshop_' . $key, array(
                // phpcs:ignore WordPress.WP.I18n.MissingArgDomain, WordPress.WP.I18n.NonSingularStringLiteralSingular, WordPress.WP.I18n.NonSingularStringLiteralPlural -- 动态标签
                'label'                     => $label,
                'public'                    => false,
                'internal'                  => true,
                'exclude_from_search'       => true,
                'show_in_admin_all'         => true,
                'show_in_admin_status_list' => true,
                'show_in_admin_all_list'    => true,
                // phpcs:ignore WordPress.WP.I18n.MissingArgDomain,WordPress.WP.I18n.NonSingularStringLiteralSingular,WordPress.WP.I18n.NonSingularStringLiteralPlural -- 动态状态标签
                'label_count'               => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>'),
            ));
        }
    }

    /**
     * 全部订单状态 → 中文标签（单一来源，物流第二批新增三状态）。
     *
     * functions.php 的 mlshop_get_order_statuses()、统计页分布图、后台通知
     * 均委托到这里（修复审计「标签映射三处重复」技术债）。
     *
     * @return array slug => label
     */
    public static function get_status_labels()
    {
        return apply_filters('mlshop_order_status_labels', array(
            'pending'           => __('待付款', 'at8-moonlight-shop'),
            'paid'              => __('已付款', 'at8-moonlight-shop'),
            'processing'        => __('处理中', 'at8-moonlight-shop'),
            'awaiting_shipment' => __('待发货', 'at8-moonlight-shop'),
            'shipped'           => __('已发货', 'at8-moonlight-shop'),
            'delivered'         => __('已签收', 'at8-moonlight-shop'),
            'completed'         => __('已完成', 'at8-moonlight-shop'),
            'failed'            => __('支付失败', 'at8-moonlight-shop'),
            'refunded'          => __('已退款', 'at8-moonlight-shop'),
            'cancelled'         => __('已取消', 'at8-moonlight-shop'),
        ));
    }

    /**
     * 单个状态 → 中文标签（未知状态原样返回）。
     *
     * @param string $status
     * @return string
     */
    public static function get_status_label($status)
    {
        $labels = self::get_status_labels();
        $status = (string) $status;
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * 计入销售额（已收款口径）的状态集合（物流第二批扩容）。
     *
     * paid 之后的所有未流失状态（含 awaiting_shipment/shipped/delivered）
     * 都算已收款；pending / failed / refunded / cancelled 不计。
     *
     * @return array
     */
    public static function get_revenue_statuses()
    {
        return apply_filters('mlshop_revenue_statuses', array(
            'paid', 'processing', 'awaiting_shipment', 'shipped', 'delivered', 'completed',
        ));
    }

    /**
     * 从购物车创建订单。
     *
     * @param int    $user_id
     * @param string $gateway_id
     * @param string $coupon_code      优惠码（可选）
     * @param array  $shipping_address 收货地址（实物订单；自提单为提货人信息）
     * @param array  $args             报价附加参数（透传 Price_Calculator::quote，
     *                                 如 ['shipping_mode'=>'pickup']）
     */
    public static function create_from_cart($user_id, $gateway_id, $coupon_code = '', $shipping_address = array(), $args = array())
    {
        $cart = MLSHOP_Cart::get_instance();
        $items = $cart->get_items();
        if (empty($items)) {
            return new WP_Error('empty', __('购物车为空。', 'at8-moonlight-shop'));
        }

        // 统一报价（Moonlight_Price_Calculator：小计 → 优惠券 → 运费 → 合计）。
        $quote = Moonlight_Price_Calculator::quote($items, $coupon_code, $args);
        $subtotal = $quote['subtotal'];
        $total    = $quote['total'];
        $shipping = $quote['shipping'];
        $has_physical = $quote['has_physical'];

        $coupon_meta = array();
        if ($quote['coupon_valid'] && $quote['discount'] > 0) {
            // 原子预留名额，防并发超发（两人同时用同一限次码时仅一人能预留成功）
            if (Moonlight_Price_Calculator::reserve_coupon($quote['coupon_id'])) {
                $coupon_meta = array(
                    '_mlshop_coupon_code' => strtoupper(trim((string) $coupon_code)),
                    '_mlshop_coupon_discount' => $quote['discount'],
                    '_mlshop_coupon_reserved' => '1',
                );
            }
            // 预留失败（名额已满）：不报错，按原价下单，避免阻断购买
        }

        // 任一失败路径都要释放已预留的优惠券名额（修复审计 M1：名额泄漏）。
        $release_coupon = function () use ($coupon_meta) {
            if (!empty($coupon_meta['_mlshop_coupon_code']) && class_exists('MLSHOP_Coupon')) {
                MLSHOP_Coupon::release($coupon_meta['_mlshop_coupon_code']);
            }
        };

        // 库存校验（下单前拦截超卖）：_mlshop_stock 为空或 0 视为不限量。
        // 卡密商品以池内剩余条数为准（池是真实库存，计数器只是冗余显示）。
        foreach ($items as $it) {
            $chk_pid = isset($it['id']) ? (int) $it['id'] : 0;
            $chk_qty = isset($it['qty']) ? (int) $it['qty'] : 0;
            if (!$chk_pid || $chk_qty <= 0) {
                continue;
            }
            $chk_stock = (int) get_post_meta($chk_pid, '_mlshop_stock', true);
            if ('cardkey' === get_post_meta($chk_pid, '_mlshop_type', true)) {
                // 卡密以库存池真实可售数为准（加密批次模型 available 计数；
                // 未迁移的旧商品自动回落到明文池行数），计数器只是冗余显示。
                $pool_count = Moonlight_Card_Stock::available($chk_pid);
                if ($pool_count > 0) {
                    $chk_stock = ($chk_stock > 0) ? min($chk_stock, $pool_count) : $pool_count;
                }
            }
            if ($chk_stock > 0 && $chk_qty > $chk_stock) {
                $release_coupon();
                return new WP_Error(
                    'stock',
                    /* translators: 1: 商品名, 2: 剩余库存 */
                    sprintf(__('「%1$s」库存不足，仅剩 %2$d 件。', 'at8-moonlight-shop'), get_the_title($chk_pid), $chk_stock)
                );
            }
        }

        // 库存原子扣减（fail-closed，修复审计 H2 超卖）：扣减失败（并发期间被买走）
        // 必须拒绝下单并回滚本次已扣商品，不允许「清零兜底继续建单」。
        // 实物 / 虚拟在此扣减；卡密由交付時 pop_cardkey 扣除，避免雙扣。
        $decremented = array();
        foreach ($items as $item) {
            $pid = (int) $item['id'];
            if (!$pid || 'cardkey' === get_post_meta($pid, '_mlshop_type', true)) {
                continue;
            }
            $stock = (int) get_post_meta($pid, '_mlshop_stock', true);
            if ($stock <= 0) {
                continue; // 0 / -1 / 空 = 不限量
            }
            if (mlshop_atomic_decrement_post_meta($pid, '_mlshop_stock', (int) $item['qty'])) {
                $decremented[] = array($pid, (int) $item['qty']);
                continue;
            }
            foreach ($decremented as $d) {
                mlshop_atomic_increment_post_meta($d[0], '_mlshop_stock', $d[1]);
            }
            $release_coupon();
            return new WP_Error(
                'stock',
                /* translators: 1: 商品名, 2: 剩余库存 */
                sprintf(__('「%1$s」库存不足，仅剩 %2$d 件。', 'at8-moonlight-shop'), get_the_title($pid), $stock - 1)
            );
        }

        $order_id = wp_insert_post(array(
            'post_title'  => 'MLS-' . wp_date('Ymd') . '-' . wp_generate_password(5, false, false),
            'post_type'   => 'mlshop_order',
            'post_status' => 'mlshop_pending',
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id)) {
            // 建单失败：回滚已扣库存 + 释放优惠券（修复审计 M1）
            foreach ($decremented as $d) {
                mlshop_atomic_increment_post_meta($d[0], '_mlshop_stock', $d[1]);
            }
            $release_coupon();
            return $order_id;
        }

        update_post_meta($order_id, '_mlshop_order_no', Moonlight_Migrations::generate_order_no());
        update_post_meta($order_id, '_mlshop_customer', (int) $user_id);
        update_post_meta($order_id, '_mlshop_user_id', $user_id);
        update_post_meta($order_id, '_mlshop_items', $items);
        update_post_meta($order_id, '_mlshop_subtotal', $subtotal);
        update_post_meta($order_id, '_mlshop_total', $total);
        update_post_meta($order_id, '_mlshop_discount', $quote['discount']);
        update_post_meta($order_id, '_mlshop_shipping', $shipping);
        update_post_meta($order_id, '_mlshop_has_physical', $has_physical ? '1' : '0');
        if ($has_physical && is_array($shipping_address) && !empty($shipping_address)) {
            update_post_meta($order_id, '_mlshop_shipping_address', $shipping_address);
        }
        // 到店自提订单：记录标记（物流信息 meta box / 邮件据此展示「自提」）
        if ($has_physical && isset($args['shipping_mode']) && 'pickup' === $args['shipping_mode']) {
            update_post_meta($order_id, '_mlshop_pickup', '1');
        }
        update_post_meta($order_id, '_mlshop_gateway', $gateway_id);
        update_post_meta($order_id, '_mlshop_status', 'pending');
        update_post_meta($order_id, '_mlshop_currency', mlshop_get_option('currency_symbol', 'HK$'));
        update_post_meta($order_id, '_mlshop_created', current_time('mysql'));
        foreach ($coupon_meta as $k => $v) {
            update_post_meta($order_id, $k, $v);
        }

        $cart->clear();
        return $order_id;
    }

    /**
     * 为付费内容直接创建专用订单（不经过购物车）。
     *
     * @param int    $user_id
     * @param int    $post_id   mlshop_product ID
     * @param string $gateway_id
     * @return int|WP_Error
     */
    public static function create_for_paywall($user_id, $post_id, $gateway_id)
    {
        $user_id = (int) $user_id;
        $post_id = (int) $post_id;
        // 付费内容可挂在 商品 / 文章 / 页面 上（与 MLSHOP_Product_Pay_Meta 支持范围、
        // MLSHOP_Pay_Access::is_paywalled() 保持一致），否则文章付费墙能显示却永远买不了。
        $pay_pt = $post_id ? get_post_type($post_id) : '';
        if (!$user_id || !$post_id || !in_array($pay_pt, array('mlshop_product', 'post', 'page'), true)) {
            return new WP_Error('invalid', __('内容无效。', 'at8-moonlight-shop'));
        }
        $pay_mode = class_exists('MLSHOP_Product_Pay_Meta')
            ? MLSHOP_Product_Pay_Meta::get($post_id, 'pay_mode', 'off')
            : 'off';
        if ($pay_mode === 'off') {
            return new WP_Error('not_paywalled', __('该内容未启用付费。', 'at8-moonlight-shop'));
        }

        // 按会员等级取价：统一走 Moonlight_Price_Calculator（修复审计「取价逻辑三处分散」）
        $price = Moonlight_Price_Calculator::paywall_price($post_id, $user_id);
        if ($price <= 0) {
            return new WP_Error('noprice', __('价格未设置。', 'at8-moonlight-shop'));
        }

        $title = get_the_title($post_id);
        $order_id = wp_insert_post(array(
            'post_title'  => 'MLS-PW-' . wp_date('Ymd') . '-' . wp_generate_password(5, false, false),
            'post_type'   => 'mlshop_order',
            'post_status' => 'mlshop_pending',
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id)) {
            return $order_id;
        }

        update_post_meta($order_id, '_mlshop_user_id', $user_id);
        update_post_meta($order_id, '_mlshop_items', array(array('id' => $post_id, 'qty' => 1, 'price' => $price, 'title' => $title)));
        update_post_meta($order_id, '_mlshop_total', $price);
        update_post_meta($order_id, '_mlshop_gateway', $gateway_id);
        update_post_meta($order_id, '_mlshop_status', 'pending');
        update_post_meta($order_id, '_mlshop_currency', mlshop_get_option('currency_symbol', 'HK$'));
        update_post_meta($order_id, '_mlshop_paywall_post', $post_id);
        update_post_meta($order_id, '_mlshop_created', current_time('mysql'));

        return $order_id;
    }

    /**
     * 创建积分充值订单（不经过购物车）。
     *
     * @param int    $user_id
     * @param float  $credit  充值积分数
     * @param float  $price   应付金额（货币）
     * @param string $gateway_id
     * @return int|WP_Error
     */
    public static function create_recharge($user_id, $credit, $price, $gateway_id)
    {
        $user_id = (int) $user_id;
        $credit  = (float) $credit;
        $price   = (float) $price;
        if (!$user_id) {
            return new WP_Error('invalid', __('用户无效。', 'at8-moonlight-shop'));
        }
        if ($credit <= 0 || $price <= 0) {
            return new WP_Error('invalid', __('充值金额无效。', 'at8-moonlight-shop'));
        }

        $credit_name = mlshop_get_option('credit_name', __('积分', 'at8-moonlight-shop'));
        $order_id = wp_insert_post(array(
            'post_title'  => 'MLS-RC-' . wp_date('Ymd') . '-' . wp_generate_password(5, false, false),
            'post_type'   => 'mlshop_order',
            'post_status' => 'mlshop_pending',
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id)) {
            return $order_id;
        }

        update_post_meta($order_id, '_mlshop_user_id', $user_id);
        update_post_meta($order_id, '_mlshop_type', 'recharge');
        update_post_meta($order_id, '_mlshop_credit_amount', $credit);
        update_post_meta($order_id, '_mlshop_items', array(
            /* translators: 1: 充值金额, 2: 积分名称 */
            array('title' => sprintf(__('%1$s %2$s 充值', 'at8-moonlight-shop'), $credit, $credit_name), 'qty' => 1, 'subtotal' => $price),
        ));
        update_post_meta($order_id, '_mlshop_total', $price);
        update_post_meta($order_id, '_mlshop_gateway', $gateway_id);
        update_post_meta($order_id, '_mlshop_status', 'pending');
        update_post_meta($order_id, '_mlshop_currency', mlshop_get_option('currency_symbol', 'HK$'));
        update_post_meta($order_id, '_mlshop_created', current_time('mysql'));

        return $order_id;
    }

    /**
     * 创建会员升级订单（不经过购物车）。
     *
     * @param int    $user_id
     * @param string $level  目标等级 key（gold/diamond/monthly/premium）
     * @param float  $price  应付金额
     * @param string $gateway_id
     * @return int|WP_Error
     */
    public static function create_membership($user_id, $level, $price, $gateway_id)
    {
        $user_id = (int) $user_id;
        $price   = (float) $price;
        if (!$user_id) {
            return new WP_Error('invalid', __('用户无效。', 'at8-moonlight-shop'));
        }
        if ($price <= 0) {
            return new WP_Error('invalid', __('升级价格无效。', 'at8-moonlight-shop'));
        }
        $label = class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label($level) : $level;
        $order_id = wp_insert_post(array(
            'post_title'  => 'MLS-MB-' . wp_date('Ymd') . '-' . wp_generate_password(5, false, false),
            'post_type'   => 'mlshop_order',
            'post_status' => 'mlshop_pending',
            'post_author' => $user_id,
        ));
        if (is_wp_error($order_id)) {
            return $order_id;
        }
        update_post_meta($order_id, '_mlshop_user_id', $user_id);
        update_post_meta($order_id, '_mlshop_type', 'membership');
        update_post_meta($order_id, '_mlshop_membership_target', $level);
        update_post_meta($order_id, '_mlshop_items', array(
            /* translators: %s: 值 */
            array('title' => sprintf(__('%s 升级', 'at8-moonlight-shop'), $label), 'qty' => 1, 'subtotal' => $price),
        ));
        update_post_meta($order_id, '_mlshop_total', $price);
        update_post_meta($order_id, '_mlshop_gateway', $gateway_id);
        update_post_meta($order_id, '_mlshop_status', 'pending');
        update_post_meta($order_id, '_mlshop_currency', mlshop_get_option('currency_symbol', 'HK$'));
        update_post_meta($order_id, '_mlshop_created', current_time('mysql'));

        return $order_id;
    }

    /**
     * 标记订单为已支付并触发交付。
     *
     * @param int    $order_id
     * @param string $gateway 支付网关 ID（可选，记录交易来源）
     * @param string $txn_id  网关交易号 / Payment Intent（可选）
     */
    public static function mark_paid($order_id, $gateway = '', $txn_id = '')
    {
        if ($gateway) {
            update_post_meta($order_id, '_mlshop_payment_gateway', $gateway);
        }
        if ($txn_id) {
            update_post_meta($order_id, '_mlshop_payment_id', $txn_id);
        }
        return self::set_status($order_id, 'paid');
    }

    public static function mark_processing($order_id)
    {
        return self::set_status($order_id, 'processing');
    }

    /**
     * 标记订单为已完成（管理员确认收货后调用）。
     *
     * @param int $order_id
     */
    public static function mark_completed($order_id)
    {
        return self::set_status($order_id, 'completed');
    }

    /**
     * 标记订单为已退款（会自动回滚库存）。
     *
     * @param int $order_id
     */
    public static function mark_refunded($order_id)
    {
        return self::set_status($order_id, 'refunded');
    }

    /**
     * 标记订单为已取消（pending 阶段取消会自动回滚库存）。
     *
     * @param int $order_id
     */
    public static function mark_cancelled($order_id)
    {
        return self::set_status($order_id, 'cancelled');
    }

    /**
     * 标记订单为支付失败（允许从 failed 重新回到 pending 重试）。
     *
     * @param int    $order_id
     * @param string $message 失败原因（可选）
     */
    public static function mark_failed($order_id, $message = '')
    {
        return self::set_status($order_id, 'failed', array('message' => $message));
    }

    /**
     * 统一的状态变更入口：校验转换合法性、同步 post_status 与 meta、
     * 在需要时回滚库存，并触发 mlshop_order_<status> 钩子。
     *
     * @param int    $order_id
     * @param string $new   目标状态（pending/paid/processing/awaiting_shipment/shipped/delivered/completed/failed/refunded/cancelled）
     * @param array  $extra 附加数据（如 failed 的 message）
     * @return bool|WP_Error
     */
    public static function set_status($order_id, $new, $extra = array())
    {
        $new     = strtolower($new);
        $current = self::get_status($order_id);
        if ($current === $new) {
            return true;
        }
        if (!self::can_transition($current, $new)) {
            return new WP_Error(
                'invalid_transition',
                /* translators: 1: 当前状态, 2: 目标状态 */
                sprintf(__('订单状态不允许从 %1$s 变更为 %2$s。', 'at8-moonlight-shop'), $current, $new)
            );
        }

        // 库存回滚：pending/processing/待发货/已付款 取消或失败，或已付款后退款。
        // awaiting_shipment 取消 = 货未出库，回滚库存；shipped 之后取消属异常件，
        // 库存不自动回滚（由管理员走 refunded 处理退货入库）。
        // 审计 M3：paid → cancelled 此前只回资金不回库存（与 refunded 不对称），
        // 现已把 paid 纳入 cancelled 的回滚来源——资金回退与库存回滚同步发生。
        if (in_array($new, array('cancelled', 'failed'), true) && in_array($current, array('pending', 'processing', 'awaiting_shipment', 'paid'), true)) {
            self::restore_stock($order_id);
        }
        if ('refunded' === $new && in_array($current, array('paid', 'processing', 'awaiting_shipment', 'shipped', 'delivered', 'completed'), true)) {
            self::restore_stock($order_id);
        }

        // 审计 M2：failed → pending 重开此前不重新持有库存（failed 时已回滚），
        // 后续退款会再回滚一次 → 库存凭空多出。重开时原子重新扣减，库存不足则拒绝重开。
        if ('failed' === $current && 'pending' === $new) {
            $rehold = self::rehold_stock($order_id);
            if (is_wp_error($rehold)) {
                return $rehold;
            }
        }

        // 资金回退：退款 / 已付款后取消时，余额支付回补钱包、充值订单回收已发积分
        if (in_array($new, array('refunded', 'cancelled'), true)) {
            self::maybe_reverse_funds($order_id);
            self::maybe_release_coupon($order_id);
        }

        wp_update_post(array('ID' => $order_id, 'post_status' => 'mlshop_' . $new));
        update_post_meta($order_id, '_mlshop_status', $new);

        // 物流第二批：签收时间戳（auto_complete 超期自动完成的判定依据）
        if ('delivered' === $new) {
            update_post_meta($order_id, '_mlshop_delivered_at', current_time('mysql'));
        }

        if ('failed' === $new && !empty($extra['message'])) {
            update_post_meta($order_id, '_mlshop_fail_message', sanitize_text_field($extra['message']));
        }

        do_action('mlshop_order_status_changed', $order_id, $current, $new);
        do_action('mlshop_order_' . $new, $order_id);
        return true;
    }

    /**
     * 读取订单当前状态（meta 为权威来源）。
     */
    public static function get_status($order_id)
    {
        $status = get_post_meta($order_id, '_mlshop_status', true);
        return $status ? $status : 'pending';
    }

    /**
     * 判断订单是否处于某状态。
     */
    public static function is_status($order_id, $status)
    {
        return self::get_status($order_id) === $status;
    }

    /**
     * 状态转换白名单（物流第二批扩展：awaiting_shipment / shipped / delivered）。
     *
     * 实物链路：paid/processing → awaiting_shipment（待发货）
     *   → shipped（管理员发货）→ delivered（轨迹推送/用户确认前的已签收态）
     *   → completed（用户确认收货 或 auto_complete 超期自动完成）。
     */
    public static function get_allowed_transitions($from)
    {
        $map = array(
            'pending'           => array('paid', 'failed', 'cancelled', 'processing'),
            'paid'              => array('processing', 'awaiting_shipment', 'completed', 'refunded', 'cancelled'),
            // 允许 processing -> paid：订单可能已被管理员/物流流程先置为「处理中」，
            // 此时网关回调( Stripe/PayPal webhook )再 mark_paid 会被拒绝并静默失败，
            // 造成「已收款但不交付/不授予会员」。交付类钩子均有幂等标记，重复触发安全。
            'processing'        => array('paid', 'completed', 'awaiting_shipment', 'refunded', 'cancelled', 'failed'),
            // 待发货：管理员发货 / 取消 / 退款（货未出库，取消需回滚库存）
            'awaiting_shipment' => array('shipped', 'cancelled', 'refunded'),
            // 已发货：签收（轨迹推送或人工）/ 取消 / 退款
            'shipped'           => array('delivered', 'cancelled', 'refunded'),
            // 已签收（待用户确认）：确认收货完成 / 退款
            'delivered'         => array('completed', 'refunded'),
            'completed'         => array('refunded'),
            'failed'            => array('pending', 'cancelled'),
            'refunded'          => array(),
            'cancelled'         => array(),
        );
        return isset($map[$from]) ? $map[$from] : array();
    }

    /**
     * 校验 from -> to 是否为合法转换。
     */
    public static function can_transition($from, $to)
    {
        return in_array($to, self::get_allowed_transitions($from), true);
    }

    /**
     * 回滚订单商品的库存（仅作用于 mlshop_product 且有库存配置的商品）。
     *
     * 修复审计 M2：回滚改为原子累加（与扣减对称），并发退款不丢回补量；
     * 无限量商品（'' 或负数）不回滚（原本会错误地把 -1 加成 0、把空值变成有限库存）。
     */
    private static function restore_stock($order_id)
    {
        // 审计 H2：抢占式幂等——并发退款/取消只有一个进入回补段（旧写法是裸原子累加，
        // 双并发会双倍回库存）。失败→pending 重开会删除该标记（见 rehold_stock），
        // 保证后续退款仍可回滚。
        if (!add_post_meta($order_id, '_mlshop_stock_restored', current_time('mysql'), true)) {
            return;
        }
        $items = get_post_meta($order_id, '_mlshop_items', true);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (empty($item['id'])) {
                continue;
            }
            $pid = (int) $item['id'];
            if (get_post_type($pid) !== 'mlshop_product') {
                continue;
            }
            if ('cardkey' === get_post_meta($pid, '_mlshop_type', true)) {
                continue; // 卡密库存由交付時管理，回滾不在此處
            }
            $raw = get_post_meta($pid, '_mlshop_stock', true);
            if ('' === $raw || (int) $raw < 0) {
                continue; // 不限量
            }
            mlshop_atomic_increment_post_meta($pid, '_mlshop_stock', (int) $item['qty']);
        }
    }

    /**
     * failed → pending 重开时重新持有库存（restore_stock 的逆操作）。
     *
     * 逐项原子扣减；任一项库存不足即回滚本轮已扣减的项并拒绝重开，
     * 避免出现「部分持货」的中间态。成功后清除 _mlshop_stock_restored 标记，
     * 使订单后续退款/取消时 restore_stock 能再次生效。
     *
     * @return true|WP_Error
     */
    private static function rehold_stock($order_id)
    {
        $items = get_post_meta($order_id, '_mlshop_items', true);
        if (!is_array($items)) {
            return true;
        }
        $held = array();
        foreach ($items as $item) {
            if (empty($item['id'])) {
                continue;
            }
            $pid = (int) $item['id'];
            if (get_post_type($pid) !== 'mlshop_product') {
                continue;
            }
            if ('cardkey' === get_post_meta($pid, '_mlshop_type', true)) {
                continue; // 卡密库存由交付時管理
            }
            $raw = get_post_meta($pid, '_mlshop_stock', true);
            if ('' === $raw || (int) $raw < 0) {
                continue; // 不限量
            }
            if (mlshop_atomic_decrement_post_meta($pid, '_mlshop_stock', (int) $item['qty'])) {
                $held[] = array($pid, (int) $item['qty']);
                continue;
            }
            // 库存不足：回滚本轮已扣减的项，拒绝重开
            foreach ($held as $h) {
                mlshop_atomic_increment_post_meta($h[0], '_mlshop_stock', $h[1]);
            }
            return new WP_Error(
                'mlshop_rehold_stock',
                /* translators: %d: 数量 */
                sprintf(__('商品 #%d 库存不足，无法重新打开订单。', 'at8-moonlight-shop'), $pid)
            );
        }
        delete_post_meta($order_id, '_mlshop_stock_restored');
        return true;
    }

    /**
     * 退款 / 已付款后取消时回退资金（幂等），防止资损：
     *  - 余额支付订单：把已扣钱包余额回补到同一账本 _mlshop_balance
     *    （修复审计 H1「双账本串账」：余额扣的是 _mlshop_balance，
     *    回补必须回到同一账本，禁止写进积分账本 mlshop_credit_balance）；
     *  - 充值订单：回收已入账的积分（用户已花掉则记录告警，不重复回退）。
     * 未实际付款的 pending 取消（无 payment_id）不触发余额回补。
     */
    private static function maybe_reverse_funds($order_id)
    {
        // 审计 C2/H2/H3 修复：回补权经 add_post_meta(unique) 原子抢占——并发的
        // 退款 / 取消 / 网关补偿只有一个能进入回补段。关键细节：**仅在确有资金
        // 可回补时才抢旗标**——pending 空单取消若也抢旗标，会把随后网关补偿
        // （扣了钱但完单失败）挡在门外，造成资金丢失。
        $uid     = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $type    = get_post_meta($order_id, '_mlshop_type', true);
        $gateway = get_post_meta($order_id, '_mlshop_payment_gateway', true);

        $balance_paid = 0.0;
        if ('balance' === $gateway && $uid > 0) {
            // 以扣款凭据 `_mlshop_balance_spent`（网关扣款成功时写入）为准。
            // 旧版只认 `_mlshop_payment_id`，而余额网关 mark_paid 从不传交易号 →
            // 凭据永远为空 → 退款/取消时钱包从不回补（审计 C1，用户静默损失货款）。
            // 兼容旧数据：`_mlshop_payment_id` 有值仍视为已扣款（按订单总额回补）。
            $balance_paid = (float) get_post_meta($order_id, '_mlshop_balance_spent', true);
            if ($balance_paid <= 0 && get_post_meta($order_id, '_mlshop_payment_id', true)) {
                $balance_paid = (float) get_post_meta($order_id, '_mlshop_total', true);
            }
        }
        $recharge_recall = ('recharge' === $type && $uid > 0 && get_post_meta($order_id, '_mlshop_recharge_granted', true))
            ? (float) get_post_meta($order_id, '_mlshop_credit_amount', true)
            : 0.0;
        $credit_return = ('credit' === $gateway && $uid > 0)
            ? (float) get_post_meta($order_id, '_mlshop_credit_spent', true)
            : 0.0;

        if ($balance_paid <= 0 && $recharge_recall <= 0 && $credit_return <= 0) {
            return; // 无任何资金动过：不抢旗标、不回补
        }
        if (!add_post_meta($order_id, '_mlshop_funds_reversed', current_time('mysql'), true)) {
            return; // 并发的退款/取消/补偿已处理
        }

        if ($balance_paid > 0) {
            // 回补到余额钱包本身（原子累加），与扣款账本一致
            mlshop_atomic_increment_user_meta($uid, '_mlshop_balance', $balance_paid);
        }

        if ($recharge_recall > 0) {
            // 修复：读取键与写入键一致（create_recharge 写入 _mlshop_credit_amount，
            // 此处曾误读 _mlshop_credit 导致充值退款永远收不回积分）。
            /* translators: %d: 数量 */
            $remaining = MLSHOP_Credit::spend($uid, $recharge_recall, sprintf(__('订单 #%d 退款回收充值积分', 'at8-moonlight-shop'), $order_id));
            if (false === $remaining) {
                update_post_meta($order_id, '_mlshop_recharge_revoke_short', $recharge_recall);
            }
        }

        // 积分支付订单：按实际扣减量原路返还积分（退款 / 已付款后取消均触发）。
        // 与充值回收相互独立：充值订单没有 _mlshop_credit_spent，积分支付订单
        // 没有 _mlshop_recharge_granted，两条账目不会互相串扰。
        if ($credit_return > 0 && class_exists('MLSHOP_Credit')) {
            /* translators: %d: 数量 */
            MLSHOP_Credit::add($uid, $credit_return, sprintf(__('订单 #%d 退款返还积分', 'at8-moonlight-shop'), $order_id));
        }
    }

    /**
     * 订单取消 / 退款时回补优惠券名额（幂等），避免名额被占死。
     *
     * 仅对确实用过券（_mlshop_coupon_code 存在）且尚未回补的订单生效。
     */
    private static function maybe_release_coupon($order_id)
    {
        if (get_post_meta($order_id, '_mlshop_coupon_released', true)) {
            return;
        }
        $code = get_post_meta($order_id, '_mlshop_coupon_code', true);
        if (!$code) {
            return;
        }
        if (class_exists('MLSHOP_Coupon')) {
            MLSHOP_Coupon::release($code);
        }
        update_post_meta($order_id, '_mlshop_coupon_released', '1');
    }

    /**
     * 注册 WP-Cron 事件（若尚未调度）。DISABLE_WP_CRON 环境下不会自动触发，
     * 因此同时提供惰性过期（maybe_expire）作为兜底。
     */
    public static function maybe_schedule_cron()
    {
        if (!wp_next_scheduled('mlshop_expire_pending_orders')) {
            wp_schedule_event(time(), 'hourly', 'mlshop_expire_pending_orders');
        }
    }

    /**
     * 惰性过期：查看订单时若已超时才取消（不依赖 cron）。
     *
     * @param int $order_id
     */
    public static function maybe_expire($order_id)
    {
        $minutes = (int) mlshop_get_option('order_expire_minutes', 30);
        if ($minutes <= 0) {
            return;
        }
        if (self::get_status($order_id) !== 'pending') {
            return;
        }
        // 线下(扫码)订单本就等待管理员确认收款，不参与自动过期。
        // 关键（2026-08-30 修复）：必须读 `_mlshop_gateway`（建单时写入的「下单网关」），
        // 不能读 `_mlshop_payment_gateway` —— 后者只在 mark_paid() 付款成功时才写入，
        // 而本方法只处理 pending 未付款订单，该 meta 此刻必然为空 → 豁免 100% 失效，
        // 会把等待人工确认收款的线下订单误判超时而取消（用户可能已转账，引发纠纷）。
        if ('manual' === get_post_meta($order_id, '_mlshop_gateway', true)) {
            return;
        }
        $created = get_post_meta($order_id, '_mlshop_created', true);
        if (!$created) {
            return;
        }
        $ts = strtotime($created);
        if ($ts && (current_time('timestamp') - $ts) > $minutes * 60) {
            self::mark_cancelled($order_id);
        }
    }

    /**
     * 批量取消所有超时的未支付订单（由 WP-Cron 调用）。
     *
     * 物流第二批：同一 hourly 调度里顺带执行——
     *  1) delivered 订单超期自动完成（auto_complete，option auto_complete_days）；
     *  2) 发货轨迹自动查询兜底（15 分钟专用调度 moonlight_shipping_sync 是主路径，
     *     这里做惰性兜底，带 10 分钟节流，页面加载绝不触发）。
     */
    public static function expire_pending_orders()
    {
        $minutes = (int) mlshop_get_option('order_expire_minutes', 30);
        if ($minutes > 0) {
            $orders = get_posts(array(
                'post_type'      => 'mlshop_order',
                'posts_per_page' => 200,
                'post_status'    => 'mlshop_pending',
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'ASC',
            ));
            foreach ($orders as $id) {
                self::maybe_expire($id);
            }
        }

        // 物流第二批兜底（仅 cron 上下文调用本方法，页面加载不经过这里）
        if (class_exists('MLSHOP_Shipping')) {
            MLSHOP_Shipping::auto_complete_orders();
            MLSHOP_Shipping::run_shipping_sync(false); // false = 带 10 分钟节流的兜底
        }
    }

    public static function get_user_orders($user_id, $limit = 20)
    {
        // R1 性能优化（Phase 12 实测驱动）：原 meta_query 实现对
        // _mlshop_user_id 的 meta_value 匹配是索引外全扫，10 万订单线性恶化。
        // 改为 posts⋈postmeta 直连 SQL（meta_key 索引命中 + 主键回表取行），
        // 输出仍为 WP_Post 数组，行为与原实现一致。
        $ids = static::query_user_order_ids((int) $user_id, max(1, (int) $limit));
        $out = array();
        foreach ($ids as $id) {
            $p = get_post((int) $id);
            if ($p && 'mlshop_order' === $p->post_type) {
                $out[] = $p;
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- 参数已白名单化
            }
        }
        return $out;
    }

    /**
     * R1：按用户取订单 ID（protected 供测试覆盖接桩）。
     *
     * @return int[] 按创建时间倒序
     */
    protected static function query_user_order_ids($user_id, $limit)
    {
        global $wpdb;
        $stati = array('mlshop_pending', 'mlshop_paid', 'mlshop_processing', 'mlshop_awaiting_shipment', 'mlshop_shipped', 'mlshop_delivered', 'mlshop_completed', 'mlshop_failed', 'mlshop_refunded', 'mlshop_cancelled');
        $in = "'" . implode("','", array_map('esc_sql', $stati)) . "'";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- 参数已白名单化
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m
                    ON m.post_id = p.ID AND m.meta_key = '_mlshop_user_id' AND m.meta_value = %s
                 WHERE p.post_type = 'mlshop_order'
                   AND p.post_status IN ($in)
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                (string) $user_id,
                (int) $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? array_map('intval', wp_list_pluck($rows, 'ID')) : array();
    }

    /**
     * 单个订单查看短代码：[mlshop_order id="123"]。
     */
    public function shortcode_order($atts)
    {
        // 缓存兼容：订单详情含卡密 / 下载链接等敏感内容，禁止页面缓存（计划书第五十九节）
        mlshop_no_cache();
        $atts = shortcode_atts(array('id' => 0), $atts, 'mlshop_order');
        $order_id = (int) $atts['id'];
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            return '';
        }
        if (!current_user_can('manage_options') && (int) get_post_meta($order_id, '_mlshop_user_id', true) !== get_current_user_id()) {
            return '<p>' . esc_html__('无权查看该订单。', 'at8-moonlight-shop') . '</p>';
        }
        self::maybe_expire($order_id);
        ob_start();
        mlshop_get_template('order', array(
            'order_id' => $order_id,
            'back_url' => mlshop_get_orders_url(),
        ));
        return ob_get_clean();
    }
}

