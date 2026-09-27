# PAYMENT.md — 统一支付层设计

> Phase 1 交付物 · 2026-09-27 · 对应计划书第十九–二十四、五十一节

## 一、目标

把审计发现的两套网关体系（商城 `MLSHOP_Gateway` 抽象类 ×5 网关、用户中心 `MLUC_Payment_Gateway_Interface` ×4 网关）收敛为**一套**：以用户中心接口为基（能力位/查询/退款齐全、验签实现质量已验证），融合商城的注册表白名单机制。合并后网关清单：

| Gateway ID | 名称 | 来源 | Free/Pro | 退款 |
|---|---|---|---|---|
| `alipay` | 支付宝 | 用户中心移植（自实现 RSA2，无 SDK） | Free | ✅ 站内（`alipay.trade.refund`） |
| `wechatpay` | 微信支付 | 新增骨架（API v3 自实现：平台证书/回调验签/退款） | Free 基础（Native/JSAPI/H5 按场景子类） | ✅（v3 退款 API） |
| `paypal` | PayPal | 合并两实现（商城 approve 流 + 用户中心 Smart Buttons 流，按场景分发） | Free | ✅（v2 `/payments-captures/{id}/refund`） |
| `stripe` | Stripe | 合并两实现（Checkout Session 唯一路径） | Free | ✅（Refund API） |
| `balance` | 余额 | 商城保留 | Free | 原路回补 |
| `credit` | 积分 | 商城保留（充值/解锁场景） | Free | 原路回补 |
| `cod` | 货到付款 | 商城保留 | Free | —（退款=标记） |
| `manual` | 线下转账 | 合并 | Free | 标记 |

> 支付与 License 解耦（计划书第五十一节）：购买 Pro 的订单是普通订单（gateway=任意），支付成功事件 → License 服务监听 `moonlight_order_paid` 授予——支付层不认识 License。

## 二、网关接口（v2）

```php
interface Moonlight_Payment_Gateway_Interface {
    public function get_id(): string;
    public function get_title(): string;            // 后台/结算展示名
    public function get_description(): string;
    public function is_available(): bool;           // 配置完整性开关（缺密钥=false）
    public function get_capabilities(): array;      // ['currencies'=>['CNY'], 'refund'=>true, 'query'=>true, 'recurring'=>false, 'scenes'=>['web','h5','native']]

    /** 创建支付：返回统一结果 */
    public function create_payment( Moonlight_Order $order ): array;
    // 返回 ['success'=>bool, 'message'=>string, 'flow'=>'redirect|qr|js|none',
    //       'redirect'=>?string, 'qr'=>?string, 'instructions'=>?string]

    public function handle_return( Moonlight_Order $order ): array;   // 同步回跳（只读校验）
    public function handle_notify( WP_REST_Request $request ): array; // 异步通知：内部完成验签+金额+幂等，返回 ['handled'=>bool,'paid'=>bool,'message'=>string]
    public function query_payment( Moonlight_Order $order ): string;  // paid|pending|failed
    public function refund( Moonlight_Order $order, float $amount, string $reason = '' ): array; // ['success','refund_id','message']
}
```

旧接口兼容：`MLUC_Payment_Gateway_Interface` 与 `MLSHOP_Gateway` 由适配器桥接（`class_alias` + 包装），存量自定义网关不失效；标记 `deprecated` 于下一大版本移除。

## 三、Payment_Manager

```php
moonlight_payment()->get_gateway( 'alipay' );               // 实例（不可用也返回实例，is_available 判定）
moonlight_payment()->get_available_gateways( $order );      // 能力位（币种/场景）+ mlshop_enabled_gateways 白名单 + is_available 三重过滤
moonlight_payment()->process( $order_id, $gateway_id );     // 下单唯一入口：白名单校验（AJAX 层再校验一次）→ create_payment → 落支付流水
moonlight_payment()->handle_notify( $gateway_id, $req );    // REST 回调分发 → 网关 handle_notify → mark_paid 统一走订单服务
moonlight_payment()->refund( $order_id, $amount, $reason ); // Refund_Service 调用点
```

注册：`apply_filters('moonlight_payment_gateways', array)` 返回实例数组；`moonlight_enabled_gateways` option 作启用白名单（沿用商城机制）。**网关必须幂等**：同一订单重复 process/notify 不得重复扣款（统一在 Manager 层先查订单终态，网关内部再兜底）。

