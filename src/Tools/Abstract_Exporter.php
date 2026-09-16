<?php
/**
 * Abstract CSV exporter base class.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Tools;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Intercessor\Http\Request;

/**
 * Abstract base class for all Intercessor CSV exporters.
 *
 * Defines the Template Method pattern for CSV generation: concrete subclasses
 * implement getFilename(), getHeaders(), and getRows(), while this class
 * handles all shared concerns — nonce verification, capability enforcement,
 * HTTP header emission, output buffer management, UTF-8 BOM injection, and
 * safe fputcsv streaming.
 *
 * Each exporter is paired with an admin_post_{action} handler registered by
 * Tools_Admin_Page::register(). The dispatch() method is the single entry point
 * called from that handler.
 *
 * @since   1.0.0
 * @package Intercessor
 */
abstract class Abstract_Exporter {

	// -------------------------------------------------------------------------
	// Subclass contract
	// -------------------------------------------------------------------------

	/**
	 * Return the CSV download filename (without directory path).
	 *
	 * Implementations should embed the current UTC date so downloads are
	 * distinguishable when saved locally, e.g. 'intercessor-requests-2025-01-01.csv'.
	 *
	 * @since  1.0.0
	 * @return string Filename used in the Content-Disposition HTTP header.
	 */
	abstract protected function get_filename(): string;

	/**
	 * Return the ordered list of column header labels for the CSV.
	 *
	 * The number of elements must match the number of values in each row
	 * returned by getRows().
	 *
	 * @since  1.0.0
	 * @return string[] Ordered column header labels.
	 */
	abstract protected function get_headers(): array;

	/**
	 * Return all data rows to be written to the CSV file.
	 *
	 * Each element must be an array of scalar values in exactly the same
	 * order as the headers returned by getHeaders(). fputcsv handles all
	 * quoting and escaping.
	 *
	 * @since  1.0.0
	 * @return array<int, array<int, scalar>> Indexed list of scalar value arrays.
	 */
	abstract protected function get_rows(): array;

	// -------------------------------------------------------------------------
	// Nonce and capability helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the WordPress nonce action string for this exporter.
	 *
	 * Derived from exportKey() by default. Subclasses may override to use
	 * a custom action string that does not follow the default pattern.
	 *
	 * @since  1.0.0
	 * @return string Nonce action string, e.g. 'intercessor_export_settings'.
	 */
	protected function nonce_action(): string {
		return 'intercessor_export_' . $this->export_key();
	}

	/**
	 * Return a short slug identifying this exporter, used in nonces and hooks.
	 *
	 * Derived automatically from the concrete class short name by stripping
	 * the 'Exporter' suffix and lower-casing the remainder. For example,
	 * SettingsExporter produces 'settings'.
	 *
	 * @since  1.0.0
	 * @return string Lower-case exporter slug.
	 */
	protected function export_key(): string {
		$bare = ( new \ReflectionClass( $this ) )->getShortName();
		return strtolower( str_replace( 'Exporter', '', $bare ) );
	}

	/**
	 * Return the WordPress capability required to run this export.
	 *
	 * Defaults to 'export_prayer_reports'. Override in a subclass to restrict
	 * a particular export to a different capability.
	 *
	 * @since  1.0.0
	 * @return string WordPress capability string.
	 */
	protected function required_capability(): string {
		return 'export_prayer_reports';
	}

	// -------------------------------------------------------------------------
	// Public dispatch entry point
	// -------------------------------------------------------------------------

	/**
	 * Verify security, then stream the CSV file to the browser and exit.
	 *
	 * This is the single method called from the admin_post_{action} handler
	 * registered by ToolsAdminPage. It enforces the capability check and nonce
	 * verification before delegating to streamCsv(). Always calls exit after
	 * streaming to prevent WordPress from appending HTML.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function dispatch(): void {
		$this->check_capability();
		$this->verify_nonce();
		$this->stream_csv();
		exit;
	}

	// -------------------------------------------------------------------------
	// Security enforcement
	// -------------------------------------------------------------------------

	/**
	 * Halt with a 403 wp_die() if the current user lacks the required capability.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function check_capability(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to export data.', 'intercessor' ),
				403
			);
		}
	}

	/**
	 * Halt with a 403 wp_die() if the posted nonce is absent or invalid.
	 *
	 * Reads '_wpnonce' from $_POST, sanitizes it, and verifies it against
	 * the action string returned by nonceAction().
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function verify_nonce(): void {
		$req = Request::capture();
		$req->require_nonce( $this->nonce_action() );
	}

	// -------------------------------------------------------------------------
	// CSV streaming
	// -------------------------------------------------------------------------

	/**
	 * Emit HTTP response headers and write the CSV data to the output buffer.
	 *
	 * Discards any existing PHP output buffer first to prevent BOM or
	 * whitespace corruption from theme output, debug bars, or error notices.
	 * Writes a UTF-8 BOM (0xEF 0xBB 0xBF) before the first row so that
	 * Microsoft Excel opens the file with correct character encoding.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function stream_csv(): void {
		// Prevent header corruption from prior output.
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Cannot export CSV: headers already sent.', 'intercessor' ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$filename = sanitize_file_name( $this->get_filename() );

		nocache_headers();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Could not open output stream for CSV export.', 'intercessor' ) );
		}

		// Excel UTF-8 BOM.
		fprintf( $output, '%s', "\xEF\xBB\xBF" );

		fputcsv( $output, $this->get_headers() );

		// STREAMING: avoids loading full dataset into memory
		foreach ( $this->get_rows_stream() as $row ) {
			fputcsv( $output, $row );
		}

		exit;
	}

	/**
	 * Return a generator that yields one CSV row at a time.
	 *
	 * The default implementation retrieves all rows from get_rows() — the
	 * method every concrete exporter actually implements — and yields them
	 * one by one. This is the method stream_csv() calls, so it MUST route
	 * through get_rows() by default or a subclass's get_rows() is silently
	 * never invoked.
	 *
	 * Declared protected (not private) so a subclass whose result set is too
	 * large to build in memory (e.g. exporting hundreds of thousands of rows)
	 * can override this method with a true LIMIT/OFFSET streaming query. No
	 * current exporter needs that — they already call get_items() with
	 * 'number' => 0, which loads the full set into memory before get_rows()
	 * even returns — so the passthrough below is correct for all of them
	 * today.
	 *
	 * @since  1.0.0
	 * @since  1.0.2 Fixed to actually call get_rows() (previously ignored
	 *               get_rows() entirely and queried the prayer_requests
	 *               table directly regardless of which exporter was running).
	 * @return \Generator Yields arrays of scalar values for each CSV row.
	 */
	protected function get_rows_stream(): \Generator {
		yield from $this->get_rows();
	}

	// -------------------------------------------------------------------------
	// Form helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the HTML for a hidden nonce input field for embedding in export forms.
	 *
	 * Passes $echo = false to wp_nonce_field() so the HTML is returned as a
	 * string rather than being printed. The template is responsible for echoing.
	 *
	 * @since  1.0.0
	 * @return string HTML <input type="hidden"> element with a fresh nonce value.
	 */
	public function nonce_field(): string {
		return wp_nonce_field( $this->nonce_action(), '_wpnonce', true, false );
	}

	/**
	 * Return a fresh nonce value string (not a full HTML field).
	 *
	 * Useful when embedding the nonce in JavaScript data attributes rather
	 * than an HTML form field.
	 *
	 * @since  1.0.0
	 * @return string Nonce value string.
	 */
	public function create_nonce(): string {
		return wp_create_nonce( $this->nonce_action() );
	}
}
