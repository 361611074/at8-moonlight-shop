# DEVELOPMENT.md — 开发指南

## 环境搭建

```text
PHP 7.4+（开发机用 8.3；需 openssl 扩展）
无 composer 依赖；测试不需要 WordPress 环境
```

## 运行测试

```bash
php tests/run.php          # 独立测试套件（WP 函数桩 + 断言），当前 313+ 项
php -l <file>              # 提交前对改动文件做语法校验
```

- `tests/wp-stubs.php`：最小 WordPress 函数桩（含 postmeta 行模型、$wpdb shim、HTTP 桩 `__test_http_handler`、transient、usermeta 等）；
- 桩只模拟被测代码路径的行为语义，不是 WP 模拟器；
- 新功能必须附带测试：把可测逻辑写成静态方法或可子类化的 protected 数据原语（参考 `Moonlight_Card_Stock` 的 8 个 protected 数据原语 + 测试子类模式）。

## 架构约定

### 双命名空间
- `MLSHOP_*`：存量业务类，`includes/class-*.php`（SPL 自动加载映射）；
- `Moonlight_*`：新核心层，`includes/core/class-*.php`（自动加载已注册）；
- `MLPRO_*`：Pro 插件 `moonlight-shop-pro/includes/class-*.php`；
- **依赖方向**：Pro → Free 单向；Free 永不探测 Pro；`Moonlight_*` 可调用 `MLSHOP_*`，反向引用需 `class_exists` 防护。

### 铁律（计划书 + 审计沉淀）
1. **价格**：任何取价走 `Moonlight_Price_Calculator`（tier_price/quote），禁止就地计算；
2. **订单状态**：只能经 `MLSHOP_Order::set_status()`（白名单 + 幂等标记 + 钩子），禁止直写 `_mlshop_status`；
3. **支付**：不信任前端返回；「标记已付」必经验签 + 金额比对 + 状态机幂等；
4. **卡密**：明文永不落库、永不进日志；发放只能走 `Moonlight_Card_Stock::pop()`（CAS）；
5. **资金**：余额（`_mlshop_balance`）与积分（`mlshop_credit_balance`）账本严格隔离；余额增减必须原子（`mlshop_atomic_increment/decrement_user_meta`）；
6. **输出**：一切 escape；**输入**：一切 sanitize + validate；**查询**：一切 `$wpdb->prepare`；
7. **写端点**：nonce + capability 必配；资源访问必查属主；
8. **密钥**：存 option、永不回显（`mlshop_mask_secret`）、空提交不改值（`mlshop_sanitize_secret_keep`）。

### 钩子命名
- 新钩子一律 `moonlight_*`；存量 `mlshop_*` / `mluc_*` 钩子保持并行触发（兼容期不删）；
- 过滤器示例见 README「开发」节。

### 数据库变更流程
1. 改 `Moonlight_DB_Migrator::DB_VERSION`（语义化递增）；
2. 在 `steps()` 注册 `'版本号' => 回调`，回调必须幂等、失败返回 `WP_Error`（升级器会停在该步并落 `moonlight_db_upgrade_error`）;
3. 迁移只增不删（旧键只读兼容优先于迁移动作）；破坏性动作（如置空明文卡密）前先 `Moonlight_Migrations::snapshot()`。

## 目录速查

```text
moonlight-shop/
├── moonlight-shop.php            # 启动器：双自动加载 + plugins_loaded 单例
├── includes/
│   ├── class-*.php               # 存量业务类（订单/商品/购物车/支付/物流/售后…）
│   └── core/                     # 新核心层
│       ├── class-price-calculator.php
│       ├── class-card-stock.php
│       ├── class-refund-service.php
│       ├── class-address-book.php
│       ├── class-region-provider.php (+ data/regions-cn.php)
│       ├── class-db-migrator.php / class-migrations.php
│       ├── interface-shipping-provider.php / class-provider-*.php
│       └── interface-payment-gateway.php
├── templates/                    # 前台模板（.mlshop-* 命名空间）
├── assets/                       # css/js（按需加载）
└── languages/                    # zh_CN/zh_HK/zh_TW/en_US
moonlight-shop-pro/               # Pro（License/Webhook/统计/导出）
tests/                            # wp-stubs.php + run.php
docs/                             # 设计文档（Phase 1 产物）
```

## 提交与发布

- 提交信息：`feat(phaseN): 概要` / `fix(审计编号): 概要` / `docs(phaseN): 概要`；一个阶段一个或多个原子 commit；
- 版本号：插件头 Version + readme.txt Stable Tag 同步；DB_VERSION 独立递增；
- 发布前清单：`php tests/run.php` 全绿 → 全量 `php -l` → 升级路径冒烟（DB_VERSION 步进幂等）→ 卸载清单核对。

## 已知技术债（欢迎认领）

- 付费墙双实现合并（`MLSHOP_Pay_Access` ↔ `MLUC_Paywall`）排在会员中心并线阶段；
- 快递100 Provider 的官方 API 参数需真实 Key 验证（已留 `moonlight_shipping_express100_request` 过滤器）；
- .po 翻译未重编译（v2.0.0 新文案）；
- 统计页 `posts_per_page => -1` 待改分页聚合（Pro 统计已分页）；
- `moonlight/v1` 业务 REST 路由按 docs/API.md 规划逐步落地（当前 AJAX 承载）。
