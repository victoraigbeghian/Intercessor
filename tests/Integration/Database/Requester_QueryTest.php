<?php
/**
 * Integration tests for Intercessor\Database\Query\Requester_Query.
 *
 * @package Intercessor\Tests\Integration\Database
 */

declare(strict_types=1);

namespace Intercessor\Tests\Integration\Database;

use Intercessor\Database\Query\Requester_Query;
use Intercessor\Database\Row\Requester;
use WP_UnitTestCase;

class Requester_QueryTest extends WP_UnitTestCase {

	/** @var Requester_Query */
	private Requester_Query $query;

	protected function setUp(): void {
		parent::setUp();
		$this->query = new Requester_Query();

		// find_or_create() records the current user on new rows, so start
		// every test as a guest.
		wp_set_current_user( 0 );
	}

	// -------------------------------------------------------------------------
	// add_item / get_item
	// -------------------------------------------------------------------------

	public function test_add_and_get_item_roundtrip(): void {
		$id   = (int) $this->query->add_item( [
			'name'  => 'Alice Smith',
			'email' => 'alice@example.com',
		] );
		$item = $this->query->get_item( $id );

		$this->assertInstanceOf( Requester::class, $item );
		$this->assertSame( 'Alice Smith',         $item->name );
		$this->assertSame( 'alice@example.com',   $item->email );
	}

	// -------------------------------------------------------------------------
	// find_or_create()
	// -------------------------------------------------------------------------

	public function test_find_or_create_creates_new_requester(): void {
		$id = $this->query->find_or_create( 'new@example.com', 'New User' );
		$this->assertGreaterThan( 0, $id );

		$item = $this->query->get_item( $id );
		$this->assertSame( 'new@example.com', $item->email );
	}

	public function test_find_or_create_returns_existing_id_for_known_email(): void {
		$first  = $this->query->find_or_create( 'same@example.com', 'First Name' );
		$second = $this->query->find_or_create( 'same@example.com', 'Different Name' );

		$this->assertSame( $first, $second );
	}

	public function test_find_or_create_does_not_create_duplicate(): void {
		$this->query->find_or_create( 'unique@example.com', 'User A' );
		$this->query->find_or_create( 'unique@example.com', 'User B' );

		$items = $this->query->get_items( [ 'email' => 'unique@example.com' ] );
		$this->assertCount( 1, $items );
	}

	// -------------------------------------------------------------------------
	// Linking requester rows to WordPress accounts
	// -------------------------------------------------------------------------

	public function test_link_to_user_links_guest_row(): void {
		$requester_id = (int) $this->query->find_or_create( 'guest@example.com', 'Guest' );
		$user_id      = self::factory()->user->create( [ 'user_email' => 'guest@example.com' ] );

		$this->assertTrue( $this->query->link_to_user( 'guest@example.com', $user_id ) );
		$this->assertSame( $requester_id, $this->query->find_by_wp_user( $user_id )->id );
	}

	public function test_link_to_user_does_not_repoint_a_linked_row(): void {
		$owner = self::factory()->user->create( [ 'user_email' => 'owner@example.com' ] );
		$other = self::factory()->user->create( [ 'user_email' => 'other@example.com' ] );

		$this->query->find_or_create( 'owner@example.com', 'Owner' );
		$this->query->link_to_user( 'owner@example.com', $owner );

		$this->assertFalse( $this->query->link_to_user( 'owner@example.com', $other ) );
		$this->assertNull( $this->query->find_by_wp_user( $other ) );
	}

	public function test_find_or_create_links_guest_row_for_logged_in_owner(): void {
		$requester_id = (int) $this->query->find_or_create( 'later@example.com', 'Later' );
		$user_id      = self::factory()->user->create( [ 'user_email' => 'later@example.com' ] );

		wp_set_current_user( $user_id );
		$this->query->find_or_create( 'later@example.com', 'Later' );

		$this->assertSame( $requester_id, $this->query->find_by_wp_user( $user_id )->id );
	}

