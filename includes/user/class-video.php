<?php
/**
 * 示范影片：CPT mluc_demo_video + 短代码 [mluc_videos]。
 *
 * 每个视频有最低访问等级；前端短代码按当前用户等级过滤显示。
 *
 * 自 moonlight-user-center v2.0.0 并入（at8-moonlight-shop Phase A，无行为变更）。
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Video
{
    public const CPT = 'mluc_demo_video';
    public const META_LEVEL = 'mluc_video_min_level';
    public const META_EMBED = 'mluc_video_embed';
    public const META_THUMB = 'mluc_video_thumb';

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
        if (!shortcode_exists('mluc_videos')) {
            add_shortcode('mluc_videos', array($this, 'shortcode_videos'));
        }
        if (!shortcode_exists('mluc_video')) {
            add_shortcode('mluc_video', array($this, 'shortcode_single'));
        }
        // 安全：单页直访兜底（[mluc_videos] 卡片会输出 the_permalink），未达等级一律拦截。
        add_action('template_redirect', array($this, 'guard_single_access'));
        // 安全：归档 / ?post_type= 枚举兜底（has_archive=false 挡不住查询变量方式）。
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
     * 影片单页访问控制：未登录引导登录，已登录但等级不足返回 403。
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
            wp_die(esc_html__('權限不足，無法觀看此影片。', 'at8-moonlight-shop'), 403);
        }
    }

    public function register_cpt()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => __('示範影片', 'at8-moonlight-shop'),
                'singular_name' => __('示範影片', 'at8-moonlight-shop'),
                'add_new'       => __('新建影片', 'at8-moonlight-shop'),
                'add_new_item'  => __('新增示範影片', 'at8-moonlight-shop'),
                'edit_item'     => __('編輯示範影片', 'at8-moonlight-shop'),
            ),
            // 安全收敛：与教材同理（关闭 REST / 归档 / 搜索收录），
            // 保留单页链接供 [mluc_videos] 卡片跳转，
            // 未授权访问由 template_redirect 上的等级拦截兜底（见 guard_single_access）。
            'public'              => true,
            'publicly_queryable'  => true,
            'has_archive'         => false,
            'show_in_rest'        => false,
            'show_ui'             => true,
            'exclude_from_search' => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_menu'        => false,
            'menu_icon'           => 'dashicons-video-alt3',
            'menu_position'       => 21,
            'supports'            => array('title', 'editor', 'thumbnail', 'excerpt'),
            'capability_type'     => 'post',
        ));
    }

    public function register_metabox()
    {
        add_meta_box(
            'mluc_video_meta',
            __('影片設定', 'at8-moonlight-shop'),
            array($this, 'render_metabox'),
            self::CPT,
            'normal',
            'high'
        );
    }

    public function render_metabox($post)
    {
        wp_nonce_field('mluc_video_meta', 'mluc_video_meta_nonce');
        $level = get_post_meta($post->ID, self::META_LEVEL, true);
        if (!$level) {
            $level = 'free';
        }
        $embed = get_post_meta($post->ID, self::META_EMBED, true);
        $thumb = get_post_meta($post->ID, self::META_THUMB, true);
        $levels = class_exists('MLUC_Membership') ? MLUC_Membership::get_levels() : array();
        // 普通会员关闭时 free 不在等级表中，但作为「公开内容」标记仍需出现在下拉里
        if (!isset($levels['free'])) {
            $levels = array('free' => array('label' => __('公開（免費）', 'at8-moonlight-shop'))) + $levels;
        }
        ?>
        <p>
            <label for="mluc_video_min_level"><strong><?php esc_html_e('最低訪問等級', 'at8-moonlight-shop'); ?></strong></label><br>
            <select name="mluc_video_min_level" id="mluc_video_min_level" style="width:100%;max-width:300px;">
                <?php foreach ($levels as $key => $lv) : ?>
                    <option value="<?php echo esc_attr($key); ?>"<?php selected($level, $key); ?>>
                        <?php echo esc_html($lv['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="mluc_video_embed"><strong><?php esc_html_e('嵌入 URL（YouTube / Vimeo / 直接 mp4）', 'at8-moonlight-shop'); ?></strong></label><br>
            <input type="url" name="mluc_video_embed" id="mluc_video_embed" value="<?php echo esc_attr($embed); ?>" style="width:100%;" placeholder="https://www.youtube.com/watch?v=... 或 https://.../video.mp4">
        </p>
        <p>
            <label for="mluc_video_thumb"><strong><?php esc_html_e('自訂縮圖網址（選填）', 'at8-moonlight-shop'); ?></strong></label><br>
            <input type="url" name="mluc_video_thumb" id="mluc_video_thumb" value="<?php echo esc_attr($thumb); ?>" style="width:100%;">
        </p>
        <?php
    }

    public function save_metabox($post_id)
    {
        if (!isset($_POST['mluc_video_meta_nonce']) || !wp_verify_nonce($_POST['mluc_video_meta_nonce'], 'mluc_video_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (isset($_POST['mluc_video_min_level'])) {
            update_post_meta($post_id, self::META_LEVEL, sanitize_key($_POST['mluc_video_min_level']));
        }
        if (isset($_POST['mluc_video_embed'])) {
            update_post_meta($post_id, self::META_EMBED, esc_url_raw($_POST['mluc_video_embed']));
        }
        if (isset($_POST['mluc_video_thumb'])) {
            update_post_meta($post_id, self::META_THUMB, esc_url_raw($_POST['mluc_video_thumb']));
        }
    }

    /**
     * 将 YouTube/Vimeo 链接转为可嵌入 URL。
     */
    public static function normalize_embed_url($url)
    {
        $url = trim((string) $url);
        if (!$url) {
            return '';
        }
        // 直接 mp4
        if (preg_match('/\.(mp4|webm|ogv)(\?|$)/i', $url)) {
            return $url;
        }
        // YouTube watch?v=
        if (preg_match('#youtube\.com/watch\?v=([\w\-]+)#i', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        // YouTube youtu.be
        if (preg_match('#youtu\.be/([\w\-]+)#i', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        // Vimeo
        if (preg_match('#vimeo\.com/(\d+)#i', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return $url;
    }

    /**
     * 查询当前用户可访问的影片。
     *
     * @return WP_Query
     */
    public static function query_accessible_videos($args = array())
    {
        $defaults = array(
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        $args = wp_parse_args($args, $defaults);

        // 按等级过滤（meta_query OR）
        $levels = self::accessible_levels();
        $level_query = array('relation' => 'OR');
        foreach ($levels as $lvl) {
            $level_query[] = array(
                'key'     => self::META_LEVEL,
                'value'   => $lvl,
                'compare' => '=',
            );
        }
        // 未填等级的视为 free
        $level_query[] = array(
            'key'     => self::META_LEVEL,
            'value'   => '',
            'compare' => 'NOT EXISTS',
        );
        $args['meta_query'] = $level_query;

        return new WP_Query($args);
    }

    /**
     * 短代码 [mluc_videos limit="12"]。
     */
    public function shortcode_videos($atts)
    {
        $atts = shortcode_atts(array(
            'limit' => 12,
            'columns' => 3,
        ), $atts, 'mluc_videos');

        $query = self::query_accessible_videos(array('posts_per_page' => (int) $atts['limit']));

        ob_start();
        echo '<div class="mluc-videos-grid" data-columns="' . esc_attr($atts['columns']) . '">';
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $embed   = get_post_meta($post_id, self::META_EMBED, true);
                $thumb   = get_post_meta($post_id, self::META_THUMB, true);
                $min_lv  = get_post_meta($post_id, self::META_LEVEL, true) ?: 'free';
                $level_label = class_exists('MLUC_Membership') ? MLUC_Membership::get_level_label($min_lv) : $min_lv;
                if (!$thumb && has_post_thumbnail()) {
                    $thumb = get_the_post_thumbnail_url($post_id, 'medium');
                }
                ?>
                <div class="mluc-video-card">
                    <a class="mluc-video-thumb" href="<?php the_permalink(); ?>">
                        <?php if ($thumb) : ?>
                            <img src="<?php echo esc_url($thumb); ?>" alt="<?php the_title_attribute(); ?>">
                        <?php else : ?>
                            <span class="mluc-video-placeholder">▶</span>
                        <?php endif; ?>
                        <span class="mluc-video-badge mluc-level-<?php echo esc_attr($min_lv); ?>">
                            <?php echo esc_html($level_label); ?>
                        </span>
                    </a>
                    <h3 class="mluc-video-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                </div>
                <?php
            }
            wp_reset_postdata();
        } else {
            echo '<p class="mluc-empty">' . esc_html__('暫無可觀看的示範影片。', 'at8-moonlight-shop') . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * 短代码 [mluc_video id="123"] 单影片播放。
     */
    public function shortcode_single($atts)
    {
        $atts = shortcode_atts(array('id' => 0), $atts, 'mluc_video');
        $post_id = (int) $atts['id'];
        if (!$post_id) {
            $post_id = get_the_ID();
        }
        if (!$post_id || self::CPT !== get_post_type($post_id)) {
            return '';
        }
        $min_level = get_post_meta($post_id, self::META_LEVEL, true) ?: 'free';
        $can_access = !class_exists('MLUC_Membership') || MLUC_Membership::user_can_access($min_level);
        $embed = self::normalize_embed_url(get_post_meta($post_id, self::META_EMBED, true));

        ob_start();
        ?>
        <div class="mluc-video-single">
            <?php if ($can_access) : ?>
                <?php if ($embed) : ?>
                    <div class="mluc-video-player">
                        <iframe src="<?php echo esc_url($embed); ?>" frameborder="0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                    </div>
                <?php endif; ?>
                <h2><?php echo esc_html(get_the_title($post_id)); ?></h2>
                <div class="mluc-video-content"><?php echo wp_kses_post(get_post_field('post_content', $post_id)); ?></div>
            <?php else : ?>
                <p class="mluc-locked">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: 等级名 */
                            __('此影片需要 %s 會員等級，請升級以觀看。', 'at8-moonlight-shop'),
                            MLUC_Membership::get_level_label($min_level)
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

