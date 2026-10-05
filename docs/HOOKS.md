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
| `sccm_scan_urls` | `array $urls` | Pages fetched by the server-side scanner. |
| `sccm_scan_sslverify` | `bool $verify` | Set to `false` for staging with self-signed certificates. |
| `sccm_async_css` | `bool $async` | Return `false` to load the front-end stylesheet the usual (render-blocking) way. |

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
	);
	return $services;
} );
```

## PHP actions

| Action | Arguments | When |
|---|---|---|
| `sccm_consent_recorded` | `array $row` | After a consent record is stored. |
| `sccm_consent_version_changed` | `int $version` | After "ask everyone again" / cookie list change (purge page caches here). |
| `sccm_settings_saved` | `string $tab` | After settings are saved in the admin. |

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
