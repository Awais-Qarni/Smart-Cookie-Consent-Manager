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

Quick options for a throw-away local site:
- WordPress + the official SQLite Database Integration drop-in, or
- WordPress from Composer (`composer create-project johnpbloch/wordpress-core wp`) + MariaDB +
  WP-CLI (`composer require wp-cli/wp-cli-bundle`), served with
  `PHP_CLI_SERVER_WORKERS=6 php -d realpath_cache_ttl=0 -S 127.0.0.1:8080 -t wp`.
  (Several workers: the admin loads many files at once. `realpath_cache_ttl=0`: PHP otherwise
  keeps following an old symlink target when you switch the plugin folder.)
  Add `define( 'WP_HTTP_BLOCK_EXTERNAL', true );` so WordPress does not wait on wordpress.org.

## 2. Run

```bash
npm install
SCCM_E2E_URL=http://127.0.0.1:8080 npm run e2e
```

Third-party requests are intercepted and answered locally, so no internet is needed. Results
and screenshots go to `tests/e2e/output/` (git-ignored).

## 3. More checks

| Command | What it covers |
|---|---|
| `npm run e2e` | Visitor side: compact banner + Customize, dialog tabs, switches, details, button order, widget, expiry, scan-mode safety, reporting (76 checks). |
| `SCCM_WP=/path/to/wp-cli-wrapper npm run test:wp` | Cookie list rules, browser-scan rules, settings, email recipients and design, categories, library details, hashed asset files (WP-CLI, 56 checks). |
| `SCCM_WP=… SCCM_E2E_URL=… npm run e2e:admin` | Admin screens as `admin`/`admin`: approve/ignore, expiry, recipients, sample email, positions, button order, banner style, a real browser scan, CSRF (35 checks). |
| `node tests/e2e/admin-shots.mjs <dir>` | Screenshots of every admin tab, for visual review. |

The last three reset the plugin settings and cookie list of the site they run on: use a
development site only.
