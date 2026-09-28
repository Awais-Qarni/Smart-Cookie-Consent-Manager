<?php
/**
 * Known services library: blocking patterns + cookie definitions.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access to includes/data/services.php (filterable with `sccm_services`).
 */
class SCCM_Services {

	/**
	 * Cache.
	 *
	 * @var array|null
	 */
	private static $services = null;

	/**
	 * All services keyed by id.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$services ) {
			$data = include SCCM_PATH . 'includes/data/services.php';
			/**
			 * Add or change known services.
			 *
			 * @param array $data Services keyed by id.
			 */
			$data           = apply_filters( 'sccm_services', is_array( $data ) ? $data : array() );
			self::$services = array();
			foreach ( $data as $key => $service ) {
				self::$services[ $key ] = wp_parse_args(
					$service,
					array(
						'name'     => $key,
						'provider' => '',
						'category' => 'marketing',
						'google'   => false,
						'patterns' => array(),
						'cookies'  => array(),
					)
				);
			}
		}
		return self::$services;
	}

	/**
	 * One service.
	 *
	 * @param string $key Service id.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Services whose blocking rules are active.
	 *
	 * @return array
	 */
	public static function active() {
		$disabled = (array) SCCM_Settings::get( 'disabled_services' );
		$mode     = SCCM_Settings::get( 'consent_mode' );
		$out      = array();
		foreach ( self::all() as $key => $service ) {
			if ( in_array( $key, $disabled, true ) || 'necessary' === $service['category'] || empty( $service['patterns'] ) ) {
				continue;
			}
			// In Advanced Consent Mode, Google tags load and run cookieless until consent.
			if ( 'advanced' === $mode && ! empty( $service['google'] ) ) {
				continue;
			}
			$out[ $key ] = $service;
		}
		return $out;
	}

	/**
	 * Services that can be switched on/off in the Blocking tab (have patterns, not necessary).
	 *
	 * @return array
	 */
	public static function active_candidates() {
		return array_filter(
			self::all(),
			function ( $service ) {
				return 'necessary' !== $service['category'] && ! empty( $service['patterns'] );
			}
		);
	}

	/**
	 * Blocking rules: library rules + custom rules from settings.
	 *
	 * @return array List of array( pattern, category, service ).
	 */
	public static function rules() {
		$rules = array();
		foreach ( (array) SCCM_Settings::get( 'rules' ) as $rule ) {
			$rules[] = array(
				'pattern'  => $rule['pattern'],
				'category' => $rule['category'],
				'service'  => '',
			);
		}
		foreach ( self::active() as $key => $service ) {
			foreach ( $service['patterns'] as $pattern ) {
				$rules[] = array(
					'pattern'  => $pattern,
					'category' => $service['category'],
					'service'  => $key,
				);
			}
		}
		/**
		 * Filter the blocking rules.
		 *
		 * @param array $rules List of array( pattern, category, service ).
		 */
		return apply_filters( 'sccm_blocking_rules', $rules );
	}

	/**
	 * Find the service a URL or code snippet belongs to (any category).
	 *
	 * @param string $haystack URL or inline code.
	 * @return string|null Service id.
	 */
	public static function match_resource( $haystack ) {
		foreach ( self::all() as $key => $service ) {
			foreach ( $service['patterns'] as $pattern ) {
				if ( '' !== $pattern && false !== stripos( $haystack, $pattern ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	/**
	 * Find a known cookie definition in the library.
	 *
	 * @param string $name Cookie name.
	 * @return array|null array( service, name, duration, purpose, category, provider ).
	 */
	public static function match_cookie( $name ) {
		foreach ( self::all() as $key => $service ) {
			foreach ( $service['cookies'] as $cookie ) {
				if ( SCCM_Cookies::name_matches( $cookie[0], $name ) ) {
					return array(
						'service'  => $key,
						'name'     => $cookie[0],
						'duration' => isset( $cookie[1] ) ? $cookie[1] : '',
						'purpose'  => isset( $cookie[2] ) ? $cookie[2] : '',
						'type'     => isset( $cookie[3] ) ? $cookie[3] : 'cookie',
						'category' => $service['category'],
						'provider' => $service['provider'],
					);
				}
			}
		}
		return null;
	}
}
