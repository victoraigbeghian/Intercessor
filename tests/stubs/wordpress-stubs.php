<?php

// phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags,WordPress.NamingConventions.PrefixAllGlobals -- Test stub file replicating WP core function signatures
defined( 'ABSPATH' ) || exit;
/**
 * WordPress function stubs for unit tests.
 *
 * These stubs allow unit tests to run without a full WordPress installation.
 * Only functions that are actually called by the classes under test are stubbed.
 * Integration tests use a real WordPress installation and do NOT include this file.
 *
 * @package Intercessor\Tests
 */

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		// WordPress strips the contents of <script> and <style>, not just the
		// tags, so emulate that here before strip_tags() removes the markup.
		$str = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $str );
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		return filter_var( $email, FILTER_SANITIZE_EMAIL ) ?: '';
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $str ): string {
		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		// WordPress lowercases first, then strips. Doing it the other way
		// around removes every uppercase letter before it can be lowercased.
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		// In unit tests return truthy for 'valid_nonce', false otherwise.
		return $nonce === 'valid_nonce' ? 1 : false;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $tag, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	// Simple in-memory store for unit tests.
	function get_option( string $option, $default = false ) {
		global $intercessor_test_options;
		return $intercessor_test_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value ): bool {
		global $intercessor_test_options;
		$intercessor_test_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		global $intercessor_test_options;
		unset( $intercessor_test_options[ $option ] );
		return true;
	}
}
