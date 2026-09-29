# AT8-LICENSE-SERVER-RECON.md — at8-license/v1 服务端侦察（只读）

> 2026-09-29 · MERGE-USER-CENTER.md Phase D 侦察任务产出。
> 目标站：`https://wordpress.xmm.fan`（已确认装有 At8 License Server 插件，
> REST 前缀 `at8-license/v1`）。
> 用途：未来把 MLUC_License_Manager 的远程协议适配到该服务端时的依据。
> **本阶段不实现适配器**（MLUC 引擎现用协议为自研 `mluc-license/v1/verify`，
> 服务端尚无该前缀路由）。

## 数据来源

- 活站 `GET https://wordpress.xmm.fan/wp-json/at8-license/v1`（2026-09-29 实测 HTTP 200）；
- 本仓库根目录快照 `wpjson.json`（整站 REST index，与活站一致）。

站点 REST 命名空间全量：`oembed/1.0`、`at8sa/v1`、`at8-license/v1`、`at8/v1`、`wp/v2`、
`wp-site-health/v1`、`wp-block-editor/v1`、`wp-abilities/v1`。
（`at8sa/v1`、`at8/v1` 属同厂其他插件，本次未展开。）

## 路由清单（5 条）

| 路由 | 方法 | 必填参数 | 说明 |
| --- | --- | --- | --- |
| `/at8-license/v1` | GET | — | 命名空间索引 |
| `/at8-license/v1/activate` | POST | `license`, `product` | 激活（站点绑定） |
| `/at8-license/v1/validate` | POST | `license`, `product` | 校验（周期验证） |
| `/at8-license/v1/deactivate` | POST | `product`（license 可选） | 停用 / 解绑 |
| `/at8-license/v1/update` | POST | `license`, `product` | 版本更新检查（插件更新源） |
| `/at8-license/v1/package` | GET | `token` | 带签名下载令牌取安装包 |

## 公共参数 schema（activate / validate / deactivate / update 共用）

| 参数 | 类型 | 必填 | 描述（照录服务端 schema） |
| --- | --- | --- | --- |
| `license` | string | activate/validate/update 必填；deactivate 可选 | 授权密钥 |
| `domain` | string | 否 | 发起请求站点的主机名，也可以用 site_url 代替 |
| `site_url` | string | 否 | 发起请求站点的完整 URL |
| `home_url` | string | 否 | 首页 URL，当它与站点 URL 不同时填写 |
| `product` | string | **是** | 密钥对应的产品标识 |
| `wp_version` | string | 否 | 发起请求站点的 WordPress 版本 |
| `php_version` | string | 否 | 发起请求站点的 PHP 版本 |
| `wp` | string | 否 | wp_version 的旧写法 |
| `php` | string | 否 | php_version 的旧写法 |
| `environment` | string | 否 | production、staging 或 development |

`/at8-license/v1/package` 参数：`token`（string，必填）——「带签名的下载令牌」，
推测由 update / activate 响应下发（响应体未侦察，见下）。

## 与 MLUC 引擎协议的对照（未来适配要点）

| 维度 | MLUC 引擎现状（客户端侧） | at8-license/v1 |
| --- | --- | --- |
| 验证端点 | `POST {server}/wp-json/mluc-license/v1/verify` | `POST /wp-json/at8-license/v1/validate` |
| 请求体 | `{license_key, product, site_url}`（JSON） | `license` / `product` / `site_url`（+可选 domain/home_url/environment） |
| 参数名 | `license_key` | `license` |
| 缓存 | transient 12h（`mluc_lic_verify_<md5(key|product)>`） | 服务端未知 |
| 宽限期 | 7 天（失败沿用最后有效结果） | 服务端语义未知 |
| 站点标识 | `site_url`（引擎本地另存站点 hash 绑定） | `domain` / `site_url` / `home_url` 三选 |

## 已知信息缺口（需源码或文档）

REST index 只暴露参数 schema，以下内容**信息不足**，适配前需服务端源码或文档：

1. 响应体结构：validate / activate 成功与失败的 JSON 形状（字段名、`valid` 布尔还是
   `code` 错误码）、HTTP 状态码约定；
2. 授权状态语义：到期 / 暂停 / 撤销在 validate 响应中如何表达；
3. 站点绑定规则：`domain` 归一化（www / 协议 / 路径）与换绑（deactivate 再 activate）限制、
   激活数上限的表现形式；
4. `environment` 对验证结果的影响（staging 是否宽松）；
5. 鉴权方式：请求是否需要签名 / token（schema 未列出鉴权参数，可能纯 license+product 即可）；
6. `/package` 的 `token` 签发流程（推测在 update 响应中）。

## 适配器落点备忘（未实现）

- 引擎侧唯一发起点：`MLUC_License_Manager::remote_verify_ok()`
  （`moonlight-shop/includes/user/class-license-manager.php`，写死
  `mluc-license/v1/verify`）。未来适配 = 在该处按 `license_server_url` 的
  前缀/选项分流到 `at8-license/v1/validate`，把 `license_key` 改名 `license`，
  并按服务端响应形状解析；缓存与宽限逻辑可完全复用引擎现有实现。
