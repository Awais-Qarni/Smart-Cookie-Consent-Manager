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
				'description' => __( 'Necessary cookies make the website usable by enabling basic functions such as page navigation, security and remembering your cookie choice. The website cannot work properly without them.', 'smart-cookie-consent-manager' ),
			),
			'functional' => array(
				'label'       => __( 'Preferences', 'smart-cookie-consent-manager' ),
				'description' => __( 'Preference cookies let the website remember choices that change how it looks or behaves, such as your language or region, and provide extra features like embedded maps, fonts and chat.', 'smart-cookie-consent-manager' ),
			),
			'analytics'  => array(
				'label'       => __( 'Statistics', 'smart-cookie-consent-manager' ),
				'description' => __( 'Statistics cookies help us understand how visitors use the website by collecting and reporting information anonymously, so we can improve it.', 'smart-cookie-consent-manager' ),
			),
			'marketing'  => array(
				'label'       => __( 'Marketing', 'smart-cookie-consent-manager' ),
				'description' => __( 'Marketing cookies are used to track visitors across websites. The aim is to show ads that are relevant to you, and to measure how well advertising campaigns perform.', 'smart-cookie-consent-manager' ),
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
