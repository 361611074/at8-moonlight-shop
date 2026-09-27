=== 漫步白月光电子商城 ===

Contributors: 漫步白月光
Tags: shop, ecommerce, cart, checkout, digital, download, cardkey, elementor, astra
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: 漫步白月光
Author URI: https://www.at8.fun/

轻量、主题无关的电子商城系统，兼容 Astra 主题与 Elementor，与「漫步白月光用户中心」无缝集成。

== 功能 ==
* 商品自定义文章类型：实物 / 虚拟下载 / 卡密 三种类型
* 会话购物车（游客可用，Cookie 存储）
* 结算流程：订单创建 + 可扩展支付网关
* 支付网关：货到付款、余额支付、扫码/线下付款（占位，可扩展支付宝/微信）
* 虚拟商品：支付后自动生成安全下载链接（限时）
* 卡密商品：售出后从卡密池自动分配并扣减库存
* 订单管理：自定义文章类型 + 用户中心「我的订单」Tab
* 短代码驱动，任意主题/页面构建器可用

== 短代码 ==
* [mlshop_products]   商品列表
* [mlshop_cart]       购物车
* [mlshop_checkout]   结算
* [mlshop_orders]     我的订单（用户中心未启用时使用）
* [mlshop_downloads]  我的下载
* [mlshop_order id=N] 单个订单

== 与用户中心集成 ==
启用「漫步白月光用户中心」后，商城自动在账户中心添加「我的订单」Tab。

== 扩展性 ==
* 支付网关通过过滤器 `mlshop_payment_gateways` 扩展
* 账户 Tab 通过过滤器 `mluc_account_tabs` 扩展

== 版权 ==
本插件为原创实现，采用 GPL-2.0-or-later 授权，作者：漫步白月光。
官网：https://www.at8.fun/
