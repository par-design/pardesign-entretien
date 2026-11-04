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

	/**
	 * Value rendered in the API key field when a key is set. The real key never reaches the
	 * page HTML (readable by any script or extension in the admin's browser). On save, this
	 * value or an empty field means "keep the current key".
	 */
	const KEY_PLACEHOLDER = 'password-is-set';

	/** Transient prefix of the one-shot notice shown after a redirect, keyed by user id. */
	const NOTICE_TRANSIENT = 'pardesign_entretien_notice_';

	/** HTML allowed in the one-shot notice: only the link to the app's Courriels page. */
	const NOTICE_HTML = array(
		'a' => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_pardesign_entretien_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_pardesign_entretien_action', array( $this, 'handle_action' ) );
	}

	public function menu(): void {
		add_management_page(
			__( 'Entretien PAR Design', 'pardesign-entretien' ),
			__( 'Entretien PAR Design', 'pardesign-entretien' ),
			Pardesign_Entretien_Settings::capability(),
			'pardesign-entretien',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( Pardesign_Entretien_Settings::capability() ) ) {
			return;
		}
		$current = Pardesign_Entretien::current();
		// One-shot notice left by handle_save()/handle_action(); never taken from the URL, so a
		// crafted link cannot display an arbitrary message to an administrator.
		$notice = (string) get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		if ( '' !== $notice ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Entretien PAR Design', 'pardesign-entretien' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo wp_kses( $notice, self::NOTICE_HTML ); ?></p></div>
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
					<input type="hidden" name="op" value="finish">
					<?php submit_button( __( 'Terminer l’entretien', 'pardesign-entretien' ), 'primary', 'submit', false ); ?>
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
						<td>
							<input name="api_key" id="api_key" type="password" class="regular-text" value="<?php echo '' !== (string) Pardesign_Entretien_Settings::get( 'api_key' ) ? esc_attr( self::KEY_PLACEHOLDER ) : ''; ?>" autocomplete="new-password">
							<?php if ( Pardesign_Entretien_Settings::api_key_is_constant() ) : ?>
								<p class="description"><?php esc_html_e( 'Définie par la constante PARDESIGN_ENTRETIEN_API_KEY dans wp-config.php ; la valeur saisie ici est ignorée.', 'pardesign-entretien' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Laissez tel quel pour conserver la clé actuelle ; saisissez une nouvelle valeur pour la remplacer.', 'pardesign-entretien' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="site_id"><?php esc_html_e( 'Identifiant du site', 'pardesign-entretien' ); ?></label></th>
						<td><input name="site_id" id="site_id" type="text" class="regular-text" value="<?php echo esc_attr( Pardesign_Entretien_Settings::get( 'site_id' ) ); ?>"></td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'La liste ClickUp du site (entretiens, courriel du client, améliorations) se configure dans l’application PAR Design : Sites → Éditer.', 'pardesign-entretien' ); ?></p>
				<?php submit_button( __( 'Enregistrer', 'pardesign-entretien' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( Pardesign_Entretien_Settings::capability() ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_entretien_save' );

		// Backend URL: HTTPS only (the API key travels in a header). An invalid value keeps the
		// previous setting and is reported instead of being stored.
		$backend_url = isset( $_POST['backend_url'] ) ? esc_url_raw( wp_unslash( $_POST['backend_url'] ) ) : '';
		$url_error   = '';
		if ( '' !== $backend_url && ( 0 !== strpos( $backend_url, 'https://' ) || ! wp_http_validate_url( $backend_url ) ) ) {
			$url_error   = __( 'URL du backend refusée : elle doit commencer par https://.', 'pardesign-entretien' );
			$backend_url = (string) Pardesign_Entretien_Settings::get( 'backend_url' );
		}

		// API key: the field shows a placeholder, never the key. Empty or placeholder = keep.
		$submitted_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$api_key       = ( '' === $submitted_key || self::KEY_PLACEHOLDER === $submitted_key )
			? (string) Pardesign_Entretien_Settings::get( 'api_key' )
			: $submitted_key;

		Pardesign_Entretien_Settings::update(
			array(
				'backend_url' => $backend_url,
				'api_key'     => $api_key,
				'site_id'     => isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '',
			)
		);

		$msg = __( 'Configuration enregistrée.', 'pardesign-entretien' );
		if ( '' !== $url_error ) {
			$msg .= ' ' . $url_error;
		}

		$this->redirect( esc_html( $msg ) );
	}

	public function handle_action(): void {
		if ( ! current_user_can( Pardesign_Entretien_Settings::capability() ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_entretien_action' );

		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$result = null;

		switch ( $op ) {
			case 'start':
				$result = Pardesign_Entretien::start();
				$msg    = esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'Entretien démarré. Faites vos mises à jour, puis revenez le terminer.', 'pardesign-entretien' ) );
				break;
			case 'finish':
			case 'finish_send':  // Anciens boutons, si la page a été chargée avant la mise à jour.
			case 'finish_draft':
				// Le backend ne fait jamais d'envoi depuis le plugin : le rapport part
				// toujours en brouillon, envoyé après validation dans l'application.
				$entretien_id = Pardesign_Entretien::current_id();
				$result       = Pardesign_Entretien::finish( false );
				$msg          = is_wp_error( $result ) ? esc_html( $result->get_error_message() ) : self::draft_notice( (string) $entretien_id );
				break;
			case 'cancel':
				Pardesign_Entretien::cancel();
				$msg = esc_html__( 'Entretien annulé.', 'pardesign-entretien' );
				break;
			default:
				$msg = esc_html__( 'Action inconnue.', 'pardesign-entretien' );
		}

		$this->redirect( $msg );
	}

	/** Message after « Terminer » : the draft is waiting in the app's Courriels page, with a link to it. */
	private static function draft_notice( string $entretien_id ): string {
		$base = rtrim( (string) Pardesign_Entretien_Settings::get( 'backend_url' ), '/' );
		$url  = $base . '/courriels?t=entretien' . ( '' !== $entretien_id ? '&e=' . rawurlencode( $entretien_id ) : '' );
		return sprintf(
			/* translators: %s: link to the Courriels page of the app */
			esc_html__( 'Brouillon créé, à valider et envoyer dans les %s.', 'pardesign-entretien' ),
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'courriels de l’application', 'pardesign-entretien' ) . '</a>'
		);
	}

	/** Stores the message (already-escaped HTML) for the current user (60 s) and redirects to the page without any query parameter. */
	private function redirect( string $msg ): void {
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $msg, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=pardesign-entretien' ) );
		exit;
	}
}
