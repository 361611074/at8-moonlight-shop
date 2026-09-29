# PAYMENT-TESTING.md — 支付网关沙箱/上线测试指南

> 适用版本：Moonlight Shop 2.1.0+ · 对应计划书第六十七节「支付测试」
> 代码层验证已由 `tests/run.php` 覆盖（签名回环 / 金额比对 / 幂等 / 伪造拒绝）；
> 本指南覆盖**需要真实沙箱账号的端到端走单**。上线前建议全部走一遍。

## 通用验收清单（每个网关都要过）

```text
[ ] 支付成功 → 订单 paid → 交付动作发生（下载 token/卡密弹出/会员授予）
[ ] 重复回调（同通知重放 ≥10 次）→ 订单只完成一次
[ ] 金额篡改（伪造通知改金额）→ 拒绝并留痕 _mlshop_pay_amount_mismatch
[ ] 签名伪造（错误签名/缺签名头）→ 4xx，且不入账
[ ] 断网/网关 API 不可用 → 下单不崩溃，订单留 pending，可重试
[ ] 订单过期（默认 30 分钟未付）→ 自动 cancelled，库存/优惠券释放
[ ] 全额退款 → 状态 refunded + 库存回滚 + 优惠券释放
[ ] 部分退款 → 状态不变，_mlshop_refunded_total 累加，累计达总额自动收尾
[ ] 游客订单（若开启）→ 令牌查看/下载正常，登录用户不可越权
```

回调地址速查（都展示在对应设置页）：

```text
支付宝:  POST {site}/wp-json/mlshop/v1/alipay/notify
微信:    POST {site}/wp-json/mlshop/v1/wechat/notify
Stripe:  POST {site}/?mlshop_stripe_webhook=1
PayPal:  Webhook ID 填入设置页（verify-webhook-signature API 校验）
```

---

## 一、支付宝（alipay.trade.page.pay）

### 沙箱准备

