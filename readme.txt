=== AT8 Moonlight Shop ===

Contributors: at8fun
Tags: shop, ecommerce, cart, checkout, digital, download, cardkey, elementor, astra
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 3.3.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: ManBu BaiYueGuang
Author URI: https://www.at8.fun/

A lightweight, theme-agnostic shop system with a built-in account center: physical, downloadable and card-key products, cart, orders, memberships, credits and daily check-in.

== Description ==

AT8 Moonlight Shop is a lightweight, theme-agnostic e-commerce system for WordPress. It does not require WooCommerce, creates all needed pages on activation, and works with both shortcodes and Elementor widgets. Chinese, Simplified Chinese and English translations are bundled.

**Products & Orders**

* Custom product post type with three kinds: physical goods, downloadable virtual goods, and card-key (license code) products
* Session cart for guests (cookie based); guests can order with just an e-mail address and retrieve their order via a 48-character access token
* Card-key products: AES-256-CBC encrypted batch stock pool, plain codes never stored in the database, automatic allocation on sale, automatic low-stock e-mail alerts
* Downloadable products: time-limited secure download links generated automatically after payment

**Payments**

* Built-in gateways: Alipay (desktop website payment, self-implemented RSA2), WeChat Pay v3 (Native QR / H5), PayPal, Stripe, wallet balance, credits, cash on delivery, QR / offline transfer
* Payment callbacks: signature verification + amount comparison + idempotency; prices are always calculated server-side — no amount fields come from the browser
* Unified refunds: refund request → rule gate → full / partial refund → stock rollback and wallet / credit reimbursement

**Credits**

* Global exchange rate configurable in the admin, applied consistently to recharges, credit payments and automatic credit pricing of products
* Daily check-in rewards (base reward + streak bonus, configurable), sharing the same credit ledger as the shop
* Concurrency-safe credit ledger (conditional SQL updates plus named locks)

**Membership & Account**

* Custom membership levels (name / price / duration / member pricing) with automatic downgrade on expiry
* Account center: overview, profile, membership, purchases, credit balance and check-in, orders, addresses, downloads, coupons
* Front-end login / register / lost-password pages created automatically; OAuth social login and avatar library supported

**Shipping**

* Shipping templates (flat / per-item, free-shipping threshold), local pickup, address book
* Shipment management, Express100 tracking aggregation (15-minute auto sync), auto completion on delivery

**Third-party services**

This plugin does not send any data to the plugin author. Outbound requests happen only in these cases:

* Payment gateways: Alipay / WeChat Pay / PayPal / Stripe receive order number, amount and product title as required to process payments (configured by the site administrator);
* Express100 tracking (optional): after configuring a key, tracking numbers are sent to Express100 to query shipment status;
* License server (optional, only for sites selling a Pro license): orders for mapped products can send order number, buyer e-mail and product / plan codes to your own license server for automatic license issuance.

The plugin does not collect or upload any other data.

== Installation ==

1. In your WordPress dashboard go to "Plugins → Add New" and install / activate AT8 Moonlight Shop;
2. On activation the shop, cart, checkout, login, register and account pages are created automatically;
3. Open "Products → Shop Settings" to configure currency, payment gateways, shipping and credits;
4. Create your first products under "Products → Add New" and start selling.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. AT8 Moonlight Shop is a standalone shop system with its own products, orders, cart and payments. A one-click tool to import existing WooCommerce products is included.

= Can guests place orders? =

Yes. "Allow guest checkout" is enabled by default: guests order with an e-mail address, and the order page plus the confirmation e-mail invite them to register afterwards. This can be turned off in the settings.

= How do users earn and spend credits? =

Users can recharge credits in the account center (packages and rates are configurable) and earn credits through the daily check-in. On checkout they can pay entirely with credits at the configured rate; refunds are reimbursed automatically.

= Which payment methods are supported? =

Alipay (CNY only), WeChat Pay v3 (CNY only), PayPal, Stripe, wallet balance, credits, cash on delivery and offline transfer. Every gateway can be extended via the `mlshop_payment_gateways` filter.

= What are the server requirements? =

WordPress 5.8+ and PHP 7.4+. Alipay and WeChat Pay need the OpenSSL PHP extension.

== Screenshots ==

1. Shop dashboard: sales overview, to-dos and card-key stock alerts
2. Product list: grid card layout with type badges and tag cloud
3. Checkout: coupon codes, shipping, multiple payment methods and credit balance hint
4. Account center: membership level, credit balance, check-in and ledger
5. Shop settings: every feature configured in one place

== Changelog ==

= 3.3.0 =
* Renamed to AT8 Moonlight Shop with a unified text domain and rebuilt language packs (zh_CN / zh_TW / zh_HK / en_US)
* Plugin Check compliance: removed extract() from template loader, replaced debug logging with admin notices, added all standard readme sections and third-party service disclosure
* Internationalization: fixed four MO-compiler bugs that made translation files silently unusable; language packs now follow the site locale

= 3.2.2 =
* WordPress.org review compatibility: removed extract() (explicit template variable expansion), removed unconditional error_log (adjustment results now shown as admin notices), added Requires fields to the plugin header
* Completed standard readme sections (Installation / FAQ / Screenshots / Upgrade Notice) and third-party service disclosure
* Packaging tool now compiles languages/*.po to .mo before zipping

= 3.2.1 =
* Fixed translations not following the site locale: four bugs in the i18n compiler (big-endian magic / translation table offset / relative offsets / concatenation order) had made .mo files unusable
* Language packs completed: 472+69 entries added per Chinese locale, full coverage for zh_TW / zh_HK / zh_CN; 61 key entries for en_US
* Settings navigation title now uses gettext

= 3.2.0 =
* Funds-safety audit: wallet reimbursement on balance-order refunds, atomic claim against duplicate crediting in concurrent payment callbacks, credit unlock spend-result validation, claim-based idempotency for refund / cancel side effects, automatic reimbursement when payment completion fails
* Page slug takeover protection, recharge rate limiting, credit ledger locking

= 3.1.2 =
* Single-plugin merge audit: license admin instantiation guard, check-in feature reachable (setting + front-end entry), uninstall now cleans up merged user-center data

= 3.1.1 =
* Fixed float conversion overcharging credits (converge before ceil); automatic credit / balance reimbursement when a gateway fails to complete an order

= 3.1.0 =
* Credits: global exchange rate, credit payment gateway, automatic credit price conversion, manual admin adjustments

= 3.0.0 =
* Merged the user center into the shop: one plugin now provides shop + account center + licensing

= 2.2.0 =
* WeChat Pay v3 gateway; moonlight/v1 REST API (26 routes); guest checkout

= 2.1.0 =
* Guest checkout (e-mail ordering + access-token retrieval); performance and compatibility audit

= 2.0.0 =
* Payment secret masking, unified price calculator, encrypted card-key batch model, shipping templates (first phase)

== Upgrade Notice ==

= 3.3.0 =
Renamed to AT8 Moonlight Shop with rebuilt language packs and WordPress.org compliance fixes. Recommended for all users.

= 3.2.2 =
WordPress.org review compatibility fixes, no functional changes. Recommended for all users.

= 3.2.1 =
Fixes the interface language not following the site locale. Recommended for multilingual sites.

= 3.2.0 =
Funds-safety audit fixes covering balance / credit refunds and concurrency. Strongly recommended.
