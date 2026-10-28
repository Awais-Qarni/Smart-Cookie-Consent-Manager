=== Smart Cookie Consent Manager ===
Contributors: muhammadawais
Tags: cookie consent, gdpr, cookie banner, consent mode, gpc
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie consent banner that blocks trackers until consent, with Google Consent Mode v2, GPC, consent records, a cookie scanner and cookie policy.

== Description ==

Smart Cookie Consent Manager adds a complete, self-hosted cookie consent system to any WordPress site. No account, no subscription, all data stays on your server.

* Accept All / Reject Non-Essential / Manage Preferences, with equal buttons
* Blocks scripts, iframes and fonts until the visitor allows their category
* Google Consent Mode v2 built in
* Honours Global Privacy Control (GPC)
* Consent records with CSV export and search by consent ID or IP address (IP shortened, coded, full or not saved)
* Cookie scanner with email alerts
* Automatic cookie policy list: [sccm_cookie_policy]
* Works with page caching and optimisation plugins
* Export/import settings to reuse them on other sites

== Installation ==

1. Upload the plugin and activate it.
2. Go to Compliance → Dashboard and follow "Needs your attention" (create the Cookie Policy page, scan).
3. Cookies tab → approve any cookies under "Needs review".
4. Using a page cache or CDN that the plugin does not clear by itself? Clear it once.

== Frequently Asked Questions ==

= How do visitors change their choice later? =
Use the floating button, the [sccm_cookie_settings] shortcode, or a menu link to #sccm-preferences.

= How do I run my own script only after consent? =
Use `<script type="text/plain" data-sccm-category="analytics">…</script>`.

== Changelog ==

= 1.3.2 =
* Cookies tab: the columns of the cookie list line up in every category; on phones each cookie is shown as a block with labels.

= 1.3.1 =
* Dashboard uses the full screen width and starts with the dashboard; the banner status is a card. "Needs your attention" only appears when something is to do.
* Fixed: reinstalling the plugin (or resetting its settings) asked to create the Cookie Policy page again and could create a duplicate. The existing page is now found and linked again automatically.

= 1.3.0 =
* New: the admin menu is called "Compliance" and shows every tab as a submenu (flyout on hover).
* New: Help tab with getting started, every option explained, shortcodes, developer code and answers to common problems (replaces the help panel).
* New: Dashboard with charts: visitor choices per day, acceptance rate, allowed per category, cookies by category, scan facts.
* New: "Banner version" shown and explained in Consent Records (current version, versions with their start date and reason, "older" mark, filter), in the visitor's About tab, in Tools and in the CSV export.
* The daily email about a new third-party file says so at the top and names where to find it; preconnect / dns-prefetch hints are no longer reported.
* Security hardening for consent records, cookie reports and settings import.

= 1.2.3 =
* Google Maps is now Marketing (was Preferences): the map sets Google's NID cookie, which Google also uses for advertising. Embedded maps wait until the visitor allows Marketing. Existing cookie lists are updated automatically (cookies you edited yourself stay as they are); visitors are not asked again.

= 1.2.2 =
* "Scan now" no longer lists cookies that were only in your own browser (from admin tools such as a cache plugin's dashboard, or from extensions). A cookie counts only when one of your pages sets it during the scan; the others are left to your real visitors ("Learn from visitors"). With that setting off they wait for review, marked "Only found in your browser".
* Intercom's device cookie (intercom-device-id-…) is recognised.

= 1.2.1 =
* Fixed: "Scan now" stopped with "The scan could not finish: HTTP 400" on hosts with an object cache (WP Engine, Redis, Memcached). The scan now keeps its data in the database, so clearing any cache never breaks it.
* The plugin no longer empties the host's object cache (WP Engine Memcached, W3 Total Cache object cache) when it clears page caches; during "Scan now" the page caches are cleared once, when the scan ends.
* "Saved" and "Scan finished" messages are no longer lost on hosts with an object cache.
* A failed scan now says why (expired login, firewall, server error) instead of only the HTTP status, and short host hiccups are retried.

= 1.2.0 =
* Fixed: the "Provider name for your own cookies" text was not shown (the cookie list kept "This website").
* Cookie durations shown to visitors are the real ones measured by the scan (not typical values); the consent cookie shows the "Ask again after" period; local storage says "No expiry date" instead of "Persistent".
* "Learn from visitors": the number of different visitors needed (default 2) and the number of days (default 14) can be changed in the settings.
* Consent records: shortened IP addresses show the hidden part as *** instead of 0; new option to save the full IP address; search records by IP address.
* Fixed: the Consent Records list stayed empty on MySQL 8 hosts (e.g. WP Engine) although records were saved.
* Security: fake cookie reports cannot add services or re-show the banner; text is trimmed by characters (no lost records with accents or emoji).
* Tested on MySQL 8.4 (strict mode) and MariaDB 11.4.
* Consent records are saved on password-protected (staging) sites, from cached pages on another domain, and when the REST API is blocked (admin-ajax fallback); the Consent Records tab explains problems and has a "Test record saving" button.
* Scans adapt to the size of the website: small sites completely, bigger ones 40–80 pages (main pages, then sub and sub-sub pages of every section), in short steps that never hit time limits.
* Compact top/bottom banner: buttons stacked vertically.
* Scan now keeps going in a background tab, and continues where it stopped after leaving the page.
* Admin: Help button in the top right corner; fixed padding in fold-out settings panels; removed a non-working "Add rule" button from the scan report.
* Security and quality: CSV export safe for spreadsheets, admin messages not taken from the URL, fully prepared queries, scans respect the host's PHP time limit; requires WordPress 6.2.
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
