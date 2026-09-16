<?php
/**
 * Query class for the prayer_requests table.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Database\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\BerlinDB\Query;
use Intercessor\Database\Row\Prayer_Request;
use Intercessor\Database\Schema\Prayer_Requests_Schema;

/**
 * Provides CRUD and domain-specific query methods for the prayer_requests table.
 *
 * Inherits all BerlinDB base CRUD methods. The @method tags below expose the
 * correctly typed signatures for IDE autocompletion and static analysis.
 *
 * @since   1.0.0
 * @package Intercessor
 *
 * @method Prayer_Request|false get_item( int $id )
 * @method PrayerRequest[]     get_items( array $args = [] )
 * @method int|false           add_item( array $data )
 * @method bool                update_item( int $id, array $data )
 * @method bool                delete_item( int $id )
 * @method int                 count_items( array $args = [] )
 */
final class Prayer_Request_Query extends Query {

	/**
	 * The complete set of valid prayer_requests.status values.
	 *
	 * @since 1.0.2
	 * @var   string[]
	 */
	public const VALID_STATUSES = array( 'pending', 'approved', 'rejected', 'archived', 'private' );

	/**
	 * Shared prefix for all Intercessor table names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $prefix = 'intercessor';

	/**
	 * Table name segment (appended to prefix + $wpdb->prefix).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_name = 'prayer_requests';

	/**
	 * Short SQL alias used in query fragments.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_alias = 'pr';

	/**
	 * Fully-qualified Schema subclass name.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_schema = Prayer_Requests_Schema::class;

	/**
	 * Singular item label used for cache keys and hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_name = 'prayer_request';

	/**
	 * Plural item label used for cache keys and hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_name_plural = 'prayer_requests';

	/**
	 * Fully-qualified Row subclass instantiated for each result.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_shape = Prayer_Request::class;

	/**
	 * Count all prayer requests currently awaiting moderation.
	 *
	 * Lightweight single-column COUNT — safe to call on every page load.
	 *
	 * @since  1.0.2
	 * @return int Total number of pending prayer requests.
	 */
	public function count_pending(): int {
		return $this->count_items( array( 'status' => 'pending' ) );
	}

