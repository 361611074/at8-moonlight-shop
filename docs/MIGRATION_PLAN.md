# MIGRATION_PLAN.md — 数据迁移与兼容计划

> Phase 1 交付物 · 2026-09-27 · 对应计划书第六十四、六十五节

## 一、迁移范围总述

审计确认两插件**均无自定义表**，因此迁移对象是：**option 键、postmeta 键、usermeta 键、CPT 数据、cron、页面短代码**。所有迁移通过 `Moonlight_DB_Migrator`（DATABASE.md 第二节）以 DB_VERSION 步骤执行：可重入、幂等、每步前落快照、失败可回滚。

迁移触发：新版 `moonlight-shop` 激活时——

1. 若检测到旧 `moonlight-user-center` 处于激活状态：后台显示迁移引导页（统计将迁移的数据量：订单 N 条 / License N 条 / 会员用户 N 人 / 设置项 N 键），**用户确认后才执行**；执行完毕自动停用旧插件（不卸载、不删数据）。
2. 若旧插件未安装/未激活：静默做兼容扫描（发现 `mluc_*` 残留数据时仍按映射迁移，保证换插件不丢数据）。

## 二、映射总表

### 2.1 option

| 旧 | 新 | 方法 |
|---|---|---|
| `mlshop_options`（数组：页面 ID、default_gateway、currency_symbol） | `moonlight_shop_options.general.*` | 键展开映射；`currency_symbol`→`moonlight_shop_options.general.currency`（HKD） |
| 独立 option `mlshop_*`（约 50 个：stripe_*/paypal_*/shipping_*/pay_*/…） | `moonlight_shop_options.<module>.*` | 按 Settings 注册表自动遍历迁移（读取全部 `mlshop_` 前缀 option → 归组写入 → 旧键保留只读） |
| `mluc_options`（会员等级/支付密钥/OAuth/界面文案/License） | `moonlight_shop_options.membership.* / payment.* / oauth.* / ui.* / license.*` | `membership_levels` 结构原样搬（等级定义不变则用户等级无需变动）；支付宝/Stripe/PayPal 密钥原样搬（DB 内不落日志） |
| `mlshop_enabled_gateways` / `mlshop_url_slugs` | `moonlight_shop_options.payment.enabled` / `.general.slugs` | 直搬 |
| `moonlight_db_version` | 新增 | 初始 `2.0.0` |

### 2.2 CPT / postmeta

| 旧 | 新 | 方法 |
|---|---|---|
| `mluc_order`（会员购买/付费墙订单） | `mlshop_order` | 逐条复制 post + meta 按映射改键（见下）；**原 `mluc_order` 保留不删**，兼容层只读注册 |
| `_mluc_pay_status` pending/paid/cancelled | `_mlshop_status` pending/completed/cancelled（会员单直接终态；type=paywall 的 paid → completed） | 映射 |
| `_mluc_pay_user` | `_mlshop_customer`（int user_id；商城订单同步补写该键） | 直转 |
| `_mluc_pay_price` / `_mluc_pay_gateway` / `_mluc_pay_order_no` | `_mlshop_total` / `_mlshop_gateway` / `_mlshop_order_no` | 直转（manual→manual，paypal/stripe/alipay 同名） |
| `_mluc_pay_log` | `_mlshop_payment_log` | 直转 |
| `_mluc_pay_granted/_mluc_pay_refunded/_mluc_pw_granted/_mluc_pay_autoclosed` | `_mlshop_membership_granted` / `_mlshop_funds_reversed` / `_mlshop_paywall_granted` / `_mlshop_autoclosed` | 映射（幂等标记语义对齐） |
| `mluc_license` CPT（含 meta） | `moonlight_license` CPT（同名 meta 保留） | 复制 post + meta；旧 CPT 只读保留 |
| `_mlshop_cardkeys`（旧明文卡密） | 批次模型（`mlshop_card_batch` + 加密块） | 逐商品：按行拆分 → 生成一个"迁移批次" → 每条写 hash+enc+available → 完成后旧 meta 写入 `_mlshop_cardkeys_migrated=1` 并**置空原文** |
| `mlshop_order` 既有 meta | 不变 | 仅补 `_mlshop_order_no`（存量订单回填）与 `_mlshop_customer` |

### 2.3 usermeta

