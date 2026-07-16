<?php
/**
 * Collecte des données serveur pour le scan d'audit (poussées au backend) :
 * version PHP/WP, SSL, WP_DEBUG, édition de fichiers, plugins de sécurité.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Server_Audit {

	/** Slugs (préfixes de dossier) de plugins de sécurité courants. */
	const SECURITY_PLUGIN_SLUGS = array(
		'wordfence',
		'better-wp-security', // Solid Security (ex iThemes)
		'sucuri-scanner',
		'all-in-one-wp-security-and-firewall',
		'wp-cerber',
		'ninjafirewall',
	);

	/**
	 * @return array{php_version:string,wp_version:string,https:bool,wp_debug:bool,file_edit_disabled:bool,security_plugins:array,collected_at:string}
	 */
	public static function collect(): array {
		global $wp_version;

		return array(
			'php_version'        => PHP_VERSION,
			'wp_version'         => (string) $wp_version,
			'https'              => is_ssl() || 0 === strpos( (string) home_url(), 'https://' ),
			'wp_debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'file_edit_disabled' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'security_plugins'   => self::detect_security_plugins(),
			'collected_at'       => gmdate( 'c' ),
		);
	}

	/** @return array<int,string> slugs des plugins de sécurité actifs détectés. */
	private static function detect_security_plugins(): array {
		$active   = (array) get_option( 'active_plugins', array() );
		$detected = array();
		foreach ( $active as $plugin_path ) {
			$slug = false !== strpos( $plugin_path, '/' ) ? dirname( $plugin_path ) : basename( $plugin_path, '.php' );
			if ( in_array( $slug, self::SECURITY_PLUGIN_SLUGS, true ) ) {
				$detected[] = $slug;
			}
		}
		return $detected;
	}
}
