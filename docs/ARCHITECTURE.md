# ARCHITECTURE.md — Moonlight Shop 目标架构

> Phase 1 交付物 · 2026-09-27 · 基于 [ARCHITECTURE_AUDIT.md](ARCHITECTURE_AUDIT.md) 的结论
>
> 核心原则（沿用计划书）：**轻量、模块化、低依赖、主题无关、安全、可扩展。80% 常见商城场景 + 20% 可扩展架构，不复制 WooCommerce。**

---

## 一、产品形态决策

**一个产品：Moonlight Shop（轻量 WordPress 商城 + 会员中心）。**

- 最终交付两个可安装插件：
  - `moonlight-shop`（Free）— 完整可用的商城 + 会员中心核心（吸收现有用户中心的全部 Free 能力）；
  - `moonlight-shop-pro`（Pro）— 纯增强插件，只调用 Free 的 API，不复制任何 Core 代码。
- **演进策略：不推倒重来。** 现有 `moonlight-shop` v1.7.4 作为 Free 的骨架（订单状态机/网关验签/CAS 卡密/付费内容引擎质量已验证），现有用户中心模块按"类整体搬迁"的方式并入（auth/account/membership/license/oauth），保留其数据结构兼容读取。
- 旧 `moonlight-user-center` 插件在迁移期保持可安装：启用新 Moonlight Shop 后会提示迁移并停用旧插件；未迁移前旧插件照常工作（MIGRATION_PLAN.md 详述）。

## 二、分层与模块地图

```text
Moonlight Shop (Free)
│
├── Core 层（基础设施，任何模块可依赖）
│   ├── Loader          单例注册、SPL 自动加载（沿用现有 mlshop_ 前缀机制）
│   ├── Options         统一 option 访问（收敛 shop 双路径 + mluc_options）
│   ├── DB_Migrator     DB_VERSION 升级机制（见 DATABASE.md）
│   ├── Price_Calculator 统一价格计算器（商品价→会员价→优惠券→运费→税费预留→合计）
│   ├── Money           金额格式化/最小单位换算（Currency Service：CNY/USD/HKD…）
│   ├── Logger          支付/订单/物流/错误日志（脱敏白名单，沿用 MLUC_Payment_Log 模式）
│   ├── Notification    站内通知 + Email 事件总线（注册/下单/支付/发货/完成/退款/发卡/到期）
│   └── Http            网关共用的 HTTP 客户端封装（超时/重试/代理，Stripe/PayPal/支付宝复用）
│
├── User 层（原用户中心并入）
│   ├── Auth（登录/注册/找回，保留节流/蜜罐/防枚举）
│   ├── Account（账户中心 Tab 框架：概览/订单/下载/卡密/地址/资料/会员）
│   ├── Membership（等级定义后台可配、授予只升不降、惰性到期 + 每日 cron 双保险）
│   ├── Customer（客户视图：usermeta 聚合、消费统计）
│   └── OAuth（保留，五家 + Apple JWT 验签）
│
├── Product 层
│   ├── Product（CPT mlshop_product 保留；physical/virtual/cardkey）
│   ├── PayMeta（付费内容字段；付费墙双实现合并后只此一份）
│   └── Inventory（库存服务：CAS 扣减 fail-closed、原子回滚、预留/恢复）
│
├── Cart / Checkout 层
│   ├── Cart（Cookie 购物车保留 + 发布状态校验修复；混合类型同单）
│   └── Checkout（结算控制器；一律调 Price_Calculator，不自带计算）
│
├── Order 层（系统核心）
│   ├── Order（CPT mlshop_order；状态机白名单扩展为计划书第十七节完整状态集）
│   ├── Order_Service（mark_paid/mark_shipped/complete/cancel/refund 唯一入口）
│   └── 订单拆分：主订单 + fulfillment 分段（digital/card 立即完成；physical 走物流链）
│
├── Payment 层（统一支付，见 PAYMENT.md）
│   ├── Payment_Manager（moonlight_payment() 单例；网关注册表 + 能力位）
│   ├── Gateway_Interface（以 MLUC_Payment_Gateway_Interface 为蓝本 + create_payment/handle_notify）
│   └── Gateways：Alipay / WeChatPay(预留) / PayPal / Stripe / Balance / Credit / COD / Manual
│
├── Digital 层
│   ├── Download（token 下载端点保留 + 下载次数计数）
│   └── FileAccess（流式输出/X-Sendfile 探测，路径永不暴露）
│
├── Card 层（卡密，见 DATABASE.md 卡密存储升级）
│   ├── Card_Stock（批次 + hash/加密双轨存储 + CAS 弹出保留）
│   ├── Card_Importer（批量导入/导出审计）
│   └── Auto_Delivery（支付成功 → 锁定 → 绑定 → sold → 展示）
│
├── Shipping 层（见 SHIPPING.md）
│   ├── Shipping_Provider_Interface（承运商/聚合平台接入点）
│   ├── Shipping_Templates（固定/满额包邮/按件/按重量/按地区）
│   ├── Region_Provider（省市区数据源抽象，不写死地区）
│   └── Shipment / Tracking（发货单 + 轨迹 + cron 自动物流查询）
│
├── Refund 层
│   └── Refund_Service（全额/部分；网关联动退款；虚拟已下载/卡密已查看/实物已发货规则闸）
│
├── Coupon 层（保留现有原子 reserve/release + 修复泄漏路径）
│
└── API 层（见 API.md）
    └── REST moonlight/v1（资源路由 + 权限回调 + nonce/capability + 参数校验）

admin/        后台页面（仪表盘/订单/商品/卡密/下载/物流/客户/会员/优惠/支付/售后/统计/设置/Pro）
frontend/     前台路由与短代码兼容层（[mluc_*] 与 [mlshop_*] 全部继续可用）
templates/    前台模板（.moonlight-/mlshop- 命名空间不变）
assets/       css/js（按需加载：非商城页面零资源）
languages/    zh_CN 默认 + en_US（.po 重编译）
```

