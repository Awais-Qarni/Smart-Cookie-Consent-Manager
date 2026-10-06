<?php
/**
 * Shortcodes:
 *   [sccm_cookie_policy]                     Full cookie list grouped by category.
 *   [sccm_cookie_settings text="…" style="link|button"]  Opens the cookie settings window.
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

		// Cookie Policy page: full width, no widget sidebars (setting "Hide the sidebar").
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'is_active_sidebar', array( __CLASS__, 'filter_sidebar' ), 99 );
		add_filter( 'sidebars_widgets', array( __CLASS__, 'filter_sidebars_widgets' ), 99 );
		// Themes with their own per-page layout setting.
		add_filter( 'astra_page_layout', array( __CLASS__, 'theme_layout' ), 99 );
		add_filter( 'generate_sidebar_layout', array( __CLASS__, 'theme_layout' ), 99 );
		add_filter( 'ocean_post_layout', array( __CLASS__, 'theme_layout_ocean' ), 99 );
	}

	/**
	 * Whether the current front-end request shows the Cookie Policy page chosen in the settings.
	 *
	 * @return bool
	 */
	public static function is_policy_page() {
		if ( is_admin() || ! did_action( 'wp' ) ) {
			return false;
		}
		$page_id = (int) SCCM_Settings::get( 'policy_page_id' );
		return $page_id > 0 && is_page( $page_id );
	}

	/**
	 * Whether sidebars are hidden on this request.
	 *
	 * @return bool
	 */
	private static function hide_sidebar() {
		return SCCM_Settings::get( 'policy_hide_sidebar' ) && self::is_policy_page();
	}

	/**
	 * Body class for styling the Cookie Policy page.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( self::is_policy_page() ) {
			$classes[] = 'sccm-policy-page';
		}
		return $classes;
	}

	/**
	 * No active sidebar on the Cookie Policy page (most themes then leave the sidebar out).
	 *
	 * @param bool $active Whether the sidebar has widgets.
	 * @return bool
	 */
	public static function filter_sidebar( $active ) {
		return self::hide_sidebar() ? false : $active;
	}

	/**
	 * No widgets in any sidebar on the Cookie Policy page (for themes that print sidebars without
	 * asking is_active_sidebar()).
	 *
	 * @param array $sidebars Sidebar id => widget ids.
	 * @return array
	 */
	public static function filter_sidebars_widgets( $sidebars ) {
		if ( ! is_array( $sidebars ) || ! self::hide_sidebar() ) {
			return $sidebars;
		}
		foreach ( $sidebars as $id => $widgets ) {
			if ( 'wp_inactive_widgets' !== $id && 'array_version' !== $id ) {
				$sidebars[ $id ] = array();
			}
		}
		return $sidebars;
	}

	/**
	 * Astra / GeneratePress: "no-sidebar" layout on the Cookie Policy page.
	 *
	 * @param string $layout Layout.
	 * @return string
	 */
	public static function theme_layout( $layout ) {
		return self::hide_sidebar() ? 'no-sidebar' : $layout;
	}

	/**
	 * OceanWP: "full-width" layout on the Cookie Policy page.
	 *
	 * @param string $layout Layout.
	 * @return string
	 */
	public static function theme_layout_ocean( $layout ) {
		return self::hide_sidebar() ? 'full-width' : $layout;
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
		// Not .sccm-root: the list takes its fonts, colours and spacing from the theme.
		echo '<div class="sccm-policy">';
		foreach ( SCCM_Categories::all( true ) as $key => $cat ) {
			$cookies = $grouped[ $key ];
			if ( ! $cookies && 'yes' !== $atts['show_empty'] ) {
				continue;
			}
			echo '<h3 class="sccm-policy__category">' . esc_html( $cat['label'] ) . '</h3>';
			echo '<p>' . esc_html( $cat['description'] ) . '</p>';
			if ( ! $cookies ) {
				echo '<p class="sccm-policy__empty">' . esc_html( $texts['no_cookies'] ) . '</p>';
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
			echo '<p class="sccm-policy__updated">' . esc_html( sprintf( __( 'Cookie list last updated: %s', 'smart-cookie-consent-manager' ), mysql2date( get_option( 'date_format' ), get_date_from_gmt( $updated ) ) ) ) . '</p>';
		}
		echo '<p>' . self::settings_link( array() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Link/button that opens the cookie settings window.
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
