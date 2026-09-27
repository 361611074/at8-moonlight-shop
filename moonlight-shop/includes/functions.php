<?php
/**
 * 商城通用辅助函数。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

function mlshop_get_option($key, $default = '')
{
    // 后台「商城設定」用 register_setting 把每个字段存成独立 option（mlshop_$key），
    // 而激活器把页面 ID 等存进 mlshop_options 数组。这里优先读独立 option，
    // 读不到再回退到数组，保证两条路径都能命中。
    $val = get_option('mlshop_' . $key, null);
    if (null !== $val) {
        return $val;
    }
    $options = get_option('mlshop_options', array());
    return isset($options[$key]) ? $options[$key] : $default;
}

function mlshop_get_page_url($key)
{
    $page_id = (int) mlshop_get_option($key . '_page_id', 0);
    if ($page_id) {
        return get_permalink($page_id);
    }
    return home_url('/' . $key . '/');
}

/**
 * 商城入口 URL：自动识别站点上的真实「商店/商城/教材」页，避免写死。
 *
 * 解析优先级：
 *  1) mlshop_options['store_page_id']（显式配置，管理员可在 wp_options 覆盖）
 *  2) 已发布 page，按 slug 优先级匹配：shop > store > mall > bookshop > products
 *     （slug 命中即返回其 permalink；同一 slug 多页则取 ID 最小者）
 *  3) mlshop_product CPT 自定义 archive（has_archive='shop' → /shop/）
 *  4) home_url('/shop/') 兜底
 *
 * 用于购物车/收藏空态的「去挑选商品」按钮。
 */
function mlshop_get_store_url()
{
    // 1) 显式配置
    $store_id = (int) mlshop_get_option('store_page_id', 0);
    if ($store_id && get_post_status($store_id) === 'publish') {
        $url = get_permalink($store_id);
        if ($url) {
            return $url;
        }
    }

    // 2) 按 slug 优先级匹配已发布 page
    $candidates = array('shop', 'store', 'mall', 'bookshop', 'products');
    $args = array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'post_name__in'  => $candidates,
    );
    $matched = get_posts($args);
    if (!empty($matched)) {
        $url = get_permalink($matched[0]);
        if ($url) {
            return $url;
        }
    }

    // 3) CPT 自定义 archive
    if (post_type_exists('mlshop_product')) {
        $url = get_post_type_archive_link('mlshop_product');
        if ($url) {
            return $url;
        }
    }

    // 4) 兜底
    return home_url('/shop/');
}

function mlshop_format_price($price)
{
    $symbol = mlshop_get_option('currency_symbol', 'HK$');
    return $symbol . number_format((float) $price, 2, '.', '');
}

function mlshop_get_template($slug, $args = array())
{
    if (is_array($args)) {
        extract($args);
    }
    $theme_file  = get_stylesheet_directory() . '/mlshop/' . $slug . '.php';
    $plugin_file = MLSHOP_PLUGIN_DIR . 'templates/' . $slug . '.php';
    $file        = file_exists($theme_file) ? $theme_file : $plugin_file;
    if (file_exists($file)) {
        include $file;
    }
}

function mlshop_ajax_data()
{
    return array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('mlshop_nonce'),
    );
}

function mlshop_send_json($success, $message, $data = array())
{
    wp_send_json(array(
        'success' => (bool) $success,
        'message' => $message,
        'data'    => $data,
    ));
}

/**
 * 获取某商品的已购数量（用于限定库存/限购）。
 */
function mlshop_get_product_meta($product_id, $key, $default = '')
{
    $val = get_post_meta($product_id, '_mlshop_' . $key, true);
    return $val === '' ? $default : $val;
}

/**
 * 解析相册 meta 为附件 ID 数组。
 *
 * 相册 meta 有两种存储形态：后台 Meta Box 存逗号分隔字符串，
 * 程序化写入（导入脚本、其他插件）可能存 ID 数组。两者都要能读。
 *
 * @param mixed $raw meta 原始值
 * @return int[] 去重后的正整数 ID 数组（不校验附件是否存在）
 */
