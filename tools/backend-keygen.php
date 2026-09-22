#!/usr/bin/env php
<?php
/**
 * Generate the Ed25519 keypair the BACKEND uses to sign inbound requests to the sites.
 *
 * Usage: php tools/backend-keygen.php
 *
 * Pin the PUBLIC key in Pardesign_Entretien_Inbound_Auth::TRUSTED_BACKEND_KEYS under a key id.
 * Put the PRIVATE key (PKCS#8, base64) in the backend's environment (e.g. a Vercel secret) as
 * PARDESIGN_SIGNING_KEY, with the key id as PARDESIGN_SIGNING_KEY_ID. Never commit it.
 * Node: crypto.createPrivateKey({ key: Buffer.from(process.env.PARDESIGN_SIGNING_KEY, 'base64'),
 *                                 format: 'der', type: 'pkcs8' })
 * Generate a second keypair kept offline as a recovery key, pinned under a second id.
 */

if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "ext/sodium is required.\n" );
	exit( 1 );
}

$keypair = sodium_crypto_sign_keypair();
$public  = sodium_crypto_sign_publickey( $keypair );
$seed    = substr( sodium_crypto_sign_secretkey( $keypair ), 0, 32 ); // libsodium secret key = seed || public key

// PKCS#8 DER wrapper for an Ed25519 private key (RFC 8410): fixed 16-byte prefix + 32-byte seed.
$pkcs8 = hex2bin( '302e020100300506032b657004220420' ) . $seed;

echo 'public  (pin in TRUSTED_BACKEND_KEYS): ', base64_encode( $public ), "\n";
echo 'private (PKCS#8 base64, backend env):  ', base64_encode( $pkcs8 ), "\n";
