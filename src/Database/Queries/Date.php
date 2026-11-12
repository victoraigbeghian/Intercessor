<?php
/**
 * Timezone-aware date query class for Intercessor BerlinDB tables.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Database\Queries;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Generates SQL WHERE sub-clauses that filter BerlinDB query results by date.
 *
 * This class is adapted from Intercessor\BerlinDB\Queries\Date (uploaded) and
 * WordPress's WP_Date_Query. Two significant changes are made:
 *
 * 1. Timezone handling — the original class uses time() and gmdate() (UTC)
 *    throughout. Calendar periods here are worked out in the site timezone
 *    instead, so "today" means the site's local day.
 *
 * @note  Intercessor stores date_created and date_modified in UTC. The
 *        period helpers return site-local boundaries; code that compares
 *        them with a stored column must use period_boundaries_utc().
 *
 *    Period helpers (today, week, month, year) use DateTimeImmutable with
 *    wp_timezone() so boundaries are always in local calendar time.
 *
 * Usage:
 *   $date = new Date( array(
 *       'after'     => '2025-01-01',
 *       'before'    => '2025-01-31',
 *       'inclusive' => true,
 *       'column'    => 'date_created',
 *   ) );
 *   $sql = $date->get_sql_clauses(); // returns ['join' => '', 'where' => ' AND ...']
 *
 *   // Period shortcuts via Prayer_Request_Stats:
 *   $stats = new Prayer_Request_Stats();
 *   $count = $stats->get_count( [ 'period' => 'today', 'status' => 'approved' ] );
 *
 * @since   1.0.0
 * @package Intercessor
 */
class Date {

	// ── Properties ────────────────────────────────────────────────────────────

	/** @var array Sanitized date query clauses. */
	public array $queries = array();

	/** @var string Top-level relation: 'AND' or 'OR'. */
	public string $relation = 'AND';

	/** @var string Default column to query. */
	public string $column = 'date_created';

	/** @var string Default comparison operator. */
	public string $compare = '=';

	/** @var int Day week starts on (0=Sun … 6=Sat); defaults to WP option. */
	public int $start_of_week = 0;

	/**
	 * Current local-time Unix timestamp.
	 *
	 * Uses current_time('timestamp') (site timezone) instead of time() (UTC).
	 *
	 * @var int
	 */
	public int $now = 0;

	/** @var array<string> Supported time-related clause keys. */
	public array $time_keys = array(
		'after', 'before', 'value',
		'year', 'month', 'monthnum', 'week', 'w',
		'dayofyear', 'day', 'dayofweek', 'dayofweek_iso',
		'hour', 'minute', 'second',
	);

	/** @var array<string> Supported comparison operators. */
	public array $comparison_keys = array(
		'=', '!=', '>', '>=', '<', '<=',
		'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN',
	);

	/** @var array<string> Multi-value comparison operators. */
	public array $multi_value_keys = array(
		'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN',
	);

	/** @var array<string> Supported relation operators. */
	public array $relation_keys = array( 'OR', 'AND' );

	public $db;

	// ── Constructor ───────────────────────────────────────────────────────────

	/**
	 * Initialise the date query from an array of clauses.
	 *
	 * Accepts the same structure as WP_Date_Query: an array of clause arrays
	 * with optional top-level keys 'relation', 'column', 'compare',
	 * 'start_of_week'. Time-related first-order keys ('after', 'before',
	 * 'year', 'month', etc.) may be passed at the top level directly.
	 *
	 * The key timezone difference: 'now' — when supplied as a Unix timestamp
	 * — is treated as a LOCAL-time timestamp (from current_time()). When
	 * omitted, current_time('timestamp') is used.
	 *
	 * @since  1.0.0
	 * @param  array $date_query Array of date clauses.
	 */
	public function __construct( array $date_query = array() ) {
		if ( empty( $date_query ) ) {
			return;
		}

		// Use site-local time instead of UTC.
		$this->now           = $this->get_now( $date_query );
		$this->column        = $this->get_column( $date_query );
		$this->compare       = $this->get_compare( $date_query );
		$this->relation      = $this->get_relation( $date_query );
		$this->start_of_week = $this->get_start_of_week( $date_query );

		// Support time-based keys at the top level of the array.
		if ( ! isset( $date_query[0] ) ) {
			$date_query = array( $date_query );
		}

		$this->queries = $this->sanitize_query( $date_query );
	}

