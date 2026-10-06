<?php
/**
 * Cookie registry (table {prefix}sccm_cookies).
 *
 * The registry is the single source for: the cookie list shown to visitors, the cookie
 * policy, cookie clean-up for refused categories, and the scanner's "known" list.
 *
 * How cookies get in (kept deliberately quiet, so the list stays short and accurate):
 *  - Known cookies (the service library) are added automatically as Active, in the right
 *    category. Nothing to do for the site owner.
 *  - Unknown cookies seen by the server scan are added as "Needs review".
 *  - Unknown cookies reported by visitors' browsers are added as "Needs review" only after
 *    at least two different visitors reported them (browser extensions and other one-off
 *    noise never reach the list). Logged-in users never report.
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
	 * Most items that can wait for review at the same time (flood protection).
	 */
	const MAX_PENDING = 50;

	/**
	 * Different visitors that must report an unknown cookie before it is listed.
	 */
	const MIN_VISITORS = 2;

	/**
	 * Option that holds cookies reported by visitors but not yet listed.
	 */
	const CANDIDATES_OPTION = 'sccm_candidates';

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
	 * A bump was requested while suppressed.
	 *
	 * @var bool
	 */
	private static $bump_pending = false;

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
	 * Number of items waiting for review. One cheap query, safe to call on every admin page.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		if ( null !== self::$rows ) {
			return self::counts()['pending'];
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' ) );
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
		if ( false !== $result && ( 'active' === $clean['status'] || ( $old && 'active' === $old['status'] ) ) ) {
			self::list_changed();
		}
		return $result;
	}

	/**
	 * The list of cookies shown to visitors (banner details, Cookie Policy page) changed.
	 */
	private static function list_changed() {
		/**
		 * Fires when the cookies shown to visitors change (added, edited, removed). The plugin
		 * clears page caches on it (see SCCM_Cache).
		 */
		do_action( 'sccm_cookie_list_changed' );
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$old        = self::get( $id );
		$ok         = $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::$rows = null;
		if ( $ok && $old && 'active' === $old['status'] ) {
			self::list_changed();
		}
		return (bool) $ok;
	}

	/**
	 * Move every item with one status to another (e.g. ignore everything waiting for review).
	 *
	 * @param string $from Current status.
	 * @param string $to   New status (ignored|active).
	 * @return int Rows changed.
	 */
	public static function bulk_status( $from, $to ) {
		global $wpdb;
		if ( ! in_array( $from, self::STATUSES, true ) || ! in_array( $to, self::STATUSES, true ) || $from === $to ) {
			return 0;
		}
		$changed = (int) $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'     => $to,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'status' => $from )
		);
		self::$rows = null;
		if ( $changed && 'active' === $to ) {
			self::maybe_bump_version();
		}
		if ( $changed && ( 'active' === $to || 'active' === $from ) ) {
			self::list_changed();
		}
		return $changed;
	}

	/**
	 * Add the cookies of a library service as active entries.
	 *
	 * Cookies the library marks "only if seen" are skipped when the service was merely detected
	 * (source 'scanner'); an explicit "add this service" (source 'library') adds them all.
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
		return (int) self::bulk(
			function () use ( $service, $service_key, $source ) {
				$added = 0;
				foreach ( $service['cookies'] as $cookie ) {
					$type = isset( $cookie[3] ) ? $cookie[3] : 'cookie';
					if ( 'scanner' === $source && ! empty( $cookie[4] ) ) {
						continue;
					}
					if ( self::find_exact( $cookie[0], $type ) ) {
						continue;
					}
					$id = self::save(
						array(
							'name'     => $cookie[0],
							'type'     => $type,
							'category' => SCCM_Services::cookie_category( $service, $cookie ),
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
				return $added;
			}
		);
	}

	/**
	 * Record a cookie seen by the scanner or a visitor's browser.
	 *
	 * Known → update last_seen. Found in the library → add as active (automatic category).
	 * Unknown → "needs review" (from a visitor's browser: only once enough visitors reported it).
	 *
	 * @param string $name   Cookie name.
	 * @param string $type   Storage type.
	 * @param string $origin 'scanner' (server scan) or 'visitor' (reported by a browser).
	 * @return string '' when nothing was added, 'active' or 'pending' when added.
	 */
	public static function record_seen( $name, $type = 'cookie', $origin = 'scanner' ) {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$existing = self::find_matching( $name, $type );
		if ( $existing ) {
			if ( 'scanner' === $origin ) {
				$wpdb->update( self::table(), array( 'last_seen' => $now ), array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return '';
		}

		$known = SCCM_Services::match_cookie( $name );
		if ( $known && $known['type'] === $type ) {
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

		if ( self::pending_count() >= self::MAX_PENDING ) {
			return '';
		}
		if ( 'visitor' === $origin && ! self::add_candidate( $name, $type ) ) {
			return '';
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
	 * Count one visitor's report of an unknown cookie.
	 *
	 * @param string $name Cookie name.
	 * @param string $type Storage type.
	 * @return bool True when enough different visitors have reported it (list it now).
	 */
	private static function add_candidate( $name, $type ) {
		$candidates = get_option( self::CANDIDATES_OPTION, array() );
		$candidates = is_array( $candidates ) ? $candidates : array();
		$now        = time();
		$key        = $type . ':' . $name;
		$visitor    = substr( md5( SCCM_Consent_Log::client_ip() . '|' . wp_salt( 'nonce' ) ), 0, 10 );

		// Forget stale candidates.
		foreach ( $candidates as $k => $candidate ) {
			if ( ! isset( $candidate['t'] ) || $now - (int) $candidate['t'] > 14 * DAY_IN_SECONDS ) {
				unset( $candidates[ $k ] );
			}
		}

		if ( ! isset( $candidates[ $key ] ) ) {
			if ( count( $candidates ) >= 100 ) {
				return false;
			}
			$candidates[ $key ] = array(
				'v' => array(),
				't' => $now,
			);
		}
		if ( ! in_array( $visitor, $candidates[ $key ]['v'], true ) ) {
			$candidates[ $key ]['v'][] = $visitor;
			$candidates[ $key ]['t']   = $now;
		}

		$promote = count( $candidates[ $key ]['v'] ) >= self::MIN_VISITORS;
		if ( $promote ) {
			unset( $candidates[ $key ] );
		}
		update_option( self::CANDIDATES_OPTION, $candidates, false );
		return $promote;
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
	 * Seed the registry with the plugin's own cookie (and WooCommerce's when active).
	 *
	 * WordPress login cookies are not listed: only logged-in users have them, visitors never
	 * do. If a visitor-facing WordPress cookie ever appears (e.g. comment cookies) the scanner
	 * adds it from the library automatically.
	 */
	public static function seed_defaults() {
		self::bulk(
			function () {
				self::add_service( 'sccm', 'default' );
				if ( class_exists( 'WooCommerce' ) ) {
					self::add_service( 'woocommerce', 'default' );
				}
			},
			false
		);
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
		return (int) self::bulk(
			function () use ( $rows ) {
				$count = 0;
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
				return $count;
			}
		);
	}

	/**
	 * Run a bulk operation: registry changes inside it ask everyone again at most once, at the end.
	 *
	 * @param callable $operation Work to do.
	 * @param bool     $bump      Whether to bump the consent version afterwards when needed.
	 * @return mixed Whatever the operation returns.
	 */
	public static function bulk( callable $operation, $bump = true ) {
		$was_suppressed      = self::$suppress_bump;
		$was_pending         = self::$bump_pending;
		self::$suppress_bump = true;
		self::$bump_pending  = false;

		$result = $operation();

		$needed              = self::$bump_pending;
		self::$suppress_bump = $was_suppressed;
		self::$bump_pending  = $was_pending || ( $needed && $was_suppressed );
		if ( $needed && $bump && ! $was_suppressed ) {
			self::bump_version();
		}
		return $result;
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
		if ( ! SCCM_Settings::get( 'reask_on_change' ) ) {
			return;
		}
		if ( self::$suppress_bump ) {
			self::$bump_pending = true;
			return;
		}
		self::bump_version();
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
