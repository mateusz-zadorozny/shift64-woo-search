<?php
/**
 * Search statistics logger (MySQL-based, works without Redis).
 *
 * @package Shift64_Woo_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search statistics tracker and reporter.
 */
class Shift64_Woo_Search_Stats {

	/**
	 * Get the stats table name.
	 *
	 * @return string Full table name with prefix.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'shift64_woo_search_stats';
	}

	/**
	 * Create the stats database table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            query_text VARCHAR(255) NOT NULL,
            query_normalized VARCHAR(255) NOT NULL,
            results_count INT UNSIGNED NOT NULL DEFAULT 0,
            response_time_ms DECIMAL(8,2) NOT NULL DEFAULT 0,
            search_mode VARCHAR(20) NOT NULL DEFAULT 'autocomplete',
            user_id BIGINT UNSIGNED DEFAULT NULL,
            session_id VARCHAR(64) DEFAULT NULL,
            ip_hash VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_normalized (query_normalized),
            INDEX idx_created (created_at),
            INDEX idx_no_results (results_count, created_at)
        ) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Normalize a search query for stats grouping.
	 *
	 * @param string $query Raw search query.
	 * @return string Normalized query string.
	 */
	public static function normalize_query( $query ) {
		$query = mb_strtolower( trim( $query ) );
		$query = preg_replace( '/\s+/', ' ', $query );
		$query = preg_replace( '/[^a-z0-9ąćęłńóśźżàâäéèêëïîôùûüÿœæ\s\-]/', '', $query );
		return $query;
	}

	/**
	 * Log a search query.
	 *
	 * @param string   $query          Original query text.
	 * @param int      $results_count  Number of results found.
	 * @param float    $response_time  Response time in ms.
	 * @param string   $mode           'autocomplete' or 'full'.
	 * @param int|null $user_id      Optional user ID.
	 */
	public static function log_search( $query, $results_count = 0, $response_time = 0.0, $mode = 'autocomplete', $user_id = null ) {
		global $wpdb;

		$table = self::get_table_name();

		// Hash the IP for privacy.
		$ip_hash = null;
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip_hash = hash( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) . wp_salt( 'auth' ) );
		}

		$session_id = null;
		if ( ! empty( $_COOKIE['shift64_woo_search_session'] ) ) {
			$session_id = sanitize_text_field( wp_unslash( $_COOKIE['shift64_woo_search_session'] ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'query_text'       => mb_substr( $query, 0, 255 ),
				'query_normalized' => mb_substr( self::normalize_query( $query ), 0, 255 ),
				'results_count'    => (int) $results_count,
				'response_time_ms' => (float) $response_time,
				'search_mode'      => in_array( $mode, array( 'autocomplete', 'full' ), true ) ? $mode : 'autocomplete',
				'user_id'          => $user_id,
				'session_id'       => $session_id ? mb_substr( $session_id, 0, 64 ) : null,
				'ip_hash'          => $ip_hash ? mb_substr( $ip_hash, 0, 64 ) : null,
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%f', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get top search queries.
	 *
	 * @param int $days  Number of days to look back.
	 * @param int $limit Number of results.
	 * @return array
	 */
	public static function get_top_searches( $days = 30, $limit = 20 ) {
		global $wpdb;

		$table = self::get_table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_normalized, COUNT(*) as search_count, AVG(results_count) as avg_results, AVG(response_time_ms) as avg_time
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY query_normalized
             ORDER BY search_count DESC
             LIMIT %d",
				$date,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get zero-result search queries.
	 *
	 * @param int $days  Number of days to look back.
	 * @param int $limit Maximum results to return.
	 * @return array
	 */
	public static function get_zero_result_searches( $days = 30, $limit = 20 ) {
		global $wpdb;

		$table = self::get_table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_normalized, COUNT(*) as search_count
             FROM {$table}
             WHERE created_at >= %s AND results_count = 0
             GROUP BY query_normalized
             ORDER BY search_count DESC
             LIMIT %d",
				$date,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove stats records older than $days.
	 *
	 * @param int $days Number of days to retain.
	 * @return int|false Number of deleted rows or false on error.
	 */
	public static function cleanup_old( $days = 90 ) {
		global $wpdb;

		$table = self::get_table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < %s",
				$date
			)
		);
	}

	/**
	 * Remove all stats records.
	 *
	 * @return int|false Number of deleted rows or false on error.
	 */
	public static function cleanup_all() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Get total search count for a period.
	 *
	 * @param int $days Number of days to look back.
	 * @return int
	 */
	public static function get_search_count( $days = 30 ) {
		global $wpdb;

		$table = self::get_table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
				$date
			)
		);
	}

	/**
	 * Get daily search counts for a volume chart.
	 *
	 * @param int $days Number of days to look back.
	 * @return array Array of objects with search_date and search_count.
	 */
	public static function get_daily_counts( $days = 30 ) {
		global $wpdb;

		$table = self::get_table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as search_date, COUNT(*) as search_count
             FROM {$table}
             WHERE created_at >= %s
             GROUP BY DATE(created_at)
             ORDER BY search_date ASC",
				$date
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get average response time for a period.
	 *
	 * @param int $days Number of days to look back.
	 * @return float
	 */
	public static function get_avg_response_time( $days = 30 ) {
		global $wpdb;

		$table = self::get_table_name();
		$date  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT AVG(response_time_ms) FROM {$table} WHERE created_at >= %s",
				$date
			)
		);
	}
}
