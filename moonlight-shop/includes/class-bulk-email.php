<?php
/**
 * 群发邮件：管理员向客户批量发送自定义邮件。
 *
 * 收件人来源：全部用户 / 有订单的客户 / 买过指定商品的客户 / 指定会员等级 / 手动输入邮箱。
 * 发送采用 AJAX 分批渐进（每批 25 封），避免超时；邮件正文支持占位符
 * {site_name} {site_url} {customer_name} {customer_email}。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Bulk_Email
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
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_mlshop_bulk_email_prepare', array($this, 'ajax_prepare'));
        add_action('wp_ajax_mlshop_bulk_email_step', array($this, 'ajax_step'));
        add_action('wp_ajax_mlshop_bulk_email_test', array($this, 'ajax_test'));
    }

    public function add_menu()
    {
        add_submenu_page(
            'edit.php?post_type=mlshop_product',
            __('群发邮件', 'moonlight-shop'),
            __('群发邮件', 'moonlight-shop'),
            'manage_options',
            'mlshop-bulk-email',
            array($this, 'render_page')
        );
    }

    /**
     * 收件人来源类型。
     */
    private function recipient_types()
    {
        return array(
            'all'        => __('全部注册用户', 'moonlight-shop'),
            'customers'  => __('有订单的客户', 'moonlight-shop'),
            'product'    => __('买过指定商品的客户', 'moonlight-shop'),
            'level'      => __('指定会员等级', 'moonlight-shop'),
            'manual'     => __('手动输入邮箱', 'moonlight-shop'),
        );
    }

    /**
     * 构建收件人列表：[{email, user_id}]。
     */
    private function build_recipients($type, $extra)
    {
        $list = array();

        if ('manual' === $type) {
            $raw = isset($extra['emails']) ? $extra['emails'] : '';
            $lines = preg_split('/\r\n|\r|\n|,|;/', $raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!is_email($line)) {
                    continue;
                }
                $u = get_user_by('email', $line);
                $list[] = array('email' => $line, 'user_id' => $u ? $u->ID : 0);
            }
            return $list;
        }

        if ('all' === $type) {
            $users = get_users(array('fields' => array('ID', 'user_email', 'display_name')));
            foreach ($users as $u) {
                if (is_email($u->user_email)) {
                    $list[] = array('email' => $u->user_email, 'user_id' => $u->ID);
                }
            }
            return $list;
        }

        if ('customers' === $type || 'product' === $type) {
            $orders = get_posts(array(
                'post_type'      => 'mlshop_order',
                'post_status'    => 'any',
                'posts_per_page' => 1000,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
            ));
            $user_ids = array();
            $target_product = isset($extra['product_id']) ? (int) $extra['product_id'] : 0;
            foreach ($orders as $oid) {
                $uid = (int) get_post_meta($oid, '_mlshop_user_id', true);
                if (!$uid) {
                    continue;
                }
                if ('product' === $type) {
                    if (!$target_product) {
                        continue;
                    }
                    $items = (array) get_post_meta($oid, '_mlshop_items', true);
                    $hit = false;
                    foreach ($items as $it) {
                        if (isset($it['id']) && (int) $it['id'] === $target_product) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        continue;
                    }
                }
                $user_ids[$uid] = $uid;
            }
            if ($user_ids) {
                $users = get_users(array('include' => array_values($user_ids), 'fields' => array('ID', 'user_email', 'display_name')));
                foreach ($users as $u) {
                    if (is_email($u->user_email)) {
                        $list[] = array('email' => $u->user_email, 'user_id' => $u->ID);
                    }
                }
            }
            return $list;
        }

        if ('level' === $type) {
            $level = isset($extra['level']) ? sanitize_key($extra['level']) : '';
            // 仅在「漫步白月光用户中心」插件激活且 MLUC_Membership 类已加载时按会员等级筛选。
            // 之前误用 function_exists('MLUC_Membership')，但那是 class 不是函数，永远查不到 → 提示「未检测到用户中心插件」。
            if ($level && class_exists('MLUC_Membership')) {
                $users = get_users(array(
                    'meta_key'   => 'mluc_membership_level',
                    'meta_value' => $level,
                    'fields'     => array('ID', 'user_email', 'display_name'),
                ));
                foreach ($users as $u) {
                    if (is_email($u->user_email)) {
                        $list[] = array('email' => $u->user_email, 'user_id' => $u->ID);
                    }
                }
            }
            return $list;
        }

        return $list;
    }

    /**
     * 渲染撰写页面。
     */
    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $products = get_posts(array('post_type' => 'mlshop_product', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        $levels = array();
        // 同 fix bug：MLUC_Membership 是 class 不是 function
        if (class_exists('MLUC_Membership')) {
            $levels = MLUC_Membership::get_levels();
        }
        $from_name  = mlshop_get_option('from_name', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $from_email = mlshop_get_option('from_email', mlshop_get_option('store_email', get_option('admin_email')));
        ?>
        <div class="wrap mlshop-bulk-layout mlshop-bulk-email">
            <h1><?php esc_html_e('群发邮件', 'moonlight-shop'); ?></h1>

            <div class="mlshop-card">
                <h2 class="mlshop-card-title"><?php esc_html_e('收件人', 'moonlight-shop'); ?></h2>
                <fieldset class="mlshop-recipient-types">
                    <?php foreach ($this->recipient_types() as $k => $label) : ?>
                        <label style="display:block;margin:6px 0;">
                            <input type="radio" name="mlshop_recipient_type" value="<?php echo esc_attr($k); ?>" <?php checked($k, 'customers'); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="mlshop-recipient-pane" data-pane="product">
                    <label class="mlshop-label" for="mlshop_bulk_product"><?php esc_html_e('选择商品', 'moonlight-shop'); ?></label>
                    <select id="mlshop_bulk_product" name="mlshop_bulk_product" class="regular-text">
                        <option value=""><?php esc_html_e('— 请选择 —', 'moonlight-shop'); ?></option>
                        <?php foreach ($products as $p) : ?>
                            <option value="<?php echo (int) $p->ID; ?>"><?php echo esc_html($p->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mlshop-recipient-pane" data-pane="level" <?php echo $levels ? '' : 'hidden'; ?>>
                    <label class="mlshop-label" for="mlshop_bulk_level"><?php esc_html_e('选择会员等级', 'moonlight-shop'); ?></label>
                    <select id="mlshop_bulk_level" name="mlshop_bulk_level" class="regular-text">
                        <option value=""><?php esc_html_e('— 请选择 —', 'moonlight-shop'); ?></option>
                        <?php foreach ($levels as $key => $lv) : ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html(isset($lv['label']) ? $lv['label'] : $key); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$levels) : ?>
                        <p class="description"><?php esc_html_e('（未检测到用户中心插件，无法按会员等级筛选）', 'moonlight-shop'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="mlshop-recipient-pane" data-pane="manual">
                    <label class="mlshop-label" for="mlshop_bulk_emails"><?php esc_html_e('邮箱列表', 'moonlight-shop'); ?></label>
                    <textarea id="mlshop_bulk_emails" name="mlshop_bulk_emails" rows="4" class="large-text code" placeholder="user1@example.com, user2@example.com&#10;每行或逗号分隔一个"></textarea>
                </div>

                <p class="description"><span class="mlshop-count-hint" id="mlshop_recipient_count"></span></p>
            </div>

            <div class="mlshop-card">
                <h2 class="mlshop-card-title"><?php esc_html_e('邮件内容', 'moonlight-shop'); ?></h2>

                <div class="mlshop-field">
                    <label class="mlshop-label" for="mlshop_bulk_subject"><?php esc_html_e('邮件主题', 'moonlight-shop'); ?></label>
                    /* translators: %s: 值 */
                    <input type="text" id="mlshop_bulk_subject" class="large-text" value="<?php echo esc_attr(sprintf(__('来自 %s 的最新消息', 'moonlight-shop'), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES))); ?>">
                </div>

                <div class="mlshop-field">
                    <label class="mlshop-label" for="mlshop_bulk_body"><?php esc_html_e('邮件正文', 'moonlight-shop'); ?></label>
                    <textarea id="mlshop_bulk_body" class="mlshop-bulk-body"><?php echo esc_textarea(__("亲爱的 {customer_name}：\n\n感谢您一直以来的支持！\n\n{site_name}", 'moonlight-shop')); ?></textarea>
                    <p class="mlshop-vars">
                        <?php esc_html_e('支持占位符：', 'moonlight-shop'); ?>
                        <code data-var="{site_name}">{site_name}</code>
                        <code data-var="{site_url}">{site_url}</code>
                        <code data-var="{customer_name}">{customer_name}</code>
                        <code data-var="{customer_email}">{customer_email}</code>
                        <?php esc_html_e('（点击插入）', 'moonlight-shop'); ?>
                    </p>
                </div>

                <div class="mlshop-field">
                    <label class="mlshop-label" for="mlshop_bulk_from_name"><?php esc_html_e('发件人名称', 'moonlight-shop'); ?></label>
                    <input type="text" id="mlshop_bulk_from_name" class="regular-text" value="<?php echo esc_attr($from_name); ?>">
                </div>

                <div class="mlshop-field">
                    <label class="mlshop-label" for="mlshop_bulk_from_email"><?php esc_html_e('发件人邮箱', 'moonlight-shop'); ?></label>
                    <input type="email" id="mlshop_bulk_from_email" class="regular-text" value="<?php echo esc_attr($from_email); ?>">
                </div>

                <div class="mlshop-actions">
                    <button type="button" class="button" id="mlshop_bulk_test"><?php esc_html_e('发送测试到管理员', 'moonlight-shop'); ?></button>
                    <span class="mlshop-test-note"><?php esc_html_e('先发一封到您自己的后台邮箱预览效果。', 'moonlight-shop'); ?></span>
                </div>
            </div>

            <div class="mlshop-card">
                <h2 class="mlshop-card-title"><?php esc_html_e('发送', 'moonlight-shop'); ?></h2>
                <div class="mlshop-progress" id="mlshop_bulk_progress" hidden>
                    <div class="mlshop-progress-bar" id="mlshop_bulk_progress_bar"></div>
                </div>
                <div class="mlshop-progress-text" id="mlshop_bulk_progress_text"></div>
                <div class="mlshop-actions">
                    <button type="button" class="button button-primary" id="mlshop_bulk_send"><?php esc_html_e('开始群发', 'moonlight-shop'); ?></button>
                </div>
                <div class="mlshop-result" id="mlshop_bulk_result"></div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX：构建收件人并存入 transient，返回总数。
     */
    public function ajax_prepare()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer('mlshop_bulk_email', 'nonce', false)) {
            wp_send_json_error('permission');
        }
        $type  = isset($_POST['recipient_type']) ? sanitize_key($_POST['recipient_type']) : 'customers';
        $extra = array(
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'level'      => isset($_POST['level']) ? sanitize_key($_POST['level']) : '',
            'emails'     => isset($_POST['emails']) ? wp_unslash($_POST['emails']) : '',
        );
        $recipients = $this->build_recipients($type, $extra);
        // 去重（按邮箱）
        $seen = array();
        $unique = array();
        foreach ($recipients as $r) {
            $key = strtolower($r['email']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            $unique[] = $r;
        }
        $data = array(
            'subject'    => isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '',
            'body'       => isset($_POST['body']) ? wp_unslash($_POST['body']) : '',
            'from_name'  => isset($_POST['from_name']) ? sanitize_text_field(wp_unslash($_POST['from_name'])) : '',
            'from_email' => isset($_POST['from_email']) ? sanitize_email(wp_unslash($_POST['from_email'])) : '',
            'recipients' => $unique,
            'sent'       => 0,
            'failed'     => 0,
        );
        set_transient('mlshop_bulk_' . get_current_user_id(), $data, 600);
        wp_send_json_success(array('total' => count($unique)));
    }

    /**
     * AJAX：发送下一批（25 封），返回进度。
     */
    public function ajax_step()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer('mlshop_bulk_email', 'nonce', false)) {
            wp_send_json_error('permission');
        }
        $data = get_transient('mlshop_bulk_' . get_current_user_id());
        if (!$data || empty($data['recipients'])) {
            wp_send_json_error('no_queue');
        }
        $all = $data['recipients'];
        $done = $data['sent'] + $data['failed'];
        $chunk = array_slice($all, $done, 25);
        foreach ($chunk as $r) {
            $user = $r['user_id'] ? get_userdata($r['user_id']) : null;
            $subj = mlshop_expand_email_vars($data['subject'], $user);
            $body = mlshop_expand_email_vars($data['body'], $user);
            $body = $this->wrap_body($body);
            $ok = mlshop_send_html_mail($r['email'], $subj, $body, $data['from_name'], $data['from_email']);
            if ($ok) {
                $data['sent']++;
            } else {
                $data['failed']++;
            }
        }
        set_transient('mlshop_bulk_' . get_current_user_id(), $data, 600);
        $new_done = $data['sent'] + $data['failed'];
        $finished = $new_done >= count($all);
        if ($finished) {
            delete_transient('mlshop_bulk_' . get_current_user_id());
        }
        wp_send_json_success(array(
            'done'    => $new_done,
            'total'   => count($all),
            'sent'    => $data['sent'],
            'failed'  => $data['failed'],
            'finished' => $finished,
        ));
    }

    /**
     * AJAX：发送测试邮件到管理员。
     */
    public function ajax_test()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer('mlshop_bulk_email', 'nonce', false)) {
            wp_send_json_error('permission');
        }
        $admin = get_userdata(get_current_user_id());
        $subj = isset($_POST['subject']) ? wp_unslash($_POST['subject']) : '';
        $body = isset($_POST['body']) ? wp_unslash($_POST['body']) : '';
        $subj = mlshop_expand_email_vars($subj, $admin);
        $body = mlshop_expand_email_vars($body, $admin);
        $body = $this->wrap_body($body);
        $from_name = isset($_POST['from_name']) ? sanitize_text_field(wp_unslash($_POST['from_name'])) : '';
        $from_email = isset($_POST['from_email']) ? sanitize_email(wp_unslash($_POST['from_email'])) : '';
        $ok = mlshop_send_html_mail($admin->user_email, $subj, $body, $from_name, $from_email);
        if ($ok) {
            /* translators: %s: 值 */
            wp_send_json_success(array('msg' => sprintf(__('测试邮件已发送到 %s。', 'moonlight-shop'), $admin->user_email)));
        }
        wp_send_json_error(__('测试邮件发送失败，请检查服务器邮件配置。', 'moonlight-shop'));
    }

    /**
     * 给群发正文套一层简单卡片外壳。
     */
    private function wrap_body($content)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        return '<div style="max-width:600px;margin:0 auto;padding:24px;background:#fff;border:1px solid #e4e9f0;border-radius:8px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;color:#2d2d2d;line-height:1.7;">'
            . '<h1 style="font-size:20px;margin:0 0 16px;color:#1f2937;">' . esc_html($site) . '</h1>'
            . wpautop($content)
            . '</div>';
    }
}
