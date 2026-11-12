<?php
/**
 * Query class for the requesters table.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Database\Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\BerlinDB\Query;
use Intercessor\Database\Row\Requester;
use Intercessor\Database\Schema\Requesters_Schema;
use Intercessor\Database\Query\Prayer_Request_Query;

/**
 * Provides CRUD and domain-specific query methods for the requesters table.
 *
 * @since   1.0.0
 * @package Intercessor
 *
 * @method Requester|false get_item( int $id )
 * @method Requester[]     get_items( array $args = [] )
 * @method int|false       add_item( array $data )
 * @method bool            update_item( int $id, array $data )
 * @method bool            delete_item( int $id )
 * @method int             count_items( array $args = [] )
 */
final class Requester_Query extends Query {

	/**
	 * Shared prefix for all Intercessor table names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $prefix = 'intercessor';

	/**
	 * Table name segment (appended to prefix + $wpdb->prefix).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_name = 'requesters';

	/**
	 * Short SQL alias used in query fragments.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_alias = 'rq';

	/**
	 * Fully-qualified Schema subclass name.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_schema = Requesters_Schema::class;

	/**
	 * Singular item label used for cache keys and hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_name = 'requester';

	/**
	 * Plural item label used for cache keys and hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_name_plural = 'requesters';

	/**
	 * Fully-qualified Row subclass instantiated for each result.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_shape = Requester::class;

	/**
	 * Find an existing requester by email, or create one if none exists.
	 *
	 * Used by Public_Loader and Rest_Api to ensure each form submission is
	 * associated with exactly one requester record per email address. When a
	 * match is found, returns its ID without modifying the existing row.
	 *
	 * The combined name is written to the legacy name column for backward
	 * compatibility with any code that reads it directly.
	 *
	 * @since  1.0.0
	 * @since  1.1.3 Links an existing guest row to the logged-in user when
	 *               the email is that user's own.
	 * @param  string $email      Sanitized email address of the submitter.
	 * @param  string $first_name First name provided at submission time.
	 * @param  string $last_name  Last name provided at submission time.
	 * @return int|false          Requester primary key on success; false on DB error.
	 */
	public function find_or_create( string $email, string $first_name, string $last_name = '' ): int|false {
		$existing = $this->get_items(
			array(
				'email'  => $email,
				'number' => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			// A guest row that belongs to the person now logged in gets
			// linked, so requests they made before registering show up in
			// their history. Only when the email is their own account's:
			// a logged-in user typing someone else's address must not
			// acquire that person's requester row.
			if ( ! $existing[0]->is_linked_to_user() && is_user_logged_in() ) {
				$user = wp_get_current_user();

				if ( 0 === strcasecmp( (string) $user->user_email, $email ) ) {
					$this->link_to_user( $email, (int) $user->ID );
				}
			}

			return $existing[0]->id;
		}

		$full_name = trim( $first_name . ' ' . $last_name );

		return $this->add_item(
			array(
				'email'      => $email,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'name'       => $full_name ?: $email,
				'wp_user_id' => get_current_user_id(),
			)
		);
	}

	/**
	 * Link the unlinked requester row for an email address to a WordPress user.
	 *
	 * Does nothing when the user already has a requester row, so one account
	 * never ends up with two, and never re-points a row that is already
	 * linked to somebody. Callers are responsible for only passing an email
	 * address that belongs to the given user.
	 *
	 * @since  1.1.3
	 * @param  string $email   Email address stored on the requester row.
	 * @param  int    $user_id WordPress user ID to link the row to.
	 * @return bool            True when a row was linked; false otherwise.
	 */
	public function link_to_user( string $email, int $user_id ): bool {
		if ( '' === $email || $user_id <= 0 ) {
			return false;
		}

		if ( $this->find_by_wp_user( $user_id ) !== null ) {
			return false;
		}

		$unlinked = $this->get_items(
			array(
				'email'      => $email,
				'wp_user_id' => 0,
				'number'     => 1,
			)
		);

		if ( empty( $unlinked ) ) {
			return false;
		}

		return $this->update_item( $unlinked[0]->id, array( 'wp_user_id' => $user_id ) );
	}

	/**
	 * Link every unlinked requester row whose email matches a WordPress account.
	 *
	 * One-time repair for installs upgraded from a release before 1.1.3, where
	 * accounts created through the prayer form were never linked to the
	 * requester row created by the same submission.
	 *
	 * @since  1.1.3
	 * @return int Number of requester rows linked.
	 */
	public function link_unlinked_to_matching_users(): int {
		$unlinked = $this->get_items(
			array(
				'wp_user_id' => 0,
				'number'     => 0,
				'orderby'    => 'id',
				'order'      => 'ASC',
			)
		);

		$linked = 0;

		foreach ( $unlinked as $requester ) {
			$user = get_user_by( 'email', $requester->email );

			if ( $user && $this->link_to_user( $requester->email, (int) $user->ID ) ) {
				$linked++;
			}
		}

		return $linked;
	}

	/**
	 * Find a requester row linked to a specific WordPress user account.
	 *
	 * Returns the first match ordered by the default sort (date_created ASC).
	 * Returns null when no row has the given wp_user_id.
	 *
	 * @since  1.0.0
	 * @param  int           $userId WordPress user ID to search for.
	 * @return Requester|null        Matching requester row, or null if not found.
	 */
	public function find_by_wp_user( int $userId ): ?Requester {
		$results = $this->get_items(
			array(
				'wp_user_id' => $userId,
				'number'     => 1,
			)
		);

		return $results[0] ?? null;
	}

	/**
	 * Check whether a requester (identified by email) has already submitted
	 * a prayer request with the same subject line.
	 *
	 * Used by Submission_Pipeline to block duplicate submissions when the
	 * "Prevent Duplicate Requests" setting is enabled.
	 *
	 * Returns false immediately when no requester record exists for the given
	 * email — a first-time submitter can never have a duplicate.
	 *
	 * @since  1.0.1
	 * @since  1.1.3 Reads the requester ID from the returned row. The query
	 *               previously passed a 'fields' argument that this query
	 *               class does not support and cast the returned row object
	 *               to int, which always produced 1, so every submitter was
	 *               compared against requester #1.
	 * @param  string $email   Sanitized email address of the submitter.
	 * @param  string $subject Sanitized subject line of the new request.
	 * @return bool            True when a duplicate exists; false otherwise.
	 */
	public function has_duplicate_subject( string $email, string $subject ): bool {
		if ( '' === $email || '' === $subject ) {
			return false;
		}

		$existing = $this->get_items(
			array(
				'email'  => $email,
				'number' => 1,
			)
		);

		if ( empty( $existing ) ) {
			return false;
		}

		$requester_id = (int) $existing[0]->id;

		$prayer_query = new Prayer_Request_Query();
		$matches      = $prayer_query->get_items(
			array(
				'requester_id' => $requester_id,
				'subject'      => $subject,
				'number'       => 1,
			)
		);

		return ! empty( $matches );
	}
}
