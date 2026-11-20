<?php
/**
 * Table base class of Intercessor's custom-table data layer.
 *
 * Written for Intercessor and modelled on the structure of BerlinDB
 * (https://github.com/berlindb/core). It is not a copy of that library and
 * does not share its API; see wiki/Architecture.md for the differences.
 *
 * License: GPL-2.0-or-later
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\BerlinDB;

/**
 * Manages a single WordPress database table via dbDelta.
 *
 * Subclasses declare three properties to configure the table:
 * - $name    — table name without the global $wpdb->prefix
 * - $version — semver string; bump to trigger a schema upgrade
 * - $schema  — fully-qualified Schema subclass name
 *
 * On construction the class resolves the fully-qualified table name,
 * registers it as a named property on the global $wpdb object, and calls
 * upgrade() when the installed schema version stored in the WordPress options
 * table is behind the declared $version.
 *
 * @since   1.0.0
 * @package Intercessor
 */
abstract class Table {

	// -------------------------------------------------------------------------
	// Subclass-defined properties
	// -------------------------------------------------------------------------

	/**
	 * Table name without the global $wpdb->prefix.
	 *
	 * For example: 'intercessor_prayer_requests'. The global prefix is
	 * prepended automatically in __construct().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $name = '';

	/**
	 * Semver string used to track the installed schema version.
	 *
	 * Bump this value whenever the column set changes. The constructor
	 * compares it against the stored option and calls upgrade() when behind.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $version = '1.0.0';

	/**
	 * Fully-qualified Schema subclass name describing this table's columns.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $schema = '';

	// -------------------------------------------------------------------------
	// Runtime state
	// -------------------------------------------------------------------------

	/**
	 * Fully-qualified table name including $wpdb->prefix.
	 *
	 * Populated in __construct() as $wpdb->prefix . $this->name.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_name = '';

	/**
	 * Lazily-resolved Schema instance, populated on first call to getSchema().
	 *
	 * @since 1.0.0
	 * @var   Schema|null
	 */
	private ?Schema $schema_instance = null;

	/**
	 * WordPress option key used to persist the currently-installed schema version.
	 *
	 * Derived from the table name as '{name}_db_version'. Because every table
	 * $name already begins with the plugin prefix (e.g. 'intercessor_prayer_requests'),
	 * the resulting option name is prefixed and collision-safe in wp_options.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	private string $db_version_key = '';

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Resolve the full table name, register with $wpdb, and maybe upgrade the schema.
	 *
	 * Guards against duplicate $wpdb property and tables[] registration when
	 * the same table class is instantiated more than once per request (e.g.
	 * TableRegistry::register() followed by TableRegistry::install()).
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name     = $wpdb->prefix . $this->name;
		$this->db_version_key = $this->name . '_db_version';

		// Register as a named property on $wpdb so that
		// $wpdb->intercessor_prayer_requests etc. resolve correctly.
		if ( ! isset( $wpdb->{$this->name} ) ) {
			$wpdb->{$this->name} = $this->table_name;
		}

		// Add to $wpdb->tables only once to avoid duplicate entries.
		if ( ! in_array( $this->name, $wpdb->tables, true ) ) {
			$wpdb->tables[] = $this->name;
		}

		$this->maybeUpgrade();
	}

	// -------------------------------------------------------------------------
	// Schema helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the lazily-resolved Schema instance for this table.
	 *
	 * Constructs the class declared in $schema on the first call and caches
	 * it for subsequent calls within the same request.
	 *
	 * @since  1.0.0
	 * @return Schema Configured schema instance describing this table's columns.
	 */
	protected function getSchema(): Schema {
		if ( $this->schema_instance === null ) {
			$schemaClass           = $this->schema;
			$this->schema_instance = new $schemaClass();
		}

		return $this->schema_instance;
	}

	// -------------------------------------------------------------------------
	// Install / upgrade
	// -------------------------------------------------------------------------

	/**
	 * Run upgrade() only when the installed schema version is behind $version.
	 *
	 * Reads the stored version from the WordPress options table and compares
	 * it to $version using version_compare(). Called automatically from
	 * __construct().
	 *
	 * @since  1.0.0
	 * @return void
	 */
	protected function maybeUpgrade(): void {
		$installed = (string) get_option( $this->db_version_key, '0.0.0' );

		if ( version_compare( $installed, $this->version, '<' ) ) {
			$this->upgrade();
		}
	}

	/**
	 * Build and execute the CREATE TABLE statement via dbDelta.
	 *
	 * Subclasses may override this method to add ALTER TABLE statements for
	 * column or index migrations. The override must call parent::upgrade() at
	 * the end to run dbDelta and persist the updated version number.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function upgrade(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$sql = $this->buildCreateSql();

		if ( ! empty( $sql ) ) {
			dbDelta( $sql );
			update_option( $this->db_version_key, $this->version );
		}
	}

	/**
	 * Build the CREATE TABLE SQL string consumed by dbDelta.
	 *
	 * dbDelta has strict formatting requirements: two spaces before PRIMARY KEY,
	 * each column and key definition on its own line, and the charset collate
	 * appended after the closing parenthesis.
	 *
	 * @since  1.0.0
	 * @return string Complete CREATE TABLE SQL, or an empty string when no columns are defined.
	 */
	protected function buildCreateSql(): string {
		global $wpdb;

		$schema  = $this->getSchema();
		$columns = $schema->getColumns();

		if ( empty( $columns ) ) {
			return '';
		}

		$columnDefs = [];
		$keys       = [];

		foreach ( $columns as $column ) {
			$columnDefs[] = $column->getDefinition();

			if ( $column->primary ) {
				// dbDelta requires exactly two spaces before PRIMARY KEY.
				$keys[] = "PRIMARY KEY  ({$column->name})";
			} elseif ( $column->isIndexed() ) {
				$keys[] = "KEY {$column->name} ({$column->name})";
			}
		}

		$allDefs = array_merge( $columnDefs, $keys );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$this->table_name} (\n"
			. implode( ",\n", $allDefs ) . "\n"
			. ") {$charset};\n";
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/**
	 * Return the schema version currently stored in the WordPress options table.
	 *
	 * Falls back to '0.0.0' when no version has been stored, which triggers an
	 * upgrade on the next request.
	 *
	 * @since  1.0.0
	 * @return string Installed schema version string (semver format).
	 */
	public function getVersion(): string {
		return (string) get_option( $this->db_version_key, '0.0.0' );
	}

	/**
	 * Return the fully-qualified table name including the global $wpdb->prefix.
	 *
	 * @since  1.0.0
	 * @return string Fully-qualified table name, e.g. 'wp_intercessor_prayer_requests'.
	 */
	public function getTableName(): string {
		return $this->table_name;
	}
}