function mlshop_parse_gallery_ids($raw)
{
    if (is_array($raw)) {
        $parts = $raw;
    } elseif (is_scalar($raw) && (string) $raw !== '') {
        $parts = explode(',', (string) $raw);
    } else {
        return array();
    }

    $ids = array();
    foreach ($parts as $part) {
        $id = (int) $part;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * 相册 meta 转回逗号分隔字符串（供后台隐藏字段回填）。
 *
 * @param mixed $raw meta 原始值
 * @return string
 */
function mlshop_gallery_to_csv($raw)
{
    return implode(',', mlshop_parse_gallery_ids($raw));
}

/**
 * 获取商品相册图片 ID 列表（含主图）。
 *
 * 列表顺序：特色图片（若有）排在最前，其后为「商品相册」Meta Box 中选定的图片。
 * 仅返回真实存在的图片附件，过滤掉已删除的 ID。
 *
 * @return int[] 附件 ID 数组
 */
function mlshop_get_product_gallery($product_id)
{
    $gallery = array();
    if (has_post_thumbnail($product_id)) {
        $gallery[] = (int) get_post_thumbnail_id($product_id);
    }

    foreach (mlshop_parse_gallery_ids(get_post_meta($product_id, '_mlshop_gallery', true)) as $id) {
        if (in_array($id, $gallery, true)) {
            continue;
        }
        if (wp_attachment_is_image($id)) {
            $gallery[] = $id;
        }
    }

    return $gallery;
}

/**
 * 浮点清洗（用于汇率等数值配置项）。
 */
function mlshop_sanitize_float($value)
{
    return (float) preg_replace('/[^0-9.]/', '', (string) $value);
}

/**
 * 积分充值套餐。
 *
 * 后台「充值套餐」每行一条，格式：积分|金额（例：100|10）。
 * 留空或解析失败时回退到内置默认套餐。
 *
 * @return array [{credit:float, price:float}, ...]
 */
function mlshop_get_recharge_packages()
{
    $raw = (string) mlshop_get_option('recharge_packages', '');
    $packages = array();
    if ('' !== $raw) {
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ('' === $line || strpos($line, '#') === 0) {
                continue;
            }
            $parts = preg_split('/[|:＄$]/u', $line);
            if (count($parts) < 2) {
                continue;
            }
            $credit = (float) trim($parts[0]);
            $price  = (float) trim($parts[1]);
            if ($credit > 0 && $price > 0) {
                $packages[] = array('credit' => $credit, 'price' => $price);
            }
        }
    }
    if (empty($packages)) {
        $packages = array(
            array('credit' => 100,  'price' => 10),
            array('credit' => 500,  'price' => 45),
            array('credit' => 1000, 'price' => 80),
            array('credit' => 3000, 'price' => 200),
        );
    }
    return apply_filters('mlshop_recharge_packages', $packages);
}

/**
 * 自定义充值的积分汇率（多少积分 / 1 个货币单位）。
 */
function mlshop_get_credit_rate()
{
    $rate = (float) mlshop_get_option('credit_rate', 10);
    return $rate > 0 ? $rate : 10;
}

/**
 * 订单状态枚举（slug => 显示名）。
 */
function mlshop_get_order_statuses()
{
    return apply_filters('mlshop_order_statuses', array(
        'pending'    => __('待付款', 'moonlight-shop'),
        'paid'       => __('已付款', 'moonlight-shop'),
        'processing' => __('处理中', 'moonlight-shop'),
        'completed'  => __('已完成', 'moonlight-shop'),
        'failed'     => __('支付失败', 'moonlight-shop'),
        'refunded'   => __('已退款', 'moonlight-shop'),
        'cancelled'  => __('已取消', 'moonlight-shop'),
    ));
}

/**
 * 订单状态显示名。
 */
function mlshop_get_order_status_label($status)
{
    $statuses = mlshop_get_order_statuses();
    return isset($statuses[$status]) ? $statuses[$status] : $status;
}

/**
 * 订单列表页 URL（用户中心存在则指向其订单 Tab，否则回退结算页）。
 */
function mlshop_get_orders_url()
{
    if (function_exists('mluc_get_account_url')) {
        return add_query_arg('tab', 'orders', mluc_get_account_url());
    }
    return mlshop_get_page_url('checkout');
}

/**
 * 支付网关显示名（按 ID 取 title，取不到则回退原 ID）。
 */
function mlshop_get_gateway_title($gateway_id)
{
    if (class_exists('MLSHOP_Payment')) {
        $g = MLSHOP_Payment::get_instance()->get_gateway($gateway_id);
        if ($g) {
            return $g->get_title();
        }
    }
    return $gateway_id;
}

/**
 * 统一 HTML 邮件发送助手：可临时覆盖发件人名称 / 邮箱。
 *
 * 通过 wp_mail_from / wp_mail_from_name 过滤器注入（优先级 20，高于默认），
 * 发送后精确移除本次添加的匿名回调，避免污染后续邮件。
 *
 * @param string|string[] $to        收件人（单个或数组）
 * @param string          $subject   主题
 * @param string          $body      HTML 正文
 * @param string          $from_name 发件人名称（空则用默认）
 * @param string          $from_email 发件人邮箱（空或非法则用默认）
 * @return bool
 */
function mlshop_send_html_mail($to, $subject, $body, $from_name = '', $from_email = '')
{
    $cb_from = null;
    $cb_name = null;
    if ($from_email && is_email($from_email)) {
        $cb_from = function ($v) use ($from_email) {
            return $from_email;
        };
        add_filter('wp_mail_from', $cb_from, 20);
    }
    if ($from_name) {
        $cb_name = function ($v) use ($from_name) {
            return $from_name;
        };
        add_filter('wp_mail_from_name', $cb_name, 20);
    }
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $ok      = wp_mail($to, $subject, $body, $headers);
    if ($cb_from) {
        remove_filter('wp_mail_from', $cb_from, 20);
    }
    if ($cb_name) {
        remove_filter('wp_mail_from_name', $cb_name, 20);
    }
    return (bool) $ok;
}

/**
 * 邮件占位符替换。支持：{site_name} {site_url} {customer_name} {customer_email}。
 *
 * 占位符值一律按 HTML 上下文转义后填入（站点名 / 用户昵称 / 邮箱均为可含 HTML 的输入），
 * 避免群发邮件正文被注入标签（用户昵称是用户可控字段）。
 *
 * @param string    $text
 * @param \WP_User|null $user
 * @return string
 */
function mlshop_expand_email_vars($text, $user = null)
{
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $map  = array(
        '{site_name}'      => esc_html($site),
        '{site_url}'       => esc_url(home_url()),
        '{customer_name}'  => $user ? esc_html($user->display_name) : '',
        '{customer_email}' => $user ? esc_html($user->user_email) : '',
    );
    return str_replace(array_keys($map), array_values($map), $text);
}

/**
 * 原子扣减数值型 post meta（库存等）。
 *
 * 直接 UPDATE ... WHERE 当前值 >= 扣减量，由数据库保证原子性，
 * 避免「读取 → 判断 → 写回」在并发请求下超卖 / 扣成负数。
 *
 * @param int    $post_id
 * @param string $meta_key
 * @param float  $amount 需 > 0
 * @return bool 扣减成功（受影响行数 > 0）
 */
function mlshop_atomic_decrement_post_meta($post_id, $meta_key, $amount)
{
    global $wpdb;
    $post_id = (int) $post_id;
    $amount  = (float) $amount;
    if ($post_id <= 0 || $amount <= 0) {
        return false;
    }
    $affected = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS DECIMAL(20,4)) - %f
             WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS DECIMAL(20,4)) >= %f",
            $amount,
            $post_id,
            $meta_key,
            $amount
        )
    );
    if ($affected) {
        wp_cache_delete($post_id, 'post_meta');
    }
    return (bool) $affected;
}

