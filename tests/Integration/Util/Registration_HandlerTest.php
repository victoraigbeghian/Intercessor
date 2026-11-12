<?php
/**
 * Integration tests for Intercessor\Util\Registration_Handler.
 *
 * Covers the login block for accounts whose email is not yet confirmed.
 *
 * @package Intercessor\Tests\Integration\Util
 */

declare(strict_types=1);

namespace Intercessor\Tests\Integration\Util;

use Intercessor\Util\Registration_Handler;
use WP_Error;
use WP_UnitTestCase;
use WP_User;

class Registration_HandlerTest extends WP_UnitTestCase {

	private const PASSWORD = 'correct horse battery staple';

	/** @var int */
	private int $user_id;

	protected function setUp(): void {
		parent::setUp();

		Registration_Handler::register();

		$this->user_id = self::factory()->user->create( [
			'user_login' => 'pendinguser',
			'user_email' => 'pending@example.com',
			'user_pass'  => self::PASSWORD,
		] );

		update_user_meta( $this->user_id, Registration_Handler::META_PENDING, '1' );
	}

	public function test_pending_account_cannot_log_in_with_correct_password(): void {
		$result = wp_authenticate( 'pendinguser', self::PASSWORD );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'intercessor_email_unconfirmed', $result->get_error_code() );
	}

	public function test_pending_account_cannot_log_in_by_email(): void {
		$result = wp_authenticate( 'pending@example.com', self::PASSWORD );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'intercessor_email_unconfirmed', $result->get_error_code() );
	}

	public function test_wrong_password_does_not_reveal_pending_state(): void {
		$result = wp_authenticate( 'pendinguser', 'not the password' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'incorrect_password', $result->get_error_code() );
	}

	public function test_confirmed_account_can_log_in(): void {
		delete_user_meta( $this->user_id, Registration_Handler::META_PENDING );

		$result = wp_authenticate( 'pendinguser', self::PASSWORD );

		$this->assertInstanceOf( WP_User::class, $result );
	}

	public function test_password_reset_confirms_the_account(): void {
		reset_password( get_user_by( 'id', $this->user_id ), 'a brand new password' );

		$this->assertFalse( Registration_Handler::is_pending( $this->user_id ) );
		$this->assertInstanceOf( WP_User::class, wp_authenticate( 'pendinguser', 'a brand new password' ) );
	}
}
