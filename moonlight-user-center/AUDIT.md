# moonlight-user-center 架构审计报告（AUDIT.md）

> 生成时间：2026-09-27 · 审计基线：v1.7.1（初始提交 29008fa）
> 本文为 Phase 0 产出。审计期间未修改任何代码。后续 Phase 1 → Phase 10 均以本报告为依据。

---

## 1. 当前架构

### 1.1 总体结构

```text
moonlight-user-center/
├── moonlight-user-center.php      # 入口：常量 + SPL 自动加载 + 模块实例化（plugins_loaded）
├── includes/                      # 单层扁平结构，class-{kebab}.php ↔ MLUC_{Snake_Name}
├── templates/                     # 前台模板（主题可用 mluc/{slug}.php 覆盖）
├── assets/                        # css / js（前台 mluc.js、后台 mluc-admin.js）
├── languages/                     # zh_CN / zh_HK / zh_TW / en_US（.po/.mo）
├── uninstall.php                  # 卸载清理（订单、usermeta mluc_%、transient、选项）
└── readme.txt / README.md
```

- **自动加载**：`MLUC_` 前缀类 → `includes/class-{snake→kebab}.php`。新增类只要遵守该命名即可自动加载，无需 require。
- **模块清单**（plugins_loaded 实例化）：Auth、Account、Avatar、Assets、Membership、Payments、Video、Material、Purchases、Settings、OAuth、Hidecontent、Editor_Button、Paywall、Menu；Elementor 仅在检测到已加载时启用。
- **选项存储**：单一 option `mluc_options`（数组），经 `mluc_get_option()` 读取，Settings API + `sanitize_options()` 统一清洗。
- **多语言**：文本域 `moonlight-user-center`；前台用户可见文案走 `mluc_ui_label($key, $english_default)`（后台「界面文案」可覆盖，留空回退英文默认）；后台源文案为中文。新增功能必须沿用同一约定。

### 1.2 现有扩展点（Free 侧钩子）

| 钩子 | 类型 | 位置 | 用途 |
| --- | --- | --- | --- |
| `mluc_account_tabs` | filter | class-account.php | 账户中心 Tab 注册（Purchases 已用此方式挂 Tab） |
| `mluc_membership_levels` | filter | class-membership.php | 会员等级定义 |
| `mluc_payment_gateways` | filter | class-payments.php | 网关**名称列表**（仅展示，无流程分派） |
| `mluc_membership_purchase` | action | class-account.php | 账户中心「升级购买」区渲染（Payments 已挂） |
| `mluc_oauth_providers` | filter | class-oauth.php | OAuth 提供商 |
| `mluc_payment_completed` | action | class-payments.php | 支付完成（订单ID/用户/等级） |
| `mluc_payment_order_created` | action | class-payments.php | 订单创建后 |
| `mluc_order_autoclosed` | action | class-payments.php | 订单超时关闭 |
| `mluc_membership_changed` | action | class-membership.php | 用户等级变更 |
| `mluc_elementor_widgets` | （缺失） | — | Phase 1 需补：Elementor 组件注册过滤器，供 Pro 扩展 |
| `mluc_loaded` | （缺失） | — | Phase 1 需补：插件加载完成 action，供 Pro 挂载 |

**结论**：Tab / 等级 / 网关列表 / 购买区均有钩子，但**支付流程本身不可插拔**（见第 2 节），且缺少 `mluc_loaded` 与 Elementor 组件扩展钩子。

## 2. 当前支付架构

### 2.1 订单模型

- 订单 = CPT `mluc_order`（非公开、show_ui、支持 title），状态存 postmeta：
  - `_mluc_pay_status`：`pending` / `paid` / `cancelled`
  - `_mluc_pay_user` / `_mluc_pay_level` / `_mluc_pay_price` / `_mluc_pay_gateway`
  - `_mluc_pay_type`：`paywall`（付费墙订单）或空（会员订单）
  - `_mluc_pay_post`（付费墙目标文章）、`_mluc_pay_title`（商品名）
  - `_mluc_pay_txn`（网关交易号）、`_mluc_pay_txn_ref`（网关会话/订单引用）
  - `_mluc_pay_granted` / `_mluc_pay_gateway_paid` / `_mluc_pay_autoclosed`
