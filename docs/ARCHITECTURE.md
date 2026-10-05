# Architecture & design decisions

## Goals
1. Deliver the 12 features in `FEATURES.md` on any WordPress site (plug and play).
2. Work behind page caches and optimisation plugins.
3. Zero external services: all data stays on the site's own server.
4. No build tooling: install by uploading the repo as a zip.

## Approaches considered

| Approach | Verdict |
|---|---|
| Wrap a third-party CMP script (e.g. a SaaS banner) | Rejected: subscription, data leaves the server, less control. |
| PHP renders the banner per visitor based on the consent cookie | Rejected: breaks with full-page caching (everyone gets the same HTML). |
| **Static HTML + browser-side state + server-side tag rewriting** | **Chosen.** HTML is the same for everyone (cache-safe). PHP rewrites risky tags into inert placeholders once; the browser decides what to release based on the `sccm_consent` cookie. |
| Pure JS blocking (MutationObserver only) | Not enough alone: scripts in the initial HTML can execute before JS runs. Used only as a helper. |

## Request flow

```
PHP (once per page, cacheable)
  1. wp_head priority 1  → inline "boot" script:
       - Google Consent Mode default = denied (+ wait_for_update)
       - reads sccm_consent cookie + GPC → gtag('consent','update', …) for returning visitors
       - window.SCCM_CONFIG = { categories, cookies, texts, rules, endpoints, … }
  2. Output buffer (template_redirect) → SCCM_Blocker rewrites matching tags:
       <script src=…>      → <script type="text/plain" data-sccm-category="analytics" data-sccm-src=…>
       <iframe src=…>      → <iframe data-sccm-src=… src="about:blank" data-sccm-category=…>
       <link href=…>       → <link data-sccm-href=… data-sccm-category=…>
  3. sccm-frontend.js (defer) + sccm-frontend.css

Browser
  4. Decide state: stored consent (valid version + not expired) + GPC override
  5. No valid consent → show banner (DOM built by JS)
  6. Release placeholders whose categories are granted (in document order, sequential)
  7. Delete known cookies/localStorage of denied categories
  8. On a choice: write cookie → POST /wp-json/sccm/v1/consent → gtag update → release
     (if a previously granted category is withdrawn → clean up + reload)
  9. Scanner: compare cookie names with the registry → POST /sccm/v1/report
     (cookies only, never from logged-in users; the server lists a cookie only after
     several different visitors reported it)
```

## Data

- **Options** (`sccm_settings`, one array) — all settings, see `SCCM_Settings::defaults()`.
  `sccm_consent_version` (int) is separate so it can be bumped cheaply.
- **Table `{prefix}sccm_cookies`** — the registry: name (supports `*` wildcard), type
  (cookie/localStorage/sessionStorage), category, provider, purpose, duration, service,
  status (active/pending/ignored), source (default/library/manual/scanner), timestamps.
