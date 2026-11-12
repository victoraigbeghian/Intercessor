<?php
/**
 * Unit tests for Intercessor\Http\Request.
 *
 * @package Intercessor\Tests\Unit\Http
 */

declare(strict_types=1);

namespace Intercessor\Tests\Unit\Http;

use Intercessor\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Tests the HTTP Request abstraction class.
 *
 * The Request class is instantiated directly with controlled arrays so no
 * superglobals or WordPress environment are required.
 */
class RequestTest extends TestCase {

	// -------------------------------------------------------------------------
	// Core input access
	// -------------------------------------------------------------------------

	public function test_post_returns_post_value(): void {
		$req = new Request( [], [ 'name' => 'Victor' ], [] );
		$this->assertSame( 'Victor', $req->post( 'name' ) );
	}

	public function test_get_returns_get_value(): void {
		$req = new Request( [ 'tab' => 'general' ], [], [] );
		$this->assertSame( 'general', $req->get( 'tab' ) );
	}

	public function test_input_prefers_post_over_get(): void {
		$req = new Request( [ 'key' => 'from_get' ], [ 'key' => 'from_post' ], [] );
		$this->assertSame( 'from_post', $req->input( 'key' ) );
	}

	public function test_input_falls_back_to_get_when_no_post(): void {
		$req = new Request( [ 'key' => 'from_get' ], [], [] );
		$this->assertSame( 'from_get', $req->input( 'key' ) );
	}

	public function test_input_returns_default_when_key_absent(): void {
		$req = new Request( [], [], [] );
		$this->assertSame( 'fallback', $req->input( 'missing', 'fallback' ) );
	}

	public function test_has_returns_true_for_post_key(): void {
		$req = new Request( [], [ 'nonce' => 'abc' ], [] );
		$this->assertTrue( $req->has( 'nonce' ) );
	}

	public function test_has_returns_true_for_get_key(): void {
		$req = new Request( [ 'page' => '1' ], [], [] );
		$this->assertTrue( $req->has( 'page' ) );
	}

	public function test_has_returns_false_for_missing_key(): void {
		$req = new Request( [], [], [] );
		$this->assertFalse( $req->has( 'nonexistent' ) );
	}

	// -------------------------------------------------------------------------
	// Typed accessors
	// -------------------------------------------------------------------------

	public function test_get_string_sanitizes_html_tags(): void {
		$req = new Request( [], [ 'name' => '<script>alert(1)</script>Hello' ], [] );
		$this->assertSame( 'Hello', $req->get_string( 'name' ) );
	}

	public function test_get_string_returns_default_for_missing_key(): void {
		$req = new Request( [], [], [] );
		$this->assertSame( 'default', $req->get_string( 'missing', 'default' ) );
	}

	public function test_get_int_returns_integer(): void {
		$req = new Request( [], [ 'id' => '42' ], [] );
		$this->assertSame( 42, $req->get_int( 'id' ) );
	}

	public function test_get_int_returns_zero_for_negative(): void {
		$req = new Request( [], [ 'id' => '-5' ], [] );
		$this->assertSame( 5, $req->get_int( 'id' ) ); // absint
	}

	public function test_get_int_returns_default_for_missing(): void {
		$req = new Request( [], [], [] );
		$this->assertSame( 0, $req->get_int( 'missing' ) );
	}

	public function test_get_bool_returns_true_for_truthy(): void {
		$req = new Request( [], [ 'flag' => '1' ], [] );
		$this->assertTrue( $req->get_bool( 'flag' ) );
	}

	public function test_get_bool_returns_false_for_empty(): void {
		$req = new Request( [], [ 'flag' => '' ], [] );
		$this->assertFalse( $req->get_bool( 'flag' ) );
	}

	public function test_get_email_returns_sanitized_email(): void {
		$req = new Request( [], [ 'email' => 'test@example.com' ], [] );
		$this->assertSame( 'test@example.com', $req->get_email( 'email' ) );
	}

	public function test_get_email_strips_invalid_characters(): void {
		$req = new Request( [], [ 'email' => 'bad email@example.com' ], [] );
		// sanitize_email removes the space
		$this->assertStringNotContainsString( ' ', $req->get_email( 'email' ) );
	}

	public function test_get_key_returns_lowercase_slug(): void {
		$req = new Request( [], [ 'status' => 'Approved' ], [] );
		$this->assertSame( 'approved', $req->get_key( 'status' ) );
	}

	public function test_get_key_strips_non_slug_characters(): void {
		$req = new Request( [], [ 'action' => 'bulk approve!' ], [] );
		$result = $req->get_key( 'action' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]*$/', $result );
	}

	public function test_get_textarea_preserves_newlines(): void {
		$req = new Request( [], [ 'content' => "line one\nline two" ], [] );
		$this->assertStringContainsString( "\n", $req->get_textarea( 'content' ) );
	}

