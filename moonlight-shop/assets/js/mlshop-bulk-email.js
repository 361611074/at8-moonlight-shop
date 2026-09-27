/* 漫步白月光电子商城 —— 群发邮件交互（渐进发送 / 收件人切换 / 占位符插入） */
(function ($) {
    'use strict';

    function gather() {
        return {
            recipient_type: $('input[name="mlshop_recipient_type"]:checked').val(),
            product_id: $('#mlshop_bulk_product').val(),
            level: $('#mlshop_bulk_level').val(),
            emails: $('#mlshop_bulk_emails').val(),
            subject: $('#mlshop_bulk_subject').val(),
            body: $('#mlshop_bulk_body').val(),
            from_name: $('#mlshop_bulk_from_name').val(),
            from_email: $('#mlshop_bulk_from_email').val()
        };
    }

    function showResult(ok, msg) {
        var $r = $('#mlshop_bulk_result');
        $r.removeClass('ok err').addClass(ok ? 'ok' : 'err').addClass('show').text(msg);
    }

    function togglePanes() {
        var type = $('input[name="mlshop_recipient_type"]:checked').val();
        $('.mlshop-recipient-pane').each(function () {
            var $p = $(this);
            if ($p.data('pane') === type) {
                $p.removeAttr('hidden');
            } else {
                $p.attr('hidden', 'hidden');
            }
        });
    }

    function insertAtCursor($el, text) {
        var el = $el[0];
        if (!el) {
            return;
        }
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;
        var val = $el.val();
        $el.val(val.slice(0, start) + text + val.slice(end));
        var pos = start + text.length;
        el.setSelectionRange(pos, pos);
        $el.trigger('input');
    }

    $(function () {
        togglePanes();
        $(document).on('change', 'input[name="mlshop_recipient_type"]', togglePanes);

        // 占位符插入
        $(document).on('click', '.mlshop-vars code[data-var]', function () {
            insertAtCursor($('#mlshop_bulk_body'), $(this).data('var'));
        });

        // 测试发送
        $('#mlshop_bulk_test').on('click', function () {
            var d = gather();
            if (!d.subject || !d.body) {
                showResult(false, mlshopBulkL10n && mlshopBulkL10n.fill_required || '请填写主题与正文。');
                return;
            }
            var $btn = $(this).prop('disabled', true);
            $.post(mlshopBulk.ajax_url, {
                action: 'mlshop_bulk_email_test',
                nonce: mlshopBulk.nonce,
                subject: d.subject,
                body: d.body,
                from_name: d.from_name,
                from_email: d.from_email
            }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    showResult(true, res.data.msg);
                } else {
                    showResult(false, typeof res.data === 'string' ? res.data : (mlshopBulkL10n && mlshopBulkL10n.test_failed || '测试发送失败。'));
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                showResult(false, mlshopBulkL10n && mlshopBulkL10n.test_failed || '测试发送失败。');
            });
        });

        // 群发
        var sending = false;
        $('#mlshop_bulk_send').on('click', function () {
            if (sending) {
                return;
            }
            var d = gather();
            if (!d.subject || !d.body) {
                showResult(false, mlshopBulkL10n && mlshopBulkL10n.fill_required || '请填写主题与正文。');
                return;
            }
            sending = true;
            var $btn = $(this).prop('disabled', true);
            $('#mlshop_bulk_progress').removeAttr('hidden');
            $('#mlshop_bulk_progress_bar').width('0%');
            $('#mlshop_bulk_progress_text').text(mlshopBulkL10n && mlshopBulkL10n.preparing || '正在准备收件人…');

            $.post(mlshopBulk.ajax_url, $.extend({ action: 'mlshop_bulk_email_prepare', nonce: mlshopBulk.nonce }, d), function (res) {
                if (!res.success || !res.data || !res.data.total) {
                    sending = false;
                    $btn.prop('disabled', false);
                    $('#mlshop_bulk_progress').attr('hidden', 'hidden');
                    showResult(false, mlshopBulkL10n && mlshopBulkL10n.no_recipients || '没有匹配的收件人，请检查选择。');
                    return;
                }
                var total = res.data.total;
                $('#mlshop_recipient_count').text(total + ' ' + ((mlshopBulkL10n && mlshopBulkL10n.recipients) || '位收件人'));
                step(total);
            }).fail(function () {
                sending = false;
                $btn.prop('disabled', false);
                $('#mlshop_bulk_progress').attr('hidden', 'hidden');
                showResult(false, mlshopBulkL10n && mlshopBulkL10n.prepare_failed || '准备失败。');
            });

            function step(total) {
                $.post(mlshopBulk.ajax_url, {
                    action: 'mlshop_bulk_email_step',
                    nonce: mlshopBulk.nonce
                }, function (res) {
                    if (!res.success || !res.data) {
                        sending = false;
                        $btn.prop('disabled', false);
                        $('#mlshop_bulk_progress').attr('hidden', 'hidden');
                        showResult(false, mlshopBulkL10n && mlshopBulkL10n.send_failed || '发送失败。');
                        return;
                    }
                    var pct = total ? Math.round((res.data.done / total) * 100) : 100;
                    $('#mlshop_bulk_progress_bar').width(pct + '%');
                    $('#mlshop_bulk_progress_text').text(
                        res.data.done + ' / ' + total + ' ' + ((mlshopBulkL10n && mlshopBulkL10n.processed) || '已处理') +
                        '（' + ((mlshopBulkL10n && mlshopBulkL10n.success) || '成功') + ' ' + res.data.sent +
                        '，' + ((mlshopBulkL10n && mlshopBulkL10n.failed) || '失败') + ' ' + res.data.failed + '）'
                    );
                    if (res.data.finished) {
                        sending = false;
                        $btn.prop('disabled', false);
                        showResult(true, (mlshopBulkL10n && mlshopBulkL10n.done_prefix || '群发完成：成功') + ' ' + res.data.sent +
                            ' ' + ((mlshopBulkL10n && mlshopBulkL10n.done_suffix) || '封') +
                            (res.data.failed ? '，' + ((mlshopBulkL10n && mlshopBulkL10n.fail_suffix) || '失败') + ' ' + res.data.failed : ''));
                    } else {
                        step(total);
                    }
                }).fail(function () {
                    sending = false;
                    $btn.prop('disabled', false);
                    $('#mlshop_bulk_progress').attr('hidden', 'hidden');
                    showResult(false, mlshopBulkL10n && mlshopBulkL10n.send_failed || '发送失败。');
                });
            }
        });
    });
})(jQuery);
