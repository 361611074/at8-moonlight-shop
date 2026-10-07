<?php
/**
 * 付费内容访问控制：拦截付费文章正文、校验购买权限、按等级取价、解锁记录。
 *
 * 与已有的"商城购物"流程（购物车 → 订单 → 交付）相互独立：
 *   - 商城购物：把商品加入购物车，付款后发货/发卡密/升级会员。
 *   - 付费内容：直接为"当前文章正文"付费（阅读/下载/图片/视频），付款后解锁该文章。
 *
 * 解锁记录存于 user meta `mlshop_pay_unlocks`（post_id => 过期时间戳，0=永久）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Pay_Access
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
        // 拦截正文（仅在文章详情页、前台）
        add_filter('the_content', array($this, 'filter_content'), 20);
        // 积分/金钱解锁 AJAX
        add_action('wp_ajax_nopriv_mlshop_pay_unlock', array($this, 'ajax_unlock'));
        add_action('wp_ajax_mlshop_pay_unlock', array($this, 'ajax_unlock'));
        // 付费内容订单付款后解锁（線上付款走 paid；貨到付款走 completed）
        add_action('mlshop_order_paid', array($this, 'grant_paywall_order'), 25);
        add_action('mlshop_order_completed', array($this, 'grant_paywall_order'), 25);
        // 付费墙下载端点（兼容本地附件 ID / 外链）
        add_action('template_redirect', array($this, 'handle_paywall_download'));
    }

    /* ---------- 开关与判定 ---------- */

    public static function is_pay_enabled()
    {
        return (bool) mlshop_get_option('pay_enabled', 1);
    }

    /**
     * 文章是否启用了付费（pay_mode != off 且总开关开启）。
     */
    public static function is_paywalled($post_id)
    {
        if (!self::is_pay_enabled()) {
            return false;
        }
        $mode = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_mode', 'off');
        return $mode !== 'off';
    }

    /**
     * 当前用户是否已解锁（管理员永远可见）。
     *
     * Phase C（双付费墙合并）：解锁账本双读——商城 `mlshop_pay_unlocks` 与
     * 旧用户中心 `mluc_pay_unlocks` 同 post_id 任一未过期即视为已解锁
     * （存量解锁零迁移兼容）。写路径保持只写 `mlshop_pay_unlocks`；
     * 过期清理仅针对本商城账本（旧账本只读）。旧插件激活（LEGACY）时
     * 行为与并入前一致，只读本商城账本。
     */
    public static function is_unlocked($post_id, $user_id = 0)
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $ledgers = defined('MLUC_LEGACY_ACTIVE')
            ? array('mlshop_pay_unlocks')
            : array('mlshop_pay_unlocks', 'mluc_pay_unlocks');
        $expired_shop = null;
        foreach ($ledgers as $key) {
            $unlocks = get_user_meta($user_id, $key, true);
            if (!is_array($unlocks) || !isset($unlocks[$post_id])) {
                continue;
            }
            $expire = (int) $unlocks[$post_id];
            if (!$expire || $expire >= time()) {
                return true;
            }
            if ('mlshop_pay_unlocks' === $key) {
                $expired_shop = $unlocks; // 已过期：沿用原「清理后视为未解锁」行为
            }
        }
        if (is_array($expired_shop)) {
            unset($expired_shop[$post_id]);
            update_user_meta($user_id, 'mlshop_pay_unlocks', $expired_shop);
        }
        return false;
    }

    /**
     * 当前用户是否有权购买（登录 + 会员等级门槛 pay_auth）。
     */
    public static function user_can_purchase($post_id, $user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $auth = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_auth', 'all');
        if ($auth === 'all') {
            return true;
        }
        if (!class_exists('MLUC_Membership')) {
            return false;
        }
        $required = ('diamond' === $auth) ? 'diamond' : 'gold';
        return MLUC_Membership::user_can_access($required, $user_id);
    }

    /* ---------- 价格（按等级） ---------- */

    /**
     * 金钱价：优先取对应等级的会员价，未设置则用执行价。
     * （Phase 2 收敛：唯一实现在 Moonlight_Price_Calculator::paywall_price，本方法保留兼容入口。）
     */
    public static function get_price_for_user($post_id, $user_id = 0)
    {
        return Moonlight_Price_Calculator::paywall_price($post_id, $user_id);
    }

    /**
     * 积分价：同上逻辑。
     */
    public static function get_credit_price_for_user($post_id, $user_id = 0)
    {
        return Moonlight_Price_Calculator::paywall_credit_price($post_id, $user_id);
    }

    /* ---------- 解锁 ---------- */

    /**
     * 记录解锁。expire_ts=0 表示永久。
     */
    public static function grant_unlock($post_id, $user_id, $expire_ts = 0)
    {
        $user_id = (int) $user_id;
        $post_id = (int) $post_id;
        if (!$user_id || !$post_id) {
            return false;
        }
        $unlocks = get_user_meta($user_id, 'mlshop_pay_unlocks', true);
        if (!is_array($unlocks)) {
            $unlocks = array();
        }
        $unlocks[$post_id] = (int) $expire_ts;
        update_user_meta($user_id, 'mlshop_pay_unlocks', $unlocks);
        self::bump_sales($post_id);
        do_action('mlshop_pay_unlocked', $post_id, $user_id, $expire_ts);
        return true;
    }

    /**
     * 根据订单时效设置计算解锁过期时间戳（0 = 永久）。
     */
    public static function get_unlock_expire($post_id)
    {
        if (!MLSHOP_Product_Pay_Meta::get($post_id, 'order_expire_enabled', '0')) {
            return 0;
        }
        $value = (int) MLSHOP_Product_Pay_Meta::get($post_id, 'order_expire_value', 0);
        $unit  = MLSHOP_Product_Pay_Meta::get($post_id, 'order_expire_unit', 'day');
        if ($value <= 0) {
            return 0;
        }
        $secs = array(
            'hour'  => 3600,
            'day'   => 86400,
            'month' => 2592000, // 30 天
        );
        $mult = isset($secs[$unit]) ? $secs[$unit] : 86400;
        return time() + $value * $mult;
    }

    /**
     * 销量计数（sales_offset 为后台设置的基数，叠加实际解锁数）。
     */
    public static function get_sales_count($post_id)
    {
        $offset = (int) MLSHOP_Product_Pay_Meta::get($post_id, 'sales_offset', 0);
        $real   = (int) get_post_meta($post_id, '_mlshop_pay_sales', true);
        return $offset + $real;
    }

    private static function bump_sales($post_id)
    {
        $real = (int) get_post_meta($post_id, '_mlshop_pay_sales', true);
        update_post_meta($post_id, '_mlshop_pay_sales', $real + 1);
    }

    /* ---------- 正文拦截 ---------- */

    public function filter_content($content)
    {
        if (is_admin()) {
            return $content;
        }
        if (!is_singular(array('mlshop_product', 'post', 'page'))) {
            return $content;
        }
        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }
        $is_product = (get_post_type($post_id) === 'mlshop_product');

        // 会员模块（僅會員可看）：文章/页面在正文层做门禁（商品由购物车与模板处理购买权限）
        if (!$is_product && !current_user_can('manage_options') && MLSHOP_Product::is_member_only($post_id)) {
            if (!MLSHOP_Product::purchase_check($post_id, get_current_user_id())['ok']) {
                return self::render_member_lock($post_id);
            }
        }

        // 支付模块（付费墙）：商品与文章/页面通用
        if (!self::is_paywalled($post_id)) {
            return $content;
        }

        // 已解锁 / 管理员：放行正文；按模式追加专属资源（下载 / 图集 / 视频）
        if (self::is_unlocked($post_id)) {
            $content .= self::render_pay_resource($post_id);
            return $content;
        }

        // 未解锁：按模式生成预览
        $mode   = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_mode', 'off');
        $teaser = has_excerpt($post_id)
            ? '<p class="mlshop-paywall-teaser">' . get_the_excerpt($post_id) . '</p>'
            : '';

        if ('image' === $mode) {
            // 付费图集：展示前 N 张免费图，其余锁定遮罩，下方附付费提示
            $preview = self::render_image_gallery($post_id, true);
            $locked  = $teaser . $preview . self::render_locked($post_id);
            return $locked;
        }
        if ('video' === $mode) {
            // 付费视频：展示封面 + 锁定遮罩，下方附付费提示
            $preview = self::render_video_player($post_id, true);
            $locked  = $teaser . $preview . self::render_locked($post_id);
            return $locked;
        }

        // 下载 / 阅读：截断摘要 + 付费墙
        $locked = $teaser . self::render_locked($post_id);
        return $locked;
    }

    /**
     * 已解锁后，根据付费模式渲染对应专属资源区块。
     */
    public static function render_pay_resource($post_id)
    {
        $mode = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_mode', 'off');
        switch ($mode) {
            case 'download':
                return self::render_download_block($post_id);
            case 'image':
                return self::render_image_gallery($post_id, false);
            case 'video':
                return self::render_video_player($post_id, false);
            default:
                return '';
        }
    }

    /* ---------- 下载模式（已解锁） ---------- */

    /**
     * 渲染下载资源区：可包含多个资源链接，每个独立按钮 + 复制信息；
     * 共享「下载提示 / 按钮颜色 / 按钮图标 / 文件信息 / 在线预览」。
     */
    private static function render_download_block($post_id)
    {
        $items_raw = MLSHOP_Product_Pay_Meta::get($post_id, 'download_items', '');
        $items = $items_raw ? json_decode($items_raw, true) : array();
        if (!is_array($items)) {
            $items = array();
        }

        // 旧单字段回退（历史文章仅存 download_url 等）
        if (empty($items)) {
            $old_url = get_post_meta($post_id, '_mlshop_download_url', true);
            if ($old_url) {
                $items = array(array(
                    'url'          => $old_url,
                    'label'        => MLSHOP_Product_Pay_Meta::get($post_id, 'download_btn_text', __('立即下载', 'at8-moonlight-shop')),
                    'copy_name'    => get_post_meta($post_id, '_mlshop_download_copy_name', true),
                    'copy_content' => get_post_meta($post_id, '_mlshop_download_copy_content', true),
                ));
            }
        }

        $items = array_filter(array_map(function ($it) use ($post_id) {
            if (!is_array($it) || empty($it['url'])) {
                return null;
            }
            return array(
                'dl_url'       => self::build_download_url($post_id, $it['url']),
                'raw_url'      => $it['url'],
                'label'        => isset($it['label']) && $it['label'] !== '' ? $it['label'] : __('下载', 'at8-moonlight-shop'),
                'copy_name'    => isset($it['copy_name']) ? (string) $it['copy_name'] : '',
                'copy_content' => isset($it['copy_content']) ? (string) $it['copy_content'] : '',
            );
        }, $items));

        if (empty($items)) {
            return '';
        }

        $data = array(
            'post_id'   => $post_id,
            'items'     => array_values($items),
            'note'      => MLSHOP_Product_Pay_Meta::get($post_id, 'download_note', ''),
            'btn_color' => MLSHOP_Product_Pay_Meta::get($post_id, 'download_btn_color', '#2271b1'),
            'btn_icon'  => MLSHOP_Product_Pay_Meta::get($post_id, 'download_btn_icon', ''),
            'attrs'     => self::parse_attrs(MLSHOP_Product_Pay_Meta::get($post_id, 'download_attrs', '')),
            'demo_url'  => MLSHOP_Product_Pay_Meta::get($post_id, 'demo_url', ''),
        );
        ob_start();
        mlshop_get_template('pay-download', $data);
        return ob_get_clean();
    }

    /**
     * 生成真正下载用的内部端点地址（隐藏真实外链 / 本地路径）。
     */
    private static function build_download_url($post_id, $url)
    {
        $token = self::make_pw_token($post_id, $url);
        return add_query_arg('mlshop_pw_dl', $token, home_url());
    }

    /* ---------- 图片模式（locked 预览 / 已解锁全展示） ---------- */

    private static function render_image_gallery($post_id, $locked)
    {
        $ids  = array_filter(array_map('intval', explode(',', (string) MLSHOP_Product_Pay_Meta::get($post_id, 'image_gallery', ''))));
        $ext_raw = MLSHOP_Product_Pay_Meta::get($post_id, 'image_urls', '');
        $ext = $ext_raw ? json_decode($ext_raw, true) : array();
        if (!is_array($ext)) {
            $ext = array();
        }

        $free = (int) MLSHOP_Product_Pay_Meta::get($post_id, 'image_free_count', 0);
        if ($locked && $free < 0) {
            $free = 0;
        }

        $items = array();
        $idx   = 0;
        // 上传图（附件 ID）
        foreach ($ids as $id) {
            $full  = wp_get_attachment_image_url($id, 'large');
            $thumb = wp_get_attachment_image_url($id, 'medium');
            if (!$full) {
                continue;
            }
            $is_free = (!$locked || $idx < $free);
            $items[] = array(
                'id'    => $id,
                'thumb' => $thumb ? $thumb : $full,
                'full'  => $is_free ? $full : '', // 锁定态只输出免费图，付费图仅占位
                'free'  => $is_free,
            );
            $idx++;
        }
        // 外链图片（资源链接地址）：追加在上传图之后
        foreach ($ext as $u) {
            $u = is_string($u) ? trim($u) : '';
            if ($u === '') {
                continue;
            }
            $is_free = (!$locked || $idx < $free);
            $items[] = array(
                'id'    => 0,
                'thumb' => $is_free ? $u : '', // 锁定态不暴露真实外链（仅占位）
                'full'  => $is_free ? $u : '',
                'free'  => $is_free,
            );
            $idx++;
        }

        if (empty($items)) {
            return '';
        }
        ob_start();
        mlshop_get_template('pay-image', array(
            'post_id' => $post_id,
            'items'   => $items,
            'locked'  => $locked,
            'free'    => $free,
            'total'   => count($items),
        ));
        return ob_get_clean();
    }

    /* ---------- 视频模式（locked 预览 / 已解锁播放） ---------- */

    private static function render_video_player($post_id, $locked)
    {
        $items_raw = MLSHOP_Product_Pay_Meta::get($post_id, 'video_items', '');
        $items = $items_raw ? json_decode($items_raw, true) : array();
        if (!is_array($items)) {
            $items = array();
        }

        // 旧单字段回退（历史文章仅存 video_url / video_cover）
        if (empty($items)) {
            $old_url = get_post_meta($post_id, '_mlshop_video_url', true);
            if ($old_url) {
                $items = array(array(
                    'url'   => $old_url,
                    'cover' => (int) get_post_meta($post_id, '_mlshop_video_cover', true),
                    'title' => '',
                ));
            }
        }

        $built = array();
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['url'])) {
                continue;
            }
            $cover = isset($it['cover']) ? (int) $it['cover'] : 0;
            $built[] = array(
                'url'       => $it['url'],
                'cover_url' => $cover ? wp_get_attachment_image_url($cover, 'large') : '',
                'title'     => isset($it['title']) ? (string) $it['title'] : '',
            );
        }
        if (empty($built)) {
            return '';
        }
        ob_start();
        mlshop_get_template('pay-video', array(
            'post_id' => $post_id,
            'items'   => $built,
            'locked'  => $locked,
        ));
        return ob_get_clean();
    }

    /**
     * 解析文件信息键值对（JSON 数组）。
     */
    private static function parse_attrs($raw)
    {
        if (!$raw) {
            return array();
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return array();
        }
        $out = array();
        foreach ($arr as $row) {
            if (is_array($row) && isset($row['k'], $row['v']) && $row['k'] !== '') {
                $out[] = array('k' => $row['k'], 'v' => $row['v']);
            }
        }
        return $out;
    }

    /**
     * 为已解锁用户生成付费墙下载令牌（本地附件 ID 或外链均存原文）。
     */
    private static function make_pw_token($post_id, $url)
    {
        $user_id = get_current_user_id();
        $token   = wp_generate_password(32, false);
        set_transient('mlshop_pw_dl_' . $token, array(
            'user_id' => $user_id,
            'post_id' => (int) $post_id,
            'url'     => $url,
        ), 3600);
        return $token;
    }

    /**
     * 付费墙下载端点：?mlshop_pw_dl=TOKEN
     * 仅已解锁用户可访问；本地附件走流式输出，外链走 302 跳转（真实地址不出现在页面源码）。
     */
    public function handle_paywall_download()
    {
        if (empty($_GET['mlshop_pw_dl'])) {
            return;
        }
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        $token = sanitize_text_field($_GET['mlshop_pw_dl']);
        $data  = get_transient('mlshop_pw_dl_' . $token);
        if (!$data || (int) $data['user_id'] !== get_current_user_id()) {
            wp_die(esc_html__('下载链接无效或已过期。', 'at8-moonlight-shop'));
        }
        // 二次校验：当前用户确实已解锁该文章
        if (!self::is_unlocked($data['post_id'])) {
            wp_die(esc_html__('您尚未购买此内容。', 'at8-moonlight-shop'));
        }
        $url = $data['url'];
        if (is_numeric($url)) {
            $file = get_attached_file((int) $url);
            if (!$file || !file_exists($file)) {
                wp_die(esc_html__('文件不存在。', 'at8-moonlight-shop'));
            }
            header('Content-Type: application/octet-stream');
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file));
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($file));
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- 下载流需要输出文件内容到 stdout
            readfile($file);
            exit;
        }
        // 外链：302 跳转（已登录用户才持有 token，过期即失效）
        wp_redirect(esc_url_raw($url), 302);
        exit;
    }

    /**
     * 渲染付费墙（锁定 + 付费按钮）。
     */
    public static function render_locked($post_id)
    {
        $data = self::get_pay_button_data($post_id);
        ob_start();
        mlshop_get_template('pay-locked', $data);
        return ob_get_clean();
    }

    /**
     * 渲染会员门禁（仅会员可看、未达等级）。
     */
    public static function render_member_lock($post_id)
    {
        $is_logged_in = is_user_logged_in();
        $login_url    = function_exists('mluc_get_login_url')
            ? mluc_get_login_url(get_permalink($post_id))
            : wp_login_url(get_permalink($post_id));
        $account_url  = function_exists('mluc_get_account_url') ? mluc_get_account_url() : $login_url;

        ob_start();
        mlshop_get_template('member-locked', array(
            'post_id'       => $post_id,
            'is_logged_in'  => $is_logged_in,
            'login_url'     => $login_url,
            'account_url'   => $account_url,
        ));
        return ob_get_clean();
    }

    /**
     * 组装付费按钮所需数据。
     */
    public static function get_pay_button_data($post_id)
    {
        $credit_name  = mlshop_get_option('credit_name', __('积分', 'at8-moonlight-shop'));
        $pay_type    = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_type', 'money');
        $pay_mode    = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_mode', 'off');
        $mode_labels = MLSHOP_Product_Pay_Meta::$pay_modes;
        $mode_label  = isset($mode_labels[$pay_mode]) ? $mode_labels[$pay_mode] : __('付费内容', 'at8-moonlight-shop');

        $is_logged_in = is_user_logged_in();
        $can_purchase = self::user_can_purchase($post_id);
        $auth_message = '';
        if ($is_logged_in && !$can_purchase) {
            $auth = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_auth', 'all');
            if ('diamond' === $auth) {
                $auth_message = __('仅钻石会员可购买此内容，请先升级会员。', 'at8-moonlight-shop');
            } else {
                $auth_message = __('需黄金会员及以上可购买此内容，请先升级会员。', 'at8-moonlight-shop');
            }
        }

        $title = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_popup_title', '');
        if (!$title) {
            $title = (string) mlshop_get_option('pay_popup_default_title', '');
        }
        if (!$title) {
            $title = __('付费内容', 'at8-moonlight-shop');
        }
        $desc  = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_popup_desc', '');

        $money_price = self::get_price_for_user($post_id);
        $credit_price = self::get_credit_price_for_user($post_id);
        $original_price = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'price_original', 0);

        // 备注信息（把 Meta Box 其余字段以可读形式呈现）
        $notes = array();
        $sales = self::get_sales_count($post_id);
        if ($sales > 0) {
            /* translators: %d: 数量 */
            $notes['sales'] = sprintf(__('已售 %d 份', 'at8-moonlight-shop'), $sales);
        }
        if (MLSHOP_Product_Pay_Meta::get($post_id, 'order_expire_enabled', '0')) {
            $value = (int) MLSHOP_Product_Pay_Meta::get($post_id, 'order_expire_value', 0);
            $unit  = MLSHOP_Product_Pay_Meta::get($post_id, 'order_expire_unit', 'day');
            $unit_label = isset(MLSHOP_Product_Pay_Meta::$expire_units[$unit]) ? MLSHOP_Product_Pay_Meta::$expire_units[$unit] : '';
            /* translators: 1: 时长数值, 2: 时长单位 */
            $notes['expire'] = sprintf(__('购买后 %1$d%2$s 内有效，逾期需重新购买', 'at8-moonlight-shop'), $value, $unit_label);
        }
        $aff = (float) MLSHOP_Product_Pay_Meta::get($post_id, 'aff_discount', 0);
        if ($aff > 0) {
            /* translators: %s: 值 */
            $notes['aff'] = sprintf(__('推广链接购买可享 %s%% 折扣', 'at8-moonlight-shop'), $aff);
        }
        if (MLSHOP_Product_Pay_Meta::get($post_id, 'allow_coupon', '0')) {
            $notes['coupon'] = __('本内容支持使用优惠码', 'at8-moonlight-shop');
        }
        $free_dl = (int) MLSHOP_Product_Pay_Meta::get($post_id, 'free_downloads', 0);
        if ($free_dl > 0) {
            /* translators: %d: 数量 */
            $notes['free_dl'] = sprintf(__('提供 %d 次免费下载机会', 'at8-moonlight-shop'), $free_dl);
        }

        $gateways = array();
        if (class_exists('MLSHOP_Payment')) {
            $gateways = MLSHOP_Payment::get_instance()->get_gateways();
        }
        $default_gateway = (string) mlshop_get_option('default_gateway', 'cod');

        return array(
            'post_id'          => $post_id,
            'pay_mode'         => $pay_mode,
            'pay_type'         => $pay_type,
            'mode_label'       => $mode_label,
            'credit_name'      => $credit_name,
            'is_logged_in'     => $is_logged_in,
            'can_purchase'     => $can_purchase,
            'auth_message'     => $auth_message,
            'title'            => $title,
            'desc'             => $desc,
            'money_price'      => $money_price,
            'original_price'   => $original_price,
            'credit_price'     => $credit_price,
            'credit_balance'   => MLSHOP_Credit::get_balance(),
            'gateways'         => $gateways,
            'default_gateway'  => $default_gateway,
            'login_url'        => function_exists('mluc_get_login_url') ? mluc_get_login_url() : wp_login_url(get_permalink($post_id)),
            'sales_text'       => isset($notes['sales']) ? $notes['sales'] : '',
            'expire_text'      => isset($notes['expire']) ? $notes['expire'] : '',
            'aff_text'         => isset($notes['aff']) ? $notes['aff'] : '',
            'coupon_text'      => isset($notes['coupon']) ? $notes['coupon'] : '',
            'free_dl_text'     => isset($notes['free_dl']) ? $notes['free_dl'] : '',
        );
    }

    /* ---------- AJAX 解锁 ---------- */

    public function ajax_unlock()
    {
        check_ajax_referer('mlshop_nonce', 'nonce');

        if (!is_user_logged_in()) {
            mlshop_send_json(false, __('请先登录。', 'at8-moonlight-shop'));
        }
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !self::is_paywalled($post_id)) {
            mlshop_send_json(false, __('该内容无需付费。', 'at8-moonlight-shop'));
        }
        if (self::is_unlocked($post_id)) {
            mlshop_send_json(true, __('您已解锁该内容。', 'at8-moonlight-shop'), array('reload' => true));
        }
        if (!self::user_can_purchase($post_id)) {
            mlshop_send_json(false, __('您当前的会员等级无权购买此内容。', 'at8-moonlight-shop'));
        }

        $user_id  = get_current_user_id();
        $pay_type = MLSHOP_Product_Pay_Meta::get($post_id, 'pay_type', 'money');

        if ('credit' === $pay_type) {
            $price = self::get_credit_price_for_user($post_id, $user_id);
            if ($price <= 0) {
                mlshop_send_json(false, __('积分价格未设置。', 'at8-moonlight-shop'));
            }
            if (!MLSHOP_Credit::can_spend($user_id, $price)) {
                mlshop_send_json(false, sprintf(
                    /* translators: 1: 所需积分, 2: 积分名称, 3: 当前余额 */
                    __('积分不足，需要 %1$s %2$s，当前余额 %3$s。', 'at8-moonlight-shop'),
                    $price, mlshop_get_option('credit_name', __('积分', 'at8-moonlight-shop')), MLSHOP_Credit::get_balance($user_id)
                ));
            }
            // 审计 H1：spend 的返回值必须检查——can_spend 预检与原子扣减之间是并发窗口，
            // 预检通过而实扣失败（被并发请求抢先扣走）时绝不能发货。
            // 反向双击场景由 is_unlocked 幂等重载兜底（两次实扣、一次交付有损可接受面），
            // 但「零扣分交付」绝不允许。
            /* translators: %d: 数量 */
            $remaining = MLSHOP_Credit::spend($user_id, $price, sprintf(__('解锁内容 #%d', 'at8-moonlight-shop'), $post_id));
            if (false === $remaining) {
                mlshop_send_json(false, sprintf(
                    /* translators: 1: 所需积分, 2: 积分名称, 3: 当前余额 */
                    __('积分不足，需要 %1$s %2$s，当前余额 %3$s。', 'at8-moonlight-shop'),
                    $price, mlshop_get_option('credit_name', __('积分', 'at8-moonlight-shop')), MLSHOP_Credit::get_balance($user_id)
                ));
            }
            self::grant_unlock($post_id, $user_id, self::get_unlock_expire($post_id));
            mlshop_send_json(true, __('已使用积分解锁，正在刷新…', 'at8-moonlight-shop'), array('reload' => true));
        }

        // 金钱支付：先校验网关有效性，再创建付费内容专用订单。
        // 顺序很关键（2026-08-30 修复）：原实现先 create_for_paywall() 再 get_gateway()，
        // 无效/空网关时会「先建 pending 订单再失败」→ 留下永不清理的孤儿订单
        // （站点 order_expire_minutes=0 时尤其明显，订单列表持续堆积垃圾数据）。
        // 现在把网关校验全部前置，校验不通过绝不落库。
        $gateway_id = isset($_POST['gateway']) ? sanitize_key($_POST['gateway']) : '';
        if (!$gateway_id) {
            mlshop_send_json(false, __('请选择支付方式。', 'at8-moonlight-shop'));
        }
        if (!class_exists('MLSHOP_Payment')) {
            mlshop_send_json(false, __('支付模块不可用。', 'at8-moonlight-shop'));
        }
        $gateway = MLSHOP_Payment::get_instance()->get_gateway($gateway_id);
        if (!$gateway) {
            mlshop_send_json(false, __('支付方式无效。', 'at8-moonlight-shop'));
        }
        if (!class_exists('MLSHOP_Order')) {
            mlshop_send_json(false, __('订单模块不可用。', 'at8-moonlight-shop'));
        }
        $order_id = MLSHOP_Order::create_for_paywall($user_id, $post_id, $gateway_id);
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
     * 付费内容订单付款完成后解锁。
     */
    public function grant_paywall_order($order_id)
    {
        $pw = (int) get_post_meta($order_id, '_mlshop_paywall_post', true);
        if (!$pw) {
            return;
        }
        // 幂等（审计 C2）：add_post_meta(unique) 原子抢占授予标记——并发 paid/completed
        // 双触发（回跳 × webhook）时只有一个进程授予，避免销量双计与重复交付钩子。
        if (!add_post_meta($order_id, '_mlshop_paywall_granted', current_time('mysql'), true)) {
            return;
        }
        $user_id = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if ($user_id) {
            self::grant_unlock($pw, $user_id, self::get_unlock_expire($pw));
        } else {
            delete_post_meta($order_id, '_mlshop_paywall_granted'); // 游客订单无授予对象，标记放行后续人工流程
        }
    }
}

