<?php
/**
 * Front-end template: User Prayer History block.
 *
 * Displays all prayer requests belonging to the currently logged-in user,
 * with inline edit and delete actions. Rendered by Prayer_History_Block::render()
 * when the user is authenticated and a requester record exists for them.
 *
 * Variables provided by Prayer_History_Block::render():
 *
 * @var \Intercessor\Database\Row\Prayer_Request[] $items          User's prayer requests (all statuses).
 * @var \Intercessor\Database\Query\Prayed_Count_Query $countQuery Prayed-count query instance.
 *
 * @package Intercessor
 * @since   1.1.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$dateFormat = \Intercessor\Admin\Settings::get( 'date_format' ) ?: get_option( 'date_format' );

/**
 * Map a status slug to a human-readable label.
 *
 * @param string $status Raw status slug from the database.
 * @return string Translated label.
 */
$status_label = static function ( string $status ): string {
	$labels = array(
		'pending'  => esc_html__( 'Pending review', 'intercessor' ),
		'approved' => esc_html__( 'Approved', 'intercessor' ),
		'rejected' => esc_html__( 'Rejected', 'intercessor' ),
		'archived' => esc_html__( 'Archived', 'intercessor' ),
		'private'  => esc_html__( 'Private', 'intercessor' ),
	);
	return $labels[ $status ] ?? ucfirst( $status );
};
?>
<div class="intercessor-user-history wp-block-intercessor-prayer-history">

	<?php if ( empty( $items ) ) : ?>

		<div class="intercessor-user-history__empty">
			<span class="intercessor-user-history__empty-icon" aria-hidden="true"></span>
			<p class="intercessor-user-history__empty-text">
				<?php esc_html_e( "You haven't submitted any prayer requests yet.", 'intercessor' ); ?>
			</p>
		</div>

	<?php else : ?>

		<div class="intercessor-user-history__header">
			<h2 class="intercessor-user-history__title">
				<?php esc_html_e( 'Your Prayer Requests', 'intercessor' ); ?>
			</h2>
			<span class="intercessor-user-history__count">
				<?php echo absint( count( $items ) ); ?>
			</span>
		</div>

		<div class="intercessor-user-history__notice" aria-live="polite"></div>

		<div class="intercessor-user-history__list">

			<?php foreach ( $items as $item ) :
				$prayed_total = $countQuery->get_total_for_request( $item->id );
				$item_date    = $item->date_created
					? \Intercessor\Util\Local_Time::format( $dateFormat, $item->date_created )
					: '';
			?>

			<div class="ipr-user-row intercessor-user-history__card intercessor-status-<?php echo esc_attr( $item->status ); ?>">

				<div class="intercessor-user-history__card-accent"></div>

				<div class="intercessor-user-history__card-body">

					<div class="intercessor-user-history__card-head">
						<p class="ipr-row-subject intercessor-user-history__subject">
							<?php echo esc_html( $item->subject ); ?>
						</p>
						<span class="ipr-status-badge intercessor-status-<?php echo esc_attr( $item->status ); ?>">
							<?php echo esc_html( $status_label( $item->status ) ); ?>
						</span>
					</div>

					<div class="intercessor-user-history__meta">
						<?php if ( $item_date ) : ?>
						<span class="intercessor-user-history__meta-item">
							<span class="intercessor-user-history__meta-icon" aria-hidden="true">&#128197;</span>
							<time datetime="<?php echo esc_attr( $item->date_created ); ?>">
								<?php echo esc_html( $item_date ); ?>
							</time>
						</span>
						<?php endif; ?>
						<span class="intercessor-user-history__meta-item">
							<span class="intercessor-user-history__meta-icon ipr-meta-praying" aria-hidden="true"></span>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of people who prayed */
									_n( '%d person prayed', '%d people prayed', $prayed_total, 'intercessor' ),
									$prayed_total
								)
							);
							?>
						</span>
					</div>

					<p class="ipr-row-content intercessor-user-history__excerpt">
						<?php echo esc_html( wp_trim_words( $item->content, 25, '…' ) ); ?>
					</p>

					<?php // ── Inline edit form (hidden until Edit is clicked) ── ?>
					<div class="ipr-edit-form intercessor-user-history__edit-form" hidden>
						<form class="ipr-update-form">
							<input type="hidden" name="request_id" value="<?php echo absint( $item->id ); ?>" />

							<div class="intercessor-user-history__edit-field">
								<label for="ipr-subject-<?php echo absint( $item->id ); ?>">
									<?php esc_html_e( 'Subject', 'intercessor' ); ?>
								</label>
								<input
									type="text"
									id="ipr-subject-<?php echo absint( $item->id ); ?>"
									name="subject"
									class="intercessor-input"
									value="<?php echo esc_attr( $item->subject ); ?>"
									required
								/>
							</div>

							<div class="intercessor-user-history__edit-field">
								<label for="ipr-content-<?php echo absint( $item->id ); ?>">
									<?php esc_html_e( 'Prayer Request', 'intercessor' ); ?>
								</label>
								<textarea
									id="ipr-content-<?php echo absint( $item->id ); ?>"
									name="content"
									class="intercessor-input"
									rows="4"
									required
								><?php echo esc_textarea( $item->content ); ?></textarea>
							</div>

							<p class="intercessor-user-history__edit-notice">
								<?php esc_html_e( 'Your request will be sent back for review after saving.', 'intercessor' ); ?>
							</p>

							<p class="ipr-form-msg" role="status" aria-live="polite"></p>

							<div class="intercessor-user-history__edit-actions">
								<button type="submit" class="wp-element-button intercessor-submit">
									<?php esc_html_e( 'Save Changes', 'intercessor' ); ?>
								</button>
							</div>
						</form>
					</div>

					<div class="intercessor-user-history__actions">
						<button
							type="button"
							class="intercessor-user-history__action-btn intercessor-user-history__action-btn--edit"
							data-ipr-action="edit"
						>
							<?php esc_html_e( 'Edit', 'intercessor' ); ?>
						</button>
						<button
							type="button"
							class="intercessor-user-history__action-btn intercessor-user-history__action-btn--delete"
							data-ipr-action="delete"
							data-request-id="<?php echo absint( $item->id ); ?>"
						>
							<?php esc_html_e( 'Delete', 'intercessor' ); ?>
						</button>
					</div>

				</div>

			</div>

			<?php endforeach; ?>

		</div>

	<?php endif; ?>

</div>
