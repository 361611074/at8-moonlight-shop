<?php
/**
 * 「付费功能（用户中心）」Meta Box 模板（Pro 付费墙）。
 *
 * 交互结构与 at8-moonlight-shop 的 product-pay-meta.php 同源：
 *   - 顶部模式标签卡，写入隐藏域 mluc_pw_pay_mode
 *   - 关闭面板 / 通用配置面板 / 模式专属面板，显隐由 mluc-pw-admin.js 控制
 * 字段前缀 mluc_pw_，样式类前缀 mluc-pm-。后台标签保留中文（前台文案在
 * class-paywall.php 中全部走 mluc_ui_label 英文默认）。
 *
 * @var WP_Post $post
 * @var array   $data        字段当前值（含默认）
 * @var string  $symbol      货币符号
 * @var array   $level_prices 各会员等级价 [level => [label, price]]
 * @var array   $attrs_pairs / $gallery_ids / $dl_items / $vid_items / $img_urls
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<style>
.mluc-pw-meta { --ml-accent:#7c5cff; --ml-line:#e6e8ef; --ml-soft:#f6f7fb; font-size:13px; }
.mluc-pw-meta * { box-sizing:border-box; }
.mluc-pw-meta .ml-pm-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
.mluc-pw-meta .ml-pm-tab {
    border:1px solid var(--ml-line); background:#fff; color:#3a3f51;
    padding:7px 16px; border-radius:999px; cursor:pointer; font-size:13px; line-height:1.4;
    transition:all .15s ease;
}
.mluc-pw-meta .ml-pm-tab:hover { border-color:var(--ml-accent); color:var(--ml-accent); }
.mluc-pw-meta .ml-pm-tab.is-active { background:var(--ml-accent); border-color:var(--ml-accent); color:#fff; }
.mluc-pw-meta .ml-pm-pane { display:none; border:1px solid var(--ml-line); border-radius:10px; padding:16px; background:var(--ml-soft); }
.mluc-pw-meta .ml-pm-pane.is-show { display:block; }
.mluc-pw-meta .ml-pm-pane + .ml-pm-pane { margin-top:12px; }
.mluc-pw-meta .ml-pm-group + .ml-pm-group { margin-top:18px; padding-top:16px; border-top:1px dashed var(--ml-line); }
.mluc-pw-meta .ml-pm-hint { margin:0; color:#6b7080; line-height:1.6; }
.mluc-pw-meta .ml-pm-title { font-weight:600; color:#232838; margin:0 0 10px; font-size:13px; }
.mluc-pw-meta .ml-pm-row { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
.mluc-pw-meta .ml-pm-row > label { font-weight:600; color:#3a3f51; }
.mluc-pw-meta .ml-pm-inline { display:flex; flex-wrap:wrap; gap:14px 20px; align-items:center; }
.mluc-pw-meta .ml-pm-inline label { display:inline-flex; align-items:center; gap:6px; margin:0; font-weight:400; }
.mluc-pw-meta input[type=text], .mluc-pw-meta input[type=number], .mluc-pw-meta input[type=url] {
    width:100%; max-width:340px; padding:6px 9px; border:1px solid var(--ml-line); border-radius:6px; background:#fff;
}
.mluc-pw-meta textarea { width:100%; max-width:560px; padding:6px 9px; border:1px solid var(--ml-line); border-radius:6px; background:#fff; }
.mluc-pw-meta input[type=color] { width:42px; height:32px; padding:2px; border:1px solid var(--ml-line); border-radius:6px; background:#fff; vertical-align:middle; }
.mluc-pw-meta .ml-pm-note { color:#8a8f9e; font-size:12px; margin:4px 0 0; line-height:1.5; }
.mluc-pw-meta .ml-pm-suffix { color:#6b7080; margin-left:4px; }
.mluc-pw-meta .ml-pm-attr-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
.mluc-pw-meta .ml-pm-attr-row input { flex:1; max-width:none; }
.mluc-pw-meta .ml-pm-gallery { display:flex; flex-wrap:wrap; gap:10px; margin:10px 0; }
.mluc-pw-meta .ml-pm-thumb { position:relative; width:92px; height:92px; border:1px solid var(--ml-line); border-radius:8px; overflow:hidden; background:#fff; }
.mluc-pw-meta .ml-pm-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.mluc-pw-meta .ml-pm-thumb button { position:absolute; top:3px; right:3px; width:22px; height:22px; line-height:1; border:0; border-radius:50%; background:rgba(220,50,50,.88); color:#fff; cursor:pointer; padding:0; }
.mluc-pw-meta .ml-pm-cover { width:200px; max-width:100%; border:1px solid var(--ml-line); border-radius:8px; margin-top:8px; display:block; }
.mluc-pw-meta .ml-pm-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.mluc-pw-meta .button { cursor:pointer; }
.mluc-pw-meta .ml-pm-repeater-h { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.mluc-pw-meta .ml-pm-repeater-h .button { margin-left:auto; }
.mluc-pw-meta .ml-pm-dl-row, .mluc-pw-meta .ml-pm-vid-row, .mluc-pw-meta .ml-pm-url-row {
    padding:10px; border:1px solid var(--ml-line); border-radius:8px; background:#fff; margin-bottom:10px;
}
.mluc-pw-meta .ml-pm-dl-row { display:flex; flex-direction:column; gap:6px; }
.mluc-pw-meta .ml-pm-dl-row .ml-pm-dl-url { max-width:none; }
.mluc-pw-meta .ml-pm-row-inline { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.mluc-pw-meta .ml-pm-row-inline input { flex:1; min-width:120px; max-width:none; }
.mluc-pw-meta .ml-pm-vid-row { display:flex; flex-direction:column; gap:6px; }
.mluc-pw-meta .ml-pm-vid-row .ml-pm-vid-url { max-width:none; }
.mluc-pw-meta .ml-pm-vid-cover-wrap { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.mluc-pw-meta .ml-pm-vid-cover-img { width:120px; max-width:100%; border:1px solid var(--ml-line); border-radius:6px; }
.mluc-pw-meta .ml-pm-url-row { display:flex; gap:8px; align-items:center; }
.mluc-pw-meta .ml-pm-url-row input { flex:1; max-width:none; }
.mluc-pw-meta .ml-pm-price-grid { display:flex; flex-wrap:wrap; gap:10px 24px; }
.mluc-pw-meta .ml-pm-price-grid .ml-pm-row { margin-bottom:0; }
</style>

<div class="mluc-pw-meta" data-mode="<?php echo esc_attr($data['pay_mode']); ?>">

    <div class="ml-pm-tabs" role="tablist">
        <?php foreach (MLUC_Paywall::$pay_modes as $k => $label) : ?>
            <button type="button" class="ml-pm-tab" data-mode="<?php echo esc_attr($k); ?>" role="tab"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="mluc_pw_pay_mode" value="<?php echo esc_attr($data['pay_mode']); ?>">

    <!-- 关闭 -->
    <div class="ml-pm-pane" data-pane="off">
        <p class="ml-pm-hint">当前为「关闭」状态，内容将作为普通公开文章发布，不启用付费校验。选择上方其它模式即可开启对应付费能力。</p>
    </div>

    <!-- 通用配置 -->
    <div class="ml-pm-pane ml-pm-common" data-pane="common">

        <div class="ml-pm-group">
            <p class="ml-pm-title">谁能购买</p>
            <div class="ml-pm-inline">
                <label>
                    <input type="radio" name="mluc_pw_pay_auth" value="all" <?php checked($data['pay_auth'], 'all'); ?>>
                    所有人可购买
                </label>
                <?php foreach ($level_prices as $lk => $lp) : ?>
                    <label>
                        <input type="radio" name="mluc_pw_pay_auth" value="<?php echo esc_attr($lk); ?>" <?php checked($data['pay_auth'], $lk); ?>>
                        <?php echo esc_html($lp['label']); ?> 及以上可购买
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="ml-pm-note">设置会员专享后，请确认对应会员等级存在，否则购买入口会异常。</p>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">价格</p>
            <div class="ml-pm-price-grid">
                <div class="ml-pm-row">
                    <label for="mluc_pw_price_sell">执行价</label>
                    <span><input type="number" step="0.01" min="0" id="mluc_pw_price_sell" name="mluc_pw_price_sell" value="<?php echo esc_attr($data['price_sell']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
                </div>
                <div class="ml-pm-row" data-show="read,image,video">
                    <label for="mluc_pw_price_original">原价（划线展示）</label>
                    <span><input type="number" step="0.01" min="0" id="mluc_pw_price_original" name="mluc_pw_price_original" value="<?php echo esc_attr($data['price_original']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
                </div>
            </div>
            <?php if ($level_prices) : ?>
                <p class="ml-pm-title" style="margin-top:14px;">会员价（留空 / 0 = 该等级按执行价购买）</p>
                <div class="ml-pm-price-grid">
                    <?php foreach ($level_prices as $lk => $lp) : ?>
                        <div class="ml-pm-row">
                            <label for="mluc_pw_level_price_<?php echo esc_attr($lk); ?>"><?php echo esc_html($lp['label']); ?> 会员价</label>
                            <span><input type="number" step="0.01" min="0" id="mluc_pw_level_price_<?php echo esc_attr($lk); ?>" name="mluc_pw_level_price[<?php echo esc_attr($lk); ?>]" value="<?php echo esc_attr($lp['price']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">销售浮动</p>
            <div class="ml-pm-row">
                <label for="mluc_pw_sales_offset">销量基数偏移</label>
                <input type="number" step="1" id="mluc_pw_sales_offset" name="mluc_pw_sales_offset" value="<?php echo esc_attr($data['sales_offset']); ?>">
                <p class="ml-pm-note">在真实销量上增加或减少的数量，避免出现 0 销量</p>
            </div>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">订单时效</p>
            <label style="font-weight:400;display:inline-flex;align-items:center;gap:6px;">
                <input type="hidden" name="mluc_pw_order_expire_enabled" value="0">
                <input type="checkbox" name="mluc_pw_order_expire_enabled" value="1" <?php checked($data['order_expire_enabled'], '1'); ?>>
                开启订单时效（到期后需重新购买）
            </label>
            <div class="ml-pm-row" style="margin-top:10px;">
                <label for="mluc_pw_order_expire_value">有效时长</label>
                <span>
                    <input type="number" step="1" min="0" id="mluc_pw_order_expire_value" name="mluc_pw_order_expire_value" value="<?php echo esc_attr($data['order_expire_value']); ?>">
                    <select name="mluc_pw_order_expire_unit">
                        <?php foreach (MLUC_Paywall::$expire_units as $k => $label) : ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($data['order_expire_unit'], $k); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </div>
            <p class="ml-pm-note">建议在正文中提醒用户时效，避免误购投诉</p>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">商品信息（付费卡片展示）</p>
            <div class="ml-pm-row">
                <label for="mluc_pw_pay_popup_title">卡片标题</label>
                <input type="text" id="mluc_pw_pay_popup_title" name="mluc_pw_pay_popup_title" value="<?php echo esc_attr($data['pay_popup_title']); ?>" placeholder="留空则使用文章标题">
            </div>
            <div class="ml-pm-row">
                <label for="mluc_pw_pay_popup_desc">卡片描述</label>
                <textarea id="mluc_pw_pay_popup_desc" name="mluc_pw_pay_popup_desc" rows="3"><?php echo esc_textarea($data['pay_popup_desc']); ?></textarea>
            </div>
        </div>

    </div>

    <!-- 付费阅读 -->
    <div class="ml-pm-pane" data-pane="read">
        <p class="ml-pm-hint">付费阅读即对全文收费，无需额外配置资源。用户购买后展示完整正文，未购买时展示付费墙。</p>
    </div>

    <!-- 付费下载 -->
    <div class="ml-pm-pane" data-pane="download">

        <div class="ml-pm-group">
            <p class="ml-pm-title ml-pm-repeater-h">资源清单
                <button type="button" class="button ml-pm-dl-add">+ 新增资源链接</button>
            </p>
            <p class="ml-pm-note" style="margin-top:0;">可添加多个下载地址，每个独立一个下载按钮；未购买时锁定墙不会暴露真实地址。填写媒体库附件 ID 或外链均可。</p>
            <div class="ml-pm-dl-list">
                <?php foreach ($dl_items as $it) : ?>
                    <div class="ml-pm-dl-row">
                        <input type="url" class="ml-pm-dl-url" value="<?php echo esc_attr($it['url']); ?>" placeholder="下载地址 https://...">
                        <div class="ml-pm-row-inline">
                            <input type="text" class="ml-pm-dl-label" value="<?php echo esc_attr($it['label']); ?>" placeholder="按钮文字（留空默认 Download）">
                            <input type="text" class="ml-pm-dl-copy-name" value="<?php echo esc_attr($it['copy_name']); ?>" placeholder="复制项名称（如 提取码）">
                        </div>
                        <div class="ml-pm-row-inline">
                            <input type="text" class="ml-pm-dl-copy-content" value="<?php echo esc_attr($it['copy_content']); ?>" placeholder="复制内容（如 5942）">
                            <button type="button" class="button ml-pm-dl-del">删除</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="mluc_pw_download_items" class="ml-pm-dl-val" value="<?php echo esc_attr($data['download_items']); ?>">
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">下载提示</p>
            <div class="ml-pm-row">
                <label for="mluc_pw_download_note">下载提示</label>
                <input type="text" id="mluc_pw_download_note" name="mluc_pw_download_note" value="<?php echo esc_attr($data['download_note']); ?>" placeholder="如：解压密码 5942">
                <p class="ml-pm-note">显示在下载按钮区下方的一段附加说明（提取码、解压密码等）</p>
            </div>
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">按钮颜色</p>
            <div class="ml-pm-row">
                <label for="mluc_pw_download_btn_color">下载按钮颜色</label>
                <input type="color" id="mluc_pw_download_btn_color" name="mluc_pw_download_btn_color" value="<?php echo esc_attr($data['download_btn_color']); ?>">
            </div>
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">文件信息</p>
            <p class="ml-pm-note" style="margin-top:0;">为下载区附加的键值对说明，例如「格式：PDF / 大小：12MB」。</p>
            <div class="ml-pm-attrs">
                <?php foreach ($attrs_pairs as $pair) : ?>
                    <div class="ml-pm-attr-row">
                        <input type="text" class="ml-pm-attr-k" value="<?php echo esc_attr($pair[0]); ?>" placeholder="键（如 文件格式）">
                        <input type="text" class="ml-pm-attr-v" value="<?php echo esc_attr($pair[1]); ?>" placeholder="值（如 PDF）">
                        <button type="button" class="button ml-pm-attr-del">删除</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="ml-pm-actions">
                <button type="button" class="button ml-pm-attr-add">添加一条</button>
            </div>
            <input type="hidden" name="mluc_pw_download_attrs" class="ml-pm-attrs-val" value="<?php echo esc_attr($data['download_attrs']); ?>">
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">在线预览</p>
            <div class="ml-pm-row">
                <label for="mluc_pw_demo_url">演示地址</label>
                <input type="url" id="mluc_pw_demo_url" name="mluc_pw_demo_url" value="<?php echo esc_attr($data['demo_url']); ?>" placeholder="https://...">
                <p class="ml-pm-note">用于软件、主题等可在线演示的资源</p>
            </div>
        </div>

    </div>

    <!-- 付费图集 -->
    <div class="ml-pm-pane" data-pane="image">
        <div class="ml-pm-group">
            <p class="ml-pm-title">付费图集</p>
            <p class="ml-pm-note" style="margin-top:0;">上传多张图片，前台按序展示。前 N 张可免费查看，其余需付费解锁。</p>
            <div class="ml-pm-gallery" data-target="mluc_pw_image_gallery">
                <?php foreach ($gallery_ids as $aid) :
                    $thumb = wp_get_attachment_image_url($aid, 'thumbnail');
                    if (!$thumb) { continue; }
                ?>
                    <div class="ml-pm-thumb" data-id="<?php echo esc_attr($aid); ?>">
                        <img src="<?php echo esc_url($thumb); ?>" alt="">
                        <button type="button" class="ml-pm-remove" aria-label="删除">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="ml-pm-actions">
                <button type="button" class="button ml-pm-gallery-add">新增图片</button>
                <button type="button" class="button ml-pm-gallery-edit">编辑图片</button>
                <button type="button" class="button ml-pm-gallery-clear">清空</button>
            </div>
            <input type="hidden" name="mluc_pw_image_gallery" class="ml-pm-gallery-ids" value="<?php echo esc_attr($data['image_gallery']); ?>">
        </div>
        <div class="ml-pm-group">
            <p class="ml-pm-title ml-pm-repeater-h">外链图片（资源链接地址）
                <button type="button" class="button ml-pm-url-add">+ 新增图片链接</button>
            </p>
            <p class="ml-pm-note" style="margin-top:0;">除上传图外，也可直接填写图片链接地址，与上传图一同按序展示；前 N 张免费规则同样适用。</p>
            <div class="ml-pm-url-list">
                <?php foreach ($img_urls as $u) : ?>
                    <div class="ml-pm-url-row">
                        <input type="url" class="ml-pm-url-input" value="<?php echo esc_attr($u); ?>" placeholder="https://... 图片直链">
                        <button type="button" class="button ml-pm-url-del">删除</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="mluc_pw_image_urls" class="ml-pm-url-hidden" value="<?php echo esc_attr($data['image_urls']); ?>">
        </div>
        <div class="ml-pm-group">
            <p class="ml-pm-title">免费展示张数</p>
            <div class="ml-pm-row">
                <label for="mluc_pw_image_free_count">前几张免费</label>
                <span><input type="number" step="1" min="0" id="mluc_pw_image_free_count" name="mluc_pw_image_free_count" value="<?php echo esc_attr($data['image_free_count']); ?>" style="max-width:90px;"> 张</span>
                <p class="ml-pm-note">可免费查看前 N 张；N 不可大于图集总数，否则不生效</p>
            </div>
        </div>
    </div>

    <!-- 付费视频 -->
    <div class="ml-pm-pane" data-pane="video">
        <div class="ml-pm-group">
            <p class="ml-pm-title ml-pm-repeater-h">播放列表
                <button type="button" class="button ml-pm-vid-add">+ 新增视频</button>
            </p>
            <p class="ml-pm-note" style="margin-top:0;">可添加多个视频，未购买时仅展示封面，不暴露视频地址。</p>
            <div class="ml-pm-vid-list">
                <?php foreach ($vid_items as $it) :
                    $vc_url = $it['cover'] ? wp_get_attachment_image_url($it['cover'], 'medium') : '';
                ?>
                    <div class="ml-pm-vid-row" data-cover="<?php echo esc_attr($it['cover']); ?>">
                        <input type="url" class="ml-pm-vid-url" value="<?php echo esc_attr($it['url']); ?>" placeholder="视频 URL（mp4 / m3u8 / 第三方嵌入）">
                        <div class="ml-pm-row-inline">
                            <input type="text" class="ml-pm-vid-title" value="<?php echo esc_attr($it['title']); ?>" placeholder="标题（可选）">
                            <button type="button" class="button ml-pm-vid-del">删除</button>
                        </div>
                        <div class="ml-pm-vid-cover-wrap">
                            <button type="button" class="button ml-pm-vid-cover">选择封面</button>
                            <button type="button" class="button ml-pm-vid-cover-del">移除</button>
                            <?php if ($vc_url) : ?><img class="ml-pm-vid-cover-img" src="<?php echo esc_url($vc_url); ?>" alt=""><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="mluc_pw_video_items" class="ml-pm-vid-val" value="<?php echo esc_attr($data['video_items']); ?>">
        </div>
    </div>

</div>
