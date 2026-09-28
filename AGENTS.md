# AGENTS.md — Smart Cookie Consent Manager

Instructions for any AI agent or developer working in this repository.
**Read this file first, then `docs/PROGRESS.md` to see exactly where work stopped.**

---

## 1. What this project is

**Smart Cookie Consent Manager** (SCCM) is a plug-and-play WordPress plugin that gives any
website a complete cookie consent system (a CMP, "Consent Management Platform"):

- A banner with **Accept All / Reject Non-Essential / Manage Preferences**
- Non-essential cookies, scripts, iframes and fonts **blocked until consent**
- **Google Consent Mode v2**, **Global Privacy Control (GPC)** support
- **Consent records** (evidence), **cookie scanner** with email alerts
- An automatically maintained **Cookie Policy** list

It must work on **any** WordPress site after activation, with sensible defaults and no code
changes. It is NOT built for one specific website.

- Author: Muhammad Awais (WordPress Developer)
- Repo: https://github.com/Awais-Qarni/Smart-Cookie-Consent-Manager
- License: GPL-2.0-or-later (WordPress plugin standard)

## 2. Where to find things

| File | Purpose |
|---|---|
| `docs/PROGRESS.md` | **Session log + current status + next steps. Start here.** |
| `docs/ROADMAP.md` | Phased plan with checkboxes. Tick items as they are finished. |
| `docs/FEATURES.md` | The 12 required features and their acceptance criteria. This is the contract. |
| `docs/ARCHITECTURE.md` | How the plugin is built and why (design decisions). |
| `docs/TESTING.md` | Manual test checklist (staging) incl. GPC tests + automated checks. |
| `docs/HOOKS.md` | Public filters/actions/JS API for developers. |
| `CHANGELOG.md` | User-facing change history. |
| `README.md` | Product overview, install, usage. |
| `readme.txt` | WordPress.org-format readme. |

## 3. Code map

```
smart-cookie-consent-manager.php   Bootstrap: constants, requires, activation hooks
uninstall.php                      Removes options/tables only if "delete data on uninstall" is on
includes/
  class-sccm-plugin.php            Main singleton; wires all modules
  class-sccm-install.php           DB tables (dbDelta), default options, upgrades (DB_VERSION)
  class-sccm-settings.php          Option storage, defaults, sanitising, import/export
  class-sccm-categories.php        The 4 fixed categories + Consent Mode mapping
  class-sccm-cookies.php           Cookie registry (DB table sccm_cookies), wildcard matching
  class-sccm-services.php          Library of known services (GA, GTM, Meta, YouTube…)
  class-sccm-blocker.php           Server-side HTML rewrite: blocks scripts/iframes/links
  class-sccm-frontend.php          Head boot script, config JSON, assets, Consent Mode default
  class-sccm-consent-log.php       Consent records (DB table sccm_consent_log), CSV export
  class-sccm-rest.php              REST: /sccm/v1/consent and /sccm/v1/report
  class-sccm-scanner.php           Server scan (cron) + visitor-reported cookies + email alerts
  class-sccm-shortcodes.php        [sccm_cookie_policy], [sccm_cookie_settings]
  data/services.php                Service signatures (data only)
  admin/class-sccm-admin.php       Admin menu, tabs, form handlers
  admin/views/*.php                One view file per admin tab
assets/js/sccm-frontend.js         Banner, preferences modal, unblocking, GPC, cleanup, reporting
assets/css/sccm-frontend.css       Banner/modal styles (CSS variables for theming)
assets/js/sccm-admin.js            Small admin helpers
assets/css/sccm-admin.css          Admin styles
tests/                             Automated checks (see docs/TESTING.md)
```

## 4. Golden rules (do not break these)

1. **Scope = `docs/FEATURES.md`.** All 12 features must keep working. Do not remove or
   silently change a feature. New ideas go to "Later" in `docs/ROADMAP.md` first.
2. **Plug and play.** No site-specific code, names, domains or brands. Integrations with
   other plugins/services must be generic (data in `includes/data/services.php`, filters).
3. **Cache-safe.** Never print per-visitor data from PHP on the front end. Consent state is
   read in the browser from the `sccm_consent` cookie. The banner is rendered by JS.
4. **No build step, no runtime dependencies.** Plain PHP (7.4+) and vanilla JS (ES2017,
   no jQuery on the front end). The repo root must be installable as-is (zip → upload).
5. **Security.** Escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`),
   sanitise all input, `check_admin_referer()` + `current_user_can( 'manage_options' )` on
   every admin action, `$wpdb->prepare()` for every query with variables.
6. **Privacy by design.** Never store cookie *values*. IP addresses are anonymised or hashed
   (setting). Consent records hold only what is needed as evidence.
7. **Naming.** PHP prefix `sccm_` / classes `SCCM_*`, JS global `window.SCCM`, CSS prefix
   `sccm-`, text domain `smart-cookie-consent-manager`. All user-facing strings translatable.
8. **Database changes** only through `SCCM_Install` and a bump of `SCCM_Install::DB_VERSION`.
9. **Fair consent UI.** Accept and Reject always have equal visual weight; nothing is
   pre-ticked; closing/ignoring the banner never counts as consent.

## 5. How to work in this repo

1. Read `docs/PROGRESS.md` → pick the next unchecked item in `docs/ROADMAP.md`.
2. Make the change. Keep functions small and documented (PHPDoc / JSDoc).
3. Run the checks in `docs/TESTING.md` → "Automated checks" (at minimum `php -l` on every
   PHP file and `node --check` on JS files; `npm test` if present).
4. Update **at the end of every session**:
   - `docs/PROGRESS.md` — add a dated entry: what was done, what is next, blockers.
   - `docs/ROADMAP.md` — tick finished items.
   - `CHANGELOG.md` — user-facing changes under "Unreleased".
5. Commit with clear messages, e.g. `feat(scanner): email digest for new cookies`.
   Bump the version in the plugin header, `SCCM_VERSION` and `readme.txt` only on release.

## 6. Definition of done (per feature)

- Works on a clean WordPress install with default settings.
- Works with page caching enabled (no per-visitor PHP output).
- Admin-configurable where it makes sense; strings translatable.
- Covered by a step in `docs/TESTING.md`.
- `docs/PROGRESS.md` and `docs/ROADMAP.md` updated.
