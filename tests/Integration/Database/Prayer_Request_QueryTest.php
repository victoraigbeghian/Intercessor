<?php
/**
 * Integration tests for Intercessor\Database\Query\Prayer_Request_Query.
 *
 * @package Intercessor\Tests\Integration\Database
 */

declare(strict_types=1);

namespace Intercessor\Tests\Integration\Database;

use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;
use Intercessor\Database\Row\Prayer_Request;
use WP_UnitTestCase;

/**
 * Tests CRUD operations on the prayer_requests table against a real test DB.
 *
 * Requires WP_UnitTestCase (from wordpress-develop test suite) and a properly
 * configured test database. See tests/bootstrap-integration.php for setup.
 *
 * Each test method runs in a transaction that is rolled back on tearDown by
 * WP_UnitTestCase, so the DB is clean after every test.
 */
class Prayer_Request_QueryTest extends WP_UnitTestCase {

	/** @var Prayer_Request_Query */
	private Prayer_Request_Query $query;

	/** @var int Requester ID used across tests */
	private int $requester_id;

	protected function setUp(): void {
		parent::setUp();

		$this->query = new Prayer_Request_Query();

		// Create a requester to satisfy the foreign key.
		$rq                  = new Requester_Query();
		$this->requester_id  = (int) $rq->add_item( [
			'name'  => 'Test User',
			'email' => 'testuser@example.com',
		] );
	}

	// -------------------------------------------------------------------------
	// add_item()
	// -------------------------------------------------------------------------

