# ARCHITECTURE_AUDIT.md — Moonlight Shop 代码审计报告

> Phase 0 交付物 · 审计日期：2026-09-27
>
> 审计对象：
> - `moonlight-shop/` 漫步白月光电子商城 v1.7.4（51 个 PHP 文件，约 11,166 行 PHP / 1,220 行 JS / 2,915 行 CSS）
> - `moonlight-user-center/` 漫步白月光用户中心 v2.0.0（50 个 PHP 文件，约 11,220 行 PHP / 655 行 JS / 1,732 行 CSS，含 `pro/moonlight-user-center-pro/`）
>
> 审计方法：全量逐文件阅读（关键文件双路径交叉验证），安全项逐条以文件+行号定位证据；同时核对了插件自带的 AUDIT.md / SECURITY-CHECK.md / TEST-PLAN.md / CHANGELOG.md 历史文档。
>
> 本报告结论将作为 Phase 1（ARCHITECTURE.md / DATABASE.md / API.md / PAYMENT.md / SHIPPING.md / FREE-PRO.md / MIGRATION_PLAN.md）的设计输入。

---

## 一、执行摘要

**总体判断：两个插件完成度和安全意识显著高于计划书的预设，不需要推倒重写，应采用「演进式重构 + 架构收敛」路线。**

计划书第八十九条要求"先审计、不允许立即重写"。审计结论支持一个明确方向：

1. **数据架构可以保留**。两插件均为纯 CPT + postmeta 存储，无任何自定义表（全库 `CREATE TABLE|dbDelta` 零命中）。符合计划书第六节"如果当前商品系统已经基于 WordPress 原生机制并且性能可以接受，不要为了高级架构强行重写"的保留条件。真正缺失的是 **DB_VERSION 升级机制**与**统一价格计算器**，这两项必须补。
2. **支付安全闭环成立**。Stripe（HMAC 验签 + 5 分钟时间窗）、PayPal（官方验签 API + 四重校验）、支付宝（自实现 RSA2：验签 + app_id 比对 + 订单号反查 + 金额 ±0.01 比对 + 原子完单）均不信任前端返回，三层幂等防护完整。未发现可伪造支付成功的路径。
3. **主要架构问题是"两套体系"**：商城与用户中心各自实现一套支付网关抽象（`MLSHOP_Gateway` 抽象类 vs `MLUC_Payment_Gateway_Interface`）、一套付费内容引擎（`MLSHOP_Pay_Access` 658 行 vs `MLUC_Paywall` 1153 行）、两份会员取价逻辑——这正是计划书要求的整合重点。
4. **确认 2 个高危、若干中危缺陷**（余额/积分双账本串账、库存扣减失败仍下单等），全部可在现有架构内以小步修复解决，无需重构。

关键数字：

| 指标 | moonlight-shop | moonlight-user-center |
|---|---|---|
| 版本 | 1.7.4 | 2.0.0（含 Pro 0.x） |
| 自定义数据表 | 0 | 0 |
| CPT | 3（product/order/coupon） | 4（mluc_order/license/avatar/material/video 等） |
| 支付网关 | 5（COD/余额/Manual/Stripe/PayPal） | 4（Manual/PayPal/Stripe/支付宝） |
| AJAX handler | 14，全部带 nonce | 14，全部带 nonce |
| REST 路由 | 0 | 2（仅支付回调） |
| TODO/FIXME | 0 | 0 |

---

## 二、项目结构现状

