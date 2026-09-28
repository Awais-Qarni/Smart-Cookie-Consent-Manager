<?php
/**
 * Public REST endpoints used by the banner (pages are cached, so no nonces):
 *   POST /wp-json/sccm/v1/consent  → consent record
 *   POST /wp-json/sccm/v1/report   → cookie names seen in a visitor's browser (scanner)
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
	}

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
		if ( ! SCCM_Settings::get( 'log_enabled' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'logged' => false ), 200 );
		}
		if ( self::rate_limited( 'consent', 30 ) ) {
			return new WP_Error( 'sccm_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		$data = $request->get_json_params();
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
		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Receive cookie/localStorage names seen in a browser that are not in the registry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function report( WP_REST_Request $request ) {
		if ( ! SCCM_Settings::get( 'scanner_client' ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		if ( self::rate_limited( 'report', 10 ) ) {
			return new WP_Error( 'sccm_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		$data  = $request->get_json_params();
		$items = is_array( $data['items'] ?? null ) ? array_slice( $data['items'], 0, 30 ) : array();

		$added = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = isset( $item['n'] ) ? (string) $item['n'] : '';
			$type = isset( $item['t'] ) ? (string) $item['t'] : 'cookie';
			if ( ! self::valid_name( $name ) || ! in_array( $type, array( 'cookie', 'localStorage' ), true ) ) {
				continue;
			}
			if ( '' !== SCCM_Cookies::record_seen( $name, $type ) ) {
				++$added;
			}
		}
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'added' => $added,
			),
			200
		);
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
