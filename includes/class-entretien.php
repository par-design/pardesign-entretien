<?php
/**
 * Orchestration d'un entretien côté site : démarrer (snapshot avant),
 * terminer (snapshot après + déclenchement du rapport). État local en option.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien {

	const CURRENT_OPTION = 'pardesign_entretien_current';

	/**
	 * Démarre un entretien : capture le snapshot "avant" et le pousse au backend.
	 *
	 * @return array{entretien_id:string}|WP_Error
	 */
	public static function start() {
		if ( self::current_id() ) {
			return new WP_Error( 'already_running', __( 'Un entretien est déjà en cours. Terminez-le ou annulez-le d’abord.', 'pardesign-entretien' ) );
		}

		$entretien_id = self::generate_id();
		$snapshot     = Pardesign_Entretien_Snapshot::capture();

		$pushed = Pardesign_Entretien_Api_Client::push_snapshot( $entretien_id, 'before', $snapshot );
		if ( is_wp_error( $pushed ) ) {
			return $pushed;
		}

		update_option(
			self::CURRENT_OPTION,
			array(
				'entretien_id' => $entretien_id,
				'started_at'   => $snapshot['taken_at'],
			)
		);

		return array( 'entretien_id' => $entretien_id );
	}

	/**
	 * Termine l'entretien en cours : snapshot "après", push, puis génère le rapport.
	 *
	 * @param bool $send true = envoie au client, false = brouillon à valider.
	 * @return array|WP_Error Réponse du backend (diff + statut d'envoi).
	 */
	public static function finish( bool $send = true ) {
		$id = self::current_id();
		if ( ! $id ) {
			return new WP_Error( 'none_running', __( 'Aucun entretien en cours.', 'pardesign-entretien' ) );
		}

		$snapshot = Pardesign_Entretien_Snapshot::capture();
		$pushed   = Pardesign_Entretien_Api_Client::push_snapshot( $id, 'after', $snapshot );
		if ( is_wp_error( $pushed ) ) {
			return $pushed;
		}

		$report = Pardesign_Entretien_Api_Client::generate_report( $id, $send );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		delete_option( self::CURRENT_OPTION );
		return $report;
	}

	/**
	 * Cycle d'entretien AUTOMATIQUE complet (piloté par le backend via REST) :
	 * snapshot avant → mises à jour selon la portée → snapshot après →
	 * collecte serveur → rapport. Garde-fou : si une màj échoue, le rapport est
	 * gardé en brouillon (pas d'envoi client).
	 *
	 * @param bool   $send         Envoyer le rapport au client si aucune erreur.
	 * @param string $scope        full | plugins | none.
	 * @param string $entretien_id Id pré-généré (exécution asynchrone) ; sinon généré ici.
	 * @return array{entretien_id:string,update_errors:array,report:mixed}|WP_Error
	 */
	public static function run_auto( bool $send = true, string $scope = 'full', string $entretien_id = '' ) {
		if ( '' === $entretien_id ) {
			$entretien_id = self::generate_id();
		}

		// Verrou de site : pris à la programmation (REST / poll) ou ici pour un appel direct ;
		// refuse de démarrer si un autre entretien le détient.
		if ( ! Pardesign_Entretien_Lock::acquire( $entretien_id, 'running' ) ) {
			$current = Pardesign_Entretien_Lock::current();
			return new WP_Error(
				'locked',
				sprintf( 'Un entretien est déjà en cours (%s).', $current ? $current['entretien_id'] : '?' )
			);
		}
		Pardesign_Entretien_Lock::refresh( $entretien_id, 'running' );

		// La màj du cœur (téléchargement + extraction + upgrade DB) dépasse souvent
		// le max_execution_time du host. Sans ce garde-fou, PHP tue le process en
		// plein milieu : pas de snapshot « après », pas de rapport, et les màj de
		// plugins déjà faites deviennent orphelines. On lève donc les limites.
		self::raise_limits();

		$before = Pardesign_Entretien_Snapshot::capture();
		$pushed = Pardesign_Entretien_Api_Client::push_snapshot( $entretien_id, 'before', $before );
		if ( is_wp_error( $pushed ) ) {
			Pardesign_Entretien_Lock::release( $entretien_id );
			return $pushed;
		}

		// Filet de sécurité : si le process meurt malgré tout pendant les màj
		// (timeout, mémoire, fatal), ce hook capture l'état courant en « après » et
		// génère un brouillon — le rapport reflète alors ce qui a réellement été fait
		// (ex : plugins à jour, cœur non). Neutralisé en fin de flux normal.
		$finalized = false;
		register_shutdown_function(
			static function () use ( &$finalized, $entretien_id ) {
				if ( $finalized ) {
					return;
				}
				$after = Pardesign_Entretien_Snapshot::capture();
				Pardesign_Entretien_Api_Client::push_snapshot( $entretien_id, 'after', $after );
				// Sortie anormale : jamais d'envoi automatique au client, brouillon seulement.
				Pardesign_Entretien_Api_Client::generate_report( $entretien_id, false );
				Pardesign_Entretien_Lock::release( $entretien_id );
			}
		);

		// Filet de sécurité : dump BD avant les màj. Un échec n'interrompt JAMAIS
		// l'entretien — le statut remonte au backend via les données serveur.
		$backup = array(
			'ok'         => false,
			'method'     => null,
			'file'       => null,
			'size_bytes' => 0,
			'error'      => 'skipped',
			'taken_at'   => gmdate( 'c' ),
		);
		if ( in_array( $scope, array( 'full', 'plugins' ), true ) ) {
			Pardesign_Entretien_Lock::refresh( $entretien_id, 'backup' );
			$backup = Pardesign_Entretien_Backup::run();
		}

		$update_errors = array();
		if ( in_array( $scope, array( 'full', 'plugins' ), true ) ) {
			Pardesign_Entretien_Lock::refresh( $entretien_id, 'updating' );
			$update_errors = array_merge( $update_errors, self::update_all_plugins() );
		}
		// Résultat structuré de la màj du cœur (null si la portée n'inclut pas le
		// cœur). On le remonte au backend via push_server_data → journalisé : plus
		// de no-op muet quand le cœur ne bouge pas (offre absente, dismissée,
		// exigence non satisfaite, erreur d'upgrade).
		$core_result = null;
		if ( 'full' === $scope ) {
			$core_result = self::update_core();
			if ( 'error' === $core_result['status'] && ! empty( $core_result['error'] ) ) {
				$update_errors[] = sprintf( 'Cœur WordPress: %s', $core_result['error'] );
			}
		}

		$after = Pardesign_Entretien_Snapshot::capture();
		Pardesign_Entretien_Api_Client::push_snapshot( $entretien_id, 'after', $after );

		// Données serveur pour le scan d'audit (best-effort), avec le statut de la
		// sauvegarde BD (nom de fichier seulement — jamais le chemin complet).
		Pardesign_Entretien_Api_Client::push_server_data(
			array_merge(
				Pardesign_Entretien_Server_Audit::collect(),
				array(
					'last_backup'      => array(
						'ok'         => (bool) $backup['ok'],
						'method'     => $backup['method'],
						'file'       => ! empty( $backup['file'] ) ? basename( $backup['file'] ) : null,
						'size_bytes' => (int) $backup['size_bytes'],
						'error'      => $backup['error'],
						'taken_at'   => $backup['taken_at'],
					),
					'last_core_update' => $core_result,
				)
			)
		);

		// Garde-fou : en cas d'erreur de màj, on ne pousse pas l'envoi au client.
		$effective_send = $send && empty( $update_errors );
		$report         = Pardesign_Entretien_Api_Client::generate_report( $entretien_id, $effective_send );

		// Flux normal terminé : le filet de sécurité devient un no-op, le verrou est libéré.
		$finalized = true;
		Pardesign_Entretien_Lock::release( $entretien_id );

		return array(
			'entretien_id'  => $entretien_id,
			'scope'         => $scope,
			'backup'        => $backup,
			'update_errors' => $update_errors,
			'sent'          => $effective_send,
			'report'        => is_wp_error( $report ) ? array( 'error' => $report->get_error_message() ) : $report,
		);
	}

	/**
	 * Met à jour toutes les extensions ayant une màj disponible.
	 *
	 * @return array<int,string> Messages d'erreur (vide si tout va bien).
	 */
	private static function update_all_plugins(): array {
		self::load_upgrader();
		wp_update_plugins();

		$updates = get_plugin_updates();
		if ( empty( $updates ) ) {
			return array();
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$results  = $upgrader->bulk_upgrade( array_keys( $updates ) );

		$errors = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $plugin => $result ) {
				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf( '%s: %s', $plugin, $result->get_error_message() );
				} elseif ( null === $result || false === $result ) {
					$errors[] = sprintf( '%s: mise à jour échouée.', $plugin );
				}
			}
		} else {
			$errors[] = __( 'Échec de la mise à jour groupée des extensions.', 'pardesign-entretien' );
		}
		return $errors;
	}

	/**
	 * Met à jour le cœur WordPress si une version est disponible, et renvoie un
	 * résultat structuré (journalisé côté backend). Rend explicite le cas « cœur
	 * non mis à jour » et sa raison, au lieu d'un no-op muet.
	 *
	 * @return array{status:string,from:?string,to:?string,response:?string,offers:array,error:?string}
	 *   status: updated | up_to_date | no_offer | error.
	 */
	private static function update_core(): array {
		self::load_upgrader();
		wp_version_check();

		$from = get_bloginfo( 'version' );
		$base = array(
			'status'   => 'no_offer',
			'from'     => $from,
			'to'       => null,
			'response' => null,
			'offers'   => self::core_offers_diagnostic(),
			'error'    => null,
		);

		$updates = get_core_updates();
		if ( is_wp_error( $updates ) ) {
			return array_merge( $base, array( 'status' => 'error', 'error' => $updates->get_error_message() ) );
		}
		if ( empty( $updates ) || ! isset( $updates[0] ) ) {
			return $base; // no_offer
		}

		$update   = $updates[0];
		$response = isset( $update->response ) ? (string) $update->response : null;
		$offered  = isset( $update->version ) ? (string) $update->version : null;
		if ( 'upgrade' !== $response ) {
			// L'offre retenue n'est pas une màj installable (latest, development, ou
			// exigence PHP/MySQL non satisfaite) → cœur laissé tel quel, sciemment.
			return array_merge( $base, array( 'status' => 'up_to_date', 'to' => $offered, 'response' => $response ) );
		}

		$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $update );

		if ( is_wp_error( $result ) ) {
			return array_merge(
				$base,
				array( 'status' => 'error', 'to' => $offered, 'response' => $response, 'error' => $result->get_error_message() )
			);
		}
		return array_merge( $base, array( 'status' => 'updated', 'to' => $offered, 'response' => $response ) );
	}

	/**
	 * Photographie compacte de TOUTES les offres de cœur (dismissées incluses) pour
	 * diagnostiquer un no-op : distingue « aucune offre » d'une offre 7.0 présente
	 * mais dismissée par un admin, ou marquée « latest »/« development ».
	 *
	 * @return array<int,array{version:?string,response:?string,dismissed:bool}>
	 */
	private static function core_offers_diagnostic(): array {
		$all = get_core_updates( array( 'dismissed' => true ) );
		if ( is_wp_error( $all ) || ! is_array( $all ) ) {
			return array();
		}
		$out = array();
		foreach ( $all as $u ) {
			$out[] = array(
				'version'   => isset( $u->version ) ? (string) $u->version : null,
				'response'  => isset( $u->response ) ? (string) $u->response : null,
				'dismissed' => ! empty( $u->dismissed ),
			);
		}
		return $out;
	}

	/**
	 * Récupère un entretien interrompu : capture l'état ACTUEL du site comme
	 * snapshot « après » pour l'id fourni, puis (re)génère le rapport. Le backend
	 * apparie le « avant » déjà stocké avec ce nouveau « après » — le diff crédite
	 * donc correctement les màj réellement effectuées (ex : plugins) avant le crash.
	 *
	 * @param string $entretien_id Id de l'entretien orphelin (« avant » sans rapport).
	 * @param bool   $send         true = envoie au client ; défaut false = brouillon.
	 * @return array|WP_Error Réponse du backend, ou WP_Error.
	 */
	public static function recover( string $entretien_id, bool $send = false ) {
		if ( '' === trim( $entretien_id ) ) {
			return new WP_Error( 'no_id', __( 'entretien_id requis.', 'pardesign-entretien' ) );
		}

		$after  = Pardesign_Entretien_Snapshot::capture();
		$pushed = Pardesign_Entretien_Api_Client::push_snapshot( $entretien_id, 'after', $after );
		if ( is_wp_error( $pushed ) ) {
			return $pushed;
		}

		// Données serveur rafraîchies (best-effort), comme un run normal.
		Pardesign_Entretien_Api_Client::push_server_data( Pardesign_Entretien_Server_Audit::collect() );

		return Pardesign_Entretien_Api_Client::generate_report( $entretien_id, $send );
	}

	/** Lève les limites PHP pour les opérations longues (màj cœur/plugins). */
	private static function raise_limits(): void {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/** Charge les API d'upgrade de wp-admin (hors contexte admin). */
	private static function load_upgrader(): void {
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	/** Annule l'entretien en cours sans générer de rapport. */
	public static function cancel(): void {
		delete_option( self::CURRENT_OPTION );
	}

	public static function current_id(): ?string {
		$current = get_option( self::CURRENT_OPTION );
		return is_array( $current ) && ! empty( $current['entretien_id'] ) ? (string) $current['entretien_id'] : null;
	}

	public static function current() {
		return get_option( self::CURRENT_OPTION );
	}

	/** Identifiant d'entretien public (pour pré-génération côté REST asynchrone). */
	public static function new_id(): string {
		return self::generate_id();
	}

	private static function generate_id(): string {
		$site = sanitize_title( (string) Pardesign_Entretien_Settings::get( 'site_id' ) );
		return $site . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
	}
}
