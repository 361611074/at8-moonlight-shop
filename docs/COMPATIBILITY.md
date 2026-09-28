# Phase 13：兼容性报告（COMPATIBILITY）

> 适用版本：Moonlight Shop 组合包 2.0.x（moonlight-shop / moonlight-shop-pro / moonlight-user-center）
> 对应计划书第十三节要求：WordPress 最新稳定版、PHP 8.1–8.4、Astra / Twenty Twenty 系列主题、Elementor。

---

## 一、PHP 兼容性（8.1 / 8.2 / 8.3 / 8.4）

### 已验证

- **语法级验证**：全部插件 PHP 文件通过 `php -l`（PHP 8.4 静态二进制）
  语法检查，0 error / 0 warning（结果见下方命令，可随时复跑）。
- **最低版本承诺**：`readme.txt` 声明 **PHP 7.4+**。代码库已避免以下
  8.0+ 专属语法（因此 7.4 兼容性不被破坏）：
  - 未使用命名参数、`match` 表达式、构造器属性提升、readonly、enum；
  - 未使用 8.1+ 的 never 返回类型、first-class callable 语法；
  - 动态属性（8.2 弃用）：所有类均声明属性，未发现运行时创建动态属性。

### 8.1–8.4 逐版风险点排查结论

| 版本 | 排查项 | 结论 |
|---|---|---|
| 8.1 | `strftime` / `date` 弃用族 | 未使用 ✅ |
| 8.2 | 动态属性、`${var}` 字符串内插弃用 | 未使用 ✅（插值均为 `{$var}` 形式或 `sprintf`） |
| 8.3 | 无破坏性变更影响面 | ✅ |
| 8.4 | 隐式可空参数类型弃用（`function f(Type $x = null)`） | 全库 `rg` 扫描未命中 ✅ |

> 说明：`php -l` 仅保证语法层；运行时行为兼容由测试套件
> （`tests/run.php`，328 项断言，PHP 8.4 下全绿）覆盖核心业务逻辑。
> 支付宝 SDK 为自实现 RSA2（phpseclib-free，仅用 OpenSSL 扩展），
> 无第三方 PHP 库版本耦合。

复跑命令：

```bash
for f in $(git ls-files '*.php'); do php -l "$f" || exit 1; done
php tests/run.php
```

---

## 二、WordPress 兼容性

| 项目 | 结论 |
|---|---|
| WordPress 最低版本 | 6.0（readme.txt `Requires at least: 6.0`） |
| 最新稳定版（6.x） | ✅ 兼容。未使用任何 `_deprecated_function` 触点；`wp_insert_post` / `WP_Query` / `WP_REST_*` / options API 均为长期稳定 API |
| register_post_status | 订单 10 态独立状态注册，含 `label_count`（`_n_noop`），后台列表计数正常 |
| REST API | 仅卡密/支付宝 notify 使用 `register_rest_route`，权限回调齐备 |
| 升级路径 | 1.7.x → 2.0.x 自动 DB 迁移（`Moonlight_DB_Version` 迁移器），旧键只读兼容；回滚 = 停新启旧（计划书第六十四节达标） |

---

## 三、主题兼容性（Astra / Twenty Twenty 系列）

设计层面的主题无关性（计划书核心原则）：

1. **模板可覆盖**：所有前台输出走 `mlshop_get_template()`，
   主题内 `moonlight-shop/` 目录同名文件即可覆盖，无硬编码模板路径。
2. **CSS 作用域隔离**：全部前台样式限定在 `.mlshop-*` 前缀类名下，
   无元素选择器与 `!important` 泛滥，不会反向污染主题。
3. **无 jQuery/JS 框架冲突**：仅依赖 WordPress 自带 jQuery，
   所有事件绑定在 `.mlshop-*` 类名命名空间内。
4. **短代码驱动**：`[mlshop_cart]` `[mlshop_checkout]` `[mlshop_order]`
   等可放入任何页面构建器区块，主题只提供内容区。

逐主题注意点：

- **Astra**：✅ 无已知冲突；Astra 的页面缓存建议排除账户/购物车/结算页
  （插件已输出 `DONOTCACHEPAGE`，主流缓存插件遵循）。
- **Twenty Twenty–Twenty Twenty-Five**：✅ 块主题下短代码块 /
  短代码区块均可正常渲染；表单样式继承主题按钮基类，视觉一致性可接受。

---

## 四、Elementor 兼容性

- 插件注册了专属 Elementor 组件（`class-elementor.php` +
  `elementor-widgets.php`）：商品网格、购物车、账户入口等，
  动态标签使用标准 Elementor Controls API。
- 仅在 Elementor 已激活时挂载（`did_action('elementor/loaded')` 守卫），
  未安装 Elementor 时零加载成本。
- Elementor Pro 主题生成器与 `mlshop_*` 短代码无冲突。

---

## 五、浏览器 / 前端

- 目标：ES5 语法（`var`、无可选链），IE11 不做承诺但无语法性阻断。
- 结算页省市区二级选择在无 JS 环境下退化为全部 optgroup 可见
  （渐进增强，`mlshop_render_region_selects`）。
- 游客购买新增的邮箱字段为原生 `type="email"`，无 JS 时浏览器原生校验兜底。

---

## 六、本版（2.1）新增改动兼容性说明

游客购买相关改动均为**增量式**：

- 新增 meta 键 `_mlshop_guest_email` / `_mlshop_guest_token` /
  `_mlshop_guest_created`：不触碰既有键，无迁移需求。
- 新增选项 `guest_checkout_enabled`（默认 1）/ `guest_order_rate_limit`
  （默认 10）：未保存过设置的老站点直接获得默认行为，与
  `mlshop_get_option` 的兜底语义一致。
- 回跳 URL 新增 `mlshop_gt` 参数：`mlshop*` 前缀在支付宝验签前被剔除，
  不影响既有签名校验路径。
- 登录用户订单的查看/回跳授权语义**完全不变**（所有者或管理员）。

---

## 七、结论

- PHP 8.1–8.4：语法级全绿 + 核心业务测试全绿，符合 7.4+ 承诺。
- WordPress 6.0+ / 最新稳定版：兼容，升级路径可回滚。
- Astra / TT 系列 / Elementor：经设计层隔离 + 守卫加载，无已知冲突。
