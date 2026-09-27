=== 漫步白月光用户中心 ===

Contributors: 漫步白月光
Tags: user, login, register, account, profile, elementor, astra
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: 漫步白月光
Author URI: https://www.at8.fun/

轻量、主题无关的 WordPress 用户中心，兼容 Astra 主题与 Elementor 页面构建器。支持 Free + Pro 商业化体系与支付宝 / PayPal / Stripe 收款。

== 功能 ==
* 前端登录 / 注册 / 找回密码（短代码驱动，任意主题可用）
* 账户中心仪表盘：侧栏导航 + 概览 / 资料编辑 / 我的订单 / 我的 License / 退出
* 资料编辑：昵称、个人网站、简介、密码修改
* 前端头像上传
* Elementor 组件：登录表单、注册表单、用户资料卡（Pro 可扩展更多组件）
* 账户 Tab 通过过滤器 `mluc_account_tabs` 可扩展（如挂接商城订单）
* 统一支付抽象层：manual / PayPal / Stripe / Alipay 网关注册表，`mluc_payment_gateways_registered` 可扩展
* License 授权体系：签发 / 激活 / 停用 / 验证 / 宽限期 / 撤销 / 续期，支付成功可自动颁发

== 在线支付 ==
* 后台「用户中心 → 设置 → 在线支付」配置 PayPal（Smart Buttons，服务端 capture）与 Stripe（Checkout 托管结账）
* 支付宝（电脑网站支付）：RSA2 签名，异步 notify 四重校验（签名 / 商户 / 订单号 / 金额），回跳以服务端 alipay.trade.query 复核为准，仅支持 CNY
* PayPal：前台弹窗付款，服务端捕获并校验金额后自动开通会员
* Stripe：跳转 Stripe 托管页支付，回跳自动校验开通；可选 Webhook（checkout.session.completed）兜底，端点 /wp-json/mluc/v1/stripe-webhook
* 支付宝异步通知端点：/wp-json/mluc/v1/alipay/notify
* 货币代码后台可配（默认 USD），零小数货币（JPY/KRW 等）自动处理
* 结构化支付日志随订单记录（调试上下文可开关）
* 线下转账流程保持不变；双插件同装走商城支付时本流程不启用

== License / Pro ==
* 本插件为 Free 核心；Pro 扩展（pro/moonlight-user-center-pro/）作为独立插件安装，提供 License 激活管理、Elementor 会员状态卡、订单 CSV 导出
* 后台「用户中心 → License 管理」手工签发 / 撤销 / 续期 License
* 「设置 → License / Pro」可配置支付成功自动颁发 License 的会员等级与远程 License Server 地址（可选；远程验证缓存 12h，断网宽限 7 天，Server 故障不影响 Free）
* 订单与 License 严格分离：续费复用同一 License 叠加有效期；退款自动撤销关联 License
* 后台「用户中心 → 系统状态」可体检环境 / 网关 / 回调地址

== 配置说明 ==
【第 0 步：通用设置】
1. 后台「用户中心 → 设置」找到「在线支付（PayPal / Stripe）」区块。
2. 「货币代码」填 ISO 4217 三位代码（如 USD / EUR / HKD / JPY），两个网关共用；
   务必与会员等级价格的币种一致，否则金额校验会拒绝开通。
3. 勾选要启用的网关，保存后会员购买卡片才会出现对应支付按钮。

【第 0.5 步：支付宝（仅 CNY）】
1. 支付宝开放平台创建「电脑网站支付」应用并通过审核，获取 App ID；
2. 「接口加签方式」选 RSA2：上传应用公钥后，把「支付宝公钥」复制到后台
   （注意是平台生成的支付宝公钥，不是你的应用公钥），应用私钥填入后台
   （PKCS#1 / PKCS#8 均可，密钥仅存本站数据库，后台脱敏显示）；
3. 「环境」按开放平台应用类型选择沙盒 / 正式（正式环境涉及真实收款，请谨慎）；
4. 通知地址无需在开放平台手工配置，下单时自动携带 notify_url：
   https://你的域名/wp-json/mluc/v1/alipay/notify（服务器需可公网访问）；
