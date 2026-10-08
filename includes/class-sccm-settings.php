<?php
/**
 * Settings storage, defaults and sanitising.
 *
 * All settings live in one option array (SCCM_Settings::OPTION). Text settings are stored
 * as an empty string when the site owner has not customised them, so the translated
 * default is used (keeps translations working).
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings helper (static).
 */
class SCCM_Settings {

	const OPTION = 'sccm_settings';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// General.
			'enabled'              => 1,
			'position'             => 'bottom',   // bottom | top | bottom-left | bottom-right | center.
			'banner_layout'        => 'compact',  // compact (text + 3 buttons; Customize opens the full dialog) | tabs (full dialog at once).
			'button_order'         => 'accept_reject', // See button_orders(). All buttons always look the same.
			'floating_button'      => 1,
			'floating_position'    => 'bottom-left', // bottom-left | bottom-right | bottom-center | left-center | right-center.
			'policy_page_id'       => 0,
			'policy_hide_sidebar'  => 1,          // Hide widget sidebars on the Cookie Policy page.
			'show_privacy_link'    => 1,
			'consent_expiry_days'  => 365,        // 0 = session only; 1-395 days.
			'reask_on_change'      => 1,
			'reject_grace_days'    => 0,
			'reload_on_withdraw'   => 1,

			// Appearance.
			'color_background'     => '#ffffff',
			'color_text'           => '#1f2937',
			'color_button_bg'      => '#1f2937',
			'color_button_text'    => '#ffffff',
			'color_link'           => '#1d4ed8',
			'color_toggle_on'      => '#15803d',
			'border_radius'        => 8,
			'custom_css'           => '',

			// Texts ('' = use translated default).
			'texts'                => array_fill_keys( array_keys( self::default_texts() ), '' ),

			// Categories (label/description '' = default; enabled only for optional ones).
			'categories'           => array(
				'functional' => array(
					'enabled'     => 1,
					'label'       => '',
					'description' => '',
				),
				'analytics'  => array(
					'enabled'     => 1,
					'label'       => '',
					'description' => '',
				),
				'marketing'  => array(
					'enabled'     => 1,
					'label'       => '',
					'description' => '',
				),
				'necessary'  => array(
					'enabled'     => 1,
					'label'       => '',
					'description' => '',
				),
			),

			// Google Consent Mode & tags.
			'consent_mode'         => 'basic',    // basic (block Google tags) | advanced | off.
			'ads_data_redaction'   => 1,
			'url_passthrough'      => 0,
			'gtm_id'               => '',
			'ga4_id'               => '',

			// Blocking.
			'blocker_enabled'      => 1,
			'disabled_services'    => array(),
			'rules'                => array(),    // list of array( 'pattern' => '', 'category' => '' ).
			'iframe_placeholder'   => 1,

			// Privacy signals.
			'gpc_enabled'          => 1,
			'gpc_scope'            => 'all',      // all | marketing.

			// Scanner.
			'scanner_client'       => 1,
			'learn_visitors'       => 2,          // Different visitors who must report a cookie before it is listed.
			'learn_days'           => 14,         // ...within this many days.
			'scan_schedule'        => 'weekly',   // daily | weekly | off.
			'alerts_enabled'       => 1,
			'alert_hour'           => 9,          // Daily email time (0-23, site time zone); sent only when something changed.
			'alert_email'          => '',
			'alert_include_admin'  => 1,          // Also send to the site admin email.

			// Consent log.
			'log_enabled'          => 1,
			'ip_mode'              => 'anonymize', // anonymize | hash | full | none.
			'retention_months'     => 24,

