<?php
/**
 * Controller for the single-requester tabbed detail page.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Prayer_History_Query;
use Intercessor\Database\Query\Prayer_Note_Query;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Prayed_Count_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Database\Row\Requester;

/**
 * Resolves a requester by ID, defines the tab registry, and dispatches
 * rendering to the appropriate tab method.
 *
 * Tabs:
 *   overview  — Profile card with avatar, name, email, linked WP user.
 *   prayers   — All prayer requests submitted by this requester.
 *   history   — Status-change history across all of their requests.
 *   notes     — Admin notes attached to this requester's requests.
 *   delete    — Destructive action panel.
 *
 * URL scheme:
 *   ?page=intercessor-requesters&requester_id={id}&tab={tab}
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Requester_View {

	// ── Constants ────────────────────────────────────────────────────────────

	/** @var string Default tab slug. */
	public const DEFAULT_TAB = 'overview';

	// ── Properties ───────────────────────────────────────────────────────────

	/**
	 * The resolved requester row.
	 *
	 * @since 1.0.0
	 * @var   Requester
	 */
	private Requester $requester;

	/**
	 * The currently active tab slug.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private string $active_tab;

	/**
	 * Ordered tab registry: slug → label/icon metadata.
	 *
	 * @since 1.0.0
	 * @var   array<string, array{label: string, icon: string, dashicon: string}>
	 */
	private array $tabs;

	// ── Factory ──────────────────────────────────────────────────────────────

	/**
	 * Attempt to resolve a requester from the URL and return a view instance.
	 *
	 * Returns null (with a proper wp_die message) when the ID is missing,
	 * non-numeric, or does not match any row.
	 *
	 * @since  1.0.0
	 * @return self|null  Resolved view instance, or null on failure.
	 */
	public static function from_request(): ?self {
		if ( ! current_user_can( 'view_prayer_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'intercessor' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requester_id = isset( $_GET['requester_id'] ) ? absint( $_GET['requester_id'] ) : 0;

		if ( $requester_id <= 0 ) {
			return null;
		}

		$query     = new Requester_Query();
		$requester = $query->get_item( $requester_id );

		if ( ! $requester ) {
			return null;
		}

		return new self( $requester );
	}

	// ── Constructor ──────────────────────────────────────────────────────────

	/**
	 * Initialise the view with a resolved requester row.
	 *
	 * @since  1.0.0
	 * @param  Requester $requester The requester row to display.
	 */
	private function __construct( Requester $requester ) {
		$this->requester  = $requester;
		$this->tabs       = $this->build_tabs();
		$this->active_tab = $this->resolve_active_tab();
	}

	// ── Public API ───────────────────────────────────────────────────────────

	/**
	 * Return the resolved requester row.
	 *
	 * @since  1.0.0
	 * @return Requester
	 */
	public function get_requester(): Requester {
		return $this->requester;
	}

	/**
	 * Return the active tab slug.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	public function get_active_tab(): string {
		return $this->active_tab;
	}

	/**
	 * Return the full tab registry.
	 *
	 * @since  1.0.0
	 * @return array<string, array{label: string, icon: string, dashicon: string}>
	 */
	public function get_tabs(): array {
		return $this->tabs;
	}

	/**
	 * Build a tab URL for the given slug, preserving the requester ID.
	 *
	 * @since  1.0.0
	 * @param  string $tab Tab slug.
	 * @return string      Absolute admin URL.
	 */
	public function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page'         => 'intercessor-requesters',
				'requester_id' => $this->requester->id,
				'tab'          => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Dispatch rendering to the active tab method.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_tab_content(): void {
		switch ( $this->active_tab ) {
			case 'prayers':
				$this->render_tab_prayers();
				break;
			case 'history':
				$this->render_tab_history();
				break;
			case 'notes':
				$this->render_tab_notes();
				break;
			case 'delete':
				$this->render_tab_delete();
				break;
			default:
				$this->render_tab_overview();
		}
	}

	// ── Tab configuration ────────────────────────────────────────────────────

	/**
	 * Build the ordered tab registry.
	 *
	 * The registry is filtered via 'intercessor_requester_tabs' so third-party
	 * code or future add-ons can inject additional tabs.
	 *
	 * @since  1.0.0
	 * @return array<string, array{label: string, dashicon: string}>
	 */
	private function build_tabs(): array {
		$tabs = array(
			'overview' => array(
				'label'    => __( 'Overview', 'intercessor' ),
				'dashicon' => 'dashicons-admin-users',
			),
			'prayers'  => array(
				'label'    => __( 'Prayer Requests', 'intercessor' ),
				'dashicon' => 'dashicons-heart',
			),
			'history'  => array(
				'label'    => __( 'History', 'intercessor' ),
				'dashicon' => 'dashicons-backup',
			),
			'notes'    => array(
				'label'    => __( 'Notes', 'intercessor' ),
				'dashicon' => 'dashicons-admin-comments',
			),
			'delete'   => array(
				'label'    => __( 'Delete', 'intercessor' ),
				'dashicon' => 'dashicons-trash',
			),
		);

		/**
		 * Filter the requester detail page tab registry.
		 *
		 * @since 1.0.0
		 * @param array     $tabs      Ordered tab definitions.
		 * @param Requester $requester The requester being displayed.
		 */
		return (array) apply_filters( 'intercessor_requester_tabs', $tabs, $this->requester );
	}

	/**
	 * Resolve and validate the active tab slug from the URL query string.
	 *
	 * Falls back to DEFAULT_TAB for unknown slugs.
	 *
	 * @since  1.0.0
	 * @return string  Validated tab slug.
	 */
	private function resolve_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : self::DEFAULT_TAB;
		return array_key_exists( $requested, $this->tabs ) ? $requested : self::DEFAULT_TAB;
	}

	// ── Tab renderers ────────────────────────────────────────────────────────

	/**
	 * Render the Overview tab — profile card and account details.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_tab_overview(): void {
		$r        = $this->requester;
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$requester_id = isset( $r->id ) ? (int) $r->id : 0;

		// Resolve WP user only if valid.
		$wp_user = null;

		if ( ! empty( $r->wp_user_id ) && $r->is_linked_to_user() ) {
			$wp_user = get_user_by( 'id', (int) $r->wp_user_id );
		}

		$prayer_q = new Prayer_Request_Query();

		// Reduce repeated object calls → reuse base args
		$base_args = array(
			'requester_id' => $requester_id,
		);

		$total = $prayer_q->count_items( $base_args );

		$pending = $prayer_q->count_items(
			$base_args + array( 'status' => 'pending' )
		);

		$approved = $prayer_q->count_items(
			$base_args + array( 'status' => 'approved' )
		);

		$is_pending_reg = ( $wp_user instanceof \WP_User )
			&& \Intercessor\Util\Registration_Handler::is_pending( (int) $wp_user->ID );

		// Safely handle query params (consistent WP pattern)
		$reg_resent = filter_input(
			INPUT_GET,
			'reg_resent',
			FILTER_VALIDATE_BOOL
		) ?? false;

		$reg_confirmed = filter_input(
			INPUT_GET,
			'reg_confirmed',
			FILTER_VALIDATE_BOOL
		) ?? false;

		$allowed_errors = array(
			'expired',
			'invalid_token',
			'already_confirmed',
		);

		$reg_error_input = filter_input( INPUT_GET, 'reg_error', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$reg_error       = is_string( $reg_error_input )
			? sanitize_key( wp_unslash( $reg_error_input ) )
			: '';

		if ( ! in_array( $reg_error, $allowed_errors, true ) ) {
			$reg_error = '';
		}
		?>
		
		<?php if ( $reg_resent ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'Confirmation email resent.', 'intercessor' ); ?>
			</p></div>
		<?php endif; ?>
		<?php if ( $reg_confirmed ) : ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'Account confirmed manually.', 'intercessor' ); ?>
			</p></div>
		<?php endif; ?>
		<?php if ( $reg_error === 'already_confirmed' ) : ?>
			<div class="notice notice-info is-dismissible"><p>
				<?php esc_html_e( 'This account has already been confirmed.', 'intercessor' ); ?>
			</p></div>
		<?php elseif ( $reg_error !== '' ) : ?>
			<div class="notice notice-error is-dismissible"><p>
				<?php esc_html_e( 'An error occurred. Please try again.', 'intercessor' ); ?>
			</p></div>
		<?php endif; ?>

		<div class="intercessor-requester-overview">

			<?php // ── Profile card ──────────────────────────────────────── ?>
			<div class="intercessor-box">
				<h2 class="intercessor-box__title">
					<span class="ipr-icon ipr-icon-user ipr-icon-inline" aria-hidden="true"></span>
					<?php esc_html_e( 'Profile', 'intercessor' ); ?>
				</h2>

				<div class="intercessor-requester-profile">
					<div class="intercessor-requester-avatar">
						<?php echo get_avatar( $r->email, 80, '', esc_attr( $r->get_display_name() ) ); ?>
					</div>
					<div class="intercessor-requester-meta">
						<h3 class="intercessor-requester-name">
							<?php echo esc_html( $r->get_display_name() ); ?>
							<?php if ( $r->status !== 'active' ) : ?>
								<span class="intercessor-status <?php echo esc_attr( $r->status ); ?>">
									<?php echo esc_html( ucfirst( $r->status ) ); ?>
								</span>
							<?php endif; ?>
						</h3>
						<a class="intercessor-requester-email" href="mailto:<?php echo esc_attr( $r->email ); ?>">
							<?php echo esc_html( $r->email ); ?>
						</a>
					</div>
				</div>

				<table class="intercessor-detail-table">
					<tr>
						<th><?php esc_html_e( 'Requester ID', 'intercessor' ); ?></th>
						<td><code>#<?php echo absint( $r->id ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'First Name', 'intercessor' ); ?></th>
						<td><?php echo esc_html( $r->get_first_name() ?: '—' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Name', 'intercessor' ); ?></th>
						<td><?php echo esc_html( $r->get_last_name() ?: '—' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'intercessor' ); ?></th>
						<td>
							<span class="intercessor-status <?php echo esc_attr( $r->status ); ?>">
								<?php echo esc_html( ucfirst( $r->status ) ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WordPress User', 'intercessor' ); ?></th>
						<td>
							<?php if ( $wp_user ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $r->wp_user_id ) ); ?>">
									<?php echo esc_html( $wp_user->user_login ); ?>
								</a>
								<?php if ( $is_pending_reg ) : ?>
									<span class="intercessor-status private" style="margin-left:6px;">
										<?php esc_html_e( 'Awaiting confirmation', 'intercessor' ); ?>
									</span>
								<?php endif; ?>
							<?php elseif ( $r->is_linked_to_user() ) : ?>
								<em><?php esc_html_e( '(deleted)', 'intercessor' ); ?></em>
							<?php else : ?>
								<?php esc_html_e( 'Guest', 'intercessor' ); ?>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( $wp_user ) : ?>
					<tr>
						<th><?php esc_html_e( 'Registration', 'intercessor' ); ?></th>
						<td>
							<?php if ( $is_pending_reg ) : ?>
								<span class="intercessor-status pending">
									<?php esc_html_e( 'Email not confirmed', 'intercessor' ); ?>
								</span>
								<div style="margin-top:0.5rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action"       value="intercessor_resend_confirmation">
										<input type="hidden" name="requester_id" value="<?php echo absint( $r->id ); ?>">
										<?php wp_nonce_field( 'intercessor_resend_confirmation' ); ?>
										<button type="submit" class="button button-small">
											<span class="dashicons dashicons-email-alt" style="font-size:14px;vertical-align:middle;margin-right:3px;"></span>
											<?php esc_html_e( 'Resend Email', 'intercessor' ); ?>
										</button>
									</form>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									      onsubmit="return confirm('<?php echo esc_js( __( 'Mark this account as confirmed without email verification?', 'intercessor' ) ); ?>')">
										<input type="hidden" name="action"       value="intercessor_manual_confirm_account">
										<input type="hidden" name="requester_id" value="<?php echo absint( $r->id ); ?>">
										<?php wp_nonce_field( 'intercessor_manual_confirm_account' ); ?>
										<button type="submit" class="button button-small">
											<span class="dashicons dashicons-yes" style="font-size:14px;vertical-align:middle;margin-right:3px;"></span>
											<?php esc_html_e( 'Mark Confirmed', 'intercessor' ); ?>
										</button>
									</form>
								</div>
							<?php else : ?>
								<span class="intercessor-status approved">
									<?php esc_html_e( 'Confirmed', 'intercessor' ); ?>
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Registered', 'intercessor' ); ?></th>
						<td><?php echo esc_html( \Intercessor\Util\Local_Time::format( $date_fmt, $r->date_created ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Updated', 'intercessor' ); ?></th>
						<td><?php echo esc_html( \Intercessor\Util\Local_Time::format( $date_fmt, $r->date_modified ) ); ?></td>
					</tr>
				</table>
			</div>

			<?php // ── Stats card ────────────────────────────────────────── ?>
			<div class="intercessor-box">
				<h2 class="intercessor-box__title">
					<span class="ipr-icon ipr-icon-praying ipr-icon-inline" aria-hidden="true"></span>
					<?php esc_html_e( 'Prayer Stats', 'intercessor' ); ?>
				</h2>

				<div class="intercessor-stats intercessor-requester-stats">
					<div class="intercessor-stat-card intercessor-stat-total">
						<span class="intercessor-stat-icon ipr-icon ipr-icon-praying" aria-hidden="true"></span>
						<span class="intercessor-stat-number"><?php echo absint( $total ); ?></span>
						<span class="intercessor-stat-label"><?php esc_html_e( 'Total Requests', 'intercessor' ); ?></span>
					</div>
					<div class="intercessor-stat-card intercessor-stat-pending">
						<span class="intercessor-stat-icon ipr-icon ipr-icon-warning1" aria-hidden="true"></span>
						<span class="intercessor-stat-number"><?php echo absint( $pending ); ?></span>
						<span class="intercessor-stat-label"><?php esc_html_e( 'Pending', 'intercessor' ); ?></span>
					</div>
					<div class="intercessor-stat-card intercessor-stat-approved">
						<span class="intercessor-stat-icon ipr-icon ipr-icon-checkmark" aria-hidden="true"></span>
						<span class="intercessor-stat-number"><?php echo absint( $approved ); ?></span>
						<span class="intercessor-stat-label"><?php esc_html_e( 'Approved', 'intercessor' ); ?></span>
					</div>
				</div>

				<p style="padding: 0 1.125rem 1rem;">
					<a href="<?php echo esc_url( $this->tab_url( 'prayers' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'View All Prayer Requests', 'intercessor' ); ?>
					</a>
				</p>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the Prayer Requests tab — paginated list of this requester's prayers.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_tab_prayers(): void {
		$r          = $this->requester;
		$date_fmt   = get_option( 'date_format' );
		$prayer_q   = new Prayer_Request_Query();
		$count_q    = new Prayed_Count_Query();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;

		$prayers = $prayer_q->get_items( array(
			'requester_id' => $r->id,
			'number'       => $per_page,
			'offset'       => ( $paged - 1 ) * $per_page,
			'orderby'      => 'date_created',
			'order'        => 'DESC',
		) );

		$total_prayers = $prayer_q->count_items( array( 'requester_id' => $r->id ) );
		$total_pages   = (int) ceil( $total_prayers / $per_page );

		$status_labels = array(
			'pending'  => __( 'Pending',  'intercessor' ),
			'approved' => __( 'Approved', 'intercessor' ),
			'rejected' => __( 'Rejected', 'intercessor' ),
			'archived' => __( 'Archived', 'intercessor' ),
			'private'  => __( 'Private',  'intercessor' ),
		);
		?>
		<div class="intercessor-box">
			<h2 class="intercessor-box__title">
				<span class="ipr-icon ipr-icon-praying ipr-icon-inline" aria-hidden="true"></span>
				<?php
				printf(
					/* translators: %d: total number of prayer requests */
					esc_html( _n( 'Prayer Request (%d)', 'Prayer Requests (%d)', $total_prayers, 'intercessor' ) ),
					absint( $total_prayers )
				);
				?>
			</h2>

			<?php if ( empty( $prayers ) ) : ?>
				<p class="intercessor-notes-empty">
					<?php esc_html_e( 'This requester has not submitted any prayer requests yet.', 'intercessor' ); ?>
				</p>
			<?php else : ?>
				<table class="wp-list-table widefat striped intercessor-requester-prayers-table">
					<thead>
						<tr>
							<th style="width:40px;"><?php esc_html_e( 'ID', 'intercessor' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'intercessor' ); ?></th>
							<th><?php esc_html_e( 'Status', 'intercessor' ); ?></th>
							<th><?php esc_html_e( 'Prayed For', 'intercessor' ); ?></th>
							<th><?php esc_html_e( 'Submitted', 'intercessor' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'intercessor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $prayers as $prayer ) :
							$prayed_total = $count_q->get_total_for_request( $prayer->id );
							$detail_url   = add_query_arg(
								array( 'page' => 'intercessor-requests', 'view' => $prayer->id ),
								admin_url( 'admin.php' )
							);
						?>
							<tr>
								<td><code>#<?php echo absint( $prayer->id ); ?></code></td>
								<td>
									<a href="<?php echo esc_url( $detail_url ); ?>">
										<?php echo esc_html( $prayer->subject ); ?>
									</a>
									<?php if ( $prayer->is_anonymous() ) : ?>
										<span class="intercessor-status private"><?php esc_html_e( 'Anonymous', 'intercessor' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<span class="intercessor-status <?php echo esc_attr( $prayer->status ); ?>">
										<?php echo esc_html( $status_labels[ $prayer->status ] ?? ucfirst( $prayer->status ) ); ?>
									</span>
								</td>
								<td><?php echo absint( $prayed_total ); ?></td>
								<td><?php echo esc_html( \Intercessor\Util\Local_Time::format( $date_fmt, $prayer->date_created ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">
										<?php esc_html_e( 'View', 'intercessor' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom" style="padding: 0.5rem 1.125rem;">
						<div class="tablenav-pages">
							<?php
							echo esc_url( paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							) ) );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the History tab — status-change timeline for all of this
	 * requester's prayer requests.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_tab_history(): void {
		$r        = $this->requester;
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$prayer_q = new Prayer_Request_Query();
		$hist_q   = new Prayer_History_Query();

		$prayers = $prayer_q->get_items( array(
			'requester_id' => $r->id,
			'number'       => 0,
			'orderby'      => 'date_created',
			'order'        => 'DESC',
		) );

		$status_labels = array(
			'pending'  => __( 'Pending',  'intercessor' ),
			'approved' => __( 'Approved', 'intercessor' ),
			'rejected' => __( 'Rejected', 'intercessor' ),
			'archived' => __( 'Archived', 'intercessor' ),
			'private'  => __( 'Private',  'intercessor' ),
		);
		?>
		<div class="intercessor-box">
			<h2 class="intercessor-box__title">
				<span class="ipr-icon ipr-icon-praying ipr-icon-inline" aria-hidden="true"></span>
				<?php esc_html_e( 'Status Change History', 'intercessor' ); ?>
			</h2>

			<?php if ( empty( $prayers ) ) : ?>
				<p class="intercessor-notes-empty">
					<?php esc_html_e( 'No prayer requests found for this requester.', 'intercessor' ); ?>
				</p>
			<?php else :
				$any_history = false;
				foreach ( $prayers as $prayer ) :
					$history = $hist_q->get_for_request( $prayer->id );
					if ( empty( $history ) ) {
						continue;
					}
					$any_history = true;
					$detail_url  = add_query_arg(
						array( 'page' => 'intercessor-requests', 'view' => $prayer->id ),
						admin_url( 'admin.php' )
					);
				?>
					<div class="intercessor-requester-history-group">
						<h3 class="intercessor-requester-history-prayer">
							<a href="<?php echo esc_url( $detail_url ); ?>">
								<?php echo esc_html( $prayer->subject ); ?>
							</a>
							<span class="intercessor-status <?php echo esc_attr( $prayer->status ); ?>">
								<?php echo esc_html( $status_labels[ $prayer->status ] ?? ucfirst( $prayer->status ) ); ?>
							</span>
						</h3>

						<ol class="intercessor-timeline">
							<?php foreach ( $history as $entry ) :
								$actor = '';
								if ( $entry->actor_user_id > 0 ) {
									$actor_user = get_user_by( 'id', $entry->actor_user_id );
									$actor      = $actor_user ? $actor_user->display_name : __( 'Unknown', 'intercessor' );
								}
							?>
								<li class="intercessor-timeline-entry">
									<div class="intercessor-timeline-dot ipr-icon" aria-hidden="true"></div>
									<div class="intercessor-timeline-content">
										<div class="intercessor-timeline-status-change">
											<?php if ( $entry->old_status ) : ?>
												<span class="intercessor-status-tag intercessor-status-<?php echo esc_attr( $entry->old_status ); ?>">
													<?php echo esc_html( $status_labels[ $entry->old_status ] ?? ucfirst( $entry->old_status ) ); ?>
												</span>
												<span class="intercessor-arrow" aria-label="<?php esc_attr_e( 'changed to', 'intercessor' ); ?>">&#8594;</span>
											<?php endif; ?>
											<span class="intercessor-status-tag intercessor-status-<?php echo esc_attr( $entry->new_status ); ?>">
												<?php echo esc_html( $status_labels[ $entry->new_status ] ?? ucfirst( $entry->new_status ) ); ?>
											</span>
										</div>
										<div class="intercessor-timeline-meta">
											<?php if ( $entry->date_created ) : ?>
												<time datetime="<?php echo esc_attr( $entry->date_created ); ?>">
													<?php echo esc_html( \Intercessor\Util\Local_Time::format( $date_fmt, $entry->date_created ) ); ?>
												</time>
											<?php endif; ?>
											<?php if ( $actor ) : ?>
												<span class="intercessor-timeline-actor">
													<?php printf(
														/* translators: %s: moderator name */
														esc_html__( 'by %s', 'intercessor' ),
														esc_html( $actor )
													); ?>
												</span>
											<?php endif; ?>
										</div>
										<?php if ( ! empty( $entry->note ) ) : ?>
											<div class="intercessor-timeline-note">
												<p><?php echo wp_kses_post( $entry->note ); ?></p>
											</div>
										<?php endif; ?>
									</div>
								</li>
							<?php endforeach; ?>
						</ol>
					</div>
				<?php endforeach;

				if ( ! $any_history ) : ?>
					<p class="intercessor-notes-empty">
						<?php esc_html_e( 'No status changes have been recorded for this requester\'s prayers yet.', 'intercessor' ); ?>
					</p>
				<?php endif;
			endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Notes tab.
	 *
	 * Two sections:
	 *  1. Requester Notes — notes attached directly to this requester record.
	 *     Admins can add new notes and delete existing ones inline.
	 *  2. Prayer Request Notes — read-only cross-reference of all notes attached
	 *     to this requester's individual prayer requests.
	 *
	 * Flash-notice query args handled here:
	 *   rn_added=1   — requester note was created successfully.
	 *   rn_deleted=1 — requester note was deleted successfully.
	 *   rn_error=1   — an error occurred.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_tab_notes(): void {
		$r               = $this->requester;
		$date_fmt        = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$rn_query        = new \Intercessor\Database\Query\Requester_Note_Query();
		$prayer_q        = new Prayer_Request_Query();
		$pr_note_q       = new Prayer_Note_Query();
		$requester_notes = $rn_query->get_for_requester( $r->id );
		$note_count      = count( $requester_notes );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rn_added'] ) ) { ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'Note added.', 'intercessor' ); ?>
			</p></div>
		<?php }
		if ( isset( $_GET['rn_deleted'] ) ) { ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php esc_html_e( 'Note deleted.', 'intercessor' ); ?>
			</p></div>
		<?php }
		if ( isset( $_GET['rn_error'] ) ) { ?>
			<div class="notice notice-error is-dismissible"><p>
				<?php esc_html_e( 'An error occurred. Please try again.', 'intercessor' ); ?>
			</p></div>
		<?php }
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>

		<?php // ── Section 1: Requester Notes ─────────────────────────────── ?>
		<div class="intercessor-box">
			<h2 class="intercessor-box__title">
				<span class="ipr-icon ipr-icon-user ipr-icon-inline" aria-hidden="true"></span>
				<?php esc_html_e( 'Requester Notes', 'intercessor' ); ?>
				<span class="intercessor-note-count"><?php echo absint( $note_count ); ?></span>
			</h2>

			<?php if ( empty( $requester_notes ) ) : ?>
				<p class="intercessor-notes-empty">
					<?php esc_html_e( 'No notes yet. Add the first one below.', 'intercessor' ); ?>
				</p>
			<?php else : ?>
				<ul class="intercessor-notes-list" style="max-height:none;">
					<?php foreach ( $requester_notes as $note ) :
						$author      = get_user_by( 'id', $note->author_user_id );
						$author_name = $author ? $author->display_name : __( 'Unknown', 'intercessor' );
					?>
						<li class="intercessor-note <?php echo esc_attr( $note->is_private() ? 'intercessor-note--private' : 'intercessor-note--shared' ); ?>">

							<div class="intercessor-note__meta">
								<span class="intercessor-note__author"><?php echo esc_html( $author_name ); ?></span>
								<span class="intercessor-note__date">
									<?php echo esc_html( \Intercessor\Util\Local_Time::format( $date_fmt, $note->date_created ) ); ?>
								</span>
								<span class="intercessor-note__badge <?php echo $note->is_private() ? 'intercessor-note__badge--private' : 'intercessor-note__badge--shared'; ?>">
									<?php echo $note->is_private() ? esc_html__( 'Private', 'intercessor' ) : esc_html__( 'General', 'intercessor' ); ?>
								</span>
							</div>

							<div class="intercessor-note__body">
								<?php echo wp_kses_post( wpautop( $note->content ) ); ?>
							</div>

							<div class="intercessor-note__actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								      onsubmit="return confirm('<?php echo esc_js( __( 'Delete this note? This cannot be undone.', 'intercessor' ) ); ?>')">
									<input type="hidden" name="action"       value="intercessor_delete_requester_note">
									<input type="hidden" name="note_id"      value="<?php echo absint( $note->id ); ?>">
									<input type="hidden" name="requester_id" value="<?php echo absint( $r->id ); ?>">
									<?php wp_nonce_field( 'intercessor_delete_requester_note' ); ?>
									<button type="submit" class="button-link intercessor-note__delete">
										<?php esc_html_e( 'Delete', 'intercessor' ); ?>
									</button>
								</form>
							</div>

						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php // ── Add requester note form ───────────────────────────── ?>
			<div class="intercessor-add-note">
				<h3><?php esc_html_e( 'Add Note', 'intercessor' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action"       value="intercessor_add_requester_note">
					<input type="hidden" name="requester_id" value="<?php echo absint( $r->id ); ?>">
					<?php wp_nonce_field( 'intercessor_add_requester_note' ); ?>

					<div class="intercessor-add-note__field">
						<label for="rn_content_<?php echo absint( $r->id ); ?>" class="screen-reader-text">
							<?php esc_html_e( 'Note content', 'intercessor' ); ?>
						</label>
						<textarea
							name="note_content"
							id="rn_content_<?php echo absint( $r->id ); ?>"
							rows="4"
							placeholder="<?php esc_attr_e( 'Write an internal note about this requester\xe2\x80\xa6', 'intercessor' ); ?>"
							class="large-text"
							required></textarea>
					</div>

					<div class="intercessor-add-note__options">
						<label class="intercessor-add-note__privacy">
							<input type="checkbox" name="note_is_private" value="1" checked>
							<?php esc_html_e( 'Private (admin only)', 'intercessor' ); ?>
						</label>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Add Note', 'intercessor' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>

		<?php
		// ── Section 2: Prayer Request Notes (read-only cross-reference) ───────
		$prayers = $prayer_q->get_items( array( 'requester_id' => $r->id, 'number' => 0 ) );

		if ( ! empty( $prayers ) ) :
			$groups = array();
			foreach ( $prayers as $prayer ) {
				$pn = $pr_note_q->get_for_request( $prayer->id );
				if ( ! empty( $pn ) ) {
					$groups[] = array( 'prayer' => $prayer, 'notes' => $pn );
				}
			}

			if ( ! empty( $groups ) ) :
				$total_pn = array_sum( array_map( static fn( $g ) => count( $g['notes'] ), $groups ) );
		?>
		<div class="intercessor-box" style="margin-top:1.25rem;">
			<h2 class="intercessor-box__title">
				<span class="ipr-icon ipr-icon-praying ipr-icon-inline" aria-hidden="true"></span>
				<?php esc_html_e( 'Prayer Request Notes', 'intercessor' ); ?>
				<span class="intercessor-note-count"
				      title="<?php esc_attr_e( 'Total notes across all prayer requests', 'intercessor' ); ?>">
					<?php echo absint( $total_pn ); ?>
				</span>
			</h2>
			<p style="padding:0.4rem 1.125rem 0;color:#646970;font-size:0.875rem;">
				<?php esc_html_e( 'Read-only view. To manage these notes open the individual prayer request.', 'intercessor' ); ?>
			</p>
			<?php foreach ( $groups as $group ) :
				$prayer     = $group['prayer'];
				$pn_list    = $group['notes'];
				$detail_url = add_query_arg(
					array( 'page' => 'intercessor-requests', 'view' => $prayer->id ),
					admin_url( 'admin.php' )
				);
			?>
				<div class="intercessor-requester-notes-group">
					<h3 class="intercessor-requester-notes-prayer">
						<a href="<?php echo esc_url( $detail_url ); ?>">
							<?php echo esc_html( $prayer->subject ); ?>
						</a>
						<span class="intercessor-note-count"><?php echo count( $pn_list ); ?></span>
					</h3>
					<ul class="intercessor-notes-list" style="max-height:none;">
						<?php foreach ( $pn_list as $pn ) :
							$pn_author = get_user_by( 'id', $pn->author_user_id );
							$pn_name   = $pn_author ? $pn_author->display_name : __( 'Unknown', 'intercessor' );
						?>
							<li class="intercessor-note <?php echo esc_attr( $pn->is_private() ? 'intercessor-note--private' : 'intercessor-note--shared' ); ?>">
								<div class="intercessor-note__meta">
									<span class="intercessor-note__author"><?php echo esc_html( $pn_name ); ?></span>
									<span class="intercessor-note__date">
										<?php echo esc_html( \Intercessor\Util\Local_Time::format( $date_fmt, $pn->date_created ) ); ?>
									</span>
									<span class="intercessor-note__badge <?php echo $pn->is_private() ? 'intercessor-note__badge--private' : 'intercessor-note__badge--shared'; ?>">
										<?php echo $pn->is_private() ? esc_html__( 'Private', 'intercessor' ) : esc_html__( 'Shared', 'intercessor' ); ?>
									</span>
								</div>
								<div class="intercessor-note__body">
									<?php echo wp_kses_post( wpautop( $pn->content ) ); ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
			endif; // ! empty( $groups )
		endif;   // ! empty( $prayers )
	}

	/**
	 * Render the Delete tab — destructive action panel with confirmation checkbox.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function render_tab_delete(): void {
		$r          = $this->requester;
		$prayer_q   = new Prayer_Request_Query();
		$total      = $prayer_q->count_items( array( 'requester_id' => $r->id ) );
		$back_url   = add_query_arg(
			array( 'page' => 'intercessor-requesters', 'requester_id' => $r->id, 'tab' => 'overview' ),
			admin_url( 'admin.php' )
		);
		?>
		<div class="intercessor-box">
			<h2 class="intercessor-box__title">
				<span class="dashicons dashicons-trash" aria-hidden="true" style="color:#d63638;"></span>
				<?php esc_html_e( 'Delete Requester', 'intercessor' ); ?>
			</h2>

			<div style="padding: 0 1.125rem 1.125rem;">
				<div class="notice notice-error inline" style="margin: 1rem 0;">
					<p>
						<strong><?php esc_html_e( 'Warning:', 'intercessor' ); ?></strong>
						<?php
						printf(
							/* translators: 1: requester name 2: number of prayer requests */
							esc_html( _n(
								'This will permanently delete %1$s and their %2$d prayer request.',
								'This will permanently delete %1$s and their %2$d prayer requests.',
								$total,
								'intercessor'
							) ),
							'<strong>' . esc_html( $r->get_display_name() ) . '</strong>',
							absint( $total )
						);
						?>
						<?php esc_html_e( 'This action cannot be undone.', 'intercessor' ); ?>
					</p>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action"       value="intercessor_delete_requester">
					<input type="hidden" name="requester_id" value="<?php echo absint( $r->id ); ?>">
					<?php wp_nonce_field( 'intercessor_delete_requester' ); ?>

					<p>
						<label>
							<input type="checkbox" name="confirm_delete" value="1" id="intercessor-delete-confirm" required>
							<?php printf(
								/* translators: %s: requester name */
								esc_html__( 'Yes, I want to permanently delete %s.', 'intercessor' ),
								'<strong>' . esc_html( $r->get_display_name() ) . '</strong>'
							); ?>
						</label>
					</p>

					<p>
						<label>
							<input type="checkbox" name="delete_prayers" value="1" id="intercessor-delete-prayers">
							<?php esc_html_e( 'Also delete all associated prayer requests, notes, and history.', 'intercessor' ); ?>
						</label>
					</p>

					<p style="margin-top: 1.25rem;">
						<button type="submit" class="button button-primary" style="background:#d63638;border-color:#d63638;"
							onclick="return confirm('<?php echo esc_js( __( 'This cannot be undone. Are you sure?', 'intercessor' ) ); ?>')">
							<?php esc_html_e( 'Delete Requester', 'intercessor' ); ?>
						</button>
						&nbsp;
						<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Cancel', 'intercessor' ); ?>
						</a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