- 订单号 = 标题 `MB-{YmdHis}-{等级名}`；**无独立 order_no 字段**（Phase 2 补 `_mluc_pay_order_no`，支付宝 out_trade_no 需要）。
- 超时自动关闭：`mluc_pay_autoclose` 每小时 cron（默认 72h，可配），原子 UPDATE 抢占。

### 2.2 网关现状

| 网关 | 类 | 流程 | 金额/归属校验 |
| --- | --- | --- | --- |
| 线下转账 | 无独立类（Payments 内联） | 下单 → 用户转账 → 管理员「确认收款并开通」 | — |
| PayPal | `MLUC_PayPal`（静态） | Orders v2 REST + JS SDK Buttons；`ajax_create` / `ajax_capture` | capture 后核对 `custom_id` = 本地订单 ID、金额差 ≤ 0.01 |
| Stripe | `MLUC_Stripe`（静态） | Checkout Session 跳转；回跳 `retrieve_session` 复核 + REST Webhook 兜底 | `client_reference_id`、`amount_total`（最小货币单位）、`payment_status=paid`；Webhook 验签（HMAC、5min 时间窗、多 v1）+ livemode 一致性 + **不信任请求体、回查 API** |

### 2.3 关键安全机制（现状良好，必须保持）

- 金额只信服务端：`ajax_buy_level` 用 `MLUC_Membership::get_level_price($level)`，客户端仅传 `level` + `gateway`。
- 完单入口 `MLUC_Payments::complete_order()`：**原子抢占**（单条 UPDATE 仅在 pending→paid 时胜出），天然幂等；开通失败回滚状态允许重试。
- 回跳不作为支付依据：Stripe 回跳后服务端 retrieve + 完成统一走 `complete_order`。
- AJAX 均有 `check_ajax_referer('mluc_nonce')` + 登录 + 订单归属（`_mluc_pay_user` ↔ 当前用户）+ 网关匹配校验。
- 下单限流：10 分钟 10 单/用户（transient）。
- 输出转义规范（esc_html/esc_attr/esc_url）、SQL 全部走元数据 API 或 `$wpdb->prepare`。

### 2.4 支付架构短板（Phase 1 目标）

`ajax_buy_level` 中网关分派是**硬编码 if/else**：

```php
if ('paypal' === $gw) { ... }
if ('stripe' === $gw) { $session = MLUC_Stripe::create_session(...); ... }
// manual 落到默认分支
```

`mluc_payment_gateways` 过滤器只影响**名称列表**，新增网关（支付宝）无法在不改动 Payments 核心的情况下接入下单/回调/查询流程。**必须抽象出网关接口 + 网关注册表**，让 PayPal / Stripe / Alipay 成为平等实现。

## 3. 当前会员架构

- **等级定义**：`mluc_options['membership_levels']`（label/color/price/validity/sort_order），回退工厂默认 free/monthly/gold/premium/diamond；`free_level_enabled` 控制 free 基座。**等级自带价格与有效期（天），实质上就是"套餐(Plan)"**——Phase 2 无需另建 Plan 表，直接以等级为套餐真相源。
- **用户会员状态**：usermeta `mluc_membership_level` + `mluc_membership_expires`；读取时惰性检查到期并自动降级。
- **授予逻辑**：`grant_level()` 只升不降（sort_order 比较）；有效期在现有未过期到期日上叠加 N 天（= 续费语义已存在）。
- **付费墙**：`_mluc_pay_type=paywall` 订单复用同一订单 CPT 与 `complete_order` 流程（`MLUC_Paywall::grant_for_order` 解锁文章）。
- **商城共存**：与 moonlight-shop 同装时，购买走商城流程（`MLSHOP_Membership_UI`），本插件订单后台菜单隐藏、`ajax_buy_level` 拒绝下单。

