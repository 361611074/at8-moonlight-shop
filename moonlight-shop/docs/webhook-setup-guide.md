# moonlight-shop Webhook 配置指南
> 适用版本: v1.0.0+ (moonlight-shop + moonlight-user-center)
> 作者: 漫步白月光 | 官网: https://www.at8.fun/

## 两个端点（插件已内置，无需再写路由）

| 网关 | Webhook URL |
|------|-------------|
| Stripe | `https://your-site.com/?mlshop_stripe_webhook=1` |
| PayPal | `https://your-site.com/?mlshop_paypal_webhook=1` |

> 例：`https://your-site.com/?mlshop_stripe_webhook=1`（把 `your-site.com` 换成你的站点域名）

## 前置要求

1. 站点已安装并启用 **moonlight-shop** 与 **moonlight-user-center** 插件。
2. 在 **WordPress 后台 → 商城 → 商城设定** 中已填写对应网关的 API 密钥：
   - Stripe: `stripe_publishable`, `stripe_secret`, `stripe_webhook_secret`(留空，下一步填)
   - PayPal: `paypal_client_id`, `paypal_secret`, `paypal_webhook_id`(留空，下一步填)
3. 站点支持 **HTTPS**（Stripe/PayPal 都要求 webhook URL 为 HTTPS）。
4. 如果你使用 CDN(阿里云 ESA / Cloudflare)，需将上述两个 URL 加入 **CDN 绕过/直接回源** 列表，否则 CDN 会拦截 POST 请求。

---

## 一、Stripe Webhook 配置

### Step 1. 登录 Stripe Dashboard
- 地址: https://dashboard.stripe.com
- 确保选对账户模式: **测试模式 (Test mode)** 或 **实时模式 (Live mode)**，与你后台填的 key 模式一致。

### Step 2. 创建 Webhook 端点
1. 左侧菜单 **Developers → Webhooks → Add endpoint**
2. 填入 URL:
   ```
   https://your-site.com/?mlshop_stripe_webhook=1
   ```
   > 例如: `https://your-site.com/?mlshop_stripe_webhook=1`
3. **Redirect URL** 留空。
4. 在 **Select events to send** 中勾选:
   - `checkout.session.completed` (必选 —— 用于标记订单 paid)
   - 可选: `charge.dispute.created` 等事件按需添加

### Step 3. 复制 Webhook Secret
创建成功后，在端点详情页找到 **Signing secret**，格式为 `whsec_xxxxxxxx`。
点击 **Reveal** 复制完整字符串。

### Step 4. 填入 WordPress 后台
- 后台路径: **商城 → 商城设定 → Stripe 支付设定**
- 粘贴到 **Webhook Secret** 字段，保存。

### Step 5. 测试验证
1. 前台用测试卡号 (4242 4242 4242 4242) 下一笔订单并完成 Stripe Checkout。
2. 后台 **商城 → 订单** 应出现该订单，状态变为 **已支付**，并触发邮件。
3. 在 Stripe Dashboard **Webhooks → 最新事件** 中确认 `checkout.session.completed` 返回 **200 OK**。

---

## 二、PayPal Webhook 配置

### Step 1. 登录 PayPal Developer Dashboard
- 地址: https://developer.paypal.com/dashboard/applications
- 选对环境: **Sandbox** 或 **Live**，与后台 `paypal_sandbox` 开关一致。

### Step 2. 创建/选择 App
1. 点击 **Create App** (或选择已有 App)。
2. 记下 **Client ID** 和 **Secret**，填入 WordPress 后台 **商城 → 商城设定 → PayPal 支付设定**。

### Step 3. 创建 Webhook
1. 在 App 详情页左侧菜单 **Webhooks → Add Webhook**
2. 填入 URL:
   ```
   https://your-site.com/?mlshop_paypal_webhook=1
   ```
3. **Event types** 至少勾选:
   - `PAYMENT.CAPTURE.COMPLETED` (必选 —— 用于标记订单 paid)
   - 可选: `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED` 等
4. 保存。

### Step 4. 获取 Webhook ID
创建后在列表页会显示 **Webhook ID**，格式为 `WH-xxxxxxxxxxx`。
复制该 ID。

### Step 5. 填入 WordPress 后台
- 后台路径: **商城 → 商城设定 → PayPal 支付设定**
- 粘贴到 **Webhook ID** 字段，保存。

### Step 6. 测试验证
1. 前台用 PayPal Sandbox 账号下单，走 PayPal 支付流程。
2. 后台 **商城 → 订单** 应出现该订单，状态变为 **已支付**，并触发邮件。
3. 在 PayPal Dashboard **Webhooks → Event Log** 确认事件返回 **200 OK**。

---

## 常见问题排查

| 现象 | 原因 | 解法 |
|------|------|------|
| Stripe 端点返回 404 | 站点重写规则失效或 CDN 拦截 | 后台 → 设定 → 永久链接，点一次「保存更改」；或将两个 URL 加入 CDN 回源白名单 |
| PayPal 端点返回 401 / Signature verification failed | `paypal_webhook_id` 未填或填错 | 确认后台填的是 `WH-` 开头的完整 ID |
| Webhook 事件到达但订单未更新 | 订单状态已是 paid/processing 之一，被幂等守卫跳过 | 这是正常行为；可清空订单再测 |
| 测试环境能收到，正式环境收不到 | 模式不一致(测试 key vs 正式 webhook) | 确认 Dashboard 模式与后台 key 模式相同 |
| 本地 phpstudy 调试收不到 webhook | 本地无公网 HTTPS 地址 | 用 ngrok / cloudflare tunnel 做临时公网隧道，或直连服务器 IP |

---

## 安全说明

- Stripe 端点会校验 `HTTP_STRIPE_SIGNATURE`，未配置 `stripe_webhook_secret` 时直接返回 400。
- PayPal 端点会调用官方 `verify-webhook-signature` 接口，未配置 `paypal_webhook_id` 时直接返回 200 但不处理事件(避免 PayPal 无限重试)。
- 两个端点均无认证 nonce，**依赖签名校验**，这是 Stripe/PayPal 官方推荐方式。
- 不要在公开场合泄露 `stripe_secret` / `stripe_webhook_secret` / `paypal_client_id` / `paypal_secret`。

---

## 参考链接
- Stripe Webhook 文档: https://stripe.com/docs/webhooks
- PayPal Webhook 文档: https://developer.paypal.com/docs/api/webhooks/v1/
- moonlight-shop 插件主页: https://www.at8.fun/
