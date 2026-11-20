<?php
/**
 * Front-end template: Prayer Wall block.
 *
 * Variables provided by Prayer_Wall_Block::render():
 *
 * @var \Intercessor\Database\Row\Prayer_Request[] $items          Prayer request rows.
 * @var \Intercessor\Database\Query\Requester_Query $requesterQuery Query instance for name lookups.
 * @var bool   $showDate     Whether to display the submission date.
 * @var bool   $showAuthor   Whether to display the requester name.
 * @var int    $paged        Current page number.
 * @var int    $maxPages     Total number of pages.
 * @var int    $limit        Items per page.
 *
 * @package Intercessor
 * @since   1.0.0
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scoped variables included via require, not true globals

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings;
use Intercessor\Database\Query\Prayed_Count_Query;

$dateFormat  = Settings::get( 'date_format' ) ?: get_option( 'date_format' );
$countQuery  = new Prayed_Count_Query();

// Pass the prayer-wall interaction config via wp_add_inline_script().
// Guard with a flag so multiple Prayer_Wall blocks on one page only
// emit the config once.
if ( ! defined( 'INTERCESSOR_PRAY_CONFIG_PRINTED' ) ) {
	define( 'INTERCESSOR_PRAY_CONFIG_PRINTED', true );
	$prayConfig = wp_json_encode( array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'intercessor_record_prayer' ),
		'action'  => 'intercessor_record_prayer',
		'i18n'    => array(
			'praying' => __( 'Praying…',           'intercessor' ),
			'prayed'  => __( 'I prayed for this!', 'intercessor' ),
			'pray'    => __( 'I prayed for this',  'intercessor' ),
			'error'   => __( 'Could not record your prayer. Please try again.', 'intercessor' ),
		),
	) );
	wp_add_inline_script(
		'intercessor-public',
		'window.intercessorPray = ' . $prayConfig . ';',
		'before'
	);
}
?>
<div class="intercessor-prayer-wall wp-block-intercessor-prayer-wall">

	<?php if ( empty( $items ) ) : ?>
		<p class="intercessor-empty">
			<?php esc_html_e( 'No prayer requests found.', 'intercessor' ); ?>
		</p>
	<?php else : ?>

		<ul class="intercessor-request-list">
			<?php foreach ( $items as $item ) :
				$displayName = '';
				if ( $showAuthor && ! $item->is_anonymous() && $item->requester_id > 0 ) {
					$requester   = $requesterQuery->get_item( $item->requester_id );
					$displayName = $requester ? esc_html( $requester->get_display_name() ) : '';
				}
				$prayedTotal = $countQuery->get_total_for_request( $item->id );
			?>
				<li class="intercessor-request-item intercessor-status-<?php echo esc_attr( $item->status ); ?>">

					<h3 class="intercessor-request-subject">
						<?php echo esc_html( $item->subject ); ?>
					</h3>

					<div class="intercessor-request-meta">
						<?php if ( $showAuthor ) : ?>
							<span class="intercessor-request-author intercessor-meta-author">
								<?php if ( $displayName ) : ?>
									<?php printf(
										/* translators: %s: requester name */
										esc_html__( 'By %s', 'intercessor' ),
										esc_html( $displayName )
									); ?>
								<?php else : ?>
									<?php esc_html_e( 'Anonymous', 'intercessor' ); ?>
								<?php endif; ?>
							</span>
						<?php endif; ?>

						<?php if ( $showDate && $item->date_created ) : ?>
							<time class="intercessor-request-date"
								  datetime="<?php echo esc_attr( $item->date_created ); ?>">
								<?php echo esc_html( \Intercessor\Util\Local_Time::format( $dateFormat, $item->date_created ) ); ?>
							</time>
						<?php endif; ?>
					</div>

					<div class="intercessor-request-content">
						<?php echo wp_kses_post( wpautop( $item->content ) ); ?>
					</div>

					<?php // ── "I prayed for this" button ───────────────── ?>
					<div class="intercessor-pray-action">
						<button
							class="intercessor-pray-btn"
							data-request-id="<?php echo absint( $item->id ); ?>"
							aria-label="<?php esc_attr_e( 'I prayed for this request', 'intercessor' ); ?>"
						>
							<span class="intercessor-pray-icon ipr-icon ipr-icon-praying" aria-hidden="true"></span>
							<span class="intercessor-pray-label">
								<?php esc_html_e( 'I prayed for this', 'intercessor' ); ?>
							</span>
							<span class="intercessor-pray-count" aria-label="<?php esc_attr_e( 'prayers', 'intercessor' ); ?>">
								<?php echo absint( $prayedTotal ); ?>
							</span>
						</button>
					</div>

				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $maxPages > 1 ) : ?>
			<nav class="intercessor-pagination"
				 aria-label="<?php esc_attr_e( 'Prayer requests navigation', 'intercessor' ); ?>">

				<?php if ( $paged > 1 ) : ?>
					<a class="intercessor-page-link"
					   href="<?php echo esc_url( add_query_arg( 'ipage', $paged - 1 ) ); ?>">
						&laquo; <?php esc_html_e( 'Previous', 'intercessor' ); ?>
					</a>
				<?php endif; ?>

				<span class="intercessor-page-info">
					<?php printf(
						/* translators: 1: current page 2: total pages */
						esc_html__( 'Page %1$d of %2$d', 'intercessor' ),
						(int) $paged,
						(int) $maxPages
					); ?>
				</span>

				<?php if ( $paged < $maxPages ) : ?>
					<a class="intercessor-page-link"
					   href="<?php echo esc_url( add_query_arg( 'ipage', $paged + 1 ) ); ?>">
						<?php esc_html_e( 'Next', 'intercessor' ); ?> &raquo;
					</a>
				<?php endif; ?>

			</nav>
		<?php endif; ?>

	<?php endif; ?>

</div>