/**
 * 原子增加数值型 post meta（库存回滚 / 退款回补等）。
 * 与 mlshop_atomic_decrement_post_meta 对称：库存回滚也必须原子，
 * 避免「读取 → 相加 → 写回」在并发退款/取消时丢失回补量。
 * postmeta 无 (post_id, meta_key) 唯一索引，先 UPDATE，无行再 INSERT。
 *
 * @param int    $post_id
 * @param string $meta_key
 * @param float  $amount 需 > 0
 * @return bool
 */
function mlshop_atomic_increment_post_meta($post_id, $meta_key, $amount)
{
    global $wpdb;
    $post_id = (int) $post_id;
    $amount  = (float) $amount;
    if ($post_id <= 0 || $amount <= 0) {
        return false;
    }
    $affected = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS DECIMAL(20,4)) + %f
             WHERE post_id = %d AND meta_key = %s",
            $amount,
            $post_id,
            $meta_key
        )
    );
    if (!$affected) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
                $post_id,
                $meta_key
            )
        );
        if (!$exists) {
            $wpdb->insert(
                $wpdb->postmeta,
                array('post_id' => $post_id, 'meta_key' => $meta_key, 'meta_value' => (string) $amount),
                array('%d', '%s', '%s')
            );
        }
    }
    wp_cache_delete($post_id, 'post_meta');
    return true;
}

