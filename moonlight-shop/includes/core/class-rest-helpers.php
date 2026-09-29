<?php
/**
 * REST 统一响应与安全辅助（moonlight/v1，docs/API.md 第六节）。
 *
 * 职责（纯静态工具，无状态、无路由逻辑）：
 *  - ok() / err()   统一响应格式：成功 {code:'moonlight_ok', data, meta?}；
 *                   失败 {code, message, data:{status}}，HTTP 状态码语义化
 *                   （400 参数 / 401 未登录 / 403 属主或权限 / 404 / 409 状态冲突 / 429 限流）；
 *  - require_login()        登录路由 permission_callback 的统一门槛；
 *  - current_order_owner()  订单属主校验（IDOR 防线，对齐 class-payment.php 游客分支口径：
 *                   管理员 manage_options / 订单所有者 / 游客订单凭访问令牌 hash_equals）；
 *  - pagination_params()    分页参数读取与夹紧；
 *  - rate_limit_ok()        简易限流（user + bucket，transient 计数），reveal 类端点使用。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_Rest_Helpers
{
    /**
     * 成功响应（HTTP 200）：{code:'moonlight_ok', data, meta?}。
     *
     * @param array|mixed $data 业务数据
     * @param array|null  $meta 分页等元信息（null = 省略 meta 键）
     * @return WP_REST_Response
     */
    public static function ok($data = array(), $meta = null)
    {
        $body = array(
            'code' => 'moonlight_ok',
            'data' => $data,
        );
        if (null !== $meta) {
            $body['meta'] = $meta;
        }
        return new WP_REST_Response($body, 200);
    }

    /**
     * 失败响应：{code, message, data:{status}}，HTTP 状态码语义化。
     *
     * @param string $code    机器可读错误码（前端 i18n 映射）
     * @param string $message 人类可读消息
     * @param int    $status  HTTP 状态码（400/401/403/404/409/429/500）
     * @param array  $extra   data 内附加字段（可选）
     * @return WP_REST_Response
     */
    public static function err($code, $message, $status = 400, $extra = array())
    {
        $data = array('status' => (int) $status);
        if (is_array($extra) && !empty($extra)) {
            $data = array_merge($data, $extra);
        }
        return new WP_REST_Response(array(
            'code'    => (string) $code,
            'message' => (string) $message,
            'data'    => $data,
        ), (int) $status);
    }

    /**
     * 登录路由 permission_callback 的统一门槛。
     *
     * 浏览器端 wp_rest nonce 由 WP 核心 Cookie 认证链路校验，此处只判登录态。
     *
     * @return true|WP_Error（WP_Error 携带 status=401，REST 层按语义返回）
     */
    public static function require_login()
    {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'moonlight_not_logged_in',
                __('请先登录。', 'moonlight-shop'),
                array('status' => 401)
            );
        }
        return true;
    }

    /**
     * 订单属主校验（IDOR 防线）。
     *
     * 口径与 MLSHOP_Payment::can_view_order / AJAX wechat_query 归属校验一致：
     *  1. 管理员（manage_options）恒通过；
     *  2. 登录订单（_mlshop_user_id > 0）：仅订单所有者；
     *  3. 游客订单（user_id = 0）：凭订单页访问令牌（mlshop_verify_guest_token，
     *     hash_equals 时序安全），复用与回跳 / AJAX 同一 helper，不另立约定。
     *
     * @param int    $order_id    订单 ID
     * @param string $guest_token 游客订单访问令牌（可选，来自请求 token 参数）
     * @return true|WP_Error（404 订单不存在 / 403 非属主）
     */
    public static function current_order_owner($order_id, $guest_token = '')
    {
        $order_id = (int) $order_id;
        if (!$order_id || get_post_type($order_id) !== 'mlshop_order') {
            return new WP_Error(
                'moonlight_order_not_found',
                __('订单不存在。', 'moonlight-shop'),
                array('status' => 404)
            );
        }
        if (current_user_can('manage_options')) {
            return true;
        }
        $owner = (int) get_post_meta($order_id, '_mlshop_user_id', true);
        if ($owner > 0) {
            if ($owner === get_current_user_id()) {
                return true;
            }
            return new WP_Error(
                'moonlight_forbidden_order_owner',
                __('无权访问该订单。', 'moonlight-shop'),
                array('status' => 403)
            );
        }
        // 游客订单：必须携带有效令牌（无令牌一律拒绝，含登录用户）。
        if ('' !== (string) $guest_token && mlshop_verify_guest_token($order_id, (string) $guest_token)) {
            return true;
        }
        return new WP_Error(
            'moonlight_forbidden_order_owner',
            __('无权访问该订单。', 'moonlight-shop'),
            array('status' => 403)
        );
    }

    /**
     * 分页参数读取与夹紧（page/per_page）。
     *
     * @param WP_REST_Request $request
     * @param int $default_per_page 默认每页条数
     * @param int $max_per_page     每页上限（防大分页拖库）
     * @return array {page:int, per_page:int, offset:int}
     */
    public static function pagination_params($request, $default_per_page = 10, $max_per_page = 100)
    {
        $page = (int) $request->get_param('page');
        $page = $page > 0 ? $page : 1;
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) {
            $per_page = (int) $default_per_page;
        }
        if ($per_page > (int) $max_per_page) {
            $per_page = (int) $max_per_page;
        }
        return array(
            'page'     => $page,
            'per_page' => $per_page,
            'offset'   => ($page - 1) * $per_page,
        );
    }

    /**
     * 简易限流（下单 / reveal 类端点）：user + bucket 计数，transient 滑动窗口。
     *
     * 每次调用先检查后累加：达到上限返回 false（调用方回 429）。
     * 上限可经 moonlight_rest_rate_limit 过滤器按 bucket 覆盖（0 = 不限流）。
     *
     * @param string $bucket 限流桶名（如 'reveal'）
     * @param int    $max    窗口内最大次数（默认 10）
     * @param int    $window 窗口秒数（默认 60 = 每分钟）
     * @return bool true = 放行（并已计数）
     */
    public static function rate_limit_ok($bucket, $max = 10, $window = 60)
    {
        /**
         * REST 限流上限（按 bucket 覆盖，0 = 关闭该桶限流）。
         *
         * @param int    $max    默认上限
         * @param string $bucket 限流桶名
         */
        $max = (int) apply_filters('moonlight_rest_rate_limit', (int) $max, (string) $bucket);
        if ($max <= 0) {
            return true;
        }
        $uid = get_current_user_id();
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'mlshop_rl_' . sanitize_key((string) $bucket) . '_' . md5($uid . '|' . $ip);
        $count = (int) get_transient($key);
        if ($count >= $max) {
            return false;
        }
        set_transient($key, $count + 1, (int) $window);
        return true;
    }
}
