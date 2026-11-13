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
| Server scan (cron / "Scan now") | Cookies in `Set-Cookie` headers of real pages (known → Active, unknown → Needs review), with their real lifetime (Max-Age / Expires → `record_lifetime()`, which only replaces a library value, an empty one or its own earlier measurement); for each service detected in the HTML, the cookies it always sets. |
| Visitor reports | Known library cookies → Active at once. Unknown cookies → candidates in option `sccm_candidates`; listed as Needs review only after `SCCM_Cookies::min_visitors()` (setting `learn_visitors`, default 2) different visitors reported them within `learn_days` (default 14) days. |

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
       cookies (minus admin-only ones) → record_seen; their real lifetimes (scan mode wraps
       document.cookie and notes Max-Age / Expires) → SCCM_Cookies::record_lifetime(); storage keys written by the page (or
       known to the library) → record_seen; resource URLs → services (their third-party cookies
       come from includes/data/services.php) or "other third-party resources".
```

If a security header forbids framing the site, the scan reports it and the server scan result
stands.

**Cookies the admin's browser already had.** The frames share the admin's cookie jar, which
also holds cookies of admin tools (e.g. NitroPack's dashboard, its Intercom support chat),
other plugins and extensions. No list of such cookies could ever be complete, so the rule is
proof, not names: before the first page opens, `runScan` notes the cookie names the browser
has. A cookie counts as the website's when a scanned page writes it (scan mode wraps
`document.cookie` and `cookieStore.set`), or when it was not there before (also covers cookies
set by responses to the page's requests). The rest is sent as `unconfirmed` and settled at the
end by `SCCM_Scanner::settle_unconfirmed()`:
- "Learn from visitors" on: not listed. Real visitors' browsers report unknown cookie names,
  so a cookie the website really sets — even one a script sets only when it is missing, which
  the admin's browser already had — is listed after the usual number of visitors; admin-only
  cookies never come back (see "Browsers of WordPress users" below).
- "Learn from visitors" off: `SCCM_Cookies::record_unconfirmed()` puts them under Needs review
  (even when the library knows them), marked "Only found in your browser" (option
  `sccm_browser_only`).
Server-set cookies are not affected: the server part requests pages without cookies, like a
first-time visitor. Regression tests: admin e2e BU1–BU3.

**Pages are built as for a logged-out visitor.** Scan mode needs the administrator (token +
`manage_options`), but scripts that themes and plugins load only for logged-in users (admin
bar tools, support chats, page builders) would write cookies visitors never get, and a known
one would be listed at once. So `SCCM_Frontend::scan_as_visitor()` (on `setup_theme`, before
the theme loads) decides scan mode first and then sets the current user to 0 for the rest of
the page. The admin page reads the frame directly (same origin), so nothing in the frame needs
the login. Regression test: admin e2e BU4.

**Browsers of WordPress users never report.** Plugins load support chats and analytics on
their own admin pages (production 1.3.4: Intercom on NitroPack's settings page), and those
cookies are saved for the whole domain and stay in the browser after logging out. Several staff
browsers visiting the site logged out were "several visitors", so a known cookie such as
`intercom-id-*` was added automatically. Sign of such a browser: `wp-settings-*`, which
WordPress sets in the admin area and keeps for a year, also after logging out. The browser does
not report when it has one (`reportUnknown()`), and `SCCM_REST::store_report()` ignores a
report when `SCCM_REST::is_wordpress_user_browser()` (logged in, a valid login cookie — a REST
request without a nonce has no current user —, or a `wp-settings-*` cookie) or when the report
lists a `wp-settings-*` cookie. Regression tests: admin e2e SB1–SB4, WP-CLI "visitor reports".

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

**Scan now and the page being left.** The browser part's waits use a Web Worker timer (not
slowed down in background tabs). Findings are reported every 3 pages (`sccm_browser_scan_report`,
final=0), so `sccm_browser_scan` (token, pages, pages done, heartbeat) always knows what is left;
leaving the page triggers the browser's "Leave site?" prompt, and `pagehide` sends the rest with
`navigator.sendBeacon` (leaving=1 releases the scan). The server part is kept going by
`keep_alive()` (cron 90 s after the last step), with a lock (`sccm_scan_lock`) so the page and
cron never step at once. Any plugin admin page then calls `sccm_browser_scan_resume` and
continues; while another tab reports (heartbeat < 45 s) it waits and takes over if that tab goes
quiet. Only the final report (final=1) finishes the scan.

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

Only **page** caches are cleared, never the object cache (WP Engine Memcached, W3 Total Cache
"flush all", Redis): it holds data of the whole site. Steps of "Scan now" call
`SCCM_Cache::defer()`, so a clear they ask for waits for the scan's final report
(`SCCM_Cache::release()`), or at the latest `DEFER` (10 minutes) when the scan is left.

**Rule: state that must survive from one request to the next is never kept in a transient.**
On hosts with a persistent object cache, transients live only in that cache and disappear
whenever anything (this plugin, the host, another plugin) empties it. The scan tokens
(`sccm_scan_tokens`, with their own expiry), the scan lock (`sccm_scan_lock`, the time it was
taken) and admin messages (user meta `sccm_notice`) are therefore options / user meta.
Transients are used only where losing them is harmless (rate-limit counters, the one-minute
pass of "Send a test record"). Regression test: admin e2e BS5 (a mu-plugin drops all transients
on every cache clear).

## Asset files (why they are copied to uploads)

Page-cache/CDN plugins such as NitroPack and some hosts cache plugin files and ignore the
`?ver=` query string, so an update could leave logged-out visitors on old JS. `SCCM_Plugin::asset()`
copies each CSS/JS file to `uploads/sccm-assets/<name>.<hash>.<ext>` (hash of version, file
time and size) and serves that URL. If uploads is not writable it falls back to the plugin URL
with `?ver=<version>.<file time>`. Uninstall removes the folder.

Older copies are **not** deleted at once: pages cached before an update still ask for them, and
a 404 there means no banner. When a new copy is made, the older ones are noted in the option
`sccm_asset_retired` (file name => time replaced) and deleted when a later copy is made more than
30 days after that (`SCCM_Plugin::ASSET_KEEP`). A new copy also fires `sccm_assets_changed`,
which makes `SCCM_Cache` clear the page caches, so cached pages are rebuilt with the new files.

## Admin structure

One admin page (`admin.php?page=sccm`, titled "Smart Cookie Consent Manager") with seven tabs,
each one view file in `includes/admin/views/`: `dashboard`, `cookies`, `banner`, `settings`,
`records`, `tools`, `help`. The sidebar item is called **Compliance**; its submenu (a flyout on
hover) has one item per tab: the first is the page itself, the others are links to
`…&tab=<slug>`, and the `submenu_file` filter highlights the open tab. So every old link keeps
working. Old tab slugs of 0.1.0 (`general`, `appearance`, `texts`, `categories`, `blocking`,
`scanner`, `log`) are mapped in `SCCM_Admin::legacy_tabs()`; old `#help` links open the Help
tab. All help (how it works, every tab, banner version, shortcodes, developer code, questions
and problems, glossary) lives on the Help tab only, so the other tabs stay short.

