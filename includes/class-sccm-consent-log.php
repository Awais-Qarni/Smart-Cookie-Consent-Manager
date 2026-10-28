<?php
/**
 * Consent records (table {prefix}sccm_consent_log) — evidence of each visitor choice.
 *
 * @package SmartCookieConsentManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent log storage, queries and export.
 */
class SCCM_Consent_Log {

	const CHOICES = array( 'accept_all', 'reject_all', 'custom', 'gpc' );

	/**
	 * WHERE clause of every record query; the values come from filters(), in this order. Filters
	 * that are not set match every record, so it is always the same prepared statement.
	 */
	const WHERE = "consent_id LIKE %s AND (%s = '' OR choice = %s) AND created_at >= %s AND created_at <= %s AND (%s = '' OR ip IN (%s, %s, %s)) AND (%d = 0 OR consent_version = %d)";

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sccm_consent_log';
	}

	/**
	 * Whether the records table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
	}

	/**
	 * Store a consent record.
	 *
	 * @param array $data consent_id, choice, categories (array), gpc, version, url.
	 * @return int|WP_Error Insert id.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		// Values come from visitors' browsers: anything that is not a plain value is refused.
		$consent_id = isset( $data['consent_id'] ) && is_string( $data['consent_id'] ) ? strtolower( $data['consent_id'] ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{16,64}$/', $consent_id ) ) {
			return new WP_Error( 'sccm_bad_id', 'Invalid consent id.' );
		}
		$choice = isset( $data['choice'] ) && is_string( $data['choice'] ) ? $data['choice'] : '';
		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return new WP_Error( 'sccm_bad_choice', 'Invalid choice.' );
		}
		// The banner version of the page the visitor used: never newer than the current one
		// (a cached page may carry an older one), so a record cannot claim a list that does not exist.
		$version = isset( $data['version'] ) && is_scalar( $data['version'] ) ? max( 1, absint( $data['version'] ) ) : 1;
		if ( $version > (int) get_option( 'sccm_consent_version', 1 ) ) {
			return new WP_Error( 'sccm_bad_version', 'Invalid banner version.' );
		}

		$categories = array( 'necessary' );
		foreach ( (array) ( $data['categories'] ?? array() ) as $cat ) {
			if ( is_string( $cat ) && SCCM_Categories::is_optional( $cat ) && ! in_array( $cat, $categories, true ) ) {
				$categories[] = $cat;
			}
		}

		$row = array(
			'consent_id'      => $consent_id,
			'choice'          => $choice,
			'categories'      => implode( ',', $categories ),
			'gpc'             => empty( $data['gpc'] ) ? 0 : 1,
			'consent_version' => $version,
			'url'             => self::clean_url( isset( $data['url'] ) && is_string( $data['url'] ) ? $data['url'] : '' ),
			'ip'              => self::process_ip( self::client_ip() ),
			'user_agent'      => mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			'created_at'      => current_time( 'mysql', true ),
		);

		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $ok && ! self::table_exists() ) {
			// The table is missing (e.g. a failed activation or a database restore): create it and retry once.
			SCCM_Install::create_tables();
			$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( ! $ok ) {
			/* translators: %s: database error message */
			return new WP_Error( 'sccm_db', sprintf( __( 'The database did not store the record: %s', 'smart-cookie-consent-manager' ), $wpdb->last_error ? $wpdb->last_error : __( 'unknown error', 'smart-cookie-consent-manager' ) ) );
		}
		/**
		 * Fires after a consent record is stored.
		 *
		 * @param array $row Stored row.
		 */
		do_action( 'sccm_consent_recorded', $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Query records.
	 *
	 * @param array $args search, choice, from (Y-m-d), to (Y-m-d), per_page, page.
	 * @return array array( rows, total ).
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$where    = array_merge( array( self::table() ), self::filters( $args ) );
		$per_page = max( 1, min( 500, absint( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// One fixed statement: filters that are not set match every record (see filters()).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- self::WHERE is a constant with placeholders only.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . self::WHERE, $where ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . self::WHERE . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $where, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Stream a CSV export to the browser.
	 *
	 * @param array $args Same filters as query().
	 */
	public static function stream_csv( array $args = array() ) {
		global $wpdb;

		$where = array_merge( array( self::table() ), self::filters( $args ) );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'id', 'consent_id', 'date_time_utc', 'choice', 'categories', 'gpc', 'banner_version', 'url', 'ip', 'user_agent' ) );

		// In batches by id (not OFFSET), so a large export stays fast to the last row.
		$batch = 1000;
		$last  = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- self::WHERE is a constant with placeholders only.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . self::WHERE . ' AND id > %d ORDER BY id ASC LIMIT %d', array_merge( $where, array( $last, $batch ) ) ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$last = (int) $row['id'];
				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'csv_cell' ),
						array(
							$row['id'],
							$row['consent_id'],
							$row['created_at'],
							$row['choice'],
							$row['categories'],
							$row['gpc'] ? 'yes' : 'no',
							$row['consent_version'],
							$row['url'],
							$row['ip'],
							$row['user_agent'],
						)
					)
				);
			}
		} while ( is_array( $rows ) && count( $rows ) === $batch );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * A CSV value that spreadsheet programs never run as a formula (CSV injection): page URLs and
	 * browser names come from visitors, so a value starting with = + - @ gets a leading quote.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Delete records older than N months.
	 *
	 * @param int $months Months to keep (0 = keep everything).
	 * @return int Rows deleted.
	 */
	public static function purge( $months ) {
		global $wpdb;
		$months = absint( $months );
		if ( ! $months ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff ) );
	}

	/**
	 * Delete the records of one consent ID (used to remove test records).
	 *
	 * @param string $consent_id Consent ID.
	 */
	public static function delete_by_consent_id( $consent_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'consent_id' => (string) $consent_id ) );
	}

	/**
	 * Delete all records.
	 */
	public static function delete_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
	}

	/**
	 * Counts per choice for the last N days.
	 *
	 * @param int $days Days.
	 * @return array choice => count.
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - absint( $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT choice, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY choice', $table, $since ), ARRAY_A );
		$out  = array_fill_keys( self::CHOICES, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['choice'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Choices per day for the dashboard chart (site time zone), oldest day first.
	 *
	 * @param int $days Number of days, today included.
	 * @return array Y-m-d => array( choice => count ) for every day, also days without records.
	 */
	public static function daily( $days = 30 ) {
		global $wpdb;
		$days   = max( 1, min( 365, absint( $days ) ) );
		$today  = new DateTimeImmutable( 'today', wp_timezone() );
		$first  = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$offset = $today->getOffset();
		$out    = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$out[ $first->modify( "+{$i} days" )->format( 'Y-m-d' ) ] = array_fill_keys( self::CHOICES, 0 );
		}
		// Records are stored in UTC: shift by the site's offset so a day is the site's day.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS day, choice, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY day, choice',
				$offset,
				self::table(),
				gmdate( 'Y-m-d H:i:s', $first->getTimestamp() )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ][ $row['choice'] ] ) ) {
				$out[ $row['day'] ][ $row['choice'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	/**
	 * How many records allowed each optional category (dashboard), in the last N days.
	 *
	 * @param int $days Days.
	 * @return array category => count, plus 'total' => number of records.
	 */
	public static function category_counts( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - absint( $days ) * DAY_IN_SECONDS );
		$out   = array( 'total' => 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT categories, COUNT(*) AS total FROM %i WHERE created_at >= %s GROUP BY categories', self::table(), $since ), ARRAY_A );
		foreach ( SCCM_Categories::KEYS as $key ) {
			if ( 'necessary' !== $key ) {
				$out[ $key ] = 0;
			}
		}
		foreach ( (array) $rows as $row ) {
			$out['total'] += (int) $row['total'];
			foreach ( explode( ',', (string) $row['categories'] ) as $key ) {
				if ( isset( $out[ $key ] ) && 'total' !== $key ) {
					$out[ $key ] += (int) $row['total'];
				}
			}
		}
		return $out;
	}

	/**
	 * Records per banner version, newest version first (Consent Records, Dashboard).
	 *
	 * @return array version => count.
	 */
	public static function version_counts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT consent_version AS v, COUNT(*) AS total FROM %i GROUP BY consent_version ORDER BY consent_version DESC', self::table() ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['v'] ] = (int) $row['total'];
		}
		return $out;
	}

	/**
	 * Visitor IP from REMOTE_ADDR (proxies can override with the `sccm_client_ip` filter).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the detected client IP (e.g. to read a trusted proxy header).
		 *
		 * @param string $ip IP address.
		 */
		$ip = (string) apply_filters( 'sccm_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Apply the IP storage setting.
	 *
	 * @param string $ip Raw IP.
	 * @return string
	 */
	public static function process_ip( $ip ) {
		if ( '' === $ip ) {
			return '';
		}
		switch ( SCCM_Settings::get( 'ip_mode' ) ) {
			case 'none':
				return '';
			case 'hash':
				return self::hash_ip( $ip );
			case 'full':
				return $ip;
			default:
				return self::anonymize_ip( $ip );
		}
	}

	/**
	 * Remove the host part of an IP (IPv4 last octet, IPv6 last 80 bits).
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function anonymize_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.***', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				// The first 48 bits (the network) are kept, the rest is hidden.
				$groups = unpack( 'n3', substr( $packed, 0, 6 ) );
				return vsprintf( '%x:%x:%x', array_values( $groups ) ) . str_repeat( ':****', 5 );
			}
		}
		return '';
	}

	/**
	 * The one-way code of an IP address: always the same code for the same address on this
	 * website (keyed with the site's secret salt), but the address cannot be worked out from it.
	 *
	 * @param string $ip IP address.
	 * @return string 32 hex characters.
	 */
	public static function hash_ip( $ip ) {
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Every form an IP address can have in the records (full, shortened, one-way code), so a
	 * search by IP finds a visitor's records whichever setting was used when they were saved.
	 *
	 * @param string $ip IP address.
	 * @return string[] Three values (full, shortened, code); empty strings for an invalid address.
	 */
	public static function ip_forms( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return array( '', '', '' );
		}
		return array( $ip, self::anonymize_ip( $ip ), self::hash_ip( $ip ) );
	}

	/**
	 * Keep only same-site URLs (path + query, max 2048 chars).
	 *
	 * @param string $url URL sent by the browser.
	 * @return string
	 */
	private static function clean_url( $url ) {
		$url  = esc_url_raw( (string) $url );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			return '';
		}
		return mb_substr( $url, 0, 2048 );
	}

	/**
	 * Filter values for the record queries, validated. Without a filter: the search matches every
	 * ID ('%'), the choice is '' (switched off in the query) and the dates span every record. Real
	 * dates are used on purpose: MySQL in strict mode refuses to compare a date column with ''.
	 *
	 * @param array $args search (consent ID or IP address), choice, from (Y-m-d), to (Y-m-d), version.
	 * @return array The values for the placeholders of self::WHERE, in order.
	 */
	private static function filters( array $args ) {
		global $wpdb;
		$term    = ! empty( $args['search'] ) ? trim( sanitize_text_field( $args['search'] ) ) : '';
		$is_ip   = '' !== $term && filter_var( $term, FILTER_VALIDATE_IP );
		// The search box takes a consent ID, or an IP address (found in any stored form).
		$search  = '' !== $term && ! $is_ip ? '%' . $wpdb->esc_like( $term ) . '%' : '%';
		$ip      = $is_ip ? self::ip_forms( $term ) : array( '', '', '' );
		$choice  = ! empty( $args['choice'] ) && in_array( $args['choice'], self::CHOICES, true ) ? $args['choice'] : '';
		$from    = ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ? $args['from'] . ' 00:00:00' : '1000-01-01 00:00:00';
		$to      = ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ? $args['to'] . ' 23:59:59' : '9999-12-31 23:59:59';
		$version = absint( $args['version'] ?? 0 );
		return array( $search, $choice, $choice, $from, $to, $ip[0], $ip[0], $ip[1], $ip[2], $version, $version );
	}
}
