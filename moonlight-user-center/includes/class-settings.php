<?php
/**
 * 后台设置页：编辑 mluc_options。
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Settings
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
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue'));

        // 插件列表页的「设置」快捷入口
        add_filter('plugin_action_links_' . plugin_basename(MLUC_UC_FILE), array($this, 'add_action_links'));
    }

    /**
     * 顶级菜单「用户中心」。position=31 让它落在 Comments(25) 之后、Appearance(60) 之前，
     * 避免被滚出侧栏视野。
     */
    public function register_admin_menu()
    {
        add_menu_page(
            __('用户中心', 'moonlight-user-center'),
            __('用户中心', 'moonlight-user-center'),
            'manage_options',
            'mluc-settings',
            array($this, 'render_settings_page'),
            'dashicons-admin-users',
            31
        );
        // 显式把「设置」注册为第一个子菜单：
        // 否则 mluc_avatar CPT 的 show_in_menu='mluc-settings' 抢走第一个子菜单位置，
        // 顶级菜单链接会变成头像库、设置页本身不显示。
        add_submenu_page(
            'mluc-settings',
            __('设置', 'moonlight-user-center'),
            __('设置', 'moonlight-user-center'),
            'manage_options',
            'mluc-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * 仅在「用户中心 → 设置」页加载插件样式与脚本（会员等级表 / 侧栏图标表依赖 .mluc- 前缀样式）。
     */
    public function admin_enqueue()
    {
        if (!isset($_GET['page']) || 'mluc-settings' !== $_GET['page']) {
            return;
        }
        wp_enqueue_style('mluc-style', MLUC_UC_URL . 'assets/css/mluc.css', array(), MLUC_VERSION);
        // WP 媒体库（侧栏菜单图标可上传 PNG / SVG）
        wp_enqueue_media();
        wp_enqueue_script(
            'mluc-admin',
            MLUC_UC_URL . 'assets/js/mluc-admin.js',
            array('jquery'),
            MLUC_VERSION,
            true
        );
    }

    /**
     * 插件列表页添加「设置」链接。
     */
    public function add_action_links($links)
    {
        $url = admin_url('admin.php?page=mluc-settings');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('设置', 'moonlight-user-center') . '</a>';
        return $links;
    }

    /**
     * 用 Settings API 注册选项与字段。
     */
    public function register_settings()
    {
        register_setting('mluc_settings', 'mluc_options', array(
            'sanitize_callback' => array($this, 'sanitize_options'),
        ));

        add_settings_section(
            'mluc_general',
            __('常规设置', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );

        $page_fields = array(
            'account_page_id'      => __('账户中心页面', 'moonlight-user-center'),
            'login_page_id'        => __('登录页面', 'moonlight-user-center'),
            'register_page_id'     => __('注册页面', 'moonlight-user-center'),
            'lostpassword_page_id' => __('找回密码页面', 'moonlight-user-center'),
        );
        foreach ($page_fields as $key => $label) {
            add_settings_field(
                'mluc_' . $key,
                $label,
                array($this, 'render_page_field'),
                'mluc-settings',
                'mluc_general',
                array('key' => $key)
            );
        }

        add_settings_field(
            'mluc_redirect_after_login',
            __('登录后重定向', 'moonlight-user-center'),
            array($this, 'render_redirect_field'),
            'mluc-settings',
            'mluc_general'
        );

        add_settings_field(
            'mluc_enable_avatar',
            __('头像上传', 'moonlight-user-center'),
            array($this, 'render_avatar_field'),
            'mluc-settings',
            'mluc_general'
        );

        // 会员等级定义
        add_settings_section(
            'mluc_membership',
            __('会员等级定义', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_membership_levels',
            __('等级配置', 'moonlight-user-center'),
            array($this, 'render_membership_field'),
            'mluc-settings',
            'mluc_membership'
        );

        // 独立收款（仅装用户中心、未启用商城时生效）
        add_settings_section(
            'mluc_payment',
            __('独立收款（未启用商城时生效）', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_payment',
            __('付款说明', 'moonlight-user-center'),
            array($this, 'render_payment_field'),
            'mluc-settings',
            'mluc_payment'
        );

        // 在线支付（PayPal / Stripe）
        add_settings_section(
            'mluc_payment_online',
            __('在线支付（PayPal / Stripe）', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_payment_online',
            __('网关凭据', 'moonlight-user-center'),
            array($this, 'render_online_payment_field'),
            'mluc-settings',
            'mluc_payment_online'
        );

        // 在线支付（支付宝）
        add_settings_section(
            'mluc_payment_alipay',
            __('在线支付（支付宝）', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_payment_alipay',
            __('支付宝配置', 'moonlight-user-center'),
            array($this, 'render_alipay_field'),
            'mluc-settings',
            'mluc_payment_alipay'
        );

        // License / Pro
        add_settings_section(
            'mluc_license',
            __('License / Pro', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_license',
            __('授权设置', 'moonlight-user-center'),
            array($this, 'render_license_field'),
            'mluc-settings',
            'mluc_license'
        );

        // 邮件通知
        add_settings_section(
            'mluc_email',
            __('邮件通知', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_email',
            __('通知开关', 'moonlight-user-center'),
            array($this, 'render_email_field'),
            'mluc-settings',
            'mluc_email'
        );

        // 积分与余额（v2.1.0）：充值比例 / 兑换比例全部后台可自定义
        add_settings_section(
            'mluc_credit',
            __('积分与余额', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_credit',
            __('积分体系', 'moonlight-user-center'),
            array($this, 'render_credit_field'),
            'mluc-settings',
            'mluc_credit'
        );

        // 订单管理（所有支付方式通用）
        add_settings_section(
            'mluc_orders',
            __('订单管理（所有支付方式通用）', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_orders',
            __('超时自动关闭', 'moonlight-user-center'),
            array($this, 'render_orders_field'),
            'mluc-settings',
            'mluc_orders'
        );

        // 侧栏菜单图标（前端账户中心左侧导航）
        add_settings_section(
            'mluc_nav_icons',
            __('侧栏菜单图标', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_nav_icons',
            __('菜单图标', 'moonlight-user-center'),
            array($this, 'render_nav_icons_field'),
            'mluc-settings',
            'mluc_nav_icons'
        );

        // 界面文案（账户中心侧栏与提示文字，可改成英文等其他语言）
        add_settings_section(
            'mluc_ui_labels',
            __('界面文案（账户中心）', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_ui_labels',
            __('文字自定义', 'moonlight-user-center'),
            array($this, 'render_ui_labels_field'),
            'mluc-settings',
            'mluc_ui_labels'
        );

        // 第三方登录
        add_settings_section(
            'mluc_oauth',
            __('第三方登录', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_oauth',
            __('OAuth 应用凭据', 'moonlight-user-center'),
            array($this, 'render_oauth_field'),
            'mluc-settings',
            'mluc_oauth'
        );

        // 会员头像库入口（合并自独立 CPT 菜单项）
        add_settings_section(
            'mluc_avatar',
            __('会员头像库', 'moonlight-user-center'),
            '__return_false',
            'mluc-settings'
        );
        add_settings_field(
            'mluc_avatar',
            __('头像库', 'moonlight-user-center'),
            array($this, 'render_avatar_library_field'),
            'mluc-settings',
            'mluc_avatar'
        );
    }

    /**
     * 页面下拉字段。
     */
    public function render_page_field($args)
    {
        $key   = $args['key'];
        $value = (int) mluc_get_option($key, 0);
        wp_dropdown_pages(array(
            'name'              => 'mluc_options[' . $key . ']',
            'selected'          => $value,
            'show_option_none'  => __('— 选择页面 —', 'moonlight-user-center'),
            'option_none_value' => 0,
        ));
        $code = '[' . esc_html($this->shortcode_for($key)) . ']';
        echo '<p class="description">' .
             sprintf(esc_html__('该页面需包含短代码 %s。', 'moonlight-user-center'), '<code>' . $code . '</code>') .
             '</p>';
    }

    private function shortcode_for($key)
    {
        $map = array(
            'account_page_id'      => 'mluc_account',
            'login_page_id'        => 'mluc_login',
            'register_page_id'     => 'mluc_register',
            'lostpassword_page_id' => 'mluc_lostpassword',
        );
        return isset($map[$key]) ? $map[$key] : '';
    }

    /**
     * 登录后重定向 URL。
     */
    public function render_redirect_field()
    {
        $value = mluc_get_option('redirect_after_login', '');
        echo '<input type="url" name="mluc_options[redirect_after_login]" value="' .
             esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' .
             esc_html__('留空则登录后跳转到账户中心页面。', 'moonlight-user-center') . '</p>';
    }

    /**
     * 会员头像库开关（管理员在「会员头像库」上传头像，用户在前端选择）。
     */
    public function render_avatar_field()
    {
        $value = mluc_get_option('enable_avatar', 1);
        echo '<label><input type="checkbox" name="mluc_options[enable_avatar]" value="1" ' .
             checked(1, $value, false) . ' /> ' .
             esc_html__('启用会员头像库：管理员在「会员头像库」上传头像，用户在前端账户中心选择', 'moonlight-user-center') . '</label>';
        // checkbox 取消勾选时不会随表单提交，需补 hidden 同键置 0
        echo '<input type="hidden" name="mluc_options[enable_avatar]" value="0" />';
        echo '<p class="description">' .
             esc_html__('启用后：后台「用户中心 → 会员头像库」可上传多张头像；用户在前端账户中心从列表中选择一个作为自己的头像（无需自行上传）。', 'moonlight-user-center') . '</p>';
    }

    /**
     * 会员等级定义表格：支持编辑 / 新增 / 删除等级。
     * free 为系统基座等级（价格/有效期恒为永久），同样支持删除：
     * 删除 free 等同关闭「普通会员」基座，未付费用户视为无等级；
     * 重新勾选顶部「启用普通会员」开关即可恢复。
     */
    public function render_membership_field()
    {
        if (!class_exists('MLUC_Membership')) {
            echo '<p class="description">' . esc_html__('会员模块未加载。', 'moonlight-user-center') . '</p>';
            return;
        }
        $levels = MLUC_Membership::get_levels();
        $free_on = MLUC_Membership::free_enabled();
        $default_colors = array(
            'free'    => '#95a5a6',
            'monthly' => '#3498db',
            'gold'    => '#f1c40f',
            'premium' => '#9b59b6',
            'diamond' => '#1abc9c',
        );
        ?>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[free_level_enabled]" value="1"<?php checked((bool) $free_on); ?>>
                <strong><?php esc_html_e('启用普通会员（免费基座等级）', 'moonlight-user-center'); ?></strong>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('关闭后站点仅保留付费等级：未购买会员的用户不再显示「普通会员」身份；标记为 free 的公开内容仍对所有人可见。', 'moonlight-user-center'); ?>
        </p>
        <p class="description">
            <?php esc_html_e('在此新增、编辑或删除会员等级（含 free 基座等级）。删除某等级后，原拥有该等级的用户会自动降级；删除 free 等级则未付费用户视为无任何等级身份，重新勾选上方「启用普通会员」即可恢复。价格与有效期将用于「升级会员」购买流程（后续阶段接入）。', 'moonlight-user-center'); ?>
        </p>
        <table class="mluc-membership-table widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e('等级标识', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('显示名称', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('价格', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('有效期（天）', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('标识颜色', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('排序', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('操作', 'moonlight-user-center'); ?></th>
                </tr>
            </thead>
            <tbody id="mluc-levels-tbody">
                <?php foreach ($levels as $key => $lv) : ?>
                    <?php
                    $is_free  = ('free' === $key);
                    $label    = isset($lv['label']) ? $lv['label'] : $key;
                    $price    = isset($lv['price']) ? $lv['price'] : 0;
                    $validity = isset($lv['validity']) ? (int) $lv['validity'] : 0;
                    $color    = !empty($lv['color']) ? $lv['color'] : (isset($default_colors[$key]) ? $default_colors[$key] : '#2f6fed');
                    $sort     = isset($lv['sort_order']) ? (int) $lv['sort_order'] : 50;
                    $np       = 'mluc_options[membership_levels][' . $key . ']';
                    ?>
                    <tr>
                        <td>
                            <code><?php echo esc_html($key); ?></code>
                            <?php if ($is_free) : ?><span class="mluc-fixed-tag"><?php esc_html_e('默认', 'moonlight-user-center'); ?></span><?php endif; ?>
                        </td>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr($np); ?>[label]" value="<?php echo esc_attr($label); ?>"></td>
                        <td><?php if ($is_free) : ?>—<?php else : ?><input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr($np); ?>[price]" value="<?php echo esc_attr($price); ?>"><?php endif; ?></td>
                        <td><?php if ($is_free) : ?><?php esc_html_e('永久', 'moonlight-user-center'); ?><?php else : ?><input type="number" min="0" class="small-text" name="<?php echo esc_attr($np); ?>[validity]" value="<?php echo esc_attr($validity); ?>"><?php endif; ?></td>
                        <td><input type="color" name="<?php echo esc_attr($np); ?>[color]" value="<?php echo esc_attr($color); ?>"></td>
                        <td><input type="number" min="0" class="small-text" name="<?php echo esc_attr($np); ?>[sort_order]" value="<?php echo esc_attr($sort); ?>"></td>
                        <td>
                            <button type="button" class="mluc-del-level"><?php esc_html_e('删除', 'moonlight-user-center'); ?></button>
                            <label class="mluc-del-check"><input type="checkbox" name="<?php echo esc_attr($np); ?>[delete]" value="1"> <?php esc_html_e('删除', 'moonlight-user-center'); ?></label>
                            <?php if ($is_free) : ?><span class="description"><?php esc_html_e('删除 free 等同关闭普通会员基座', 'moonlight-user-center'); ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="mluc-add-level"><?php esc_html_e('+ 添加等级', 'moonlight-user-center'); ?></button></p>

        <!-- 新增行模板（克隆用，不随表单直接提交） -->
        <template id="mluc-level-row-tpl">
            <tr>
                <td><input type="text" class="regular-text" data-name="mluc_options[membership_levels][__NEWKEY__][key]" placeholder="level_key"></td>
                <td><input type="text" class="regular-text" data-name="mluc_options[membership_levels][__NEWKEY__][label]" placeholder="<?php esc_attr_e('显示名称', 'moonlight-user-center'); ?>"></td>
                <td><input type="number" step="0.01" min="0" class="small-text" data-name="mluc_options[membership_levels][__NEWKEY__][price]" value="0"></td>
                <td><input type="number" min="0" class="small-text" data-name="mluc_options[membership_levels][__NEWKEY__][validity]" value="30"></td>
                <td><input type="color" data-name="mluc_options[membership_levels][__NEWKEY__][color]" value="#2f6fed"></td>
                <td><input type="number" min="0" class="small-text" data-name="mluc_options[membership_levels][__NEWKEY__][sort_order]" value="50"></td>
                <td><button type="button" class="mluc-del-level"><?php esc_html_e('删除', 'moonlight-user-center'); ?></button></td>
            </tr>
        </template>
        <script>
        (function () {
            var btn = document.getElementById('mluc-add-level');
            var tbody = document.getElementById('mluc-levels-tbody');
            var tpl = document.getElementById('mluc-level-row-tpl');
            if (!btn || !tbody || !tpl) { return; }
            var counter = 0;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                counter++;
                var newKey = '__new__' + counter + '__';
                var row = tpl.content.querySelector('tr').cloneNode(true);
                row.querySelectorAll('[data-name]').forEach(function (el) {
                    var n = el.getAttribute('data-name').replace('__NEWKEY__', newKey);
                    el.setAttribute('name', n);
                    el.removeAttribute('data-name');
                });
                tbody.appendChild(row);
            });
            tbody.addEventListener('click', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('mluc-del-level')) {
                    e.preventDefault();
                    var tr = e.target.closest('tr');
                    if (tr) { tr.parentNode.removeChild(tr); }
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * 独立收款设置：货币符号 + 线下转账付款说明。
     * 仅装用户中心（未启用商城）时生效：用户购买后按此说明转账，管理员在「会员订单」确认收款即自动开通。
     */
    public function render_payment_field()
    {
        $symbol       = mluc_get_option('pay_currency_symbol', '$');
        $instructions = mluc_get_option('pay_manual_instructions', '');
        $manual_on    = mluc_get_option('manual_enabled', 1);
        ?>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[manual_enabled]" value="1" <?php checked($manual_on, 1); ?>>
                <?php esc_html_e('启用线下转账（Bank Transfer，管理员确认收款）', 'moonlight-user-center'); ?>
            </label>
            <span class="description"><?php esc_html_e('取消勾选后前台购买/付费墙不再出现线下转账选项；PayPal 与 Stripe 的启用开关在下方「在线支付」区块。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label for="mluc_pay_currency"><?php esc_html_e('货币符号', 'moonlight-user-center'); ?></label>
            <input type="text" id="mluc_pay_currency" class="small-text" name="mluc_options[pay_currency_symbol]" value="<?php echo esc_attr($symbol); ?>">
        </p>
        <p>
            <label for="mluc_pay_instructions"><strong><?php esc_html_e('付款说明（展示给用户）', 'moonlight-user-center'); ?></strong></label><br>
            <textarea id="mluc_pay_instructions" class="large-text" rows="5" name="mluc_options[pay_manual_instructions]" placeholder="轉帳銀行 / 帳號 / 聯絡方式等"><?php echo esc_textarea($instructions); ?></textarea>
        </p>
        <p class="description">
            <?php esc_html_e('双插件同装时购买走商城支付流程，此说明不展示。仅装用户中心时，用户在账户中心「会员等级」选择等级购买，再按此说明完成转账；管理员在「会员订单」确认收款后等级自动开通。', 'moonlight-user-center'); ?>
        </p>
        <?php
    }

    /**
     * 订单管理：Pending 订单超时自动关闭（对所有支付方式生效）。
     */
    public function render_orders_field()
    {
        $auto_hours = (int) mluc_get_option('order_auto_close_hours', 72);
        ?>
        <p>
            <label for="mluc_pay_autoclose"><?php esc_html_e('Pending 订单自动关闭', 'moonlight-user-center'); ?></label>
            <input type="number" id="mluc_pay_autoclose" class="small-text" name="mluc_options[order_auto_close_hours]" value="<?php echo esc_attr($auto_hours); ?>" min="0" max="8760">
            <?php esc_html_e('小时（0 = 不自动关闭）', 'moonlight-user-center'); ?>
            <span class="description"><?php esc_html_e('创建后超过该时长仍未完成支付的 Pending 订单将自动置为 Cancelled。对线下转账、PayPal、Stripe、支付宝全部生效，与上方各网关的启用开关无关。建议不小于 24 小时，以免误伤正在进行的在线支付。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[pay_debug_log]" value="1" <?php checked(!empty(mluc_get_option('pay_debug_log', 0))); ?>>
                <?php esc_html_e('支付调试日志（在订单内记录网关交互上下文，排查用，平时建议关闭）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * 在线支付设置：货币代码 + PayPal / Stripe 凭据。
     */
    public function render_online_payment_field()
    {
        $code        = mluc_get_option('pay_currency_code', 'USD');
        $pp_enabled  = mluc_get_option('paypal_enabled', 0);
        $pp_mode     = mluc_get_option('paypal_mode', 'sandbox');
        $pp_cid      = mluc_get_option('paypal_client_id', '');
        $pp_secret   = mluc_get_option('paypal_secret', '');
        $st_enabled  = mluc_get_option('stripe_enabled', 0);
        $st_pk       = mluc_get_option('stripe_pk', '');
        $st_sk       = mluc_get_option('stripe_sk', '');
        $st_wh       = mluc_get_option('stripe_webhook_secret', '');
        $account_url = mluc_get_account_url();
        $webhook_url = rest_url('mluc/v1/stripe-webhook');
        ?>
        <p>
            <label for="mluc_currency_code"><strong><?php esc_html_e('货币代码（ISO 4217，两个网关共用）', 'moonlight-user-center'); ?></strong></label><br>
            <input type="text" id="mluc_currency_code" class="small-text" name="mluc_options[pay_currency_code]" value="<?php echo esc_attr($code); ?>" placeholder="USD" maxlength="3">
            <span class="description"><?php esc_html_e('如 USD / HKD / EUR / JPY。需与会员等级价格币种一致。', 'moonlight-user-center'); ?></span>
        </p>
        <hr>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[paypal_enabled]" value="1" <?php checked($pp_enabled, 1); ?>>
                <?php esc_html_e('启用 PayPal（前台 Smart Buttons，服务端扣款）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <p>
            <label for="mluc_pp_mode"><?php esc_html_e('模式', 'moonlight-user-center'); ?></label>
            <select id="mluc_pp_mode" name="mluc_options[paypal_mode]">
                <option value="sandbox" <?php selected($pp_mode, 'sandbox'); ?>><?php esc_html_e('沙盒（Sandbox）', 'moonlight-user-center'); ?></option>
                <option value="live" <?php selected($pp_mode, 'live'); ?>><?php esc_html_e('正式（Live）', 'moonlight-user-center'); ?></option>
            </select>
        </p>
        <p>
            <label for="mluc_pp_cid"><?php esc_html_e('Client ID', 'moonlight-user-center'); ?></label><br>
            <input type="text" id="mluc_pp_cid" class="large-text code" name="mluc_options[paypal_client_id]" value="<?php echo esc_attr($pp_cid); ?>" autocomplete="off">
        </p>
        <p>
            <label for="mluc_pp_secret"><?php esc_html_e('Secret', 'moonlight-user-center'); ?></label><br>
            <input type="password" id="mluc_pp_secret" class="large-text code" name="mluc_options[paypal_secret]" value="<?php echo esc_attr($pp_secret); ?>" autocomplete="new-password">
        </p>
        <hr>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[stripe_enabled]" value="1" <?php checked($st_enabled, 1); ?>>
                <?php esc_html_e('启用 Stripe（Checkout 托管结账页，支持信用卡 / Apple Pay / Google Pay）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <p>
            <label for="mluc_st_pk"><?php esc_html_e('Publishable Key', 'moonlight-user-center'); ?></label><br>
            <input type="text" id="mluc_st_pk" class="large-text code" name="mluc_options[stripe_pk]" value="<?php echo esc_attr($st_pk); ?>" placeholder="pk_live_... / pk_test_..." autocomplete="off">
        </p>
        <p>
            <label for="mluc_st_sk"><?php esc_html_e('Secret Key', 'moonlight-user-center'); ?></label><br>
            <input type="password" id="mluc_st_sk" class="large-text code" name="mluc_options[stripe_sk]" value="<?php echo esc_attr($st_sk); ?>" placeholder="sk_live_... / sk_test_..." autocomplete="new-password">
        </p>
        <p>
            <label for="mluc_st_wh"><?php esc_html_e('Webhook Signing Secret（可选，兜底开通）', 'moonlight-user-center'); ?></label><br>
            <input type="password" id="mluc_st_wh" class="large-text code" name="mluc_options[stripe_webhook_secret]" value="<?php echo esc_attr($st_wh); ?>" placeholder="whsec_..." autocomplete="new-password">
        </p>
        <p class="description">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: webhook URL */
                __('如需 Webhook 兜底，在 Stripe 后台添加端点 %s ，订阅 checkout.session.completed 事件，并把 Signing Secret 填到上面。', 'moonlight-user-center'),
                $webhook_url
            ));
            ?>
        </p>
        <p class="description">
            <?php esc_html_e('回跳处理地址为账户中心页面，支付完成后自动校验并开通会员，无需管理员手动确认；线下转账流程保持不变。', 'moonlight-user-center'); ?>
            <br><?php echo esc_html($account_url); ?>
        </p>
        <?php
    }

    /**
     * 界面文案：账户中心全部前台可见文案，可改成英文或其他语言。
     * 分组展示：侧栏与通用 / 概览页 / 个人资料页 / 会员等级页 / 已购内容页 / 购买卡 / 操作提示。
     * 留空则回退到插件内置默认。
     */
    public function render_ui_labels_field()
    {
        $fields = array(
            __('侧栏与通用', 'moonlight-user-center') => array(
                'tab_overview' => array('t' => '概览（侧栏）', 'd' => 'Overview'),
                'tab_profile' => array('t' => '个人资料（侧栏）', 'd' => 'Profile'),
                'tab_membership' => array('t' => '會員等級（侧栏）', 'd' => 'Membership Level'),
                'tab_purchases' => array('t' => '已購內容（侧栏）', 'd' => 'Purchased Content'),
                'tab_orders' => array('t' => '我的订单（侧栏）', 'd' => 'My Orders'),
                'tab_licenses' => array('t' => '我的 License（侧栏）', 'd' => 'My Licenses'),
                'tab_logout' => array('t' => '退出登录（侧栏）', 'd' => 'Log Out'),
                'no_member' => array('t' => '未開通會員', 'd' => 'Non-member'),
                'permanent' => array('t' => '永久有效', 'd' => 'Permanent'),
                'th_level' => array('t' => '等級（表头）', 'd' => 'Level'),
                'th_status' => array('t' => '狀態（表头）', 'd' => 'Status'),
                'purchases_empty' => array('t' => '已购空提示', 'd' => 'You have no purchased content yet.'),
                'purchases_login' => array('t' => '已购登录提示', 'd' => 'Please log in first to view your purchased content.'),
            ),
            __('概览页', 'moonlight-user-center') => array(
                'greet_dawn' => array('t' => '凌晨好', 'd' => 'Good early morning'),
                'greet_morning' => array('t' => '早上好', 'd' => 'Good morning'),
                'greet_noon' => array('t' => '中午好', 'd' => 'Good midday'),
                'greet_afternoon' => array('t' => '下午好', 'd' => 'Good afternoon'),
                'greet_evening' => array('t' => '晚上好', 'd' => 'Good evening'),
                'ov_phone_prefix' => array('t' => '電話：（前缀）', 'd' => 'Phone: '),
                'ov_expire' => array('t' => '到期 %s（%s 为日期）', 'd' => 'Expires %s'),
                'ov_expired' => array('t' => '已過期', 'd' => 'Expired'),
                'ov_quick_title' => array('t' => '快捷操作（标题）', 'd' => 'Quick Actions'),
                'ov_qa_profile_t' => array('t' => '编辑资料（卡片标题）', 'd' => 'Edit Profile'),
                'ov_qa_profile_s' => array('t' => '编辑资料（副标题）', 'd' => 'Nickname / Phone / Bio'),
                'ov_qa_member_t' => array('t' => '会员等级（卡片标题）', 'd' => 'Membership'),
                'ov_qa_member_s' => array('t' => '会员等级（副标题）', 'd' => 'Benefits / Upgrade'),
                'ov_qa_security_t' => array('t' => '账户安全（卡片标题）', 'd' => 'Account Security'),
                'ov_qa_security_s' => array('t' => '账户安全（副标题）', 'd' => 'Password / Login'),
                'ov_stats_title' => array('t' => '账户统计（标题）', 'd' => 'Account Stats'),
                'ov_stat_reg' => array('t' => '註冊時間', 'd' => 'Registered on'),
                'ov_stat_posts' => array('t' => '發布文章', 'd' => 'Posts'),
                'ov_stat_comments' => array('t' => '評論', 'd' => 'Comments'),
            ),
            __('个人资料页', 'moonlight-user-center') => array(
                'pf_avatar_title' => array('t' => '頭像（标题）', 'd' => 'Avatar'),
                'pf_avatar_sub' => array('t' => '头像区说明', 'd' => 'Choose one from the avatar library below; avatars are maintained by the administrator.'),
                'pf_avatar_current' => array('t' => '當前頭像', 'd' => 'Current Avatar'),
                'pf_avatar_tip' => array('t' => '头像切换提示', 'd' => 'Click any avatar below to switch instantly, no save needed.'),
                'pf_avatar_empty' => array('t' => '头像库为空提示', 'd' => 'No avatars have been uploaded yet. Please contact the administrator.'),
                'av_op_failed' => array('t' => '头像：操作失败提示', 'd' => 'Operation failed.'),
                'av_network_retry' => array('t' => '头像：网络错误请重试提示', 'd' => 'Network error. Please retry.'),
                'av_uploading' => array('t' => '头像：上传中提示', 'd' => 'Uploading…'),
                'av_upload_failed' => array('t' => '头像：上传失败提示', 'd' => 'Upload failed.'),
                'av_network_error' => array('t' => '头像：网络错误提示', 'd' => 'Network error.'),
                'av_switching' => array('t' => '头像：切换中提示', 'd' => 'Switching…'),
                'av_avatar_updated' => array('t' => '头像：已更新提示', 'd' => 'Avatar updated.'),
                'pf_base_title' => array('t' => '基礎資料（标题）', 'd' => 'Basic Information'),
                'pf_base_sub' => array('t' => '基础资料说明', 'd' => 'This information appears on your public profile and article bylines.'),
                'pf_email' => array('t' => '電郵（字段）', 'd' => 'Email'),
                'pf_email_sub' => array('t' => '電郵说明', 'd' => 'To change your email, please contact the administrator.'),
                'pf_display' => array('t' => '顯示名稱', 'd' => 'Display Name'),
                'pf_nickname' => array('t' => '昵稱', 'd' => 'Nickname'),
                'pf_phone' => array('t' => '電話', 'd' => 'Phone'),
                'pf_phone_ph' => array('t' => '電話占位提示', 'd' => 'e.g. 9123 4567'),
                'pf_url' => array('t' => '個人網站', 'd' => 'Website'),
                'pf_bio' => array('t' => '個人簡介', 'd' => 'Bio'),
                'pf_save' => array('t' => '保存資料（按钮）', 'd' => 'Save Changes'),
                'pf_pass_title' => array('t' => '修改密碼（标题）', 'd' => 'Change Password'),
                'pf_pass_sub' => array('t' => '修改密码说明', 'd' => 'For account security, change your password regularly. You will need to log in again after changing it.'),
                'pf_old_pass' => array('t' => '原密碼', 'd' => 'Current Password'),
                'pf_new_pass' => array('t' => '新密碼', 'd' => 'New Password'),
                'pf_new_pass_sub' => array('t' => '新密码说明', 'd' => 'At least 6 characters.'),
                'pf_pass_save' => array('t' => '更新密碼（按钮）', 'd' => 'Update Password'),
            ),
            __('会员等级页', 'moonlight-user-center') => array(
                'mb_current_title' => array('t' => '當前會員等級（标题）', 'd' => 'Current Membership'),
                'mb_free_tip' => array('t' => 'Free 会员提示', 'd' => 'You are currently a Free member. Upgrade to Monthly or Premium to unlock more materials and demo videos.'),
                'mb_none_tip' => array('t' => '未开通提示', 'd' => 'You do not have a membership yet. Upgrade to unlock more materials and demo videos.'),
                'mb_expired_tip' => array('t' => '已过期提示', 'd' => 'Your membership has expired. Please renew to continue.'),
                'mb_expire_date' => array('t' => '到期日：%s（%s 为日期）', 'd' => 'Expires: %s'),
                'mb_permanent' => array('t' => '永久會員', 'd' => 'Lifetime member'),
                'mb_table_title' => array('t' => '會員等級對照（标题）', 'd' => 'Membership Levels'),
                'mb_table_sub' => array('t' => '等级对照说明', 'd' => 'Compare the benefits and your current status of each level.'),
                'mb_th_desc' => array('t' => '描述（表头）', 'd' => 'Description'),
                'mb_st_current' => array('t' => '當前', 'd' => 'Current'),
                'mb_st_included' => array('t' => '已包含', 'd' => 'Included'),
                'mb_st_locked' => array('t' => '未解鎖', 'd' => 'Locked'),
                'mb_upgrade_title' => array('t' => '開通 / 升級會員（标题）', 'd' => 'Buy / Upgrade Membership'),
            ),
            __('已购内容页', 'moonlight-user-center') => array(
                'pu_th_item' => array('t' => '教材（表头）', 'd' => 'Item'),
                'pu_th_type' => array('t' => '類型（表头）', 'd' => 'Type'),
                'pu_th_date' => array('t' => '購買日期（表头）', 'd' => 'Purchase Date'),
                'pu_th_action' => array('t' => '操作（表头）', 'd' => 'Action'),
                'pu_type_cardkey' => array('t' => '卡密', 'd' => 'Card Key'),
                'pu_type_virtual' => array('t' => '虛擬下載', 'd' => 'Download'),
                'pu_view_order' => array('t' => '查看訂單（按钮）', 'd' => 'View Order'),
            ),
            __('购买卡（开通/升级会员）', 'moonlight-user-center') => array(
                'buy_ok' => array('t' => '付款成功提示', 'd' => 'Payment successful. Your membership has been activated.'),
                'buy_failed' => array('t' => '付款失败提示', 'd' => 'Payment was not completed or verification failed. Please try again or contact the administrator.'),
                'buy_cancelled' => array('t' => '已取消支付提示', 'd' => 'Payment cancelled. The order remains pending.'),
                'buy_validity_days' => array('t' => '有效期 %d 天（%d 为天数）', 'd' => 'Valid for %d days'),
                'buy_btn' => array('t' => '購買（按钮）', 'd' => 'Buy'),
                'buy_instructions' => array('t' => '付款說明（标题）', 'd' => 'Payment Instructions'),
                'buy_instructions_body' => array('t' => '付款說明（正文，后台未填「付款说明」时显示）', 'd' => 'Please complete the transfer as instructed below. Your membership will be activated once the administrator confirms your payment.'),
                'buy_gateway_manual' => array('t' => '线下转账（网关名称）', 'd' => 'Bank Transfer (admin confirms payment)'),
                'buy_gateway_paypal' => array('t' => 'PayPal（网关名称）', 'd' => 'PayPal (Online Payment)'),
                'buy_gateway_stripe' => array('t' => 'Stripe（网关名称）', 'd' => 'Stripe (Credit Card)'),
                'buy_gateway_alipay' => array('t' => '支付宝（网关名称）', 'd' => 'Alipay (支付宝)'),
                'buy_login_required' => array('t' => '请先登录提示', 'd' => 'Please log in first.'),
                'buy_use_shop' => array('t' => '请使用商城购买提示', 'd' => 'Please use the shop upgrade flow to purchase membership.'),
                'buy_module_missing' => array('t' => '会员模块未加载提示', 'd' => 'Membership module is not loaded.'),
                'buy_invalid_level' => array('t' => '无效等级提示', 'd' => 'Invalid membership level.'),
                'buy_no_price' => array('t' => '等级未定价提示', 'd' => 'This level has no price set and cannot be purchased yet.'),
                'buy_level_too_low' => array('t' => '等级不低于所选提示', 'd' => 'Your current membership level is not lower than the selected one. No need to purchase.'),
                'buy_invalid_gateway' => array('t' => '支付方式无效提示', 'd' => 'Invalid payment method.'),
                'buy_no_gateway' => array('t' => '无可用支付方式提示', 'd' => 'No payment method is available. Please contact the administrator.'),
                'buy_too_many' => array('t' => '下单过于频繁提示', 'd' => 'Too many orders in a short period. Please try again later.'),
                'buy_order_failed' => array('t' => '订单创建失败提示', 'd' => 'Failed to create the order. Please try again later.'),
                'buy_order_paypal' => array('t' => '订单已建立（PayPal）提示', 'd' => 'Order created. Please complete the PayPal payment.'),
                'buy_order_stripe' => array('t' => '订单已建立（Stripe）提示', 'd' => 'Order created. Redirecting to Stripe…'),
                'buy_order_alipay' => array('t' => '订单已建立（支付宝）提示', 'd' => 'Order created. Redirecting to Alipay…'),
                'buy_order_manual' => array('t' => '订单已建立（线下转账）提示', 'd' => 'Order created. Please complete the transfer as instructed. Your membership will be activated once the administrator confirms your payment.'),
                'buy_history' => array('t' => '我的購買記錄（标题）', 'd' => 'My Purchase History'),
                'buy_th_order' => array('t' => '訂單（表头）', 'd' => 'Order'),
                'buy_th_amount' => array('t' => '金額（表头）', 'd' => 'Amount'),
                'buy_th_date' => array('t' => '日期（表头）', 'd' => 'Date'),
                'buy_st_pending' => array('t' => '待確認', 'd' => 'Pending'),
                'buy_st_paid' => array('t' => '已收款', 'd' => 'Paid'),
                'buy_st_cancelled' => array('t' => '已取消', 'd' => 'Cancelled'),
                'buy_pp_load_fail' => array('t' => 'PayPal 组件加载失败提示', 'd' => 'Failed to load PayPal. Please refresh the page and try again.'),
                'buy_pp_confirming' => array('t' => '正在確認付款提示', 'd' => 'Confirming payment...'),
                'buy_pp_error' => array('t' => 'PayPal 付款异常提示', 'd' => 'PayPal payment error. Please try again or contact the administrator.'),
                'buy_pp_goto' => array('t' => '请完成 PayPal 付款提示', 'd' => 'Please complete the PayPal payment below.'),
                'buy_net_error' => array('t' => '網絡異常提示', 'd' => 'Network error. Please try again later.'),
                'buy_gateway_balance' => array('t' => '余额支付（网关名称）', 'd' => 'Account Balance'),
                'buy_gateway_credit' => array('t' => '积分支付（网关名称）', 'd' => 'Points Payment'),
                'buy_order_balance' => array('t' => '余额支付成功提示', 'd' => 'Payment successful via account balance.'),
                'buy_order_credit' => array('t' => '积分支付成功提示', 'd' => 'Payment successful via points.'),
            ),
            __('积分与余额页', 'moonlight-user-center') => array(
                'cr_tab' => array('t' => '积分余额（侧栏）', 'd' => 'Points & Balance'),
                'cr_recharge_title' => array('t' => '充值（标题）', 'd' => 'Top Up'),
                'cr_exchange_title' => array('t' => '兑换余额（标题）', 'd' => 'Exchange to Balance'),
                'cr_balance_credit' => array('t' => '积分余额（卡片标签）', 'd' => 'Points Balance'),
                'cr_balance_money' => array('t' => '账户余额（卡片标签）', 'd' => 'Account Balance'),
                'cr_custom' => array('t' => '自定义（选项）', 'd' => 'Custom'),
                'cr_rate_hint' => array('t' => '充值比例提示（%1$s 积分名，%2$s 比例值）', 'd' => 'Rate: %2$s %1$s per currency unit'),
                'cr_exchange_hint' => array('t' => '兑换比例提示（%1$s 比例值）', 'd' => 'Rate: %1$s points = 1 currency unit'),
                'cr_btn_recharge' => array('t' => '去充值（按钮）', 'd' => 'Top Up'),
                'cr_btn_exchange' => array('t' => '立即兑换（按钮）', 'd' => 'Exchange Now'),
                'cr_ledger_credit' => array('t' => '积分流水（标题）', 'd' => 'Points History'),
                'cr_ledger_balance' => array('t' => '余额流水（标题）', 'd' => 'Balance History'),
                'cr_empty_ledger' => array('t' => '无流水提示', 'd' => 'No records yet.'),
                'cr_th_time' => array('t' => '时间（表头）', 'd' => 'Time'),
                'cr_th_delta' => array('t' => '变动（表头）', 'd' => 'Change'),
                'cr_th_balance' => array('t' => '余额（表头）', 'd' => 'Balance'),
                'cr_th_note' => array('t' => '说明（表头）', 'd' => 'Note'),
                'cr_exchanged' => array('t' => '兑换成功提示', 'd' => 'Exchange successful.'),
                'cr_checkin_btn' => array('t' => '签到（按钮）', 'd' => 'Check In'),
                'cr_checkin_done' => array('t' => '今日已签到（状态）', 'd' => 'Checked in today'),
                'cr_checkin_streak' => array('t' => '连续签到天数（%d 为天数）', 'd' => '%d-day streak'),
            ),
            __('我的订单 / 我的 License 页', 'moonlight-user-center') => array(
                'od_title' => array('t' => '我的订单（标题）', 'd' => 'My Orders'),
                'od_empty' => array('t' => '无订单提示', 'd' => 'You have no orders yet.'),
                'od_th_no' => array('t' => '订单号（表头）', 'd' => 'Order No.'),
                'od_th_item' => array('t' => '商品（表头）', 'd' => 'Item'),
                'od_th_gateway' => array('t' => '支付方式（表头）', 'd' => 'Method'),
                'lic_title' => array('t' => '我的 License（标题）', 'd' => 'My Licenses'),
                'lic_empty' => array('t' => '无 License 提示', 'd' => 'You have no licenses yet. Purchase a Pro plan to get one.'),
                'lic_th_key' => array('t' => 'License（表头）', 'd' => 'License'),
                'lic_th_product' => array('t' => '产品（表头）', 'd' => 'Product'),
                'lic_th_site' => array('t' => '授权站点（表头）', 'd' => 'Site'),
                'lic_th_expires' => array('t' => '到期（表头）', 'd' => 'Expires'),
                'lic_st_active' => array('t' => '有效', 'd' => 'Active'),
                'lic_st_inactive' => array('t' => '未激活', 'd' => 'Inactive'),
                'lic_st_expired' => array('t' => '已过期', 'd' => 'Expired'),
                'lic_st_revoked' => array('t' => '已撤销', 'd' => 'Revoked'),
                'lic_st_suspended' => array('t' => '已暂停', 'd' => 'Suspended'),
            ),
            __('隐藏内容锁定卡（hidecontent）', 'moonlight-user-center') => array(
                'hc_title_reply' => array('t' => '评论后可查看（卡片标题）', 'd' => 'Comment to View'),
                'hc_title_logged' => array('t' => '登录后可查看（卡片标题）', 'd' => 'Log in to View'),
                'hc_title_vip1' => array('t' => '会员可查看（卡片标题）', 'd' => 'Members Only'),
                'hc_title_payshow' => array('t' => '付费后可查看（卡片标题）', 'd' => 'Purchase to View'),
            ),
            __('付费墙（文章付费）', 'moonlight-user-center') => array(
                'pw_mode_read' => array('t' => '付费阅读（标签）', 'd' => 'Paid Read'),
                'pw_mode_download' => array('t' => '付费下载（标签）', 'd' => 'Paid Download'),
                'pw_mode_image' => array('t' => '付费图片（标签）', 'd' => 'Paid Gallery'),
                'pw_mode_video' => array('t' => '付费视频（标签）', 'd' => 'Paid Video'),
                'pw_default_title' => array('t' => '默认卡片标题（后台未填时）', 'd' => 'Premium Content'),
                'pw_btn_unlock' => array('t' => '解锁按钮', 'd' => 'Unlock Now'),
                'pw_login_btn' => array('t' => '登录后购买（按钮）', 'd' => 'Log In to Purchase'),
                'pw_gated' => array('t' => '等级不足提示', 'd' => 'Your membership level cannot purchase this content. Please upgrade your membership.'),
                'pw_gated_level' => array('t' => '等级门槛提示（%s 为等级名）', 'd' => 'This content is available to %s members and above. Please upgrade your membership.'),
                'pw_already' => array('t' => '已解锁提示', 'd' => 'You already have access to this content.'),
                'pw_not_needed' => array('t' => '无需付费提示', 'd' => 'This content does not require payment.'),
                'pw_no_price' => array('t' => '未定价提示', 'd' => 'This content has no price set and cannot be purchased yet.'),
                'pw_disabled' => array('t' => '付费功能关闭提示', 'd' => 'Paid content is not available.'),
                'pw_sales_note' => array('t' => '已售提示（%d 为数量）', 'd' => 'Sold: %d'),
                'pw_expire_note' => array('t' => '时效提示（%d 数量 / %s 单位）', 'd' => 'Access expires %d %s after purchase.'),
                'pw_unit_hour' => array('t' => '时效单位：小时', 'd' => 'hour(s)'),
                'pw_unit_day' => array('t' => '时效单位：天', 'd' => 'day(s)'),
                'pw_unit_month' => array('t' => '时效单位：个月', 'd' => 'month(s)'),
                'pw_order_manual' => array('t' => '订单已建立（线下转账）提示', 'd' => 'Order created. Please complete the bank transfer as instructed. The content will be unlocked once the administrator confirms your payment.'),
                'pw_order_stripe' => array('t' => '订单已建立（Stripe）提示', 'd' => 'Order created. Redirecting to Stripe…'),
                'pw_order_alipay' => array('t' => '订单已建立（支付宝）提示', 'd' => 'Order created. Redirecting to Alipay…'),
                'pw_instructions_title' => array('t' => '付款说明（标题）', 'd' => 'Payment Instructions'),
                'pw_dl_default_btn' => array('t' => '下载按钮默认文字', 'd' => 'Download'),
                'pw_dl_invalid' => array('t' => '下载链接无效提示', 'd' => 'This download link is invalid or has expired.'),
                'pw_dl_not_owned' => array('t' => '未购买下载提示', 'd' => 'You have not purchased this content.'),
                'pw_dl_missing' => array('t' => '文件不存在提示', 'd' => 'File not found.'),
                'pw_demo_link' => array('t' => '在线演示（链接文字）', 'd' => 'Live Demo'),
                'pw_view_full' => array('t' => '查看大图（链接文字）', 'd' => 'View Full Size'),
                'pw_locked_item' => array('t' => '锁定图片占位文字', 'd' => 'Locked'),
                'pw_locked_video' => array('t' => '锁定视频占位文字', 'd' => 'Locked video. Purchase to watch.'),
                'pw_ok_reload' => array('t' => '支付成功提示', 'd' => 'Payment successful. Reloading…'),
            ),
            __('登录 / 注册 / 找回密码页', 'moonlight-user-center') => array(
                'lg_title' => array('t' => '登录（标题）', 'd' => 'Log In'),
                'lg_user' => array('t' => '用户名或邮箱', 'd' => 'Username or Email'),
                'lg_pass' => array('t' => '密码', 'd' => 'Password'),
                'lg_remember' => array('t' => '记住我', 'd' => 'Remember Me'),
                'lg_btn' => array('t' => '登录（按钮）', 'd' => 'Log In'),
                'lg_forgot' => array('t' => '忘记密码？', 'd' => 'Forgot password?'),
                'lg_register' => array('t' => '立即注册', 'd' => 'Register now'),
                'lp_title' => array('t' => '找回密码（标题）', 'd' => 'Lost Password'),
                'lp_user' => array('t' => '找回密码：用户名或邮箱', 'd' => 'Username or Email'),
                'lp_btn' => array('t' => '发送重置链接', 'd' => 'Send Reset Link'),
                'lp_back' => array('t' => '返回登录', 'd' => 'Back to Log In'),
                'rg_title' => array('t' => '注册账户（标题）', 'd' => 'Create Account'),
                'rg_user' => array('t' => '注册：用户名', 'd' => 'Username'),
                'rg_email' => array('t' => '注册：邮箱', 'd' => 'Email'),
                'rg_pass' => array('t' => '注册：密码', 'd' => 'Password'),
                'rg_btn' => array('t' => '注册（按钮）', 'd' => 'Register'),
                'rg_login' => array('t' => '已有账户？去登录', 'd' => 'Already have an account? Log in'),
            ),
            __('操作提示（保存/修改密码等）', 'moonlight-user-center') => array(
                'msg_login_required' => array('t' => '请先登录。', 'd' => 'Please log in first.'),
                'msg_account_login' => array('t' => '请先 %s 后查看账户中心。（%s 为登录链接）', 'd' => 'Please %s to view the account center.'),
                'msg_login_link' => array('t' => '登录（链接文字）', 'd' => 'log in'),
                'msg_profile_updated' => array('t' => '资料已更新。', 'd' => 'Profile updated.'),
                'msg_nickname_empty' => array('t' => '昵称不能为空。', 'd' => 'Nickname cannot be empty.'),
                'msg_pass_fields' => array('t' => '请填写原密码和新密码。', 'd' => 'Please enter your current and new password.'),
                'msg_pass_len' => array('t' => '新密码至少 6 位。', 'd' => 'The new password must be at least 6 characters.'),
                'msg_pass_wrong' => array('t' => '原密码不正确。', 'd' => 'The current password is incorrect.'),
                'msg_pass_changed' => array('t' => '密码已修改，请重新登录。', 'd' => 'Password changed. Please log in again.'),
            ),
        );
        $saved = mluc_get_option('ui_labels', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        echo '<p class="description">' . esc_html__('修改账户中心全部前台文字。留空直接显示英文默认值；填写后优先显示填写内容（中文或其他语言均可）。保存后刷新前台页面生效。', 'moonlight-user-center') . '</p>';
        echo '<table class="form-table" role="presentation">';
        foreach ($fields as $group => $items) {
            printf('<tr><th colspan="2" style="text-align:left;background:#f6f7f7;"><strong>%s</strong></th></tr>', esc_html($group));
            foreach ($items as $key => $f) {
                printf(
                    '<tr><th scope="row"><label for="mluc_ui_%1$s">%2$s</label></th><td><input type="text" id="mluc_ui_%1$s" class="regular-text" name="mluc_options[ui_labels][%1$s]" value="%3$s" placeholder="%4$s" autocomplete="off"></td></tr>',
                    esc_attr($key),
                    esc_html($f['t']),
                    esc_attr(isset($saved[$key]) ? (string) $saved[$key] : ''),
                    esc_attr($f['d'])
                );
            }
        }
        echo '</table>';
    }

    /**
     * 侧栏菜单图标：让管理员为每个 Tab 自定义 dashicons 类 或 上传自定义 PNG/SVG 图片。
     * 留空则回退到插件内置默认。
     */
    public function render_nav_icons_field()
    {
        if (!class_exists('MLUC_Account')) {
            echo '<p class="description">' . esc_html__('账户中心模块未加载。', 'moonlight-user-center') . '</p>';
            return;
        }
        $tabs   = MLUC_Account::get_instance()->get_tabs();
        $saved  = mluc_get_option('nav_icons', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        // 规范化存储结构：nav_icons[key] = ['type' => 'dashicon'|'image', 'value' => 类名 | attachment_id]
        // 旧数据（裸 dashicons 字符串）自动包成新结构。
        $norm = array();
        foreach ($saved as $k => $v) {
            $key = (string) $k;
            if (is_array($v) && isset($v['type']) && isset($v['value'])) {
                $norm[$key] = array(
                    'type'  => ('image' === $v['type']) ? 'image' : 'dashicon',
                    'value' => $v['value'],
                );
            } elseif (is_string($v) && strpos($v, 'dashicons-') === 0) {
                $norm[$key] = array('type' => 'dashicon', 'value' => $v);
            } else {
                $norm[$key] = array('type' => 'dashicon', 'value' => '');
            }
        }
        // 常用 dashicons 候选（datalist 自动补全），覆盖 admin / 通用 / 电商三类。
        $suggestions = array(
            'dashicons-dashboard', 'dashicons-admin-home', 'dashicons-admin-users', 'dashicons-groups',
            'dashicons-edit', 'dashicons-id', 'dashicons-id-alt',
            'dashicons-star-filled', 'dashicons-star-empty', 'dashicons-awards',
            'dashicons-cart', 'dashicons-store', 'dashicons-clipboard', 'dashicons-tag', 'dashicons-voucher',
            'dashicons-download', 'dashicons-upload', 'dashicons-media-default',
            'dashicons-tickets-alt', 'dashicons-money-alt', 'dashicons-coins',
            'dashicons-book', 'dashicons-book-alt', 'dashicons-welcome-learn-more',
            'dashicons-heart', 'dashicons-thumbs-up', 'dashicons-bell', 'dashicons-email',
            'dashicons-migrate', 'dashicons-exit', 'dashicons-lock',
        );
        ?>
        <table class="mluc-nav-icons-table widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e('菜单项', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('标识', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('图标', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('预览', 'moonlight-user-center'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tabs as $key => $tab) :
                    $cur_key = sanitize_key($key);
                    $cur     = isset($norm[$key]) ? $norm[$key] : array('type' => 'dashicon', 'value' => '');
                    $cur_default_icon = isset($tab['icon']) ? $tab['icon'] : '';
                    $dash_val = ('dashicon' === $cur['type']) ? (string) $cur['value'] : $cur_default_icon;
                    $img_url  = '';
                    if ('image' === $cur['type'] && !empty($cur['value'])) {
                        $img_url = wp_get_attachment_image_url((int) $cur['value'], 'thumbnail');
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html(isset($tab['title']) ? $tab['title'] : $key); ?></strong></td>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td>
                            <fieldset class="mluc-icon-picker">
                                <legend class="screen-reader-text"><?php echo esc_html(isset($tab['title']) ? $tab['title'] : $key); ?></legend>
                                <label class="mluc-icon-type">
                                    <input type="radio" name="mluc_options[nav_icons][<?php echo esc_attr($cur_key); ?>][type]" value="dashicon" <?php checked($cur['type'], 'dashicon'); ?>>
                                    <?php esc_html_e('Dashicons 类', 'moonlight-user-center'); ?>
                                </label>
                                <label class="mluc-icon-type">
                                    <input type="radio" name="mluc_options[nav_icons][<?php echo esc_attr($cur_key); ?>][type]" value="image" <?php checked($cur['type'], 'image'); ?>>
                                    <?php esc_html_e('自定义图片', 'moonlight-user-center'); ?>
                                </label>
                                <div class="mluc-icon-dashicon" <?php echo 'dashicon' !== $cur['type'] ? 'hidden' : ''; ?>>
                                    <input type="text" class="regular-text mluc-icon-input"
                                           list="mluc-common-icons"
                                           name="mluc_options[nav_icons][<?php echo esc_attr($cur_key); ?>][value]"
                                           value="<?php echo esc_attr($dash_val); ?>"
                                           placeholder="dashicons-cart">
                                </div>
                                <div class="mluc-icon-image" <?php echo 'image' !== $cur['type'] ? 'hidden' : ''; ?>>
                                    <input type="hidden" class="mluc-icon-image-id"
                                           name="mluc_options[nav_icons][<?php echo esc_attr($cur_key); ?>][value]"
                                           value="<?php echo 'image' === $cur['type'] ? (int) $cur['value'] : 0; ?>">
                                    <span class="mluc-icon-image-preview">
                                        <?php if ($img_url) : ?>
                                            <img src="<?php echo esc_url($img_url); ?>" alt="" />
                                        <?php endif; ?>
                                    </span>
                                    <button type="button" class="button mluc-icon-upload"><?php esc_html_e('上传/选择图片', 'moonlight-user-center'); ?></button>
                                    <button type="button" class="button-link-delete mluc-icon-remove"><?php esc_html_e('移除', 'moonlight-user-center'); ?></button>
                                    <p class="description" style="flex-basis:100%;margin:4px 0 0;"><?php esc_html_e('支持 PNG / SVG / JPG。建议 24×24 或 32×32 的方形图标。', 'moonlight-user-center'); ?></p>
                                </div>
                            </fieldset>
                        </td>
                        <td>
                            <?php if ('image' === $cur['type'] && $img_url) : ?>
                                <img class="mluc-preview-img" src="<?php echo esc_url($img_url); ?>" alt="" data-mluc-icon-preview>
                            <?php else : ?>
                                <span class="dashicons <?php echo esc_attr($dash_val ? $dash_val : 'dashicons-minus'); ?>" data-mluc-icon-preview></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <datalist id="mluc-common-icons">
            <?php foreach ($suggestions as $s) : ?>
                <option value="<?php echo esc_attr($s); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="description">
            <?php esc_html_e('为每个菜单项选择内置 Dashicons 类，或上传自定义 PNG / SVG / JPG 图片。留空时使用插件内置默认图标。', 'moonlight-user-center'); ?>
        </p>
        <?php
    }

    /**
     * 第三方登录 OAuth 凭据（client_id / client_secret，按提供商）。
     */
    public function render_oauth_field()
    {
        if (!class_exists('MLUC_OAuth')) {
            echo '<p class="description">' . esc_html__('OAuth 模块未加载。', 'moonlight-user-center') . '</p>';
            return;
        }
        $providers = MLUC_OAuth::providers();
        $saved = mluc_get_option('oauth', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $callback = home_url('/?mluc_oauth=wechat');
        ?>
        <p class="description">
            <?php
            /* translators: %s = 回调地址示例 */
            printf(esc_html__('在对应开放平台填写回调地址（示例）：%s。仅填写了 Client ID 与 Secret 的提供商才会出现在前端登录按钮。', 'moonlight-user-center'), '<code>' . esc_url($callback) . '</code>');
            ?>
        </p>
        <table class="mluc-oauth-table widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e('提供商', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('Client ID / App ID', 'moonlight-user-center'); ?></th>
                    <th><?php esc_html_e('Client Secret', 'moonlight-user-center'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($providers as $id => $p) : ?>
                    <?php
                    $c = isset($saved[$id]) ? $saved[$id] : array();
                    $cid = isset($c['client_id']) ? $c['client_id'] : '';
                    $sec = isset($c['client_secret']) ? $c['client_secret'] : '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($p['label']); ?></strong></td>
                        <td><input type="text" class="regular-text" name="mluc_options[oauth][<?php echo esc_attr($id); ?>][client_id]" value="<?php echo esc_attr($cid); ?>"></td>
                        <td><input type="password" class="regular-text" name="mluc_options[oauth][<?php echo esc_attr($id); ?>][client_secret]" value="<?php echo esc_attr($sec); ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * 支付宝配置：启用开关 / 模式 / App ID / 应用私钥 / 支付宝公钥。
     * 密钥脱敏：已配置时输入框留空 = 保持不变；绝不回显明文（§18）。
     */
    public function render_alipay_field()
    {
        $enabled   = mluc_get_option('alipay_enabled', 0);
        $mode      = mluc_get_option('alipay_mode', 'sandbox');
        $app_id    = mluc_get_option('alipay_app_id', '');
        $priv_set  = '' !== trim((string) mluc_get_option('alipay_private_key', ''));
        $pub_set   = '' !== trim((string) mluc_get_option('alipay_public_key', ''));
        $priv_hint = $priv_set
            ? sprintf(__('已配置（尾 4 位 …%s），留空保持不变', 'moonlight-user-center'), esc_html(self::key_tail(mluc_get_option('alipay_private_key', ''))))
            : __('未配置', 'moonlight-user-center');
        $pub_hint = $pub_set
            ? sprintf(__('已配置（尾 4 位 …%s），留空保持不变', 'moonlight-user-center'), esc_html(self::key_tail(mluc_get_option('alipay_public_key', ''))))
            : __('未配置', 'moonlight-user-center');
        ?>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[alipay_enabled]" value="1" <?php checked($enabled, 1); ?>>
                <?php esc_html_e('启用支付宝（电脑网站支付，跳转式，仅支持 CNY）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <p>
            <label for="mluc_ali_mode"><?php esc_html_e('环境', 'moonlight-user-center'); ?></label>
            <select id="mluc_ali_mode" name="mluc_options[alipay_mode]">
                <option value="sandbox" <?php selected($mode, 'sandbox'); ?>><?php esc_html_e('沙盒（Sandbox）', 'moonlight-user-center'); ?></option>
                <option value="production" <?php selected($mode, 'production'); ?>><?php esc_html_e('正式（Production）', 'moonlight-user-center'); ?></option>
            </select>
            <span class="description"><?php esc_html_e('生产环境涉及真实收款，切换前请确认应用与密钥均为正式环境。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label for="mluc_ali_appid"><strong><?php esc_html_e('App ID', 'moonlight-user-center'); ?></strong></label><br>
            <input type="text" id="mluc_ali_appid" class="regular-text code" name="mluc_options[alipay_app_id]" value="<?php echo esc_attr($app_id); ?>" autocomplete="off">
        </p>
        <p>
            <label for="mluc_ali_priv"><strong><?php esc_html_e('应用私钥（RSA2）', 'moonlight-user-center'); ?></strong></label><br>
            <textarea id="mluc_ali_priv" class="large-text code" rows="4" name="mluc_options[alipay_private_key]" placeholder="<?php echo esc_attr($priv_hint); ?>" autocomplete="new-password"></textarea>
            <span class="description"><?php echo esc_html__('支持 PKCS#1 / PKCS#8，可带或不含 PEM 头。仅保存在本站数据库，不写入代码与日志。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label for="mluc_ali_pub"><strong><?php esc_html_e('支付宝公钥（RSA2）', 'moonlight-user-center'); ?></strong></label><br>
            <textarea id="mluc_ali_pub" class="large-text code" rows="4" name="mluc_options[alipay_public_key]" placeholder="<?php echo esc_attr($pub_hint); ?>" autocomplete="new-password"></textarea>
            <span class="description"><?php echo esc_html__('注意：填支付宝公钥（开放平台「接口加签方式」页查看），不是应用公钥。', 'moonlight-user-center'); ?></span>
        </p>
        <p class="description">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: notify URL */
                __('在支付宝开放平台无需手动配置异步地址，本插件下单时自动携带 notify_url：%s（服务器需可公网访问）。', 'moonlight-user-center'),
                MLUC_Gateway_Alipay::notify_url()
            ));
            ?>
        </p>
        <p class="description">
            <?php
            echo esc_html(sprintf(
                /* translators: %s: 当前货币代码 */
                __('支付宝仅支持 CNY 结算。当前货币代码为 %1$s，需改为 CNY 后前台才会出现支付宝选项。', 'moonlight-user-center'),
                MLUC_Payments::currency_code()
            ));
            ?>
        </p>
        <?php
    }

    /**
     * 取密钥尾 4 位（脱敏展示用）。
     */
    private static function key_tail($raw)
    {
        $body = preg_replace('/\s+/', '', (string) $raw);
        return substr($body, -4);
    }

    /**
     * License / Pro 设置：远程 License Server + 支付自动颁发等级。
     */
    public function render_license_field()
    {
        $server      = mluc_get_option('license_server_url', '');
        $auto_levels = (array) mluc_get_option('license_auto_levels', array());
        ?>
        <p>
            <label for="mluc_lic_server"><strong><?php esc_html_e('License Server 地址（可选）', 'moonlight-user-center'); ?></strong></label><br>
            <input type="url" id="mluc_lic_server" class="large-text code" name="mluc_options[license_server_url]" value="<?php echo esc_attr($server); ?>" placeholder="https://license.example.com">
            <span class="description"><?php echo esc_html__('留空则 Pro 不启用（安全默认：避免本站自行签发授权）。配置后走远程验证：结果缓存 12 小时；网络失败进入 7 天宽限期，期间 Pro 功能照常，绝不因 Server 故障影响 Free 功能。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label for="mluc_lic_auto"><strong><?php esc_html_e('支付成功自动颁发 License 的会员等级', 'moonlight-user-center'); ?></strong></label><br>
            <input type="text" id="mluc_lic_auto" class="regular-text code" name="mluc_options[license_auto_levels_csv]" value="<?php echo esc_attr(implode(',', $auto_levels)); ?>" placeholder="monthly,gold">
            <span class="description"><?php echo esc_html__('填等级标识（后台「会员等级定义」中的 key），英文逗号分隔，留空 = 不自动颁发。用户购买这些等级并支付成功后，自动为其创建 / 续期 moonlight-user-center-pro License（到期时长跟随等级有效期）。', 'moonlight-user-center'); ?></span>
        </p>
        <?php
    }

    /**
     * 邮件通知开关。
     */
    public function render_email_field()
    {
        ?>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[email_purchase_enabled]" value="1" <?php checked(!empty(mluc_get_option('email_purchase_enabled', 1))); ?>>
                <?php esc_html_e('支付成功后向购买用户发送邮件', 'moonlight-user-center'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[email_license_reminder_enabled]" value="1" <?php checked(!empty(mluc_get_option('email_license_reminder_enabled', 1))); ?>>
                <?php esc_html_e('License 到期前 7 天发送续费提醒邮件（每日检查一次）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <?php
    }

    /**
     * 积分与余额设置（v2.1.0）：启用开关、积分名称、充值比例 / 套餐 / 限额、
     * 兑换比例与单次下限——全部后台可自定义，前台只展示不传价。
     */
    public function render_credit_field()
    {
        $credit_on    = mluc_credit_enabled();
        $balance_on   = mluc_balance_enabled();
        $name         = mluc_get_credit_name();
        $rate         = mluc_get_credit_rate();
        $packages     = (array) mluc_get_option('credit_packages', array());
        $lines        = array();
        foreach ($packages as $pkg) {
            if (is_array($pkg) && isset($pkg['credit'], $pkg['price'])) {
                $lines[] = $pkg['credit'] . '|' . $pkg['price'];
            }
        }
        $packages_txt = implode("\n", $lines);
        $cmin     = (int) mluc_get_option('credit_custom_min', 10);
        $cmax     = (int) mluc_get_option('credit_custom_max', 0);
        $ex_on    = !empty(mluc_get_option('credit_exchange_enabled', 0));
        $ex_rate  = mluc_get_credit_exchange_rate();
        $ex_min   = (int) mluc_get_option('credit_exchange_min', 100);
        $symbol   = MLUC_Payments::get_currency_symbol();
        ?>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[credit_enabled]" value="1" <?php checked($credit_on); ?>>
                <?php esc_html_e('启用积分体系（积分充值、积分支付、积分兑换余额）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <p>
            <label for="mluc_credit_name"><?php esc_html_e('积分名称', 'moonlight-user-center'); ?></label>
            <input type="text" id="mluc_credit_name" class="small-text" name="mluc_options[credit_name]" value="<?php echo esc_attr($name); ?>">
            <span class="description"><?php esc_html_e('如：积分 / 金币 / Z币。显示在账户中心与支付选项。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label for="mluc_credit_rate"><strong><?php esc_html_e('充值比例（1 货币单位 = 多少积分）', 'moonlight-user-center'); ?></strong></label><br>
            <input type="number" id="mluc_credit_rate" class="small-text" name="mluc_options[credit_rate]" value="<?php echo esc_attr((string) $rate); ?>" min="0.01" step="0.01">
            <span class="description"><?php echo esc_html(sprintf(__('用户自定义充值时：应付金额 = 积分数 ÷ 该比例。例：10 表示 1%1$s 得 10 积分；支付订单时按此比例换算积分（向上取整）。', 'moonlight-user-center'), $symbol)); ?></span>
        </p>
        <p>
            <label for="mluc_credit_packages"><strong><?php esc_html_e('充值套餐（每行一条：积分|金额）', 'moonlight-user-center'); ?></strong></label><br>
            <textarea id="mluc_credit_packages" class="large-text" rows="4" name="mluc_options[credit_packages_txt]" placeholder="100|10&#10;500|45&#10;1000|80"><?php echo esc_textarea($packages_txt); ?></textarea>
            <span class="description"><?php echo esc_html(sprintf(__('例：100|10 表示 100 积分卖 %1$s10。留空使用内置默认套餐（100|10、500|45、1000|80）。套餐金额即服务端价格，不经过比例换算。', 'moonlight-user-center'), $symbol)); ?></span>
        </p>
        <p>
            <label for="mluc_credit_custom_min"><?php esc_html_e('自定义充值限额', 'moonlight-user-center'); ?></label>
            <input type="number" id="mluc_credit_custom_min" class="small-text" name="mluc_options[credit_custom_min]" value="<?php echo esc_attr((string) $cmin); ?>" min="1"> -
            <input type="number" id="mluc_credit_custom_max" class="small-text" name="mluc_options[credit_custom_max]" value="<?php echo esc_attr((string) $cmax); ?>" min="0">
            <span class="description"><?php esc_html_e('单次自定义充值的积分数量区间（上限填 0 = 不限制）。', 'moonlight-user-center'); ?></span>
        </p>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[balance_enabled]" value="1" <?php checked($balance_on); ?>>
                <?php esc_html_e('启用余额钱包（余额可作为支付方式，并作为积分兑换的目标）', 'moonlight-user-center'); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[credit_exchange_enabled]" value="1" <?php checked($ex_on); ?>>
                <strong><?php esc_html_e('启用积分兑换余额', 'moonlight-user-center'); ?></strong>
            </label>
        </p>
        <p>
            <label for="mluc_credit_exchange_rate"><strong><?php esc_html_e('兑换比例（多少积分 = 1 货币单位余额）', 'moonlight-user-center'); ?></strong></label><br>
            <input type="number" id="mluc_credit_exchange_rate" class="small-text" name="mluc_options[credit_exchange_rate]" value="<?php echo esc_attr((string) $ex_rate); ?>" min="0.01" step="0.01">
            <span class="description"><?php echo esc_html(sprintf(__('例：100 表示 100 积分兑换 1%1$s 余额。兑换所得余额可用于余额支付。', 'moonlight-user-center'), $symbol)); ?></span>
        </p>
        <p>
            <label for="mluc_credit_exchange_min"><?php esc_html_e('单次最少兑换积分', 'moonlight-user-center'); ?></label>
            <input type="number" id="mluc_credit_exchange_min" class="small-text" name="mluc_options[credit_exchange_min]" value="<?php echo esc_attr((string) $ex_min); ?>" min="1">
        </p>
        <hr>
        <p>
            <label>
                <input type="checkbox" name="mluc_options[checkin_enabled]" value="1" <?php checked(!empty(mluc_get_option('checkin_enabled', 0))); ?>>
                <strong><?php esc_html_e('启用每日签到送积分', 'moonlight-user-center'); ?></strong>
            </label>
        </p>
        <p>
            <label for="mluc_checkin_base"><?php esc_html_e('基础奖励', 'moonlight-user-center'); ?></label>
            <input type="number" id="mluc_checkin_base" class="small-text" name="mluc_options[checkin_base]" value="<?php echo esc_attr((string) max(0, (int) mluc_get_option('checkin_base', 5))); ?>" min="0">
            <?php echo esc_html($name); ?>，
            <label for="mluc_checkin_every"><?php esc_html_e('连续每满', 'moonlight-user-center'); ?></label>
            <input type="number" id="mluc_checkin_every" class="small-text" name="mluc_options[checkin_every]" value="<?php echo esc_attr((string) max(0, (int) mluc_get_option('checkin_every', 7))); ?>" min="0">
            <?php esc_html_e('天额外', 'moonlight-user-center'); ?>
            <label for="mluc_checkin_extra"></label>
            <input type="number" id="mluc_checkin_extra" class="small-text" name="mluc_options[checkin_extra]" value="<?php echo esc_attr((string) max(0, (int) mluc_get_option('checkin_extra', 20))); ?>" min="0">
            <span class="description"><?php echo esc_html(sprintf(__('例：基础 5%1$s，连续每满 7 天额外 +20%1$s（当天发放）。填 0 可关闭加成。', 'moonlight-user-center'), $name)); ?></span>
        </p>
        <p class="description">
            <?php esc_html_e('账本安全：余额与积分的每次增减均为原子操作并记录流水；订单支付按服务端换算，客户端传值不参与计价。与 Moonlight Shop 同装时，商城积分模块优先，本区块设置仅作用于独立流程。', 'moonlight-user-center'); ?>
        </p>
        <?php
    }

    /**
     * 清洗提交值。复选框未勾选时不提交，必须显式记为 0。
     */
    public function sanitize_options($input)
    {
        $input  = is_array($input) ? $input : array();
        $options = get_option('mluc_options', array());

        foreach (array('account_page_id', 'login_page_id', 'register_page_id', 'lostpassword_page_id') as $k) {
            $options[$k] = isset($input[$k]) ? (int) $input[$k] : 0;
        }

        $options['redirect_after_login'] = isset($input['redirect_after_login'])
            ? esc_url_raw(trim($input['redirect_after_login'])) : '';

        $options['enable_avatar'] = !empty($input['enable_avatar']) ? 1 : 0;

        // 普通会员（free 基座等级）开关
        $options['free_level_enabled'] = !empty($input['free_level_enabled']) ? 1 : 0;

        // 独立收款设置（仅装用户中心时生效）
        $options['pay_currency_symbol'] = isset($input['pay_currency_symbol'])
            ? sanitize_text_field(wp_unslash($input['pay_currency_symbol'])) : '';
        $options['pay_manual_instructions'] = isset($input['pay_manual_instructions'])
            ? sanitize_textarea_field(wp_unslash($input['pay_manual_instructions'])) : '';
        // 支付方式开关（线下转账）+ 订单自动关闭（对所有网关生效）
        $options['manual_enabled'] = !empty($input['manual_enabled']) ? 1 : 0;
        $options['order_auto_close_hours'] = max(0, min(8760, (int) ($input['order_auto_close_hours'] ?? 72)));

        // 在线支付（PayPal / Stripe）
        $code = strtoupper(sanitize_text_field(wp_unslash($input['pay_currency_code'] ?? '')));
        $options['pay_currency_code'] = preg_match('/^[A-Z]{3}$/', $code) ? $code : 'USD';
        $options['paypal_enabled']    = !empty($input['paypal_enabled']) ? 1 : 0;
        $pp_mode = sanitize_key(wp_unslash($input['paypal_mode'] ?? 'sandbox'));
        $options['paypal_mode']       = in_array($pp_mode, array('sandbox', 'live'), true) ? $pp_mode : 'sandbox';
        $options['paypal_client_id']  = isset($input['paypal_client_id']) ? sanitize_text_field(wp_unslash($input['paypal_client_id'])) : '';
        $options['paypal_secret']     = isset($input['paypal_secret']) ? sanitize_text_field(wp_unslash($input['paypal_secret'])) : '';
        $options['stripe_enabled']    = !empty($input['stripe_enabled']) ? 1 : 0;
        $options['stripe_pk']         = isset($input['stripe_pk']) ? sanitize_text_field(wp_unslash($input['stripe_pk'])) : '';
        $options['stripe_sk']         = isset($input['stripe_sk']) ? sanitize_text_field(wp_unslash($input['stripe_sk'])) : '';
        $options['stripe_webhook_secret'] = isset($input['stripe_webhook_secret']) ? sanitize_text_field(wp_unslash($input['stripe_webhook_secret'])) : '';

        // 在线支付（支付宝）。密钥脱敏：留空 = 保持既有配置；绝不写空值覆盖。
        $options['alipay_enabled'] = !empty($input['alipay_enabled']) ? 1 : 0;
        $ali_mode = sanitize_key(wp_unslash($input['alipay_mode'] ?? 'sandbox'));
        $options['alipay_mode']    = in_array($ali_mode, array('sandbox', 'production'), true) ? $ali_mode : 'sandbox';
        $options['alipay_app_id']  = isset($input['alipay_app_id']) ? sanitize_text_field(wp_unslash($input['alipay_app_id'])) : '';
        foreach (array('alipay_private_key', 'alipay_public_key') as $ali_key) {
            $val = isset($input[$ali_key]) ? trim((string) wp_unslash($input[$ali_key])) : '';
            if ('' !== $val) {
                $options[$ali_key] = sanitize_textarea_field($val);
            }
        }

        // License / Pro
        $options['license_server_url'] = isset($input['license_server_url'])
            ? esc_url_raw(trim((string) wp_unslash($input['license_server_url']))) : '';
        $auto_csv = isset($input['license_auto_levels_csv']) ? (string) wp_unslash($input['license_auto_levels_csv']) : '';
        $auto_levels = array();
        foreach (explode(',', $auto_csv) as $lv) {
            $lv = sanitize_key(trim($lv));
            if ('' !== $lv) {
                $auto_levels[] = $lv;
            }
        }
        $options['license_auto_levels'] = array_values(array_unique($auto_levels));

        // 邮件通知开关
        $options['email_purchase_enabled']       = !empty($input['email_purchase_enabled']) ? 1 : 0;
        $options['email_license_reminder_enabled'] = !empty($input['email_license_reminder_enabled']) ? 1 : 0;

        // 支付调试日志（排查用）
        $options['pay_debug_log'] = !empty($input['pay_debug_log']) ? 1 : 0;

        // 积分与余额（v2.1.0）：比例/限额数值守卫，套餐逐行解析后存数组。
        $options['credit_enabled'] = !empty($input['credit_enabled']) ? 1 : 0;
        $options['balance_enabled'] = !empty($input['balance_enabled']) ? 1 : 0;
        $credit_name = isset($input['credit_name']) ? sanitize_text_field(wp_unslash($input['credit_name'])) : '';
        $options['credit_name'] = '' !== trim($credit_name) ? $credit_name : '';
        $credit_rate = isset($input['credit_rate']) ? (float) $input['credit_rate'] : 10;
        $options['credit_rate'] = $credit_rate > 0 ? $credit_rate : 10;
        $exchange_rate = isset($input['credit_exchange_rate']) ? (float) $input['credit_exchange_rate'] : 100;
        $options['credit_exchange_rate'] = $exchange_rate > 0 ? $exchange_rate : 100;
        $options['credit_custom_min'] = max(1, (int) ($input['credit_custom_min'] ?? 10));
        $options['credit_custom_max'] = max(0, (int) ($input['credit_custom_max'] ?? 0));
        $options['credit_exchange_min'] = max(1, (int) ($input['credit_exchange_min'] ?? 100));
        $options['credit_exchange_enabled'] = !empty($input['credit_exchange_enabled']) ? 1 : 0;
        $options['checkin_enabled'] = !empty($input['checkin_enabled']) ? 1 : 0;
        $options['checkin_base']  = max(0, (int) ($input['checkin_base'] ?? 5));
        $options['checkin_every'] = max(0, (int) ($input['checkin_every'] ?? 7));
        $options['checkin_extra'] = max(0, (int) ($input['checkin_extra'] ?? 20));
        // 套餐：文本「积分|金额」逐行解析，非法行跳过；留空 = 使用内置默认（mluc_get_recharge_packages 处理）。
        $packages_txt = isset($input['credit_packages_txt']) ? (string) wp_unslash($input['credit_packages_txt']) : '';
        $clean_packages = array();
        foreach (preg_split('/\r\n|\r|\n/', $packages_txt) as $line) {
            $line = trim(sanitize_text_field((string) $line));
            if ('' === $line || false === strpos($line, '|')) {
                continue;
            }
            list($p_credit, $p_price) = array_map('trim', explode('|', $line, 2));
            $p_credit = (float) $p_credit;
            $p_price  = (float) $p_price;
            if ($p_credit > 0 && $p_price > 0) {
                $clean_packages[] = array('credit' => $p_credit, 'price' => $p_price);
            }
        }
        $options['credit_packages'] = $clean_packages;

        // 界面文案（数组，逐项清洗；空值回退默认由 mluc_ui_label 处理）
        $options['ui_labels'] = array();
        if (isset($input['ui_labels']) && is_array($input['ui_labels'])) {
            foreach ($input['ui_labels'] as $k => $v) {
                $key = sanitize_key(wp_unslash($k));
                if ('' === $key) {
                    continue;
                }
                $options['ui_labels'][$key] = sanitize_text_field(wp_unslash($v));
            }
        }

        // 侧栏菜单图标：每项存 ['type' => 'dashicon'|'image', 'value' => 类名 | attachment_id]
        // 兼容旧裸字符串数据（自动包成 dashicon 结构）；失效附件降级为空。
        if (isset($input['nav_icons']) && is_array($input['nav_icons'])) {
            $clean = array();
            foreach ($input['nav_icons'] as $k => $v) {
                $key = sanitize_key($k);
                if (!$key) {
                    continue;
                }
                if (!is_array($v)) {
                    // 旧格式：裸字符串（dashicons 类名）
                    $str = trim((string) $v);
                    $clean[$key] = array(
                        'type'  => 'dashicon',
                        'value' => (strpos($str, 'dashicons-') === 0) ? sanitize_key($str) : '',
                    );
                    continue;
                }
                $type = isset($v['type']) ? sanitize_key($v['type']) : 'dashicon';
                if ('image' === $type) {
                    $att = isset($v['value']) ? (int) $v['value'] : 0;
                    if ($att && wp_get_attachment_url($att)) {
                        $clean[$key] = array('type' => 'image', 'value' => $att);
                    } else {
                        $clean[$key] = array('type' => 'dashicon', 'value' => '');
                    }
                } else {
                    $str = isset($v['value']) ? trim((string) $v['value']) : '';
                    $clean[$key] = array(
                        'type'  => 'dashicon',
                        'value' => (strpos($str, 'dashicons-') === 0) ? sanitize_key($str) : '',
                    );
                }
            }
            $options['nav_icons'] = $clean;
        } else {
            $options['nav_icons'] = array();
        }

        // 会员等级定义（支持新增 / 编辑 / 删除）
        if (isset($input['membership_levels']) && is_array($input['membership_levels'])) {
            $clean = array();
            foreach ($input['membership_levels'] as $raw_key => $v) {
                if (!is_array($v)) {
                    continue;
                }
                // 无 JS 回退：勾选「删除」的等级（含 free 基座）直接跳过
                if (!empty($v['delete']) && '__new__' !== substr((string) $raw_key, 0, 7)) {
                    continue;
                }
                $is_new = ('__new__' === substr((string) $raw_key, 0, 7));
                if ($is_new) {
                    // 新等级：从子字段 key 解析真实 key（默认用 label 派生）
                    $desired = isset($v['key']) ? sanitize_key($v['key']) : '';
                    if ('' === $desired) {
                        $desired = isset($v['label']) ? sanitize_key($v['label']) : '';
                    }
                    if ('' === $desired || 'free' === $desired) {
                        continue; // 无效或保留键，跳过新增
                    }
                    $real_key = self::unique_level_key($desired, $clean);
                } else {
                    $real_key = sanitize_key($raw_key);
                }

                if ('free' === $real_key) {
                    // free 为系统基座：强制属性，价格/有效期恒为永久
                    $clean['free'] = array(
                        'label'      => isset($v['label']) ? sanitize_text_field($v['label']) : '普通',
                        'color'      => isset($v['color']) ? sanitize_hex_color($v['color']) : '#95a5a6',
                        'price'      => 0,
                        'validity'   => 0,
                        'sort_order' => 10,
                    );
                    continue;
                }

                $validity = isset($v['validity']) ? (int) $v['validity'] : 0;
                $clean[$real_key] = array(
                    'label'      => isset($v['label']) ? sanitize_text_field($v['label']) : $real_key,
                    'color'      => isset($v['color']) ? sanitize_hex_color($v['color']) : '#2f6fed',
                    'price'      => isset($v['price']) ? (float) $v['price'] : 0,
                    'validity'   => $validity,
                    'sort_order' => isset($v['sort_order']) ? (int) $v['sort_order'] : 50,
                );
            }
            // free 基座是否保留由「启用普通会员」开关决定（见 MLUC_Membership::get_levels()）
            $options['membership_levels'] = $clean;
            // free 等级被删除时强制关闭普通会员基座，避免 get_levels() 又把 free 补回来
            if (!isset($clean['free'])) {
                $options['free_level_enabled'] = 0;
            }
        } else {
            $existing = mluc_get_option('membership_levels', array());
            if (is_array($existing)) {
                $options['membership_levels'] = $existing;
            }
        }

        // 第三方登录凭据
        if (isset($input['oauth']) && is_array($input['oauth'])) {
            $clean = array();
            foreach ($input['oauth'] as $pid => $v) {
                if (!is_array($v)) {
                    continue;
                }
                $clean[$pid] = array(
                    'client_id'     => isset($v['client_id']) ? sanitize_text_field($v['client_id']) : '',
                    'client_secret' => isset($v['client_secret']) ? sanitize_text_field($v['client_secret']) : '',
                );
            }
            if ($clean) {
                $options['oauth'] = $clean;
            }
        }

        return $options;
    }

    /**
     * 为新等级生成不与现有键冲突的 key（冲突时追加 _2 / _3 …）。
     */
    private static function unique_level_key($desired, $existing)
    {
        if (!isset($existing[$desired])) {
            return $desired;
        }
        $i = 2;
        while (isset($existing[$desired . '_' . $i])) {
            $i++;
        }
        return $desired . '_' . $i;
    }

    /**
     * 会员头像库入口（与「常规设置 → 头像上传开关」分离；此区块是管理员管理库本身的入口）。
     * 列出最近 8 个头像的缩略图 + 「前往头像库管理」按钮，避免再单独开一个菜单项。
     */
    public function render_avatar_library_field()
    {
        if (!class_exists('MLUC_Avatar')) {
            echo '<p class="description">' . esc_html__('头像库模块未加载。', 'moonlight-user-center') . '</p>';
            return;
        }
        $avatars = MLUC_Avatar::get_library();
        $manage_url = admin_url('edit.php?post_type=' . MLUC_Avatar::CPT);
        $count = is_array($avatars) ? count($avatars) : 0;
        $preview = is_array($avatars) ? array_slice($avatars, 0, 8) : array();
        ?>
        <div class="mluc-avatar-library-box">
            <p class="description" style="margin-top:0;">
                <?php echo esc_html(sprintf(
                    /* translators: %d: 头像总数 */
                    __('当前头像库共 %d 张可选头像；用户在账户中心「个人资料」页从这些头像中选择一个作为自己的头像。', 'moonlight-user-center'),
                    $count
                )); ?>
            </p>
            <?php if ($preview) : ?>
                <ul class="mluc-avatar-library-preview">
                    <?php foreach ($preview as $a) : ?>
                        <li><img src="<?php echo esc_url($a['url']); ?>" alt="<?php echo esc_attr($a['title']); ?>"></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="description"><?php esc_html_e('头像库为空。点击下方按钮上传头像。', 'moonlight-user-center'); ?></p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url($manage_url); ?>" class="button button-primary">
                    <?php esc_html_e('前往头像库管理 →', 'moonlight-user-center'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * 渲染设置页。
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap mluc-settings-wrap">
            <h1><?php echo esc_html__('用户中心设置', 'moonlight-user-center'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('mluc_settings');
                do_settings_sections('mluc-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
