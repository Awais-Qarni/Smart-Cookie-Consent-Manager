<?php
/**
 * Page cache clearing.
 *
 * Every page carries the banner settings and the cookie list (cache-safe: the same for every
 * visitor), and the Cookie Policy page prints the list too. When those change, cached copies of
 * the pages are out of date, so the page caches of common caching plugins and hosts are cleared:
 * after settings are saved, after the consent version changes, and after the list of cookies
 * shown to visitors changes (approve, edit, delete, a scan adding a known cookie…).
 *
 * At most one clear per request, at the end of it. Clears that are not started by an
 * administrator (e.g. a cookie added from a visitor's report) are throttled to one per
 * THROTTLE seconds; a clear asked for in between runs from WP-Cron when the wait is over.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache clearing for the integrations listed in purge_all().
 */
class SCCM_Cache {

	/**
	 * Minimum seconds between two clears that were not started by an administrator.
	 */
	const THROTTLE = 300;

	/**
	 * Cron hook for a postponed clear.
	 */
	const EVENT = 'sccm_purge_cache_event';

	/**
	 * Whether a clear is waiting for the end of this request.
	 *
	 * @var bool
	 */
	private static $queued = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'sccm_settings_saved', array( __CLASS__, 'request' ) );
		add_action( 'sccm_consent_version_changed', array( __CLASS__, 'request' ) );
		add_action( 'sccm_cookie_list_changed', array( __CLASS__, 'request' ) );
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
	}

	/**
	 * Ask for a clear at the end of this request.
	 */
	public static function request() {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_queued' ), 1 );
	}

	/**
	 * End of the request: clear now, or later when the last clear was too recent.
	 */
	public static function flush_queued() {
		self::$queued = false;
		$wait = (int) get_option( 'sccm_last_cache_purge', 0 ) + self::THROTTLE - time();
		if ( $wait > 0 && ! current_user_can( 'manage_options' ) ) {
			if ( ! wp_next_scheduled( self::EVENT ) ) {
				wp_schedule_single_event( time() + $wait, self::EVENT );
			}
			return;
		}
		self::run();
	}

	/**
	 * Clear the page caches now.
	 */
	public static function run() {
		/**
		 * Whether the plugin clears page caches when its settings or cookie list change.
		 *
		 * @param bool $purge Clear the caches (default true).
		 */
		if ( ! apply_filters( 'sccm_purge_page_cache', true ) ) {
			return;
		}
		update_option( 'sccm_last_cache_purge', time(), false );

		$page_id = (int) SCCM_Settings::get( 'policy_page_id' );
		if ( $page_id ) {
			// Hosts and plugins that only purge single posts listen to this.
			clean_post_cache( $page_id );
		}
		self::purge_all();

		/**
		 * Fires after the plugin cleared page caches. Clear any other cache here.
		 */
		do_action( 'sccm_cache_purged' );
	}

	/**
	 * Clear the whole-site page cache of every supported plugin/host that is active.
	 *
	 * Each call is guarded: a missing or changed third-party function never breaks the site.
	 */
	private static function purge_all() {
		$calls = array(
			// WP Engine.
			static function () {
				if ( class_exists( 'WpeCommon' ) ) {
					if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
						WpeCommon::purge_memcached();
					}
					if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
						WpeCommon::purge_varnish_cache();
					}
				}
			},
			// NitroPack.
			static function () {
				if ( function_exists( 'nitropack_sdk_purge' ) ) {
					nitropack_sdk_purge( null, null, 'Smart Cookie Consent Manager: cookie settings changed' );
				}
			},
			// WP Rocket.
			static function () {
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
				}
			},
			// W3 Total Cache.
			static function () {
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
				}
			},
			// WP Super Cache.
			static function () {
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();
				}
			},
			// WP Fastest Cache.
			static function () {
				if ( function_exists( 'wpfc_clear_all_cache' ) ) {
					wpfc_clear_all_cache( true );
				}
			},
			// SiteGround Speed Optimizer.
			static function () {
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
				}
			},
			// LiteSpeed Cache, Breeze (Cloudways), Hummingbird: they listen to these actions.
			static function () {
				do_action( 'litespeed_purge_all' );
				do_action( 'breeze_clear_all_cache' );
				do_action( 'wphb_clear_page_cache' );
			},
		);
		foreach ( $calls as $call ) {
			try {
				$call();
			} catch ( Throwable $e ) {
				// A cache plugin failing must never break saving settings or the front end.
				continue;
			}
		}
	}
}
