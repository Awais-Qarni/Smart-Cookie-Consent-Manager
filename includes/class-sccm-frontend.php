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
		add_filter( 'style_loader_tag', array( __CLASS__, 'style_tag' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'scan_mode_headers' ), 0 );
	}

	/**
	 * Scan mode: the admin's own browser loads a page in a hidden frame (Cookies → Scan now) with
	 * every category allowed, so all scripts run and set their cookies, which the admin page then
	 * reads. Needs a fresh one-time token (SCCM_Scanner::scan_token()) AND an administrator.
	 * Visitors never see it, and nothing is recorded or stored.
	 *
	 * @return bool
	 */
	public static function is_scan_mode() {
		static $mode = null;
		if ( null === $mode ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked against a stored one-time token.
			$token = isset( $_GET['sccm_scan'] ) ? sanitize_key( wp_unslash( $_GET['sccm_scan'] ) ) : '';
			$mode  = '' !== $token && current_user_can( 'manage_options' ) && SCCM_Scanner::valid_scan_token( $token );
		}
		return $mode;
	}

	/**
	 * Never cache a scan-mode page.
	 */
	public static function scan_mode_headers() {
		if ( self::is_scan_mode() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
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
		list( $css_url, $css_ver ) = SCCM_Plugin::asset( 'assets/css/sccm-frontend.css' );
		wp_enqueue_style( 'sccm-frontend', $css_url, array(), $css_ver );
		wp_add_inline_style( 'sccm-frontend', self::inline_css() );

		$args = array( 'strategy' => 'defer' );
		list( $js_url, $js_ver ) = SCCM_Plugin::asset( 'assets/js/sccm-frontend.js' );
		wp_enqueue_script( 'sccm-frontend', $js_url, array(), $js_ver, version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ? $args : false );
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
	 * Load our stylesheet without blocking the first paint.
	 *
	 * The banner is built by JavaScript after the page is parsed, and the script waits for the
	 * stylesheet before showing anything, so nothing appears unstyled. Pages that print the cookie
	 * policy table in their content keep the normal (blocking) stylesheet, because that markup is
	 * visible immediately. Without JavaScript the normal stylesheet is used too.
	 *
	 * @param string $tag    HTML tag.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public static function style_tag( $tag, $handle ) {
		if ( 'sccm-frontend' !== $handle ) {
			return $tag;
		}
		$post = is_singular() ? get_post() : null;
		if ( $post && has_shortcode( (string) $post->post_content, 'sccm_cookie_policy' ) ) {
			return $tag;
		}
		/**
		 * Keep the stylesheet render-blocking (return false to load it the usual way).
		 *
		 * @param bool $async Whether to load it without blocking.
		 */
		if ( ! apply_filters( 'sccm_async_css', true ) ) {
			return $tag;
		}
		$async = preg_replace( '/media=([\'"])all\1/', 'media="print" onload="this.media=\'all\'" data-no-optimize="1"', $tag, 1, $count );
		if ( ! $count || null === $async ) {
			return $tag;
		}
		return $async . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Build the config passed to the browser.
	 *
	 * Kept compact on purpose: it is printed in every page.
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
					't' => $row['type'],
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

		// Clean-up list (names to delete when a category is refused), grouped by category:
		// c = cookies, l = local storage, s = session storage. Registry + library cookies.
		$slots   = array(
			'cookie'         => 'c',
			'localStorage'   => 'l',
			'sessionStorage' => 's',
		);
		$cleanup = array();
		foreach ( SCCM_Cookies::query( array( 'status' => 'active' ) ) as $row ) {
			if ( 'necessary' !== $row['category'] ) {
				$cleanup[ $row['category'] ][ $slots[ $row['type'] ] ][ $row['name'] ] = true;
			}
		}
		foreach ( SCCM_Services::all() as $service ) {
			foreach ( $service['cookies'] as $cookie ) {
				$category = SCCM_Services::cookie_category( $service, $cookie );
				if ( 'necessary' !== $category ) {
					$type = isset( $cookie[3] ) && isset( $slots[ $cookie[3] ] ) ? $cookie[3] : 'cookie';
					$cleanup[ $category ][ $slots[ $type ] ][ $cookie[0] ] = true;
				}
			}
		}
		foreach ( $cleanup as $category => $by_slot ) {
			foreach ( $by_slot as $slot => $names ) {
				$cleanup[ $category ][ $slot ] = array_keys( $names );
			}
		}

		// Cookie names already in the registry (any status): the visitor-side scanner skips these.
		$known = array();
		foreach ( SCCM_Cookies::all_rows() as $row ) {
			if ( 'cookie' === $row['type'] ) {
				$known[] = $row['name'];
			}
		}

		$config = array(
			'v'           => (int) get_option( 'sccm_consent_version', 1 ),
			'cookie'      => 'sccm_consent',
			'days'        => (int) $settings['consent_expiry_days'],
			'graceDays'   => (int) $settings['reject_grace_days'],
			'reload'      => (bool) $settings['reload_on_withdraw'],
			'position'    => $settings['position'],
			'order'       => $settings['button_order'],
			'layout'      => $settings['banner_layout'],
			'scanMode'    => self::is_scan_mode(),
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
