# Progress log

Newest entry first. Every session ends with an entry: **Done / Next / Notes & blockers**.
Anyone (human or AI) continuing the work should start from the latest "Next".

---

## Current status

- **Version:** 1.2.0 (all owner requests so far done; not yet staging-tested on a real site)
- **Branch:** `feature/v1-build`, merged into `main` by pull request
- **Phase:** 5b done (see `ROADMAP.md`); staging test and release are next
- **Last updated:** 2026-10-06 (session 6)
- **Tests:** unit 33, WP-CLI 120, visitor e2e 102, admin e2e 51, all passing; WordPress.org
  Plugin Check clean on the release files except the requested `mailto:` Author URI

---

## 2026-10-06 — Session 6 (owner feedback round 4 + production hardening)

**Set-up:** the project now also runs on the owner's Windows PC: portable PHP 8.3 + MariaDB
11.4 + WordPress 7.1 + WP-CLI in `workspace/sccm-dev` (outside the repo; start/stop/env scripts
there). Windows notes added to `tests/e2e/README.md`. Commit `84b9693` made the e2e runners
work on Windows (output path, `SCCM_WP` pointing at `wp-cli.phar`).

**Root causes found**
- Cookie Policy page unreadable on the owner's dark theme: the list was `.sccm-root`, so it got
  the banner's dark text colour (`--sccm-text`) and font size.
- "Policy page must update after a scan": the shortcode was always current, but page caches
  (NitroPack, WP Engine) kept serving the old copy. Same for banner texts/cookie list.
- About tab "Your current choice: Allowed all" without agreeing: it was the banner **preview**
  (`/#sccm-banner`) shown to the owner, who had clicked Allow all earlier; the preview rendered
  the stored choice.
- Buttons looked different per theme and lost their bold: theme rules (`.elementor-kit-N
  button:hover` …) beat `.sccm-btn`, and our own `.sccm-root button { font: inherit }` (higher
  specificity) wiped `font-weight: 600`.
- "Allow selection" wrapped: three equal columns in a 460px corner box are narrower than the text.
- Email "broken": dark-mode inversion (Outlook / Gmail apps) turned the dark header into a light
  block, and Outlook ignores padding on `<a>`, so the button lost its padding.

**Done**
- Policy list follows the theme (no `.sccm-root`, `currentColor` lines, only cookie names break);
  "Hide the sidebar on the Cookie Policy page" (default on; widget sidebars emptied; Astra /
  GeneratePress / OceanWP no-sidebar layout); body class `sccm-policy-page`.
- `SCCM_Cache` (new): clears page caches of WP Engine, NitroPack, WP Rocket, LiteSpeed, W3TC,
  WP Super Cache, WP Fastest Cache, SiteGround, Breeze, Hummingbird after settings saves, version
  bumps and cookie list changes (`sccm_cookie_list_changed`); once per request, visitor-caused
  clears throttled to 5 min (rest via WP-Cron); every call guarded.
- Button order: 4 options (`accept_reject` default, `reject_accept`, `accept_first`,
  `reject_first`); Customize takes Allow selection's place. DB version 3 maps saved old values
  so the compact banner looks as before.
- Theme isolation: dialogs + widget in `<div id="sccm-app">`, all CSS scoped to it; buttons
  `nowrap`, `fitButtons()` stacks them (equal width) when they do not fit; detailed style in
  wide bars: buttons right-aligned at natural equal width; compact bar: equal columns.
- Preview = first visit (switches off, "no choice yet", also in its Customize window).
- Removed the "Show details" link (and its text setting).
- Email rebuilt: light design that survives inversion, `color-scheme` + dark-mode CSS
  (Apple Mail, Outlook.com `[data-ogsc]`), Outlook-safe button, dark-mode label colours.
- Hardening: AMP pages skipped; more page-builder editors skipped; Dashboard warns when another
  consent plugin (Cookiebot, Complianz, CookieYes, Borlabs…) is active; `.pot` generated with
  translator comments for every placeholder; Custom CSS help names the real selectors.
- **Emails (owner: "no auto email arrives")**: root causes: one daily WP-Cron job at a random
  time (2 h after activation), listed addresses replaced the admin, failures were silent. A
  per-scan report was built first, then replaced at the owner's request by one simple rule:
  `sccm_daily_event` at the chosen hour (`alert_hour`, default 9, site time zone) sends one email
  only if `sccm_pending_alert` holds changes (≈ last 24 h); no changes, no email. Scheduled scans
  run one hour earlier. Recipients = admin (`alert_include_admin`) + listed. No admin links or
  buttons in the email (recipients may have no account). Last result in `sccm_last_mail`
  (Settings); unsent changes stay queued. Unclassified resources queued only when new.
