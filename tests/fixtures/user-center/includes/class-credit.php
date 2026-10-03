<?php
/**
 * 积分账本：继承余额钱包的原子账本，仅更换存储键与动作前缀。
 *
 * - user meta `mluc_credit_balance`：当前积分（float）
 * - user meta `mluc_credit_ledger`：最近 200 条流水
 * - 充值比例 / 兑换比例等配置见 functions.php（mluc_get_credit_*），全部后台可自定义
 *
 * @package Moonlight_User_Center
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLUC_Credit extends MLUC_Wallet
{
    const KEY    = 'mluc_credit_balance';
    const LEDGER = 'mluc_credit_ledger';
    const HOOK   = 'mluc_credit';
}
