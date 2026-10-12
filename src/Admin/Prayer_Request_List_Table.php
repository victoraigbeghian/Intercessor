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
	 * Full moderation view (prayer managers / administrators).
	 *
	 * @since 1.1.3
	 * @var   string
	 */
	public const MODE_ADMIN = 'admin';

	/**
	 * Restricted, read-only view for prayer warriors.
	 *
	 * Lists only approved and private requests, hides requester contact
	 * details and every moderation control, and offers only the
	 * "I prayed for this" action.
	 *
	 * @since 1.1.3
	 * @var   string
	 */
	public const MODE_WARRIOR = 'warrior';

	/**
	 * User-meta key backing the "Number of prayer requests per page"
	 * Screen Option.
	 *
	 * @since 1.1.3
	 * @var   string
	 */
	public const PER_PAGE_OPTION = 'intercessor_prayers_per_page';

	/**
	 * The only statuses a prayer warrior may ever see.
	 *
	 * Enforced in prepare_items() and get_views() — never derived from
	 * request input — so a tampered status_filter cannot widen the list.
	 *
	 * @since 1.1.3
	 * @var   string[]
	 */
	public const WARRIOR_STATUSES = array( 'approved', 'private' );

	/**
	 * Messages longer than this many words are collapsed behind a
	 * "Read full request" dropdown in the warrior view.
	 *
	 * @since 1.1.3
	 * @var   int
	 */
	public const COLLAPSE_WORDS = 100;

	/**
	 * Query instance used to fetch and count prayer request rows.
	 *
	 * @since 1.0.0
	 * @var   Prayer_Request_Query
	 */
	private Prayer_Request_Query $query;

	/**
	 * Display mode: MODE_ADMIN or MODE_WARRIOR.
	 *
	 * @since 1.1.3
	 * @var   string
	 */
	private string $mode;

	/**
	 * Initialise the list table and instantiate the query class.
	 *
	 * @since 1.0.0
	 * @since 1.1.3 Accepts a display mode.
	 * @param string $mode One of self::MODE_ADMIN or self::MODE_WARRIOR.
	 */
	public function __construct( string $mode = self::MODE_ADMIN ) {
		parent::__construct( array(
			'singular' => 'prayer_request',
			'plural'   => 'prayer_requests',
			'ajax'     => false,
		) );

		$this->mode  = self::MODE_WARRIOR === $mode ? self::MODE_WARRIOR : self::MODE_ADMIN;
		$this->query = new Prayer_Request_Query();
	}

	/**
	 * Whether this table is rendering the restricted prayer warrior view.
	 *
	 * @since  1.1.3
	 * @return bool
	 */
	private function is_warrior_mode(): bool {
		return self::MODE_WARRIOR === $this->mode;
	}

	/**
	 * Base URL of the page this table is displayed on.
	 *
	 * @since  1.1.3
	 * @return string
	 */
	private function get_base_url(): string {
		return admin_url(
			$this->is_warrior_mode()
				? 'admin.php?page=intercessor-private-requests'
				: 'admin.php?page=intercessor-requests'
		);
	}

	/**
	 * Resolve the status constraint for queries and counts.
	 *
	 * Admin mode: the requested status_filter, or no constraint.
	 * Warrior mode: always limited to WARRIOR_STATUSES; a status_filter is
	 * honoured only when it is itself one of those statuses.
	 *
	 * @since  1.1.3
	 * @param  string $statusFilter Sanitised status_filter request value.
	 * @return string|string[]|null Status value(s) to filter by, or null for none.
	 */
	private function resolve_status_constraint( string $statusFilter ) {
		if ( ! $this->is_warrior_mode() ) {
			return '' !== $statusFilter ? $statusFilter : null;
		}

		return in_array( $statusFilter, self::WARRIOR_STATUSES, true )
			? $statusFilter
			: self::WARRIOR_STATUSES;
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
		if ( $this->is_warrior_mode() ) {
			return array(
				'subject'      => __( 'Prayer Request', 'intercessor' ),
				'status'       => __( 'Status', 'intercessor' ),
				'requester_id' => __( 'Requester', 'intercessor' ),
				'date_created' => __( 'Submitted', 'intercessor' ),
				'actions'      => __( 'Pray', 'intercessor' ),
			);
		}

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
		// Prayer warriors have no moderation powers.
		if ( $this->is_warrior_mode() ) {
			return array();
		}

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
		// Honours the "Number of prayer requests per page" Screen Option.
		$perPage     = max( 1, (int) $this->get_items_per_page( self::PER_PAGE_OPTION, 20 ) );
		$currentPage = $this->get_pagenum();

		$req          = Request::capture();
		$statusFilter = $req->get_key( 'status_filter' );
		$constraint   = $this->resolve_status_constraint( $statusFilter );

		$args = array(
			'number'  => $perPage,
			'offset'  => ( $currentPage - 1 ) * $perPage,
			'orderby' => $req->get_key( 'orderby' ) ?: 'date_created',
			'order'   => strtoupper( $req->get_key( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC',
		);

		$countArgs = array();

		if ( null !== $constraint ) {
			$args['status']      = $constraint;
			$countArgs['status'] = $constraint;
		}

		$this->items = $this->query->get_items( $args );
		$total       = $this->query->count_items( $countArgs );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $perPage,
			'total_pages' => (int) ceil( $total / $perPage ),
		) );

		// Hidden columns come from the Screen Options "Columns" checklist.
		$hidden = $this->screen ? get_hidden_columns( $this->screen ) : array();

		$this->_column_headers = array( $this->get_columns(), $hidden, $this->get_sortable_columns() );
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

		if ( $this->is_warrior_mode() ) {
			$statuses = array(
				''         => __( 'All',      'intercessor' ),
				'approved' => __( 'Approved', 'intercessor' ),
				'private'  => __( 'Private',  'intercessor' ),
			);
		} else {
			$statuses = array(
				''         => __( 'All',      'intercessor' ),
				'pending'  => __( 'Pending',  'intercessor' ),
				'approved' => __( 'Approved', 'intercessor' ),
				'rejected' => __( 'Rejected', 'intercessor' ),
				'archived' => __( 'Archived', 'intercessor' ),
				'private'  => __( 'Private',  'intercessor' ),
			);
		}

		// A warrior's "current" tab is only meaningful for visible statuses.
		if ( $this->is_warrior_mode() && ! in_array( $current, self::WARRIOR_STATUSES, true ) ) {
			$current = '';
		}

		$views    = array();
		$base     = $this->get_base_url();

		foreach ( $statuses as $slug => $label ) {
			if ( $slug === '' ) {
				$allConstraint = $this->resolve_status_constraint( '' );
				$count         = $this->query->count_items(
					null === $allConstraint ? array() : array( 'status' => $allConstraint )
				);
			} else {
				$count = $this->query->count_items( array( 'status' => $slug ) );
			}

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
	 * without opening each one. A short excerpt of the request body is
	 * shown beneath the subject for the same reason — so a moderator can
	 * triage most requests from this list without clicking View on each
	 * one individually.
	 *
	 * @since  1.0.0
	 * @since  1.1.1 Added a truncated content excerpt beneath the subject.
	 * @param  object $item
	 * @return string
	 */
	protected function column_subject( $item ): string {
		if ( $this->is_warrior_mode() ) {
			return $this->render_warrior_subject( $item );
		}

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

		$title = sprintf(
			/* translators: %s: subject of the prayer request */
			'<strong><a href="%s">%s</a>%s</strong>',
			esc_url( $editUrl ),
			$subject,
			$flag
		);

		return $title . $this->render_content_excerpt( (string) ( $item->content ?? '' ) );
	}

	/**
	 * Render the subject and full message for the prayer warrior view.
	 *
	 * The subject is plain text (no link — the detail view is not available
	 * to warriors). The full message is shown inline when it is short, or
	 * collapsed behind a native <details> dropdown when it exceeds
	 * COLLAPSE_WORDS words, so no JavaScript is needed to read it.
	 *
	 * @since  1.1.3
	 * @param  object $item Prayer request row.
	 * @return string       HTML cell content.
	 */
	private function render_warrior_subject( $item ): string {
		$subject = esc_html( $item->subject ?: __( '(no subject)', 'intercessor' ) );
		$content = trim( (string) ( $item->content ?? '' ) );

		$html = '<strong class="intercessor-warrior-subject">' . $subject . '</strong>';

		if ( $content === '' ) {
			return $html;
		}

		$full = '<div class="intercessor-warrior-message">'
			. wp_kses_post( wpautop( esc_html( $content ) ) )
			. '</div>';

		if ( $this->count_words( $content ) <= self::COLLAPSE_WORDS ) {
			return $html . $full;
		}

		$preview = esc_html( wp_trim_words( $content, 25, '…' ) );

		return $html
			. '<details class="intercessor-row-details">'
			. '<summary class="intercessor-row-details__summary">'
			. '<span class="intercessor-row-details__preview">' . $preview . '</span> '
			. '<span class="button button-small intercessor-row-details__toggle">'
			. '<span class="intercessor-row-details__more">' . esc_html__( 'Read full request', 'intercessor' ) . '</span>'
			. '<span class="intercessor-row-details__less">' . esc_html__( 'Show less', 'intercessor' ) . '</span>'
			. '</span>'
			. '</summary>'
			. $full
			. '</details>';
	}

	/**
	 * Count the words in a plain-text string.
	 *
	 * @since  1.1.3
	 * @param  string $text Plain text.
	 * @return int          Number of whitespace-separated words.
	 */
	private function count_words( string $text ): int {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Render a short, word-boundary-truncated preview of the request body.
	 *
	 * $item->content is plain text — sanitized on submission, never raw
	 * HTML — so no markup stripping is needed before trimming, only
	 * escaping on output. wp_trim_words() (rather than a raw substr()) is
	 * used so the cut never lands mid-word.
	 *
	 * @since  1.1.1
	 * @param  string $content Full prayer request body text.
	 * @return string          HTML snippet, or '' when there's no content.
	 */
	private function render_content_excerpt( string $content ): string {
		$content = trim( $content );

		if ( $content === '' ) {
			return '';
		}

		$excerpt = wp_trim_words( $content, 18, '…' );

		return '<p class="intercessor-row-excerpt">' . esc_html( $excerpt ) . '</p>';
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
		if ( $this->is_warrior_mode() ) {
			return $this->render_warrior_requester( $item );
		}

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
	 * Render the Requester column for prayer warriors.
	 *
	 * Shows the requester's name only for non-anonymous requests, as plain
	 * text. Anonymous requests show "[Anonymous]" and never trigger a
	 * requester lookup, so neither the name nor the email ever reaches the
	 * page. There is no link to the requester detail screen.
	 *
	 * @since  1.1.3
	 * @param  object $item Prayer request row.
	 * @return string       HTML cell content.
	 */
	private function render_warrior_requester( $item ): string {
		if ( $item->is_anonymous() ) {
			return '<em>' . esc_html__( '[Anonymous]', 'intercessor' ) . '</em>';
		}

		$requester_id = (int) $item->requester_id;
		$requester    = $requester_id > 0 ? ( new Requester_Query() )->get_item( $requester_id ) : null;

		if ( ! $requester ) {
			return '<span style="color:#a0a0a0;">' . esc_html__( '(none)', 'intercessor' ) . '</span>';
		}

		return esc_html( $requester->get_display_name() );
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
	 * Render inline status-transition, View, and pray-count action buttons.
	 *
	 * @since  1.0.0
	 * @since  1.1.1 Buttons are now contextual to the row's current status
	 *               instead of always showing Approve + Reject. Previously
	 *               an already-Rejected row still offered "Approve" and a
	 *               Private row offered both "Approve" and "Reject" —
	 *               confusing, and in the same-status case a button with no
	 *               real effect. See get_status_transition_buttons().
	 * @param  object $item
	 * @return string
	 */
	protected function column_actions( $item ): string {
		// Prayer warriors get the pray button only — no moderation forms,
		// no link to the detail view.
		if ( $this->is_warrior_mode() ) {
			return $this->render_admin_pray_button( absint( $item->id ) );
		}

		$nonce   = wp_create_nonce( 'intercessor_moderate' );
		$id      = absint( $item->id );
		$postUrl = esc_url( admin_url( 'admin-post.php' ) );

		$transitionButtons = $this->get_status_transition_buttons( (string) $item->status, $id, $nonce, $postUrl );

		$viewUrl = esc_url( add_query_arg(
			array( 'page' => 'intercessor-requests', 'view' => $id ),
			admin_url( 'admin.php' )
		) );
		$view = sprintf(
			'<a href="%s" class="button button-small">%s</a>',
			$viewUrl,
			esc_html__( 'View', 'intercessor' )
		);

		$pray = $this->render_admin_pray_button( $id );

		return '<span class="intercessor-moderate-actions">'
			. implode( ' ', $transitionButtons ) . ' ' . $view
			. '</span> ' . $pray;
	}

	/**
	 * Return the status-transition buttons appropriate for the row's
	 * current status, rather than always offering Approve + Reject.
	 *
	 *   pending / private → Approve, Reject   (the two open decisions)
	 *   approved          → Reject, Archive   (undo, or retire)
	 *   rejected          → Approve, Archive  (reconsider, or retire)
	 *   archived          → Restore to Pending (bring back into the queue)
	 *
	 * @since  1.1.1
	 * @param  string $status  Current item status.
	 * @param  int    $id      Prayer request ID.
	 * @param  string $nonce   Shared moderation nonce for this request.
	 * @param  string $postUrl admin-post.php URL.
	 * @return string[]        HTML form markup, in display order.
	 */
	private function get_status_transition_buttons( string $status, int $id, string $nonce, string $postUrl ): array {
		switch ( $status ) {
			case 'approved':
				return array(
					$this->render_moderation_button( $id, 'rejected', __( 'Reject', 'intercessor' ), 'button-small', $nonce, $postUrl ),
					$this->render_moderation_button( $id, 'archived', __( 'Archive', 'intercessor' ), 'button-small', $nonce, $postUrl ),
				);

			case 'rejected':
				return array(
					$this->render_moderation_button( $id, 'approved', __( 'Approve', 'intercessor' ), 'button-small button-primary', $nonce, $postUrl ),
					$this->render_moderation_button( $id, 'archived', __( 'Archive', 'intercessor' ), 'button-small', $nonce, $postUrl ),
				);

			case 'archived':
				return array(
					$this->render_moderation_button( $id, 'pending', __( 'Restore to Pending', 'intercessor' ), 'button-small', $nonce, $postUrl ),
				);

			case 'pending':
			case 'private':
			default:
				return array(
					$this->render_moderation_button( $id, 'approved', __( 'Approve', 'intercessor' ), 'button-small button-primary', $nonce, $postUrl ),
					$this->render_moderation_button( $id, 'rejected', __( 'Reject', 'intercessor' ), 'button-small', $nonce, $postUrl ),
				);
		}
	}

	/**
	 * Render a single status-transition form/button.
	 *
	 * Factored out of the old column_actions() body so
	 * get_status_transition_buttons() can request exactly the transitions
	 * relevant to a row's current status without duplicating this markup
	 * per status branch.
	 *
	 * @since  1.1.1
	 * @param  int    $id        Prayer request ID.
	 * @param  string $newStatus Target status value — one of
	 *                           Admin\Moderation_Handler::VALID_STATUSES.
	 * @param  string $label     Button label.
	 * @param  string $cssClass  Additional button CSS class(es).
	 * @param  string $nonce     Shared moderation nonce for this request.
	 * @param  string $postUrl   admin-post.php URL.
	 * @return string            HTML form markup.
	 */
	private function render_moderation_button( int $id, string $newStatus, string $label, string $cssClass, string $nonce, string $postUrl ): string {
		return sprintf(
			'<form method="post" action="%s" style="display:inline">
				<input type="hidden" name="action"     value="intercessor_moderate">
				<input type="hidden" name="request_id" value="%d">
				<input type="hidden" name="new_status" value="%s">
				<input type="hidden" name="_wpnonce"   value="%s">
				<button type="submit" class="button %s">%s</button>
			</form>',
			$postUrl, $id, esc_attr( $newStatus ), esc_attr( $nonce ), esc_attr( $cssClass ),
			esc_html( $label )
		);
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
	 * @since  1.1.0
	 * @param  int $id Prayer request primary key.
	 * @return string  HTML button markup.
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
