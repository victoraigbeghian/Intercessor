<?php
/**
 * Prayer request moderation handler.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Http\Request;
use Intercessor\Util\Notifier;

/**
 * Handles the admin_post action that moderates prayer requests.
 *
 * All input is read through a Request instance captured at the start of
 * handle() — no direct superglobal access anywhere in this class.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Moderation_Handler {

	/**
	 * Allowed status values accepted through the moderation form.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	private const VALID_STATUSES = array( 'approved', 'rejected', 'pending', 'archived', 'private' );

	/**
	 * Process the moderation form submission and redirect.
	 *
	 * @since  1.0.0
	 * @since  1.1.3 Sends the status-change notification only when the
	 *               status actually changed.
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'edit_prayers' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'intercessor' ), 403 );
		}

		$req = Request::capture();

		$req->require_nonce( 'intercessor_moderate' );

		$id        = $req->get_int( 'request_id' );
		$newStatus = $req->get_key( 'new_status' );
		$note      = $req->get_textarea( 'moderator_note' );

		$listUrl = admin_url( 'admin.php?page=intercessor-requests' );

		if ( $id === 0 || ! in_array( $newStatus, self::VALID_STATUSES, true ) ) {
			wp_safe_redirect( add_query_arg( 'error', '1', $listUrl ) );
			exit;
		}

		$query = new Prayer_Request_Query();

		// Read the current status first so the requester is only emailed
		// when the status really changes. Re-saving the same status (for
		// example to record a moderator note) must not send a notification.
		$current        = $query->get_item( $id );
		$status_changed = $current && $current->status !== $newStatus;

		$updated = $query->update_status( $id, $newStatus, $note );

		if ( $updated ) {
			if ( $status_changed ) {
				Notifier::notify_status_change( $id, $newStatus );
			}
			wp_safe_redirect( add_query_arg( 'updated', '1', $listUrl ) );
		} else {
			wp_safe_redirect( add_query_arg( 'error', '1', $listUrl ) );
		}

		exit;
	}
}
