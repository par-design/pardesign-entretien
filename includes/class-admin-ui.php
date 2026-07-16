<?php
/**
 * Page d'administration : configuration du backend + boutons « Démarrer » / « Terminer » l'entretien.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Admin_UI {

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_pardesign_entretien_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_pardesign_entretien_action', array( $this, 'handle_action' ) );
	}

	public function menu(): void {
		add_management_page(
			__( 'Entretien PAR Design', 'pardesign-entretien' ),
			__( 'Entretien PAR Design', 'pardesign-entretien' ),
			'manage_options',
			'pardesign-entretien',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = Pardesign_Entretien::current();
		$notice  = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Entretien PAR Design', 'pardesign-entretien' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Entretien en cours', 'pardesign-entretien' ); ?></h2>
			<?php if ( $current ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: identifiant, 2: date de début */
						esc_html__( 'En cours : %1$s (démarré le %2$s).', 'pardesign-entretien' ),
						'<code>' . esc_html( $current['entretien_id'] ) . '</code>',
						esc_html( $current['started_at'] )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'pardesign_entretien_action' ); ?>
					<input type="hidden" name="action" value="pardesign_entretien_action">
					<input type="hidden" name="op" value="finish_send">
					<?php submit_button( __( 'Terminer et envoyer le rapport', 'pardesign-entretien' ), 'primary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'pardesign_entretien_action' ); ?>
					<input type="hidden" name="action" value="pardesign_entretien_action">
					<input type="hidden" name="op" value="finish_draft">
					<?php submit_button( __( 'Terminer (brouillon, sans envoi)', 'pardesign-entretien' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'pardesign_entretien_action' ); ?>
					<input type="hidden" name="action" value="pardesign_entretien_action">
					<input type="hidden" name="op" value="cancel">
					<?php submit_button( __( 'Annuler', 'pardesign-entretien' ), 'link-delete', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Aucun entretien en cours. Démarrez-en un avant de faire vos mises à jour.', 'pardesign-entretien' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pardesign_entretien_action' ); ?>
					<input type="hidden" name="action" value="pardesign_entretien_action">
					<input type="hidden" name="op" value="start">
					<?php submit_button( __( 'Démarrer un entretien', 'pardesign-entretien' ), 'primary', 'submit', false, Pardesign_Entretien_Settings::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<?php if ( ! Pardesign_Entretien_Settings::is_configured() ) : ?>
					<p class="description"><?php esc_html_e( 'Configurez d’abord le backend ci-dessous.', 'pardesign-entretien' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Configuration', 'pardesign-entretien' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pardesign_entretien_save' ); ?>
				<input type="hidden" name="action" value="pardesign_entretien_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="backend_url"><?php esc_html_e( 'URL du backend', 'pardesign-entretien' ); ?></label></th>
						<td><input name="backend_url" id="backend_url" type="url" class="regular-text" value="<?php echo esc_attr( Pardesign_Entretien_Settings::get( 'backend_url' ) ); ?>" placeholder="https://entretiens.pardesign.net"></td>
					</tr>
					<tr>
						<th scope="row"><label for="api_key"><?php esc_html_e( 'Clé API du site', 'pardesign-entretien' ); ?></label></th>
						<td><input name="api_key" id="api_key" type="password" class="regular-text" value="<?php echo esc_attr( Pardesign_Entretien_Settings::get( 'api_key' ) ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row"><label for="site_id"><?php esc_html_e( 'Identifiant du site', 'pardesign-entretien' ); ?></label></th>
						<td><input name="site_id" id="site_id" type="text" class="regular-text" value="<?php echo esc_attr( Pardesign_Entretien_Settings::get( 'site_id' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="clickup_task_id"><?php esc_html_e( 'ID de tâche ClickUp', 'pardesign-entretien' ); ?></label></th>
						<td>
							<input name="clickup_task_id" id="clickup_task_id" type="text" class="regular-text" value="<?php echo esc_attr( Pardesign_Entretien_Settings::get( 'clickup_task_id' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Tâche « client » dans ClickUp (sous-tâches = améliorations, champ courriel = destinataire). Synchronisé au backend à l’enregistrement.', 'pardesign-entretien' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="clickup_email_field_id"><?php esc_html_e( 'ID du champ courriel ClickUp (optionnel)', 'pardesign-entretien' ); ?></label></th>
						<td>
							<input name="clickup_email_field_id" id="clickup_email_field_id" type="text" class="regular-text" value="<?php echo esc_attr( Pardesign_Entretien_Settings::get( 'clickup_email_field_id' ) ); ?>">
							<p class="description"><?php esc_html_e( 'À renseigner seulement si la tâche a plusieurs champs de type courriel ; sinon le 1er champ courriel est utilisé automatiquement.', 'pardesign-entretien' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Enregistrer', 'pardesign-entretien' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_entretien_save' );

		$clickup_task_id        = isset( $_POST['clickup_task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['clickup_task_id'] ) ) : '';
		$clickup_email_field_id = isset( $_POST['clickup_email_field_id'] ) ? sanitize_text_field( wp_unslash( $_POST['clickup_email_field_id'] ) ) : '';

		Pardesign_Entretien_Settings::update(
			array(
				'backend_url'            => isset( $_POST['backend_url'] ) ? esc_url_raw( wp_unslash( $_POST['backend_url'] ) ) : '',
				'api_key'                => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
				'site_id'                => isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '',
				'clickup_task_id'        => $clickup_task_id,
				'clickup_email_field_id' => $clickup_email_field_id,
			)
		);

		$msg = __( 'Configuration enregistrée.', 'pardesign-entretien' );

		// Synchronise le mapping ClickUp vers le backend (si configuré).
		if ( Pardesign_Entretien_Settings::is_configured() ) {
			$sync = Pardesign_Entretien_Api_Client::sync_site( $clickup_task_id, $clickup_email_field_id );
			if ( is_wp_error( $sync ) ) {
				/* translators: %s: message d'erreur du backend */
				$msg .= ' ' . sprintf( __( 'Synchro ClickUp échouée : %s', 'pardesign-entretien' ), $sync->get_error_message() );
			} else {
				$msg .= ' ' . __( 'Mapping ClickUp synchronisé au backend.', 'pardesign-entretien' );
			}
		}

		$this->redirect( $msg );
	}

	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_entretien_action' );

		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$result = null;

		switch ( $op ) {
			case 'start':
				$result = Pardesign_Entretien::start();
				$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'Entretien démarré. Faites vos mises à jour, puis revenez le terminer.', 'pardesign-entretien' );
				break;
			case 'finish_send':
				$result = Pardesign_Entretien::finish( true );
				$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'Entretien terminé et rapport envoyé au client.', 'pardesign-entretien' );
				break;
			case 'finish_draft':
				$result = Pardesign_Entretien::finish( false );
				$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'Entretien terminé. Brouillon de rapport généré, à valider dans le dashboard.', 'pardesign-entretien' );
				break;
			case 'cancel':
				Pardesign_Entretien::cancel();
				$msg = __( 'Entretien annulé.', 'pardesign-entretien' );
				break;
			default:
				$msg = __( 'Action inconnue.', 'pardesign-entretien' );
		}

		$this->redirect( $msg );
	}

	private function redirect( string $msg ): void {
		wp_safe_redirect( add_query_arg( 'msg', rawurlencode( $msg ), admin_url( 'tools.php?page=pardesign-entretien' ) ) );
		exit;
	}
}
