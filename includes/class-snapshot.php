<?php
/**
 * Capture l'état des versions du site : coeur WordPress + plugins installés.
 * Produit exactement la même forme que le reader Git et que le type `Snapshot`
 * de @pardesign/core, pour que le backend applique un seul moteur de diff.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Snapshot {

	/**
	 * Construit le snapshot courant.
	 *
	 * @return array{site:string,wp_core_version:string,plugins:array,taken_at:string,source:string}
	 */
	public static function capture(): array {
		return array(
			'site'            => (string) Pardesign_Entretien_Settings::get( 'site_id' ),
			'wp_core_version' => self::core_version(),
			'plugins'         => self::plugins(),
			'taken_at'        => gmdate( 'c' ),
			'source'          => 'wp',
		);
	}

	private static function core_version(): string {
		// Après une màj du cœur dans le MÊME process, le global $wp_version reste la
		// version pré-upgrade : version.php n'est chargé qu'une fois, au début de la
		// requête. On relit donc la version sur le disque pour un « après » exact.
		$file = ABSPATH . WPINC . '/version.php';
		if ( is_readable( $file ) ) {
			$wp_version = '';
			require $file; // redéfinit $wp_version (variable locale) depuis le disque
			if ( '' !== $wp_version ) {
				return (string) $wp_version;
			}
		}

		global $wp_version;
		return (string) $wp_version;
	}

	/**
	 * Liste des plugins avec version installée et mise à jour disponible.
	 *
	 * @return array<int,array{slug:string,name:string,version:string,update_available:?string}>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$all     = get_plugins();
		$updates = get_plugin_updates(); // clé = chemin du plugin ; ->update->new_version

		$result = array();
		foreach ( $all as $plugin_path => $data ) {
			$update_available = null;
			if ( isset( $updates[ $plugin_path ]->update->new_version ) ) {
				$update_available = (string) $updates[ $plugin_path ]->update->new_version;
			}

			$result[] = array(
				'slug'             => self::slug_from_path( $plugin_path ),
				'name'             => isset( $data['Name'] ) ? (string) $data['Name'] : $plugin_path,
				'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '0',
				'update_available' => $update_available,
			);
		}

		return $result;
	}

	/** "woocommerce/woocommerce.php" => "woocommerce" ; "hello.php" => "hello". */
	private static function slug_from_path( string $plugin_path ): string {
		if ( false !== strpos( $plugin_path, '/' ) ) {
			return dirname( $plugin_path );
		}
		return basename( $plugin_path, '.php' );
	}
}
