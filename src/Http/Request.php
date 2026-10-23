<?php
/**
 * Request class.
 *
 * @package Intercessor
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Intercessor\Http;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress-aware HTTP request abstraction.
 *
 * Encapsulates GET, POST, and SERVER data with WordPress sanitization and nonce verification.
 */
final class Request {

	/**
	 * GET data.
	 *
	 * @var array
	 */
	private array $get;

	/**
	 * POST data.
	 *
	 * @var array
	 */
	private array $post;

	/**
	 * SERVER data.
	 *
	 * @var array
	 */
	private array $server;

	/**
	 * Constructor.
	 *
	 * Accepts raw request arrays, recursively unslashes and sanitizes them, and
	 * stores only sanitized values so generic accessors never expose raw input.
	 *
	 * @param array $get    GET parameters.
	 * @param array $post   POST parameters.
	 * @param array $server SERVER parameters.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct( array $get, array $post, array $server ) {
		$this->get    = $this->sanitize_data( $this->unslash( $get ) );
		$this->post   = $this->sanitize_data( $this->unslash( $post ) );
		$this->server = $this->sanitize_data( $this->unslash( $server ) );
	}

	/**
	 * Capture request from globals.
     *
     * @since 1.0.0
     * @return self
     * @noinspection PhpDocMissingThrowsInspection
	 */
	public static function capture(): self {
		// Raw superglobals are intentionally passed unprocessed: the constructor
		// recursively unslashes them via unslash() and sanitizes them via
		// sanitize_data() before storage, and nonces are verified by callers
		// where required. The sniffs below cannot follow values into the
		// constructor, so they are suppressed here with that justification.
		return new self(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Unslashed and sanitized in constructor; nonces verified by callers.
			$_GET,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Unslashed and sanitized in constructor; nonces verified by callers.
			$_POST,
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Unslashed and sanitized in constructor.
			$_SERVER
		);
	}

	// -------------------------------------------------------------
	// Core access
	// -------------------------------------------------------------

	/**
	 * Retrieve input (POST overrides GET).
	 *
	 * Returns $default when the key is absent from both POST and GET, or
	 * when it is present but the sanitized value is an empty string. This
	 * matches the expectation of typed accessors (get_string, get_int, …)
	 * whose callers pass a meaningful $default for optional fields: a blank
	 * submitted value is treated the same as no value at all.
	 *
	 * @param string $key     Input key.
	 * @param mixed  $default Default value if key is absent or blank.
	 *
	 * @since  1.0.0
	 * @since  1.1.2 Returns $default for empty-string values, not just for
	 *               absent/null keys.
	 * @return mixed
	 */
	public function input( string $key, $default = null ) {
		$value = $this->post[ $key ] ?? $this->get[ $key ] ?? null;

		if ( $value === null || $value === '' ) {
			return $default;
		}

		return $value;
	}

	/**
	 * GET value.
     * 
     * @param string $key     Input key.
     * @param mixed  $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return $this->get[$key] ?? $default;
	}

	/**
	 * POST value.
     * 
     * @param string $key     Input key.
     * @param mixed  $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return mixed
	 */
	public function post( string $key, $default = null ) {
		return $this->post[$key] ?? $default;
	}

	/**
	 * Check existence in GET or POST.
     *
     * @param string $key Input key.
     *
     * @since  1.0.0
     * @return bool True if key exists in either GET or POST, false otherwise.
	 */
	public function has( string $key ): bool {
		return isset( $this->post[$key] ) || isset( $this->get[$key] );
	}

	// -------------------------------------------------------------
	// Typed accessors
	// -------------------------------------------------------------

    /**
     * Return a sanitized string from input.
     *
     * @param string $key     Input key.
     * @param string $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return string
     */
	public function get_string( string $key, string $default = '' ): string {
		return sanitize_text_field(
			(string) $this->input( $key, $default )
		);
	}

