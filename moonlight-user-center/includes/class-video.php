<?php
/**
 * 示范影片：CPT mluc_demo_video + 短代码 [mluc_videos]。
 *
 * 每个视频有最低访问等级；前端短代码按当前用户等级过滤显示。
 *
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
        add_shortcode('mluc_videos', array($this, 'shortcode_videos'));
        add_shortcode('mluc_video', array($this, 'shortcode_single'));
    }

    public function register_cpt()
    {
        register_post_type(self::CPT, array(
            'labels' => array(
                'name'          => __('示範影片', 'moonlight-user-center'),
                'singular_name' => __('示範影片', 'moonlight-user-center'),
                'add_new_item'  => __('新增示範影片', 'moonlight-user-center'),
                'edit_item'     => __('編輯示範影片', 'moonlight-user-center'),
            ),
            'public'              => true,
            'has_archive'         => true,
            'show_in_rest'        => true,
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
            __('影片設定', 'moonlight-user-center'),
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
            $levels = array('free' => array('label' => __('公開（免費）', 'moonlight-user-center'))) + $levels;
        }
        ?>
        <p>
            <label for="mluc_video_min_level"><strong><?php esc_html_e('最低訪問等級', 'moonlight-user-center'); ?></strong></label><br>
            <select name="mluc_video_min_level" id="mluc_video_min_level" style="width:100%;max-width:300px;">
                <?php foreach ($levels as $key => $lv) : ?>
                    <option value="<?php echo esc_attr($key); ?>"<?php selected($level, $key); ?>>
                        <?php echo esc_html($lv['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="mluc_video_embed"><strong><?php esc_html_e('嵌入 URL（YouTube / Vimeo / 直接 mp4）', 'moonlight-user-center'); ?></strong></label><br>
            <input type="url" name="mluc_video_embed" id="mluc_video_embed" value="<?php echo esc_attr($embed); ?>" style="width:100%;" placeholder="https://www.youtube.com/watch?v=... 或 https://.../video.mp4">
        </p>
        <p>
            <label for="mluc_video_thumb"><strong><?php esc_html_e('自訂縮圖網址（選填）', 'moonlight-user-center'); ?></strong></label><br>
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
        $levels = array('free');
        if (is_user_logged_in() && class_exists('MLUC_Membership')) {
            $user_level = MLUC_Membership::get_user_level();
            $all = MLUC_Membership::get_levels();
            foreach ($all as $key => $lv) {
                if (MLUC_Membership::get_level_sort_order($key) <= MLUC_Membership::get_level_sort_order($user_level)) {
                    $levels[] = $key;
                }
            }
        }
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
            echo '<p class="mluc-empty">' . esc_html__('暫無可觀看的示範影片。', 'moonlight-user-center') . '</p>';
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
                            __('此影片需要 %s 會員等級，請升級以觀看。', 'moonlight-user-center'),
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
