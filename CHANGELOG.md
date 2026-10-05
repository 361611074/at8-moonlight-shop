## [3.2.1] - 2026-10-04（多语言跟随系统修复）

**根因**：用户报告「系统是繁体，设置页仍是简体中文」。排查发现 i18n 工具链与语言包各有一层问题，叠加导致所有翻译死码：

### 修复
- **tools/i18n.php 编译器四个 bug（.mo 从未真正可用）**：
  1. magic 用 pack('N') 大端写出（95 04 12 de），PHP MO 解析器只认小端 → 解析 0 条目；
  2. header 译文表偏移错写为原文数据偏移；
  3. 表内 off 写成块内相对偏移（GNU MO 要求绝对文件偏移）；
  4. 拼接顺序（表→数据）与偏移假设矛盾。四项全修后 .mo 终于可读。
- **语言包补齐**：全量 makepot → update-po，zh_TW / zh_HK / zh_CN 各补 472+69 条空翻译
  （简繁字符映射 + 两岸用词短语，对齐 ca3f837e 的 OpenCC 先例），中文三语言 100% 覆盖；
  en_US 补管理界面与积分/签到关键面 61 条。
- **设置页导航标题（页面导航）走 gettext**：此前是 JS 兜底字符串，makepot 提取不到；
  现 wp_localize_script 下发译文，JS 硬编码仅作无 localize 时的兜底。

### 测试
- run.php 829 → 834：新增 8 语言包 .po + 小端 .mo 齐备断言、关键词条已译断言
  （常规设置 / 积分与签到 / 会员等级定义 / 页面导航 / 允许订单使用积分支付 / 每日签到）；
  run-user 198、run-credit 45 全绿。

## [3.2.0] - 2026-10-04（双审查员独立审计修复：资金并发与安全）

换独立视角做对抗性复审（资金/并发审计 + 安全/输入审计各一名，互不沟通），共报告
2 Critical / 3 High / 7 Medium / 5 Low，全部逐条核实后修复 14 项：

### Critical（资金安全）
- **余额订单退款/取消钱包永不回补（C1）**：状态机回补以 `_mlshop_payment_id` 为凭据，
  而余额网关 `mark_paid` 从不传交易号 → 凭据恒空 → 退款时用户静默损失全部货款。
  现网关扣款成功写入 `_mlshop_balance_spent` 凭据，状态机按凭据回补（旧数据按
  payment_id 兼容）。
- **并发支付回调双触发 → 充值重复入账（C2）**：微信轮询 × 异步 notify、Stripe 回跳 ×
  webhook 重投等并发场景下，`grant_recharge / grant_membership / grant_paywall_order`
  的「读标记 → 动作 → 写标记」是 check-then-act，会双份入账 / 双开会员。
  三个授予函数全部改为 `add_post_meta(unique)` 原子抢占授予权，抢到才动作。

### High
- **积分解锁 `spend()` 返回值被丢弃（H1）**：预检与原子扣减之间的并发窗口里实扣
  失败仍发货（零扣分白拿内容）。现检查返回值，失败即拒绝。
- **状态机副作用整体并发不安全（H2）**：`maybe_reverse_funds` 落旗标在回补之后、
  `restore_stock` 完全无幂等标记 → 并发退款/取消双回补 + 双回库存。两者均改为
  `add_post_meta(unique)` 抢占式幂等。
- **网关完单失败补偿与并发取消竞态 → 双倍退款（H3）**：积分/余额网关的补偿与
  状态机回补抢同一抢占旗标（谁先抢到谁补），杜绝用户净赚一份货款。

### Medium
- **积分流水并发丢行（M1）**：ledger 读改写以每用户 `GET_LOCK` 串行化（锁不可用
  优雅降级）。
- **failed → pending 重开不重新持货（M2）**：新增 `rehold_stock()` 原子重新扣减
  （不足则整单拒绝重开并回滚已扣项），杜绝后续退款时库存凭空多出。
- **paid → cancelled 资金回退但库存不回滚（M3）**：与 refunded 对齐，paid 纳入
  cancelled 的回滚来源。
- **扣减凭据写入时序（M4）**：`_mlshop_credit_spent` 改为先落凭据再动钱
  （崩溃窗口不再产生无凭据死账），spend 失败即清除。
