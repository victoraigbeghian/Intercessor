<?php
/**
 * Plugin roles and capabilities manager.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the three custom WordPress roles and six custom
 * capabilities used throughout the Intercessor plugin.
 *
 * Roles
 * ─────
 * prayer_manager  Full prayer-management access. Equivalent to an editor with
 *                 all five custom prayer caps. Intended for staff who manage
 *                 the prayer ministry but should not have full WP admin access.
 *
 * prayer_warrior  Minimal WP access. Can see prayer requests marked private,
 *                 but cannot view reports, export data, or moderate prayer
 *                 requests in any way. Intended for trusted volunteers whose
 *                 role is limited to praying, not managing or reviewing data.
 *
 * requester       Minimal WP access (read only). Assigned automatically when a
 *                 guest prayer submitter is registered as a WP user. No prayer
 *                 management capabilities.
 *
 * Custom capabilities
 * ───────────────────
 * edit_prayers                Create, edit, moderate, and delete prayer requests
 *                             and requesters. The core operational capability.
 *
 * manage_prayer_settings      Access and save the Settings page, Tools page, and
 *                             import/upgrade actions.
 *
 * view_prayer_reports         View the Requesters list, Reports, and dashboard
 *                             stats widgets.
 *
 * export_prayer_reports       Export data to CSV from the Tools page.
 *
 * view_prayer_sensitive_data  Reserved for future use: see private/sensitive
 *                             requester data. Currently defined but not checked.
 *
 * read_private_prayers        Reserved for future use: see prayer requests with
 *                             'private' status. Currently defined but not checked.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Roles {

	// ── Role slugs ────────────────────────────────────────────────────────────

	/** @var string Prayer Manager role slug. */
	public const ROLE_PRAYER_MANAGER = 'prayer_manager';

	/** @var string Prayer Warrior role slug. */
	public const ROLE_PRAYER_WARRIOR = 'prayer_warrior';

	/** @var string Requester role slug. */
	public const ROLE_REQUESTER = 'requester';

	// ── Capability constants ──────────────────────────────────────────────────

	/** @var string Core operational capability: moderate and manage prayers. */
	public const CAP_EDIT_PRAYERS = 'edit_prayers';

	/** @var string Access settings, tools, and import pages. */
	public const CAP_MANAGE_SETTINGS = 'manage_prayer_settings';

	/** @var string View requesters list, reports, and dashboard stats. */
	public const CAP_VIEW_REPORTS = 'view_prayer_reports';

	/** @var string Export CSV data from the Tools page. */
	public const CAP_EXPORT_REPORTS = 'export_prayer_reports';

	/** @var string Reserved: see private requester data. */
	public const CAP_VIEW_SENSITIVE = 'view_prayer_sensitive_data';

	/** @var string Reserved: see private-status prayer requests. */
	public const CAP_READ_PRIVATE_PRAYERS = 'read_private_prayers';

	// ── Role creation ─────────────────────────────────────────────────────────

	/**
	 * Register the three custom roles.
	 *
	 * Safe to call multiple times — add_role() is a no-op when the role
	 * already exists (WordPress checks $wp_roles->roles before inserting).
	 *
	 * Called during plugin activation.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function add_roles(): void {
		// ── Prayer Manager ────────────────────────────────────────────────────
		// Full editor-level WP capabilities so the role can manage content,
		// users (list only), and uploads, without requiring the administrator
		// role. Custom caps are added separately via add_caps().
		add_role(
			self::ROLE_PRAYER_MANAGER,
			__( 'Prayer Manager', 'intercessor' ),
			array(
				'read'                   => true,
				'read_private_pages'     => true,
				'read_private_posts'     => true,
				'edit_users'             => true,
				'edit_posts'             => true,
				'edit_pages'             => true,
				'edit_published_posts'   => true,
				'edit_published_pages'   => true,
				'edit_private_pages'     => true,
				'edit_private_posts'     => true,
				'edit_others_posts'      => true,
				'edit_others_pages'      => true,
				'publish_posts'          => true,
				'publish_pages'          => true,
				'delete_posts'           => true,
				'delete_pages'           => true,
				'delete_private_pages'   => true,
				'delete_private_posts'   => true,
				'delete_published_pages' => true,
				'delete_published_posts' => true,
				'delete_others_posts'    => true,
				'delete_others_pages'    => true,
				'manage_categories'      => true,
				'manage_links'           => true,
				'moderate_comments'      => true,
				'upload_files'           => true,
				'export'                 => true,
				'import'                 => true,
				'list_users'             => true,
				// Level caps (required by some core WP checks).
				'level_9'                => true,
				'level_8'                => true,
				'level_7'                => true,
				'level_6'                => true,
				'level_5'                => true,
				'level_4'                => true,
				'level_3'                => true,
				'level_2'                => true,
				'level_1'                => true,
				'level_0'                => true,
			)
		);

		// ── Prayer Warrior ────────────────────────────────────────────────────
		// Read-only WP access. Cannot create, edit, or publish any content.
		// Trusted volunteers who help pray and may view/export data.
		// Custom prayer caps added separately via add_caps().
		add_role(
			self::ROLE_PRAYER_WARRIOR,
			__( 'Prayer Warrior', 'intercessor' ),
			array(
				'read'         => true,
				'edit_posts'   => false,
				'delete_posts' => false,
			)
		);

		// ── Requester ─────────────────────────────────────────────────────────
		// Minimal WP account assigned to guest prayer submitters who are
		// auto-registered. No prayer management capabilities.
		add_role(
			self::ROLE_REQUESTER,
			__( 'Requester', 'intercessor' ),
			array(
				'read' => true,
			)
		);
	}

	// ── Capability assignment ─────────────────────────────────────────────────

	/**
	 * Add the six custom prayer capabilities to the appropriate roles.
	 *
	 * Uses $wp_roles->add_cap() which writes directly to the database,
	 * so this is idempotent — calling it multiple times is safe.
	 *
	 * Capability matrix:
	 *
	 *   Capability                 administrator  prayer_manager  prayer_warrior
	 *   ─────────────────────────────────────────────────────────────────────────
	 *   edit_prayers               ✓              ✓               —
	 *   manage_prayer_settings     ✓              ✓               —
	 *   view_prayer_reports        ✓              ✓               —
	 *   export_prayer_reports      ✓              ✓               —
	 *   view_prayer_sensitive_data ✓              ✓               —
	 *   read_private_prayers       —              —                ✓
	 *
	 * Called during plugin activation.
	 *
	 * @since  1.0.0
	 * @since  1.1.1 prayer_warrior no longer receives edit_prayers. It was
	 *               granted the single capability that gates every
	 *               moderation action (can_moderate() checks edit_prayers),
	 *               which contradicted this role's own "read-only" /
	 *               "trusted volunteer" description above and gave it the
	 *               same operational power as prayer_manager.
	 * @return void
	 */
	public static function add_caps(): void {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}

		// ── administrator ─────────────────────────────────────────────────────
		$wp_roles->add_cap( 'administrator', self::CAP_EDIT_PRAYERS );
		$wp_roles->add_cap( 'administrator', self::CAP_MANAGE_SETTINGS );
		$wp_roles->add_cap( 'administrator', self::CAP_VIEW_REPORTS );
		$wp_roles->add_cap( 'administrator', self::CAP_EXPORT_REPORTS );
		$wp_roles->add_cap( 'administrator', self::CAP_VIEW_SENSITIVE );

		// ── prayer_manager ────────────────────────────────────────────────────
		$wp_roles->add_cap( self::ROLE_PRAYER_MANAGER, self::CAP_EDIT_PRAYERS );
		$wp_roles->add_cap( self::ROLE_PRAYER_MANAGER, self::CAP_MANAGE_SETTINGS );
		$wp_roles->add_cap( self::ROLE_PRAYER_MANAGER, self::CAP_VIEW_REPORTS );
		$wp_roles->add_cap( self::ROLE_PRAYER_MANAGER, self::CAP_EXPORT_REPORTS );
		$wp_roles->add_cap( self::ROLE_PRAYER_MANAGER, self::CAP_VIEW_SENSITIVE );

		// ── prayer_warrior ────────────────────────────────────────────────────
		$wp_roles->add_cap( self::ROLE_PRAYER_WARRIOR, self::CAP_READ_PRIVATE_PRAYERS );
	}

	/**
	 * Remove all custom prayer capabilities from all roles.
	 *
	 * Called during plugin uninstall (not deactivation — roles persist until
	 * the plugin is actually deleted, matching the old plugin's behaviour).
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function remove_caps(): void {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}

		$all_caps = array(
			self::CAP_EDIT_PRAYERS,
			self::CAP_MANAGE_SETTINGS,
			self::CAP_VIEW_REPORTS,
			self::CAP_EXPORT_REPORTS,
			self::CAP_VIEW_SENSITIVE,
			self::CAP_READ_PRIVATE_PRAYERS,
		);

		$all_roles = array(
			'administrator',
			self::ROLE_PRAYER_MANAGER,
			self::ROLE_PRAYER_WARRIOR,
			self::ROLE_REQUESTER,
		);

		foreach ( $all_roles as $role ) {
			foreach ( $all_caps as $cap ) {
				$wp_roles->remove_cap( $role, $cap );
			}
		}
	}

	// ── Role removal ─────────────────────────────────────────────────────────

	/**
	 * Remove the three custom roles from WordPress.
	 *
	 * Called during plugin uninstall. remove_role() is a no-op for roles
	 * that do not exist, so it is safe to call unconditionally.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function remove_roles(): void {
		remove_role( self::ROLE_PRAYER_MANAGER );
		remove_role( self::ROLE_PRAYER_WARRIOR );
		remove_role( self::ROLE_REQUESTER );
	}
}
