<?php
/**
 * Public endpoints used by the banner (pages are cached, so no nonces):
 *   POST /wp-json/sccm/v1/consent  → consent record
 *   POST /wp-json/sccm/v1/report   → cookie names seen in a visitor's browser (scanner)
 * and the same through admin-ajax.php (actions sccm_consent / sccm_report), which the browser
 * uses when a site blocks the REST API for visitors (security plugins, firewalls).
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
	}

	/**
	 * Option: the last time a visitor's browser could not use the REST API (status, time).
	 */
	const REST_PROBLEM_OPTION = 'sccm_rest_problem';

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
	 * @param mixed $data Decoded JSON payload.
	 * @return array|WP_Error array( ok, logged ) or an error with an HTTP status.
	 */
	public static function store_consent( $data ) {
		if ( ! SCCM_Settings::get( 'log_enabled' ) ) {
			return array(
				'ok'     => true,
				'logged' => false,
			);
		}
		if ( self::rate_limited( 'consent', 60 ) ) {
			return new WP_Error( 'sccm_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sccm_bad_request', 'Invalid payload.', array( 'status' => 400 ) );
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
		if ( ! SCCM_Settings::get( 'scanner_client' ) ) {
			return array( 'ok' => true );
		}
		if ( self::rate_limited( 'report', 10 ) ) {
			return new WP_Error( 'sccm_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		$items = is_array( $data ) && is_array( $data['items'] ?? null ) ? array_slice( $data['items'], 0, 30 ) : array();

		$added = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = isset( $item['n'] ) ? (string) $item['n'] : '';
			$type = isset( $item['t'] ) ? (string) $item['t'] : 'cookie';
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
	 * admin-ajax fallback for consent records (the browser uses it when the REST API is blocked).
	 * Public like the REST route: pages are cached, so there is no nonce; same validation and
	 * rate limit as the REST route.
	 */
	public static function ajax_consent() {
		self::respond( self::store_consent( self::ajax_payload() ) );
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
		$status = isset( $_POST['rest_status'] ) ? absint( $_POST['rest_status'] ) : 0;
		$raw    = isset( $_POST['payload'] ) ? (string) wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, every value is validated by the store function.
		// phpcs:enable
		self::note_rest_problem( $status );
		return strlen( $raw ) <= 20000 ? json_decode( $raw, true ) : null;
	}

	/**
	 * Remember (at most once an hour) that a browser could not use the REST API, so the Consent
	 * Records tab can explain it.
	 *
	 * @param int $status HTTP status the REST request got (0 = network error / blocked).
	 */
	private static function note_rest_problem( $status ) {
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
