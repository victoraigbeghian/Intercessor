<?php
/**
 * Integration tests for Intercessor\Util\Rate_Limiter.
 *
 * @package Intercessor\Tests\Integration\Util
 */

declare(strict_types=1);

namespace Intercessor\Tests\Integration\Util;

use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Util\Rate_Limiter;
use WP_UnitTestCase;

/**
 * Tests rate limiting against real DB rows and real settings storage.
 */
class Rate_LimiterTest extends WP_UnitTestCase {

	/** @var Requester_Query */
	private Requester_Query $requester_query;

	/** @var Prayer_Request_Query */
	private Prayer_Request_Query $prayer_query;

	protected function setUp(): void {
		parent::setUp();
		$this->requester_query = new Requester_Query();
		$this->prayer_query    = new Prayer_Request_Query();
	}

	// -------------------------------------------------------------------------
	// is_allowed() — disabled (limit = 0)
	// -------------------------------------------------------------------------

	public function test_is_allowed_returns_true_when_limit_is_zero(): void {
		update_option( 'intercessor_settings', [ 'max_requests_per_day' => 0 ] );
		$this->assertTrue( Rate_Limiter::is_allowed( 'any@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// is_allowed() — new requester
	// -------------------------------------------------------------------------

	public function test_is_allowed_returns_true_for_unknown_email(): void {
		update_option( 'intercessor_settings', [ 'max_requests_per_day' => 3 ] );
		$this->assertTrue( Rate_Limiter::is_allowed( 'brandnew@example.com' ) );
	}

	// -------------------------------------------------------------------------
	// is_allowed() — below limit
	// -------------------------------------------------------------------------

	public function test_is_allowed_returns_true_below_limit(): void {
		update_option( 'intercessor_settings', [ 'max_requests_per_day' => 3 ] );

		$email        = 'below@example.com';
		$requester_id = $this->requester_query->find_or_create( $email, 'Below User' );

		// Insert 2 requests (below limit of 3).
		$this->prayer_query->add_item( [
			'requester_id' => $requester_id,
			'subject'      => 'Request 1',
			'content'      => 'Content.',
			'status'       => 'pending',
			'is_public'    => 1,
		] );
		$this->prayer_query->add_item( [
			'requester_id' => $requester_id,
			'subject'      => 'Request 2',
			'content'      => 'Content.',
			'status'       => 'pending',
			'is_public'    => 1,
		] );

		$this->assertTrue( Rate_Limiter::is_allowed( $email ) );
	}

	// -------------------------------------------------------------------------
	// is_allowed() — at/over limit
	// -------------------------------------------------------------------------

	public function test_is_allowed_returns_false_at_limit(): void {
		update_option( 'intercessor_settings', [ 'max_requests_per_day' => 2 ] );

		$email        = 'atlimit@example.com';
		$requester_id = $this->requester_query->find_or_create( $email, 'At Limit User' );

		// Insert exactly 2 requests (equals limit of 2).
		for ( $i = 0; $i < 2; $i++ ) {
			$this->prayer_query->add_item( [
				'requester_id' => $requester_id,
				'subject'      => "Request {$i}",
				'content'      => 'Content.',
				'status'       => 'pending',
				'is_public'    => 1,
			] );
		}

		$this->assertFalse( Rate_Limiter::is_allowed( $email ) );
	}

	// -------------------------------------------------------------------------
	// get_limit()
	// -------------------------------------------------------------------------

	public function test_get_limit_returns_configured_value(): void {
		update_option( 'intercessor_settings', [ 'max_requests_per_day' => 5 ] );
		$this->assertSame( 5, Rate_Limiter::get_limit() );
	}

	public function test_get_limit_returns_default_when_not_set(): void {
		delete_option( 'intercessor_settings' );
		$this->assertSame( 3, Rate_Limiter::get_limit() );
	}

	// -------------------------------------------------------------------------
	// Per-client limit
	// -------------------------------------------------------------------------

	public function test_client_is_allowed_until_hourly_limit_is_reached(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		add_filter( 'intercessor_max_requests_per_ip_per_hour', static fn() => 2 );

		$this->assertTrue( Rate_Limiter::is_client_allowed() );
		Rate_Limiter::record_client_submission();
		$this->assertTrue( Rate_Limiter::is_client_allowed() );
		Rate_Limiter::record_client_submission();
		$this->assertFalse( Rate_Limiter::is_client_allowed() );
	}

	public function test_client_limit_is_counted_per_address(): void {
		add_filter( 'intercessor_max_requests_per_ip_per_hour', static fn() => 1 );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
		Rate_Limiter::record_client_submission();
		$this->assertFalse( Rate_Limiter::is_client_allowed() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.12';
		$this->assertTrue( Rate_Limiter::is_client_allowed() );
	}

	public function test_client_limit_can_be_disabled_with_filter(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.13';
		add_filter( 'intercessor_max_requests_per_ip_per_hour', '__return_zero' );

		Rate_Limiter::record_client_submission();
		$this->assertTrue( Rate_Limiter::is_client_allowed() );
	}
}
