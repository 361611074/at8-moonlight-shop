# SHIPPING.md — 物流系统设计

> Phase 1 交付物 · 2026-09-27 · 对应计划书第二十五–三十一节

## 一、现状与差距

现状：固定运费 + 满额包邮、单一承运商名、后台手工填物流四字段、无轨迹查询（审计第九节）。
目标：`Shipping_Provider_Interface` + 运费模板 + 地区数据源抽象 + 发货单/轨迹 + cron 自动物流查询，同时保留"不接物流 API 也能完整运转"的轻量基线（个人站长场景优先）。

## 二、Shipping Provider 接口

```php
interface Moonlight_Shipping_Provider_Interface {
    public function get_code(): string;                       // sf / kuaidi100 / kdbird / manual ...
    public function get_name(): string;
    public function is_available(): bool;
    public function get_supported_companies(): array;         // [['code'=>'SF','name'=>'顺丰速运'],...]
    public function create_shipment( Moonlight_Shipment $shipment ): array;  // ['success','tracking_no','message'] 预留电子面单
    public function query_tracking( string $company_code, string $tracking_no ): array;
    // 返回标准化轨迹：['status'=>'transit|delivered|exception|pending', 'events'=>[['time'=>ts,'desc'=>string,'city'=>?string]], 'message'=>?string]
}
```

- 注册：`apply_filters('moonlight_shipping_providers', array)`；设置页选择启用 Provider + 填 Key/Secret（**密钥脱敏展示，同支付密钥规范**）。
- **首版内置**：`manual`（手工发货，零依赖，默认）+ `express100`（快递100 聚合查询，作为唯一对接示例，API 细节实现时以官方文档为准）；顺丰/中通/圆通/申通/韵达/极兔/京东/EMS 通过 Provider 或聚合平台接入，不逐一硬编码。
- 轨迹查询失败**绝不能影响订单**：失败记 shipping log + 后台标记"查询失败可重试"（对齐计划书第六十八节"物流 API 不可用时订单不能崩溃"）。

## 三、配送方式

| 方式 | 行为 |
|---|---|
| `none`（无需物流） | 虚拟/卡密商品默认；整单无实物时不产生运费与发货单 |
| `express`（快递配送） | 默认实物方式；按运费模板计费 |
| `pickup`（自提） | 结算免运费，要求填提货人+手机；预留门店地址 option |

## 四、运费模板（Phase 6 交付）

CPT `moonlight_shipping_template`（非公开）+ 规则 meta JSON：

```json
{
  "mode": "fixed|piece|weight|region",
  "free_threshold": 99,          // 满额包邮；0=关闭
  "first_item_fee": 10, "extra_item_fee": 5,
  "first_weight_fee": 8, "extra_weight_fee": 2, "unit_weight_gram": 1000,
  "regions": [ { "region_codes": ["CN-BJ","CN-SH"], "adjust": {"first_item_fee": 15} } ]
}
```

- 商品引用：`_mlshop_shipping_template`（留空 = 默认模板）；重量 `_mlshop_weight`。
- 计算**只发生在 Price_Calculator**（多商品取各模板计费求和，或按最优先模板——首版：逐商品模板计费求和 + 全局满额包邮门槛）。
- Free 内置：固定运费 + 满额包邮 + 按件；**按重量/按地区为 Pro**（FREE-PRO.md 矩阵）。

## 五、Region Provider（地区数据源抽象）

```php
interface Moonlight_Region_Provider_Interface {
    public function get_provinces(): array;                 // [['code'=>'CN-BJ','name'=>'北京'],...]
    public function get_cities( string $province_code ): array;
    public function get_districts( string $city_code ): array;
    public function resolve( string $code ): array;         // code => [province,city,district] 名称
}
```

- 首版内置 `builtin`：精简国标省市两级行政区数据文件（`includes/shipping/data/regions-cn.php`，纯数组，不写死到业务代码）；区县三级 + 导入自定义数据留作 Pro/扩展。
- 地址簿与运费模板的地区匹配均走 code（如 `CN-BJ`），禁止把"北京"字符串写进业务逻辑（对齐计划书第三十节）。

## 六、发货单与轨迹

- CPT `mlshop_shipment`（`post_parent` = 订单）：meta `_mlshop_ship_items` / `_mlshop_ship_provider` / `_mlshop_ship_company` / `_mlshop_ship_no` / `_mlshop_ship_status`（created → transit → delivered | exception）/ `_mlshop_ship_events`（JSON 轨迹增量）/ `_mlshop_ship_raw`（最近原始响应，供调试，含脱敏）。
- 状态联动（经 Order_Service）：订单 `awaiting_shipment` → 后台发货（手工填单或 Provider create_shipment）→ 订单 `shipped` + 通知；轨迹到 delivered（cron 或用户查询时惰性刷新）→ 订单 `delivered` → 用户确认或 7 天自动 → `completed`。
- Cron：`moonlight_shipping_sync`（15 分钟间隔，仅处理 transit 状态且启用自动查询的发货单，批量 ≤50/次，失败退避）；设置项：自动查询开关/频率/超时关单（对齐计划书第二十七、六十节）。
- 用户侧：订单详情展示轨迹时间线；无 Provider 时展示手工物流字段（现状保留）。

## 七、地址簿

- usermeta `moonlight_addresses`：数组（id/name/phone/region codes/detail/is_default）；结算页选择或新增（nonce + AJAX，属主校验）；订单快照沿用 `_mlshop_shipping_address`。
- **服务端强制**：整单含实物 → 结算与下单接口校验地址完整性（修复审计 M3），自提除外。

## 八、测试矩阵（Phase 6 交付时验证）

已发货/运输中/派送中/已签收/异常五态模拟；API 断网下单与发货不崩溃；运费模板组合（满额包邮边界/按件/按重量/地区加价）；混合订单（实物+虚拟+卡密）拆分流转；地址簿越权读写（IDOR）；自提免运费。
