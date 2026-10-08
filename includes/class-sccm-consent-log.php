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

		$consent_id = isset( $data['consent_id'] ) ? strtolower( (string) $data['consent_id'] ) : '';
		if ( ! preg_match( '/^[a-f0-9-]{16,64}$/', $consent_id ) ) {
			return new WP_Error( 'sccm_bad_id', 'Invalid consent id.' );
		}
		$choice = isset( $data['choice'] ) ? (string) $data['choice'] : '';
		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return new WP_Error( 'sccm_bad_choice', 'Invalid choice.' );
		}

		$categories = array( 'necessary' );
		foreach ( (array) ( $data['categories'] ?? array() ) as $cat ) {
			if ( SCCM_Categories::is_optional( $cat ) && ! in_array( $cat, $categories, true ) ) {
				$categories[] = $cat;
			}
		}

		$row = array(
			'consent_id'      => $consent_id,
			'choice'          => $choice,
			'categories'      => implode( ',', $categories ),
			'gpc'             => empty( $data['gpc'] ) ? 0 : 1,
			'consent_version' => max( 1, absint( $data['version'] ?? 1 ) ),
			'url'             => self::clean_url( $data['url'] ?? '' ),
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

		list( $search, $choice, $from, $to ) = self::filters( $args );
		$table                               = self::table();
		$per_page                            = max( 1, min( 500, absint( $args['per_page'] ?? 50 ) ) );
		$page                                = max( 1, absint( $args['page'] ?? 1 ) );
		$offset                              = ( $page - 1 ) * $per_page;

		// One fixed statement: filters that are not set match every record (see filters()).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE consent_id LIKE %s AND (%s = '' OR choice = %s) AND created_at >= %s AND created_at <= %s",
				$table,
				$search,
				$choice,
				$choice,
				$from,
				$to
			)
		);
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE consent_id LIKE %s AND (%s = '' OR choice = %s) AND created_at >= %s AND created_at <= %s ORDER BY id DESC LIMIT %d OFFSET %d",
				$table,
				$search,
				$choice,
				$choice,
				$from,
				$to,
				$per_page,
				$offset
			),
			ARRAY_A
		);
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

		list( $search, $choice, $from, $to ) = self::filters( $args );
		$table                               = self::table();

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'id', 'consent_id', 'date_time_utc', 'choice', 'categories', 'gpc', 'consent_version', 'url', 'ip', 'user_agent' ) );

		$batch  = 1000;
		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE consent_id LIKE %s AND (%s = '' OR choice = %s) AND created_at >= %s AND created_at <= %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$table,
					$search,
					$choice,
					$choice,
					$from,
					$to,
					$batch,
					$offset
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
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
			$offset += $batch;
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
				return substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
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
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				$packed = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
				return (string) inet_ntop( $packed );
			}
		}
		return '';
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
	 * @param array $args search, choice, from (Y-m-d), to (Y-m-d).
	 * @return array array( search LIKE pattern, choice, from datetime, to datetime ).
	 */
	private static function filters( array $args ) {
		global $wpdb;
		$search = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%' : '%';
		$choice = ! empty( $args['choice'] ) && in_array( $args['choice'], self::CHOICES, true ) ? $args['choice'] : '';
		$from   = ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['from'] ) ? $args['from'] . ' 00:00:00' : '1000-01-01 00:00:00';
		$to     = ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['to'] ) ? $args['to'] . ' 23:59:59' : '9999-12-31 23:59:59';
		return array( $search, $choice, $from, $to );
	}
}
