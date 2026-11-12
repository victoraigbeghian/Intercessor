<?php
/**
 * Prayer request statistics aggregator.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Reports;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Queries\Date;
use Intercessor\Util\Local_Time;

/**
 * Provides query methods for prayer request data.
 *
 * This aggregator wraps the timezone-aware
 * Date class to produce counts, breakdowns, and time-series data for the
 * Reports page. All queries use direct $wpdb prepared statements so the Date
 * class's WHERE clauses are composable without going through BerlinDB's
 * buildWhere() (which does not support date sub-clauses).
 *
 * Usage:
 *   $stats = new Prayer_Request_Stats();
 *
 *   // Count by status + period.
 *   $count = $stats->get_count( 'approved', 'today' );
 *
 *   // Full status breakdown for a period.
 *   $breakdown = $stats->get_status_breakdown( 'month' );
 *
 *   // Daily submission series for a month.
 *   $series = $stats->get_daily_series( 'month' );
 *
 *   // Top requesters by count.
 *   $top = $stats->get_top_requesters( 5, 'year' );
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Prayer_Request_Stats {

	/** @var string All valid prayer request statuses. */
	public const ALL_STATUSES = array( 'pending', 'approved', 'rejected', 'archived', 'private' );

	/** @var string wp_cache group for all stats caches. */
	private const CACHE_GROUP = 'intercessor';

	/** @var int Stats cache TTL in seconds (5 minutes). */
	private const CACHE_TTL = 300;

	/** @var string Fully-qualified table name (without prefix, prefix added in __construct). */
	private string $table;

	/**
	 * Initialise the stats object with the resolved table name.
	 *
	 * @since  1.0.0
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'intercessor_prayer_requests';
	}

	/**
	 * Flush all stats caches.
	 *
	 * Called by Submission_Pipeline after a successful insert and by the
	 * status-change hook so Reports numbers update immediately.
	 *
	 * wp_cache_flush_group() is used when available (WP 6.1+ / persistent
	 * cache plugins). For sites without group-flush support, individual keys
	 * are deleted instead; the object cache will evict them naturally via TTL.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function bust_cache(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
			return;
		}

		// Fallback: delete the Dashboard Widget transient so it also refreshes.
		delete_transient( 'intercessor_dashboard_stats' );
	}

	// ── Core count methods ────────────────────────────────────────────────────

	/**
	 * Count prayer requests by status and optional period.
	 *
	 * @since  1.0.0
	 * @param  string $status  Status value, or '*' / '' for all statuses.
	 * @param  string $period  Named period (see Date::for_period()) or 'all_time'.
	 * @return int             Matching row count.
	 */
	public function get_count( string $status = '*', string $period = 'all_time' ): int {
		$cache_key = 'ipr_count_' . md5( $status . '|' . $period );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		if ( $status === '*' || $status === '' ) {
			if ( 'all_time' === $period ) {
				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE 1=1',
					$this->table
				);
			} else {
				[ $after, $before ] = Date::period_boundaries_utc( $period );
				$sql = $wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE date_created >= %s AND date_created < %s',
					$this->table,
					$after,
					$before
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var( $sql );
			wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );
			return $count;
		}

		if ( 'all_time' === $period ) {
			$sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s',
				$this->table,
				$status
			);
		} else {
			[ $after, $before ] = Date::period_boundaries_utc( $period );
			$sql = $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND date_created >= %s AND date_created < %s',
				$this->table,
				$status,
				$after,
				$before
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $sql );
		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );
		return $count;
	}

	/**
	 * Return counts for every status for a given period.
	 *
	 * @since  1.0.0
	 * @param  string $period  Named period or 'all_time'.
	 * @return array<string, int>  Map of status → count.
	 */
	public function get_status_breakdown( string $period = 'all_time' ): array {
		$cache_key = 'ipr_breakdown_' . md5( $period );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		if ( 'all_time' === $period ) {
			$sql = $wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt FROM %i WHERE 1=1 GROUP BY status',
				$this->table
			);
		} else {
			[ $after, $before ] = Date::period_boundaries_utc( $period );
			$sql = $wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt FROM %i WHERE date_created >= %s AND date_created < %s GROUP BY status',
				$this->table,
				$after,
				$before
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$result = array_fill_keys( self::ALL_STATUSES, 0 );

		foreach ( (array) $rows as $row ) {
			if ( isset( $result[ $row['status'] ] ) ) {
				$result[ $row['status'] ] = (int) $row['cnt'];
			}
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return counts of all prayers grouped by calendar day for a period.
	 *
	 * Useful for rendering a time-series chart or table of submissions per day.
	 * Days with zero submissions are included so the series has no gaps.
	 *
	 * @since  1.0.0
	 * @param  string $period  Named period ('today', 'week', 'month', 'year').
	 * @param  string $status  Status filter, or '*' for all.
	 * @return array<int, array{date: string, count: int}> Ordered by date ASC.
	 */
	public function get_daily_series( string $period = 'month', string $status = '*' ): array {
		global $wpdb;

		$cache_key = 'daily_series_' . md5( $period . '|' . $status );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$sql = $wpdb->prepare(
			'SELECT
				DATE( DATE_ADD( date_created, INTERVAL %d SECOND ) ) AS day,
				COUNT(*) AS cnt
			FROM %i
			WHERE 1=1',
			Local_Time::utc_offset_seconds(),
			$this->table
		);

		$sql = $this->append_period_sql( $sql, $period );

		if ( '*' !== $status && '' !== $status ) {
			$sql .= $wpdb->prepare(
				' AND status = %s',
				$status
			);
		}

		$sql .= ' GROUP BY day ORDER BY day ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: array();

		[
			$after,
			$before,
		] = Date::period_boundaries(
			'all_time' === $period ? 'year' : $period
		);

		$timezone = wp_timezone();
		$current  = new \DateTimeImmutable( $after, $timezone );
		$end      = new \DateTimeImmutable( $before, $timezone );

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ $row['day'] ] = (int) $row['cnt'];
		}

		$series = array();

		while ( $current < $end ) {
			$day = $current->format( 'Y-m-d' );

			$series[] = array(
				'date'  => $day,
				'count' => $counts[ $day ] ?? 0,
			);

			$current = $current->modify( '+1 day' );
		}

		wp_cache_set( $cache_key, $series, self::CACHE_GROUP, self::CACHE_TTL );

		return $series;
	}

	/**
	 * Return counts grouped by calendar week for a period.
	 *
	 * @since  1.0.0
	 * @param  string $period Named period.
	 * @param  string $status Status filter.
	 * @return array<int, array{week_start: string, count: int}>
	 */
	public function get_weekly_series( string $period = 'year', string $status = '*' ): array {
		$cache_key = 'ipr_weekly_' . md5( $period . '|' . $status );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$start_of_week = (int) get_option( 'start_of_week', 0 );

		// date_created is UTC; shift it to site-local time before bucketing
		// so a week (and its year) is the site's calendar week.
		$offset = Local_Time::utc_offset_seconds();

		if ( 1 === $start_of_week ) {
			$sql = $wpdb->prepare(
				'SELECT
					WEEK( DATE_ADD( date_created, INTERVAL %d SECOND ), 1 ) AS wk,
					YEAR( DATE_ADD( date_created, INTERVAL %d SECOND ) ) AS yr,
					COUNT(*) AS cnt
				FROM %i
				WHERE 1=1',
				$offset,
				$offset,
				$this->table
			);
		} elseif ( $start_of_week >= 2 && $start_of_week <= 6 ) {
			$sql = $wpdb->prepare(
				'SELECT
					WEEK( DATE_SUB( DATE_ADD( date_created, INTERVAL %d SECOND ), INTERVAL %d DAY ), 0 ) AS wk,
					YEAR( DATE_ADD( date_created, INTERVAL %d SECOND ) ) AS yr,
					COUNT(*) AS cnt
				FROM %i
				WHERE 1=1',
				$offset,
				$start_of_week,
				$offset,
				$this->table
			);
		} else {
			$sql = $wpdb->prepare(
				'SELECT
					WEEK( DATE_ADD( date_created, INTERVAL %d SECOND ), 0 ) AS wk,
					YEAR( DATE_ADD( date_created, INTERVAL %d SECOND ) ) AS yr,
					COUNT(*) AS cnt
				FROM %i
				WHERE 1=1',
				$offset,
				$offset,
				$this->table
			);
		}

		$sql = $this->append_period_sql( $sql, $period );

		if ( '*' !== $status && '' !== $status ) {
			$sql .= $wpdb->prepare(
				' AND status = %s',
				$status
			);
		}

		$sql .= ' GROUP BY yr, wk ORDER BY yr ASC, wk ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		$series = array();

		foreach ( $rows as $row ) {
			$series[] = array(
				'label' => sprintf(
					/* translators: 1: year number, 2: week number */
					__( 'Week %2$d, %1$d', 'intercessor' ),
					(int) $row['yr'],
					(int) $row['wk']
				),
				'count' => (int) $row['cnt'],
			);
		}

		wp_cache_set( $cache_key, $series, self::CACHE_GROUP, self::CACHE_TTL );

		return $series;
	}

	/**
	 * Return the top N requesters by prayer request count.
	 *
	 * @since  1.0.0
	 * @param  int    $limit  Number of requesters to return.
	 * @param  string $period Named period or 'all_time'.
	 * @param  string $status Status filter or '*'.
	 * @return array<int, array{requester_id: int, name: string, email: string, count: int}>
	 */
	public function get_top_requesters(
		int $limit = 10,
		string $period = 'all_time',
		string $status = '*'
	): array {
		$cache_key = 'ipr_top_req_' . md5( $limit . '|' . $period . '|' . $status );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$limit = max( 0, $limit );

		$sql = $wpdb->prepare(
			'SELECT
				pr.requester_id,
				r.first_name,
				r.last_name,
				r.name,
				r.email,
				COUNT(*) AS cnt
			FROM %i pr
			LEFT JOIN %i r
				ON r.id = pr.requester_id
			WHERE 1=1',
			$this->table,
			$wpdb->prefix . 'intercessor_requesters'
		);

		$sql = $this->append_period_sql( $sql, $period, 'pr.date_created' );

		if ( '*' !== $status && '' !== $status ) {
			$sql .= $wpdb->prepare(
				' AND pr.status = %s',
				$status
			);
		}

		$sql .= ' GROUP BY pr.requester_id ORDER BY cnt DESC';

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare(
				' LIMIT %d',
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		$mapped = array_map(
			static function ( array $row ): array {
				$name = trim(
					( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' )
				);

				if ( '' === $name ) {
					$name = $row['name'] ?: __( '(unnamed)', 'intercessor' );
				}

				return array(
					'requester_id' => (int) $row['requester_id'],
					'name'         => $name,
					'email'        => $row['email'] ?? '',
					'count'        => (int) $row['cnt'],
				);
			},
			$rows
		);

		wp_cache_set( $cache_key, $mapped, self::CACHE_GROUP, self::CACHE_TTL );

		return $mapped;
	}

	/**
	 * Return summary stats for the overview report card row.
	 *
	 * @since  1.0.0
	 * @param  string $period Named period.
	 * @return array{total:int, pending:int, approved:int, rejected:int, archived:int, private:int}
	 */
	public function get_overview( string $period = 'all_time' ): array {
		$breakdown = $this->get_status_breakdown( $period );

		return array(
			'total'    => array_sum( $breakdown ),
			'pending'  => $breakdown['pending'],
			'approved' => $breakdown['approved'],
			'rejected' => $breakdown['rejected'],
			'archived' => $breakdown['archived'],
			'private'  => $breakdown['private'],
		);
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Append a prepared date boundary clause for a named period.
	 *
	 * Returns the original SQL for 'all_time' so queries are unrestricted.
	 *
	 * @since  1.0.0
	 * @param  string $sql    Prepared SQL to append to.
	 * @param  string $period Named period or 'all_time'.
	 * @param  string $column Date column expression.
	 * @return string         SQL with a prepared date boundary clause appended.
	 */
	private function append_period_sql( string $sql, string $period, string $column = 'date_created' ): string {
		global $wpdb;

		if ( $period === 'all_time' ) {
			return $sql;
		}

		[ $after, $before ] = Date::period_boundaries_utc( $period );

		if ( 'pr.date_created' === $column ) {
			return $sql . $wpdb->prepare(
				' AND pr.date_created >= %s AND pr.date_created < %s',
				$after,
				$before
			);
		}

		return $sql . $wpdb->prepare(
			' AND date_created >= %s AND date_created < %s',
			$after,
			$before
		);
	}
}
