<?php
/**
 * 工作纸 / PDF 教材：CPT mluc_material + 短代码 [mluc_materials] [mluc_downloads]。
 *
 * 后台上传文件，前端按会员等级过滤。下载用一次性 token 限次。
 *
 * 自 moonlight-user-center v2.0.0 并入（moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Material
{
    public const CPT = 'mluc_material';
    public const META_LEVEL = 'mluc_material_min_level';
    public const META_FILE = 'mluc_material_file';
    public const META_DOWNLOAD_LIMIT = 'mluc_material_download_limit';

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
        add_action('init', array($this, 'register_cpt'));
        add_action('add_meta_boxes', array($this, 'register_metabox'));
        add_action('save_post_' . self::CPT, array($this, 'save_metabox'));
        if (!shortcode_exists('mluc_materials')) {
            add_shortcode('mluc_materials', array($this, 'shortcode_materials'));
        }
        if (!shortcode_exists('mluc_downloads')) {
            add_shortcode('mluc_downloads', array($this, 'shortcode_downloads'));
        }
        add_action('admin_post_mluc_download', array($this, 'handle_download'));
        add_action('admin_post_nopriv_mluc_download', array($this, 'handle_download'));
        // 安全：单页直访兜底（短代码卡片会输出 permalink），未达等级一律拦截。
        add_action('template_redirect', array($this, 'guard_single_access'));
        // 安全：归档/列表查询兜底。?post_type=mluc_material 这类查询不受 has_archive
        // 控制，关闭归档后仍可被枚举，故对前台主查询强制追加等级过滤。
        add_action('pre_get_posts', array($this, 'filter_archive_query'));
    }

    /**
     * 当前用户可访问的等级 key 列表。
     */
    public static function accessible_levels()
    {
        $levels = array('free');
        if (is_user_logged_in() && class_exists('MLUC_Membership')) {
            $user_level = MLUC_Membership::get_user_level();
            $all        = MLUC_Membership::get_levels();
            foreach ($all as $key => $lv) {
                if (MLUC_Membership::get_level_sort_order($key) <= MLUC_Membership::get_level_sort_order($user_level)) {
                    $levels[] = $key;
                }
            }
        }
        return $levels;
    }

    /**
     * 前台主查询（归档 / ?post_type= 枚举）按会员等级过滤。
     * 短代码用的是独立 WP_Query，非主查询，不受影响。
     */
    public function filter_archive_query($q)
    {
        if (is_admin() || !$q->is_main_query()) {
            return;
        }
        if (self::CPT !== $q->get('post_type') || $q->is_singular()) {
            return;
        }
        if (current_user_can('edit_others_posts')) {
            return;
        }
        $mq = array('relation' => 'OR');
        foreach (self::accessible_levels() as $lvl) {
            $mq[] = array('key' => self::META_LEVEL, 'value' => $lvl, 'compare' => '=');
        }
        $mq[] = array('key' => self::META_LEVEL, 'value' => '', 'compare' => 'NOT EXISTS');
        $q->set('meta_query', $mq);
    }

    /**
     * 教材单页访问控制：未登录引导登录，已登录但等级不足返回 403。
     * 短代码列表本身已按等级过滤，此处只兜底直接访问 permalink 的情况。
     */
    public function guard_single_access()
    {
        if (!is_singular(self::CPT)) {
            return;
        }
        $post_id = (int) get_queried_object_id();
        if (!$post_id) {
            return;
        }
        // 作者/编辑/管理员始终可见，便于预览
        if (current_user_can('edit_post', $post_id)) {
            return;
        }
        $min_lv = get_post_meta($post_id, self::META_LEVEL, true);
        if ('' === (string) $min_lv) {
            $min_lv = 'free';
        }
        if (class_exists('MLUC_Membership') && !MLUC_Membership::user_can_access($min_lv)) {
            if (!is_user_logged_in()) {
                auth_redirect();
                exit;
            }
            wp_die(esc_html__('權限不足，無法檢視此教材。', 'moonlight-shop'), 403);
        }
    }

    public function register_cpt()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => __('工作紙 / 教材', 'moonlight-shop'),
                'singular_name' => __('教材', 'moonlight-shop'),
                'add_new'       => __('新建教材', 'moonlight-shop'),
                'add_new_item'  => __('新增教材', 'moonlight-shop'),
                'edit_item'     => __('編輯教材', 'moonlight-shop'),
            ),
            // 安全收敛（原 public=true + show_in_rest=true + has_archive=true，
            // 未登录访客可经 /wp-json/wp/v2/mluc_material 与归档页拿到全部高等级教材）：
            //   - 关闭 REST：杜绝 JSON 接口批量拉取；
            //   - 关闭归档：杜绝列表页枚举；
            //   - 排除搜索：杜绝站内搜索泄露标题/摘要；
            //   - 保留单页链接（短代码卡片会用 the_permalink），
            //     由 template_redirect 上的等级拦截兜底（见 guard_single_access）。
            'public'              => true,
            'publicly_queryable'  => true,
            'has_archive'         => false,
            'show_in_rest'        => false,
            'show_ui'             => true,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_menu'        => false,
            'menu_icon'           => 'dashicons-media-document',
            'menu_position'       => 22,
            'supports'            => array('title', 'editor', 'thumbnail', 'excerpt'),
            'capability_type'     => 'post',
        ));
    }

    public function register_metabox()
    {
        add_meta_box(
            'mluc_material_meta',
            __('教材設定', 'moonlight-shop'),
            array($this, 'render_metabox'),
            self::CPT,
            'normal',
            'high'
        );
    }

    public function render_metabox($post)
    {
        wp_nonce_field('mluc_material_meta', 'mluc_material_meta_nonce');
        $level  = get_post_meta($post->ID, self::META_LEVEL, true) ?: 'free';
        $file   = get_post_meta($post->ID, self::META_FILE, true);
        $limit  = (int) get_post_meta($post->ID, self::META_DOWNLOAD_LIMIT, true);
        $levels = class_exists('MLUC_Membership') ? MLUC_Membership::get_levels() : array();
        // 普通会员关闭时 free 不在等级表中，但作为「公开内容」标记仍需出现在下拉里
        if (!isset($levels['free'])) {
            $levels = array('free' => array('label' => __('公開（免費）', 'moonlight-shop'))) + $levels;
        }
        ?>
        <p>
            <label for="mluc_material_min_level"><strong><?php esc_html_e('最低訪問等級', 'moonlight-shop'); ?></strong></label><br>
            <select name="mluc_material_min_level" id="mluc_material_min_level" style="width:100%;max-width:300px;">
                <?php foreach ($levels as $key => $lv) : ?>
                    <option value="<?php echo esc_attr($key); ?>"<?php selected($level, $key); ?>>
                        <?php echo esc_html($lv['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="mluc_material_file"><strong><?php esc_html_e('文件網址（PDF / ZIP）', 'moonlight-shop'); ?></strong></label><br>
            <input type="url" name="mluc_material_file" id="mluc_material_file" value="<?php echo esc_attr($file); ?>" style="width:100%;" placeholder="https://...">
        </p>
        <p>
            <label for="mluc_material_download_limit"><strong><?php esc_html_e('每用戶下載次數上限（0 = 不限）', 'moonlight-shop'); ?></strong></label><br>
            <input type="number" min="0" name="mluc_material_download_limit" id="mluc_material_download_limit" value="<?php echo esc_attr($limit); ?>" style="width:120px;">
        </p>
        <?php
    }

    public function save_metabox($post_id)
    {
        if (!isset($_POST['mluc_material_meta_nonce']) || !wp_verify_nonce($_POST['mluc_material_meta_nonce'], 'mluc_material_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (isset($_POST['mluc_material_min_level'])) {
            update_post_meta($post_id, self::META_LEVEL, sanitize_key($_POST['mluc_material_min_level']));
        }
        if (isset($_POST['mluc_material_file'])) {
            update_post_meta($post_id, self::META_FILE, esc_url_raw($_POST['mluc_material_file']));
        }
        if (isset($_POST['mluc_material_download_limit'])) {
            update_post_meta($post_id, self::META_DOWNLOAD_LIMIT, max(0, (int) $_POST['mluc_material_download_limit']));
        }
    }

    /**
     * 查询当前用户可访问的教材。
     */
    public static function query_accessible_materials($args = array())
    {
        $defaults = array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        $args = wp_parse_args($args, $defaults);

        $levels = self::accessible_levels();
        $level_query = array('relation' => 'OR');
        foreach ($levels as $lvl) {
            $level_query[] = array(
                'key'     => self::META_LEVEL,
                'value'   => $lvl,
                'compare' => '=',
            );
        }
        $level_query[] = array(
            'key'     => self::META_LEVEL,
            'value'   => '',
            'compare' => 'NOT EXISTS',
        );
        $args['meta_query'] = $level_query;
        return new WP_Query($args);
    }

    /**
     * 短代码 [mluc_materials limit="20"] 工作纸列表（公开页面）。
     */
    public function shortcode_materials($atts)
    {
        $atts = shortcode_atts(array('limit' => 20), $atts, 'mluc_materials');
        $query = self::query_accessible_materials(array('posts_per_page' => (int) $atts['limit']));

        ob_start();
        echo '<div class="mluc-materials-list">';
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $min_lv = get_post_meta($post_id, self::META_LEVEL, true) ?: 'free';
                $level_label = class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label($min_lv) : $min_lv;
                $download_url = is_user_logged_in()
                    ? wp_nonce_url(add_query_arg(array('action' => 'mluc_download', 'mid' => $post_id), admin_url('admin-post.php')), 'mluc_download_' . $post_id)
                    : '';
                ?>
                <div class="mluc-material-item">
                    <h3><?php the_title(); ?></h3>
                    <span class="mluc-material-badge mluc-level-<?php echo esc_attr($min_lv); ?>">
                        <?php echo esc_html($level_label); ?>
                    </span>
                    <div class="mluc-material-excerpt"><?php the_excerpt(); ?></div>
                    <?php if ($download_url) : ?>
                        <a class="mluc-btn mluc-btn-download" href="<?php echo esc_url($download_url); ?>">
                            <?php esc_html_e('下載 PDF / 工作紙', 'moonlight-shop'); ?>
                        </a>
                    <?php else : ?>
                        <a class="mluc-btn mluc-btn-login" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">
                            <?php esc_html_e('登入後下載', 'moonlight-shop'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php
            }
            wp_reset_postdata();
        } else {
            echo '<p class="mluc-empty">' . esc_html__('暫無可下載的教材。', 'moonlight-shop') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * 短代码 [mluc_downloads] 用户中心下载页（限登录）。
     */
    public function shortcode_downloads()
    {
        if (!is_user_logged_in()) {
            return '<p class="mluc-message">' .
                esc_html__('請先登入。', 'moonlight-shop') .
                '</p>';
        }
        $query = self::query_accessible_materials(array('posts_per_page' => 50));

        ob_start();
        echo '<div class="mluc-downloads-list">';
        if ($query->have_posts()) {
            echo '<div class="mluc-table-scroll"><table class="mluc-table"><thead><tr><th>' . esc_html__('教材', 'moonlight-shop') . '</th><th>' . esc_html__('等級', 'moonlight-shop') . '</th><th>' . esc_html__('操作', 'moonlight-shop') . '</th></tr></thead><tbody>';
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $min_lv = get_post_meta($post_id, self::META_LEVEL, true) ?: 'free';
                $level_label = class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label($min_lv) : $min_lv;
                $url = wp_nonce_url(add_query_arg(array('action' => 'mluc_download', 'mid' => $post_id), admin_url('admin-post.php')), 'mluc_download_' . $post_id);
                $used = (int) get_user_meta(get_current_user_id(), 'mluc_material_dl_' . $post_id, true);
                $limit = (int) get_post_meta($post_id, self::META_DOWNLOAD_LIMIT, true);
                $remaining = $limit > 0 ? max(0, $limit - $used) : '∞';
                echo '<tr>';
                echo '<td>' . esc_html(get_the_title()) . '</td>';
                echo '<td><span class="mluc-level-badge mluc-level-' . esc_attr($min_lv) . '">' . esc_html($level_label) . '</span></td>';
                echo '<td><a class="mluc-btn mluc-btn-small" href="' . esc_url($url) . '">' . esc_html__('下載', 'moonlight-shop') . '</a> ';
                echo '<span class="mluc-muted">';
                if ($limit > 0) {
                    /* translators: %1$$s: 值, %2$$s: 值 */
                    echo esc_html(sprintf(__('剩餘 %1$s / %2$s 次', 'moonlight-shop'), $remaining, $limit));
                } else {
                    esc_html_e('不限次', 'moonlight-shop');
                }
                echo '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            wp_reset_postdata();
        } else {
            echo '<p class="mluc-empty">' . esc_html__('暫無教材可下載。', 'moonlight-shop') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * 处理下载请求：限次验证、限流、force-download。
     */
    public function handle_download()
    {
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }
        $post_id = isset($_GET['mid']) ? (int) $_GET['mid'] : 0;
        $nonce   = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
        if (!$post_id || !wp_verify_nonce($nonce, 'mluc_download_' . $post_id)) {
            wp_die(esc_html__('安全校驗失敗。', 'moonlight-shop'));
        }
        if (self::CPT !== get_post_type($post_id)) {
            wp_die(esc_html__('教材不存在。', 'moonlight-shop'));
        }
        $min_lv = get_post_meta($post_id, self::META_LEVEL, true) ?: 'free';
        if (class_exists('MLUC_Membership') && !MLUC_Membership::user_can_access($min_lv)) {
            wp_die(esc_html__('權限不足，無法下載。', 'moonlight-shop'));
        }
        $limit = (int) get_post_meta($post_id, self::META_DOWNLOAD_LIMIT, true);
        $used  = (int) get_user_meta(get_current_user_id(), 'mluc_material_dl_' . $post_id, true);
        if ($limit > 0 && $used >= $limit) {
            wp_die(esc_html__('已達下載次數上限。', 'moonlight-shop'));
        }
        $file = get_post_meta($post_id, self::META_FILE, true);
        if (!$file) {
            wp_die(esc_html__('文件未配置。', 'moonlight-shop'));
        }
        // 计数：读-改-写在并发下会重复计数（连点两次只记一次却被放行两次），
        // 这里用 5 秒会话节流保证同一次下载只计一次，计数准确且不影响正常重试。
        $lock_key = 'mluc_dl_lock_' . get_current_user_id() . '_' . $post_id;
        if (!get_transient($lock_key)) {
            set_transient($lock_key, 1, 5);
            update_user_meta(get_current_user_id(), 'mluc_material_dl_' . $post_id, $used + 1);
        }

        // 本地上传文件：直接流式输出，避免暴露真实下载地址（仍受登录/等级/次数限制保护）。
        $upload = wp_upload_dir();
        $local  = '';
        if (!empty($upload['baseurl']) && strpos($file, $upload['baseurl']) === 0) {
            $local = str_replace($upload['baseurl'], $upload['basedir'], $file);
        }
        // 安全：路径必须落在上传目录内。URL 形如
        // /wp-content/uploads/../../../etc/passwd 会绕过前缀检查，
        // 故用 realpath 解析后再比对真实前缀。
        if ($local) {
            $real      = realpath($local);
            $base_real = !empty($upload['basedir']) ? realpath($upload['basedir']) : '';
            if (!$real || !$base_real || 0 !== strpos($real, $base_real)) {
                $local = '';
            } else {
                $local = $real;
            }
        }
        if ($local && file_exists($local) && is_readable($local)) {
            $download_name = preg_replace('/[^A-Za-z0-9._-]/u', '_', basename($file));
            if (!$download_name) {
                $download_name = 'download';
            }
            $mime = wp_check_filetype($local);
            header('Content-Type: ' . (!empty($mime['type']) ? $mime['type'] : 'application/octet-stream'));
            header('Content-Disposition: attachment; filename="' . $download_name . '"');
            header('Content-Length: ' . (string) filesize($local));
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- 下载流需要输出文件内容到 stdout
            header('Cache-Control: no-store, no-cache, must-revalidate');
            readfile($local);
            exit;
        }

        // 远程文件：回退为 302 跳转（权限校验已在上方完成）。
        wp_redirect($file, 302);
        exit;
    }
}
