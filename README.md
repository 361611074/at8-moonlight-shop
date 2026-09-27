# 漫步白月光 · 会员中心 + 电子商城（插件组合包）

本仓库为「会员中心」与「电子商城」两个 WordPress 插件的**组合包**，配套使用，二者均为轻量、主题无关实现，兼容 **Astra 主题** 与 **Elementor 页面构建器**，作者均为「漫步白月光」（官网 https://www.at8.fun/），采用 GPL-2.0-or-later 授权。

## 目录结构

```
moonlight-user-center/   会员中心插件（前端登录/注册/找回密码/账户中心 + PayPal/Stripe 会员支付）
moonlight-shop/          电子商城插件（实物/虚拟/卡密商品 + 购物车/结算/订单 + 可扩展支付网关）
```

## moonlight-user-center（会员中心）

轻量、主题无关的用户中心，提供前端登录、注册、找回密码、账户仪表盘、资料编辑、头像上传，并内置 PayPal / Stripe 会员支付。

- 短代码：`[mluc_login]` `[mluc_register]` `[mluc_lostpassword]` `[mluc_account]`
- 与 Astra 无冲突（样式作用域限定 `.mluc-`），与 Elementor 集成提供专用组件。

## moonlight-shop（电子商城 v1.7.4）

轻量、主题无关的电子商城系统，兼容 Astra 与 Elementor，支持：

- **商品类型**：实物、虚拟下载、卡密商品；
- **交易流程**：购物车、结算、订单管理；
- **支付网关**：可扩展支付网关，与「漫步白月光用户中心」无缝集成（会员/账户体系共用）。

## 安装

将 `moonlight-user-center/` 与 `moonlight-shop/` 两个目录分别上传至 `wp-content/plugins/`，在 WordPress 后台「插件」中启用即可。建议先启用会员中心，再启用电子商城以获得账号体系联动。

## 兼容性

- 与 Astra 主题无冲突；
- 与 Elementor 集成，提供专用组件；
- 不依赖主题全局函数，可独立运行。

## 版权

本组合包内插件均为原创实现，采用 GPL-2.0-or-later 授权，作者：漫步白月光。官网：https://www.at8.fun/
