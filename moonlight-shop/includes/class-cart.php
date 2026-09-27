<?php
/**
 * 购物车（基于 Cookie 的会话购物车，兼容游客）。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class MLSHOP_Cart
{
    private static $instance;
    private $cookie = 'mlshop_cart';
    private $items  = null;

    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
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

    public function add_item($product_id, $qty = 1)
    {
        $items = $this->read();
        $product_id = (int) $product_id;
        $qty = max(1, (int) $qty);
        if (isset($items[$product_id])) {
            $items[$product_id] += $qty;
        } else {
            $items[$product_id] = $qty;
        }
        $this->write($items);
    }

    public function set_qty($product_id, $qty)
    {
        $items = $this->read();
        $product_id = (int) $product_id;
        if ($qty <= 0) {
            unset($items[$product_id]);
        } else {
            $items[$product_id] = (int) $qty;
        }
        $this->write($items);
    }

    public function remove_item($product_id)
    {
        $items = $this->read();
        unset($items[(int) $product_id]);
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
        foreach ($items as $pid => $qty) {
            $product = get_post((int) $pid);
            if (!$product || $product->post_type !== 'mlshop_product') {
                continue;
            }
            $price = (float) get_post_meta($product->ID, '_mlshop_price', true);
            $result[] = array(
                'id'     => $product->ID,
                'title'  => $product->post_title,
                'qty'    => (int) $qty,
                'price'  => $price,
                'type'   => get_post_meta($product->ID, '_mlshop_type', true),
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
     * 計算運費（含實物商品且未達免運門檻時收取固定運費）。
     */
    public function get_shipping()
    {
        if (!MLSHOP_Shipping::enabled() || !$this->has_physical()) {
            return 0.0;
        }
        return MLSHOP_Shipping::calc($this->get_total(), true);
    }

    public function shortcode_cart()
    {
        ob_start();
        mlshop_get_template('cart', array(
            'items'    => $this->get_items(),
            'total'    => $this->get_total(),
            'shipping' => $this->get_shipping(),
        ));
        return ob_get_clean();
    }
}
