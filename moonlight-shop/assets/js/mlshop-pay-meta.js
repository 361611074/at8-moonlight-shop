/* 漫步白月光电子商城 —— 后台「付费功能」Meta Box 交互
 * 触发条件：文章 / 页面 / 商品 (post / page / mlshop_product) 编辑器
 *
 * 覆盖能力：
 *   1. 顶部 tab 切换（关闭 / 付费阅读 / 付费下载 / 付费图片 / 付费视频）
 *   2. data-show 条件显隐（基于当前 mode 控制 .ml-pm-group / .ml-pm-row）
 *   3. 4 个 repeater：下载资源 / 视频 / 外链图片 / 文件信息（KV 对）
 *      - 新增 / 删除行
 *      - 同步到 hidden input 的 JSON 值（提交时即写库）
 *   4. 付费图集（多图选择）：wp.media 多选图片，写入 CSV attachment ids
 *   5. 视频封面：每行独立 wp.media 单选图片
 *
 * 设计：jQuery IIFE（与 mlshop-admin.js 一致）；只在 .mlshop-pay-meta 存在时初始化。
 * 严格零 !important；零 woocommerce / WC_ 依赖。
 */
(function ($) {
    'use strict';

    // 仅在付费功能 Meta Box 实际存在时初始化
    $(function () {
        var $roots = $('.mlshop-pay-meta');
        if (!$roots.length) {
            return;
        }
        $roots.each(function () {
            initPayMeta($(this));
        });
    });

    function initPayMeta($root) {
        var currentMode = ($root.attr('data-mode') || 'off').toString();

        // === 1. Tab 切换 ===
        $root.on('click', '.ml-pm-tab', function (e) {
            e.preventDefault();
            var mode = ($(this).attr('data-mode') || '').toString();
            if (!mode) {
                return;
            }
            setMode($root, mode);
        });

        // === 2. data-show 条件显隐（基于当前 mode） ===
        function applyDataShow(mode) {
            $root.find('[data-show]').each(function () {
                var list = ($(this).attr('data-show') || '').toString();
                var tokens = list.split(',').map(function (s) { return s.trim(); });
                var visible = tokens.indexOf(mode) !== -1;
                $(this).toggle(visible);
            });
        }

        // === 3. Repeater: 下载资源 ===
        $root.on('click', '.ml-pm-dl-add', function (e) {
            e.preventDefault();
            var $list = $root.find('.ml-pm-dl-list');
            var tpl = '' +
                '<div class="ml-pm-dl-row">' +
                '<input type="url" class="ml-pm-dl-url" value="" placeholder="下载地址 https://...">' +
                '<div class="ml-pm-row-inline">' +
                '<input type="text" class="ml-pm-dl-label" value="" placeholder="按钮文字（留空默认「下载」）">' +
                '<input type="text" class="ml-pm-dl-copy-name" value="" placeholder="复制项名称（如 提取码）">' +
                '</div>' +
                '<div class="ml-pm-row-inline">' +
                '<input type="text" class="ml-pm-dl-copy-content" value="" placeholder="复制内容（如 5942）">' +
                '<button type="button" class="button ml-pm-dl-del">删除</button>' +
                '</div>' +
                '</div>';
            $list.append(tpl);
            syncDlVal($root);
        });
        $root.on('click', '.ml-pm-dl-del', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.ml-pm-dl-row');
            // 至少保留 1 行，避免空状态把字段值也清空
            if ($root.find('.ml-pm-dl-row').length <= 1) {
                $row.find('input').val('');
            } else {
                $row.remove();
            }
            syncDlVal($root);
        });
        $root.on('input change', '.ml-pm-dl-row input', function () {
            syncDlVal($root);
        });

        function syncDlVal($root) {
            var arr = [];
            $root.find('.ml-pm-dl-row').each(function () {
                var $r = $(this);
                arr.push({
                    url: $r.find('.ml-pm-dl-url').val() || '',
                    label: $r.find('.ml-pm-dl-label').val() || '',
                    copy_name: $r.find('.ml-pm-dl-copy-name').val() || '',
                    copy_content: $r.find('.ml-pm-dl-copy-content').val() || ''
                });
            });
            // 全空则存空字符串，避免无意义数据
            var hasAny = arr.some(function (it) {
                return it.url || it.label || it.copy_name || it.copy_content;
            });
            $root.find('.ml-pm-dl-val').val(hasAny ? JSON.stringify(arr) : '');
        }

        // === 4. Repeater: 视频播放列表 ===
        $root.on('click', '.ml-pm-vid-add', function (e) {
            e.preventDefault();
            var $list = $root.find('.ml-pm-vid-list');
            var tpl = '' +
                '<div class="ml-pm-vid-row" data-cover="0">' +
                '<input type="url" class="ml-pm-vid-url" value="" placeholder="视频 URL（mp4 / m3u8 / 第三方嵌入）">' +
                '<div class="ml-pm-row-inline">' +
                '<input type="text" class="ml-pm-vid-title" value="" placeholder="标题（可选）">' +
                '<button type="button" class="button ml-pm-vid-del">删除</button>' +
                '</div>' +
                '<div class="ml-pm-vid-cover-wrap">' +
                '<button type="button" class="button ml-pm-vid-cover">选择封面</button>' +
                '<button type="button" class="button ml-pm-vid-cover-del">移除</button>' +
                '</div>' +
                '</div>';
            $list.append(tpl);
            syncVidVal($root);
        });
        $root.on('click', '.ml-pm-vid-del', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.ml-pm-vid-row');
            if ($root.find('.ml-pm-vid-row').length <= 1) {
                $row.find('input[type=url], input[type=text]').val('');
                $row.attr('data-cover', '0');
                $row.find('.ml-pm-vid-cover-img').remove();
            } else {
                $row.remove();
            }
            syncVidVal($root);
        });
        $root.on('click', '.ml-pm-vid-cover', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.ml-pm-vid-row');
            openCoverPicker($row);
        });
        $root.on('click', '.ml-pm-vid-cover-del', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.ml-pm-vid-row');
            $row.attr('data-cover', '0');
            $row.find('.ml-pm-vid-cover-img').remove();
            syncVidVal($root);
        });
        $root.on('input change', '.ml-pm-vid-row input', function () {
            syncVidVal($root);
        });

        function syncVidVal($root) {
            var arr = [];
            $root.find('.ml-pm-vid-row').each(function () {
                var $r = $(this);
                arr.push({
                    url: $r.find('.ml-pm-vid-url').val() || '',
                    cover: parseInt($r.attr('data-cover') || '0', 10) || 0,
                    title: $r.find('.ml-pm-vid-title').val() || ''
                });
            });
            var hasAny = arr.some(function (it) { return it.url || it.cover || it.title; });
            $root.find('.ml-pm-vid-val').val(hasAny ? JSON.stringify(arr) : '');
        }

        function openCoverPicker($row) {
            if (typeof wp === 'undefined' || !wp.media) { return; }
            var frame = wp.media({
                title: '选择视频封面',
                button: { text: '使用此封面' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                $row.attr('data-cover', String(att.id || 0));
                var url = (att.sizes && att.sizes.medium && att.sizes.medium.url) || att.url || '';
                $row.find('.ml-pm-vid-cover-img').remove();
                if (url) {
                    $row.find('.ml-pm-vid-cover-wrap').append(
                        '<img class="ml-pm-vid-cover-img" src="' + escapeAttr(url) + '" alt="">'
                    );
                }
                syncVidVal($root);
            });
            frame.open();
        }

        // === 5. Repeater: 外链图片（资源链接地址） ===
        $root.on('click', '.ml-pm-url-add', function (e) {
            e.preventDefault();
            var $list = $root.find('.ml-pm-url-list');
            $list.append(
                '<div class="ml-pm-url-row">' +
                '<input type="url" class="ml-pm-url-input" value="" placeholder="https://... 图片直链">' +
                '<button type="button" class="button ml-pm-url-del">删除</button>' +
                '</div>'
            );
            syncUrlVal($root);
        });
        $root.on('click', '.ml-pm-url-del', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.ml-pm-url-row');
            if ($root.find('.ml-pm-url-row').length <= 1) {
                $row.find('input').val('');
            } else {
                $row.remove();
            }
            syncUrlVal($root);
        });
        $root.on('input change', '.ml-pm-url-row input', function () {
            syncUrlVal($root);
        });

        function syncUrlVal($root) {
            var arr = [];
            $root.find('.ml-pm-url-row .ml-pm-url-input').each(function () {
                var v = ($(this).val() || '').toString().trim();
                if (v) { arr.push(v); }
            });
            $root.find('.ml-pm-url-hidden').val(arr.length ? JSON.stringify(arr) : '');
        }

        // === 6. Repeater: 文件信息（键值对） ===
        $root.on('click', '.ml-pm-attr-add', function (e) {
            e.preventDefault();
            var $wrap = $root.find('.ml-pm-attrs');
            $wrap.append(
                '<div class="ml-pm-attr-row">' +
                '<input type="text" class="ml-pm-attr-k" value="" placeholder="键（如 文件格式）">' +
                '<input type="text" class="ml-pm-attr-v" value="" placeholder="值（如 PDF）">' +
                '<button type="button" class="button ml-pm-attr-del">删除</button>' +
                '</div>'
            );
            syncAttrsVal($root);
        });
        $root.on('click', '.ml-pm-attr-del', function (e) {
            e.preventDefault();
            var $row = $(this).closest('.ml-pm-attr-row');
            if ($root.find('.ml-pm-attr-row').length <= 1) {
                $row.find('input').val('');
            } else {
                $row.remove();
            }
            syncAttrsVal($root);
        });
        $root.on('input change', '.ml-pm-attr-row input', function () {
            syncAttrsVal($root);
        });

        function syncAttrsVal($root) {
            var arr = [];
            $root.find('.ml-pm-attr-row').each(function () {
                var $r = $(this);
                var k = ($r.find('.ml-pm-attr-k').val() || '').toString().trim();
                var v = ($r.find('.ml-pm-attr-v').val() || '').toString().trim();
                if (k) { arr.push([k, v]); }
            });
            $root.find('.ml-pm-attrs-val').val(arr.length ? JSON.stringify(arr) : '');
        }

        // === 7. 付费图集（多图） ===
        $root.on('click', '.ml-pm-gallery-add', function (e) {
            e.preventDefault();
            openGalleryPicker($root, true);
        });
        $root.on('click', '.ml-pm-gallery-edit', function (e) {
            e.preventDefault();
            openGalleryPicker($root, false);
        });
        $root.on('click', '.ml-pm-gallery-clear', function (e) {
            e.preventDefault();
            $root.find('.ml-pm-gallery').empty();
            $root.find('.ml-pm-gallery-ids').val('');
        });
        $root.on('click', '.ml-pm-remove', function (e) {
            e.preventDefault();
            var $thumb = $(this).closest('.ml-pm-thumb');
            $thumb.remove();
            syncGalleryIds($root);
        });

        function openGalleryPicker($root, isAdd) {
            if (typeof wp === 'undefined' || !wp.media) { return; }
            var existing = currentGalleryIds($root);
            var frame = wp.media({
                title: isAdd ? '新增图片' : '编辑图集',
                button: { text: isAdd ? '加入图集' : '使用此图集' },
                library: { type: 'image' },
                multiple: true
            });
            frame.on('open', function () {
                if (!isAdd && existing.length) {
                    var sel = frame.state().get('selection');
                    existing.forEach(function (id) {
                        var att = wp.media.attachment(id);
                        att.fetch();
                        sel.add(att);
                    });
                }
            });
            frame.on('select', function () {
                var ids = [];
                frame.state().get('selection').each(function (m) { ids.push(m.id); });
                if (!isAdd) {
                    // 编辑模式：替换为当前选择（多选）
                    $root.find('.ml-pm-gallery').empty();
                }
                ids.forEach(function (id) {
                    var att = wp.media.attachment(id);
                    att.fetch().then(function (model) {
                        var url = model.get('url');
                        var sizes = model.get('sizes');
                        if (sizes && sizes.thumbnail) { url = sizes.thumbnail.url; }
                        else if (sizes && sizes.medium) { url = sizes.medium.url; }
                        $root.find('.ml-pm-gallery').append(
                            '<div class="ml-pm-thumb" data-id="' + id + '">' +
                            '<img src="' + escapeAttr(url) + '" alt="">' +
                            '<button type="button" class="ml-pm-remove" aria-label="删除">×</button>' +
                            '</div>'
                        );
                    });
                });
                $root.find('.ml-pm-gallery-ids').val(ids.join(','));
            });
            frame.open();
        }

        function currentGalleryIds($root) {
            var v = ($root.find('.ml-pm-gallery-ids').val() || '').toString();
            if (!v) { return []; }
            return v.split(',').map(function (s) { return parseInt(s.trim(), 10); }).filter(function (n) { return n > 0; });
        }

        function syncGalleryIds($root) {
            var ids = [];
            $root.find('.ml-pm-gallery .ml-pm-thumb').each(function () {
                var id = parseInt($(this).attr('data-id') || '0', 10);
                if (id > 0) { ids.push(id); }
            });
            $root.find('.ml-pm-gallery-ids').val(ids.join(','));
        }

        // === 初始化：按 data-mode 应用 tab/pane 状态 ===
        setMode($root, currentMode);

        // 工具：把 URL 安全塞进 HTML 属性
        function escapeAttr(s) {
            return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        }
    }

    // 集中控制：切 mode、激活 tab、显隐 pane、刷新 data-show
    function setMode($root, mode) {
        mode = (mode || 'off').toString();
        $root.attr('data-mode', mode);
        $root.find('input[name="mlshop_pay_mode"]').val(mode);

        // tab 视觉
        $root.find('.ml-pm-tab').removeClass('is-active').attr('aria-selected', 'false');
        $root.find('.ml-pm-tab[data-mode="' + mode + '"]').addClass('is-active').attr('aria-selected', 'true');

        // pane 显隐：off → 只显示 off；其它 → 显示 common + 对应模式
        $root.find('.ml-pm-pane').removeClass('is-show');
        if (mode === 'off') {
            $root.find('.ml-pm-pane[data-pane="off"]').addClass('is-show');
        } else {
            $root.find('.ml-pm-pane[data-pane="common"]').addClass('is-show');
            $root.find('.ml-pm-pane[data-pane="' + mode + '"]').addClass('is-show');
        }

        // data-show 条件
        $root.find('[data-show]').each(function () {
            var list = ($(this).attr('data-show') || '').toString();
            var tokens = list.split(',').map(function (s) { return s.trim(); });
            $(this).toggle(tokens.indexOf(mode) !== -1);
        });
    }
})(jQuery);