```text
仓库根（组合包结构）
├── README.md                  # 组合包说明（GPL-2.0-or-later，作者 漫步白月光，at8.fun）
├── moonlight-shop/            # 电子商城 v1.7.4
│   ├── moonlight-shop.php     # 主文件：MLSHOP_* SPL 自动加载 + plugins_loaded 单例启动
│   ├── uninstall.php
│   ├── includes/              # 32 个 class-*.php（业务核心）
│   ├── templates/             # 17 个前台模板（含 elementor/）
│   ├── assets/                # css×5 / js×4
│   ├── languages/             # zh_CN / zh_HK / zh_TW / en_US 四套 .po
│   └── docs/webhook-setup-guide.md
└── moonlight-user-center/     # 用户中心 v2.0.0
    ├── moonlight-user-center.php  # MLUC_* SPL 自动加载 + mluc_loaded 扩展钩子
    ├── uninstall.php
    ├── includes/              # 33 个 class-*.php
    ├── templates/             # 10 个模板
    ├── assets/                # css×1 / js×4
    ├── languages/             # 四套 .po（v2.0.0 新文案未重编译）
    ├── pro/moonlight-user-center-pro/   # 独立 Pro 插件（License 客户端 / Elementor 会员卡 / 订单导出）
    └── AUDIT.md / SECURITY-CHECK.md / TEST-PLAN.md / CHANGELOG.md
```

两插件启动模式一致：`ABSPATH` 守卫 → 版本常量 → `spl_autoload_register`（前缀 `MLSHOP_`/`MLUC_`，类名映射 `class-*.php`）→ `plugins_loaded` 单例实例化。Elementor 均为条件加载（`did_action('elementor/loaded')`），**不是核心依赖**，卸载后短代码体系完整可用。

---

## 三、核心类与模块地图

### moonlight-shop（每个类的职责）

| 类 | 职责 |
|---|---|
| `MLSHOP_Product` | 商品 CPT（`mlshop_product`）、分类法、类型（physical/virtual/cardkey）、商品 meta 保存 |
| `MLSHOP_Product_Pay_Meta` | 付费内容 Meta Box（pay_mode/pay_auth/价格系列/积分价），挂在商品/文章/页面 |
| `MLSHOP_Cart` | Cookie 购物车（`mlshop_cart`，JSON，7 天，httponly），服务端重算价格/库存 |
| `MLSHOP_Order` | 订单 CPT（`mlshop_order`）、状态机（7 态白名单 + `set_status()` 统一入口）、下单、幂等标记、过期 cron |
| `MLSHOP_Payment` | 网关注册表 + 回跳/回调分发（`?mlshop_stripe_webhook=1` 等） |
| `MLSHOP_Gateway`（抽象类） | 网关契约：`get_id/get_title/get_description/process_payment` |
| 5 个网关类 | COD / Balance（原子扣款）/ Manual / Stripe（Checkout Session）/ PayPal（Orders v2） |
| `MLSHOP_Coupon` | 优惠券 CPT、原子 reserve/release（`$wpdb->prepare` 条件 UPDATE） |
| `MLSHOP_Download` | 数字交付：32 位随机 token + transient、下载端点属主校验、卡密 CAS 弹出 |
| `MLSHOP_Shipping` | 固定运费/满额包邮、实物判定、paid→processing 推进 |
| `MLSHOP_Credit` / `Credit_UI` | 积分账本（usermeta 余额 + 200 条流水数组）、充值下单 |
| `MLSHOP_Pay_Access` | 付费内容引擎：the_content 拦截、会员门禁、解锁账本、图集/视频/下载解锁 |
| `MLSHOP_Membership_UI` | 会员升级卡（`[mlshop_membership]`）、授予（幂等 `_mlshop_membership_granted`） |
| `MLSHOP_Statistics` | 后台订单统计：时间窗、销售额/AOV/热销/趋势，服务端纯 SVG 图表 |
| `MLSHOP_Bulk_Email` | 群发邮件：5 种收件人来源、transient 队列分批 25 封 |
| `MLSHOP_Favorite` / `Widgets` / `Header_Actions` | Cookie 心愿单 / 5 个小工具 / Astra 页眉购物车·收藏·会员按钮 |
| `MLSHOP_Account_Tab` | 在用户中心账户页挂 orders/downloads/coupons 三个 Tab |
| `MLSHOP_Email` / `Elementor` / `Admin` / `Ajax` / `Assets` | 邮件通知 / 7 个 Elementor 小工具 / 后台设置与操作 / 14 个 AJAX / 资源按需加载 |

