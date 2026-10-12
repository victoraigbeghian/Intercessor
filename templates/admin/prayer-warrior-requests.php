<?php
/**
 * Admin template: prayer requests list for prayer warriors.
 *
 * Rendered by Admin_Loader::render_private_requests_page(). $table is a
 * Prayer_Request_List_Table in MODE_WARRIOR, already prepared.
 *
 * This view is intentionally read-only: there is no "Add Prayer Request"
 * modal, no bulk-action form, no search box, and no links to the request
 * detail or requester screens. The only interaction is the
 * "I prayed for this" button, handled via AJAX by admin.js.
 *
 * @var \Intercessor\Admin\Prayer_Request_List_Table $table
 *
 * @package Intercessor
 * @since   1.1.3
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scoped variables included via require, not true globals

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'read_private_prayers' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'intercessor' ) );
}
?>
<div class="wrap intercessor-warrior-requests">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Prayer Requests', 'intercessor' ); ?></h1>

	<hr class="wp-header-end">

	<p class="description">
		<?php esc_html_e( 'Approved and private prayer requests. Requester contact details and the names of anonymous requesters are hidden. Use "I prayed for this" to record your prayer.', 'intercessor' ); ?>
	</p>

	<?php $table->views(); ?>

	<?php $table->display(); ?>

</div>
