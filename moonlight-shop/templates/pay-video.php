<?php
/**
 * 付费视频：支持多个视频。
 * 已解锁时逐个渲染播放器（直链 video / 外链跳转）；未解锁时每个显示封面 + 锁定遮罩。
 *
 * 变量由 MLSHOP_Pay_Access::render_video_player() 提供。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}

$post_id = isset($post_id) ? (int) $post_id : 0;
$items   = isset($items) && is_array($items) ? $items : array();
$locked  = !empty($locked);

if (empty($items)) {
    return;
}
?>
<div class="mlshop-pay-video-list">
    <?php foreach ($items as $v) :
        $video_url = isset($v['url']) ? $v['url'] : '';
        $cover_url = isset($v['cover_url']) ? $v['cover_url'] : '';
        $title     = isset($v['title']) ? $v['title'] : '';
    ?>
        <div class="mlshop-pay-video-item">
            <?php if ($locked) :
                $style = $cover_url ? ' style="background-image:url(' . esc_url($cover_url) . ');"' : '';
            ?>
                <div class="mlshop-pay-video mlshop-video-locked"<?php echo esc_html($style); ?> data-post-id="<?php echo esc_attr($post_id); ?>">
                    <span class="mlshop-video-mask">
                        <span class="mlshop-video-play">▶</span>
                        <span class="mlshop-video-tip"><?php echo $title ? esc_html($title) : esc_html_e('付费后观看', 'moonlight-shop'); ?></span>
                    </span>
                </div>
            <?php else : ?>
                <div class="mlshop-pay-video">
                    <?php
                    $ext = strtolower(pathinfo(wp_parse_url($video_url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    if (in_array($ext, array('mp4', 'webm', 'ogg', 'mov'), true)) :
                    ?>
                        <video class="mlshop-video-player" controls preload="metadata"<?php echo $cover_url ? ' poster="' . esc_url($cover_url) . '"' : ''; ?>>
                            <source src="<?php echo esc_url($video_url); ?>" type="video/<?php echo esc_attr($ext === 'mov' ? 'mp4' : $ext); ?>">
                            <?php esc_html_e('当前浏览器不支持视频播放。', 'moonlight-shop'); ?>
                        </video>
                    <?php else : ?>
                        <a class="mlshop-btn mlshop-video-link" href="<?php echo esc_url($video_url); ?>" target="_blank" rel="nofollow noopener">
                            <span class="mlshop-video-play">▶</span> <?php echo $title ? esc_html($title) : esc_html_e('观看视频', 'moonlight-shop'); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <?php if ($title) : ?><p class="mlshop-video-title"><?php echo esc_html($title); ?></p><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
