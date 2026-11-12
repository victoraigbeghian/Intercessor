<?php
/**
 * Unit tests for Intercessor\Admin\Settings\Sanitizer.
 *
 * @package Intercessor\Tests\Unit\Admin\Settings
 */

declare(strict_types=1);

namespace Intercessor\Tests\Unit\Admin\Settings;

use Intercessor\Admin\Settings\Registry;
use Intercessor\Admin\Settings\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the settings Sanitizer class.
 *
 * Sanitizer is a pure class with no side effects — it accepts input and a
 * Registry, applies WordPress sanitization functions (stubbed for unit tests),
 * and returns the cleaned array.
 */
class SanitizerTest extends TestCase {

	/** @var Sanitizer */
	private Sanitizer $sanitizer;

	protected function setUp(): void {
		$schema = [
			'general' => [
				'approval' => [
					'title'  => 'Approval',
					'fields' => [
						[ 'id' => 'auto_approve',         'type' => 'checkbox' ],
						[ 'id' => 'require_login',        'type' => 'checkbox' ],
						[ 'id' => 'max_requests_per_day', 'type' => 'number' ],
						[ 'id' => 'admin_email',          'type' => 'email' ],
					],
				],
			],
			'moderation' => [
				'opts' => [
					'title'  => 'Options',
					'fields' => [
						[ 'id' => 'profanity_words',  'type' => 'textarea' ],
						[ 'id' => 'moderation_role',  'type' => 'select' ],
						[ 'id' => 'some_text_field',  'type' => 'text' ],
						[ 'id' => 'secret_key',       'type' => 'password' ],
					],
				],
			],
		];

		$registry        = new Registry( $schema );
		$this->sanitizer = new Sanitizer( $registry );
	}

	// -------------------------------------------------------------------------
	// Checkbox
	// -------------------------------------------------------------------------

	public function test_checkbox_truthy_value_stores_as_one(): void {
		$result = $this->sanitizer->sanitize( [ 'auto_approve' => '1' ], 'general' );
		$this->assertSame( '1', $result['auto_approve'] );
	}

	public function test_checkbox_falsy_value_stores_as_zero(): void {
		$result = $this->sanitizer->sanitize( [ 'auto_approve' => '' ], 'general' );
		$this->assertSame( '0', $result['auto_approve'] );
	}

	public function test_unchecked_checkbox_defaults_to_zero(): void {
		// A browser does not submit an unchecked checkbox. The sanitizer has
		// to default the field to '0' so a previously-ticked value does not
		// survive the Renderer's array_merge over the stored settings.
		$result = $this->sanitizer->sanitize( [], 'general' );
		$this->assertSame( '0', $result['auto_approve'] );
		$this->assertSame( '0', $result['require_login'] );
	}

	public function test_missing_non_checkbox_fields_are_still_ignored(): void {
		// Only checkbox and multicheck fields get a default from absence; a
		// missing text or number field must stay absent so the Renderer's
		// array_merge preserves the stored value.
		$result = $this->sanitizer->sanitize( [], 'general' );
		$this->assertArrayNotHasKey( 'max_requests_per_day', $result );
	}

	// -------------------------------------------------------------------------
	// Number
	// -------------------------------------------------------------------------

	public function test_number_integer_string_returns_integer(): void {
		$result = $this->sanitizer->sanitize( [ 'max_requests_per_day' => '5' ], 'general' );
		$this->assertSame( 5, $result['max_requests_per_day'] );
	}

	public function test_number_float_string_returns_float(): void {
		$result = $this->sanitizer->sanitize( [ 'max_requests_per_day' => '3.5' ], 'general' );
		$this->assertSame( 3.5, $result['max_requests_per_day'] );
	}

	public function test_number_non_numeric_returns_zero(): void {
		$result = $this->sanitizer->sanitize( [ 'max_requests_per_day' => 'abc' ], 'general' );
		$this->assertSame( 0, $result['max_requests_per_day'] );
	}

	// -------------------------------------------------------------------------
	// Email
	// -------------------------------------------------------------------------

	public function test_email_valid_address_passes_through(): void {
		$result = $this->sanitizer->sanitize( [ 'admin_email' => 'test@example.com' ], 'general' );
		$this->assertSame( 'test@example.com', $result['admin_email'] );
	}

	// -------------------------------------------------------------------------
	// Textarea
	// -------------------------------------------------------------------------

	public function test_textarea_preserves_whitespace(): void {
		$result = $this->sanitizer->sanitize( [ 'profanity_words' => "word1\nword2" ], 'moderation' );
		$this->assertStringContainsString( 'word1', $result['profanity_words'] );
	}

	// -------------------------------------------------------------------------
	// Select
	// -------------------------------------------------------------------------

	public function test_select_sanitizes_to_key_format(): void {
		$result = $this->sanitizer->sanitize( [ 'moderation_role' => 'Editor' ], 'moderation' );
		$this->assertSame( 'editor', $result['moderation_role'] );
	}

	// -------------------------------------------------------------------------
	// Text / Password
	// -------------------------------------------------------------------------

	public function test_text_strips_html_tags(): void {
		$result = $this->sanitizer->sanitize( [ 'some_text_field' => '<b>Hello</b>' ], 'moderation' );
		$this->assertSame( 'Hello', $result['some_text_field'] );
	}

	public function test_password_field_sanitizes_as_text(): void {
		$result = $this->sanitizer->sanitize( [ 'secret_key' => '<em>secret</em>' ], 'moderation' );
		$this->assertSame( 'secret', $result['secret_key'] );
	}

	// -------------------------------------------------------------------------
	// Tab / section scoping
	// -------------------------------------------------------------------------

	public function test_sanitize_without_tab_processes_all_fields(): void {
		$input  = [
			'auto_approve'     => '1',
			'profanity_words'  => 'word1, word2',
		];
		$result = $this->sanitizer->sanitize( $input );
		$this->assertArrayHasKey( 'auto_approve',    $result );
		$this->assertArrayHasKey( 'profanity_words', $result );
	}

	public function test_sanitize_with_tab_ignores_other_tab_fields(): void {
		$input  = [
			'auto_approve'    => '1',
			'profanity_words' => 'word1',
		];
		$result = $this->sanitizer->sanitize( $input, 'general' );
		// profanity_words belongs to 'moderation', not 'general'.
		$this->assertArrayHasKey( 'auto_approve',    $result );
		$this->assertArrayNotHasKey( 'profanity_words', $result );
	}

	public function test_sanitize_unknown_fields_are_ignored(): void {
		$result = $this->sanitizer->sanitize( [ 'hacker_field' => 'injection' ] );
		$this->assertArrayNotHasKey( 'hacker_field', $result );
	}
}
