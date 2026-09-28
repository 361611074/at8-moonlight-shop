# Moonlight Shop — 轻量 WordPress 商城 + 会员中心

> **一个轻量级 WordPress 会员中心 + 商城系统**：实物 / 虚拟下载 / 卡密商品、购物车、订单、物流、售后、会员、可扩展支付网关。主题无关，兼容 Astra 与 Elementor，**不依赖 WooCommerce**。
>
> 作者：漫步白月光（https://www.at8.fun/） · License: GPL-2.0-or-later

## 适合谁

```text
软件作者 / 主题插件作者 / 数字产品站 / 会员站 / 卡密站 / 小型电商 / 个人站长
```

核心卖点：轻、快、简单、数字商品友好、卡密友好、会员友好、国内支付友好（支付宝）、全球支付友好（PayPal/Stripe）、支持实物物流。

## 目录结构

```text
moonlight-shop/          Free 主插件（完整可用的商城，安装即可卖货）
moonlight-shop-pro/      Pro 插件（增强：Webhook / Pro 统计 / 订单导出，需 Free）
moonlight-user-center/   会员中心插件（现行版本，账户/登录/会员等级/OAuth；后续版本并入 Free）
docs/                    架构与设计文档（ARCHITECTURE / DATABASE / API / PAYMENT / SHIPPING / FREE-PRO / MIGRATION_PLAN）
tests/                   独立测试套件（php tests/run.php，无需 WordPress 环境）
```

## Free / Pro 区别

| 能力 | Free | Pro |
|---|---|---|
| 实物/虚拟/卡密商品、购物车、订单 | ✅ | ✅ |
| 支付：支付宝 / PayPal / Stripe / 余额 / 积分 / 货到付款 / 线下转账 | ✅ | ✅ |
| 运费模板（固定/满额包邮/按件）、物流轨迹、售后退款 | ✅ | ✅ |
| 会员等级（后台可配）、会员价、优惠券（固定/百分比） | ✅ | ✅ |
| 卡密批次加密库存、自动发卡、下载次数/有效期 | ✅ | ✅ |
| 游客购买（邮箱下单 + 访问令牌取货 + 付款后推荐注册） | ✅ | ✅ |
| 出站 Webhook（HMAC 签名 + 重试退避） | — | ✅ |
| Pro 统计（30/90 天趋势、商品排行、渠道占比） | — | ✅ |
| 订单 CSV 导出 | — | ✅ |
| 运费模板按重量/按地区、电子面单 | — | 路线图 |

Pro 通过 License 激活（引擎来自会员中心 `MLUC_License_Manager`，未启用会员中心时为本地模式）。详见 [docs/FREE-PRO.md](docs/FREE-PRO.md)。

## 安装

1. 将 `moonlight-shop/`（及可选的 `moonlight-shop-pro/`、`moonlight-user-center/`）上传到 `wp-content/plugins/`；
2. 后台「插件」启用（建议顺序：会员中心 → 商城 → Pro）；
3. 激活后自动创建页面：商品/购物车/结算（商城）、登录/注册/找回密码/账户中心（会员中心）；
4. 进入 **后台 → 商城设置** 配置货币、支付网关、运费。详见 [INSTALL.md](INSTALL.md)。

## 快速上手

### 创建商品
后台 → 商品 → 新增，选择类型：
- **实物**：填价格/库存/重量，挂运费模板，买家收货后确认或 7 天自动完成；
- **虚拟下载**：填媒体库文件附件 ID、下载有效期（天）、下载次数上限（0 不限）；
- **卡密**：在「卡密」文本框粘贴卡密（每行一条）——保存即加密入库存池（AES-256-CBC + 哈希去重，明文不落库），售出自动弹出发放，库存低于 10 自动邮件预警。批量导入/批次管理在 **后台 → 商城 → 卡密库存**。

### 配置支付
后台 → 商城设置 → 对应网关 section：
- **支付宝**：开放平台 AppID + 应用私钥 + 支付宝公钥（电脑网站支付，RSA2），支持沙盒；异步通知 URL 会展示在设置页，填到开放平台；
- **Stripe**：Publishable + Secret Key + Webhook Signing Secret（金额/签名双重校验，secret 未配置时 webhook 直接拒绝）；
- **PayPal**：Client ID + Secret + Webhook ID（沙盒/正式切换）；
- **余额/积分**：充值套餐在「付费内容」区配置；
- 所有密钥保存后不再回显（只显示尾 4 位），留空表示不修改。

### 配置物流
后台 → 商城设置 → 运費設定：启用物流、固定运费、满额包邮门槛、运费模板（每行 `名称|模式|首件|续件|门槛`）、自提开关、物流 Provider（手工 / 快递100 聚合查询）、自动轨迹查询、N 天自动确认收货。订单编辑页可一键创建发货单；轨迹由 cron 每 15 分钟自动同步（查询失败绝不影响订单）。

