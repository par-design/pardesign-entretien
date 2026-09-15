#!/usr/bin/env php
<?php
/**
 * Generate an Ed25519 release-signing keypair.
 *
 * Usage: php tools/keygen.php
 *
 * Paste the PUBLIC key into Pardesign_Entretien_Updater::TRUSTED_KEYS under a key id.
 * Store the SECRET key offline (password manager or encrypted file). Never commit it,
 * never put it in a CI or hosting environment variable, never pass it as a CLI argument.
 * Generate two keypairs and store them in different places: the second one is the
 * recovery key used to revoke the first if it ever leaks.
 */

if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "ext/sodium is required.\n" );
	exit( 1 );
}

$keypair = sodium_crypto_sign_keypair();
echo 'public (pin in TRUSTED_KEYS): ', base64_encode( sodium_crypto_sign_publickey( $keypair ) ), "\n";
echo 'secret (store offline):      ', base64_encode( sodium_crypto_sign_secretkey( $keypair ) ), "\n";
