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
        add_filter('plugin_action_links_' . plugin_basename(MLUC_PLUGIN_FILE), array($this, 'add_action_links'));
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
        wp_enqueue_style('mluc-style', MLUC_PLUGIN_URL . 'assets/css/mluc.css', array(), MLUC_VERSION);
        // WP 媒体库（侧栏菜单图标可上传 PNG / SVG）
        wp_enqueue_media();
        wp_enqueue_script(
            'mluc-admin',
            MLUC_PLUGIN_URL . 'assets/js/mluc-admin.js',
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
     * free 为系统基座等级（不可删除、价格/有效期恒为永久）。
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
            <?php esc_html_e('在此新增、编辑或删除会员等级。删除某等级后，原拥有该等级的用户会自动降级为「普通」。价格与有效期将用于「升级会员」购买流程（后续阶段接入）。', 'moonlight-user-center'); ?>
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
                            <?php if ($is_free) : ?>
                                <span class="mluc-fixed-tag"><?php esc_html_e('不可删除', 'moonlight-user-center'); ?></span>
                            <?php else : ?>
                                <button type="button" class="mluc-del-level"><?php esc_html_e('删除', 'moonlight-user-center'); ?></button>
                                <label class="mluc-del-check"><input type="checkbox" name="<?php echo esc_attr($np); ?>[delete]" value="1"> <?php esc_html_e('删除', 'moonlight-user-center'); ?></label>
                            <?php endif; ?>
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
        $symbol       = mluc_get_option('pay_currency_symbol', 'HK$');
        $instructions = mluc_get_option('pay_manual_instructions', '');
        ?>
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
                // 无 JS 回退：勾选「删除」的非 free 等级直接跳过
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
