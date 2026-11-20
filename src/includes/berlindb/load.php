<?php
/**
 * Loader for Intercessor's custom-table data layer.
 *
 * The classes live in the Intercessor\BerlinDB namespace and follow the
 * structure of BerlinDB, but they are Intercessor's own implementation, not
 * the upstream library. Loads the class files in dependency order and guards
 * against double-loading them within Intercessor.
 *
 * Load order matches class inheritance:
 * Column → Schema → Row → Table → Query
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Guard: do not load bundled classes twice.
if ( class_exists( 'Intercessor\\BerlinDB\\Table', false ) ) {
	return;
}

$intercessor_berlin_db_src = __DIR__ . '/core/src/';

/**
 * Ordered list of BerlinDB core class files to load.
 *
 * Files are required in dependency order so that parent classes exist
 * before any child class is parsed.
 *
 * @var string[]
 */
$intercessor_berlin_db_files = array(
	'Column.php',
	'Schema.php',
	'Row.php',
	'Table.php',
	'Query.php',
);

foreach ( $intercessor_berlin_db_files as $intercessor_berlin_db_file ) {
	$intercessor_berlin_db_path = $intercessor_berlin_db_src . $intercessor_berlin_db_file;

	if ( ! file_exists( $intercessor_berlin_db_path ) ) {
		wp_die(
			sprintf(
				/* translators: %s: absolute path to the missing file */
				esc_html__( 'Intercessor: Required BerlinDB file missing: %s', 'intercessor' ),
				esc_html( $intercessor_berlin_db_path )
			)
		);
	}

	require_once $intercessor_berlin_db_path;
}
