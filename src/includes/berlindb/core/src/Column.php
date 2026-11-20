<?php
/**
 * Column base class of Intercessor's custom-table data layer.
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
 * Represents a single column definition within a BerlinDB Schema.
 *
 * Each Column instance carries all metadata needed to generate a dbDelta-
 * compatible SQL fragment, validate query arguments, and guide the Query
 * layer's auto-population and ORDER BY whitelist logic. Columns are
 * constructed by Schema::getColumns() from the raw array definitions
 * declared in each Schema subclass.
 *
 * @since   1.0.0
 * @package Intercessor
 */
class Column {

	// -------------------------------------------------------------------------
	// Core identity
	// -------------------------------------------------------------------------

	/**
	 * Column name as it appears in the database table.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public string $name = '';

	/**
	 * SQL data type string (e.g. 'varchar', 'bigint', 'longtext', 'datetime', 'tinyint').
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public string $type = 'varchar';

	/**
	 * Column length or precision value (e.g. '255', '20').
	 *
	 * Empty string omits the length clause from the type definition.
	 *
	 * @since 1.0.0
	 * @var   string|int
	 */
	public string|int $length = '';

	/**
	 * Whether the column should carry the UNSIGNED modifier.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $unsigned = false;

	/**
	 * Whether the column allows NULL values.
	 *
	 * When false (default), NOT NULL is appended to the column definition.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $allow_null = false;

	/**
	 * Default value for the column emitted as DEFAULT 'value' in SQL.
	 *
	 * Skipped for columns flagged as 'created' or 'modified' since those
	 * are populated at runtime by the Query layer.
	 *
	 * @since 1.0.0
	 * @var   mixed
	 */
	public mixed $default = '';

	/**
	 * Extra SQL appended after the default clause (e.g. 'auto_increment').
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	public string $extra = '';

	// -------------------------------------------------------------------------
	// Key flags
	// -------------------------------------------------------------------------

	/**
	 * Whether this column is the table's PRIMARY KEY.
	 *
	 * Exactly one column per schema should carry this flag.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $primary = false;

	/**
	 * Whether a KEY index should be created on this column.
	 *
	 * Also controls inclusion in the searchable columns list used
	 * for LIKE search queries.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $searchable = false;

	// -------------------------------------------------------------------------
	// Query behaviour flags
	// -------------------------------------------------------------------------

	/**
	 * Whether this column is whitelisted for ORDER BY in get_items() queries.
	 *
	 * Also causes a KEY index to be generated. Columns that are sortable but
	 * not searchable will still receive a KEY index.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $sortable = false;

	/**
	 * Whether date_query clauses can be applied to this column.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $date_query = false;

	/**
	 * Whether the Query layer should auto-populate this column on INSERT.
	 *
	 * When true, the column receives the current UTC datetime if not
	 * explicitly included in the add_item() data array.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $created = false;

	/**
	 * Whether the Query layer should auto-update this column on UPDATE.
	 *
	 * When true, the column receives the current UTC datetime if not
	 * explicitly included in the update_item() data array.
	 *
	 * @since 1.0.0
	 * @var   bool
	 */
	public bool $modified = false;

	/**
	 * Optional PHP type hint for casting raw database values after retrieval.
	 *
	 * @since 1.0.0
	 * @var   string|null
	 */
	public ?string $validate = null;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Initialise a column from an associative array of property values.
	 *
	 * Only keys that match declared public properties are applied; unknown
	 * keys are silently ignored, making forward-compatible schema definitions
	 * possible.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $args Column configuration array from a Schema subclass.
	 */
	public function __construct( array $args = [] ) {
		foreach ( $args as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}

	// -------------------------------------------------------------------------
	// SQL helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the full inline SQL column definition for use in CREATE TABLE / ALTER TABLE.
	 *
	 * Assembles the type string, NOT NULL constraint, DEFAULT clause, and any
	 * extra SQL (e.g. auto_increment) in dbDelta-compatible order.
	 *
	 * @since  1.0.0
	 * @return string e.g. "`id` bigint(20) unsigned NOT NULL auto_increment"
	 */
	public function getDefinition(): string {
		$parts = [ "`{$this->name}`", $this->typeString() ];

		if ( ! $this->allow_null ) {
			$parts[] = 'NOT NULL';
		}

		if ( $this->default !== '' && ! $this->created && ! $this->modified ) {
			$parts[] = "DEFAULT '{$this->default}'";
		}

		if ( $this->extra !== '' ) {
			$parts[] = $this->extra;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Build the SQL type+length+unsigned fragment used inside getDefinition().
	 *
	 * @since  1.0.0
	 * @return string e.g. "bigint(20) unsigned" or "varchar(255)"
	 */
	protected function typeString(): string {
		$type = $this->type;

		if ( $this->length !== '' ) {
			$type .= "({$this->length})";
		}

		if ( $this->unsigned ) {
			$type .= ' unsigned';
		}

		return $type;
	}

	/**
	 * Return whether this column should receive a KEY index in the CREATE TABLE SQL.
	 *
	 * A column is indexed when it is either sortable or searchable. Primary key
	 * columns are handled separately by Table::buildCreateSql() and are excluded.
	 *
	 * @since  1.0.0
	 * @return bool True when a KEY index should be generated for this column.
	 */
	public function isIndexed(): bool {
		return $this->searchable || $this->sortable;
	}
}
