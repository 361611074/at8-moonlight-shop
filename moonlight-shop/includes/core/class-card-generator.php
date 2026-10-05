<?php
/**
 * 卡密自动生成。
 *
 * 现状：Moonlight_Card_Stock::import() 只接受外部提供的明文数组，管理员得自己
 * 准备 CSV 再导入。参考子比（zibll）的「管理卡密」页做法，改为在后台可直接批量生成。
 *
 * 设计约束（沿用卡密库存既有模型）：
 *   - 生成即入库为加密批次（AES-256-CBC），**明文不落库**，与手动导入完全一致；
 *   - 跨批次去重（SHA-256 指纹），碰撞自动重掷；
 *   - 字符集默认去掉易混淆的 0/O/1/l/I，降低客服沟通成本；
 *   - 有效��一次性：优先用 random_bytes，无强随机源则拒绝生成（不产出弱口令）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Card_Generator
{
    /** 默认字符集：去掉 0/O/1/l/I 等易混淆字符 */
    const CHARSET_DEFAULT = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    /** 自定义字符集时也剔除这些易混淆字符 */
    const CHARSET_EXCLUDE  = '0O1lI';

    /**
     * 无状态：类只提供静态方法，留空以兼容统一的 init() 调用惯例。
     */
    public static function init()
    {
    }

    /**
     * 生成一批卡密并导入为加密批次。
     *
     * @param int    $product_id 商品 ID
     * @param int    $count      生成数量
     * @param int    $length     单个卡密长度
     * @param string $charset    字符集（留空用默认）
     * @param string $prefix     前缀（如 VIP-）
     * @param string $batch_name 批次名
     * @param int    $expires    过期时间戳，0 = 永不过期
     * @param bool   $strong     是否强随机
     * @return array|WP_Error
     */
    public static function generate($product_id, $count, $length, $charset = '', $prefix = '', $batch_name = '', $expires = 0)
    {
        $product_id = (int) $product_id;
        $count      = (int) $count;
        $length     = (int) $length;

        if ($product_id <= 0) {
            return new WP_Error('mlshop_card_gen_product', __('请选择要为其生成卡密的商品。', 'moonlight-shop'));
        }
        if (!function_exists('random_bytes')) {
            return new WP_Error('mlshop_card_gen_rng', __('服务器缺少 random_bytes，无法安全生成卡密。请改用手动导入。', 'moonlight-shop'));
        }
        if ($count < 1 || $count > 2000) {
            return new WP_Error('mlshop_card_gen_count', __('生成数量需在 1 – 2000 之间。', 'moonlight-shop'));
        }
        if ($length < 8 || $length > 64) {
            return new WP_Error('mlshop_card_gen_length', __('卡密长度需在 8 – 64 之间。', 'moonlight-shop'));
        }
        if (!get_post($product_id) || 'mlshop_product' !== get_post_type($product_id)) {
            return new WP_Error('mlshop_card_gen_product_type', __('商品不存在。', 'moonlight-shop'));
        }

        // 字符集：留空用默认；自定义时剔除易混淆字符。
        // 注意：调用方明确给了字符集、剔除后却空了 → 报错，不静默回退到默认
        // （否则用户以为自己限制了字符，实际拿到的是另一套）。
        $raw_set     = str_replace(str_split(self::CHARSET_EXCLUDE), '', (string) $charset);
        $raw_set     = preg_replace('/[^A-Za-z0-9]/', '', $raw_set);
        $raw_set     = implode('', array_unique(str_split((string) $raw_set)));
        if ('' === (string) $charset) {
            $set = self::CHARSET_DEFAULT;
        } elseif (strlen($raw_set) < 8) {
            return new WP_Error('mlshop_card_gen_charset', __('字符集里没有足够的可用字符（已剔除 0/O/1/I/l），请更换。', 'moonlight-shop'));
        } else {
            $set = $raw_set;
        }
        if (strlen($set) < 8) {
            return new WP_Error('mlshop_card_gen_charset', __('字符集可用字符不足 8 个，请更换。', 'moonlight-shop'));
        }

        $prefix = sanitize_text_field((string) $prefix);
        if (strlen($prefix) > 16) {
            $prefix = substr($prefix, 0, 16);
        }

        // 已有指纹（跨批次去重），避免与既有卡密撞号
        $existing = Moonlight_Card_Stock::fingerprints($product_id);

        $max_len = strlen($set) - 1;
        $keys    = array();
        $tries   = 0;
        $limit   = $count * 20; // 碰撞重掷上限，够用且不会打爆 CPU

        while (count($keys) < $count && $tries < $limit) {
            $tries++;
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                // random_int 是密码学安全的强随机；失败则退出而不是退化成 mt_rand
                try {
                    $idx = random_int(0, $max_len);
                } catch (Exception $e) {
                    return new WP_Error('mlshop_card_gen_rng_fail', __('随机源异常，生成已中止。', 'moonlight-shop'));
                }
                $code .= $set[$idx];
            }
            $plain = '' !== $prefix ? $prefix . $code : $code;

            $h = hash('sha256', $plain);
            if (isset($existing[$h])) {
                continue; // 撞号，重掷
            }
            $existing[$h] = true;
            $keys[]        = $plain;
        }

        if (empty($keys)) {
            return new WP_Error('mlshop_card_gen_collision', __('连续碰撞未能生成唯一卡密，请缩短长度或更换字符集。', 'moonlight-shop'));
        }

        if ('' === trim((string) $batch_name)) {
            $batch_name = sprintf(
                /* translators: 1: 日期时间 2: 生成数量 */
                __('自动生成 %1$s（%2$d 张）', 'moonlight-shop'),
                wp_date('Y-m-d H:i'),
                count($keys)
            );
        }

        // 走既有入库通道：加密批次 + 审计，明文不落库
        $res = Moonlight_Card_Stock::import($product_id, $keys, $batch_name, $expires);
        if (empty($res['batch_id'])) {
            $why = (!empty($res['duplicates']))
                ? sprintf(
                    /* translators: %d: 重复数量 */
                    __('全部 %d 张与既有卡密重复。', 'moonlight-shop'),
                    (int) $res['duplicates']
                )
                : __('未知错误。', 'moonlight-shop');
            return new WP_Error('mlshop_card_gen_import', __('入库失败：', 'moonlight-shop') . $why);
        }

        return array(
            'batch_id'   => (int) $res['batch_id'],
            'generated'  => count($keys),
            'duplicates' => (int) $res['duplicates'],
            'tries'      => $tries,
            'length'     => $length,
            'charset'    => $set,
            'prefix'     => $prefix,
        );
    }
}