## 4. 当前数据库结构

- 无自定义数据表（不新建 `wp_mluc_*` 表是正确决策）。数据载体：
  - 订单：CPT `mluc_order` + postmeta（`_mluc_pay_*`）
  - 会员：usermeta（`mluc_membership_%`）
  - 选项：`mluc_options`（单数组）
  - 头像库/教材/影片：CPT `mluc_avatar` / `mluc_material` / `mluc_video`
- **License 存储（Phase 2/5 决策）**：遵循 §29「优先复用现有结构」，采用 CPT `mluc_license` + postmeta（license_key/product/status/user/site/expires/limit/count/last_check/grace），与订单（post）保持同构，卸载脚本统一清理，不引入新表。

## 5. 可以复用的代码

| 能力 | 复用点 | 说明 |
| --- | --- | --- |
| 完单/幂等 | `MLUC_Payments::complete_order()` | 原子抢占 + 回滚，支付宝 notify 直接复用 |
| 金额工具 | `api_amount()` / `api_amount_minor()` / `is_zero_decimal()` | 支付宝以元为单位字符串，可复用格式化 |
| 下单 | `ajax_buy_level` 主体 | 保留校验链，仅把网关分派改为注册表 |
| 归属校验 | `MLUC_PayPal::check_order()` 模式 | Alipay AJAX/回跳按同模式实现 |
| 前端购买卡 | `templates/membership-purchase.php` | 网关单选为循环渲染，Alipay 自动出现；JS 增加 `redirect` 流通用分支 |
| 回跳处理 | `MLUC_Stripe::maybe_handle_return()` 模式 | Alipay return 处理按同模式实现 |
| Webhook/Notify | `MLUC_Stripe::register_webhook()` 模式 | Alipay notify 注册 `mluc/v1/alipay/notify` |
| Tab 注册 | `MLUC_Purchases::register_tab()` 模式 | 「我的订单」「我的 License」Tab 同法挂载 |
| UI 文案 | `mluc_ui_label()` | 新增前台文案全部走此机制 |
| 自动加载 | SPL autoloader | 新类零注册成本 |

## 6. 必须重构的代码（最小化原则）

1. **`MLUC_Payments::ajax_buy_level`**：网关分派段（paypal/stripe/manual 三个 if 分支）替换为 `MLUC_Payment_Manager` 注册表分派；校验链、限流、订单创建原样保留。`MLUC_PayPal` / `MLUC_Stripe` 类本体与其 AJAX 端点**不动**，由轻量网关适配器包装。
2. **`MLUC_Elementor::register_widgets`**：注册列表包一层 `mluc_elementor_widgets` filter（Pro 追加组件）。
3. **`moonlight-user-center.php`**：加载新增模块 + `do_action('mluc_loaded')`；版本号 1.7.1 → 2.0.0。
4. **`MLUC_Settings`**：新增「支付宝」「License / Pro」「邮件通知」设置区块（同一 Settings API、同一 sanitize）。私钥显示需脱敏（留空/占位符不覆盖旧值）。
5. **`uninstall.php`**：补充清理 `mluc_license` CPT 与新增 transient。

以上之外**不搬迁文件、不改目录结构**（§73 的理想目录仅作长期演进参考，§5 明确第一阶段不移动现有文件）。

## 7. Free / Pro 拆分方案

- **形态**：本仓库内新增 `pro/moonlight-user-center-pro/` 子目录 = 独立 WordPress 插件（Free Core + Pro Add-on，符合 §4「Free 插件 + Pro 插件」，避免双仓库阶段重复建设）。
- **依赖方向**：Pro → Free 单向。Pro 仅通过 Free 的公开钩子与 `MLUC_License_Manager` 公共 API 交互；Free 对 Pro **零硬依赖**（所有 Pro 检测用 `class_exists` / `function_exists`，Pro 缺失/停用时 Free 全功能正常，符合 §40/§41/§42）。
- **Pro 授权门禁**：Pro 插件自身不判断 `is_pro`，统一走 `MLUC_License_Manager::is_product_active('moonlight-user-center-pro')`（§37：不散落 if 判断）。
- **Pro 首发功能**（真实可用、规模克制）：① Elementor「会员状态卡」组件；② 后台订单 CSV 导出。均为站点级增强，不触碰 Free 核心链路。
- **Free 侧新增能力**（免费版保留完整可用，§3.1）：支付抽象 + 支付宝网关 + 订单/License 数据 + 账户中心「我的订单」「我的 License」Tab + 后台 License 管理/系统状态 + 邮件通知。

