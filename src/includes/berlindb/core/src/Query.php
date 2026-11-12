<?php
/**
 * Query base class of Intercessor's custom-table data layer.
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
 * ORM-like CRUD and query interface for a single BerlinDB-managed table.
 *
 * Provides five base CRUD methods (add_item, get_item, get_items, update_item,
 * delete_item) plus count_items, all backed by $wpdb and driven by the
 * Schema declared in $table_schema. Subclasses configure the query target by
 * declaring the properties listed below and may add domain-specific methods
 * that compose the base methods.
 *
 * Subclass required properties:
 * - $prefix           — shared table prefix segment (e.g. 'intercessor')
 * - $table_name       — bare table name without the global DB prefix or $prefix
 * - $table_alias      — short alias used in SQL fragments (e.g. 'pr')
 * - $table_schema     — fully-qualified Schema subclass name
 * - $item_name        — singular item label for cache keys and WP hooks
 * - $item_name_plural — plural item label
 * - $item_shape       — fully-qualified Row subclass to instantiate per result
 *
 * @since   1.0.0
 * @package Intercessor
 */
abstract class Query {

	// -------------------------------------------------------------------------
	// Subclass-defined properties
	// -------------------------------------------------------------------------

	/**
	 * Prefix segment shared across all tables in the same plugin (e.g. 'intercessor').
	 *
	 * Combined with $table_name to form the bare table name that is appended
	 * to $wpdb->prefix in the constructor.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $prefix = '';

	/**
	 * Table name segment without the global $wpdb->prefix or $prefix.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_name = '';

	/**
	 * Short SQL alias used in query fragments (currently informational only).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_alias = '';

	/**
	 * Fully-qualified Schema subclass name used to describe this table's columns.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $table_schema = '';

	/**
	 * Singular item label used for cache keys and WordPress hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_name = '';

	/**
	 * Plural item label used for cache keys and WordPress hook names.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_name_plural = '';

	/**
	 * Fully-qualified Row subclass instantiated for each database result.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $item_shape = Row::class;

	// -------------------------------------------------------------------------
	// Runtime state
	// -------------------------------------------------------------------------

	/**
	 * Fully-qualified table name resolved at construction time.
	 *
	 * Formed as $wpdb->prefix . $prefix . '_' . $table_name (or just
	 * $wpdb->prefix . $table_name when $prefix is empty).
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	protected string $fq_table_name = '';

	/**
	 * Lazily resolved Schema instance, cached after first access.
	 *
	 * @since 1.0.0
	 * @var   Schema|null
	 */
	private ?Schema $schema_instance = null;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * Resolve and cache the fully-qualified table name.
	 *
	 * Supports both '{prefix}_{table}' and standalone '{table}' naming
	 * conventions via the $prefix property.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$bare = $this->prefix !== ''
			? "{$this->prefix}_{$this->table_name}"
			: $this->table_name;

