# DATABASE.md — 数据架构与数据字典

> Phase 1 交付物 · 2026-09-27

## 一、存储决策（审计结论）

**保留 WordPress 原生存储（CPT + postmeta / usermeta / options / transient），首版不引入自定义业务表。**

理由（详见 ARCHITECTURE_AUDIT.md 十六.1）：现有全部功能建立在 CPT 生态上（权限、回收站、查询、导出）；目标场景为个人站长/数字商品/卡密/小型电商，量级下 meta 查询性能可接受；避免大迁移风险。计划书第七节的 17 张自定义表**不在首版实现**，其中仅一张作为性能兜底预留（见第六节）。

**但必须新增三件基础设施：DB_VERSION 升级机制、卡密加密双轨、订单索引预留。**

## 二、DB_VERSION 升级机制（新增，对齐计划书第六十三节）

```php
// includes/core/class-db-migrator.php
final class Moonlight_DB_Migrator {
    const OPTION        = 'moonlight_db_version';
    const DB_VERSION    = '2.0.0';   // 语义化：每次 schema/数据结构变更必须递增

    public static function maybe_upgrade(): void {
        $from = get_option( self::OPTION, '0' );
        if ( version_compare( $from, self::DB_VERSION, '>=' ) ) return;
        foreach ( self::steps() as $ver => $callback ) {
            if ( version_compare( $from, $ver, '<' ) ) {
                call_user_func( $callback );
                update_option( self::OPTION, $ver );   // 每步落版本号，可断点续跑
            }
        }
        update_option( self::OPTION, self::DB_VERSION );
    }
    // steps(): '2.0.0' => [__CLASS__, 'to_2_0_0'], ... 升级前自动 ls 落地数据快照（见 MIGRATION_PLAN.md）
}
// 挂载：admin_init（后台触发，避免前台抖动）+ register_activation_hook
```

规则：禁止 `DROP TABLE`；每步迁移可重入（幂等）；迁移失败保留旧数据并在后台显示修复入口；版本号写入 option，卸载时清除（uninstall 同步清理清单见第五节）。

## 三、数据字典（现状 + 变更标注）

### 3.1 商品（CPT `mlshop_product`，保留）

| meta | 说明 | 变更 |
|---|---|---|
| `_mlshop_type` | physical / virtual / cardkey | 不变 |
| `_mlshop_price` / `_mlshop_sku` / `_mlshop_gallery` | 基础 | 不变 |
| `_mlshop_stock` | -1 无限；扣减一律走 Inventory 服务（fail-closed） | 行为变更 |
| `_mlshop_file` / `_mlshop_download_limit` | 下载附件 ID / 有效期（天） | 新增 `_mlshop_download_count`（次数上限，0=不限） |
| `_mlshop_cardkeys` | 卡密明文（旧） | **弃用写入**，迁移入批次模型（见 3.5）；保留只读兼容 |
| `_mlshop_membership_level` | 购买授予等级 | 不变 |
| pay_meta 系列（pay_mode/pay_auth/price_*/credit_price*/allow_coupon/order_expire_*/download_items/…） | 付费内容 | 不变；成为唯一付费墙配置源（合并 MLUC_Paywall） |

### 3.2 订单（CPT `mlshop_order`，保留；用户中心 `mluc_order` 迁移合并）