	public function test_get_array_returns_array_value(): void {
		$req = new Request( [], [ 'ids' => [ '1', '2', '3' ] ], [] );
		$this->assertSame( [ '1', '2', '3' ], $req->get_array( 'ids' ) );
	}

	public function test_get_array_splits_comma_separated_scalar(): void {
		$req = new Request( [], [ 'ids' => '1,2,3' ], [] );
		$this->assertSame( [ '1', '2', '3' ], $req->get_array( 'ids' ) );
	}

	public function test_get_array_wraps_single_scalar(): void {
		// A single value without commas is treated as a one-element list.
		// This covers form posts where one ID arrives without the trailing comma.
		$req = new Request( [], [ 'ids' => 'alpha' ], [] );
		$this->assertSame( [ 'alpha' ], $req->get_array( 'ids' ) );
	}

	public function test_get_int_array_converts_and_filters(): void {
		$req = new Request( [], [ 'bulk_ids' => [ '3', '0', '7', '-2', 'abc' ] ], [] );
		$result = $req->get_int_array( 'bulk_ids' );
		// 0 and negatives (absint gives 0 or positive) and non-numeric stripped
		$this->assertSame( [ 3, 7, 2 ], $result );
	}

	public function test_get_int_array_returns_empty_for_missing(): void {
		$req = new Request( [], [], [] );
		$this->assertSame( [], $req->get_int_array( 'missing' ) );
	}

	// -------------------------------------------------------------------------
	// Unslashing
	// -------------------------------------------------------------------------

	public function test_constructor_unslashes_post_data(): void {
		$req = new Request( [], [ 'name' => 'O\\\'Brien' ], [] );
		$this->assertSame( "O'Brien", $req->post( 'name' ) );
	}

	public function test_constructor_unslashes_get_data(): void {
		$req = new Request( [ 'q' => 'hello\\\'world' ], [], [] );
		$this->assertSame( "hello'world", $req->get( 'q' ) );
	}

	// -------------------------------------------------------------------------
	// Server accessors
	// -------------------------------------------------------------------------

	public function test_get_remote_addr_returns_ip(): void {
		$req = new Request( [], [], [ 'REMOTE_ADDR' => '127.0.0.1' ] );
		$this->assertSame( '127.0.0.1', $req->get_remote_addr() );
	}

	public function test_get_remote_addr_returns_empty_when_absent(): void {
		$req = new Request( [], [], [] );
		$this->assertSame( '', $req->get_remote_addr() );
	}

	public function test_get_user_agent_returns_ua_string(): void {
		$req = new Request( [], [], [ 'HTTP_USER_AGENT' => 'Mozilla/5.0' ] );
		$this->assertSame( 'Mozilla/5.0', $req->get_user_agent() );
	}

	// -------------------------------------------------------------------------
	// HTTP method detection
	// -------------------------------------------------------------------------

	public function test_is_post_returns_true_for_post_method(): void {
		$req = new Request( [], [], [ 'REQUEST_METHOD' => 'POST' ] );
		$this->assertTrue( $req->is_post() );
	}

	public function test_is_get_returns_true_for_get_method(): void {
		$req = new Request( [], [], [ 'REQUEST_METHOD' => 'GET' ] );
		$this->assertTrue( $req->is_get() );
	}

	public function test_is_post_returns_false_for_get_method(): void {
		$req = new Request( [], [], [ 'REQUEST_METHOD' => 'GET' ] );
		$this->assertFalse( $req->is_post() );
	}

	public function test_get_method_defaults_to_get(): void {
		$req = new Request( [], [], [] );
		$this->assertSame( 'GET', $req->get_method() );
	}

	// -------------------------------------------------------------------------
	// Nonce verification
	// -------------------------------------------------------------------------

	public function test_verify_nonce_returns_true_for_valid_nonce(): void {
		$req = new Request( [], [ '_wpnonce' => 'valid_nonce' ], [] );
		$this->assertTrue( $req->verify_nonce( 'any_action' ) );
	}

	public function test_verify_nonce_returns_false_for_invalid_nonce(): void {
		$req = new Request( [], [ '_wpnonce' => 'bad_nonce' ], [] );
		$this->assertFalse( $req->verify_nonce( 'any_action' ) );
	}

	public function test_verify_nonce_returns_false_when_field_absent(): void {
		$req = new Request( [], [], [] );
		$this->assertFalse( $req->verify_nonce( 'any_action' ) );
	}

	public function test_verify_nonce_accepts_custom_field_name(): void {
		$req = new Request( [], [ 'nonce' => 'valid_nonce' ], [] );
		$this->assertTrue( $req->verify_nonce( 'any_action', 'nonce' ) );
	}
}
