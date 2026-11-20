<?php
/**
 * Prayer note admin POST handler.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Prayer_Note_Query;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Http\Request;

/**
 * Handles admin_post actions for creating and deleting prayer notes.
 *
 * All input is read through a Request instance captured at the start of
 * each handler — no direct superglobal access anywhere in this class.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Note_Handler {

	/**
	 * Process the add-note form submission.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function handle_add(): void {
		if ( ! current_user_can( 'edit_prayers' ) ) {
			wp_die( esc_html__( 'You do not have permission to add notes.', 'intercessor' ), 403 );
		}

		$req = Request::capture();

		$req->require_nonce( 'intercessor_add_note' );

		$requestId    = $req->get_int( 'request_id' );
		$content      = $req->get_textarea( 'note_content' );

		// A checkbox is only submitted when ticked, so presence is the value.
		// Reading it with a default of true made every note private, because
		// an unticked box is indistinguishable from a missing key.
		$private      = $req->has( 'note_is_private' );
		$redirectBase = admin_url( 'admin.php?page=intercessor-requests&view=' . $requestId );

		if ( $requestId === 0 || $content === '' ) {
			wp_safe_redirect( add_query_arg( 'note_error', '1', $redirectBase ) );
			exit;
		}

		$prayerQuery = new Prayer_Request_Query();
		if ( ! $prayerQuery->get_item( $requestId ) ) {
			wp_safe_redirect( add_query_arg( 'note_error', '1', $redirectBase ) );
			exit;
		}

		$noteQuery = new Prayer_Note_Query();
		$newId     = $noteQuery->add_note( $requestId, $content, $private );

		wp_safe_redirect( add_query_arg(
			$newId ? 'note_added' : 'note_error',
			'1',
			$redirectBase
		) );
		exit;
	}

	/**
	 * Process the delete-note form submission.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'edit_prayers' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete notes.', 'intercessor' ), 403 );
		}

		$req = Request::capture();

		$req->require_nonce( 'intercessor_delete_note' );

		$noteId       = $req->get_int( 'note_id' );
		$requestId    = $req->get_int( 'request_id' );
		$redirectBase = admin_url( 'admin.php?page=intercessor-requests&view=' . $requestId );

		if ( $noteId === 0 || $requestId === 0 ) {
			wp_safe_redirect( add_query_arg( 'note_error', '1', $redirectBase ) );
			exit;
		}

		$noteQuery = new Prayer_Note_Query();
		$note      = $noteQuery->get_item( $noteId );

		// IDOR guard: the note must belong to the stated prayer request.
		if ( ! $note || (int) $note->prayer_request_id !== $requestId ) {
			wp_safe_redirect( add_query_arg( 'note_error', '1', $redirectBase ) );
			exit;
		}

		$deleted = $noteQuery->delete_item( $noteId );

		wp_safe_redirect( add_query_arg(
			$deleted ? 'note_deleted' : 'note_error',
			'1',
			$redirectBase
		) );
		exit;
	}
}
