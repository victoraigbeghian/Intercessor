<?php
/**
 * Conversion between stored UTC datetimes and the site's local time.
 *
 * @package Intercessor
 * @since   1.1.3
 */

declare(strict_types=1);

namespace Intercessor\Util;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Helpers for showing and querying the plugin's datetime columns.
 *
 * Every date_created and date_modified column is written in UTC by the
 * query layer. Anything shown to a person, and any "today / this week"
 * report boundary, has to be converted to or from the site timezone.
 * These helpers are the one place that conversion happens.
 *
 * @since   1.1.3
 * @package Intercessor
 */
final class Local_Time {

	/**
	 * Format a stored UTC datetime in the site timezone and locale.
	 *
	 * Drop-in replacement for mysql2date(), which formats the stored value
	 * as if it were already local and so shows UTC wall-clock time.
	 *
	 * @since  1.1.3
	 * @param  string $format       PHP date format.
	 * @param  string $utc_datetime Stored value, 'Y-m-d H:i:s' in UTC.
	 * @return string               Formatted local date, or '' for an empty or invalid value.
	 */
	public static function format( string $format, string $utc_datetime ): string {
		if ( '' === $utc_datetime || str_starts_with( $utc_datetime, '0000-00-00' ) ) {
			return '';
		}

		$timestamp = strtotime( $utc_datetime . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * Convert a site-local 'Y-m-d H:i:s' string to the same instant in UTC.
	 *
	 * Used to turn local calendar boundaries ("start of today") into values
	 * that can be compared with the stored UTC columns.
	 *
	 * @since  1.1.3
	 * @param  string $local_datetime 'Y-m-d H:i:s' in the site timezone.
	 * @return string                 'Y-m-d H:i:s' in UTC.
	 */
	public static function to_utc( string $local_datetime ): string {
		$local = new \DateTimeImmutable( $local_datetime, wp_timezone() );

		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Current offset of the site timezone from UTC, in seconds.
	 *
	 * Used by report queries to group stored UTC datetimes by local calendar
	 * day or week (DATE_ADD( column, INTERVAL n SECOND )). It is the offset in
	 * force now, so across a daylight-saving change rows from the other side
	 * of the change are shifted by the current offset rather than their own.
	 * That can only misplace a row submitted within an hour of local midnight.
	 *
	 * @since  1.1.3
	 * @return int Offset in seconds; negative west of UTC.
	 */
	public static function utc_offset_seconds(): int {
		return wp_timezone()->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}
}