### moonlight-user-center

| 类 | 职责 |
|---|---|
| `MLUC_Auth` | 前台登录/注册/找回密码（节流、蜜罐、一次性渲染令牌、防枚举） |
| `MLUC_Account` | 账户中心 `[mluc_account]` 6 Tab、资料/改密 AJAX |
| `MLUC_Membership` | 会员等级：后台可配（label/color/price/validity/sort），usermeta 存储，**只升不降**，读取时惰性到期降级（无 cron） |
| `MLUC_Payments` | `mluc_order` CPT、下单/确认/取消/退款后台操作、`complete_order()` 原子完单、超时关闭 cron |
| `MLUC_Payment_Gateway_Interface` | 网关契约（比商城丰富）：`get_id/get_name/is_available/get_capabilities/process_payment/handle_return/query_payment/refund` |
| `MLUC_Payment_Manager` | 网关注册表、可用性+币种能力校验、安全随机订单号 `MLUC+Ymd+8hex` 查重 |
| `MLUC_Payment_Log` | 支付日志环形 50 条入 `_mluc_pay_log`，debug 白名单键 + 后台开关，**不记密钥** |
| 4 个网关 | Manual / PayPal（Smart Buttons）/ Stripe（Checkout + Webhook）/ **Alipay（自实现 RSA2，含站内退款）** |
| `MLUC_License_Manager` / `License_Admin` | License 唯一授权引擎：签发/验证/12h 缓存/7 天断网宽限/撤销/续期；后台管理页 |
| `MLUC_Paywall` | 文章付费墙（阅读/下载/图集/视频），REST `rest_prepare_post` 封堵 + feed 置空 |
| `MLUC_Hidecontent` | `[hidecontent]` 短代码（reply/logged/vip1/payshow），嵌套安全 |
| `MLUC_Purchases` | 从商城订单提取已购虚拟/卡密商品（纯读取） |
| `MLUC_Material` / `MLUC_Video` | 等级门控 CPT（REST/归档/搜索全关，realpath 防穿越） |
| `MLUC_OAuth` | OAuth2 五家（含 Apple RS256 验签、state cookie 双因子） |
| `MLUC_Avatar` / `Email_Notifications` / `System_Status` / `Settings` / `Menu` | 头像库（无用户上传面）/ 邮件通知 / 环境体检 / 统一设置（sanitize） / 菜单可见性 |
| Pro：`MLUCP_License_Client` 等 5 文件 | 纯 UI 客户端，`is_active()` 直接调 Free 的 License_Manager；依赖方向 Pro→Free 单向 |

---

## 四、数据库架构现状

**两插件均无任何自定义数据表，也无 DB_VERSION 升级机制。** 数据载体明细：

| 数据 | moonlight-shop | moonlight-user-center |
|---|---|---|
| 商品/订单/优惠券 | CPT `mlshop_product` / `mlshop_order` / `mlshop_coupon` + postmeta（`_mlshop_items/_total/_subtotal/_shipping/_coupon_*/_shipping_address/_delivery/_gateway/_payment_*/_tracking_*`） | — |
| 会员订单/License | — | CPT `mluc_order`（`_mluc_pay_status/_user/_level/_price/_gateway/_order_no/_txn/_granted/_refunded/_pw_granted/_autoclosed` + 日志 `_mluc_pay_log`）、CPT `mluc_license`（post_title=Key + 9 个 meta） |
| 会员等级 | — | usermeta `mluc_membership_level` + `mluc_membership_expires`（0=永久） |
| 解锁记录 | usermeta `mlshop_pay_unlocks`（post_id=>过期戳） | usermeta `mluc_pay_unlocks`（同构） |
| 钱包 | usermeta `_mlshop_balance`（余额网关）与 `mlshop_credit_balance`（积分）**两套并存** | 无 |
| 设置 | **双路径**：独立 option `mlshop_$key`（约 50 个）+ `mlshop_options` 数组（页面 ID 等，后台无 UI 编辑入口） | 单一 `mluc_options`（Settings API 统一 sanitize） |
| 临时数据 | transient：下载 token、群发队列、PayPal token、价格缓存 | transient：下载 token、OAuth state、License 验证缓存（12h） |

