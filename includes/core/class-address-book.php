<?php
/**
 * 收货地址簿（usermeta `moonlight_addresses`）。
 *
 * 每用户一份地址数组，条目结构：
 *   ['id' => '唯一id', 'name' => '收件人', 'phone' => '手机号',
 *    'province' => 'CN-XX', 'city' => 'CN-XX-XX',
 *    'detail' => '详细地址', 'is_default' => bool]
 *
 * 安全约定：
 *  - 全部 API 以 $uid 为属主读写（调用方传 get_current_user_id()，
 *    绝不接受客户端传 uid），跨用户 id 天然取不到 → 属主隔离；
 *  - 省/市区码必须能被 Moonlight_Region_Provider resolve，否则拒绝保存。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Address_Book
{
    const META_KEY = 'moonlight_addresses';

    /**
     * 每用户最多保存的地址数（防 usermeta 膨胀）。
     */
    const MAX_ADDRESSES = 20;

    /**
     * 读取用户全部地址。
     *
     * @param int $uid
     * @return array[] 默认地址排最前，其余按保存顺序
     */
    public static function get_list($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return array();
        }
        $raw = get_user_meta($uid, self::META_KEY, true);
        if (!is_array($raw)) {
            return array();
        }
        $list = array_values(array_filter($raw, 'is_array'));
        // 稳定排序：默认地址置顶，其余保持原相对顺序
        usort($list, function ($a, $b) {
            $ad = !empty($a['is_default']) ? 1 : 0;
            $bd = !empty($b['is_default']) ? 1 : 0;
            if ($ad !== $bd) {
                return $bd - $ad;
            }
            return 0;
        });
        return $list;
    }

    /**
     * 按 id 取单条地址（仅限属主自己的列表）。
     *
     * @param int    $uid
     * @param string $id
     * @return array|false
     */
    public static function get($uid, $id)
    {
        $id = (string) $id;
        if ('' === $id) {
            return false;
        }
        foreach (self::get_list($uid) as $addr) {
            if (isset($addr['id']) && (string) $addr['id'] === $id) {
                return $addr;
            }
        }
        return false;
    }

    /**
     * 新增 / 编辑地址（带完整校验）。
     *
     * 校验规则：name 1-32 字；phone 5-20 位（数字与 -）；province/city 区码必须
     * 能 resolve（且市须属于省）；detail 1-120 字。任一失败返回 WP_Error。
     *
     * @param int   $uid
     * @param array $in  ['id'(可选),'name','phone','province','city','detail','is_default'(可选)]
     * @return array|WP_Error 保存后的完整地址条目
     */
    public static function save($uid, $in)
    {
        $uid = (int) $uid;
        if ($uid <= 0 || !is_array($in)) {
            return new WP_Error('invalid', __('用户无效。', 'at8-moonlight-shop'));
        }

        $name = isset($in['name']) ? sanitize_text_field($in['name']) : '';
        $phone = isset($in['phone']) ? sanitize_text_field($in['phone']) : '';
        $province = isset($in['province']) ? sanitize_text_field($in['province']) : '';
        $city = isset($in['city']) ? sanitize_text_field($in['city']) : '';
        $detail = isset($in['detail']) ? sanitize_textarea_field($in['detail']) : '';

        $name = preg_replace('/\s+/u', ' ', trim($name));
        $detail = trim($detail);

        if (function_exists('mb_strlen')) {
            $name_len = mb_strlen($name, 'UTF-8');
            $detail_len = mb_strlen($detail, 'UTF-8');
        } else {
            $name_len = strlen($name);
            $detail_len = strlen($detail);
        }
        if ($name_len < 1 || $name_len > 32) {
            return new WP_Error('name', __('收件人姓名需为 1-32 个字。', 'at8-moonlight-shop'));
        }
        if (!preg_match('/^[0-9\-]{5,20}$/', $phone)) {
            return new WP_Error('phone', __('手机号需为 5-20 位数字或「-」。', 'at8-moonlight-shop'));
        }

        $rp = Moonlight_Region_Provider::resolve($province);
        if (!$rp || 'province' !== $rp['level']) {
            return new WP_Error('region', __('请选择有效的省份。', 'at8-moonlight-shop'));
        }
        $rc = Moonlight_Region_Provider::resolve($city);
        if (!$rc || 'city' !== $rc['level'] || $rc['province'] !== $rp['code']) {
            return new WP_Error('region', __('请选择该省份下有效的城市。', 'at8-moonlight-shop'));
        }

        if ($detail_len < 1 || $detail_len > 120) {
            return new WP_Error('detail', __('详细地址需为 1-120 个字。', 'at8-moonlight-shop'));
        }

        $list = self::get_list($uid);
        $id = isset($in['id']) ? sanitize_text_field((string) $in['id']) : '';
        $entry = array(
            'id'       => $id,
            'name'     => $name,
            'phone'    => $phone,
            'province' => $rp['code'],
            'city'     => $rc['code'],
            'detail'   => $detail,
            // 快照（resolve 后的名称），展示时无需再查 provider
            'province_name' => $rp['name'],
            'city_name'     => $rc['name'],
        );

        $found = false;
        foreach ($list as $k => $addr) {
            if (isset($addr['id']) && (string) $addr['id'] === $id && '' !== $id) {
                // 编辑：保留原创建字段，合并新值
                $entry['id'] = $id;
                $list[$k] = array_merge($addr, $entry);
                $found = true;
                break;
            }
        }
        if (!$found) {
            if (count($list) >= self::MAX_ADDRESSES) {
                return new WP_Error(
                    'limit',
                    /* translators: %d: 数量 */
                    sprintf(__('最多保存 %d 个收货地址。', 'at8-moonlight-shop'), self::MAX_ADDRESSES)
                );
            }
            $entry['id'] = self::gen_id($list);
            $list[] = $entry;
        }

        $is_default = !empty($in['is_default']);
        // 首个地址自动设为默认；勾选默认时清除其它默认
        $has_default = false;
        foreach ($list as $addr) {
            if (!empty($addr['is_default'])) {
                $has_default = true;
                break;
            }
        }
        if ($is_default || !$has_default) {
            foreach ($list as $k => $addr) {
                $list[$k]['is_default'] = ((string) $addr['id'] === (string) $entry['id']);
            }
        }

        self::persist($uid, $list);
        return self::get($uid, $entry['id']);
    }

    /**
     * 删除地址（仅属主自己的）。被删的是默认地址时，把剩余首条提升为默认。
     *
     * @param int    $uid
     * @param string $id
     * @return bool 是否删除了条目
     */
    public static function delete($uid, $id)
    {
        $uid = (int) $uid;
        $id = (string) $id;
        if ($uid <= 0 || '' === $id) {
            return false;
        }
        $list = self::get_list($uid);
        $kept = array();
        $found = false;
        foreach ($list as $addr) {
            if (isset($addr['id']) && (string) $addr['id'] === $id) {
                $found = true;
                continue;
            }
            $kept[] = $addr;
        }
        if (!$found) {
            return false;
        }
        // 保持「有地址必有默认」不变式
        $has_default = false;
        foreach ($kept as $addr) {
            if (!empty($addr['is_default'])) {
                $has_default = true;
                break;
            }
        }
        if (!$has_default && !empty($kept)) {
            $kept[0]['is_default'] = true;
        }
        self::persist($uid, $kept);
        return true;
    }

    /**
     * 设为默认地址（仅属主自己的）。
     *
     * @param int    $uid
     * @param string $id
     * @return bool
     */
    public static function set_default($uid, $id)
    {
        $uid = (int) $uid;
        $id = (string) $id;
        if ($uid <= 0 || '' === $id) {
            return false;
        }
        $list = self::get_list($uid);
        $found = false;
        foreach ($list as $k => $addr) {
            if (isset($addr['id']) && (string) $addr['id'] === $id) {
                $list[$k]['is_default'] = true;
                $found = true;
            } else {
                $list[$k]['is_default'] = false;
            }
        }
        if (!$found) {
            return false;
        }
        self::persist($uid, $list);
        return true;
    }

    /**
     * 取默认地址（无默认时回退第一条）。
     *
     * @param int $uid
     * @return array|false
     */
    public static function first($uid)
    {
        $list = self::get_list($uid);
        if (empty($list)) {
            return false;
        }
        foreach ($list as $addr) {
            if (!empty($addr['is_default'])) {
                return $addr;
            }
        }
        return $list[0];
    }

    /**
     * 生成条目 id：time() + 随机后缀，并保证在当前列表内唯一。
     *
     * @param array $list 现有列表
     * @return string
     */
    private static function gen_id($list)
    {
        do {
            $id = (string) time() . wp_rand(1000, 9999);
            $dup = false;
            foreach ($list as $addr) {
                if (isset($addr['id']) && (string) $addr['id'] === $id) {
                    $dup = true;
                    break;
                }
            }
        } while ($dup);
        return $id;
    }

    /**
     * 写回 usermeta。
     *
     * @param int   $uid
     * @param array $list
     */
    private static function persist($uid, $list)
    {
        update_user_meta((int) $uid, self::META_KEY, array_values($list));
    }
}

