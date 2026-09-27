# 回归测试计划（Phase 10）

> 环境：WordPress 5.8+ / PHP 7.4–8.4。机器可执行部分已在开发环境完成（见「自动化」节）；
> 标注 **[人工]** 的项需在真实 WordPress + 支付平台环境按步骤验收。

## 自动化（已完成 ✅）

| 项 | 工具 | 结果 |
| --- | --- | --- |
| 全量 PHP 语法 lint（41 个文件，Free + Pro） | `php -l`（PHP 8.5.11 CLI） | 全部通过 |
| 加载冒烟测试（WP 桩环境实例化全部模块，Free + Pro 主文件完整加载） | 自研桩测试 | SMOKE-OK |
| 网关接口契约（4 网关实现接口完整性 + 能力矩阵） | 反射断言 | 全部通过 |
| 支付宝签名内容排序（key 升序 / 剔除 sign、sign_type / 空值剔除） | 反射断言 | 通过 |
| PEM 归一化（PKCS#1 / PKCS#8 裸 base64 → openssl 可解析） | 真实 RSA 2048 密钥 | 通过 |
| RSA2 签名 → 验签正例 / **篡改负例**（金额篡改、状态篡改必被拒） | 真实密钥 | SIGN-OK |
| 响应体验签（`alipay_trade_query_response` 原文提取 + 验签 + 负例） | 真实密钥 | 通过 |

## Free 回归 **[人工]**

```text
[ ] 安装 / 启用（激活页自动创建：账户中心 / 登录 / 注册 / 找回密码）
[ ] 前台登录 / 注册 / 找回密码（短代码页 + Elementor 组件）
[ ] 账户中心：概览 / 资料 / 会员 / 已购 / 我的订单 / 我的 License 六个 Tab
[ ] 资料修改（昵称 / 简介 / 网站 / 电话）+ 修改密码
[ ] 头像库选择
[ ] 会员等级定义：新增 / 编辑 / 删除 / free 开关
[ ] Elementor：登录 / 注册 / 资料卡组件正常渲染
[ ] 卸载：订单、License、usermeta、transient 全部清理
```

## Pro 回归 **[人工]**

```text
[ ] 未安装 Pro：Free 全功能正常，无 Fatal / 无错误日志
[ ] 安装未激活 License：Pro 菜单可见，Pro 功能（Elementor 会员状态卡 / 订单 CSV 导出）不可用
[ ] License 激活（合法 Key）→ Pro 功能立即可用
[ ] License 重复激活本站 → 幂等成功
[ ] License 激活其他站点已绑定的 Key → 明确报错（域名迁移需先停用）
[ ] License 停用 → Pro 功能立即停止，Free 正常
[ ] License 过期 → Pro 停止；Free 正常；续期后恢复
[ ] License 撤销（后台）→ Pro 停止且不可再激活
[ ] 配置 license_server_url 后：验证走远程；断网 7 天内 Pro 保持可用（宽限期），7 天后停止
[ ] License Server 宕机：Free 用户中心全功能不受影响
```

## 支付回归 **[人工]**

### PayPal（不得回归）

```text
[ ] 沙盒下单 → Smart Buttons 弹出 → 支付 → 服务端 capture → 金额校验 → 自动开通
[ ] 金额与订单不一致 → 拒绝开通并提示
[ ] capture 失败 / 中途取消 → 订单保持 pending，可重试
```

### Stripe（不得回归）

```text
[ ] 下单跳转 Checkout → 支付 → 回跳自动校验开通
[ ] Webhook 兜底（checkout.session.completed）开通
[ ] 伪造 / 错误签名 webhook → 400 拒绝
[ ] 取消支付 → 回跳提示 cancelled，订单 pending
```

### 支付宝（新增，对照 §67）

```text
[ ] 前提：货币代码 = CNY，OpenSSL 可用，App ID / 私钥 / 支付宝公钥配置完整
[ ] 沙盒下单 → 跳转支付宝收银台 → 支付成功
[ ] 异步 notify → 验签 + 金额 + 订单号 → 自动开通（对应等级）
[ ] 浏览器回跳 → 服务端 alipay.trade.query 复核后展示成功（回跳单独伪造参数不开通）
[ ] 重复 notify（重放同一通知）→ 只开通一次
[ ] 伪造 notify（篡改金额 / 订单号 / 签名）→ 拒绝（fail）
[ ] 错误金额通知 → 拒绝
[ ] 支付取消 → 订单 pending，72h 后自动关闭（autoclose cron）
[ ] 已 paid 订单再次通知 → 幂等返回 success，无重复开通
[ ] 管理员退款（订单页按钮）→ 原路退回 + 关联 License 撤销
```

### 线下转账（不得回归）

```text
[ ] 下单展示付款说明 → 管理员「确认收款并开通」→ 开通
[ ] 管理员取消订单
```

## 断网测试 **[人工]**（§68）

```text
[ ] 关闭外网：Free 登录 / 注册 / 账户中心正常（本地模式 License 正常）
[ ] 支付宝 / Stripe / PayPal API 不可达：下单报明确错误，订单自动作废，站点其他功能正常
[ ] license_server_url 不可达：宽限期内 Pro 正常，Free 恒正常
```

## 兼容性（Phase 9）

```text
[✅] PHP 8.5 语法全量 lint（自动化）
[✅] 新增代码遵守 PHP 7.4 语法（无 nullsafe / match / 构造器提升 / 枚举 / readonly，已扫描确认）
[ ] [人工] PHP 7.4 / 8.0–8.4 各版本冒烟（php -l + 一次完整安装）
[ ] [人工] WordPress 5.8 / 最新版 安装启用
```
