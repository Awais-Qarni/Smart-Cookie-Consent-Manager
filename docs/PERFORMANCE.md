# Performance

The plugin must not make a website slow. This page records what it costs, how that was
measured, and what was changed. Numbers were measured on a development WordPress 6.5 / PHP 8.3 /
MariaDB 10.11 (local, no page cache). They show relative cost; your server will differ.

## What a visitor's page view pays

| Part | Cost | Notes |
|---|---|---|
| PHP: build config + JSON | **0.7–0.8 ms**, 1–2 DB queries | One query reads the cookie table; the settings are autoloaded with the other options. |
| PHP: HTML blocker (output buffer) | **about 1.2 ms** for a 434 KB page | Three regex passes over the page; measured with the real service patterns. |
| Whole request, plugin on vs off | 48.1 ms vs 47.6 ms | Difference is within measurement noise. |
| Inline in `<head>` (boot script + config) | ≈ 8.5–9.5 KB raw, **≈ 3.6 KB gzipped** | Grows about 120 bytes per listed cookie. |
| `sccm-frontend.js` (deferred) | 35.5 KB raw, **10.5 KB gzipped** | v0.1.0: 23.7 / 7.0 KB. It now draws the compact banner, the tabbed dialog (Consent / Details / About), the edge tabs and supports the browser scan. No jQuery, loaded with `defer`. The Details tab (every cookie card) is built only when a visitor opens it. |
| `sccm-boot.js` (inline) | 4.0 KB raw, **1.8 KB gzipped** | v0.1.0: 3.1 / 1.5 KB. |
| `sccm-frontend.css` | 14.4 KB raw, **3.6 KB gzipped** | v0.1.0: 9.3 / 2.4 KB. No longer blocks the first paint (see below). |
| REST calls | none on a normal page view | One POST when the visitor chooses; one low-priority POST at most once per browser session, only if there are unknown cookies, never for logged-in users. |

Everything is the same for every visitor, so full-page caches and CDNs serve it as-is.

## Changes made in this round

0. **Assets are served from hashed copies** (`SCCM_Plugin::asset()` →
   `uploads/sccm-assets/sccm-frontend.<hash>.js`), so hosts, CDNs and cache plugins that ignore
   `?ver=` (NitroPack, some WP Engine setups) never serve an old stylesheet or script after an
   update. The copy is made once per version; a normal page view only checks that it exists.

1. **Stylesheet no longer blocks rendering.** It loads with `media="print" onload=…` (plus a
   `<noscript>` fallback). The script waits for it before drawing anything, so there is no
   unstyled flash. Pages that contain the `[sccm_cookie_policy]` table keep the normal
   stylesheet because that markup is visible immediately. Turn it off with
   `add_filter( 'sccm_async_css', '__return_false' )`.
2. **Settings are autoloaded** (they were stored without autoload, so every front-end request
   paid one extra query). Existing sites are converted by the upgrade routine.
3. **Admin menu badge**: it loaded and sorted the entire cookie table on every wp-admin page.
   It is now a single indexed `COUNT(*)`, and only when needed.
4. **Cookie clean-up** (runs on every page view of visitors who refused something) uses one
   compact list grouped by category, compiles each wildcard pattern once, and exits at once
   when nothing is refused. The `known` list sent to the browser is plain names, cookies only.
5. **Idle-time reporting**: the visitor-side scanner runs in `requestIdleCallback`, only once
   per session for the same set of names, and not at all for logged-in users.
6. **Scans in steps**: a scan plans 1–80 pages (see ARCHITECTURE → Scan plan) and the server
   part works in steps of about 20 seconds (`SCCM_Scanner::step()`): "Scan now" asks for one
   step per AJAX request, scheduled scans continue in follow-up cron runs. No request runs long,
   whatever the size of the website (tested: 60 pages on a slow host = 4 cron runs of ≤ 21 s).
7. **Fewer writes**: a known cookie reported by a browser no longer writes `last_seen` to the
   database; flooding is capped (50 waiting items, 100 candidates).

## The browser scan

"Scan now" opens the planned pages (up to 80) in hidden frames in the administrator's browser,
three at a time (each 3–10 s: it waits until the page stops loading files); 60 pages took
about 1 min 45 s in the test. This costs
nothing for visitors: it only happens in the admin's browser, the scan-mode pages are never
cached, and the scheduled (cron) scan stays server-only.

## Tried and reverted

- **Pre-check before rewriting the HTML** (one big regex of all blocking patterns, to skip
  pages that contain none). Measured **6.8 ms** per page against **1.55 ms** for the existing
  three-pass rewrite, so it was slower, and was removed. The blocker stays as it was.

## Where the remaining cost is, honestly

- The JS grew by about 2 KB gzipped for the new features. There is no build step by design
  (no minifier), so the file is shipped as written; gzip on your server/CDN is important.
- The blocker runs on every uncached page view. With a page cache it runs once per cached
  page, not per visitor.
- A very long cookie list makes the inline config grow (about 120 bytes per cookie). Typical
  sites list 10–20 cookies.

## How to re-measure

```bash
php tests/bench-blocker.php            # the HTML blocker on a synthetic 430 KB page
wp eval 'echo strlen( wp_json_encode( SCCM_Frontend::config() ) );'
curl -s https://your-site/ | wc -c      # compare with the plugin deactivated
```