订单状态：商城 7 态（pending/paid/processing/completed/failed/refunded/cancelled），post_status 与 meta `_mlshop_status` 双写（meta 为权威）；用户中心 3 态（pending/paid/cancelled + refunded 标记）。

**升级机制评估**：因无表，无 dbDelta 需求，但计划书第六十三节要求 DB_VERSION 机制——新架构必须补上（含 option 版本号 + 升级钩子 + 数据迁移），为未来性能优化（如订单索引表）预留路径。

---

## 五、商品系统

- 三种类型齐全：`physical` / `virtual` / `cardkey`（`class-product.php:364-371`）；留空默认按实物参与运费。
- 基础字段：价格/SKU/库存（-1 无限）/下载文件附件 ID/下载有效期（天，**无次数限制**）/卡密文本/相册/购买送会员等级。
- 付费内容字段独立 Meta Box：pay_mode（read/download/image/video）、pay_auth（all/gold/diamond）、money/credit 双计价、价格系列、JSON 化的 download_items/video_items/image_urls、销量基数。
- **会员价已有**：gold/diamond 两档货币价与积分价，等级来源 `MLUC_Membership::get_user_level`（跨插件依赖，有 `class_exists` 防护）。
- **缺陷：价格计算分散**——购物车重算、`Pay_Access::get_price_for_user`、`Order::create_for_paywall`（注释自认"就近计算避免跨类依赖"）三处各自取价，无 `Moonlight_Price_Calculator` 类。这是计划书第十六节点名禁止的模式，Phase 2 必须统一。

## 六、购物车 / 结算 / 订单

- 购物车：纯 Cookie（未签名），游客可用；所有价格/库存/类型服务端重算，**不存在价格篡改面**（计划书第七十一节测试项当前即可通过）。边缘缺口：`get_items()` 不校验 `post_status=publish`，下架商品可被带入结算。
- 订单状态机：**已具备统一状态机雏形**——`get_allowed_transitions()` 白名单 + `set_status()` 单一入口（校验→库存回滚→资金回退/券释放→双写状态→触发 `mlshop_order_status_changed` / `mlshop_order_<status>` 钩子），幂等标记体系完善（8 个 `_*_granted/_reversed/_released/_sent` 标记）。尚未闭环的是计划书第十八节的违例直写防护（无代码层拦截 `$order->status` 直写，但全库无违例调用）。
- 未支付订单：每小时 cron + 页面惰性过期双保险；manual 线下转账豁免。
- 优惠券：reserve 原子占名额；**缺陷：后续失败路径不 release（名额泄漏）**。

## 七、支付系统

### 两套网关体系对比（整合的核心对象）

| 维度 | 商城 `MLSHOP_Gateway` | 用户中心 `MLUC_Payment_Gateway_Interface` |
|---|---|---|
| 形态 | 抽象类，4 方法 | 接口，8 方法（含 capabilities/query_payment/refund） |
| 扩展方式 | `mlshop_payment_gateways` filter + `mlshop_enabled_gateways` 白名单 | `mluc_payment_gateways_registered` filter（另存遗留空壳 filter） |
| 退款 | **无**（标记退款但未对接网关） | **支付宝真实退款**；PayPal/Stripe 返回 WP_Error 指引后台操作 |
| 回调安全 | Stripe HMAC+时间窗；PayPal 官方验签 API + 四重校验 | 同左 + 支付宝验签/app_id/反查/金额四重 |
| 幂等 | 网关层 + payment 层 + 状态机层 | `complete_order()` 原子完单 + meta 标记 |

