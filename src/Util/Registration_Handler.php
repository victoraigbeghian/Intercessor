<?php
/**
 * User registration handler for prayer form account creation.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Util;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Admin\Settings;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Roles;

/**
 * Handles optional account creation when a guest submits a prayer request.
 *
 * When the "Create an account?" checkbox is selected on the prayer form,
 * this class:
 *
 *  1. Validates username uniqueness and password match.
 *  2. Creates a WordPress user with role 'requester' and status 'pending'
 *     (via the user_status column — 0 = active, 2 = pending, not yet a
 *     built-in WP concept; we store our own meta flag as well).
 *  3. Generates a time-limited confirmation token stored in user meta.
 *  4. Sends the user an email with a confirmation link.
 *  5. On confirmation link click, removes the pending flag and marks the
 *     account active.
 *
 * The pending state means the WordPress account exists but is explicitly
 * labelled as unconfirmed. Intercessor does not restrict WP login for
 * pending users — that is a hosting/site-level concern. The flag is
 * advisory for the Intercessor admin UI.
 *
 * Token format: 64-character hex string stored under the meta key
 * '_intercessor_confirm_token'. A separate meta key
 * '_intercessor_confirm_expiry' holds the Unix timestamp of expiry (48 h).
 *
 * Confirmation URL query parameter: intercessor_confirm_email={token}
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Registration_Handler {

	// ── Constants ─────────────────────────────────────────────────────────────

	/** @var string User meta key for the pending-confirmation flag. */
	public const META_PENDING  = '_intercessor_registration_pending';

	/** @var string User meta key for the confirmation token. */
	public const META_TOKEN    = '_intercessor_confirm_token';

	/** @var string User meta key for the token expiry timestamp. */
	public const META_EXPIRY   = '_intercessor_confirm_expiry';

	/** @var string User meta key for the confirmation source page URL. */
	public const META_SOURCE_URL = '_intercessor_confirm_source_url';

	/** @var int Token lifetime in seconds (48 hours). */
	private const TOKEN_TTL = 172800;

	// ── Public entry points ───────────────────────────────────────────────────

	/**
	 * Attempt to create a pending WordPress account after a prayer request
	 * has been successfully submitted.
	 *
	 * Returns silently (with no side effects) when:
	 *   - enable_registration is off in settings.
	 *   - The form did not request an account (create_account was not sent).
	 *   - The email already belongs to a WordPress user.
	 *
	 * On validation failure the method returns an array of error strings;
	 * the caller must decide whether to surface these (the prayer request is
	 * already saved at this point, so the user is informed their prayer was
	 * received but account creation failed).
	 *
	 * @since  1.0.0
	 * @param  string $email      Sanitized email from the prayer form.
	 * @param  string $first_name First name.
	 * @param  string $last_name  Last name.
	 * @param  array  $post_data  Raw $_POST input (via Request::capture()).
	 * @return array              Empty array on success or skip; error strings on failure.
	 */
	public static function maybe_create_account(
		string $email,
		string $first_name,
		string $last_name,
		array  $post_data
	): array {
		// Guard: setting must be on.
		if ( ! Settings::get( 'enable_registration', false ) ) {
			return array();
		}

		// Guard: user did not tick the checkbox.
		if ( empty( $post_data['create_account'] ) ) {
			return array();
		}

		// Guard: email already has a WP account — silently skip.
		if ( email_exists( $email ) ) {
			return array( __( 'An account with that email already exists. Your prayer request was saved.', 'intercessor' ) );
		}

		$generate_username = (bool) Settings::get( 'generate_username', false );
		$generate_password = (bool) Settings::get( 'generate_password', false );

		// ── Resolve username ──────────────────────────────────────────────────
		if ( $generate_username ) {
			$username = self::generate_unique_username( $email, $first_name, $last_name );
		} else {
			$username = sanitize_user( wp_unslash( $post_data['username'] ?? '' ), true );

			if ( $username === '' ) {
				return array( __( 'A username is required to create an account.', 'intercessor' ) );
			}

			if ( ! validate_username( $username ) ) {
				return array( __( 'The username contains invalid characters.', 'intercessor' ) );
			}

			if ( username_exists( $username ) ) {
				return array( __( 'That username is already taken. Please choose another.', 'intercessor' ) );
			}
		}

		// ── Resolve password ──────────────────────────────────────────────────
		if ( $generate_password ) {
			$password = wp_generate_password( 16, true, false );
		} else {
			$password         = wp_unslash( $post_data['account_password'] ?? '' );
			$password_confirm = wp_unslash( $post_data['account_password_confirm'] ?? '' );

			if ( strlen( $password ) < 8 ) {
				return array( __( 'Password must be at least 8 characters.', 'intercessor' ) );
			}

			if ( $password !== $password_confirm ) {
				return array( __( 'Passwords do not match.', 'intercessor' ) );
			}
		}

		// ── Create the WordPress user ─────────────────────────────────────────
		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
			'role'         => Roles::ROLE_REQUESTER,
		) );

		if ( is_wp_error( $user_id ) ) {
			return array(
				// translators: %s: WP error message
				sprintf(
					/* translators: %s: WP error message */
					__( 'Could not create account: %s', 'intercessor' ),
					$user_id->get_error_message()
				),
			);
		}

		// ── Mark as pending confirmation ──────────────────────────────────────
		update_user_meta( $user_id, self::META_PENDING, '1' );

		// ── Link the requester row to the new account ─────────────────────────
		// The submission pipeline created (or found) the requester row before
		// this account existed, so its wp_user_id is still 0. Without this the
		// Prayer History block, which looks requesters up by user ID, never
		// finds the person's requests, and the admin "resend confirmation" and
		// "confirm manually" actions never appear. Linking while the account
		// is still pending is safe because a pending account cannot log in.
		( new Requester_Query() )->link_to_user( $email, (int) $user_id );

		$token  = self::generate_token();
		$expiry = time() + self::TOKEN_TTL;

		update_user_meta( $user_id, self::META_TOKEN,      $token );
		update_user_meta( $user_id, self::META_EXPIRY,     (string) $expiry );

		// Store the source page URL (posted by the form) so the confirmation
		// redirect lands back on the prayer form page rather than home_url('/').
		$raw_source = isset( $post_data['source_url'] ) ? esc_url_raw( wp_unslash( $post_data['source_url'] ) ) : '';
		$source_url = ( $raw_source !== '' && wp_validate_redirect( $raw_source, '' ) !== '' )
			? $raw_source
			: home_url( '/' );
		update_user_meta( $user_id, self::META_SOURCE_URL, $source_url );

		// ── Send confirmation email ───────────────────────────────────────────
		self::send_confirmation_email( $user_id, $email, $first_name, $token );

		return array();
	}

	/**
	 * Wire the registration hooks.
	 *
	 * Called from Plugin::init(), which runs on 'init' at priority 5, so the
	 * confirmation handler added here still fires at priority 10 of the same
	 * 'init' run.
	 *
	 * @since  1.1.3
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'handle_confirmation' ), 10 );
		add_filter( 'wp_authenticate_user', array( self::class, 'block_pending_login' ), 10, 2 );
		add_action( 'after_password_reset', array( self::class, 'confirm_on_password_reset' ), 10, 1 );
	}

	/**
	 * Handle a click on the emailed confirmation link.
	 *
	 * Hooked to 'init' at priority 10 by register().
	 * Reads the 'intercessor_confirm_email' query parameter, validates the
	 * token, and if valid removes the pending flag before redirecting back to
	 * the source page (where the prayer form lives) with a status query arg.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public static function handle_confirmation(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['intercessor_confirm_email'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['intercessor_confirm_email'] ) );

		if ( strlen( $token ) !== 64 || ! ctype_xdigit( $token ) ) {
			self::redirect_after_confirm( 'invalid', '' );
			return;
		}

		// Find the user by token.
		$users = get_users( array(
			'meta_key'   => self::META_TOKEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $token,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 1,
			'fields'     => 'ids',
		) );

		if ( empty( $users ) ) {
			self::redirect_after_confirm( 'invalid', '' );
			return;
		}

		$user_id    = (int) $users[0];
		$expiry     = (int) get_user_meta( $user_id, self::META_EXPIRY,     true );
		$source_url = (string) get_user_meta( $user_id, self::META_SOURCE_URL, true );

		if ( $expiry < time() ) {
			self::cleanup_token_meta( $user_id );
			self::redirect_after_confirm( 'expired', $source_url );
			return;
		}

		self::mark_confirmed( $user_id );

		self::redirect_after_confirm( 'confirmed', $source_url );
	}

	/**
	 * Refuse the login of an account whose email address is not yet confirmed.
	 *
	 * Hooked to 'wp_authenticate_user', which WordPress runs for username and
	 * email logins before it checks the password. The password is checked
	 * here first so that the "not confirmed" message is only shown to someone
	 * who knows it; anyone else gets WordPress's normal wrong-password error
	 * and learns nothing about the account's state.
	 *
	 * @since  1.1.3
	 * @param  \WP_User|\WP_Error $user     User found for the submitted login, or an earlier error.
	 * @param  string             $password Password submitted with the login.
	 * @return \WP_User|\WP_Error           The user unchanged, or an error when confirmation is pending.
	 */
	public static function block_pending_login( $user, $password ) {
		if ( ! $user instanceof \WP_User || ! self::is_pending( (int) $user->ID ) ) {
			return $user;
		}

		if ( ! wp_check_password( (string) $password, $user->user_pass, $user->ID ) ) {
			return $user;
		}

		return new \WP_Error(
			'intercessor_email_unconfirmed',
			__( 'Please confirm your email address before logging in. Open the confirmation link we emailed you, or reset your password to confirm your address.', 'intercessor' )
		);
	}

	/**
	 * Treat a completed password reset as confirmation of the email address.
	 *
	 * Hooked to 'after_password_reset'. A reset can only be completed from a
	 * link sent to the account's address, so it proves the same thing the
	 * confirmation link does. This is also the way back in for someone whose
	 * confirmation link has expired.
	 *
	 * @since  1.1.3
	 * @param  \WP_User $user User whose password was just reset.
	 * @return void
	 */
	public static function confirm_on_password_reset( $user ): void {
		if ( $user instanceof \WP_User && self::is_pending( (int) $user->ID ) ) {
			self::mark_confirmed( (int) $user->ID );
		}
	}

	/**
	 * Clear the pending flag and token data, then announce the confirmation.
	 *
	 * @since  1.1.3
	 * @param  int $user_id WordPress user ID being confirmed.
	 * @return void
	 */
	private static function mark_confirmed( int $user_id ): void {
		delete_user_meta( $user_id, self::META_PENDING );
		self::cleanup_token_meta( $user_id );
		delete_user_meta( $user_id, self::META_SOURCE_URL );

		/**
		 * Fires after a requester's email address has been confirmed.
		 *
		 * @since 1.0.0
		 * @param int $user_id Confirmed WordPress user ID.
		 */
		do_action( 'intercessor_email_confirmed', $user_id );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Generate a unique username from the available name and email data.
	 *
	 * Tries first_name.last_name, then first_name, then the local part of
	 * the email. Appends an incrementing suffix until the username is unique.
	 *
	 * @since  1.0.0
	 * @param  string $email      Email address.
	 * @param  string $first_name First name.
	 * @param  string $last_name  Last name.
	 * @return string             Unique, sanitized username.
	 */
	private static function generate_unique_username(
		string $email,
		string $first_name,
		string $last_name
	): string {
		$candidates = array_filter( array(
			$first_name !== '' && $last_name !== ''
				? strtolower( $first_name . '.' . $last_name )
				: '',
			$first_name !== '' ? strtolower( $first_name ) : '',
			strtolower( strstr( $email, '@', true ) ?: $email ),
		) );

		$base = sanitize_user( reset( $candidates ) ?: 'requester', true );
		$base = preg_replace( '/[^a-z0-9._\-]/', '', $base );
		$base = $base ?: 'requester';

		$username = $base;
		$suffix   = 1;

		while ( username_exists( $username ) ) {
			$username = $base . $suffix;
			$suffix++;
		}

		return $username;
	}

	/**
	 * Generate a cryptographically random 64-character hexadecimal token.
	 *
	 * @since  1.0.0
	 * @return string 64-character hex string.
	 */
	private static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Build the email confirmation URL for a given token.
	 *
	 * @since  1.0.0
	 * @param  string $token Confirmation token.
	 * @return string        Absolute URL.
	 */
	private static function confirmation_url( string $token ): string {
		return add_query_arg(
			'intercessor_confirm_email',
			rawurlencode( $token ),
			home_url( '/' )
		);
	}

	/**
	 * Send the account confirmation email to the new user.
	 *
	 * @since  1.0.0
	 * @param  int    $user_id    WordPress user ID.
	 * @param  string $email      Recipient email address.
	 * @param  string $first_name First name for greeting.
	 * @param  string $token      Confirmation token.
	 * @return void
	 */
	private static function send_confirmation_email(
		int    $user_id,
		string $email,
		string $first_name,
		string $token
	): void {
		$site_name    = get_bloginfo( 'name' );
		$confirm_url  = self::confirmation_url( $token );
		$greeting     = $first_name ?: __( 'there', 'intercessor' );

		$from_name    = Settings::get( 'email_from_name' )    ?: $site_name;
		$from_address = Settings::get( 'email_from_address' ) ?: get_option( 'admin_email' );

		// translators: %s: site name
		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Confirm your account at %s', 'intercessor' ),
			$site_name
		);

		$message = sprintf(
			/* translators: 1: first name, 2: site name, 3: confirmation URL, 4: expiry hours */
			__(
				"Hi %1\$s,\n\nThank you for registering at %2\$s. Please confirm your email address by clicking the link below:\n\n%3\$s\n\nThis link will expire in %4\$d hours.\n\nIf you did not create an account, you can safely ignore this email.\n\nRegards,\n%2\$s",
				'intercessor'
			),
			$greeting,
			$site_name,
			$confirm_url,
			(int) ( self::TOKEN_TTL / HOUR_IN_SECONDS )
		);

		$headers = array(
			sprintf( 'From: %s <%s>', $from_name, $from_address ),
			'Content-Type: text/plain; charset=UTF-8',
		);

		/**
		 * Filter the confirmation email before it is sent.
		 *
		 * @since 1.0.0
		 * @param array  $args    Array of email arguments: to, subject, message, headers.
		 * @param int    $user_id WordPress user ID of the new account.
		 */
		$args = apply_filters( 'intercessor_confirmation_email_args', array(
			'to'      => $email,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		), $user_id );

		wp_mail( $args['to'], $args['subject'], $args['message'], $args['headers'] );
	}

	/**
	 * Remove confirmation token meta keys from a user record.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return void
	 */
	private static function cleanup_token_meta( int $user_id ): void {
		delete_user_meta( $user_id, self::META_TOKEN );
		delete_user_meta( $user_id, self::META_EXPIRY );
	}

	/**
	 * Redirect to the source page (or home) with a status query arg and exit.
	 *
	 * The source URL is the page that hosted the prayer form when the user
	 * registered — stored in user meta during account creation. Falls back to
	 * home_url('/') when the stored URL is empty.
	 *
	 * @since  1.0.0
	 * @param  string $status     One of 'confirmed', 'invalid', 'expired'.
	 * @param  string $source_url Previously stored source page URL (may be empty).
	 * @return void
	 */
	private static function redirect_after_confirm( string $status, string $source_url = '' ): void {
		$base = ( $source_url !== '' && filter_var( $source_url, FILTER_VALIDATE_URL ) )
			? $source_url
			: home_url( '/' );

		// Strip any existing intercessor query args from the base URL before
		// appending the fresh status arg so we never double-append.
		$base = remove_query_arg(
			array( 'intercessor_confirm_email', 'intercessor_account' ),
			$base
		);

		$url = add_query_arg( 'intercessor_account', rawurlencode( $status ), $base );

		wp_safe_redirect( esc_url_raw( $url ) );
		exit;
	}

	/**
	 * Return true when a given user has a pending email confirmation.
	 *
	 * @since  1.0.0
	 * @param  int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function is_pending( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Generate a fresh confirmation token and resend the confirmation email.
	 *
	 * Used by admin handlers when an admin manually triggers a resend.
	 * Overwrites the existing token (invalidating the old confirmation link).
	 *
	 * @since  1.0.0
	 * @param  int    $user_id    WordPress user ID.
	 * @param  string $email      Recipient email address.
	 * @param  string $first_name First name for the greeting.
	 * @return void
	 */
	public static function resend_confirmation( int $user_id, string $email, string $first_name ): void {
		$token  = self::generate_token();
		$expiry = time() + self::TOKEN_TTL;

		update_user_meta( $user_id, self::META_TOKEN,  $token );
		update_user_meta( $user_id, self::META_EXPIRY, (string) $expiry );

		self::send_confirmation_email( $user_id, $email, $first_name, $token );
	}
}
