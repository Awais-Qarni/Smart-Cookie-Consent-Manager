<?php
/**
 * Integration checks that need a real WordPress + database (run: wp eval-file tests/wp/registry-test.php).
 *
 * Covers the cookie registry rules (the list must stay short), settings sanitising, category
 * names, alert recipients and the alert email. It resets the cookie list of the site it runs on,
 * so only use it on a development site.
 *
 * @package SmartCookieConsentManager
 */

// phpcs:ignoreFile

global $wpdb;
// wp eval-file runs this inside a function, so the counters live in $GLOBALS.
$GLOBALS['sccm_failures'] = 0;
$GLOBALS['sccm_total']    = 0;
// The Cookie Policy page linked before the run; it is linked again at the end.
$GLOBALS['sccm_policy_id'] = (int) SCCM_Settings::get( 'policy_page_id' );

function sccm_check( $name, $condition ) {
	++$GLOBALS['sccm_total'];
	if ( ! $condition ) {
		++$GLOBALS['sccm_failures'];
	}
	echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n";
}

function sccm_names( $status = 'pending' ) {
	return array_column( SCCM_Cookies::query( array( 'status' => $status ) ), 'name' );
}

function sccm_as_visitor( $ip, $name, $type = 'cookie' ) {
	$filter = function () use ( $ip ) {
		return $ip;
	};
	add_filter( 'sccm_client_ip', $filter );
	$result = SCCM_Cookies::record_seen( $name, $type, 'visitor' );
	remove_filter( 'sccm_client_ip', $filter );
	return $result;
}

// Start clean: only the plugin's own cookie, no candidates, settings at defaults.
$wpdb->query( 'DELETE FROM ' . SCCM_Cookies::table() . " WHERE service <> 'sccm'" );
delete_option( 'sccm_candidates' );
delete_option( 'sccm_pending_alert' );
SCCM_Settings::update( SCCM_Settings::defaults() );
SCCM_Cookies::all_rows();
$wpdb->query( 'DELETE FROM ' . SCCM_Cookies::table() . " WHERE service <> 'sccm'" );
wp_cache_flush();

/* ---------------------------------------------------------------- Categories */

$labels = array_column( SCCM_Categories::all(), 'label' );
sccm_check( 'category names follow the Cookiebot convention', array( 'Necessary', 'Preferences', 'Statistics', 'Marketing' ) === array_values( $labels ) );
sccm_check( 'category keys (Consent Mode mapping) are unchanged', array( 'necessary', 'functional', 'analytics', 'marketing' ) === SCCM_Categories::KEYS );

/* ---------------------------------------------------------------- Seeding */

sccm_check( 'a fresh list holds only the plugin cookie (no WordPress login cookies)', array( 'sccm_consent' ) === sccm_names( 'active' ) );

/* ---------------------------------------------------------------- Visitor reports */

sccm_check( 'unknown cookie from ONE visitor is not listed', '' === sccm_as_visitor( '203.0.113.1', 'mystery_cookie' ) && ! in_array( 'mystery_cookie', sccm_names(), true ) );
sccm_check( 'the same visitor reporting again still does not list it', '' === sccm_as_visitor( '203.0.113.1', 'mystery_cookie' ) && ! in_array( 'mystery_cookie', sccm_names(), true ) );
sccm_check( 'a SECOND different visitor lists it as pending', 'pending' === sccm_as_visitor( '203.0.113.2', 'mystery_cookie' ) && in_array( 'mystery_cookie', sccm_names(), true ) );
sccm_check( 'a known library cookie is added immediately, in the right category', 'active' === sccm_as_visitor( '203.0.113.1', '_hjSession_998877' ) && 'analytics' === SCCM_Cookies::find_matching( '_hjSession_998877' )['category'] );
sccm_check( 'a conditional library cookie (_fbc) is categorised when seen', 'active' === sccm_as_visitor( '203.0.113.1', '_fbc' ) && 'marketing' === SCCM_Cookies::find_matching( '_fbc' )['category'] );