The Dashboard charts are inline SVG drawn on the server (`SCCM_Admin_Charts`: stacked bars per
day, donuts, percentage bars): no chart library, no script, nothing loaded from elsewhere, and a
`<title>` per chart and per bar for screen readers and tooltips. Their numbers come from
`SCCM_Consent_Log::daily()` (per day in the site's time zone), `category_counts()` and
`stats()`.

Forms that sit inside the Settings form (sample email, send waiting alerts) use the HTML `form`
attribute because forms cannot be nested.

## Banner version (consent version)

`sccm_consent_version` goes up by one when visitors must be asked again: a cookie visitors can
see is added or changes category (setting "Ask again when the cookie list changes"), or the
admin clicks "Ask all visitors again". `SCCM_Cookies::bump_version( $reason )` notes the date
and reason of each version in `sccm_version_history` (`version => { t, r }`, last 100), so a
record can be matched to the list the visitor agreed to. The same number appears in the
visitor's consent cookie (`v`), the About tab of the banner, Consent Records (column, filter,
per-version box, "older" mark), the CSV (`banner_version`), Tools and the Dashboard. A record
claiming a version newer than the current one is refused.

## Cookie Policy page (never twice)

`policy_page_id` is a setting, so it is lost when the owner deletes the plugin with "delete all
data" or resets the settings, while the page itself stays (uninstall never deletes content the
owner may have edited, linked in menus or had indexed). `SCCM_Shortcodes::policy_page()` returns
the linked page or, if there is none, finds the oldest published page containing
`[sccm_cookie_policy]` and links it again. It runs on activation (`SCCM_Install::install()`), on
the Dashboard, after Tools → Reset and in "Create the page" (which then says it linked the
existing page instead of creating one). The front end never runs this query.

