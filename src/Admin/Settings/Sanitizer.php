<?php
/**
 * Settings input sanitizer.
 *
 * @package Intercessor
 * @since   1.0.0
 */
declare(strict_types=1);

namespace Intercessor\Admin\Settings;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Validates and sanitizes settings input before it is stored.
 *
 * Pure class: no side-effects, fully testable. Delegates type-based
 * sanitization to WordPress core functions. Unknown field types are
 * passed through an 'intercessor_sanitize_{type}' filter so plugins or
 * themes can handle custom field types without modifying this class.
 *
 * @since   1.0.0
 * @package Intercessor
 */
class Sanitizer {

	/**
	 * Registry instance used to look up field types by ID.
	 *
	 * @since 1.0.0
	 * @var   Registry
	 */
	private Registry $registry;

	/**
	 * Construct the sanitizer with a Registry instance.
	 *
	 * @since 1.0.0
	 * @param Registry $registry Registry describing the current settings schema.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Sanitize a raw settings input array.
	 *
	 * Iterates the field types registered for the given tab and/or section
	 * and sanitizes each present key according to its declared type. Keys
	 * absent from $input are skipped so unchecked checkboxes (which submit
	 * no value) are handled correctly by the caller via array_merge().
	 *
	 * @since  1.0.0
	 * @param  array       $input   Raw $_POST values from the settings form.
	 * @param  string|null $tab     Limit sanitization to a specific tab, or null for all.
	 * @param  string|null $section Limit sanitization to a specific section, or null for all.
	 * @return array<string, mixed> Sanitized key-value pairs.
	 */
	public function sanitize( array $input, ?string $tab = null, ?string $section = null ): array {
		$types  = $this->registry->get_field_types( $tab, $section );
		$output = array();

		foreach ( $types as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				// Unchecked checkboxes and empty multichecks are absent from
				// $_POST, so without this branch they'd keep whatever value was
				// already stored. That is the 1.1.2 bug: a user who unchecks a
				// previously-ticked box sees it snap back on reload because the
				// stored '1' survives the sanitize → array_merge in the Renderer.
				// Only fields known to omit themselves get this default; every
				// other missing key is still ignored so unrelated tab writes
				// are not overwritten.
				if ( 'checkbox' === $type ) {
					$output[ $key ] = '0';
				} elseif ( 'multicheck' === $type ) {
					$output[ $key ] = array();
				}
				continue;
			}

			$value = $input[ $key ];

			switch ( $type ) {
				case 'text':
				case 'password':
					$output[ $key ] = sanitize_text_field( (string) $value );
					break;

				case 'email':
					$output[ $key ] = sanitize_email( (string) $value );
					break;

				case 'url':
					$output[ $key ] = esc_url_raw( (string) $value );
					break;

				case 'number':
					$output[ $key ] = is_numeric( $value ) ? $value + 0 : 0;
					break;

				case 'checkbox':
					$output[ $key ] = ! empty( $value ) ? '1' : '0';
					break;

				case 'textarea':
					$output[ $key ] = sanitize_textarea_field( (string) $value );
					break;

				case 'select':
					$output[ $key ] = sanitize_key( (string) $value );
					break;

				case 'multicheck':
					$output[ $key ] = array_map( 'sanitize_text_field', (array) $value );
					break;

				default:
					/**
					 * Allow custom field types to be sanitized via filter.
					 *
					 * @param mixed  $value The raw input value.
					 * @param string $key   The field ID.
					 */
					$output[ $key ] = apply_filters(
						"intercessor_sanitize_{$type}",
						$value,
						$key
					);
					break;
			}
		}

		return $output;
	}
}
