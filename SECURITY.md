# SECURITY.md — 安全模型与实施记录

> 状态：2026-09-27（v2.0.0）· 依据 [docs/ARCHITECTURE_AUDIT.md](docs/ARCHITECTURE_AUDIT.md) 全量审计 + Phase 2–8 修复实施

## 一、安全基线（全库强制）

| 维度 | 实施 |
|---|---|
| SQL 注入 | 全部 `$wpdb` 查询经 `$wpdb->prepare()`；元数据操作走 WP API |
| XSS | 模板系统性 `esc_html/esc_attr/esc_url`；无 `echo $_GET/$_POST` 直出 |
| CSRF | 28+ AJAX handler 全部 `check_ajax_referer`；全部 admin_post/meta box `check_admin_referer` + capability |
| IDOR | 订单/下载 token/卡密/地址簿全部属主校验（订单 `_mlshop_user_id` ↔ current user；token 绑定 user_id） |
| 价格篡改 | 前端零金额字段；价格一律服务端 `Moonlight_Price_Calculator` 重算 |
| 文件安全 | 下载 token 化（真实路径永不暴露）+ 文件名白名单清洗防 header 注入；上传走媒体库（WP 自带 MIME 校验） |
| 日志脱敏 | 支付/卡密审计日志白名单字段；密码/密钥/卡密明文/Token 永不入日志 |

## 二、支付安全闭环

所有「标记已付」必须通过：**验签 → 订单号反查（不信任回传 ID）→ 金额/币种比对（±0.01 或最小单位）→ 商户/账号比对 → 状态机幂等**。

| 网关 | 验签 | 金额复核 | 幂等 |
|---|---|---|---|
| 支付宝 | RSA2（通知/回跳/响应体三层）+ app_id 比对 | `total_amount` ±0.01 | 状态机短路 + 原子完单 |
| Stripe | HMAC `t.v1` + 5 分钟时间窗 + `hash_equals`；**secret 未配置 → 400 拒绝** | `session.amount_total` 最小单位比对 | session id + 状态机 |
| PayPal | 官方 verify-webhook-signature API + capture 四重校验（属主/存储单号/金额币种/custom_id） | capture 金额=订单总额 | capture id + 状态机 |
| 回跳 token | PayPal capture 回跳必须匹配下单保存的单号（不信任 URL token） | 同上 | — |

同步回跳只做只读复核（验签 + 服务端查询），**开通唯一依据是异步通知或服务端 API 复核**——前端 `?paid=1` 永不作为付款依据。

## 三、资金一致性

- **单一钱包原则**：余额钱包（`_mlshop_balance`，货币）与积分账本（`mlshop_credit_balance`）严格隔离；余额网关扣款与退款回补写同一账本（原子累加），禁止跨账本（审计 H1 修复）。
- **库存 fail-closed**：原子扣减失败 → 拒绝下单 + 补偿回滚已扣商品（审计 H2 修复）；回滚与扣减对称原子（M2 修复）。
- **优惠券**：reserve 原子占位，任何失败路径 release（M1 修复）；退款/取消自动释放。
- **退款**：Refund_Service 规则闸（虚拟已下载/卡密已交付/实物已发货/会员超 24h 拒绝，过滤器可覆盖）；网关退款失败不改订单状态（提示人工）；部分退款 CAS 累加，达总额自动收尾。

## 四、卡密安全（计划书第十一/十二节）

- 存储：AES-256-CBC（key = `sha256(wp_salt('auth').'|mlshop-card-v1')`，随机 IV/条）+ sha256 哈希去重校验；**明文永不落库**；
- 发放：meta_id + 旧值 CAS 原子认领，一张卡绝不双发（并发压测语义见 tests）；
- 查看：后台列表只显掩码（前 4 + **** + 后 4）；单条明文需 manage_options + nonce + confirm 二次确认；**每次解密查看写审计日志**（环形 100 条：时间/用户/动作/IP）；
- 禁止明文批量导出；旧明文池由 DB 迁移（2.1.0）自动转入加密批次并置空原文。

## 五、密钥管理

- 全部支付/物流密钥存 DB option（不上传、不入 git——`.gitignore` 明确排除密钥类文件）；
- 设置页**永不回显**：只显示尾 4 位；空提交 = 保持原值（`mlshop_sanitize_secret_keep`）；
- Stripe webhook secret 为必填语义：未配置时端点直接拒绝（不降级放行）。

## 六、审计问题闭环状态

| 审计编号 | 问题 | 状态 |
|---|---|---|
| H1 | 余额/积分双账本串账 | ✅ Phase 2（同账本回补） |
| H2 | 库存扣减失败仍下单 | ✅ Phase 2（fail-closed） |
| M1 | 优惠券名额泄漏 | ✅ Phase 2 |
| M2 | 库存回滚非原子 | ✅ Phase 2 |
| M3 | 实物地址不强制 | ✅ Phase 6a（服务端强制） |
| M4 | 支付密钥回显 | ✅ Phase 3a（脱敏） |
| M5 | 测试域名硬编码 | ✅ Phase 9（环境类型判断） |
| M6 | webhook secret 缺省跳过 | ✅ Phase 3a（503 拒绝） |
| L1 | 卡密明文存储 | ✅ Phase 5（加密批次） |
| L2 | PayPal URL token 信任 | ✅ Phase 3a |
| L3 | 付费墙外链 302 | 保留（管理员可控，低危） |
| L4 | Cookie 购物车未签名/下架商品 | ✅ Phase 2（publish 校验；价格服务端重算兜底） |
| L5 | 卸载残留 | ✅ Phase 5/6/8（三插件 uninstall 清单补齐） |
| L6 | UI label 未转义（用户中心） | 保留（管理员级，低危） |
| L7 | editor 可见支付 meta box | 保留（capability 已挡操作，低危） |
| L8–L10 | 信息性/文档失实 | 文档已同步（本文件） |

## 七、报告安全问题的建议

发现漏洞请勿公开 Issue：联系 https://www.at8.fun/ 或仓库所有者私下披露。我们建议的披露周期为 90 天。
