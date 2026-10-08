# Smart Cookie Consent Manager

A plug-and-play cookie consent plugin for WordPress. Activate it and your site gets a
compliant cookie banner, blocking of tracking until consent, consent records and a cookie
scanner, with no coding and no subscription. All data stays on your own server.

**Author:** Muhammad Awais, WordPress Developer · **License:** GPL-2.0-or-later ·
**Requires:** WordPress 6.2+, PHP 7.4+

---

## Features

**What visitors see**
1. **Accept All**: one button, everything allowed.
2. **Reject Non-Essential**: one button with the same size and style as Accept.
3. **Manage Preferences**: a compact banner (Allow all, Deny, Customize) by default, or the
   detailed tabbed banner; "Customize" opens one dialog with Consent, Details and About tabs (like Cookiebot):
   a switch per category (Necessary, Preferences, Statistics, Marketing), every cookie grouped by
   category and provider, and the visitor's own choice, date and consent ID.
4. **Change or withdraw anytime**: a cookie settings button in five positions (a round icon in a
   corner, or a "Cookies" tab on the bottom or side edge), the `[sccm_cookie_settings]`
   shortcode, or any link to `#sccm-preferences` (e.g. a menu item).
5. **Fair buttons**: nothing is pre-ticked, and closing or ignoring the banner is never consent.
6. **Remember and re-ask**: the choice is remembered for the time you choose (12 months by
   default; from "until the browser closes" to 13 months); the banner asks again when the
   cookie list changes, after that time, or when you choose to.

**Behind the scenes**

7. **Block until consent**: scripts, iframes (YouTube, Maps…) and fonts are blocked in the page
   HTML until their category is allowed. Google Consent Mode v2 is built in.
8. **Consent records**: each choice is stored with a consent ID, date/time, categories, GPC flag,
   version and page. The IP is shortened (203.0.113.***) by default, or saved as a one-way
   code, in full, or not at all. Search by consent ID or IP address, filter and export to CSV.
9. **Cookie scanner**: scans your pages on the server and in your own browser (so cookies set by
   scripts and by embedded services are found, like Cookiebot's crawler). Small websites are
   scanned completely, bigger ones 40–80 pages (main pages, then sub pages of every section).
   Known services are sorted into the right category automatically; unknown cookies wait for
   your approval. Visitors' browsers can also report cookies set by JavaScript (names only); a
   cookie is listed after enough different visitors reported it (default 2 within 14 days,
   both adjustable). Once a day, at a time you choose, an email reports what changed, only if
   something did.
10. **Cookie list for visitors**: shown in the banner's Details tab and on your Cookie Policy page
    via `[sccm_cookie_policy]`, and updated automatically.
11. **Global Privacy Control (GPC)**: honours the browser privacy signal and records it.
12. **Works with any setup**: cache-safe, works with optimisation plugins and page translators,
    and you can export and import settings for your other websites.

## Installation

1. Download the plugin zip (GitHub → Code → Download ZIP) or clone this repo.
2. WordPress → Plugins → Add New → Upload Plugin → choose the zip → Activate.
   *(Tip: rename the folder to `smart-cookie-consent-manager` for clean updates.)*
3. Go to **Cookie Consent** in the admin menu. The **Dashboard** tells you what to do:
   - "Create the page" makes your Cookie Policy page.
   - "Scan now", then approve any cookies under **Needs review** in the **Cookies** tab.
   - **Banner**: pick the position, colours and wording.
   - The **Help: how it works** button explains every tab and option.
4. The page caches of WP Engine, NitroPack, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze and Hummingbird are cleared for you; with another cache or a CDN, clear it once.

That's it: known trackers (Google Analytics, Tag Manager, Google Ads, Meta Pixel, LinkedIn,
TikTok, Hotjar, Clarity, HubSpot, YouTube, Vimeo, Google Maps, Google Fonts and more) are
blocked automatically until consent.

## Usage

| What | How |
|---|---|
| Cookie list on a page | `[sccm_cookie_policy]` |
| "Cookie settings" link or button | `[sccm_cookie_settings text="Cookie settings" style="link"]` (or `style="button"`) |
| Menu item that opens the cookie settings | Custom link with URL `#sccm-preferences` |
| Run your own script only after consent | `<script type="text/plain" data-sccm-category="analytics">…</script>` |
| Block another third-party service | Settings → Blocking and Google → Your own blocking rules |
| Same settings on another website | Tools → Export settings, then Import on the other site |
| Daily change email (time, recipients) | Settings → Cookie scan and email alerts (one address per line; the site admin gets it too) |
| Preview the banner | Visit `https://your-site/#sccm-banner` |

Developers: see [`docs/HOOKS.md`](docs/HOOKS.md) for PHP filters/actions and the JavaScript API.

## Compatibility notes

- **Page caching:** fully supported (the banner is rendered in the browser). The plugin clears
  the page caches of WP Engine, NitroPack, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze and Hummingbird when settings or the cookie list change; clear any other cache or
  CDN by hand (or from the `sccm_cache_purged` action).
- **Themes and page builders:** the banner's styles are scoped to `#sccm-app`, so theme button
  and link styles do not change it. The Cookie Policy list uses the theme's own look.
- **Other consent plugins:** run only one. The Dashboard warns when Cookiebot, Complianz,
  CookieYes, Borlabs, Real Cookie Banner, iubenda, Cookie Notice or similar is still active.
- **AMP:** AMP pages are left alone (AMP has its own consent component).
- **Optimisation plugins** (script delay/combine/minify): the plugin's own scripts carry the
  usual exclusion attributes. If an optimiser still delays them, exclude `sccm-` and
  `data-sccm-` in its settings.
- **Fonts inside CSS:** fonts loaded from *inside* a stylesheet (`@import`) cannot be blocked by
  tag rewriting. The scanner warns you; host those fonts on your own server.
- **Page builders:** blocking is switched off inside editor previews (Elementor, Beaver, Divi,
  Oxygen, Bricks…).

## For developers and AI agents

Start with [`AGENTS.md`](AGENTS.md), then [`docs/PROGRESS.md`](docs/PROGRESS.md).

```bash
npm install          # dev tools only
npm test             # PHP/JS lint + PHP unit tests
npm run e2e          # visitor-side browser tests (needs a running WordPress, see tests/e2e/README.md)
npm run test:wp      # cookie list, scan plan, settings, email, records checks via WP-CLI (dev site only)
npm run e2e:admin    # admin screens incl. a real browser scan (dev site only)
```

| Doc | Content |
|---|---|
| [docs/FEATURES.md](docs/FEATURES.md) | The 12 features and their acceptance criteria |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | How it works and why |
| [docs/ROADMAP.md](docs/ROADMAP.md) | What is done and what is next |
| [docs/TESTING.md](docs/TESTING.md) | Automated checks and staging test checklist |
| [docs/HOOKS.md](docs/HOOKS.md) | Filters, actions, JS API |
| [docs/PERFORMANCE.md](docs/PERFORMANCE.md) | What the plugin costs, measured, and what was tuned |

> This plugin helps you meet consent requirements, but it is not legal advice. Have your
> compliance team review the banner wording and categories.
