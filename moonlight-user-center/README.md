# 漫步白月光用户中心 (moonlight-user-center)

轻量、主题无关的 WordPress 用户中心插件，兼容 **Astra 主题** 与 **Elementor 页面构建器**。提供前端登录、注册、找回密码、账户仪表盘、资料编辑、头像上传等完整功能，内置**统一支付抽象层**（支付宝 / PayPal / Stripe / 线下转账）与 **License 授权体系**，构成 Free + Pro 可商业化产品架构。

- 作者：漫步白月光
- 官网：https://www.at8.fun/
- 版本：2.0.0
- 授权：GPL-2.0-or-later
- 最低要求：WordPress 5.8 / PHP 7.4（支付宝需 PHP OpenSSL 扩展）

## 产品架构

```text
moonlight-user-center (Free 核心)
│   用户系统 / 账户中心 / Elementor / 统一支付抽象 / 订单 / License 管理
│
└── pro/moonlight-user-center-pro (Pro 扩展，独立插件)
        License 激活管理 / Elementor 会员状态卡 / 订单 CSV 导出
        （依赖方向 Pro → Free 单向，Pro 缺失或失效不影响 Free 任何功能）
```

## 功能特性

- **前端登录 / 注册 / 找回密码**：短代码驱动，任意主题可用，样式作用域限定 `.mluc-`。
- **账户中心仪表盘**：概览 / 资料编辑 / 会员等级 / 已购内容 / **我的订单** / **我的 License**，Tab 通过 `mluc_account_tabs` 过滤器可扩展。
- **统一支付抽象层**：`MLUC_Payment_Gateway_Interface` + `MLUC_Payment_Manager` 注册表；内置 manual / PayPal / Stripe / Alipay，第三方可通过 `mluc_payment_gateways_registered` 追加网关。
- **支付宝（电脑网站支付）**：RSA2 签名（OpenSSL 实现，PKCS#1 / PKCS#8 均可）；异步 notify 验签 + 商户身份 + 订单号 + 金额 + 状态四重校验；浏览器回跳以服务端 `alipay.trade.query` 复核为准；重复通知幂等；支持退款；仅 CNY。
- **PayPal / Stripe（零回归）**：既有 Smart Buttons 与 Checkout 流程原样保留；Stripe Webhook 兜底端点 `/wp-json/mluc/v1/stripe-webhook`；支付宝 notify 端点 `/wp-json/mluc/v1/alipay/notify`。
- **License 授权体系**：`MLUC-PRO-XXXX-XXXX-XXXX-XXXX` 安全随机 Key；签发 / 激活 / 停用 / 验证 / 撤销 / 续期；订单与 License 分离（续费叠加有效期，不新建）；远程 License Server 模式可选（验证缓存 12h，断网宽限 7 天，**Server 故障绝不影响 Free**）；支付成功可按配置自动颁发 License；退款自动撤销。
- **后台管理**：License 管理、系统状态体检、订单管理（超时自动关闭）、支付日志（随订单记录、调试上下文可开关）、邮件通知（购买成功 + License 到期提醒）。
- **界面文案全量可自定义** + 多语言（简体中文 / 繁体 / 英文）。

## 商业链路

```text
用户选择套餐 → 服务端定价建单 → 支付宝 / PayPal / Stripe / 线下转账
→ 异步通知验签（或管理员确认）→ complete_order 原子完单
→ 开通会员等级 →（按配置）自动颁发 / 续期 License → Pro 功能启用
```

金额始终以服务端等级配置为准，客户端仅传递 `level` 与 `gateway`；浏览器回跳不作为开通依据。

## 短代码

| 短代码 | 说明 |
| --- | --- |
| `[mluc_login]` | 登录表单 |
| `[mluc_register]` | 注册表单 |
| `[mluc_lostpassword]` | 找回密码 |
| `[mluc_account]` | 账户中心 |

## 安装

1. 将本仓库内容上传至 `wp-content/plugins/moonlight-user-center/`（Pro 需另将 `pro/moonlight-user-center-pro/` 上传为独立插件）；
2. 在 WordPress 后台「插件」中启用「漫步白月光用户中心」（及可选的 Pro）；
3. 进入「用户中心 → 设置」配置货币、支付网关与 License；「系统状态」可一键体检。

## 文档

- [AUDIT.md](AUDIT.md) — Phase 0 架构审计报告
- [SECURITY-CHECK.md](SECURITY-CHECK.md) — Phase 8 安全审计清单
- [TEST-PLAN.md](TEST-PLAN.md) — Phase 10 回归测试计划
- [CHANGELOG.md](CHANGELOG.md) — 更新日志
- [Pro 插件说明](pro/moonlight-user-center-pro/README.md)

## 兼容性

- 与 Astra 主题无冲突（样式作用域限定 `.mluc-`）；
- 与 Elementor 集成，提供专用组件（Pro 可通过 `mluc_elementor_widgets` 追加）；
- 不依赖主题全局函数，可独立运行；Pro 缺失 / 禁用 / 授权失效时 Free 全功能正常。

## 版权

本插件为原创实现，采用 GPL-2.0-or-later 授权，作者：漫步白月光。官网：https://www.at8.fun/
