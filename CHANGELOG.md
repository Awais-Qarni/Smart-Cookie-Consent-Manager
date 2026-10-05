# Changelog

All notable changes are listed here. Format: [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]
### Changed
- **Admin simplified: 9 tabs became 6** (Dashboard, Cookies, Banner, Settings, Consent Records,
  Tools). The Dashboard shows whether the banner is live, a plain "Needs your attention" to-do
  list, three key numbers and a how-it-works strip. Old tab links keep working.
- New **Help** button on every admin page: how it works, and every tab, button and option
  explained in plain language.
- **Categories renamed** to Cookiebot's names: Necessary, Preferences, Statistics, Marketing
  (keys and Google Consent Mode mapping are unchanged; custom names you saved are kept).
- Banner now shows the four **category switches on the first layer** (turn off for the old
  three-button banner) with "Reject", "Accept" and "Allow selection".
- **Consent expiry is your choice**: presets (until the browser closes, 1 week, 1/3/6/12/13
  months) or any number of days up to 395 (browsers do not keep a cookie longer).
- Cookie settings button has two more positions, **bottom centre** and **left/right edge**,
  drawn as a minimal half-circle that peeks out from the screen edge and slides out on hover.
- Preferences window shows the visitor's **current choice, date and consent ID** with a Copy
  button; the consent ID is also in the `dataLayer` event and `SCCM.getConsentId()`.
- Scanner alert email redesigned (HTML, clear sections, one button, plain-text version) and
  can go to **several recipients**; "Send me a sample email" button.
- Cookie list is shown **grouped by category**; unknown cookies wait in a "Needs review" inbox
  where a category must be chosen before Approve; "Ignore all" button.

### Fixed
- **Cookie list was far too long** (e.g. 36 entries, 6 auto-active). Causes and fixes:
  WordPress login cookies (only logged-in users have them) are no longer listed; visitor
  browsers no longer report local storage; logged-in users never report; an unknown cookie is
  listed only after at least two different visitors reported it; a flood limit of 50 waiting
  items; a detected service lists only the cookies it always sets (GA4: `_ga`, `_ga_*`;
  `_gid`/`_gat*` belong to Universal Analytics and conditional ones like `_fbc` are listed only
  when really seen). Upgrading removes the old unreviewed noise and re-scans.
- A scan that finds new known cookies now asks visitors again at most once, and not at all on
  the very first scan.

### Performance
- Settings are autoloaded (one fewer database query per page view).
- The admin menu no longer loads the whole cookie table on every admin page.
- Stylesheet no longer blocks the first paint (the banner waits for it, so nothing flashes
  unstyled); wildcard matching is cached; the cookie clean-up and the idle-time reporter do
  less work; "Scan now" is limited to 6 pages and shorter timeouts so it cannot hit the PHP
  time limit. See `docs/PERFORMANCE.md`.

## [0.1.0] - 2026-09-28
### Added
- Cookie banner with Accept All / Reject Non-Essential / Manage Preferences (bar, box or
  centred layouts), equal button styling, accessible preferences window.
- Four categories (Necessary, Functional, Analytics, Tracking / Advertising) with editable
  labels and descriptions.
- Blocking until consent: server-side rewriting of scripts, iframes and stylesheet/preconnect
  links; manual tagging; iframe placeholders; ordered release without page reload.
- Google Consent Mode v2 (Strict / Advanced / Off), optional GTM and GA4 loading by ID.
- Global Privacy Control support with scope setting and visitor notice.
- Consent records with consent ID, CSV export, filters, retention and IP anonymisation/hashing.
- Cookie registry with wildcards, library of 29 known services, pending review workflow.
- Cookie scanner: visitor-side detection + scheduled/manual page scan + daily email digest.
- `[sccm_cookie_policy]` and `[sccm_cookie_settings]` shortcodes, one-click policy page.
- Re-ask on cookie list change, expiry, manual version bump, optional grace period after reject.
- Settings export/import for reuse on other websites; multisite-aware install/uninstall.
- Developer hooks and JS API (`window.SCCM`, `sccm:consent` event, `dataLayer` event).
- Docs for humans and AI agents; PHP unit tests and Playwright end-to-end tests.