- **Table `{prefix}sccm_consent_log`** — evidence records (see FEATURES #8).
- **Blocking rules** — stored in settings (`rules`: pattern → category) plus rules contributed
  by services enabled from the library.
- **Scan results** — option `sccm_last_scan`.

## How the cookie list is built (and kept short)

| Source | What it adds |
|---|---|
| Install | `sccm_consent` (and WooCommerce's cookies when active). Not the WordPress login cookies: only logged-in users have them. |
| Server scan (cron / "Scan now") | Cookies in `Set-Cookie` headers of real pages (known → Active, unknown → Needs review); for each service detected in the HTML, the cookies it always sets. |
| Visitor reports | Known library cookies → Active at once. Unknown cookies → candidates in option `sccm_candidates`; listed as Needs review only after `SCCM_Cookies::MIN_VISITORS` (2) different visitors reported them. |

Guards: pending items are capped (`MAX_PENDING` = 50), ignored cookies are never re-added, a
scan bumps the consent version at most once (never on the first scan), and approving needs an
explicit category. See `tests/wp/registry-test.php`.

## Browser scan (why "Scan now" opens your pages in a hidden frame)

A server request cannot run JavaScript, so it only sees `Set-Cookie` headers. Most tracking
cookies (`_ga`, HubSpot, chat widgets) are set by scripts, and third-party cookies (YouTube,
Calendly…) live on other domains. Hosted CMPs such as Cookiebot use a crawler with a real
browser. To stay self-hosted, the plugin borrows the administrator's browser:

```
Admin clicks "Scan now"  (assets/js/sccm-admin.js → browserScan)
  1. AJAX sccm_browser_scan_start: server scan (SCCM_Scanner::run) + one-time token (15 min,
     bound to the admin user) + up to 6 page URLs with ?sccm_scan=<token>
  2. Each page opens in a hidden <iframe sandbox="allow-scripts allow-same-origin">.
     SCCM_Frontend::is_scan_mode() = valid token AND manage_options → config.scanMode = true:
       boot: every category granted, Storage.setItem is watched; front end: everything released,
       nothing shown, stored, logged or reported; page sent with no-cache headers.
     The admin page waits until the frame stops loading files (2–9 s, scrolling for lazy
     content), then reads cookie names, storage keys and performance resource URLs.
  3. AJAX sccm_browser_scan_report → SCCM_Scanner::record_browser_scan():
       cookies (minus admin-only ones) → record_seen; storage keys written by the page (or
       known to the library) → record_seen; resource URLs → services (their third-party cookies
       come from includes/data/services.php) or "other third-party resources".
```

If a security header forbids framing the site, the scan reports it and the server scan result
stands.

## Consent dialog

`assets/js/sccm-frontend.js → buildDialog()` builds one dialog used twice: `#sccm-banner` (first
visit, in the chosen position) and `#sccm-prefs` (reopened, centred, with a close button). Tabs:
Consent (text + category switches), Details (category accordion → provider accordion → cookie
cards; built on first open), About (explanation + the visitor's consent status). Switches of
one category in both tabs stay in sync. Buttons: Allow all / Allow selection / Deny, identical
style, order from the `button_order` setting.

## Admin structure

Six tabs, each one view file in `includes/admin/views/`: `dashboard`, `cookies`, `banner`,
`settings`, `records`, `tools`, plus `help.php` (the ⓘ panel, shown on every tab). Old tab
slugs (`general`, `appearance`, `texts`, `categories`, `blocking`, `scanner`, `log`) are mapped
in `SCCM_Admin::legacy_tabs()`. Forms that sit inside the Settings form (sample email, send
waiting alerts) use the HTML `form` attribute because forms cannot be nested.

## Consent cookie
`sccm_consent` = URI-encoded JSON, first-party, `Path=/`, `SameSite=Lax`, `Secure` on HTTPS.
```json
{ "id": "uuid-v4", "v": 3, "t": 1790000000, "c": { "functional": 0, "analytics": 1, "marketing": 0 }, "m": "custom", "g": 0 }
```
The cookie has an expiry date of N days, or none (session cookie) when the owner chose "until
the browser is closed" (`days: 0`).
`id` = consent ID (shown to visitor, stored in the log), `v` = consent version, `t` = unix time,
`c` = granted categories, `m` = method, `g` = GPC applied.

## Why four fixed categories
Stable keys make Consent Mode mapping, blocking rules, exports and multi-site imports
predictable. Labels/descriptions are editable; categories can be hidden if unused.

## Security model
- Admin: `manage_options` + nonces on every action (admin-post handlers).
- Public REST endpoints (needed because pages are cached, so no nonces): strict validation,
  size limits, per-IP-hash rate limiting via transients, names-only payloads.
- Output escaped everywhere; SQL via `$wpdb->prepare()`.

## Known limitations (documented for site owners)
- Fonts/scripts pulled in from *inside* CSS/JS bundles (e.g. `@import` of Google Fonts inside a
  combined stylesheet) cannot be rewritten; the scanner flags them. Fix: self-host fonts.
- Scripts that were already executed cannot be "unloaded" → page reload on withdrawal.
- Optimisers that rewrite `<script>` tags *after* our buffer must exclude `data-sccm-*`
  tags (documented in README → Compatibility).
