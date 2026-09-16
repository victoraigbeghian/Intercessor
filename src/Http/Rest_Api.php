<?php
/**
 * REST API endpoint registration.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Http;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings;
use Intercessor\Database\Query\Prayer_History_Query;
use Intercessor\Database\Query\Prayer_Note_Query;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Database\Row\Prayer_Note;
use Intercessor\Util\Submission_Pipeline;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and handles all Intercessor REST API endpoints.
 *
 * Base namespace: /wp-json/intercessor/v1/
 *
 * Public endpoints (no auth required):
 *   GET  /requests                    — list approved requests
 *   GET  /requests/{id}               — single request
 *   GET  /requests/{id}/history       — status timeline
 *
 * Authenticated (canSubmit):
 *   POST /requests                    — submit new prayer request
 *
 * Moderator only (canModerate checks edit_prayers capability):
 *   POST  /requests/{id}/status       — update request status
 *   GET   /requesters                 — list all requesters
 *   GET   /requests/{id}/notes        — list notes on a request
 *   POST  /requests/{id}/notes        — create a note
 *   DELETE /requests/{id}/notes/{nid} — delete a note
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Rest_Api {

	/**
	 * REST API namespace for all Intercessor routes.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private const NAMESPACE = 'intercessor/v1';

	/**
	 * Register the 'rest_api_init' hook.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_routes(): void {

		// ── GET  /requests ────────────────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_requests' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'status'   => array(
						'type'              => 'string',
						'default'           => 'approved',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// ── GET  /requests/{id} ───────────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// ── POST /requests ────────────────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_request' ),
				'permission_callback' => array( $this, 'can_submit' ),
				'args'                => $this->submission_args(),
			)
		);

		// ── POST /requests/{id}/status ────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>[\d]+)/status',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_status' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'required' => true,  'sanitize_callback' => 'absint' ),
					'status' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'enum'              => Prayer_Request_Query::VALID_STATUSES,
					),
					'note'   => array( 'type' => 'string',  'default'  => '',    'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);

		// ── GET /requesters ───────────────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requesters',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_requesters' ),
				'permission_callback' => array( $this, 'can_moderate' ),
			)
		);

		// ── GET /requests/{id}/history ────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>[\d]+)/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		// ── GET  /requests/{id}/notes ─────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>[\d]+)/notes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notes' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'id'         => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'private'    => array(
						'type'              => 'string',
						'default'           => 'all',
						'enum'              => array( 'all', 'private', 'shared' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// ── POST /requests/{id}/notes ─────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>[\d]+)/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_note' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'id'         => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'content'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static fn( $v ) => is_string( $v ) && trim( $v ) !== '',
					),
					'is_private' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// ── DELETE /requests/{id}/notes/{nid} ────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>[\d]+)/notes/(?P<nid>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_note' ),
				'permission_callback' => array( $this, 'can_moderate' ),
				'args'                => array(
					'id'  => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'nid' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks — requests
	// -------------------------------------------------------------------------

	/**
	 * Return a paginated list of prayer requests.
	 *
	 * Non-admin callers are always forced to status=approved to prevent
	 * information disclosure.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with per_page, page, status.
	 * @return WP_REST_Response
	 */
	public function get_requests( WP_REST_Request $request ): WP_REST_Response {
		$query   = new Prayer_Request_Query();
		$perPage = $request->get_param( 'per_page' );
		$page    = $request->get_param( 'page' );
		$status  = $request->get_param( 'status' );

		if ( ! current_user_can( 'edit_prayers' ) && $status !== 'approved' ) {
			$status = 'approved';
		}

		// Private requests are never exposed publicly via the REST API.
		if ( $status === 'private' && ! current_user_can( 'edit_prayers' ) ) {
			$status = 'approved';
		}

		$items = $query->get_items( array(
			'status'    => $status,
			'is_public' => 1,
			'number'    => $perPage,
			'offset'    => ( $page - 1 ) * $perPage,
			'orderby'   => 'date_created',
			'order'     => 'DESC',
		) );

		return new WP_REST_Response(
			array_map( array( $this, 'prepare_request_item' ), $items ),
			200
		);
	}

	/**
	 * Return a single prayer request by primary key.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with id.
	 * @return WP_REST_Response         200, 403, or 404.
	 */
	public function get_request( WP_REST_Request $request ): WP_REST_Response {
		$query = new Prayer_Request_Query();
		$item  = $query->get_item( $request->get_param( 'id' ) );

		// Check existence.
		if ( ! $item ) {
			return new WP_REST_Response( array( 'message' => __( 'Not found.', 'intercessor' ) ), 404 );
		}

		// Block access to non-public requests for unauthenticated / non-moderator callers.
		if ( ! $item->is_public() && ! current_user_can( 'edit_prayers' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Forbidden.', 'intercessor' ) ), 403 );
		}

		// Private requests are hidden from public REST consumers.
		if ( $item->is_private_status() && ! current_user_can( 'edit_prayers' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Forbidden.', 'intercessor' ) ), 403 );
		}

		return new WP_REST_Response( $this->prepare_request_item( $item ), 200 );
	}

	/**
	 * Create a new prayer request via the REST API.
	 *
	 * Delegates to Submission_Service so that the same rate-limiting,
	 * profanity filtering, and email notifications that the AJAX form
	 * handler applies are enforced here as well.
	 *
	 * reCAPTCHA is enforced here too when enabled for the form. Previously
	 * this endpoint had no bot-protection of its own, so anyone could bypass
	 * the AJAX form's reCAPTCHA check entirely by posting straight to this
	 * route — the shared pipeline's rate limit and profanity filter still
	 * ran, but the CAPTCHA-specific defense did not.
	 *
	 * @since  1.0.0
	 * @since  1.0.1 Delegates to Submission_Service (was duplicating logic).
	 * @since  1.0.2 Enforces reCAPTCHA when enabled, matching the AJAX form
	 *               handler. This endpoint previously bypassed it entirely.
	 * @param  WP_REST_Request $request REST request with email, first_name, last_name,
	 *                                  subject, content, is_anonymous, is_private,
	 *                                  g-recaptcha-response.
	 * @return WP_REST_Response         201 with new ID, or 4xx/500 on failure.
	 */
	public function create_request( WP_REST_Request $request ): WP_REST_Response {
		// reCAPTCHA — same check the AJAX form handler performs. Must run
		// before the submission pipeline so a bot cannot skip it by calling
		// this endpoint directly instead of submitting the block's form.
		if ( Recaptcha::is_enabled_for_form() ) {
			$token = (string) $request->get_param( 'g-recaptcha-response' );

			if ( ! Recaptcha::verify( $token, Recaptcha::get_remote_ip() ) ) {
				return new WP_REST_Response(
					array( 'message' => __( 'reCAPTCHA verification failed. Please try again.', 'intercessor' ) ),
					403
				);
			}
		}

		$allow_private = (bool) Settings::get( 'allow_private_requests', false );
		$is_private    = $allow_private && (bool) $request->get_param( 'is_private' );

		$result = Submission_Pipeline::run(
			(string) $request->get_param( 'email' ),
			(string) ( $request->get_param( 'first_name' ) ?? '' ),
			(string) ( $request->get_param( 'last_name' )  ?? '' ),
			(string) $request->get_param( 'subject' ),
			(string) $request->get_param( 'content' ),
			(bool)   $request->get_param( 'is_anonymous' ),
			$is_private
		);

		// The pipeline returns a WP_Error on any failure, with an appropriate message and status code.
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 500 );
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}

		return new WP_REST_Response( array( 'id' => $result ), 201 );
	}

	/**
	 * Update the status of a prayer request.
	 *
	 * Also writes a PrayerHistory entry via Prayer_Request_Query::update_status().
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with id, status, note.
	 * @return WP_REST_Response         200 or 500.
	 */
	public function update_status( WP_REST_Request $request ): WP_REST_Response {
		$query   = new Prayer_Request_Query();
		$updated = $query->update_status(
			$request->get_param( 'id' ),
			$request->get_param( 'status' ),
			$request->get_param( 'note' )
		);

		// update_status() returns false on failure, true on success.
		if ( ! $updated ) {
			return new WP_REST_Response( array( 'message' => __( 'Update failed.', 'intercessor' ) ), 500 );
		}

		return new WP_REST_Response( array( 'updated' => true ), 200 );
	}

	/**
	 * Return all requester records (admin only).
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request (no params).
	 * @return WP_REST_Response         200 with requester rows.
	 */
	public function get_requesters( WP_REST_Request $request ): WP_REST_Response {
		$query = new Requester_Query();
		$items = $query->get_items( array( 'orderby' => 'date_created', 'order' => 'DESC' ) );

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * Return the status-change history timeline for a prayer request.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with id.
	 * @return WP_REST_Response         200, 403, or 404.
	 */
	public function get_history( WP_REST_Request $request ): WP_REST_Response {
		$pqQuery = new Prayer_Request_Query();
		$parent  = $pqQuery->get_item( $request->get_param( 'id' ) );

		if ( ! $parent ) {
			return new WP_REST_Response( array( 'message' => __( 'Not found.', 'intercessor' ) ), 404 );
		}

		// Block access to private requests for unauthenticated / non-moderator callers.
		if ( ! $parent->is_public() && ! current_user_can( 'edit_prayers' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Forbidden.', 'intercessor' ) ), 403 );
		}

		$historyQuery = new Prayer_History_Query();
		$history      = $historyQuery->get_for_request( $request->get_param( 'id' ) );

		// Strip moderator-only fields (moderator ID, internal notes) from the
		// response for callers who are not moderators. This prevents leaking
		// admin user IDs and private notes through the public endpoint.
		$can_moderate = current_user_can( 'edit_prayers' );

		$sanitized = array_map(
			static function ( $row ) use ( $can_moderate ) {
				$data = (array) $row;
				if ( ! $can_moderate ) {
					unset( $data['moderator_id'], $data['note'] );
				}
				return $data;
			},
			$history
		);

		return new WP_REST_Response( $sanitized, 200 );
	}

	// -------------------------------------------------------------------------
	// Callbacks — notes
	// -------------------------------------------------------------------------

	/**
	 * Return all notes for a given prayer request.
	 *
	 * Supports an optional 'private' filter: 'all' (default), 'private', 'shared'.
	 * Only moderators can call this endpoint.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with id and optional private filter.
	 * @return WP_REST_Response         200 with note array, or 404 if request not found.
	 */
	public function get_notes( WP_REST_Request $request ): WP_REST_Response {
		$requestId = $request->get_param( 'id' );
		$filter    = $request->get_param( 'private' );

		// Verify the parent prayer request exists.
		$pqQuery = new Prayer_Request_Query();
		if ( ! $pqQuery->get_item( $requestId ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Prayer request not found.', 'intercessor' ) ), 404 );
		}

		$noteQuery = new Prayer_Note_Query();

		$notes = match ( $filter ) {
			'private' => $noteQuery->get_private_for_request( $requestId ),
			'shared'  => $noteQuery->get_items( array(
				'prayer_request_id' => $requestId,
				'is_private'        => 0,
				'orderby'           => 'date_created',
				'order'             => 'ASC',
				'number'            => 0,
			) ),
			default   => $noteQuery->get_for_request( $requestId ),
		};

		return new WP_REST_Response(
			$this->prepare_note_items( $notes ),
			200
		);
	}

	/**
	 * Create a new internal note on a prayer request.
	 *
	 * Only moderators can create notes. The author is always set to the
	 * currently authenticated WordPress user.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with id, content, is_private.
	 * @return WP_REST_Response         201 with new note data, 404, or 500.
	 */
	public function create_note( WP_REST_Request $request ): WP_REST_Response {
		$requestId = $request->get_param( 'id' );
		$content   = trim( (string) $request->get_param( 'content' ) );
		$private   = (bool) $request->get_param( 'is_private' );

		// Verify the parent prayer request exists.
		$pqQuery = new Prayer_Request_Query();
		if ( ! $pqQuery->get_item( $requestId ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Prayer request not found.', 'intercessor' ) ), 404 );
		}

		$noteQuery = new Prayer_Note_Query();
		$newId     = $noteQuery->add_note( $requestId, $content, $private );

		if ( ! $newId ) {
			return new WP_REST_Response( array( 'message' => __( 'Could not save note.', 'intercessor' ) ), 500 );
		}

		$note = $noteQuery->get_item( $newId );

		return new WP_REST_Response(
			$note ? $this->prepare_note_item( $note ) : array( 'id' => $newId ),
			201
		);
	}

	/**
	 * Delete a single note by its ID.
	 *
	 * Validates that the note belongs to the stated prayer request (IDOR guard)
	 * before deleting.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request REST request with id (request) and nid (note).
	 * @return WP_REST_Response         200, 403, or 404.
	 */
	public function delete_note( WP_REST_Request $request ): WP_REST_Response {
		$requestId = $request->get_param( 'id' );
		$noteId    = $request->get_param( 'nid' );

		$noteQuery = new Prayer_Note_Query();
		$note      = $noteQuery->get_item( $noteId );

		if ( ! $note ) {
			return new WP_REST_Response( array( 'message' => __( 'Note not found.', 'intercessor' ) ), 404 );
		}

		// IDOR guard: the note must belong to the stated prayer request.
		if ( $note->prayer_request_id !== $requestId ) {
			return new WP_REST_Response( array( 'message' => __( 'Note does not belong to this request.', 'intercessor' ) ), 403 );
		}

		$deleted = $noteQuery->delete_item( $noteId );

		if ( ! $deleted ) {
			return new WP_REST_Response( array( 'message' => __( 'Could not delete note.', 'intercessor' ) ), 500 );
		}

		return new WP_REST_Response( array( 'deleted' => true, 'id' => $noteId ), 200 );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current caller may submit a prayer request.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public function can_submit(): bool {
		if ( Settings::get( 'require_login', false ) ) {
			return is_user_logged_in();
		}

		return true;
	}

	/**
	 * Check whether the current caller may perform moderation actions.
	 *
	 * Used for status changes, note creation/deletion, and requester listing.
	 *
	 * @since  1.0.0
	 * @return bool
	 */
	public function can_moderate(): bool {
		return current_user_can( 'edit_prayers' );
	}

	// -------------------------------------------------------------------------
	// Response shape helpers
	// -------------------------------------------------------------------------

	/**
	 * Shape a prayer request row into a REST-safe array.
	 *
	 * Sensitive fields (requester_id, moderator_note) are included only for
	 * callers with the edit_prayers capability.
	 *
	 * @since  1.0.0
	 * @param  object               $item Prayer request row.
	 * @return array<string, mixed>
	 */
	private function prepare_request_item( object $item ): array {
		$isAdmin = current_user_can( 'edit_prayers' );

		return array(
			'id'             => (int) $item->id,
			'subject'        => $item->subject,
			'content'        => $item->content,
			'status'         => $item->status,
			'is_anonymous'   => (bool) $item->is_anonymous,
			'date_created'   => $item->date_created,
			'requester_id'   => $isAdmin ? (int) $item->requester_id : null,
			'moderator_note' => $isAdmin ? $item->moderator_note     : null,
		);
	}

	/**
	 * Shape a collection of Prayer_Note rows into REST-safe arrays.
	 *
	 * Pre-fetches all unique note authors in a single WP object-cache
	 * warm-up pass, avoiding an N+1 get_user_by() call per note.
	 *
	 * @since  1.0.1
	 * @param  Prayer_Note[] $notes Array of note row objects.
	 * @return array<int, array<string, mixed>>
	 */
	private function prepare_note_items( array $notes ): array {
		if ( empty( $notes ) ) {
			return array();
		}

		// Collect unique author IDs and resolve them in one pass.
		$author_ids = array_unique(
			array_map( static fn( Prayer_Note $n ) => (int) $n->author_user_id, $notes )
		);

		$author_names = array();
		foreach ( $author_ids as $uid ) {
			if ( $uid > 0 ) {
				$user                  = get_user_by( 'id', $uid );
				$author_names[ $uid ]  = $user ? $user->display_name : __( 'Unknown', 'intercessor' );
			}
		}

		return array_map(
			function ( Prayer_Note $note ) use ( $author_names ): array {
				$uid = (int) $note->author_user_id;
				return array(
					'id'                => $note->id,
					'prayer_request_id' => $note->prayer_request_id,
					'author_user_id'    => $uid,
					'author_name'       => $author_names[ $uid ] ?? __( 'Unknown', 'intercessor' ),
					'content'           => $note->content,
					'is_private'        => (bool) $note->is_private,
					'date_created'      => $note->date_created,
					'date_modified'     => $note->date_modified,
				);
			},
			$notes
		);
	}

	/**
	 * Shape a PrayerNote row into a REST-safe array.
	 *
	 * Resolves the author's display name from the WordPress users table.
	 * The raw author_user_id is included so the client can link to user profiles.
	 *
	 * @since  1.0.0
	 * @param  Prayer_Note           $note Note row object.
	 * @return array<string, mixed>
	 */
	private function prepare_note_item( Prayer_Note $note ): array {
		$author     = get_user_by( 'id', $note->author_user_id );
		$authorName = $author ? $author->display_name : __( 'Unknown', 'intercessor' );

		return array(
			'id'                => $note->id,
			'prayer_request_id' => $note->prayer_request_id,
			'author_user_id'    => $note->author_user_id,
			'author_name'       => $authorName,
			'content'           => $note->content,
			'is_private'        => (bool) $note->is_private,
			'date_created'      => $note->date_created,
			'date_modified'     => $note->date_modified,
		);
	}

	/**
	 * Return the REST argument schema for the prayer request creation endpoint.
	 *
	 * @since  1.0.0
	 * @return array<string, array<string, mixed>>
	 */
	private function submission_args(): array {
		return array(
			'first_name'   => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
			'last_name'    => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
			'email'        => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_email', 'validate_callback' => 'is_email' ),
			'subject'      => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
			'content'      => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ),
			'is_anonymous' => array( 'type' => 'boolean', 'default'  => false ),
			'is_private'   => array( 'type' => 'boolean', 'default'  => false ),
			'g-recaptcha-response' => array(
				'type'              => 'string',
				'required'          => false,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
