/* 漫步白月光电子商城 前端交互 */
(function ($) {
    'use strict';

    function post(action, data, cb) {
        data = data || {};
        data.action = action;
        data.nonce = MLSHOP.nonce;
        $.post(MLSHOP.ajax_url, $.param(data), function (res) {
            cb(res);
        }, 'json').fail(function () {
            cb({ success: false, message: mlshop_i18n.network_error });
        });
    }

    function reloadCart() {
        if ($('.mlshop-cart').length) {
            location.reload();
        }
    }

    // 页眉购物车 / 收藏角标实时更新
    function mlshop_set_header_count(type, n) {
        var $badge = $('.mlshop-header-' + type + '-count');
        if (!$badge.length) {
            return;
        }
        n = parseInt(n, 10) || 0;
        $badge.text(n > 0 ? n : '');
        $badge.toggleClass('is-empty', n <= 0);
    }

    /**
     * 实时刷新侧栏「我的收藏」widget 内部内容。
     * 服务端 fragment 与 widget() 渲染走同一份代码（mlshop_render_favorites_widget_inner），
     * 保证切换收藏后无需刷新整页，sidebar 立刻同步标题角标 + 列表项 / empty 状态。
     */
    function mlshop_refresh_sidebar_favorites() {
        var $widgets = $('.mlshop-widget.mlshop-widget-favorites');
        if (!$widgets.length) {
            return;
        }
        post('mlshop_get_favorites_widget_fragment', {}, function (res) {
            if (res && res.success && res.data && typeof res.data.html === 'string') {
                $widgets.html(res.data.html);
                // 顺手把 header 角标也对齐一次，防止两路计数偶发偏差
                if (typeof res.data.count !== 'undefined') {
                    mlshop_set_header_count('fav', res.data.count);
                }
            }
        });
    }

    function mlshop_sync_header_counts() {
        post('mlshop_get_counts', {}, function (res) {
            if (res.success && res.data) {
                mlshop_set_header_count('cart', res.data.cart);
                mlshop_set_header_count('fav', res.data.fav);
            }
        });
    }

    $(function () {
        // 页眉购物车 / 收藏按钮 inline 定位：把 .mlshop-header-actions 移到 Astra Header Builder 的右栏（primary / mobile）。
        // 它当前位于 `do_action('astra_header')` 或 `astra_header_primary_container_after` 触发点
        // —— 移动后必然内联到 right 区，且按钮有 `mlshop-header-in-right` 类避免被 CSS 隐藏。
        function mlshop_relocate_header_actions() {
            var $btn = $('.mlshop-header-actions');
            if (!$btn.length || $btn.hasClass('mlshop-header-in-right')) {
                return;
            }
            var $target = $('.site-header-primary-section-right').first();
            if (!$target.length) {
                $target = $('.site-header-mobile-section-right').first();
            }
            if ($target.length) {
                $btn.appendTo($target);
            }
            // 可见性不再依赖此类（CSS 已默认 inline-flex）；这里仅为右对齐布局。
            $btn.addClass('mlshop-header-in-right');
        }
        mlshop_relocate_header_actions();
        // Astra Elementor 自定义样式 / 触发延迟 / 主题 customizer 修改后可能重渲染 header，重跑一次
        $(window).on('load', mlshop_relocate_header_actions);

        // 会员中心下拉菜单：点击按钮切换显隐，点击外部 / Esc 关闭
        function mlshop_close_member_menu() {
            $('.mlshop-header-account').removeClass('is-open').find('.mlshop-header-member').attr('aria-expanded', 'false');
        }
        $(document).on('click', '.mlshop-header-member', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $account = $(this).closest('.mlshop-header-account');
            var open = $account.toggleClass('is-open').hasClass('is-open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $('.mlshop-header-account').not($account).removeClass('is-open').find('.mlshop-header-member').attr('aria-expanded', 'false');
        });
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.mlshop-header-account').length) {
                mlshop_close_member_menu();
            }
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                mlshop_close_member_menu();
            }
        });
        // 加入购物车
        $(document).on('click', '.mlshop-add-to-cart', function () {
            var $btn = $(this);
            var qty = 1;
            var $form = $btn.closest('.mlshop-buy-form');
            if ($form.length && $form.find('.mlshop-qty').length) {
                qty = parseInt($form.find('.mlshop-qty').val(), 10) || 1;
            }
            $btn.prop('disabled', true);
            post('mlshop_add_to_cart', { product_id: $btn.data('product-id'), qty: qty }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    $btn.text(mlshop_i18n.added);
                    mlshop_set_header_count('cart', res.data.count);
                    setTimeout(function () { $btn.text(mlshop_i18n.add_to_cart); }, 1500);
                } else {
                    alert(res.message);
                }
            });
        });

        // 修改数量
        $(document).on('change', '.mlshop-qty', function () {
            var $input = $(this);
            post('mlshop_update_cart', { product_id: $input.data('product-id'), qty: $input.val() }, function () {
                reloadCart();
                mlshop_sync_header_counts();
            });
        });

        // 数量步进：- / + 按钮（详情页 + 购物车页共用同一组件）
        $(document).on('click', '.mlshop-qty-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.is(':disabled')) {
                return;
            }
            var $input = $btn.siblings('.mlshop-qty');
            if (!$input.length) {
                return;
            }
            var min = parseInt($input.attr('min'), 10);
            var max = parseInt($input.attr('max'), 10);
            var val = parseInt($input.val(), 10);
            if (isNaN(val)) {
                val = isNaN(min) ? 1 : min;
            }
            if (isNaN(min)) { min = 1; }
            if ($btn.hasClass('mlshop-qty-minus')) {
                if (val > min) { val = val - 1; }
            } else {
                val = val + 1;
                if (!isNaN(max) && max > 0 && val > max) { val = max; }
            }
            $input.val(val);
            // 购物车页需要重算，只在含 product_id 时触发 change 复用现有逻辑；详情页不发请求
            if ($input.data('product-id')) {
                $input.trigger('change');
            }
        });

        // 移除
        $(document).on('click', '.mlshop-remove', function () {
            var $btn = $(this);
            post('mlshop_remove_cart', { product_id: $btn.data('product-id') }, function () {
                reloadCart();
                mlshop_sync_header_counts();
            });
        });

        // 应用优惠码
        $(document).on('click', '.mlshop-apply-coupon', function () {
            var $btn = $(this);
            var $box = $btn.closest('.mlshop-coupon-box');
            var $code = $box.find('.mlshop-coupon-input');
            var $msg = $box.find('.mlshop-coupon-msg');
            var $hidden = $('.mlshop-coupon-code');
            var code = $.trim($code.val());
            if (!code) {
                $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(mlshop_i18n.enter_coupon).show();
                return;
            }
            $btn.prop('disabled', true);
            $msg.removeClass('mlshop-ok mlshop-error').hide();

            post('mlshop_apply_coupon', { coupon_code: code }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    $msg.removeClass('mlshop-error').addClass('mlshop-ok').text(res.message).show();
                    $hidden.val(res.data.code);
                    $('.mlshop-coupon-tag').text('(' + res.data.code + ')');
                    $('.mlshop-coupon-discount-text').text(res.data.discount_text);
                    $('.mlshop-coupon-discount-text').closest('.mlshop-checkout-discount').show();
                    $('.mlshop-checkout-total-text').text(res.data.total_text);
                    // 同步運費行
                    if ($('.mlshop-checkout-shipping').length) {
                        $('.mlshop-checkout-shipping-text').text(res.data.shipping_text);
                        if (res.data.shipping_note) {
                            $('.mlshop-shipping-note').text(res.data.shipping_note);
                        }
                    }
                } else {
                    $hidden.val('');
                    $('.mlshop-coupon-discount-text').closest('.mlshop-checkout-discount').hide();
                    $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(res.message).show();
                }
            });
        });

        // 省市联动：选省后只显示该省的城市（optgroups 渐进增强，无 JS 时全部可见）
        function mlshop_filter_cities($prov) {
            var $city = $prov.closest('.mlshop-field, .mlshop-address-form, form, body').find('.mlshop-region-city').first();
            if (!$city.length) {
                $city = $prov.siblings('.mlshop-region-city').first();
            }
            if (!$city.length) {
                return;
            }
            var p = $prov.val();
            $city.find('optgroup').each(function () {
                var show = $(this).attr('data-province') === p;
                $(this).toggle(show);
                $(this).find('option').prop('disabled', !show);
            });
            var $sel = $city.find('option:selected');
            if (!$sel.length || $sel.prop('disabled') || ($sel.val() && p && $sel.closest('optgroup').attr('data-province') !== p)) {
                $city.val('');
            }
        }
        $(document).on('change', '.mlshop-region-province', function () {
            mlshop_filter_cities($(this));
        });
        // 初始过滤一次（带预选省份的回填场景）
        $(function () {
            $('.mlshop-region-province').each(function () {
                if ($(this).val()) {
                    mlshop_filter_cities($(this));
                }
            });
        });

        // 结算页：地址簿选择 → 填充收件表单
        $(document).on('change', '.mlshop-address-select', function () {
            var $opt = $(this).find('option:selected');
            if (!$(this).val() || !$opt.attr('data-province')) {
                return;
            }
            $('.mlshop-ship-name').val($opt.attr('data-name') || '');
            $('.mlshop-ship-phone').val($opt.attr('data-phone') || '');
            $('.mlshop-ship-address').val($opt.attr('data-detail') || '');
            var $prov = $('.mlshop-region-province').first();
            $prov.val($opt.attr('data-province')).trigger('change');
            $('.mlshop-region-city').first().val($opt.attr('data-city') || '');
        });

        // 结算页：快递配送 / 到店自提切换
        $(document).on('change', 'input[name="mlshop_shipping_mode"]', function () {
            var pickup = $('input[name="mlshop_shipping_mode"]:checked').val() === 'pickup';
            $('.mlshop-ship-fields').toggle(!pickup);
            $('.mlshop-pickup-fields').toggle(pickup);
            // 自提免运费：同步运费行展示
            if (pickup) {
                $('.mlshop-checkout-shipping').data('mlshop-orig', $('.mlshop-checkout-shipping-text').text());
                $('.mlshop-checkout-shipping-text').text(mlshop_i18n.pickup_free);
                $('.mlshop-shipping-note').text(mlshop_i18n.pickup_note);
            } else {
                var orig = $('.mlshop-checkout-shipping').data('mlshop-orig');
                if (orig) {
                    $('.mlshop-checkout-shipping-text').text(orig);
                }
                $('.mlshop-shipping-note').text(mlshop_i18n.shipping_note_orig || '');
            }
        });
        // 记住服务端初始运费文案，切换回「快递配送」时还原
        $(function () {
            if ($('.mlshop-checkout-shipping').length) {
                mlshop_i18n.shipping_note_orig = $('.mlshop-shipping-note').first().text();
            }
        });

        // 提交订单
        $(document).on('submit', '.mlshop-checkout-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $msg = $form.find('.mlshop-msg');
            $btn.prop('disabled', true);

            var payload = { gateway: $form.find('input[name="gateway"]:checked').val() };
            var code = $.trim($form.find('.mlshop-coupon-code').val());
            if (code) {
                payload.coupon_code = code;
            }

            // 游客购买：带上联系邮箱（服务端强制校验）
            var $guestEmail = $('.mlshop-guest-email');
            if ($guestEmail.length) {
                var guestEmail = $.trim($guestEmail.val());
                if (!guestEmail || guestEmail.indexOf('@') < 1) {
                    $btn.prop('disabled', false);
                    $msg.removeClass('mlshop-ok mlshop-error').addClass('mlshop-error')
                        .text(mlshop_i18n.guest_email_required || '请填写有效的电子邮箱。').show();
                    return;
                }
                payload.guest_email = guestEmail;
            }

            // 實物訂單：收集並校驗收件資料（服務端仍會完整強制校驗）
            var $mode = $('input[name="mlshop_shipping_mode"]:checked');
            if ($('.mlshop-shipping-box').length) {
                if ($mode.length && $mode.val() === 'pickup') {
                    var pkName = $.trim($('.mlshop-pickup-name').val());
                    var pkPhone = $.trim($('.mlshop-pickup-phone').val());
                    if (!pkName || !pkPhone) {
                        $btn.prop('disabled', false);
                        $('.mlshop-shipping-msg').removeClass('mlshop-ok').addClass('mlshop-error').text(mlshop_i18n.pickup_required).show();
                        return;
                    }
                    payload.shipping_mode = 'pickup';
                    payload.pickup_name = pkName;
                    payload.pickup_phone = pkPhone;
                } else {
                    var shipName = $.trim($('.mlshop-ship-name').val());
                    var shipPhone = $.trim($('.mlshop-ship-phone').val());
                    var shipAddr = $.trim($('.mlshop-ship-address').val());
                    var shipProvince = $.trim($('.mlshop-region-province').first().val());
                    var shipCity = $.trim($('.mlshop-region-city').first().val());
                    if (!shipName || !shipPhone || !shipAddr || !shipProvince || !shipCity) {
                        $btn.prop('disabled', false);
                        $('.mlshop-shipping-msg').removeClass('mlshop-ok').addClass('mlshop-error').text(mlshop_i18n.address_required).show();
                        return;
                    }
                    payload.shipping_name = shipName;
                    payload.shipping_phone = shipPhone;
                    payload.shipping_province = shipProvince;
                    payload.shipping_city = shipCity;
                    payload.shipping_address = shipAddr;
                    payload.shipping_note = $.trim($('.mlshop-ship-note').val());
                    var addrId = $.trim($('.mlshop-address-select').val() || '');
                    if (addrId) {
                        payload.address_id = addrId;
                    }
                }
            }

            post('mlshop_place_order', payload, function (res) {
                $btn.prop('disabled', false);
                $msg.removeClass('mlshop-ok mlshop-error').addClass(res.success ? 'mlshop-ok' : 'mlshop-error').text(res.message).show();
                if (res.success && res.data && res.data.redirect) {
                    setTimeout(function () { window.location.href = res.data.redirect; }, 800);
                } else if (res.success && res.data && res.data.order_url) {
                    // 无跳转网关（COD / 线下）：引导到订单页查看状态与交付内容（游客订单带访问令牌）
                    setTimeout(function () { window.location.href = res.data.order_url; }, 800);
                }
            });
        });

        /* ===================== 账户中心 / [mlshop_address] 地址簿 ===================== */

        // 编辑：把条目回填进表单（data-* 由模板输出）
        $(document).on('click', '.mlshop-addr-edit', function (e) {
            e.preventDefault();
            var $b = $(this);
            var $form = $('.mlshop-address-form');
            $form.find('.mlshop-addr-id').val($b.data('id') || '');
            $form.find('.mlshop-addr-name').val($b.data('name') || '');
            $form.find('.mlshop-addr-phone').val($b.data('phone') || '');
            $form.find('.mlshop-addr-detail').val($b.data('detail') || '');
            $form.find('.mlshop-addr-default').prop('checked', !!$b.data('default'));
            $form.find('.mlshop-region-province').val($b.data('province') || '').trigger('change');
            $form.find('.mlshop-region-city').val($b.data('city') || '');
            $form.find('.mlshop-addr-submit').text(mlshop_i18n.addr_update);
            $form.trigger('mlshop:addr-editing');
        });

        // 删除
        $(document).on('click', '.mlshop-addr-del', function (e) {
            e.preventDefault();
            if (!window.confirm(mlshop_i18n.addr_confirm_delete)) {
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true);
            post('mlshop_address_delete', { id: $btn.data('id') }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.message);
                }
            });
        });

        // 新增 / 编辑保存（成功后整页刷新以重新渲染列表）
        $(document).on('submit', '.mlshop-address-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $msg = $form.find('.mlshop-addr-msg');
            var name = $.trim($form.find('.mlshop-addr-name').val());
            var phone = $.trim($form.find('.mlshop-addr-phone').val());
            var province = $.trim($form.find('.mlshop-region-province').val());
            var city = $.trim($form.find('.mlshop-region-city').val());
            var detail = $.trim($form.find('.mlshop-addr-detail').val());
            if (!name || !phone || !province || !city || !detail) {
                $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(mlshop_i18n.address_required).show();
                return;
            }
            var payload = {
                id: $.trim($form.find('.mlshop-addr-id').val() || ''),
                name: name,
                phone: phone,
                province: province,
                city: city,
                detail: detail,
                is_default: $form.find('.mlshop-addr-default').prop('checked') ? 1 : 0
            };
            $form.find('.mlshop-addr-submit').prop('disabled', true);
            $msg.removeClass('mlshop-ok mlshop-error').hide();
            post('mlshop_address_save', payload, function (res) {
                $form.find('.mlshop-addr-submit').prop('disabled', false);
                if (res.success) {
                    location.reload();
                } else {
                    $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(res.message).show();
                }
            });
        });

        // 付费内容解锁
        $(document).on('click', '.mlshop-pay-unlock', function () {
            var $btn = $(this);
            var $box = $btn.closest('.mlshop-paywall-action');
            var $msg = $box.find('.mlshop-paywall-msg');
            var postId = $btn.data('post-id');
            var type = $btn.data('type');
            var gateway = '';
            if (type === 'money') {
                var $checked = $box.find('input[name="mlshop_paywall_gateway"]:checked');
                gateway = $checked.length ? $checked.val() : '';
            }
            $btn.prop('disabled', true);
            $msg.removeClass('mlshop-ok mlshop-error').hide();

            post('mlshop_pay_unlock', { post_id: postId, type: type, gateway: gateway }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    if (res.data && res.data.redirect) {
                        window.location.href = res.data.redirect;
                        return;
                    }
                    $msg.addClass('mlshop-ok').text(res.message).show();
                    if (res.data && res.data.reload) {
                        setTimeout(function () { location.reload(); }, 900);
                    }
                } else {
                    $msg.addClass('mlshop-error').text(res.message).show();
                }
            });
        });

        // 积分充值：自定义输入实时算价
        $(document).on('change', 'input[name="mlshop_recharge_pkg"]', function () {
            var $custom = $('.mlshop-recharge-custom');
            if ($(this).val() === 'custom') {
                $custom.show();
                $('.mlshop-recharge-custom-input').trigger('input');
            } else {
                $custom.hide();
                $('.mlshop-recharge-custom-price').text('');
            }
        });

        $(document).on('input', '.mlshop-recharge-custom-input', function () {
            var rate = parseFloat($('.mlshop-recharge-custom-hint').data('rate'));
            var credit = parseFloat($(this).val());
            var $price = $('.mlshop-recharge-custom-price');
            if (rate > 0 && credit > 0) {
                $price.text('≈ ' + (credit / rate).toFixed(2));
            } else {
                $price.text('');
            }
        });

        // 积分充值提交
        $(document).on('submit', '.mlshop-recharge-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('.mlshop-recharge-submit');
            var $msg = $form.find('.mlshop-recharge-msg');
            var pkg = $form.find('input[name="mlshop_recharge_pkg"]:checked').val();
            if (pkg === undefined) {
                $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(mlshop_i18n.select_recharge_pkg).show();
                return;
            }
            var payload = { package: pkg };
            if (pkg === 'custom') {
                payload.custom_credit = $form.find('.mlshop-recharge-custom-input').val();
            }
            var $gw = $form.find('select[name="mlshop_recharge_gateway"]');
            if ($gw.length) {
                payload.gateway = $gw.val();
            }
            $btn.prop('disabled', true);
            $msg.removeClass('mlshop-ok mlshop-error').hide();

            post('mlshop_create_recharge', payload, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    if (res.data && res.data.redirect) {
                        $msg.removeClass('mlshop-error').addClass('mlshop-ok').text(res.message).show();
                        setTimeout(function () { window.location.href = res.data.redirect; }, 700);
                    } else {
                        $msg.removeClass('mlshop-error').addClass('mlshop-ok').text(res.message).show();
                    }
                } else {
                    $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(res.message).show();
                }
            });
        });

        // 会员升级购买
        $(document).on('click', '.mlshop-buy-membership', function () {
            var $btn = $(this);
            var level = $btn.data('level');
            var $box = $btn.closest('.mlshop-membership-upgrade');
            var $msg = $box.find('.mlshop-membership-msg');
            var $gw = $box.find('input[name="mlshop_mb_gateway_' + level + '"]:checked');
            var gateway = $gw.length ? $gw.val() : 'cod';
            $btn.prop('disabled', true);
            $msg.removeClass('mlshop-ok mlshop-error').hide();

            post('mlshop_buy_membership', { level: level, gateway: gateway }, function (res) {
                $btn.prop('disabled', false);
                if (res.success) {
                    if (res.data && res.data.redirect) {
                        $msg.removeClass('mlshop-error').addClass('mlshop-ok').text(res.message).show();
                        setTimeout(function () { window.location.href = res.data.redirect; }, 700);
                    } else {
                        $msg.removeClass('mlshop-error').addClass('mlshop-ok').text(res.message).show();
                    }
                } else {
                    $msg.removeClass('mlshop-ok').addClass('mlshop-error').text(res.message).show();
                }
            });
        });

        // 商品相册：缩略图 / 左右箭头 / 指示器 三向联动
        function mlshopGalleryGoTo($gallery, idx) {
            var count = parseInt($gallery.attr('data-count'), 10);
            if (!count || count < 2) {
                return;
            }
            var total = count;
            idx = ((idx % total) + total) % total;

            var $mainImg = $gallery.find('.mlshop-gallery-main-img');
            var $thumbs  = $gallery.find('.mlshop-gallery-thumbs .mlshop-gallery-thumb');
            var $dots    = $gallery.find('.mlshop-gallery-dots .mlshop-gallery-dot');
            var $activeThumb = $thumbs.filter('[data-index="' + idx + '"]');
            var src = $activeThumb.attr('data-full');
            if (!src) {
                return;
            }
            $mainImg.addClass('is-switching');
            $mainImg.off('load error').on('load error', function () {
                $(this).removeClass('is-switching');
            });
            $mainImg.attr('src', src);
            $mainImg.attr('data-zoom', src);
            setTimeout(function () { $mainImg.removeClass('is-switching'); }, 220);
            $thumbs.removeClass('is-active').filter('[data-index="' + idx + '"]').addClass('is-active');
            $dots.removeClass('is-active').filter('[data-index="' + idx + '"]').addClass('is-active');
            $gallery.attr('data-index', idx);
            $gallery.trigger('mlshop:gallery-changed');
        }

        // 放大镜（悬停放大）：主图取景框 + 右侧放大面板，随切图联动
        function mlshopInitZoom($gallery) {
            if ($gallery.data('mlshop-zoom-init')) {
                return;
            }
            $gallery.data('mlshop-zoom-init', 1);

            var $main = $gallery.find('.mlshop-gallery-main');
            var $img  = $gallery.find('.mlshop-gallery-main-img');
            if (!$img.length) {
                return;
            }

            var $lens = $('<div class="mlshop-gallery-lens"></div>');
            var $zoom = $('<div class="mlshop-gallery-zoom"></div>');
            $main.append($lens);
            $gallery.append($zoom);

            var ZOOM = 2.2;

            function zoomSrc() {
                return $img.attr('data-zoom') || $img.attr('src');
            }

            function isEnabled() {
                return window.innerWidth > 900 && !(window.matchMedia && window.matchMedia('(hover: none)').matches);
            }

            $main.on('mouseenter', function () {
                if (!isEnabled()) {
                    return;
                }
                if (!$img[0].complete || !$img[0].naturalWidth) {
                    return;
                }
                $zoom.css('background-image', 'url("' + zoomSrc() + '")');
                $lens.show();
                $zoom.show();
            });

            $main.on('mouseleave', function () {
                $lens.hide();
                $zoom.hide();
            });

            $main.on('mousemove', function (e) {
                if (!$lens.is(':visible')) {
                    return;
                }
                var rect = $img[0].getBoundingClientRect();
                var x = e.clientX - rect.left;
                var y = e.clientY - rect.top;
                var lensW = rect.width / ZOOM;
                var lensH = rect.height / ZOOM;
                var lx = Math.max(0, Math.min(x - lensW / 2, rect.width - lensW));
                var ly = Math.max(0, Math.min(y - lensH / 2, rect.height - lensH));
                $lens.css({ left: lx, top: ly, width: lensW, height: lensH });
                var zoomW = $zoom.width();
                var zoomH = $zoom.height();
                $zoom.css('background-size', (zoomW * ZOOM) + 'px ' + (zoomH * ZOOM) + 'px');
                $zoom.css('background-position', '-' + (lx * ZOOM) + 'px -' + (ly * ZOOM) + 'px');
            });

            $gallery.on('mlshop:gallery-changed', function () {
                if ($zoom.is(':visible')) {
                    $zoom.css('background-image', 'url("' + zoomSrc() + '")');
                }
            });
        }

        $('[data-mlshop-gallery]').each(function () {
            mlshopInitZoom($(this));
        });

        $(document).on('click', '.mlshop-gallery-prev', function (e) {
            e.preventDefault();
            var $gallery = $(this).closest('[data-mlshop-gallery]');
            var idx = parseInt($gallery.attr('data-index'), 10) || 0;
            mlshopGalleryGoTo($gallery, idx - 1);
        });

        $(document).on('click', '.mlshop-gallery-next', function (e) {
            e.preventDefault();
            var $gallery = $(this).closest('[data-mlshop-gallery]');
            var idx = parseInt($gallery.attr('data-index'), 10) || 0;
            mlshopGalleryGoTo($gallery, idx + 1);
        });

        $(document).on('click', '.mlshop-gallery-dot', function (e) {
            e.preventDefault();
            var $gallery = $(this).closest('[data-mlshop-gallery]');
            var idx = parseInt($(this).attr('data-index'), 10) || 0;
            mlshopGalleryGoTo($gallery, idx);
        });

        $(document).on('click', '.mlshop-gallery-thumb', function (e) {
            e.preventDefault();
            var $gallery = $(this).closest('[data-mlshop-gallery]');
            var idx = parseInt($(this).attr('data-index'), 10) || 0;
            mlshopGalleryGoTo($gallery, idx);
        });

        // 商品 Tab 切换
        $(document).on('click', '.mlshop-tab-nav li', function () {
            var tab = $(this).data('tab');
            $('.mlshop-tab-nav li').removeClass('is-active');
            $(this).addClass('is-active');
            $('.mlshop-tab-panel').removeClass('is-active');
            $('#tab-' + tab).addClass('is-active');
        });

        // 收藏 / 取消收藏
        $(document).on('click', '.mlshop-favorite-btn', function () {
            var $btn = $(this);
            post('mlshop_toggle_favorite', { product_id: $btn.data('product-id') }, function (res) {
                if (res.success) {
                    $btn.toggleClass('is-active', res.data.active);
                    $btn.find('.mlshop-heart').text(res.data.active ? '♥' : '♡');
                    $btn.find('.mlshop-fav-label').text(res.data.active ? mlshop_i18n.faved : mlshop_i18n.favorite);
                    $btn.attr('aria-pressed', res.data.active ? 'true' : 'false');
                    mlshop_set_header_count('fav', res.data.count);
                    // 整页没刷新时侧栏 widget 仍可能是旧 HTML：主动拉一次 fragment 替换
                    mlshop_refresh_sidebar_favorites();
                } else if (res.message) {
                    alert(res.message);
                }
            });
        });
    });

    /**
     * 价格筛选双滑块
     * - 拖动时实时更新 hidden 值 + 数值显示 + 区间高亮
     * - 钳制：min 不能超过 max，反之亦然
     * - 松手（change 事件）防抖 400ms 后自动提交表单
     * - 若滑块停在上下限端点，对应 hidden 输入会被 disabled，URL 更干净
     */
    $(function () {
        var $sliders = $('.mlshop-price-slider');
        if (!$sliders.length) {
            return;
        }
        $sliders.each(function () {
            var $root = $(this);
            var $min = $root.find('.mlshop-price-slider-min');
            var $max = $root.find('.mlshop-price-slider-max');
            var $range = $root.find('.mlshop-price-slider-range');
            var $form = $root.closest('form');
            var $hmin = $form.find('.mlshop-price-hidden-min');
            var $hmax = $form.find('.mlshop-price-hidden-max');
            var $dmin = $form.find('.mlshop-price-display-min');
            var $dmax = $form.find('.mlshop-price-display-max');
            var lo = parseFloat($root.data('min')) || 0;
            var hi = parseFloat($root.data('max')) || 0;
            if (hi <= lo) {
                hi = lo + 1;
            }

            var fmt = function (v) {
                v = parseFloat(v);
                if (isNaN(v)) {
                    return '';
                }
                return (Math.floor(v) === v) ? String(v) : v.toFixed(2);
            };
            var clamp = function (val, low, high) {
                val = parseFloat(val);
                if (isNaN(val)) {
                    return low;
                }
                return Math.max(low, Math.min(high, val));
            };
            var setRangeBar = function () {
                var a = clamp($min.val(), lo, hi);
                var b = clamp($max.val(), lo, hi);
                var leftPct = ((a - lo) / (hi - lo)) * 100;
                var rightPct = 100 - ((b - lo) / (hi - lo)) * 100;
                $range.css({ left: leftPct + '%', right: rightPct + '%' });
            };
            var setDisplay = function () {
                $dmin.text(fmt($min.val()));
                $dmax.text(fmt($max.val()));
            };
            var setHidden = function () {
                $hmin.val(fmt($min.val()));
                $hmax.val(fmt($max.val()));
            };
            var autoSubmit = function () {
                // 端点不放进 URL（更干净），让 archive_query 自然走无价格过滤分支
                if (clamp($min.val(), lo, hi) <= lo) {
                    $hmin.attr('disabled', 'disabled');
                } else {
                    $hmin.removeAttr('disabled');
                }
                if (clamp($max.val(), lo, hi) >= hi) {
                    $hmax.attr('disabled', 'disabled');
                } else {
                    $hmax.removeAttr('disabled');
                }
                if ($form.length) {
                    $form.get(0).submit();
                }
            };

            var debounce = null;
            var debouncedSubmit = function () {
                clearTimeout(debounce);
                debounce = setTimeout(autoSubmit, 400);
            };

            $min.on('input', function () {
                if (parseFloat($min.val()) > parseFloat($max.val())) {
                    $min.val($max.val());
                }
                setHidden();
                setDisplay();
                setRangeBar();
            });
            $max.on('input', function () {
                if (parseFloat($max.val()) < parseFloat($min.val())) {
                    $max.val($min.val());
                }
                setHidden();
                setDisplay();
                setRangeBar();
            });
            $min.on('change', debouncedSubmit);
            $max.on('change', debouncedSubmit);

            // 初始化：把 URL 现有 min/max / 显示 / 区间高亮都同步一遍
            setHidden();
            setDisplay();
            setRangeBar();
        });
    });

    /**
     * 物流第二批：用户确认收货（订单详情 delivered 状态按钮）。
     * 提交 mlshop_confirm_delivery，成功后刷新页面展示「已完成」状态。
     */
    $(document).on('click', '.mlshop-confirm-delivery', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        if (!window.confirm(mlshop_i18n.confirm_delivery || '确定已收到商品？确认后订单将完成。')) {
            return;
        }
        $btn.prop('disabled', true);
        post('mlshop_confirm_delivery', { order_id: $btn.data('order') }, function (res) {
            if (res && res.success) {
                location.reload();
                return;
            }
            $btn.prop('disabled', false);
            window.alert(res && res.message ? res.message : mlshop_i18n.network_error);
        });
    });
    /**
     * 售后退款：用户申请售后（订单详情页「申请售后」按钮）。
     * 提交 mlshop_apply_refund，成功后刷新页面展示「售后处理中」状态。
     */
    $(document).on('click', '.mlshop-apply-refund', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        var $box = $btn.closest('.mlshop-refund-apply');
        var reason = $.trim(($box.find('.mlshop-refund-reason').val() || ''));
        if (!reason) {
            window.alert(mlshop_i18n.refund_reason_required || '请填写售后原因。');
            return;
        }
        if (!window.confirm(mlshop_i18n.apply_refund_confirm || '确定提交售后申请？提交后请等待管理员处理。')) {
            return;
        }
        $btn.prop('disabled', true);
        post('mlshop_apply_refund', { order_id: $btn.data('order'), reason: reason }, function (res) {
            if (res && res.success) {
                location.reload();
                return;
            }
            $btn.prop('disabled', false);
            window.alert(res && res.message ? res.message : mlshop_i18n.network_error);
        });
    });
    /**
     * 微信支付（Native 扫码）：订单页「我已完成支付」按钮。
     * 触发服务端 query() 复核（登录 + nonce + 属主/游客令牌），
     * 复核 SUCCESS 且金额一致才 mark_paid，成功后刷新页面展示状态。
     */
    $(document).on('click', '.mlshop-wechat-check', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        $btn.prop('disabled', true);
        var $msg = $btn.closest('.mlshop-qr-placeholder').find('.mlshop-wechat-check-msg');
        $msg.removeClass('mlshop-ok mlshop-error').hide();
        // 游客订单：订单页 URL 自带访问令牌（token=），一并提交供属主校验
        var m = window.location.search.match(/[?&]token=([^&]+)/);
        var payload = { order_id: $btn.data('order') };
        if (m && m[1]) {
            payload.token = decodeURIComponent(m[1]);
        }
        post('mlshop_wechat_query', payload, function (res) {
            if (res && res.success) {
                if (res.data && res.data.status === 'paid') {
                    location.reload();
                    return;
                }
                $btn.prop('disabled', false);
                $msg.addClass(res.data && res.data.status === 'pending' ? 'mlshop-ok' : 'mlshop-error')
                    .text(res.message).show();
                return;
            }
            $btn.prop('disabled', false);
            $msg.addClass('mlshop-error').text(res && res.message ? res.message : mlshop_i18n.network_error).show();
        });
    });
})(jQuery);