- **充值下单无限流（M5）**：每用户 10 分钟 10 单门槛（过滤器可调）。
- **页面 slug 抢占（安全 M-1）**：page-sync / 收藏页懒创建按 slug 找回前，校验页面
  内容确属本插件短代码——防编辑角色预占 slug 把 /account/ /login/ 入口指向钓鱼页。

### Low / 功能缺陷
- 签到 `GET_LOCK` 注册 shutdown 兜底释放（持久连接场景不滞留）；
- 管理员扣减积分失败（余额不足）现以后台通知明示，不再静默丢弃；
- 授权桥接 metabox 能力检查收紧为对象级 `edit_post`；
- 授权中心管理密钥不再回显进表单（留空 = 保持原值，此前每次保存设置页都可能清空密钥）；
- 授权中心两项 option 改注册进 `mlshop_settings_group`（旧组无表单承载，提交被
  options.php 静默丢弃、配置存不上）；
- 结算页网关说明改用 `get_label_desc()`（后台自定义的「前台顯示說明」此前不生效）。

### 已核实无需修改
积分账本余额原子性 / 购物车服务端取价 / 部分退款 CAS 累计 / Stripe·微信金额复核 /
gateway_labels 输出转义 / 页眉 CSS 变量双重清洗 / 卡密生成随机性 / 下载流 / CSV 导出
/ REST 权限 —— 审计确认无洞。

### 测试
- run.php 820 / run-user.php 198 / run-credit.php 45 全绿；
- 回补幂等断言更新为抢占式语义（Stripe 站内无回补 → 不落内部旗标）。

## [3.1.2] - 2026-10-03（单插件并入态审查修复）

对「用户中心完全并入商城」后的单插件形态做全面审查（含 run.php 820 项与 run-credit.php 45 项全绿基线），发现并修复三处并入遗漏：

### Free（moonlight-shop）

### 修复
- **客户分发包 Fatal 风险**：`mlshop_boot_user_modules()` 无条件 `MLUC_License_Admin::get_instance()`，
  客户分发包已剔除 `includes/user/class-license-admin.php`（d7a96559 的双保险设计），
  类不存在时整站 Fatal；且无条件实例化本身违背「仅授权方自用站实例化」契约。
  现以 `MLUC_LICENSE_LOCAL_MODE` 常量 + `class_exists` 双重守卫后才实例化。
- **每日签到并入后不可达**：`MLUC_Checkin` 随并入进入商城，但
  `checkin_enabled` / `credit_enabled`（mluc_options）与签到奖励参数没有任何后台设置入口，
  账户中心积分 Tab 也没有签到按钮——功能被整体锁死。现补齐：
  - 「用户中心设置 → 积分与签到」区块（启用积分体系 / 启用签到 / 基础奖励 / 连续加成间隔与数额）；
  - 账户中心「积分余额」Tab 顶部签到卡（连续天数 / 今日状态 / 一键签到，AJAX 入账商城积分账本并即时刷新余额）。
- **卸载不清理并入的会员中心数据**：独立用户中心插件的 uninstall.php 随目录移除后，
  商城卸载残留 mluc_options、`mluc_%` 用户数据（会员等级 / OAuth / 签到 / 积分钱包账本）、
  mluc_order / mluc_license 文章与 transient。现由商城 uninstall.php 接管清理；
  独立版用户中心插件仍在激活时（双插件共存站）全部跳过，不越权清理。

### 测试
- run.php 814 → 820 项：新增「并入态功能可达性」断言（签到设置与模板入口、
  boot 双重守卫、uninstall 接管与共存保护）；run-user.php 198 项、run-credit.php 45 项全绿。

## [3.1.1] - 2026-10-03（模拟用户验收修复）

### Free（moonlight-shop）

### 修复
- **积分换算浮点误差多扣积分**：`mlshop_currency_to_credit()` 直接 `ceil(金额 × 比例)`，
  整数结果可被浮点误差顶高 1（如 1.10 × 100 → 110.000…01 → 111，用户被多扣 1 积分）；
  现先按 6 位小数收敛再向上取整。同步修复会员中心 `MLUC_Gateway_Credit::order_cost()` 同款问题。