			// Advanced.
			'delete_on_uninstall'  => 0,
		);
	}

	/**
	 * Default, translatable visitor-facing texts.
	 *
	 * @return array
	 */
	public static function default_texts() {
		return array(
			// Dialog: header, tabs and buttons.
			'banner_title'         => __( 'This website uses cookies', 'smart-cookie-consent-manager' ),
			'banner_text'          => __( 'We use cookies to make this website work. With your consent, we also use cookies to understand how the site is used and to support our marketing. Choose which cookies you allow, or accept or reject them all. You can change your choice at any time.', 'smart-cookie-consent-manager' ),
			'tab_consent'          => __( 'Consent', 'smart-cookie-consent-manager' ),
			'tab_details'          => __( 'Details', 'smart-cookie-consent-manager' ),
			'tab_about'            => __( 'About', 'smart-cookie-consent-manager' ),
			'btn_accept'           => __( 'Allow all', 'smart-cookie-consent-manager' ),
			'btn_reject'           => __( 'Deny', 'smart-cookie-consent-manager' ),
			'btn_selection'        => __( 'Allow selection', 'smart-cookie-consent-manager' ),
			'btn_customize'        => __( 'Customize', 'smart-cookie-consent-manager' ),
			'about_text'           => __( 'Cookies are small text files that websites store in your browser. The law says we may only store cookies that are strictly necessary for the website to work. For all other types of cookies we need your permission. Some cookies are placed by third-party services that appear on our pages. You can change or withdraw your consent at any time with the cookie settings button.', 'smart-cookie-consent-manager' ),
			// Details tab: cookie cards.
			'no_cookies'           => __( 'No cookies in this category.', 'smart-cookie-consent-manager' ),
			'provider_site'        => __( 'This website', 'smart-cookie-consent-manager' ),
			'col_name'             => __( 'Name', 'smart-cookie-consent-manager' ),
			'col_provider'         => __( 'Provider', 'smart-cookie-consent-manager' ),
			'col_purpose'          => __( 'Purpose', 'smart-cookie-consent-manager' ),
			'col_duration'         => __( 'Maximum storage duration', 'smart-cookie-consent-manager' ),
			'col_type'             => __( 'Type', 'smart-cookie-consent-manager' ),
			'type_cookie'          => __( 'HTTP Cookie', 'smart-cookie-consent-manager' ),
			'type_localStorage'    => __( 'HTML Local Storage', 'smart-cookie-consent-manager' ),
			'type_sessionStorage'  => __( 'HTML Session Storage', 'smart-cookie-consent-manager' ),
			// Links, widget, misc.
			'policy_link'          => __( 'Cookie Policy', 'smart-cookie-consent-manager' ),
			'privacy_link'         => __( 'Privacy Policy', 'smart-cookie-consent-manager' ),
			'settings_button'      => __( 'Cookie settings', 'smart-cookie-consent-manager' ),
			'widget_label'         => __( 'Cookies', 'smart-cookie-consent-manager' ),
			'close'                => __( 'Close', 'smart-cookie-consent-manager' ),
			// About tab: the visitor's consent.
			'consent_status'       => __( 'Your current choice', 'smart-cookie-consent-manager' ),
			'consent_date'         => __( 'Date', 'smart-cookie-consent-manager' ),
			'consent_id'           => __( 'Your consent ID', 'smart-cookie-consent-manager' ),
			'no_choice'            => __( 'You have not made a choice yet.', 'smart-cookie-consent-manager' ),
			'copy'                 => __( 'Copy', 'smart-cookie-consent-manager' ),
			'copied'               => __( 'Copied', 'smart-cookie-consent-manager' ),
			'choice_accept_all'    => __( 'Allowed all cookies', 'smart-cookie-consent-manager' ),
			'choice_reject_all'    => __( 'Denied non-essential cookies', 'smart-cookie-consent-manager' ),
			'choice_custom'        => __( 'Allowed a selection', 'smart-cookie-consent-manager' ),
			'choice_gpc'           => __( 'Browser privacy signal (GPC) honoured', 'smart-cookie-consent-manager' ),
			/* translators: %s: list of cookie categories, e.g. "Statistics, Marketing". */
			'gpc_notice'           => __( 'Your browser sent a Global Privacy Control (GPC) signal. We have honoured it, so the following cookies stay off: %s.', 'smart-cookie-consent-manager' ),
			// Blocked videos / maps.
			/* translators: %s: cookie category name, e.g. "Marketing". */
			'placeholder_text'     => __( 'This content is provided by a third party and may set %s cookies. It is blocked until you allow them.', 'smart-cookie-consent-manager' ),
			'placeholder_btn'      => __( 'Allow and load', 'smart-cookie-consent-manager' ),
		);
	}

	/**
	 * Get all settings or one key (merged with defaults).
	 *
	 * @param string|null $key Setting key or null for all.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Visitor-facing text: custom value or translated default.
	 *
	 * @param string $key Text key.
	 * @return string
	 */
	public static function text( $key ) {
		$texts    = (array) self::get( 'texts' );
		$defaults = self::default_texts();
		if ( ! empty( $texts[ $key ] ) ) {
			return (string) $texts[ $key ];
		}
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * All visitor texts resolved.
	 *
	 * @return array
	 */
	public static function texts() {
		$out = array();
		foreach ( array_keys( self::default_texts() ) as $key ) {
			$out[ $key ] = self::text( $key );
		}
		/**
		 * Filter the visitor-facing texts (e.g. for multilingual plugins).
		 *
		 * @param array $out Texts keyed by id.
		 */
		return apply_filters( 'sccm_texts', $out );
	}

	/**
	 * Save a partial settings array (sanitised) on top of the current settings.
	 *
	 * @param array $input Partial settings.
	 * @return array Saved settings.
	 */
	public static function update( array $input ) {
		$current = self::get();
		$clean   = self::sanitize( $input, $current );
		update_option( self::OPTION, $clean, true );
		self::$cache = null;
		return self::get();
	}

	/**
	 * Reset the request cache (after external option changes).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitise input on top of the current values. Unknown keys are dropped.
	 *
	 * @param array $input   Raw input (partial).
	 * @param array $current Current full settings.
	 * @return array
	 */
	public static function sanitize( array $input, array $current ) {
		$defaults = self::defaults();
		$out      = $current;

		$bools = array( 'enabled', 'floating_button', 'show_privacy_link', 'policy_hide_sidebar', 'reask_on_change', 'reload_on_withdraw', 'ads_data_redaction', 'url_passthrough', 'blocker_enabled', 'iframe_placeholder', 'gpc_enabled', 'scanner_client', 'alerts_enabled', 'alert_include_admin', 'log_enabled', 'delete_on_uninstall' );
		foreach ( $bools as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}

		$enums = array(
			'position'          => array( 'bottom', 'top', 'bottom-left', 'bottom-right', 'center' ),
			'floating_position' => array( 'bottom-left', 'bottom-right', 'bottom-center', 'left-center', 'right-center' ),
			'button_order'      => array_keys( self::button_orders() ),
			'banner_layout'     => array( 'compact', 'tabs' ),
			'consent_mode'      => array( 'basic', 'advanced', 'off' ),
			'gpc_scope'         => array( 'all', 'marketing' ),
			'scan_schedule'     => array( 'daily', 'weekly', 'off' ),
			'ip_mode'           => array( 'anonymize', 'hash', 'full', 'none' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) ) {
				$value       = sanitize_key( $input[ $key ] );
				$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
			}
		}

		$ints = array(
			'policy_page_id'      => array( 0, PHP_INT_MAX ),
			'consent_expiry_days' => array( 0, 395 ),
			'reject_grace_days'   => array( 0, 395 ),
			'border_radius'       => array( 0, 32 ),
			'retention_months'    => array( 0, 120 ),
			'alert_hour'          => array( 0, 23 ),
			'learn_visitors'      => array( 1, 50 ),
			'learn_days'          => array( 1, 90 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], absint( $input[ $key ] ) ) );
			}
		}

		foreach ( array( 'color_background', 'color_text', 'color_button_bg', 'color_button_text', 'color_link', 'color_toggle_on' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$color       = sanitize_hex_color( $input[ $key ] );
				$out[ $key ] = $color ? $color : $defaults[ $key ];
			}
		}

		if ( isset( $input['custom_css'] ) ) {
			$out['custom_css'] = wp_strip_all_tags( (string) $input['custom_css'] );
		}

		if ( isset( $input['gtm_id'] ) ) {
			$gtm           = strtoupper( trim( (string) $input['gtm_id'] ) );
			$out['gtm_id'] = preg_match( '/^GTM-[A-Z0-9]{4,12}$/', $gtm ) ? $gtm : '';
		}
		if ( isset( $input['ga4_id'] ) ) {
			$ga4           = strtoupper( trim( (string) $input['ga4_id'] ) );
			$out['ga4_id'] = preg_match( '/^G-[A-Z0-9]{4,16}$/', $ga4 ) ? $ga4 : '';
		}

		if ( isset( $input['alert_email'] ) ) {
			$out['alert_email'] = implode( ', ', self::parse_emails( (string) $input['alert_email'] ) );
		}

		if ( isset( $input['texts'] ) && is_array( $input['texts'] ) ) {
			foreach ( array_keys( self::default_texts() ) as $key ) {
				if ( isset( $input['texts'][ $key ] ) ) {
					$value = wp_kses( (string) $input['texts'][ $key ], self::allowed_text_html() );
					// Store '' when equal to the default, so translations keep working.
					$out['texts'][ $key ] = ( trim( $value ) === self::default_texts()[ $key ] ) ? '' : trim( $value );
				}
			}
		}

		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			foreach ( SCCM_Categories::KEYS as $key ) {
				if ( ! isset( $input['categories'][ $key ] ) || ! is_array( $input['categories'][ $key ] ) ) {
					continue;
				}
				$cat = $input['categories'][ $key ];
				if ( isset( $cat['label'] ) ) {
					$out['categories'][ $key ]['label'] = sanitize_text_field( $cat['label'] );
				}
				if ( isset( $cat['description'] ) ) {
					$out['categories'][ $key ]['description'] = sanitize_textarea_field( $cat['description'] );
				}
				if ( array_key_exists( 'enabled', $cat ) ) {
					$out['categories'][ $key ]['enabled'] = ( 'necessary' === $key || ! empty( $cat['enabled'] ) ) ? 1 : 0;
				}
			}
		}

		if ( isset( $input['disabled_services'] ) && is_array( $input['disabled_services'] ) ) {
			$known                    = array_keys( SCCM_Services::all() );
			$out['disabled_services'] = array_values( array_intersect( array_map( 'sanitize_key', $input['disabled_services'] ), $known ) );
		}

		if ( isset( $input['rules'] ) && is_array( $input['rules'] ) ) {
			$rules = array();
			foreach ( $input['rules'] as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['pattern'] ) ) {
					continue;
				}
				$pattern  = trim( wp_strip_all_tags( (string) $rule['pattern'] ) );
				$category = isset( $rule['category'] ) ? sanitize_key( $rule['category'] ) : '';
				if ( '' === $pattern || strlen( $pattern ) < 3 || ! SCCM_Categories::is_optional( $category ) ) {
					continue;
				}
				$rules[] = array(
					'pattern'  => mb_substr( $pattern, 0, 255 ),
					'category' => $category,
				);
			}
			$out['rules'] = array_slice( $rules, 0, 200 );
		}

		return $out;
	}

	/**
	 * Split a list of email addresses (commas, semicolons, spaces or new lines) into valid,
	 * unique addresses. At most 10.
	 *
	 * @param string $raw Raw input.
	 * @return array
	 */
	public static function parse_emails( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
			$email = sanitize_email( $candidate );
			if ( $email && is_email( $email ) && ! in_array( strtolower( $email ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $email;
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * Recipients of the scanner email: the configured list, or the site admin email.
	 *
	 * @return array
	 */
	public static function alert_recipients() {
		$raw = (string) self::get( 'alert_email' );
		$own = self::parse_emails( $raw );
		// The site admin gets the email too (setting), and always when no address is listed.
		if ( self::get( 'alert_include_admin' ) || ! $own ) {
			$raw = get_option( 'admin_email' ) . "\n" . $raw;
		}
		return self::parse_emails( $raw );
	}

	/**
	 * Button orders: key => label. "Allow selection" (detailed style) and "Customize" (compact
	 * style) take the same place. All buttons always look the same.
	 *
	 * @return array
	 */
	public static function button_orders() {
		return array(
			'accept_reject' => __( 'Allow all · Deny · Allow selection / Customize', 'smart-cookie-consent-manager' ),
			'reject_accept' => __( 'Deny · Allow all · Allow selection / Customize', 'smart-cookie-consent-manager' ),
			'accept_first'  => __( 'Allow all · Allow selection / Customize · Deny', 'smart-cookie-consent-manager' ),
			'reject_first'  => __( 'Deny · Allow selection / Customize · Allow all', 'smart-cookie-consent-manager' ),
		);
	}

	/**
	 * HTML allowed inside customised texts (links and basic emphasis).
	 *
	 * @return array
	 */
	public static function allowed_text_html() {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
		);
	}

	/**
	 * Export settings + cookie registry as a JSON string (for reuse on other sites).
	 *
	 * @return string
	 */
	public static function export_json() {
		$settings = self::get();
		unset( $settings['policy_page_id'] ); // Site specific.

		$data = array(
			'plugin'   => 'smart-cookie-consent-manager',
			'version'  => SCCM_VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => $settings,
			'cookies'  => SCCM_Cookies::export_rows(),
		);
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Import settings (and optionally cookies) from a JSON export.
	 *
	 * @param string $json          JSON string.
	 * @param bool   $with_cookies  Also import the cookie registry.
	 * @return true|WP_Error
	 */
	public static function import_json( $json, $with_cookies = true ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'smart-cookie-consent-manager' || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new WP_Error( 'sccm_invalid_import', __( 'This file is not a valid Smart Cookie Consent Manager export.', 'smart-cookie-consent-manager' ) );
		}
		$settings = $data['settings'];
		unset( $settings['policy_page_id'] );
		self::update( $settings );

		if ( $with_cookies && ! empty( $data['cookies'] ) && is_array( $data['cookies'] ) ) {
			SCCM_Cookies::import_rows( $data['cookies'] );
		}
		SCCM_Install::schedule_events();
		return true;
	}

	/**
	 * Merge stored values over defaults (one level deep for arrays of arrays).
	 *
	 * @param array $defaults Defaults.
	 * @param array $stored   Stored values.
	 * @return array
	 */
	private static function merge( array $defaults, array $stored ) {
		$out = $defaults;
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			if ( in_array( $key, array( 'texts', 'categories' ), true ) && is_array( $value ) ) {
				foreach ( $value as $sub => $sub_value ) {
					if ( is_array( $sub_value ) && isset( $out[ $key ][ $sub ] ) && is_array( $out[ $key ][ $sub ] ) ) {
						$out[ $key ][ $sub ] = array_merge( $out[ $key ][ $sub ], $sub_value );
					} else {
						$out[ $key ][ $sub ] = $sub_value;
					}
				}
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