	// ── Core query methods ────────────────────────────────────────────────────

	/**
	 * Generate the final SQL WHERE sub-clauses array.
	 *
	 * Returns an array with 'join' (always empty for date queries) and 'where'
	 * (the prepared SQL fragment starting with ' AND '). Append the 'where'
	 * value directly to a custom query's WHERE clause.
	 *
	 * @since  1.0.0
	 * @return array{join: string, where: string}
	 */
	public function get_sql_clauses(): array {
		$sql = $this->get_sql_for_query( $this->queries );

		if ( ! empty( $sql['where'] ) ) {
			$sql['where'] = ' AND ' . $sql['where'];
		}

		return $sql;
	}

	/**
	 * Recursive-friendly query sanitizer.
	 *
	 * Ensures every clause array has the required defaults and recurses into
	 * nested sub-queries so the full tree is validated before SQL generation.
	 *
	 * @since  1.0.0
	 * @param  array $queries      Array of query clauses.
	 * @param  array $parent_query Parent clause for inheritance.
	 * @return array               Sanitized clause array.
	 */
	public function sanitize_query( array $queries = array(), array $parent_query = array() ): array {
		$retval   = array();
		$defaults = array(
			'now'           => $this->get_now(),
			'column'        => $this->get_column(),
			'compare'       => $this->get_compare(),
			'relation'      => $this->get_relation(),
			'start_of_week' => $this->get_start_of_week(),
		);

		// Numeric keys must have array values.
		foreach ( $queries as $qkey => $qvalue ) {
			if ( is_numeric( $qkey ) && ! is_array( $qvalue ) ) {
				unset( $queries[ $qkey ] );
			}
		}

		// Inherit defaults from parent.
		foreach ( $defaults as $dkey => $dvalue ) {
			if ( isset( $queries[ $dkey ] ) ) {
				continue;
			}
			$queries[ $dkey ] = isset( $parent_query[ $dkey ] )
				? $parent_query[ $dkey ]
				: $dvalue;
		}

		if ( $this->is_first_order_clause( $queries ) ) {
			$this->validate_date_values( $queries );
		}

		foreach ( $queries as $key => $q ) {
			if ( ! is_array( $q ) || in_array( $key, $this->time_keys, true ) ) {
				$retval[ $key ] = $q;
			} else {
				$retval[] = $this->sanitize_query( $q, $queries );
			}
		}

		return $retval;
	}

	// ── Getters ───────────────────────────────────────────────────────────────

	/**
	 * Return the current UTC Unix timestamp for date-boundary calculations.
	 *
	 * Uses time() (UTC), which is correct for use with wp_date() — that
	 * function expects a UTC timestamp and handles the site-timezone
	 * conversion internally. Replaces the former current_time('timestamp')
	 * call, which was soft-deprecated in WordPress 5.3 and returned a
	 * non-standard local-time Unix value.
	 *
	 * @since  1.0.0
	 * @since  1.0.1 Replaced deprecated current_time('timestamp') with time().
	 * @param  array $query Optional query to extract an explicit 'now' value from.
	 * @return int          UTC Unix timestamp.
	 */
	public function get_now( array $query = array() ): int {
		if ( ! empty( $query['now'] ) && is_numeric( $query['now'] ) ) {
			return absint( $query['now'] );
		}

		return time();
	}

	/**
	 * Determine and validate the column to query.
	 *
	 * @since  1.0.0
	 * @param  array $query Optional query array.
	 * @return string       Validated column name.
	 */
	public function get_column( array $query = array() ): string {
		return ! empty( $query['column'] )
			? $this->validate_column( $query['column'] )
			: $this->column;
	}

	/**
	 * Determine and validate the comparison operator.
	 *
	 * @since  1.0.0
	 * @param  array $query Optional query array.
	 * @return string       Comparison operator.
	 */
	public function get_compare( array $query = array() ): string {
		return ! empty( $query['compare'] )
			&& in_array( $query['compare'], $this->comparison_keys, true )
			? strtoupper( $query['compare'] )
			: $this->compare;
	}