## 8. 支付宝接入方案（Phase 4）

- **产品**：电脑网站支付 `alipay.trade.page.pay`（跳转式，适合会员升级页）；一次性购买，无自动扣款（§50/§51）。
- **签名**：RSA2（SHA256），PHP OpenSSL 内置函数实现（`openssl_sign` / `openssl_verify`），**不引入第三方 SDK**；`is_available()` 检测 OpenSSL 与密钥配置，缺一不可用并提示。
- **网关接口**：实现 `MLUC_Payment_Gateway_Interface`（get_id/get_name/is_available/get_supported_currencies/process_payment/handle_return/verify_notify/query_payment/refund/capabilities）。
- **能力矩阵**：仅 `CNY`；`pay_currency_code ≠ CNY` 时不出现支付宝选项（§49/§50 订单带 amount+currency，不写死货币到核心模型）。
- **配置**：启用开关 / App ID / 应用私钥 / 支付宝公钥 / 沙盒或生产网关；后台私钥脱敏显示；密钥不入 Git（§18）。
- **链路**（§19/§27/§28）：
  - 下单：`out_trade_no = _mluc_pay_order_no`（`MLUC + 日期 + 8位随机hex`，服务端生成不可预测）；金额取 `_mluc_pay_price`（服务端源）。
  - 回跳 return：验签 + **服务端 `alipay.trade.query` 复核**后才展示成功（浏览器回跳不作支付依据）。
  - 异步 notify：验签（支付宝公钥）→ 核对 out_trade_no / total_amount / app_id / trade_status（TRADE_SUCCESS|TRADE_FINISHED）→ 复用 `complete_order` 原子完单 → 回 `success`；重复通知幂等（§20）。
- **退款**：接口实现 `alipay.trade.refund`（后台手动触发保留为后续阶段，MVP 先具备能力位）。

## 9. License 方案（Phase 2/5）

- **数据模型**（CPT `mluc_license` + meta，对应 §7 字段）：license_key / product / status(active|inactive|expired|revoked|suspended) / user_id / email / site_url / site_hash / activation_limit / activation_count / expires_at(0=永久) / order_id / last_check_at / grace_until / reminded。
- **Key 规则**（§8）：`MLUC-PRO-XXXX-XXXX-XXXX-XXXX`，`random_bytes(16)` 安全随机，不可预测、不与自增 ID 关联；唯一索引靠生成时查重重试。
- **验证机制**（§9/§10）：本地校验（状态+到期）为主；预留 `license_server_url`（未来独立 License Server）；远程验证结果缓存 12h，网络失败进入 **Grace Period（默认 7 天）**，宽限期内 Pro 功能照常，超期降级；**任何情况下 License Server 故障不影响 Free**（§68）。
- **激活/停用**：本地激活绑定 `site_url + site_hash`，校验状态/到期/激活数上限；支持域名迁移（解绑再绑）、撤销（admin → revoked，Pro 立即停用）、续费（同 user+product 复用 License，`expires_at` 叠加，符合 §24 订单与 License 分离、续费不新建 License）。
- **自动开通链**（§21/§77）：`mluc_payment_completed` → 若订单等级 ∈ 后台配置「自动颁发 License 的等级」（默认空，站点显式开启）→ 为下单用户创建/续期 `moonlight-user-center-pro` License → 前台显示 Pro。
- **邮件**（§47）：购买成功邮件（支付完成钩子）、License 到期前 7 天提醒（每日 cron，`reminded` 标记防重复）。开关后台可配。
- **日志**（§45/§46）：结构化支付日志写入订单 meta `_mluc_pay_log`（order_id/gateway/event/status/created_at 环形截断），debug 级别可开关；不记录任何密钥。

