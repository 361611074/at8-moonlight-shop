<?php
/**
 * 商品收藏（心愿单）—— 基于 Cookie 的会话收藏，兼容游客。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Favorite
{
    private static $instance;
    private $cookie = 'mlshop_fav';
    private $ids    = null;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_shortcode('mlshop_favorites', array($this, 'shortcode_favorites'));
        add_action('wp_ajax_nopriv_mlshop_toggle_favorite', array($this, 'toggle'));
        add_action('wp_ajax_mlshop_toggle_favorite', array($this, 'toggle'));
    }

    private function read()
    {
        if ($this->ids !== null) {
            return $this->ids;
        }
        $raw = isset($_COOKIE[$this->cookie]) ? $_COOKIE[$this->cookie] : '';
        $dec = $raw ? json_decode(stripslashes($raw), true) : array();
        $this->ids = is_array($dec) ? array_filter(array_map('intval', $dec), function ($id) {
            return $id > 0;
        }) : array();
        return $this->ids;
    }

    private function write($ids)
    {
        $this->ids = array_values(array_unique($ids));
        $value = json_encode($this->ids);
        setcookie($this->cookie, $value, time() + 2592000, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }

    public function get_ids()
    {
        return $this->read();
    }

    public function has($product_id)
    {
        return in_array((int) $product_id, $this->read(), true);
    }

    public function count()
    {
        return count($this->read());
    }

    public function toggle($product_id = 0)
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            check_ajax_referer('mlshop_nonce', 'nonce');
            $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        }
        if (!$product_id || get_post_type($product_id) !== 'mlshop_product') {
            if (defined('DOING_AJAX') && DOING_AJAX) {
                mlshop_send_json(false, __('商品不存在。', 'moonlight-shop'));
            }
            return false;
        }
        $ids = $this->read();
        $pos = array_search($product_id, $ids, true);
        $active = false;
        if ($pos !== false) {
            unset($ids[$pos]);
        } else {
            $ids[] = $product_id;
            $active = true;
        }
        $this->write(array_values($ids));
        if (defined('DOING_AJAX') && DOING_AJAX) {
            mlshop_send_json(true, $active ? __('已加入收藏。', 'moonlight-shop') : __('已取消收藏。', 'moonlight-shop'), array(
                'count'  => $this->count(),
                'active' => $active,
            ));
        }
        return $active;
    }

    public function shortcode_favorites()
    {
        $ids = $this->get_ids();
        ob_start();
        if (empty($ids)) {
            echo '<div class="mlshop-empty-state">';
            echo '<p class="mlshop-message">' . esc_html__('收藏是空白的。', 'moonlight-shop') . '</p>';
            echo '<a class="mlshop-btn" href="' . esc_url(mlshop_get_store_url()) . '">' . esc_html__('去挑选商品', 'moonlight-shop') . '</a>';
            echo '</div>';
            return ob_get_clean();
        }
        $query = new WP_Query(array(
            'post_type'      => 'mlshop_product',
            'posts_per_page' => -1,
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'post_status'    => 'publish',
        ));
        echo '<div class="mlshop-products mlshop-favorites-grid">';
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                mlshop_get_template('product-item', array('product_id' => get_the_ID()));
            }
            wp_reset_postdata();
        }
        echo '</div>';
        return ob_get_clean();
    }
}

/**
 * 输出收藏（心愿单）按钮。
 *
 * @param int  $product_id 商品 ID
 * @param bool $echo       是否直接输出
 * @return string
 */
function mlshop_favorite_button($product_id = 0, $echo = true)
{
    if (!$product_id) {
        $product_id = get_the_ID();
    }
    $active = (class_exists('MLSHOP_Favorite') && MLSHOP_Favorite::get_instance()->has($product_id)) ? ' is-active' : '';
    $label  = $active ? __('已收藏', 'moonlight-shop') : __('收藏', 'moonlight-shop');
    $html   = sprintf(
        '<button type="button" class="mlshop-btn mlshop-favorite-btn%s" data-product-id="%d" aria-pressed="%s"><span class="mlshop-heart">%s</span><span class="mlshop-fav-label">%s</span></button>',
        esc_attr($active),
        (int) $product_id,
        $active ? 'true' : 'false',
        $active ? '♥' : '♡',
        esc_html($label)
    );
    if ($echo) {
        echo $html;
    }
    return $html;
}

/**
 * 当前收藏数量（用于顶栏/购物车区显示）。
 */
function mlshop_favorite_count()
{
    if (!class_exists('MLSHOP_Favorite')) {
        return 0;
    }
    return MLSHOP_Favorite::get_instance()->count();
}

/**
 * 输出侧栏「我的收藏」widget 内部 HTML（从 <h2> 标题到 ul/empty 段落止）。
 * 不含 widget area 的 before_widget / after_widget 包裹，由调用方放到容器 innerHTML。
 * 用于 ajax fragment，让 JS 在收藏切换后无需整页刷新就能即时同步侧栏内容。
 *
 * @param string $title 标题文案（默认「我的收藏」）。
 * @return string HTML 片段
 */
function mlshop_render_favorites_widget_inner($title = '')
{
    if (!class_exists('MLSHOP_Favorite')) {
        return '';
    }
    $fav  = MLSHOP_Favorite::get_instance();
    $ids  = $fav->get_ids();
    $title = $title !== '' ? $title : __('我的收藏', 'moonlight-shop');
    $count = count($ids);
    ob_start();
    ?>
    <h2 class="mlshop-widget-title"><?php echo esc_html($title); ?> <span class="mlshop-fav-count"><?php echo (int) $count; ?></span></h2>
    <?php if ($count === 0) : ?>
        <p class="mlshop-empty"><?php esc_html_e('还没有收藏的商品。', 'moonlight-shop'); ?></p>
    <?php else : ?>
        <?php
        $q = new WP_Query(array(
            'post_type'      => 'mlshop_product',
            'posts_per_page' => -1,
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'post_status'    => 'publish',
        ));
        if ($q->have_posts()) :
            ?>
            <ul class="mlshop-fav-list">
            <?php while ($q->have_posts()) : $q->the_post(); ?>
                <li>
                    <a href="<?php echo esc_url(get_permalink()); ?>">
                        <?php echo get_the_post_thumbnail(get_the_ID(), 'thumbnail'); ?>
                        <span><?php echo esc_html(get_the_title()); ?></span>
                    </a>
                </li>
            <?php endwhile; wp_reset_postdata(); ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}
