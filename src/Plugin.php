<?php
/**
 * Central plugin controller.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Admin_Loader;
use Intercessor\Block\Block_Loader;
use Intercessor\Http\Rest_Api;
use Intercessor\Public\Public_Loader;
use Intercessor\Util\Cron_Handler;
use Intercessor\Util\Registration_Handler;

/**
 * Central plugin controller — singleton.
 *
 * Responsible only for wiring top-level loaders together; detailed
 * behaviour lives in dedicated loader/manager classes. Instantiated
 * once from the main plugin bootstrap file via Plugin::instance()->boot().
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @since 1.0.0
	 * @var   self|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor — use Plugin::instance() instead.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Return the singleton Plugin instance, creating it if necessary.
	 *
	 * @since  1.0.0
	 * @return self
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire all top-level WordPress hooks.
	 *
	 * Called exactly once from the bootstrap file after requirements
	 * have been confirmed and activation hooks registered.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'init' ), 5 );
	}

	/**
	 * Initialise all plugin subsystems.
	 *
	 * Hooked to 'init' at priority 5 so database tables are registered
	 * before any code that may query them runs at default priority 10.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init(): void {
		// Runs first: cheaply detects an in-place version change and
		// re-syncs role capabilities if needed, before anything else checks
		// current_user_can() against them this request.
		Upgrader::maybe_upgrade();

		// Database tables are registered early so BerlinDB can install them.
		Database\Table_Registry::register();

		// Admin hooks are only needed in the admin area.
		if ( is_admin() ) {
			( new Admin_Loader() )->register();
		}

		// Public-facing hooks are needed on every page load.
		( new Public_Loader() )->register();
		( new Block_Loader() )->register();
		( new Rest_Api() )->register();
		( new Cron_Handler() )->register();

		// Email confirmation handler — fires on any page load that carries the
		// intercessor_confirm_email query parameter.
		add_action( 'init', array( Registration_Handler::class, 'handle_confirmation' ), 10 );
	}
}