- **积分 / 余额网关完单失败不吞钱**：`process_payment` 扣减成功后若 `mark_paid` 失败
  （如订单恰被自动过期取消），此前积分/余额直接丢失；现自动原账回补并清理扣减留痕，
  与会员中心网关「扣了钱必开通、没开通必退钱」语义对齐。
- **双插件同装致命（商城先加载顺序）**：商城与会员中心同装且商城先加载时，
  商城 functions.php 合并区块先定义共用 `mluc_*` 函数，会员中心 functions.php
  无守卫重声明 → `Cannot redeclare` 全站 500；会员中心侧（2.1.1）补 `function_exists`
  守卫后，任何激活顺序均安全。

### 测试
- run.php 801 → 812 项：浮点换算回归（1.10×100=110 / 0.07×100=7 / 0.56×12.5=7）、
  积分与余额网关完单失败回补、双插件同装商城先加载序共存（含守卫源级断言）；
  run-credit.php 24 → 45 项（会员中心模拟用户全流程）。三套全绿。

## [3.1.0] - 2026-10-03（积分体系完善版）

### Free（moonlight-shop）

**积分兑换比例全局化（后台「商城設定 → 付费/会员/积分」）**
- `credit_rate` 语义升级为**全局兑换比例**（多少积分 = 1 货币单位），不再只作用于自定义充值：
  自定义充值金额、积分支付订单换算、商品/付费内容积分价自动换算三处统一走该比例；
- 新增开关 `credit_pay_enabled`（允许订单使用积分支付）与 `credit_auto_price`（积分价自动换算）。

**积分支付网关（第 8 网关，id = `credit`）**
- 订单可全额用积分支付：所需积分 = 订单金额 × 比例（向上取整，商家不吃亏）；
- 安全语义与余额网关一致：终态幂等（重复回调不二次扣分）+ 原子扣减（并发不扣成负数）；
- 实际扣减量落订单 meta `_mlshop_credit_spent`，退款 / 已付款后取消由状态机
  （`maybe_reverse_funds`）按该值原路返还积分，与充值回收两条账目互不串扰；
- 充值订单禁用积分支付（防「用积分买积分」套利），游客不可用；
- 结算页显示当前积分余额与兑换比例提示。

**积分价自动换算（商品 / 付费内容）**
- 未手填积分价（`credit_price` / `credit_price_gold` / `credit_price_diamond`）时，
  按「货币档价 × 兑换比例」自动换算（会员档位价同样联动）；手填值始终优先；
- 修改比例后全站自动积分价即时生效，无需逐个商品调整。

**积分价 / 换算助手函数（含过滤器）**
- `mlshop_currency_to_credit()` / `mlshop_credit_to_currency()`：
  双向换算 + `mlshop_currency_to_credit` / `mlshop_credit_to_currency` 过滤器；
- `mlshop_credit_pay_enabled()` / `mlshop_credit_auto_price_enabled()` 开关读取。

**管理员手动调整积分（参考成熟主题交互自行实现）**
- 用户资料页新增「积分管理」区块（仅 `manage_options` 可见）：方向（增加/扣减）+ 正数金额 +
  必填备注，流水留痕（含操作者登录名）；扣减不足时拒绝，绝不产生负余额。

**修复**
- 充值订单退款回收积分读错 meta 键（`_mlshop_credit` → `_mlshop_credit_amount`）：
  修复后充值退款才能真正收回已入账积分（此前静默失效）；
- `default_gateway` 补注册 sanitize（此前表单值未清洗直接落库）；
- `gateway_label_zh` 补 `credit` / `wechat` 中文标签。

**清理与测试**
- `uninstall.php` 补清理积分相关 option 与用户积分账本 usermeta（`mlshop_credit_balance`
  / `mlshop_credit_ledger` / `_mlshop_balance`）；
- 测试：+44 项断言（换算助手 / 自动积分价 / 积分网关全流程 / 退款回补 / 充值回收修复回归），
  全量 801 项全绿；tests/run-user.php 补 `add_shortcode` 等缺失桩（存量 harness 缺陷）。

### 兼容性
- WordPress 6.0+ / PHP 7.4+；无 DB 结构变更，升级即用。已保存过「前台公開支付方式」的站点
  需在设置中手动勾选「积分支付」才会对前台公开。

## [3.0.1] - 2026-09-30（线上事故修复）

