# End-to-end tests

`run.mjs` drives Chromium with Playwright through all 12 features against a real WordPress.

## 1. A WordPress to test against

Any local/staging WordPress works:

1. Install and activate this plugin.
2. Copy `tests/e2e/fixture-trackers.php` to `wp-content/mu-plugins/`. It adds fake Google
   Analytics, Meta Pixel, YouTube, Google Fonts, an unknown cookie, a manual-tagged script and
   settings links to every page. **Never put the fixture on a live site.**
3. Create a published page with slug `cookie-policy` containing `[sccm_cookie_policy]` and
   select it in Cookie Consent → General.

Quick option without MySQL (as used during development): WordPress + the official SQLite
Database Integration drop-in, served with `php -S 127.0.0.1:8080`.

## 2. Run

```bash
npm install
SCCM_E2E_URL=http://127.0.0.1:8080 npm run e2e
```

Third-party requests are intercepted and answered locally, so no internet is needed. Results
and screenshots go to `tests/e2e/output/` (git-ignored).