| meta | 说明 | 变更 |
|---|---|---|
| `_mlshop_status`（权威）+ post_status 双写 | 状态机 | 状态集扩展：pending / **awaiting_payment** / paid / processing / **awaiting_shipment** / **shipped** / **delivered** / completed / cancelled / **refunding** / refunded / failed；post_status 注册同步扩展 |
| `_mlshop_items` | 行项目数组（product_id/qty/unit_price/type） | 增加 `fulfillment` 字段：digital/card/physical 分段各自状态 |
| `_mlshop_total/_subtotal/_shipping/_coupon_*` | 金额，由 Price_Calculator 一次性写入 | 新增 `_mlshop_discount`、`_mlshop_tax`（预留位，恒 0） |
| `_mlshop_gateway` / `_mlshop_payment_*` | 网关与支付信息 | 统一网关 ID 命名（alipay/wechat/paypal/stripe/balance/credit/cod/manual） |
| `_mlshop_shipping_address` | 下单地址快照 | 保留；新增 `_mlshop_address_id`（地址簿引用） |
| `_mlshop_delivery` / `_*_granted` 等 8 个幂等标记 | 交付/授予/回退标记 | 不变，新增 `_mlshop_refund_marks` |
| `_mlshop_tracking_*` | 手工物流 | 保留，shipment 结构化后作为展示兜底 |
| `_mluc_pay_*`（用户中心会员/付费墙订单） | 旧 | **迁移映射**：`_mluc_pay_status`→`_mlshop_status`（paid→completed 或 paid，按 type）、`_mluc_pay_user`→`_mlshop_customer`（新增 user_id meta）、`_mluc_pay_price`→`_mlshop_total`、`_mluc_pay_gateway`→`_mlshop_gateway`、`_mluc_pay_order_no`→`_mlshop_order_no`（商城同步新增服务端订单号，沿用 `MLUC+Ymd+8hex` 格式器 → `ML`+随机）；`_mluc_pay_type=paywall` 订单并入付费墙解锁体系 |

### 3.3 用户 / 会员（usermeta，全部保留）

| key | 说明 |
|---|---|
| `mluc_membership_level` / `mluc_membership_expires` | 会员等级/到期（0=永久）——**沿用不改名**（等级定义在会员中心，商城读取） |
| `mlshop_pay_unlocks` / `mluc_pay_unlocks` | 付费墙解锁账本（迁移时合并为 `moonlight_unlocks`，旧键只读兼容） |
| `mlshop_credit_balance` / `mlshop_credit_ledger` | 积分账本——唯一钱包之一 |
| `_mlshop_balance` | **弃用**：余额网关迁移到积分账本（修复审计 H1 双账本串账；迁移步骤：`_mlshop_balance` 余额一次性并入 `mlshop_credit_balance` 并记流水） |
| `moonlight_addresses`（新增） | 地址簿：数组（name/phone/region{province,city,district}/detail/is_default） |
| `mluc_oauth_*` / `_mluc_avatar*` 等 | OAuth 绑定/头像，沿用 |

### 3.4 设置（option，收敛为单一路径）

- `moonlight_shop_options`（新单一数组，替代 shop 的"独立 option × 50 + mlshop_options 数组"双路径与 `mluc_options`）：Settings API 注册 + 统一 sanitize；键按模块分组（general/product/cart/payment/shipping/card/membership/license/advanced）。
- 迁移：`mlshop_$key` 独立键与 `mlshop_options`、`mluc_options` 的既有值按映射表导入新结构，旧键保留只读（兼容未迁移副本），后台设置页只读写新结构。
- 敏感键（支付密钥）：**存储不变（DB option），展示强制脱敏**（仅尾 4 位），留空不覆盖。

### 3.5 卡密系统（升级核心，对齐计划书第十一/十二节）

新存储模型（postmeta 承载，无新表）：

```text
CPT mlshop_card_batch（新增，非公开）
├── post 字段：post_title=批次名, post_parent=商品ID, post_status=active/disabled
└── meta：_mlshop_batch_note / _mlshop_batch_imported_at / _mlshop_batch_count_total
    / _mlshop_batch_count_available（冗余计数，导入/发放/导出时原子维护）

卡密本体：option 不可用（量大）→ 存储于 postmeta of batch? 不行 → 采用「加密串」方案：
每条卡密 = product 下的一行记录，存储载体：postmeta `_mlshop_batch_keys`（分块 JSON，每块 ≤500 条，
块内 record：{ seq, hash(sha256), enc(AES-256-CBC 原文, key=wp_salt('auth') 派生), status,
              order_id?, user_id?, sold_at?, used_at?, expires_at? }）
状态：available / reserved（下单预留，15 分钟）/ sold / used / expired / disabled
```

