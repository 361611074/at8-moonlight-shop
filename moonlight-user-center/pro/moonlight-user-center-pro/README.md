# 漫步白月光用户中心 Pro (moonlight-user-center-pro)

「漫步白月光用户中心」（Free 版）的商业化扩展插件。安装目录 `pro/moonlight-user-center-pro/`，上传后作为**独立插件**在 WordPress 后台启用。

- 版本：2.0.0
- 授权：GPL-2.0-or-later
- 依赖：**必须先安装并启用** moonlight-user-center（Free 版）2.0.0+

## Pro 功能

| 功能 | 说明 |
| --- | --- |
| License 激活管理 | 后台「用户中心 → License（Pro）」输入 Key 激活 / 停用，支持域名迁移 |
| Elementor 会员状态卡 | 登录用户展示当前等级徽章、到期时间与升级按钮 |
| 订单 CSV 导出 | 会员订单列表页一键导出（含订单号 / 金额 / 币种 / 网关 / 交易号） |

后续 Pro 功能将以相同方式挂载到 Free 提供的扩展点（`mluc_loaded`、`mluc_account_tabs`、`mluc_elementor_widgets`、`mluc_payment_gateways_registered`）。

## 授权体系

- 所有 Pro 功能统一受 `MLUCP_License_Client::is_active()` 门禁，底层调用 Free 的 `MLUC_License_Manager`。
- **本地验证模式**（默认）：License 签发后绑定站点，状态 / 到期 / 站点绑定在本地校验。
- **远程 License Server 模式**（可选）：在 Free 后台「设置 → License / Pro」填写 `license_server_url` 后，验证请求发送到独立授权服务器；结果缓存 12 小时；网络失败进入 **7 天宽限期**，期间 Pro 功能照常，超期才降级；License Server 故障**绝不影响 Free 用户中心**。
- Free 版在未安装 / 禁用 / 授权失效的任何情况下均正常工作（登录、注册、账户中心、支付等基础功能不依赖 Pro）。

## 安装

1. 将 `pro/moonlight-user-center-pro/` 上传至 `wp-content/plugins/`；
2. 确认「漫步白月光用户中心」已启用；
3. 启用本插件，进入「用户中心 → License（Pro）」输入 License Key 激活。

## License Key 获取

- 站点在 Free 后台「设置 → License / Pro → 支付成功自动颁发 License 的会员等级」配置等级后，用户购买这些等级并支付成功，将自动获得绑定其账号的 License；
- 也可由管理员在「用户中心 → License 管理」手工签发。

## 与 Free 的边界

- Pro 仅通过 Free 的公开钩子与 `MLUC_License_Manager` 公共 API 交互，**不反向修改 Free 的任何行为**；
- 类前缀 `MLUCP_` 与 Free 的 `MLUC_` 完全隔离，可安全共存与独立启停。
