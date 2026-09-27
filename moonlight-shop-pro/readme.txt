=== Moonlight Shop Pro ===
Contributors: moonlight
Tags: ecommerce, shop, webhook, analytics, export
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

漫步白月光电子商城（moonlight-shop）的 Pro 扩展：授权门禁、出站 Webhook、Pro 统计与订单导出。

== Description ==

Moonlight Shop Pro 是「漫步白月光电子商城」（moonlight-shop，Free）的商业增强插件，依赖方向 Pro → Free 单向，零复制 Free 业务代码。

**必须先安装并启用「漫步白月光电子商城」（moonlight-shop）。**

功能：

* **License 授权门禁**：优先复用 moonlight-user-center 的 License 引擎（站点绑定 / 宽限期 / 撤销联动）；引擎缺席时回退本地开关（`mlpro_local_active`），会员中心并线后自动切换。未激活只显示后台提示，Free 商城不受影响。
* **出站 Webhook**：订阅订单生命周期事件（order.created / order.paid / order.awaiting_shipment / order.shipped / order.delivered / order.completed / order.cancelled / order.refunded / order.failed / refund.processed），HMAC-SHA256 签名（`X-Moonlight-Signature: t=...,v1=...`），失败入队重试（5/15/60 分钟退避，5 次后丢弃）。
* **Pro 统计**：近 7/30/90 天销售趋势（线图）、商品销量 Top 10、支付渠道占比，全部服务端 SVG 渲染，按天聚合缓存 1 小时。
* **订单 CSV 导出**：日期范围筛选，流式输出，内置 CSV 公式注入防护（= + - @ Tab 前置单引号）。

== Installation ==

1. 先安装并启用「漫步白月光电子商城」（moonlight-shop）。
2. 上传本插件到 `/wp-content/plugins/moonlight-shop-pro/` 并启用。
3. 后台「Moonlight Pro → Pro 授权」确认授权状态（本地模式默认开启）。

== Changelog ==

= 2.0.0 =
* 首个 Pro 版本：License 客户端、出站 Webhook（签名 + 队列重试 + 环形日志）、Pro 统计、订单 CSV 导出。