		$this->fq_table_name = $wpdb->prefix . $bare;
	}

	// -------------------------------------------------------------------------
	// Schema helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the lazily-resolved Schema instance.
	 *
	 * Constructs the Schema class declared in $table_schema on first call
	 * and caches it for subsequent calls within the same request.
	 *
	 * @since  1.0.0
	 * @return Schema Configured schema instance for this table.
	 */
	protected function getSchema(): Schema {
		if ( $this->schema_instance === null ) {
			$class                 = $this->table_schema;
			$this->schema_instance = new $class();
		}

		return $this->schema_instance;
	}

	/**
	 * Return the name of the primary key column for this table.
	 *
	 * Delegates to Schema::getPrimaryColumn() and falls back to 'id' when
	 * no column is flagged as primary.
	 *
	 * @since  1.0.0
	 * @return string Primary key column name.
	 */
	protected function getPrimaryKey(): string {
		return $this->getSchema()->getPrimaryColumn()?->name ?? 'id';
	}

	// -------------------------------------------------------------------------
	// CREATE
	// -------------------------------------------------------------------------

	/**
	 * Insert a new row into the table.
	 *
	 * Date columns flagged as 'created' or 'modified' in the Schema are
	 * automatically set to the current UTC datetime when not explicitly
	 * provided in $data. All keys in $data are validated against the schema
	 * column list before the INSERT is executed; unknown keys are silently
	 * dropped.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data Column name to value pairs for the new row.
	 * @return int|false                  Auto-increment ID of the new row, or false on failure.
	 */
	public function add_item( array $data ): int|false {
		global $wpdb;

		$data = $this->injectDateColumns( $data );
		$data = $this->sanitizeData( $data );

		$result = $wpdb->insert( $this->fq_table_name, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $result === false ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------------
	// READ (single)
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a single row by its primary key value.
	 *
	 * Returns a hydrated Row subclass instance on success, or false when
	 * no row with the given primary key exists.
	 *
	 * @since  1.0.0
	 * @param  int        $id Primary key value to look up.
	 * @return Row|false      Hydrated Row object, or false when not found.
	 */
	public function get_item( int $id ): Row|false {
		global $wpdb;

		$pk = $this->getPrimaryKey();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE %i = %d LIMIT 1',
				$this->fq_table_name,
				$pk,
				$id
			)
		);

		if ( $row === null ) {
			return false;
		}

		return $this->shapeItem( $row );
	}

	// -------------------------------------------------------------------------
	// READ (collection)
	// -------------------------------------------------------------------------

	/**
	 * Query the table and return an array of matching row objects.
	 *
	 * Supported $args keys:
	 * - Any column name — exact match (scalar) or IN match (array)
	 * - 'number'  (int)    — LIMIT; 0 means no limit
	 * - 'offset'  (int)    — OFFSET for pagination
	 * - 'orderby' (string) — column name; validated against sortable columns
	 * - 'order'   (string) — 'ASC' or 'DESC'
	 * - 'search'  (string) — LIKE search across all searchable columns
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $args {
	 *     Optional query arguments.
	 *
	 *     @type int    $number  Maximum rows to return; 0 = unlimited. Default 20.
	 *     @type int    $offset  Number of rows to skip for pagination. Default 0.
	 *     @type string $orderby Column to sort by. Default: primary key column.
	 *     @type string $order   Sort direction: 'ASC' or 'DESC'. Default 'DESC'.
	 *     @type string $search  LIKE search term applied across searchable columns.
	 * }
	 * @return Row[] Array of hydrated Row subclass instances.
	 */
	public function get_items( array $args = [] ): array {
		global $wpdb;

		[ $where, $values ] = $this->buildWhere( $args );

		$orderby = $this->sanitizeOrderby( $args['orderby'] ?? $this->getPrimaryKey() );
		$order   = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$number  = isset( $args['number'] ) ? max( 0, (int) $args['number'] ) : 20;
		$offset  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$this->fq_table_name}{$where} ORDER BY {$orderby} {$order}";

		if ( $number > 0 ) {
			$sql .= " LIMIT {$number} OFFSET {$offset}";
		}

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $sql );

		if ( empty( $rows ) ) {
			return [];
		}

		return array_map( [ $this, 'shapeItem' ], $rows );
	}

	// -------------------------------------------------------------------------
	// COUNT
	// -------------------------------------------------------------------------

	/**
	 * Count rows matching the given criteria.
	 *
	 * Accepts the same filter arguments as get_items() (column exact matches
	 * and 'search'), but ignores all pagination arguments (number, offset,
	 * orderby, order) to produce an accurate total.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $args Same filter keys as get_items() minus pagination.
	 * @return int                        Number of matching rows.
	 */
	public function count_items( array $args = [] ): int {
		global $wpdb;

		unset( $args['number'], $args['offset'], $args['orderby'], $args['order'] );

		[ $where, $values ] = $this->buildWhere( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$this->fq_table_name}{$where}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, ...$values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $sql );
	}

	// -------------------------------------------------------------------------
	// UPDATE
	// -------------------------------------------------------------------------

	/**
	 * Update a row by its primary key value.
	 *
	 * The 'modified' date column is automatically set to the current UTC
	 * datetime unless it is explicitly present in $data. All keys in $data
	 * are validated against the schema column list; unknown keys are dropped.
	 *
	 * @since  1.0.0
	 * @param  int                  $id   Primary key value of the row to update.
	 * @param  array<string, mixed> $data Column name to value pairs to apply.
	 * @return bool                       True on success (including no-op), false on DB error.
	 */
	public function update_item( int $id, array $data ): bool {
		global $wpdb;

		$pk   = $this->getPrimaryKey();
		$data = $this->injectModifiedColumn( $data );
		$data = $this->sanitizeData( $data );

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->fq_table_name,
			$data,
			[ $pk => $id ]
		);

		return $result !== false;
	}

	// -------------------------------------------------------------------------
	// DELETE
	// -------------------------------------------------------------------------

	/**
	 * Delete a single row by its primary key value.
	 *
	 * @since  1.0.0
	 * @param  int  $id Primary key value of the row to delete.
	 * @return bool     True on success, false on DB error.
	 */
	public function delete_item( int $id ): bool {
		global $wpdb;

		$pk     = $this->getPrimaryKey();
		$result = $wpdb->delete( $this->fq_table_name, [ $pk => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $result !== false;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Instantiate the configured Row subclass from a raw $wpdb stdClass result.
	 *
	 * Called by get_item() and get_items() for each returned row.
	 *
	 * @since  1.0.0
	 * @param  object $row Raw stdClass database row from $wpdb.
	 * @return Row         Hydrated Row subclass instance.
	 */
	protected function shapeItem( object $row ): Row {
		$class = $this->item_shape;
		return new $class( $row );
	}

	/**
	 * Build a SQL WHERE clause and a parallel positional values array from $args.
	 *
	 * Iterates $args, skips pagination keys, validates each remaining key against
	 * the schema, and builds %s placeholder conditions. Array values produce IN()
	 * clauses; null values produce IS NULL; all other scalar values produce = %s.
	 * A 'search' key produces a LIKE search across all searchable columns combined
	 * with OR. Returns a two-element array: the WHERE string (with leading space or
	 * empty) and the positional values array for $wpdb->prepare().
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed>         $args Query arguments to translate into SQL.
	 * @return array{0: string, 1: mixed[]}       Tuple of [' WHERE ...', [values]].
	 */
	protected function buildWhere( array $args ): array {
		global $wpdb;

		$conditions = [];
		$values     = [];
		$schema     = $this->getSchema();
		$pk         = $this->getPrimaryKey();

		foreach ( $args as $key => $value ) {
			if ( in_array( $key, [ 'number', 'offset', 'orderby', 'order', 'search' ], true ) ) {
				continue;
			}

			$column = $schema->getColumn( $key );

			if ( $column === null && $key !== $pk ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $value ), '%s' ) );
				$conditions[] = "`{$key}` IN ({$placeholders})";
				array_push( $values, ...$value );
			} elseif ( $value === null ) {
				$conditions[] = "`{$key}` IS NULL";
			} else {
				$conditions[] = "`{$key}` = %s";
				$values[]     = $value;
			}
		}

		if ( ! empty( $args['search'] ) ) {
			$searchable = $schema->getSearchableColumns();

			if ( ! empty( $searchable ) ) {
				$likes = [];

				foreach ( $searchable as $col ) {
					$likes[]  = "`{$col}` LIKE %s";
					$values[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
				}

				$conditions[] = '(' . implode( ' OR ', $likes ) . ')';
			}
		}

		$where = empty( $conditions ) ? '' : ' WHERE ' . implode( ' AND ', $conditions );

		return [ $where, $values ];
	}

	/**
	 * Strip any keys from $data that are not declared columns in the schema.
	 *
	 * Prevents accidental insertion of arbitrary data into the table and
	 * ensures $wpdb->insert() / $wpdb->update() receive only valid columns.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data Raw column-value pairs from the caller.
	 * @return array<string, mixed>       Filtered to schema-declared columns only.
	 */
	protected function sanitizeData( array $data ): array {
		$schema     = $this->getSchema();
		$validNames = array_map( static fn( Column $c ) => $c->name, $schema->getColumns() );

		return array_filter(
			$data,
			static fn( string $key ) => in_array( $key, $validNames, true ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Inject the current UTC datetime into created/modified columns for INSERT.
	 *
	 * Uses Schema::getCreatedColumn() and Schema::getModifiedColumn() to
	 * identify the target columns. Values already present in $data are not
	 * overwritten, allowing callers to supply explicit timestamps.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data Incoming column-value pairs for add_item().
	 * @return array<string, mixed>       $data with created/modified columns added if absent.
	 */
	protected function injectDateColumns( array $data ): array {
		$now    = current_time( 'mysql', true );
		$schema = $this->getSchema();

		$created  = $schema->getCreatedColumn();
		$modified = $schema->getModifiedColumn();

		if ( $created !== null && ! isset( $data[ $created ] ) ) {
			$data[ $created ] = $now;
		}

		if ( $modified !== null && ! isset( $data[ $modified ] ) ) {
			$data[ $modified ] = $now;
		}

		return $data;
	}

	/**
	 * Inject the current UTC datetime into the modified column for UPDATE.
	 *
	 * Only adds the timestamp when the modified column is not already present
	 * in $data, preserving explicit caller-supplied values.
	 *
	 * @since  1.0.0
	 * @param  array<string, mixed> $data Incoming column-value pairs for update_item().
	 * @return array<string, mixed>       $data with the modified column added if absent.
	 */
	protected function injectModifiedColumn( array $data ): array {
		$modified = $this->getSchema()->getModifiedColumn();

		if ( $modified !== null && ! isset( $data[ $modified ] ) ) {
			$data[ $modified ] = current_time( 'mysql', true );
		}

		return $data;
	}

	/**
	 * Validate and sanitize an orderby column name against the schema's sortable list.
	 *
	 * Returns the caller-supplied $orderby value only when it appears in the
	 * list of sortable columns declared in the schema. Falls back to the primary
	 * key column name to prevent SQL injection via unsanitized ORDER BY input.
	 *
	 * @since  1.0.0
	 * @param  string $orderby Caller-supplied column name for ORDER BY.
	 * @return string          Validated column name safe for interpolation into SQL.
	 */
	protected function sanitizeOrderby( string $orderby ): string {
		$sortable = $this->getSchema()->getSortableColumns();
		$pk       = $this->getPrimaryKey();

		if ( in_array( $orderby, $sortable, true ) ) {
			return $orderby;
		}

		return $pk;
	}
}
