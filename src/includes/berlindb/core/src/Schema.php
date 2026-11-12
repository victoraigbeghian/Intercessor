<?php
/**
 * Schema base class of Intercessor's custom-table data layer.
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
 * Defines the column set for a BerlinDB-managed database table.
 *
 * Subclasses declare a public $columns array where each element is an
 * associative array of Column property names and values. The Schema class
 * lazily converts those raw arrays into Column objects on first access and
 * exposes helper methods used by Table (for SQL generation) and Query (for
 * argument validation, date injection, and ORDER BY whitelisting).
 *
 * Example subclass declaration:
 *
 *   public array $columns = [
 *       [ 'name' => 'id',    'type' => 'bigint',  'primary' => true, ... ],
 *       [ 'name' => 'email', 'type' => 'varchar',  'length'  => '255', ... ],
 *   ];
 *
 * @since   1.0.0
 * @package Intercessor
 */
class Schema {

	/**
	 * Raw column definition arrays declared by each Schema subclass.
	 *
	 * Each element is an associative array matching Column property names.
	 * Subclasses must override this property with their own column set.
	 *
	 * @since 1.0.0
	 * @var   array<int, array<string, mixed>>
	 */
	public array $columns = [];

	/**
	 * Lazily resolved Column object cache, populated on first call to getColumns().
	 *
	 * @since 1.0.0
	 * @var   Column[]
	 */
	private array $resolved = [];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return all columns as hydrated Column objects.
	 *
	 * Converts the raw $columns array into Column instances on the first call
	 * and caches the result. Subsequent calls return the cached array.
	 *
	 * @since  1.0.0
	 * @return Column[] Indexed list of Column objects in declaration order.
	 */
	public function getColumns(): array {
		if ( empty( $this->resolved ) ) {
			$this->resolved = array_map(
				static fn( array $def ) => new Column( $def ),
				$this->columns
			);
		}

		return $this->resolved;
	}

	/**
	 * Return the primary key Column, or null when none is defined.
	 *
	 * Iterates all columns and returns the first one flagged as primary.
	 * If no column carries the primary flag, null is returned and the Query
	 * layer falls back to 'id'.
	 *
	 * @since  1.0.0
	 * @return Column|null The primary key Column, or null if none is declared.
	 */
	public function getPrimaryColumn(): ?Column {
		foreach ( $this->getColumns() as $column ) {
			if ( $column->primary ) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * Return the names of all columns whitelisted for ORDER BY queries.
	 *
	 * Used by Query::sanitizeOrderby() to validate caller-supplied orderby
	 * arguments before embedding them in SQL.
	 *
	 * @since  1.0.0
	 * @return string[] Column names of all sortable columns.
	 */
	public function getSortableColumns(): array {
		return array_values(
			array_map(
				static fn( Column $c ) => $c->name,
				array_filter( $this->getColumns(), static fn( Column $c ) => $c->sortable )
			)
		);
	}

	/**
	 * Return the names of all columns that receive a KEY index in CREATE TABLE SQL.
	 *
	 * Includes both explicitly searchable columns and sortable columns (which
	 * also receive an index to support efficient ORDER BY). Used by
	 * Query::buildWhere() to construct LIKE search conditions.
	 *
	 * @since  1.0.0
	 * @return string[] Column names of all indexed columns.
	 */
	public function getSearchableColumns(): array {
		return array_values(
			array_map(
				static fn( Column $c ) => $c->name,
				array_filter( $this->getColumns(), static fn( Column $c ) => $c->searchable || $c->sortable )
			)
		);
	}

	/**
	 * Return a Column object by its name, or null when not found.
	 *
	 * Used by Query::buildWhere() to verify that a given query argument key
	 * corresponds to a declared column before including it in a WHERE clause.
	 *
	 * @since  1.0.0
	 * @param  string      $name Column name to look up.
	 * @return Column|null       Matching Column object, or null if not declared.
	 */
	public function getColumn( string $name ): ?Column {
		foreach ( $this->getColumns() as $column ) {
			if ( $column->name === $name ) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * Return the name of the column flagged as 'created', or null.
	 *
	 * Used by Query::injectDateColumns() to identify which column should
	 * receive the current UTC datetime on INSERT when not explicitly provided.
	 *
	 * @since  1.0.0
	 * @return string|null Column name, or null when no column carries the 'created' flag.
	 */
	public function getCreatedColumn(): ?string {
		foreach ( $this->getColumns() as $column ) {
			if ( $column->created ) {
				return $column->name;
			}
		}

		return null;
	}

	/**
	 * Return the name of the column flagged as 'modified', or null.
	 *
	 * Used by Query::injectModifiedColumn() to identify which column should
	 * receive the current UTC datetime on UPDATE when not explicitly provided.
	 *
	 * @since  1.0.0
	 * @return string|null Column name, or null when no column carries the 'modified' flag.
	 */
	public function getModifiedColumn(): ?string {
		foreach ( $this->getColumns() as $column ) {
			if ( $column->modified ) {
				return $column->name;
			}
		}

		return null;
	}
}