### 修复（严重 / 线上事故）
- **MLUC_* 常量与自动加载器不再在商城主文件的文件作用域定义**：插件加载顺序由
  active_plugins 数组决定，商城若先于旧用户中心加载，文件作用域抢注的
  MLUC_PLUGIN_DIR 等常量（指向商城目录）会使旧插件随后的 define 全部失败，
  旧插件自动加载器随之指向错误目录 → `Class "MLUC_Payments" not found` 全站 500。
  现改为 `plugins_loaded`（优先级 5）内判定 `MLUC_LEGACY_ACTIVE` 后再定义/注册——
  该标记在所有插件文件加载完毕后必然可靠，任何加载顺序均安全。
- 用户中心 2.0.2：MLUC_* 常量改为守卫式定义（双保险，任何顺序零警告）。

### 事故记录
- 2026-09-29 夜间在测试站（wordpress.xmm.fan）升级 v3.0 时，激活顺序变化触发
  上述缺陷，站点全站 500；经 SSH 定位（wp-cli 复现 + 隔离探针 + debug.log 比对）
  后修复，站点恢复并于命令行完成 v3.0 全量部署与残留目录清理。

## [3.0.0] - 2026-09-30（会员中心并入版）

「用户中心」正式并入 Moonlight Shop：**单插件提供 商城 + 会员中心 + License 全部能力**。旧 `moonlight-user-center` 转为退役态（激活时显示退役提示，数据全保留，停用后商城自动接管）。

### 会员中心并入（六阶段，docs/MERGE-USER-CENTER.md）
- **Phase A**：19 个用户模块并入 `includes/user/`（Auth/Account/Membership/License 引擎/OAuth/Settings/Material/Video/Hidecontent 等），`MLUC_` 类名保留；共存守卫（旧插件 define `MLUC_LEGACY_ACTIVE` 时新侧整体让位，双插件同启行为不变）
- **Phase B**：账户/登录/注册/找回 4 页由商城激活器创建；`mluc_options` 直读（零迁移）；后台「用户中心」归组为商城菜单下「会员与账户」；账户 Tab 按 key 去重
- **Phase C**：付费墙双实现合并——`Pay_Access` 兼容读取旧 `_mluc_pw_*` 配置（同名直映）与 `mluc_pay_unlocks` 旧解锁账本（任一未过期即解锁，零迁移兼容）；hidecontent 探测单点化；License 自动颁发链切至 `mlshop_order_paid`（product 语义沿用 `moonlight-user-center-pro`，存量授权不失效）
- **Phase D**：moonlight-shop-pro 收编 MLUCP Elementor 会员卡；License **双产品语义**（`moonlight-shop-pro` ∨ `moonlight-user-center-pro` 任一激活即 Pro）；旧 Pro 共存保护；at8-license-server REST 侦察文档
- **Phase E**：旧插件退役提示（可关闭）+ 后台「迁移状态」页（旧数据核对 + M3 授权迁移 UI 出口）+ 修复独立运行时迁移的两大盲区（未注册 CPT 状态数组直查、`post_type_exists` 门控放宽）

### 修复
- M3 迁移在仅装商城时静默迁移 0 条的盲区（显式状态数组 + 门控放宽）

### 测试
- 755 + 187 项断言全绿（并入五阶段累计新增约 250 项）

### 兼容性
- WordPress 6.0+ / PHP 7.4+；旧插件可继续共存（行为不变），建议核对迁移状态页后停用

## [2.2.0] - 2026-09-29

### Free（moonlight-shop）
- 微信支付 v3 网关（第 6 网关）：Native 扫码 / H5 / auto 场景；WECHATPAY2-SHA256-RSA2048 请求签名；AES-256-GCM resource 解密；验签双模式（微信支付公钥优先 / 平台证书自动下载）；异步通知四重校验（验签→appid/mchid→订单反查→金额分严格比对）；/v3/refund 退款与过期关单；JSAPI 预留
- moonlight/v1 REST API 第一批（26 条路由）：商品/购物车/结算/订单/下载/卡密/地址/账户；全部显式 permission_callback + 属主校验 + 服务端取价；卡密列表只出掩码，reveal 需 confirm + 属主 + 限流
- i18n：tools/i18n.php 工具链（makepot / update-po / po→mo），POT 1065 串全量提取，369 条新增源串补入 4 语言 PO，MO 全部重编译（无 gettext 依赖）
- docs/PAYMENT-TESTING.md：支付宝/微信/Stripe/PayPal 沙箱与上线走单清单
- 测试：507 项断言（+181）；修复 GCM 篡改测试的偶发失败（篡改字节改为确定性 XOR）

