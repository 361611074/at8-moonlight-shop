# MERGE-USER-CENTER.md — 会员中心并入 Free 插件计划（v3.0 路线）

> 2026-09-29 · 目标：`moonlight-shop` v3.0 成为「商城 + 会员中心」一体化产品；
> `moonlight-user-center` 转为兼容退役态，`moonlight-shop-pro` 吸收 MLUCP Pro 能力。
> 依据：ARCHITECTURE.md 第一节、MIGRATION_PLAN.md M3/M8/M9、Phase 0 审计结论（重复热点：付费墙整模块、双网关体系、双会员授予）。

## 总原则（沿用计划书执行规则）

1. **零数据丢失**：usermeta（`mluc_membership_level` 等）与 option（`mluc_options`）**沿用不改名**，等级体系不变是硬约束；
2. **共存保护**：旧插件激活期间，并入模块全部让位（检测旧插件 → 不注册，仅迁移工具可用）；
3. **每阶段可独立发布**：每阶段 = 测试扩展 + 站点验证 + 一个 commit。

## Phase A：用户模块并入（无行为变更）

- 将 `moonlight-user-center/includes/` 中 **无商城耦合** 的模块原样复制到 `moonlight-shop/includes/user/`（保留 `MLUC_` 类前缀，避免一次性重命名）：
  `class-auth / class-account / class-membership / class-avatar / class-oauth / class-email-notifications / class-system-status / class-hidecontent / class-editor-button / class-material / class-video / class-license-manager / class-license-admin / class-payment-log / class-payment-gateway-interface / class-payment-manager / class-settings / class-menu`
  （`class-paywall / class-purchases / class-payments* / class-paypal / class-stripe` 暂不并入——Phase C/D 处理）
- `moonlight-shop.php` 自动加载扩展：`MLUC_` → `includes/user/class-*.php`；
- 启动守卫：`if (!class_exists('MLUC_Auth', false) && !defined('MLUC_ACTIVE_LEGACY'))` —— 旧插件先加载时（其文件头 define MLUC_ACTIVE_LEGACY）新侧不实例化，**两插件共存时行为与现状完全一致**；
- `includes/functions.php` 的 `mluc_*` 助手去重合并（function_exists 守卫）；
- 短代码注册全部带 `shortcode_exists` 守卫；
- 模板复制 `templates/`（mluc 系）→ `moonlight-shop/templates/user/`；
- 测试：Auth 节流/蜜罐桩测、Membership 只升不降/惰性到期、License 签发/宽限——并入后行为断言 + 507 项回归。

## Phase B：设置与页面接管

- `mluc_options` 读取链并入 `Moonlight_Options`（同键名直读，不迁移数据）；
- 用户中心页面创建逻辑并入商城激活器（登录/注册/找回/账户中心，已有页面跳过）；
- 后台菜单合并：原「用户中心」顶级菜单改为商城下「会员与账户」子菜单组；
- `mluc_account_tabs` 过滤器保持兼容（商城 Account_Tab 与并入版共享同一过滤器协议）。

## Phase C：支付并线（依赖 Phase A）

- 会员购买/付费墙解锁统一下单到商城 `MLSHOP_Order`（gateway 复用 Payment_Manager 全网关，含支付宝/微信）；
- `MLUC_Payments` 不再注册；存量 `mluc_order` 走既有 M3 门控复制迁移；
- `MLUC_Paywall` 退役：`mluc_pw_*` 文章 meta 由 `MLSHOP_Pay_Access` 兼容读取（映射表见 DATABASE.md 2.2）；
- `hidecontent` 的 payshow 双墙探测改单点（只探测 `MLSHOP_Pay_Access`）；
- License 自动颁发链：`mlshop_order_paid` → License_Manager（替换 `mluc_payment_completed` 链）。

## Phase D：Pro 收编

- `moonlight-shop-pro` 吸收 MLUCP：Elementor 会员卡、订单导出（已有 CSV 版）→ 合一；
- License 引擎对接站点现装的 **at8-license-server**（已发现测试站装有该插件）+ 自研 server 端备选；
- `mlucp_license_*` admin_post 并入 Pro 设置页。

## Phase E：旧插件退役

- 旧插件激活时后台持续提示「功能已并入，请停用」（提供一键迁移统计页：计数校验后停用）；
- 兼容层（类别名/短代码别名/旧钩子并行）冻结只修 bug；
- `moonlight-user-center` 目录移入 `legacy/`（两版本后删除）。

## 验收（并入完成后）

```text
[✓] 单插件提供 登录/注册/账户/会员/卡密/下载/售后/物流/全支付
[✓] 旧插件数据零迁移可用（usermeta 同名直读）
[✓] mluc_* / mlshop_* 旧钩子并行触发
[✓] 双付费墙合并为单引擎
[✓] 测试套件 ≥600 项全绿
```
