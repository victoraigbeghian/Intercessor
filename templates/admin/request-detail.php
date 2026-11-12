<?php
/**
 * Admin template: single prayer request detail view with notes panel.
 *
 * Rendered by Admin_Loader::render_requests_page() when ?view={id} is present.
 * All variables are resolved here; the template is pure output.
 *
 * @package Intercessor
 * @since   1.0.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scoped variables included via require, not true globals

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'edit_prayers' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'intercessor' ) );
}

use Intercessor\Database\Query\Prayed_Count_Query;
use Intercessor\Database\Query\Prayer_Note_Query;
use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;

// ── Resolve the prayer request ────────────────────────────────────────────────
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$requestId = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;

$prayerQuery = new Prayer_Request_Query();
$request     = $prayerQuery->get_item( $requestId );

if ( ! $request ) {
	echo '<div class="wrap"><p>';
	echo esc_html__( 'Prayer request not found.', 'intercessor' );
	echo '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=intercessor-requests' ) ) . '">';
	echo '&larr; ' . esc_html__( 'Back to Prayer Requests', 'intercessor' );
	echo '</a></p></div>';
	return;
}

// ── Resolve the requester ─────────────────────────────────────────────────────
$requesterQuery = new Requester_Query();
$requester      = $request->requester_id > 0 ? $requesterQuery->get_item( $request->requester_id ) : null;

// ── Resolve notes ─────────────────────────────────────────────────────────────
$noteQuery = new Prayer_Note_Query();
$notes     = $noteQuery->get_for_request( $requestId );

// ── Resolve prayed-for total ────────────────────────────────────────────────
// Shown here (and prayable from here) regardless of status, so private
// requests — never displayed on the public Prayer Wall — can still be
// prayed for and counted from wp-admin.
$prayedTotal = ( new Prayed_Count_Query() )->get_total_for_request( $requestId );

// ── Formatting helpers ────────────────────────────────────────────────────────
$dateFormat = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

$statusLabels = array(
	'pending'  => esc_html__( 'Pending',  'intercessor' ),
	'approved' => esc_html__( 'Approved', 'intercessor' ),
	'rejected' => esc_html__( 'Rejected', 'intercessor' ),
	'archived' => esc_html__( 'Archived', 'intercessor' ),
	'private'  => esc_html__( 'Private',  'intercessor' ),
);

$backUrl = admin_url( 'admin.php?page=intercessor-requests' );
?>
<div class="wrap intercessor-detail">

	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Prayer Request', 'intercessor' ); ?>
		<span class="intercessor-status <?php echo esc_attr( $request->status ); ?>">
			<?php echo esc_html( $statusLabels[ $request->status ] ?? ucfirst( $request->status ) ); ?>
		</span>
	</h1>

	<a href="<?php echo esc_url( $backUrl ); ?>" class="page-title-action">
		<?php esc_html_e( '&larr; All Requests', 'intercessor' ); ?>
	</a>

	<hr class="wp-header-end">

	<?php if ( $request->is_private_status() ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>
				<span class="ipr-icon ipr-icon-user ipr-icon-inline" aria-hidden="true"></span>
				<?php esc_html_e( 'Private Request', 'intercessor' ); ?>
			</strong>
				&mdash;
				<?php esc_html_e( 'This prayer request is marked private. It is hidden from all public displays, blocks, and REST API responses.', 'intercessor' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php // ── Flash notices ─────────────────────────────────────────────── ?>
	<?php if ( isset( $_GET['note_added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Note added.', 'intercessor' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['note_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Note deleted.', 'intercessor' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['note_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'An error occurred. Please try again.', 'intercessor' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Status updated.', 'intercessor' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="intercessor-detail-grid">

		<?php // ── Left column: request + moderation ───────────────────── ?>
		<div class="intercessor-detail-main">

			<?php // ── Request meta box ───────────────────────────────── ?>
			<div class="intercessor-box">
				<h2 class="intercessor-box__title">
					<span class="ipr-icon ipr-icon-praying ipr-icon-inline" aria-hidden="true"></span>
					<?php esc_html_e( 'Request Details', 'intercessor' ); ?>
				</h2>

				<table class="intercessor-detail-table">
					<tr>
						<th><?php esc_html_e( 'ID', 'intercessor' ); ?></th>
						<td><?php echo absint( $request->id ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Subject', 'intercessor' ); ?></th>
						<td><strong><?php echo esc_html( $request->subject ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'intercessor' ); ?></th>
						<td>
							<span class="intercessor-status <?php echo esc_attr( $request->status ); ?>">
								<?php echo esc_html( $statusLabels[ $request->status ] ?? ucfirst( $request->status ) ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Anonymous', 'intercessor' ); ?></th>
						<td><?php echo $request->is_anonymous() ? esc_html__( 'Yes', 'intercessor' ) : esc_html__( 'No', 'intercessor' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Public', 'intercessor' ); ?></th>
						<td><?php echo $request->is_public() ? esc_html__( 'Yes', 'intercessor' ) : esc_html__( 'No', 'intercessor' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Submitted', 'intercessor' ); ?></th>
						<td><?php echo esc_html( \Intercessor\Util\Local_Time::format( $dateFormat, $request->date_created ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Updated', 'intercessor' ); ?></th>
						<td><?php echo esc_html( \Intercessor\Util\Local_Time::format( $dateFormat, $request->date_modified ) ); ?></td>
					</tr>
				</table>

				<?php if ( $requester ) : ?>
					<h3><?php esc_html_e( 'Requester', 'intercessor' ); ?></h3>
					<table class="intercessor-detail-table">
						<tr>
							<th><?php esc_html_e( 'Name', 'intercessor' ); ?></th>
							<td>
								<?php if ( $request->is_anonymous() ) :
									// Show display name if user can 'manage_prayer_settings'.
									// Otherwise, show "[Anonymous]" for privacy.
									if ( current_user_can( 'manage_prayer_settings' ) ) { ?>
										<em><?php echo esc_html( $requester->get_display_name() ); ?></em>
									<?php }	else {	?>
									<em><?php esc_html_e( '[Anonymous]', 'intercessor' ); ?></em>
								<?php } ?>
								<?php else : ?>
									<?php echo esc_html( $requester->get_display_name() ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Email', 'intercessor' ); ?></th>
							<td><a href="mailto:<?php echo esc_attr( $requester->email ); ?>"><?php echo esc_html( $requester->email ); ?></a></td>
						</tr>
						<?php if ( $requester->wp_user_id > 0 ) : ?>
							<tr>
								<th><?php esc_html_e( 'WP User', 'intercessor' ); ?></th>
								<td>
									<?php
									$wpUser = get_user_by( 'id', $requester->wp_user_id );
									echo $wpUser ? esc_html( $wpUser->user_login ) : esc_html__( '[deleted]', 'intercessor' );
									?>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Prayer Content', 'intercessor' ); ?></h3>
				<div class="intercessor-request-body">
					<?php echo wp_kses_post( wpautop( $request->content ) ); ?>
				</div>

				<?php if ( $request->moderator_note !== '' ) : ?>
					<h3><?php esc_html_e( 'Moderator Note', 'intercessor' ); ?></h3>
					<div class="intercessor-moderator-note">
						<?php echo wp_kses_post( wpautop( $request->moderator_note ) ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php // ── Prayer activity box ────────────────────────────── ?>
			<div class="intercessor-box">
				<h2 class="intercessor-box__title">
					<span class="ipr-icon ipr-icon-praying ipr-icon-inline" aria-hidden="true"></span>
					<?php esc_html_e( 'Prayer Activity', 'intercessor' ); ?>
				</h2>

				<p class="intercessor-prayed-total">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of times this request has been prayed for */
							_n(
								'Prayed for %d time so far.',
								'Prayed for %d times so far.',
								$prayedTotal,
								'intercessor'
							),
							$prayedTotal
						)
					);
					?>
				</p>

				<?php if ( $request->is_private_status() ) : ?>
					<p class="description">
						<?php esc_html_e( 'This request is private and never appears on the public Prayer Wall, so it can only be prayed for here.', 'intercessor' ); ?>
					</p>
				<?php endif; ?>

				<button type="button" class="button intercessor-admin-pray-btn" data-request-id="<?php echo absint( $requestId ); ?>">
					<span class="ipr-icon ipr-icon-praying" aria-hidden="true"></span>
					<span class="intercessor-admin-pray-label"><?php esc_html_e( 'I prayed for this', 'intercessor' ); ?></span>
					<span class="intercessor-admin-pray-count"><?php echo absint( $prayedTotal ); ?></span>
				</button>
			</div>

			<?php // ── Moderation actions box ─────────────────────────── ?>
			<div class="intercessor-box">
				<h2 class="intercessor-box__title">
					<span class="ipr-icon ipr-icon-checkmark ipr-icon-inline" aria-hidden="true"></span>
					<?php esc_html_e( 'Change Status', 'intercessor' ); ?>
				</h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action"     value="intercessor_moderate">
					<input type="hidden" name="request_id" value="<?php echo absint( $request->id ); ?>">
					<?php wp_nonce_field( 'intercessor_moderate' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="new_status"><?php esc_html_e( 'New Status', 'intercessor' ); ?></label>
							</th>
							<td>
								<select name="new_status" id="new_status">
									<?php foreach ( $statusLabels as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $request->status, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="moderator_note"><?php esc_html_e( 'Moderator Note', 'intercessor' ); ?></label>
							</th>
							<td>
								<textarea name="moderator_note" id="moderator_note" rows="3" class="large-text"><?php echo esc_textarea( $request->moderator_note ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Internal note saved with this status change. Visible only to admins.', 'intercessor' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Update Status', 'intercessor' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

		</div>

		<?php // ── Right column: notes ───────────────────────────────────── ?>
		<div class="intercessor-detail-sidebar">

			<div class="intercessor-box intercessor-notes-box">

				<h2 class="intercessor-box__title">
					<span class="ipr-icon ipr-icon-user ipr-icon-inline" aria-hidden="true"></span>
					<?php esc_html_e( 'Internal Notes', 'intercessor' ); ?>
					<span class="intercessor-note-count"><?php echo absint( count( $notes ) ); ?></span>
				</h2>

				<?php // ── Existing notes list ─────────────────────────── ?>
				<?php if ( empty( $notes ) ) : ?>
					<p class="intercessor-notes-empty">
						<?php esc_html_e( 'No notes yet. Add the first one below.', 'intercessor' ); ?>
					</p>
				<?php else : ?>
					<ul class="intercessor-notes-list">
						<?php foreach ( $notes as $note ) :
							$noteAuthor = get_user_by( 'id', $note->author_user_id );
							$authorName = $noteAuthor ? $noteAuthor->display_name : esc_html__( 'Unknown', 'intercessor' );
						?>
							<li class="intercessor-note <?php echo esc_attr( $note->is_private() ? 'intercessor-note--private' : 'intercessor-note--shared' ); ?>">

								<div class="intercessor-note__meta">
									<span class="intercessor-note__author"><?php echo esc_html( $authorName ); ?></span>
									<span class="intercessor-note__date">
										<?php echo esc_html( \Intercessor\Util\Local_Time::format( $dateFormat, $note->date_created ) ); ?>
									</span>
									<?php if ( $note->is_private() ) : ?>
										<span class="intercessor-note__badge intercessor-note__badge--private">
											<?php esc_html_e( 'Private', 'intercessor' ); ?>
										</span>
									<?php else : ?>
										<span class="intercessor-note__badge intercessor-note__badge--shared">
											<?php esc_html_e( 'Shared', 'intercessor' ); ?>
										</span>
									<?php endif; ?>
								</div>

								<div class="intercessor-note__body">
									<?php echo wp_kses_post( wpautop( $note->content ) ); ?>
								</div>

								<div class="intercessor-note__actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										  onsubmit="return confirm('<?php echo esc_js( __( 'Delete this note? This cannot be undone.', 'intercessor' ) ); ?>')">
										<input type="hidden" name="action"     value="intercessor_delete_note">
										<input type="hidden" name="note_id"    value="<?php echo absint( $note->id ); ?>">
										<input type="hidden" name="request_id" value="<?php echo absint( $requestId ); ?>">
										<?php wp_nonce_field( 'intercessor_delete_note' ); ?>
										<button type="submit" class="button-link intercessor-note__delete">
											<?php esc_html_e( 'Delete', 'intercessor' ); ?>
										</button>
									</form>
								</div>

							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php // ── Add note form ────────────────────────────────── ?>
				<div class="intercessor-add-note">
					<h3><?php esc_html_e( 'Add Note', 'intercessor' ); ?></h3>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action"     value="intercessor_add_note">
						<input type="hidden" name="request_id" value="<?php echo absint( $requestId ); ?>">
						<?php wp_nonce_field( 'intercessor_add_note' ); ?>

						<div class="intercessor-add-note__field">
							<label for="note_content" class="screen-reader-text">
								<?php esc_html_e( 'Note content', 'intercessor' ); ?>
							</label>
							<textarea name="note_content" id="note_content" rows="4"
									  placeholder="<?php esc_attr_e( 'Write an internal note…', 'intercessor' ); ?>"
									  class="large-text" required></textarea>
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

		</div>

	</div>

</div>
