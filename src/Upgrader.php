<?php
/**
 * Plugin upgrade handler.
 *
 * @package Intercessor
 * @since   1.1.1
 */
declare(strict_types=1);

namespace Intercessor;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Requester_Query;
use Intercessor\Util\Registration_Handler;

/**
 * Detects an in-place version change and re-runs the tasks that
 * Activator::activate() normally handles, but which only fire on
 * activation — not on a routine plugin update.
 *
 * Activator::activate() calls Roles::add_caps() once, at activation. On a
 * site that updates the plugin without deactivating it first (the common
 * case — WordPress auto-updates and one-click "Update Now" both do this),
 * add_caps() never runs again. Roles::add_cap() also only ever *adds*
 * capabilities, so even if it did re-run, a capability removed from the
 * code (e.g. prayer_warrior losing edit_prayers, then view/export_reports)
 * would remain stuck on existing installs indefinitely.
 *
 * This class closes that gap: on every request, cheaply compare the
 * version stored in options against the version of the code that's
 * actually running, and when they differ, fully re-sync capabilities
 * (strip every custom capability from every role, then re-add exactly the
 * current set) before recording the new version. remove_caps() followed
 * by add_caps() is what correctly removes a stale capability — add_caps()
 * alone cannot.
 *
 * @since   1.1.1
 * @package Intercessor
 */
final class Upgrader {

	/**
	 * Option name used to track the last version that ran its upgrade tasks.
	 *
	 * Shared with Activator, which writes the same option on activation so
	 * a fresh install doesn't immediately trip an "upgrade" on its first
	 * request.
	 *
	 * @since 1.1.1
	 * @var   string
	 */
	private const VERSION_OPTION = 'intercessor_version';

	/**
	 * Re-sync plugin state if the running code is a different version than
	 * what's recorded in the database.
	 *
	 * Hooked to run early on 'init'. The get_option() call is a single,
	 * typically autoloaded row, so the common case (nothing to do) is cheap
	 * enough to check on every request.
	 *
	 * @since  1.1.1
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::VERSION_OPTION, '' );

		if ( $installed_version === INTERCESSOR_VERSION ) {
			return;
		}

		self::upgrade( $installed_version );

		update_option( self::VERSION_OPTION, INTERCESSOR_VERSION );
	}

	/**
	 * Run the actual upgrade tasks.
	 *
	 * Currently just a full capability re-sync, which is sufficient to fix
	 * up any role/capability drift between the version an install last ran
	 * and the version now active — including the prayer_warrior fixes in
	 * 1.1.1, and any future capability change without needing a new
	 * version-specific branch here.
	 *
	 * $installed_version is accepted (rather than reading the option again)
	 * so a future version-specific migration — one that should only run
	 * when coming from an older release, e.g. a one-time data backfill —
	 * has what it needs to add a version_compare() check without another
	 * database read.
	 *
	 * @since  1.1.1
	 * @param  string $installed_version Version string previously recorded,
	 *                                   or '' if this is a fresh activation
	 *                                   with no recorded version yet.
	 * @return void
	 */
	private static function upgrade( string $installed_version ): void {
		// Roles::remove_caps() followed by Roles::add_caps() re-syncs every
		// custom capability on every custom role to exactly what the
		// currently running code declares — removing anything stale and
		// re-adding anything missing. Both methods manage $wp_roles
		// themselves and are safe to call unconditionally.
		Roles::remove_caps();
		Roles::add_caps();

		// One-time tasks for installs coming from a release before 1.1.3.
		// A fresh activation records its version in Activator and never
		// reaches here with an empty string that has data to migrate.
		if ( $installed_version !== '' && version_compare( $installed_version, '1.1.3', '<' ) ) {
			self::end_sessions_of_unconfirmed_accounts();

			// Repair requester rows that were never linked to the account
			// created alongside them. Runs after the sessions above are
			// ended: linking a row to a still-pending account is only safe
			// once that account can no longer be logged in.
			( new Requester_Query() )->link_unlinked_to_matching_users();
		}
	}

	/**
	 * Log out accounts that registered through the prayer form but never
	 * confirmed their email address.
	 *
	 * Before 1.1.3 the pending flag was not checked at login, so such an
	 * account may hold a valid session. From 1.1.3 a pending account cannot
	 * log in; ending existing sessions makes that true for accounts that are
	 * already logged in as well.
	 *
	 * @since  1.1.3
	 * @return void
	 */
	private static function end_sessions_of_unconfirmed_accounts(): void {
		$user_ids = get_users( array(
			'meta_key' => Registration_Handler::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'   => 'ids',
			'number'   => -1,
		) );

		foreach ( $user_ids as $user_id ) {
			\WP_Session_Tokens::get_instance( (int) $user_id )->destroy_all();
		}
	}
}
