# CHANGELOG.md

本文件记录 Moonlight Shop 组合包的面向开发者的变更。用户可读的版本说明见各插件 `readme.txt`。

## [2.1.0] — 2026-09-28（游客购买版）

### Free（moonlight-shop）

**游客购买（未注册用户可下单）**
- 结算短代码与下单 AJAX 支持 `wp_ajax_nopriv_mlshop_place_order`：游客填写有效邮箱即可下单（可在设置关闭）
- 游客订单新增 meta：`_mlshop_guest_email` / `_mlshop_guest_token`（48 位随机令牌）/ `_mlshop_guest_created`
- 订单详情查看授权改造：所有者 / 管理员 / 游客令牌持有者（`hash_equals` 时序安全比较），防订单 ID 枚举
- 下载链接：游客订单（user_id=0）凭下载令牌领取（令牌即凭据），登录用户订单维持登录校验
- Stripe / PayPal / 支付宝回跳 URL 附带 `mlshop_gt` 访客令牌，回跳授权三分支校验（支付宝验签前剔除 mlshop* 前缀参数，不影响 RSA2 验签）
- 余额支付与优惠码对游客禁用（服务端强制，不信任前端）
- 防滥用：同 IP 每小时下单限流（默认 10，选项 `guest_order_rate_limit`，0 不限；`mlshop_guest_order_rate_limit` 过滤器可覆盖）
- 订单确认邮件：游客订单发往下单邮箱；登录用户订单邮件行为不变

**付款完成后推荐注册**
- 订单详情页（已付款状态）：游客订单显示「推荐注册」引导卡（立即注册 / 已有账号登录），注册链接预填下单邮箱
- 订单确认邮件：游客订单内嵌注册推荐板块（注册 + 查看订单链接）
- 注册链接优先使用会员中心 `mluc_get_register_url()`，未启用会员中心时回退 `wp_registration_url()`

**后台**
- 商城设置新增「允許訪客購買」「訪客下單限流」
- 订单编辑页客户栏显示「访客订单」标签与联系邮箱

### 测试与文档
- `tests/run.php` 新增游客购买用例组：游客订单判定、令牌校验（有效/错误/空/跨订单）、订单 URL 令牌注入、注册链接回退与邮箱预填、游客邮件收件人与注册板块、登录用户订单不含注册板块
- 新增 `tests/perf-benchmark.php`（Phase 12 可复现基准脚本）
- 新增 `docs/PERFORMANCE_AUDIT.md`（Phase 12 性能审计报告）与 `docs/COMPATIBILITY.md`（Phase 13 兼容性报告，PHP 8.1–8.4）

## [2.0.0] — 2026-09-27（架构重构版）

本版本是 Phase 0–10 架构级重构的产物：审计 → 设计 → Core → 支付 → 数字 → 卡密 → 物流 → 售后 → Pro → 后台/前端收尾。全部变更经独立测试套件验证（313+ 项断言）。

### Free（moonlight-shop）

**安全修复（源自全量代码审计）**
- 修复高危：库存扣减失败仍继续建单（超卖 fail-open）→ fail-closed：拒绝下单 + 补偿回滚（H2）
- 修复高危：余额退款回补写入积分账本（双账本串账）→ 回补到原钱包且原子累加（H1）
- 修复：优惠券预留后置失败不释放（名额泄漏）（M1）；库存回滚读改写非原子（M2）
- 修复：实物订单收货地址仅前端必填 → 服务端强制 + 区码可解析校验（M3）
- 修复：支付密钥回显进设置页 HTML → 脱敏（尾 4 位）+ 空提交不改值（M4）
- 修复：Stripe webhook secret 未配置时跳过验签 → 拒绝处理（M6，含用户中心）
- 修复：PayPal 回跳信任 URL token → 必须匹配下单保存单号（L2）
- 修复：购物车/结算可带入未发布商品（L4 部分）

