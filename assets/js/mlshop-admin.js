/* 漫步白月光电子商城 —— 后台商品相册上传器 */
(function ($) {
    'use strict';

    $(function () {
        var $input = $('#mlshop_gallery');
        if (!$input.length) {
            return;
        }
        var $preview = $('#mlshop_gallery_preview');
        var $btn = $('#mlshop_gallery_btn');
        var frame;

        function currentIds() {
            return ($input.val() || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        }

        function renderPreview() {
            $preview.html('');
            currentIds().forEach(function (id) {
                var att = wp.media.attachment(id);
                att.fetch({
                    success: function (model) {
                        var url = model.get('url');
                        var sizes = model.get('sizes');
                        if (sizes && sizes.thumbnail) {
                            url = sizes.thumbnail.url;
                        } else if (sizes && sizes.full) {
                            url = sizes.full.url;
                        }
                        if (url) {
                            $('<img>', { src: url, 'data-id': id, title: '点击移除' }).appendTo($preview);
                        }
                    }
                });
            });
        }

        $btn.on('click', function (e) {
            e.preventDefault();
            if (frame) {
                frame.open();
                return;
            }
            frame = wp.media({
                title: (window.MLSHOP && MLSHOP.select_gallery) ? MLSHOP.select_gallery : '选择商品相册',
                button: { text: (window.MLSHOP && MLSHOP.confirm_album) ? MLSHOP.confirm_album : '确认相册' },
                multiple: true,
                library: { type: 'image' }
            });

            frame.on('open', function () {
                var sel = frame.state().get('selection');
                sel.reset();
                currentIds().forEach(function (id) {
                    var att = wp.media.attachment(id);
                    att.fetch();
                    sel.add(att);
                });
            });

            frame.on('select', function () {
                var sel = frame.state().get('selection');
                var ids = [];
                sel.each(function (att) { ids.push(att.id); });
                $input.val(ids.join(','));
                renderPreview();
            });

            frame.open();
        });

        $preview.on('click', 'img', function () {
            var id = $(this).data('id').toString();
            var ids = currentIds().filter(function (x) { return x !== id; });
            $input.val(ids.join(','));
            renderPreview();
        });

        // 服务端已渲染过缩略图时跳过首次重绘，避免图片闪烁
        if (!$preview.children('img').length) {
            renderPreview();
        }
    });

    /* ===== 设置页美化：把每个 <h2> 区块包成卡片，并接管 nav 链接做平滑滚动 =====
     *
     * 历史坑（2026-08-30 修复）：
     * 旧实现强制覆盖 h2 id 为 mlshop-sec-1/2/3... 顺序编号，又 prepend 一个新 nav，
     * 但 PHP 端已渲染的旧 nav 还在页面上未删除 → 用户点击旧 nav 的「列表顯示」
     * （href=#mlshop-sec-list 语义 id）时，h2 id 已被改写成 #mlshop-sec-5，
     * 锚点找不到目标 + form[display:flex] + html{scroll-behavior:smooth} 组合下
     * 浏览器对原生 anchor 跳转行为不一致 → 表现为「点击不跳转」。
     *
     * 新规则：信任 PHP 端已渲染的 nav 与有语义 id（#mlshop-sec-list / -slugs / -archive
     * 等），JS 只做三件事：① 把 h2+紧随 table 包成 section（视觉）；
     * ② 拦截 nav 点击，强制 scrollIntoView，绕开 flex/grid + scroll-behavior:smooth 的
     *    兼容性坑；③ 滚动监听高亮当前 section 对应 nav。绝不覆盖 id，绝不新建 nav。
     */
    function mlshopSettingsBeautify() {
        var $wrap = $('.mlshop-admin-settings');
        if (!$wrap.length || $wrap.data('mlshop-beautified')) {
            return;
        }
        $wrap.data('mlshop-beautified', 1);

        var $form = $wrap.children('form').eq(0);
        if (!$form.length) {
            return;
        }

        // ① 把 h2 + 紧随的 siblings（直到下一个 h2）包成 section，已包过则跳过
        $form.children('h2.mlshop-card-title').each(function () {
            var $h = $(this);
            if ($h.parent().is('section.mlshop-card')) {
                return;
            }
            $h.nextUntil('h2').addBack().wrapAll($('<section>', { 'class': 'mlshop-card' }));
        });

        // 接管 PHP 已渲染的 nav（不再新建 nav，不再覆盖 id）
        var $nav = $wrap.children('nav.mlshop-settings-nav').first();
        if (!$nav.length) {
            $nav = $wrap.find('nav.mlshop-settings-nav').first();
        }
        var $links = $nav.find('a[href^="#mlshop-sec-"]');

        // ② 拦截 nav 点击：preventDefault + 显式 scrollIntoView + 更新 hash
        $links.on('click', function (e) {
            var hash = this.getAttribute('href') || '';
            if (hash.charAt(0) !== '#') { return; }
            var id = hash.substring(1);
            var tgt = document.getElementById(id);
            if (!tgt) { return; } // 找不到目标，回退到原生 anchor 行为
            e.preventDefault();
            try {
                tgt.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (err) {
                tgt.scrollIntoView(); // 老浏览器 fallback
            }
            if (window.history && history.replaceState) {
                history.replaceState(null, '', hash);
            }
            $links.removeClass('active');
            $(this).addClass('active');
        });

        // ③ 滚动高亮当前 section
        function onScroll() {
            var pos = window.scrollY + 120;
            var currentId = '';
            $form.children('section.mlshop-card').each(function () {
                if ($(this).offset().top <= pos) {
                    var $h2 = $(this).children('h2').first();
                    if ($h2.length) { currentId = $h2.attr('id') || ''; }
                }
            });
            if (!currentId) { return; }
            $links.removeClass('active');
            $links.filter('[href="#' + currentId + '"]').addClass('active');
        }
        $(window).on('scroll', onScroll).trigger('scroll');
    }

    $(function () {
        if ($('.mlshop-admin-settings').length) {
            mlshopSettingsBeautify();
        }
    });
})(jQuery);

/* === 漫步白月光电子商城 —— 颜色选择器（商品价格等设置） === */
(function ($) {
    'use strict';
    $(function () {
        if ($.fn.wpColorPicker) {
            $('.mlshop-color-field').wpColorPicker();
        }
    });
})(jQuery);