## 三、关键架构规则

1. **唯一价格计算器**：cart/checkout/order/gateway 一律调 `Moonlight_Price_Calculator`，禁止就地取价（消除审计发现的三处分散计算）。计算序列：`商品原价 → 会员价（等级价/等级折扣）→ 优惠券 → 活动预留 → 运费 → 税费预留位 → 合计`。结果一次性写入订单 meta，网关只与 `_mlshop_total` 对账。
2. **唯一订单入口**：状态变更只能走 `Moonlight_Order_Service`（`mark_paid()` 等），内部维持现有白名单状态机 + 幂等标记 + `moonlight_order_*` 钩子。新增状态：`awaiting_payment / awaiting_shipment / shipped / delivered / refunding`（与既有 7 态映射兼容）。
3. **唯一支付入口**：`moonlight_payment()->get_gateway('alipay')` 等；回调安全三件套（验签、金额比对、幂等）在 Payment_Manager 统一实施，网关只实现协议细节。
4. **钩子命名规范**：全部 `moonlight_*`（新）；旧 `mlshop_*` / `mluc_*` 钩子保留并行触发（deprecated 注释），确保现有第三方/Pro 代码不断。过滤器示例：`apply_filters('moonlight_product_price', $price, $product, $context)`。
5. **命名规范**：类 `Moonlight_*`（迁移期保留 `MLSHOP_*`/`MLUC_*` 类名别名，`class_alias` 兼容层）；函数 `moonlight_*`；REST `moonlight/v1`；CSS `.moonlight-*`（保留 `.mlshop-*`/`.mluc-*`）；JS `moonlight*`。
6. **性能规则**：前台非商城页面不加载任何商城 JS/CSS（沿用现有 Assets 按需加载并扩展）；账户/购物车/结算页设 `DONOTCACHEPAGE`；统计改分页聚合查询，取消 `posts_per_page => -1`。
7. **主题无关**：不写死任何主题函数；Astra/Elementor 集成保留现有条件加载模式；Elementor 只作为 UI 层。
8. **依赖原则**：WordPress Core + PHP + 必要 SDK。支付不引入完整官方 SDK（现有自实现 RSA2/REST 客户端质量已验证），新增微信支付同样自实现 API v3（公私钥/证书/验签），不引入 composer 全家桶。
9. **安全基线**（每条对应审计项）：输入 sanitize + validate、输出 escape、`$wpdb->prepare`、全部写端点 nonce + capability、资源属主校验（IDOR）、服务端取价（价格篡改）、验签 + 金额 + 幂等（回调）、密钥永不回显（对齐支付宝式尾 4 位脱敏）、日志白名单脱敏、卡密 hash + 加密双轨、下载路径永不暴露。

## 四、仓库目录（目标形态，允许实施期微调）

```text
moonlight-shop/（仓库根）
├── moonlight-shop/            # Free 插件（可安装单元）
│   ├── moonlight-shop.php
│   ├── includes/{core,user,product,cart,checkout,order,payment,digital,card,shipping,refund,coupon,api}/
│   ├── admin/  frontend/  templates/  assets/  languages/
│   └── compat/                # 旧插件兼容层（mluc_* 短代码、类别名、option 映射、迁移器）
├── moonlight-shop-pro/        # Pro 插件（可安装单元）
│   ├── moonlight-shop-pro.php
│   └── includes/{license,advanced-shipping,advanced-coupon,membership-plus,analytics,api}/
├── docs/                      # 本套设计文档 + webhook-setup-guide.md
└── README.md
```

> 迁移期仓库根的 `moonlight-user-center/` 保留至 Phase 9 结束（后台+前端完成）后再按计划书"最后清理旧代码"步骤移除；清理前旧插件代码不改动、不删除。

## 五、阶段实施映射（对应计划书 Phase 2–13）

| 阶段 | 交付 |
|---|---|
| Phase 2 Core | Price_Calculator、Options 收敛、Inventory fail-closed 修复（H2/M1/M2）、订单状态机扩展、下单服务化、购物车发布校验；不接支付/物流 |
| Phase 3 Payment | Payment_Manager + 统一接口；支付宝网关移植进商城、三网关退款 API、密钥脱敏（M4）、webhook secret 强制（M6）；微信支付 v3 骨架 |
| Phase 4 Digital | 下载次数计数、 FileAccess（X-Sendfile 探测）、下载日志 |
| Phase 5 Card | 批次 + 导入器 + hash/加密双轨 + 库存预警 + 1000/10000 并发压测脚本 |
| Phase 6 Physical | 地址簿、运费模板、Region Provider、Shipment/Tracking、Shipping_Provider_Interface、地址服务端强制（M3） |
| Phase 7 Refund | Refund_Service + 规则闸 + 部分退款 + 网关联动 |
| Phase 8 Pro | moonlight-shop-pro 骨架 + License（复用 MLUC_License_Manager）+ License Server 端点 |
| Phase 9 后台 | 统一菜单、仪表盘（今日/本月销售额、待发货、库存预警）、卡密/物流/售后页 |
| Phase 10 前端 | 账户中心 Tab 合并（订单/下载/卡密/地址/会员）、模板统一、双付费墙合并 |
| Phase 11–13 | 安全复测（H1/M1–M6 回归 + 计划书特别测试项）、性能基准、PHP 8.1–8.4/Astra/TT/Elementor 兼容矩阵 |
```