### 兼容性
- WordPress 6.0+ / PHP 7.4+（OpenSSL 必需）；旧 AJAX 与网关全部保留，REST 为新增通道

# CHANGELOG.md

本文件记录 Moonlight Shop 组合包的面向开发者的变更。用户可读的版本说明见各插件 `readme.txt`。

## [2.1.0] — 2026-09-28（游客购买版）

### Free（moonlight-shop）

**游客购买（未注册用户可下单）**
- 结算短代码与下单 AJAX 支持 `wp_ajax_nopriv_mlshop_place_order`：游客填写有效邮箱即可下单（可在设置关闭）
- 游客订单新增 meta：`_mlshop_guest_email` / `_mlshop_guest_token`（48 位随机令牌）/ `_mlshop_guest_created`
- 订单详情查看授权改造：所有者 / 管理员 / 游客令牌持有者（`hash_equals` 时序安全比较），防订单 ID 枚举
- 下载链接：游客订单（user_id=0）凭下载令牌领取（令牌即凭据），登录用户订单维持登录校验
- Stripe / PayPal / 支付宝回跳 URL 附带 `mlshop_gt` 访客令牌，回跳授权三分支校验（支付宝验签前剔除 mlshop* 前缀参数，不影响 RSA2 验签）
- 余额支付与优惠码对游客禁用（服务端强制，不信任前端）
- 防滥用：同 IP 每小时下单限流（默认 10，选项 `guest_order_rate_limit`，0 不限；`mlshop_guest_order_rate_limit` 过滤器可覆盖）
- 订单确认邮件：游客订单发往下单邮箱；登录用户订单邮件行为不变

**付款完成后推荐注册**
- 订单详情页（已付款状态）：游客订单显示「推荐注册」引导卡（立即注册 / 已有账号登录），注册链接预填下单邮箱
- 订单确认邮件：游客订单内嵌注册推荐板块（注册 + 查看订单链接）
- 注册链接优先使用会员中心 `mluc_get_register_url()`，未启用会员中心时回退 `wp_registration_url()`

**后台**
- 商城设置新增「允許訪客購買」「訪客下單限流」
- 订单编辑页客户栏显示「访客订单」标签与联系邮箱

### 测试与文档
- `tests/run.php` 新增游客购买用例组：游客订单判定、令牌校验（有效/错误/空/跨订单）、订单 URL 令牌注入、注册链接回退与邮箱预填、游客邮件收件人与注册板块、登录用户订单不含注册板块
- 新增 `tests/perf-benchmark.php`（Phase 12 可复现基准脚本）
- 新增 `docs/PERFORMANCE_AUDIT.md`（Phase 12 性能审计报告）与 `docs/COMPATIBILITY.md`（Phase 13 兼容性报告，PHP 8.1–8.4）

## [2.0.0] — 2026-09-27（架构重构版）

本版本是 Phase 0–10 架构级重构的产物：审计 → 设计 → Core → 支付 → 数字 → 卡密 → 物流 → 售后 → Pro → 后台/前端收尾。全部变更经独立测试套件验证（313+ 项断言）。

### Free（moonlight-shop）

**安全修复（源自全量代码审计）**
- 修复高危：库存扣减失败仍继续建单（超卖 fail-open）→ fail-closed：拒绝下单 + 补偿回滚（H2）
- 修复高危：余额退款回补写入积分账本（双账本串账）→ 回补到原钱包且原子累加（H1）
- 修复：优惠券预留后置失败不释放（名额泄漏）（M1）；库存回滚读改写非原子（M2）
- 修复：实物订单收货地址仅前端必填 → 服务端强制 + 区码可解析校验（M3）
- 修复：支付密钥回显进设置页 HTML → 脱敏（尾 4 位）+ 空提交不改值（M4）
- 修复：Stripe webhook secret 未配置时跳过验签 → 拒绝处理（M6，含用户中心）
- 修复：PayPal 回跳信任 URL token → 必须匹配下单保存单号（L2）
- 修复：购物车/结算可带入未发布商品（L4 部分）