- 写路径全部通过 `Moonlight_Card_Stock` 服务；**导出/查看接口只输出解密原文且每次记审计日志**（谁/何时/多少条），后台禁止一次明文输出全量（分页 ≤100/次 + capability `manage_options` + 二次确认 nonce）。
- 发放：沿用现有 CAS 弹出思想，升级为「reserved 占位 + 支付超时回滚 available」；同卡双发在 hash 唯一索引语义上二次校验。
- 兼容：旧 `_mlshop_cardkeys` 明文在 DB_VERSION 迁移步骤中逐条转入新模型（hash+enc），完成后旧 meta 置空并记迁移日志。
- 库存预警：available < 阈值（默认 10）时 Notification 事件 `moonlight_card_stock_low`。

### 3.6 优惠券（CPT `mlshop_coupon` 保留）

- Free：固定金额/百分比 + 有效期 + 总次数 + 用户级次数（现有能力）。
- Pro：满减门槛、指定商品/分类、会员专属、首购——字段扩展为 `_mlshop_coupon_rules` JSON（应用条件白名单），Price_Calculator 统一消费。
- 修复：reserve 后任何失败路径必须 release（审计 M1）。

### 3.7 下载 / 物流 / 日志

- 下载 token：transient 机制保留（`moonlight_dl_{token}`），增加 `downloads_used` 计数与次数上限校验；下载行为记入订单日志（不计明文卡密）。
- 发货单（Phase 6）：CPT `mlshop_shipment`（post_parent=订单），meta：provider/tracking_no/status（created/transit/delivered/exception）/items/created_at；轨迹增量存 `_mlshop_tracking_events`（JSON 数组，cron 追加）。
- 日志：沿用 `_mluc_pay_log` 白名单模式 → `moonlight_log($channel, $event, $context)`，channel ∈ payment/order/shipping/error，敏感键过滤清单：password/token/secret/key/card/payer_info。

## 四、CPT 注册总表（新插件）

| CPT | public | 用途 |
|---|---|---|
| `mlshop_product` | true | 商品（保留） |
| `mlshop_order` | false | 订单（保留；接管 mluc_order 数据） |
| `mlshop_coupon` | false | 优惠券（保留） |
| `mlshop_card_batch` | false | 卡密批次（新增） |
| `mlshop_shipment` | false | 发货单（Phase 6） |
| `mluc_avatar` / `mluc_material` / `mluc_demo_video` | 保留原名 | 头像库/教材/影片（会员中心模块沿用，避免无谓迁移） |

`mluc_order`：迁移后由兼容层继续注册（`publicly_queryable=false`，仅读取），直至版本明确废弃。

## 五、卸载清理清单（修复审计 L5）

uninstall.php 按「设置 → 订单/License → 批次/发货单 → usermeta（`mluc_%` **和** `_mluc_%`、`mlshop_%`、`_mlshop_%`）→ transient（`moonlight_dl_*` 等，用 `_transient_%` 精确清单）→ option」顺序清理；商品/文章内容默认保留（提供 `MOONLIGHT_UNINSTALL_PURGE_ALL` 常量才彻底删除）。

## 六、性能兜底（计划书 Phase 12 预案）

- 首版：订单列表查询限字段 + 分页；统计改为按日聚合 option（cron 每晚重算）替代全量 posts_per_page=-1；商品列表索引靠 `mlshop_product_cat` 分类法 + transient 价格区间缓存（现有）。
- 预留：若 10 万订单实测 meta_query 劣化，则通过 DB_VERSION 步骤引入 `wp_moonlight_order_index`（order_id/customer_id/status/total/created_at 索引表，订单创建/状态变更时同步写入）——表结构现在冻结，实现延后，避免重写。

## 七、迁移与回滚

数据迁移步骤、映射表、回滚策略见 [MIGRATION_PLAN.md](MIGRATION_PLAN.md)。原则：旧键只读兼容优先于迁移动作；任何迁移步骤先落数据快照（option `moonlight_migration_snapshot`，含计数校验），可重入、可回滚。
