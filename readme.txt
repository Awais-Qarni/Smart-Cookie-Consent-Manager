=== Smart Cookie Consent Manager ===
Contributors: muhammadawais
Tags: cookie consent, gdpr, cookie banner, consent mode, gpc
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plug-and-play cookie consent: banner, blocking until consent, Google Consent Mode v2, GPC, consent records, cookie scanner and automatic cookie policy.

== Description ==

Smart Cookie Consent Manager adds a complete, self-hosted cookie consent system to any WordPress site. No account, no subscription, all data stays on your server.

* Accept All / Reject Non-Essential / Manage Preferences, with equal buttons
* Blocks scripts, iframes and fonts until the visitor allows their category
* Google Consent Mode v2 built in
* Honours Global Privacy Control (GPC)
* Consent records with CSV export
* Cookie scanner with email alerts
* Automatic cookie policy list: [sccm_cookie_policy]
* Works with page caching and optimisation plugins
* Export/import settings to reuse them on other sites

== Installation ==

1. Upload the plugin and activate it.
2. Go to Cookie Consent → Dashboard and follow "Needs your attention" (create the Cookie Policy page, scan).
3. Cookies tab → approve any cookies under "Needs review".
4. Using a page cache or CDN that the plugin does not clear by itself? Clear it once.

== Frequently Asked Questions ==

= How do visitors change their choice later? =
Use the floating button, the [sccm_cookie_settings] shortcode, or a menu link to #sccm-preferences.

= How do I run my own script only after consent? =
Use `<script type="text/plain" data-sccm-category="analytics">…</script>`.

== Changelog ==

= 1.2.0 =
* Scans adapt to the size of the website: small sites completely, bigger ones 40–80 pages (main pages, then sub and sub-sub pages of every section), in short steps that never hit time limits.
* Compact top/bottom banner: buttons stacked vertically.
* One email a day at a time you choose, only when something changed in the last 24 hours, to the site admin and the listed addresses; no admin links in the email; delivery status shown in Settings.
* Button order: four choices, incl. Allow all · Deny · Allow selection (new default) and Deny · Allow all · Allow selection.
* Banner looks the same on every theme (theme button/link styles no longer leak in); button texts never wrap.
* Banner preview looks like a first visit; the redundant "Show details" link is gone.
* Cookie Policy page follows the theme (readable on dark themes), hides the sidebar, and refreshes after every scan.
* Page caches of WP Engine, NitroPack, WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround, Breeze and Hummingbird are cleared automatically when settings or the cookie list change.
* Alert email redesigned for dark mode and Outlook.
* Warning when another cookie consent plugin is active; AMP pages and more page-builder editors are left alone.
* Translation template (.pot) included.
* Simpler admin: 6 tabs, a Dashboard with a to-do list, and a Help panel explaining everything.
* Categories renamed to Necessary, Preferences, Statistics, Marketing.
* Cookiebot-style dialog: Consent / Details / About tabs, equal Allow all / Allow selection / Deny buttons.
* Compact banner style (default): Allow all, Deny and Customize; the tabbed banner is a setting.
* Cache-proof asset files (works with NitroPack and CDNs that ignore ?ver=).
* More services recognised: NitroPack, GTranslate, PHP session, Tidio, LiveChat, Klaviyo, Snapchat, Reddit, WPML.
* Scan now also scans in your browser, so cookies set by scripts and embedded services are found.
* Consent ID/date shown to visitors, your choice of consent expiry, button order setting.
* New cookie settings button positions (bottom centre, left/right edge as a "Cookies" tab).
* Much shorter, more accurate cookie list; readable HTML alert emails to several recipients.

= 0.1.0 =
* First version.
