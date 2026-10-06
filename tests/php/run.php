<?php
/**
 * Dependency-free unit tests for pure logic (run: php tests/php/run.php).
 *
 * WordPress functions used by the tested methods are stubbed below.
 *
 * @package SmartCookieConsentManager
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'SCCM_PATH', dirname( __DIR__, 2 ) . '/' );

function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function __( $s ) {
	return $s;
}
function apply_filters( $hook, $value ) {
	return $value;
}

require SCCM_PATH . 'includes/class-sccm-blocker.php';
require SCCM_PATH . 'includes/class-sccm-cookies.php';
require SCCM_PATH . 'includes/class-sccm-rest.php';
require SCCM_PATH . 'includes/class-sccm-consent-log.php';

$failures = 0;
$total    = 0;

function check( $name, $condition ) {
	global $failures, $total;
	++$total;
	if ( ! $condition ) {
		++$failures;
		echo "FAIL  {$name}\n";
		return;
	}
	echo "PASS  {$name}\n";
}

$rules = array(
	array( 'pattern' => 'googletagmanager.com/gtag/js', 'category' => 'analytics' ),
	array( 'pattern' => "gtag('config'", 'category' => 'analytics' ),
	array( 'pattern' => 'connect.facebook.net', 'category' => 'marketing' ),
	array( 'pattern' => 'youtube.com/embed', 'category' => 'marketing' ),
	array( 'pattern' => 'fonts.googleapis.com', 'category' => 'functional' ),
);

/* ---------------------------------------------------------------- Blocker */

$html = '<html><head>'
	. '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
	. "<script>gtag('config','G-1');</script>"
	. '<script type="module" src="https://connect.facebook.net/x.js"></script>'
	. '<script type="application/ld+json">{"x":"connect.facebook.net"}</script>'
	. '<script id="sccm-boot" data-sccm-skip>fbq("x")</script>'
	. '<script>var necessary = 1;</script>'
	. '<script src="/wp-includes/js/jquery.js"></script>'
	. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto">'
	. '<link rel="icon" href="https://fonts.googleapis.com/icon.png">'
	. '</head><body>'
	. '<iframe width="560" src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>'
	. '<iframe class="lazy" data-src="https://www.youtube.com/embed/def"></iframe>'
	. '<iframe src="https://example.org/widget"></iframe>'
	. '</body></html>';

$out = SCCM_Blocker::rewrite( $html, $rules );

check( 'external analytics script blocked with data-sccm-src', false !== strpos( $out, 'type="text/plain" data-sccm-category="analytics" data-sccm-src="https://www.googletagmanager.com/gtag/js?id=G-1"' ) );
check( 'async attribute kept on blocked script', (bool) preg_match( '#<script\s+async type="text/plain"#', $out ) );
check( 'original src removed from blocked script', ! preg_match( '#\ssrc="https://www\.googletagmanager\.com#', $out ) );
check( 'inline gtag config blocked', false !== strpos( $out, "<script type=\"text/plain\" data-sccm-category=\"analytics\">gtag('config'" ) );
check( 'module type preserved in data-sccm-type', false !== strpos( $out, 'data-sccm-type="module"' ) );
check( 'JSON-LD untouched', false !== strpos( $out, '<script type="application/ld+json">' ) );
check( 'data-sccm-skip script untouched', false !== strpos( $out, '<script id="sccm-boot" data-sccm-skip>' ) );
check( 'necessary inline script untouched', false !== strpos( $out, '<script>var necessary = 1;</script>' ) );
check( 'local script untouched', false !== strpos( $out, '<script src="/wp-includes/js/jquery.js"></script>' ) );
check( 'font stylesheet blocked', false !== strpos( $out, 'data-sccm-category="functional" data-sccm-href="https://fonts.googleapis.com/css2?family=Roboto"' ) );
check( 'rel=icon link untouched', false !== strpos( $out, '<link rel="icon" href="https://fonts.googleapis.com/icon.png">' ) );
check( 'youtube iframe blocked', false !== strpos( $out, 'data-sccm-category="marketing" data-sccm-src="https://www.youtube.com/embed/abc"' ) );
check( 'lazy iframe data-src moved', false !== strpos( $out, 'data-sccm-datasrc="https://www.youtube.com/embed/def"' ) && false === strpos( $out, ' data-src=' ) );
check( 'unmatched iframe untouched', false !== strpos( $out, '<iframe src="https://example.org/widget">' ) );
check( 'no rules = unchanged HTML', SCCM_Blocker::rewrite( $html, array() ) === $html );
check( 'already blocked tags are not double-processed', SCCM_Blocker::rewrite( $out, $rules ) === $out );

check( 'attr(): double quotes', 'a b' === SCCM_Blocker::attr( ' id="a b"', 'id' ) );
check( "attr(): single quotes", 'x' === SCCM_Blocker::attr( " id='x'", 'id' ) );
check( 'attr(): unquoted', 'y' === SCCM_Blocker::attr( ' id=y async', 'id' ) );
check( 'attr(): boolean', '' === SCCM_Blocker::attr( ' async', 'async' ) );
check( 'attr(): src does not match data-src', null === SCCM_Blocker::attr( ' data-src="z"', 'src' ) );

/* ---------------------------------------------------------------- Wildcards & validation */

check( 'wildcard _ga_* matches _ga_ABC', SCCM_Cookies::name_matches( '_ga_*', '_ga_ABC' ) );
check( 'wildcard _ga_* does not match _ga', ! SCCM_Cookies::name_matches( '_ga_*', '_ga' ) );
check( 'exact name match', SCCM_Cookies::name_matches( '_fbp', '_fbp' ) && ! SCCM_Cookies::name_matches( '_fbp', '_fbpx' ) );
check( 'wildcard with dots', SCCM_Cookies::name_matches( '_pk_id.*', '_pk_id.1.abcd' ) );
check( 'regex characters are literal', ! SCCM_Cookies::name_matches( 'a.b', 'axb' ) );

check( 'valid cookie name accepted', SCCM_REST::valid_name( '_hjSession_12345' ) );
check( 'name with spaces rejected', ! SCCM_REST::valid_name( 'bad name' ) );
check( 'name with markup rejected', ! SCCM_REST::valid_name( '<script>' ) );
check( 'over-long name rejected', ! SCCM_REST::valid_name( str_repeat( 'a', 101 ) ) );

/* ---------------------------------------------------------------- IP anonymisation */

check( 'IPv4 anonymised', '203.0.113.0' === SCCM_Consent_Log::anonymize_ip( '203.0.113.77' ) );
check( 'IPv6 anonymised', '2001:db8:85a3::' === SCCM_Consent_Log::anonymize_ip( '2001:db8:85a3:1234:5678:8a2e:370:7334' ) );
check( 'invalid IP gives empty string', '' === SCCM_Consent_Log::anonymize_ip( 'not-an-ip' ) );

echo "\n" . ( $total - $failures ) . "/{$total} tests passed\n";
exit( $failures ? 1 : 0 );
