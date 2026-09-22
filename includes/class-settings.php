<?php
/**
 * Stockage de la configuration du plugin (URL du backend, clé API par site, identifiant de site).
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Settings {

	const OPTION = 'pardesign_entretien_settings';

	/**
	 * Récupère un réglage. Les valeurs peuvent être surchargées par des constantes
	 * définies dans wp-config.php (pratique pour ne pas stocker la clé en base).
	 */
	public static function get( string $key, $default = '' ) {
		$constants = array(
			'backend_url'            => 'PARDESIGN_ENTRETIEN_BACKEND_URL',
			'api_key'                => 'PARDESIGN_ENTRETIEN_API_KEY',
			'site_id'                => 'PARDESIGN_ENTRETIEN_SITE_ID',
			'clickup_task_id'        => 'PARDESIGN_ENTRETIEN_CLICKUP_TASK_ID',
			'clickup_email_field_id' => 'PARDESIGN_ENTRETIEN_CLICKUP_EMAIL_FIELD_ID',
		);
		if ( isset( $constants[ $key ] ) && defined( $constants[ $key ] ) ) {
			return constant( $constants[ $key ] );
		}

		$settings = self::read();
		if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
			return $settings[ $key ];
		}

		if ( 'site_id' === $key ) {
			return wp_parse_url( home_url(), PHP_URL_HOST );
		}

		// URL du backend par défaut : la prod PAR Design. Ainsi chaque nouvelle
		// installation pointe déjà au bon endroit — il ne reste que la clé API à
		// saisir. Reste surchargeable via le réglage ou la constante wp-config.
		if ( 'backend_url' === $key ) {
			return 'https://entretiens.pardesign.net';
		}

		return $default;
	}

	public static function update( array $values ): void {
		$settings = array_merge( self::read(), $values );
		if ( is_multisite() ) {
			update_site_option( self::OPTION, $settings );
		} else {
			update_option( self::OPTION, $settings );
		}
	}

	/**
	 * Stored settings. On multisite they are network-wide (the REST endpoints update core and
	 * plugins network-wide, so only a network administrator may configure them); a value left
	 * in a site option by an older version is still read as a fallback.
	 */
	private static function read(): array {
		if ( is_multisite() ) {
			$network = get_site_option( self::OPTION, array() );
			if ( is_array( $network ) && ! empty( $network ) ) {
				return $network;
			}
		}
		$local = get_option( self::OPTION, array() );
		return is_array( $local ) ? $local : array();
	}

	/** Capability required to configure and drive the plugin. */
	public static function capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/** True when the API key comes from a wp-config.php constant (cannot be rotated remotely). */
	public static function api_key_is_constant(): bool {
		return defined( 'PARDESIGN_ENTRETIEN_API_KEY' );
	}

	public static function is_configured(): bool {
		return self::get( 'backend_url' ) && self::get( 'api_key' );
	}
}