    /**
     * Return a non-negative integer from input.
     *
     * Uses absint(), so negative submitted values are converted to their
     * positive equivalent. Use this for primary keys, page numbers, and
     * any other field where only non-negative integers are meaningful.
     * For fields that may legitimately carry a negative value, use
     * get_signed_int() instead.
     *
     * @param string $key     Input key.
     * @param int    $default Default value if key is absent or blank.
     *
     * @since  1.0.0
     * @since  1.1.2 Docblock clarifies unsigned-only behaviour; get_signed_int()
     *               added for signed-integer use cases.
     * @return int Non-negative integer.
     */
	public function get_int( string $key, int $default = 0 ): int {
		return absint( $this->input( $key, $default ) );
	}

    /**
     * Return a signed integer from input.
     *
     * Preserves the sign of the submitted value, unlike get_int() which
     * strips it via absint(). Use for offsets, deltas, or any field where
     * a negative value is valid.
     *
     * @param string $key     Input key.
     * @param int    $default Default value if key is absent or blank.
     *
     * @since  1.1.2
     * @return int
     */
	public function get_signed_int( string $key, int $default = 0 ): int {
		return intval( $this->input( $key, $default ) );
	}

    /**
     * Return a boolean from input.
     *
     * Accepts any value that filter_var() recognizes as boolean, including
     * 'true', 'false', '1', '0', 'yes', 'no', etc. Returns $default for any
     * unrecognized values or if the key is not set.
     *
     * @param string $key     Input key.
     * @param bool   $default Default value if key is not set or value is unrecognized.
     * @since  1.0.0
     * @return bool
     */
	public function get_bool( string $key, bool $default = false ): bool {
		$value = $this->input( $key, $default );
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE )
			?? $default;
	}

    /**
     * Return a sanitized email from input.
     *
     * @param string $key     Input key.
     * @param string $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return string
     */
	public function get_email( string $key, string $default = '' ): string {
		return sanitize_email(
			(string) $this->input( $key, $default )
		);
	}

    /**
    * Return a sanitized URL from input.
    *
    * @param string $key     Input key.
    * @param string $default Default value if key is not set.
    * 
    * @since  1.0.0
    * @return string
    */
	public function get_url( string $key, string $default = '' ): string {
		return esc_url_raw(
			(string) $this->input( $key, $default )
		);
	}

    /**
     * Return an array of sanitized strings from input.
     *
     * Expects the input to be an array or a comma-separated string. Sanitizes
     * each value as a string and returns an array of sanitized strings.
     *
     * @param string $key     Input key.
     * @param array|string $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return array
     */
    public function get_array( string $key, $default = array() ): array {
        $value = $this->input( $key, $default );
        if ( is_string( $value ) ) {
            $value = explode( ',', $value );
        }
        if ( is_array( $value ) ) {
            return array_map(
                static fn( $v ) => sanitize_text_field( (string) $v ),
                $value
            );
        }
        return array();
    }

    /**
    * Return an array of absolute integers from input.
    *
    * Expects the input to be an array or a comma-separated string. Sanitizes
    * each value as an absolute integer and returns an array of positive integers,
    * filtering out any non-numeric or non-positive values.
    *
    * @param string $key     Input key.
    * @param array|string $default Default value if key is not set.
    * 
    * @since  1.0.0
    * @return array
    */
	public function get_int_array( string $key, $default = array() ): array {
		$values = $this->get_array( $key, $default );

		$values = array_map( 'absint', $values );

		return array_values(
			array_filter(
				$values,
				static fn( $v ) => $v > 0
			)
		);
	}

    /**
     * Return a sanitized textarea string from input.
     *
     * @param string $key     Input key.
     * @param string $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return string
     */
	public function get_textarea( string $key, string $default = '' ): string {
		return sanitize_textarea_field(
			(string) $this->input( $key, $default )
		);
	}
    
     /**
     * Return a sanitized key from input.
     *
     * @param string $key     Input key.
     * @param string $default Default value if key is not set.
     * 
     * @since  1.0.0
     * @return string
     */
	public function get_key( string $key, string $default = '' ): string {
		return sanitize_key(
			(string) $this->input( $key, $default )
		);
	}

	// -------------------------------------------------------------
	// Security
	// -------------------------------------------------------------

    /**
     * Verify a nonce from the request.
     *
     * Checks the specified field (default '_wpnonce') for a nonce and verifies
     * it against the given action. Returns true if valid, false otherwise.
     *
     * @param string $action Nonce action to verify against.
     * @param string $field  Request field to check for the nonce (default '_wpnonce').
     *
     * @since  1.0.0
     * @return bool True if nonce is valid, false otherwise.
     */
	public function verify_nonce( string $action, string $field = '_wpnonce' ): bool {
		$nonce = $this->post[$field] ?? '';

		if ( '' === $nonce ) {
			return false;
		}

		return wp_verify_nonce(
			sanitize_text_field( $nonce ),
			$action
		) !== false;
	}

    /**
     * Require a valid nonce in the request, or terminate with an error.
     *
     * Checks the specified field (default '_wpnonce') for a nonce and verifies
     * it against the given action. If verification fails, terminates execution
     * with a security error message.
     *
     * @param string $action Nonce action to verify against.
     * @param string $field  Request field to check for the nonce (default '_wpnonce').
     *
     * @since  1.0.0
     * @return void
     */
	public function require_nonce( string $action, string $field = '_wpnonce' ): void {
		if ( ! $this->verify_nonce( $action, $field ) ) {
			wp_die( esc_html__( 'Security check failed.', 'intercessor' ) );
		}
	}

	// -------------------------------------------------------------
	// Request metadata
	// -------------------------------------------------------------

    /**
     * Return the HTTP method of the request.
     *
     * @since  1.0.0
     * @return string Uppercase HTTP method (e.g., 'GET', 'POST').
     */
	public function get_method(): string {
		return strtoupper(
			$this->server['REQUEST_METHOD'] ?? 'GET'
		);
	}

    /**
     * Return true if the request method is POST.
     * @since  1.0.0
     * @return bool True if method is POST, false otherwise.
     */
	public function is_post(): bool {
		return 'POST' === $this->get_method();
	}

    /**
     * Return true if the request method is GET.
     *
     * @since  1.0.0
     * @return bool True if method is GET, false otherwise.
     */
	public function is_get(): bool {
		return 'GET' === $this->get_method();
	}

    /**
    * Return the client's IP address from the request.
    *
    * @since  1.0.0
    * @return string Client IP address, or empty string if not available.
    */
	public function get_remote_addr(): string {
		return sanitize_text_field(
			(string) ( $this->server['REMOTE_ADDR'] ?? '' )
		);
	}

    /**
     * Return the user agent string from the request.
     *
     * @since  1.0.0
     * @return string User agent string, or empty string if not available.
     */
	public function get_user_agent(): string {
		return sanitize_text_field(
			(string) ( $this->server['HTTP_USER_AGENT'] ?? '' )
		);
	}

	// -------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------

	/**
	 * Recursively unslash request data.
     *
     * @param array $data Input data array, potentially containing slashed values.
     * 
     * @since  1.0.0
     * @return array Unslashed data array.
	 */
	private function unslash( array $data ): array {
		foreach ( $data as $key => $value ) {
			$data[$key] = is_array( $value )
				? $this->unslash( $value )
				: wp_unslash( $value );
		}

		return $data;
	}

	/**
	 * Recursively sanitize request data before storage.
	 *
	 * Textarea sanitization is used as the broad default because it strips
	 * unsafe markup while preserving line breaks for fields such as prayer
	 * request content. Typed accessors still apply narrower validation and
	 * sanitization for emails, URLs, integers, booleans, and keys.
	 *
	 * @param array $data Unslashed request data.
	 *
	 * @since  1.0.0
	 * @return array Sanitized request data.
	 */
	private function sanitize_data( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = $this->sanitize_data( $value );
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$data[ $key ] = sanitize_textarea_field( (string) $value );
				continue;
			}

			$data[ $key ] = '';
		}

		return $data;
	}
}