// Local storage never gets in, through the REST endpoint.
$request = new WP_REST_Request( 'POST', '/sccm/v1/report' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( wp_json_encode( array( 'items' => array( array( 'n' => 'ls_key_a', 't' => 'localStorage' ), array( 'n' => 'ls_key_b', 't' => 'localStorage' ) ) ) ) );
rest_do_request( $request );
$request2 = new WP_REST_Request( 'POST', '/sccm/v1/report' );
$request2->set_header( 'content-type', 'application/json' );
$request2->set_body( wp_json_encode( array( 'items' => array( array( 'n' => 'ls_key_a', 't' => 'localStorage' ) ) ) ) );
rest_do_request( $request2 );
sccm_check( 'local storage keys are never listed', ! in_array( 'ls_key_a', sccm_names(), true ) && ! in_array( 'ls_key_a', sccm_names( 'active' ), true ) );

// Ignored cookies do not come back.
$row = SCCM_Cookies::find_matching( 'mystery_cookie' );
SCCM_Cookies::save( array( 'status' => 'ignored' ), $row['id'] );
sccm_as_visitor( '203.0.113.3', 'mystery_cookie' );
sccm_as_visitor( '203.0.113.4', 'mystery_cookie' );
sccm_check( 'an ignored cookie is not reported again', ! in_array( 'mystery_cookie', sccm_names(), true ) && in_array( 'mystery_cookie', sccm_names( 'ignored' ), true ) );

// Flood protection.
for ( $i = 0; $i < SCCM_Cookies::MAX_PENDING + 10; $i++ ) {
	SCCM_Cookies::record_seen( 'flood_' . $i, 'cookie', 'scanner' );
}
sccm_check( 'pending list is capped (flood protection)', SCCM_Cookies::pending_count() <= SCCM_Cookies::MAX_PENDING );
SCCM_Cookies::bulk_status( 'pending', 'ignored' );
sccm_check( '"Ignore all" empties the review list', 0 === SCCM_Cookies::pending_count() );

/* ---------------------------------------------------------------- Scanner rules */

$wpdb->query( 'DELETE FROM ' . SCCM_Cookies::table() . " WHERE service <> 'sccm'" );
SCCM_Cookies::all_rows();
wp_cache_flush();
SCCM_Cookies::add_service( 'meta-pixel', 'scanner' );
sccm_check( 'a detected service adds only the cookies it always sets', array( '_fbp' ) === sccm_names( 'active' ) || in_array( '_fbp', sccm_names( 'active' ), true ) && ! in_array( '_fbc', sccm_names( 'active' ), true ) );
SCCM_Cookies::add_service( 'meta-pixel', 'library' );
sccm_check( 'adding the service by hand adds all of its cookies', in_array( '_fbc', sccm_names( 'active' ), true ) );

$ga4 = SCCM_Services::get( 'google-analytics' );
sccm_check( 'GA4 lists _ga and _ga_* only', array( '_ga', '_ga_*' ) === array_column( $ga4['cookies'], 0 ) );

// One consent-version bump per bulk operation, none for the very first scan.
update_option( 'sccm_consent_version', 5 );
SCCM_Cookies::bulk(
	function () {
		SCCM_Cookies::add_service( 'hotjar', 'scanner' );
		SCCM_Cookies::add_service( 'microsoft-clarity', 'scanner' );
	}
);
sccm_check( 'a bulk change bumps the consent version exactly once', 6 === (int) get_option( 'sccm_consent_version' ) );
SCCM_Cookies::bulk(
	function () {
		SCCM_Cookies::add_service( 'matomo', 'scanner' );
	},
	false
);
sccm_check( 'a bulk change can skip the bump (first scan)', 6 === (int) get_option( 'sccm_consent_version' ) );

/* ---------------------------------------------------------------- Browser scan */

$wpdb->query( 'DELETE FROM ' . SCCM_Cookies::table() . " WHERE service <> 'sccm'" );
SCCM_Cookies::all_rows();
wp_set_current_user( 0 );
$token = SCCM_Scanner::scan_token();
sccm_check( 'a scan token is useless without a logged-in user', false === SCCM_Scanner::valid_scan_token( $token ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$token = SCCM_Scanner::scan_token( false, SCCM_Cookies::counts() );
$data  = SCCM_Scanner::valid_scan_token( $token );
sccm_check( 'a scan token is valid for the admin who created it', is_array( $data ) );
sccm_check( 'a made-up scan token is refused', false === SCCM_Scanner::valid_scan_token( 'abcdefghijklmnopqrstuvwx' ) );
$summary = SCCM_Scanner::record_browser_scan(
	array(
		'pages'     => 2,
		'cookies'   => array( '_ga', 'wordpress_logged_in_abc', 'wp-settings-1', 'site_js_cookie', '<bad>' ),
		'storage'   => array(
			array( 'n' => 'elementor', 't' => 'localStorage', 'new' => false ),
			array( 'n' => 'old_dashboard_key', 't' => 'localStorage', 'new' => false ),
			array( 'n' => 'site_written_key', 't' => 'localStorage', 'new' => true ),
		),
		'resources' => array( 'https://www.youtube.com/embed/abc', 'https://assets.calendly.com/assets/external/widget.js', 'https://cdn.example.net/lib.js', home_url( '/wp-includes/js/x.js' ) ),
	),
	$data
);
$names = array_map(
	function ( $r ) {
		return $r['name'] . '|' . $r['status'];
	},
	SCCM_Cookies::all_rows()
);
sccm_check( 'browser scan: known JS cookie is listed', in_array( '_ga|active', $names, true ) );
sccm_check( 'browser scan: admin-only cookies are never listed', ! preg_grep( '/^(wordpress_|wp-settings)/', $names ) );
sccm_check( 'browser scan: unknown site cookie waits for review', in_array( 'site_js_cookie|pending', $names, true ) );
sccm_check( 'browser scan: invalid names are dropped', ! preg_grep( '/bad/', $names ) );
sccm_check( 'browser scan: known storage key is listed even if the browser had it before', in_array( 'elementor|active', $names, true ) );
sccm_check( 'browser scan: unknown storage key the browser already had is skipped', ! preg_grep( '/old_dashboard_key/', $names ) );
sccm_check( 'browser scan: unknown storage key written by the page waits for review', in_array( 'site_written_key|pending', $names, true ) );
sccm_check( 'browser scan: embedded YouTube adds its third-party cookies', in_array( 'YSC|active', $names, true ) );
sccm_check( 'browser scan: Calendly is recognised (its cookies are necessary)', in_array( '_calendly_session|active', $names, true ) );
$scan = get_option( SCCM_Scanner::RESULT_OPTION );
sccm_check( 'browser scan: unknown third-party host goes to the report, not the cookie list', in_array( 'cdn.example.net', array_column( $scan['unclassified'], 'host' ), true ) );
sccm_check( 'browser scan: summary counts what was added', $summary['active'] >= 5 && 2 === $summary['pending'] );
wp_set_current_user( 0 );

/* ---------------------------------------------------------------- Library details */
$m = SCCM_Services::match_cookie( 'gt_autoswitch' );
sccm_check( 'a cookie can have its own category (GTranslate auto-switch is Preferences)', $m && 'gtranslate' === $m['service'] && 'functional' === $m['category'] );
$m = SCCM_Services::match_cookie( '__GT_TRANSLATE_LANGS' );
sccm_check( 'other GTranslate keys keep the service category (Necessary)', $m && 'necessary' === $m['category'] );
$m = SCCM_Services::match_cookie( 'nitroCachedPage' );
sccm_check( 'NitroPack cache key is recognised', $m && 'nitropack' === $m['service'] );
$m = SCCM_Services::match_cookie( 'PHPSESSID' );
sccm_check( 'PHP session cookie is recognised as necessary', $m && 'necessary' === $m['category'] );
$urls = SCCM_Scanner::urls();
sccm_check( 'scan URLs start with the home page and are capped at 15', home_url( '/' ) === $urls[0] && count( $urls ) <= 15 );
list( $asset_url, $asset_ver ) = SCCM_Plugin::asset( 'assets/js/sccm-frontend.js' );
sccm_check( 'front-end script is served from a hashed copy (cache-proof)', (bool) preg_match( '#/sccm-assets/sccm-frontend\.[0-9a-f]{10}\.js$#', $asset_url ) && null === $asset_ver );
$uploads = wp_upload_dir( null, false );
sccm_check( 'the hashed copy exists and matches the source', file_exists( $uploads['basedir'] . '/sccm-assets/' . basename( $asset_url ) ) && md5_file( SCCM_PATH . 'assets/js/sccm-frontend.js' ) === md5_file( $uploads['basedir'] . '/sccm-assets/' . basename( $asset_url ) ) );
add_filter( 'sccm_versioned_asset_files', '__return_false' );
list( $asset_url ) = SCCM_Plugin::asset( 'assets/js/sccm-frontend.js' );
sccm_check( 'hashed copies can be switched off with a filter', 0 === strpos( $asset_url, SCCM_URL ) );
remove_filter( 'sccm_versioned_asset_files', '__return_false' );

/* ---------------------------------------------------------------- Settings */

$s = SCCM_Settings::update( array( 'floating_position' => 'left-center', 'consent_expiry_days' => 0 ) );
sccm_check( 'widget position "left-center" is accepted', 'left-center' === $s['floating_position'] );
sccm_check( 'expiry 0 (session only) is accepted', 0 === $s['consent_expiry_days'] );
$s = SCCM_Settings::update( array( 'floating_position' => 'nonsense', 'consent_expiry_days' => 9999 ) );
sccm_check( 'unknown widget position falls back to the default', 'bottom-left' === $s['floating_position'] );
sccm_check( 'expiry is capped at 395 days (browser limit)', 395 === $s['consent_expiry_days'] );
$s = SCCM_Settings::update( array( 'consent_expiry_days' => 90 ) );
sccm_check( 'a chosen expiry is stored', 90 === $s['consent_expiry_days'] );
sccm_check( 'front-end config carries the chosen expiry', 90 === SCCM_Frontend::config()['days'] );
sccm_check( 'banner style defaults to compact', 'compact' === $s['banner_layout'] && 'compact' === SCCM_Frontend::config()['layout'] );
$s = SCCM_Settings::update( array( 'banner_layout' => 'tabs' ) );
sccm_check( 'banner style "tabs" is accepted', 'tabs' === $s['banner_layout'] );
$s = SCCM_Settings::update( array( 'banner_layout' => 'popup-of-doom' ) );
sccm_check( 'unknown banner style falls back to compact', 'compact' === $s['banner_layout'] );

$s = SCCM_Settings::update( array( 'alert_email' => "one@example.com, two@example.com\nnot-an-email  one@example.com;three@example.org" ) );
sccm_check( 'several alert recipients are stored (invalid and duplicate dropped)', 'one@example.com, two@example.com, three@example.org' === $s['alert_email'] );
sccm_check( 'alert_recipients(): the site admin plus the listed addresses', array( get_option( 'admin_email' ), 'one@example.com', 'two@example.com', 'three@example.org' ) === SCCM_Settings::alert_recipients() );
SCCM_Settings::update( array( 'alert_include_admin' => 0 ) );
sccm_check( 'alert_recipients(): only the listed addresses when "also the site admin" is off', array( 'one@example.com', 'two@example.com', 'three@example.org' ) === SCCM_Settings::alert_recipients() );
SCCM_Settings::update( array( 'alert_include_admin' => 1 ) );
SCCM_Settings::update( array( 'alert_email' => '' ) );
sccm_check( 'no recipients falls back to the site admin email', array( get_option( 'admin_email' ) ) === SCCM_Settings::alert_recipients() );
sccm_check( 'settings are autoloaded (no extra query per page)', in_array( SCCM_Settings::OPTION, array_keys( wp_load_alloptions() ), true ) );

/* ---------------------------------------------------------------- Email */

$email = SCCM_Scanner::build_email(
	array(
		array( 'name' => 'mystery_cookie', 'type' => 'cookie', 'status' => 'pending', 'category' => '', 'time' => time() ),
		array( 'name' => '_ga', 'type' => 'cookie', 'status' => 'active', 'category' => 'analytics', 'time' => time() ),
		array( 'name' => 'cdn.example.net', 'type' => 'resource', 'status' => 'unclassified', 'category' => '', 'time' => time() ),
	)
);
sccm_check( 'email subject names the number of cookies to review', false !== strpos( $email['subject'], '1 new cookie needs review' ) );
sccm_check( 'email HTML lists the cookie and its category, with plain instructions', false !== strpos( $email['html'], 'mystery_cookie' ) && false !== strpos( $email['html'], 'Statistics' ) && false !== strpos( $email['html'], 'Cookie Consent → Cookies' ) );
sccm_check( 'email has a plain-text version', false !== strpos( $email['text'], 'mystery_cookie' ) && false !== strpos( $email['text'], 'Statistics' ) );
$escaped = SCCM_Scanner::build_email( array( array( 'name' => '<script>alert(1)</script>', 'type' => 'cookie', 'status' => 'pending', 'category' => '', 'time' => time() ) ) );
sccm_check( 'cookie names are escaped in the email', false === strpos( $escaped['html'], '<script>alert(1)' ) );
sccm_check( 'email declares light and dark colour schemes', false !== strpos( $email['html'], 'name="color-scheme" content="light dark"' ) && false !== strpos( $email['html'], 'prefers-color-scheme:dark' ) );
sccm_check( 'email header is light (no dark block that dark mode inverts)', 0 === preg_match( '/style="[^"]*background:#(1f2937|111827)/', $email['html'] ) );
file_put_contents( getenv( 'SCCM_EMAIL_PREVIEW' ) ?: sys_get_temp_dir() . '/sccm-email-preview.html', $email['html'] );

/* ---------------------------------------------------------------- Daily change email */

$GLOBALS['sccm_mails'] = array();
$capture               = function ( $null, $atts ) {
	$GLOBALS['sccm_mails'][] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );
SCCM_Settings::update( array( 'alerts_enabled' => 1, 'alert_email' => 'team@example.com', 'alert_hour' => 7, 'scan_schedule' => 'weekly' ) );
delete_option( 'sccm_pending_alert' );

SCCM_Scanner::daily();
sccm_check( 'daily check without changes: no email', 0 === count( $GLOBALS['sccm_mails'] ) );

SCCM_Cookies::queue_alert( 'new_tracker', 'cookie', 'pending', '' );
SCCM_Cookies::queue_alert( '_clck', 'cookie', 'active', 'analytics' );
sccm_check( 'a change is not emailed right away (it waits for the daily check)', 0 === count( $GLOBALS['sccm_mails'] ) );
SCCM_Scanner::daily();
$mail = end( $GLOBALS['sccm_mails'] );
sccm_check( 'daily check with changes: one email', 1 === count( $GLOBALS['sccm_mails'] ) && false !== strpos( $mail['subject'], '1 new cookie needs review' ) );
sccm_check( 'the email lists the changes of the last 24 hours and the banner total', false !== strpos( $mail['message'], 'Cookie changes in the last 24 hours' ) && false !== strpos( $mail['message'], 'new_tracker' ) && false !== strpos( $mail['message'], '_clck' ) && false !== strpos( $mail['message'], 'Your cookie banner now lists' ) );
sccm_check( 'no links into the WordPress admin and no "Review and approve" button (recipients may have no account)', false === strpos( $mail['message'], 'wp-admin' ) && false === strpos( $mail['message'], 'Review and approve' ) && false === strpos( $mail['message'], '<a ' ) );
sccm_check( 'the email goes to the site admin and the listed address', array( get_option( 'admin_email' ), 'team@example.com' ) === $mail['to'] );
sccm_check( 'after the email the changes are cleared', ! get_option( 'sccm_pending_alert' ) );
SCCM_Scanner::daily();
sccm_check( 'next day without new changes: no email', 1 === count( $GLOBALS['sccm_mails'] ) );
$status = get_option( SCCM_Scanner::MAIL_OPTION );
sccm_check( 'the result of the last email is recorded', ! empty( $status['ok'] ) && '' === $status['error'] && in_array( 'team@example.com', $status['to'], true ) );

SCCM_Settings::update( array( 'alerts_enabled' => 0 ) );
SCCM_Cookies::queue_alert( 'another_cookie', 'cookie', 'pending', '' );
SCCM_Scanner::daily();
sccm_check( 'emails switched off: no email', 1 === count( $GLOBALS['sccm_mails'] ) );
SCCM_Settings::update( array( 'alerts_enabled' => 1 ) );

remove_filter( 'pre_wp_mail', $capture, 10 );
$refuse = function () {
	return false;
};
add_filter( 'pre_wp_mail', $refuse );
sccm_check( 'a failed email is reported as failed', false === SCCM_Scanner::send_digest() );
$status = get_option( SCCM_Scanner::MAIL_OPTION );
sccm_check( 'the failure and its reason are recorded (shown on the Settings tab), the changes are kept for the next day', empty( $status['ok'] ) && '' !== $status['error'] && get_option( 'sccm_pending_alert' ) );
remove_filter( 'pre_wp_mail', $refuse );

SCCM_Install::schedule_events();
$daily = wp_next_scheduled( SCCM_Scanner::DAILY_EVENT );
$scan  = wp_next_scheduled( 'sccm_scan_event' );
sccm_check( 'the daily check runs at the chosen hour (7:00, site time)', $daily && 7 === (int) wp_date( 'G', $daily ) && '00' === wp_date( 'i', $daily ) && $daily > time() && $daily <= time() + DAY_IN_SECONDS );
sccm_check( 'scheduled scans run one hour before it (6:00)', $scan && 6 === (int) wp_date( 'G', $scan ) );
SCCM_Settings::update( array( 'alert_hour' => 99 ) );
sccm_check( 'an invalid hour is clamped to 0-23', 23 === (int) SCCM_Settings::get( 'alert_hour' ) );

delete_option( 'sccm_pending_alert' );
SCCM_Scanner::run();
SCCM_Scanner::run();
sccm_check( 'third-party resources already reported are not reported again on the next scan', ! array_filter( (array) get_option( 'sccm_pending_alert', array() ), function ( $i ) { return 'unclassified' === $i['status']; } ) );
delete_option( 'sccm_pending_alert' );
delete_option( SCCM_Scanner::MAIL_OPTION );
SCCM_Settings::update( array( 'alert_email' => '', 'alert_hour' => 9 ) );
SCCM_Install::schedule_events();

/* ---------------------------------------------------------------- Button order */

$policy_id = $GLOBALS['sccm_policy_id'];
SCCM_Settings::update( SCCM_Settings::defaults() );
sccm_check( 'button order defaults to Allow all · Deny · Allow selection', 'accept_reject' === SCCM_Settings::get( 'button_order' ) && 'accept_reject' === SCCM_Frontend::config()['order'] );
foreach ( array( 'reject_accept', 'accept_first', 'reject_first', 'accept_reject' ) as $order ) {
	$s = SCCM_Settings::update( array( 'button_order' => $order ) );
	sccm_check( "button order {$order} is accepted", $order === $s['button_order'] );
}
foreach ( array( 'accept_first' => 'accept_reject', 'reject_first' => 'reject_accept' ) as $old => $new ) {
	$raw                 = get_option( SCCM_Settings::OPTION );
	$raw['button_order'] = $old;
	update_option( SCCM_Settings::OPTION, $raw );
	update_option( SCCM_Install::DB_VERSION_OPTION, '2' );
	SCCM_Install::maybe_upgrade();
	SCCM_Settings::flush();
	sccm_check( "upgrade moves a saved {$old} to {$new} (compact banner unchanged)", $new === SCCM_Settings::get( 'button_order' ) && SCCM_Install::DB_VERSION === get_option( SCCM_Install::DB_VERSION_OPTION ) );
}
wp_clear_scheduled_hook( 'sccm_scan_event' );
SCCM_Install::schedule_events();
$s = SCCM_Settings::update( array( 'button_order' => 'deny_hidden' ) );
sccm_check( 'unknown button order falls back to the default', 'accept_reject' === $s['button_order'] );
sccm_check( '"Show details" text is gone (the Details tab replaces it)', ! isset( SCCM_Settings::texts()['btn_details'] ) );

/* ---------------------------------------------------------------- Cookie Policy page: sidebar */

$page_id = $policy_id ? $policy_id : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cookie Policy test', 'post_content' => '[sccm_cookie_policy]' ) );
SCCM_Settings::update( array( 'policy_page_id' => $page_id ) );
sccm_check( 'hide-sidebar setting is on by default', 1 === (int) SCCM_Settings::get( 'policy_hide_sidebar' ) );
$GLOBALS['wp_the_query']->query( array( 'page_id' => $page_id ) );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
$had_wp              = did_action( 'wp' );
if ( ! $had_wp ) {
	$GLOBALS['wp_actions']['wp'] = 1; // As if the front-end query ran.
}
$widgets = array( 'sidebar-1' => array( 'search-2' ), 'wp_inactive_widgets' => array( 'text-3' ), 'array_version' => 3 );
sccm_check( 'policy page: no active sidebar', false === apply_filters( 'is_active_sidebar', true, 'sidebar-1' ) );
$filtered = apply_filters( 'sidebars_widgets', $widgets );
sccm_check( 'policy page: sidebars emptied, inactive widgets kept', array() === $filtered['sidebar-1'] && array( 'text-3' ) === $filtered['wp_inactive_widgets'] && 3 === $filtered['array_version'] );
sccm_check( 'policy page: Astra/GeneratePress layout is no-sidebar', 'no-sidebar' === apply_filters( 'astra_page_layout', 'right-sidebar' ) && 'no-sidebar' === apply_filters( 'generate_sidebar_layout', 'right-sidebar' ) );
sccm_check( 'policy page: body class added', in_array( 'sccm-policy-page', apply_filters( 'body_class', array() ), true ) );
SCCM_Settings::update( array( 'policy_hide_sidebar' => 0 ) );
sccm_check( 'hide-sidebar off: sidebars untouched', true === apply_filters( 'is_active_sidebar', true, 'sidebar-1' ) && $widgets === apply_filters( 'sidebars_widgets', $widgets ) );
SCCM_Settings::update( array( 'policy_hide_sidebar' => 1 ) );
$other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Other page' ) );
$GLOBALS['wp_the_query']->query( array( 'page_id' => $other ) );
sccm_check( 'other pages keep their sidebar', true === apply_filters( 'is_active_sidebar', true, 'sidebar-1' ) && $widgets === apply_filters( 'sidebars_widgets', $widgets ) );
wp_delete_post( $other, true );
if ( ! $had_wp ) {
	unset( $GLOBALS['wp_actions']['wp'] );
}
$GLOBALS['wp_the_query']->init();

/* ---------------------------------------------------------------- Page cache clearing */

$fired = 0;
$count = function () use ( &$fired ) {
	++$fired;
};
add_action( 'sccm_cookie_list_changed', $count );
$id = SCCM_Cookies::save( array( 'name' => 'cache_test_cookie', 'category' => 'analytics', 'status' => 'active' ) );
SCCM_Cookies::save( array( 'name' => 'cache_test_cookie', 'category' => 'marketing', 'status' => 'active' ), $id );
sccm_check( 'adding or editing a visible cookie signals a cookie list change', 2 === $fired );
$pending = SCCM_Cookies::save( array( 'name' => 'cache_test_pending', 'status' => 'pending' ) );
sccm_check( 'a cookie waiting for review does not (visitors do not see it)', 2 === $fired );
SCCM_Cookies::delete( $pending );
SCCM_Cookies::delete( $id );
sccm_check( 'deleting a visible cookie signals a change', 3 === $fired );
remove_action( 'sccm_cookie_list_changed', $count );

$purged = 0;
$count  = function () use ( &$purged ) {
	++$purged;
};
add_action( 'sccm_cache_purged', $count );
wp_set_current_user( 0 );
delete_option( 'sccm_last_cache_purge' );
wp_clear_scheduled_hook( SCCM_Cache::EVENT );
SCCM_Cache::request();
SCCM_Cache::request();
SCCM_Cache::flush_queued();
sccm_check( 'a requested cache clear runs once at the end of the request', 1 === $purged && (int) get_option( 'sccm_last_cache_purge' ) >= time() - 5 );
SCCM_Cache::request();
SCCM_Cache::flush_queued();
sccm_check( 'a second clear from a visitor within 5 minutes is postponed to WP-Cron', 1 === $purged && wp_next_scheduled( SCCM_Cache::EVENT ) > time() );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
SCCM_Cache::request();
SCCM_Cache::flush_queued();
sccm_check( 'an administrator\'s change clears the cache right away', 2 === $purged );
add_filter( 'sccm_purge_page_cache', '__return_false' );
SCCM_Cache::run();
sccm_check( 'the sccm_purge_page_cache filter turns clearing off', 2 === $purged );
remove_filter( 'sccm_purge_page_cache', '__return_false' );
remove_action( 'sccm_cache_purged', $count );
wp_clear_scheduled_hook( SCCM_Cache::EVENT );
wp_set_current_user( 0 );
remove_action( 'shutdown', array( 'SCCM_Cache', 'flush_queued' ), 1 ); // The clears queued above already ran.

/* ---------------------------------------------------------------- Other consent plugins */

require_once SCCM_PATH . 'includes/admin/class-sccm-admin.php';
sccm_check( 'no other consent plugin detected on a clean site', array() === SCCM_Admin::other_consent_plugins() );
$fake = function ( $plugins ) {
	return array_merge( (array) $plugins, array( 'cookiebot/cookiebot.php', 'complianz-gdpr/complianz-gpdr.php' ) );
};
add_filter( 'option_active_plugins', $fake );
sccm_check( 'active Cookiebot / Complianz are detected (two banners would conflict)', array( 'Cookiebot', 'Complianz' ) === SCCM_Admin::other_consent_plugins() );
remove_filter( 'option_active_plugins', $fake );

/* ---------------------------------------------------------------- AMP */

sccm_check( 'AMP detection is off when no AMP plugin is active', false === SCCM_Frontend::is_amp() );

// Leave the site tidy (the Cookie Policy page stays linked).
$wpdb->query( 'DELETE FROM ' . SCCM_Cookies::table() . " WHERE service <> 'sccm'" );
delete_option( 'sccm_candidates' );
delete_option( 'sccm_pending_alert' );
SCCM_Settings::update( array_merge( SCCM_Settings::defaults(), array( 'policy_page_id' => $policy_id ) ) );
if ( ! $policy_id ) {
	wp_delete_post( $page_id, true );
}

echo "\n" . ( $GLOBALS['sccm_total'] - $GLOBALS['sccm_failures'] ) . '/' . $GLOBALS['sccm_total'] . " checks passed\n";
if ( $GLOBALS['sccm_failures'] ) {
	WP_CLI::halt( 1 );
}
