/* 漫步白月光用户中心 前端交互 */
(function ($) {
    'use strict';

    var i18n = (typeof mluc_i18n !== 'undefined') ? mluc_i18n : {};

    function showMsg($form, text, ok) {
        var $msg = $form.find('.mluc-msg').length ? $form.find('.mluc-msg') : $form.siblings('.mluc-msg');
        if (!$msg.length) {
            $msg = $('<p class="mluc-msg" role="alert"></p>');
            $form.prepend($msg);
        }
        $msg.removeClass('mluc-ok mluc-error').addClass(ok ? 'mluc-ok' : 'mluc-error').text(text).show();
    }

    function doSubmit($form) {
        var action = $form.data('action');
        if (!action) {
            return;
        }
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true);

        var data = $form.serializeArray();
        data.push({ name: 'action', value: 'mluc_' + action });
        data.push({ name: 'nonce', value: MLUC.nonce });

        $.post(MLUC.ajax_url, $.param(data), function (res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                showMsg($form, res.message, true);
                if (res.data && res.data.redirect) {
                    setTimeout(function () { window.location.href = res.data.redirect; }, 800);
                } else {
                    setTimeout(function () { location.reload(); }, 800);
                }
            } else {
                showMsg($form, (res && res.message) ? res.message : (i18n.op_failed || '操作失败'), false);
            }
        }, 'json').fail(function () {
            $btn.prop('disabled', false);
            showMsg($form, i18n.network_error_retry || '网络错误，请重试', false);
        });
    }

    $(function () {
        $(document).on('submit', '.mluc-form', function (e) {
            e.preventDefault();
            doSubmit($(this));
        });

        // 头像选择（点击 grid 中的头像立即切换）
        $(document).on('click', '[data-mluc-pick-avatar]', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.prop('disabled') || $btn.hasClass('is-busy')) {
                return;
            }
            var avatarId = parseInt($btn.data('avatar-id'), 10) || 0;
            if (!avatarId) {
                return;
            }
            var $msg = $('#mluc_avatar_msg');
            var $preview = $('#mluc_avatar_preview');
            var $group = $btn.closest('.mluc-avatar-picker');
            $btn.addClass('is-busy').prop('disabled', true);
            if ($msg.length) {
                $msg.removeClass('is-error').text(i18n.switching || '切換中…');
            }

            $.post(MLUC.ajax_url, {
                action: 'mluc_select_avatar',
                nonce: MLUC.nonce,
                avatar_id: avatarId
            }, function (res) {
                $btn.removeClass('is-busy').prop('disabled', false);
                if (res && res.success && res.data && res.data.url) {
                    if ($preview.length) {
                        $preview.attr('src', res.data.url);
                    }
                    if ($group.length) {
                        $group.find('.mluc-avatar-option').removeClass('is-selected').attr('aria-checked', 'false');
                        $btn.addClass('is-selected').attr('aria-checked', 'true');
                    }
                    if ($msg.length) {
                        $msg.removeClass('is-error').text(res.message || (i18n.avatar_updated || '頭像已更新'));
                    }
                } else {
                    if ($msg.length) {
                        $msg.addClass('is-error').text((res && res.message) ? res.message : (i18n.op_failed || '操作失败'));
                    }
                }
            }, 'json').fail(function () {
                $btn.removeClass('is-busy').prop('disabled', false);
                if ($msg.length) {
                    $msg.addClass('is-error').text(i18n.network_error || '網絡錯誤');
                }
            });
        });

        // 后台「侧栏菜单图标」实时预览由 mluc-admin.js 处理（避免双绑定）。
    });
})(jQuery);
