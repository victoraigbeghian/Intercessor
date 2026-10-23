<?php
/**
 * Front-end public loader.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Public;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings;
use Intercessor\Database\Query\Prayed_Count_Query;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Http\Request;
use Intercessor\Util\Submission_Pipeline;
use Intercessor\Util\Notifier;
use Intercessor\Util\Profanity_Filter;
use Intercessor\Util\Rate_Limiter;
use Intercessor\Util\Recaptcha;
use Intercessor\Util\Registration_Handler;

/**
 * Handles all front-end (public) concerns for the Intercessor plugin.
 *
 * All superglobal access is centralised through a Request instance created
 * via Request::capture() at the top of each AJAX handler so every input is
 * unslashed and typed before use.
 *
 * Submission validation order:
 *   1. Nonce verification.
 *   2. Login gate (if require_login is enabled).
 *   3. reCAPTCHA token verification (if enabled for the form).
 *   4. Field presence / format validation.
 *   5. Daily rate limit check per email address (HTTP 429 on breach).
 *   6. Profanity filter — flags matching requests to 'pending' with a
 *      moderator note; does NOT block the submission.
 *   7. DB insert + email notifications.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Public_Loader {

	/**
	 * Register all front-end WordPress hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_intercessor_submit_request',        array( $this, 'handle_form_submission' ) );
		add_action( 'wp_ajax_nopriv_intercessor_submit_request', array( $this, 'handle_form_submission' ) );

		add_action( 'wp_ajax_intercessor_record_prayer',        array( $this, 'handle_record_prayer' ) );
		add_action( 'wp_ajax_nopriv_intercessor_record_prayer', array( $this, 'handle_record_prayer' ) );

		add_action( 'wp_ajax_intercessor_update_own_request', array( $this, 'handle_update_own_request' ) );
		add_action( 'wp_ajax_intercessor_delete_own_request', array( $this, 'handle_delete_own_request' ) );
	}

	/**
	 * Enqueue the front-end stylesheet and register scripts.
	 *
	 * Assets are only enqueued when at least one Intercessor block is
	 * present on the current page, avoiding unnecessary HTTP requests on
	 * unrelated pages.
	 *
	 * @since  1.0.0
	 * @since  1.0.1 Conditional enqueue — skips pages with no Intercessor blocks.
	 * @return void
	 */
	public function enqueue_assets(): void {
		// Only load assets on pages that actually render an Intercessor block.
		if (
			! has_block( 'intercessor/prayer-form' ) &&
			! has_block( 'intercessor/prayer-wall' ) &&
			! has_block( 'intercessor/prayer-history' )
		) {
			// Still register scripts/styles so block render callbacks can
			// enqueue them on-demand (e.g. via wp_enqueue_script inside render()).
			$this->register_assets();
			return;
		}

		$this->register_assets();
		$this->enqueue_registered_assets();
	}

	/**
	 * Register (but do not enqueue) all front-end scripts and styles.
	 *
	 * @since  1.0.1
	 * @return void
	 */
	private function register_assets(): void {
		wp_register_style(
			'intercessor-iconfont',
			INTERCESSOR_URL . 'assets/css/iconfont.css',
			array(),
			INTERCESSOR_VERSION
		);

		wp_register_style(
			'intercessor-public',
			INTERCESSOR_URL . 'assets/css/public.css',
			array( 'intercessor-iconfont' ),
			INTERCESSOR_VERSION
		);

		wp_register_script(
			'intercessor-prayer-form',
			INTERCESSOR_URL . 'assets/js/public/prayer-form.js',
			array( 'intercessor-public' ),
			INTERCESSOR_VERSION,
			true
		);

		wp_register_script(
			'intercessor-prayer-wall',
			INTERCESSOR_URL . 'assets/js/public/prayer-wall.js',
			array( 'intercessor-public' ),
			INTERCESSOR_VERSION,
			true
		);

		// Data-carrier handle for wp_add_inline_script() calls from blocks.
		if ( ! wp_script_is( 'intercessor-public', 'registered' ) ) {
			wp_register_script(
				'intercessor-public',
				'',
				array(),
				INTERCESSOR_VERSION,
				true
			);
		}
	}

	/**
	 * Enqueue all registered front-end assets.
	 *
	 * @since  1.0.1
	 * @return void
	 */
	private function enqueue_registered_assets(): void {
		wp_enqueue_style( 'intercessor-iconfont' );
		wp_enqueue_style( 'intercessor-public' );
		wp_enqueue_script( 'intercessor-public' );
	}

	// -------------------------------------------------------------------------
	// AJAX: prayer form submission
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler for prayer form block submissions.
	 *
	 * Handles nonce, login gate, and reCAPTCHA — concerns that are
	 * specific to the web form. The shared submission pipeline
	 * (rate limit, duplicate-subject check, profanity filter, DB insert,
	 * notifications) is delegated to Submission_Pipeline::run().
	 *
	 * @since  1.0.0
	 * @since  1.0.1 Delegates persistence to Submission_Pipeline::run().
	 * @return void
	 */
	public function handle_form_submission(): void {
		$req = Request::capture();

		// 1. Nonce.
		if ( ! $req->verify_nonce( 'intercessor_submit', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'intercessor' ) ), 403 );
		}

		// 2. Login gate.
		if ( Settings::get( 'require_login', false ) && ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a prayer request.', 'intercessor' ) ), 401 );
		}

		// 3. reCAPTCHA (form-specific; REST endpoint has its own auth model).
		if ( Recaptcha::is_enabled_for_form() ) {
			$token = $req->get_string( 'g-recaptcha-response' );
			if ( ! Recaptcha::verify( $token, $req->get_remote_addr() ) ) {
				wp_send_json_error( array( 'message' => __( 'reCAPTCHA verification failed. Please try again.', 'intercessor' ) ), 403 );
			}
		}

		// 4. Read and validate fields.
		$first_name = $req->get_string( 'first_name' );
		$last_name  = $req->get_string( 'last_name' );
		$email      = $req->get_email( 'requester_email' );
		$subject    = $req->get_string( 'subject' );
		$content    = $req->get_textarea( 'content' );
		$anonymous  = (bool) $req->input( 'is_anonymous', false );
		$is_private = (bool) Settings::get( 'allow_private_requests', false )
			&& (bool) $req->input( 'is_private', false );

		$errors = array();
		if ( $first_name === '' )    { $errors[] = __( 'First name is required.', 'intercessor' ); }
		if ( ! is_email( $email ) )  { $errors[] = __( 'A valid email address is required.', 'intercessor' ); }
		if ( $subject === '' )       { $errors[] = __( 'Subject is required.', 'intercessor' ); }
		if ( $content === '' )       { $errors[] = __( 'Prayer request content is required.', 'intercessor' ); }

		if ( ! empty( $errors ) ) {
			wp_send_json_error( array( 'message' => implode( ' ', $errors ) ), 422 );
		}

		// 5–7. Rate limit, profanity filter, DB insert, notifications.
		$result = Submission_Pipeline::run( $email, $first_name, $last_name, $subject, $content, $anonymous, $is_private );

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 500 );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		$new_id = $result;

		// 8. Optional WP account registration (form-specific).
		$reg_errors = Registration_Handler::maybe_create_account(
			$email,
			$first_name,
			$last_name,
			$_POST // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at step 1.
		);

		$success_message = __( 'Thank you. Your prayer request has been received.', 'intercessor' );

		if ( empty( $reg_errors ) && ! empty( $_POST['create_account'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$success_message .= ' ' . __( 'A confirmation email has been sent — please check your inbox to activate your account.', 'intercessor' );
		} elseif ( ! empty( $reg_errors ) ) {
			$success_message .= ' ' . implode( ' ', $reg_errors );
		}

		wp_send_json_success( array(
			'message' => $success_message,
			'id'      => $new_id,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: "I prayed for this" interaction
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler that records a "prayed for" interaction on a prayer request.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function handle_record_prayer(): void {
		$req = Request::capture();

		if ( ! $req->verify_nonce( 'intercessor_record_prayer', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'intercessor' ) ), 403 );
		}

		$requestId = $req->get_int( 'request_id' );

		if ( $requestId === 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'intercessor' ) ), 400 );
		}

		$prayerQuery = new Prayer_Request_Query();
		$request     = $prayerQuery->get_item( $requestId );

		if ( ! $request || ! $request->is_public() || ! $request->is_approved() ) {
			wp_send_json_error( array( 'message' => __( 'Prayer request not found.', 'intercessor' ) ), 404 );
		}

		$userId       = get_current_user_id();
		$anonymousKey = '';

		if ( $userId === 0 ) {
			$anonymousKey = wp_hash( $req->get_remote_addr() . '|' . $req->get_user_agent() );
		}

		$countQuery = new Prayed_Count_Query();
		$recorded   = $countQuery->record_prayer( $requestId, $userId, $anonymousKey );

		if ( ! $recorded ) {
			wp_send_json_error( array( 'message' => __( 'Could not record your prayer.', 'intercessor' ) ), 500 );
		}

		$total = $countQuery->get_total_for_request( $requestId );

		wp_send_json_success( array(
			'message' => __( 'Your prayer has been recorded. Thank you!', 'intercessor' ),
			'total'   => $total,
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX: user editing their own prayer request
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: update the subject/content of the current user's own request.
	 *
	 * Resets status to 'pending' so the updated request goes through moderation.
	 * Requires login; ownership is verified against the wp_user_id on the
	 * requester record.
	 *
	 * @since 1.1.0
	 */
	public function handle_update_own_request(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'intercessor' ) ), 401 );
		}

		$req = Request::capture();

		if ( ! $req->verify_nonce( 'intercessor_history', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'intercessor' ) ), 403 );
		}

		$request_id = $req->get_int( 'request_id' );
		if ( $request_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'intercessor' ) ), 400 );
		}

		$prayer_query = new Prayer_Request_Query();
		$prayer       = $prayer_query->get_item( $request_id );

		if ( ! $prayer ) {
			wp_send_json_error( array( 'message' => __( 'Prayer request not found.', 'intercessor' ) ), 404 );
		}

		// Ownership check: the logged-in user must own the requester record.
		$requester_query = new Requester_Query();
		$requester       = $requester_query->find_by_wp_user( get_current_user_id() );

		if ( ! $requester || $requester->id !== $prayer->requester_id ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this request.', 'intercessor' ) ), 403 );
		}

		$subject = $req->get_string( 'subject' );
		$content = $req->get_textarea( 'content' );

		if ( '' === $subject || '' === $content ) {
			wp_send_json_error( array( 'message' => __( 'Subject and prayer request are required.', 'intercessor' ) ), 400 );
		}

		$updated = $prayer_query->update_item( $request_id, array(
			'subject' => $subject,
			'content' => $content,
			'status'  => 'pending', // back to pending so admin reviews the changes.
		) );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the prayer request.', 'intercessor' ) ), 500 );
		}

		wp_send_json_success( array(
			'message' => __( 'Saved — your request will be reviewed shortly.', 'intercessor' ),
		) );
	}

	/**
	 * AJAX handler: permanently delete the current user's own prayer request.
	 *
	 * Cascades to prayer_history, prayer_notes, and prayed_counts child records
	 * via Prayer_Request_Query::bulk_delete(). Ownership is verified against the
	 * wp_user_id on the requester record before any deletion takes place.
	 *
	 * @since 1.1.0
	 */
	public function handle_delete_own_request(): void {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'intercessor' ) ), 401 );
		}

		$req = Request::capture();

		if ( ! $req->verify_nonce( 'intercessor_history', 'nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'intercessor' ) ), 403 );
		}

		$request_id = $req->get_int( 'request_id' );
		if ( $request_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'intercessor' ) ), 400 );
		}

		$prayer_query = new Prayer_Request_Query();
		$prayer       = $prayer_query->get_item( $request_id );

		if ( ! $prayer ) {
			wp_send_json_error( array( 'message' => __( 'Prayer request not found.', 'intercessor' ) ), 404 );
		}

		// Ownership check.
		$requester_query = new Requester_Query();
		$requester       = $requester_query->find_by_wp_user( get_current_user_id() );

		if ( ! $requester || $requester->id !== $prayer->requester_id ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to delete this request.', 'intercessor' ) ), 403 );
		}

		$deleted = $prayer_query->bulk_delete( array( $request_id ) );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete the prayer request.', 'intercessor' ) ), 500 );
		}

		wp_send_json_success( array(
			'message' => __( 'Prayer request deleted.', 'intercessor' ),
		) );
	}
}
