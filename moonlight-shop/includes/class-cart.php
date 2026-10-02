<?php
/**
 * 购物车（cookie 存储）。
 *
 * 支持可变商品（授权套餐选择）：key 可以是 "商品ID" 或 "商品ID_变体代码"
 * （如 "1358_three_year"）。变体合法性在写入时校验（白名单来自商品
 * _at8lic_variants），读取时二次校验，伪造 Cookie 无法带入非法变体。
 * get_items() 输出的每个条目带 key（写回购物车用）与 variant（履约用），
 * 变体价格经 MLSHOP_License_Bridge::variant_price()（USD 模式自动切美元价）。
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Cart
{
    private $cookie = 'mlshop_cart';
    private $items = null;

    public static function get_instance()
    {
        static $i = null;
        return $i ?: $i = new self();
    }

    private function __construct()
    {
        add_shortcode('mlshop_cart', array($this, 'shortcode_cart'));
    }

    private function read()
    {
        if ($this->items !== null) {
            return $this->items;
        }
        $raw = isset($_COOKIE[$this->cookie]) ? $_COOKIE[$this->cookie] : '';
        $this->items = $raw ? json_decode(stripslashes($raw), true) : array();
        if (!is_array($this->items)) {
            $this->items = array();
        }
        return $this->items;
    }

    private function write($items)
    {
        $this->items = $items;
        $value = json_encode($items);
        setcookie($this->cookie, $value, time() + 604800, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }

    /**
     * 归一化购物车 key："1358" / "1358_three_year"。
     * 变体不在商品白名单内时回落为纯商品 ID（基础套餐）。
     */
    private function normalize_key($key)
    {
        $key = sanitize_text_field((string) $key);
        $pid = (int) $key;
        if ($pid <= 0) {
            return '';
        }
        if ((string) $pid === $key) {
            return (string) $pid;
        }
        $variant = substr($key, strlen((string) $pid) + 1);
        return self::variant_valid($pid, $variant) ? $pid . '_' . $variant : (string) $pid;
    }

    /** 变体是否在该商品的可变选项白名单内 */
    public static function variant_valid($pid, $variant)
    {
        $variant = sanitize_key((string) $variant);
        if ($variant === '') {
            return false;
        }
        $variants = get_post_meta((int) $pid, '_at8lic_variants', true);
        if (!is_array($variants)) {
            return false;
        }
        foreach ($variants as $v) {
            if (isset($v['plan']) && sanitize_key($v['plan']) === $variant) {
                return true;
            }
        }
        return false;
    }

    public function add_item($product_id, $qty = 1)
    {
        $items = $this->read();
        $key = $this->normalize_key($product_id);
        if ($key === '') {
            return;
        }
        $qty = max(1, (int) $qty);
        if (isset($items[$key])) {
            $items[$key] += $qty;
        } else {
            $items[$key] = $qty;
        }
        $this->write($items);
    }

    public function set_qty($product_id, $qty)
    {
        $key = $this->normalize_key($product_id);
        if ($key === '') {
            return;
        }
        $items = $this->read();
        if ($qty <= 0) {
            unset($items[$key]);
        } else {
            $items[$key] = (int) $qty;
        }
        $this->write($items);
    }

    public function remove_item($product_id)
    {
        $key = $this->normalize_key($product_id);
        $items = $this->read();
        unset($items[$key]);
        $this->write($items);
    }

    public function clear()
    {
        $this->write(array());
    }

    public function get_items()
    {
        $items = $this->read();
        $result = array();
        foreach ($items as $key => $qty) {
            $pid = (int) $key;
            $product = get_post($pid);
            if (!$product || $product->post_type !== 'mlshop_product') {
                continue;
            }
            // 只允许已发布商品留在购物车：下架/草稿商品随读随剔除，
            // 防止伪造 Cookie 把下架商品带进结算（价格仍由服务端重算兜底）。
            if ('publish' !== $product->post_status) {
                continue;
            }
            $variant = '';
            if ((string) $pid !== (string) $key) {
                $candidate = substr((string) $key, strlen((string) $pid) + 1);
                if (self::variant_valid($pid, $candidate)) {
                    $variant = sanitize_key($candidate);
                }
            }

            $price = (float) get_post_meta($product->ID, '_mlshop_price', true);
            if ($variant !== '' && class_exists('MLSHOP_License_Bridge')) {
                $vprice = MLSHOP_License_Bridge::variant_price($product->ID, $variant);
                if ($vprice > 0) {
                    $price = (float) $vprice;
                }
            }

            $title = $product->post_title;
            if ($variant !== '' && class_exists('MLSHOP_License_Bridge')) {
                $title .= '（' . MLSHOP_License_Bridge::variant_label($variant) . '）';
            }

            $result[] = array(
                'key'      => $variant !== '' ? $pid . '_' . $variant : (string) $pid,
                'id'       => $product->ID,
                'variant'  => $variant,
                'title'    => $title,
                'qty'      => (int) $qty,
                'price'    => $price,
                'type'     => get_post_meta($product->ID, '_mlshop_type', true),
                'subtotal' => $price * (int) $qty,
            );
        }
        return $result;
    }

    public function get_count()
    {
        return array_sum(array_map('intval', $this->read()));
    }

    public function get_total()
    {
        $total = 0;
        foreach ($this->get_items() as $item) {
            $total += $item['subtotal'];
        }
        return $total;
    }

    /**
     * 購物車是否含實物商品。
     */
    public function has_physical()
    {
        return MLSHOP_Shipping::has_physical($this->get_items());
    }

    /**
     * 計算運費（模板感知：有模板走 template_calc，否則全局固定運費 + 滿額包郵）。
     */
    public function get_shipping()
    {
        if (!MLSHOP_Shipping::enabled() || !$this->has_physical()) {
            return 0.0;
        }
        return MLSHOP_Shipping::calc_for_items($this->get_items(), $this->get_total());
    }

    public function shortcode_cart()
    {
        // 缓存兼容：购物车为用户态内容，禁止页面缓存（计划书第五十九节）
        mlshop_no_cache();
        ob_start();
        mlshop_get_template('cart', array(
            'items'    => $this->get_items(),
            'total'    => $this->get_total(),
            'shipping' => $this->get_shipping(),
        ));
        return ob_get_clean();
    }
}
