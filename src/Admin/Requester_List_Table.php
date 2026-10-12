<?php
/**
 * Requesters WP_List_Table implementation.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Requester_Query;
use Intercessor\Http\Request;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the requesters admin list table.
 *
 * Displays all requester records on the Intercessor → Requesters admin page
 * in a sortable, paginated WP_List_Table. Links email addresses as mailto
 * anchors and resolves WordPress usernames for linked accounts.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Requester_List_Table extends WP_List_Table {

	/**
	 * User-meta key backing the "Number of requesters per page" Screen Option.
	 *
	 * @since 1.1.1
	 * @var   string
	 */
	public const PER_PAGE_OPTION = 'intercessor_requesters_per_page';

	/**
	 * Query instance used to fetch and count requester rows.
	 *
	 * @since 1.0.0
	 * @var   RequesterQuery
	 */
	private Requester_Query $query;

	/**
	 * Initialise the list table and instantiate the query class.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'requester',
				'plural'   => 'requesters',
				'ajax'     => false,
			)
		);

		$this->query = new Requester_Query();
	}

	/**
	 * Display a message when no requesters match the current filter.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No requesters found.', 'intercessor' );
	}

	/**
	 * Return the column definitions for the list table.
	 *
	 * @since  1.0.0
	 * @return array<string, string> Map of column key to column header label.
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'name'         => __( 'Name',        'intercessor' ),
			'email'        => __( 'Email',        'intercessor' ),
			'status'       => __( 'Status',       'intercessor' ),
			'wp_user_id'   => __( 'WP User',      'intercessor' ),
			'date_created' => __( 'Registered',   'intercessor' ),
		);
	}

	/**
	 * Return the sortable column definitions.
	 *
	 * @since  1.0.0
	 * @return array<string, array{0: string, 1: bool}> Map of column key to sort tuple.
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name'         => array( 'first_name', false ),
			'email'        => array( 'email', false ),
			'date_created' => array( 'date_created', true ),
		);
	}

	/**
	 * Fetch rows and configure pagination for the current page view.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function prepare_items(): void {
		// Honours the "Number of requesters per page" Screen Option.
		$perPage     = max( 1, (int) $this->get_items_per_page( self::PER_PAGE_OPTION, 20 ) );
		$currentPage = $this->get_pagenum();

		$args = array(
			'number'  => $perPage,
			'offset'  => ( $currentPage - 1 ) * $perPage,
			'orderby' => Request::capture()->get_key( 'orderby' ) ?: 'date_created',
			'order'   => strtoupper( Request::capture()->get_key( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC',
		);

		$this->items = $this->query->get_items( $args );
		$total       = $this->query->count_items( array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $perPage,
				'total_pages' => (int) ceil( $total / $perPage ),
			)
		);

		// Hidden columns come from the Screen Options "Columns" checklist.
		$hidden = $this->screen ? get_hidden_columns( $this->screen ) : array();

		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns() );
	}

	/**
	 * Render fallback cell content for columns without a dedicated method.
	 *
	 * @since  1.0.0
	 * @param  object $item        The current row object.
	 * @param  string $column_name The current column key.
	 * @return string              Escaped cell content.
	 */
	protected function column_default( $item, $column_name ) {
		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Render the bulk-action checkbox cell.
	 *
	 * @since  1.0.0
	 * @param  object $item The current row object.
	 * @return string       HTML checkbox input.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="bulk_ids[]" value="%d" />', absint( $item->id ) );
	}

	/**
	 * Render the Name column — linked to the requester detail page.
	 *
	 * Shows the display name (first + last when available, legacy name
	 * otherwise) as a link, with first name and last name on separate lines
	 * below when the structured fields are populated.
	 *
	 * @since  1.0.0
	 * @param  object $item The current row object.
	 * @return string       HTML for the name cell including row actions.
	 */
	protected function column_name( $item ): string {
		$detail_url = add_query_arg(
			array(
				'page'         => 'intercessor-requesters',
				'requester_id' => absint( $item->id ),
				'tab'          => 'overview',
			),
			admin_url( 'admin.php' )
		);

		$display = esc_html( $item->get_display_name() );
		$sub     = '';

		// Show structured first/last below the link when they differ from the combined name.
		if ( $item->first_name !== '' || $item->last_name !== '' ) {
			$sub = '<br><small style="color:#646970;">'
				. esc_html( $item->first_name )
				. ( $item->first_name !== '' && $item->last_name !== '' ? ' ' : '' )
				. esc_html( $item->last_name )
				. '</small>';
		}

		$row_actions = $this->row_actions(
			array(
				'view' => sprintf(
					/* translators: %s: URL to the requester detail page --- IGNORE --- */
					'<a href="%s">%s</a>',
					esc_url( $detail_url ),
					esc_html__( 'View', 'intercessor' )
				),
			)
		);

		return '<strong><a href="' . esc_url( $detail_url ) . '">' . $display . '</a></strong>'
			. $sub
			. $row_actions;
	}

	/**
	 * Render the requester's email address as a mailto anchor.
	 *
	 * @since  1.0.0
	 * @param  object $item The current row object.
	 * @return string       HTML anchor element.
	 */
	protected function column_email( $item ): string {
		return '<a href="mailto:' . esc_attr( $item->email ) . '">' . esc_html( $item->email ) . '</a>';
	}

	/**
	 * Render the linked WordPress username, or a dash when not linked.
	 *
	 * Performs a get_user_by() lookup for linked accounts and displays
	 * a localised '(deleted)' label when the WP user no longer exists.
	 *
	 * @since  1.0.0
	 * @param  object $item The current row object.
	 * @return string       Plain text username, dash, or '(deleted)' label.
	 */
	protected function column_wp_user_id( $item ): string {
		if ( empty( $item->wp_user_id ) ) {
			return '—';
		}

		$user = get_user_by( 'id', (int) $item->wp_user_id );

		if ( ! $user ) {
			return esc_html__( '(deleted)', 'intercessor' );
		}

		$output = '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">'
			. esc_html( $user->user_login )
			. '</a>';

		if ( \Intercessor\Util\Registration_Handler::is_pending( $user->ID ) ) {
			$output .= ' <span class="intercessor-status private" style="font-size:0.75em;">'
				. esc_html__( 'Pending', 'intercessor' )
				. '</span>';
		}

		return $output;
	}

	/**
	 * Render the registration date using the WordPress date format option.
	 *
	 * @since  1.0.0
	 * @param  object $item The current row object.
	 * @return string       Localised date string.
	 */
	protected function column_date_created( $item ): string {
		return esc_html(
			mysql2date( get_option( 'date_format' ), $item->date_created )
		);
	}
}
