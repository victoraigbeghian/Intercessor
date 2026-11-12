<?php
/**
 * Shared prayer request submission pipeline.
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
use Intercessor\Reports\Prayer_Request_Stats;

/**
 * Runs the shared prayer request submission pipeline.
 *
 * Both the AJAX form handler (Public_Loader) and the REST endpoint
 * (Rest_Api::create_request) delegate to this class so that every
 * submission path enforces the same rules in the same order:
 *
 *   1. Rate limiting (per-email daily cap).
 *   2. Duplicate-subject check — blocks resubmission of the same subject
 *      when the "Prevent Duplicate Requests" setting is enabled.
 *   3. Profanity filter — flags matching requests to 'pending' with a
 *      moderator note; does NOT block the submission.
 *   4. Find or create a requester record by email.
 *   5. Insert the prayer request row.
 *   6. Email notifications (admin new-request, requester acknowledgement).
 *
 * Nonce/reCAPTCHA/login checks are transport-specific and remain in the
 * calling handler; they must be applied BEFORE calling run().
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Submission_Pipeline {

	/**
	 * Execute the submission pipeline.
	 *
	 * @since  1.0.0
	 * @since  1.1.3 Ignores the supplied email for logged-in submitters and
	 *               uses their account email instead.
	 *
	 * @param  string $email        Sanitized, validated email address.
	 * @param  string $first_name   Sanitized first name.
	 * @param  string $last_name    Sanitized last name (may be empty).
	 * @param  string $subject      Sanitized subject line.
	 * @param  string $content      Sanitized prayer request body.
	 * @param  bool   $is_anonymous Whether the requester wishes to remain anonymous.
	 * @param  bool   $is_private   Whether the request should be kept private (never shown on the Prayer Wall).
	 *
	 * @return int|\WP_Error  New prayer request ID on success; WP_Error on failure.
	 *                        The WP_Error data array always contains 'status' (HTTP
	 *                        status code) so callers can propagate an appropriate
	 *                        response without inspecting the error code.
	 */
	public static function run(
		string $email,
		string $first_name,
		string $last_name,
		string $subject,
		string $content,
		bool $is_anonymous = false,
		bool $is_private = false
	): int|\WP_Error {

		// ── 0. Logged-in submitters always use their own account email ─────
		// The form shows the email read-only for logged-in users, but that is
		// only a browser attribute; the posted value can be anything. Using
		// the account's address here covers the AJAX form and the REST route
		// alike. Moderators are exempt so they can still enter a request on
		// someone else's behalf through the REST API.
		if ( is_user_logged_in() && ! current_user_can( 'edit_prayers' ) ) {
			$account_email = (string) wp_get_current_user()->user_email;

			if ( '' !== $account_email ) {
				$email = $account_email;
			}
		}

		// ── 1. Rate limit ──────────────────────────────────────────────────
		// Per client first: it does not depend on anything the client sends.
		// Moderators entering requests on behalf of others are not limited.
		$limit_client = ! current_user_can( 'edit_prayers' );

		if ( $limit_client && ! Rate_Limiter::is_client_allowed() ) {
			return new \WP_Error(
				'intercessor_client_rate_limited',
				__( 'Too many prayer requests have been sent from your network. Please try again later.', 'intercessor' ),
				array( 'status' => 429 )
			);
		}

		if ( ! Rate_Limiter::is_allowed( $email ) ) {
			$limit = Rate_Limiter::get_limit();

			return new \WP_Error(
				'intercessor_rate_limited',
				sprintf(
					/* translators: %d: daily submission limit number */
					_n(
						'You may only submit %d prayer request per day. Please try again tomorrow.',
						'You may only submit %d prayer requests per day. Please try again tomorrow.',
						$limit,
						'intercessor'
					),
					$limit
				),
				array( 'status' => 429 )
			);
		}

		// ── 2. Duplicate-subject check ─────────────────────────────────────
		if ( (bool) Settings::get( 'prevent_duplicate_requests', true ) ) {
			$requester_query = new Requester_Query();
			if ( $requester_query->has_duplicate_subject( $email, $subject ) ) {
				return new \WP_Error(
					'intercessor_duplicate_request',
					__( 'You have already submitted a prayer request with this subject. Please use a different subject or update your existing request.', 'intercessor' ),
					array( 'status' => 409 )
				);
			}
		}

		// ── 3. Profanity filter ────────────────────────────────────────────
		$auto_approve   = (bool) Settings::get( 'auto_approve', false );
		$initial_status = $auto_approve ? 'approved' : 'pending';
		$moderator_note = '';

		if ( Profanity_Filter::is_enabled() ) {
			$matched = array_unique(
				array_merge(
					Profanity_Filter::get_matched_words( $subject ),
					Profanity_Filter::get_matched_words( $content )
				)
			);

			if ( ! empty( $matched ) ) {
				$initial_status = 'pending';
				$moderator_note = Profanity_Filter::build_moderator_note( $matched );
			}
		}

		// ── 4. Find or create requester ────────────────────────────────────
		$requester_query = new Requester_Query();
		$requester_id    = $requester_query->find_or_create( $email, $first_name, $last_name );

		if ( ! $requester_id ) {
			return new \WP_Error(
				'intercessor_requester_failed',
				__( 'Could not save requester information.', 'intercessor' ),
				array( 'status' => 500 )
			);
		}

		// ── 5. Insert prayer request ───────────────────────────────────────
		$prayer_query = new Prayer_Request_Query();
		$new_id       = $prayer_query->add_item(
			array(
				'requester_id'   => $requester_id,
				'subject'        => $subject,
				'content'        => $content,
				'status'         => $is_private ? 'private' : $initial_status,
				'is_anonymous'   => $is_anonymous ? 1 : 0,
				'is_public'      => $is_private ? 0 : 1,
				'moderator_note' => $moderator_note,
			)
		);

		if ( ! $new_id ) {
			return new \WP_Error(
				'intercessor_insert_failed',
				__( 'Could not save your prayer request.', 'intercessor' ),
				array( 'status' => 500 )
			);
		}

		if ( $limit_client ) {
			Rate_Limiter::record_client_submission();
		}

		// ── 6. Notifications ───────────────────────────────────────────────
		Notifier::notify_admin_new_request( $new_id );
		Notifier::notify_requester_received( $new_id );

		return $new_id;
	}
}
