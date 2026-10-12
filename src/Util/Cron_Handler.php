<?php
/**
 * Cron handler for prayer-count notification emails.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Util;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings;
use Intercessor\Database\Query\Prayed_Count_Query;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Database\Row\Prayer_Request;
use Intercessor\Database\Row\Requester;

/**
 * Manages the scheduled cron job that emails requesters when their prayer
 * request has been prayed for.
 *
 * Architecture overview
 * ─────────────────────
 * WordPress cron supports three built-in intervals (hourly, twicedaily,
 * daily). This class registers two additional intervals — weekly and monthly —
 * via the 'cron_schedules' filter.
 *
 * A single cron event is used: 'intercessor_pray_notification'. Its schedule
 * is derived from the 'cron_frequency' setting ('daily', 'weekly', or
 * 'monthly'). Whenever the frequency or send-time setting changes, the
 * existing event is cleared and rescheduled.
 *
 * Deduplication
 * ─────────────
 * After each email batch, the current UTC timestamp is stored in
 * 'intercessor_cron_last_run' via update_option(). On the next run, only
 * prayer requests whose prayed-count was last updated AFTER that timestamp
 * are considered — preventing the same count from triggering a second email.
 *
 * The option 'intercessor_cron_notified' stores a JSON-encoded map of
 * { prayer_request_id => last_notified_count } so an email is sent only
 * when the count has grown since the previous notification.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Cron_Handler {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * Name of the WordPress cron event fired on each scheduled run.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public const EVENT_HOOK = 'intercessor_pray_notification';

	/**
	 * Option key that stores the UTC timestamp of the last completed run.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public const LAST_RUN_OPTION = 'intercessor_cron_last_run';

	/**
	 * Option key that stores a JSON map of { request_id => notified_count }.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public const NOTIFIED_OPTION = 'intercessor_cron_notified';

	/**
	 * Supported frequency identifiers.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	public const FREQUENCIES = array( 'daily', 'weekly', 'monthly' );

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Attach all WordPress hooks required by this class.
	 *
	 * Call once from Plugin::init().
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules',    array( $this, 'add_schedules' ) );
		add_action( self::EVENT_HOOK,    array( $this, 'run' ) );

		// Reschedule whenever the admin saves settings.
		add_action( 'update_option_intercessor_settings', array( $this, 'maybe_reschedule' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Custom cron intervals
	// -------------------------------------------------------------------------

	/**
	 * Register custom 'weekly' and 'monthly' cron schedule intervals.
	 *
	 * WordPress ships with 'hourly', 'twicedaily', and 'daily'. This filter
	 * appends 'weekly' (7 days) and 'monthly' (30 days) so
	 * wp_schedule_event() can use them.
	 *
	 * @since  1.0.0
	 * @param  array $schedules Existing cron schedule definitions.
	 * @return array            Modified schedules with weekly and monthly added.
	 */
	public function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'intercessor' ),
			);
		}

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly (every 30 days)', 'intercessor' ),
			);
		}

		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Schedule lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Schedule the cron event on plugin activation.
	 *
	 * Called from Activator::activate(). Calculates the first run timestamp
	 * based on the admin-configured send time (hour + minute in UTC) and
	 * schedules the event when it is not already registered.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::EVENT_HOOK ) ) {
			return;
		}

		wp_schedule_event(
			self::next_run_timestamp(),
			self::recurrence(),
			self::EVENT_HOOK
		);
	}

	/**
	 * Remove the scheduled cron event on plugin deactivation.
	 *
	 * Called from Deactivator::deactivate(). Uses wp_clear_scheduled_hook()
	 * rather than wp_unschedule_event() so all occurrences are removed.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT_HOOK );
	}

	/**
	 * Reschedule the event when the relevant settings change.
	 *
	 * Fired on 'update_option_intercessor_settings'. Compares the frequency
	 * and send-time values in the old and new option arrays; reschedules only
	 * when at least one has changed.
	 *
	 * @since  1.0.0
	 * @param  mixed $old_value The option value before the update.
	 * @param  mixed $new_value The option value after the update.
	 * @return void
	 */
	public function maybe_reschedule( mixed $old_value, mixed $new_value ): void {
		$old = is_array( $old_value ) ? $old_value : array();
		$new = is_array( $new_value ) ? $new_value : array();

		$keys = array( 'cron_frequency', 'cron_send_hour', 'cron_send_minute' );

		$changed = false;
		foreach ( $keys as $key ) {
			if ( ( $old[ $key ] ?? '' ) !== ( $new[ $key ] ?? '' ) ) {
				$changed = true;
				break;
			}
		}

		if ( $changed ) {
			self::unschedule();
			self::schedule();
		}
	}

	// -------------------------------------------------------------------------
	// Main cron callback
	// -------------------------------------------------------------------------

	/**
	 * Execute the notification run: query all approved and private requests,
	 * check prayed counts, and email requesters whose counts have grown
	 * since the last run.
	 *
	 * Fires on the 'intercessor_pray_notification' cron event.
	 *
	 * @since  1.0.0
	 * @since  1.1.1 Includes 'private' status requests so requesters whose
	 *               requests are hidden from the public Prayer Wall are
	 *               still notified when an admin/prayer warrior prays for
	 *               them via Admin_Loader::handle_admin_record_prayer().
	 * @return void
	 */
	public function run(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		/**
		 * Fires immediately before the prayer-count notification batch runs.
		 *
		 * @since 1.0.0
		 */
		do_action( 'intercessor_before_pray_notification_run' );

		$notified = self::get_notified_map();
		$updated  = $notified; // Will be persisted at end.

		$prayer_query = new Prayer_Request_Query();
		$count_query  = new Prayed_Count_Query();
		$req_query    = new Requester_Query();

		// Fetch all approved (public) and private requests. Private requests
		// never appear on the public Prayer Wall — the only way they ever
		// accumulate a prayed-for count is via the admin "I prayed for this"
		// button (Admin_Loader::handle_admin_record_prayer()) — but their
		// requesters still deserve to know someone prayed for them, so they
		// are swept into the same notification batch as public ones.
		// Pending/rejected/archived requests are intentionally excluded.
		// No arbitrary limit — cron runs in the background so memory budget
		// is the only practical constraint.
		$requests = $prayer_query->get_items( array(
			'status' => array( 'approved', 'private' ),
			'number' => 0,
		) );

		foreach ( $requests as $request ) {
			$total = $count_query->get_total_for_request( $request->id );

			// Skip if no one has prayed for this request yet.
			if ( $total === 0 ) {
				continue;
			}

			$last_notified = (int) ( $notified[ $request->id ] ?? 0 );

			// Skip if total has not grown since the last notification.
			if ( $total <= $last_notified ) {
				continue;
			}

			// Resolve the requester and send the email.
			if ( $request->requester_id <= 0 ) {
				continue;
			}

			$requester = $req_query->get_item( $request->requester_id );

			if ( ! $requester || empty( $requester->email ) ) {
				continue;
			}

			$sent = $this->send_notification( $request, $requester, $total );

			if ( $sent ) {
				$updated[ $request->id ] = $total;
			}
		}

		// Persist the updated notified map and record the run timestamp.
		update_option( self::NOTIFIED_OPTION, wp_json_encode( $updated ), false );
		update_option( self::LAST_RUN_OPTION, time(), false );

		/**
		 * Fires immediately after the notification batch completes.
		 *
		 * @since 1.0.0
		 * @param array $updated  Map of { request_id => notified_count } after this run.
		 */
		do_action( 'intercessor_after_pray_notification_run', $updated );
	}

	// -------------------------------------------------------------------------
	// Email dispatch
	// -------------------------------------------------------------------------

	/**
	 * Build and dispatch a single prayer-count notification email.
	 *
	 * Composes a plain-text message from the reusable email_body() template,
	 * applies the configured From name/address via the 'wp_mail_from' and
	 * 'wp_mail_from_name' filters, and calls wp_mail().
	 *
	 * @since  1.0.0
	 * @param  Prayer_Request $request   The prayer request row.
	 * @param  Requester      $requester The requester row (must have a non-empty email).
	 * @param  int            $total     Current total number of times prayed for.
	 * @return bool                      True when wp_mail() reports success.
	 */
	public function send_notification(
		Prayer_Request $request,
		Requester $requester,
		int $total
	): bool {
		$to      = sanitize_email( $requester->email );
		$subject = $this->email_subject( $request );
		$body    = $this->email_body( $request, $requester, $total );
		$headers = $this->email_headers();

		/**
		 * Filter the notification email recipient.
		 *
		 * @since 1.0.0
		 * @param string         $to        Sanitized email address.
		 * @param Prayer_Request $request   Prayer request row.
		 * @param Requester      $requester Requester row.
		 */
		$to = apply_filters( 'intercessor_pray_notification_to', $to, $request, $requester );

		/**
		 * Filter the notification email subject.
		 *
		 * @since 1.0.0
		 * @param string         $subject Subject line.
		 * @param Prayer_Request $request Prayer request row.
		 * @param int            $total   Current prayed count.
		 */
		$subject = apply_filters( 'intercessor_pray_notification_subject', $subject, $request, $total );

		/**
		 * Filter the notification email body.
		 *
		 * @since 1.0.0
		 * @param string         $body      Plain-text message body.
		 * @param Prayer_Request $request   Prayer request row.
		 * @param Requester      $requester Requester row.
		 * @param int            $total     Current prayed count.
		 */
		$body = apply_filters( 'intercessor_pray_notification_body', $body, $request, $requester, $total );

		return wp_mail( $to, $subject, $body, $headers );
	}

	// -------------------------------------------------------------------------
	// Email template helpers
	// -------------------------------------------------------------------------

	/**
	 * Compose the subject line for a prayer-count notification email.
	 *
	 * @since  1.0.0
	 * @param  Prayer_Request $request The prayer request row.
	 * @return string                  Translated subject line.
	 */
	public function email_subject( Prayer_Request $request ): string {
		// translators: %s: site name
		return sprintf(
			/* translators: %s: prayer request subject line */
			__( '[%1$s] Your prayer request has been prayed for: %2$s', 'intercessor' ),
			get_bloginfo( 'name' ),
			$request->subject
		);
	}

	/**
	 * Compose the plain-text body for a prayer-count notification email.
	 *
	 * The message intentionally uses plain text so it renders correctly in
	 * every email client without additional HTML processing. Calling code
	 * may replace this via the 'intercessor_pray_notification_body' filter.
	 *
	 * @since  1.0.0
	 * @param  Prayer_Request $request   The prayer request row.
	 * @param  Requester      $requester The requester row.
	 * @param  int            $total     Total number of times the request has been prayed for.
	 * @return string                    Plain-text email body.
	 */
	public function email_body(
		Prayer_Request $request,
		Requester $requester,
		int $total
	): string {
		$name = $requester->get_display_name();

		// translators: %s: requester first name or friendly greeting
		$greeting = sprintf(
			/* translators: %s: requester display name */
			__( 'Dear %s,', 'intercessor' ),
			$name
		);

		// translators: %s: prayer request subject
		$intro = sprintf(
			/* translators: %s: prayer request subject line */
			__( 'We have good news — your prayer request "%s" has been prayed for.', 'intercessor' ),
			$request->subject
		);

		// translators: %s: prayer request content excerpt
		$count_line = sprintf(
			/* translators: %d: number of times the request has been prayed for */
			_n(
				'It has been prayed for %d time.',
				'It has been prayed for %d times.',
				$total,
				'intercessor'
			),
			$total
		);

		$closing = __( 'Thank you for sharing your prayer request with us. We will continue to pray for you.', 'intercessor' );

		// translators: %s: site name, %s: login URL
		$sign_off = sprintf(
			/* translators: %s: site name */
			__( 'Blessings,\n%s', 'intercessor' ),
			get_bloginfo( 'name' )
		);

		return implode( "\n\n", array( $greeting, $intro, $count_line, $closing, $sign_off ) );
	}

	/**
	 * Build the wp_mail() headers array for outgoing notification emails.
	 *
	 * Applies the 'From' name and address from the plugin's email settings,
	 * falling back to WordPress site defaults when the settings are empty.
	 *
	 * @since  1.0.0
	 * @return string[] Array of raw header strings for wp_mail().
	 */
	public function email_headers(): array {
		$from_name    = Settings::get( 'email_from_name' )    ?: get_bloginfo( 'name' );
		$from_address = Settings::get( 'email_from_address' ) ?: get_option( 'admin_email' );

		return array(
			sprintf( 'From: %s <%s>', $from_name, sanitize_email( $from_address ) ),
			'Content-Type: text/plain; charset=UTF-8',
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return whether the prayer-count cron notification is enabled in settings.
	 *
	 * @since  1.0.0
	 * @return bool True when enabled.
	 */
	public static function is_enabled(): bool {
		return (bool) Settings::get( 'cron_notify_prayed', true );
	}

	/**
	 * Return the WP-Cron recurrence slug for the configured frequency.
	 *
	 * Maps the 'cron_frequency' setting value to a WordPress cron schedule
	 * identifier. Falls back to 'daily' for any unrecognised value.
	 *
	 * @since  1.0.0
	 * @return string One of: 'daily', 'weekly', 'monthly'.
	 */
	public static function recurrence(): string {
		$freq = (string) Settings::get( 'cron_frequency', 'daily' );
		return in_array( $freq, self::FREQUENCIES, true ) ? $freq : 'daily';
	}

	/**
	 * Calculate the Unix timestamp for the next scheduled run.
	 *
	 * Reads 'cron_send_hour' (0–23) and 'cron_send_minute' (0–59) from
	 * settings and returns the next future UTC timestamp that falls on that
	 * time-of-day. If today's scheduled time has already passed, the timestamp
	 * is rolled forward to the same time tomorrow.
	 *
	 * @since  1.0.0
	 * @return int Unix timestamp (UTC) for the first scheduled run.
	 */
	public static function next_run_timestamp(): int {
		$hour   = max( 0, min( 23, (int) Settings::get( 'cron_send_hour',   8 ) ) );
		$minute = max( 0, min( 59, (int) Settings::get( 'cron_send_minute', 0 ) ) );

		// Build a target timestamp for today at the configured UTC time.
		$today = strtotime( gmdate( 'Y-m-d' ) . " {$hour}:{$minute}:00 UTC" );

		// If that moment has already passed, schedule for the same time tomorrow.
		return ( $today > time() ) ? $today : $today + DAY_IN_SECONDS;
	}

	/**
	 * Retrieve the persisted notified-count map from the options table.
	 *
	 * Returns an associative array keyed by prayer_request_id (int cast)
	 * with the last-notified total count as the value.
	 *
	 * @since  1.0.0
	 * @return array<int, int> Map of { prayer_request_id => last_notified_count }.
	 */
	private static function get_notified_map(): array {
		$raw = get_option( self::NOTIFIED_OPTION, '{}' );
		$map = json_decode( (string) $raw, true );

		if ( ! is_array( $map ) ) {
			return array();
		}

		// Ensure keys and values are both ints.
		$clean = array();
		foreach ( $map as $id => $count ) {
			$clean[ (int) $id ] = (int) $count;
		}

		return $clean;
	}
}
