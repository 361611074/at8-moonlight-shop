=== Moonlight Shop Pro ===
Contributors: moonlight
Tags: ecommerce, shop, webhook, analytics, export, elementor
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

漫步白月光电子商城（moonlight-shop）的 Pro 扩展：授权门禁、Elementor 会员状态卡、出站 Webhook、Pro 统计与订单导出。

== Description ==

Moonlight Shop Pro 是「漫步白月光电子商城」（moonlight-shop，Free）的商业增强插件，依赖方向 Pro → Free 单向，零复制 Free 业务代码。

**必须先安装并启用「漫步白月光电子商城」（moonlight-shop）。**

功能：

* **License 授权门禁（双产品语义）**：复用并入 moonlight-shop 的 License 引擎（站点绑定 / 宽限期 / 撤销联动），依次检查 moonlight-shop-pro 与存量产品 moonlight-user-center-pro——任一激活即激活，存量旧授权继续可用；引擎缺席时回退本地开关（`mlpro_local_active`）。未激活只显示后台提示，Free 商城不受影响。
* **Elementor 会员状态卡**（自旧版用户中心 Pro 收编）：展示会员等级 / 到期时间 / 升级入口，License 未激活时不注册；旧版「用户中心 Pro」仍激活时自动让位，不重复注册。
* **出站 Webhook**：订阅订单生命周期事件（order.created / order.paid / order.awaiting_shipment / order.shipped / order.delivered / order.completed / order.cancelled / order.refunded / order.failed / refund.processed），HMAC-SHA256 签名（`X-Moonlight-Signature: t=...,v1=...`），失败入队重试（5/15/60 分钟退避，5 次后丢弃）。
* **Pro 统计**：近 7/30/90 天销售趋势（线图）、商品销量 Top 10、支付渠道占比，全部服务端 SVG 渲染，按天聚合缓存 1 小时。
* **订单 CSV 导出**：日期范围筛选，流式输出，内置 CSV 公式注入防护（= + - @ Tab 前置单引号）。导出商城订单（mlshop_order）；存量用户中心订单（mluc_order）的导出仍由旧版 Pro 提供，互不冲突。

== Installation ==

1. 先安装并启用「漫步白月光电子商城」（moonlight-shop）。
2. 上传本插件到 `/wp-content/plugins/moonlight-shop-pro/` 并启用。
3. 后台「Moonlight Pro → Pro 授权」确认授权状态（本地模式默认开启）。

== Changelog ==

= 2.2.0 =
* Phase D（Pro 收编）：自旧版「用户中心 Pro」（MLUCP）并入 Elementor 会员状态卡组件（类名 / 组件名不变，旧 Pro 激活时自动让位）。
* License 双产品语义：引擎模式下依次检查 moonlight-shop-pro 与 moonlight-user-center-pro，任一激活即激活（存量旧授权继续可用），两产品结果分别缓存并在授权页展示。
* 旧 MLUCP Pro 共存保护：旧 Pro 激活时本插件的 Elementor 组件与 License 双检查跳过，Webhook / 统计 / 导出不受影响。

= 2.0.0 =
* 首个 Pro 版本：License 客户端、出站 Webhook（签名 + 队列重试 + 环形日志）、Pro 统计、订单 CSV 导出。
