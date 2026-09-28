<?php
/**
 * Front end: boot script, config, assets, optional GTM/GA4 loader.
 *
 * Everything printed here is identical for every visitor (cache-safe). The visitor's
 * consent state is resolved in the browser.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end output.
 */
class SCCM_Frontend {

	/**
	 * Attributes that keep common optimisation/caching plugins away from our scripts.
	 */
	const SKIP_ATTRS = 'data-sccm-skip data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-noptimize="1" data-nowprocket nitro-exclude';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_boot' ), -1000 );
		add_action( 'wp_head', array( __CLASS__, 'print_google_tags' ), -999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
	}

	/**
	 * Whether the consent system should run on this request.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$active = SCCM_Settings::get( 'enabled' ) && ! is_admin() && ! is_feed() && ! is_embed() && ! wp_doing_ajax() && ! self::is_builder_preview();
		/**
		 * Disable the consent system for a request (e.g. a landing page builder preview).
		 *
		 * @param bool $active Whether it runs.
		 */
		return (bool) apply_filters( 'sccm_enabled', $active );
	}

	/**
	 * Detect page-builder editor previews where blocking would break editing.
	 *
	 * @return bool
	 */
	public static function is_builder_preview() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'elementor-preview', 'fl_builder', 'et_fb', 'ct_builder', 'tve', 'vc_editable', 'bricks', 'brizy-edit-iframe' ) as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return true;
			}
		}
		// phpcs:enable
		return is_customize_preview();
	}

	/**
	 * Print config + boot script as the very first thing in <head>.
	 */
	public static function print_boot() {
		if ( ! self::is_active() ) {
			return;
		}
		$config = wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$boot   = self::read_asset( 'assets/js/sccm-boot.js' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded with HEX_TAG; boot file is our own static asset.
		echo "\n<script id=\"sccm-boot\" " . self::SKIP_ATTRS . ">window.SCCM_CONFIG={$config};\n{$boot}</script>\n";
	}

	/**
	 * Optional: load Google Tag Manager / GA4 by ID with the correct consent handling.
	 * Strict mode → tags are blocked until Analytics consent. Advanced → load immediately.
	 */
	public static function print_google_tags() {
		if ( ! self::is_active() ) {
			return;
		}
		$gtm = SCCM_Settings::get( 'gtm_id' );
		$ga4 = SCCM_Settings::get( 'ga4_id' );
		if ( ! $gtm && ! $ga4 ) {
			return;
		}

		$blocked = 'basic' === SCCM_Settings::get( 'consent_mode' );
		$attrs   = $blocked ? 'type="text/plain" data-sccm-category="analytics"' : '';

		if ( $gtm ) {
			$gtm_js = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer'," . wp_json_encode( $gtm ) . ');';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<script id="sccm-gtm" ' . $attrs . '>' . $gtm_js . "</script>\n";
		}
		if ( $ga4 ) {
			$src = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga4 );
			if ( $blocked ) {
				echo '<script id="sccm-ga4-src" type="text/plain" data-sccm-category="analytics" data-sccm-src="' . esc_url( $src ) . '" async></script>' . "\n";
			} else {
				echo '<script id="sccm-ga4-src" src="' . esc_url( $src ) . '" async></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
			}
			$ga4_js = 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};gtag("js",new Date());gtag("config",' . wp_json_encode( $ga4 ) . ');';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<script id="sccm-ga4" ' . $attrs . '>' . $ga4_js . "</script>\n";
		}
	}

	/**
	 * Enqueue the front-end script and styles.
	 */
	public static function enqueue() {
		if ( ! self::is_active() ) {
			return;
		}
		wp_enqueue_style( 'sccm-frontend', SCCM_URL . 'assets/css/sccm-frontend.css', array(), SCCM_VERSION );
		wp_add_inline_style( 'sccm-frontend', self::inline_css() );

		$args = array( 'strategy' => 'defer' );
		wp_enqueue_script( 'sccm-frontend', SCCM_URL . 'assets/js/sccm-frontend.js', array(), SCCM_VERSION, version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ? $args : false );
	}

	/**
	 * Add optimiser-exclusion attributes to our script tag.
	 *
	 * @param string $tag    HTML tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function script_tag( $tag, $handle ) {
		if ( 'sccm-frontend' !== $handle ) {
			return $tag;
		}
		if ( false === strpos( $tag, ' defer' ) ) {
			$tag = str_replace( ' src=', ' defer src=', $tag );
		}
		return str_replace( '<script ', '<script ' . self::SKIP_ATTRS . ' ', $tag );
	}

	/**
	 * Build the config passed to the browser.
	 *
	 * @return array
	 */
	public static function config() {
		$settings   = SCCM_Settings::get();
		$grouped    = SCCM_Cookies::grouped();
		$categories = array();

		foreach ( SCCM_Categories::all( true ) as $key => $cat ) {
			$cookies = array();
			foreach ( $grouped[ $key ] as $row ) {
				$cookies[] = array(
					'n' => $row['name'],
					'p' => $row['provider'],
					'u' => $row['purpose'],
					'd' => $row['duration'],
				);
			}
			$categories[] = array(
				'key'         => $key,
				'label'       => $cat['label'],
				'description' => $cat['description'],
				'locked'      => $cat['locked'],
				'cookies'     => $cookies,
			);
		}

		// Clean-up list: registry + library cookies of optional categories.
		$cleanup = array();
		$seen    = array();
		foreach ( SCCM_Cookies::query( array( 'status' => 'active' ) ) as $row ) {
			if ( 'necessary' !== $row['category'] ) {
				$cleanup[]                                = array(
					'n' => $row['name'],
					't' => $row['type'],
					'c' => $row['category'],
				);
				$seen[ $row['type'] . ':' . $row['name'] ] = true;
			}
		}
		foreach ( SCCM_Services::all() as $service ) {
			if ( 'necessary' === $service['category'] ) {
				continue;
			}
			foreach ( $service['cookies'] as $cookie ) {
				$type = isset( $cookie[3] ) ? $cookie[3] : 'cookie';
				if ( empty( $seen[ $type . ':' . $cookie[0] ] ) ) {
					$cleanup[] = array(
						'n' => $cookie[0],
						't' => $type,
						'c' => $service['category'],
					);
				}
			}
		}

		// Known names for the visitor-side scanner (any status).
		$known = array();
		foreach ( SCCM_Cookies::all_rows() as $row ) {
			$known[] = array(
				'n' => $row['name'],
				't' => $row['type'],
			);
		}

		$config = array(
			'v'           => (int) get_option( 'sccm_consent_version', 1 ),
			'cookie'      => 'sccm_consent',
			'days'        => (int) $settings['consent_expiry_days'],
			'graceDays'   => (int) $settings['reject_grace_days'],
			'reload'      => (bool) $settings['reload_on_withdraw'],
			'position'    => $settings['position'],
			'floating'    => (bool) $settings['floating_button'],
			'floatingPos' => $settings['floating_position'],
			'placeholder' => (bool) $settings['iframe_placeholder'],
			'categories'  => $categories,
			'texts'       => SCCM_Settings::texts(),
			'links'       => array(
				'policy'  => self::policy_url(),
				'privacy' => $settings['show_privacy_link'] ? (string) get_privacy_policy_url() : '',
			),
			'gpc'         => array(
				'on'    => (bool) $settings['gpc_enabled'],
				'scope' => $settings['gpc_scope'],
			),
			'cm'          => array(
				'mode'        => $settings['consent_mode'],
				'map'         => SCCM_Categories::consent_mode_map(),
				'redact'      => (bool) $settings['ads_data_redaction'],
				'passthrough' => (bool) $settings['url_passthrough'],
			),
			'cleanup'     => $cleanup,
			'known'       => $known,
			'log'         => (bool) $settings['log_enabled'],
			'scan'        => (bool) $settings['scanner_client'],
			'rest'        => array(
				'consent' => esc_url_raw( rest_url( 'sccm/v1/consent' ) ),
				'report'  => esc_url_raw( rest_url( 'sccm/v1/report' ) ),
			),
		);

		/**
		 * Filter the browser config.
		 *
		 * @param array $config Config array.
		 */
		return apply_filters( 'sccm_frontend_config', $config );
	}

	/**
	 * Cookie policy page URL (if set and published).
	 *
	 * @return string
	 */
	public static function policy_url() {
		$page_id = (int) SCCM_Settings::get( 'policy_page_id' );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}
		return '';
	}

	/**
	 * CSS variables from the Appearance settings + custom CSS.
	 *
	 * @return string
	 */
	public static function inline_css() {
		$s    = SCCM_Settings::get();
		$text = self::hex_to_rgb( $s['color_text'] );
		$vars = array(
			'--sccm-bg'         => $s['color_background'],
			'--sccm-text'       => $s['color_text'],
			'--sccm-btn-bg'     => $s['color_button_bg'],
			'--sccm-btn-text'   => $s['color_button_text'],
			'--sccm-link'       => $s['color_link'],
			'--sccm-toggle'     => $s['color_toggle_on'],
			'--sccm-radius'     => absint( $s['border_radius'] ) . 'px',
			'--sccm-muted'      => 'rgba(' . $text . ',0.72)',
			'--sccm-border'     => 'rgba(' . $text . ',0.18)',
			'--sccm-soft'       => 'rgba(' . $text . ',0.06)',
			'--sccm-slider-off' => 'rgba(' . $text . ',0.32)',
		);
		$css = '.sccm-root{';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		$css .= '}';
		if ( ! empty( $s['custom_css'] ) ) {
			$css .= "\n" . $s['custom_css'];
		}
		return $css;
	}

	/**
	 * "#1f2937" → "31,41,55".
	 *
	 * @param string $hex Hex colour.
	 * @return string
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return '31,41,55';
		}
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}

	/**
	 * Read a bundled asset file.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private static function read_asset( $relative ) {
		$file = SCCM_PATH . $relative;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}
}
