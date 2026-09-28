<?php
/**
 * Cookie scanner.
 *
 * Two sources feed the registry:
 *  1. Visitor-side detection (sccm-frontend.js → REST /report) — catches cookies set by JS.
 *  2. Server-side scan (cron + "Scan now") — fetches key pages, reads Set-Cookie headers and
 *     third-party scripts/iframes/fonts, and matches them against the service library.
 * New items are added to the registry (known services → active, unknown → pending) and
 * reported by email in a daily digest.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner and scheduled tasks.
 */
class SCCM_Scanner {

	const RESULT_OPTION = 'sccm_last_scan';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'sccm_scan_event', array( __CLASS__, 'run' ) );
		add_action( 'sccm_daily_event', array( __CLASS__, 'daily' ) );
	}

	/**
	 * Run a server-side scan.
	 *
	 * @return array Results (also stored in the sccm_last_scan option).
	 */
	public static function run() {
		$results = array(
			'time'         => time(),
			'pages'        => array(),
			'services'     => array(),
			'unclassified' => array(),
			'cookies'      => array(),
			'notes'        => array(),
		);
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( self::urls() as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'user-agent'  => 'SmartCookieConsentManager-Scanner/' . SCCM_VERSION . '; ' . home_url(),
					/**
					 * Verify SSL when scanning (turn off for staging with self-signed certificates).
					 *
					 * @param bool $verify Verify.
					 */
					'sslverify'   => (bool) apply_filters( 'sccm_scan_sslverify', true ),
				)
			);
			if ( is_wp_error( $response ) ) {
				$results['pages'][ $url ] = $response->get_error_message();
				continue;
			}
			$results['pages'][ $url ] = (int) wp_remote_retrieve_response_code( $response );

			foreach ( (array) wp_remote_retrieve_cookies( $response ) as $cookie ) {
				if ( is_object( $cookie ) && ! empty( $cookie->name ) && SCCM_REST::valid_name( $cookie->name ) ) {
					$results['cookies'][ $cookie->name ] = true;
					SCCM_Cookies::record_seen( $cookie->name, 'cookie' );
				}
			}

			self::analyse_html( (string) wp_remote_retrieve_body( $response ), $site_host, $results );
		}

		// Detected services: make sure their known cookies are in the registry.
		foreach ( array_keys( $results['services'] ) as $service_key ) {
			$added = SCCM_Cookies::add_service( $service_key, 'scanner' );
			if ( $added ) {
				$service = SCCM_Services::get( $service_key );
				foreach ( $service['cookies'] as $cookie ) {
					SCCM_Cookies::queue_alert( $cookie[0], isset( $cookie[3] ) ? $cookie[3] : 'cookie', 'active', $service['category'] );
				}
			}
		}

		$results['cookies']      = array_keys( $results['cookies'] );
		$results['unclassified'] = array_values( $results['unclassified'] );
		foreach ( $results['unclassified'] as $item ) {
			SCCM_Cookies::queue_alert( $item['host'], 'resource', 'unclassified', '' );
		}
		update_option( self::RESULT_OPTION, $results, false );
		return $results;
	}

	/**
	 * Analyse one page's HTML for third-party resources.
	 *
	 * @param string $html      HTML.
	 * @param string $site_host Own host.
	 * @param array  $results   Results (by reference).
	 */
	public static function analyse_html( $html, $site_host, array &$results ) {
		if ( '' === $html ) {
			return;
		}
		$custom = (array) SCCM_Settings::get( 'rules' );

		// External resources: scripts, iframes, stylesheets (incl. already-blocked ones).
		preg_match_all( '#<(script|iframe|link)\b([^>]*)>#i', $html, $tags, PREG_SET_ORDER );
		foreach ( $tags as $tag ) {
			$attrs = $tag[2];
			foreach ( array( 'src', 'data-sccm-src', 'data-src', 'data-sccm-datasrc', 'href', 'data-sccm-href' ) as $attr ) {
				$url = SCCM_Blocker::attr( $attrs, $attr );
				if ( ! $url ) {
					continue;
				}
				if ( 'link' === strtolower( $tag[1] ) && ! preg_match( '/rel\s*=\s*["\']?[^"\'>]*(stylesheet|preconnect|dns-prefetch|preload)/i', $attrs ) ) {
					continue;
				}
				$url  = html_entity_decode( $url, ENT_QUOTES );
				$host = wp_parse_url( ( 0 === strpos( $url, '//' ) ? 'https:' : '' ) . $url, PHP_URL_HOST );
				if ( ! $host || $host === $site_host ) {
					continue;
				}
				$service = SCCM_Services::match_resource( $url );
				if ( $service ) {
					self::add_service_hit( $results, $service, $url );
					continue;
				}
				$covered = false;
				foreach ( $custom as $rule ) {
					if ( false !== stripos( $url, $rule['pattern'] ) ) {
						$covered = true;
						break;
					}
				}
				if ( ! $covered && ! isset( $results['unclassified'][ $host ] ) ) {
					$results['unclassified'][ $host ] = array(
						'host' => $host,
						'url'  => substr( $url, 0, 300 ),
						'tag'  => strtolower( $tag[1] ),
					);
				}
			}
		}

		// Inline scripts that load services (e.g. pixel snippets).
		preg_match_all( '#<script\b[^>]*>(.*?)</script\s*>#is', $html, $inline );
		foreach ( $inline[1] as $code ) {
			if ( '' === trim( $code ) ) {
				continue;
			}
			$service = SCCM_Services::match_resource( $code );
			if ( $service ) {
				self::add_service_hit( $results, $service, 'inline script' );
			}
		}

		// Fonts or trackers pulled from inside CSS cannot be blocked by tag rewriting.
		if ( preg_match( '#@import\s+url\(\s*["\']?https?://fonts\.googleapis\.com#i', $html ) || preg_match( '#fonts\.gstatic\.com#i', self::inline_css( $html ) ) ) {
			$results['notes']['css_fonts'] = __( 'Google Fonts are loaded from inside CSS (e.g. an @import or a combined stylesheet). These requests cannot be blocked by the plugin. Host the fonts on your own server to avoid them.', 'smart-cookie-consent-manager' );
		}
	}

	/**
	 * Record a detected service.
	 *
	 * @param array  $results Results (by reference).
	 * @param string $key     Service id.
	 * @param string $where   URL or "inline script".
	 */
	private static function add_service_hit( array &$results, $key, $where ) {
		$service = SCCM_Services::get( $key );
		if ( ! isset( $results['services'][ $key ] ) ) {
			$results['services'][ $key ] = array(
				'name'     => $service['name'],
				'category' => $service['category'],
				'examples' => array(),
			);
		}
		if ( count( $results['services'][ $key ]['examples'] ) < 3 && ! in_array( $where, $results['services'][ $key ]['examples'], true ) ) {
			$results['services'][ $key ]['examples'][] = substr( $where, 0, 200 );
		}
	}

	/**
	 * Inline <style> contents of a page.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function inline_css( $html ) {
		preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m );
		return implode( "\n", $m[1] );
	}

	/**
	 * Pages to scan: home, cookie policy, latest pages and posts.
	 *
	 * @return array
	 */
	public static function urls() {
		$urls   = array( home_url( '/' ) );
		$policy = SCCM_Frontend::policy_url();
		if ( $policy ) {
			$urls[] = $policy;
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				'orderby'        => 'modified',
				'fields'         => 'ids',
				'has_password'   => false,
			)
		);
		foreach ( $posts as $post_id ) {
			$urls[] = get_permalink( $post_id );
		}
		/**
		 * Filter the URLs the scanner fetches.
		 *
		 * @param array $urls URLs.
		 */
		return array_slice( array_unique( array_filter( apply_filters( 'sccm_scan_urls', $urls ) ) ), 0, 15 );
	}

	/**
	 * Daily housekeeping: email digest + consent log retention.
	 */
	public static function daily() {
		self::send_digest();
		SCCM_Consent_Log::purge( (int) SCCM_Settings::get( 'retention_months' ) );
	}

	/**
	 * Email the queued scanner findings (max once per ~day).
	 *
	 * @param bool $force Ignore the once-per-day limit.
	 * @return bool Whether an email was sent.
	 */
	public static function send_digest( $force = false ) {
		$queue = (array) get_option( 'sccm_pending_alert', array() );
		if ( ! $queue || ! SCCM_Settings::get( 'alerts_enabled' ) ) {
			return false;
		}
		$last = (int) get_option( 'sccm_last_alert', 0 );
		if ( ! $force && time() - $last < 23 * HOUR_IN_SECONDS ) {
			return false;
		}

		$to = SCCM_Settings::get( 'alert_email' );
		$to = $to ? $to : get_option( 'admin_email' );

		$lines = array();
		foreach ( $queue as $item ) {
			switch ( $item['status'] ) {
				case 'pending':
					/* translators: 1: name, 2: storage type */
					$lines[] = sprintf( __( '- %1$s (%2$s): NEW, needs a category', 'smart-cookie-consent-manager' ), $item['name'], $item['type'] );
					break;
				case 'unclassified':
					/* translators: %s: host name */
					$lines[] = sprintf( __( '- %s: third-party resource without a blocking rule', 'smart-cookie-consent-manager' ), $item['name'] );
					break;
				default:
					/* translators: 1: name, 2: storage type, 3: category */
					$lines[] = sprintf( __( '- %1$s (%2$s): known service, added automatically as %3$s', 'smart-cookie-consent-manager' ), $item['name'], $item['type'], SCCM_Categories::label( $item['category'] ) );
			}
		}
		$lines = array_unique( $lines );

		/* translators: 1: site name, 2: number of items */
		$subject = sprintf( __( '[%1$s] Cookie scanner found %2$d new item(s)', 'smart-cookie-consent-manager' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), count( $lines ) );
		$body    = __( 'The cookie scanner found new cookies or third-party resources on your website:', 'smart-cookie-consent-manager' ) . "\n\n"
			. implode( "\n", $lines ) . "\n\n"
			. __( 'Review them here:', 'smart-cookie-consent-manager' ) . ' ' . admin_url( 'admin.php?page=sccm&tab=cookies' ) . "\n";

		$sent = wp_mail( $to, $subject, $body );
		if ( $sent ) {
			update_option( 'sccm_last_alert', time(), false );
			delete_option( 'sccm_pending_alert' );
		}
		return $sent;
	}
}