### 游客购买（未注册用户下单）
后台 → 商城设置 → 基本設定：「允許訪客購買」（默认开启）+「訪客下單限流」（同 IP 每小时单数，0 不限）。访客在结算页填邮箱即可下单：订单确认、下载链接与卡密发送到该邮箱；订单页/下载/支付回跳均凭 48 位访问令牌放行（余额支付与优惠码需登录后使用）。**付款完成后**，订单页与订单邮件会自动推荐其注册成为网站用户（注册链接优先使用会员中心注册页，并预填下单邮箱）。

### 会员
会员等级在 **用户中心 → 设置 → 会员等级** 配置（名称/价格/有效期/排序）；商品可设置购买后授予等级；商品与付费内容支持 gold/diamond 会员价；到期自动降级（读取时惰性 + 每日检查）。

## 短代码

```text
[mlshop_products] [mlshop_cart] [mlshop_checkout] [mlshop_orders] [mlshop_order id=""]
[mlshop_downloads] [mlshop_address] [mlshop_coupons] [mlshop_membership]
```

会员中心短代码（`moonlight-user-center`）：`[mluc_login] [mluc_register] [mluc_lostpassword] [mluc_account]`。Elementor 组件可选（商品网格/单页/加购/分类/购物车/收藏/搜索 + 会员组件），卸载 Elementor 后短代码体系完整可用。

## REST API

统一命名空间 `moonlight/v1`（规划与权限模型见 [docs/API.md](docs/API.md)）；现行已暴露：

```text
POST /wp-json/mlshop/v1/alipay/notify     支付宝异步通知（验签保障）
POST /wp-json/mluc/v1/alipay/notify       会员中心支付宝通知（旧路由，保留）
POST /wp-json/mluc/v1/stripe-webhook      会员中心 Stripe Webhook
```

## 开发（Hooks / 过滤器）

```php
// 状态机与生命周期（每状态一个动作钩子 + 统一变更钩子）
do_action('mlshop_order_status_changed', $order_id, $from, $to);
do_action('mlshop_order_paid', $order_id);            // paid/processing/awaiting_shipment/shipped/delivered/completed/cancelled/refunded/failed

// 价格（统一价格计算器）
apply_filters('moonlight_product_price', $price, $product, $level);
apply_filters('moonlight_tier_price', $price, $ctx);   // ['kind','level','sell','gold','diamond']

// 支付 / 物流 / 售后 / 卡密
apply_filters('moonlight_payment_gateways', $gateways);        // 网关注册表
apply_filters('moonlight_enabled_gateways', $ids);             // 前台公开白名单
apply_filters('moonlight_shipping_providers', $providers);     // 物流 Provider 注册表
apply_filters('moonlight_refund_allowed', $allowed, $ctx);     // 售后规则闸
do_action('moonlight_refund_processed', $order_id, $amount, $partial);
do_action('moonlight_card_stock_low', $product_id, $available);
apply_filters('moonlight_download_sendfile', false, $file, $data); // X-Sendfile/X-Accel-Redirect 接管下载流
apply_filters('moonlight_regions', $regions);                  // 地区数据源
```

命名规范：类 `MLSHOP_*`（存量）/ `Moonlight_*`（新核心层，`includes/core/`），Pro 为 `MLPRO_*`；函数 `mlshop_*`；REST `moonlight/v1`；CSS `.mlshop-*` / `.mluc-*`。开发指南见 [DEVELOPMENT.md](DEVELOPMENT.md)。

## 安全

安全基线与实施记录见 [SECURITY.md](SECURITY.md) 与 [docs/ARCHITECTURE_AUDIT.md](docs/ARCHITECTURE_AUDIT.md)（全量代码审计报告）。要点：全部写端点 nonce + capability；资源属主校验（订单/下载/卡密/地址）；服务端取价（前端零金额字段）；支付回调验签 + 金额比对 + 幂等三重；卡密 AES-256-CBC 加密存储 + 单条授权查看审计；支付密钥永不回显；下载路径永不暴露（token 化）。

## 升级

- 数据库结构由 `DB_VERSION`（option `moonlight_db_version`）管理，后台自动增量升级，每步幂等、可断点续跑、失败即停并保留修复入口；
- 从 v1.7.x 升级：明文卡密自动迁移到加密批次；订单回填服务端订单号；旧配置键只读兼容；`moonlight-user-center` 数据（会员订单/License）在管理员确认后复制合并（见 [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md)）；
- 回滚：停用新版启用旧版即可恢复（迁移不删除旧数据）。

## 文档索引

[架构](docs/ARCHITECTURE.md) · [数据库](docs/DATABASE.md) · [API](docs/API.md) · [支付](docs/PAYMENT.md) · [物流](docs/SHIPPING.md) · [Free/Pro](docs/FREE-PRO.md) · [迁移](docs/MIGRATION_PLAN.md) · [审计](docs/ARCHITECTURE_AUDIT.md) · [安全](SECURITY.md) · [安装](INSTALL.md) · [开发](DEVELOPMENT.md) · [变更](CHANGELOG.md)
