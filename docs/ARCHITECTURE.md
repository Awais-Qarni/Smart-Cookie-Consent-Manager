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
  1. AJAX sccm_browser_scan_start: plans the scan (SCCM_Scanner::begin → urls()), first server
     step, one-time token (30 min, bound to the admin user), the planned URLs with
     ?sccm_scan=<token>. AJAX sccm_browser_scan_server: next server step (≈ 20 s) until done.
  2. Each page opens in a hidden <iframe sandbox="allow-scripts allow-same-origin">, three at a time.
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

## Scan plan (which pages, how many)

`SCCM_Scanner::urls()` → `scan_budget( count_pages() )` pages (all published items of public
post types): up to 40 → all (+2 for home and policy page); more → 40 + 2·√(pages − 40), at most
80 (100 pages → 55, 300 → 72). Order: (1) every main page: home, Cookie Policy, blog page,
top-level menu items, top-level pages; (2) one recent item of each other public content type;
(3) half of what is left for sub pages (menu level 2, child pages), half for sub-sub pages (menu
level 3+, grandchildren), each taken round-robin over their parents so every section is
covered; a level with fewer pages leaves its share to the other; (4) recently updated content.
Only this website's pages: no files, feeds, wp-admin, wp-login, wp-json. Filters:
`sccm_scan_budget`, `sccm_scan_urls`.

Work in progress lives in `sccm_scan_state` (plan, pages done, results so far): `begin()`,
`step( $seconds )`, `finish()`. Scheduled scans: `run_cron()` + `sccm_scan_continue_event`
every 30 s until done; "Scan now": one AJAX request per step; `run()` (tests, no-JS fallback
uses begin + one 40 s step + background continuation) scans everything in one call.

## Consent dialog

`assets/js/sccm-frontend.js → buildDialog()` builds one dialog used twice: `#sccm-banner` (first
visit, in the chosen position) and `#sccm-prefs` (reopened, centred, with a close button). Tabs:
Consent (text + category switches), Details (category accordion → provider accordion → cookie
cards; built on first open), About (explanation + the visitor's consent status). Switches of
one category in both tabs stay in sync. Buttons: Allow all / Allow selection / Deny, identical
style, order from the `button_order` setting (`SCCM_Settings::button_orders()`; JS `ORDERS`):
`accept_reject` (default), `reject_accept`, `accept_first`, `reject_first`. "Allow selection"
and the compact banner's "Customize" take the same place.

The first-visit banner has two styles (`banner_layout`): **compact** (default,
`buildCompactBanner()`: text + Allow all / Deny / Customize; Customize hides the banner and opens
`#sccm-prefs`; closing that without a choice shows the banner again) and **tabs** (the dialog
above as the banner).

**Preview** (`/#sccm-banner`, used by the admin's Preview links): the banner is shown even when
the visitor has already chosen, but it renders as a first visit (switches off, About says "no
choice yet"), and so does the window its Customize button opens (`showsChoice()`). The stored
choice only changes if a button is clicked.

**Theme isolation.** Dialogs and the widget are mounted in `<div id="sccm-app">` (`mount()`), and
every rule in `sccm-frontend.css` starts with `#sccm-app`. Theme rules (`button {…}`,
`.elementor-kit-5 button:hover`, `html body p {…}`) are less specific, so the banner looks the
same on every site; the e2e suite checks this with a hostile stylesheet. Buttons never wrap their
text (`white-space: nowrap`); `fitButtons()` stacks the three buttons (still equal width) when
they do not fit side by side, e.g. in a corner box with a long translation.

## Cookie Policy page

`[sccm_cookie_policy]` prints the active cookies grouped by category when the page is rendered,
so it is always current; `SCCM_Cache` clears cached copies when the list changes. The list is
not `.sccm-root`: fonts, colours and table look come from the theme (lines use `currentColor`),
so it is readable on light and dark themes. On the page chosen in the settings,
`policy_hide_sidebar` (default on) empties widget sidebars (`is_active_sidebar`,
`sidebars_widgets`) and asks Astra / GeneratePress / OceanWP for their no-sidebar layout.

## Page caches (`SCCM_Cache`)

Every page carries the config (texts, colours, cookie list) and the policy page prints the
list, so cached copies go stale when they change. `SCCM_Cache::request()` is hooked to
`sccm_settings_saved`, `sccm_consent_version_changed` and `sccm_cookie_list_changed`; the clear
runs once at `shutdown`. Clears not started by an administrator (e.g. a cookie added from a
visitor's report) are throttled to one per 5 minutes, the rest postponed to a WP-Cron event, so
visitors can never cause a stream of purges. Each integration call is guarded (`function_exists`
/ `try … catch ( Throwable )`): a missing or changed cache plugin never breaks the site.

## Asset files (why they are copied to uploads)

Page-cache/CDN plugins such as NitroPack and some hosts cache plugin files and ignore the
`?ver=` query string, so an update could leave logged-out visitors on old JS. `SCCM_Plugin::asset()`
copies each CSS/JS file to `uploads/sccm-assets/<name>.<hash>.<ext>` (hash of version, file
time and size), serves that URL, and deletes older copies. If uploads is not writable it falls
back to the plugin URL with `?ver=<version>.<file time>`. Uninstall removes the folder.

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
- AMP pages (official AMP plugin) and page-builder editors are left alone: no banner, no
  blocking (AMP has its own consent component; editors must keep working).
- Another consent plugin running at the same time (Cookiebot, Complianz, CookieYes…) gives two
  banners; the Dashboard warns about it (`SCCM_Admin::other_consent_plugins()`).
- Page caches that are not in the `SCCM_Cache` list (e.g. a CDN in front of the site) must be
  cleared by hand, or from the `sccm_cache_purged` action.
