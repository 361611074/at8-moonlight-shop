<?php
/**
 * 付费下载资源区（已解锁后展示）：可包含多个资源链接，每个独立按钮 + 复制信息。
 * 共享：下载提示 / 按钮颜色 / 按钮图标 / 文件信息 / 在线预览。
 *
 * 变量由 MLSHOP_Pay_Access::render_download_block() 提供。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}

$post_id   = isset($post_id) ? (int) $post_id : 0;
$items     = isset($items) && is_array($items) ? $items : array();
$btn_color = isset($btn_color) ? $btn_color : '#2271b1';
$btn_icon  = isset($btn_icon) ? $btn_icon : '';
$note      = isset($note) ? $note : '';
$attrs     = isset($attrs) && is_array($attrs) ? $attrs : array();
$demo_url  = isset($demo_url) ? $demo_url : '';
?>
<div class="mlshop-pay-download">
    <?php foreach ($items as $it) : ?>
        <div class="mlshop-dl-item">
            <a class="mlshop-btn mlshop-dl-btn" href="<?php echo esc_url($it['dl_url']); ?>" download
                style="background-color:<?php echo esc_attr($btn_color); ?>;border-color:<?php echo esc_attr($btn_color); ?>;">
                <?php if ($btn_icon) : ?><span class="mlshop-dl-icon"><?php echo esc_html($btn_icon); ?></span><?php endif; ?>
                <span class="mlshop-dl-label"><?php echo esc_html($it['label']); ?></span>
            </a>

            <?php if (!empty($it['copy_content'])) : ?>
                <div class="mlshop-copy-block">
                    <?php if (!empty($it['copy_name'])) : ?>
                        <span class="mlshop-copy-name"><?php echo esc_html($it['copy_name']); ?></span>
                    <?php endif; ?>
                    <button type="button" class="mlshop-copy-btn" data-copy="<?php echo esc_attr($it['copy_content']); ?>">
                        <?php esc_html_e('复制', 'moonlight-shop'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($note) : ?>
        <p class="mlshop-dl-note"><?php echo esc_html($note); ?></p>
    <?php endif; ?>

    <?php if ($attrs) : ?>
        <ul class="mlshop-dl-attrs">
            <?php foreach ($attrs as $a) : ?>
                <li><span class="mlshop-dl-attr-k"><?php echo esc_html($a['k']); ?></span><span class="mlshop-dl-attr-v"><?php echo esc_html($a['v']); ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($demo_url) : ?>
        <p class="mlshop-dl-demo">
            <a href="<?php echo esc_url($demo_url); ?>" target="_blank" rel="nofollow noopener"><?php esc_html_e('在线预览', 'moonlight-shop'); ?>&nbsp;↗</a>
        </p>
    <?php endif; ?>
</div>
