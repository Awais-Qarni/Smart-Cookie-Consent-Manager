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
 * "Needs review". Changes are reported by one email a day, only when there were any.
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
	 * Result of the last alert email: time, ok, to, subject, error.
	 */
	const MAIL_OPTION = 'sccm_last_mail';

	/**
	 * Work in progress of a scan (plan, pages done, results so far).
	 */
	const STATE_OPTION = 'sccm_scan_state';

	/**
	 * Cron hook: the next step of a scan (scheduled scans, and "Scan now" when its page was left).
	 */
	const CONTINUE_EVENT = 'sccm_scan_continue_event';

	/**
	 * The browser part of "Scan now" in progress: token, user, pages, pages done, last activity.
	 * Lets the scan continue when the admin comes back after leaving the page.
	 */
	const BROWSER_OPTION = 'sccm_browser_scan';

	/**
	 * Lock so two requests (the admin page and WP-Cron) never scan at the same time.
	 */
	const LOCK = 'sccm_scan_lock';

	/**
	 * Seconds of scanning per request for scheduled scans and "Scan now" (hosts stop requests
	 * after 30–60 s).
	 */
	const STEP_SECONDS = 20;

	/**
	 * Websites with up to this many pages are scanned completely.
	 */
	const BASE_PAGES = 40;

	/**
	 * Most pages a scan visits (bigger websites get between BASE_PAGES and this).
	 */
	const MAX_PAGES = 80;

	/**
	 * Daily WP-Cron hook: the change email (at the hour chosen in the settings) and log clean-up.
	 */
	const DAILY_EVENT = 'sccm_daily_event';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'sccm_scan_event', array( __CLASS__, 'run_cron' ) );
		add_action( self::CONTINUE_EVENT, array( __CLASS__, 'continue_cron' ) );
		add_action( self::DAILY_EVENT, array( __CLASS__, 'daily' ) );
	}

	/**
	 * Next run of the daily email: today or tomorrow at the hour chosen in the settings (site time).
	 *
	 * @return int Unix time.
	 */
	public static function next_daily_time() {
		$hour = max( 0, min( 23, (int) SCCM_Settings::get( 'alert_hour' ) ) );
		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$at   = $now->setTime( $hour, 0 );
		if ( $at <= $now ) {
			$at = $at->modify( '+1 day' );
		}
		return $at->getTimestamp();
	}

	/**
	 * Forget the queue after a successful email.
	 */
	private static function queue_sent() {
		update_option( 'sccm_last_alert', time(), false );
		delete_option( 'sccm_pending_alert' );
	}

	/**
	 * How many cookies the banner lists, per category (for the email).
	 *
	 * @return array total, by_category (label => count).
	 */
	public static function list_summary() {
		$counts = array();
		$total  = 0;
		foreach ( SCCM_Cookies::grouped() as $key => $rows ) {
			$counts[ SCCM_Categories::label( $key ) ] = count( $rows );
			$total                                   += count( $rows );
		}
		return array(
			'total'       => $total,
			'by_category' => $counts,
		);
	}

	/**
	 * Run a whole server-side scan (scan plan → all pages → result).
	 *
	 * Used by tests and by "Scan now" without JavaScript. Scheduled scans use run_cron() instead,
	 * which works in short steps so a big site never runs into the PHP time limit.
	 *
	 * @param bool $manual True for "Scan now" (shorter timeouts per page).
	 * @return array Results (also stored in the sccm_last_scan option).
	 */
	public static function run( $manual = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$state = self::begin( $manual );
		// One "ask visitors again" at most for the whole scan (steps inside do not bump on their own).
		SCCM_Cookies::bulk(
			static function () {
				while ( ! self::step( 0, true ) ) {
					// step( 0 ) scans one page per call until the plan is done.
				}
			},
			empty( $state['first'] )
		);
		return self::finish();
	}

	/**
	 * Scheduled scan (WP-Cron): start, then work in steps of STEP_SECONDS; what is left continues
	 * in a follow-up cron run, so no single request runs long.
	 */
	public static function run_cron() {
		self::begin( false );
		self::continue_cron();
	}

	/**
	 * Cron: the next step of a scheduled scan.
	 */
	public static function continue_cron() {
		if ( ! get_option( self::STATE_OPTION ) ) {
			return;
		}
		if ( self::step( self::STEP_SECONDS ) ) {
			self::finish();
			return;
		}
		wp_schedule_single_event( time() + 30, self::CONTINUE_EVENT );
	}

	/**
	 * Start a scan: plan the pages and keep the work in progress in an option.
	 *
	 * @param bool $manual "Scan now" (true) or scheduled (false).
	 * @return array State: urls, done, manual, first, results.
	 */
	public static function begin( $manual = false ) {
		wp_clear_scheduled_hook( self::CONTINUE_EVENT );
		$state = array(
			'urls'    => self::urls(),
			'done'    => 0,
			'manual'  => (bool) $manual,
			'first'   => false === get_option( self::RESULT_OPTION ),
			'total'   => self::count_pages(),
			'results' => array(
				'time'         => time(),
				'pages'        => array(),
				'services'     => array(),
				'unclassified' => array(),
				'cookies'      => array(),
				'notes'        => array(),
			),
		);
		update_option( self::STATE_OPTION, $state, false );
		if ( $manual ) {
			self::keep_alive();
		}
		return $state;
	}

	/**
	 * "Scan now": if the admin leaves the page, WP-Cron finishes the server part. Every step the
	 * page makes pushes this back, so it only runs when the page is gone.
	 */
	public static function keep_alive() {
		wp_clear_scheduled_hook( self::CONTINUE_EVENT );
		wp_schedule_single_event( time() + 90, self::CONTINUE_EVENT );
	}

	/**
	 * Scan the next pages of the plan.
	 *
	 * @param int  $seconds     Time budget; 0 = exactly one page.
	 * @param bool $ignore_lock Skip the lock (run() does the whole scan in one request).
	 * @return bool True when every page of the plan was scanned (false also while another request
	 *              holds the lock).
	 */
	public static function step( $seconds, $ignore_lock = false ) {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || empty( $state['urls'] ) || $state['done'] >= count( $state['urls'] ) ) {
			return true;
		}
		if ( ! $ignore_lock ) {
			if ( get_transient( self::LOCK ) ) {
				return false;
			}
			set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		}
		$start   = time();
		$results = $state['results'];
		$results['cookies'] = array_fill_keys( (array) $results['cookies'], true );

		// Stay well inside the PHP time limit of this request (often 30 s): a shorter step, page
		// timeouts that fit in what is left, and no new page when the next one might not fit.
		$limit   = (int) ini_get( 'max_execution_time' );
		$request = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : $start;
		if ( $seconds > 0 && $limit > 0 ) {
			$seconds = max( 1, min( $seconds, (int) floor( $limit / 3 ) ) );
		}

		// Changes found by a scan ask visitors again at most once per step, and not at all on the
		// very first scan (nobody has consented to an older list yet).
		SCCM_Cookies::bulk(
			function () use ( &$state, &$results, $start, $seconds, $limit, $request ) {
				do {
					$timeout = $state['manual'] ? 10 : 15;
					if ( $limit > 0 ) {
						$timeout = max( 2, min( $timeout, $limit - ( time() - $request ) - 8 ) );
					}
					$url = $state['urls'][ $state['done'] ];
					self::scan_pages( array( $url ), $timeout, $results );
					++$state['done'];
				} while ( $seconds > 0 && $state['done'] < count( $state['urls'] ) && time() - $start < $seconds && ( $limit <= 0 || time() - $request + 18 < $limit ) );
				self::add_detected_services( $results );
			},
			empty( $state['first'] )
		);

		$results['cookies'] = array_keys( $results['cookies'] );
		$state['results']   = $results;
		update_option( self::STATE_OPTION, $state, false );
		if ( ! $ignore_lock ) {
			delete_transient( self::LOCK );
		}
		return $state['done'] >= count( $state['urls'] );
	}

	/**
	 * Progress of the scan in progress.
	 *
	 * @return array done, total (pages planned).
	 */
	public static function progress() {
		$state = get_option( self::STATE_OPTION );
		return array(
			'done'  => is_array( $state ) ? (int) $state['done'] : 0,
			'total' => is_array( $state ) ? count( (array) $state['urls'] ) : 0,
		);
	}

	/**
	 * Finish the scan: store the result and report new third-party resources.
	 *
	 * @return array Results.
	 */
	public static function finish() {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) ) {
			$last = get_option( self::RESULT_OPTION );
			return is_array( $last ) ? $last : array();
		}
		$results                 = $state['results'];
		$results['cookies']      = array_values( (array) $results['cookies'] );
		$results['unclassified'] = array_values( (array) $results['unclassified'] );
		$results['site_pages']   = (int) $state['total'];
		// Report only resources that are new since the last scan (not the same list every time).
		$previous = get_option( self::RESULT_OPTION );
		$known    = is_array( $previous ) && ! empty( $previous['unclassified'] ) ? array_column( (array) $previous['unclassified'], 'host' ) : array();
		foreach ( $results['unclassified'] as $item ) {
			if ( ! in_array( $item['host'], $known, true ) ) {
				SCCM_Cookies::queue_alert( $item['host'], 'resource', 'unclassified', '' );
			}
		}
		update_option( self::RESULT_OPTION, $results, false );
		delete_option( self::STATE_OPTION );
		wp_clear_scheduled_hook( self::CONTINUE_EVENT );
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
				'url'  => mb_substr( $url, 0, 300 ),
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
	 * Remember the browser part of "Scan now" (so it can continue after the page was left).
	 *
	 * @param string $token Scan token.
	 * @param array  $urls  Pages to open (with the token).
	 */
	public static function browser_begin( $token, array $urls ) {
		update_option(
			self::BROWSER_OPTION,
			array(
				'token' => $token,
				'user'  => get_current_user_id(),
				'urls'  => array_values( $urls ),
				'done'  => array(),
				'beat'  => time(),
			),
			false
		);
	}

	/**
	 * The browser part in progress for the current user, or null (an expired one is forgotten).
	 *
	 * @return array|null
	 */
	public static function browser_state() {
		$state = get_option( self::BROWSER_OPTION );
		if ( ! is_array( $state ) ) {
			return null;
		}
		if ( ! self::valid_scan_token( $state['token'] ) ) {
			if ( (int) $state['user'] === get_current_user_id() ) {
				delete_option( self::BROWSER_OPTION );
			}
			return null;
		}
		return $state;
	}

	/**
	 * Note pages the browser has finished; keeps the token alive while the scan makes progress.
	 *
	 * @param array $done    Page URLs opened since the last report.
	 * @param bool  $leaving The page running the scan is being left (another page may continue).
	 * @return array Pages still to open.
	 */
	public static function browser_progress( array $done, $leaving = false ) {
		$state = self::browser_state();
		if ( ! $state ) {
			return array();
		}
		$state['done'] = array_values( array_intersect( $state['urls'], array_unique( array_merge( $state['done'], array_map( 'strval', $done ) ) ) ) );
		$state['beat'] = $leaving ? 0 : time();
		update_option( self::BROWSER_OPTION, $state, false );
		$data = get_transient( 'sccm_scan_' . $state['token'] );
		if ( is_array( $data ) ) {
			set_transient( 'sccm_scan_' . $state['token'], $data, 30 * MINUTE_IN_SECONDS );
		}
		return array_values( array_diff( $state['urls'], $state['done'] ) );
	}

	/**
	 * The page running the scan is still there (another tab will not start a second run).
	 */
	public static function browser_touch() {
		$state = get_option( self::BROWSER_OPTION );
		if ( is_array( $state ) && (int) $state['user'] === get_current_user_id() ) {
			$state['beat'] = time();
			update_option( self::BROWSER_OPTION, $state, false );
		}
	}

	/**
	 * The browser part is complete: forget it and its token.
	 */
	public static function browser_end() {
		$state = get_option( self::BROWSER_OPTION );
		if ( is_array( $state ) ) {
			delete_transient( 'sccm_scan_' . $state['token'] );
		}
		delete_option( self::BROWSER_OPTION );
	}

	/**
	 * Create a one-time scan-mode token for the current administrator (30 minutes, renewed while the scan makes progress).
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
			30 * MINUTE_IN_SECONDS
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
	 * Pages the browser scan opens (the same plan as the server part, see urls()).
	 *
	 * @param string $token Scan-mode token.
	 * @return array
	 */
	public static function browser_scan_urls( $token ) {
		$urls = array();
		$state = get_option( self::STATE_OPTION );
		$plan  = is_array( $state ) && ! empty( $state['urls'] ) ? $state['urls'] : self::urls();
		foreach ( $plan as $url ) {
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
	public static function record_browser_scan( array $data, array $token, $token_id = '' ) {
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

		// Reports of one scan arrive in batches: add them up.
		$before                  = isset( $results['browser'] ) && '' !== $token_id && ( $results['browser']['token'] ?? '' ) === $token_id ? $results['browser'] : array();
		$results['unclassified'] = array_values( $results['unclassified'] );
		$results['browser']      = array(
			'token'   => $token_id,
			'time'    => time(),
			'pages'   => (int) ( $before['pages'] ?? 0 ) + absint( $data['pages'] ?? 0 ),
			'blocked' => (int) ( $before['blocked'] ?? 0 ) + absint( $data['blocked'] ?? 0 ),
			'found'   => array_values( array_unique( array_merge( (array) ( $before['found'] ?? array() ), array_keys( $seen ) ) ) ),
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
			$results['services'][ $key ]['examples'][] = mb_substr( $where, 0, 200 );
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
	 * Number of published, public pages and posts of the website (all public post types).
	 *
	 * @return int
	 */
	public static function count_pages() {
		$total = 0;
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $total;
	}

	/**
	 * How many pages a scan visits, from the size of the website: small sites are scanned
	 * completely (up to 40 pages); bigger sites get a few more pages the bigger they are
	 * (100 pages → 55, 300 → 72), never more than 80.
	 *
	 * @param int $total Published pages and posts (count_pages()).
	 * @return int
	 */
	public static function scan_budget( $total ) {
		$total  = max( 0, (int) $total );
		$budget = $total <= self::BASE_PAGES ? $total + 2 : self::BASE_PAGES + (int) round( 2 * sqrt( $total - self::BASE_PAGES ) );
		/**
		 * Number of pages a scan visits.
		 *
		 * @param int $budget Pages (before the 1–100 limit).
		 * @param int $total  Published pages and posts of the website.
		 */
		$budget = (int) apply_filters( 'sccm_scan_budget', min( self::MAX_PAGES, $budget ), $total );
		return max( 1, min( 100, $budget ) );
	}

	/**
	 * The pages to scan, in order:
	 *  1. every main page: home, Cookie Policy, blog page, top-level menu items, top-level pages;
	 *  2. one recent item of each other content type (posts, products…: other templates, other
	 *     scripts);
	 *  3. sub pages and sub-sub pages (menu levels 2 and 3, child and grandchild pages), half the
	 *     remaining budget each, taken from every parent in turn rather than all from one;
	 *  4. if pages are left in the budget: recently updated content.
	 * Small websites are scanned completely. Only pages of this website, no files or admin pages.
	 *
	 * @return string[] URLs.
	 */
	public static function urls() {
		$budget = self::scan_budget( self::count_pages() );
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$main   = array();
		$sub    = array(); // parent => urls.
		$subsub = array(); // parent => urls.
		$types  = array();
		$recent = array();

		$main[] = home_url( '/' );
		$policy = SCCM_Frontend::policy_url();
		if ( $policy ) {
			$main[] = $policy;
		}
		$blog = (int) get_option( 'page_for_posts' );
		if ( $blog ) {
			$main[] = get_permalink( $blog );
		}

		// Menus: level 1 = main, level 2 = sub, level 3+ = sub-sub.
		foreach ( get_nav_menu_locations() as $menu_id ) {
			$items  = (array) wp_get_nav_menu_items( $menu_id );
			$parent = array();
			foreach ( $items as $item ) {
				$parent[ (int) $item->ID ] = (int) $item->menu_item_parent;
			}
			foreach ( $items as $item ) {
				$depth = 0;
				$id    = (int) $item->ID;
				while ( ! empty( $parent[ $id ] ) && $depth < 5 ) {
					$id = $parent[ $id ];
					++$depth;
				}
				$url = isset( $item->url ) ? (string) $item->url : '';
				if ( 0 === $depth ) {
					$main[] = $url;
				} elseif ( 1 === $depth ) {
					$sub[ 'm' . $item->menu_item_parent ][] = $url;
				} else {
					$subsub[ 'm' . $item->menu_item_parent ][] = $url;
				}
			}
		}

		// Page tree: top-level pages are main pages, children sub pages, grandchildren sub-sub pages.
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'fields'         => 'id=>parent',
				'has_password'   => false,
			)
		);
		foreach ( $pages as $page_id => $parent_id ) {
			$depth = 0;
			$up    = (int) $parent_id;
			while ( $up && $depth < 5 ) {
				++$depth;
				$up = isset( $pages[ $up ] ) ? (int) $pages[ $up ] : 0;
			}
			if ( 0 === $depth ) {
				$main[] = get_permalink( $page_id );
			} elseif ( 1 === $depth ) {
				$sub[ 'p' . $parent_id ][] = get_permalink( $page_id );
			} else {
				$subsub[ 'p' . $parent_id ][] = get_permalink( $page_id );
			}
		}

		// One recent item of every other public content type, then recently updated content.
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( in_array( $type, array( 'page', 'attachment' ), true ) ) {
				continue;
			}
			$latest = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'has_password'   => false,
				)
			);
			if ( $latest ) {
				$types[] = get_permalink( $latest[0] );
			}
		}
		$recent_ids = get_posts(
			array(
				'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
				'post_status'    => 'publish',
				'posts_per_page' => $budget,
				'orderby'        => 'modified',
				'fields'         => 'ids',
				'has_password'   => false,
			)
		);
		foreach ( $recent_ids as $post_id ) {
			$recent[] = get_permalink( $post_id );
		}

		// Fill the budget level by level.
		$plan = array();
		$add  = static function ( $url ) use ( &$plan, $budget, $host ) {
			$url = self::clean_url( (string) $url, $host );
			if ( $url && count( $plan ) < $budget ) {
				$plan[ $url ] = true;
			}
		};
		array_map( $add, $main );
		array_map( $add, $types );
		$left     = $budget - count( $plan );
		$sub_list = self::round_robin( $sub );
		$deep     = self::round_robin( $subsub );
		$deep_len = count( $deep );
		$sub_take = $deep_len ? (int) ceil( $left / 2 ) : $left;
		array_map( $add, array_slice( $sub_list, 0, $sub_take ) );
		array_map( $add, $deep );
		array_map( $add, $sub_list ); // A level with fewer pages leaves its share to the others.
		array_map( $add, $recent );

		/**
		 * Filter the pages a scan visits.
		 *
		 * @param array $urls   URLs, in order.
		 * @param int   $budget Number of pages the scan is meant to visit.
		 */
		$urls = (array) apply_filters( 'sccm_scan_urls', array_keys( $plan ), $budget );
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		return array_slice( $urls, 0, 100 );
	}

	/**
	 * Take one item from each group in turn ("every parent gets a turn").
	 *
	 * @param array $groups Group => list.
	 * @return array
	 */
	private static function round_robin( array $groups ) {
		$out    = array();
		$groups = array_values( array_filter( array_map( 'array_values', $groups ) ) );
		for ( $i = 0; $groups; $i++ ) {
			foreach ( $groups as $key => $list ) {
				if ( isset( $list[ $i ] ) ) {
					$out[] = $list[ $i ];
				} else {
					unset( $groups[ $key ] );
				}
			}
		}
		return $out;
	}

	/**
	 * A URL of this website that is worth scanning (no fragment, files, feeds or admin), or ''.
	 *
	 * @param string $url  URL.
	 * @param string $host This website's host.
	 * @return string
	 */
	private static function clean_url( $url, $host ) {
		$url = (string) strtok( $url, '#' );
		if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/(wp-admin|wp-login\.php|wp-json|feed)(/|$)|\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|pptx?|mp4|mp3|xml|txt|css|js)$#i', $path ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Daily housekeeping: email digest + consent log retention.
	 */
	public static function daily() {
		self::send_digest();
		SCCM_Consent_Log::purge( (int) SCCM_Settings::get( 'retention_months' ) );

		// Keep the chosen hour (a "daily" repeat drifts by an hour when the clocks change).
		$next = wp_next_scheduled( self::DAILY_EVENT );
		if ( $next && (int) wp_date( 'G', $next ) !== (int) SCCM_Settings::get( 'alert_hour' ) ) {
			wp_clear_scheduled_hook( self::DAILY_EVENT );
			wp_schedule_event( self::next_daily_time(), 'daily', self::DAILY_EVENT );
		}
	}

	/**
	 * The daily email: sent only when something changed since the last email (in practice the
	 * last 24 hours): cookies added to the banner, cookies waiting for review, new third-party
	 * resources. No changes, no email. Items stay queued until an email went out.
	 *
	 * @return bool Whether an email was sent.
	 */
	public static function send_digest() {
		$queue = (array) get_option( 'sccm_pending_alert', array() );
		if ( ! $queue || ! SCCM_Settings::get( 'alerts_enabled' ) ) {
			return false;
		}
		$sent = self::mail( self::build_email( $queue, false, self::list_summary() ) );
		if ( $sent ) {
			self::queue_sent();
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
		$email = self::build_email( $queue, true, self::list_summary() );
		return self::mail( $email );
	}

	/**
	 * Send an email built by build_email() to every recipient (HTML + plain-text version), and
	 * remember the result (shown on the Settings tab, so a host that drops mail is noticed).
	 *
	 * @param array $email subject, html, text.
	 * @return bool
	 */
	private static function mail( array $email ) {
		$to    = SCCM_Settings::alert_recipients();
		$error = '';
		$alt   = static function ( $phpmailer ) use ( $email ) {
			$phpmailer->AltBody = $email['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		$fail  = static function ( $wp_error ) use ( &$error ) {
			$error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '';
		};
		add_action( 'phpmailer_init', $alt );
		add_action( 'wp_mail_failed', $fail );
		$sent = $to ? (bool) wp_mail( $to, $email['subject'], $email['html'], array( 'Content-Type: text/html; charset=UTF-8' ) ) : false;
		remove_action( 'phpmailer_init', $alt );
		remove_action( 'wp_mail_failed', $fail );
		if ( ! $to ) {
			$error = __( 'No valid email address.', 'smart-cookie-consent-manager' );
		} elseif ( ! $sent && '' === $error ) {
			$error = __( 'WordPress could not send the email (wp_mail returned false).', 'smart-cookie-consent-manager' );
		}
		update_option(
			self::MAIL_OPTION,
			array(
				'time'    => time(),
				'ok'      => $sent,
				'to'      => $to,
				'subject' => $email['subject'],
				'error'   => $sent ? '' : wp_strip_all_tags( $error ),
			),
			false
		);
		return $sent;
	}

	/**
	 * Build the daily change email: subject, HTML (inline styles, table layout for email clients)
	 * and text. No links into the WordPress admin: some recipients may not have an account.
	 *
	 * @param array      $queue Items from SCCM_Cookies::queue_alert().
	 * @param bool       $test  Mark the email as a sample.
	 * @param array|null $list  list_summary(): how many cookies the banner lists.
	 * @return array subject, html, text.
	 */
	public static function build_email( array $queue, $test = false, $list = null ) {
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
		$n_review = count( $groups['pending'] );
		$n_auto   = count( $groups['active'] );
		$n_other  = count( $groups['unclassified'] );

		if ( $n_review ) {
			/* translators: 1: site name, 2: number of cookies */
			$subject = sprintf( _n( '[%1$s] %2$d new cookie needs review', '[%1$s] %2$d new cookies need review', $n_review, 'smart-cookie-consent-manager' ), $site, $n_review );
		} elseif ( $n_auto ) {
			/* translators: 1: site name, 2: number of cookies */
			$subject = sprintf( _n( '[%1$s] %2$d cookie was added to your cookie banner', '[%1$s] %2$d cookies were added to your cookie banner', $n_auto, 'smart-cookie-consent-manager' ), $site, $n_auto );
		} else {
			/* translators: 1: site name, 2: number of resources */
			$subject = sprintf( _n( '[%1$s] %2$d new third-party resource found', '[%1$s] %2$d new third-party resources found', max( 1, $n_other ), 'smart-cookie-consent-manager' ), $site, max( 1, $n_other ) );
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
		$h .= '<div class="sccm-e-h" style="' . $font . 'font-size:22px;line-height:28px;font-weight:700;color:#111827;margin-top:4px;">' . esc_html__( 'Cookie changes in the last 24 hours', 'smart-cookie-consent-manager' ) . '</div>';
		$h .= '</td></tr>';

		if ( $list ) {
			$h .= '<tr><td class="sccm-e-pad" style="padding:4px 32px 0;">';
			$h .= '<div class="sccm-e-p" style="' . $font . 'font-size:14px;line-height:21px;color:#4b5563;">' . esc_html( self::email_list_line( $list ) ) . '</div>';
			$h .= '</td></tr>';
		}

		// Summary numbers.
		$h .= '<tr><td class="sccm-e-pad" style="padding:16px 32px 8px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>';
		$h .= self::email_stat( $n_review, _n( 'needs review', 'need review', $n_review, 'smart-cookie-consent-manager' ), $n_review ? '#d97706' : '#9ca3af' );
		$h .= '<td class="sccm-e-gap" width="12" style="width:12px;">&nbsp;</td>';
		$h .= self::email_stat( $n_auto, __( 'added automatically', 'smart-cookie-consent-manager' ), '#16a34a' );
		$h .= '</tr></table></td></tr>';

		// Needs review.
		if ( $n_review ) {
			$h .= '<tr><td class="sccm-e-pad" style="padding:20px 32px 0;">';
			$h .= self::email_heading( __( 'Needs review', 'smart-cookie-consent-manager' ), __( 'We could not tell what these cookies are for. Someone who manages the website should choose a category for each one in WordPress under Cookie Consent → Cookies. Until then they are not shown to visitors.', 'smart-cookie-consent-manager' ), $font );
			$h .= self::email_rows( array_slice( $groups['pending'], 0, $limit ), $font, $mono, 'pending' );
			$h .= self::email_more( count( $groups['pending'] ) - $limit, $font );
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
			$h .= self::email_heading( __( 'Other third-party resources', 'smart-cookie-consent-manager' ), __( 'External files were found that are not in the service library, so they are not blocked. If one of them tracks visitors, a blocking rule can be added in WordPress under Cookie Consent → Settings.', 'smart-cookie-consent-manager' ), $font );
			$h .= '<div class="sccm-e-p" style="' . $mono . 'font-size:13px;line-height:20px;color:#374151;word-break:break-all;">' . esc_html( implode( ', ', $hosts ) ) . ( $n_other > 8 ? ' …' : '' ) . '</div>';
			$h .= '</td></tr>';
		}

		// Footer.
		$h .= '<tr><td class="sccm-e-pad" style="padding:28px 32px 28px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td class="sccm-e-row" style="border-top:1px solid #e5e7eb;padding-top:16px;">';
		$h .= '<div class="sccm-e-muted" style="' . $font . 'font-size:12px;line-height:18px;color:#6b7280;">' . esc_html( self::email_footer( $recipients ) ) . '</div>';
		$h .= '</td></tr></table></td></tr>';
		$h .= '</table></td></tr></table></body></html>';

		/* ---- Plain text */
		$t = $site . ' — ' . __( 'Cookie changes in the last 24 hours', 'smart-cookie-consent-manager' ) . "\n" . str_repeat( '=', 40 ) . "\n\n";
		if ( $list ) {
			$t .= self::email_list_line( $list ) . "\n\n";
		}
		if ( $n_review ) {
			$t .= __( 'NEEDS REVIEW', 'smart-cookie-consent-manager' ) . ' (' . $n_review . ")\n";
			$t .= __( 'Someone who manages the website should choose a category for each one in WordPress under Cookie Consent → Cookies. Until then visitors do not see them.', 'smart-cookie-consent-manager' ) . "\n";
			foreach ( array_slice( $groups['pending'], 0, $limit ) as $item ) {
				$t .= '  - ' . $item['name'] . ' (' . $item['type'] . ")\n";
			}
			$t .= "\n";
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
		$t .= '--' . "\n" . self::email_footer( $recipients ) . "\n";

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
	 * Footer: who gets the email and where it is set up (no admin link: recipients may not have an account).
	 *
	 * @param string $recipients Comma-separated addresses.
	 * @return string
	 */
	private static function email_footer( $recipients ) {
		$hour = wp_date( get_option( 'time_format' ), ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTime( (int) SCCM_Settings::get( 'alert_hour' ), 0 )->getTimestamp() );
		/* translators: 1: email addresses, 2: time of day */
		return sprintf( __( 'Sent by Smart Cookie Consent Manager to %1$s. This email is sent once a day at %2$s, only when something changed. The website\'s administrator can change this in WordPress under Cookie Consent → Settings.', 'smart-cookie-consent-manager' ), $recipients, $hour );
	}

	/**
	 * "Your cookie banner now lists N cookies: Necessary 3 · Statistics 2 …"
	 *
	 * @param array $list list_summary().
	 * @return string
	 */
	private static function email_list_line( array $list ) {
		$parts = array();
		foreach ( (array) $list['by_category'] as $label => $count ) {
			$parts[] = $label . ' ' . (int) $count;
		}
		/* translators: 1: number of cookies, 2: count per category, e.g. "Necessary 3 · Statistics 2" */
		return sprintf( _n( 'Your cookie banner now lists %1$d cookie (%2$s).', 'Your cookie banner now lists %1$d cookies (%2$s).', (int) $list['total'], 'smart-cookie-consent-manager' ), (int) $list['total'], implode( ' · ', $parts ) );
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
