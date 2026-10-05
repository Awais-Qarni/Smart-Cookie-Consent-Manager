# Changelog

All notable changes are listed here. Format: [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]
### Changed
- **New default banner style "Compact"**: title, text, policy links and three buttons
  (**Allow all**, **Deny**, **Customize**). Customize opens the full Consent / Details / About
  window with the category switches and **Allow selection**; closing it without choosing brings
  the banner back. The tabbed banner is still available under Banner → **Banner style →
  Detailed**. Allow all and Deny always look the same.
- **More services recognised**: PHP session (`PHPSESSID`), NitroPack, GTranslate (including the
  `googtrans` language cookie when it is really set), Tidio, LiveChat, Klaviyo, Snapchat Pixel,
  Reddit Pixel and WPML. A cookie can now have its own category inside a service (e.g.
  GTranslate's auto-switch key is Preferences while the widget itself is Necessary).
- **Scan covers more pages**: the pages in your menus are scanned first (that is where contact
  forms, booking widgets and maps usually are); "Scan now" opens up to 10 pages in your browser.
- The Cookies tab shows what the browser part of the last scan did (pages opened, names seen),
  or a warning when it could not run, so a short list can be explained.
- The Help button sits next to the page title.
- **Admin simplified: 9 tabs became 6** (Dashboard, Cookies, Banner, Settings, Consent Records,
  Tools). The Dashboard shows whether the banner is live, a plain "Needs your attention" to-do
  list, three key numbers and a how-it-works strip. Old tab links keep working.
- New **Help** button on every admin page: how it works, and every tab, button and option
  explained in plain language.
- **Categories renamed** to Cookiebot's names: Necessary, Preferences, Statistics, Marketing
  (keys and Google Consent Mode mapping are unchanged; custom names you saved are kept).
- **New consent dialog modelled on Cookiebot**: three tabs (**Consent**, **Details**, **About**)
  and three equal buttons (**Allow all**, **Allow selection**, **Deny**). Consent shows a switch
  per category across the full width (2 × 2 in corner boxes and on phones). Details is an
  accordion per category → per provider → a card per cookie (name, purpose, maximum storage
  duration, type). About explains cookies and shows the visitor's choice, date and consent ID
  (with Copy). The same dialog opens again from the cookie settings button.
- **Button order** setting: Allow all first (default) or Deny first. All buttons always look the
  same.
- **Consent expiry is your choice**: presets (until the browser closes, 1 week, 1/3/6/12/13
  months) or any number of days up to 395 (browsers do not keep a cookie longer).
- Cookie settings button has two more positions, **bottom centre** and **left/right edge**,
  shown as a slim tab with the word "Cookies" attached to the screen edge (corners keep a round
  icon). The consent ID is also in the `dataLayer` event and `SCCM.getConsentId()`.
- **"Scan now" also scans in your browser**: the pages open in a hidden frame in the admin's own
  browser with everything allowed, so cookies set by scripts (Google Analytics, HubSpot, chat
  widgets…) and third-party cookies of embedded services (YouTube, Calendly, Meta, LinkedIn…)
  are found, like Cookiebot's crawler. The service library now knows those third-party cookies
  and Calendly, Stripe, Elementor and WooCommerce order attribution.
- "Remember the choice for": the number of days only appears for "Another number of days".
- Scanner alert email redesigned (HTML, clear sections, one button, plain-text version) and
  can go to **several recipients**; "Send me a sample email" button.
- Cookie list is shown **grouped by category**; unknown cookies wait in a "Needs review" inbox
  where a category must be chosen before Approve; "Ignore all" button.

### Fixed
- **Logged-in and private windows showed different banners**: page-cache/CDN plugins (e.g.
  NitroPack) and some hosts serve plugin files from their own cache and ignore the `?ver=`
  number, so logged-out visitors kept an old script. Front-end and admin CSS/JS are now served
  from copies with the content hash in the file name (`wp-content/uploads/sccm-assets/`), so
  every update gets a new address. Turn off with the `sccm_versioned_asset_files` filter.
- **Admin screens looked broken / Help and expiry did nothing after updating**: the plugin's CSS
  and JS kept the same version number, so browsers and hosts (e.g. WP Engine) served the old
  cached files. Every asset is now versioned by its file time.
- **Scan found only 2–3 cookies** (e.g. `__cf_bm`, `sccm_consent`): it only read the server's
  `Set-Cookie` headers. Cookies set by JavaScript and third-party cookies are now found by the
  browser scan (above).
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
  time limit. The cookie details are only built when a visitor opens the Details tab.
  See `docs/PERFORMANCE.md`.

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
