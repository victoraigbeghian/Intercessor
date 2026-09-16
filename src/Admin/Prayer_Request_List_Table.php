<?php
/**
 * Prayer requests WP_List_Table implementation.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Http\Request;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the prayer requests admin list table with bulk actions.
 *
 * Bulk actions (approve, reject, delete) are processed by BulkActionHandler
 * via an admin_post_{action} hook. The list table form POSTs to admin-post.php;
 * the requests.php template wraps it with the correct action and nonce.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Prayer_Request_List_Table extends WP_List_Table {

	/**
	 * Query instance used to fetch and count prayer request rows.
	 *
	 * @since 1.0.0
	 * @var   Prayer_Request_Query
	 */
	private Prayer_Request_Query $query;

	/**
	 * Initialise the list table and instantiate the query class.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'prayer_request',
			'plural'   => 'prayer_requests',
			'ajax'     => false,
		) );

		$this->query = new Prayer_Request_Query();
	}

	/**
	 * Display a message when no prayer requests match the current filter.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No prayer requests found.', 'intercessor' );
	}

	/**
	 * Return the column definitions for the list table.
	 *
	 * @since  1.0.0
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'subject'      => __( 'Subject', 'intercessor' ),
			'status'       => __( 'Status', 'intercessor' ),
			'requester_id' => __( 'Requester', 'intercessor' ),
			'date_created' => __( 'Submitted', 'intercessor' ),
			'actions'      => __( 'Actions', 'intercessor' ),
		);
	}

	/**
	 * Return the sortable column definitions.
	 *
	 * @since  1.0.0
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'subject'      => array( 'subject', false ),
			'status'       => array( 'status', false ),
			'date_created' => array( 'date_created', true ),
		);
	}

	/**
	 * Return the available bulk actions.
	 *
	 * @since  1.0.0
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'bulk_approve'      => __( 'Approve',       'intercessor' ),
			'bulk_reject'       => __( 'Reject',        'intercessor' ),
			'bulk_mark_private' => __( 'Mark Private',  'intercessor' ),
			'bulk_delete'       => __( 'Delete',        'intercessor' ),
		);
	}

	/**
	 * Fetch rows and configure pagination for the current page view.
	 *
	 * Also reads the 'status_filter' GET param to scope results to a single
	 * status value, supporting the status-tab filter links above the table.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function prepare_items(): void {
		$perPage     = 20;
		$currentPage = $this->get_pagenum();

		$req          = Request::capture();
		$statusFilter = $req->get_key( 'status_filter' );

		$args = array(
			'number'  => $perPage,
			'offset'  => ( $currentPage - 1 ) * $perPage,
			'orderby' => $req->get_key( 'orderby' ) ?: 'date_created',
			'order'   => strtoupper( $req->get_key( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC',
		);

		if ( $statusFilter !== '' ) {
			$args['status'] = $statusFilter;
		}

		$this->items = $this->query->get_items( $args );

		$countArgs = $statusFilter !== '' ? array( 'status' => $statusFilter ) : array();
		$total     = $this->query->count_items( $countArgs );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $perPage,
			'total_pages' => ceil( $total / $perPage ),
		) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Return status-filter navigation links to display above the table.
	 *
	 * Each link shows the count for that status in parentheses and highlights
	 * the currently active filter.
	 *
	 * @since  1.0.0
	 * @return array<string, string> Map of status slug to HTML anchor string.
	 */
	protected function get_views(): array {
		$current = Request::capture()->get_key( 'status_filter' );

		$statuses = array(
			''         => __( 'All',      'intercessor' ),
			'pending'  => __( 'Pending',  'intercessor' ),
			'approved' => __( 'Approved', 'intercessor' ),
			'rejected' => __( 'Rejected', 'intercessor' ),
			'archived' => __( 'Archived', 'intercessor' ),
			'private'  => __( 'Private',  'intercessor' ),
		);

		$views    = array();
		$base     = admin_url( 'admin.php?page=intercessor-requests' );

		foreach ( $statuses as $slug => $label ) {
			$count    = $slug === ''
				? $this->query->count_items( array() )
				: $this->query->count_items( array( 'status' => $slug ) );

			$url      = $slug === '' ? $base : add_query_arg( 'status_filter', $slug, $base );
			$active   = $current === $slug ? ' class="current"' : '';

			$views[ $slug ] = sprintf(
				/* translators: %s: status label, %d: total number of prayer requests with that status */
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$active,
				esc_html( $label ),
				(int) $count
			);
		}

		return $views;
	}

	// -------------------------------------------------------------------------
	// Column renderers
	// -------------------------------------------------------------------------

	/**
	 * Fallback renderer for columns without a dedicated method.
	 *
	 * @since  1.0.0
	 * @param  object $item
	 * @param  string $column_name
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Render the bulk-action checkbox cell.
	 *
	 * @since  1.0.0
	 * @param  object $item
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="bulk_ids[]" value="%d" />',
			absint( $item->id )
		);
	}

	/**
	 * Render the subject cell as a link to the single-request detail view.
	 *
	 * When the profanity filter has flagged the request the subject cell
	 * shows a small warning icon so moderators can spot flagged items
	 * without opening each one.
	 *
	 * @since  1.0.0
	 * @param  object $item
	 * @return string
	 */
	protected function column_subject( $item ): string {
		$subject = esc_html( $item->subject ?: __( '(no subject)', 'intercessor' ) );
		$editUrl = add_query_arg(
			array( 'page' => 'intercessor-requests', 'view' => absint( $item->id ) ),
			admin_url( 'admin.php' )
		);

		// Show a flag icon when the moderator_note was set by the profanity filter.
		$flag = '';
		if ( ! empty( $item->moderator_note ) &&
			str_contains( $item->moderator_note, '[Profanity filter]' ) ) {
			$flag = ' <span class="intercessor-flag ipr-icon ipr-icon-warning1" title="' .
				esc_attr__( 'Flagged by profanity filter', 'intercessor' ) .
				'" aria-hidden="true"></span>';
		}

		return sprintf(
			/* translators: %s: subject of the prayer request */
			'<strong><a href="%s">%s</a>%s</strong>',
			esc_url( $editUrl ),
			$subject,
			$flag
		);
	}

	/**
	 * Render the Requester column.
	 *
	 * Fetches the requester row and displays their display name (first + last
	 * name, or legacy name field) as a link to the requester detail page.
	 * Falls back gracefully when the requester row no longer exists.
	 *
	 * @since  1.0.2
	 * @param  object $item The current row object.
	 * @return string       HTML cell content.
	 */
	protected function column_requester_id( $item ): string {
		$requester_id = (int) $item->requester_id;

		if ( $requester_id <= 0 ) {
			return '<span style="color:#a0a0a0;">' . esc_html__( '(none)', 'intercessor' ) . '</span>';
		}

		$query     = new Requester_Query();
		$requester = $query->get_item( $requester_id );

		if ( ! $requester ) {
			return '<span style="color:#a0a0a0;">'
				/* translators: %d: requester ID number */
				. sprintf( esc_html__( '#%d (deleted)', 'intercessor' ), $requester_id )
				. '</span>';
		}

		$detail_url = add_query_arg(
			array(
				'page'         => 'intercessor-requesters',
				'requester_id' => $requester_id,
				'tab'          => 'overview',
			),
			admin_url( 'admin.php' )
		);

		return '<a href="' . esc_url( $detail_url ) . '">'
			. esc_html( $requester->get_display_name() )
			. '</a>';
	}

	/**
	 * Render a coloured status pill.
	 *
	 * @since  1.0.0
	 * @param  object $item
	 * @return string
	 */
	protected function column_status( $item ): string {
		$labels = array(
			'pending'  => '<span class="intercessor-status pending">'  . esc_html__( 'Pending',  'intercessor' ) . '</span>',
			'approved' => '<span class="intercessor-status approved">' . esc_html__( 'Approved', 'intercessor' ) . '</span>',
			'rejected' => '<span class="intercessor-status rejected">' . esc_html__( 'Rejected', 'intercessor' ) . '</span>',
			'archived' => '<span class="intercessor-status archived">' . esc_html__( 'Archived', 'intercessor' ) . '</span>',
			'private'  => '<span class="intercessor-status private">' . esc_html__( 'Private',  'intercessor' ) . '</span>',
		);

		return $labels[ $item->status ] ?? esc_html( $item->status );
	}

	/**
	 * Render the submission date.
	 *
	 * @since  1.0.0
	 * @param  object $item
	 * @return string
	 */
	protected function column_date_created( $item ): string {
		return esc_html(
			mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->date_created )
		);
	}

	/**
	 * Render inline Approve, Reject, and View action buttons.
	 *
	 * @since  1.0.0
	 * @param  object $item
	 * @return string
	 */
	protected function column_actions( $item ): string {
		$nonce   = wp_create_nonce( 'intercessor_moderate' );
		$id      = absint( $item->id );
		$postUrl = esc_url( admin_url( 'admin-post.php' ) );

		$approve = sprintf(
			/* translators: %d: total number of prayer requests */
			'<form method="post" action="%s" style="display:inline">
				<input type="hidden" name="action"     value="intercessor_moderate">
				<input type="hidden" name="request_id" value="%d">
				<input type="hidden" name="new_status" value="approved">
				<input type="hidden" name="_wpnonce"   value="%s">
				<button type="submit" class="button button-small button-primary">%s</button>
			</form>',
			$postUrl, $id, esc_attr( $nonce ),
			esc_html__( 'Approve', 'intercessor' )
		);

		$reject = sprintf(
			/* translators: %d: total number of prayer requests */
			'<form method="post" action="%s" style="display:inline">
				<input type="hidden" name="action"     value="intercessor_moderate">
				<input type="hidden" name="request_id" value="%d">
				<input type="hidden" name="new_status" value="rejected">
				<input type="hidden" name="_wpnonce"   value="%s">
				<button type="submit" class="button button-small">%s</button>
			</form>',
			$postUrl, $id, esc_attr( $nonce ),
			esc_html__( 'Reject', 'intercessor' )
		);

		$viewUrl = esc_url( add_query_arg(
			array( 'page' => 'intercessor-requests', 'view' => $id ),
			admin_url( 'admin.php' )
		) );
		/* translators: %d: number of pending prayer requests */
		$view = sprintf(
			'<a href="%s" class="button button-small">%s</a>',
			$viewUrl,
			esc_html__( 'View', 'intercessor' )
		);

		$pray = $this->render_admin_pray_button( $id );

		return $approve . ' ' . $reject . ' ' . $view . ' ' . $pray;
	}

	/**
	 * Render the admin "I prayed for this" button for a single row.
	 *
	 * Unlike the public Prayer Wall button, this is available for requests
	 * in any status — including 'private' ones, which are never shown on
	 * the front end and so can never be prayed for via the public button.
	 * The click is handled entirely by admin.js via AJAX (intercessor_admin_record_prayer);
	 * no page reload or nonce field is needed per-row since the shared
	 * nonce is localized once via Admin_Loader::enqueue_assets().
	 *
	 * @since 1.1.0
	 *
	 * @param int    $id Prayer request primary key.
	 *
	 * @return string HTML button markup.
	 */
	private function render_admin_pray_button( int $id ): string {
		$total = ( new \Intercessor\Database\Query\Prayed_Count_Query() )->get_total_for_request( $id );

		return sprintf(
			'<button type="button" class="button button-small intercessor-admin-pray-btn" data-request-id="%1$d">
				<span class="ipr-icon ipr-icon-praying" aria-hidden="true"></span>
				<span class="intercessor-admin-pray-label">%2$s</span>
				<span class="intercessor-admin-pray-count">%3$d</span>
			</button>',
			$id,
			esc_html__( 'I prayed for this', 'intercessor' ),
			absint( $total )
		);
	}
}
