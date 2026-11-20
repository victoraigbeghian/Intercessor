<?php
/**
 * Submission rate limiter.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Util;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;

/**
 * Enforces the prayer request submission limits.
 *
 * Two limits apply: a daily limit per email address, and since 1.1.3 an
 * hourly limit per IP address (see is_client_allowed()), which the first
 * cannot provide on its own because the email is supplied by the client.
 *
 * The limit is configured via the 'max_requests_per_day' plugin setting.
 * A value of 0 disables rate limiting entirely.
 *
 * The check works by:
 *   1. Looking up the requester record for the given email address.
 *   2. Counting prayer requests created by that requester in the last 24 hours
 *      using a direct $wpdb query (BerlinDB's date_query support is limited,
 *      so we use a raw WHERE date_created >= NOW() - INTERVAL 1 DAY).
 *   3. Returning false (blocked) when the count meets or exceeds the limit.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Rate_Limiter {

	/**
	 * Check whether the given email address has exceeded the daily submission limit.
	 *
	 * Returns true (allowed) when:
	 *   - The limit setting is 0 (disabled).
	 *   - The email has no existing requester record (first submission).
	 *   - The requester's submission count in the last 24 h is below the limit.
	 *
	 * Returns false (blocked) when the count meets or exceeds the limit.
	 *
	 * @since  1.0.0
	 * @param  string $email Sanitized email address of the submitter.
	 * @return bool          True when the submission is allowed; false when blocked.
	 */
	public static function is_allowed( string $email ): bool {
		$limit = (int) Settings::get( 'max_requests_per_day', 3 );

		// 0 = rate limiting disabled.
		if ( $limit === 0 ) {
			return true;
		}

		// Look up the requester record for this email.
		$requester_query = new Requester_Query();
		$existing        = $requester_query->get_items(
			array(
				'email'  => $email,
				'number' => 1,
			)
		);

		// No existing requester → this is their first submission, always allow.
		if ( empty( $existing ) ) {
			return true;
		}

		$requesterId = (int) $existing[0]->id;
		$count       = self::count_recent_submissions( $requesterId );

		return $count < $limit;
	}

	/**
	 * Return the number of prayer requests submitted by a requester in the last 24 hours.
	 *
	 * Uses a direct $wpdb query with a date comparison rather than BerlinDB's
	 * get_items() because the base Query class does not yet expose date_query
	 * args. The table name is retrieved via the global $wpdb object.
	 *
	 * @since  1.0.0
	 * @param  int $requesterId Requester primary key.
	 * @return int              Count of prayer requests in the last 24 hours.
	 */
	private static function count_recent_submissions( int $requesterId ): int {
		global $wpdb;

		// WordPress-safe table identifier (WP 6.3+ supports %i)
		$table = $wpdb->prefix . 'intercessor_prayer_requests';

		// Use WP time (respects timezone settings)
		$after = gmdate(
			'Y-m-d H:i:s',
			current_time( 'timestamp', true ) - DAY_IN_SECONDS
		);

		$sql = $wpdb->prepare(
			'SELECT COUNT(*)
			FROM %i
			WHERE requester_id = %d
			AND date_created >= %s',
			$table,
			$requesterId,
			$after
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Return the configured daily limit for display in error messages.
	 *
	 * @since  1.0.0
	 * @return int Configured limit; 0 means disabled.
	 */
	public static function get_limit(): int {
		return (int) Settings::get( 'max_requests_per_day', 3 );
	}

	// -------------------------------------------------------------------------
	// Per-client limit
	// -------------------------------------------------------------------------

	/**
	 * Default number of submissions accepted from one IP address per hour.
	 *
	 * Deliberately generous: a congregation on one Wi-Fi network shares an
	 * address. The limit exists to stop a script, not a busy Sunday.
	 *
	 * @since 1.1.3
	 * @var   int
	 */
	private const DEFAULT_CLIENT_LIMIT = 20;

	/**
	 * Return the number of submissions one IP address may make per hour.
	 *
	 * Returns 0 (disabled) when the per-email limit is set to 0, so the
	 * existing "set to 0 to disable rate limiting" setting switches off both
	 * limits. Sites behind a proxy or CDN that does not pass the visitor's
	 * address through to REMOTE_ADDR see every visitor as one client and
	 * should raise or disable this with the filter.
	 *
	 * @since  1.1.3
	 * @return int Submissions per hour per IP address; 0 means disabled.
	 */
	public static function get_client_limit(): int {
		if ( self::get_limit() === 0 ) {
			return 0;
		}

		/**
		 * Filters how many prayer requests one IP address may submit per hour.
		 *
		 * @since 1.1.3
		 * @param int $limit Submissions per hour; return 0 to disable the check.
		 */
		return max( 0, (int) apply_filters( 'intercessor_max_requests_per_ip_per_hour', self::DEFAULT_CLIENT_LIMIT ) );
	}

	/**
	 * Check whether the current client may submit another prayer request.
	 *
	 * The per-email limit alone is bypassed by changing the address on each
	 * submission. This second limit is keyed on the connecting IP address and
	 * does not depend on anything the client supplies.
	 *
	 * @since  1.1.3
	 * @return bool True when the submission is allowed; false when blocked.
	 */
	public static function is_client_allowed(): bool {
		$limit = self::get_client_limit();
		$key   = self::client_key();

		if ( $limit === 0 || $key === '' ) {
			return true;
		}

		return self::client_window( $key )['count'] < $limit;
	}

	/**
	 * Count one accepted submission against the current client.
	 *
	 * The first submission starts a one-hour window; later ones increment
	 * the count without extending it.
	 *
	 * @since  1.1.3
	 * @return void
	 */
	public static function record_client_submission(): void {
		$key = self::client_key();

		if ( $key === '' || self::get_client_limit() === 0 ) {
			return;
		}

		$window = self::client_window( $key );
		$window['count']++;

		// Expire at the end of the original hour. Re-setting with a fresh
		// hour on every submission would let a steady trickle hold the
		// window open forever.
		$remaining = max( 1, $window['start'] + HOUR_IN_SECONDS - time() );

		set_transient( $key, $window, $remaining );
	}

	/**
	 * Read the current counting window for a client key.
	 *
	 * The start time is stored with the count so the window length does not
	 * depend on how the transient backend reports expiry.
	 *
	 * @since  1.1.3
	 * @param  string $key Transient key from client_key().
	 * @return array{count: int, start: int} Current window; a new one when none is active.
	 */
	private static function client_window( string $key ): array {
		$stored = get_transient( $key );

		if (
			is_array( $stored )
			&& isset( $stored['count'], $stored['start'] )
			&& (int) $stored['start'] + HOUR_IN_SECONDS > time()
		) {
			return array(
				'count' => (int) $stored['count'],
				'start' => (int) $stored['start'],
			);
		}

		return array(
			'count' => 0,
			'start' => time(),
		);
	}

	/**
	 * Build the transient key for the connecting client.
	 *
	 * Uses REMOTE_ADDR only. Forwarded-for headers are client-controlled
	 * unless a trusted proxy sets them, and trusting them here would let a
	 * script pick a new identity per request. The address is hashed so it is
	 * not stored in the options table.
	 *
	 * @since  1.1.3
	 * @return string Transient key, or '' when the address is unavailable.
	 */
	private static function client_key(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP address below.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return 'intercessor_rl_' . md5( wp_salt( 'nonce' ) . $ip );
	}
}
