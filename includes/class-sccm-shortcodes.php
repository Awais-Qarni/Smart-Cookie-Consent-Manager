<?php
/**
 * Shortcodes:
 *   [sccm_cookie_policy]                     Full cookie list grouped by category.
 *   [sccm_cookie_settings text="…" style="link|button"]  Opens the preferences window.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode renderers.
 */
class SCCM_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'sccm_cookie_policy', array( __CLASS__, 'policy' ) );
		add_shortcode( 'sccm_cookie_settings', array( __CLASS__, 'settings_link' ) );
	}

	/**
	 * Cookie policy table.
	 *
	 * @param array $atts Attributes: show_empty (yes|no).
	 * @return string
	 */
	public static function policy( $atts = array() ) {
		$atts    = shortcode_atts(
			array(
				'show_empty' => 'no',
			),
			$atts,
			'sccm_cookie_policy'
		);
		$texts   = SCCM_Settings::texts();
		$grouped = SCCM_Cookies::grouped();
		$updated = '';
		foreach ( SCCM_Cookies::query( array( 'status' => 'active' ) ) as $row ) {
			if ( $row['updated_at'] > $updated ) {
				$updated = $row['updated_at'];
			}
		}

		ob_start();
		echo '<div class="sccm-root sccm-policy">';
		foreach ( SCCM_Categories::all( true ) as $key => $cat ) {
			$cookies = $grouped[ $key ];
			if ( ! $cookies && 'yes' !== $atts['show_empty'] ) {
				continue;
			}
			echo '<h3 class="sccm-policy__category">' . esc_html( $cat['label'] ) . '</h3>';
			echo '<p>' . esc_html( $cat['description'] ) . '</p>';
			if ( ! $cookies ) {
				echo '<p class="sccm-muted">' . esc_html( $texts['no_cookies'] ) . '</p>';
				continue;
			}
			echo '<table class="sccm-table"><thead><tr>';
			foreach ( array( 'col_name', 'col_provider', 'col_purpose', 'col_duration' ) as $col ) {
				echo '<th scope="col">' . esc_html( $texts[ $col ] ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $cookies as $row ) {
				echo '<tr>';
				echo '<td data-label="' . esc_attr( $texts['col_name'] ) . '"><code>' . esc_html( $row['name'] ) . '</code>' . ( 'cookie' !== $row['type'] ? ' <small>(' . esc_html( $row['type'] ) . ')</small>' : '' ) . '</td>';
				echo '<td data-label="' . esc_attr( $texts['col_provider'] ) . '">' . esc_html( $row['provider'] ) . '</td>';
				echo '<td data-label="' . esc_attr( $texts['col_purpose'] ) . '">' . esc_html( $row['purpose'] ) . '</td>';
				echo '<td data-label="' . esc_attr( $texts['col_duration'] ) . '">' . esc_html( $row['duration'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		if ( $updated ) {
			/* translators: %s: date */
			echo '<p class="sccm-muted">' . esc_html( sprintf( __( 'Cookie list last updated: %s', 'smart-cookie-consent-manager' ), mysql2date( get_option( 'date_format' ), get_date_from_gmt( $updated ) ) ) ) . '</p>';
		}
		echo '<p>' . self::settings_link( array() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Link/button that opens the preferences window.
	 *
	 * @param array $atts text, style.
	 * @return string
	 */
	public static function settings_link( $atts = array() ) {
		$atts  = shortcode_atts(
			array(
				'text'  => SCCM_Settings::text( 'settings_button' ),
				'style' => 'link',
			),
			$atts,
			'sccm_cookie_settings'
		);
		$class = 'sccm-open-preferences' . ( 'button' === $atts['style'] ? ' sccm-btn sccm-root' : '' );
		return '<a href="#sccm-preferences" class="' . esc_attr( $class ) . '" data-sccm-open role="button">' . esc_html( $atts['text'] ) . '</a>';
	}
}
