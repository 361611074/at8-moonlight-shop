<?php
/**
 * 产品付费 Meta Box 模板（原创版式）。
 *
 * 交互结构：
 *   - 顶部「模式标签卡」切换付费模式，写入隐藏域 mlshop_pay_mode
 *   - 「关闭」面板：仅提示，无其它配置
 *   - 「通用配置」面板：购买权限 / 付款方式 / 积分售价 / 价格 / 销售浮动 / 优惠码 / 订单时效 / 商品信息
 *   - 「专属配置」面板：按当前模式显示 下载 / 图集 / 视频 的专属字段
 * 显隐由后台 JS（mlshop-admin.js）依据 data-pane / data-show 控制。
 *
 * 字段文案与版式为本项目原创，不照搬任何第三方主题。
 *
 * @var WP_Post $post
 * @var array   $data  所有字段当前值（含默认值）
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

$modes  = MLSHOP_Product_Pay_Meta::$pay_modes;
$auths  = MLSHOP_Product_Pay_Meta::$pay_auths;
$types  = MLSHOP_Product_Pay_Meta::$pay_types;
$units  = MLSHOP_Product_Pay_Meta::$expire_units;
$symbol = mlshop_get_option('currency_symbol', 'HK$');
$credit_name = mlshop_get_option('credit_name', '积分');

// 文件信息（键值对）解析
$attrs_pairs = array();
if (!empty($data['download_attrs'])) {
    $tmp = json_decode((string) $data['download_attrs'], true);
    if (is_array($tmp)) {
        foreach ($tmp as $p) {
            if (is_array($p) && isset($p[0])) {
                $attrs_pairs[] = array((string) $p[0], (string) (isset($p[1]) ? $p[1] : ''));
            }
        }
    }
}
if (empty($attrs_pairs)) {
    $attrs_pairs = array(array('', ''));
}

// 付费图集（CSV 附件 ID）
$gallery_ids = array();
if (!empty($data['image_gallery'])) {
    $gallery_ids = array_filter(array_map('intval', explode(',', (string) $data['image_gallery'])));
}

// 下载资源清单（可重复）
$dl_items = array();
if (!empty($data['download_items'])) {
    $tmp = json_decode((string) $data['download_items'], true);
    if (is_array($tmp)) {
        foreach ($tmp as $it) {
            if (!is_array($it)) { continue; }
            $dl_items[] = array(
                'url'          => isset($it['url']) ? (string) $it['url'] : '',
                'label'        => isset($it['label']) ? (string) $it['label'] : '',
                'copy_name'    => isset($it['copy_name']) ? (string) $it['copy_name'] : '',
                'copy_content' => isset($it['copy_content']) ? (string) $it['copy_content'] : '',
            );
        }
    }
}
if (empty($dl_items)) {
    $dl_items = array(array('url' => '', 'label' => '', 'copy_name' => '', 'copy_content' => ''));
}

// 视频播放列表（可重复）
$vid_items = array();
if (!empty($data['video_items'])) {
    $tmp = json_decode((string) $data['video_items'], true);
    if (is_array($tmp)) {
        foreach ($tmp as $it) {
            if (!is_array($it)) { continue; }
            $vid_items[] = array(
                'url'   => isset($it['url']) ? (string) $it['url'] : '',
                'cover' => isset($it['cover']) ? (int) $it['cover'] : 0,
                'title' => isset($it['title']) ? (string) $it['title'] : '',
            );
        }
    }
}
if (empty($vid_items)) {
    $vid_items = array(array('url' => '', 'cover' => 0, 'title' => ''));
}

// 外链图片（资源链接地址，可重复）
$img_urls = array();
if (!empty($data['image_urls'])) {
    $tmp = json_decode((string) $data['image_urls'], true);
    if (is_array($tmp)) {
        foreach ($tmp as $u) {
            if (is_string($u) && $u !== '') {
                $img_urls[] = $u;
            }
        }
    }
}
if (empty($img_urls)) {
    $img_urls = array('');
}
?>
<style>
.mlshop-pay-meta { --ml-accent:#7c5cff; --ml-line:#e6e8ef; --ml-soft:#f6f7fb; font-size:13px; }
.mlshop-pay-meta * { box-sizing:border-box; }
.mlshop-pay-meta .ml-pm-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
.mlshop-pay-meta .ml-pm-tab {
    border:1px solid var(--ml-line); background:#fff; color:#3a3f51;
    padding:7px 16px; border-radius:999px; cursor:pointer; font-size:13px; line-height:1.4;
    transition:all .15s ease;
}
.mlshop-pay-meta .ml-pm-tab:hover { border-color:var(--ml-accent); color:var(--ml-accent); }
.mlshop-pay-meta .ml-pm-tab.is-active { background:var(--ml-accent); border-color:var(--ml-accent); color:#fff; }
.mlshop-pay-meta .ml-pm-pane { display:none; border:1px solid var(--ml-line); border-radius:10px; padding:16px; background:var(--ml-soft); }
.mlshop-pay-meta .ml-pm-pane.is-show { display:block; }
.mlshop-pay-meta .ml-pm-pane + .ml-pm-pane { margin-top:12px; }
.mlshop-pay-meta .ml-pm-group + .ml-pm-group { margin-top:18px; padding-top:16px; border-top:1px dashed var(--ml-line); }
.mlshop-pay-meta .ml-pm-hint { margin:0; color:#6b7080; line-height:1.6; }
.mlshop-pay-meta .ml-pm-title { font-weight:600; color:#232838; margin:0 0 10px; font-size:13px; }
.mlshop-pay-meta .ml-pm-row { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
.mlshop-pay-meta .ml-pm-row > label { font-weight:600; color:#3a3f51; }
.mlshop-pay-meta .ml-pm-inline { display:flex; flex-wrap:wrap; gap:14px 20px; align-items:center; }
.mlshop-pay-meta .ml-pm-inline label { display:inline-flex; align-items:center; gap:6px; margin:0; font-weight:400; }
.mlshop-pay-meta input[type=text], .mlshop-pay-meta input[type=number], .mlshop-pay-meta input[type=url] {
    width:100%; max-width:340px; padding:6px 9px; border:1px solid var(--ml-line); border-radius:6px; background:#fff;
}
.mlshop-pay-meta textarea { width:100%; max-width:560px; padding:6px 9px; border:1px solid var(--ml-line); border-radius:6px; background:#fff; }
.mlshop-pay-meta input[type=color] { width:42px; height:32px; padding:2px; border:1px solid var(--ml-line); border-radius:6px; background:#fff; vertical-align:middle; }
.mlshop-pay-meta .ml-pm-note { color:#8a8f9e; font-size:12px; margin:4px 0 0; line-height:1.5; }
.mlshop-pay-meta .ml-pm-suffix { color:#6b7080; margin-left:4px; }
.mlshop-pay-meta .ml-pm-attr-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
.mlshop-pay-meta .ml-pm-attr-row input { flex:1; max-width:none; }
.mlshop-pay-meta .ml-pm-gallery { display:flex; flex-wrap:wrap; gap:10px; margin:10px 0; }
.mlshop-pay-meta .ml-pm-thumb { position:relative; width:92px; height:92px; border:1px solid var(--ml-line); border-radius:8px; overflow:hidden; background:#fff; }
.mlshop-pay-meta .ml-pm-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.mlshop-pay-meta .ml-pm-thumb button { position:absolute; top:3px; right:3px; width:22px; height:22px; line-height:1; border:0; border-radius:50%; background:rgba(220,50,50,.88); color:#fff; cursor:pointer; padding:0; }
.mlshop-pay-meta .ml-pm-cover { width:200px; max-width:100%; border:1px solid var(--ml-line); border-radius:8px; margin-top:8px; display:block; }
.mlshop-pay-meta .ml-pm-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.mlshop-pay-meta .button { cursor:pointer; }
.mlshop-pay-meta .ml-pm-repeater-h { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.mlshop-pay-meta .ml-pm-repeater-h .button { margin-left:auto; }
.mlshop-pay-meta .ml-pm-dl-row, .mlshop-pay-meta .ml-pm-vid-row, .mlshop-pay-meta .ml-pm-url-row {
    padding:10px; border:1px solid var(--ml-line); border-radius:8px; background:#fff; margin-bottom:10px;
}
.mlshop-pay-meta .ml-pm-dl-row { display:flex; flex-direction:column; gap:6px; }
.mlshop-pay-meta .ml-pm-dl-row .ml-pm-dl-url { max-width:none; }
.mlshop-pay-meta .ml-pm-row-inline { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.mlshop-pay-meta .ml-pm-row-inline input { flex:1; min-width:120px; max-width:none; }
.mlshop-pay-meta .ml-pm-vid-row { display:flex; flex-direction:column; gap:6px; }
.mlshop-pay-meta .ml-pm-vid-row .ml-pm-vid-url { max-width:none; }
.mlshop-pay-meta .ml-pm-vid-cover-wrap { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.mlshop-pay-meta .ml-pm-vid-cover-img { width:120px; max-width:100%; border:1px solid var(--ml-line); border-radius:6px; }
.mlshop-pay-meta .ml-pm-url-row { display:flex; gap:8px; align-items:center; }
.mlshop-pay-meta .ml-pm-url-row input { flex:1; max-width:none; }
</style>

<div class="mlshop-pay-meta" data-mode="<?php echo esc_attr($data['pay_mode']); ?>">

    <div class="ml-pm-tabs" role="tablist">
        <?php foreach ($modes as $k => $label) : ?>
            <button type="button" class="ml-pm-tab" data-mode="<?php echo esc_attr($k); ?>" role="tab"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="mlshop_pay_mode" value="<?php echo esc_attr($data['pay_mode']); ?>">

    <!-- 关闭 -->
    <div class="ml-pm-pane" data-pane="off">
        <p class="ml-pm-hint">当前为「关闭」状态，内容将作为普通公开文章发布，不启用付费或会员校验。选择上方其它模式即可开启对应付费能力。</p>
    </div>

    <!-- 通用配置（所有开启模式共有） -->
    <div class="ml-pm-pane ml-pm-common" data-pane="common">

        <div class="ml-pm-group">
            <p class="ml-pm-title">谁能购买</p>
            <div class="ml-pm-inline">
                <?php foreach ($auths as $k => $label) : ?>
                    <label>
                        <input type="radio" name="mlshop_pay_auth" value="<?php echo esc_attr($k); ?>" <?php checked($data['pay_auth'], $k); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="ml-pm-note">设置会员专享后，请确认会员功能已开启且对应等级存在，否则购买入口会异常。</p>
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">付款方式</p>
            <div class="ml-pm-inline">
                <?php foreach ($types as $k => $label) : ?>
                    <label>
                        <input type="radio" name="mlshop_pay_type" value="<?php echo esc_attr($k); ?>" <?php checked($data['pay_type'], $k); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">积分售价 <span class="ml-pm-note" style="display:inline;">（仅「积分商品」付款方式生效）</span></p>
            <div class="ml-pm-row">
                <label for="mlshop_credit_price">标准积分售价</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_credit_price" name="mlshop_credit_price" value="<?php echo esc_attr($data['credit_price']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($credit_name); ?></span></span>
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_credit_price_gold">黄金会员积分售价</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_credit_price_gold" name="mlshop_credit_price_gold" value="<?php echo esc_attr($data['credit_price_gold']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($credit_name); ?></span></span>
                <p class="ml-pm-note">填 0 代表该等级免费获取</p>
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_credit_price_diamond">钻石会员积分售价</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_credit_price_diamond" name="mlshop_credit_price_diamond" value="<?php echo esc_attr($data['credit_price_diamond']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($credit_name); ?></span></span>
                <p class="ml-pm-note">填 0 代表该等级免费获取；会员价不应高于标准价</p>
            </div>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">价格 <span class="ml-pm-note" style="display:inline;">（「普通商品」付款方式生效）</span></p>
            <div class="ml-pm-row">
                <label for="mlshop_price_sell">执行价</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_price_sell" name="mlshop_price_sell" value="<?php echo esc_attr($data['price_sell']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
            </div>
            <div class="ml-pm-row" data-show="read,image,video">
                <label for="mlshop_price_original">原价（划线展示）</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_price_original" name="mlshop_price_original" value="<?php echo esc_attr($data['price_original']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
                <p class="ml-pm-note">展示在执行价之前并划线；会员价不应高于原价</p>
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_price_gold">黄金会员价</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_price_gold" name="mlshop_price_gold" value="<?php echo esc_attr($data['price_gold']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
                <p class="ml-pm-note">填 0 代表该等级免费</p>
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_price_diamond">钻石会员价</label>
                <span><input type="number" step="0.01" min="0" id="mlshop_price_diamond" name="mlshop_price_diamond" value="<?php echo esc_attr($data['price_diamond']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span></span>
                <p class="ml-pm-note">填 0 代表该等级免费</p>
            </div>
            <div class="ml-pm-row" data-show="read">
                <label for="mlshop_free_downloads">免费下载次数（超出后收费）</label>
                <span><input type="number" step="1" min="0" id="mlshop_free_downloads" name="mlshop_free_downloads" value="<?php echo esc_attr($data['free_downloads']); ?>"> 次</span>
                <span style="margin-top:6px;"><input type="number" step="0.01" min="0" name="mlshop_free_download_over_price" value="<?php echo esc_attr($data['free_download_over_price']); ?>"><span class="ml-pm-suffix"><?php echo esc_html($symbol); ?></span> / 次</span>
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_aff_discount">推广折扣</label>
                <span><input type="number" step="0.1" min="0" max="100" id="mlshop_aff_discount" name="mlshop_aff_discount" value="<?php echo esc_attr($data['aff_discount']); ?>"> %</span>
                <p class="ml-pm-note">推广者本人购买时享受的折扣比例</p>
            </div>
        </div>

        <div class="ml-pm-group" data-show="read,download,image,video">
            <p class="ml-pm-title">销售浮动</p>
            <div class="ml-pm-row">
                <label for="mlshop_sales_offset">销量基数偏移</label>
                <input type="number" step="1" id="mlshop_sales_offset" name="mlshop_sales_offset" value="<?php echo esc_attr($data['sales_offset']); ?>">
                <p class="ml-pm-note">在真实销量上增加或减少的数量，避免出现 0 销量</p>
            </div>
        </div>

        <div class="ml-pm-group" data-show="read">
            <p class="ml-pm-title">优惠码</p>
            <label style="font-weight:400;display:inline-flex;align-items:center;gap:6px;">
                <input type="hidden" name="mlshop_allow_coupon" value="0">
                <input type="checkbox" name="mlshop_allow_coupon" value="1" <?php checked($data['allow_coupon'], '1'); ?>>
                允许使用优惠码
            </label>
            <p class="ml-pm-note">开启后结算环节可输入优惠码抵扣（<?php echo esc_html($credit_name); ?>账户中发放）</p>
        </div>

        <div class="ml-pm-group" data-show="read">
            <p class="ml-pm-title">订单时效</p>
            <label style="font-weight:400;display:inline-flex;align-items:center;gap:6px;">
                <input type="hidden" name="mlshop_order_expire_enabled" value="0">
                <input type="checkbox" name="mlshop_order_expire_enabled" value="1" <?php checked($data['order_expire_enabled'], '1'); ?>>
                开启订单时效（到期后需重新购买）
            </label>
            <div class="ml-pm-row" style="margin-top:10px;">
                <label for="mlshop_order_expire_value">有效时长</label>
                <span>
                    <input type="number" step="1" min="0" id="mlshop_order_expire_value" name="mlshop_order_expire_value" value="<?php echo esc_attr($data['order_expire_value']); ?>">
                    <select name="mlshop_order_expire_unit">
                        <?php foreach ($units as $k => $label) : ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($data['order_expire_unit'], $k); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </div>
            <p class="ml-pm-note">建议在正文中提醒用户时效，避免误购投诉</p>
        </div>

        <div class="ml-pm-group" data-show="read">
            <p class="ml-pm-title">商品信息（付费弹窗展示）</p>
            <div class="ml-pm-row">
                <label for="mlshop_pay_popup_title">弹窗标题</label>
                <input type="text" id="mlshop_pay_popup_title" name="mlshop_pay_popup_title" value="<?php echo esc_attr($data['pay_popup_title']); ?>" placeholder="留空则使用文章标题">
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_pay_popup_desc">弹窗描述</label>
                <textarea id="mlshop_pay_popup_desc" name="mlshop_pay_popup_desc" rows="3"><?php echo esc_textarea($data['pay_popup_desc']); ?></textarea>
            </div>
        </div>

    </div>

    <!-- 付费阅读：专属说明 -->
    <div class="ml-pm-pane" data-pane="read">
        <p class="ml-pm-hint">付费阅读即对全文收费，无需额外配置资源。用户购买后展示完整正文，未购买时展示付费墙。</p>
    </div>

    <!-- 付费下载：专属字段 -->
    <div class="ml-pm-pane" data-pane="download">

        <div class="ml-pm-group">
            <p class="ml-pm-title ml-pm-repeater-h">资源清单
                <button type="button" class="button ml-pm-dl-add">+ 新增资源链接</button>
            </p>
            <p class="ml-pm-note" style="margin-top:0;">可添加多个下载地址，每个独立一个下载按钮；未购买时锁定墙不会暴露真实地址。</p>
            <div class="ml-pm-dl-list">
                <?php foreach ($dl_items as $it) : ?>
                    <div class="ml-pm-dl-row">
                        <input type="url" class="ml-pm-dl-url" value="<?php echo esc_attr($it['url']); ?>" placeholder="下载地址 https://...">
                        <div class="ml-pm-row-inline">
                            <input type="text" class="ml-pm-dl-label" value="<?php echo esc_attr($it['label']); ?>" placeholder="按钮文字（留空默认「下载」）">
                            <input type="text" class="ml-pm-dl-copy-name" value="<?php echo esc_attr($it['copy_name']); ?>" placeholder="复制项名称（如 提取码）">
                        </div>
                        <div class="ml-pm-row-inline">
                            <input type="text" class="ml-pm-dl-copy-content" value="<?php echo esc_attr($it['copy_content']); ?>" placeholder="复制内容（如 5942）">
                            <button type="button" class="button ml-pm-dl-del">删除</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="mlshop_download_items" class="ml-pm-dl-val" value="<?php echo esc_attr($data['download_items']); ?>">
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">下载提示</p>
            <div class="ml-pm-row">
                <label for="mlshop_download_note">下载提示</label>
                <input type="text" id="mlshop_download_note" name="mlshop_download_note" value="<?php echo esc_attr($data['download_note']); ?>" placeholder="如：解压密码 5942">
                <p class="ml-pm-note">显示在下载按钮区下方的一段附加说明（提取码、解压密码等）</p>
            </div>
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">按钮外观</p>
            <p class="ml-pm-note" style="margin-top:0;">按钮文字已在每个资源条目内单独设置；以下为所有下载按钮共用的图标与颜色。</p>
            <div class="ml-pm-row">
                <label for="mlshop_download_btn_icon">按钮图标类名</label>
                <input type="text" id="mlshop_download_btn_icon" name="mlshop_download_btn_icon" value="<?php echo esc_attr($data['download_btn_icon']); ?>" placeholder="fa-solid fa-download">
                <p class="ml-pm-note">填写 Font Awesome 图标类名；留空则不显示图标</p>
            </div>
            <div class="ml-pm-row">
                <label for="mlshop_download_btn_color">按钮颜色</label>
                <input type="color" id="mlshop_download_btn_color" name="mlshop_download_btn_color" value="<?php echo esc_attr($data['download_btn_color']); ?>">
            </div>
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">文件信息</p>
            <p class="ml-pm-note" style="margin-top:0;">为下载按钮附加的键值对说明，例如「格式：PDF / 大小：12MB」。</p>
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
            <input type="hidden" name="mlshop_download_attrs" class="ml-pm-attrs-val" value="<?php echo esc_attr($data['download_attrs']); ?>">
        </div>

        <div class="ml-pm-group">
            <p class="ml-pm-title">在线预览</p>
            <div class="ml-pm-row">
                <label for="mlshop_demo_url">演示地址</label>
                <input type="url" id="mlshop_demo_url" name="mlshop_demo_url" value="<?php echo esc_attr($data['demo_url']); ?>" placeholder="https://...">
                <p class="ml-pm-note">用于软件、主题等可在线演示的资源</p>
            </div>
        </div>

    </div>

    <!-- 付费图集：专属字段 -->
    <div class="ml-pm-pane" data-pane="image">
        <div class="ml-pm-group">
            <p class="ml-pm-title">付费图集</p>
            <p class="ml-pm-note" style="margin-top:0;">上传多张图片，前台按序展示。前 N 张可免费查看，其余需付费解锁。</p>
            <div class="ml-pm-gallery" data-target="mlshop_image_gallery">
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
            <input type="hidden" name="mlshop_image_gallery" class="ml-pm-gallery-ids" value="<?php echo esc_attr($data['image_gallery']); ?>">
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
            <input type="hidden" name="mlshop_image_urls" class="ml-pm-url-hidden" value="<?php echo esc_attr($data['image_urls']); ?>">
        </div>
        <div class="ml-pm-group">
            <p class="ml-pm-title">免费展示张数</p>
            <div class="ml-pm-row">
                <label for="mlshop_image_free_count">前几张免费</label>
                <span><input type="number" step="1" min="0" id="mlshop_image_free_count" name="mlshop_image_free_count" value="<?php echo esc_attr($data['image_free_count']); ?>" style="max-width:90px;"> 张</span>
                <p class="ml-pm-note">可免费查看前 N 张；N 不可大于图集总数，否则不生效</p>
            </div>
        </div>
    </div>

    <!-- 付费视频：专属字段 -->
    <div class="ml-pm-pane" data-pane="video">
        <div class="ml-pm-group">
            <p class="ml-pm-title ml-pm-repeater-h">播放列表
                <button type="button" class="button ml-pm-vid-add">+ 新增视频</button>
            </p>
            <p class="ml-pm-note" style="margin-top:0;">可添加多个视频，每个独立一个播放器或观看入口。</p>
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
            <input type="hidden" name="mlshop_video_items" class="ml-pm-vid-val" value="<?php echo esc_attr($data['video_items']); ?>">
        </div>
    </div>

</div>