- Tests: unit 33/33, WP-CLI 101/101 (was 56), e2e 99/99 (was 76; new: four orders × two styles,
  preview, hostile theme CSS, one-line buttons, long translation stacking, wide-bar width,
  policy on a dark theme), admin e2e 38/38 (was 35; daily email after a scan, delivery status). All suites also run with `WP_DEBUG` on: no PHP
  warnings, notices or deprecations from the plugin (WordPress 7.1, PHP 8.3).

**Round 5 (same day): adaptive scan, version 1.2.0, vertical bar buttons**
- Scan plan (`SCCM_Scanner::urls()`): budget from the number of published pages (≤ 40 → all;
  else 40 + 2·√(n − 40), max 80), main pages → one per content type → sub / sub-sub pages
  round-robin per parent → recent content. Scans run in steps (`begin/step/finish`, state in
  `sccm_scan_state`): "Scan now" one AJAX step at a time + browser frames 3 in parallel;
  scheduled scans continue via `sccm_scan_continue_event`; no-JS "Scan now" 40 s + background.
  Tested for real: 143-page site → 60-page plan, Scan now 104 s; slow host (0.7 s/page) cron
  scan = 4 runs of ≤ 21 s.
- Compact top/bottom bar: buttons in one vertical column (right of the text from 700 px).
- Version 1.2.0 (header, `SCCM_VERSION`, readme Stable tag, package.json); Author URI
  `mailto:reachoutawais@gmail.com`; CHANGELOG `[Unreleased]` → `[1.2.0] - 2026-10-06`.
- Tests: WP-CLI 111/111, e2e 102/102, admin e2e 38/38.

**Round 6 (same day): final review before going live**
- WordPress.org Plugin Check (installed on the test site) on the release files: 155 findings on
  the repo folder (mostly tests/docs/dotfiles, not shipped) → release files clean except the
  owner's `mailto:` Author URI (WordPress.org requires a web URL there; kept on request).
- Security: CSV injection in the records export fixed (`csv_cell`); admin notices moved from
  `?sccm_msg=` to a per-user transient (no spoofed messages); nonce checked in every handler;
  all SQL prepared with `%i` table names (Requires at least 6.2); record filters as one fixed
  statement; banner HTML texts `wp_kses`'d before output whatever the source.
- Bug found by WP_DEBUG: a scan step + a page timeout could exceed a 30 s PHP limit (fatal).
  Steps now size themselves from `max_execution_time`; tested with a 2 s/page slow host.
- GA4-by-ID uses Google's snippet (no raw script tag); new admin e2e check for Strict/Advanced.
- Dead-code sweep: no unused PHP functions or texts; removed `.sccm-linkbtn` CSS, `.gitkeep`.
- Tests: unit 33/33, WP-CLI 120/120, e2e 102/102, admin e2e 41/41, no PHP notices with WP_DEBUG.

**Round 9 (same day): "consent records stay empty" on staging**
- Locally records worked (REST 201, row listed). Likely causes on WP Engine staging, all fixed:
  the banner posted with `credentials: 'omit'`, so a password-protected staging site (HTTP
  auth) refused it; the absolute REST URL in a page cached under another domain / scheme could
  be blocked (mixed content, other host); a security plugin or firewall may block the REST API.
- Fix: same-origin credentials, same-site (relative) URLs, admin-ajax fallback
  (`sccm_consent` / `sccm_report`) on any REST failure except 400/429, `sccm_rest_problem`
  note + notice on the Consent Records tab, help text when the list is empty; consent rate
  limit 60/h per IP.
- Tests: admin e2e 51/51 (CR1–CR5: stored and listed, sent with credentials, same-site URL,
  REST blocked → saved via admin-ajax + notice, empty-list help).
- Owner: after updating, purge NitroPack + WP Engine caches (old cached pages carry the old
  script), then test in a private window.

**Round 8 (same day): small admin fixes, then pull request**
- Help button in the top right corner of the header.
- Removed the "Add rule" form in the scan report and its handler: it saved a rule under
  Settings but the report looked unchanged, so it seemed to do nothing. Rules are added under
  Settings → Blocking and Google.
- Fold-out panels: full-width tables and text areas keep the 20 px right padding.
- Docs brought in line with the code (README, FEATURES, ROADMAP, PERFORMANCE sizes).

**Round 7 (same day): "Scan now stops when I switch tab or leave the page"**
- Causes: background tabs slow page timers (the settle loop crawled); leaving the page killed
  the browser part and left the server part half-done (nothing continued it).
