<?php
/**
 * Authentication of inbound requests (backend → plugin) by Ed25519 signature.
 *
 * The backend signs every request with its private key; the plugin verifies it against the
 * public keys pinned in TRUSTED_BACKEND_KEYS. Nothing stored on the site can therefore forge
 * an inbound request: a leaked database or API key only allows impersonating the site towards
 * the backend, never driving updates on the site. A signature is bound to this site, the HTTP
 * method, the route and the body; replays are blocked by a timestamp window and a single-use
 * nonce.
 *
 * Request headers:
 *   X-Pardesign-Key-Id     id of the backend signing key (a key of TRUSTED_BACKEND_KEYS)
 *   X-Pardesign-Timestamp  unix time (seconds) when the request was signed
 *   X-Pardesign-Nonce      random string, 16 to 128 chars of [A-Za-z0-9_-], unique per request
 *   X-Pardesign-Signature  base64 Ed25519 detached signature of the message below
 *
 * Signed message: the following seven lines joined with "\n" (no trailing newline):
 *   key id, timestamp, nonce, site id, METHOD (upper case), route, sha256 of the raw body (hex)
 * The site id is the value the site sends as X-Pardesign-Site; the route is the REST route
 * without the /wp-json prefix, e.g. "/pardesign-entretien/v1/run"; an empty body hashes to
 * e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Inbound_Auth {

	/**
	 * Trusted backend signing public keys (Ed25519, base64), keyed by key id. Generate with
	 * tools/backend-keygen.php; the private key lives only in the backend's environment.
	 * Rotation: add the new key here, release, switch the backend, remove the old key later.
	 * A placeholder fails closed: every inbound request is refused until real keys are pinned.
	 */
	const TRUSTED_BACKEND_KEYS = array(
		'b1-2026' => 'Pds/p+ZwG8yASacC0/CjqU9zeO50k4NZW5UnggTSymk=',
		'b2-2026' => 'Aik1i8t6c+MgtYBAyvlQCR4Y8Yy8CJV+D2cGXLxjqyU=',
	);

	/** Accepted clock difference between backend and site, in seconds. */
	const MAX_SKEW = 300;

	/** How long a nonce is remembered; must exceed 2 × MAX_SKEW. */
	const NONCE_TTL = 900;

	/** Option name prefix of remembered nonces (one option per nonce, autoload off). */
	const NONCE_PREFIX = 'pardesign_entretien_nonce_';

	/**
	 * Verify the signature of an inbound REST request.
	 *
	 * @return true|WP_Error Error codes carry HTTP status 401.
	 */
	public static function verify( WP_REST_Request $request ) {
		$key_id    = trim( (string) $request->get_header( 'x_pardesign_key_id' ) );
		$timestamp = trim( (string) $request->get_header( 'x_pardesign_timestamp' ) );
		$nonce     = trim( (string) $request->get_header( 'x_pardesign_nonce' ) );
		$signature = trim( (string) $request->get_header( 'x_pardesign_signature' ) );

		if ( '' === $key_id || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return self::error( 'missing_signature', 'Signed request headers are required.' );
		}
		if ( ! isset( self::TRUSTED_BACKEND_KEYS[ $key_id ] ) ) {
			return self::error( 'untrusted_key', 'Unknown signing key id.' );
		}
		$key = base64_decode( self::TRUSTED_BACKEND_KEYS[ $key_id ], true );
		if ( false === $key || 32 !== strlen( $key ) ) {
			return self::error( 'key_misconfigured', 'Backend public key is not configured on this site.' );
		}
		if ( ! preg_match( '/^\d{1,12}$/', $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return self::error( 'stale_timestamp', 'Request timestamp outside the accepted window.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $nonce ) ) {
			return self::error( 'bad_nonce', 'Malformed nonce.' );
		}
		$sig = base64_decode( $signature, true );
		if ( false === $sig || 64 !== strlen( $sig ) ) {
			return self::error( 'bad_signature', 'Malformed signature.' );
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return self::error( 'sodium_missing', 'Ed25519 verification is unavailable on this server.' );
		}

		$message = self::message( $key_id, $timestamp, $nonce, $request );
		try {
			$valid = sodium_crypto_sign_verify_detached( $sig, $message, $key );
		} catch ( \Throwable $e ) {
			$valid = false;
		}
		if ( true !== $valid ) {
			return self::error( 'bad_signature', 'Signature verification failed.' );
		}

		// Only a request with a valid signature consumes its nonce.
		if ( ! self::remember_nonce( $nonce ) ) {
			return self::error( 'replay', 'Nonce already used.' );
		}
		return true;
	}

	/** Build the canonical message signed by the backend for this request. */
	public static function message( string $key_id, string $timestamp, string $nonce, WP_REST_Request $request ): string {
		return implode(
			"\n",
			array(
				$key_id,
				$timestamp,
				$nonce,
				(string) Pardesign_Entretien_Settings::get( 'site_id' ),
				strtoupper( (string) $request->get_method() ),
				(string) $request->get_route(),
				hash( 'sha256', (string) $request->get_body() ),
			)
		);
	}

	/**
	 * Remember a nonce atomically (add_option is a single INSERT that fails on duplicate).
	 * Expired nonces are pruned on the way.
	 *
	 * @return bool false when the nonce was already recorded.
	 */
	private static function remember_nonce( string $nonce ): bool {
		global $wpdb;

		$name = self::NONCE_PREFIX . hash( 'sha256', $nonce );
		if ( ! add_option( $name, (string) time(), '', 'no' ) ) {
			return false;
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
				$wpdb->esc_like( self::NONCE_PREFIX ) . '%',
				(string) ( time() - self::NONCE_TTL )
			)
		);
		return true;
	}

	private static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 401 ) );
	}
}
