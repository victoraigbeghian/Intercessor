<?php
/**
 * Plugin uninstall routine.
 *
 * Runs when a user deletes the plugin from the WordPress Plugins screen.
 * No plugin classes are available in this context.
 *
 * Data removal is opt-in: tables are dropped only when 'delete_data_on_uninstall'
 * was enabled in settings before deletion.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Retrieve settings before deletion to check the data-removal flag.
$intercessor_settings = get_option( 'intercessor_settings', array() );

// Remove all plugin options unconditionally.
delete_option( 'intercessor_version' );
delete_option( 'intercessor_settings' );

// Remove per-table schema version options written by the BerlinDB Table layer.
delete_option( 'intercessor_prayer_requests_db_version' );
delete_option( 'intercessor_requesters_db_version' );
delete_option( 'intercessor_prayer_history_db_version' );
delete_option( 'intercessor_prayer_notes_db_version' );
delete_option( 'intercessor_prayed_counts_db_version' );
delete_option( 'intercessor_requester_notes_db_version' );

// Remove the six custom prayer capabilities from all roles, then remove
// the three custom roles (prayer_manager, prayer_warrior, requester).
// Use $wp_roles directly — plugin classes are not available in uninstall.php.
global $wp_roles;
if ( ! isset( $wp_roles ) ) {
	$wp_roles = new WP_Roles();
}

$intercessor_prayer_caps = array(
	'edit_prayers',
	'manage_prayer_settings',
	'view_prayer_reports',
	'export_prayer_reports',
	'view_prayer_sensitive_data',
	'read_private_prayers',
);

$intercessor_cap_bearing_roles = array(
	'administrator',
	'prayer_manager',
	'prayer_warrior',
	'requester',
);

foreach ( $intercessor_cap_bearing_roles as $intercessor_role_slug ) {
	foreach ( $intercessor_prayer_caps as $intercessor_cap ) {
		$wp_roles->remove_cap( $intercessor_role_slug, $intercessor_cap );
	}
}

remove_role( 'prayer_manager' );
remove_role( 'prayer_warrior' );
remove_role( 'requester' );

// Drop tables only when the site owner explicitly opted in.
if ( ! empty( $intercessor_settings['delete_data_on_uninstall'] ) ) {
	// Drop in reverse dependency order: child tables before parent tables.
	$intercessor_tables = array(
		$wpdb->prefix . 'intercessor_prayed_counts',
		$wpdb->prefix . 'intercessor_requester_notes',
		$wpdb->prefix . 'intercessor_prayer_notes',
		$wpdb->prefix . 'intercessor_prayer_history',
		$wpdb->prefix . 'intercessor_prayer_requests',
		$wpdb->prefix . 'intercessor_requesters',
	);

	foreach ( $intercessor_tables as $intercessor_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$intercessor_table}" );
	}
}
