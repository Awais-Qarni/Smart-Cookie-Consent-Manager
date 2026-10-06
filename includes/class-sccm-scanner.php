<?php
/**
 * Cookie scanner.
 *
 * Two sources feed the registry:
 *  1. Server-side scan (cron + "Scan now") — fetches key pages, reads Set-Cookie headers and
 *     third-party scripts/iframes/fonts, and matches them against the service library.
 *  2. Visitor-side detection (sccm-frontend.js → REST /report) — catches cookies set by JS.
 *     Only unknown cookies reported by several different visitors are listed (see
 *     SCCM_Cookies), and logged-in users never report.
 * Known cookies are added automatically as Active in the right category; unknown ones wait as
 * "Needs review". New items are reported by email in a daily digest.
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
	 * @param bool $manual True for "Scan now" (fewer pages and shorter timeouts, so the admin
	 *                     request cannot run into the PHP time limit).
	 * @return array Results (also stored in the sccm_last_scan option).
	 */
	public static function run( $manual = false ) {
		$first   = false === get_option( self::RESULT_OPTION );
		$results = array(
			'time'         => time(),
			'pages'        => array(),
			'services'     => array(),
			'unclassified' => array(),
			'cookies'      => array(),
			'notes'        => array(),
		);
		$urls    = self::urls();
		if ( $manual ) {
			$urls = array_slice( $urls, 0, 10 );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
		}

		// Changes found by a scan ask visitors again at most once, and not at all on the very
		// first scan (nobody has consented to an older list yet).
		SCCM_Cookies::bulk(
			function () use ( &$results, $urls, $manual ) {
				self::scan_pages( $urls, $manual ? 10 : 20, $results );

				self::add_detected_services( $results );
			},
			! $first
		);

		$results['cookies']      = array_keys( $results['cookies'] );
		$results['unclassified'] = array_values( $results['unclassified'] );
		foreach ( $results['unclassified'] as $item ) {
			SCCM_Cookies::queue_alert( $item['host'], 'resource', 'unclassified', '' );
		}
		update_option( self::RESULT_OPTION, $results, false );
		return $results;
	}

	/**
	 * Fetch the pages and feed cookies / third-party resources into the results.
	 *
	 * @param array $urls    Pages.
	 * @param int   $timeout Seconds per page.
	 * @param array $results Results (by reference).
	 */
	private static function scan_pages( array $urls, $timeout, array &$results ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => $timeout,
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
					SCCM_Cookies::record_seen( $cookie->name, 'cookie', 'scanner' );
				}
			}

			self::analyse_html( (string) wp_remote_retrieve_body( $response ), $site_host, $results );
		}
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
				self::classify_url( html_entity_decode( $url, ENT_QUOTES ), $site_host, strtolower( $tag[1] ), $results );
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
	 * Sort one third-party URL: a known service, a resource covered by a custom rule, or an
	 * "other third-party resource" for the report.
	 *
	 * @param string $url       URL.
	 * @param string $site_host Own host.
	 * @param string $tag       Where it was found (script, iframe, link, browser).
	 * @param array  $results   Results (by reference).
	 */
	private static function classify_url( $url, $site_host, $tag, array &$results ) {
		$host = wp_parse_url( ( 0 === strpos( $url, '//' ) ? 'https:' : '' ) . $url, PHP_URL_HOST );
		if ( ! $host || $host === $site_host ) {
			return;
		}
		$service = SCCM_Services::match_resource( $url );
		if ( $service ) {
			self::add_service_hit( $results, $service, $url );
			return;
		}
		foreach ( (array) SCCM_Settings::get( 'rules' ) as $rule ) {
			if ( false !== stripos( $url, $rule['pattern'] ) ) {
				return;
			}
		}
		if ( ! isset( $results['unclassified'][ $host ] ) ) {
			$results['unclassified'][ $host ] = array(
				'host' => $host,
				'url'  => substr( $url, 0, 300 ),
				'tag'  => $tag,
			);
		}
	}

	/**
	 * Make sure the cookies of every detected service are in the registry.
	 *
	 * @param array $results Results.
	 */
	private static function add_detected_services( array $results ) {
		foreach ( array_keys( $results['services'] ) as $service_key ) {
			if ( SCCM_Cookies::add_service( $service_key, 'scanner' ) ) {
				$service = SCCM_Services::get( $service_key );
				foreach ( $service['cookies'] as $cookie ) {
					if ( empty( $cookie[4] ) ) {
						SCCM_Cookies::queue_alert( $cookie[0], isset( $cookie[3] ) ? $cookie[3] : 'cookie', 'active', SCCM_Services::cookie_category( $service, $cookie ) );
					}
				}
			}
		}
	}

	/* ------------------------------------------------------------------ Browser scan
	 *
	 * The server cannot run JavaScript, so it never sees the cookies that scripts set (Google
	 * Analytics, HubSpot, chat widgets…). "Scan now" therefore also opens the pages in a hidden
	 * frame in the administrator's own browser, in scan mode (every category allowed, nothing
	 * stored or logged, see SCCM_Frontend::is_scan_mode()). The admin page collects the cookie
	 * and storage names and the third-party addresses the pages used, and sends them here.
	 */

	/**
	 * Create a one-time scan-mode token for the current administrator (15 minutes).
	 *
	 * @param bool  $first  Whether this is the first scan ever (then it never asks visitors again).
	 * @param array $counts Registry counts before the scan started (for the summary).
	 * @return string
	 */
	public static function scan_token( $first = false, array $counts = array() ) {
		$token = strtolower( wp_generate_password( 24, false, false ) );
		set_transient(
			'sccm_scan_' . $token,
			array(
				'user'   => get_current_user_id(),
				'first'  => (bool) $first,
				'counts' => $counts,
			),
			15 * MINUTE_IN_SECONDS
		);
		return $token;
	}

	/**
	 * Whether a scan-mode token is valid for the current user.
	 *
	 * @param string $token Token.
	 * @return array|false Token data.
	 */
	public static function valid_scan_token( $token ) {
		if ( ! preg_match( '/^[a-z0-9]{24}$/', (string) $token ) || ! get_current_user_id() ) {
			return false;
		}
		$data = get_transient( 'sccm_scan_' . $token );
		return ( is_array( $data ) && (int) $data['user'] === get_current_user_id() ) ? $data : false;
	}

	/**
	 * Pages the browser scan opens (the first 10 of the scan pages).
	 *
	 * @param string $token Scan-mode token.
	 * @return array
	 */
	public static function browser_scan_urls( $token ) {
		$urls = array();
		foreach ( array_slice( self::urls(), 0, 10 ) as $url ) {
			$urls[] = add_query_arg( 'sccm_scan', $token, $url );
		}
		return $urls;
	}

	/**
	 * Cookies that only exist because an administrator is logged in. Never listed.
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	public static function is_admin_cookie( $name ) {
		foreach ( array( 'wordpress_*', 'wp-settings-*', 'wp-saving-*', 'wp_lang', 'wp-postpass_*' ) as $pattern ) {
			if ( SCCM_Cookies::name_matches( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record what the browser scan found.
	 *
	 * @param array $data  cookies: [names]; storage: [{n, t, new}]; resources: [urls]; pages, blocked: ints.
	 * @param array $token Token data from valid_scan_token().
	 * @return array Summary: active, pending (added since the scan started), unclassified.
	 */
	public static function record_browser_scan( array $data, array $token ) {
		$results = get_option( self::RESULT_OPTION );
		if ( ! is_array( $results ) ) {
			$results = array(
				'time'         => time(),
				'pages'        => array(),
				'services'     => array(),
				'unclassified' => array(),
				'cookies'      => array(),
				'notes'        => array(),
			);
		}
		$results['unclassified'] = self::keyed_by_host( (array) $results['unclassified'] );
		$site_host               = wp_parse_url( home_url(), PHP_URL_HOST );
		$seen                    = array();

		SCCM_Cookies::bulk(
			function () use ( $data, $site_host, &$results, &$seen ) {
				foreach ( array_slice( (array) ( $data['cookies'] ?? array() ), 0, 200 ) as $name ) {
					$name = (string) $name;
					if ( ! SCCM_REST::valid_name( $name ) || self::is_admin_cookie( $name ) ) {
						continue;
					}
					$seen[ $name ] = true;
					SCCM_Cookies::record_seen( $name, 'cookie', 'scanner' );
				}
				foreach ( array_slice( (array) ( $data['storage'] ?? array() ), 0, 200 ) as $item ) {
					$name = isset( $item['n'] ) ? (string) $item['n'] : '';
					$type = isset( $item['t'] ) ? (string) $item['t'] : '';
					if ( ! SCCM_REST::valid_name( $name ) || ! in_array( $type, array( 'localStorage', 'sessionStorage' ), true ) ) {
						continue;
					}
					// Storage keys the admin's browser already had before the scan may come from the
					// dashboard or an extension; those count only when the library knows them.
					$known = SCCM_Services::match_cookie( $name );
					if ( empty( $item['new'] ) && ! ( $known && $known['type'] === $type ) ) {
						continue;
					}
					$seen[ $name ] = true;
					SCCM_Cookies::record_seen( $name, $type, 'scanner' );
				}
				foreach ( array_slice( (array) ( $data['resources'] ?? array() ), 0, 500 ) as $url ) {
					$url = esc_url_raw( (string) $url );
					if ( $url ) {
						self::classify_url( $url, $site_host, 'browser', $results );
					}
				}
				self::add_detected_services( $results );
			},
			empty( $token['first'] )
		);

		$results['unclassified'] = array_values( $results['unclassified'] );
		$results['browser']      = array(
			'time'    => time(),
			'pages'   => absint( $data['pages'] ?? 0 ),
			'blocked' => absint( $data['blocked'] ?? 0 ),
			'found'   => array_keys( $seen ),
		);
		update_option( self::RESULT_OPTION, $results, false );

		$now    = SCCM_Cookies::counts();
		$before = isset( $token['counts'] ) ? (array) $token['counts'] : array();
		return array(
			'active'       => max( 0, $now['active'] - (int) ( $before['active'] ?? $now['active'] ) ),
			'pending'      => max( 0, $now['pending'] - (int) ( $before['pending'] ?? $now['pending'] ) ),
			'unclassified' => count( $results['unclassified'] ),
		);
	}

	/**
	 * Unclassified resources keyed by host (they are stored as a list).
	 *
	 * @param array $list Items with a 'host'.
	 * @return array
	 */
	private static function keyed_by_host( array $list ) {
		$out = array();
		foreach ( $list as $item ) {
			if ( isset( $item['host'] ) ) {
				$out[ $item['host'] ] = $item;
			}
		}
		return $out;
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
	 * Pages to scan: home, cookie policy, the pages in your menus (where contact forms, booking
	 * widgets and maps usually are), then the latest pages and posts.
	 *
	 * @return array At most 15 URLs (the browser scan opens the first 10).
	 */
	public static function urls() {
		$urls   = array( home_url( '/' ) );
		$policy = SCCM_Frontend::policy_url();
		if ( $policy ) {
			$urls[] = $policy;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( get_nav_menu_locations() as $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
				$url = isset( $item->url ) ? strtok( (string) $item->url, '#' ) : '';
				if ( $url && wp_parse_url( $url, PHP_URL_HOST ) === $host ) {
					$urls[] = $url;
				}
			}
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
		return array_slice( array_values( array_unique( array_filter( apply_filters( 'sccm_scan_urls', $urls ) ) ) ), 0, 15 );
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

		$sent = self::mail( self::build_email( $queue ) );
		if ( $sent ) {
			update_option( 'sccm_last_alert', time(), false );
			delete_option( 'sccm_pending_alert' );
		}
		return $sent;
	}

	/**
	 * Send a sample email so the site owner can see the design and check delivery.
	 *
	 * @return bool
	 */
	public static function send_test() {
		$now   = time();
		$queue = array(
			array(
				'name'     => 'example_tracking_id',
				'type'     => 'cookie',
				'status'   => 'pending',
				'category' => '',
				'time'     => $now,
			),
			array(
				'name'     => '_ga',
				'type'     => 'cookie',
				'status'   => 'active',
				'category' => 'analytics',
				'time'     => $now,
			),
			array(
				'name'     => '_fbp',
				'type'     => 'cookie',
				'status'   => 'active',
				'category' => 'marketing',
				'time'     => $now,
			),
		);
		$email = self::build_email( $queue, true );
		return self::mail( $email );
	}

	/**
	 * Send an email built by build_email() to every recipient (HTML + plain-text version).
	 *
	 * @param array $email subject, html, text.
	 * @return bool
	 */
	private static function mail( array $email ) {
		$alt = static function ( $phpmailer ) use ( $email ) {
			$phpmailer->AltBody = $email['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( SCCM_Settings::alert_recipients(), $email['subject'], $email['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return (bool) $sent;
	}

	/**
	 * Build the digest: subject, HTML (inline styles, table layout for email clients) and text.
	 *
	 * @param array $queue Items from SCCM_Cookies::queue_alert().
	 * @param bool  $test  Mark the email as a sample.
	 * @return array subject, html, text.
	 */
	public static function build_email( array $queue, $test = false ) {
		$groups = array(
			'pending'      => array(),
			'active'       => array(),
			'unclassified' => array(),
		);
		foreach ( $queue as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : 'active';
			if ( ! isset( $groups[ $status ] ) ) {
				continue;
			}
			$groups[ $status ][ $item['type'] . ':' . $item['name'] ] = $item;
		}
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$review   = admin_url( 'admin.php?page=sccm&tab=cookies' );
		$settings = admin_url( 'admin.php?page=sccm&tab=settings' );
		$n_review = count( $groups['pending'] );
		$n_auto   = count( $groups['active'] );
		$n_other  = count( $groups['unclassified'] );

		if ( $n_review ) {
			/* translators: 1: site name, 2: number of cookies */
			$subject = sprintf( _n( '[%1$s] %2$d cookie needs your review', '[%1$s] %2$d cookies need your review', $n_review, 'smart-cookie-consent-manager' ), $site, $n_review );
		} else {
			/* translators: 1: site name, 2: number of cookies */
			$subject = sprintf( _n( '[%1$s] Cookie scan: %2$d cookie added automatically', '[%1$s] Cookie scan: %2$d cookies added automatically', max( 1, $n_auto ), 'smart-cookie-consent-manager' ), $site, max( 1, $n_auto ) );
		}
		if ( $test ) {
			/* translators: %s: subject */
			$subject = sprintf( __( 'Sample: %s', 'smart-cookie-consent-manager' ), $subject );
		}

		$recipients = implode( ', ', SCCM_Settings::alert_recipients() );
		$limit      = 25;

		/* ---- HTML
		 * Built like a bulletproof email: tables, inline styles and bgcolor attributes. A light design
		 * (no dark header) so mail apps that invert colours in dark mode (Outlook, Gmail apps) still
		 * show it correctly, plus real dark-mode colours for apps that support them (Apple Mail,
		 * Outlook.com via [data-ogsc]/[data-ogsb], Outlook for Mac). The button gets its padding from
		 * its table cell, which Outlook for Windows respects.
		 */
		$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$mono = 'font-family:Menlo,Consolas,Monaco,monospace;';

		$h  = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">';
		$h .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
		$h .= '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">';
		$h .= '<title>' . esc_html( $subject ) . '</title>';
		$h .= '<style>'
			. ':root{color-scheme:light dark;supported-color-schemes:light dark;}'
			. 'a{text-decoration:none;}'
			. '@media (prefers-color-scheme:dark){'
			. '.sccm-e-page{background:#111827 !important;}'
			. '.sccm-e-card,.sccm-e-row{background:#1f2937 !important;border-color:#374151 !important;}'
			. '.sccm-e-h{color:#f9fafb !important;}'
			. '.sccm-e-p{color:#d1d5db !important;}'
			. '.sccm-e-muted{color:#9ca3af !important;}'
			. '.sccm-e-stat{background:#111827 !important;}'
			. '.sccm-e-link{color:#93c5fd !important;}'
			. '.sccm-e-pill-pending{color:#fbbf24 !important;border-color:#fbbf24 !important;}'
			. '.sccm-e-pill-active{color:#4ade80 !important;border-color:#4ade80 !important;}'
			. '}'
			. '[data-ogsb] .sccm-e-page{background:#111827 !important;}'
			. '[data-ogsb] .sccm-e-card,[data-ogsb] .sccm-e-row{background:#1f2937 !important;}'
			. '[data-ogsc] .sccm-e-h{color:#f9fafb !important;}'
			. '[data-ogsc] .sccm-e-p{color:#d1d5db !important;}'
			. '[data-ogsc] .sccm-e-muted{color:#9ca3af !important;}'
			. '@media (max-width:620px){.sccm-e-pad{padding-left:18px !important;padding-right:18px !important;}.sccm-e-stat{display:block !important;width:auto !important;margin-bottom:10px !important;}.sccm-e-gap{display:none !important;}}'
			. '</style></head>';
		$h .= '<body class="sccm-e-page" style="margin:0;padding:0;background:#f3f4f6;-webkit-text-size-adjust:100%;">';

		// Preview line shown by mail apps next to the subject.
		$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . esc_html( $subject ) . '</div>';

		$h .= '<table role="presentation" class="sccm-e-page" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6" style="background:#f3f4f6;"><tr><td align="center" style="padding:24px 12px;">';
		$h .= '<table role="presentation" class="sccm-e-card" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;">';

		// Header: coloured line, site name, title.
		$h .= '<tr><td height="4" bgcolor="#2563eb" style="height:4px;line-height:4px;font-size:4px;background:#2563eb;border-radius:10px 10px 0 0;">&nbsp;</td></tr>';
		$h .= '<tr><td class="sccm-e-pad" style="padding:24px 32px 8px;">';
		$h .= '<div class="sccm-e-muted" style="' . $font . 'font-size:12px;line-height:16px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;">' . esc_html( $site ) . '</div>';
		$h .= '<div class="sccm-e-h" style="' . $font . 'font-size:22px;line-height:28px;font-weight:700;color:#111827;margin-top:4px;">' . esc_html__( 'Cookie scan report', 'smart-cookie-consent-manager' ) . '</div>';
		$h .= '</td></tr>';

		// Summary numbers.
		$h .= '<tr><td class="sccm-e-pad" style="padding:16px 32px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$h .= self::email_stat( $n_review, _n( 'needs your review', 'need your review', $n_review, 'smart-cookie-consent-manager' ), $n_review ? '#d97706' : '#9ca3af' );
		$h .= '<td class="sccm-e-gap" width="12" style="width:12px;">&nbsp;</td>';
		$h .= self::email_stat( $n_auto, __( 'added automatically', 'smart-cookie-consent-manager' ), '#16a34a' );
		$h .= '</tr></table></td></tr>';

		// Needs review.
		if ( $n_review ) {
			$h .= '<tr><td class="sccm-e-pad" style="padding:20px 32px 0;">';
			$h .= self::email_heading( __( 'Needs your review', 'smart-cookie-consent-manager' ), __( 'We could not tell what these cookies are for. Choose a category for each one and click Approve. Until then they are not shown to visitors.', 'smart-cookie-consent-manager' ), $font );
			$h .= self::email_rows( array_slice( $groups['pending'], 0, $limit ), $font, $mono, 'pending' );
			$h .= self::email_more( count( $groups['pending'] ) - $limit, $font );
			$h .= self::email_button( $review, __( 'Review and approve', 'smart-cookie-consent-manager' ), $font );
			$h .= '</td></tr>';
		}

		// Added automatically.
		if ( $n_auto ) {
			$h .= '<tr><td class="sccm-e-pad" style="padding:24px 32px 0;">';
			$h .= self::email_heading( __( 'Added automatically', 'smart-cookie-consent-manager' ), __( 'These are well-known cookies. They were put in the right category for you. Nothing to do unless you disagree.', 'smart-cookie-consent-manager' ), $font );
			$h .= self::email_rows( array_slice( $groups['active'], 0, $limit ), $font, $mono, 'active' );
			$h .= self::email_more( count( $groups['active'] ) - $limit, $font );
			$h .= '</td></tr>';
		}

		// Other third-party resources.
		if ( $n_other ) {
			$hosts = array();
			foreach ( array_slice( $groups['unclassified'], 0, 8 ) as $item ) {
				$hosts[] = $item['name'];
			}
			$h .= '<tr><td class="sccm-e-pad" style="padding:24px 32px 0;">';
			$h .= self::email_heading( __( 'Other third-party resources', 'smart-cookie-consent-manager' ), __( 'External files were found that are not in the service library, so they are not blocked. If one of them tracks visitors, add a blocking rule in the plugin settings.', 'smart-cookie-consent-manager' ), $font );
			$h .= '<div class="sccm-e-p" style="' . $mono . 'font-size:13px;line-height:20px;color:#374151;word-break:break-all;">' . esc_html( implode( ', ', $hosts ) ) . ( $n_other > 8 ? ' …' : '' ) . '</div>';
			$h .= '</td></tr>';
		}

		// Footer.
		$h .= '<tr><td class="sccm-e-pad" style="padding:28px 32px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="sccm-e-row" style="border-top:1px solid #e5e7eb;padding-top:16px;">';
		$h .= '<div class="sccm-e-muted" style="' . $font . 'font-size:12px;line-height:18px;color:#6b7280;">' . esc_html__( 'Sent by Smart Cookie Consent Manager', 'smart-cookie-consent-manager' ) . ' · ' . esc_html( $recipients ) . '</div>';
		$h .= '<div style="' . $font . 'font-size:12px;line-height:18px;margin-top:4px;"><a class="sccm-e-link" href="' . esc_url( $settings ) . '" style="color:#2563eb;text-decoration:underline;">' . esc_html__( 'Change recipients or turn these emails off', 'smart-cookie-consent-manager' ) . '</a></div>';
		$h .= '</td></tr></table></td></tr>';
		$h .= '</table></td></tr></table></body></html>';

		/* ---- Plain text */
		$t = $site . ' — ' . __( 'Cookie scan report', 'smart-cookie-consent-manager' ) . "\n" . str_repeat( '=', 40 ) . "\n\n";
		if ( $n_review ) {
			$t .= __( 'NEEDS YOUR REVIEW', 'smart-cookie-consent-manager' ) . ' (' . $n_review . ")\n";
			$t .= __( 'Choose a category for each cookie and click Approve. Until then visitors do not see them.', 'smart-cookie-consent-manager' ) . "\n";
			foreach ( array_slice( $groups['pending'], 0, $limit ) as $item ) {
				$t .= '  - ' . $item['name'] . ' (' . $item['type'] . ")\n";
			}
			$t .= "\n" . __( 'Review and approve:', 'smart-cookie-consent-manager' ) . ' ' . $review . "\n\n";
		}
		if ( $n_auto ) {
			$t .= __( 'ADDED AUTOMATICALLY', 'smart-cookie-consent-manager' ) . ' (' . $n_auto . ")\n";
			foreach ( array_slice( $groups['active'], 0, $limit ) as $item ) {
				$t .= '  - ' . $item['name'] . ' → ' . SCCM_Categories::label( $item['category'] ) . "\n";
			}
			$t .= "\n";
		}
		if ( $n_other ) {
			$t .= __( 'OTHER THIRD-PARTY RESOURCES (not blocked)', 'smart-cookie-consent-manager' ) . ' (' . $n_other . ")\n";
			foreach ( array_slice( $groups['unclassified'], 0, 8 ) as $item ) {
				$t .= '  - ' . $item['name'] . "\n";
			}
			$t .= "\n";
		}
		$t .= '--' . "\n" . __( 'Change recipients or turn these emails off:', 'smart-cookie-consent-manager' ) . ' ' . $settings . "\n";

		return array(
			'subject' => $subject,
			'html'    => $h,
			'text'    => $t,
		);
	}

	/**
	 * One summary box of the email: a big number and a caption, with a coloured line on the left.
	 *
	 * @param int    $number  Number.
	 * @param string $caption Caption (plain text).
	 * @param string $accent  Accent colour (number + line).
	 * @return string
	 */
	private static function email_stat( $number, $caption, $accent ) {
		$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		return '<td class="sccm-e-stat" width="50%" valign="top" bgcolor="#f9fafb" style="width:50%;background:#f9fafb;border-left:4px solid ' . esc_attr( $accent ) . ';border-radius:6px;padding:14px 16px;">'
			. '<div style="' . $font . 'font-size:30px;line-height:34px;font-weight:700;color:' . esc_attr( $accent ) . ';">' . (int) $number . '</div>'
			. '<div class="sccm-e-p" style="' . $font . 'font-size:13px;line-height:18px;color:#4b5563;margin-top:2px;">' . esc_html( wp_strip_all_tags( $caption ) ) . '</div></td>';
	}

	/**
	 * Section heading and explanation.
	 *
	 * @param string $title Heading.
	 * @param string $text  Explanation.
	 * @param string $font  Font style.
	 * @return string
	 */
	private static function email_heading( $title, $text, $font ) {
		return '<div class="sccm-e-h" style="' . $font . 'font-size:16px;line-height:22px;font-weight:700;color:#111827;">' . esc_html( $title ) . '</div>'
			. '<div class="sccm-e-p" style="' . $font . 'font-size:14px;line-height:21px;color:#4b5563;margin:4px 0 12px;">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Rows of cookie names with a label (type or category) on the right.
	 *
	 * @param array  $items  Items.
	 * @param string $font   Font style.
	 * @param string $mono   Monospace font style.
	 * @param string $status pending|active.
	 * @return string
	 */
	private static function email_rows( array $items, $font, $mono, $status ) {
		$pending = 'pending' === $status;
		$h       = '<table role="presentation" class="sccm-e-row" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;border-collapse:separate;">';
		$i       = 0;
		foreach ( $items as $item ) {
			$border = $i++ ? 'border-top:1px solid #e5e7eb;' : '';
			$label  = $pending
				? ( 'cookie' === $item['type'] ? __( 'Cookie', 'smart-cookie-consent-manager' ) : $item['type'] )
				: SCCM_Categories::label( $item['category'] );
			$color  = $pending ? '#b45309' : '#15803d';
			$h     .= '<tr>'
				. '<td class="sccm-e-row" style="' . $border . 'padding:10px 14px;"><span class="sccm-e-h" style="' . $mono . 'font-size:13px;line-height:18px;color:#111827;word-break:break-all;">' . esc_html( $item['name'] ) . '</span></td>'
				. '<td class="sccm-e-row" align="right" style="' . $border . 'padding:10px 14px;white-space:nowrap;"><span class="sccm-e-pill-' . ( $pending ? 'pending' : 'active' ) . '" style="' . $font . 'font-size:12px;line-height:16px;font-weight:700;color:' . $color . ';border:1px solid ' . $color . ';border-radius:999px;padding:2px 10px;">' . esc_html( $label ) . '</span></td>'
				. '</tr>';
		}
		return $h . '</table>';
	}

	/**
	 * A button that works in every mail app (padding on the cell, mso-padding-alt for Outlook).
	 *
	 * @param string $url   Link.
	 * @param string $label Text.
	 * @param string $font  Font style.
	 * @return string
	 */
	private static function email_button( $url, $label, $font ) {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 4px;"><tr>'
			. '<td align="center" bgcolor="#2563eb" style="background:#2563eb;border-radius:6px;mso-padding-alt:12px 24px;">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" style="' . $font . 'display:inline-block;padding:12px 24px;font-size:15px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">' . esc_html( $label ) . '</a>'
			. '</td></tr></table>';
	}

	/**
	 * "…and N more" line.
	 *
	 * @param int    $more Remaining items.
	 * @param string $font Font style.
	 * @return string
	 */
	private static function email_more( $more, $font ) {
		if ( $more < 1 ) {
			return '';
		}
		/* translators: %d: number of items */
		return '<div class="sccm-e-muted" style="' . $font . 'font-size:13px;line-height:18px;color:#6b7280;margin-top:6px;">' . esc_html( sprintf( __( '…and %d more. See the full list in the plugin.', 'smart-cookie-consent-manager' ), (int) $more ) ) . '</div>';
	}
}
