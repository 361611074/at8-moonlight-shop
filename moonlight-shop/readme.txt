=== 漫步白月光电子商城 ===

Contributors: 漫步白月光
Tags: shop, ecommerce, cart, checkout, digital, download, cardkey, elementor, astra
Requires at least: 5.8
Tested up to: 6.6
Stable tag: 3.0.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: 漫步白月光
Author URI: https://www.at8.fun/

轻量、主题无关的电子商城系统，兼容 Astra 主题与 Elementor，与「漫步白月光用户中心」无缝集成。

== Description ==
支持实物 / 虚拟下载 / 卡密三类商品与购物车、结算、订单全流程；支付网关内置支付宝 / 微信（预留）、PayPal、Stripe、余额、积分、货到付款（COD）与线下转账；提供运费模板、物流轨迹查询与售后退款；与「漫步白月光用户中心」账户中心无缝集成。

== 功能 ==
* 商品自定义文章类型：实物 / 虚拟下载 / 卡密 三种类型
* 会话购物车（游客可用，Cookie 存储）
* 结算流程：订单创建 + 可扩展支付网关
* 支付网关：支付宝 / 微信（预留）、PayPal、Stripe、余额支付、积分支付、货到付款、扫码/线下付款
* 虚拟商品：支付后自动生成安全下载链接（限时）
* 卡密商品：加密批次库存池，售出后自动分配并扣减库存
* 运费模板（固定 / 按件计费、满额包邮、到店自提）
* 物流：发货单管理、快递100 轨迹查询、签收自动完成
* 售后退款：规则闸校验、部分退款、网关原路退回
* 订单管理：自定义文章类型 + 用户中心「我的订单」Tab
* 短代码驱动，任意主题/页面构建器可用

== 短代码 ==
* [mlshop_products]   商品列表
* [mlshop_cart]       购物车
* [mlshop_checkout]   结算
* [mlshop_orders]     我的订单（用户中心未启用时使用）
* [mlshop_order id=N] 单个订单
* [mlshop_downloads]  我的下载
* [mlshop_favorites]  我的收藏
* [mlshop_address]    收货地址（用户中心未启用时使用）
* [mlshop_coupons]    我的优惠券（当前可用优惠券列表）
* [mlshop_membership] 会员升级卡片

== 与用户中心集成 ==
启用「漫步白月光用户中心」后，商城自动在账户中心添加「我的订单」「收货地址」「我的下载」「我的优惠券」Tab。

== 扩展性 ==
* 支付网关通过过滤器 `mlshop_payment_gateways` 扩展
* 账户 Tab 通过过滤器 `mluc_account_tabs` 扩展

== 版权 ==
本插件为原创实现，采用 GPL-2.0-or-later 授权，作者：漫步白月光。
官网：https://www.at8.fun/

== Changelog ==

= 2.1.0 =
* 新增游客购买：未注册访客填写邮箱即可下单，订单确认与虚拟商品发送到该邮箱
* 游客订单安全：48 位访问令牌（hash_equals 时序安全），订单页/下载/网关回跳均凭令牌放行；余额支付与优惠码对游客禁用；IP 限流防灌单
* 付款完成后推荐注册：订单页与订单邮件内嵌注册引导（邮箱预填、会员中心注册页优先）
* 新增 Phase 12 性能审计（docs/PERFORMANCE_AUDIT.md，附可复现基准脚本）与 Phase 13 兼容性报告（docs/COMPATIBILITY.md，PHP 8.1–8.4 全量语法验证）

= 2.0.0 =
* 全面安全审计修复：支付密钥脱敏保存、地址簿服务端校验、支付回跳归属与 token 防伪校验、优惠券名额防泄漏（原子预留/释放）等
* 统一价格计算器：小计 → 优惠券 → 运费 → 合计单一口径（Moonlight_Price_Calculator），支持会员等级价
* 卡密系统重构为加密批次模型：卡密加密存储、跨批次去重、CAS 防并发超发、掩码查看与审计日志
* 物流第一批/第二批：运费模板、收货地址簿、发货单管理、快递100 轨迹查询、签收后自动完成
* 售后退款：售后申请、退款规则闸矩阵、部分退款、Stripe/PayPal/余额原路退回
* 新增后台「商城仪表盘」：今日/本月销售额、订单/待发货/待处理售后、商品/会员数与卡密库存预警
* 缓存兼容：购物车/结算/账户/订单详情等用户态短代码自动禁用页面缓存；测试站禁缓存逻辑改为环境判断（moonlight_disable_cache 过滤器），移除硬编码域名
* 新增 [mlshop_coupons] 短代码（当前可用优惠券列表）
* Pro 扩展（moonlight-shop-pro）：License 授权门禁、出站 Webhook（HMAC 签名 + 退避重试）、Pro 统计、订单 CSV 导出
