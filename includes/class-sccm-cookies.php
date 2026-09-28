<?php
/**
 * Cookie registry (table {prefix}sccm_cookies).
 *
 * The registry is the single source for: the cookie list shown to visitors, the cookie
 * policy, cookie clean-up for refused categories, and the scanner's "known" list.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry CRUD and helpers.
 */
class SCCM_Cookies {

	const TYPES    = array( 'cookie', 'localStorage', 'sessionStorage' );
	const STATUSES = array( 'active', 'pending', 'ignored' );
	const SOURCES  = array( 'default', 'library', 'manual', 'scanner', 'import' );

	/**
	 * Request cache of all rows.
	 *
	 * @var array|null
	 */
	private static $rows = null;

	/**
	 * When true, registry changes do not bump the consent version (bulk operations).
	 *
	 * @var bool
	 */
	private static $suppress_bump = false;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sccm_cookies';
	}

	/**
	 * Wildcard match ('*' = any characters). Exact match otherwise.
	 *
	 * @param string $pattern Registry name (may contain *).
	 * @param string $name    Actual cookie name.
	 * @return bool
	 */
	public static function name_matches( $pattern, $name ) {
		if ( false === strpos( $pattern, '*' ) ) {
			return $pattern === $name;
		}
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
		return (bool) preg_match( $regex, $name );
	}

	/**
	 * All rows (cached per request).
	 *
	 * @return array
	 */
	public static function all_rows() {
		global $wpdb;
		if ( null === self::$rows ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
			$rows  = is_array( $rows ) ? $rows : array();
			$order = array_flip( SCCM_Categories::KEYS );
			usort(
				$rows,
				function ( $a, $b ) use ( $order ) {
					$ca = isset( $order[ $a['category'] ] ) ? $order[ $a['category'] ] : 9;
					$cb = isset( $order[ $b['category'] ] ) ? $order[ $b['category'] ] : 9;
					return ( $ca === $cb ) ? strcasecmp( $a['name'], $b['name'] ) : $ca - $cb;
				}
			);
			self::$rows = $rows;
		}
		return self::$rows;
	}

	/**
	 * Filtered list.
	 *
	 * @param array $args status, category, search.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		$rows = self::all_rows();
		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $args ) {
					if ( ! empty( $args['status'] ) && $row['status'] !== $args['status'] ) {
						return false;
					}
					if ( ! empty( $args['category'] ) && $row['category'] !== $args['category'] ) {
						return false;
					}
					if ( ! empty( $args['search'] ) ) {
						$needle = strtolower( $args['search'] );
						if ( false === strpos( strtolower( $row['name'] . ' ' . $row['provider'] . ' ' . $row['purpose'] ), $needle ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * Active cookies grouped by category (for visitors).
	 *
	 * @return array category => list of rows.
	 */
	public static function grouped() {
		$out = array_fill_keys( SCCM_Categories::KEYS, array() );
		foreach ( self::query( array( 'status' => 'active' ) ) as $row ) {
			if ( isset( $out[ $row['category'] ] ) ) {
				$out[ $row['category'] ][] = $row;
			}
		}
		return $out;
	}

	/**
	 * Counts per status.
	 *
	 * @return array
	 */
	public static function counts() {
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( self::all_rows() as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				++$out[ $row['status'] ];
			}
		}
		return $out;
	}

	/**
	 * One row by id.
	 *
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all_rows() as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Registry row whose name pattern matches a real cookie name.
	 *
	 * @param string $name Cookie name.
	 * @param string $type Storage type.
	 * @return array|null
	 */
	public static function find_matching( $name, $type = 'cookie' ) {
		foreach ( self::all_rows() as $row ) {
			if ( $row['type'] === $type && self::name_matches( $row['name'], $name ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Insert or update a row.
	 *
	 * @param array $data Row data.
	 * @param int   $id   Row id to update (0 = insert).
	 * @return int|false Row id or false.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$old   = $id ? self::get( $id ) : null;
		$clean = self::sanitize_row( $data, $old );
		if ( '' === $clean['name'] ) {
			return false;
		}
		$now                 = current_time( 'mysql', true );
		$clean['updated_at'] = $now;

		if ( $old ) {
			$ok = $wpdb->update( self::table(), $clean, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = false === $ok ? false : (int) $id;
		} else {
			$clean['created_at'] = $now;
			$ok                  = $wpdb->insert( self::table(), $clean ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result              = $ok ? (int) $wpdb->insert_id : false;
		}
		self::$rows = null;

		// A new active cookie, or an active cookie moved to another category, is a change
		// visitors have not agreed to yet → optionally ask everyone again.
		if ( false !== $result && 'active' === $clean['status'] ) {
			if ( ! $old || 'active' !== $old['status'] || $old['category'] !== $clean['category'] ) {
				self::maybe_bump_version();
			}
		}
		return $result;
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$ok         = $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::$rows = null;
		return (bool) $ok;
	}

	/**
	 * Add all cookies of a library service as active entries.
	 *
	 * @param string $service_key Service id.
	 * @param string $source      Source label.
	 * @return int Number of rows added.
	 */
	public static function add_service( $service_key, $source = 'library' ) {
		$service = SCCM_Services::get( $service_key );
		if ( ! $service ) {
			return 0;
		}
		$was_suppressed      = self::$suppress_bump;
		self::$suppress_bump = true;
		$added               = 0;
		foreach ( $service['cookies'] as $cookie ) {
			$type = isset( $cookie[3] ) ? $cookie[3] : 'cookie';
			if ( self::find_exact( $cookie[0], $type ) ) {
				continue;
			}
			$id = self::save(
				array(
					'name'     => $cookie[0],
					'type'     => $type,
					'category' => $service['category'],
					'provider' => $service['provider'],
					'purpose'  => isset( $cookie[2] ) ? $cookie[2] : '',
					'duration' => isset( $cookie[1] ) ? $cookie[1] : '',
					'service'  => $service_key,
					'status'   => 'active',
					'source'   => $source,
				)
			);
			if ( $id ) {
				++$added;
			}
		}
		self::$suppress_bump = $was_suppressed;
		if ( $added ) {
			self::maybe_bump_version();
		}
		return $added;
	}

	/**
	 * Record a cookie seen by the scanner or a visitor's browser.
	 *
	 * Known → update last_seen. Found in the library → add as active. Unknown → pending.
	 *
	 * @param string $name Cookie name.
	 * @param string $type Storage type.
	 * @return string '' when already known, 'active' or 'pending' when added.
	 */
	public static function record_seen( $name, $type = 'cookie' ) {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$existing = self::find_matching( $name, $type );
		if ( $existing ) {
			$wpdb->update( self::table(), array( 'last_seen' => $now ), array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return '';
		}

		$known = SCCM_Services::match_cookie( $name );
		if ( $known ) {
			self::save(
				array(
					'name'       => $known['name'],
					'type'       => $type,
					'category'   => $known['category'],
					'provider'   => $known['provider'],
					'purpose'    => $known['purpose'],
					'duration'   => $known['duration'],
					'service'    => $known['service'],
					'status'     => 'active',
					'source'     => 'scanner',
					'first_seen' => $now,
					'last_seen'  => $now,
				)
			);
			self::queue_alert( $known['name'], $type, 'active', $known['category'] );
			return 'active';
		}

		self::save(
			array(
				'name'       => $name,
				'type'       => $type,
				'category'   => 'necessary',
				'status'     => 'pending',
				'source'     => 'scanner',
				'first_seen' => $now,
				'last_seen'  => $now,
			)
		);
		self::queue_alert( $name, $type, 'pending', '' );
		return 'pending';
	}

	/**
	 * Queue an item for the email digest.
	 *
	 * @param string $name     Cookie name.
	 * @param string $type     Storage type.
	 * @param string $status   active|pending.
	 * @param string $category Category key.
	 */
	public static function queue_alert( $name, $type, $status, $category ) {
		$queue   = (array) get_option( 'sccm_pending_alert', array() );
		$queue[] = array(
			'name'     => $name,
			'type'     => $type,
			'status'   => $status,
			'category' => $category,
			'time'     => time(),
		);
		update_option( 'sccm_pending_alert', array_slice( $queue, -100 ), false );
	}

	/**
	 * Seed the registry with the plugin's own cookie and WordPress core cookies.
	 */
	public static function seed_defaults() {
		self::$suppress_bump = true;
		self::add_service( 'sccm', 'default' );
		self::add_service( 'wordpress', 'default' );
		if ( class_exists( 'WooCommerce' ) ) {
			self::add_service( 'woocommerce', 'default' );
		}
		self::$suppress_bump = false;
	}

	/**
	 * Rows for export (no ids/timestamps).
	 *
	 * @return array
	 */
	public static function export_rows() {
		$out = array();
		foreach ( self::all_rows() as $row ) {
			if ( 'pending' === $row['status'] ) {
				continue;
			}
			$out[] = array_intersect_key( $row, array_flip( array( 'name', 'type', 'category', 'provider', 'purpose', 'duration', 'service', 'status' ) ) );
		}
		return $out;
	}

	/**
	 * Import rows (skips names that already exist).
	 *
	 * @param array $rows Rows from export_rows().
	 * @return int Number imported.
	 */
	public static function import_rows( array $rows ) {
		self::$suppress_bump = true;
		$count               = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? $row['type'] : 'cookie';
			if ( self::find_exact( $row['name'], $type ) ) {
				continue;
			}
			$row['source'] = 'import';
			if ( self::save( $row ) ) {
				++$count;
			}
		}
		self::$suppress_bump = false;
		if ( $count ) {
			self::maybe_bump_version();
		}
		return $count;
	}

	/**
	 * Increase the consent version so every visitor is asked again.
	 *
	 * @return int New version.
	 */
	public static function bump_version() {
		$version = (int) get_option( 'sccm_consent_version', 1 ) + 1;
		update_option( 'sccm_consent_version', $version );
		/**
		 * Fires after the consent version changes (e.g. to purge page caches).
		 *
		 * @param int $version New version.
		 */
		do_action( 'sccm_consent_version_changed', $version );
		return $version;
	}

	/**
	 * Bump the version if "ask again when the cookie list changes" is on.
	 */
	public static function maybe_bump_version() {
		if ( ! self::$suppress_bump && SCCM_Settings::get( 'reask_on_change' ) ) {
			self::bump_version();
		}
	}

	/**
	 * Exact name+type lookup.
	 *
	 * @param string $name Name.
	 * @param string $type Type.
	 * @return array|null
	 */
	private static function find_exact( $name, $type ) {
		foreach ( self::all_rows() as $row ) {
			if ( $row['name'] === $name && $row['type'] === $type ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Sanitise a row.
	 *
	 * @param array      $data Input.
	 * @param array|null $old  Existing row.
	 * @return array
	 */
	private static function sanitize_row( array $data, $old = null ) {
		$base = $old ? $old : array(
			'name'       => '',
			'type'       => 'cookie',
			'category'   => 'necessary',
			'provider'   => '',
			'purpose'    => '',
			'duration'   => '',
			'service'    => '',
			'status'     => 'active',
			'source'     => 'manual',
			'first_seen' => null,
			'last_seen'  => null,
		);
		$row  = array_merge( $base, $data );

		$name = preg_replace( '/[^A-Za-z0-9_\-\.\*\[\]:@$%~|]/', '', (string) $row['name'] );

		return array(
			'name'       => substr( $name, 0, 191 ),
			'type'       => in_array( $row['type'], self::TYPES, true ) ? $row['type'] : 'cookie',
			'category'   => SCCM_Categories::is_valid( $row['category'] ) ? $row['category'] : 'necessary',
			'provider'   => substr( sanitize_text_field( (string) $row['provider'] ), 0, 191 ),
			'purpose'    => sanitize_textarea_field( (string) $row['purpose'] ),
			'duration'   => substr( sanitize_text_field( (string) $row['duration'] ), 0, 100 ),
			'service'    => sanitize_key( (string) $row['service'] ),
			'status'     => in_array( $row['status'], self::STATUSES, true ) ? $row['status'] : 'active',
			'source'     => in_array( $row['source'], self::SOURCES, true ) ? $row['source'] : 'manual',
			'first_seen' => $row['first_seen'] ? $row['first_seen'] : null,
			'last_seen'  => $row['last_seen'] ? $row['last_seen'] : null,
		);
	}
}