/**
 * 原子扣减数值型 user meta（余额 / 积分等）。
 *
 * @param int    $user_id
 * @param string $meta_key
 * @param float  $amount 需 > 0
 * @return bool 扣减成功（余额充足且已扣减）；余额不足或无此 meta 返回 false
 */
function mlshop_atomic_decrement_user_meta($user_id, $meta_key, $amount)
{
    global $wpdb;
    $user_id = (int) $user_id;
    $amount  = (float) $amount;
    if ($user_id <= 0 || $amount <= 0) {
        return false;
    }
    $affected = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS DECIMAL(20,4)) - %f
             WHERE user_id = %d AND meta_key = %s AND CAST(meta_value AS DECIMAL(20,4)) >= %f",
            $amount,
            $user_id,
            $meta_key,
            $amount
        )
    );
    if ($affected) {
        wp_cache_delete($user_id, 'user_meta');
    }
    return (bool) $affected;
}

/**
 * 原子增加数值型 user meta（充值 / 退款回补）。
 *
 * usermeta 表没有 (user_id, meta_key) 唯一索引，无法用 ON DUPLICATE KEY，
 * 因此先尝试原子 UPDATE，无对应行时再 INSERT（并再试一次 UPDATE 兜底并发插入）。
 *
 * @param int    $user_id
 * @param string $meta_key
 * @param float  $amount 需 > 0
 * @return bool
 */
function mlshop_atomic_increment_user_meta($user_id, $meta_key, $amount)
{
    global $wpdb;
    $user_id = (int) $user_id;
    $amount  = (float) $amount;
    if ($user_id <= 0 || $amount <= 0) {
        return false;
    }
    $affected = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS DECIMAL(20,4)) + %f
             WHERE user_id = %d AND meta_key = %s",
            $amount,
            $user_id,
            $meta_key
        )
    );
    if (!$affected) {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
                $user_id,
                $meta_key
            )
        );
        if (!$exists) {
            $wpdb->insert(
                $wpdb->usermeta,
                array('user_id' => $user_id, 'meta_key' => $meta_key, 'meta_value' => (string) $amount),
                array('%d', '%s', '%s')
            );
            // 并发下另一请求可能已插入，此时改为再次原子累加
            if (!$wpdb->rows_affected) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS DECIMAL(20,4)) + %f
                         WHERE user_id = %d AND meta_key = %s",
                        $amount,
                        $user_id,
                        $meta_key
                    )
                );
            }
        }
    }
    wp_cache_delete($user_id, 'user_meta');
    return true;
}

