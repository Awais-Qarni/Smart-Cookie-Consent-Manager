# Progress log

Newest entry first. Every session ends with an entry: **Done / Next / Notes & blockers**.
Anyone (human or AI) continuing the work should start from the latest "Next".

---

## Current status

- **Version:** 0.1.0 (feature-complete for the 12 features, not yet staging-tested on a real site)
- **Branch:** `feature/v1-build` (pull request to `main`)
- **Phase:** 5, Quality (see `ROADMAP.md`)
- **Last updated:** 2026-09-28

---

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
