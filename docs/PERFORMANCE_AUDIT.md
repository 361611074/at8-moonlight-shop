# Phase 12：性能审计报告（PERFORMANCE AUDIT）

> 适用版本：Moonlight Shop 组合包 2.0.x（Free / Pro / 用户中心）
> 审计方式：静态代码审计 + 可复现基准脚本（`tests/perf-benchmark.php`）
> 审计范围对应计划书第十二节（Phase 12）要求的五类查询：
> **商品查询 / 订单查询 / 后台订单 / 卡密库存 / 物流查询**，
> 以及 1000 / 10000 商品、10000 用户、100000 订单量级验证。

---

## 一、方法论与环境说明

本次审计在无 WordPress 运行时的环境下完成，采用两条互补路径：

1. **静态审计**：逐文件审查全部 SQL 触点（`get_posts` / `WP_Query` /
   `get_post_meta` / `update_option` / autoload 选项），评估其在
   10⁴–10⁵ 行数据量级下的查询复杂度与索引命中情况。
2. **可复现基准**：提供 `tests/perf-benchmark.php`，可在与生产同规格的
   环境中（WP-CLI 或浏览器带 `?mlshop_bench=<token>` 执行）灌入
   1000 商品 / 10000 商品 / 100000 订单，实测五类查询耗时与查询次数，
   输出逐项对照表。**基准结论以生产环境实测为准，本文只给出静态判定
   与预期量级**，不虚构未执行的运行时数字。

---

## 二、总体结论

| 领域 | 静态判定 | 10 万订单量级风险 |
|---|---|---|
| 商品查询（列表/详情） | ✅ 良好 | 低 |
| 订单查询（用户侧） | ⚠️ 可用但有索引隐患 | 中 |
| 后台订单列表 | ✅ 良好（分页 + 状态过滤走 post_status） | 低 |
| 卡密库存（批次模型） | ✅ 良好（CAS + 精确行级操作） | 低 |
| 物流查询（发货单/轨迹） | ⚠️ 有 N+1 与无限量查询 | 中 |
| 统计聚合（Free 统计页 / Pro 统计） | ⚠️ 全量拉 ID + 逐单取 meta | **中高（已有缓存缓解）** |
| options autoload | ⚠️ 两个增长型选项 | 中（长期运行站点） |

无「发布阻塞级」问题；两处 ⚠️ 均给出明确修复建议（见第四节）。

---

## 三、分项审计

### 1. 商品查询（1000 / 10000 商品）

- 归档页 `archive-product.php` 走标准主查询（分页、无 meta_query 重查询），
  价格区间筛选使用 option 缓存的边界（`mlshop_price_bounds_v1`），
  10000 商品下仍为**每页一次主查询 + 少量 term 查询**。
- 单商品页为标准 `single` 查询，价格经 `Moonlight_Price_Calculator` 纯内存计算。
- **判定：合格。** 商品表查询复杂度不随商品总数增长（分页恒定）。

### 2. 订单查询（10000 用户 / 100000 订单）

- 用户侧 `MLSHOP_Order::get_user_orders()` 使用
  `meta_key=_mlshop_user_id + meta_value=<uid>` 查询。
  WordPress 默认只在 `postmeta.meta_key` 上有索引，**meta_value 匹配
  是全表扫描**；在 10⁵ 订单 × 每单约 12 条 meta（≈1.2×10⁶ 行）时，
  单次「我的订单」查询预计数十至数百毫秒，且随订单总量线性恶化。
- 前台「我的订单」有分页（`posts_per_page=$limit`），无 `posts_per_page=-1`。
- **判定：可用，但建议加复合索引（见第四节 R1）。**

### 3. 后台订单列表

- 订单状态映射为独立 `post_status`（`mlshop_pending` 等 10 态），
  后台列表按状态过滤直接命中 `wp_posts` 的 `post_status + post_type`
  复合索引，**不经 postmeta**。
- `pre_get_posts` 修复（`fix_admin_list_status`）保证列表查询不退化为
  全状态扫描。
- **判定：合格。** 10 万订单下后台列表首屏为恒定成本分页查询。

### 4. 卡密库存（批次模型 + AES-256-CBC）

- `Moonlight_Card_Stock::pop()`：先取活跃批次（`find_all_batches`，
  批次数通常 < 50），再**按 meta_id 精确行级 CAS 认领**，无全池扫描、
  无「读-改-写」竞态；并发冲突最多重试 5 次后由上层报「库存不足」。
- 状态迁移通过 meta_key 换名（`_mlshop_card_a → _mlshop_card_s`），
  可用池查询永远命中 `_mlshop_card_a` 精确 key，不膨胀。
