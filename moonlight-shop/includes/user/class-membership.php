<?php
/**
 * 会员等级系统：等级定义可由后台「会员等级定义」动态新增 / 编辑 / 删除。
 *
 * 存储：user meta mluc_membership_level (等级 key) + mluc_membership_expires (timestamp)。
 * 等级真相源：mluc_options['membership_levels']；为空时回退到本类工厂默认（含 free 等 5 级）。
 * 提供 helper 函数 mluc_user_level() / mluc_user_can_access() / mluc_user_is_expired()。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Membership
{
    /** @var array<string,array<string,mixed>> */
    private static $levels = array(
        'free' => array(
            'label'       => 'Basic',
            'sort_order'  => 10,
            'expireable'  => false,
            'description' => 'Browse basic content and purchase paid materials.',
        ),
        'monthly' => array(
            'label'       => 'Monthly',
            'sort_order'  => 20,
            'expireable'  => true,
            'description' => 'Unlock monthly materials and demo videos.',
        ),
        'gold' => array(
            'label'       => 'Gold Member',
            'sort_order'  => 25,
            'expireable'  => true,
            'description' => 'Access advanced materials and worksheet downloads.',
        ),
        'premium' => array(
            'label'       => 'Premium',
            'sort_order'  => 30,
            'expireable'  => true,
            'description' => 'Unlock all premium materials, demo videos and worksheets.',
        ),
        'diamond' => array(
            'label'       => 'Diamond Member',
            'sort_order'  => 35,
            'expireable'  => true,
            'description' => 'Unlimited access to all site resources, including new content.',
        ),
    );

    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct()
    {
        add_action('show_user_profile', array($this, 'render_profile_fields'));
        add_action('edit_user_profile', array($this, 'render_profile_fields'));
        add_action('personal_options_update', array($this, 'save_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_profile_fields'));
        // 商城訂單付款後自動升級會員（priority 15：早於發信 20，確保郵件能反映新等級）
        // 同时挂 paid 与 completed：
        //  - 即时网关（余额/Stripe/PayPal）走 paid → 立即授予；
        //  - 货到付款(COD) 走 processing → completed，paid 不触发，故需挂 completed 才能授予。
        // grant_from_order 内部有 _mluc_membership_granted 幂等标记，paid+completed 重复触发不会重复授予。
        add_action('mlshop_order_paid', array($this, 'grant_from_order'), 15);
        add_action('mlshop_order_completed', array($this, 'grant_from_order'), 15);
    }

    /**
     * 等级标识颜色（用于 UI 标签上色）。
     */
    public static function get_level_color($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]['color']) ? $levels[$key]['color'] : '';
    }

    /**
     * 等级购买价格（来自后台定义）。
     */
    public static function get_level_price($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]['price']) ? (float) $levels[$key]['price'] : 0;
    }

    /**
     * 等级有效期（天，0 = 永久）。
     */
    public static function get_level_validity($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]['validity']) ? (int) $levels[$key]['validity'] : 0;
    }

    /**
     * 普通会员（free 基座等级）是否启用。
     * 关闭后站点仅保留付费等级，未购买会员的用户不拥有任何等级；
     * 标记为 free 的公开内容仍对所有人可见（free 是内容可见性标签，不等同于会员身份）。
     */
    public static function free_enabled()
    {
        $on = function_exists('mluc_get_option') ? mluc_get_option('free_level_enabled', 1) : 1;
        return !empty($on);
    }

    /**
     * 全部等级定义（动态，真相源 = mluc_options['membership_levels']）。
     * 未配置时回退到工厂默认；始终保证 free 存在；按 sort_order 升序。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function get_levels()
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }
        $saved = function_exists('mluc_get_option') ? mluc_get_option('membership_levels', array()) : array();
        if (!is_array($saved) || empty($saved)) {
            // 后台未定义任何等级：使用插件内置工厂默认
            $levels = self::$levels;
        } else {
            $levels = array();
            foreach ($saved as $key => $lv) {
                if (!is_array($lv)) {
                    continue;
                }
                $key = sanitize_key($key);
                if ('' === $key) {
                    continue;
                }
                $validity = isset($lv['validity']) ? (int) $lv['validity'] : 0;
                $levels[$key] = array(
                    'label'       => isset($lv['label']) && '' !== (string) $lv['label'] ? $lv['label'] : $key,
                    'color'       => isset($lv['color']) ? $lv['color'] : (isset(self::$levels[$key]['color']) ? self::$levels[$key]['color'] : '#2f6fed'),
                    'price'       => isset($lv['price']) ? (float) $lv['price'] : 0,
                    'validity'    => $validity,
                    'sort_order'  => isset($lv['sort_order']) ? (int) $lv['sort_order'] : (isset(self::$levels[$key]['sort_order']) ? (int) self::$levels[$key]['sort_order'] : 50),
                    'expireable'  => ('free' === $key) ? false : ($validity > 0),
                    'description' => isset($lv['description']) ? (string) $lv['description'] : (isset(self::$levels[$key]['description']) ? self::$levels[$key]['description'] : ''),
                );
            }
            // 兜底：未提供 description 的等级用工厂默认（首次启用插件或新等级默认文案）
            foreach ($levels as $k => $lv) {
                if ((!isset($lv['description']) || '' === (string) $lv['description']) && isset(self::$levels[$k]['description'])) {
                    $levels[$k]['description'] = self::$levels[$k]['description'];
                }
            }
        }
        // 普通会员（free 基座）开关：开启时补回缺失的 free；关闭时移除（未付费用户视为无等级）
        if (self::free_enabled()) {
            if (!isset($levels['free'])) {
                $levels['free'] = self::$levels['free'];
            }
        } else {
            unset($levels['free']);
        }
        $levels = apply_filters('mluc_membership_levels', $levels);
        uasort($levels, function ($a, $b) {
            return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0);
        });
        $cache = $levels;
        return $levels;
    }

    /**
     * 单个等级的标签。
     */
    public static function get_level_label($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]) ? $levels[$key]['label'] : $key;
    }

    /**
     * 渲染等级徽章 HTML（背景/前景色取自后台自定义 color；无 color 时回退到月光默认）。
     */
    public static function get_level_badge($key)
    {
        $levels = self::get_levels();
        $label  = isset($levels[$key]['label']) ? $levels[$key]['label'] : $key;
        $color  = isset($levels[$key]['color']) ? $levels[$key]['color'] : '';
        $style  = '';
        if ($color && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            // 自定义色：浅色背景（hex 取 0.18 alpha），深色前景（直接用 hex）
            $style = ' style="background:' . esc_attr($color) . '1a;color:' . esc_attr($color) . ';border:1px solid ' . esc_attr($color) . '33;"';
        }
        return '<span class="mluc-level-badge mluc-level-' . esc_attr($key) . '"' . $style . '>' . esc_html($label) . '</span>';
    }

    /**
     * 当前用户等级 key；未登录或未设置视为 free（free 关闭时为无等级空字符串）。
     */
    public static function get_user_level($user_id = 0)
    {
        $default = self::free_enabled() ? 'free' : '';
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return $default;
        }
        $level = get_user_meta($user_id, 'mluc_membership_level', true);
        if (!$level || !isset(self::get_levels()[$level])) {
            $level = $default;
        }
        // 到期检查：付费等级过期则降级（free 开启→降回 free；free 关闭→清空等级）
        if ($level !== $default && self::is_user_expired($user_id)) {
            if ('' === $default) {
                delete_user_meta($user_id, 'mluc_membership_level');
                delete_user_meta($user_id, 'mluc_membership_expires');
            } else {
                self::set_user_level($user_id, $default, 0);
            }
            $level = $default;
        }
        return $level;
    }

    /**
     * 当前用户是否已过期。
     */
    public static function is_user_expired($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return true;
        }
        $expires = (int) get_user_meta($user_id, 'mluc_membership_expires', true);
        // 未设置到期时间 = 永不过期（用于免费等级或管理员手工设为永久）
        if (!$expires) {
            return false;
        }
        return $expires < time();
    }

    /**
     * 设置用户等级与到期时间。$expires=0 表示永不过期。
     *
     * @param int $user_id
     * @param string $level
     * @param int $expires Unix timestamp
     */
    public static function set_user_level($user_id, $level, $expires = 0)
    {
        $user_id = (int) $user_id;
        // '' 表示清空等级（普通会员关闭时，未付费用户无等级）
        if ('' !== $level && !isset(self::get_levels()[$level])) {
            return false;
        }
        if ('' === $level) {
            delete_user_meta($user_id, 'mluc_membership_level');
            delete_user_meta($user_id, 'mluc_membership_expires');
            do_action('mluc_membership_changed', $user_id, '', 0);
            return true;
        }
        update_user_meta($user_id, 'mluc_membership_level', $level);
        update_user_meta($user_id, 'mluc_membership_expires', (int) $expires);
        do_action('mluc_membership_changed', $user_id, $level, (int) $expires);
        return true;
    }

    /**
     * 訂單付款後授予會員等級（由 moonlight-shop 的 mlshop_order_paid 钩子觸發）。
     * 遍歷訂單商品，對設定了 _mlshop_membership_level 的商品升級對應等級。
     * 只升不降：已是更高等級（如高級）時，購買月費不會降級。
     *
     * @param int $order_id
     */
    public function grant_from_order($order_id)
    {
        if (get_post_type($order_id) !== 'mlshop_order') {
            return;
        }
        // 幂等：webhook 重試 / 重複回調時不重複授予（避免月費被多次續期）
        if (get_post_meta($order_id, '_mluc_membership_granted', true)) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if (!$user_id) {
            return;
        }
        $items = (array) get_post_meta($order_id, '_mlshop_items', true);
        $granted_any = false;
        foreach ($items as $it) {
            $pid   = isset($it['id']) ? (int) $it['id'] : 0;
            $grant = get_post_meta($pid, '_mlshop_membership_level', true);
            // 仅授予在后台定义中存在的付费等级（放开原 monthly/premium 硬编码限制）
            if (!$grant || 'free' === $grant || !isset(self::get_levels()[$grant])) {
                continue;
            }
            // 仅在用户存在时授予，避免孤立订单触发错误升级
            if (get_user_by('id', $user_id)) {
                if ($this->grant_level($user_id, $grant)) {
                    $granted_any = true;
                }
            }
        }
        if ($granted_any) {
            update_post_meta($order_id, '_mluc_membership_granted', current_time('mysql'));
        }
    }

    /**
     * 授予等級（只升不降）。有效期来自后台定义：
     * - validity = 0：永久（expires=0）。
     * - validity > 0：在「現有有效到期日」基礎上 +N 天；若當前無效則從現在起算。
     *
     * @param int    $user_id
     * @param string $level
     * @return bool
     */
    public function grant_level($user_id, $level)
    {
        $user_id = (int) $user_id;
        $levels  = self::get_levels();
        if (!isset($levels[$level])) {
            return false;
        }
        $current = self::get_user_level($user_id);
        // 已是更高或同等等級則不變更（月費不會覆蓋高級）
        if (self::get_level_sort_order($current) >= self::get_level_sort_order($level)) {
            return false;
        }
        $validity = (int) ($levels[$level]['validity'] ?? 0);
        if ($validity <= 0) {
            // 永久等級（如 premium）
            self::set_user_level($user_id, $level, 0);
            return true;
        }
        $expires = (int) get_user_meta($user_id, 'mluc_membership_expires', true);
        $base    = ($expires && $expires > time()) ? $expires : time();
        $new_expires = (int) strtotime('+' . $validity . ' days', $base);
        self::set_user_level($user_id, $level, $new_expires);
        return true;
    }

    /**
     * 等级排序权重，用于比较"是否 >= 某等级"。
     */
    public static function get_level_sort_order($level)
    {
        $levels = self::get_levels();
        return isset($levels[$level]) ? (int) $levels[$level]['sort_order'] : 0;
    }

    /**
     * 当前用户是否有权访问指定等级。
     */
    public static function user_can_access($required_level, $user_id = 0)
    {
        // free 内容恒公开：无论普通会员是否启用，标记为 free 的内容对所有人可见
        if ('free' === $required_level) {
            return true;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $current = self::get_user_level($user_id);
        return self::get_level_sort_order($current) >= self::get_level_sort_order($required_level);
    }

    /**
     * 当前用户等级到期时间（Unix timestamp），0 表示永久。
     */
    public static function get_user_expires($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return 0;
        }
        return (int) get_user_meta($user_id, 'mluc_membership_expires', true);
    }

    /**
     * 后台用户编辑页渲染会员等级字段。
     */
    public function render_profile_fields($user)
    {
        // 安全：仅管理员可见/可改会员等级。edit_user 对"用户本人"恒为真，
        // 不能作为守卫，否则任何订阅者都能在个人资料页给自己升到最高等级（提权）。
        if (!current_user_can('manage_options')) {
            return;
        }
        $current_level   = get_user_meta($user->ID, 'mluc_membership_level', true);
        $current_expires = get_user_meta($user->ID, 'mluc_membership_expires', true);
        $default_level   = self::free_enabled() ? 'free' : '';
        if (!$current_level || !isset(self::get_levels()[$current_level])) {
            $current_level = $default_level;
        }
        $expires_value = $current_expires ? wp_date('Y-m-d', (int) $current_expires) : '';
        $expires_never = !$current_expires ? ' checked="checked"' : '';
        ?>
        <h2 id="mluc-membership"><?php echo esc_html__('会员等级', 'moonlight-user-center'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="mluc_membership_level"><?php echo esc_html__('等级', 'moonlight-user-center'); ?></label></th>
                <td>
                    <select name="mluc_membership_level" id="mluc_membership_level">
                        <?php if (!self::free_enabled()) : ?>
                            <option value=""<?php selected($current_level, ''); ?>><?php esc_html_e('（無會員等級）', 'moonlight-user-center'); ?></option>
                        <?php endif; ?>
                        <?php foreach (self::get_levels() as $key => $lv) : ?>
                            <option value="<?php echo esc_attr($key); ?>"<?php selected($current_level, $key); ?>>
                                <?php echo esc_html($lv['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php echo esc_html__('可选等级由后台「会员等级定义」配置。', 'moonlight-user-center'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="mluc_membership_expires_date"><?php echo esc_html__('到期日', 'moonlight-user-center'); ?></label></th>
                <td>
                    <input type="date" name="mluc_membership_expires_date" id="mluc_membership_expires_date" value="<?php echo esc_attr($expires_value); ?>" />
                    <label style="margin-left:1em;">
                        <input type="checkbox" name="mluc_membership_expires_never" value="1"<?php echo $expires_never; ?> />
                        <?php echo esc_html__('永不过期', 'moonlight-user-center'); ?>
                    </label>
                    <p class="description"><?php echo esc_html__('勾选「永不过期」时忽略日期；不勾选则到期时间为该日 23:59（站点时区）。', 'moonlight-user-center'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * 后台用户编辑页保存会员等级字段。
     */
    public function save_profile_fields($user_id)
    {
        // 安全：同 render，仅管理员可保存等级设置（edit_user 无法防本人提权）。
        if (!current_user_can('manage_options')) {
            return;
        }
        // BUG 修复：字段未提交时不做任何改动（防止其他插件/表单触发 profile 保存钩子时把等级意外重置为 free）。
        if (!isset($_POST['mluc_membership_level'])) {
            return;
        }
        $level = sanitize_key(wp_unslash($_POST['mluc_membership_level']));
        $never = !empty($_POST['mluc_membership_expires_never']);
        $date  = isset($_POST['mluc_membership_expires_date']) ? sanitize_text_field(wp_unslash($_POST['mluc_membership_expires_date'])) : '';
        $expires = 0;
        if ($never) {
            $expires = 0;
        } elseif ($date) {
            // BUG 修复：按站点时区解析到期日 23:59（原实现走 UTC，导致 GMT+8 站点提前 8 小时到期）。
            $dt = date_create_from_format('Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone());
            if ($dt instanceof DateTime) {
                $expires = (int) $dt->getTimestamp();
            } else {
                $ts = strtotime($date . ' 23:59:59');
                $expires = $ts ? (int) $ts : 0;
            }
        }
        self::set_user_level($user_id, $level, $expires);
    }
}

/**
 * 全局 helper：当前用户等级。
 */
if (!function_exists('mluc_user_level')) {
function mluc_user_level($user_id = 0)
{
    return MLUC_Membership::get_user_level($user_id);
}
}

/**
 * 全局 helper：当前用户是否有权访问某等级。
 */
if (!function_exists('mluc_user_can_access')) {
function mluc_user_can_access($required_level, $user_id = 0)
{
    return MLUC_Membership::user_can_access($required_level, $user_id);
}
}

/**
 * 全局 helper：当前用户是否已过期。
 */
if (!function_exists('mluc_user_is_expired')) {
function mluc_user_is_expired($user_id = 0)
{
    return MLUC_Membership::is_user_expired($user_id);
}
}
