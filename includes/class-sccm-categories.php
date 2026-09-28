<?php
/**
 * The four fixed cookie categories.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Category definitions and helpers.
 */
class SCCM_Categories {

	/**
	 * Category keys in display order.
	 */
	const KEYS = array( 'necessary', 'functional', 'analytics', 'marketing' );

	/**
	 * Default labels and descriptions.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'necessary'  => array(
				'label'       => __( 'Necessary', 'smart-cookie-consent-manager' ),
				'description' => __( 'Required for the website to work, for example security, page loading and remembering your cookie choice. They cannot be switched off.', 'smart-cookie-consent-manager' ),
			),
			'functional' => array(
				'label'       => __( 'Functional', 'smart-cookie-consent-manager' ),
				'description' => __( 'Remember your settings and provide extra features, such as language preferences, embedded fonts and content.', 'smart-cookie-consent-manager' ),
			),
			'analytics'  => array(
				'label'       => __( 'Analytics', 'smart-cookie-consent-manager' ),
				'description' => __( 'Help us understand how visitors use the website so we can improve it. Information is collected in aggregate.', 'smart-cookie-consent-manager' ),
			),
			'marketing'  => array(
				'label'       => __( 'Tracking / Advertising', 'smart-cookie-consent-manager' ),
				'description' => __( 'Used by advertising and social media partners to measure campaigns and show relevant ads on other websites.', 'smart-cookie-consent-manager' ),
			),
		);
	}

	/**
	 * Resolved categories (custom label/description or defaults) with enabled flag.
	 *
	 * @param bool $only_enabled Skip categories the admin disabled.
	 * @return array Keyed by category key.
	 */
	public static function all( $only_enabled = false ) {
		$settings = (array) SCCM_Settings::get( 'categories' );
		$defaults = self::defaults();
		$out      = array();

		foreach ( self::KEYS as $key ) {
			$custom  = isset( $settings[ $key ] ) ? (array) $settings[ $key ] : array();
			$enabled = ( 'necessary' === $key ) ? 1 : ( isset( $custom['enabled'] ) ? (int) $custom['enabled'] : 1 );
			if ( $only_enabled && ! $enabled ) {
				continue;
			}
			$out[ $key ] = array(
				'key'         => $key,
				'label'       => ! empty( $custom['label'] ) ? $custom['label'] : $defaults[ $key ]['label'],
				'description' => ! empty( $custom['description'] ) ? $custom['description'] : $defaults[ $key ]['description'],
				'enabled'     => $enabled,
				'locked'      => 'necessary' === $key,
			);
		}

		/**
		 * Filter resolved categories.
		 *
		 * @param array $out Categories keyed by key.
		 */
		return apply_filters( 'sccm_categories', $out );
	}

	/**
	 * Label for a category key.
	 *
	 * @param string $key Category key.
	 * @return string
	 */
	public static function label( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ]['label'] : $key;
	}

	/**
	 * Whether the key is a valid category.
	 *
	 * @param string $key Category key.
	 * @return bool
	 */
	public static function is_valid( $key ) {
		return in_array( $key, self::KEYS, true );
	}

	/**
	 * Whether the key is a valid category that visitors can refuse.
	 *
	 * @param string $key Category key.
	 * @return bool
	 */
	public static function is_optional( $key ) {
		return self::is_valid( $key ) && 'necessary' !== $key;
	}

	/**
	 * Google Consent Mode v2 consent types controlled by each category.
	 *
	 * @return array
	 */
	public static function consent_mode_map() {
		return apply_filters(
			'sccm_consent_mode_map',
			array(
				'functional' => array( 'functionality_storage', 'personalization_storage' ),
				'analytics'  => array( 'analytics_storage' ),
				'marketing'  => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
			)
		);
	}
}