| 旧 | 新 | 方法 |
|---|---|---|
| `mluc_membership_level` / `mluc_membership_expires` | **同名保留** | 无动作（等级体系不变是硬约束） |
| `mlshop_pay_unlocks` + `mluc_pay_unlocks` | `moonlight_unlocks`（合并去重，同 post_id 取较晚过期） | 合并；旧键保留只读 |
| `_mlshop_balance`（余额网关账本） | `mlshop_credit_balance` | **H1 修复迁移**：余额逐用户并入积分账本 + 写一条 `balance_migration` 流水；`_mlshop_balance` 归零保留（防重复迁移，用 option 标记断点） |
| `mluc_oauth_*` / `_mluc_avatar*` / `mluc_material_dl_*` | 同名保留 | 无动作 |
| `mlshop_credit_ledger` | 保留 | 流水追加迁移记录 |

### 2.4 页面 / 短代码 / cron / 小工具

- 页面：旧页面内容含 `[mluc_*]` / `[mlshop_*]` → **短代码全部保留解析**（compat 层把 `[mluc_account]` 等映射到新渲染器），页面不改动。新增页面（`/downloads` `/license-keys` `/address` 等）激活时按需创建。
- Cron：旧 `mluc_pay_autoclose`、`mlshop_expire_pending_orders` 迁移到 `moonlight_expire_pending_orders`（逻辑合并：状态机扩展后一个事件处理）；`mluc_license_expiry_check` → `moonlight_license_daily`。旧事件在旧插件停用时清理。
- 小工具：`mlshop_*` widget 注册名保留，无迁移。

## 三、兼容策略（迁移期三层兜底）

1. **兼容层 `compat/`**（随新插件分发，计划"最后清理"阶段才移除）：
   - 短代码别名：`[mluc_*]` 全集 → 新实现；
   - 类别名：`class_alias( Moonlight_X, MLUC_X / MLSHOP_X )`（存量第三方钩子/模板引用不断）；
   - 钩子别名：`mluc_*` / `mlshop_*` 旧事件与新 `moonlight_*` 事件**并行触发**；
   - meta 读取兜底：Order/会员/License 读取器遇到旧键自动映射（写恒用新键）。
2. **旧插件共存保护**：新插件激活后若旧插件仍激活 → 后台强提示并限制：不做迁移、两插件前台短代码以先加载者为准（新插件 `plugins_loaded` 优先级 5 抢注册）。文档明确建议顺序：装新 → 迁移 → 停用旧。
3. **数据零删除**：迁移全程不 DROP、不删 post/usermeta/option；仅 `_mlshop_cardkeys` 置空属计划内（原文已入加密块，且迁移快照留档）。

## 四、迁移快照与回滚策略

- **快照**：每个迁移步骤开始前写 `moonlight_migration_snapshot` option：`{step, started_at, counts:{orders,licenses,users,options,cards}, checksum}`（计数 + 关键 meta 抽样 hash）。步骤完成写 `moonlight_migration_log`（追加式）。
- **回滚**：
  - Level 1（业务回滚）：停用新插件 → 重新启用旧插件。因旧数据未删、旧键保留，旧插件**立刻恢复原样工作**（这是"旧键只读保留"策略的核心动机）。
  - Level 2（数据回滚）：迁移产生的唯一破坏性变更 = 卡密明文置空 + `_mlshop_balance` 归零。快照记录受影响对象 ID 清单，`wp-cli` 式修复脚本 `moonlight-rollback-step`（后台工具页提供）可从加密块/积分流水反向还原。
  - Level 3（重置）：卸载并按 DATABASE.md 第五节清理后全新安装（最后手段）。
- **回滚触发条件**：迁移步骤抛异常（自动停止 + 后台红色修复入口，不半途继续）；计数校验不符（orders 复制数 ≠ 快照数）；迁移后冒烟测试失败（Phase 2 自动的 10 项核心冒烟）。

## 五、迁移执行清单（Phase 2 起逐项实现）

| 步骤 | 内容 | 所属 Phase |
|---|---|---|
| M0 | DB_Migrator + 快照/日志/修复工具页 | Phase 2 |
| M1 | option 归组迁移（shop 双路径 + mluc_options → moonlight_shop_options） | Phase 2 |
| M2 | 订单 meta 补写（`_mlshop_order_no`/`_mlshop_customer` 存量回填） | Phase 2 |
| M3 | mluc_order → mlshop_order 订单迁移 + 状态映射 | Phase 2 |
| M4 | 余额账本合并（H1） | Phase 2 |
| M5 | 解锁账本合并（mluc/mlshop_pay_unlocks → moonlight_unlocks） | Phase 4 |
| M6 | 卡密明文 → 批次加密双轨 | Phase 5 |
| M7 | License CPT 迁移 + License Server 端点 | Phase 8 |
| M8 | 页面/短代码/cron 切换 + 旧插件停用引导 | Phase 9 |
| M9 | 兼容层冻结（只修 bug），发布"旧插件退役"说明 | Phase 10 后 |
