# Performance

The plugin must not make a website slow. This page records what it costs, how that was
measured, and what was changed. Numbers were measured on a development WordPress 6.5 / PHP 8.3 /
MariaDB 10.11 (local, no page cache). They show relative cost; your server will differ.

## What a visitor's page view pays

| Part | Cost | Notes |
|---|---|---|
| PHP: build config + JSON | **0.8 ms**, 1–2 DB queries | One query reads the cookie table; the settings are autoloaded with the other options. |
| PHP: HTML blocker (output buffer) | **about 1.2 ms** for a 434 KB page | Three regex passes over the page; measured with the real service patterns. |
| Whole request, plugin on vs off | 48.1 ms vs 47.6 ms | Difference is within measurement noise. |
| Inline in `<head>` (boot script + config) | 9.5 KB raw, **3.7 KB gzipped** | Was 10.2 KB raw / 3.6 KB gzipped before; the new visitor texts offset the savings. |
| `sccm-frontend.js` (deferred) | 30.3 KB raw, **8.9 KB gzipped** | Was 23.7 / 7.0 KB: it now also draws the category switches, the consent status and the edge widget. No jQuery, loaded with `defer`. |
| `sccm-frontend.css` | 12.5 KB raw, **3.2 KB gzipped** | Was 9.3 / 2.5 KB. No longer blocks the first paint (see below). |
| REST calls | none on a normal page view | One POST when the visitor chooses; one low-priority POST at most once per browser session, only if there are unknown cookies, never for logged-in users. |

Everything is the same for every visitor, so full-page caches and CDNs serve it as-is.

## Changes made in this round

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
6. **Scan limits**: "Scan now" fetches at most 6 pages with 10-second timeouts (it used to be
   up to 15 pages × 20 s inside one admin request, which could hit the PHP time limit).
   Scheduled scans keep the larger limits and run in the background (cron).
7. **Fewer writes**: a known cookie reported by a browser no longer writes `last_seen` to the
   database; flooding is capped (50 waiting items, 100 candidates).

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
