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
		SCCM_Cache::init();

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
	 * Public URL of a plugin asset whose FILE NAME changes whenever the file changes.
	 *
	 * Hosts, CDNs and optimisation plugins (WP Engine, NitroPack, Cloudflare…) often cache static
	 * files by path and ignore the "?ver=" query string, so an updated script could keep being
	 * served in its old version to logged-out visitors. The asset is therefore copied once to
	 * wp-content/uploads/sccm-assets/<name>.<hash>.<ext>; a new hash means a new URL that no cache
	 * has seen. If the copy is not possible, the normal plugin URL with "?ver=" is used.
	 *
	 * @param string $relative Path relative to the plugin root, e.g. 'assets/js/sccm-frontend.js'.
	 * @return array array( url, ver ) for wp_enqueue_script()/wp_enqueue_style().
	 */
	public static function asset( $relative ) {
		$source = SCCM_PATH . $relative;
		$time   = @filemtime( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ver    = SCCM_VERSION . ( $time ? '.' . $time : '' );
		/**
		 * Copy assets to uploads with a versioned file name (return false to serve them from the plugin folder).
		 *
		 * @param bool $copy Whether to copy.
		 */
		if ( ! $time || ! apply_filters( 'sccm_versioned_asset_files', true ) ) {
			return array( SCCM_URL . $relative, $ver );
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return array( SCCM_URL . $relative, $ver );
		}
		$info = pathinfo( $relative );
		$hash = substr( md5( $ver . '|' . filesize( $source ) ), 0, 10 );
		$name = $info['filename'] . '.' . $hash . '.' . $info['extension'];
		$dir  = trailingslashit( $uploads['basedir'] ) . 'sccm-assets';
		$file = $dir . '/' . $name;
		if ( ! file_exists( $file ) ) {
			if ( ! wp_mkdir_p( $dir ) || ! @copy( $source, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array( SCCM_URL . $relative, $ver );
			}
			// Remove older copies of this asset.
			foreach ( (array) glob( $dir . '/' . $info['filename'] . '.*.' . $info['extension'] ) as $old ) {
				if ( $old && $old !== $file ) {
					wp_delete_file( $old );
				}
			}
		}
		return array( set_url_scheme( trailingslashit( $uploads['baseurl'] ) . 'sccm-assets/' . $name ), null );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'smart-cookie-consent-manager', false, dirname( SCCM_BASENAME ) . '/languages' );
	}
}
