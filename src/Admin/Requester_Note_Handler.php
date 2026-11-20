<?php
/**
 * Requester note admin POST handler.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Requester_Note_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Http\Request;

/**
 * Handles admin_post actions for creating and deleting requester notes.
 *
 * All input is read through a Request instance — no direct superglobal access.
 * After each action the handler redirects back to the requester detail notes
 * tab, carrying a flash-notice query argument.
 *
 * Registered actions:
 *   admin_post_intercessor_add_requester_note
 *   admin_post_intercessor_delete_requester_note
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Requester_Note_Handler {

	// ── Shared URL helpers ───────────────────────────────────────────────────

	/**
	 * Build the redirect base URL for the requester notes tab.
	 *
	 * @since  1.0.0
	 * @param  int $requester_id Requester primary key.
	 * @return string            Absolute admin URL for the notes tab.
	 */
	private static function notes_tab_url( int $requester_id ): string {
		return add_query_arg(
			array(
				'page'         => 'intercessor-requesters',
				'requester_id' => $requester_id,
				'tab'          => 'notes',
			),
			admin_url( 'admin.php' )
		);
	}

	// ── Action handlers ──────────────────────────────────────────────────────

	/**
	 * Process the add-requester-note form submission.
	 *
	 * Validates capability, nonce, requester existence, and non-empty content
	 * before inserting the note row. Redirects back to the notes tab with
	 * a 'rn_added=1' flag on success or 'rn_error=1' on failure.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function handle_add(): void {
		if ( ! current_user_can( 'view_prayer_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to add notes.', 'intercessor' ), 403 );
		}

		$req          = Request::capture();
		$req->require_nonce( 'intercessor_add_requester_note' );

		$requester_id = $req->get_int( 'requester_id' );
		$content      = $req->get_textarea( 'note_content' );

		// A checkbox is only submitted when ticked, so presence is the value.
		// Reading it with a default of true made every note private, because
		// an unticked box is indistinguishable from a missing key.
		$private      = $req->has( 'note_is_private' );
		$redirect     = self::notes_tab_url( $requester_id );

		if ( $requester_id === 0 || $content === '' ) {
			wp_safe_redirect( add_query_arg( 'rn_error', '1', $redirect ) );
			exit;
		}

		// Verify the requester actually exists (IDOR guard).
		$rq_query = new Requester_Query();
		if ( ! $rq_query->get_item( $requester_id ) ) {
			wp_safe_redirect( add_query_arg( 'rn_error', '1', $redirect ) );
			exit;
		}

		$note_query = new Requester_Note_Query();
		$new_id     = $note_query->add_note( $requester_id, $content, $private );

		wp_safe_redirect( add_query_arg( $new_id ? 'rn_added' : 'rn_error', '1', $redirect ) );
		exit;
	}

	/**
	 * Process the delete-requester-note form submission.
	 *
	 * Validates capability, nonce, note existence, and that the note belongs
	 * to the stated requester before deleting. Redirects back to the notes
	 * tab with a 'rn_deleted=1' flag on success or 'rn_error=1' on failure.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function handle_delete(): void {
		if ( ! current_user_can( 'view_prayer_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete notes.', 'intercessor' ), 403 );
		}

		$req          = Request::capture();
		$req->require_nonce( 'intercessor_delete_requester_note' );

		$note_id      = $req->get_int( 'note_id' );
		$requester_id = $req->get_int( 'requester_id' );
		$redirect     = self::notes_tab_url( $requester_id );

		if ( $note_id === 0 || $requester_id === 0 ) {
			wp_safe_redirect( add_query_arg( 'rn_error', '1', $redirect ) );
			exit;
		}

		$note_query = new Requester_Note_Query();
		$note       = $note_query->get_item( $note_id );

		// IDOR guard: the note must belong to the stated requester.
		if ( ! $note || (int) $note->requester_id !== $requester_id ) {
			wp_safe_redirect( add_query_arg( 'rn_error', '1', $redirect ) );
			exit;
		}

		$deleted = $note_query->delete_item( $note_id );

		wp_safe_redirect( add_query_arg( $deleted ? 'rn_deleted' : 'rn_error', '1', $redirect ) );
		exit;
	}
}