	public function test_find_or_create_does_not_link_someone_elses_email(): void {
		$this->query->find_or_create( 'victim@example.com', 'Victim' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'attacker@example.com' ] );

		wp_set_current_user( $user_id );
		$this->query->find_or_create( 'victim@example.com', 'Attacker' );

		$this->assertNull( $this->query->find_by_wp_user( $user_id ) );
	}

	public function test_link_unlinked_to_matching_users_repairs_existing_rows(): void {
		$this->query->find_or_create( 'member@example.com', 'Member' );
		$this->query->find_or_create( 'guestonly@example.com', 'Guest' );
		$user_id = self::factory()->user->create( [ 'user_email' => 'member@example.com' ] );

		$this->assertSame( 1, $this->query->link_unlinked_to_matching_users() );
		$this->assertNotNull( $this->query->find_by_wp_user( $user_id ) );
	}

	// -------------------------------------------------------------------------
	// has_duplicate_subject()
	// -------------------------------------------------------------------------

	public function test_has_duplicate_subject_detects_duplicate_for_later_requester(): void {
		// The first requester gets the lowest ID; the bug this guards against
		// compared every submitter with that row.
		$this->query->find_or_create( 'first@example.com', 'First' );
		$second_id = (int) $this->query->find_or_create( 'second@example.com', 'Second' );

		$this->add_request( $second_id, 'Healing for my mother' );

		$this->assertTrue( $this->query->has_duplicate_subject( 'second@example.com', 'Healing for my mother' ) );
	}

	public function test_has_duplicate_subject_ignores_other_requesters_subjects(): void {
		$first_id = (int) $this->query->find_or_create( 'first@example.com', 'First' );
		$this->query->find_or_create( 'second@example.com', 'Second' );

		$this->add_request( $first_id, 'Healing for my mother' );

		$this->assertFalse( $this->query->has_duplicate_subject( 'second@example.com', 'Healing for my mother' ) );
	}

	public function test_has_duplicate_subject_is_false_for_unknown_email(): void {
		$this->assertFalse( $this->query->has_duplicate_subject( 'nobody@example.com', 'Anything' ) );
	}

	private function add_request( int $requester_id, string $subject ): void {
		( new \Intercessor\Database\Query\Prayer_Request_Query() )->add_item( [
			'requester_id' => $requester_id,
			'subject'      => $subject,
			'content'      => 'Fixture content.',
			'status'       => 'pending',
			'is_public'    => 1,
			'is_anonymous' => 0,
		] );
	}

	// -------------------------------------------------------------------------
	// get_items()
	// -------------------------------------------------------------------------

	public function test_get_items_filters_by_email(): void {
		$this->query->add_item( [ 'name' => 'Bob',   'email' => 'bob@example.com' ] );
		$this->query->add_item( [ 'name' => 'Carol', 'email' => 'carol@example.com' ] );

		$items = $this->query->get_items( [ 'email' => 'bob@example.com' ] );
		$this->assertCount( 1, $items );
		$this->assertSame( 'bob@example.com', $items[0]->email );
	}

	// -------------------------------------------------------------------------
	// delete_item()
	// -------------------------------------------------------------------------

	public function test_delete_item_removes_requester(): void {
		$id = (int) $this->query->add_item( [
			'name'  => 'Delete Me',
			'email' => 'deleteme@example.com',
		] );
		$this->query->delete_item( $id );

		$this->assertFalse( $this->query->get_item( $id ) );
	}

	// -------------------------------------------------------------------------
	// count_items()
	// -------------------------------------------------------------------------

	public function test_count_items_increases_after_add(): void {
		$before = $this->query->count_items( [] );
		$this->query->add_item( [ 'name' => 'Counter', 'email' => 'counter@example.com' ] );
		$after = $this->query->count_items( [] );

		$this->assertSame( $before + 1, $after );
	}
}