**架构**
- 新增核心层 `includes/core/`（`Moonlight_*` 命名空间）：统一价格计算器、option 访问器、DB_VERSION 升级机制、迁移器
- 统一价格计算器：消除购物车/付费墙/订单三处分散取价；`moonlight_product_price` / `moonlight_tier_price` 过滤器
- 订单状态机扩展：`awaiting_shipment` / `shipped` / `delivered`；状态标签单一来源重构（消除三处重复）
- 订单新增服务端订单号 `_mlshop_order_no` 与客户 ID `_mlshop_customer`（存量自动回填）

**支付**
- 新增支付宝网关（RSA2 自实现：验签 + app_id + 订单号反查 + 金额 ±0.01 四重校验；首个站内退款能力）
- Stripe webhook 增加金额最小单位复核；Stripe / PayPal 退款 API（部分/全额）
- 币种门控：支付宝仅 CNY 订单可用

**卡密系统**
- 批次模型（CPT `mlshop_card_batch`）+ AES-256-CBC 加密双轨存储（哈希去重，明文不落库）
- CAS 原子发放（一张卡绝不双发）+ 低库存预警（钩子 + 站长邮件，6 小时防轰炸）
- 后台「卡密库存」：批次管理 / 批量导入（≤5000）/ 掩码列表 / 单条授权查看 + 审计日志（环形 100）
- 旧明文池自动迁移（DB_VERSION 2.1.0，幂等断点续跑）

**物流**
- 收货地址簿（usermeta，区码校验，账户中心 Tab + `[mlshop_address]`）
- 运费模板（fixed/piece，商品级挂载，无模板回退全局逻辑）+ 自提
- 发货单（CPT `mlshop_shipment`）+ 轨迹时间线 + 确认收货 + N 天自动完成
- `Shipping_Provider_Interface` + 手工/快递100 内置 Provider；cron 15 分钟自动轨迹同步（失败不影响订单）

**数字商品**
- 下载次数上限（0=不限）+ 下载日志（环形 50）+ `moonlight_download_sendfile` 扩展点（X-Sendfile/X-Accel-Redirect）

**售后**
- Refund Service：用户申请 + 管理员审批 + 全额/部分退款 + 规则闸（虚拟已下载/卡密已交付/实物已发货/会员超 24h 拒绝，过滤器可覆盖）

**其他**
- 缓存兼容：账户/购物车/结算页 `DONOTCACHEPAGE`；测试域名硬编码移除（环境类型判断）
- 卸载清理补齐（三插件 uninstall 清单核对）

### Pro（moonlight-shop-pro）— 新插件
- License 门禁（会员中心授权引擎优先，未启用时本地模式）
- 出站 Webhook：HMAC-SHA256 签名（`t,v1`）、事件订阅、5 分钟 cron 重试退避（5/15/60 分钟，5 次丢弃）、环形投递日志
- Pro 统计：7/30/90 天销售趋势、商品 Top10、支付渠道占比（分页聚合 + 1 小时缓存）
- 订单 CSV 导出（公式注入防护、日期白名单、流式输出）

### 兼容性
- WordPress 6.0+ / PHP 7.4+（OpenSSL 必需）
- 升级自 1.7.x：自动 DB 迁移（卡密加密、订单回填、配置归组），旧键只读兼容，回滚 = 停新启旧

## [1.7.4] — 2026-09-14（历史，摘要）

- 电子商城：实物/虚拟/卡密商品、Cookie 购物车、订单状态机（7 态）、COD/余额/Manual/Stripe/PayPal 网关、优惠券、积分、付费内容引擎、订单统计、群发邮件、Elementor 组件。详见各插件历史 readme。

## [2.0.0] — moonlight-user-center（历史摘要）

- 前端登录/注册/找回密码（节流/蜜罐/防枚举）、账户中心 6 Tab、会员等级后台可配、PayPal/Stripe/支付宝/线下四网关（Payment_Manager）、License 管理（断网宽限）、付费墙、OAuth 五家（含 Apple JWT）、Pro 子插件（License 客户端/Elementor 会员卡/订单导出）。
