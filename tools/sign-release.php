#!/usr/bin/env php
<?php
/**
 * Produce the signed release manifest (envelope) for a built plugin archive.
 *
 * Usage: php tools/sign-release.php <zip> <key_id> [tested_wp] [changelog.md] < secret-key.b64
 *
 *   zip          Archive built by tools/build-release.sh (top-level folder "pardesign-entretien/").
 *   key_id       Key id matching an entry in Pardesign_Entretien_Updater::TRUSTED_KEYS.
 *   tested_wp    Optional "Tested up to" WordPress version shown in the plugin details.
 *   changelog.md Optional Markdown/HTML changelog shown in the plugin details.
 *   stdin        The base64 secret key (kept off the command line so shell history never sees it).
 *
 * Writes <zip dir>/pardesign-entretien.json. The version, requires and requires_php values are
 * read from the plugin header INSIDE the archive so the manifest can never disagree with the zip.
 *
 * Output format (one file serves both current and legacy clients):
 *   - key_id / payload / signature: the signed envelope verified by versions >= 0.8.
 *   - version, requires, requires_php, tested, last_updated, sections: unsigned copies of the
 *     same values, read by versions <= 0.7.2 which expect a plain manifest. Newer clients ignore them.
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php tools/sign-release.php <zip> <key_id> [tested_wp] [changelog.md] < secret-key.b64\n" );
	exit( 1 );
}
list( , $zip, $key_id ) = $argv;
$tested    = $argv[3] ?? '';
$changelog = $argv[4] ?? '';

if ( ! is_file( $zip ) ) {
	fwrite( STDERR, "Archive not found: $zip\n" );
	exit( 1 );
}
if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key_id ) ) {
	fwrite( STDERR, "Invalid key id.\n" );
	exit( 1 );
}

$secret = base64_decode( trim( (string) stream_get_contents( STDIN ) ), true );
if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
	fwrite( STDERR, "Expected the base64 Ed25519 secret key on stdin.\n" );
	exit( 1 );
}

// Read the plugin header from inside the archive: the manifest must describe exactly this zip.
$archive = new ZipArchive();
if ( true !== $archive->open( $zip ) ) {
	fwrite( STDERR, "Cannot open archive.\n" );
	exit( 1 );
}
$header = (string) $archive->getFromName( 'pardesign-entretien/pardesign-entretien.php' );
$archive->close();
if ( '' === $header ) {
	fwrite( STDERR, "Archive must contain pardesign-entretien/pardesign-entretien.php at its top level.\n" );
	exit( 1 );
}

$read_header = static function ( string $field ) use ( $header ): string {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $header, $m ) ? trim( $m[1] ) : '';
};
$version = $read_header( 'Version' );
if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
	fwrite( STDERR, "Plugin header has no valid Version.\n" );
	exit( 1 );
}

$manifest = array(
	'version'      => $version,
	'sha256'       => hash_file( 'sha256', $zip ),
	'size'         => filesize( $zip ),
	'requires'     => $read_header( 'Requires at least' ),
	'requires_php' => $read_header( 'Requires PHP' ),
	'tested'       => $tested,
	'last_updated' => gmdate( 'Y-m-d' ),
	'released_at'  => gmdate( 'c' ),
	'sections'     => array(
		'changelog' => ( $changelog && is_file( $changelog ) ) ? (string) file_get_contents( $changelog ) : '',
	),
);

$payload   = json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$signature = sodium_crypto_sign_detached( $payload, $secret );
sodium_memzero( $secret );

// Legacy top-level fields first (for <= 0.7.2 clients), then the signed envelope.
$envelope = array_merge(
	$manifest,
	array(
		'key_id'    => $key_id,
		'payload'   => base64_encode( $payload ),
		'signature' => base64_encode( $signature ),
	)
);

$out = dirname( $zip ) . '/pardesign-entretien.json';
if ( false === file_put_contents( $out, json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n" ) ) {
	fwrite( STDERR, "Cannot write $out\n" );
	exit( 1 );
}

echo "Signed manifest for version $version (key $key_id) written to $out\n";
echo 'sha256: ', $manifest['sha256'], "\n";
