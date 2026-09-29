/**
 * TinyMCE 插件：插入 [hidecontent type="X"]...[/hidecontent] 短代码。
 *
 * 经典 API（addButton / addMenuItem）兼容 TinyMCE 4/6，WP 6+ 默认 TinyMCE 6。
 * 按钮渲染为下拉菜单，4 个 type 与类 MLUC_Hidecontent::$types 一一对应。
 */
(function () {
    'use strict';

    if (typeof window.tinymce === 'undefined') {
        return;
    }

    /**
     * 4 种类型与本地化文案来自服务器注入的 mluc_tinymce_i18n；
     * 若未注入则使用简体兜底，避免编辑器完全空白。
     */
    var i18n = (window.mluc_tinymce_i18n && typeof window.mluc_tinymce_i18n === 'object')
        ? window.mluc_tinymce_i18n
        : {};

    var TYPES = (Array.isArray(i18n.types) && i18n.types.length === 4)
        ? i18n.types
        : [
            { value: 'reply',   label: '评论后可查看' },
            { value: 'logged',  label: '登录后可查看' },
            { value: 'vip1',    label: '会员可查看' },
            { value: 'payshow', label: '付费后可查看' }
        ];

    var BUTTON_LABEL = i18n.button_label || '隐藏内容';
    var INSERTING    = i18n.inserting    || '请选择一种隐藏类型…';

    tinymce.PluginManager.add('mluc_hidecontent', function (editor) {
        // 工具栏下拉按钮：单击展开菜单（menubutton 在 WP 经典编辑器 API 下稳定可用）
        editor.addButton('mluc_hidecontent', {
            title: BUTTON_LABEL,
            text: BUTTON_LABEL,
            type: 'menubutton',
            menu: TYPES.map(function (t) {
                return {
                    text: t.label,
                    onclick: function () {
                        insertShortcode(editor, t.value, t.label);
                    }
                };
            })
        });

        // 同时注册一个菜单项（部分主题/插件可能屏蔽工具栏，但仍可在「格式」下拉找到）
        editor.addMenuItem('mluc_hidecontent', {
            text: BUTTON_LABEL,
            context: 'insert',
            menu: TYPES.map(function (t) {
                return {
                    text: t.label,
                    onclick: function () {
                        insertShortcode(editor, t.value, t.label);
                    }
                };
            })
        });
    });

    /**
     * 在编辑器光标处插入 [hidecontent type="X"]...[/hidecontent]。
     * 插入后光标自动落在短代码之后，便于继续输入。
     */
    function insertShortcode(editor, type, label) {
        var openTag  = '[hidecontent type="' + type + '"]';
        var closeTag = '[/hidecontent]';
        var placeholder = '在此处输入仅 ' + label + ' 才可见的内容…';
        var snippet = openTag + placeholder + closeTag;

        editor.focus();

        // 兼容 TinyMCE 4（WordPress 自带，无 collapseToEnd 方法）与 TinyMCE 6：
        // 安全地把选区折叠到末尾，再插入短代码。mceInsertContent 会把光标落在插入内容之后。
        if (typeof editor.selection.collapseToEnd === 'function') {
            editor.selection.collapseToEnd();
        } else if (typeof editor.selection.collapse === 'function') {
            editor.selection.collapse(false);
        }

        editor.execCommand('mceInsertContent', false, snippet);
    }
})();
