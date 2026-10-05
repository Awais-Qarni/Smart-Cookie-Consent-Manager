<?php
/**
 * Main plugin class: wires all modules.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
final class SCCM_Plugin {

	/**
	 * Instance.
	 *
	 * @var SCCM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance (boots the plugin on first call).
	 *
	 * @return SCCM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot modules.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( 'SCCM_Install', 'maybe_upgrade' ), 5 );

		SCCM_Frontend::init();
		SCCM_Blocker::init();
		SCCM_REST::init();
		SCCM_Scanner::init();
		SCCM_Shortcodes::init();

		if ( is_admin() ) {
			SCCM_Admin::init();
		}

		// New site in a multisite network.
		add_action(
			'wp_initialize_site',
			function ( $site ) {
				if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( is_plugin_active_for_network( SCCM_BASENAME ) ) {
					switch_to_blog( $site->blog_id );
					SCCM_Install::install();
					restore_current_blog();
				}
			},
			20
		);
	}

	/**
	 * Version string for an asset: plugin version + file modification time.
	 *
	 * Browsers, CDNs and hosts (e.g. WP Engine) cache files by URL. Without this, an updated
	 * stylesheet or script keeps being served from the cache until the plugin version changes.
	 *
	 * @param string $relative Path relative to the plugin root, e.g. 'assets/css/sccm-admin.css'.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$time = @filemtime( SCCM_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return SCCM_VERSION . ( $time ? '.' . $time : '' );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'smart-cookie-consent-manager', false, dirname( SCCM_BASENAME ) . '/languages' );
	}
}
