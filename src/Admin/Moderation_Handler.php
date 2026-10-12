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

		$query   = new Prayer_Request_Query();
		$updated = $query->update_status( $id, $newStatus, $note );

		if ( $updated ) {
			Notifier::notify_status_change( $id, $newStatus );
			wp_safe_redirect( add_query_arg( 'updated', '1', $listUrl ) );
		} else {
			wp_safe_redirect( add_query_arg( 'error', '1', $listUrl ) );
		}

		exit;
	}
}
