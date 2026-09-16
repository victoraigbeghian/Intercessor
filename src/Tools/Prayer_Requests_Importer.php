<?php
/**
 * Prayer requests CSV importer.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Tools;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Database\Query\Prayer_Request_Query;
use Intercessor\Database\Query\Requester_Query;

/**
 * Imports prayer requests from a CSV file.
 *
 * Accepts both the format produced by Prayer_Requests_Exporter and a simpler
 * "quick import" format with only the minimal required columns. For each data
 * row the importer:
 *
 *   1. Finds or creates a Requester record by email address (using the
 *      existing Requester_Query::find_or_create() upsert helper).
 *   2. Inserts a new prayer_requests row, preserving the exported status,
 *      anonymous/public flags, moderator note, and content (when present).
 *
 * Required columns (must be in the header row):
 *   Subject, Status, Requester Email
 *
 * Optional columns (used when present, ignored otherwise):
 *   Prayer Content, Requester Name, Anonymous, Public, Moderator Note,
 *   Date Submitted
 *
 * Rows are always inserted as new records — the importer never updates
 * existing rows. Duplicate detection is limited to the requester level
 * (same email → same requester ID); duplicate prayer subjects are allowed.
 *
 * @since   1.0.0
 * @package Intercessor
 */
final class Prayer_Requests_Importer extends Abstract_Importer {

	/**
	 * Return the import key used for nonce actions and hook names.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	protected function import_key(): string {
		return 'prayer_requests';
	}

	/**
	 * Return the minimum set of CSV column headers this importer requires.
	 *
	 * Rows missing any of these are rejected at the column-validation stage
	 * before any data is written.
	 *
	 * @since  1.0.0
	 * @return string[]
	 */
	protected function required_columns(): array {
		return array( 'Subject', 'Status', 'Requester Email' );
	}

	/**
	 * Process a single CSV data row.
	 *
	 * Finds or creates the requester by email, then inserts a prayer request
	 * row. Increments the appropriate result counter and appends to $this->errors
	 * on failure.
	 *
	 * @since  1.0.0
	 * @param  array<string, string> $row  Associative row keyed by CSV header.
	 * @param  int                   $line 1-based line number for error messages.
	 * @return void
	 */
	protected function process_row( array $row, int $line ): void {
		$subject = sanitize_text_field( $row['Subject'] ?? '' );
		$status  = sanitize_key( $row['Status'] ?? 'pending' );
		$email   = sanitize_email( $row['Requester Email'] ?? '' );

		// Validate required fields.
		if ( $subject === '' ) {
			$this->failed++;
			// translators: %d: CSV line number
			$this->errors[] = sprintf(
				/* translators: %d: line number in the CSV */
				__( 'Line %1$d: Subject is empty — row skipped.', 'intercessor' ),
				$line
			);
			return;
		}

		if ( $email === '' || ! is_email( $email ) ) {
			$this->failed++;
			// translators: %d: CSV line number, %s: invalid email value
			$this->errors[] = sprintf(
				/* translators: 1: line number, 2: email value */
				__( 'Line %1$d: Invalid or missing Requester Email ("%2$s") — row skipped.', 'intercessor' ),
				$line,
				esc_html( $row['Requester Email'] ?? '' )
			);
			return;
		}

		// Normalise status — fall back to 'pending' for unknown values.
		if ( ! in_array( $status, Prayer_Request_Query::VALID_STATUSES, true ) ) {
			$status = 'pending';
		}

		// Optional columns.
		$content        = sanitize_textarea_field( $row['Prayer Content'] ?? '' );
		$first_name     = sanitize_text_field( $row['First Name'] ?? ( $row['Requester Name'] ?? '' ) );
		$last_name      = sanitize_text_field( $row['Last Name']  ?? '' );
		$is_anonymous   = $this->parse_bool( $row['Anonymous'] ?? '' ) ? 1 : 0;
		$is_public      = $this->parse_bool( $row['Public']    ?? '1', true ) ? 1 : 0;
		$mod_note       = sanitize_textarea_field( $row['Moderator Note'] ?? '' );

		// Upsert requester: find existing by email or create a new record.
		$rq_query     = new Requester_Query();
		$requester_id = $rq_query->find_or_create( $email, $first_name, $last_name );

		if ( ! $requester_id ) {
			$this->failed++;
			// translators: %d: CSV line number, %s: email address
			$this->errors[] = sprintf(
				/* translators: 1: line number, 2: email */
				__( 'Line %1$d: Could not find or create requester for "%2$s".', 'intercessor' ),
				$line,
				esc_html( $email )
			);
			return;
		}

		// Build the prayer request insert payload.
		$data = array(
			'requester_id'  => $requester_id,
			'subject'       => $subject,
			'content'       => $content,
			'status'        => $status,
			'is_anonymous'  => $is_anonymous,
			'is_public'     => $is_public,
			'moderator_note'=> $mod_note,
		);

		$pr_query = new Prayer_Request_Query();
		$new_id   = $pr_query->add_item( $data );

		if ( $new_id ) {
			$this->imported++;
		} else {
			$this->failed++;
			// translators: %d: CSV line number, %s: prayer request subject
			$this->errors[] = sprintf(
				/* translators: 1: line number, 2: subject */
				__( 'Line %1$d: Database insert failed for "%2$s".', 'intercessor' ),
				$line,
				esc_html( $subject )
			);
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Parse a CSV cell value as a boolean.
	 *
	 * Recognises the localised export values ('Yes', 'No', 'yes', 'no', '1',
	 * '0') as well as raw integer booleans. Falls back to $default for any
	 * unrecognised value.
	 *
	 * @since  1.0.0
	 * @param  string $value   Raw CSV cell value.
	 * @param  bool   $default Value to return when $value is not recognised.
	 * @return bool
	 */
	private function parse_bool( string $value, bool $default = false ): bool {
		$v = strtolower( trim( $value ) );

		if ( in_array( $v, array( '1', 'yes', 'true' ), true ) ) {
			return true;
		}

		if ( in_array( $v, array( '0', 'no', 'false', '' ), true ) ) {
			return false;
		}

		return $default;
	}
}
