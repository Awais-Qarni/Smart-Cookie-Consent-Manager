<?php
/**
 * Library of well-known services (data only).
 *
 * Each service:
 *  - name, provider, category (necessary|functional|analytics|marketing)
 *  - google:   true if the service honours Google Consent Mode (skipped by the blocker in Advanced mode)
 *  - patterns: substrings matched against script src / iframe src / link href / inline script code
 *  - cookies:  list of array( name (supports * wildcard), duration, purpose, type = cookie|localStorage )
 *
 * Add services with the `sccm_services` filter. Keep this file generic: no site-specific entries.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

return array(

	/* ---------------------------------------------------------------- Necessary */

	'sccm'               => array(
		'name'     => 'Smart Cookie Consent Manager',
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'necessary',
		'patterns' => array(),
		'cookies'  => array(
			array( 'sccm_consent', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Stores your cookie consent choice.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'wordpress'          => array(
		'name'     => 'WordPress',
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'necessary',
		'patterns' => array(),
		'cookies'  => array(
			array( 'wordpress_logged_in_*', __( 'Session / 14 days', 'smart-cookie-consent-manager' ), __( 'Keeps logged-in users signed in.', 'smart-cookie-consent-manager' ) ),
			array( 'wordpress_sec_*', __( 'Session / 14 days', 'smart-cookie-consent-manager' ), __( 'Secures the login session.', 'smart-cookie-consent-manager' ) ),
			array( 'wordpress_test_cookie', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Checks whether the browser accepts cookies.', 'smart-cookie-consent-manager' ) ),
			array( 'wp-settings-*', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Stores dashboard preferences of logged-in users.', 'smart-cookie-consent-manager' ) ),
			array( 'wp_lang', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Remembers the language chosen on the login screen.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'woocommerce'        => array(
		'name'     => 'WooCommerce',
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'necessary',
		'patterns' => array(),
		'cookies'  => array(
			array( 'woocommerce_cart_hash', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Tracks changes to the shopping cart.', 'smart-cookie-consent-manager' ) ),
			array( 'woocommerce_items_in_cart', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Knows whether the cart has items.', 'smart-cookie-consent-manager' ) ),
			array( 'wp_woocommerce_session_*', __( '2 days', 'smart-cookie-consent-manager' ), __( 'Keeps the shopping session.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'cloudflare'         => array(
		'name'     => 'Cloudflare',
		'provider' => 'Cloudflare, Inc.',
		'category' => 'necessary',
		'patterns' => array(),
		'cookies'  => array(
			array( '__cf_bm', __( '30 minutes', 'smart-cookie-consent-manager' ), __( 'Bot protection.', 'smart-cookie-consent-manager' ) ),
			array( 'cf_clearance', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Proves a security challenge was passed.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'recaptcha'          => array(
		'name'     => 'Google reCAPTCHA',
		'provider' => 'Google LLC',
		'category' => 'necessary',
		'patterns' => array(),
		'cookies'  => array(
			array( '_GRECAPTCHA', __( '6 months', 'smart-cookie-consent-manager' ), __( 'Protects forms against spam and abuse.', 'smart-cookie-consent-manager' ) ),
		),
	),

	/* ---------------------------------------------------------------- Functional */

	'google-fonts'       => array(
		'name'     => 'Google Fonts',
		'provider' => 'Google LLC',
		'category' => 'functional',
		'patterns' => array( 'fonts.googleapis.com', 'fonts.gstatic.com' ),
		'cookies'  => array(),
	),
	'google-maps'        => array(
		'name'     => 'Google Maps',
		'provider' => 'Google LLC',
		'category' => 'functional',
		'patterns' => array( 'maps.googleapis.com', 'google.com/maps', 'maps.google.' ),
		'cookies'  => array(),
	),
	'vimeo'              => array(
		'name'     => 'Vimeo',
		'provider' => 'Vimeo, Inc.',
		'category' => 'functional',
		'patterns' => array( 'player.vimeo.com' ),
		'cookies'  => array(),
	),
	'polylang'           => array(
		'name'     => 'Polylang',
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'functional',
		'patterns' => array(),
		'cookies'  => array(
			array( 'pll_language', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Remembers the selected language.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'wpml'               => array(
		'name'     => 'WPML',
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'functional',
		'patterns' => array(),
		'cookies'  => array(
			array( 'wp-wpml_current_language', __( '1 day', 'smart-cookie-consent-manager' ), __( 'Remembers the selected language.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'wordpress-comments' => array(
		'name'     => __( 'WordPress comments', 'smart-cookie-consent-manager' ),
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'functional',
		'patterns' => array(),
		'cookies'  => array(
			array( 'comment_author_*', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Remembers your name and email for the comment form (only if you ask it to).', 'smart-cookie-consent-manager' ) ),
		),
	),
	'tawk'               => array(
		'name'     => 'Tawk.to',
		'provider' => 'tawk.to inc.',
		'category' => 'functional',
		'patterns' => array( 'embed.tawk.to' ),
		'cookies'  => array(
			array( 'twk_uuid_*', __( '6 months', 'smart-cookie-consent-manager' ), __( 'Identifies the live chat visitor.', 'smart-cookie-consent-manager' ) ),
			array( 'TawkConnectionTime', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Keeps the live chat connection.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'intercom'           => array(
		'name'     => 'Intercom',
		'provider' => 'Intercom, Inc.',
		'category' => 'functional',
		'patterns' => array( 'widget.intercom.io', 'js.intercomcdn.com' ),
		'cookies'  => array(
			array( 'intercom-id-*', __( '9 months', 'smart-cookie-consent-manager' ), __( 'Identifies the chat visitor.', 'smart-cookie-consent-manager' ) ),
			array( 'intercom-session-*', __( '1 week', 'smart-cookie-consent-manager' ), __( 'Keeps the chat session.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'crisp'              => array(
		'name'     => 'Crisp',
		'provider' => 'Crisp IM SAS',
		'category' => 'functional',
		'patterns' => array( 'client.crisp.chat' ),
		'cookies'  => array(
			array( 'crisp-client*', __( '6 months', 'smart-cookie-consent-manager' ), __( 'Keeps the live chat session.', 'smart-cookie-consent-manager' ) ),
		),
	),

	/* ---------------------------------------------------------------- Analytics */

	'google-tag-manager' => array(
		'name'     => 'Google Tag Manager',
		'provider' => 'Google LLC',
		'category' => 'analytics',
		'google'   => true,
		'patterns' => array( 'googletagmanager.com/gtm.js', 'googletagmanager.com/ns.html', "'gtm.start'", '"gtm.start"' ),
		'cookies'  => array(),
	),
	'google-analytics'   => array(
		'name'     => 'Google Analytics',
		'provider' => 'Google LLC',
		'category' => 'analytics',
		'google'   => true,
		'patterns' => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/ga.js', "gtag('config'", 'gtag("config"', "ga('create'" ),
		'cookies'  => array(
			array( '_ga', __( '2 years', 'smart-cookie-consent-manager' ), __( 'Distinguishes unique visitors.', 'smart-cookie-consent-manager' ) ),
			array( '_ga_*', __( '2 years', 'smart-cookie-consent-manager' ), __( 'Keeps the session state.', 'smart-cookie-consent-manager' ) ),
			array( '_gid', __( '24 hours', 'smart-cookie-consent-manager' ), __( 'Distinguishes visitors.', 'smart-cookie-consent-manager' ) ),
			array( '_gat*', __( '1 minute', 'smart-cookie-consent-manager' ), __( 'Limits the request rate.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'microsoft-clarity'  => array(
		'name'     => 'Microsoft Clarity',
		'provider' => 'Microsoft Corporation',
		'category' => 'analytics',
		'patterns' => array( 'clarity.ms' ),
		'cookies'  => array(
			array( '_clck', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Stores the Clarity user ID.', 'smart-cookie-consent-manager' ) ),
			array( '_clsk', __( '1 day', 'smart-cookie-consent-manager' ), __( 'Connects page views into one session recording.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'hotjar'             => array(
		'name'     => 'Hotjar',
		'provider' => 'Hotjar Ltd.',
		'category' => 'analytics',
		'patterns' => array( 'static.hotjar.com', 'script.hotjar.com' ),
		'cookies'  => array(
			array( '_hjSessionUser_*', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Stores a unique user ID.', 'smart-cookie-consent-manager' ) ),
			array( '_hjSession_*', __( '30 minutes', 'smart-cookie-consent-manager' ), __( 'Holds current session data.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'matomo'             => array(
		'name'     => 'Matomo',
		'provider' => __( 'This website', 'smart-cookie-consent-manager' ),
		'category' => 'analytics',
		'patterns' => array( 'matomo.js', 'piwik.js', '_paq.push' ),
		'cookies'  => array(
			array( '_pk_id.*', __( '13 months', 'smart-cookie-consent-manager' ), __( 'Stores a unique visitor ID.', 'smart-cookie-consent-manager' ) ),
			array( '_pk_ses.*', __( '30 minutes', 'smart-cookie-consent-manager' ), __( 'Holds session data.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'jetpack-stats'      => array(
		'name'     => 'Jetpack Stats',
		'provider' => 'Automattic Inc.',
		'category' => 'analytics',
		'patterns' => array( 'stats.wp.com', 'pixel.wp.com' ),
		'cookies'  => array(
			array( 'tk_ai', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Stores an anonymous visitor ID for statistics.', 'smart-cookie-consent-manager' ) ),
		),
	),

	/* ---------------------------------------------------------------- Tracking / Advertising */

	'google-ads'         => array(
		'name'     => 'Google Ads',
		'provider' => 'Google LLC',
		'category' => 'marketing',
		'google'   => true,
		'patterns' => array( 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net', 'google.com/pagead' ),
		'cookies'  => array(
			array( '_gcl_*', __( '3 months', 'smart-cookie-consent-manager' ), __( 'Stores ad-click information to measure conversions.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'meta-pixel'         => array(
		'name'     => 'Meta Pixel',
		'provider' => 'Meta Platforms, Inc.',
		'category' => 'marketing',
		'patterns' => array( 'connect.facebook.net', 'facebook.com/tr', 'fbq(' ),
		'cookies'  => array(
			array( '_fbp', __( '3 months', 'smart-cookie-consent-manager' ), __( 'Identifies browsers for advertising and analytics.', 'smart-cookie-consent-manager' ) ),
			array( '_fbc', __( '2 years', 'smart-cookie-consent-manager' ), __( 'Stores the last ad click.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'linkedin-insight'   => array(
		'name'     => 'LinkedIn Insight Tag',
		'provider' => 'LinkedIn Corporation',
		'category' => 'marketing',
		'patterns' => array( 'snap.licdn.com', 'px.ads.linkedin.com', '_linkedin_partner_id' ),
		'cookies'  => array(
			array( 'li_fat_id', __( '30 days', 'smart-cookie-consent-manager' ), __( 'Measures LinkedIn ad conversions.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'tiktok-pixel'       => array(
		'name'     => 'TikTok Pixel',
		'provider' => 'TikTok Pte. Ltd.',
		'category' => 'marketing',
		'patterns' => array( 'analytics.tiktok.com', 'ttq.load' ),
		'cookies'  => array(
			array( '_ttp', __( '13 months', 'smart-cookie-consent-manager' ), __( 'Measures TikTok ad performance.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'microsoft-ads'      => array(
		'name'     => 'Microsoft Advertising (UET)',
		'provider' => 'Microsoft Corporation',
		'category' => 'marketing',
		'patterns' => array( 'bat.bing.com' ),
		'cookies'  => array(
			array( '_uetsid', __( '1 day', 'smart-cookie-consent-manager' ), __( 'Measures Microsoft Ads conversions.', 'smart-cookie-consent-manager' ) ),
			array( '_uetvid', __( '13 months', 'smart-cookie-consent-manager' ), __( 'Identifies returning visitors for Microsoft Ads.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'x-pixel'            => array(
		'name'     => 'X (Twitter) Pixel',
		'provider' => 'X Corp.',
		'category' => 'marketing',
		'patterns' => array( 'static.ads-twitter.com', 'ads-twitter.com/uwt.js' ),
		'cookies'  => array(
			array( '_twclid', __( '2 years', 'smart-cookie-consent-manager' ), __( 'Measures X ad conversions.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'pinterest-tag'      => array(
		'name'     => 'Pinterest Tag',
		'provider' => 'Pinterest, Inc.',
		'category' => 'marketing',
		'patterns' => array( 's.pinimg.com/ct', 'pintrk(' ),
		'cookies'  => array(
			array( '_pin_unauth', __( '1 year', 'smart-cookie-consent-manager' ), __( 'Measures Pinterest ad conversions.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'hubspot'            => array(
		'name'     => 'HubSpot',
		'provider' => 'HubSpot, Inc.',
		'category' => 'marketing',
		'patterns' => array( 'js.hs-scripts.com', 'js.hs-analytics.net', 'js.hs-banner.com' ),
		'cookies'  => array(
			array( '__hstc', __( '6 months', 'smart-cookie-consent-manager' ), __( 'Tracks visitors for marketing.', 'smart-cookie-consent-manager' ) ),
			array( 'hubspotutk', __( '6 months', 'smart-cookie-consent-manager' ), __( 'Identifies the visitor for HubSpot forms and marketing.', 'smart-cookie-consent-manager' ) ),
			array( '__hssc', __( '30 minutes', 'smart-cookie-consent-manager' ), __( 'Tracks sessions.', 'smart-cookie-consent-manager' ) ),
			array( '__hssrc', __( 'Session', 'smart-cookie-consent-manager' ), __( 'Detects a new browser session.', 'smart-cookie-consent-manager' ) ),
		),
	),
	'youtube'            => array(
		'name'     => 'YouTube',
		'provider' => 'Google LLC',
		'category' => 'marketing',
		'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com/embed', 'youtube.com/iframe_api' ),
		'cookies'  => array(),
	),
);
