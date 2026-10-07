<?php
/**
 * moonlight/v1 REST API（Phase 1：公开路由 + 登录路由，docs/API.md 第二、三节）。
 *
 * 架构约定：
 *  - 单例，rest_api_init 注册全部路由；全部路由显式 permission_callback（绝不省略）；
 *  - 响应统一 Moonlight_Rest_Helpers::ok()/err()（docs/API.md 第六节）；
 *  - 业务逻辑复用服务层（Cart / Price_Calculator / Order / Payment / Card_Stock /
 *    Refund_Service / Address_Book），REST 只是能力的 REST 形态，不复刻 AJAX handler；
 *  - 参数经 register_rest_route args 声明式校验 + handler 内 sanitize；
 *  - 安全清单（API.md 第七节）：orders/downloads/license-keys/addresses 第一步属主校验；
 *    checkout 不接受任何前端金额字段（服务端全取价）；卡密输出只给掩码。
 *
 * 与旧 admin-ajax 的关系：全部保留不动，REST 为并行新通道。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class Moonlight_REST
{
    /** REST 命名空间。 */
    const NS = 'moonlight/v1';

    /** 单实例。 */
    private static $instance;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /* ================================================================
     * 路由注册（全部显式 permission_callback）
     * ================================================================ */

    public function register_routes()
    {
        $perm_public = '__return_true';
        $perm_login  = array($this, 'perm_logged_in');
        $perm_order  = array($this, 'perm_order_owner');

        /* ---------------- 公开路由（无需登录，只读） ---------------- */

        register_rest_route(self::NS, '/products', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_products'),
            'permission_callback' => $perm_public,
            'args'                => array(
                'page'     => array('default' => 1, 'sanitize_callback' => 'absint'),
                'per_page' => array('default' => 10, 'sanitize_callback' => 'absint'),
                'search'   => array('sanitize_callback' => 'sanitize_text_field'),
                'category' => array('sanitize_callback' => 'sanitize_text_field'),
                'type'     => array('sanitize_callback' => 'sanitize_key'),
                'orderby'  => array('default' => 'date', 'enum' => array('id', 'date', 'price')),
            ),
        ));

        register_rest_route(self::NS, '/products/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_product'),
            'permission_callback' => $perm_public,
            'args'                => array(
                'id' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route(self::NS, '/products/(?P<id>\d+)/price', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_product_price'),
            'permission_callback' => $perm_public,
            'args'                => array(
                'id' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route(self::NS, '/cart', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_cart'),
            'permission_callback' => $perm_public,
        ));

        register_rest_route(self::NS, '/shipping/quote', array(
            // GET + POST 双通道：items 经 body 传入（API.md 标注 GET，任务书允许 body items）
            'methods'             => 'GET, POST',
            'callback'            => array($this, 'rest_shipping_quote'),
            'permission_callback' => $perm_public,
            'args'                => array(
                'items'         => array('type' => 'array'),
                'coupon_code'   => array('sanitize_callback' => 'sanitize_text_field'),
                'shipping_mode' => array('sanitize_callback' => 'sanitize_key'),
            ),
        ));

        register_rest_route(self::NS, '/regions', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_regions'),
            'permission_callback' => $perm_public,
            'args'                => array(
                'province' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        /* ---------------- 登录路由（购物车写操作） ---------------- */

        register_rest_route(self::NS, '/cart/items', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_cart_add'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'product_id' => array('required' => true, 'sanitize_callback' => 'absint'),
                'qty'        => array('default' => 1, 'sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route(self::NS, '/cart/items/(?P<product_id>\d+)', array(
            'methods'             => 'PATCH',
            'callback'            => array($this, 'rest_cart_update'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'product_id' => array('sanitize_callback' => 'absint'),
                'qty'        => array('required' => true),
            ),
        ));

        register_rest_route(self::NS, '/cart/items/(?P<product_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'rest_cart_remove'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'product_id' => array('sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route(self::NS, '/cart', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'rest_cart_clear'),
            'permission_callback' => $perm_login,
        ));

        register_rest_route(self::NS, '/cart/coupon', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_cart_coupon'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'code' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        /* ---------------- 登录路由（结算 / 订单 / 交付 / 账户） ---------------- */

        register_rest_route(self::NS, '/checkout', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_checkout'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'gateway'       => array('required' => true, 'sanitize_callback' => 'sanitize_key'),
                'coupon_code'   => array('sanitize_callback' => 'sanitize_text_field'),
                'address_id'    => array('sanitize_callback' => 'sanitize_text_field'),
                'shipping_mode' => array('sanitize_callback' => 'sanitize_key'),
                'customer_note' => array('sanitize_callback' => 'sanitize_textarea_field'),
                'pickup_name'   => array('sanitize_callback' => 'sanitize_text_field'),
                'pickup_phone'  => array('sanitize_callback' => 'sanitize_text_field'),
                // 安全清单：不接受前端金额——price/total/amount 等字段即使传入也被忽略。
            ),
        ));

        register_rest_route(self::NS, '/orders', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_orders'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'page'     => array('default' => 1, 'sanitize_callback' => 'absint'),
                'per_page' => array('default' => 20, 'sanitize_callback' => 'absint'),
            ),
        ));

        register_rest_route(self::NS, '/orders/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_order'),
            'permission_callback' => $perm_order,
            'args'                => array(
                'id'    => array('sanitize_callback' => 'absint'),
                'token' => array('sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        register_rest_route(self::NS, '/orders/(?P<id>\d+)/cancel', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_order_cancel'),
            'permission_callback' => array($this, 'perm_order_owner_write'),
            'args'                => array('id' => array('sanitize_callback' => 'absint')),
        ));

        register_rest_route(self::NS, '/orders/(?P<id>\d+)/confirm', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_order_confirm'),
            'permission_callback' => array($this, 'perm_order_owner_write'),
            'args'                => array('id' => array('sanitize_callback' => 'absint')),
        ));

        register_rest_route(self::NS, '/orders/(?P<id>\d+)/refund', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_order_refund'),
            'permission_callback' => array($this, 'perm_order_owner_write'),
            'args'                => array(
                'id'     => array('sanitize_callback' => 'absint'),
                'reason' => array('required' => true, 'sanitize_callback' => 'sanitize_textarea_field'),
            ),
        ));

        register_rest_route(self::NS, '/downloads', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_downloads'),
            'permission_callback' => $perm_login,
        ));

        register_rest_route(self::NS, '/license-keys', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_license_keys'),
            'permission_callback' => $perm_login,
        ));

        register_rest_route(self::NS, '/license-keys/(?P<meta_id>\d+)/reveal', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_license_reveal'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'meta_id' => array('sanitize_callback' => 'absint'),
                'confirm' => array('required' => true),
            ),
        ));

        register_rest_route(self::NS, '/addresses', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_addresses_list'),
            'permission_callback' => $perm_login,
        ));

        register_rest_route(self::NS, '/addresses', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_address_save'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'name'       => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'phone'      => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'province'   => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'city'       => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'detail'     => array('required' => true, 'sanitize_callback' => 'sanitize_textarea_field'),
                'is_default' => array('type' => 'boolean'),
            ),
        ));

        register_rest_route(self::NS, '/addresses/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'PATCH',
            'callback'            => array($this, 'rest_address_update'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'id'         => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'name'       => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'phone'      => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'province'   => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'city'       => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'detail'     => array('required' => true, 'sanitize_callback' => 'sanitize_textarea_field'),
                'is_default' => array('type' => 'boolean'),
            ),
        ));

        register_rest_route(self::NS, '/addresses/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array($this, 'rest_address_delete'),
            'permission_callback' => $perm_login,
            'args'                => array(
                'id' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));

        register_rest_route(self::NS, '/account', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_account'),
            'permission_callback' => $perm_login,
        ));
    }

    /* ================================================================
     * 权限回调
     * ================================================================ */

    /**
     * 登录路由统一 permission：未登录 → WP_Error(401)。
     *
     * @return true|WP_Error
     */
    public function perm_logged_in()
    {
        return Moonlight_Rest_Helpers::require_login();
    }

    /**
     * 订单路由 permission：属主（管理员 / 所有者 / 游客令牌）。
     *
     * 游客令牌请求不要求登录：直接走 current_order_owner（内部 hash_equals
     * 校验令牌；登录订单对未登录访客一律 403），保证「游客凭合法令牌查看
     * 自己订单」的既定权限模型在 REST 与前端订单页行为一致。
     * 无令牌请求维持先登录再校验属主。
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public function perm_order_owner($request)
    {
        $token    = (string) $request->get_param('token');
        $order_id = (int) $request->get_param('id');
        if ('' !== $token) {
            return Moonlight_Rest_Helpers::current_order_owner($order_id, $token);
        }
        $login = Moonlight_Rest_Helpers::require_login();
        if (true !== $login) {
            return $login;
        }
        return Moonlight_Rest_Helpers::current_order_owner($order_id, '');
    }

    /**
     * 订单写路由 permission：仅属主 / 管理员（审计 P2 收紧）。
     *
     * 游客访问令牌仅用于只读查看（/orders/{id} 与下载），改单（取消 / 确认收货）
     * 与售后（退款申请）一律要求登录身份，防止泄露的 URL 令牌被用于改单 / 售后。
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public function perm_order_owner_write($request)
    {
        $login = Moonlight_Rest_Helpers::require_login();
        if (true !== $login) {
            return $login;
        }
        $order_id = (int) $request->get_param('id');
        $user_id  = get_current_user_id();
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        if ($order_id && (int) get_post_meta($order_id, '_mlshop_user_id', true) === $user_id) {
            return true;
        }
        return new WP_Error(
            'mlshop_rest_forbidden',
            __('You are not allowed to modify this order.', 'at8-moonlight-shop'),
            array('status' => 403)
        );
    }

    /* ================================================================
     * 公开路由 handler
     * ================================================================ */

    /**
     * GET /products：商品列表（只返回 publish 的公开字段）。
     */
    public function rest_products($request)
    {
        $p       = Moonlight_Rest_Helpers::pagination_params($request);
        $search  = sanitize_text_field((string) $request->get_param('search'));
        $type    = sanitize_key((string) $request->get_param('type'));
        $orderby = (string) $request->get_param('orderby');
        if (!in_array($orderby, array('id', 'date', 'price'), true)) {
            $orderby = 'date';
        }
        $category = sanitize_text_field((string) $request->get_param('category'));

        $args = array(
            'post_type'      => 'mlshop_product',
            'post_status'    => 'publish',
            'posts_per_page' => 500, // 上限保护；精确分页在格式化后于内存完成
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ('' !== $search) {
            $args['s'] = $search;
        }
        if ('' !== $category) {
            // 分类走 taxonomy slug（生产环境 WP_Query 命中；测试环境由下方 PHP 过滤兜底）
            $args['tax_query'] = array(array(
                'taxonomy' => 'mlshop_product_cat',
                'field'    => 'slug',
                'terms'    => $category,
            ));
        }

        $items = array();
        foreach ((array) get_posts($args) as $post) {
            $items[] = self::format_product($post);
        }

        // PHP 侧二次过滤（搜索按标题、类型按 meta）：保证任意环境行为一致
        if ('' !== $search) {
            $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
            $items = array_values(array_filter($items, function ($it) use ($needle) {
                $title = function_exists('mb_strtolower') ? mb_strtolower((string) $it['title'], 'UTF-8') : strtolower((string) $it['title']);
                return false !== strpos($title, $needle);
            }));
        }
        if ('' !== $type) {
            $items = array_values(array_filter($items, function ($it) use ($type) {
                return (string) $it['type'] === $type;
            }));
        }
        if ('' !== $category && function_exists('get_the_terms')) {
            $items = array_values(array_filter($items, function ($it) use ($category) {
                foreach ((array) $it['categories'] as $c) {
                    if ((string) $c['slug'] === $category) {
                        return true;
                    }
                }
                return false;
            }));
        }

        // 排序（白名单：id / date / price），PHP 侧统一执行
        if ('price' === $orderby) {
            usort($items, function ($a, $b) { return $a['price'] <=> $b['price']; });
        } elseif ('id' === $orderby) {
            usort($items, function ($a, $b) { return $a['id'] <=> $b['id']; });
        } else {
            usort($items, function ($a, $b) { return $b['id'] <=> $a['id']; }); // date DESC ≈ 新品在前
        }

        $total = count($items);
        $slice = array_slice($items, $p['offset'], $p['per_page']);
        return Moonlight_Rest_Helpers::ok($slice, array(
            'total'    => $total,
            'pages'    => (int) ceil($total / max(1, $p['per_page'])),
            'page'     => $p['page'],
            'per_page' => $p['per_page'],
        ));
    }

    /**
     * GET /products/{id}：商品详情（404 处理；绝不输出卡密 / 文件 / 付费内容配置）。
     */
    public function rest_product($request)
    {
        $post = get_post((int) $request->get_param('id'));
        if (!$post || 'mlshop_product' !== $post->post_type || 'publish' !== $post->post_status) {
            return Moonlight_Rest_Helpers::err('moonlight_product_not_found', __('商品不存在。', 'at8-moonlight-shop'), 404);
        }
        return Moonlight_Rest_Helpers::ok(self::format_product($post));
    }

    /**
     * GET /products/{id}/price：当前用户视角价格（会员价计算器）。
     */
    public function rest_product_price($request)
    {
        $post = get_post((int) $request->get_param('id'));
        if (!$post || 'mlshop_product' !== $post->post_type || 'publish' !== $post->post_status) {
            return Moonlight_Rest_Helpers::err('moonlight_product_not_found', __('商品不存在。', 'at8-moonlight-shop'), 404);
        }
        $price = Moonlight_Price_Calculator::product_price((int) $post->ID);
        return Moonlight_Rest_Helpers::ok(array(
            'id'         => (int) $post->ID,
            'price'      => $price,
            'price_html' => mlshop_format_price($price),
        ));
    }

    /**
     * GET /cart：购物车内容（Cookie 会话，游客可用）。
     */
    public function rest_cart($request)
    {
        $cart  = MLSHOP_Cart::get_instance();
        $items = $cart->get_items();
        $total = $cart->get_total();
        return Moonlight_Rest_Helpers::ok(array(
            'items'      => $items,
            'count'      => $cart->get_count(),
            'total'      => round((float) $total, 2),
            'total_html' => mlshop_format_price($total),
        ));
    }

    /**
     * GET/POST /shipping/quote：运费试算（只算不收；items 缺省取当前购物车）。
     */
    public function rest_shipping_quote($request)
    {
        $raw   = $request->get_param('items');
        $mode  = sanitize_key((string) $request->get_param('shipping_mode'));
        $coupon = sanitize_text_field((string) $request->get_param('coupon_code'));
        if (is_array($raw) && !empty($raw)) {
            $items = self::build_quote_items($raw);
        } else {
            $items = MLSHOP_Cart::get_instance()->get_items();
        }
        $quote = Moonlight_Price_Calculator::quote($items, $coupon, array(
            'shipping_mode' => ('pickup' === $mode) ? 'pickup' : 'ship',
        ));
        return Moonlight_Rest_Helpers::ok($quote);
    }

    /**
     * GET /regions：省市区数据（Region Provider）。
     */
    public function rest_regions($request)
    {
        $province  = strtoupper(trim((string) $request->get_param('province')));
        $provinces = Moonlight_Region_Provider::provinces();
        if ('' !== $province) {
            return Moonlight_Rest_Helpers::ok(array(
                'provinces' => $provinces,
                'cities'    => Moonlight_Region_Provider::cities($province),
            ));
        }
        $cities = array();
        foreach ($provinces as $code => $name) {
            $c = Moonlight_Region_Provider::cities($code);
            if (!empty($c)) {
                $cities[$code] = $c;
            }
        }
        return Moonlight_Rest_Helpers::ok(array(
            'provinces' => $provinces,
            'cities'    => $cities,
        ));
    }

    /* ================================================================
     * 登录路由 handler：购物车写操作
     * ================================================================ */

    /**
     * POST /cart/items {product_id, qty}：加购（夹紧 1..999 + publish + 库存预检，
     * 与 MLSHOP_Ajax::add_to_cart 同语义）。
     */
    public function rest_cart_add($request)
    {
        // 支持可变商品 key："1358" 或 "1358_three_year"（变体合法性在 Cart 内校验）
        $raw        = sanitize_text_field((string) $request->get_param('product_id'));
        $product_id = (int) $raw;
        $qty        = (int) $request->get_param('qty');
        $check = self::validate_cart_product($product_id);
        if (true !== $check) {
            return $check; // WP_REST_Response（404/400）
        }
        if ($qty <= 0) {
            return Moonlight_Rest_Helpers::err('moonlight_qty_invalid', __('购买数量必须大于 0。', 'at8-moonlight-shop'), 400);
        }
        $qty = max(1, min(999, $qty));

        $cart    = MLSHOP_Cart::get_instance();
        $in_cart = 0;
        foreach ($cart->get_items() as $it) {
            if (isset($it['id']) && (int) $it['id'] === $product_id) {
                $in_cart += (int) $it['qty'];
            }
        }
        // 库存预检（_mlshop_stock 空/0 = 不限量），与 AJAX 同口径
        $stock = (int) get_post_meta($product_id, '_mlshop_stock', true);
        if ($stock > 0) {
            if ($in_cart >= $stock) {
                /* translators: %d: 数量 */
                return Moonlight_Rest_Helpers::err('moonlight_stock_insufficient', sprintf(__('库存不足，仅剩 %d 件。', 'at8-moonlight-shop'), $stock), 409);
            }
            if ($in_cart + $qty > $stock) {
                $qty = $stock - $in_cart; // 夹紧到剩余可购数量
            }
        }

        $cart->add_item($raw, $qty);
        return Moonlight_Rest_Helpers::ok(array(
            'count' => $cart->get_count(),
            'qty'   => $qty,
        ));
    }

    /**
     * PATCH /cart/items/{product_id} {qty}：改数量（0 = 删除，与 AJAX update_cart 同语义）。
     */
    public function rest_cart_update($request)
    {
        $product_id = sanitize_text_field((string) $request->get_param('product_id'));
        $qty        = (int) $request->get_param('qty');
        $check = self::validate_cart_product((int) $product_id);
        if (true !== $check) {
            return $check; // WP_REST_Response（404/400）
        }
        if ($qty < 0) {
            return Moonlight_Rest_Helpers::err('moonlight_qty_invalid', __('数量不能为负数。', 'at8-moonlight-shop'), 400);
        }
        $qty = max(0, min(999, $qty));
        if ($qty > 0) {
            // 库存上限夹紧（与 AJAX 同口径）
            $stock = (int) get_post_meta($product_id, '_mlshop_stock', true);
            if ($stock > 0 && $qty > $stock) {
                $qty = $stock;
            }
        }
        MLSHOP_Cart::get_instance()->set_qty($product_id, $qty);
        return Moonlight_Rest_Helpers::ok(array(
            'count' => MLSHOP_Cart::get_instance()->get_count(),
            'qty'   => $qty,
        ));
    }

    /**
     * DELETE /cart/items/{product_id}：删除单条。
     */
    public function rest_cart_remove($request)
    {
        $product_id = (int) $request->get_param('product_id');
        if (!$product_id) {
            return Moonlight_Rest_Helpers::err('moonlight_param_invalid', __('缺少商品 ID。', 'at8-moonlight-shop'), 400);
        }
        MLSHOP_Cart::get_instance()->remove_item($product_id);
        return Moonlight_Rest_Helpers::ok(array(
            'count' => MLSHOP_Cart::get_instance()->get_count(),
        ));
    }

    /**
     * DELETE /cart：清空购物车。
     */
    public function rest_cart_clear($request)
    {
        MLSHOP_Cart::get_instance()->clear();
        return Moonlight_Rest_Helpers::ok(array('count' => 0));
    }

    /**
     * POST /cart/coupon {code}：校验 + 折扣预览（不 reserve 名额）。
     */
    public function rest_cart_coupon($request)
    {
        if (!class_exists('MLSHOP_Coupon')) {
            return Moonlight_Rest_Helpers::err('moonlight_coupon_unavailable', __('优惠模块不可用。', 'at8-moonlight-shop'), 400);
        }
        $code = sanitize_text_field((string) $request->get_param('code'));
        if ('' === $code) {
            return Moonlight_Rest_Helpers::err('moonlight_coupon_empty', __('请输入优惠码。', 'at8-moonlight-shop'), 400);
        }
        $cart     = MLSHOP_Cart::get_instance();
        $items    = $cart->get_items();
        $subtotal = $cart->get_total();
        $cid = MLSHOP_Coupon::validate($code, $subtotal, $items);
        if (is_wp_error($cid)) {
            return Moonlight_Rest_Helpers::err('moonlight_coupon_invalid', $cid->get_error_message(), 400);
        }
        $discount     = (float) MLSHOP_Coupon::compute_discount($cid, $subtotal);
        $after        = round($subtotal - $discount, 2);
        $has_physical = MLSHOP_Shipping::has_physical($items);
        $shipping     = ($has_physical && MLSHOP_Shipping::enabled()) ? (float) MLSHOP_Shipping::calc_for_items($items, $after) : 0.0;
        $final        = round($after + $shipping, 2);
        return Moonlight_Rest_Helpers::ok(array(
            'code'          => strtoupper(trim($code)),
            'discount'      => $discount,
            'subtotal'      => round((float) $subtotal, 2),
            'shipping'      => $shipping,
            'total'         => $final,
            'discount_text' => mlshop_format_price($discount),
            'total_text'    => mlshop_format_price($final),
        ));
    }

    /* ================================================================
     * 登录路由 handler：结算
     * ================================================================ */

    /**
     * POST /checkout {gateway, coupon_code?, address_id?, shipping_mode?, customer_note?}：
     * 服务端全取价下单（校验链与 AJAX place_order 一致：Cart::get_items →
     * create_from_cart 内部走 Price_Calculator::quote → 原子扣库存）。
     *
     * 安全要点：
     *  - 本 handler 从不读取 price/total/amount 等金额字段（前端传了也被忽略）；
     *  - gateway 必须在 mlshop_enabled_gateways 白名单内；
     *  - 游客 checkout 不在 REST 提供（游客走现有 AJAX 路径）；
     *  - 实物订单配送地址只能来自地址簿（防前端篡改，与 AJAX「地址簿覆盖表单」同口径）。
     */
    public function rest_checkout($request)
    {
        $uid        = get_current_user_id();
        $gateway_id = sanitize_key((string) $request->get_param('gateway'));
        $enabled    = self::enabled_gateway_ids();
        if ('' === $gateway_id || !in_array($gateway_id, $enabled, true)) {
            return Moonlight_Rest_Helpers::err('moonlight_gateway_forbidden', __('该支付方式暂未开放。', 'at8-moonlight-shop'), 403);
        }
        $gateway = self::payment_gateway($gateway_id);
        if (!$gateway) {
            return Moonlight_Rest_Helpers::err('moonlight_gateway_forbidden', __('该支付方式暂未开放。', 'at8-moonlight-shop'), 403);
        }

        $coupon_code = sanitize_text_field((string) $request->get_param('coupon_code'));
        $items = MLSHOP_Cart::get_instance()->get_items();
        if (empty($items)) {
            return Moonlight_Rest_Helpers::err('moonlight_cart_empty', __('购物车为空。', 'at8-moonlight-shop'), 400);
        }

        // 配送方式：以服务端购物车条目判定实物；自提开关未开启时忽略客户端值。
        $has_physical = MLSHOP_Shipping::has_physical($items);
        $shipping_mode = 'ship';
        if ($has_physical
            && 'pickup' === sanitize_key((string) $request->get_param('shipping_mode'))
            && MLSHOP_Shipping::pickup_enabled()) {
            $shipping_mode = 'pickup';
        }

        $note = sanitize_textarea_field((string) $request->get_param('customer_note'));
        $shipping_address = array();
        if ($has_physical) {
            if ('pickup' === $shipping_mode) {
                // 自提：仅需提货人姓名 + 手机号（免运费），与 AJAX 同校验
                $pickup_name  = sanitize_text_field((string) $request->get_param('pickup_name'));
                $pickup_phone = sanitize_text_field((string) $request->get_param('pickup_phone'));
                if ('' === $pickup_name || '' === $pickup_phone) {
                    return Moonlight_Rest_Helpers::err('moonlight_pickup_required', __('请填写提货人姓名与手机号。', 'at8-moonlight-shop'), 400);
                }
                $shipping_address = array(
                    'name'   => $pickup_name,
                    'phone'  => $pickup_phone,
                    'note'   => $note,
                    'pickup' => 1,
                );
            } else {
                // 快递配送：REST 只接受地址簿 id（不接受裸地址字段，防篡改）
                $address_id = sanitize_text_field((string) $request->get_param('address_id'));
                if ('' === $address_id) {
                    return Moonlight_Rest_Helpers::err('moonlight_address_required', __('实物订单需选择收货地址。', 'at8-moonlight-shop'), 400);
                }
                $saved = class_exists('Moonlight_Address_Book') ? Moonlight_Address_Book::get($uid, $address_id) : false;
                if (!$saved) {
                    return Moonlight_Rest_Helpers::err('moonlight_address_not_found', __('所选地址不存在，请重新选择。', 'at8-moonlight-shop'), 404);
                }
                $shipping_address = array(
                    'name'          => $saved['name'],
                    'phone'         => $saved['phone'],
                    'province'      => $saved['province'],
                    'province_name' => isset($saved['province_name']) ? $saved['province_name'] : '',
                    'city'          => $saved['city'],
                    'city_name'     => isset($saved['city_name']) ? $saved['city_name'] : '',
                    'address'       => $saved['detail'],
                    'note'          => $note,
                );
            }
        }

        $order_id = MLSHOP_Order::create_from_cart($uid, $gateway_id, $coupon_code, $shipping_address, array(
            'shipping_mode' => $shipping_mode,
        ));
        if (is_wp_error($order_id)) {
            $status = ('stock' === $order_id->get_error_code()) ? 409 : 400;
            return Moonlight_Rest_Helpers::err('moonlight_order_create', $order_id->get_error_message(), $status);
        }

        $order_no = (string) get_post_meta($order_id, '_mlshop_order_no', true);
        $payment  = $gateway->process_payment($order_id);
        if (!is_array($payment)) {
            $payment = array('success' => false, 'message' => __('支付发起失败。', 'at8-moonlight-shop'));
        }
        return Moonlight_Rest_Helpers::ok(array(
            'order_id' => (int) $order_id,
            'order_no' => $order_no,
            'payment'  => $payment,
        ));
    }

    /* ================================================================
     * 登录路由 handler：订单
     * ================================================================ */

    /**
     * GET /orders：本人订单列表（服务端强制 user = current_user）。
     */
    public function rest_orders($request)
    {
        $p      = Moonlight_Rest_Helpers::pagination_params($request, 20);
        $orders = MLSHOP_Order::get_user_orders(get_current_user_id(), 100);
        $list   = array();
        foreach ((array) $orders as $order) {
            $list[] = self::format_order_summary($order->ID);
        }
        usort($list, function ($a, $b) { return $b['id'] <=> $a['id']; }); // 新单在前
        $total = count($list);
        $slice = array_slice($list, $p['offset'], $p['per_page']);
        return Moonlight_Rest_Helpers::ok($slice, array(
            'total'    => $total,
            'pages'    => (int) ceil($total / max(1, $p['per_page'])),
            'page'     => $p['page'],
            'per_page' => $p['per_page'],
        ));
    }

    /**
     * GET /orders/{id}：订单详情（属主校验在 permission_callback；
     * delivery 摘要：卡密只给掩码，下载给出一次性 URL）。
     */
    public function rest_order($request)
    {
        $order_id = (int) $request->get_param('id');
        MLSHOP_Order::maybe_expire($order_id); // 惰性过期（与订单短代码同口径）
        $data = self::format_order_summary($order_id);
        $data['delivery'] = self::format_delivery($order_id);
        return Moonlight_Rest_Helpers::ok($data);
    }

    /**
     * POST /orders/{id}/cancel：取消（仅 pending / awaiting_payment）。
     */
    public function rest_order_cancel($request)
    {
        $order_id = (int) $request->get_param('id');
        $status   = MLSHOP_Order::get_status($order_id);
        // awaiting_payment 为对外语义名（本插件内即 pending 待付款）；
        // 保留别名以兼容第三方定义的 awaiting_payment 状态。
        if (!in_array($status, array('pending', 'awaiting_payment'), true)) {
            return Moonlight_Rest_Helpers::err('moonlight_status_conflict', __('当前订单状态不支持取消。', 'at8-moonlight-shop'), 409);
        }
        $res = MLSHOP_Order::set_status($order_id, 'cancelled');
        if (is_wp_error($res)) {
            return Moonlight_Rest_Helpers::err('moonlight_status_conflict', $res->get_error_message(), 409);
        }
        return Moonlight_Rest_Helpers::ok(array('status' => 'cancelled'));
    }

    /**
     * POST /orders/{id}/confirm：确认收货（delivered → completed，
     * 复用 AJAX confirm_delivery 校验链：属主已在 permission 层校验）。
     */
    public function rest_order_confirm($request)
    {
        $order_id = (int) $request->get_param('id');
        if (MLSHOP_Order::get_status($order_id) !== 'delivered') {
            return Moonlight_Rest_Helpers::err('moonlight_status_conflict', __('当前订单状态不支持确认收货。', 'at8-moonlight-shop'), 409);
        }
        $res = MLSHOP_Order::set_status($order_id, 'completed');
        if (is_wp_error($res)) {
            return Moonlight_Rest_Helpers::err('moonlight_status_conflict', $res->get_error_message(), 409);
        }
        return Moonlight_Rest_Helpers::ok(array('status' => 'completed'));
    }

    /**
     * POST /orders/{id}/refund {reason}：申请售后（属主；走 Refund_Service::apply）。
     */
    public function rest_order_refund($request)
    {
        $order_id = (int) $request->get_param('id');
        if (!class_exists('Moonlight_Refund_Service')) {
            return Moonlight_Rest_Helpers::err('moonlight_refund_unavailable', __('售后模块不可用。', 'at8-moonlight-shop'), 400);
        }
        $reason = sanitize_textarea_field((string) $request->get_param('reason'));
        $res = Moonlight_Refund_Service::apply($order_id, get_current_user_id(), $reason);
        if (is_wp_error($res)) {
            return Moonlight_Rest_Helpers::err('moonlight_refund_rejected', $res->get_error_message(), 400);
        }
        return Moonlight_Rest_Helpers::ok(array('status' => 'pending'));
    }

    /* ================================================================
     * 登录路由 handler：交付（下载 / 卡密）
     * ================================================================ */

    /**
     * GET /downloads：本人交付的下载列表（token / 产品 / 剩余次数），
     * 遍历口径与 MLSHOP_Download::shortcode_downloads 一致。
     */
    public function rest_downloads($request)
    {
        $orders = MLSHOP_Order::get_user_orders(get_current_user_id(), 50);
        $out = array();
        foreach ((array) $orders as $order) {
            $delivery = get_post_meta($order->ID, '_mlshop_delivery', true);
            if (!is_array($delivery)) {
                continue;
            }
            foreach ($delivery as $d) {
                if (!is_array($d) || !isset($d['type']) || 'download' !== $d['type']) {
                    continue;
                }
                $token  = (string) ($d['token'] ?? '');
                $info   = $token ? get_transient('mlshop_dl_' . $token) : false;
                $max    = is_array($info) ? (int) $info['max'] : 0;
                $used   = is_array($info) ? (int) $info['used'] : 0;
                $out[]  = array(
                    'order_id'   => (int) $order->ID,
                    'product_id' => (int) ($d['product_id'] ?? 0),
                    'title'      => (string) get_the_title((int) ($d['product_id'] ?? 0)),
                    'token'      => $token,
                    'url'        => add_query_arg('mlshop_download', $token, home_url()),
                    'max'        => $max,
                    'used'       => $used,
                    'left'       => $max > 0 ? max(0, $max - $used) : 0,
                );
            }
        }
        return Moonlight_Rest_Helpers::ok($out);
    }

    /**
     * GET /license-keys：本人已售卡密列表（只给掩码 + meta_id，永不输出明文）。
     */
    public function rest_license_keys($request)
    {
        $uid  = get_current_user_id();
        $rows = static::find_sold_rows_for_user($uid);
        $out  = array();
        foreach ((array) $rows as $row) {
            $rec    = is_array($row) ? json_decode((string) $row['meta_value'], true) : null;
            $plain  = Moonlight_Card_Stock::decrypt_record_json((string) $row['meta_value']);
            $out[]  = array(
                'meta_id'    => (int) $row['meta_id'],
                'product_id' => self::product_id_for_batch((int) $row['post_id']),
                'order_id'   => is_array($rec) ? (int) ($rec['o'] ?? 0) : 0,
                'status'     => (string) $row['meta_key'],
                'masked'     => (false === $plain) ? '' : Moonlight_Card_Stock::mask_key($plain),
            );
        }
        return Moonlight_Rest_Helpers::ok($out);
    }

    /**
     * POST /license-keys/{meta_id}/reveal {confirm:1}：明文解密（属主 + 二次确认
     * + 每分钟限流 + 审计）。
     *
     * 属主口径（与 Refund_Service::find_card_sold_rows 同一查询思路）：该行必须是
     * 已售行（JSON o=订单 ID），且订单 _mlshop_user_id = 当前用户（管理员放行）。
     */
    public function rest_license_reveal($request)
    {
        $meta_id = (int) $request->get_param('meta_id');
        if ($meta_id <= 0) {
            return Moonlight_Rest_Helpers::err('moonlight_license_not_found', __('卡密不存在。', 'at8-moonlight-shop'), 404);
        }
        // 二次确认：confirm 必须显式为 1（防误触 / 防批量爬取）
        $confirm = $request->get_param('confirm');
        if (1 !== $confirm && '1' !== (string) $confirm) {
            return Moonlight_Rest_Helpers::err('moonlight_reveal_confirm_required', __('请先确认后再查看卡密明文。', 'at8-moonlight-shop'), 400);
        }
        // 限流：同 user 每分钟 ≤ 10 次（transient 计数）
        if (!Moonlight_Rest_Helpers::rate_limit_ok('reveal', 10, 60)) {
            return Moonlight_Rest_Helpers::err('moonlight_rate_limited', __('操作过于频繁，请稍后再试。', 'at8-moonlight-shop'), 429);
        }

        $row = static::find_card_row($meta_id);
        if (!$row || !in_array((string) $row['meta_key'], Moonlight_Card_Stock::STATUS_KEYS, true)) {
            return Moonlight_Rest_Helpers::err('moonlight_license_not_found', __('卡密不存在。', 'at8-moonlight-shop'), 404);
        }
        $rec      = json_decode((string) $row['meta_value'], true);
        $order_id = (is_array($rec) && isset($rec['o'])) ? (int) $rec['o'] : 0;
        if ($order_id <= 0 || !get_post_meta($order_id, '_mlshop_user_id', true)) {
            // 未售出（o=0）或无归属订单的行：一律按无权处理，不暴露存在性差异细节
            return Moonlight_Rest_Helpers::err('moonlight_forbidden_license', __('无权查看该卡密。', 'at8-moonlight-shop'), 403);
        }
        if (!current_user_can('manage_options')
            && (int) get_post_meta($order_id, '_mlshop_user_id', true) !== get_current_user_id()) {
            return Moonlight_Rest_Helpers::err('moonlight_forbidden_license', __('无权查看该卡密。', 'at8-moonlight-shop'), 403);
        }

        $plain = Moonlight_Card_Stock::decrypt_record_json((string) $row['meta_value']);
        if (false === $plain) {
            return Moonlight_Rest_Helpers::err('moonlight_card_undecryptable', __('卡密解密失败，请联系站长。', 'at8-moonlight-shop'), 500);
        }
        $product_id = self::product_id_for_batch((int) $row['post_id']);
        // 审计：前台明文解密必须留痕（复用 Card_Stock 环形审计）
        Moonlight_Card_Stock::audit($meta_id, 'reveal_front', array(
            'product' => $product_id,
            'order'   => $order_id,
        ));
        return Moonlight_Rest_Helpers::ok(array(
            'meta_id'    => $meta_id,
            'key'        => $plain,
            'masked'     => Moonlight_Card_Stock::mask_key($plain),
            'order_id'   => $order_id,
            'product_id' => $product_id,
        ));
    }

    /* ================================================================
     * 登录路由 handler：地址簿（属主恒 get_current_user_id()）
     * ================================================================ */

    /**
     * GET /addresses：地址列表。
     */
    public function rest_addresses_list($request)
    {
        return Moonlight_Rest_Helpers::ok(array(
            'list' => Moonlight_Address_Book::get_list(get_current_user_id()),
        ));
    }

    /**
     * POST /addresses：新增（字段校验在 Address_Book::save，区码走 Region Provider）。
     */
    public function rest_address_save($request)
    {
        return $this->do_address_save($request, '');
    }

    /**
     * PATCH /addresses/{id}：编辑（URL id 优先于 body id，防错位）。
     */
    public function rest_address_update($request)
    {
        return $this->do_address_save($request, (string) $request->get_param('id'));
    }

    /**
     * DELETE /addresses/{id}：删除（仅自己的）。
     */
    public function rest_address_delete($request)
    {
        $uid = get_current_user_id();
        $id  = (string) $request->get_param('id');
        if ('' === $id) {
            return Moonlight_Rest_Helpers::err('moonlight_param_invalid', __('缺少地址 ID。', 'at8-moonlight-shop'), 400);
        }
        if (!Moonlight_Address_Book::delete($uid, $id)) {
            return Moonlight_Rest_Helpers::err('moonlight_address_not_found', __('地址不存在或已删除。', 'at8-moonlight-shop'), 404);
        }
        return Moonlight_Rest_Helpers::ok(array(
            'list' => Moonlight_Address_Book::get_list($uid),
        ));
    }

    /* ================================================================
     * 登录路由 handler：账户聚合
     * ================================================================ */

    /**
     * GET /account：会员等级 / 到期、积分余额、订单计数、地址数聚合。
     */
    public function rest_account($request)
    {
        $uid   = get_current_user_id();
        $level = Moonlight_Price_Calculator::member_level($uid);
        $expires = 0;
        if (class_exists('MLUC_Membership') && method_exists('MLUC_Membership', 'get_user_expires')) {
            $expires = (int) MLUC_Membership::get_user_expires($uid);
        }
        $credit = class_exists('MLSHOP_Credit')
            ? (float) MLSHOP_Credit::get_balance($uid)
            : (float) get_user_meta($uid, 'mlshop_credit_balance', true);

        $orders = MLSHOP_Order::get_user_orders($uid, 100);
        $total  = 0;
        $active = 0;
        $revenue_statuses = MLSHOP_Order::get_revenue_statuses();
        foreach ((array) $orders as $order) {
            $total++;
            if (in_array(MLSHOP_Order::get_status($order->ID), $revenue_statuses, true)) {
                $active++;
            }
        }
        return Moonlight_Rest_Helpers::ok(array(
            'member_level'    => (string) $level,
            'member_expires'  => $expires,
            'credit_balance'  => $credit,
            'order_total'     => $total,
            'order_active'    => $active,
            'address_count'   => count(Moonlight_Address_Book::get_list($uid)),
        ));
    }

    /* ================================================================
     * 内部辅助（protected static，测试可子类覆盖）
     * ================================================================ */

    /**
     * 商品公开字段格式化器（绝不输出卡密 / 文件 / 付费内容配置字段）。
     *
     * @param object $post
     * @return array
     */
    protected static function format_product($post)
    {
        $pid   = (int) $post->ID;
        $price = Moonlight_Price_Calculator::product_price($pid);
        $type  = (string) get_post_meta($pid, '_mlshop_type', true);

        $stock_status = 'instock';
        if ('cardkey' === $type && class_exists('Moonlight_Card_Stock')) {
            $stock_status = (Moonlight_Card_Stock::available($pid) > 0) ? 'instock' : 'outofstock';
        }

        $images = array();
        if (function_exists('has_post_thumbnail') && has_post_thumbnail($pid)) {
            $thumb = (int) get_post_thumbnail_id($pid);
            if ($thumb > 0) {
                $images[] = $thumb;
            }
        }
        foreach (mlshop_parse_gallery_ids(get_post_meta($pid, '_mlshop_gallery', true)) as $gid) {
            if (!in_array($gid, $images, true)) {
                $images[] = $gid;
            }
        }

        $categories = array();
        if (function_exists('get_the_terms')) {
            $terms = get_the_terms($pid, 'mlshop_product_cat');
            if (is_array($terms)) {
                foreach ($terms as $t) {
                    $categories[] = array(
                        'id'   => (int) $t->term_id,
                        'name' => (string) $t->name,
                        'slug' => (string) $t->slug,
                    );
                }
            }
        }

        return array(
            'id'           => $pid,
            'title'        => (string) $post->post_title,
            'price'        => $price,
            'price_html'   => mlshop_format_price($price),
            'type'         => $type,
            'sku'          => (string) get_post_meta($pid, '_mlshop_sku', true),
            'stock_status' => $stock_status,
            'categories'   => $categories,
            'images'       => $images,
            'permalink'    => function_exists('get_permalink') ? get_permalink($pid) : '',
        );
    }

    /**
     * 运费试算条目构造：product_id/qty → 服务端取价 + publish 校验（不信任前端价格）。
     *
     * @param array $raw [{product_id, qty}, ...]
     * @return array
     */
    protected static function build_quote_items($raw)
    {
        $out = array();
        foreach ((array) $raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            $qty = isset($row['qty']) ? (int) $row['qty'] : 0;
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $post = get_post($pid);
            if (!$post || 'mlshop_product' !== $post->post_type || 'publish' !== $post->post_status) {
                continue;
            }
            $price = Moonlight_Price_Calculator::product_price($pid);
            $out[] = array(
                'id'       => $pid,
                'qty'      => $qty,
                'price'    => $price,
                'type'     => (string) get_post_meta($pid, '_mlshop_type', true),
                'subtotal' => $price * $qty,
            );
        }
        return $out;
    }

    /**
     * 加购 / 改量共用的商品校验（存在 + 类型 + 已发布）。
     *
     * @param int $product_id
     * @return true|WP_REST_Response
     */
    protected static function validate_cart_product($product_id)
    {
        if ($product_id <= 0) {
            return Moonlight_Rest_Helpers::err('moonlight_param_invalid', __('商品不存在。', 'at8-moonlight-shop'), 400);
        }
        $post = get_post($product_id);
        if (!$post || 'mlshop_product' !== $post->post_type) {
            return Moonlight_Rest_Helpers::err('moonlight_product_not_found', __('商品不存在。', 'at8-moonlight-shop'), 404);
        }
        if ('publish' !== $post->post_status) {
            return Moonlight_Rest_Helpers::err('moonlight_product_unavailable', __('商品已下架或未发布。', 'at8-moonlight-shop'), 400);
        }
        return true;
    }

    /**
     * 订单列表/详情共用摘要字段。
     *
     * @param int $order_id
     * @return array
     */
    protected static function format_order_summary($order_id)
    {
        $order_id = (int) $order_id;
        $items    = get_post_meta($order_id, '_mlshop_items', true);
        $summary  = array();
        foreach ((array) $items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $qty = isset($it['qty']) ? (int) $it['qty'] : 0;
            $sub = isset($it['subtotal']) ? (float) $it['subtotal'] : ((isset($it['price']) ? (float) $it['price'] : 0.0) * $qty);
            $summary[] = array(
                'id'       => isset($it['id']) ? (int) $it['id'] : 0,
                'title'    => isset($it['title']) ? (string) $it['title'] : (string) get_the_title(isset($it['id']) ? (int) $it['id'] : 0),
                'qty'      => $qty,
                'subtotal' => round($sub, 2),
            );
        }
        $status = MLSHOP_Order::get_status($order_id);
        return array(
            'id'           => $order_id,
            'order_no'     => (string) get_post_meta($order_id, '_mlshop_order_no', true),
            'status'       => $status,
            'status_label' => MLSHOP_Order::get_status_label($status),
            'total'        => round((float) get_post_meta($order_id, '_mlshop_total', true), 2),
            'currency'     => (string) get_post_meta($order_id, '_mlshop_currency', true),
            'gateway'      => (string) get_post_meta($order_id, '_mlshop_gateway', true),
            'created'      => (string) get_post_meta($order_id, '_mlshop_created', true),
            'items'        => $summary,
        );
    }

    /**
     * 订单交付摘要：卡密只给掩码（绝不输出明文），下载给一次性 URL + 剩余次数。
     *
     * @param int $order_id
     * @return array
     */
    protected static function format_delivery($order_id)
    {
        $delivery = get_post_meta($order_id, '_mlshop_delivery', true);
        $out = array();
        foreach ((array) $delivery as $d) {
            if (!is_array($d) || !isset($d['type'])) {
                continue;
            }
            if ('cardkey' === $d['type']) {
                $out[] = array(
                    'type'       => 'cardkey',
                    'product_id' => (int) ($d['product_id'] ?? 0),
                    // 按 sold_meta_id（或旧明文回退）解密后强制掩码，绝不输出明文
                    'masked'     => Moonlight_Card_Stock::mask_key((string) mlshop_delivery_cardkey_plaintext($d)),
                );
            } elseif ('download' === $d['type']) {
                $token = (string) ($d['token'] ?? '');
                $info  = $token ? get_transient('mlshop_dl_' . $token) : false;
                $max   = is_array($info) ? (int) $info['max'] : 0;
                $used  = is_array($info) ? (int) $info['used'] : 0;
                $out[] = array(
                    'type'       => 'download',
                    'product_id' => (int) ($d['product_id'] ?? 0),
                    'url'        => add_query_arg('mlshop_download', $token, home_url()),
                    'max'        => $max,
                    'used'       => $used,
                    'left'       => $max > 0 ? max(0, $max - $used) : 0,
                );
            }
        }
        return $out;
    }

    /**
     * 批次文章 → 所属商品 ID。
     *
     * @param int $batch_id
     * @return int
     */
    protected static function product_id_for_batch($batch_id)
    {
        $post = get_post((int) $batch_id);
        return $post ? (int) $post->post_parent : 0;
    }

    /**
     * 按用户查已售卡密行（REST 列表数据源）。
     *
     * 查询思路与 Moonlight_Refund_Service::find_card_sold_rows 同源：售出行 JSON
     * 字段序固定为 {"seq",...,"o":<id>,"u":<uid>,"t":...}，以 '"u":<uid>,'
     * （尾随逗号防前缀误命中）做 LIKE 精确匹配；h/e/iv 均为 hex/base64，
     * 不可能含引号冒号，无误报面。
     *
     * @param int $uid
     * @return array[] 行模型 {meta_id, post_id, meta_key, meta_value}
     */
    protected static function find_sold_rows_for_user($uid)
    {
        global $wpdb;
        $uid = (int) $uid;
        if ($uid <= 0 || !class_exists('Moonlight_Card_Stock')) {
            return array();
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
             FROM {$wpdb->postmeta} AS pm
             INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
             WHERE p.post_type = %s AND pm.meta_key = %s
               AND pm.meta_value LIKE %s
             ORDER BY pm.meta_id ASC",
            Moonlight_Card_Stock::CPT,
            Moonlight_Card_Stock::ST_SOLD,
            '%"u":' . $uid . ',%'
        ), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    /**
     * 按 meta_id 取卡密行（reveal 数据源，protected static 便于测试子类接到行模型）。
     *
     * @param int $meta_id
     * @return array|null {meta_id, post_id, meta_key, meta_value}
     */
    protected static function find_card_row($meta_id)
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d",
                (int) $meta_id
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * 网关白名单（管理端「前台公开支付方式」），委托支付管理器。
     *
     * @return string[]
     */
    protected static function enabled_gateway_ids()
    {
        if (!class_exists('MLSHOP_Payment')) {
            return array();
        }
        return (array) MLSHOP_Payment::get_instance()->get_enabled_gateway_ids();
    }

    /**
     * 网关实例解析，委托支付管理器。
     *
     * @param string $gateway_id
     * @return MLSHOP_Gateway|null
     */
    protected static function payment_gateway($gateway_id)
    {
        if (!class_exists('MLSHOP_Payment')) {
            return null;
        }
        return MLSHOP_Payment::get_instance()->get_gateway((string) $gateway_id);
    }

    /**
     * 地址保存共用路径（POST 新增 / PATCH 编辑）。
     *
     * @param WP_REST_Request $request
     * @param string          $force_id URL 中的地址 id（PATCH；POST 传空 = 新增）
     * @return WP_REST_Response
     */
    private function do_address_save($request, $force_id)
    {
        $uid = get_current_user_id();
        $in  = array(
            'id'         => '' !== $force_id ? $force_id : sanitize_text_field((string) $request->get_param('id')),
            'name'       => sanitize_text_field((string) $request->get_param('name')),
            'phone'      => sanitize_text_field((string) $request->get_param('phone')),
            'province'   => sanitize_text_field((string) $request->get_param('province')),
            'city'       => sanitize_text_field((string) $request->get_param('city')),
            'detail'     => sanitize_textarea_field((string) $request->get_param('detail')),
            'is_default' => (bool) $request->get_param('is_default'),
        );
        $saved = Moonlight_Address_Book::save($uid, $in);
        if (is_wp_error($saved)) {
            return Moonlight_Rest_Helpers::err('moonlight_address_invalid', $saved->get_error_message(), 400);
        }
        return Moonlight_Rest_Helpers::ok(array(
            'address' => $saved,
            'list'    => Moonlight_Address_Book::get_list($uid),
        ));
    }
}

