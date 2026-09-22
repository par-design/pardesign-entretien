<?php
/**
 * Client HTTP vers le backend central PAR Design. Authentifié par clé API du site.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Api_Client {

	/** Pousse un snapshot rattaché à un entretien et une phase ("before"|"after"). */
	public static function push_snapshot( string $entretien_id, string $phase, array $snapshot ) {
		return self::post(
			'/api/snapshots',
			array(
				'entretien_id' => $entretien_id,
				'phase'        => $phase,
				'snapshot'     => $snapshot,
			)
		);
	}

	/** Demande au backend de générer (et envoyer, ou produire un brouillon) le rapport. */
	public static function generate_report( string $entretien_id, bool $send ) {
		return self::post(
			'/api/reports/generate',
			array(
				'entretien_id' => $entretien_id,
				'send'         => $send,
			)
		);
	}

	/** Pousse les données serveur du site (audit) vers le backend. Best-effort. */
	public static function push_server_data( array $server ) {
		return self::post( '/api/audits/server-data', array( 'server' => $server ) );
	}

	/**
	 * Mode « pull » : demande au backend s'il y a un entretien à lancer pour ce site.
	 * Sens plugin → backend (jamais bloqué par les pare-feux entrants type Cloudflare).
	 *
	 * @return array|WP_Error { due:bool, scope?:string, entretien_id?:string, send?:bool }
	 */
	public static function get_pending() {
		return self::post( '/api/site/pending', array() );
	}

	/**
	 * Synchronise le mapping ClickUp de ce site vers le backend (la clé API n'est
	 * jamais modifiée côté backend par cet appel). Vide une valeur en passant ''.
	 */
	public static function sync_site( string $clickup_task_id, string $clickup_email_field_id = '' ) {
		return self::post(
			'/api/site',
			array(
				'clickup_task_id'        => $clickup_task_id,
				'clickup_email_field_id' => $clickup_email_field_id,
			)
		);
	}

	/**
	 * @return array|WP_Error Corps décodé en cas de succès, WP_Error sinon.
	 */
	private static function post( string $path, array $body ) {
		if ( ! Pardesign_Entretien_Settings::is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Le backend PAR Design n’est pas configuré.', 'pardesign-entretien' ) );
		}

		$base = rtrim( (string) Pardesign_Entretien_Settings::get( 'backend_url' ), '/' );
		if ( 0 !== strpos( $base, 'https://' ) ) {
			// The API key travels in a header: never over plain HTTP.
			return new WP_Error( 'insecure_backend', __( 'L’URL du backend doit utiliser HTTPS.', 'pardesign-entretien' ) );
		}
		$url = $base . $path;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . Pardesign_Entretien_Settings::get( 'api_key' ),
					'X-Pardesign-Site' => (string) Pardesign_Entretien_Settings::get( 'site_id' ),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : "HTTP $code";
			return new WP_Error( 'backend_error', (string) $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
