<?php
/**
 * 积分体系前端：用户中心「积分余额」Tab、充值下单 AJAX、订单付款后积分入账。
 *
 * 依赖 moonlight-user-center 的账户 Tab 机制（mluc_account_tabs）。
 * 充值的积分余额来自 MLSHOP_Credit（Stage 2 基础层）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Credit_UI
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
        // 仅在用户中心可用时挂入账户 Tab
        add_filter('mluc_account_tabs', array($this, 'register_tab'));
        // 充值下单（需登录）
        add_action('wp_ajax_mlshop_create_recharge', array($this, 'ajax_create_recharge'));
        // 订单付款后入账积分（線上付款走 paid；貨到付款走 completed；grant_recharge 内部幂等保护）
        add_action('mlshop_order_paid', array($this, 'grant_recharge'), 30);
        add_action('mlshop_order_completed', array($this, 'grant_recharge'), 30);
        // 管理员在用户资料页手动调整积分（仅 manage_options 可见可存）
        add_action('show_user_profile', array($this, 'render_admin_adjust'));
        add_action('edit_user_profile', array($this, 'render_admin_adjust'));
        add_action('personal_options_update', array($this, 'save_admin_adjust'));
        add_action('edit_user_profile_update', array($this, 'save_admin_adjust'));
    }

    /**
     * 在用户中心添加「积分余额」Tab。
     */
    public function register_tab($tabs)
    {
        if (!class_exists('MLSHOP_Credit')) {
            return $tabs;
        }
        $tabs['credit'] = array(
            'title'    => __('积分余额', 'moonlight-shop'),
            'icon'     => 'dashicons-tickets-alt',
            'callback' => array($this, 'tab_credit'),
        );
        return $tabs;
    }

    /**
     * 积分余额 Tab 内容。
     */
    public function tab_credit()
    {
        if (!class_exists('MLSHOP_Credit')) {
            return;
        }
        $user_id  = get_current_user_id();
        $balance  = MLSHOP_Credit::get_balance($user_id);
        $ledger   = MLSHOP_Credit::get_ledger($user_id, 30);
        $packages = mlshop_get_recharge_packages();
        $rate     = mlshop_get_credit_rate();
        $symbol   = mlshop_get_option('currency_symbol', 'HK$');
        $credit_name = mlshop_get_option('credit_name', '积分');

        // 充值可用网关（排除余额支付避免用余额买余额；排除积分支付避免用积分买积分的循环套利）
        $gateways = array();
        if (class_exists('MLSHOP_Payment')) {
            foreach (MLSHOP_Payment::get_instance()->get_gateways() as $g) {
                if (in_array($g->get_id(), array('balance', 'credit'), true)) {
                    continue;
                }
                $gateways[] = $g;
            }
        }

        // 签到（并入模块 MLUC_Checkin，入账与商城积分同一账本；未启用时不传参）
        $checkin_on     = class_exists('MLUC_Checkin') && MLUC_Checkin::enabled();
        $checkin_done   = $checkin_on ? MLUC_Checkin::checked_today($user_id) : false;
        $checkin_streak = $checkin_on ? MLUC_Checkin::get_streak($user_id) : 0;
        $checkin_ajax   = function_exists('mluc_ajax_data') ? mluc_ajax_data() : array();

        mlshop_get_template('account-credit', array(
            'balance'     => $balance,
            'credit_name' => $credit_name,
            'symbol'      => $symbol,
            'ledger'      => $ledger,
            'packages'    => $packages,
            'rate'        => $rate,
            'gateways'    => $gateways,
            'checkin_on'     => $checkin_on,
            'checkin_done'   => $checkin_done,
            'checkin_streak' => $checkin_streak,
            'checkin_ajax'   => $checkin_ajax,
        ));
    }

    /**
     * 充值下单 AJAX。
     */
    public function ajax_create_recharge()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');
        if (!is_user_logged_in()) {
            mlshop_send_json(false, __('请先登录。', 'moonlight-shop'));
        }
        if (!class_exists('MLSHOP_Credit') || !class_exists('MLSHOP_Order') || !class_exists('MLSHOP_Payment')) {
            mlshop_send_json(false, __('充值模块不可用。', 'moonlight-shop'));
        }

        $user_id = get_current_user_id();
        $credit  = 0.0;
        $price   = 0.0;

        $package_idx = isset($_POST['package']) ? trim($_POST['package']) : '';
        if ('' !== $package_idx && 'custom' !== $package_idx) {
            $packages = mlshop_get_recharge_packages();
            $idx = (int) $package_idx;
            if (isset($packages[$idx])) {
                $credit = (float) $packages[$idx]['credit'];
                $price  = (float) $packages[$idx]['price'];
            }
        } else {
            $credit = (float) (isset($_POST['custom_credit']) ? $_POST['custom_credit'] : 0);
            $price  = mlshop_credit_to_currency($credit);
        }

        if ($credit <= 0) {
            mlshop_send_json(false, __('请输入有效的充值积分数。', 'moonlight-shop'));
        }
        if ($price <= 0) {
            mlshop_send_json(false, __('充值金额无效，请检查汇率设置。', 'moonlight-shop'));
        }

        $gateway_id = isset($_POST['gateway']) ? sanitize_key($_POST['gateway']) : '';
        if ('balance' === $gateway_id) {
            mlshop_send_json(false, __('充值不可使用余额支付。', 'moonlight-shop'));
        }
        if ('credit' === $gateway_id) {
            mlshop_send_json(false, __('充值不可使用积分支付。', 'moonlight-shop'));
        }
        $gateway = MLSHOP_Payment::get_instance()->get_gateway($gateway_id);
        if (!$gateway) {
            mlshop_send_json(false, __('支付方式无效。', 'moonlight-shop'));
        }

        $order_id = MLSHOP_Order::create_recharge($user_id, $credit, $price, $gateway_id);
        if (is_wp_error($order_id)) {
            mlshop_send_json(false, $order_id->get_error_message());
        }

        $result = $gateway->process_payment($order_id);
        $data = array('order_id' => $order_id);
        if (!empty($result['redirect'])) {
            $data['redirect'] = $result['redirect'];
        }
        mlshop_send_json(
            !empty($result['success']),
            isset($result['message']) ? $result['message'] : '',
            $data
        );
    }

    /**
     * 充值订单付款完成后入账积分。
     */
    public function grant_recharge($order_id)
    {
        if ('recharge' !== get_post_meta($order_id, '_mlshop_type', true)) {
            return;
        }
        // 幂等：避免 paid 與 completed 雙重觸發導致重複入賬
        if (get_post_meta($order_id, '_mlshop_recharge_granted', true)) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        $credit  = (float) get_post_meta($order_id, '_mlshop_credit_amount', true);
        if ($user_id && $credit > 0 && class_exists('MLSHOP_Credit')) {
            MLSHOP_Credit::add($user_id, $credit, sprintf(__('充值到账（订单 #%s）', 'moonlight-shop'), $order_id));
            update_post_meta($order_id, '_mlshop_recharge_granted', current_time('mysql'));
        }
    }

    /**
     * 用户资料页「手动调整积分」（仅 manage_options 用户可见）。
     *
     * 方向由下拉决定（增加 / 扣减），金额恒为正数——避免正负号歧义与误输负数；
     * 备注必填，进积分流水（后台可审计）。余额不足扣减时直接拒绝，不产生负余额。
     */
    public function render_admin_adjust($user)
    {
        if (!current_user_can('manage_options') || !class_exists('MLSHOP_Credit')) {
            return;
        }
        $balance     = MLSHOP_Credit::get_balance($user->ID);
        $credit_name = mlshop_get_option('credit_name', __('积分', 'moonlight-shop'));
        ?>
        <h2><?php echo esc_html(sprintf(__('%s 管理', 'moonlight-shop'), $credit_name)); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php echo esc_html(sprintf(__('当前%s余额', 'moonlight-shop'), $credit_name)); ?></th>
                <td><strong><?php echo esc_html($balance); ?></strong></td>
            </tr>
            <tr>
                <th><label for="mlshop_credit_adjust"><?php esc_html_e('手动调整', 'moonlight-shop'); ?></label></th>
                <td>
                    <select id="mlshop_credit_adjust_dir" name="mlshop_credit_adjust_dir">
                        <option value="add"><?php esc_html_e('增加', 'moonlight-shop'); ?></option>
                        <option value="deduct"><?php esc_html_e('扣减', 'moonlight-shop'); ?></option>
                    </select>
                    <input type="number" id="mlshop_credit_adjust" name="mlshop_credit_adjust" min="0" step="0.01" class="small-text" placeholder="0">
                    <span class="description"><?php echo esc_html($credit_name); ?></span>
                    <p class="description"><?php esc_html_e('留空 = 不调整。调整会记入用户积分流水（含操作备注），扣减不会使余额变为负数。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mlshop_credit_adjust_note"><?php esc_html_e('调整备注', 'moonlight-shop'); ?></label></th>
                <td>
                    <input type="text" id="mlshop_credit_adjust_note" name="mlshop_credit_adjust_note" class="regular-text" maxlength="190">
                    <p class="description"><?php esc_html_e('必填（仅在填写了调整数额时），如：活动奖励 / 客服补偿 / 违规扣回。', 'moonlight-shop'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * 保存「手动调整积分」表单（profile 相关钩子会对本人资料页也触发，
     * 这里统一以 manage_options 做硬闸，保证只有管理员能调整任何用户）。
     */
    public function save_admin_adjust($user_id)
    {
        if (!current_user_can('manage_options') || !class_exists('MLSHOP_Credit')) {
            return false;
        }
        // 无意提交（未展开该区块的常规资料保存）直接跳过
        $raw = isset($_POST['mlshop_credit_adjust']) ? wp_unslash($_POST['mlshop_credit_adjust']) : '';
        if (!isset($_POST['mlshop_credit_adjust_dir']) && '' === trim((string) $raw)) {
            return false;
        }
        check_admin_referer('update-user_' . $user_id);

        $amount = (float) $raw;
        if ($amount <= 0) {
            return false;
        }
        $dir  = ('deduct' === ($_POST['mlshop_credit_adjust_dir'] ?? '')) ? 'deduct' : 'add';
        $note = sanitize_text_field(wp_unslash($_POST['mlshop_credit_adjust_note'] ?? ''));
        if ('' === $note) {
            // 拒绝无备注的调整：资料页本身没有报错通道，静默跳过并留系统日志痕迹
            error_log(sprintf('[moonlight-shop] credit adjust skipped for user #%d: empty note', $user_id));
            return false;
        }
        $credit_name = mlshop_get_option('credit_name', __('积分', 'moonlight-shop'));
        if ('deduct' === $dir) {
            MLSHOP_Credit::spend($user_id, $amount, sprintf(__('管理员扣减：%1$s（by %2$s）', 'moonlight-shop'), $note, wp_get_current_user()->user_login));
        } else {
            MLSHOP_Credit::add($user_id, $amount, sprintf(__('管理员增加：%1$s（by %2$s）', 'moonlight-shop'), $note, wp_get_current_user()->user_login));
        }
        return true;
    }
}
