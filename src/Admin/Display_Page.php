<?php
/**
 * Settings page controller.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings\Registry;
use Intercessor\Admin\Settings\Repository;
use Intercessor\Admin\Settings\Sanitizer;
use Intercessor\Admin\Settings\Renderer;
use Intercessor\Http\Request;
/**
 * Owns the settings page schema, registration, and rendering for Intercessor.
 *
 * Responsibilities are split into two distinct methods so AdminLoader can
 * control them independently:
 *
 *   registerSettings() — called from Admin_Loader::register(). Builds the
 *     Registry, Repository, Sanitizer, and Renderer, then attaches the
 *     Renderer's admin_init hook (Settings API registration). Does NOT touch
 *     the admin menu.
 *
 *   render() — called as the admin_menu page callback registered in
 *     Admin_Loader::add_menu_pages(). Uses the Renderer that was populated by
 *     registerSettings(). AdminLoader stores this DisplayPage as a property
 *     so the same instance (and therefore the same Renderer) is used for
 *     both steps.
 *
 * This separation means there is exactly one owner of the 'intercessor-settings'
 * menu slug (AdminLoader) and exactly one Renderer instance per request,
 * preventing the double-registration and uninitialised-property bugs that
 * occurred when DisplayPage managed its own admin_menu hook.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Display_Page {

	/**
	 * WordPress option key for all Intercessor settings.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private const OPTION_KEY = 'intercessor_settings';

	/**
	 * Renderer instance populated by registerSettings() and used by render().
	 *
	 * @since 1.0.0
	 * @var   Renderer
	 */
	private Renderer $renderer;

	/**
	 * Build the settings subsystem and attach the WordPress Settings API hooks.
	 *
	 * Constructs Registry, Repository, Sanitizer, and Renderer from the schema
	 * returned by getSchema(), then calls Renderer::init() which hooks
	 * register_setting() and add_settings_{section,field}() onto admin_init.
	 *
	 * Does NOT register an admin_menu hook — menu registration is the
	 * exclusive responsibility of Admin_Loader::add_menu_pages().
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_settings(): void {
		$registry        = new Registry( $this->get_schema() );
		$repository      = new Repository( self::OPTION_KEY );
		$sanitizer       = new Sanitizer( $registry );
		$this->renderer  = new Renderer( $registry, $repository, $sanitizer );

		$this->renderer->init();
	}

	/**
	 * Render the full settings page HTML.
	 *
	 * Called by Admin_Loader::render_settings_page() using the shared instance
	 * that was previously initialised by registerSettings(). Reads the active
	 * tab from the 'tab' GET parameter and falls back to 'general'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_prayer_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'intercessor' ) );
		}

		$tab = Request::capture()->get_key( 'tab' ) ?: 'general';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Intercessor Settings', 'intercessor' ); ?></h1>

			<?php settings_errors( 'intercessor_settings' ); ?>

			<?php $this->renderer->render_tabs( $tab ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_KEY ); ?>

				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">

				<?php $this->renderer->render_tab_content( $tab ); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Define the full settings schema.
	 *
	 * Structure: $schema[ tab ][ section ] = [ 'title' => '...', 'fields' => [...] ]
	 *
	 * Each field array must contain at least 'id', 'type', and 'label'.
	 * The schema is consumed by Registry, which exposes it to Sanitizer
	 * (for type-based sanitisation) and Renderer (for Settings API registration
	 * and HTML output).
	 *
	 * @since  1.0.0
	 * @return array<string, array<string, array{title: string, fields: array}>>
	 */
	private function get_schema(): array {
		return [

			// ── General ──────────────────────────────────────────────────────
			'general' => [
				'approval' => [
					'title'  => esc_html__( 'Approval Rules', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'auto_approve',
							'label'   => esc_html__( 'Auto-Approve Requests', 'intercessor' ),
							'desc'    => esc_html__( 'Automatically approve all incoming prayer requests without manual review.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'require_login',
							'label'   => esc_html__( 'Require Login to Submit', 'intercessor' ),
							'desc'    => esc_html__( 'Only logged-in users may submit prayer requests.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'enable_registration',
							'label'   => esc_html__( 'Enable User Registration', 'intercessor' ),
							'desc'    => esc_html__( 'Allow new users to register and submit prayer requests.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'generate_username',
							'label'   => esc_html__( 'Generate Username for New Users', 'intercessor' ),
							'desc'    => esc_html__( 'Automatically generate a username for new user accounts.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'generate_password',
							'label'   => esc_html__( 'Generate Password for New Users', 'intercessor' ),
							'desc'    => esc_html__( 'Automatically generate a password for new user accounts.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'allow_anonymous',
							'label'   => esc_html__( 'Allow Anonymous Requests', 'intercessor' ),
							'desc'    => esc_html__( 'Allow submitters to hide their identity on public-facing displays.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'allow_private_requests',
							'label'   => esc_html__( 'Allow Private Requests', 'intercessor' ),
							'desc'    => esc_html__( 'Show a "Keep my prayer request private" checkbox on the submission form. Private requests are visible only to administrators and will never appear on the Prayer Wall.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'prevent_duplicate_requests',
							'label'   => esc_html__( 'Prevent Duplicate Requests', 'intercessor' ),
							'desc'    => esc_html__( 'Block a submitter from sending a new prayer request with the same subject as one they have already submitted.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'max_requests_per_day',
							'label'   => esc_html__( 'Max Requests Per Email / Day', 'intercessor' ),
							'desc'    => esc_html__( 'Maximum prayer requests one email address may submit in a 24-hour window. Set to 0 to disable rate limiting.', 'intercessor' ),
							'type'    => 'number',
							'default' => 3,
							'min'     => 0,
						],
					],
				],				
				'site_terms' => [
					'title'  => esc_html__( 'Site Terms', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'show_site_terms',
							'label'   => esc_html__( 'Show Site Terms', 'intercessor' ),
							'desc'    => esc_html__( 'Display site terms and conditions before submitting a prayer request.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'terms_label',
							'label'   => esc_html__( 'Terms Label', 'intercessor' ),
							'desc'    => esc_html__( 'The label for the site terms checkbox.', 'intercessor' ),
							'type'    => 'text',
							'default' => esc_html__( 'I agree to the site terms and conditions', 'intercessor' ),
						],
						[
							'id'      => 'terms_url',
							'label'   => esc_html__( 'Terms URL', 'intercessor' ),
							'desc'    => esc_html__( 'The URL for the site terms and conditions. Leave blank to hide the link.', 'intercessor' ),
							'type'    => 'text',
							'default' => '',
						],
						[
							'id'      => 'show_privacy_policy',
							'label'   => esc_html__( 'Show Privacy Policy', 'intercessor' ),
							'desc'    => esc_html__( 'Display a link to the privacy policy before submitting a prayer request.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'privacy_label',
							'label'   => esc_html__( 'Privacy Policy Label', 'intercessor' ),
							'desc'    => esc_html__( 'The label for the privacy policy checkbox.', 'intercessor' ),
							'type'    => 'text',
							'default' => esc_html__( 'I have read and accept the privacy policy', 'intercessor' ),
						],
						[
							'id'      => 'privacy_url',
							'label'   => esc_html__( 'Privacy Policy URL', 'intercessor' ),
							'desc'    => esc_html__( 'The URL for the privacy policy. Leave blank to hide the link.', 'intercessor' ),
							'type'    => 'text',
							'default' => get_privacy_policy_url(),
						],
						[
							'id'      => 'require_terms_acceptance',
							'label'   => esc_html__( 'Require Acceptance of Terms', 'intercessor' ),
							'desc'    => esc_html__( 'Users must check the site terms and privacy policy boxes before submitting a prayer request.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],	
					],
				],
			],

			// ── Moderation ────────────────────────────────────────────────────
			'moderation' => [
				'moderation_options' => [
					'title'  => esc_html__( 'Moderation Options', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'profanity_filter',
							'label'   => esc_html__( 'Enable Profanity Filter', 'intercessor' ),
							'desc'    => esc_html__( 'Flag requests containing prohibited words for manual review.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'profanity_words',
							'label'   => esc_html__( 'Prohibited Words', 'intercessor' ),
							'desc'    => esc_html__( 'Comma-separated list of words that trigger moderation.', 'intercessor' ),
							'type'    => 'textarea',
							'default' => '',
						],
						[
							'id'      => 'moderation_role',
							'label'   => esc_html__( 'Moderator Role', 'intercessor' ),
							'desc'    => esc_html__( 'Minimum WordPress role required to approve/reject requests.', 'intercessor' ),
							'type'    => 'select',
							'default' => 'editor',
							'options' => [
								'editor'        => esc_html__( 'Editor', 'intercessor' ),
								'administrator' => esc_html__( 'Administrator only', 'intercessor' ),
							],
						],
					],
				],
			],

			// ── Notifications ─────────────────────────────────────────────────
			'notifications' => [
				'email' => [
					'title'  => esc_html__( 'Email Notifications', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'notify_admin_new_request',
							'label'   => esc_html__( 'Notify Admin on New Request', 'intercessor' ),
							'desc'    => esc_html__( 'Send an email to the admin when a new prayer request is submitted.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'notify_requester_received',
							'label'   => esc_html__( 'Notify Requester on Receipt', 'intercessor' ),
							'desc'    => esc_html__( 'Send a confirmation email to the requester after submission.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'notify_requester_status_change',
							'label'   => esc_html__( 'Notify Requester on Status Change', 'intercessor' ),
							'desc'    => esc_html__( 'Email the requester when their request is approved or rejected.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'admin_email',
							'label'   => esc_html__( 'Admin Notification Email', 'intercessor' ),
							'desc'    => esc_html__( 'Defaults to the site admin email if left blank.', 'intercessor' ),
							'type'    => 'text',
							'default' => '',
						],
						[
							'id'      => 'email_from_name',
							'label'   => esc_html__( 'From Name', 'intercessor' ),
							'type'    => 'text',
							'default' => get_bloginfo( 'name' ),
						],
						[
							'id'      => 'email_from_address',
							'label'   => esc_html__( 'From Email Address', 'intercessor' ),
							'type'    => 'text',
							'default' => get_option( 'admin_email', '' ),
						],
					],
				],
				'cron_notifications' => [
					'title'  => esc_html__( 'Prayer-Count Notifications (Scheduled)', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'cron_notify_prayed',
							'label'   => esc_html__( 'Enable Prayer-Count Notifications', 'intercessor' ),
							'desc'    => esc_html__( 'Send requesters a scheduled email when their prayer request has been prayed for.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'cron_frequency',
							'label'   => esc_html__( 'Send Frequency', 'intercessor' ),
							'desc'    => esc_html__( 'How often to check for new prayers and dispatch notification emails.', 'intercessor' ),
							'type'    => 'select',
							'default' => 'daily',
							'options' => [
								'daily'   => esc_html__( 'Once a day',   'intercessor' ),
								'weekly'  => esc_html__( 'Once a week',  'intercessor' ),
								'monthly' => esc_html__( 'Once a month', 'intercessor' ),
							],
						],
						[
							'id'      => 'cron_send_hour',
							'label'   => esc_html__( 'Send Hour (UTC, 0–23)', 'intercessor' ),
							'desc'    => esc_html__( 'Hour of day (UTC) at which the notification job should run. Default: 8 (08:00 UTC).', 'intercessor' ),
							'type'    => 'number',
							'default' => 8,
							'min'     => 0,
							'max'     => 23,
						],
						[
							'id'      => 'cron_send_minute',
							'label'   => esc_html__( 'Send Minute (0–59)', 'intercessor' ),
							'desc'    => esc_html__( 'Minute past the hour at which the notification job should run. Default: 0.', 'intercessor' ),
							'type'    => 'number',
							'default' => 0,
							'min'     => 0,
							'max'     => 59,
						],
					],
				],
			],

			// ── Display ───────────────────────────────────────────────────────
			'display' => [
				'block_display' => [
					'title'  => esc_html__( 'Block & Shortcode Display', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'requests_per_page',
							'label'   => esc_html__( 'Requests Per Page', 'intercessor' ),
							'desc'    => esc_html__( 'Default number of prayer requests shown in the Prayer Wall block.', 'intercessor' ),
							'type'    => 'number',
							'default' => 10,
							'min'     => 1,
							'max'     => 100,
						],
						[
							'id'      => 'show_date',
							'label'   => esc_html__( 'Show Submission Date', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'show_requester_name',
							'label'   => esc_html__( 'Show Requester Name', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'date_format',
							'label'   => esc_html__( 'Date Format', 'intercessor' ),
							'desc'    => esc_html__( 'PHP date format string. Leave blank to use the WordPress site default.', 'intercessor' ),
							'type'    => 'text',
							'default' => '',
						],
					],
				],
			],

			// ── reCAPTCHA ─────────────────────────────────────────────────────
			'recaptcha' => [
				'recaptcha_keys' => [
					'title'  => esc_html__( 'API Keys', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'recaptcha_site_key',
							'label'   => esc_html__( 'Site Key (public)', 'intercessor' ),
							'desc'    => esc_html__( 'Obtain your keys from google.com/recaptcha/admin. Use the key that appears in HTML.', 'intercessor' ),
							'type'    => 'text',
							'default' => '',
						],
						[
							'id'      => 'recaptcha_secret_key',
							'label'   => esc_html__( 'Secret Key (private)', 'intercessor' ),
							'desc'    => esc_html__( 'Used for server-side verification. Never share or expose this key.', 'intercessor' ),
							'type'    => 'password',
							'default' => '',
						],
					],
				],
				'recaptcha_config' => [
					'title'  => esc_html__( 'Configuration', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'recaptcha_version',
							'label'   => esc_html__( 'reCAPTCHA Version', 'intercessor' ),
							'desc'    => esc_html__( 'v2 shows a checkbox widget. v3 is invisible and uses a score (0.0–1.0) to determine if the user is human.', 'intercessor' ),
							'type'    => 'select',
							'default' => 'v2',
							'options' => [
								'v2' => esc_html__( "v2 — Checkbox (\"I'm not a robot\")", 'intercessor' ),
								'v3' => esc_html__( 'v3 — Invisible (score-based)', 'intercessor' ),
							],
						],
						[
							'id'      => 'recaptcha_v3_threshold',
							'label'   => esc_html__( 'v3 Score Threshold', 'intercessor' ),
							'desc'    => esc_html__( "Minimum score (0.0–1.0) to accept a v3 submission. 0.5 is Google's recommended default. Higher values are stricter.", 'intercessor' ),
							'type'    => 'number',
							'default' => '0.5',
							'min'     => 0,
							'max'     => 1,
						],
					],
				],
				'recaptcha_forms' => [
					'title'  => esc_html__( 'Enable on Pages', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'recaptcha_enable_form',
							'label'   => esc_html__( 'Prayer Request Form', 'intercessor' ),
							'desc'    => esc_html__( 'Show reCAPTCHA on the Prayer Form block to protect against automated submissions.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
						[
							'id'      => 'recaptcha_enable_history',
							'label'   => esc_html__( 'Prayer History Page', 'intercessor' ),
							'desc'    => esc_html__( 'Load the reCAPTCHA script on pages containing the Prayer History block.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
					],
				],
			],

			// ── Export ────────────────────────────────────────────────────────
			'export' => [
				'export_options' => [
					'title'  => esc_html__( 'Export Options', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'export_include_content',
							'label'   => esc_html__( 'Include Prayer Content in Requests Export', 'intercessor' ),
							'desc'    => esc_html__( 'When checked, the full prayer request body is included in the Prayer Requests CSV.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => true,
						],
						[
							'id'      => 'export_status_filter',
							'label'   => esc_html__( 'Filter Requests Export by Status', 'intercessor' ),
							'desc'    => esc_html__( 'Leave as "All" to export all prayer requests regardless of status.', 'intercessor' ),
							'type'    => 'select',
							'default' => 'all',
							'options' => [
								'all'      => esc_html__( 'All Statuses', 'intercessor' ),
								'pending'  => esc_html__( 'Pending only', 'intercessor' ),
								'approved' => esc_html__( 'Approved only', 'intercessor' ),
								'rejected' => esc_html__( 'Rejected only', 'intercessor' ),
								'archived' => esc_html__( 'Archived only', 'intercessor' ),
							],
						],
						[
							'id'      => 'export_prayed_mode',
							'label'   => esc_html__( 'Prayed Counts Export Mode', 'intercessor' ),
							'desc'    => esc_html__( 'Aggregated: one row per request with total count. Detailed: one row per actor interaction.', 'intercessor' ),
							'type'    => 'select',
							'default' => 'aggregated',
							'options' => [
								'aggregated' => esc_html__( 'Aggregated (totals per request)', 'intercessor' ),
								'detailed'   => esc_html__( 'Detailed (per actor)', 'intercessor' ),
							],
						],
					],
				],
			],

			// ── Advanced ──────────────────────────────────────────────────────
			'advanced' => [
				'data' => [
					'title'  => esc_html__( 'Data Management', 'intercessor' ),
					'fields' => [
						[
							'id'      => 'delete_data_on_uninstall',
							'label'   => esc_html__( 'Delete All Data on Uninstall', 'intercessor' ),
							'desc'    => esc_html__( 'WARNING: When checked, all prayer requests, requesters, prayed counts, and settings will be permanently deleted when the plugin is removed.', 'intercessor' ),
							'type'    => 'checkbox',
							'default' => false,
						],
					],
				],
			],
		];
	}
}