**已核实的共性优点**：一律不信任前端返回；金额/币种/订单号服务端核对；回调端点靠验签而非仅 `is_user_logged_in()`。

**缺口**（Phase 3 需补齐）：
1. 商城侧**无支付宝网关**（用户中心有，可直接移植复用）；
2. 双方 **PayPal/Stripe 退款 API 均未实现**；
3. 商城 Stripe webhook 金额不二次复核（依赖服务端创建 session 的事实，建议补查）；
4. 用户中心 Stripe webhook **secret 未配置时跳过验签**（应改为 503 拒绝）。

## 八、数字商品与卡密

- **下载安全达标**：真实路径不暴露（仅 `?mlshop_download=TOKEN`）；token = 32 位随机 + transient + 绑定 user_id，非本人 403；文件名白名单清洗防 header 注入。缺：下载次数计数（只有天数有效期）。
- **卡密**：明文多行存 `_mlshop_cardkeys` postmeta；发放用 **CAS 循环（条件 UPDATE）+ 原子扣库存**，并发防双发成立；交付幂等 `_mlshop_delivery`；订单页/邮件明文展示（业务必需）。**与计划书第十一/十二节的差距**：无批次（batch）概念、明文存储（建议 hash + 加密原文双轨）、无导入/导出审计、库存 0/空视为不限量存在误配风险。
- 卡密压力测试基础：CAS 单行原子性可支撑并发，但无批量导入工具与库存预警。

## 九、物流

- 当前：固定运费 + 满额包邮（默认 400/50），单一承运商名（默认"順豐速運"），paid→processing 自动推进，后台手工填 4 字段物流信息，**无承运商 API、无轨迹查询**。
- 与计划书第二十五至三十节差距：无 `Shipping_Provider_Interface`、无运费模板（按件/按重量/按地区）、无 Region Provider、无收货地址簿（下单一次性收集，仅存订单 meta）、无自提。
- 可复用：`has_physical()` 实物判定、订单-物流 meta 结构、tracking meta box 骨架。

## 十、会员体系

- 等级定义**后台可配**（label/color/price/validity/sort_order，支持增删改），已符合计划书第十五节"不要写死"的要求。
- 与 WordPress 用户同体系（usermeta），**不存第二套用户**——符合计划书第十四节。
- 授予只升不降 + 有效期叠加（续费语义）；到期采用**读取时惰性降级**（无 cron，性能可接受但"会员到期通知"依赖每日 License cron 顺带检查）。
- 会员购买：双渠道（用户中心直购 + 商城 `mlshop_membership` 卡片），授予逻辑双轨（幂等键不同、不冲突，但属重复实现）。
- 与商城价格联动：gold/diamond 档位价；**"任意等级任意折扣"的通用会员价格尚未抽象成统一计算器**。

## 十一、前端 / 后台 / REST API / AJAX

- **前端**：短代码体系完整（商城 `[mlshop_*]`、用户中心 `[mluc_*]` ×10），页面激活时自动创建；CSS 命名空间达标（`mlshop-`/`mluc-` 全前缀，`mluc.css` 340/340 选择器带前缀）；Elementor 组件 7+3 个且条件加载。与计划书第三十八节的差距：无 `/login` 等永久链接路由（只有短代码页面），可后续用重写规则补。
- **后台**：商城「订单统计 + 群发邮件 + 设置 + 订单/商品/优惠券 CPT 菜单」；用户中心「用户中心顶级菜单（设置/License 管理/系统状态）+ 订单 CPT（商城未装时）」。计划书第三十七节的后台信息架构（卡密/下载/物流/售后独立菜单）大部分缺失，Phase 9 按新菜单归并。
- **REST API**：商城 0 条；用户中心仅 2 条（支付宝 notify、Stripe webhook，均 `permission_callback => __return_true` 但由验签保障——回调类路由的合理设计）。业务 REST API（`moonlight/v1`）完全缺失，Phase 1 需按计划书第四十一节设计。
- **AJAX**：28 个 handler 全部带 nonce + capability 校验，无"仅 is_user_logged_in 放行"的写端点；admin_post 入口全部 `manage_options` + `check_admin_referer`。