	/**
	 * Determine and validate the relation operator.
	 *
	 * @since  1.0.0
	 * @param  array $query Optional query array.
	 * @return string       'AND' or 'OR'.
	 */
	public function get_relation( array $query = array() ): string {
		return ! empty( $query['relation'] )
			&& in_array( $query['relation'], $this->relation_keys, true )
			? strtoupper( $query['relation'] )
			: $this->relation;
	}

	/**
	 * Determine and validate the start-of-week day.
	 *
	 * Defaults to the WordPress 'start_of_week' option rather than hardcoding
	 * Sunday (0), ensuring week boundaries respect the site configuration.
	 *
	 * @since  1.0.0
	 * @param  array $query Optional query array.
	 * @return int          0 (Sunday) through 6 (Saturday).
	 */
	public function get_start_of_week( array $query = array() ): int {
		if ( isset( $query['start_of_week'] )
			&& (int) $query['start_of_week'] >= 0
			&& (int) $query['start_of_week'] <= 6
		) {
			return (int) $query['start_of_week'];
		}

		// Default to the WordPress site option — not hardcoded 0.
		return (int) get_option( 'start_of_week', 0 );
	}

	// ── Period shortcut helpers (new in Intercessor) ──────────────────────────

	/**
	 * Build a Date instance pre-configured for a named calendar period.
	 *
	 * All boundaries are computed in the site timezone so that "today" means
	 * the correct local calendar day, "this week" honours the WP start-of-week
	 * setting, etc.
	 *
	 * Supported period strings:
	 *   'today'       — from 00:00:00 to 23:59:59 of the current day.
	 *   'yesterday'   — same for yesterday.
	 *   'week'        — from start_of_week day at 00:00:00 to today 23:59:59.
	 *   'last_week'   — the full previous calendar week.
	 *   'month'       — from first to last day of the current month.
	 *   'last_month'  — the full previous calendar month.
	 *   'year'        — from 1 Jan to 31 Dec of the current year.
	 *   'last_year'   — the full previous calendar year.
	 *   'all_time'    — no date restriction (returns an empty Date instance).
	 *
	 * @since  1.0.0
	 * @param  string $period  Named period string.
	 * @param  string $column  Column to filter on. Default 'date_created'.
	 * @return self            Configured Date instance.
	 */
	public static function for_period( string $period, string $column = 'date_created' ): self {
		if ( $period === 'all_time' ) {
			return new self();
		}

		[ $after, $before ] = self::period_boundaries_utc( $period );

		return new self( array(
			'column'    => $column,
			'after'     => $after,
			'before'    => $before,
			'inclusive' => true,
		) );
	}

	/**
	 * Calculate the after/before datetime strings for a named period.
	 *
	 * All calculations use wp_timezone() + DateTimeImmutable so boundaries
	 * are always in the site's local calendar time.
	 *
	 * @since  1.0.0
	 * @param  string $period  Named period string (see for_period()).
	 * @return array{0: string, 1: string}  [ $after, $before ] as Y-m-d H:i:s.
	 */
	public static function period_boundaries( string $period ): array {
		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );

		switch ( $period ) {
			case 'today':
				$after  = $now->setTime( 0, 0, 0 );
				$before = $now->modify( '+1 day' )->setTime( 0, 0, 0 );
				break;

			case 'yesterday':
				$after  = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
				$before = $now->setTime( 0, 0, 0 );
				break;

			case 'week':
				$sow              = (int) get_option( 'start_of_week', 0 );
				$dow              = (int) $now->format( 'w' );
				$days_since_start = ( $dow - $sow + 7 ) % 7;
				$after            = $now->modify( "-{$days_since_start} days" )->setTime( 0, 0, 0 );
				$before           = $now->modify( '+1 day' )->setTime( 0, 0, 0 );
				break;

			case 'last_week':
				$sow              = (int) get_option( 'start_of_week', 0 );
				$dow              = (int) $now->format( 'w' );
				$days_since_start = ( $dow - $sow + 7 ) % 7;
				$this_week_start  = $now->modify( "-{$days_since_start} days" )->setTime( 0, 0, 0 );
				$after            = $this_week_start->modify( '-7 days' );
				$before           = $this_week_start;
				break;

			case 'month':
				$after  = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$before = $now->modify( 'first day of next month' )->setTime( 0, 0, 0 );
				break;

			case 'last_month':
				$after  = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
				$before = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				break;

			case 'year':
				$y      = (int) $now->format( 'Y' );
				$after  = new \DateTimeImmutable( "{$y}-01-01 00:00:00", $tz );
				$before = new \DateTimeImmutable( ( $y + 1 ) . '-01-01 00:00:00', $tz );
				break;

			case 'last_year':
				$y      = (int) $now->format( 'Y' ) - 1;
				$after  = new \DateTimeImmutable( "{$y}-01-01 00:00:00", $tz );
				$before = new \DateTimeImmutable( ( $y + 1 ) . '-01-01 00:00:00', $tz );
				break;

			default:
				// Unknown period — return full year as safe default.
				$y      = (int) $now->format( 'Y' );
				$after  = new \DateTimeImmutable( "{$y}-01-01 00:00:00", $tz );
				$before = new \DateTimeImmutable( ( $y + 1 ) . '-01-01 00:00:00', $tz );
				break;
		}

