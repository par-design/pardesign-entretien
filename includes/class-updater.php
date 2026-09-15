<?php
/**
 * Plugin self-update from GitHub Releases, verified against signed release manifests.
 *
 * Every release publishes two assets on a GitHub release (tag "v{version}") of the public
 * repository DEFAULT_REPO:
 *   pardesign-entretien.zip   → the plugin archive (top-level folder "pardesign-entretien/")
 *   pardesign-entretien.json  → a signed envelope:
 *                               { key_id, payload (base64 JSON manifest), signature (base64 Ed25519) }
 *
 * Trust chain: the manifest is fetched through GitHub's "latest release" redirect, its
 * signature is verified against the public keys pinned in TRUSTED_KEYS, and the archive's
 * SHA-256 is checked against the signed manifest before WordPress unpacks it. Neither GitHub
 * nor the PAR Design backend can therefore produce an installable package: only the offline
 * signing key can. Downgrades are impossible because the version is part of the signed data.
 *
 * Backward compatibility: the envelope also carries the legacy top-level fields (version,
 * requires, sections…) so that plugin versions ≤ 0.7.2, which read {backend_url}/plugin/*.json
 * without verification, can still discover and install releases when the backend redirects
 * its legacy /plugin/ paths to the GitHub release assets. This class ignores those fields.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Updater {

	const SLUG = 'pardesign-entretien';

	/** Cache of the last VERIFIED manifest. Suffixed v2 so a legacy (unsigned) cached entry is never reused. */
	const CACHE_KEY = 'pardesign_entretien_update_meta_v2';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Last verification/download error, surfaced to the backend through /self-update. */
	const LAST_ERROR_OPTION = 'pardesign_entretien_update_error';

	/**
	 * Public GitHub repository publishing the releases ("owner/repo"). It must be readable without
	 * authentication: sites carry no GitHub token. Override with the PARDESIGN_ENTRETIEN_UPDATE_REPO
	 * constant (staging).
	 */
	const DEFAULT_REPO = 'par-design/pardesign-entretien';

	/** Asset names uploaded on every GitHub release. */
	const ASSET_ZIP  = 'pardesign-entretien.zip';
	const ASSET_JSON = 'pardesign-entretien.json';

	/**
	 * Trusted release-signing public keys (Ed25519, base64), keyed by key id.
	 * Generate with tools/keygen.php. Keep two keys stored in different places: if one
	 * secret leaks, ship a release signed with the other that removes the leaked key.
	 * Rotation: add the new key, release, then remove the old key in a later release.
	 * A placeholder value fails closed: no update is ever offered until real keys are set.
	 */
	const TRUSTED_KEYS = array(
		'k1-2026' => '+ji31ZxblM8xC/MDJk0U1b4TT5g/Y6IbnetEAyETjsI=',
		'k2-2026' => 'M9bUX/mDI9yCRi2OWNwPOrTSkjcnB2+9iZe7quWHlV4=',
	);

	/** @var string Plugin basename, e.g. "pardesign-entretien/pardesign-entretien.php". */
	private $basename;

	public function __construct() {
		$this->basename = plugin_basename( PARDESIGN_ENTRETIEN_FILE );
	}

	public function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'verified_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
	}

	/** "owner/repo" of the releases repository, validated so it can only ever build a github.com URL. */
	private function repo(): string {
		$repo = defined( 'PARDESIGN_ENTRETIEN_UPDATE_REPO' ) ? (string) PARDESIGN_ENTRETIEN_UPDATE_REPO : self::DEFAULT_REPO;
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : self::DEFAULT_REPO;
	}

	/**
	 * Base URL of the releases repository. Always https://github.com/{repo}, except for staging
	 * where the PARDESIGN_ENTRETIEN_UPDATE_BASE constant may point to another HTTPS origin that
	 * mirrors the GitHub layout (/releases/latest/download/… and /releases/download/v{version}/…).
	 * Manifest signing makes the origin a matter of availability, not of trust.
	 */
	private function base_url(): string {
		if ( defined( 'PARDESIGN_ENTRETIEN_UPDATE_BASE' ) ) {
			$base = untrailingslashit( (string) PARDESIGN_ENTRETIEN_UPDATE_BASE );
			if ( 0 === strpos( $base, 'https://' ) ) {
				return $base;
			}
		}
		return 'https://github.com/' . $this->repo();
	}

	/** Signed manifest of the latest release (GitHub redirects to the newest non-prerelease). */
	private function manifest_url(): string {
		return $this->base_url() . '/releases/latest/download/' . self::ASSET_JSON;
	}

	/** Archive of one specific release. Derived from the signed version, never from remote data. */
	private function package_url( string $version ): string {
		return $this->base_url() . '/releases/download/v' . $version . '/' . self::ASSET_ZIP;
	}

	/**
	 * Fetch (and cache) the latest release manifest. Only a manifest whose signature verified
	 * is ever cached or returned; failures are cached briefly and recorded in an option.
	 *
	 * @return array|null Verified manifest, or null.
	 */
	private function fetch_meta() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return ( $cached && isset( $cached['sha256'] ) ) ? $cached : null;
		}

		$response = wp_remote_get(
			$this->manifest_url(),
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $this->fail( new WP_Error( 'manifest_http', sprintf( 'Manifest request returned HTTP %d.', $code ) ) );
		}

		$meta = self::verify_envelope( json_decode( wp_remote_retrieve_body( $response ), true ) );
		if ( is_wp_error( $meta ) ) {
			return $this->fail( $meta );
		}

		delete_option( self::LAST_ERROR_OPTION );
		set_transient( self::CACHE_KEY, $meta, self::CACHE_TTL );
		return $meta;
	}

	/** Record a failure, cache the miss for a short while (do not hammer GitHub), return null. */
	private function fail( WP_Error $error ) {
		update_option( self::LAST_ERROR_OPTION, $error->get_error_message(), false );
		set_transient( self::CACHE_KEY, array(), 30 * MINUTE_IN_SECONDS );
		return null;
	}

	/** Last recorded update error (empty string if none). */
	public static function last_error(): string {
		return (string) get_option( self::LAST_ERROR_OPTION, '' );
	}

	/**
	 * Verify a signed envelope and return the decoded manifest.
	 *
	 * Envelope: { key_id: string, payload: base64(JSON), signature: base64(Ed25519 detached over raw payload bytes) }.
	 * Manifest (payload): { version, sha256, requires?, requires_php?, tested?, last_updated?, sections? }.
	 * Any legacy top-level field in the envelope is ignored: only the signed payload is trusted.
	 *
	 * sodium_crypto_sign_verify_detached() exists on every supported install: ext/sodium ships with
	 * PHP ≥ 7.2 and WordPress ≥ 5.2 bundles the sodium_compat polyfill.
	 *
	 * @param mixed $envelope Decoded JSON.
	 * @return array|WP_Error
	 */
	public static function verify_envelope( $envelope ) {
		if ( ! is_array( $envelope ) || empty( $envelope['key_id'] ) || empty( $envelope['payload'] ) || empty( $envelope['signature'] ) ) {
			return new WP_Error( 'bad_envelope', 'Update manifest is not a signed envelope.' );
		}
		$key_id = (string) $envelope['key_id'];
		if ( ! isset( self::TRUSTED_KEYS[ $key_id ] ) ) {
			return new WP_Error( 'untrusted_key', 'Update manifest signed with an unknown key: ' . sanitize_key( $key_id ) );
		}

		$key = base64_decode( self::TRUSTED_KEYS[ $key_id ], true );
		if ( false === $key || 32 !== strlen( $key ) ) {
			return new WP_Error( 'key_misconfigured', 'Trusted key "' . sanitize_key( $key_id ) . '" is not a valid Ed25519 public key.' );
		}

		$payload = base64_decode( (string) $envelope['payload'], true );
		$sig     = base64_decode( (string) $envelope['signature'], true );
		if ( false === $payload || '' === $payload || false === $sig || 64 !== strlen( $sig ) ) {
			return new WP_Error( 'bad_envelope', 'Update manifest envelope is malformed.' );
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return new WP_Error( 'sodium_missing', 'Ed25519 verification is unavailable on this server.' );
		}
		try {
			$valid = sodium_crypto_sign_verify_detached( $sig, $payload, $key );
		} catch ( \Throwable $e ) {
			$valid = false;
		}
		if ( true !== $valid ) {
			return new WP_Error( 'bad_signature', 'Update manifest signature verification failed.' );
		}

		$meta = json_decode( $payload, true );
		if ( ! is_array( $meta )
			|| empty( $meta['version'] ) || ! preg_match( '/^\d+(\.\d+){1,3}$/', (string) $meta['version'] )
			|| empty( $meta['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $meta['sha256'] ) ) {
			return new WP_Error( 'bad_manifest', 'Update manifest is missing a valid version or sha256.' );
		}
		return $meta;
	}

	/** Inject the update offer into the WordPress plugin update transient. */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$meta = $this->fetch_meta();
		if ( ! $meta ) {
			return $transient;
		}

		$version = (string) $meta['version'];
		$info    = (object) array(
			'slug'         => self::SLUG,
			'plugin'       => $this->basename,
			'new_version'  => $version,
			'url'          => 'https://pardesign.net',
			'package'      => $this->package_url( $version ),
			'tested'       => (string) ( $meta['tested'] ?? '' ),
			'requires'     => (string) ( $meta['requires'] ?? '' ),
			'requires_php' => (string) ( $meta['requires_php'] ?? '' ),
			'icons'        => array(),
		);

		// Compare with the version WordPress just read from disk ("checked"), not the constant:
		// right after a self-update the running code still carries the previous version, and
		// using it would re-offer the release just installed for up to 12 hours.
		$installed = isset( $transient->checked[ $this->basename ] ) ? (string) $transient->checked[ $this->basename ] : PARDESIGN_ENTRETIEN_VERSION;
		if ( version_compare( $version, $installed, '>' ) ) {
			$transient->response[ $this->basename ] = $info;
		} else {
			// No update: filling no_update keeps the plugins screen consistent.
			$transient->no_update[ $this->basename ] = $info;
		}

		return $transient;
	}

	/** "View details" modal content. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$meta = $this->fetch_meta();
		if ( ! $meta ) {
			return $result;
		}

		$sections = isset( $meta['sections'] ) && is_array( $meta['sections'] ) ? $meta['sections'] : array();

		return (object) array(
			'name'          => (string) ( $meta['name'] ?? 'PAR Design — Entretien' ),
			'slug'          => self::SLUG,
			'version'       => (string) $meta['version'],
			'author'        => '<a href="https://pardesign.net">PAR Design</a>',
			'homepage'      => 'https://pardesign.net',
			'requires'      => (string) ( $meta['requires'] ?? '' ),
			'requires_php'  => (string) ( $meta['requires_php'] ?? '' ),
			'tested'        => (string) ( $meta['tested'] ?? '' ),
			'last_updated'  => (string) ( $meta['last_updated'] ?? '' ),
			'download_link' => $this->package_url( (string) $meta['version'] ),
			'sections'      => $sections,
		);
	}

	/**
	 * Download our own package and verify its SHA-256 against the signed manifest before
	 * WordPress unpacks it. Returning a local path short-circuits the core download; WordPress
	 * deletes that temporary file itself after unpacking. Runs for the /self-update endpoint,
	 * the wp-admin "Update now" button, WP-CLI and bulk upgrades alike.
	 *
	 * @param false|string|WP_Error $reply      Short-circuit value.
	 * @param string                $package    Package URL from the update transient (informational only).
	 * @param WP_Upgrader           $upgrader   Upgrader instance.
	 * @param array                 $hook_extra Contains "plugin" (basename) for plugin updates.
	 * @return false|string|WP_Error
	 */
	public function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $this->basename !== $hook_extra['plugin'] ) {
			return $reply;
		}

		$meta = $this->fetch_meta();
		if ( ! $meta ) {
			return new WP_Error( 'no_manifest', 'No verified update manifest available: ' . self::last_error() );
		}

		// The package URL in the update transient may be stale (an offer written by the previous
		// version right after its own upgrade). It carries no trust anyway: the archive is always
		// downloaded from the URL derived from the signed manifest and checked against its hash.
		$installed = $this->installed_version();
		if ( version_compare( (string) $meta['version'], $installed, '<=' ) ) {
			delete_site_transient( 'update_plugins' ); // drop the stale offer
			return new WP_Error(
				'up_to_date',
				sprintf( 'Installed version %s is already the latest signed release (%s); stale update offer cleared.', $installed, $meta['version'] )
			);
		}
		$expected = $this->package_url( (string) $meta['version'] );

		$tmp = download_url( $expected, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$hash = (string) hash_file( 'sha256', $tmp );
		if ( ! hash_equals( (string) $meta['sha256'], $hash ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$message = sprintf( 'Downloaded package does not match the signed manifest for version %s.', $meta['version'] );
			update_option( self::LAST_ERROR_OPTION, $message, false );
			delete_transient( self::CACHE_KEY );
			return new WP_Error( 'package_hash_mismatch', $message );
		}

		return $tmp;
	}

	/** Version of the plugin files currently on disk (the running constant lags right after a self-update). */
	private function installed_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->basename, false, false );
		return ! empty( $data['Version'] ) ? (string) $data['Version'] : PARDESIGN_ENTRETIEN_VERSION;
	}

	/**
	 * After extraction WordPress names the folder after the archive; force "pardesign-entretien"
	 * so the update replaces the installed plugin.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( empty( $args['plugin'] ) || $this->basename !== $args['plugin'] ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return $source;
	}

	public function flush_cache( $upgrader = null, $data = array() ): void {
		delete_transient( self::CACHE_KEY );
	}
}
