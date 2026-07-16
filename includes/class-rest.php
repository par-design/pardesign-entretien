<?php
/**
 * Endpoints REST pilotés par le backend PAR Design (Vercel Cron ou bouton
 * « Lancer maintenant »). Authentifiés par la clé API du site (secret symétrique :
 * la même clé sert pour les appels plugin → backend ET backend → plugin).
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Rest {

	const NAMESPACE = 'pardesign-entretien/v1';

	const ASYNC_HOOK = 'pardesign_entretien_run_async';

	/** Polling « pull » : interroge le backend pour savoir s'il faut lancer un entretien. */
	const POLL_HOOK = 'pardesign_entretien_poll';

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Exécution en arrière-plan de l'entretien (déclenchée par WP-Cron).
		add_action( self::ASYNC_HOOK, array( 'Pardesign_Entretien', 'run_auto' ), 10, 3 );
		// Mode pull : polling horaire du backend.
		add_action( self::POLL_HOOK, array( $this, 'handle_poll' ) );
		$this->ensure_poll_scheduled();
	}

	/** Planifie le polling horaire si le plugin est configuré et pas déjà planifié. */
	public function ensure_poll_scheduled(): void {
		if ( ! Pardesign_Entretien_Settings::is_configured() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::POLL_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::POLL_HOOK );
		}
	}

	/**
	 * Interroge le backend (« ai-je un entretien à lancer ? »). Si oui, programme
	 * l'entretien en arrière-plan avec l'id fourni par le backend. Best-effort :
	 * toute erreur réseau/backend est ignorée jusqu'au prochain passage.
	 */
	public function handle_poll(): void {
		if ( ! Pardesign_Entretien_Settings::is_configured() ) {
			return;
		}
		$res = Pardesign_Entretien_Api_Client::get_pending();
		if ( is_wp_error( $res ) || empty( $res['due'] ) ) {
			return;
		}
		$scope = isset( $res['scope'] ) ? (string) $res['scope'] : 'full';
		if ( ! in_array( $scope, array( 'full', 'plugins', 'none' ), true ) ) {
			$scope = 'full';
		}
		$entretien_id = ! empty( $res['entretien_id'] )
			? (string) $res['entretien_id']
			: Pardesign_Entretien::new_id();
		// send TOUJOURS false : jamais d'envoi automatique, validation humaine au backend.
		wp_schedule_single_event( time(), self::ASYNC_HOOK, array( false, $scope, $entretien_id ) );
		self::nudge_cron();
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_run' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'send'  => array( 'type' => 'boolean', 'default' => true ),
					'scope' => array( 'type' => 'string', 'default' => 'full' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/recover',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_recover' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'entretien_id' => array( 'type' => 'string', 'required' => true ),
					'send'         => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit-data',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_audit_data' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/self-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_self_update' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/** Bearer = clé API du site (comparaison à temps constant). */
	public function authorize( WP_REST_Request $request ): bool {
		$expected = (string) Pardesign_Entretien_Settings::get( 'api_key' );
		if ( '' === $expected ) {
			return false;
		}
		$header = (string) $request->get_header( 'authorization' );
		$token  = ( 0 === stripos( $header, 'bearer ' ) ) ? trim( substr( $header, 7 ) ) : '';
		return '' !== $token && hash_equals( $expected, $token );
	}

	public function handle_run( WP_REST_Request $request ) {
		$send  = (bool) $request->get_param( 'send' );
		$scope = (string) $request->get_param( 'scope' );
		if ( ! in_array( $scope, array( 'full', 'plugins', 'none' ), true ) ) {
			$scope = 'full';
		}

		// L'entretien (snapshots + màj + rapport) peut durer plusieurs minutes :
		// on le programme en arrière-plan et on répond IMMÉDIATEMENT au backend.
		$entretien_id = Pardesign_Entretien::new_id();
		wp_schedule_single_event( time(), self::ASYNC_HOOK, array( $send, $scope, $entretien_id ) );
		self::nudge_cron();

		return new WP_REST_Response(
			array(
				'status'       => 'scheduled',
				'entretien_id' => $entretien_id,
				'scope'        => $scope,
			),
			202
		);
	}

	/** Force WP-Cron à traiter l'événement programmé tout de suite (non bloquant). */
	private static function nudge_cron(): void {
		$url = add_query_arg( 'doing_wp_cron', sprintf( '%.22F', microtime( true ) ), site_url( 'wp-cron.php' ) );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Récupère un entretien interrompu : capture l'état courant en « après » pour
	 * l'id fourni et génère le rapport (brouillon par défaut). Synchrone (rapide).
	 */
	public function handle_recover( WP_REST_Request $request ) {
		$id   = (string) $request->get_param( 'entretien_id' );
		$send = (bool) $request->get_param( 'send' );
		if ( '' === trim( $id ) ) {
			return new WP_REST_Response( array( 'error' => 'entretien_id requis.' ), 400 );
		}

		// Màj éventuellement longue en amont ? Non : recover ne fait que capturer +
		// générer, mais on lève quand même les limites par prudence.
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$result = Pardesign_Entretien::recover( $id, $send );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'entretien_id' => $id, 'report' => $result ), 200 );
	}

	/** Collecte et pousse les données serveur, et les renvoie au backend appelant. */
	public function handle_audit_data( WP_REST_Request $request ) {
		$data = Pardesign_Entretien_Server_Audit::collect();
		Pardesign_Entretien_Api_Client::push_server_data( $data );
		return new WP_REST_Response( array( 'ok' => true, 'server' => $data ), 200 );
	}

	/**
	 * Met à jour le plugin lui-même vers la dernière version publiée sur le backend
	 * PAR Design, sans upload manuel. Piloté par le backend (bouton « Déployer la
	 * mise à jour »). Vide les caches de vérification, relance la détection, puis
	 * lance l'upgrader WordPress. Synchrone (archive légère) et idempotent : si le
	 * site est déjà à jour, répond `updated:false` sans rien faire.
	 */
	public function handle_self_update( WP_REST_Request $request ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$basename = plugin_basename( PARDESIGN_ENTRETIEN_FILE );
		$before   = PARDESIGN_ENTRETIEN_VERSION;

		// Force une vérification fraîche : vide notre cache de métadonnées ET le
		// transient d'update de WordPress, puis relance la détection.
		delete_transient( Pardesign_Entretien_Updater::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$target  = isset( $updates->response[ $basename ]->new_version )
			? (string) $updates->response[ $basename ]->new_version
			: '';

		if ( '' === $target ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'updated' => false, 'version' => $before ),
				200
			);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $basename );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_message() ), 500 );
		}
		if ( false === $result || null === $result ) {
			$messages = $skin->get_upgrade_messages();
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => 'Échec de la mise à jour.', 'log' => $messages ),
				500
			);
		}

		// L'upgrader désactive le plugin le temps du remplacement des fichiers ;
		// on garantit sa réactivation (sinon le site se retrouve sans le plugin).
		if ( ! is_plugin_active( $basename ) ) {
			activate_plugin( $basename );
		}

		return new WP_REST_Response(
			array( 'ok' => true, 'updated' => true, 'from' => $before, 'to' => $target ),
			200
		);
	}
}
