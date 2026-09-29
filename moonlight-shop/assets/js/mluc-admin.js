/* 漫步白月光用户中心 — 后台设置页交互（侧栏菜单图标上传 / 实时预览） */
(function ($) {
    'use strict';

    // 每个上传按钮复用同一个 frame，避免反复实例化。
    var mediaFrame = null;

    function ensureFrame($btn) {
        if (mediaFrame) {
            return mediaFrame;
        }
        mediaFrame = wp.media({
            title: $btn.data('mluc-frame-title') || '选择或上传图标',
            button: { text: '使用此图标' },
            library: { type: 'image' },
            multiple: false
        });
        mediaFrame.on('select', function () {
            var attachment = mediaFrame.state().get('selection').first();
            if (!attachment) {
                return;
            }
            var id  = attachment.id;
            var url = '';
            var sizes = attachment.get('sizes');
            if (sizes) {
                if (sizes.thumbnail) {
                    url = sizes.thumbnail.url;
                } else if (sizes.medium) {
                    url = sizes.medium.url;
                } else if (sizes.full) {
                    url = sizes.full.url;
                }
            }
            if (!url) {
                url = attachment.get('url') || '';
            }
            var $row = $btn.closest('tr');
            $row.find('.mluc-icon-image-id').val(id);
            var $thumbBox = $row.find('.mluc-icon-image-preview').empty();
            if (url) {
                $thumbBox.append($('<img>').attr('src', url).attr('alt', ''));
            }
            // 同步「大预览」列（替换 dashicons 占位为图片预览）
            var $big = $row.find('[data-mluc-icon-preview]');
            if ($big.length) {
                $big.removeClass('dashicons').empty();
                $big.attr('class', 'mluc-preview-img');
                $big.append($('<img>').attr('src', url).attr('alt', ''));
            }
        });
        return mediaFrame;
    }

    function setType($row, type) {
        var $dash = $row.find('.mluc-icon-dashicon');
        var $img  = $row.find('.mluc-icon-image');
        if ('image' === type) {
            $dash.attr('hidden', 'hidden');
            $img.removeAttr('hidden');
        } else {
            $img.attr('hidden', 'hidden');
            $dash.removeAttr('hidden');
        }
        // 同步大预览列
        var $big = $row.find('[data-mluc-icon-preview]');
        if (!$big.length) {
            return;
        }
        $big.empty();
        if ('image' === type) {
            var id = parseInt($row.find('.mluc-icon-image-id').val(), 10) || 0;
            var $thumbImg = $row.find('.mluc-icon-image-preview').find('img');
            var src = $thumbImg.attr('src') || '';
            $big.removeClass('dashicons').attr('class', 'mluc-preview-img');
            if (id && !src) {
                // 没有本地缩略图就保留空（后端保存后会刷新页面拿到正确图）
                return;
            }
            if (src) {
                $big.append($('<img>').attr('src', src).attr('alt', ''));
            }
        } else {
            var val = $.trim($row.find('.mluc-icon-input').val());
            $big.attr('class', 'dashicons ' + (val || 'dashicons-minus'));
        }
    }

    $(function () {
        // 1. radio 切换（jQuery 委托）
        $(document).on('change', '.mluc-icon-type input[type="radio"]', function () {
            var $r = $(this);
            var type = $r.val();
            setType($r.closest('tr'), type);
        });

        // 2. dashicons 类输入实时预览（保留原行为）
        $(document).on('input', '.mluc-icon-input', function () {
            var $input = $(this);
            var $row = $input.closest('tr');
            // 仅当当前是 dashicon 模式才更新大预览
            var $dashRadio = $row.find('.mluc-icon-type input[value="dashicon"]:checked');
            if (!$dashRadio.length) {
                return;
            }
            var $preview = $row.find('[data-mluc-icon-preview]');
            if (!$preview.length) {
                return;
            }
            var val = $.trim($input.val());
            $preview.attr('class', 'dashicons ' + (val || 'dashicons-minus'));
        });

        // 3. 点击「上传/选择图片」
        $(document).on('click', '.mluc-icon-upload', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var frame = ensureFrame($btn);
            frame.open();
        });

        // 4. 点击「移除」
        $(document).on('click', '.mluc-icon-remove', function (e) {
            e.preventDefault();
            var $row = $(this).closest('tr');
            $row.find('.mluc-icon-image-id').val(0);
            $row.find('.mluc-icon-image-preview').empty();
            var $big = $row.find('[data-mluc-icon-preview]');
            if ($big.length) {
                $big.removeClass('mluc-preview-img').empty();
                $big.attr('class', 'dashicons dashicons-minus');
            }
        });
    });

    /* ===== 设置页美化：把每个 section 区块包成卡片，并生成吸顶导航 ===== */
    function mlucSettingsBeautify() {
        var $wrap = $('.mluc-settings-wrap');
        if (!$wrap.length || $wrap.data('mluc-beautified')) {
            return;
        }
        $wrap.data('mluc-beautified', 1);

        var $form = $wrap.children('form').eq(0);
        if (!$form.length) {
            return;
        }

        var $nav = $('<nav>', { 'class': 'mluc-settings-nav' })
            .append('<div class="mluc-settings-nav-title">' + (window.mlucSettingsNavTitle || '页面导航') + '</div>')
            .append('<ul class="mluc-settings-nav-list"></ul>');
        var $list = $nav.find('ul');

        var idx = 0;
        $form.children('h2').each(function () {
            idx++;
            var $h = $(this);
            var id = 'mluc-sec-' + idx;
            $h.attr('id', id).addClass('mluc-card-title');
            var $sec = $('<section>', { 'class': 'mluc-card' });
            $h.nextUntil('h2').addBack().wrapAll($sec);
            var label = $.trim($h.text());
            $('<li>').append($('<a>', { href: '#' + id, text: label })).appendTo($list);
        });

        $wrap.addClass('mluc-settings-layout');
        $wrap.prepend($nav);

        var $links = $list.find('a');
        function onScroll() {
            var pos = window.scrollY + 120;
            var current = $links.first();
            $form.children('.mluc-card').each(function () {
                if ($(this).offset().top <= pos) {
                    var cid = $(this).children('.mluc-card-title').attr('id');
                    if (cid) {
                        current = $links.filter('[href="#' + cid + '"]');
                    }
                }
            });
            $links.removeClass('active');
            current.addClass('active');
        }
        $(window).on('scroll', onScroll).trigger('scroll');
    }

    $(function () {
        if ($('.mluc-settings-wrap').length) {
            mlucSettingsBeautify();
        }
    });
})(jQuery);