1. 登录 [支付宝开放平台沙箱](https://openhome.alipay.com/develop/sandbox/app)：取 **沙箱版 AppID、支付宝网关地址（openapi-sandbox.dl.alipaydev.com）、应用私钥/支付宝公钥**（沙箱工具一键生成 RSA2 密钥）；
2. 商城 → 商城设置 → 支付寶支付：模式=**沙盒**，填 AppID / 應用私鑰 / 支付寶公鑰；启用支付宝；
3. 把设置页展示的异步通知 URL 填入沙箱应用的「应用网关 & 授权回调地址」→ 开发设置（沙箱 notify 只对公网可达地址生效——本机调试用内网穿透：ngrok / frp / 花生壳）；
4. 沙箱买家账号（平台提供的测试买家 App）登录后付款。

### 走单用例

| 用例 | 操作 | 预期 |
|---|---|---|
| 成功支付 | 下单 → 沙箱付款 | 订单 paid；`_mlshop_payment_id` = 交易号；收到邮件 |
| 金额篡改 | 用抓包改 notify 的 total_amount（不重签） | 拒绝完单，meta 留痕 |
| 伪造通知 | 用 curl POST 假 notify（不签名） | 验签失败，返回非 success |
| 重复通知 | 在沙箱应用里重发 notify（或抓包重放 10 次） | 只完单一次 |
| 回跳展示 | 付款后浏览器跳回 | 订单页显示已付（若回跳验签失败仍以 notify 完单，属 fail-closed 正常） |
| 退款 | 订单编辑页 → 退款（留空金额=全额） | 网关真实退回沙箱买家；状态 refunded |

### 上线切换

设置页模式改回**正式**，密钥换正式应用的；确认正式应用已签约「电脑网站支付」；notify URL 在正式应用开发设置里同样填写。

---

## 二、微信支付（Native / H5）

### 准备

1. 微信商户平台（pay.weixin.qq.com）→ 产品中心开通 **Native 支付 / H5 支付**；
2. API 安全：申请 **API 证书**（取商户证书序列号 + apiclient_key.pem 私钥）、设置 **APIv3 密钥**；新商户建议用 **微信支付公钥**（公钥序列号 + 公钥文本）；老商户用平台证书（插件自动下载，无需配置）；
3. 商城设置 → 微信支付：商户号 / AppID（须与商户号绑定）/ 證書序列號 / 應用私鑰 / APIv3 密鑰 /（可选公钥序列号+公钥）；场景=auto；
4. Native 支付需在商户平台配置「支付回调 URL」域名（H5 需填 H5 支付域名）。notify URL 见上表。

### 走单用例

| 用例 | 操作 | 预期 |
|---|---|---|
| Native 成功 | 桌面下单 → 微信扫码（真实微信扫沙箱不可用，用 1 分钱真实单验证） | 订单页二维码 → 付款 → 订单页「我已完成支付」或自动刷新变 paid |
| H5 成功 | 手机浏览器下单 → 拉起微信支付 | 同上 |
| 回调验签 | 用假 body 打 notify | 401/400，不入账 |
| 金额篡改 | 抓包改 resource（无法伪造 GCM 密文/签名） | 解密失败或金额比对拒绝 |
| 关单 | 下单不付 → 30 分钟过期 | 订单 cancelled + 微信侧订单关闭（close） |
| 退款 | 后台全额退款 | 真实退回；状态 refunded |

> 本地开发注意：微信回调必须公网 HTTPS；Native code_url 二维码默认走外部 QR 图片服务渲染，可用过滤器 `mlshop_wechat_qr_img_url` 换自建。

---

## 三、Stripe

### Test 模式走单

1. Dashboard（Test mode）→ 取 pk_test_/sk_test_，Developers → Webhooks → Add endpoint（URL 见上表）→ 事件订阅 `checkout.session.completed` → 取 whsec_；
2. 商城设置 → Stripe：Test 模式勾选 + 三个 Key；4242 4242 4242 4242 测试卡走单；
3. 用例：成功支付 / webhook 重发（Send test webhook 与真实重放）/ 金额篡改（Dashboard 手动改 session 金额不可能——用旧 whsec 配新请求验拒绝）/ 退款（后台触发，Dashboard 见 refund 记录）；
4. **webhook secret 必填**：未配置时插件直接拒绝（防放大），这是设计行为。

## 四、PayPal

1. developer.paypal.com 建 Sandbox App（client_id/secret）；商城设置 PayPal：沙盒勾选 + 凭据；
2. Webhooks：Add Webhook（URL 见上表）订阅 `CHECKOUT.ORDER.APPROVED` / `PAYMENT.CAPTURE.COMPLETED`，Webhook ID 填设置页；
3. Sandbox 买家账号走单：Smart Buttons 拉起 → 付款 → capture 回跳 + webhook 双通道完单（只完一次）；
4. 退款走后台全额退款 → Sandbox 商户账号余额减少；
5. 上线切 Live App 凭据 + Live Webhook。

---

## 五、问题排查

| 症状 | 排查 |
|---|---|
| 付款成功但订单一直 pending | ① notify URL 公网可达？（浏览器直接访问应非 404）② 站点是否被缓存插件缓存了回调（回调路由已有 DONOTCACHEPAGE 保护，检查 CDN 规则）③ 开 WP_DEBUG 后看 `mlshop_alipay_log` / `mlshop_wechat_log` 钩子日志 |
| 回跳后订单页未刷新 | 正常——完单主通道是异步 notify；点「我已完成支付」（微信）或刷新订单页触发服务端 query 复核 |
| 退款按钮报「网关退款失败」 | 检查密钥/证书有效期；或先到网关后台人工退款，再用「仅标记退款」收尾 |
| 通知日志在哪 | 支付日志入订单 meta `_mlshop_payment_log`（后台订单编辑页可见）；审计类日志 option `_mlshop_card_audit`（卡密） |