## 10. 风险清单

| # | 风险 | 等级 | 缓解 |
| --- | --- | --- | --- |
| R1 | 改造 `ajax_buy_level` 引入 PayPal/Stripe 回归 | 高 | 适配器仅包装原静态方法，行为逐分支对齐；Phase 10 回归清单覆盖两网关全部路径 |
| R2 | 支付宝验签实现错误导致伪造开通 | 高 | 只信任服务端 query/验签结果；金额、订单号、app_id、trade_status 四重核对；完单必走原子 `complete_order`；伪造 notify 测试用例 |
| R3 | 私钥泄漏（设置页/日志/Git） | 高 | 密钥仅存 options；设置页脱敏（仅显示尾 4 位，提交占位符/空则保留旧值）；日志白名单字段；.gitignore 校验 |
| R4 | 重复 notify / 并发回调重复开通 | 中 | 复用 pending→paid 原子抢占；License 续期幂等标记 |
| R5 | 沙盒/生产网关域名差异 | 中 | 网关 URL 常量 + `mluc_alipay_gateway_url` filter；模式仅 sandbox/production 两值 |
| R6 | PHP 7.4 兼容（新代码误用 8.x 语法） | 中 | 新代码遵守 7.4 语法（无枚举/无构造器提升/箭头函数可用）；便携 PHP 8.2 全量 lint + 人工核查 7.4 特性边界 |
| R7 | Pro 与 Free 类名/函数冲突 | 中 | Pro 全量使用 `MLUCP_` 前缀 + 独立文本域 |
| R8 | License 续期多次发放 | 中 | 同 user+product 复用单 License；`_mluc_license_order` 关联防重 |
| R9 | 双插件（moonlight-shop）同装时的购买入口冲突 | 低 | 沿用 `shop_active()` 现有接管逻辑，支付宝仅在独立支付流程出现 |
| R10 | 时区（支付宝要求 GMT+8 timestamp） | 低 | 固定以 `Asia/Shanghai` 生成 timestamp 字段 |
| R11 | cron 未触发导致到期提醒缺失 | 低 | 提醒为增强功能；License 到期本身由读取时惰性校验兜底 |

## 11. 阶段实施映射（依据 §56–§78）

| Phase | 内容 | 产出 |
| --- | --- | --- |
| 1 | 支付抽象：接口 + 注册表 + 适配器（manual/paypal/stripe） | class-payment-gateway-interface / class-payment-manager / class-gateway-* |
| 2 | 统一订单：order_no、金额/货币落库、支付日志 | class-payments 扩展 + class-payment-log |
| 3 | Free/Pro 解耦：`mluc_loaded`、Elementor 组件过滤器、`MLUC_Pro` 能力位 | 主文件 + class-elementor + class-pro |
| 4 | 支付宝网关全链路 | class-gateway-alipay + 设置区块 + 模板 JS 分支 |
| 5 | License Manager（签发/激活/停用/验证/宽限/撤销/续期） | class-license-manager |
| 6 | 前台「我的订单」「我的 License」Tab + 购买链路打通 | templates/account-orders / account-licenses |
| 7 | 后台：License 管理、系统状态、设置扩展、邮件通知 | class-license-admin / class-system-status / class-email-notifications |
| 8 | 安全审计：对照 §64 清单逐项核查 | SECURITY-CHECK.md（结论写入 CHANGELOG） |
| 9 | 兼容性：PHP 8.2 lint 全量 + 7.4 语法规范核查 | lint 结果记录 |
| 10 | 回归测试清单（PayPal/Stripe/Alipay/Free/Pro） | TEST-PLAN.md + 人工验收项 |

> 交付物齐平 §72：每阶段记录修改/新增/删除文件、数据库、API、安全、测试、已知问题、下一步建议，统一落入 CHANGELOG.md。