## 十二、安全风险清单（合并排序）

### 高危（2，必须 Phase 2 内修复）

| # | 位置 | 问题 |
|---|---|---|
| H1 | shop `class-gateway-balance.php:33,47` vs `class-order.php:1040-1044` | **双账本串账**：余额支付扣 `_mlshop_balance`，退款/取消回补却走 `MLSHOP_Credit::add()` 写 `mlshop_credit_balance`；且全插件无任何给 `_mlshop_balance` 充值的路径。用户退款会拿到错误钱包的钱 |
| H2 | shop `class-order.php:672-677`、`class-download.php:126-129` | **超卖 fail-open**：库存原子扣减失败时清零库存后**继续建单**，应直接拒绝订单 |

### 中危（6）

| # | 位置 | 问题 |
|---|---|---|
| M1 | shop `class-order.php:602→623-646` | 优惠券 reserve 后置失败（库存校验失败/insert 失败）不 release，限次券名额永久泄漏 |
| M2 | shop `class-order.php:1020-1021` | 库存回滚读-改-写非原子（与扣减的 CAS 不对称），并发退款可丢回补 |
| M3 | shop `class-payment.php:166-175` | 实物订单收货地址仅前端 JS 必填，服务端不强制（伪造请求可建无地址实物单） |
| M4 | shop `class-admin.php:659,667,672,752`；muc `class-settings.php:580,595,599` | 支付密钥（Stripe sk_live/webhook secret、PayPal secret）以 `value` 回显进设置页 HTML（用户中心的支付宝密钥已做"仅显示尾 4 位"脱敏，应对齐） |
| M5 | shop `class-header-actions.php:62` | 生产代码硬编码测试域名判断（`test.…-languagebuilder.com`） |
| M6 | muc `class-stripe.php:234` | Stripe webhook secret 未配置时完全跳过验签（靠回查 API 兜底，但公开端点可被滥用触发 API 放大） |

### 低危 / 信息性（10）

- L1 卡密明文存储（业务可接受但需 hash 双轨 + 掩码展示 + 查看审计）——shop `class-product.php:452-455`
- L2 PayPal 回跳 `_mlshop_paypal_order` 为空时信任 URL token（后续金额/custom_id 校验兜底）——shop `class-payment.php:223-231`
- L3 付费墙外链 302 开放重定向面（管理员可控 meta）——shop `class-pay-access.php:542`
- L4 Cookie 购物车/心愿单未签名 + 不校验 publish 状态——shop `class-cart.php:36-48,93-95`
- L5 `uninstall.php` 残留（shop：transient/用户 meta/网关 option 不清理；muc：`_mluc_*` 下划线开头 usermeta 不被 `LIKE 'mluc_%'` 匹配）
- L6 muc `templates/membership-purchase.php:45-47` `mluc_ui_label()` 未转义（管理员级存储 XSS）
- L7 muc 订单 CPT `capability_type='post'` → editor 角色可看支付 meta box 界面（按钮已被 capability 挡住）
- L8 muc 支付宝回跳对 `$_GET` 逐项 `sanitize_text_field` 后验签，可能误拒合法通知——`class-gateway-alipay.php:432-434`
- L9 弱口令策略（仅 6 位无复杂度）；节流基于 `REMOTE_ADDR`（CDN 后误伤）
- L10 两插件 `.gitignore` 无密钥排除条目，与 SECURITY-CHECK.md 声明不符（密钥实际存 DB，风险有限）