- 审计日志 `_mlshop_card_audit`（见第 5 点风险）。
- **判定：合格。** 这是相比 1.x 明文池最大的性能与正确性双赢点。

### 5. 物流查询（发货单 / 轨迹）

- 发货单按订单 meta 关联，轨迹同步走 cron（15 分钟主路径 + 10 分钟
  节流兜底），页面加载**绝不**触发外部物流 API。
- ⚠️ `MLSHOP_Shipping` 内两处 `get_posts`（`$posts = get_posts(...)`,
  行 568 / 718 附近）未显式 `fields => ids` / `no_found_rows`，在
  发货单数万级时会产生不必要的整对象物化。
- **判定：可用；优化建议见 R2。**

### 6. 统计聚合（Free 统计页 / Pro 统计）

- Free `MLSHOP_Statistics::compute()`：`get_posts(fields=ids, 无 LIMIT)`
  拉取时间窗内全部订单 ID，然后**逐单 `get_post_meta` ×4**
  （status/total/user_id/items）。10 万订单、跨 90 天窗口时为
  ≈4×10⁵ 次 meta 读取，属该插件最大的单点热点。
  缓解因素：管理页访问频率低；Pro 统计（`mlpro_analytics_cache_*`）
  已按 7/30/90 天做 **1 小时结果缓存** + 分页聚合，
  `update_post_term_cache=false` 关闭了 term 物化。
- **判定：中高风险但已被缓存策略覆盖。** 根治方案见 R3。

### 7. options autoload（长期运行风险）

- `_mlshop_card_audit`（卡密审计日志）：环形裁剪存在，但单条记录含
  JSON，长期高销量站点可能长到数百 KB —— 若 autoload=yes 会拖慢
  **每个**请求。审计已确认其读写路径，建议站长在「卡密库存」页观察
  体积；若超标可改 `autoload=no`（R4）。
- `mlpro_analytics_cache_{range}`（Pro 统计缓存）为固定 3 个 key，
  体积可控，无风险。

---

## 四、修复建议（按优先级）

| 编号 | 建议 | 预期收益 | 实施成本 |
|---|---|---|---|
| R1 | `get_user_orders` 改为按 `_mlshop_order_no` / 自建关联表查询，或给 postmeta 增加 `(meta_key, meta_value(32))` 复合索引（激活器一次性执行） | 「我的订单」查询从全表扫描降为索引命中，10 万订单下 <10ms | 中 |
| R3 | 统计聚合改 SQL 直接聚合（`$wpdb->get_results` 按状态/日期 GROUP BY），或引入汇总表 `mlshop_daily_stats` | 统计页从 O(N) meta 读降为 O(天数) 聚合 | 中 |
| R2 | 物流两处 `get_posts` 补 `fields => 'ids'` + `no_found_rows => true` | 发货单批量操作内存占用降低 ~60% | 低 |
| R4 | `_mlshop_card_audit` 写入时检查体积，超 256KB 自动收缩到最近 500 条并设 `autoload=no` | 防止 autoload 选项拖慢全站 | 低 |
| R5 | `expire_pending_orders` 已按 200/批 ASC 分批（✅ 已达标），无需改动 | — | — |

> R1/R3 涉及数据迁移，按计划书「不允许重新制造复杂度」原则列为
> **建议项而非本版强制项**；现有缓存与分页策略已保证可用性。

---

## 五、基准脚本使用方法

```bash
# 在目标 WordPress 环境根目录（含 wp-load.php）执行：
php tests/perf-benchmark.php --products=10000 --orders=100000 --users=10000

# 或浏览器访问（需在 wp-config.php 定义 MLSHOP_BENCH_TOKEN）：
# https://example.com/?mlshop_bench=<token>&products=1000&orders=10000
```

脚本输出五类查询在两个量级下的耗时与查询计数对照表，
结果可直接回填本报告第五节（留空待生产实测）：

| 场景 | 商品查询 | 订单查询 | 后台订单 | 卡密 pop | 物流查询 |
|---|---|---|---|---|---|
| 1000 商品 / 1 万订单 | 待实测 | 待实测 | 待实测 | 待实测 | 待实测 |
| 10000 商品 / 10 万订单 | 待实测 | 待实测 | 待实测 | 待实测 | 待实测 |

---

## 六、结论

- 五类查询均无 fail-open 型性能缺陷；卡密 CAS 模型与订单状态机设计
  在大数据量下依然正确且高效。
- 已落地的缓存策略（Pro 统计 1h 缓存、价格边界 option 缓存、cron 化
  物流同步、过期订单 200/批分批）覆盖了主要热点。
- R1/R3 为 10 万订单量级下的两个值得排期的优化项，其余为低成本低 hanging fruit。
