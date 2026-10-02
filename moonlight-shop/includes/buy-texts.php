<?php
/**
 * 购买文案可配置（后台「商城设置 → 外观 → 购买文案」）：
 * 全站按钮与提示文案统一从这里取，默认值兼容旧文案。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @return array{add:string,favorite:string,select:string,added:string,adding:string} */
function mlshop_buy_texts()
{
    $d = array(
        'add'     => __('加入购物车', 'moonlight-shop'),
        'favorite'=> __('收藏', 'moonlight-shop'),
        'select'  => __('选择套餐', 'moonlight-shop'),
        'added'   => __('已加入', 'moonlight-shop'),
        'adding'  => __('正在加入…', 'moonlight-shop'),
    );
    $out = array();
    foreach ($d as $k => $def) {
        $v = trim((string) mlshop_get_option('buy_text_' . $k, ''));
        $out[$k] = $v !== '' ? $v : $def;
    }
    return $out;
}

/** 单键取值（模板内便捷调用） */
function mlshop_buy_text($key)
{
    $t = mlshop_buy_texts();
    return isset($t[$key]) ? $t[$key] : '';
}