### 已核实无问题的项（计划书 Phase 11 重点）

SQL 注入（全部 `$wpdb->prepare`）、可利用 XSS（模板系统性转义）、CSRF（AJAX/admin_post/meta box 全覆盖）、IDOR（订单/下载/卡密均校验属主）、价格篡改（前端无金额字段，服务端取价）、支付回调伪造（三网关验签 + 金额比对 + 幂等）、`eval/base64/create_function` 零命中。

## 十三、代码重复

**跨插件（整合重点）**：

| 用户中心 | 商城 | 结论 |
|---|---|---|
| `class-paywall.php`（1153 行） | `class-pay-access.php`（658 行） | **整模块高度重复**（paywall 文件头自述"自商城移植并独立化"）；`paywall-meta.php` ↔ `templates/product-pay-meta.php` 同源；`hidecontent` 的 payshow 判定被迫同时探测两套付费墙 |
| `class-stripe.php` webhook 验签（235-253） | `class-gateway-stripe.php:255-284` | 逻辑等价的独立实现 |
| `class-paypal.php:54-83` get_token | `class-gateway-paypal.php:58-90` | 同构 OAuth2 client_credentials + transient |
| `MLUC_Membership::grant_from_order` | `MLSHOP_Membership_UI::grant_membership` | 同一 hook 的双轨授予（幂等键不同） |
| — | 商城 5 网关无支付宝 / 用户中心无 COD、余额、积分 | 能力互补，统一后合并 |

**插件内**：会员取价两份（shop `class-order.php:710-724` vs `class-pay-access.php:114-132`，注释自认）；网关数组构造两份（`class-payment.php:42-48` vs `class-admin.php:699-706`）；订单状态标签映射三份（`class-order.php:415-427`、`functions.php:261-270`、`class-statistics.php:462-470`）；muc `accessible_levels()` 在 material/video 逐字重复；工厂默认等级数组在 hidecontent 内嵌副本。

## 十四、技术债务

1. **配置双路径**（shop）：独立 option × ~50 与 `mlshop_options` 数组并存，部分键后台无 UI；应收敛为单一 option 结构。
2. **无 DB_VERSION**（两插件）：计划书第六十三/六十四节要求的升级机制与 MIGRATION_PLAN 载体缺失。
3. **过渡形态**（muc）：PayPal/Stripe 静态实现类 + 薄适配器并存，两条建 session 路径；遗留空壳 filter `mluc_payment_gateways`；`MLUC_Payments` 594 行混合职责。
4. **.po/.mo 过期**（muc v2.0.0 新文案未编译）；**源串简繁混排**（两插件均有）；`readme.txt` 落后于功能。
5. **性能隐患**：统计页与收藏 fragment `posts_per_page => -1` 全量拉取；订单查询依赖 meta_query（无索引表）；万级订单后需按计划书 Phase 12 复测。
6. **License Server 服务端不存在**（muc CHANGELOG 自认），Pro 授权当前仅本地验证模式。
7. 修复日志式注释带具体日期（AI 迭代痕迹），应迁入 CHANGELOG。

## 十五、可复用资产与重构必要性评估

**直接保留（不重写）**：
- 两插件的单例 + SPL 自动加载骨架、模板加载器、Assets 按需加载；
- 商城订单状态机 + 幂等标记体系、优惠券原子 reserve、CAS 卡密弹出、Cookie 购物车 + 服务端重算；
- 三网关验签实现（Stripe/PayPal/支付宝）——这是最值钱的资产，支付宝 RSA2 自实现质量高；
- 用户中心会员等级后台配置体系、License Manager（含断网宽限）、OAuth、Auth 安全链路；
- 全部 `mlshop-`/`mluc-` 命名空间 CSS。