- Fix: Web Worker timers; reports every 3 pages; `sccm_browser_scan` state + resume endpoint
  (any plugin page continues, heartbeat avoids two tabs, take-over after 45 s); "Leave site?"
  prompt + sendBeacon on pagehide; server part kept alive by cron (`keep_alive`) with a lock.
- Tests: admin e2e 44/44 (new S1–S3: leave mid-scan after 6 of 19 pages → resumes on the
  Dashboard and finishes 19/19, no state left).

**Next**
1. Owner: update the plugin on staging (purge NitroPack + WP Engine once more for this update),
   deactivate Cookiebot there if it is still active (the Dashboard now says so), check the
   banner in a private window, the Cookie Policy page, and the sample email in dark mode.
   Click "Send me a sample email" and look at the "Last email" line under it: if it failed, or
   nothing arrives (also check spam), the host needs an SMTP plugin (e.g. WP Mail SMTP).
2. Then Cookies → Scan now and compare with Cookiebot (as in session 5).
3. Before a public release: run the official Plugin Check plugin and the test suites on PHP 7.4
   (minimum version; only PHP 8.3 was available here), then tag 1.0.0.

**Notes & blockers**
- Cache clearing covers the listed plugins/hosts; a CDN in front of the site (e.g. Cloudflare
  APO) must still be purged by hand or from `sccm_cache_purged`.
- "Never breaks on any site" cannot be proven; the risky parts (theme CSS, page caches, other
  CMPs, AMP, builders, optimisers) are now guarded and tested, and failures fall back to leaving
  the page untouched.

---

## 2026-10-05 — Session 5 (owner feedback: missing cookies, two different banners, Help button)

**Root causes found**
- Banner looked different logged in vs in a private window: the private window got an **old
  cached `sccm-frontend.js`** (NitroPack/CDN serve logged-out visitors from their own cache and
  ignore `?ver=`). Fixed for good with hashed copies in `uploads/sccm-assets/`
  (`SCCM_Plugin::asset()`). The owner must purge NitroPack + WP Engine caches once after this
  update; later updates change the file name by themselves.
- Missing cookies vs the first manual scan (PDF "Prima_Systems_Cookie_Details_1"): the PDF list
  was GA4 (`_ga`, `_ga_*`), Google Tag Manager, Google signals/Ads, NitroPack, GTranslate,
  Google Fonts, Cloudflare. All are now in the library (NitroPack and GTranslate were not).
  **Calendly was not in that PDF**; it was in the owner's Cookiebot screenshot, and it is found
  when a scanned page embeds it (Calendly sets its cookies on its own domain inside the embed).
  Cookies that need an interaction (e.g. `googtrans` after switching language) only appear once
  really set.

**Done**
- Banner style setting: **Compact** (default; Allow all / Deny / Customize, like the "incognito"
  look the owner liked) or **Detailed** (the tabbed banner). Customize opens the full window;
  closing it without a choice shows the banner again.
- Library: PHP session, NitroPack, GTranslate, Tidio, LiveChat, Klaviyo, Snapchat, Reddit,
  WPML; per-cookie category override (6th value); Google Ads audiences pattern.
- Scanner: menu pages first, 10 pages in the browser scan; scan diagnostics line on the Cookies
  tab ("Browser part: N pages opened…" or a warning).
- Help button aligned next to the title.
- Tests: unit 33/33, WP-CLI 56/56, e2e 76/76 (new compact checks M1–M3), admin e2e 35/35.

**Next**
1. Update the plugin on staging, **purge NitroPack and WP Engine caches once**, check the banner
   in a private window, then Cookies → **Scan now** (keep the tab open). The scan line must say
   "Browser part: N page(s) opened…".
2. Compare with Cookiebot's report; approve anything under Needs review.

**Notes & blockers**
- The browser scan cannot click (language switchers, chat buttons), so cookies set only after
  an interaction appear later via visitor reports or can be added by hand.

## 2026-10-05 — Session 4 (owner feedback on staging: design + scan)

**Root causes found**
- Admin "broken" layout, Help button and expiry dropdown not working: **stale cached CSS/JS**.
  Assets were versioned with `SCCM_VERSION` (unchanged 0.1.0), so WP Engine / the browser kept
  the old files. Fixed: `SCCM_Plugin::asset_version()` = version + file time.
- Scan listed only `__cf_bm` + `sccm_consent` while Cookiebot listed ~30: the server scan cannot
  run JavaScript, so it never saw script-set or third-party cookies.

