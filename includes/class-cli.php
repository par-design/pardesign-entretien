<?php
/**
 * Commandes WP-CLI, pour coller au workflow d'entretien direct (wp-cli) :
 *
 *   wp pardesign entretien start
 *   wp plugin update --all
 *   wp pardesign entretien finish           # envoie le rapport
 *   wp pardesign entretien finish --draft   # brouillon seulement
 *   wp pardesign entretien status
 *   wp pardesign entretien cancel
 *   wp pardesign entretien recover <id>    # récupère un entretien interrompu
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_CLI {

	/**
	 * Démarre un entretien (capture le snapshot "avant").
	 */
	public function start( $args, $assoc_args ) {
		$result = Pardesign_Entretien::start();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Entretien démarré : %s', $result['entretien_id'] ) );
	}

	/**
	 * Termine l'entretien en cours et génère le rapport.
	 *
	 * ## OPTIONS
	 *
	 * [--draft]
	 * : Génère un brouillon sans l'envoyer au client.
	 */
	public function finish( $args, $assoc_args ) {
		$send   = empty( $assoc_args['draft'] );
		$result = Pardesign_Entretien::finish( $send );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( isset( $result['summary'] ) ) {
			WP_CLI::log( WP_CLI::colorize( '%9Résumé du diff :%n' ) );
			foreach ( (array) $result['summary'] as $k => $v ) {
				WP_CLI::log( sprintf( '  %s: %s', $k, is_bool( $v ) ? ( $v ? 'oui' : 'non' ) : $v ) );
			}
		}
		WP_CLI::success( $send ? 'Rapport envoyé au client.' : 'Brouillon généré (non envoyé).' );
	}

	/**
	 * Récupère un entretien interrompu (ex : process tué pendant la màj du cœur).
	 * Capture l'état actuel comme snapshot « après » pour l'id fourni et génère le
	 * rapport (brouillon par défaut), en créditant les màj déjà faites.
	 *
	 * ## OPTIONS
	 *
	 * <entretien_id>
	 * : L'identifiant de l'entretien orphelin (visible dans le dashboard).
	 *
	 * [--send]
	 * : Envoie le rapport au client au lieu de générer un brouillon.
	 *
	 * ## EXEMPLES
	 *
	 *   wp pardesign entretien recover example-com-20260702-141521-TwAVap
	 */
	public function recover( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $id ) {
			WP_CLI::error( 'Fournis un entretien_id : wp pardesign entretien recover <id>' );
		}
		$send   = ! empty( $assoc_args['send'] );
		$result = Pardesign_Entretien::recover( $id, $send );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Rapport %s pour %s.', $send ? 'envoyé au client' : 'généré (brouillon)', $id ) );
	}

	/**
	 * Affiche l'entretien en cours, s'il y en a un, et la dernière sauvegarde BD.
	 */
	public function status( $args, $assoc_args ) {
		$current = Pardesign_Entretien::current();
		if ( ! $current ) {
			WP_CLI::log( 'Aucun entretien en cours.' );
		} else {
			WP_CLI::log( sprintf( 'En cours : %s (démarré le %s)', $current['entretien_id'], $current['started_at'] ) );
		}
		$last = Pardesign_Entretien_Backup::last();
		WP_CLI::log(
			$last
				? sprintf( 'Dernière sauvegarde BD : %s (%s, %s)', $last['file'], size_format( $last['size_bytes'] ), $last['taken_at'] )
				: 'Aucune sauvegarde BD.'
		);
	}

	/**
	 * Sauvegarde la base de données (dump gzippé dans les uploads).
	 * C'est le même filet que celui appliqué automatiquement avant les mises à
	 * jour d'un entretien automatique.
	 */
	public function backup( $args, $assoc_args ) {
		$result = Pardesign_Entretien_Backup::run();
		if ( empty( $result['ok'] ) ) {
			WP_CLI::error( sprintf( 'Sauvegarde échouée : %s', $result['error'] ?? 'erreur inconnue' ) );
		}
		WP_CLI::success(
			sprintf(
				'Sauvegarde BD (%s) : %s (%s en %ss).',
				$result['method'],
				basename( (string) $result['file'] ),
				size_format( (int) $result['size_bytes'] ),
				$result['duration_s']
			)
		);
	}

	/**
	 * Annule l'entretien en cours sans générer de rapport.
	 */
	public function cancel( $args, $assoc_args ) {
		Pardesign_Entretien::cancel();
		WP_CLI::success( 'Entretien annulé.' );
	}
}
