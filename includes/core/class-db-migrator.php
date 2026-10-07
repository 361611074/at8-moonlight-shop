<?php
/**
 * DB_VERSION 升级机制（计划书第六十三节）。
 *
 * 规则：
 *  - 版本号存 option `moonlight_db_version`，每次 schema/数据结构变更递增；
 *  - 每步迁移可重入（幂等）、失败即停（不留半截状态）、前置自动落快照；
 *  - 禁止 DROP TABLE；当前架构无自定义表，本机制承载 option/meta 迁移与未来索引表。
 *
 * 触发：后台 admin_init（避免前台抖动）+ 插件激活。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_DB_Migrator
{
    const OPTION     = 'moonlight_db_version';
    const DB_VERSION = '2.1.0';

    public static function init()
    {
        add_action('admin_init', array(__CLASS__, 'maybe_upgrade'));
        add_action('mlshop_activation_upgrade', array(__CLASS__, 'maybe_upgrade'));
    }

    /**
     * 版本步进表：'目标版本' => 回调。回调内部必须自带幂等。
     *
     * 2.1.0：存量明文卡密池 → 加密批次模型（m4_migrate_cardkeys）。
     * 已在 2.0.0 的站点升级时只跑 m4（run_initial 内亦包含 m4，保证全新安装路径同样执行）。
     */
    public static function steps()
    {
        return array(
            '2.0.0' => array('Moonlight_Migrations', 'run_initial'),
            '2.1.0' => array('Moonlight_Migrations', 'm4_migrate_cardkeys'),
        );
    }

    public static function maybe_upgrade()
    {
        $from = get_option(self::OPTION, '0');
        if (version_compare($from, self::DB_VERSION, '>=')) {
            return;
        }
        // 升级前快照（可回滚依据，见 MIGRATION_PLAN.md 第四节）
        Moonlight_Migrations::snapshot('upgrade', array('from' => $from, 'to' => self::DB_VERSION));

        foreach (self::steps() as $ver => $callback) {
            if (version_compare($from, $ver, '<')) {
                $ok = call_user_func($callback);
                if (is_wp_error($ok)) {
                    // 失败即停：后台显示修复入口，不继续后面的步骤
                    update_option('moonlight_db_upgrade_error', array(
                        'version' => $ver,
                        'code'    => $ok->get_error_code(),
                        'message' => $ok->get_error_message(),
                        'at'      => current_time('mysql'),
                    ));
                    return;
                }
                update_option(self::OPTION, $ver);
            }
        }
        update_option(self::OPTION, self::DB_VERSION);
        delete_option('moonlight_db_upgrade_error');
    }
}
