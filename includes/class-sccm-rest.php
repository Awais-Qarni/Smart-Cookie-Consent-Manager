<?php
/**
 * Public endpoints used by the banner (pages are cached, so no nonces):
 *   POST /wp-json/sccm/v1/consent  → consent record
 *   POST /wp-json/sccm/v1/report   → cookie names seen in a visitor's browser (scanner)
 * and the same through admin-ajax.php (actions sccm_consent / sccm_report), which the browser
 * uses when a site blocks the REST API for visitors (security plugins, firewalls). When the
 * block is known up front (see transport()), the browser goes straight to admin-ajax.php.
 *
 * Protected by strict validation, payload limits and per-IP rate limiting.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class SCCM_REST {

	const NS = 'sccm/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'consent', 'report' ) as $action ) {
			add_action( 'wp_ajax_nopriv_sccm_' . $action, array( __CLASS__, 'ajax_' . $action ) );
			add_action( 'wp_ajax_sccm_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		// A plugin or its settings changed: check again whether visitors can use the REST API.
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'sccm_settings_saved', 'update_option_admin_site_enhancements' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_rest_check' ) );
		}
	}

	/**
	 * Option: whether the REST API is blocked for logged-out visitors
	 * (blocked: bool, by: 'filter' | 'browser' | 'none', time).
	 */
	const REST_CHECK_OPTION = 'sccm_rest_check';

	/**
	 * Seconds a check result is reused (one hour).
	 */
	const REST_CHECK_TTL = 3600;

	/**
	 * Seconds a result reported by browsers is kept (30 days): a REST request failed with
	 * 401/403/404 and admin-ajax.php worked. A firewall block cannot be checked in PHP.
	 */
	const REST_CHECK_BROWSER_TTL = 2592000;

	/**
	 * How the browser sends consent records and cookie reports: 'rest' (REST API, admin-ajax.php
	 * if it fails) or 'ajax' (admin-ajax.php only). The same for every visitor, so cache-safe.
	 *
	 * @return string 'rest' or 'ajax'.
	 */
	public static function transport() {
		$mode = SCCM_Settings::get( 'log_transport' );
		if ( 'rest' === $mode || 'ajax' === $mode ) {
			return $mode;
		}
		$check = self::rest_check();
		return $check['blocked'] ? 'ajax' : 'rest';
	}

	/**
	 * Short text for the Settings tab: what is used now and why.
	 *
	 * @return string
	 */
	public static function transport_note() {
		$mode = SCCM_Settings::get( 'log_transport' );
		if ( 'ajax' === $mode ) {
			return __( 'admin-ajax.php (chosen here).', 'smart-cookie-consent-manager' );
		}
		if ( 'rest' === $mode ) {
			return __( 'the REST API (chosen here).', 'smart-cookie-consent-manager' );
		}
		$check = self::rest_check();
		if ( ! $check['blocked'] ) {
			return __( 'the REST API (visitors can use it).', 'smart-cookie-consent-manager' );
		}
		return 'browser' === $check['by']
			? __( 'admin-ajax.php (visitors\' browsers found the REST API blocked).', 'smart-cookie-consent-manager' )
			: __( 'admin-ajax.php (a plugin blocks the REST API for visitors).', 'smart-cookie-consent-manager' );
	}

	/**
	 * The stored check result, checked again when it is too old.
	 *
	 * @return array{blocked: bool, by: string, time: int}
	 */
	public static function rest_check() {
		$check = get_option( self::REST_CHECK_OPTION );
		if ( is_array( $check ) && isset( $check['blocked'], $check['by'], $check['time'] ) ) {
			$ttl = 'browser' === $check['by'] ? self::REST_CHECK_BROWSER_TTL : self::REST_CHECK_TTL;
			if ( time() - (int) $check['time'] < $ttl ) {
				return $check;
			}
		}
		$blocked = self::rest_blocked_for_visitors();
		return self::save_rest_check( $blocked, $blocked ? 'filter' : 'none' );
	}

	/**
	 * Store a check result. When the answer changes, cached pages still carry the old one, so
	 * the page caches are cleared.
	 *
	 * @param bool   $blocked Whether visitors cannot use the REST API.
	 * @param string $by      'filter', 'browser' or 'none'.
	 * @return array The stored result.
	 */
	private static function save_rest_check( $blocked, $by ) {
		$old   = get_option( self::REST_CHECK_OPTION );
		$check = array(
			'blocked' => (bool) $blocked,
			'by'      => $by,
			'time'    => time(),
		);
		update_option( self::REST_CHECK_OPTION, $check, false );
		if ( is_array( $old ) && isset( $old['blocked'] ) && (bool) $old['blocked'] !== $check['blocked'] ) {
			SCCM_Cache::request();
		}
		return $check;
	}

	/**
	 * A plugin or setting changed: check again on the next page view. A result reported by
	 * browsers is kept (PHP cannot see a firewall block, so a new check would say "not blocked").
	 */
	public static function forget_rest_check() {
		$check = get_option( self::REST_CHECK_OPTION );
		if ( is_array( $check ) && isset( $check['by'] ) && 'browser' !== $check['by'] ) {
			$check['time'] = 0;
			update_option( self::REST_CHECK_OPTION, $check, false );
		}
	}

	/**
	 * Whether a plugin blocks the REST API for logged-out visitors.
	 *
	 * Runs the rest_authentication_errors filter (where plugins such as Admin and Site
	 * Enhancements, Disable REST API or Perfmatters refuse REST requests) the way a logged-out
	 * visitor's request to /sccm/v1/consent would: no current user, no login cookies, that route
	 * in the query vars (plugins allow some routes), and without WordPress's own cookie check,
	 * which is only for logged-in users. The user is swapped on the global, not with
	 * wp_set_current_user(), so no login actions run; everything is restored after, so the page
	 * being built (perhaps for a logged-in administrator) is not affected. Admin and Site
	 * Enhancements is also recognised by its option while it is active (the option stays when
	 * the plugin is deactivated).
	 *
	 * @return bool
	 */
	private static function rest_blocked_for_visitors() {
		global $current_user, $wp;
		$ase = defined( 'ASENHA_VERSION' ) ? get_option( 'admin_site_enhancements' ) : null;
		if ( is_array( $ase ) && ! empty( $ase['disable_rest_api'] ) ) {
			$blocked = true;
		} else {
			$saved_user   = $current_user;
			$saved_cookie = $_COOKIE;
			$has_wp       = $wp instanceof WP;
			$saved_route  = $has_wp && isset( $wp->query_vars['rest_route'] ) ? $wp->query_vars['rest_route'] : null;
			$current_user = new WP_User( 0 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
			foreach ( array( 'LOGGED_IN_COOKIE', 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE' ) as $name ) {
				if ( defined( $name ) ) {
					unset( $_COOKIE[ constant( $name ) ] );
				}
			}
			if ( $has_wp ) {
				$wp->query_vars['rest_route'] = '/' . self::NS . '/consent';
			}
			$core = has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' );
			if ( false !== $core ) {
				remove_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', $core );
			}
			try {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
				$blocked = is_wp_error( apply_filters( 'rest_authentication_errors', null ) );
			} catch ( Throwable $e ) {
				$blocked = false;
			}
			if ( false !== $core ) {
				add_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', $core );
			}
			if ( $has_wp ) {
				if ( null === $saved_route ) {
					unset( $wp->query_vars['rest_route'] );
				} else {
					$wp->query_vars['rest_route'] = $saved_route;
				}
			}
			$_COOKIE      = $saved_cookie;
			$current_user = $saved_user; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		/**
		 * Whether the REST API is blocked for logged-out visitors (records then go to admin-ajax.php).
		 *
		 * @param bool $blocked What the check found.
		 */
		return (bool) apply_filters( 'sccm_rest_blocked', $blocked );
	}

	/**
	 * Option: the last time a visitor's browser could not use the REST API (status, time).
	 */
	const REST_PROBLEM_OPTION = 'sccm_rest_problem';

	/**
	 * Option: the last consent record that could not be saved (code, message, time), shown on the
	 * Consent Records tab so the reason is visible.
	 */
	const CONSENT_PROBLEM_OPTION = 'sccm_consent_problem';

	/**
	 * Register routes.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'consent' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'report' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Store a consent record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function consent( WP_REST_Request $request ) {
		$result = self::store_consent( $request->get_json_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, ! empty( $result['logged'] ) ? 201 : 200 );
	}

	/**
	 * Validate and store a consent record (REST and admin-ajax).
	 *
	 * @param mixed $data  Decoded JSON payload.
	 * @param bool  $limit Apply the per-IP rate limit.
	 * @return array|WP_Error array( ok, logged ) or an error with an HTTP status.
	 */
	public static function store_consent( $data, $limit = true ) {
		$result = self::save_consent( $data, $limit );
		if ( is_wp_error( $result ) ) {
			self::note_consent_problem( $result );
		}
		return $result;
	}

	/**
	 * Remember why a record was not saved (at most once a minute).
	 *
	 * @param WP_Error $error Error.
	 */
	private static function note_consent_problem( WP_Error $error ) {
		$last = get_option( self::CONSENT_PROBLEM_OPTION );
		if ( is_array( $last ) && time() - (int) $last['time'] < MINUTE_IN_SECONDS ) {
			return;
		}
		update_option(
			self::CONSENT_PROBLEM_OPTION,
			array(
				'code'    => $error->get_error_code(),
				'message' => mb_substr( wp_strip_all_tags( $error->get_error_message() ), 0, 300 ),
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Validate and insert.
	 *
	 * @param mixed $data  Decoded payload.
	 * @param bool  $limit Apply the per-IP rate limit.
	 * @return array|WP_Error
	 */
	private static function save_consent( $data, $limit = true ) {
		if ( ! SCCM_Settings::get( 'log_enabled' ) ) {
			return array(
				'ok'     => true,
				'logged' => false,
			);
		}
		if ( $limit && self::rate_limited( 'consent', 60 ) ) {
			return new WP_Error( 'sccm_rate_limited', __( 'Too many records from one IP address within an hour (60). If all visitors reach your server with the same IP address (a proxy or CDN), use the sccm_client_ip filter to read the real visitor IP.', 'smart-cookie-consent-manager' ), array( 'status' => 429 ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sccm_bad_request', __( 'The record arrived unreadable (a security plugin or firewall may change the data sent by the browser).', 'smart-cookie-consent-manager' ), array( 'status' => 400 ) );
		}
		$result = SCCM_Consent_Log::insert(
			array(
				'consent_id' => $data['consent_id'] ?? '',
				'choice'     => $data['choice'] ?? '',
				'categories' => is_array( $data['categories'] ?? null ) ? array_slice( $data['categories'], 0, 10 ) : array(),
				'gpc'        => ! empty( $data['gpc'] ),
				'version'    => $data['version'] ?? 1,
				'url'        => is_string( $data['url'] ?? null ) ? $data['url'] : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return array(
			'ok'     => true,
			'logged' => true,
		);
	}

	/**
	 * Receive cookie names seen in a browser that are not in the registry.
	 *
	 * Cookies only (local storage is plugin-internal state, not tracking, and made the list noisy).
	 * An unknown cookie is listed only after several different visitors reported it, see
	 * SCCM_Cookies::record_seen().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function report( WP_REST_Request $request ) {
		$result = self::store_report( $request->get_json_params() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * Record unknown cookie names reported by a browser (REST and admin-ajax).
	 *
	 * @param mixed $data Decoded JSON payload.
	 * @return array|WP_Error array( ok, added ) or an error with an HTTP status.
	 */
	public static function store_report( $data ) {
		// Never from browsers of people who log in to this WordPress (also checked in the browser):
		// they carry cookies of the admin area and of extensions that are not part of the website.
		if ( ! SCCM_Settings::get( 'scanner_client' ) || self::is_wordpress_user_browser() ) {
			return array( 'ok' => true );
		}
		if ( self::rate_limited( 'report', 10 ) ) {
			return new WP_Error( 'sccm_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		$items = is_array( $data ) && is_array( $data['items'] ?? null ) ? array_slice( $data['items'], 0, 30 ) : array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['n'] ) && is_string( $item['n'] ) && 0 === strpos( $item['n'], 'wp-settings-' ) ) {
				return array( 'ok' => true ); // The same sign, seen in the browser.
			}
		}

		$added = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = isset( $item['n'] ) && is_string( $item['n'] ) ? $item['n'] : '';
			$type = isset( $item['t'] ) && is_string( $item['t'] ) ? $item['t'] : 'cookie';
			if ( ! self::valid_name( $name ) || 'cookie' !== $type ) {
				continue;
			}
			if ( '' !== SCCM_Cookies::record_seen( $name, 'cookie', 'visitor' ) ) {
				++$added;
			}
		}
		return array(
			'ok'    => true,
			'added' => $added,
		);
	}

	/**
	 * Whether this request comes from a browser that is, or was, logged in to this WordPress.
	 *
	 * Plugins load support chats and analytics on their admin pages (e.g. a chat on a cache
	 * plugin's settings page), and those cookies stay in the browser after logging out. Signs:
	 * a logged-in user (read from the login cookie too: a REST request without a nonce has no
	 * current user), or a wp-settings-* cookie, which WordPress sets in the admin area and keeps
	 * for a year, also after logging out.
	 *
	 * @return bool
	 */
	public static function is_wordpress_user_browser() {
		if ( is_user_logged_in() || wp_validate_auth_cookie( '', 'logged_in' ) ) {
			return true;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wp-settings-' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * admin-ajax fallback for consent records (the browser uses it when the REST API is blocked).
	 * Public like the REST route: pages are cached, so there is no nonce; same validation and
	 * rate limit as the REST route.
	 */
	public static function ajax_consent() {
		// The admin's "Test record saving" request carries a one-time pass: it does not count
		// against (or get stopped by) the per-IP limit meant for visitors.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked against a one-time stored value.
		$pass  = isset( $_POST['sccm_test'] ) ? sanitize_key( wp_unslash( $_POST['sccm_test'] ) ) : '';
		$limit = true;
		if ( '' !== $pass && get_transient( 'sccm_record_test_' . $pass ) ) {
			delete_transient( 'sccm_record_test_' . $pass );
			$limit = false;
		}
		self::respond( self::store_consent( self::ajax_payload(), $limit ) );
	}

	/**
	 * admin-ajax fallback for visitor cookie reports.
	 */
	public static function ajax_report() {
		self::respond( self::store_report( self::ajax_payload() ) );
	}

	/**
	 * Payload of an admin-ajax fallback request; notes why the REST API could not be used.
	 *
	 * @return mixed Decoded JSON or null.
	 */
	private static function ajax_payload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public endpoint (cached pages), see the class comment.
		// "skip": the browser was told to use admin-ajax.php (transport()), not a REST failure.
		$skipped = isset( $_POST['rest_status'] ) && 'skip' === $_POST['rest_status'];
		$status  = isset( $_POST['rest_status'] ) ? absint( $_POST['rest_status'] ) : 0;
		$raw    = isset( $_POST['payload'] ) ? (string) wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, every value is validated by the store function.
		$data   = strlen( $raw ) <= 20000 ? json_decode( $raw, true ) : null;
		// Firewalls sometimes change JSON in form data: the browser also sends plain fields (f_*).
		if ( ! is_array( $data ) && isset( $_POST['f_consent_id'] ) ) {
			$data = array();
			foreach ( array( 'consent_id', 'choice', 'categories', 'gpc', 'version', 'url' ) as $key ) {
				$data[ $key ] = isset( $_POST[ 'f_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'f_' . $key ] ) ) : '';
			}
			$data['categories'] = array_filter( explode( ',', $data['categories'] ) );
			$data['url']        = isset( $_POST['f_url'] ) ? esc_url_raw( wp_unslash( $_POST['f_url'] ) ) : '';
		}
		// phpcs:enable
		if ( ! $skipped ) {
			self::note_rest_problem( $status );
		}
		return $data;
	}

	/**
	 * Remember (at most once an hour) that a browser could not use the REST API, so the Consent
	 * Records tab can explain it.
	 *
	 * @param int $status HTTP status the REST request got (0 = network error / blocked).
	 */
	private static function note_rest_problem( $status ) {
		if ( in_array( (int) $status, array( 401, 403, 404 ), true ) && 'auto' === SCCM_Settings::get( 'log_transport' ) ) {
			$check = get_option( self::REST_CHECK_OPTION );
			if ( ! is_array( $check ) || empty( $check['blocked'] ) ) {
				// The REST API refuses visitors and admin-ajax.php works: send there from now on.
				self::save_rest_check( true, 'browser' );
			}
		}
		$last = get_option( self::REST_PROBLEM_OPTION );
		if ( is_array( $last ) && time() - (int) $last['time'] < HOUR_IN_SECONDS ) {
			return;
		}
		$status = $status < 600 ? (int) $status : 0;
		update_option(
			self::REST_PROBLEM_OPTION,
			array(
				'status' => $status,
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * Send an admin-ajax JSON reply.
	 *
	 * @param array|WP_Error $result Store result.
	 */
	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error( array( 'message' => $result->get_error_message() ), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Accept only plausible cookie/storage names.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function valid_name( $name ) {
		return strlen( $name ) >= 1 && strlen( $name ) <= 100 && (bool) preg_match( '/^[A-Za-z0-9_\-\.\[\]:@$%~|]+$/', $name );
	}

	/**
	 * Simple per-IP, per-hour rate limit using transients.
	 *
	 * @param string $bucket Endpoint name.
	 * @param int    $limit  Requests per hour.
	 * @return bool True when the limit is exceeded.
	 */
	private static function rate_limited( $bucket, $limit ) {
		$ip = SCCM_Consent_Log::client_ip();
		if ( '' === $ip ) {
			return false;
		}
		$key   = 'sccm_rl_' . $bucket . '_' . substr( md5( $ip . wp_salt( 'nonce' ) ), 0, 16 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}
}