5. 结算货币须为 CNY，否则前台不出现支付宝选项。

【第 1 步：PayPal 凭据获取】
A. 沙盒测试（sandbox）：
   1. 打开 https://developer.paypal.com/dashboard/ 用 PayPal 账号登录；
   2. 「Apps & Credentials」→ 右上角开关切到「Sandbox」；
   3. 「Create App」随便起个名字（如 my-site-membership）→ 创建；
   4. 复制页面上显示的「Client ID」和「Secret」；
   5. 后台填入对应输入框，「模式」选 Sandbox，勾选启用，保存。
B. 正式上线（live）：
   1. 需要 PayPal 商家账号（Business account），在 https://www.paypal.com/ 免费升级；
   2. 回到 developer dashboard，「Apps & Credentials」切到「Live」；
   3. 「Create App」创建正式应用，复制 Client ID / Secret；
   4. 后台填入新凭据，「模式」切换为 Live，保存即可收款。
C. 沙盒测试方法：
   1. developer dashboard「Testing Tools → Sandbox accounts」里有自动生成的
      「Personal（买家）」和「Business（卖家）」两个测试账号；
   2. 前台用买家账号在 PayPal 弹窗里付款，走完整流程；
   3. 付款成功会员自动开通即代表配置正确，再切 Live。

【第 2 步：Stripe 配置（可选，与 PayPal 互不影响）】
1. https://dashboard.stripe.com/register 注册（需支持 Stripe 的地区账号）；
2. 「开发者 → API 密钥」复制「可发布密钥 pk_...」和「私密密钥 sk_...」；
3. 后台填入并勾选启用。pk 以 test_ 开头为测试模式，正式收款换 pk_live/sk_live；
4. Webhook（推荐，兜底回调）：
   a. 「开发者 → Webhooks」→「添加端点」，URL 填插件设置页显示的
      https://你的域名/wp-json/mluc/v1/stripe-webhook
   b. 监听事件勾选 checkout.session.completed；
   c. 创建后复制「签名密钥 whsec_...」填入后台「Webhook 签名密钥」。

【常见问题】
* 前台看不到 PayPal 按钮：检查是否勾选「启用」、凭据是否为空、以及当前会员等级
  是否允许购买（已是更高等级会被预检拦截，这是正常保护）。
* PayPal 报「凭据校验失败」：Client ID / Secret 复制不完整，或凭据与所选模式
  （Sandbox/Live）不匹配——沙盒凭据只能配 Sandbox 模式。
* 付款成功但会员没开通：确认货币代码与等级价格币种一致；Stripe 用户确认 Webhook
  是否配置；两站/多站部署时凭据各站独立填写。
* 切换 Sandbox ↔ Live 后报 401：清一下缓存重试（token 缓存按模式独立，正常不会
  串；仍报错到后台重存一次凭据即可）。
* 退款：在 PayPal / Stripe 商户后台手动操作，插件侧可在订单编辑页改状态并备注。

== 界面文案 ==
* 后台「用户中心 → 设置 → 界面文案（账户中心）」可自定义账户中心全部前台文字（v1.4.3 起全量开放）：
  侧栏标签、概览页（时段问候/快捷操作/账户统计）、个人资料页、会员等级页、已购内容页、
  购买卡（付款提示/购买记录表头/网关提示）、登录/注册/找回密码页、保存与改密等操作提示。
* 填英文或其他语言即可，留空使用默认值；v1.4.1 起「已购教材」默认更名为「已购内容」。

== 短代码 ==

* [mluc_login]        登录表单
* [mluc_register]     注册表单
* [mluc_lostpassword] 找回密码
* [mluc_account]      账户中心

== 兼容性 ==
* 与 Astra 主题无冲突（样式作用域限定 .mluc-）
* 与 Elementor 集成，提供专用组件
* 不依赖主题全局函数，可独立运行

== 版权 ==
本插件为原创实现，采用 GPL-2.0-or-later 授权，作者：漫步白月光。
官网：https://www.at8.fun/