**必须新增（计划书要求 vs 现状缺口）**：
1. `Moonlight_Price_Calculator`（统一取价，消除三处分散计算）；
2. `DB_VERSION` 升级机制 + MIGRATION_PLAN 载体；
3. 统一支付层（以 `MLUC_Payment_Gateway_Interface` 为蓝本扩展 + Payment Manager 单例 `moonlight_payment()`）+ 三网关退款 API + 商城支付宝网关；
4. 卡密系统升级：批次表概念、hash+加密存储、批量导入、库存预警（维持 CAS 并发模型）；
5. `Shipping_Provider_Interface` + 运费模板 + Region Provider + 地址簿；
6. `Refund Service`（全额/部分 + 网关联动）；
7. `moonlight/v1` REST API（权限/nonce/参数校验）；
8. 统一后台菜单（仪表盘/订单/商品/卡密/下载/物流/客户/会员/优惠/支付/售后/设置/Pro）；
9. 通知系统统一（Notification Service：站内 + Email，事件清单按计划书第五十五节）；
10. Webhook（Pro）、性能（订单索引）、缓存兼容（DONOTCACHEPAGE）。

**付费墙双实现合并策略**：以商城 `MLSHOP_Pay_Access` 的"订单驱动"与用户中心 `MLUC_Paywall` 的"文章驱动"合并为单一引擎（核心按 post 驱动 + 订单来源抽象），放 Core；`hidecontent` 单点探测。

## 十六、架构结论（Phase 1 设计输入）

按计划书执行规则第 8/9/10 条（架构问题先记录、不确定选简单方案、不为高级增加复杂度）：

1. **数据架构决策**：**保留 CPT + postmeta**，不引入计划书第七节的 17 张自定义表。理由：① 现有全部查询/权限/回收站/导出生态免费获得；② 商品量级（个人站长场景）下 meta_query 性能可接受，配合索引与缓存；③ 避免双写迁移风险。性能兜底方案：为订单列表建立"年月 + 关键 meta 拷贝"的汇总查询与必要时的 `wp_mlshop_order_index` 辅助表（进 DB_VERSION 机制，而非首版引入）。
2. **订单状态机**：扩展现有 7 态白名单至计划书第十七节的完整状态集（增加 awaiting_payment/awaiting_shipment/shipped/delivered/refunding/failed 语义），保持 `set_status()` 单入口 + 钩子。
3. **支付统一**：以 `MLUC_Payment_Gateway_Interface` 为基（能力位/查询/退款齐全），新增 `create_payment/handle_notify` 语义对齐计划书第十九节；Payment Manager 提供 `moonlight_payment()->get_gateway('alipay')` 等入口；商城 5 网关与用户中心 4 网关合并为统一注册表（COD/余额/积分/Manual/支付宝/微信预留/PayPal/Stripe）。
4. **Free/Pro**：沿用"Free = 核心 + Pro 独立插件、Pro 只调 Free API"的既有正确路线（MUC pro/ 已验证），扩展为 `moonlight-shop`（Free）+ `moonlight-shop-pro`（Pro），License 复用 `MLUC_License_Manager` 并补 License Server 端实现。
5. **迁移范围（MIGRATION_PLAN）**：因无自定义表，迁移成本集中在——option 键收敛（shop 双路径 → 单路径）、卡密字段加密迁移、meta 键重命名映射（`_mlshop_*`/`_mluc_*` 保留兼容读取）、会员等级 option 结构、两付费墙解锁记录合并。旧数据全部保留，不做破坏性变更；回滚策略 = 版本降级 + 兼容读取层。
6. **修复批次**：H1/H2 + M1–M6 属于 Phase 2「先稳定核心交易」的入口任务，先于新功能。

---

*审计方法备注：本报告基于两轮独立全量代码阅读（每插件一个审计代理，逐文件 + 双路径交叉验证），行号以审计时刻工作区（git 77d6f15）为准。*
