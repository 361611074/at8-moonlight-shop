<?php
/**
 * 后台「系统状态」：支付 / License 环境体检（用户中心 → 系统状态）。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_System_Status
{
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
        add_action('admin_menu', array($this, 'register_menu'));
    }

    public function register_menu()
    {
        add_submenu_page(
            'mluc-settings',
            __('系统状态', 'moonlight-user-center'),
            __('系统状态', 'moonlight-user-center'),
            'manage_options',
            'mluc-status',
            array($this, 'render_page')
        );
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $ok   = '<span style="color:#00a32a;">✔</span> ';
        $warn = '<span style="color:#dba617;">⚠</span> ';
        $bad  = '<span style="color:#d63638;">✘</span> ';

        $gateways = MLUC_Payment_Manager::get_instance()->get_all();
        $alipay   = isset($gateways['alipay']) ? $gateways['alipay'] : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('系统状态', 'moonlight-user-center'); ?></h1>

            <h2><?php esc_html_e('环境', 'moonlight-user-center'); ?></h2>
            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th><?php esc_html_e('WordPress 版本', 'moonlight-user-center'); ?></th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?>（<?php echo version_compare(get_bloginfo('version'), '5.8', '>=') ? $ok . esc_html__('满足最低要求 5.8', 'moonlight-user-center') : $bad . esc_html__('低于最低要求 5.8', 'moonlight-user-center'); ?>）</td></tr>
                    <tr><th><?php esc_html_e('PHP 版本', 'moonlight-user-center'); ?></th>
                        <td><?php echo esc_html(PHP_VERSION); ?>（<?php echo version_compare(PHP_VERSION, '7.4', '>=') ? $ok . esc_html__('满足最低要求 7.4', 'moonlight-user-center') : $bad . esc_html__('低于最低要求 7.4', 'moonlight-user-center'); ?>）</td></tr>
                    <tr><th><?php esc_html_e('OpenSSL 扩展（支付宝 RSA2）', 'moonlight-user-center'); ?></th>
                        <td><?php echo class_exists('MLUC_Gateway_Alipay') ? (MLUC_Gateway_Alipay::openssl_available() ? $ok . esc_html__('可用', 'moonlight-user-center') : $bad . esc_html__('不可用，支付宝网关无法工作', 'moonlight-user-center')) : $warn . esc_html__('网关未并入（Phase C）', 'moonlight-user-center'); ?></td></tr>
                    <tr><th><?php esc_html_e('结算货币代码', 'moonlight-user-center'); ?></th>
                        <td><?php echo esc_html(class_exists('MLUC_Payments') ? MLUC_Payments::currency_code() : '—'); ?>
                            <?php if (class_exists('MLUC_Payments') && 'CNY' === MLUC_Payments::currency_code()) : ?>
                                （<?php echo esc_html__('支付宝网关可用', 'moonlight-user-center'); ?>）
                            <?php else : ?>
                                <span class="description">（<?php echo esc_html__('支付宝仅支持 CNY；切换为 CNY 后购买页才会出现支付宝选项', 'moonlight-user-center'); ?>）</span>
                            <?php endif; ?>
                        </td></tr>
                    <tr><th><?php esc_html_e('账户中心页面', 'moonlight-user-center'); ?></th>
                        <td><?php echo (int) mluc_get_option('account_page_id', 0) ? $ok . esc_html__('已配置', 'moonlight-user-center') : $warn . esc_html__('未配置，支付回跳将回退到 /account/', 'moonlight-user-center'); ?></td></tr>
                    <tr><th><?php esc_html_e('支付调试日志', 'moonlight-user-center'); ?></th>
                        <td><?php echo MLUC_Payment_Log::debug_enabled() ? $warn . esc_html__('已开启（排查用，建议平时关闭）', 'moonlight-user-center') : $ok . esc_html__('关闭', 'moonlight-user-center'); ?></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('支付网关', 'moonlight-user-center'); ?></h2>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('网关', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('状态', 'moonlight-user-center'); ?></th>
                        <th><?php esc_html_e('说明', 'moonlight-user-center'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gateways as $id => $gw) :
                        $available = $gw->is_available();
                        $enabled   = false;
                        if ('manual' === $id) {
                            $enabled = !empty(mluc_get_option('manual_enabled', 1));
                            $note = $enabled ? '' : __('线下转账开关未开启', 'moonlight-user-center');
                        } elseif ('paypal' === $id) {
                            $enabled = (bool) mluc_get_option('paypal_enabled', 0);
                            $note = $enabled && !$available ? __('凭据未配置完整', 'moonlight-user-center') : '';
                        } elseif ('stripe' === $id) {
                            $enabled = (bool) mluc_get_option('stripe_enabled', 0);
                            $note = $enabled && !$available ? __('Secret Key 未配置', 'moonlight-user-center') : '';
                        } elseif ('alipay' === $id) {
                            $enabled = (bool) mluc_get_option('alipay_enabled', 0);
                            $note = $enabled && !$available
                                ? (MLUC_Gateway_Alipay::openssl_available()
                                    ? __('配置未完整（App ID / 私钥 / 支付宝公钥）', 'moonlight-user-center')
                                    : __('OpenSSL 不可用', 'moonlight-user-center'))
                                : '';
                        } else {
                            $note = '';
                        }
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($id); ?></code>（<?php echo esc_html($gw->get_name()); ?>）</td>
                            <td><?php echo $available ? $ok . esc_html__('可用', 'moonlight-user-center') : ($enabled ? $warn . esc_html__('已启用但不可用', 'moonlight-user-center') : $bad . esc_html__('未启用', 'moonlight-user-center')); ?></td>
                            <td><?php echo esc_html($note); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('回调地址', 'moonlight-user-center'); ?></h2>
            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th style="width:280px;"><?php esc_html_e('支付宝异步通知（notify_url）', 'moonlight-user-center'); ?></th>
                        <td><code><?php echo esc_html(class_exists('MLUC_Gateway_Alipay') ? MLUC_Gateway_Alipay::notify_url() : '—'); ?></code></td></tr>
                    <tr><th><?php esc_html_e('Stripe Webhook', 'moonlight-user-center'); ?></th>
                        <td><code><?php echo esc_html(rest_url('mluc/v1/stripe-webhook')); ?></code></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('License / Pro', 'moonlight-user-center'); ?></h2>
            <table class="widefat striped" style="max-width:900px;">
                <tbody>
                    <tr><th style="width:280px;"><?php esc_html_e('License Server 地址', 'moonlight-user-center'); ?></th>
                        <td><?php echo esc_html('' !== trim((string) mluc_get_option('license_server_url', '')) ? mluc_get_option('license_server_url', '') : __('未配置（本地验证模式）', 'moonlight-user-center')); ?></td></tr>
                    <tr><th><?php esc_html_e('License 总数 / 有效', 'moonlight-user-center'); ?></th>
                        <td><?php
                        $all = wp_count_posts(MLUC_License_Manager::CPT);
                        $total = isset($all->publish) ? (int) $all->publish : 0;
                        $actives = get_posts(array(
                            'post_type' => MLUC_License_Manager::CPT, 'post_status' => 'publish',
                            'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
                            'meta_query' => array(array('key' => '_mluc_license_status', 'value' => 'active')),
                        ));
                        $active_n = 0;
                        foreach ($actives as $lid) {
                            if ('active' === MLUC_License_Manager::effective_status($lid)) {
                                $active_n++;
                            }
                        }
                        echo esc_html($total . ' / ' . $active_n);
                        ?></td></tr>
                    <tr><th><?php esc_html_e('Pro 扩展插件', 'moonlight-user-center'); ?></th>
                        <td><?php echo (class_exists('MLUCP_License_Client') || defined('MLUCP_VERSION')) ? $ok . esc_html__('已安装', 'moonlight-user-center') : esc_html__('未安装（Free 功能不受影响）', 'moonlight-user-center'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
