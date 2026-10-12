<?php
/**
 * Plugin Name:       Intercessor
 * Plugin URI:        https://wordpress.org/plugins/intercessor
 * Description:       The best free prayer request plugin for WordPress. Collect, manage, and organize prayer requests with moderation, privacy controls, reports, exports, imports, a prayer wall, and a prayer history page.
 * Version:           1.1.1
 * Requires at least: 6.3
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            Victor Aigbeghian
 * Author URI:        https://profiles.wordpress.org/shepherd365/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       intercessor
 * Domain Path:       /languages
 *
 * @package Intercessor
 */

declare(strict_types=1);

namespace Intercessor;

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------------
// Plugin constants.
// -----------------------------------------------------------------------------

/** @since 1.0.0 @var string Current plugin version. */
define( 'INTERCESSOR_VERSION',  '1.1.1' );

/** @since 1.0.0 @var string Absolute path to the main plugin file. */
define( 'INTERCESSOR_FILE',     __FILE__ );

/** @since 1.0.0 @var string Absolute path to the plugin directory, with trailing slash. */
define( 'INTERCESSOR_DIR',      plugin_dir_path( __FILE__ ) );

/** @since 1.0.0 @var string Public URL to the plugin directory, with trailing slash. */
define( 'INTERCESSOR_URL',      plugin_dir_url( __FILE__ ) );

/** @since 1.0.0 @var string Plugin basename, e.g. intercessor/intercessor.php. */
define( 'INTERCESSOR_BASENAME', plugin_basename( __FILE__ ) );

/** @since 1.0.0 @var string Minimum PHP version required to activate. */
define( 'INTERCESSOR_MIN_PHP',  '8.0' );

/** @since 1.0.0 @var string Minimum WordPress version required to activate. */
define( 'INTERCESSOR_MIN_WP',   '6.3' );

// -----------------------------------------------------------------------------
// Bundled vendor libraries — loaded before the autoloader so class_exists()
// checks in Requirements.php pass without Composer.
// -----------------------------------------------------------------------------
require_once INTERCESSOR_DIR . 'src/includes/berlindb/load.php';

// -----------------------------------------------------------------------------
// PSR-4 autoloader for the Intercessor\ namespace.
// -----------------------------------------------------------------------------
require_once INTERCESSOR_DIR . 'src/Util/Autoloader.php';

$intercessor_autoloader = new Util\Autoloader();
$intercessor_autoloader->register();

// -----------------------------------------------------------------------------
// Requirements gate — must pass before anything else loads.
// -----------------------------------------------------------------------------
$intercessor_requirements = new Requirements();

if ( ! $intercessor_requirements->met() ) {
	add_action( 'admin_notices', array( $intercessor_requirements, 'notice' ) );
	add_action(
		'admin_init',
		static function () use ( $intercessor_requirements ): void {
			if ( is_plugin_active( INTERCESSOR_BASENAME ) ) {
				deactivate_plugins( INTERCESSOR_BASENAME );
			}
		}
	);
	return;
}

// -----------------------------------------------------------------------------
// Activation / deactivation hooks.
// -----------------------------------------------------------------------------
register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

// -----------------------------------------------------------------------------
// Boot.
// -----------------------------------------------------------------------------
Plugin::instance()->boot();