	/**
	 * Count approved prayer requests created within a given calendar period.
	 *
	 * BerlinDB's buildWhere() only recognises exact schema column names and
	 * silently ignores any key it cannot map to a column — including the
	 * 'date_query' array that WP_Query / WP_Date_Query support. Passing
	 * 'date_query' to count_items() therefore has no effect and would return
	 * all approved prayers regardless of date.
	 *
	 * This method bypasses the BerlinDB query layer and issues a direct
	 * $wpdb prepared statement with a BETWEEN clause so the date boundary
	 * is actually enforced.
	 *
	 * Dates are calculated in the site timezone (via wp_timezone()) so that
	 * "today" and "this week" honour the site's configured UTC offset rather
	 * than the server's system timezone.
	 *
	 * @since  1.0.2
	 * @param  string $period One of 'today', 'week', 'month', or 'year'.
	 * @return int            Number of approved prayers in the given period.
	 */
	public function count_approved_for_period( string $period ): int {
		global $wpdb;

		[ $after, $before ] = $this->build_date_range( $period );

		$sql = $wpdb->prepare(
			'SELECT COUNT(*)
			FROM %i
			WHERE status = %s
			AND date_created >= %s
			AND date_created < %s',
			$this->fq_table_name,
			'approved',
			$after,
			$before
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Build the after/before datetime strings for a named calendar period.
	 *
	 * Delegates to Date::period_boundaries() so that the single canonical
	 * implementation is used everywhere (Date.php, Prayer_Request_Stats,
	 * and here), including its half-open interval convention:
	 * $after is inclusive, $before is the start of the next period (exclusive).
	 *
	 * @since  1.0.2
	 * @since  1.0.1 Delegates to Date::period_boundaries() — no longer duplicates logic.
	 * @param  string $period One of 'today', 'week', 'month', or 'year'.
	 * @return array{0: string, 1: string} [ $after, $before ] as 'Y-m-d H:i:s' strings.
	 */
	private function build_date_range( string $period ): array {
		return \Intercessor\Database\Queries\Date::period_boundaries( $period );
	}
	/**
	 * Retrieve approved, publicly visible prayer requests.
	 *
	 * Merges caller-supplied $args on top of sensible defaults so the caller
	 * can override pagination, ordering, or add extra filters.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $args {
	 *     Optional. Override arguments merged on top of defaults.
	 *
	 *     @type string $status    Request status filter. Default 'approved'.
	 *     @type int    $is_public Visibility flag filter. Default 1.
	 *     @type string $orderby   Column to sort by. Default 'date_created'.
	 *     @type string $order     Sort direction, 'ASC' or 'DESC'. Default 'DESC'.
	 *     @type int    $number    Maximum rows to return. Default 20.
	 *     @type int    $offset    Rows to skip for pagination. Default 0.
	 * }
	 * @return PrayerRequest[] Array of hydrated PrayerRequest row objects.
	 */
	public function get_public_approved( array $args = [] ): array {
		$defaults = array(
			'status'    => 'approved',
			'is_public' => 1,
			'orderby'   => 'date_created',
			'order'     => 'DESC',
		);

		return $this->get_items( array_merge( $defaults, $args ) );
	}

	/**
	 * Retrieve all prayer requests awaiting moderation, oldest first.
	 *
	 * @since  1.0.0
	 * @return PrayerRequest[] Array of pending prayer request row objects.
	 */
	public function get_pending(): array {
		return $this->get_items(
			array(
				'status'  => 'pending',
				'orderby' => 'date_created',
				'order'   => 'ASC',
			)
		);
	}

	/**
	 * Update a prayer request's status and record an immutable history entry.
	 *
	 * Fetches the current row first to capture the old status, updates the
	 * status column, then writes a PrayerHistory record capturing the
	 * transition details and the acting user's ID. Returns false without
	 * writing history if the request ID does not exist.
	 *
	 * @since  1.0.0
	 * @param  int    $id        Primary key of the prayer request to update.
	 * @param  string $newStatus New status value to apply (e.g. 'approved').
	 * @param  string $note      Optional moderator note to store with the history entry.
	 * @return bool              True when the update succeeded; false otherwise.
	 */
	public function update_status( int $id, string $newStatus, string $note = '' ): bool {
		$item = $this->get_item( $id );

		if ( ! $item ) {
			return false;
		}

		$updated = $this->update_item( $id, array( 'status' => $newStatus ) );

		if ( $updated ) {
			$historyQuery = new Prayer_History_Query();
			$historyQuery->add_item(
				array(
					'prayer_request_id' => $id,
					'old_status'        => $item->status,
					'new_status'        => $newStatus,
					'actor_user_id'     => get_current_user_id(),
					'note'              => $note,
				)
			);
		}

		return $updated;
	}

	/**
	 * Update the status of multiple prayer requests in a single operation.
	 *
	 * Iterates the supplied IDs and calls updateStatus() for each one, which
	 * writes a PrayerHistory entry per transition. Silently skips IDs that
	 * do not correspond to existing rows.
	 *
	 * @since  1.0.0
	 * @param  int[]  $ids       Primary keys of the requests to update.
	 * @param  string $newStatus New status to apply to all selected requests.
	 * @param  string $note      Optional moderator note recorded in each history entry.
	 * @return int               Number of requests successfully updated.
	 */
	public function bulk_update_status( array $ids, string $newStatus, string $note = '' ): int {
		$updated = 0;

		foreach ( $ids as $id ) {
			if ( $this->update_status( absint( $id ), $newStatus, $note ) ) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Permanently delete multiple prayer requests and all their child records.
	 *
	 * Cascades to prayer_history, prayer_notes, and prayed_counts rows for
	 * each request ID before deleting the parent prayer_requests row. The
	 * plugin does not use database-level foreign key constraints, so this
	 * manual cascade is required to keep the database consistent.
	 *
	 * @since  1.0.0
	 * @param  int[] $ids Primary keys of the requests to delete.
	 * @return int        Number of requests successfully deleted.
	 */
	public function bulk_delete( array $ids ): int {
		$historyQuery = new Prayer_History_Query();
		$noteQuery    = new \Intercessor\Database\Query\Prayer_Note_Query();
		$countQuery   = new \Intercessor\Database\Query\Prayed_Count_Query();

		$deleted = 0;

		foreach ( $ids as $id ) {
			$id = absint( $id );

			if ( $id === 0 ) {
				continue;
			}

			// Delete child records before the parent to avoid orphans.
			$historyQuery->delete_all_for_request( $id );
			$noteQuery->delete_all_for_request( $id );
			$countQuery->delete_all_for_request( $id );

			if ( $this->delete_item( $id ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

}