## 四、回调安全（统一实施，对齐计划书第二十三节）

任何"标记已付"必须经过：`验签 → 订单号反查（不信任回传 ID）→ 金额/币种比对（±0.01）→ 商户/账号比对（app_id / 商户号）→ 订单状态幂等 → Order_Service::mark_paid()`。

| 网关 | 验签 | 金额 | 幂等 | 备注 |
|---|---|---|---|---|
| Alipay | RSA2 公钥验签（异步+回跳+响应体） | `total_amount` ±0.01 | `complete_order` 原子 + 状态终态 | app_id 比对保留；回跳参数先验签后使用（修复误拒问题：**不**对通知参数 sanitize） |
| WeChatPay v3 | 平台证书验签（Wechatpay-Signature/Serial/Timestamp/Nonce） | `amount.total` 分 | out_trade_no 侧状态机 + 通知 ID 去重表（transient） | 解密 resource 用 APIv3 密钥 AES-256-GCM |
| PayPal | 官方 verify-webhook-signature API + capture 四重校验 | amount+currency | capture id 存 meta 去重 | 修复：`_mlshop_paypal_order` 为空时不再信任 URL token |
| Stripe | HMAC t/v1 + 5 分钟时间窗 + hash_equals | **新增**：session.amount_total 与 `_mlshop_total` 最小单位比对 | session id 去重 | secret 未配置 → **503 拒绝**（修复 M6） |

回调端点：统一 REST `moonlight/v1/payment/{gateway}/notify`（保留旧端点 `?mlshop_stripe_webhook=1`、`mluc/v1/*` 并行路由到同一处理器一个版本期）。重放防护：时间窗 + 通知 ID/事件 ID 去重（transient 7 天）。日志：只记审计字段（白名单），永不记密钥/完整报文中的敏感段。

## 五、退款（Refund_Service，对齐计划书第三十六节）

```php
moonlight_refund( $order_id, $amount /* 0=全额 */, $reason, $actor );
// 流程：权限(admin 或 allowed) → 规则闸 → 订单 paid/completed/processing → refunding
//      → 网关 refund()（能力位 false 则仅标记，提示人工处理）
//      → 成功：refunded（或部分退款保留 completed + _mlshop_refunded_total 累计）
//      → Refund 状态机副作用：库存回滚（原子）、优惠券释放、下载权限/卡密撤销、余额/积分原路回补、通知
```

**规则闸**（可过滤 `moonlight_refund_allowed`）：

| 商品类型 | 默认规则 |
|---|---|
| virtual | 已产生下载 → 默认拒绝（后台可配置允许） |
| cardkey | 卡密已展示/已使用 → 拒绝；reserved 未交付 → 允许并回滚 reserved |
| physical | 已发货（存在 shipment）→ 默认拒绝（走售后协商）；未发货 → 允许并回滚库存 |
| 会员/付费墙 | 已授予 → 按"授予后 N 小时内"可配置（默认 24h） |

部分退款：金额累计写入 `_mlshop_refunded_total`，达到 total 自动终态 refunded；网关侧支持部分退款的（alipay/stripe/paypal/wechat）传对应参数。**修复审计 H1**：余额/积分回补只写 `mlshop_credit_balance` 单账本（`_mlshop_balance` 迁移合并）。

## 六、货币

`Moonlight_Money`：支持的币种表（CNY ¥ / USD $ / HKD HK$ / EUR €，option 扩展）；最小单位换算（分）；格式化 `price_html`；网关能力位声明支持币种，结算页过滤不可用网关（沿用用户中心机制）。禁止在代码出现硬编码货币符号（清理审计发现的 HKD 默认写死——改为 option 默认值）。

## 七、测试矩阵（Phase 3 交付时验证）

支付成功/失败/取消、重复回调 ×10、金额篡改（伪造 notify 金额 ±0.02）、订单号伪造、签名伪造、断网（网关 API 不可用时下单不崩溃、订单留 pending）、退款全额/部分、卡密订单退款回滚、余额并发扣款（100 并发单卡不双发）、PayPal/Stripe/支付宝沙箱三端回归。
