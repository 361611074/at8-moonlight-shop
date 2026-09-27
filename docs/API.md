# API.md — REST API 设计（moonlight/v1）

> Phase 1 交付物 · 2026-09-27 · 对应计划书第四十一节

## 一、原则

- 命名空间 `moonlight/v1`；资源式路由；**全部路由显式 `permission_callback`**（绝不省略）；写操作同时校验 `X-WP-Nonce`（`wp_rest`）或专用 nonce。
- 参数一律 `$request->get_param()` + JSON Schema（`register_rest_route` args）声明式校验；输出经统一资源格式化器转义。
- 现有 admin-ajax 全部保留（兼容期不删），新 REST 逐步承载同样能力；回调类路由例外规则见 PAYMENT.md。

## 二、公开路由（无需登录，只读）

| 方法 | 路由 | 说明 |
|---|---|---|
| GET | `/moonlight/v1/products` | 商品列表：`page/per_page/search/category/type/orderby`（whitelist）；返回发布商品公开字段（价格按调用者会员价上下文，未登录=原价） |
| GET | `/moonlight/v1/products/{id}` | 商品详情（公开字段；下载文件/卡密/付费内容字段永不输出） |
| GET | `/moonlight/v1/products/{id}/price` | 当前用户视角价格（会员价计算器结果） |
| GET | `/moonlight/v1/cart` | 购物车内容（Cookie 会话；游客可用） |
| GET | `/moonlight/v1/shipping/quote` | 运费试算（items hash → Price_Calculator，防篡改：金额只算不收） |
| GET | `/moonlight/v1/regions` | 省市区树（Region Provider） |

## 三、登录路由（permission：logged_in + wp_rest nonce + 属主校验）

| 方法 | 路由 | 说明 |
|---|---|---|
| POST | `/moonlight/v1/cart/items` | 加购 `{product_id, qty}`（服务端夹紧库存；类型/发布状态校验） |
| PATCH | `/moonlight/v1/cart/items/{product_id}` | 改数量 |
| DELETE | `/moonlight/v1/cart/items/{product_id}` | 删除；`DELETE /cart` 清空 |
| POST | `/moonlight/v1/cart/coupon` | 应用/移除优惠券 `{code}` |
| POST | `/moonlight/v1/checkout` | 下单 `{gateway, address_id?, pickup?, customer_note?}` → 创建订单 + create_payment 结果 |
| GET | `/moonlight/v1/orders` | 本人订单列表（服务端强制 `customer = current_user`） |
| GET | `/moonlight/v1/orders/{id}` | 订单详情（**属主校验，非本人 403**；含 fulfillment 分段状态/卡密掩码/下载 token） |
| POST | `/moonlight/v1/orders/{id}/cancel` | 取消（仅 pending/awaiting_payment） |
| POST | `/moonlight/v1/orders/{id}/confirm` | 确认收货（delivered→completed） |
| POST | `/moonlight/v1/orders/{id}/refund` | 申请售后（生成售后请求，后台审核；规则闸预检） |
| GET | `/moonlight/v1/downloads` | 本人下载权限列表（含剩余次数/有效期） |
| GET | `/moonlight/v1/downloads/{token}` | 发起下载（token 属主校验；302 到一次性流式端点或直接流式） |
| GET | `/moonlight/v1/license-keys` | 本人卡密列表（**列表只给掩码 + id**；`POST /license-keys/{id}/reveal` 二次 nonce 后返回原文并记审计） |
| GET/POST/PATCH/DELETE | `/moonlight/v1/addresses[/{id}]` | 地址簿 CRUD（属主校验 + 区码校验走 Region Provider） |
| GET | `/moonlight/v1/account` | 账户聚合（会员等级/到期、积分、订单计数） |
| GET/POST | `/moonlight/v1/notifications` | 站内通知列表 / 全部已读 |

## 四、管理路由（permission：`manage_moonlight` capability + wp_rest nonce）

| 方法 | 路由 | 说明 |
|---|---|---|
| GET | `/moonlight/v1/admin/stats` | 仪表盘统计（今日/本月销售额、订单数、待发货、库存预警） |
| GET/PATCH | `/moonlight/v1/admin/orders[/{id}]` | 订单检索/改状态（PATCH body 仅 `status` + `note`，走 Order_Service） |
| POST | `/moonlight/v1/admin/orders/{id}/ship` | 发货 `{company, tracking_no}` 或 `{provider, template}` |
| POST | `/moonlight/v1/admin/orders/{id}/refund` | 审核退款 `{amount, approve}` |
| GET/POST | `/moonlight/v1/admin/card-batches[/{id}]` | 批次管理；`POST .../keys:import`（文本粘贴，≤5000/批）；`GET .../keys?page=` 分页密文，`reveal` 单条需二次确认 |
| GET/PATCH | `/moonlight/v1/admin/settings` | 设置读写（敏感键只写不读，读返回脱敏占位） |

## 五、回调路由（公开但验签，见 PAYMENT.md）

| 方法 | 路由 |
|---|---|
| POST | `/moonlight/v1/payment/{gateway}/notify`（alipay/wechat/paypal/stripe） |

兼容旧端点：`?mlshop_stripe_webhook=1`、`mluc/v1/alipay/notify`、`mluc/v1/stripe-webhook` 保留一个版本期并内部转发。

## 六、统一响应与错误

```json
// 成功
{ "code": "moonlight_ok", "data": { ... }, "meta": { "total": 12, "pages": 2 } }
// 失败（HTTP 状态码语义化：400 参数 / 401 未登录 / 403 属主或权限 / 404 / 409 状态冲突 / 429 限流）
{ "code": "moonlight_forbidden_order_owner", "message": "无权访问该订单", "data": { "status": 403 } }
```

错误码清单统一常量化（`Moonlight_Api::ERR_*`），前端 i18n 映射。限流：下单/解锁/发卡 reveal 类端点按 user+IP 节流（复用 Auth 的节流器，CDN 场景可配信任头）。

## 七、安全清单落地对照（计划书第四十三–四十五节）

- 权限：每路由独立 `permission_callback`；管理路由统一 `current_user_can('manage_moonlight')`（新 capability，administrator 默认拥有，shop_manager 角色一并授予）。
- Nonce：浏览器端调用必须带 `wp_rest`；服务端到服务端（回调）用验签替代。
- IDOR：所有 `/orders/{id}`、`/downloads/{token}`、`/license-keys/{id}`、`/addresses/{id}` 第一步校验属主（订单 `_mlshop_customer` / token 绑定 user / 地址属主数组键）。
- 输出转义：REST 默认 JSON 安全；字段格式化器对富文本字段（订单备注等）走 `wp_kses_post`。
- 不接受前端金额：`/checkout` body 中不存在任何价格字段（价格篡改面为零）。
