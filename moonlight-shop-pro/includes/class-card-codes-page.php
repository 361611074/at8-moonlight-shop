<?php
/**
 * 后台「卡密管理」页（对齐子比 zibll商城中心 → 卡密管理 的布局与字段）。
 *
 * 界面结构照搬子比：
 *   提示条 → 卡密类型（4 个 Tab）→ 添加方式（系统自动生成 / 导入卡密）
 *   → 生成数量 → 标识 → 面额 → 高级选项（卡密位数 / 密码位数）→ 确认提交
 *
 * 与既有「商品 → 卡密库存」（发货用）并存，互不影响。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLPRO_Card_Codes_Page
{
    const SLUG = 'mlshop-card-codes';

    public static function boot()
    {
        $i = new self();
        add_action('admin_menu', array($i, 'menu'));
        add_action('admin_post_mlshop_card_codes_submit', array($i, 'handle_submit'));
        add_action('admin_post_mlshop_card_codes_delete', array($i, 'handle_delete'));
        add_action('admin_post_mlshop_card_codes_export', array($i, 'handle_export'));
    }

    public function menu()
    {
        // 挂在商城菜单下（与「卡密库存」同级）
        $parent = 'mlshop';
        if (class_exists('MLUC_Settings') && method_exists('MLUC_Settings', 'submenu_parent_slug')) {
            $parent = MLUC_Settings::submenu_parent_slug();
        }
        global $admin_page_hooks;
        if (!isset($admin_page_hooks[$parent]) && 'mlshop' !== $parent) {
            $parent = 'mlshop';
        }
        add_submenu_page(
            $parent,
            __('卡密管理', 'moonlight-shop'),
            __('卡密管理', 'moonlight-shop'),
            'manage_options',
            self::SLUG,
            array($this, 'render')
        );
    }

    private function base_url($args = array())
    {
        $parent = 'edit.php?post_type=mlshop_product';
        return add_query_arg(array_merge(array('post_type' => 'mlshop_product', 'page' => self::SLUG), $args), admin_url($parent));
    }

    /* ---------------- 提交处理 ---------------- */

    public function handle_submit()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'moonlight-shop'));
        }
        check_admin_referer('mlshop_card_codes_submit');

        $type  = isset($_POST['card_type']) ? sanitize_key(wp_unslash($_POST['card_type'])) : 'balance';
        $batch = isset($_POST['card_batch']) ? sanitize_text_field(wp_unslash($_POST['card_batch'])) : '';
        $face  = isset($_POST['card_face_value']) ? (float) $_POST['card_face_value'] : 0;
        $days  = isset($_POST['card_expires_days']) ? absint($_POST['card_expires_days']) : 0;
        $mode  = isset($_POST['card_add_mode']) ? sanitize_key(wp_unslash($_POST['card_add_mode'])) : 'generate';

        if (false !== strpos($batch, 'shipped')) {
            wp_die(esc_html__('标识中不能包含 shipped 字符（系统已占用）。', 'moonlight-shop'), 400);
        }

        if ('import' === $mode) {
            $raw = isset($_POST['card_import_text']) ? (string) wp_unslash($_POST['card_import_text']) : '';
            $lines = array();
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
                $l = trim((string) $l);
                if ('' !== $l) {
                    $lines[] = $l;
                }
            }
            if (empty($lines)) {
                wp_die(esc_html__('卡密内容为空，未导入。', 'moonlight-shop'), 400);
            }
            if (count($lines) > 5000) {
                wp_die(esc_html__('单次最多导入 5000 行。', 'moonlight-shop'), 400);
            }
            $res = MLPRO_Card_Codes::import(array(
                'type'       => $type,
                'batch'      => $batch,
                'face_value' => $face,
                'days'       => $days,
                'lines'      => $lines,
            ));
        } else {
            $res = MLPRO_Card_Codes::generate(array(
                'type'       => $type,
                'count'      => isset($_POST['card_count']) ? absint($_POST['card_count']) : 0,
                'batch'      => $batch,
                'face_value' => $face,
                'code_len'   => isset($_POST['card_code_len']) ? absint($_POST['card_code_len']) : 20,
                'pwd_len'    => isset($_POST['card_pwd_len']) ? absint($_POST['card_pwd_len']) : 35,
                'days'       => $days,
                'charset'    => isset($_POST['card_charset']) ? sanitize_text_field(wp_unslash($_POST['card_charset'])) : '',
            ));
        }

        if (is_wp_error($res)) {
            wp_die(esc_html($res->get_error_message()), 400);
        }

        $msg = ('import' === $mode)
            ? sprintf(
                /* translators: 1: 导入数 2: 重复数 3: 标识 */
                __('导入成功：%1$d 条，跳过重复 %2$d 条，标识「%3$s」。', 'moonlight-shop'),
                (int) $res['created'], (int) $res['duplicates'], $res['batch']
            )
            : sprintf(
                /* translators: 1: 生成数 2: 标识 3: 卡号位数 4: 密码位数 */
                __('生成成功：%1$d 张，标识「%2$s」（卡号 %3$d 位 / 密码 %4$d 位）。', 'moonlight-shop'),
                (int) $res['created'], $res['batch'], (int) $res['code_len'], (int) $res['pwd_len']
            );
        wp_safe_redirect(add_query_arg('mlshop_msg', rawurlencode($msg), $this->base_url(array('type' => $type))));
        exit;
    }

    public function handle_delete()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'moonlight-shop'));
        }
        check_admin_referer('mlshop_card_codes_delete');
        $batch = isset($_GET['batch']) ? sanitize_text_field(wp_unslash($_GET['batch'])) : '';
        $type  = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $n     = MLPRO_Card_Codes::delete_batch($batch, $type);
        wp_safe_redirect($this->base_url(array(
            'type'      => $type,
            'mlshop_msg' => rawurlencode(sprintf(__('已删除标识「%s」下 %d 条卡密。', 'moonlight-shop'), $batch, $n)),
        )));
        exit;
    }

    public function handle_export()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'moonlight-shop'));
        }
        check_admin_referer('mlshop_card_codes_export');
        $batch = isset($_GET['batch']) ? sanitize_text_field(wp_unslash($_GET['batch'])) : '';
        $type  = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $rows  = MLPRO_Card_Codes::list_codes(array('batch' => $batch, 'type' => $type, 'limit' => 5000));

        $name = 'cards_' . sanitize_file_name($batch) . '_' . date('YmdHis') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM，Excel 打开不乱码
        fputcsv($out, array('type', 'batch', 'code', 'password', 'face_value', 'status', 'expires_at', 'created_at'));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    /* ---------------- 页面渲染 ---------------- */

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $types = MLPRO_Card_Codes::types();
        $cur_type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'balance';
        if (!isset($types[$cur_type])) {
            $cur_type = 'balance';
        }
        $msg   = isset($_GET['mlshop_msg']) ? sanitize_text_field(wp_unslash($_GET['mlshop_msg'])) : '';
        $stats = MLPRO_Card_Codes::stats();

        // 表单默认值（首次进入给子比同款示例值）
        $batch_default = MLPRO_Card_Codes::make_tag();
        ?>
        <div class="wrap mlshop-admin-settings">
            <h1><?php esc_html_e('卡密管理', 'moonlight-shop'); ?></h1>

            <?php if ($msg) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-warning inline" style="margin:12px 0;">
                <ul style="margin:0;list-style:disc;padding-left:20px;">
                    <li><?php esc_html_e('如果您已经准备好了卡密资料，请选择导入的方式添加。', 'moonlight-shop'); ?></li>
                    <li><?php esc_html_e('您也可以采用系统生成的方式，自动批量添加卡密。', 'moonlight-shop'); ?></li>
                </ul>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mlshop_card_codes_submit'); ?>
                <input type="hidden" name="action" value="mlshop_card_codes_submit">

                <!-- 卡密类型 -->
                <h2 class="mlshop-card-title"><?php esc_html_e('卡密类型', 'moonlight-shop'); ?></h2>
                <div class="mlshop-cc-tabs" data-tabs="type">
                    <?php foreach ($types as $key => $t) : ?>
                        <label class="mlshop-cc-tab<?php echo $key === $cur_type ? ' is-active' : ''; ?>">
                            <input type="radio" name="card_type" value="<?php echo esc_attr($key); ?>" <?php checked($cur_type, $key); ?>>
                            <span><?php echo esc_html($t['label']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description"><?php esc_html_e('不同类型决定用户拿卡密后能兑换什么（余额 / 会员等级 / 积分 / 自定义内容）。', 'moonlight-shop'); ?></p>

                <!-- 添加方式 -->
                <h2 class="mlshop-card-title"><?php esc_html_e('添加方式', 'moonlight-shop'); ?></h2>
                <div class="mlshop-cc-tabs" data-tabs="mode">
                    <label class="mlshop-cc-tab is-active">
                        <input type="radio" name="card_add_mode" value="generate" checked>
                        <span><?php esc_html_e('系统自动生成', 'moonlight-shop'); ?></span>
                    </label>
                    <label class="mlshop-cc-tab">
                        <input type="radio" name="card_add_mode" value="import">
                        <span><?php esc_html_e('导入卡密', 'moonlight-shop'); ?></span>
                    </label>
                </div>

                <!-- 系统生成面板 -->
                <div class="mlshop-cc-panel" data-panel="generate">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('生成数量', 'moonlight-shop'); ?></th>
                            <td>
                                <input type="number" name="card_count" value="20" min="1" max="5000" class="small-text" required>
                                <?php esc_html_e('张', 'moonlight-shop'); ?>
                                <p class="description"><?php esc_html_e('需要生成多少张卡密（单次生成数量太多可能会对服务器性能造成影响）。', 'moonlight-shop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('标识', 'moonlight-shop'); ?></th>
                            <td>
                                <input type="text" name="card_batch" value="<?php echo esc_attr($batch_default); ?>" class="regular-text" maxlength="100">
                                <p class="description"><?php esc_html_e('对生成的卡密做标记标识，方便后期查找管理。', 'moonlight-shop'); ?> <br>
                                <?php esc_html_e('注意：标识中不能有 shipped 字符，系统已占用。', 'moonlight-shop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('面额', 'moonlight-shop'); ?></th>
                            <td>
                                <input type="number" name="card_face_value" value="0" min="0" step="0.01" class="small-text">
                                <p class="description"><?php esc_html_e('单张卡密的面额。', 'moonlight-shop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('有效期', 'moonlight-shop'); ?></th>
                            <td>
                                <input type="number" name="card_expires_days" value="0" min="0" class="small-text"> <?php esc_html_e('天（0 = 永久有效）', 'moonlight-shop'); ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('高级选项', 'moonlight-shop'); ?></th>
                            <td>
                                <label style="display:block;margin-bottom:12px;">
                                    <input type="checkbox" id="cc-adv" checked>
                                    <span><?php esc_html_e('启用自定义位数', 'moonlight-shop'); ?></span>
                                </label>
                                <div id="cc-adv-body">
                                    <p style="margin:0 0 6px;">
                                        <label style="display:inline-block;width:160px;"><?php esc_html_e('自定义卡密位数', 'moonlight-shop'); ?></label>
                                        <input type="number" name="card_code_len" value="20" min="0" max="64" class="small-text"> <?php esc_html_e('位数', 'moonlight-shop'); ?>
                                    </p>
                                    <p style="margin:0;">
                                        <label style="display:inline-block;width:160px;"><?php esc_html_e('自定义密码位数', 'moonlight-shop'); ?></label>
                                        <input type="number" name="card_pwd_len" value="35" min="4" max="64" class="small-text"> <?php esc_html_e('位数', 'moonlight-shop'); ?>
                                    </p>
                                    <p class="description"><?php esc_html_e('自定义自动生成长度（不能太短，太短可能会出现重复）。如果启用了单密码模式，可以将卡密位数设置为 0。', 'moonlight-shop'); ?></p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 导入面板 -->
                <div class="mlshop-cc-panel" data-panel="import" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('卡密内容', 'moonlight-shop'); ?></th>
                            <td>
                                <textarea name="card_import_text" rows="10" class="large-text code"
                                    placeholder="<?php esc_attr_e('每行一条，支持「卡号 密码」或「卡号,密码」两种分隔；只有卡号时密码留空。', 'moonlight-shop'); ?>"></textarea>
                                <p class="description"><?php esc_html_e('单次最多导入 5000 行。', 'moonlight-shop'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('标识', 'moonlight-shop'); ?></th>
                            <td><input type="text" name="card_batch_import" value="<?php echo esc_attr($batch_default); ?>" class="regular-text" maxlength="100"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('面额', 'moonlight-shop'); ?></th>
                            <td><input type="number" name="card_face_value_import" value="0" min="0" step="0.01" class="small-text"></td>
                        </tr>
                    </table>
                </div>

                <p style="margin-top:16px;">
                    <button type="submit" class="button button-primary button-hero"><?php esc_html_e('确认提交', 'moonlight-shop'); ?></button>
                </p>
            </form>

            <!-- 统计 -->
            <h2 class="mlshop-card-title" style="margin-top:28px;"><?php esc_html_e('卡密统计', 'moonlight-shop'); ?></h2>
            <table class="widefat striped" style="max-width:760px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('卡密类型', 'moonlight-shop'); ?></th>
                        <th><?php esc_html_e('总数', 'moonlight-shop'); ?></th>
                        <th><?php esc_html_e('未使用', 'moonlight-shop'); ?></th>
                        <th><?php esc_html_e('已使用', 'moonlight-shop'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $key => $t) : ?>
                        <?php $s = MLPRO_Card_Codes::stats($key); ?>
                        <tr<?php echo $key === $cur_type ? ' class="mlshop-cc-active-row"' : ''; ?>>
                            <td><?php echo esc_html($t['label']); ?></td>
                            <td><?php echo (int) $s['total']; ?></td>
                            <td><?php echo (int) $s['unused']; ?></td>
                            <td><?php echo (int) $s['used']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- 批次列表 -->
            <h2 class="mlshop-card-title"><?php esc_html_e('卡密标识（批次）', 'moonlight-shop'); ?></h2>
            <?php $batches = MLPRO_Card_Codes::batches(); ?>
            <?php if (empty($batches)) : ?>
                <p class="description"><?php esc_html_e('还没有卡密批次，用上面的表单生成或导入一批。', 'moonlight-shop'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('标识', 'moonlight-shop'); ?></th>
                            <th><?php esc_html_e('类型', 'moonlight-shop'); ?></th>
                            <th><?php esc_html_e('总数', 'moonlight-shop'); ?></th>
                            <th><?php esc_html_e('未使用', 'moonlight-shop'); ?></th>
                            <th><?php esc_html_e('创建时间', 'moonlight-shop'); ?></th>
                            <th><?php esc_html_e('操作', 'moonlight-shop'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $b) :
                            $tl = isset($types[$b['type']]) ? $types[$b['type']]['label'] : $b['type'];
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($b['batch']); ?></code></td>
                                <td><?php echo esc_html($tl); ?></td>
                                <td><?php echo (int) $b['total']; ?></td>
                                <td><?php echo (int) $b['unused']; ?></td>
                                <td><?php echo esc_html($b['created_at']); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url($this->base_url(array('type' => $b['type'], 'batch' => $b['batch']))); ?>"><?php esc_html_e('查看卡密', 'moonlight-shop'); ?></a>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlshop_card_codes_export&batch=' . rawurlencode($b['batch']) . '&type=' . rawurlencode($b['type'])), 'mlshop_card_codes_export')); ?>"><?php esc_html_e('导出 CSV', 'moonlight-shop'); ?></a>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mlshop_card_codes_delete&batch=' . rawurlencode($b['batch']) . '&type=' . rawurlencode($b['type'])), 'mlshop_card_codes_delete')); ?>"
                                       onclick="return confirm('<?php echo esc_js(__('确认删除该标识下全部卡密？此操作不可恢复。', 'moonlight-shop')); ?>');"><?php esc_html_e('删除', 'moonlight-shop'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- 指定批次时的卡密明细 -->
            <?php
            $view_batch = isset($_GET['batch']) ? sanitize_text_field(wp_unslash($_GET['batch'])) : '';
            if ($view_batch) :
                $rows = MLPRO_Card_Codes::list_codes(array('batch' => $view_batch, 'type' => $cur_type, 'limit' => 200));
                ?>
                <h2 class="mlshop-card-title"><?php echo esc_html(sprintf(__('标识「%s」的卡密', 'moonlight-shop'), $view_batch)); ?></h2>
                <?php if (empty($rows)) : ?>
                    <p class="description"><?php esc_html_e('该标识下没有卡密。', 'moonlight-shop'); ?></p>
                <?php else : ?>
                    <p class="description"><?php echo esc_html(sprintf(__('最多显示最近 200 条，共 %d 条。', 'moonlight-shop'), count($rows))); ?></p>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('卡号', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('密码', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('面额', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('状态', 'moonlight-shop'); ?></th>
                                <th><?php esc_html_e('过期', 'moonlight-shop'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($r['code']); ?></code></td>
                                    <td><code><?php echo '' === $r['password'] ? '—' : esc_html($r['password']); ?></code></td>
                                    <td><?php echo esc_html(number_format((float) $r['face_value'], 2)); ?></td>
                                    <td><?php echo 'unused' === $r['status'] ? esc_html__('未使用', 'moonlight-shop') : esc_html__('已使用', 'moonlight-shop'); ?></td>
                                    <td><?php echo $r['expires_at'] ? esc_html($r['expires_at']) : esc_html__('永久', 'moonlight-shop'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var root = document.querySelector('.mlshop-cc-tabs[data-tabs="mode"]');
            if (!root) { return; }
            var tabs = root.querySelectorAll('.mlshop-cc-tab');
            var panels = document.querySelectorAll('.mlshop-cc-panel');
            function show(mode) {
                for (var i = 0; i < tabs.length; i++) {
                    var on = tabs[i].querySelector('input').value === mode;
                    tabs[i].classList.toggle('is-active', on);
                }
                for (var j = 0; j < panels.length; j++) {
                    panels[j].style.display = (panels[j].getAttribute('data-panel') === mode) ? '' : 'none';
                }
            }
            for (var k = 0; k < tabs.length; k++) {
                tabs[k].addEventListener('click', function () {
                    show(this.querySelector('input').value);
                });
            }
            var checked = root.querySelector('input:checked');
            show(checked ? checked.value : 'generate');

            // 高级选项开关
            var adv = document.getElementById('cc-adv');
            var advBody = document.getElementById('cc-adv-body');
            if (adv && advBody) {
                var sync = function () {
                    advBody.style.display = adv.checked ? '' : 'none';
                };
                adv.addEventListener('change', sync);
                sync();
            }
        })();
        </script>
        <?php
    }
}
