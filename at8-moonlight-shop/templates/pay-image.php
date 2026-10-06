<?php
/**
 * 付费图片集：已解锁时全部展示；未解锁时仅展示前 N 张免费图，其余模糊 + 锁定遮罩。
 *
 * 变量由 MLSHOP_Pay_Access::render_image_gallery() 提供。
 *
 * @package Moonlight_Shop
 */
if (!defined('ABSPATH')) {
    exit;
}

$post_id = isset($post_id) ? (int) $post_id : 0;
$items   = isset($items) && is_array($items) ? $items : array();
$locked  = !empty($locked);
$free    = isset($free) ? (int) $free : 0;
$total   = isset($total) ? (int) $total : count($items);

if (empty($items)) {
    return;
}
?>
<div class="mlshop-pay-gallery<?php echo $locked ? ' mlshop-gallery-locked' : ''; ?>">
    <?php foreach ($items as $it) : ?>
        <?php if ($it['free'] && $it['full']) : ?>
            <a class="mlshop-gallery-item" href="<?php echo esc_url($it['full']); ?>" target="_blank" rel="nofollow noopener">
                <img src="<?php echo esc_url($it['thumb']); ?>" alt="">
            </a>
        <?php else : ?>
            <div class="mlshop-gallery-item mlshop-locked" data-post-id="<?php echo esc_attr($post_id); ?>">
                <?php if ($it['thumb']) : ?><img src="<?php echo esc_url($it['thumb']); ?>" alt="" class="mlshop-blur"><?php endif; ?>
                <span class="mlshop-lock-mask" aria-hidden="true">
                    <span class="mlshop-lock-ico">🔒</span>
                </span>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php if ($locked && $free < $total) : ?>
    /* translators: %1$$d: 数量, %2$$d: 数量 */
    /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已在上游转义 */
    /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已在上游转义 */
    <p class="mlshop-gallery-tip"><?php printf(esc_html__('前 %1$d 张免费，剩余 %2$d 张购买后查看。', 'at8-moonlight-shop'), $free, max(0, $total - $free)); ?></p>
<?php endif; ?>
