# WORDPRESS-ORG-FINAL-AUDIT

**Project:** at8-moonlight-shop v3.3.0
**Audit date:** 2026-10-07 (V-FINAL re-audit per 电子商城v2.md)
**Git commit:** 438d8207933e0aae2cb8733b683661567f3e35bf (master)
**Environment:** clean WordPress 7.1.3 install (Docker MariaDB 10.11, only this plugin active) + live test site wordpress.xmm.fan (PHP 8.3.33)

---

## 0. V-FINAL 新增验证（本轮执行）

| Spec item | Result |
|---|---|
| #85 Free standalone in a clean WP (no Pro / no User Center / no license server) | PASS — fresh WP + only at8-moonlight-shop; activation, products (all 3 types), cart → create_from_cart order, mark_paid flow, card-key delivery, downloads, admin pages all OK; front/store/cart/checkout/account/login/wp-admin all HTTP 200 |
| #17 readme `== Features ==` section | PASS — added (full feature list) |
| #20/#21 External Services completeness | PASS — added OAuth social-login providers (Google / GitHub / QQ / Apple / WeChat Open Platform) alongside Alipay, WeChat Pay, PayPal, Stripe, Express100, self-hosted license server |
| #61/#62 outbound requests: HTTPS + timeout + error handling | PASS — 20/20 `wp_remote_*` calls have explicit timeouts, all endpoints HTTPS, all responses checked with `is_wp_error()` |
| #78 IDOR *executed* (not just reviewed) | PASS — User B→A order read/cancel denied (403/404), User A→own OK; guest token A + order B denied; logged-in user → guest order denied; download token user-binding verified |
| #91 card-key concurrency *executed* | PASS — pool reduced to exactly 1 card, two independent `wp eval-file` processes raced: winner=1 (one GOT, one EMPTY), avail_after=0, no double-sell |
| #71 WP_DEBUG | PASS — clean web-path crawl (front/store/cart/checkout/account/login/wp-admin + REST) with WP_DEBUG_LOG: **0 warnings/notices/deprecations from this plugin**; the only log entries were test-harness artifacts (CLI echo before Set-Cookie) |
| #69/#70/#71 ZIP install / update / uninstall | PASS — clean install OK; update install retains orders/products/settings (verified twice); uninstall removes plugin tables/options but **keeps orders & products** (spec #81), reinstall + reactivate OK |
| #99 guest-token REST semantics (found & fixed bug) | see §4 — HTTP e2e: valid token **200** with order JSON, wrong token **403**, no token **401** |

## 1. 项目概况

Lightweight, theme-agnostic shop system (physical / downloadable / card-key products), cart, orders, memberships, credits + daily check-in, guest checkout, REST API (moonlight/v1), payment gateways (Alipay, WeChat Pay v3, PayPal, Stripe, wallet balance, credits, COD, manual transfer), shipping templates + Express100 tracking. Text domain `at8-moonlight-shop`; language packs zh_CN / zh_TW / zh_HK / en_US.

## 2. Free / Pro 架构

