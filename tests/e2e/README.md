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
| `npm run e2e` | Visitor side: compact banner + Customize, dialog tabs, switches, details, the four button orders, first-visit preview, theme isolation (hostile theme CSS), one-line buttons, Cookie Policy on a dark theme, widget, expiry, scan-mode safety, reporting (99 checks). |
| `SCCM_WP=/path/to/wp-cli-wrapper npm run test:wp` | Cookie list rules, browser-scan rules, settings, button orders, email recipients and design (dark mode, Outlook button), categories, library details, hashed asset files, Cookie Policy sidebar, cache clearing and throttling, other-CMP detection (WP-CLI, 100 checks; also scan-report emails, batching and delivery status). |
| `SCCM_WP=… SCCM_E2E_URL=… npm run e2e:admin` | Admin screens as `admin`/`admin`: approve/ignore, expiry, recipients, sample email, positions, button order, banner style, a real browser scan and its report email, CSRF (39 checks). |
| `node tests/e2e/admin-shots.mjs <dir>` | Screenshots of every admin tab, for visual review. |

The last three reset the plugin settings and cookie list of the site they run on: use a
development site only. (The Cookie Policy page link is kept.)

## Windows

- Run the npm scripts with Git Bash as the script shell:
  `npm_config_script_shell="C:\\Program Files\\Git\\bin\\bash.exe"` (adjust the path).
- `SCCM_WP` can point straight at `wp-cli.phar` (it is run with `php`; set `SCCM_PHP` for
  another PHP binary). Give WP-CLI the WordPress path with a `wp-cli.yml` and
  `WP_CLI_CONFIG_PATH`.
- PHP's built-in server is single-threaded on Windows (`PHP_CLI_SERVER_WORKERS` is Unix-only)
  and Chromium's extra connections stall it. Put a small proxy in front that spreads requests
  over several `php -S` processes, or use LocalWP / XAMPP.
