<?php
/**
 * Auto-update du plugin depuis le backend PAR Design (plugin privé, hors wordpress.org).
 *
 * Le backend Vercel sert deux fichiers statiques que tous les sites contactent déjà :
 *   {backend_url}/plugin/pardesign-entretien.json  → métadonnées (version, changelog…)
 *   {backend_url}/plugin/pardesign-entretien.zip   → archive de mise à jour
 *
 * L'URL des fichiers est dérivée du réglage « backend_url » de chaque site : aucune
 * URL n'est codée en dur, ce qui fonctionne aussi bien sur un domaine personnalisé.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Updater {

	const SLUG      = 'pardesign-entretien';
	const CACHE_KEY = 'pardesign_entretien_update_meta';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** @var string Basename du plugin, ex. « pardesign-entretien/pardesign-entretien.php ». */
	private $basename;

	public function __construct() {
		$this->basename = plugin_basename( PARDESIGN_ENTRETIEN_FILE );
	}

	public function hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
	}

	/** URL de base des fichiers de mise à jour, dérivée du backend configuré. */
	private function base_url(): string {
		$backend = rtrim( (string) Pardesign_Entretien_Settings::get( 'backend_url' ), '/' );
		return $backend ? $backend . '/plugin' : '';
	}

	/** URL de l'archive de mise à jour (toujours calculée depuis le backend du site). */
	private function package_url(): string {
		$base = $this->base_url();
		return $base ? $base . '/' . self::SLUG . '.zip' : '';
	}

	/**
	 * Récupère (et met en cache) les métadonnées distantes.
	 *
	 * @return array|null { version, requires, requires_php, tested, last_updated, sections… }
	 */
	private function fetch_meta() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$base = $this->base_url();
		if ( '' === $base ) {
			return null;
		}

		$response = wp_remote_get(
			$base . '/' . self::SLUG . '.json',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache court en cas d'échec pour ne pas marteler le backend.
			set_transient( self::CACHE_KEY, array(), 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$meta = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $meta ) || empty( $meta['version'] ) ) {
			set_transient( self::CACHE_KEY, array(), 30 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( self::CACHE_KEY, $meta, self::CACHE_TTL );
		return $meta;
	}

	/** Injecte l'info de mise à jour dans le transient WordPress. */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		$meta    = $this->fetch_meta();
		$package = $this->package_url();
		if ( ! $meta || empty( $meta['version'] ) || '' === $package ) {
			return $transient;
		}

		$info = (object) array(
			'slug'         => self::SLUG,
			'plugin'       => $this->basename,
			'new_version'  => (string) $meta['version'],
			'url'          => 'https://pardesign.net',
			'package'      => $package,
			'tested'       => (string) ( $meta['tested'] ?? '' ),
			'requires'     => (string) ( $meta['requires'] ?? '' ),
			'requires_php' => (string) ( $meta['requires_php'] ?? '' ),
			'icons'        => array(),
		);

		if ( version_compare( (string) $meta['version'], PARDESIGN_ENTRETIEN_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $info;
		} else {
			// Pas de mise à jour : renseigner no_update garde l'écran des extensions cohérent.
			$transient->no_update[ $this->basename ] = $info;
		}

		return $transient;
	}

	/** Fournit la fiche « Voir les détails ». */
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
			'download_link' => $this->package_url(),
			'sections'      => $sections,
		);
	}

	/**
	 * Après extraction, WordPress renomme le dossier d'après le nom de l'archive. On force
	 * « pardesign-entretien » pour que la mise à jour remplace bien le plugin existant.
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