- Free standalone verified in clean environment (§0 #85).
- Pro (`at8-moonlight-shop-pro` v3.1.0) is a separate add-on: webhooks, advanced analytics, CSV export, Elementor member card.
- No license gate touches any Free feature; `mlpro_local_active` defaults to 0 (verified by test suite).
- No front-end promo links (only plugin-header Author URI + admin Pro card); no tracking/telemetry (scan-verified).

## 3. 安全审计（静态 + 实测）

- Dynamic execution: 0 eval/exec/shell/system; 6× `base64_decode` all legitimate (payment signature verify, AES-GCM, card record, JWT).
- SQL: all dynamic SQL `$wpdb->prepare()`d; status keys whitelisted.
- CSRF: all admin handlers `current_user_can('manage_options')` + nonce; AJAX handlers nonce'd.
- XSS: output escaped (Plugin Check EscapeOutput = 0 errors); favorite-button double-escape regression found & fixed earlier.
- File download: order-bound transient tokens, user binding, expiry, count limits, `get_attached_file((int))` only (no traversal).
- Secrets: none hardcoded; keys from wp_options with masking in admin UI.

## 4. 本轮发现并修复的问题

1. **Guest token REST dead code (functional bug)** — `perm_order_owner()` called `require_login()` before the guest-token branch, so `/moonlight/v1/orders/{id}?token=…` always returned 401 for guests (the front-end order page was unaffected). **Fix:** token-bearing requests go straight to `Moonlight_Rest_Helpers::current_order_owner()` (order-exists → admin → owner → guest `hash_equals`); non-token requests unchanged; write routes remain login-only. **Verified by HTTP e2e: 200 / 403 / 401.**
2. Earlier this round: main-file header description was Chinese and stale ("WeChat (placeholder)" although WeChat Pay v3 is implemented) → English, accurate; `includes/elementor-widgets.php` missing ABSPATH guard → added; readme tags 9 → 5.

## 5. 修改文件清单（V-FINAL，spec #94）

| File | Reason | Change |
|---|---|---|
| `includes/core/class-rest.php` | P1 functional bug (guest token REST path unreachable) | `perm_order_owner()`: token-bearing requests bypass `require_login()`, go to ownership check; verified 200/403/401 |
| `at8-moonlight-shop.php` | readme/header compliance (#59) | English plugin name/description, accurate gateway list |
| `includes/elementor-widgets.php` | direct-access protection | added ABSPATH guard |
| `readme.txt` | spec #16/#17/#20/#21 | English rewrite with `== Features ==`, `== External Services ==` (incl. OAuth providers), `== Privacy ==`; tags trimmed to 5; `Contributors: x361611074` |
| `includes/class-favorite.php` | functional regression | favorite button was double-escaped (`echo esc_html($html)`); now echoes pre-escaped fragments |
| `includes/class-activator.php` + main file | privacy (#66) | `wp_add_privacy_policy_content()` registered on admin_init |
| Round-2 security (prior batch) | P1/P2 | atomic delivery claim; card-key `sold_meta_id` (no plaintext at rest); guest-order downloads; REST write routes login-only; PayPal webhook + Stripe fallback amount checks; cart qty clamp; membership grant atomic claim; `post_author` null guard |

## 6. 测试证据汇总

- Test suite: **830 + 198 assertions, 0 failures** (includes CAS concurrency, webhook idempotency, language-pack integrity).
- Clean-env executed suite: **31/32 assertions green**; the single "FAIL" was a test-harness expectation error (virtual product without an attachment correctly produces no download token).
- Card-key race (2 real processes, 1 card): winners=1, no double-sell.
- IDOR executed via `rest_do_request` as User A/B/guest: all denials correct.
- HTTP e2e guest token: 200 / 403 / 401.
- Plugin Check (final, after all fixes): **ERRORS: 0, WARNINGS: 857 (reviewed, no real issues)**.
- WP_DEBUG web crawl: 0 plugin warnings.
- ZIP: 146 files / ~3.0 MB, no dev files; update-install data-retention verified.

## 7. P0 / P1 / P2

```
P0: 0
P1: 0
P2: 6 (documented; none blocks review): set_status CAS, partial-refund internal-gateway
funds movement, guest-token rotation/expiry, legacy plaintext card pool migration,
credit-unlock double-click, 857 Plugin Check warnings (reviewed false-positives)
```

## 8. 残留声明

- #80 sandbox end-to-end payments (Alipay sandbox / Stripe Test Mode / PayPal Sandbox) require per-gateway sandbox credentials the author must configure; callback verification chains are verified by line-audit + unit tests. Not a review blocker.
- #118 official readme validator is a JS form (not scriptable); structure validated locally (all header fields + all standard sections present) and Plugin Check readme checks = 0 errors.

## FINAL DECISION

**SUBMIT**

提交前人工动作：上传 5 张截图到 .org SVN `assets/`；撤销已暴露的 GitHub PAT。
