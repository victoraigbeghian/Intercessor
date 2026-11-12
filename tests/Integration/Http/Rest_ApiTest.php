<?php
/**
 * Integration tests for the read endpoints of Intercessor\Http\Rest_Api.
 *
 * Covers who may read a single prayer request and its history: visitors
 * only see approved, public requests; moderators see everything.
 *
 * @package Intercessor\Tests\Integration\Http
 */

declare(strict_types=1);

namespace Intercessor\Tests\Integration\Http;

use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Http\Rest_Api;
use WP_REST_Request;
use WP_UnitTestCase;

class Rest_ApiTest extends WP_UnitTestCase {

	/** @var Rest_Api */
	private Rest_Api $api;

	/** @var Prayer_Request_Query */
	private Prayer_Request_Query $query;

	/** @var int Requester ID used across tests */
	private int $requester_id;

	protected function setUp(): void {
		parent::setUp();

		$this->api   = new Rest_Api();
		$this->query = new Prayer_Request_Query();

		$this->requester_id = (int) ( new Requester_Query() )->add_item( [
			'name'  => 'Rest User',
			'email' => 'restuser@example.com',
		] );

		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// GET /requests/{id}
	// -------------------------------------------------------------------------

	public function test_visitor_can_read_approved_public_request(): void {
		$id       = $this->insert_fixture( [ 'status' => 'approved' ] );
		$response = $this->api->get_request( $this->request_for( $id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
	}

	/**
	 * @dataProvider unpublished_statuses
	 */
	public function test_visitor_cannot_read_unpublished_request( string $status ): void {
		$id       = $this->insert_fixture( [ 'status' => $status ] );
		$response = $this->api->get_request( $this->request_for( $id ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertArrayNotHasKey( 'content', (array) $response->get_data() );
	}

	public function test_visitor_cannot_read_approved_request_that_is_not_public(): void {
		$id       = $this->insert_fixture( [ 'status' => 'approved', 'is_public' => 0 ] );
		$response = $this->api->get_request( $this->request_for( $id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_moderator_can_read_pending_request(): void {
		$this->become_moderator();

		$id       = $this->insert_fixture( [ 'status' => 'pending' ] );
		$response = $this->api->get_request( $this->request_for( $id ) );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// GET /requests/{id}/history
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider unpublished_statuses
	 */
	public function test_visitor_cannot_read_history_of_unpublished_request( string $status ): void {
		$id = $this->insert_fixture( [ 'status' => 'approved' ] );
		$this->query->update_status( $id, $status, 'internal note' );

		$response = $this->api->get_history( $this->request_for( $id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_visitor_history_contains_only_public_fields(): void {
		$id = $this->insert_fixture( [ 'status' => 'pending' ] );
		$this->query->update_status( $id, 'approved', 'internal note' );

		$response = $this->api->get_history( $this->request_for( $id ) );
		$rows     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'approved', $rows[0]['new_status'] );
		$this->assertArrayNotHasKey( 'actor_user_id', $rows[0] );
		$this->assertArrayNotHasKey( 'note', $rows[0] );
	}

	public function test_moderator_history_contains_actor_and_note(): void {
		$this->become_moderator();

		$id = $this->insert_fixture( [ 'status' => 'pending' ] );
		$this->query->update_status( $id, 'approved', 'internal note' );

		$rows = $this->api->get_history( $this->request_for( $id ) )->get_data();

		$this->assertArrayHasKey( 'actor_user_id', $rows[0] );
		$this->assertSame( 'internal note', $rows[0]['note'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Statuses a visitor must never be able to read.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unpublished_statuses(): array {
		return [
			'pending'  => [ 'pending' ],
			'rejected' => [ 'rejected' ],
			'archived' => [ 'archived' ],
			'private'  => [ 'private' ],
		];
	}

	private function request_for( int $id ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/intercessor/v1/requests/' . $id );
		$request->set_param( 'id', $id );

		return $request;
	}

	private function become_moderator(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_user_by( 'id', $user_id )->add_cap( 'edit_prayers' );
		wp_set_current_user( $user_id );
	}

	private function insert_fixture( array $overrides = [] ): int {
		return (int) $this->query->add_item( array_merge( [
			'requester_id' => $this->requester_id,
			'subject'      => 'Fixture subject',
			'content'      => 'Fixture content.',
			'status'       => 'pending',
			'is_public'    => 1,
			'is_anonymous' => 0,
		], $overrides ) );
	}
}
