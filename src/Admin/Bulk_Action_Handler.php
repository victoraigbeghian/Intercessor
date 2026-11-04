<?php
/**
 * Bulk action handler for the prayer requests list table.
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
 * Processes bulk approve, reject, mark-private, and delete actions from the
 * prayer requests list table.
 *
 * All input is read through a Request instance captured at the start of
 * handle() — no direct superglobal access anywhere in this class.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Bulk_Action_Handler {

	/**
	 * Allowed bulk action values.
	 *
	 * @since 1.0.0
	 * @var   string[]
	 */
	private const VALID_ACTIONS = array( 'bulk_approve', 'bulk_reject', 'bulk_delete', 'bulk_mark_private' );

	/**
	 * Map of bulk action keys to the prayer request status they set.
	 *
	 * @since 1.0.0
	 * @var   array<string, string>
	 */
	private const ACTION_STATUS_MAP = array(
		'bulk_approve'      => 'approved',
		'bulk_reject'       => 'rejected',
		'bulk_mark_private' => 'private',
	);

	/**
	 * Process the bulk action form submission and redirect back to the list.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'edit_prayers' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'intercessor' ), 403 );
		}

		$req = Request::capture();

		$req->require_nonce( 'intercessor_bulk_action' );

		$action  = $req->get_key( 'bulk_action' );
		$ids     = $req->get_int_array( 'bulk_ids' );
		$listUrl = admin_url( 'admin.php?page=intercessor-requests' );

		if ( ! in_array( $action, self::VALID_ACTIONS, true ) ) {
			wp_safe_redirect( add_query_arg( 'bulk_error', 'invalid_action', $listUrl ) );
			exit;
		}

		if ( empty( $ids ) ) {
			wp_safe_redirect( add_query_arg( 'bulk_error', 'no_selection', $listUrl ) );
			exit;
		}

		$query = new Prayer_Request_Query();

		if ( $action === 'bulk_delete' ) {
			$count = $query->bulk_delete( $ids );
			wp_safe_redirect( add_query_arg( array( 'bulk_deleted' => $count ), $listUrl ) );
			exit;
		}

		$newStatus   = self::ACTION_STATUS_MAP[ $action ];
		$updated_ids = $query->bulk_update_status( $ids, $newStatus );
		$count       = count( $updated_ids );

		foreach ( $updated_ids as $id ) {
			Notifier::notify_status_change( $id, $newStatus );
		}

		wp_safe_redirect( add_query_arg(
			array( 'bulk_updated' => $count, 'bulk_status' => $newStatus ),
			$listUrl
		) );
		exit;
	}
}