/**
 * CAS（compare-and-swap）更新 post meta：仅当当前值仍等于 $expected 时才写入。
 *
 * 用于卡密池等「读旧值 → 计算新值 → 写回」场景，避免并发下把同一张卡密发给两个人。
 *
 * @param int    $post_id
 * @param string $meta_key
 * @param string $expected 期望的当前值
 * @param string $new_value
 * @return bool 是否写入成功（false = 期间被其他请求改过）
 */
function mlshop_cas_post_meta($post_id, $meta_key, $expected, $new_value)
{
    global $wpdb;
    $affected = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s
             WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
            (string) $new_value,
            (int) $post_id,
            (string) $meta_key,
            (string) $expected
        )
    );
    if ($affected) {
        wp_cache_delete((int) $post_id, 'post_meta');
    }
    return (bool) $affected;
}

/**
 * 支付密钥脱敏展示（修复审计 M4）：设置页只显示尾 4 位，完整值永不回显进 HTML。
 *
 * @param string $value
 * @return string 形如 "••••AB12"；空值返回空串
 */
function mlshop_mask_secret($value)
{
    $value = trim((string) $value);
    if ('' === $value) {
        return '';
    }
    return '••••' . substr($value, -4);
}

/**
 * 支付密钥保存策略（修复审计 M4）：设置页输入框不再回显原值，
 * 因此「提交为空 = 保持原值不变」，只有用户输入了新值才覆盖。
 *
 * @param string $key   配置键（不含 mlshop_ 前缀）
 * @param string $value 表单提交值
 * @return string 应落库的值
 */
function mlshop_sanitize_secret_keep($key, $value)
{
    $value = trim((string) $value);
    if ('' === $value) {
        return trim((string) mlshop_get_option($key, ''));
    }
    return sanitize_text_field($value);
}

/**
 * 渲染省 / 市二级下拉（结算页收件资料、账户中心地址簿共用）。
 *
 * 市下拉以 optgroup 按省分组渲染全部城市；JS（mlshop.js）按所选省过滤
 * 非当前省的 optgroup。无 JS 时全部 optgroup 可见（渐进增强）。
 *
 * @param string $name_prefix  input name 前缀（如 shipping → shipping_province / shipping_city）
 * @param string $sel_province 选中的省区码
 * @param string $sel_city     选中的市区码
 */
function mlshop_render_region_selects($name_prefix, $sel_province = '', $sel_city = '')
{
    if (!class_exists('Moonlight_Region_Provider')) {
        return;
    }
    $provinces = Moonlight_Region_Provider::provinces();
    ?>
    <select name="<?php echo esc_attr($name_prefix); ?>_province" class="mlshop-region-province">
        <option value=""><?php esc_html_e('選擇省份 / 直轄市', 'moonlight-shop'); ?></option>
        <?php foreach ($provinces as $pcode => $pname) : ?>
            <option value="<?php echo esc_attr($pcode); ?>" <?php selected((string) $sel_province, (string) $pcode); ?>><?php echo esc_html($pname); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="<?php echo esc_attr($name_prefix); ?>_city" class="mlshop-region-city">
        <option value=""><?php esc_html_e('選擇城市', 'moonlight-shop'); ?></option>
        <?php foreach ($provinces as $pcode => $pname) :
            $cities = Moonlight_Region_Provider::cities($pcode);
            if (empty($cities)) {
                continue;
            }
            ?>
            <optgroup label="<?php echo esc_attr($pname); ?>" data-province="<?php echo esc_attr($pcode); ?>">
                <?php foreach ($cities as $ccode => $cname) : ?>
                    <option value="<?php echo esc_attr($ccode); ?>" <?php selected((string) $sel_city, (string) $ccode); ?>><?php echo esc_html($cname); ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>
    <?php
}
