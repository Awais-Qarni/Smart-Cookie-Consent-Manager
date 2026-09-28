<?php
/**
 * Installation, upgrades and deactivation.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates database tables, default options and scheduled events.
 */
class SCCM_Install {

	/**
	 * Database schema version. Bump when the table structure changes.
	 */
	const DB_VERSION = '1';

	/**
	 * Option that stores the installed schema version.
	 */
	const DB_VERSION_OPTION = 'sccm_db_version';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether activated network wide (multisite).
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	/**
	 * Deactivation hook: remove scheduled events (data is kept).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'sccm_scan_event' );
		wp_clear_scheduled_hook( 'sccm_daily_event' );
	}

	/**
	 * Run the installer for the current site.
	 */
	public static function install() {
		self::create_tables();

		if ( false === get_option( SCCM_Settings::OPTION ) ) {
			add_option( SCCM_Settings::OPTION, SCCM_Settings::defaults(), '', false );
		}
		if ( false === get_option( 'sccm_consent_version' ) ) {
			add_option( 'sccm_consent_version', 1 );
		}

		SCCM_Cookies::seed_defaults();
		self::schedule_events();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Upgrade routine, called on every load; cheap when nothing changed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Make sure the cron events exist and match the settings.
	 */
	public static function schedule_events() {
		$schedule = SCCM_Settings::get( 'scan_schedule' );

		wp_clear_scheduled_hook( 'sccm_scan_event' );
		if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, 'sccm_scan_event' );
		}

		if ( ! wp_next_scheduled( 'sccm_daily_event' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'sccm_daily_event' );
		}

		// First install: scan once shortly after activation so the cookie list is filled
		// before most visitors give consent (avoids asking them again a few days later).
		if ( false === get_option( 'sccm_last_scan' ) && 'off' !== $schedule ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'sccm_scan_event' );
		}
	}

	/**
	 * Create or update the plugin tables.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$cookies = $wpdb->prefix . 'sccm_cookies';
		$log     = $wpdb->prefix . 'sccm_consent_log';

		$sql_cookies = "CREATE TABLE {$cookies} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'cookie',
			category varchar(20) NOT NULL DEFAULT 'necessary',
			provider varchar(191) NOT NULL DEFAULT '',
			purpose text NULL,
			duration varchar(100) NOT NULL DEFAULT '',
			service varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			source varchar(20) NOT NULL DEFAULT 'manual',
			first_seen datetime NULL,
			last_seen datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY status (status),
			KEY category (category)
		) {$charset};";

		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consent_id varchar(64) NOT NULL,
			choice varchar(20) NOT NULL,
			categories varchar(191) NOT NULL DEFAULT '',
			gpc tinyint(1) NOT NULL DEFAULT 0,
			consent_version int(10) unsigned NOT NULL DEFAULT 1,
			url varchar(2048) NOT NULL DEFAULT '',
			ip varchar(100) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY consent_id (consent_id),
			KEY created_at (created_at),
			KEY choice (choice)
		) {$charset};";

		dbDelta( $sql_cookies );
		dbDelta( $sql_log );
	}

	/**
	 * Remove every trace of the plugin (used by uninstall.php).
	 */
	public static function remove_all_data() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sccm_cookies" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sccm_consent_log" );

		foreach ( array( SCCM_Settings::OPTION, 'sccm_consent_version', self::DB_VERSION_OPTION, 'sccm_last_scan', 'sccm_pending_alert', 'sccm_last_alert' ) as $option ) {
			delete_option( $option );
		}

		wp_clear_scheduled_hook( 'sccm_scan_event' );
		wp_clear_scheduled_hook( 'sccm_daily_event' );
	}
}