		return array(
			$after->format( 'Y-m-d H:i:s' ),
			$before->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Same boundaries as period_boundaries(), converted to UTC.
	 *
	 * The stored date columns are UTC, so this is the form to use in a WHERE
	 * clause. period_boundaries() remains the form to use when labelling a
	 * period or building a local day-by-day series.
	 *
	 * @since  1.1.3
	 * @param  string $period  Named period string (see for_period()).
	 * @return array{0: string, 1: string}  [ $after, $before ] as Y-m-d H:i:s in UTC.
	 */
	public static function period_boundaries_utc( string $period ): array {
		[ $after, $before ] = self::period_boundaries( $period );

		return array(
			\Intercessor\Util\Local_Time::to_utc( $after ),
			\Intercessor\Util\Local_Time::to_utc( $before ),
		);
	}

	// ── Validation ────────────────────────────────────────────────────────────

	/**
	 * Return true when the query array contains at least one time-related key.
	 *
	 * @since  1.0.0
	 * @param  array $query Query clause.
	 * @return bool
	 */
	protected function is_first_order_clause( array $query ): bool {
		return ! empty( array_intersect( $this->time_keys, array_keys( $query ) ) );
	}

	/**
	 * Validate values in a first-order date clause.
	 *
	 * Generates debug notices for out-of-range values (month > 12, etc.)
	 * without aborting — invalid queries will simply return no results.
	 *
	 * @since  1.0.0
	 * @param  array $date_query First-order clause.
	 * @return bool              True when all values are valid.
	 */
	public function validate_date_values( array $date_query = array() ): bool {
		if ( empty( $date_query ) ) {
			return false;
		}

		$valid = true;

		if ( isset( $date_query['before'] ) && is_array( $date_query['before'] ) ) {
			$valid = $this->validate_date_values( $date_query['before'] );
		}
		if ( isset( $date_query['after'] ) && is_array( $date_query['after'] ) ) {
			$valid = $this->validate_date_values( $date_query['after'] );
		}
		if ( isset( $date_query['value'] ) ) {
			return true;
		}

		$year    = $date_query['year'] ?? null;
		$_year   = is_array( $year ) ? reset( $year ) : $year;

		$checks = array(
			'dayofyear'     => array( 'min' => 1,  'max' => $_year ? (int) wp_date( 'z', mktime( 0, 0, 0, 12, 31, (int) $_year ) ) + 1 : 366 ),
			'dayofweek'     => array( 'min' => 1,  'max' => 7  ),
			'dayofweek_iso' => array( 'min' => 1,  'max' => 7  ),
			'month'         => array( 'min' => 1,  'max' => 12 ),
			'week'          => array( 'min' => 1,  'max' => $_year ? (int) wp_date( 'W', mktime( 0, 0, 0, 12, 28, (int) $_year ) ) : 53 ),
			'day'           => array( 'min' => 1,  'max' => 31 ),
			'hour'          => array( 'min' => 0,  'max' => 23 ),
			'minute'        => array( 'min' => 0,  'max' => 59 ),
			'second'        => array( 'min' => 0,  'max' => 59 ),
		);

		foreach ( $checks as $key => $range ) {
			if ( ! isset( $date_query[ $key ] ) ) {
				continue;
			}
			foreach ( (array) $date_query[ $key ] as $v ) {
				if ( ! is_numeric( $v ) || $v < $range['min'] || $v > $range['max'] ) {
					$valid = false;
				}
			}
		}

		if ( $valid && isset( $date_query['day'], $date_query['month'] ) ) {
			$y = $_year ?? '2012';
			if ( ! checkdate( (int) $date_query['month'], (int) $date_query['day'], (int) $y ) ) {
				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Validate a column name — allow only word chars, dots, underscores, and $.
	 *
	 * @since  1.0.0
	 * @param  string $column User-supplied column name.
	 * @return string         Sanitized column name.
	 */
	public function validate_column( string $column ): string {
		return preg_replace( '/[^a-zA-Z0-9_$\.]/', '', $column );
	}

	// ── SQL generation ────────────────────────────────────────────────────────

	/**
	 * Generate SQL clauses for a full query tree (recursive).
	 *
	 * @since  1.0.0
	 * @param  array $query Sanitized query tree.
	 * @param  int   $depth Current recursion depth (for indentation).
	 * @return array{join: string, where: string}
	 */
	protected function get_sql_for_query( array $query = array(), int $depth = 0 ): array {
		$chunks   = array( 'join' => array(), 'where' => array() );
		$sql      = array( 'join' => '', 'where' => '' );
		$indent   = str_repeat( '  ', $depth );
		$relation = 'AND';

		foreach ( $query as $key => $clause ) {
			if ( $key === 'relation' ) {
				$relation = $this->get_relation( array( 'relation' => $clause ) );
			} elseif ( is_array( $clause ) ) {
				if ( $this->is_first_order_clause( $clause ) ) {
					$clause_sql  = $this->get_sql_for_clause( $clause, $query );
					$where_count = count( $clause_sql['where'] );

					if ( $where_count === 0 ) {
						$chunks['where'][] = '';
					} elseif ( $where_count === 1 ) {
						$chunks['where'][] = $clause_sql['where'][0];
					} else {
						$chunks['where'][] = '( ' . implode( ' AND ', $clause_sql['where'] ) . ' )';
					}

					$chunks['join'] = array_merge( $chunks['join'], $clause_sql['join'] );
				} else {
					$sub              = $this->get_sql_for_query( $clause, $depth + 1 );
					$chunks['where'][] = $sub['where'];
					$chunks['join'][]  = $sub['join'];
				}
			}
		}

		$chunks['join']  = array_filter( $chunks['join'] );
		$chunks['where'] = array_filter( $chunks['where'] );

		if ( ! empty( $chunks['join'] ) ) {
			$sql['join'] = implode( ' ', array_unique( $chunks['join'] ) );
		}

		if ( ! empty( $chunks['where'] ) ) {
			$glue        = " \n  {$indent}{$relation} \n  {$indent}";
			$sql['where'] = "( \n  {$indent}" . implode( $glue, $chunks['where'] ) . "\n{$indent})";
		}

		return $sql;
	}

	/**
	 * Turn a first-order date clause into prepared SQL fragments.
	 *
	 * The key timezone change: build_mysql_datetime() now calls wp_date()
	 * (local time) instead of gmdate() (UTC) so string boundaries like
	 * 'first day of this month' resolve to the local calendar date.
	 *
	 * @since  1.0.0
	 * @param  array $query        First-order clause.
	 * @param  array $parent_query Parent clause.
	 * @return array{join: array, where: array<string>}
	 */
	protected function get_sql_for_clause( array $query = array(), array $parent_query = array() ): array {
		global $wpdb;

		$where_parts   = array();
		$now           = $this->get_now( $query );
		$column        = $this->get_column( $query );
		$compare       = $this->get_compare( $query );
		$start_of_week = $this->get_start_of_week( $query );
		$inclusive     = ! empty( $query['inclusive'] );
		$lt            = $inclusive ? '<=' : '<';
		$gt            = $inclusive ? '>=' : '>';

		if ( ! empty( $query['after'] ) ) {
			$where_parts[] = $wpdb->prepare(
				"{$column} {$gt} %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->build_mysql_datetime( $query['after'], ! $inclusive, $now )
			);
		}

		if ( ! empty( $query['before'] ) ) {
			$where_parts[] = $wpdb->prepare(
				"{$column} {$lt} %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->build_mysql_datetime( $query['before'], $inclusive, $now )
			);
		}

		if ( isset( $query['year'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['year'] ) )
		) {
			$where_parts[] = "YEAR( {$column} ) {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['month'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['month'] ) )
		) {
			$where_parts[] = "MONTH( {$column} ) {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( isset( $query['monthnum'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['monthnum'] ) )
		) {
			$where_parts[] = "MONTH( {$column} ) {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['week'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['week'] ) )
		) {
			$week_sql      = $this->build_mysql_week( $column, $start_of_week );
			$where_parts[] = "{$week_sql} {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( isset( $query['w'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['w'] ) )
		) {
			$week_sql      = $this->build_mysql_week( $column, $start_of_week );
			$where_parts[] = "{$week_sql} {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['dayofyear'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['dayofyear'] ) )
		) {
			$where_parts[] = "DAYOFYEAR( {$column} ) {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['day'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['day'] ) )
		) {
			$where_parts[] = "DAYOFMONTH( {$column} ) {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['dayofweek'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['dayofweek'] ) )
		) {
			$where_parts[] = "DAYOFWEEK( {$column} ) {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['dayofweek_iso'] )
			&& false !== ( $value = $this->build_numeric_value( $compare, $query['dayofweek_iso'] ) )
		) {
			$where_parts[] = "WEEKDAY( {$column} ) + 1 {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['value'] ) ) {
			$value         = $this->build_value( $compare, $query['value'] );
			$where_parts[] = "{$column} {$compare} {$value}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $query['hour'] ) || isset( $query['minute'] ) || isset( $query['second'] ) ) {
			foreach ( array( 'hour', 'minute', 'second' ) as $unit ) {
				$query[ $unit ] = $query[ $unit ] ?? null;
			}
			$time_sql = $this->build_time_query(
				$column, $compare,
				$query['hour'], $query['minute'], $query['second']
			);
			if ( $time_sql ) {
				$where_parts[] = $time_sql;
			}
		}

		return array( 'where' => $where_parts, 'join' => array() );
	}

	// ── Value builders ────────────────────────────────────────────────────────

	/**
	 * Build a validated numeric value string for use in SQL.
	 *
	 * @since  1.0.0
	 * @param  string           $compare  Comparison operator.
	 * @param  int|float|array  $value    Numeric value(s).
	 * @return string|int|false           SQL-safe value or false on invalid input.
	 */
	public function build_numeric_value( string $compare, $value = null ) {
		if ( is_null( $value ) ) {
			return false;
		}

		switch ( $compare ) {
			case 'IN':
			case 'NOT IN':
				$value = array_filter( (array) $value, 'is_numeric' );
				return empty( $value ) ? false : '(' . implode( ',', array_map( 'intval', $value ) ) . ')';

			case 'BETWEEN':
			case 'NOT BETWEEN':
				if ( ! is_array( $value ) || count( $value ) !== 2 ) {
					$value = array( $value, $value );
				}
				$value = array_values( $value );
				foreach ( $value as $v ) {
					if ( ! is_numeric( $v ) ) {
						return false;
					}
				}
				$value = array_map( 'intval', $value );
				return $value[0] . ' AND ' . $value[1];

			default:
				return is_numeric( $value ) ? (int) $value : false;
		}
	}

	/**
	 * Build a prepared SQL value string for any comparison type.
	 *
	 * @since  1.0.0
	 * @param  string       $compare  Comparison operator.
	 * @param  string|array $value    Raw value(s).
	 * @return string                 Prepared SQL fragment.
	 */
	public function build_value( string $compare, $value = null ): string {
		global $wpdb;

		$compare = strtoupper( trim( $compare ) );

		// Normalize multi-value inputs.
		if ( in_array( $compare, $this->multi_value_keys, true ) ) {
			$values = is_array( $value )
				? $value
				: preg_split( '/[,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );

			$values = array_map( 'trim', array_values( (array) $values ) );
		} else {
			$values = array( trim( (string) $value ) );
		}

		// Build the appropriate SQL fragment based on the comparison type.
		switch ( $compare ) {

			case 'IN':
			case 'NOT IN':
				if ( empty( $values ) ) {
					return '(NULL)';
				}

				$placeholders = implode(
					',',
					array_fill( 0, count( $values ), '%s' )
				);

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder list is generated exclusively from fixed %s tokens.
				$prepared = $wpdb->prepare( $placeholders, $values );

				return '(' . $prepared . ')';

			case 'BETWEEN':
			case 'NOT BETWEEN':
				$values = array_values( array_slice( $values, 0, 2 ) );

				$values = array_pad( $values, 2, '' );

				return $wpdb->prepare(
					'%s AND %s',
					$values[0],
					$values[1]
				);

			case 'LIKE':
			case 'NOT LIKE':
				return $wpdb->prepare(
					'%s',
					'%' . $wpdb->esc_like( $values[0] ) . '%'
				);

			default:
				return $wpdb->prepare(
					'%s',
					$values[0]
				);
		}
	}

	/**
	 * Build a MySQL datetime string from an array or string value.
	 *
	 * TIMEZONE FIX: the original used gmdate() (UTC). This version uses
	 * wp_date() which formats in the site's local timezone so that string
	 * boundaries ('first day of this month', '2025-06-01', etc.) resolve
	 * to local calendar dates.
	 *
	 * @since  1.0.0
	 * @param  string|array $datetime        Array of date parts or strtotime() string.
	 * @param  bool         $default_to_max  Round up incomplete dates to end-of-period.
	 * @param  int          $now             Local-time Unix timestamp.
	 * @return string                        'Y-m-d H:i:s' datetime string.
	 */
	public function build_mysql_datetime( $datetime, bool $default_to_max = false, int $now = 0 ): string {
		if ( $now === 0 ) {
			$now = $this->now ?: time();
		}

		if ( is_string( $datetime ) ) {
			$matches = array();

			if ( preg_match( '/^(\d{4})$/', $datetime, $matches ) ) {
				$datetime = array( 'year' => (int) $matches[1] );
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array( 'year' => (int) $matches[1], 'month' => (int) $matches[2] );
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array( 'year' => (int) $matches[1], 'month' => (int) $matches[2], 'day' => (int) $matches[3] );
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2}) (\d{2}):(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array( 'year' => (int) $matches[1], 'month' => (int) $matches[2], 'day' => (int) $matches[3], 'hour' => (int) $matches[4], 'minute' => (int) $matches[5] );
			} elseif ( preg_match( '/^(\d{4})\-(\d{2})\-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $datetime, $matches ) ) {
				$datetime = array( 'year' => (int) $matches[1], 'month' => (int) $matches[2], 'day' => (int) $matches[3], 'hour' => (int) $matches[4], 'minute' => (int) $matches[5], 'second' => (int) $matches[6] );
			}
		}

		if ( ! is_array( $datetime ) ) {
			$ts = ! is_int( $datetime ) ? strtotime( (string) $datetime, $now ) : (int) $datetime;

			// TIMEZONE FIX: use wp_date() (local) instead of gmdate() (UTC).
			return wp_date( 'Y-m-d H:i:s', $ts );
		}

		$datetime = array_map( 'absint', $datetime );

		// TIMEZONE FIX: derive defaults from local time.
		$local_year  = (int) wp_date( 'Y', $now );
		$local_month = (int) wp_date( 'n', $now );

		$datetime['year']   = $datetime['year']   ?? $local_year;
		$datetime['month']  = $datetime['month']  ?? ( $default_to_max ? 12 : 1 );
		$datetime['day']    = $datetime['day']     ?? (
			$default_to_max
				? (int) wp_date( 't', mktime( 0, 0, 0, $datetime['month'], 1, $datetime['year'] ) )
				: 1
		);
		$datetime['hour']   = $datetime['hour']   ?? ( $default_to_max ? 23 : 0 );
		$datetime['minute'] = $datetime['minute'] ?? ( $default_to_max ? 59 : 0 );
		$datetime['second'] = $datetime['second'] ?? ( $default_to_max ? 59 : 0 );

		return sprintf(
			'%04d-%02d-%02d %02d:%02d:%02d',
			$datetime['year'], $datetime['month'],  $datetime['day'],
			$datetime['hour'], $datetime['minute'], $datetime['second']
		);
	}

	/**
	 * Build a MySQL WEEK() expression that respects the start-of-week setting.
	 *
	 * @since  1.0.0
	 * @param  string $column        Column name (pre-validated).
	 * @param  int    $start_of_week 0=Sunday, 1=Monday, etc.
	 * @return string                SQL WEEK() expression.
	 */
	public function build_mysql_week( string $column, int $start_of_week = 0 ): string {
		switch ( $start_of_week ) {
			case 1:
				return "WEEK( {$column}, 1 )";
			case 2:
			case 3:
			case 4:
			case 5:
			case 6:
				return "WEEK( DATE_SUB( {$column}, INTERVAL {$start_of_week} DAY ), 0 )";
			case 0:
			default:
				return "WEEK( {$column}, 0 )";
		}
	}

	/**
	 * Build a time comparison SQL fragment (hour/minute/second).
	 *
	 * @since  1.0.0
	 * @param  string   $column  Pre-validated column name.
	 * @param  string   $compare Comparison operator.
	 * @param  int|null $hour    Hour (0–23).
	 * @param  int|null $minute  Minute (0–59).
	 * @param  int|null $second  Second (0–59).
	 * @return string|false      SQL fragment or false on invalid input.
	 */
	public function build_time_query( string $column, string $compare, ?int $hour, ?int $minute, ?int $second ) {
		global $wpdb;

		if ( is_null( $hour ) && is_null( $minute ) && is_null( $second ) ) {
			return false;
		}

		if ( in_array( $compare, $this->multi_value_keys, true ) ) {
			$parts = array();
			foreach ( array( 'hour' => 'HOUR', 'minute' => 'MINUTE', 'second' => 'SECOND' ) as $var => $fn ) {
				if ( ! is_null( $$var ) && false !== ( $val = $this->build_numeric_value( $compare, $$var ) ) ) {
					$parts[] = "{$fn}( {$column} ) {$compare} {$val}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			return implode( ' AND ', $parts );
		}

		if ( ! is_null( $hour ) && is_null( $minute ) && is_null( $second )
			&& false !== ( $val = $this->build_numeric_value( $compare, $hour ) )
		) {
			return "HOUR( {$column} ) {$compare} {$val}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( is_null( $hour ) && ! is_null( $minute ) && is_null( $second )
			&& false !== ( $val = $this->build_numeric_value( $compare, $minute ) )
		) {
			return "MINUTE( {$column} ) {$compare} {$val}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( is_null( $hour ) && is_null( $minute ) && ! is_null( $second )
			&& false !== ( $val = $this->build_numeric_value( $compare, $second ) )
		) {
			return "SECOND( {$column} ) {$compare} {$val}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( is_null( $minute ) ) {
			return false;
		}

		$format = $time = '';

		if ( ! is_null( $hour ) ) {
			$format .= '%H.';
			$time   .= sprintf( '%02d', $hour ) . '.';
		} else {
			$format .= '0.';
			$time   .= '0.';
		}

		$format .= '%i';
		$time   .= sprintf( '%02d', $minute );

		if ( ! is_null( $second ) ) {
			$format .= '%s';
			$time   .= sprintf( '%02d', $second );
		}

		return $wpdb->prepare(
			"DATE_FORMAT( {$column}, %s ) {$compare} %f", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$format,
			(float) $time
		);
	}

	/**
	 * Sanitize a table name — strip anything not safe for a table alias.
	 *
	 * @since  1.0.0
	 * @param  string $name Raw table name.
	 * @return string       Sanitized table name.
	 */
	protected function sanitize_table_name( string $name ): string {
		return preg_replace( '/[^a-zA-Z0-9_]/', '', $name );
	}
}