**架构**
- 新增核心层 `includes/core/`（`Moonlight_*` 命名空间）：统一价格计算器、option 访问器、DB_VERSION 升级机制、迁移器
- 统一价格计算器：消除购物车/付费墙/订单三处分散取价；`moonlight_product_price` / `moonlight_tier_price` 过滤器
- 订单状态机扩展：`awaiting_shipment` / `shipped` / `delivered`；状态标签单一来源重构（消除三处重复）
- 订单新增服务端订单号 `_mlshop_order_no` 与客户 ID `_mlshop_customer`（存量自动回填）

**支付**
- 新增支付宝网关（RSA2 自实现：验签 + app_id + 订单号反查 + 金额 ±0.01 四重校验；首个站内退款能力）
- Stripe webhook 增加金额最小单位复核；Stripe / PayPal 退款 API（部分/全额）
- 币种门控：支付宝仅 CNY 订单可用

**卡密系统**
- 批次模型（CPT `mlshop_card_batch`）+ AES-256-CBC 加密双轨存储（哈希去重，明文不落库）
- CAS 原子发放（一张卡绝不双发）+ 低库存预警（钩子 + 站长邮件，6 小时防轰炸）
- 后台「卡密库存」：批次管理 / 批量导入（≤5000）/ 掩码列表 / 单条授权查看 + 审计日志（环形 100）
- 旧明文池自动迁移（DB_VERSION 2.1.0，幂等断点续跑）

**物流**
- 收货地址簿（usermeta，区码校验，账户中心 Tab + `[mlshop_address]`）
- 运费模板（fixed/piece，商品级挂载，无模板回退全局逻辑）+ 自提
- 发货单（CPT `mlshop_shipment`）+ 轨迹时间线 + 确认收货 + N 天自动完成
- `Shipping_Provider_Interface` + 手工/快递100 内置 Provider；cron 15 分钟自动轨迹同步（失败不影响订单）

**数字商品**
- 下载次数上限（0=不限）+ 下载日志（环形 50）+ `moonlight_download_sendfile` 扩展点（X-Sendfile/X-Accel-Redirect）

**售后**
- Refund Service：用户申请 + 管理员审批 + 全额/部分退款 + 规则闸（虚拟已下载/卡密已交付/实物已发货/会员超 24h 拒绝，过滤器可覆盖）

**其他**
- 缓存兼容：账户/购物车/结算页 `DONOTCACHEPAGE`；测试域名硬编码移除（环境类型判断）
- 卸载清理补齐（三插件 uninstall 清单核对）

### Pro（moonlight-shop-pro）— 新插件
- License 门禁（会员中心授权引擎优先，未启用时本地模式）
- 出站 Webhook：HMAC-SHA256 签名（`t,v1`）、事件订阅、5 分钟 cron 重试退避（5/15/60 分钟，5 次丢弃）、环形投递日志
- Pro 统计：7/30/90 天销售趋势、商品 Top10、支付渠道占比（分页聚合 + 1 小时缓存）
- 订单 CSV 导出（公式注入防护、日期白名单、流式输出）

### 兼容性
- WordPress 6.0+ / PHP 7.4+（OpenSSL 必需）
- 升级自 1.7.x：自动 DB 迁移（卡密加密、订单回填、配置归组），旧键只读兼容，回滚 = 停新启旧

## [1.7.4] — 2026-09-14（历史，摘要）

- 电子商城：实物/虚拟/卡密商品、Cookie 购物车、订单状态机（7 态）、COD/余额/Manual/Stripe/PayPal 网关、优惠券、积分、付费内容引擎、订单统计、群发邮件、Elementor 组件。详见各插件历史 readme。

## [2.0.0] — moonlight-user-center（历史摘要）

- 前端登录/注册/找回密码（节流/蜜罐/防枚举）、账户中心 6 Tab、会员等级后台可配、PayPal/Stripe/支付宝/线下四网关（Payment_Manager）、License 管理（断网宽限）、付费墙、OAuth 五家（含 Apple JWT）、Pro 子插件（License 客户端/Elementor 会员卡/订单导出）。
