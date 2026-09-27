# 安全审计清单（Phase 8）

> 审计对象：v2.0.0 全部新增/修改代码 + 既有支付链路复核。逐项对照开发计划 §64。

## 结论

**未发现高危问题。** 支付开通链路的所有入口均经过「签名/凭据验证 + 订单号核对 + 金额核对 + 原子完单」四道关口；权限、Nonce、归属校验全部到位；密钥零落盘（代码/日志/前端）。

## 逐项核查

| 检查项 | 结果 | 说明 |
| --- | --- | --- |
| XSS | ✅ | 新增后台/前台输出全部经 `esc_html()` / `esc_attr()` / `esc_url()` / `esc_js()`；模板直接输出变量处已逐一核对（account-orders / account-licenses / 设置区块 / License 管理页） |
| CSRF | ✅ | 新增全部写操作带 nonce：License 签发/行操作（`mluc_lic_issue` / `mluc_lic_action`）、Pro 激活/停用（`mlucp_license_activate` / `mlucp_license_deactivate`）、支付宝退款（`mluc_alipay_refund`）；支付宝 notify/Stripe webhook 除外（外部回调，靠签名验证，不适用 nonce） |
| SQL Injection | ✅ | 新增原生 SQL 仅两处（订单号查重 / 订单号与 txn_ref 反查订单），全部 `$wpdb->prepare()`；其余走元数据/文章 API |
| 权限绕过 | ✅ | 后台页面与全部 admin-post 处理器 `current_user_can('manage_options')` + `check_admin_referer` 双重守卫；会员等级字段维持原有管理员限定 |
| IDOR | ✅ | 「我的订单」「我的 License」仅按 `post_author` / 归属 meta 查询当前用户数据；Alipay/Stripe 回跳校验 `_mluc_pay_user` ↔ `get_current_user_id()`；Pro 停用仅作用于绑定当前站点的 License |
| 订单越权 | ✅ | `ajax_buy_level` / `ajax_unlock` 维持原归属校验；`complete_order` 按订单号定位且网关匹配 |
| License 越权 | ✅ | 用户 A 无法激活/操作用户 B 的 License：激活仅后台管理员入口；前台仅展示 `post_author` 为自己的 License；Key 为 128 位安全随机，不可枚举 |
| Webhook 伪造 | ✅ | 支付宝 notify：RSA2 验签 + `app_id` 比对 + 订单号反查 + 金额比对 + 状态核对；Stripe webhook：HMAC 验签 + 时间窗 + livemode 一致性 + **回查 Stripe API**（不信任请求体，既有逻辑保持） |
| 支付金额篡改 | ✅ | 金额唯一来源 = 服务端 `_mluc_pay_price`（下单时从等级定义读取，客户端只传 level）；notify/回跳/查询三方金额均与本地订单比对（±0.01）；签名负例测试验证篡改必被拒 |
| 重复回调 / Replay | ✅ | 完单唯一入口 `complete_order`：单条 UPDATE `pending→paid` 原子抢占，天然幂等；License 续费复用同一 License（user+product 唯一）；到期提醒有 `reminded` 标记 |
| Nonce | ✅ | 前端 AJAX 沿用 `mluc_nonce`（`check_ajax_referer`）；见 CSRF 行 |
| REST API | ✅ | 新增路由仅 `mluc/v1/alipay/notify`（`permission_callback=__return_true` 但强校验签名——与既有 stripe-webhook 同模式，属外部回调必需）；REST 响应体仅 `success`/`fail` |
| AJAX | ✅ | 未新增 AJAX 端点；既有端点未放松校验 |
| 文件上传 | ✅ | 未触碰（无新增上传面） |
| 敏感信息泄漏 | ✅ | 支付宝私钥/支付宝公钥：仅存 `mluc_options`，后台**脱敏显示**（仅尾 4 位，留空 = 保持旧值），日志白名单字段不含密钥；验证性测试密钥未入 Git（.gitignore 排除） |
| 日志泄漏 | ✅ | `MLUC_Payment_Log` 白名单键（txn_ref/trade_no/flow/amount/currency/code/message），且上下文仅在「调试日志」开启时写入；日志随订单存取，不落全局文件 |
| 回跳页直接开通 | ✅ | 支付宝回跳：验签后**仍以服务端 `alipay.trade.query` 结果为准**，回跳参数本身不触发开通；Stripe 既有逻辑不变 |
| 金额客户端可控 | ✅ | 见「支付金额篡改」行 |
| 提权 | ✅ | License 签发/撤销/续期、支付宝退款、订单确认均 `manage_options`；Pro 授权判断集中在 `MLUC_License_Manager::is_product_active()`，无散落判断 |

## 重点设计安全说明

1. **支付 → Pro 链路的唯一开通路径**：网关验证成功 → `MLUC_Payments::complete_order`（原子）→ `mluc_payment_completed` → License 签发（后台等级白名单显式开启才生效）。任何前端参数都无法绕过该链路的金额/归属/状态校验。
2. **License Key 不可预测**：`random_bytes(16)` + `MLUC-PRO-` 前缀分组，与自增 ID 无关联；查重重试保证唯一。
3. **宽限期不放大风险**：远程验证失败仅沿用「最后一次成功验证」结果 7 天，撤销/过期会清除缓存并立即失效。
4. **Pro 失效不影响 Free**：所有 Pro 门禁在 Pro 插件内部；Free 代码不调用任何 MLUCP_* 类。
