# Hooks & JavaScript API

## PHP filters

| Filter | Arguments | Use |
|---|---|---|
| `sccm_enabled` | `bool $active` | Turn the whole consent system off for a request. |
| `sccm_blocker_enabled` | `bool $enabled` | Turn only the HTML blocker off for a request. |
| `sccm_services` | `array $services` | Add/change entries of the service library (see `includes/data/services.php` for the format). |
| `sccm_blocking_rules` | `array $rules` | Final list of `array( pattern, category, service )` used by the blocker. |
| `sccm_categories` | `array $categories` | Resolved categories (labels, descriptions, enabled). |
| `sccm_consent_mode_map` | `array $map` | Category → Google Consent Mode types. |
| `sccm_texts` | `array $texts` | Visitor-facing texts (e.g. for multilingual plugins). |
| `sccm_frontend_config` | `array $config` | Everything passed to the browser as `window.SCCM_CONFIG`. |
| `sccm_client_ip` | `string $ip` | Client IP (e.g. read a trusted proxy header). |
| `sccm_scan_urls` | `array $urls, int $budget` | Pages a scan visits, in order (server part and "Scan now" in the browser). At most 100 are used. |
| `sccm_scan_budget` | `int $budget, int $total` | How many pages a scan visits (default: all up to 40 pages, then 40 + 2·√(pages − 40), at most 80). |
| `sccm_scan_sslverify` | `bool $verify` | Set to `false` for staging with self-signed certificates. |
| `sccm_async_css` | `bool $async` | Return `false` to load the front-end stylesheet the usual (render-blocking) way. |
| `sccm_versioned_asset_files` | `bool $copy` | Return `false` to serve CSS/JS from the plugin folder with `?ver=` instead of hashed copies in `uploads/sccm-assets/`. |
| `sccm_purge_page_cache` | `bool $purge` | Return `false` to stop the plugin from clearing page caches when settings or the cookie list change. |
| `sccm_other_consent_plugins` | `string[] $names` | Other consent plugins detected as active (Dashboard warning). Return `array()` to hide the warning. |

Example: add a service.

```php
add_filter( 'sccm_services', function ( $services ) {
	$services['my-chat'] = array(
		'name'     => 'My Chat Widget',
		'provider' => 'My Chat Inc.',
		'category' => 'functional',
		'patterns' => array( 'widget.mychat.example' ),
		'cookies'  => array( array( 'mychat_id', '1 year', 'Identifies the chat visitor.' ) ),
		// A 5th value `true` after the type marks a cookie that is only set in special cases:
		// array( 'mychat_vip', '1 year', 'Set for returning VIPs.', 'cookie', true ).
		// A 6th value gives one cookie its own category:
		// array( 'mychat_lang', '1 year', 'Remembers the language.', 'cookie', false, 'functional' ).
	);
	return $services;
} );
```

## PHP actions

| Action | Arguments | When |
|---|---|---|
| `sccm_consent_recorded` | `array $row` | After a consent record is stored. |
| `sccm_consent_version_changed` | `int $version` | After "ask everyone again" / cookie list change. |
| `sccm_settings_saved` | `string $tab` | After settings are saved in the admin. |
| `sccm_cookie_list_changed` | — | A cookie visitors can see was added, edited or removed (not fired for cookies waiting for review). |
| `sccm_cache_purged` | — | After the plugin cleared page caches (see `SCCM_Cache`). Clear any other cache (e.g. a CDN) here. |

The plugin clears the page caches of WP Engine, NitroPack, WP Rocket, LiteSpeed Cache, W3 Total
Cache, WP Super Cache, WP Fastest Cache, SiteGround Speed Optimizer, Breeze and Hummingbird after
`sccm_settings_saved`, `sccm_consent_version_changed` and `sccm_cookie_list_changed` (once per
request; clears not started by an administrator at most every 5 minutes).

## Endpoints the banner uses

| Endpoint | Fallback (REST API blocked) | Data |
|---|---|---|
| `POST /wp-json/sccm/v1/consent` | `admin-ajax.php`, action `sccm_consent`, field `payload` (JSON) | Consent record: consent_id, choice, categories, gpc, version, url |
| `POST /wp-json/sccm/v1/report` | `admin-ajax.php`, action `sccm_report`, field `payload` (JSON) | Unknown cookie names (visitor scanner) |

Both are public (pages are cached, so no nonces), validated and rate-limited per IP. The
browser sends with same-origin credentials to a same-site address.

## CSS

The banner (`#sccm-banner`), the cookie settings window (`#sccm-prefs`) and the widget
(`.sccm-floating`) are inside `<div id="sccm-app">` at the end of `<body>`, and the plugin's rules
start with `#sccm-app`, so theme styles for `button`, `a`, `p`… do not change them. To restyle:

```css
.sccm-root { --sccm-radius: 0; --sccm-btn-bg: #111; }   /* variables: easiest */
#sccm-app .sccm-btn { text-transform: uppercase; }       /* or the #sccm-app prefix */
```

The Cookie Policy list (`.sccm-policy`, body class `sccm-policy-page`) is part of the page and
takes its fonts and colours from the theme.

## JavaScript API

```js
window.SCCM.hasConsent('analytics');   // true | false
window.SCCM.getConsent();              // { id, v, t, c: {functional, analytics, marketing}, m, g } | null
window.SCCM.getConsentId();            // the visitor's consent ID, or null before a choice
window.SCCM.openPreferences();          // optional tab: openPreferences('details') or 'about'
window.SCCM.showBanner();
window.SCCM.acceptAll();
window.SCCM.rejectAll();
window.SCCM.setConsent({ analytics: true, marketing: false, functional: true });

document.addEventListener('sccm:ready', (e) => console.log(e.detail.grants));
document.addEventListener('sccm:consent', (e) => console.log(e.detail.method, e.detail.grants));
```

`dataLayer` event for Google Tag Manager triggers: `sccm_consent_update` with
`sccm_categories` (array of allowed categories), `sccm_method` and `sccm_consent_id`.

## HTML attributes

| Markup | Effect |
|---|---|
| `<script type="text/plain" data-sccm-category="analytics">` | Runs only after Analytics consent. Several categories: `data-sccm-category="analytics marketing"`. |
| `<script type="text/plain" data-sccm-category="marketing" data-sccm-src="https://…">` | External script loaded after consent. |
| `<iframe data-sccm-category="marketing" data-sccm-src="https://…">` | Frame loaded after consent (placeholder shown before). |
| `data-sccm-skip` on any tag | The blocker leaves this tag alone. |
| `class="sccm-open-preferences"`, `data-sccm-open`, or `href="#sccm-preferences"` | Click opens the cookie settings dialog. |
