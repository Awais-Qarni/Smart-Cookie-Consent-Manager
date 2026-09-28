# Changelog

All notable changes are listed here. Format: [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

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
