# FREE-PRO.md — Free / Pro 功能分层与授权设计

> Phase 1 交付物 · 2026-09-27 · 对应计划书第三、四、四十八–五十一节

## 一、分层原则

- **Free = 完整可用的商城**（计划书 3.1 红线：安装即可卖货，不是广告壳）。基础商城、虚拟/卡密/实物、购物车、订单、支付宝/PayPal/Stripe/余额/积分/COD/线下全部在 Free。
- **Pro = 商业增强**，只做三类事：高级自动化、高级统计、企业级扩展。Pro 插件**零复制 Core**：只调用 `moonlight_*` API/钩子，单独前缀 `MLPRO_`。
- 依赖方向：Pro → Free 单向（沿用现有 `mluc_loaded` / 新增 `moonlight_loaded` 钩子介入模式）；Free 永不探测 Pro。

## 二、功能矩阵（Free / Pro）

| 模块 | Free | Pro |
|---|---|---|
| 商品 | 实物/虚拟/卡密、分类标签、库存、SKU | 变体（多规格 SKU）预留 |
| 会员 | 等级后台可配、等级价（gold/diamond 模式）、到期管理 | 订阅式会员（周期扣款预留）、等级折扣全局比例 |
| 优惠券 | 固定金额/百分比、有效期、次数限制 | 满减门槛、指定商品/分类、会员专属、首购券 |
| 卡密 | 批次导入、CAS 自动发卡、加密双轨、库存预警 | 批量导出、按订单补发工具、外部卡池 API |
| 数字下载 | token 下载、次数/有效期限制、路径隐藏 | 文件分块断点续传、防爬虫热链签名 URL |
| 物流 | 手工发货、快递100 查询、固定/满额包邮/按件 | 运费模板按重量/按地区、电子面单 Provider、多模板区域加价 |
| 售后 | 全额退款 + 网关联动 | 部分退款工作流、售后协商工单 |
| 统计 | 今日/本月销售额、订单数、热销、库存预警（后台基础图表） | 销售趋势图、商品/用户排行、支付渠道占比、转化漏斗、CSV/导出 |
| 集成 | Elementor 组件、短代码、REST API | **Webhook**（order.created/paid/shipped/completed/refunded、payment.success/failed、license.*）、Zapier 式出站钩子 |
| 授权 | — | Pro 功能门禁（License） |

## 三、Pro 插件结构

```text
moonlight-shop-pro/
├── moonlight-shop-pro.php        # 头部：Requires: moonlight-shop；缺 Free 时 admin notice 不启动
├── includes/
│   ├── license/class-license-client.php     # UI 客户端：is_active() 直调 Moonlight_License_Manager（沿用 MLUC 模式）
│   ├── advanced-shipping/…                  # 按重量/按地区模板、电子面单
│   ├── advanced-coupon/…                    # 规则 JSON 扩展字段与 Price_Calculator 钩子实现
│   ├── membership-plus/…                    # 订阅会员、全局等级折扣
│   ├── analytics/…                          # 趋势/排行/渠道统计（复用服务端 SVG 图表器）
│   ├── webhook/…                            # 出站 Webhook（HMAC 签名 + 重试退避 + 事件订阅 option）
│   └── api/…                                # /admin/ Pro 路由
└── assets/ languages/
```

## 四、授权机制（License）

- **引擎复用**：审计确认 `MLUC_License_Manager`（随会员中心并入 Free Core，改名 `Moonlight_License_Manager`，保留旧类名 alias）质量达标——签发（`MLPRO-XXXX-XXXX-XXXX` 格式）、站点 hash 绑定、12h 验证缓存、**7 天断网宽限**、撤销/续期/退款联动撤销。Pro 客户端零授权逻辑（修复"Pro 复制 Core"红线）。
- **License Server**（Phase 8 补齐服务端，独立小插件 `moonlight-license-server`，可装在任何 WP 站）：
  - REST：`mls-license/v1/activate | deactivate | verify | status`（server 侧签发/绑定域名/到期/状态/version）；
  - 请求带 license key + 站点 hash（host+path 归一化 md5，与客户端一致）+ 签名时间戳（防重放）；
  - Free 侧未配置 server URL 时维持本地验证模式（现有行为），配置后激活流程：输入 License → POST activate → 绑定域名 → 缓存 12h + 宽限期 7d。
- **授权与支付解耦**（计划书第五十一节）：购买 Pro 的站点自身也是一笔普通订单——`moonlight_order_paid` → License_Manager::maybe_issue_for_order（沿用现有自动颁发链，等级白名单换成产品白名单）。

## 五、Pro 门禁运行时行为（对齐计划书第五十节"不能因授权服务器故障瘫痪"）

- `is_active()`：本地有效（含宽限期内）→ 全部 Pro 功能可用；宽限超期 → Pro 功能停用但 **Free 商城与既有数据完全不受影响**（订单/商品/卡密照常）。
- Pro 模块注册采用 capability 式挂载：每模块启动前检查 `MLPRO_License_Client::is_active()`，未激活只注册 admin notice，不注册任何前台行为。

## 六、发布与版本

- 版本对齐：Free `2.0.0`（本次重构起点）、Pro `2.0.0`；两者 `Requires PHP 7.4+`（兼容现有主机），`Tested up to` 最新 WP。
- `readme.txt` / README.md 按计划书第八十一节重写（含支付/物流/卡密/会员配置说明与 Free/Pro 对照表）。