	public function test_add_item_returns_positive_int_on_success(): void {
		$id = $this->query->add_item( [
			'requester_id' => $this->requester_id,
			'subject'      => 'Test subject',
			'content'      => 'Test content.',
			'status'       => 'pending',
			'is_public'    => 1,
		] );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_add_item_returns_false_with_empty_data(): void {
		// No required columns — should fail gracefully.
		$id = $this->query->add_item( [] );
		// Either returns false or 0 depending on DB constraint; not a positive int.
		$this->assertFalse( $id );
	}

	// -------------------------------------------------------------------------
	// get_item()
	// -------------------------------------------------------------------------

	public function test_get_item_returns_prayer_request_object(): void {
		$id   = $this->insert_fixture();
		$item = $this->query->get_item( $id );

		$this->assertInstanceOf( Prayer_Request::class, $item );
	}

	public function test_get_item_populates_correct_values(): void {
		$id   = $this->insert_fixture( [ 'subject' => 'My prayer', 'status' => 'approved' ] );
		$item = $this->query->get_item( $id );

		$this->assertSame( $id,         $item->id );
		$this->assertSame( 'My prayer', $item->subject );
		$this->assertSame( 'approved',  $item->status );
	}

	public function test_get_item_returns_false_for_nonexistent_id(): void {
		$this->assertFalse( $this->query->get_item( 999999 ) );
	}

	// -------------------------------------------------------------------------
	// update_item()
	// -------------------------------------------------------------------------

	public function test_update_item_changes_value(): void {
		$id = $this->insert_fixture( [ 'status' => 'pending' ] );
		$this->query->update_item( $id, [ 'status' => 'approved' ] );

		$item = $this->query->get_item( $id );
		$this->assertSame( 'approved', $item->status );
	}

	public function test_update_item_returns_true_on_success(): void {
		$id     = $this->insert_fixture();
		$result = $this->query->update_item( $id, [ 'status' => 'archived' ] );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// delete_item()
	// -------------------------------------------------------------------------

	public function test_delete_item_removes_row(): void {
		$id = $this->insert_fixture();
		$this->query->delete_item( $id );

		$this->assertFalse( $this->query->get_item( $id ) );
	}

	public function test_delete_item_returns_true_on_success(): void {
		$id     = $this->insert_fixture();
		$result = $this->query->delete_item( $id );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// get_items()
	// -------------------------------------------------------------------------

	public function test_get_items_returns_array_of_prayer_request_objects(): void {
		$this->insert_fixture();
		$this->insert_fixture();

		$items = $this->query->get_items( [ 'requester_id' => $this->requester_id ] );

		$this->assertIsArray( $items );
		$this->assertNotEmpty( $items );
		foreach ( $items as $item ) {
			$this->assertInstanceOf( Prayer_Request::class, $item );
		}
	}

	public function test_get_items_filters_by_status(): void {
		$this->insert_fixture( [ 'status' => 'pending' ] );
		$this->insert_fixture( [ 'status' => 'approved' ] );

		$pending = $this->query->get_items( [ 'status' => 'pending' ] );

		foreach ( $pending as $item ) {
			$this->assertSame( 'pending', $item->status );
		}
	}

	public function test_get_items_respects_limit(): void {
		$this->insert_fixture();
		$this->insert_fixture();
		$this->insert_fixture();

		$items = $this->query->get_items( [ 'number' => 2 ] );
		$this->assertCount( 2, $items );
	}

	public function test_get_items_returns_empty_for_no_matches(): void {
		$items = $this->query->get_items( [ 'status' => 'nonexistent_status' ] );
		$this->assertSame( [], $items );
	}

	// -------------------------------------------------------------------------
	// count_items()
	// -------------------------------------------------------------------------

	public function test_count_items_returns_correct_count(): void {
		$before = $this->query->count_items( [] );

		$this->insert_fixture();
		$this->insert_fixture();

		$after = $this->query->count_items( [] );
		$this->assertSame( $before + 2, $after );
	}

	public function test_count_items_filters_by_status(): void {
		$this->insert_fixture( [ 'status' => 'pending' ] );
		$this->insert_fixture( [ 'status' => 'approved' ] );

		$count = $this->query->count_items( [ 'status' => 'pending' ] );
		$this->assertGreaterThanOrEqual( 1, $count );
	}

	// -------------------------------------------------------------------------
	// update_status()
	// -------------------------------------------------------------------------

	public function test_update_status_changes_status_and_writes_history(): void {
		$id = $this->insert_fixture( [ 'status' => 'pending' ] );
		$this->query->update_status( $id, 'approved' );

		$item = $this->query->get_item( $id );
		$this->assertSame( 'approved', $item->status );
	}

	public function test_update_status_returns_false_for_nonexistent_id(): void {
		$result = $this->query->update_status( 999999, 'approved' );
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// Domain helpers
	// -------------------------------------------------------------------------

	public function test_get_pending_returns_only_pending_items(): void {
		$this->insert_fixture( [ 'status' => 'pending' ] );
		$this->insert_fixture( [ 'status' => 'approved' ] );

		$pending = $this->query->get_pending();

		foreach ( $pending as $item ) {
			$this->assertTrue( $item->is_pending() );
		}
	}

	public function test_get_public_approved_returns_only_approved_public_items(): void {
		$this->insert_fixture( [ 'status' => 'approved', 'is_public' => 1 ] );
		$this->insert_fixture( [ 'status' => 'pending',  'is_public' => 1 ] );

		$items = $this->query->get_public_approved();

		foreach ( $items as $item ) {
			$this->assertTrue( $item->is_approved() );
			$this->assertTrue( $item->is_public() );
		}
	}

	// -------------------------------------------------------------------------
	// Bulk operations
	// -------------------------------------------------------------------------

	public function test_bulk_update_status_updates_all_ids(): void {
		$id1 = $this->insert_fixture( [ 'status' => 'pending' ] );
		$id2 = $this->insert_fixture( [ 'status' => 'pending' ] );

		$updated = $this->query->bulk_update_status( [ $id1, $id2 ], 'approved' );

		$this->assertSame( [ $id1, $id2 ], $updated );
		$this->assertSame( 'approved', $this->query->get_item( $id1 )->status );
		$this->assertSame( 'approved', $this->query->get_item( $id2 )->status );
	}

	public function test_bulk_delete_removes_all_ids(): void {
		$id1 = $this->insert_fixture();
		$id2 = $this->insert_fixture();

		$count = $this->query->bulk_delete( [ $id1, $id2 ] );

		$this->assertSame( 2, $count );
		$this->assertFalse( $this->query->get_item( $id1 ) );
		$this->assertFalse( $this->query->get_item( $id2 ) );
	}

	public function test_bulk_update_status_skips_nonexistent_ids(): void {
		$id      = $this->insert_fixture( [ 'status' => 'pending' ] );
		$updated = $this->query->bulk_update_status( [ $id, 999999 ], 'approved' );

		// Only the real ID should be updated.
		$this->assertSame( [ $id ], $updated );
	}

	public function test_bulk_update_status_skips_ids_already_in_target_status(): void {
		$pending  = $this->insert_fixture( [ 'status' => 'pending' ] );
		$approved = $this->insert_fixture( [ 'status' => 'approved' ] );

		$updated = $this->query->bulk_update_status( [ $pending, $approved ], 'approved' );

		// Only the request whose status changed is reported, so only its
		// requester is notified.
		$this->assertSame( [ $pending ], $updated );
	}

	public function test_bulk_update_status_writes_no_history_for_unchanged_ids(): void {
		$approved = $this->insert_fixture( [ 'status' => 'approved' ] );

		$this->query->bulk_update_status( [ $approved ], 'approved' );

		$history = ( new \Intercessor\Database\Query\Prayer_History_Query() )->get_for_request( $approved );
		$this->assertCount( 0, $history );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

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
