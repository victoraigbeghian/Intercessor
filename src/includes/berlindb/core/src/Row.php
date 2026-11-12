<?php
/**
 * Row base class of Intercessor's custom-table data layer.
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

// strict_types is intentionally absent here. $wpdb returns all column values
// as strings regardless of the underlying MySQL column type. With strict_types
// enabled, assigning a string to a typed `int` or `float` property on a Row
// subclass throws a TypeError before the subclass constructor can run its
// explicit casts. Without strict_types, PHP coerces scalar strings to their
// declared type automatically ("42" → 42, "1.5" → 1.5), which is exactly the
// behaviour needed. Subclasses may still declare strict_types=1 in their own
// files — that declaration only applies to the file it appears in.

namespace Intercessor\BerlinDB;

/**
 * Base value object for a single row returned from a BerlinDB-managed table.
 *
 * Query::shapeItem() constructs this class (or a configured subclass) for
 * every row in a result set. The raw stdClass from $wpdb is passed to the
 * constructor and each public property on the Row that matches a column name
 * in the stdClass is populated with the raw string value. Subclasses are
 * responsible for type-casting individual properties in their own constructor
 * after calling parent::__construct().
 *
 * @since   1.0.0
 * @package Intercessor
 */
class Row {

	/**
	 * Populate all declared public properties from the raw $wpdb result object.
	 *
	 * Uses get_object_vars() on $item to retrieve all column values, then
	 * iterates them and assigns only properties that exist on this class.
	 * Subclasses must call parent::__construct($item) first, then type-cast
	 * any properties that require it (e.g. (int) $this->id).
	 *
	 * @since 1.0.0
	 * @param object $item Raw stdClass returned by a $wpdb->get_row() or get_results() call.
	 */
	public function __construct( object $item ) {
		$vars = get_object_vars( $item );

		foreach ( $vars as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->$key = $value;
			}
		}
	}

	/**
	 * Return the row data as a plain associative array.
	 *
	 * Useful for serialisation, REST API output, or export pipelines that need
	 * a plain array rather than an object reference. Returns all public
	 * properties including any declared by subclasses.
	 *
	 * @since  1.0.0
	 * @return array<string, mixed> Column name to value map.
	 */
	public function toArray(): array {
		return get_object_vars( $this );
	}

	/**
	 * Magic getter that returns null for any property not declared on the class.
	 *
	 * Prevents PHP notices when code accesses a column that exists in the
	 * database but has no corresponding declared property on the Row subclass.
	 *
	 * @since  1.0.0
	 * @param  string $name Name of the property being accessed.
	 * @return mixed        Always null for undeclared properties.
	 */
	public function __get( string $name ): mixed {
		return null;
	}
}
