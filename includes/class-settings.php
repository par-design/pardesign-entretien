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

		$settings = get_option( self::OPTION, array() );
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
		$settings = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( $settings, $values ) );
	}

	public static function is_configured(): bool {
		return self::get( 'backend_url' ) && self::get( 'api_key' );
	}
}