**Done**
- Cookiebot-style dialog: Consent / Details / About tabs; Details = category accordion →
  provider accordion → cookie cards (name, purpose, maximum storage duration, type); About =
  explanation + choice/date/consent ID. Same dialog when reopened. Full-width category switches
  (2 × 2 in corner boxes and on phones). Default texts now "Allow all / Allow selection / Deny".
- Button order setting (Allow all first by default; Deny first available). Answer to the owner:
  with identical buttons either order is lawful; regulators judge equal prominence, and some
  prefer Deny first, hence the setting.
- Cookie settings button: corners = round icon; bottom centre / edges = "Cookies" text tab.
- Expiry: the days field only shows for "Another number of days".
- **Browser scan** in "Scan now" (see ARCHITECTURE.md → Browser scan); service library +
  third-party cookies (YouTube, Maps, Vimeo, Meta `fr`, LinkedIn, DoubleClick, Clarity,
  Cloudflare, reCAPTCHA) and new services (Calendly, Stripe, Elementor, WooCommerce order
  attribution).
- Cleanup: removed `banner_categories` setting, unused texts (`btn_manage`, `btn_save`,
  `prefs_*`, `always_on`, `show_cookies`, `saved`), the old modal code/CSS, the `_test` JS hook
  and `tests/e2e/widget-shots.mjs`.
- Tests: unit 33/33, e2e 69/69, WP-CLI 45/45, admin e2e 33/33 (includes a real browser scan that
  finds `_ga`, YouTube `YSC`, and an unknown script cookie + storage key).

**Next**
1. Update the plugin on staging (`primasystemstg`), open Cookie Consent once (no cache clearing
   needed now), click **Scan now** and keep the tab open until it finishes. Compare with
   Cookiebot's report on `primasystemmov`: expect the same services; names Cookiebot calls
   "unclassified" will be under **Needs review**.
2. If a service is missing from the library (e.g. a site-specific plugin), approve its cookies
   once or add it with the `sccm_services` filter.

**Notes & blockers**
- Still no access from the build environment to either site, Cookiebot, or the owner's browser
  extension, so the comparison with Cookiebot is from the owner's screenshots.

## 2026-10-05 — Session 3 (pre-staging changes, 10 requests)

**Done** (numbers refer to the owner's list)
1+6. **Admin simplified.** 9 tabs → 6 (Dashboard, Cookies, Banner, Settings, Consent Records,
   Tools). Dashboard = live status + "Needs your attention" to-do + 3 numbers + how-it-works.
   ⓘ **Help** panel on every page (`views/help.php`) explains how it works and every
   tab/button/option; the current tab's section opens. Old tab slugs still work.
2+5. **Cookiebot-style UI from public knowledge of Cookiebot** (see blockers): categories
   renamed Necessary / Preferences / Statistics / Marketing (keys unchanged); the four
   category switches are on the banner's first layer ("Reject", "Accept", "Allow selection",
   "Show details"); the details window has per-category cards.
3. **Performance review** → `docs/PERFORMANCE.md`. Done: non-blocking CSS, autoloaded settings,
   cheap admin badge, cached wildcard regex, bounded "Scan now", idle-time reporter. Tried and
   **reverted** a blocker pre-check (6.8 ms vs 1.55 ms: slower). Plugin overhead measured ≈ 0.5 ms
   per page view (noise level).
4. **Cookie list noise fixed** (root causes in the CHANGELOG): the 6 "active" were seeded
   WordPress login cookies; the ~30 "pending" came from browsers reporting every cookie and
   localStorage key (admin extensions etc.). Now: no WP login cookies, cookies-only reporting,
   never from logged-in users, ≥ 2 different visitors needed, cap 50, GA4 split from Universal
   Analytics, conditional cookies (`_fbc`…) only when seen, approve needs an explicit category,
   "Ignore all". DB version 2 cleans old noise on upgrade and schedules a re-scan.
7. **Widget**: bottom-left/right = round; bottom-centre / left-centre / right-centre = half-circle
   peeking from the edge, slides out on hover/focus (CSS only).
8. **Alert email**: HTML (tables + inline CSS) + plain-text, several recipients (one per line,
   max 10), "Send me a sample email" and "Send waiting alerts now" buttons.
9. **Consent ID**: was already a UUID in the cookie + log; now shown with the choice and date
   in the details window (Copy button), in the `dataLayer` event and `SCCM.getConsentId()`.
10. **Consent expiry** is a choice: presets, custom days (1–395) or 0 = browser session.
   Max stays 395: browsers cap cookie lifetime (~400 days), longer values would be cut silently.

