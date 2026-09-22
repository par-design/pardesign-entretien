<?php
/**
 * Endpoints REST pilotés par le backend PAR Design (Vercel Cron ou bouton
 * « Lancer maintenant »). Authentifiés par signature Ed25519 du backend (voir
 * class-inbound-auth.php) : rien de stocké sur le site ne permet de forger un appel
 * entrant. La clé API du site ne sert qu'aux appels sortants plugin → backend.
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

	/** Forme acceptée d'un identifiant d'entretien (JSON-schema pattern, sans délimiteurs). */
	const ENTRETIEN_ID_PATTERN = '^[A-Za-z0-9._-]{1,128}$';

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
		$entretien_id = ! empty( $res['entretien_id'] ) && preg_match( '/' . self::ENTRETIEN_ID_PATTERN . '/', (string) $res['entretien_id'] )
			? (string) $res['entretien_id']
			: Pardesign_Entretien::new_id();
		// Un entretien est déjà en cours (ou programmé) : on réessaiera au prochain passage.
		if ( ! Pardesign_Entretien_Lock::acquire( $entretien_id, 'scheduled' ) ) {
			return;
		}
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
				'show_in_index'       => false,
				'args'                => array(
					'send'  => array( 'type' => 'boolean', 'default' => true ),
					'scope' => array(
						'type'    => 'string',
						'default' => 'full',
						'enum'    => array( 'full', 'plugins', 'none' ),
					),
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
				'show_in_index'       => false,
				'args'                => array(
					'entretien_id' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => self::ENTRETIEN_ID_PATTERN,
					),
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
				'show_in_index'       => false,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/self-update',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_self_update' ),
				'permission_callback' => array( $this, 'authorize' ),
				'show_in_index'       => false,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rotate-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rotate_key' ),
				'permission_callback' => array( $this, 'authorize' ),
				'show_in_index'       => false,
				'args'                => array(
					'api_key' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9_\\-]{32,128}$',
					),
				),
			)
		);
	}

	/**
	 * Requête entrante signée par le backend (Ed25519 + horodatage + nonce), sur TLS.
	 * Un WP_Error explique le refus au backend (statut 401 / 403).
	 *
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		/**
		 * Local development only: allow inbound calls without TLS. Sites behind a proxy that
		 * terminates TLS should instead set $_SERVER['HTTPS'] in wp-config.php so is_ssl() is true.
		 */
		if ( ! is_ssl() && ! apply_filters( 'pardesign_entretien_allow_insecure_rest', false ) ) {
			return new WP_Error( 'https_required', 'Inbound requests must use HTTPS.', array( 'status' => 403 ) );
		}
		return Pardesign_Entretien_Inbound_Auth::verify( $request );
	}

	public function handle_run( WP_REST_Request $request ) {
		$send  = (bool) $request->get_param( 'send' );
		$scope = (string) $request->get_param( 'scope' );
		if ( ! in_array( $scope, array( 'full', 'plugins', 'none' ), true ) ) {
			$scope = 'full';
		}

		// Un seul entretien à la fois : le verrou est pris ici (atomique) et libéré en fin de run.
		$entretien_id = Pardesign_Entretien::new_id();
		if ( ! Pardesign_Entretien_Lock::acquire( $entretien_id, 'scheduled' ) ) {
			$current = Pardesign_Entretien_Lock::current();
			return new WP_REST_Response(
				array(
					'error'        => 'Un entretien est déjà en cours.',
					'entretien_id' => $current ? $current['entretien_id'] : null,
					'stage'        => $current ? $current['stage'] : null,
					'started_at'   => $current ? gmdate( 'c', (int) $current['started_at'] ) : null,
				),
				409
			);
		}

		// L'entretien (snapshots + màj + rapport) peut durer plusieurs minutes :
		// on le programme en arrière-plan et on répond IMMÉDIATEMENT au backend.
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

	/**
	 * Rotation de la clé API sortante, pilotée par le backend (requête signée). Permet de
	 * fermer une fuite sans intervention manuelle sur le site. Refusé si la clé est définie
	 * en constante dans wp-config.php.
	 */
	public function handle_rotate_key( WP_REST_Request $request ) {
		if ( Pardesign_Entretien_Settings::api_key_is_constant() ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => 'La clé API est définie dans wp-config.php (PARDESIGN_ENTRETIEN_API_KEY) ; rotation manuelle requise.' ),
				409
			);
		}
		Pardesign_Entretien_Settings::update( array( 'api_key' => (string) $request->get_param( 'api_key' ) ) );
		return new WP_REST_Response( array( 'ok' => true, 'rotated_at' => gmdate( 'c' ) ), 200 );
	}

	/** Collecte et pousse les données serveur, et les renvoie au backend appelant. */
	public function handle_audit_data( WP_REST_Request $request ) {
		$data = Pardesign_Entretien_Server_Audit::collect();
		Pardesign_Entretien_Api_Client::push_server_data( $data );
		return new WP_REST_Response( array( 'ok' => true, 'server' => $data ), 200 );
	}

	/**
	 * Met à jour le plugin lui-même vers la dernière release GitHub signée (voir
	 * class-updater.php), sans upload manuel. Piloté par le backend (bouton « Déployer la
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
				array( 'ok' => true, 'updated' => false, 'version' => $before, 'last_error' => Pardesign_Entretien_Updater::last_error() ),
				200
			);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $basename );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_message(), 'last_error' => Pardesign_Entretien_Updater::last_error() ), 500 );
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
