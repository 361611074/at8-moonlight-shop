# 更新日志

所有显著变更将记录在本文件。格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)。

## [2.1.0] - 2026-10-03

独立会员中心积分体系（参考子比主题的成熟逻辑，原创实现，无代码复制）。

### 新增

- **积分账本 + 余额钱包**：`MLUC_Credit`（mluc_credit_balance）/ `MLUC_Wallet`（mluc_balance），增减全部走 SQL 原子操作（`mluc_atomic_increment/decrement_user_meta`），并发消费不丢更新、不会扣成负数；各带 200 条环形流水。
- **积分充值**：套餐（后台每行「积分|金额」）+ 自定义积分数；金额一律服务端按「充值比例」（1 货币 = N 积分，后台可配）换算，支持单次限额；充值订单复用统一订单模型（`_mluc_pay_type=recharge`），走 PayPal / Stripe / 支付宝 / 线下转账，付款完成后幂等入账积分。
- **积分兑换余额**：按后台「兑换比例」（N 积分 = 1 货币余额，后台可配）原子转出积分、原子转入余额，两本账各留互相指向的流水；支持单次最低兑换量。
- **余额支付 / 积分支付网关**：接入 v2.0 统一网关注册表，可直接购买会员等级或解锁付费内容；扣款与完单之间失败自动回补账本；余额退款原路退回钱包、积分退款按订单扣减数回补；充值订单禁止使用余额/积分支付（防循环刷单）。
- **每日签到送积分**：基础奖励 + 连续签到每满 N 天额外加成（三项均后台可配）；断签自动重置连续天数；一天仅可签一次（服务端时区日期判定）。
- **后台调账**：用户编辑页管理员可手工调整余额 / 积分（正加负减），强制流水留痕（含操作人）。
- **设置区块**：「用户中心 → 设置 → 积分与余额」——启用开关、积分名称、充值比例、充值套餐、自定义限额、余额开关、兑换比例、兑换下限、签到奖励，全部后台可自定义；前台「积分余额」Tab 展示余额卡片 / 充值 / 兑换 / 双流水，界面文案可自定义。
- **共存**：与 Moonlight Shop 同装且商城积分模块已注册 credit Tab 时，本模块自动让位；数据键（mluc_%）随卸载清理。

### 测试

- 新增 `tests/run-credit.php`（24 项断言，假 $wpdb 驱动原子账本）：套餐解析、比例守卫、金额↔积分换算、扣减拒绝/负数防护、流水环形截断、签到连续天数与奖励。全部通过；`tests/run.php` 既有用例零回归（基线中 run-user.php 的 license-bridge 致命错误为既有问题，与本版无关）。

### 修复

- 签到并发防护：`ajax_checkin` 在「是否已签」判定与写入之间无原子性，双击/并发可重复领奖；现以每用户 MySQL 命名锁（`GET_LOCK`，立取不等待）串行化发奖段，锁不可用时优雅降级为无锁行为。

## [2.0.0] - 2026-09-27

Free + Pro 商业化体系第一版：统一支付抽象、支付宝网关、License 授权、前后台会员订单/License 页面。依据《Free-Pro-Alipay-Agent-开发计划》Phase 0–10 实施。

### 新增（Free 核心）

- **支付网关抽象层**：`MLUC_Payment_Gateway_Interface` + `MLUC_Payment_Manager` 注册表；内置 manual / paypal / stripe / alipay 四个实现，`mluc_payment_gateways_registered` 过滤器可注册第三方网关；下单流程（会员购买 + 付费墙）统一经注册表分派，核心不再硬编码网关分支。
- **支付宝网关（电脑网站支付）**：`alipay.trade.page.pay` 跳转式支付，RSA2 签名（PHP OpenSSL 实现，支持 PKCS#1 / PKCS#8）；异步 notify 验签 + 商户/订单号/金额/状态四重校验；回跳仅验签并以服务端 `alipay.trade.query` 复核为准；支持查询与退款（`alipay.trade.refund`，订单页管理按钮）；仅 CNY，配置不完整或 OpenSSL 缺失时自动隐藏。后台新增「在线支付（支付宝）」设置区块，密钥脱敏显示（尾 4 位，留空保持不变）。
- **统一订单要素**：订单新增服务端订单号 `_mluc_pay_order_no`（`MLUC + 日期 + 8 位随机 hex`，作为支付宝 out_trade_no）与结算币种 `_mluc_pay_currency`；完单/下单/退款写入结构化支付日志 `_mluc_pay_log`（白名单字段，调试上下文可开关）。
- **License 管理器**：CPT `mluc_license`；`MLUC-PRO-XXXX-XXXX-XXXX-XXXX` 安全随机 Key；签发/激活/停用/验证/宽限期（远程模式 7 天）/撤销/恢复/续期；订单与 License 分离，续费复用同一 License 叠加有效期；`is_product_active()` 为全站统一 Pro 判断入口；支付完成后可按后台配置等级自动颁发/续期 License（默认关闭）；退款自动撤销关联 License。
- **前台账户中心**：新增「我的订单」「我的 License」Tab（订单号/金额/币种/支付方式/状态；License Key/产品/状态/站点/到期）。
- **后台**：新增「License 管理」（签发/列表/搜索/撤销/恢复/续期）与「系统状态」（环境/网关/回调地址/License 概览）两个子页面；「邮件通知」设置区（购买成功邮件、License 到期前 7 天提醒，每日 cron）。
- **Free/Pro 扩展点**：`mluc_loaded`（加载完成）、`mluc_elementor_widgets`（Elementor 组件注册过滤器）、`mluc_order_refunded`、`mluc_license_*` 系列动作钩子；`MLUC_PayPal` 新增对账查询辅助方法。

### 新增（Pro 插件 `pro/moonlight-user-center-pro/`）

- 独立 WordPress 插件（`MLUCP_` 前缀，文本域 `moonlight-user-center-pro`），强依赖 Free 版，依赖方向 Pro → Free 单向。
- **License 客户端**：后台「用户中心 → License（Pro）」激活/停用/状态展示；未激活或停用时 Pro 功能整体关闭。
- **Pro 功能首发**：Elementor「会员状态卡」组件（等级徽章/到期/升级按钮）；后台订单 CSV 导出。

### 变更

- 插件版本 1.7.1 → 2.0.0；`templates/membership-purchase.php` 与付费墙前端改为「有 redirect 即跳转」的通用网关流；付费墙 `ajax_unlock` 切换到统一网关注册表（消息文案保持原样）。

### 兼容与安全

- 保持 WordPress 5.8 / PHP 7.4 最低要求；新增代码通过 PHP 8.5 全量 lint 与 7.4 语法约束扫描。
- 保持 PayPal / Stripe 既有流程与安全机制零变更（适配器仅包装）；`complete_order` 原子完单继续作为唯一开通入口，支付宝重复通知天然幂等。
- 支付宝密钥不入 Git、不进日志、后台脱敏；详见 `SECURITY-CHECK.md`（Phase 8 逐项审计：XSS/CSRF/SQLi/IDOR/伪造通知/金额篡改/重放等）。

### 已知事项

- 支付宝仅实现电脑网站支付（手机网站/当面付留待后续）；Stripe/PayPal 退款仍指引商户后台操作。
- 远程 License Server 的服务端实现（`mluc-license/v1/*`）属后续独立项目，本版已预留客户端协议（verify 端点 + 12h 缓存 + 7 天宽限）。
- `.po/.mo` 语言文件未随新增文案重新编译；前台文案可通过后台「界面文案」即时自定义，后台文案为中文源串。