**Tests**: `npm test` (33 unit), `npm run e2e` 65/65, `npm run test:wp` 31/31,
`npm run e2e:admin` 23/23, all against a real WordPress 6.5 + MariaDB built in the session
(WordPress via Composer, WP-CLI via Composer; see `tests/e2e/README.md`). The upgrade path
0.1.0 → this version was tested through a real front-end request.

**Next**
1. Install on staging and run `docs/TESTING.md` (new items D5, D6, E3, F5, I1–I4 are for this
   round). Look at the admin on a real site and compare the banner with the live Cookiebot one.
2. Check Cookies → "Needs review" after a day of real traffic: it should hold only genuine,
   unknown cookies (expect 0–3). If a real cookie never shows up, lower
   `SCCM_Cookies::MIN_VISITORS` or add it by hand.
3. Generate the `.pot` file; tag 1.0.0 after sign-off.

**Notes & blockers**
- The build environment could **not reach** `primasystemmov.wpengine.com` (egress policy) and
  has **no browser tool or Cookiebot login**, and the "attached docs" with the 11-cookie scan were
  not available. The Cookiebot-inspired parts follow Cookiebot's public conventions (category
  names, switches on the first layer, "Allow selection", consent ID/date), not a side-by-side
  review. A side-by-side review is still open (ROADMAP Phase 5b).
- Safari limits JavaScript-set cookies to 7 days, so consent for Safari visitors may be asked
  again sooner than the chosen period. A server-set cookie would avoid it (idea for "Later").
- Test tips: a long-running `php -S` keeps following an old plugin symlink target
  (`-d realpath_cache_ttl=0`); `pkill -f` can kill your own shell, match by exact name.

## 2026-09-28 — Session 2 (build v0.1.0)

**Done**
- Built the whole plugin (Phases 1–4): core, visitor side, server side, admin (9 tabs).
- Automated checks: `npm test` = lint + 33 PHP unit tests (blocker rewriting, wildcard
  matching, name validation, IP anonymisation), all passing.
- End-to-end: ran WordPress 6.5 locally (SQLite + `php -S`) with the tracker fixture
  (`tests/e2e/fixture-trackers.php`); `npm run e2e` → **40/40 checks passed**, covering:
  first visit/no tracking, equal buttons, Accept/Reject/Manage, release without reload,
  Consent Mode default + update, returning visitor, withdrawal (cookie deleted + reload,
  same consent ID), Esc/keyboard, menu link + shortcode opener, iframe placeholder, GPC
  (no banner, recorded as `gpc`, notice, switches locked), mobile layout, cookie policy page.
- Verified server side manually: consent log rows (IP anonymised), visitor-side scanner
  (unknown cookie + localStorage → Pending; `_ga`/`_fbp` → auto-added as known), server
  scan (detected Google Fonts, GA, YouTube, Meta Pixel), admin save/sanitise (expiry clamped
  to 395), CSV export, settings export, version bump, bad nonce → 403, logged out → refused.
- Fixed during testing: the switch's decorative slider captured clicks (added
  `pointer-events:none`).
- First install now schedules a scan 1 minute after activation so the cookie list is filled
  before most visitors consent.

**Next**
1. Install on the staging site; run `docs/TESTING.md` manual checklist with Compliance
   (especially GPC J1–J4, scanner email I3, cache K1, translation K2, import K4; these were
   not covered by the automated run).
2. Categorise the site's pending cookies in the Cookies tab; check the Scanner tab notes
   (fonts loaded from inside CSS cannot be blocked, self-host them).
3. Generate the `.pot` file (`wp i18n make-pot`).
4. Tag release 1.0.0 after staging sign-off.

**Notes & blockers**
- The local test environment's WordPress was a trimmed build without admin CSS/JS assets, so
  admin screens were checked for correct HTML/PHP only. Look at the admin UI on staging.
- A new known cookie found by the scanner (e.g. `_ga`) bumps the consent version when
  "Ask again when the cookie list changes" is on (by design, per feature 6).

## 2026-09-28 — Session 1 (planning)

**Done**
- Analysed requirements (12 features agreed with Compliance) and chose the architecture:
  static cache-safe HTML + browser-side consent state + server-side tag rewriting
  (see `ARCHITECTURE.md`).
- Wrote project docs: `AGENTS.md`, `CLAUDE.md`, `FEATURES.md`, `ARCHITECTURE.md`,
  `ROADMAP.md`, `TESTING.md`, this file.

**Notes**
- Plugin must stay generic (plug and play), with no site-specific names or code.