## Weekly consent report (`SCCM_Report`)

Sent from the daily check (`SCCM_Scanner::daily()` → `SCCM_Report::maybe_send()`): once per
ISO week (option `sccm_report_sent` = `o-W` of the week's Monday), on Monday or, if Monday's
check was missed, on the next daily check of that week. The first check after installing or
updating only notes the week, so the first report waits for the next Monday. Off with the
setting `report_enabled` or while consent records are off. "Send report now" calls
`SCCM_Report::send( true )` (works also when the weekly report is off).

Data (`data()`): `SCCM_Consent_Log::daily( 56, yesterday )` in one query, cut into 8 blocks of
7 days; the last block is "these 7 days", the one before is the comparison.
`category_counts_between()` gives what was allowed per category.

Sending is a small job (option `sccm_report_job`, cron hook `sccm_report_send_event`):
`start()` notes the recipients, `work()` sends one email after the other with
`SCCM_Scanner::send_one()` and starts new emails for at most `STEP_SECONDS` (15 s; every step
sends at least one), then the next WP-Cron step continues. Before each email a follow-up event
is scheduled 120 s ahead, so a step that the host stops while the mail service does not answer
is continued; that recipient is noted as "did not answer in time". "Send report now" only
queues the job and calls `spawn_cron()`, so the admin page answers at once (production 1.4.0:
Microsoft Graph timed out after 30 s and held the page). The scheduled report starts the job
and works on it in the daily cron request. The result is stored in `sccm_last_report_mail`; a job
untouched for 15 minutes no longer blocks the button.

**Classes load by name** (`spl_autoload_register` in the main file, `SCCM_Foo` →
`includes/[admin/]class-sccm-foo.php`), besides the usual `require_once`: right after an update a
host can briefly run a cached older main file with newer view files (production 1.4.0: "Class
SCCM_Report not found" on the Settings tab).

Email: tables only (no SVG, images or scripts); bar heights are pixel cells, segments are
separated by 2px cells, colours of the choices as on the Dashboard except "Denied" (orange,
so it does not read as "no data"; palette validated for colour-vision deficiency). Light
design with dark-mode overrides (`.e-*` classes, also Outlook `[data-ogsc]`/`[data-ogsb]`).
Every text element carries the font stack inline (added in `build_email()` to each
`style="font-size:…"`), plus an `[if mso]` rule: Outlook for Windows does not inherit the font
from the card table. No `margin` (Outlook ignores it): padding and spacer rows instead. Changes
are ▲ green / ▼ red with the value before on its own line, so the tiles line up.

## Consent cookie

**One cookie for everything** (1.3.6): the plugin keeps nothing else in the browser (no
localStorage / sessionStorage keys). Besides the choice, `sccm_consent` holds small notes under
`x`, each kept one day: `a` (time the REST API refused a request) and `r` ([short hash of the
last reported cookie names, time]). Before a choice the cookie holds only `x` (no expiry date);
`parseConsent()` in sccm-boot.js needs `id` and `c`, so notes alone are never a choice. With a
choice, the cookie expires `C.days` after the choice time `t` (not after the last write).
`SCCM.getConsent()` leaves `x` out.
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
- Visitor cookie reports are anonymous, so they are treated as hints: every name (known or not)
  needs `SCCM_Cookies::min_visitors()` different visitors (setting, at least 1), the waiting list is capped, and an
  addition from reports never bumps the consent version (`bulk( …, false )`).
- Text stored in sized columns is cut with `mb_substr()` (characters), never `substr()`
  (bytes): MySQL refuses a value with a broken multi-byte character.
- Admin: `manage_options` + nonce checked in every admin-post handler and AJAX callback.
  Messages after an action are kept in user meta (`sccm_notice`, 5 minutes), never read
  from the URL.
- Values from visitors' browsers (records, cookie reports, scan reports) must be plain strings
  or numbers; lists or objects in their place are refused without PHP warnings. A record
  cannot claim a banner version newer than the current one. Cookie reports from logged-in
  users are ignored on the server too, not only in the browser.
- Settings import: at most 2 MB and 2000 cookie rows; every value goes through the normal
  sanitising (`SCCM_Settings::sanitize()`, `SCCM_Cookies::sanitize_row()`).
- SQL: every query goes through `$wpdb->prepare()`, table names with `%i` (WordPress 6.2+);
  record filters are one fixed statement (`SCCM_Consent_Log::WHERE`, shared by the list, the
  count and the CSV export); unset filters use values that match everything
  (`LIKE '%'`, dates from 1000-01-01 to 9999-12-31) because MySQL in strict mode refuses to
  compare a DATETIME column with `''` (MariaDB allows it; a WP-CLI check guards this).
- CSV export: `SCCM_Consent_Log::csv_cell()` prefixes values starting with = + - @ (CSV injection).
- Texts shown as HTML in the banner are passed through `wp_kses` (links/emphasis) right before
  they go to the browser; all other texts are inserted as plain text.
- Public REST endpoints (needed because pages are cached, so no nonces): strict validation,
  size limits, per-IP-hash rate limiting via transients, names-only payloads. The browser posts
  with same-origin credentials (password-protected staging sites) to a same-site address
  (`SCCM_Frontend::same_site_url()`); when the REST API does not answer (not 400/429), it posts
  the same payload to admin-ajax.php (`sccm_consent` / `sccm_report`), which runs the same
  `store_consent()` / `store_report()` and notes the REST status in `sccm_rest_problem`
  (at most once an hour) for the Consent Records tab.
- **Transport chosen up front** (`SCCM_REST::transport()`, setting `log_transport`, default
  `auto`): the config carries `rest.transport` = `rest` or `ajax`. With `ajax` the browser skips
  the REST request (`rest_status=skip`, not noted as a problem). Automatic runs the
  `rest_authentication_errors` filter as a logged-out request to `/sccm/v1/consent` would (no
  current user and no login cookies, swapped on the globals and restored; `rest_route` set;
  core's cookie check left out), and recognises Admin and Site Enhancements by its
  `disable_rest_api` option while it is active. The result is the same for every visitor
  (cache-safe), stored in `sccm_rest_check` and reused for an hour; plugin (de)activation, SCCM
  settings and ASE settings trigger a new check. A REST 401/403/404 that reaches the admin-ajax
  fallback is a block PHP cannot see (a firewall): it is stored as `by: browser` for 30 days.
  When the answer changes, the page caches are cleared. In the browser, a 401/403/404 is also noted in the plugin's
  cookie (`sccm_consent` → `x.a`, one day), so later sends go straight to admin-ajax.php.
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
