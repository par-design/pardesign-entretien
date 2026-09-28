<?php
/**
 * "Sécurité PAR Design" admin page (Tools): CSP policy configuration.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Csp_Admin {

	const PAGE = 'pardesign-securite';

	/** Per-user transient carrying the post-redirect notice (and capture URL to open); never taken from the query string. */
	const NOTICE_TRANSIENT = 'pardesign_entretien_csp_notice_';

	const VALUE_DIRECTIVE_LABELS = array(
		'default-src'     => array( 'Sources par défaut', 'Source de repli utilisée quand une directive plus spécifique n’est pas définie ci-dessous.' ),
		'script-src'      => array( 'Scripts (JS)', 'Origines autorisées pour les balises &lt;script&gt;. Peut recevoir un nonce.' ),
		'script-src-attr' => array( 'Scripts en attribut (onclick=…)', 'S’applique aux gestionnaires d’événements inline (onclick, onload…). Un nonce ne s’applique jamais aux attributs : seul « \'unsafe-inline\' » (ou un hash + « \'unsafe-hashes\' ») fonctionne ici.' ),
		'style-src'       => array( 'Styles (CSS)', 'Origines autorisées pour les balises &lt;style&gt; et feuilles de style liées. Peut recevoir un nonce.' ),
		'style-src-attr'  => array( 'Styles en attribut (style=…)', 'S’applique aux attributs style="…" inline. Un nonce ne s’applique jamais aux attributs : seul « \'unsafe-inline\' » (ou un hash + « \'unsafe-hashes\' ») fonctionne ici — très courant avec les sliders/blocs qui fixent des dimensions en JS.' ),
		'img-src'         => array( 'Images', 'Origines autorisées pour les images.' ),
		'font-src'        => array( 'Polices', 'Origines autorisées pour les polices web.' ),
		'connect-src'     => array( 'Connexions (fetch/XHR/WebSocket)', 'Origines autorisées pour les requêtes fetch, XHR, WebSocket, EventSource…' ),
		'frame-src'       => array( 'Cadres intégrés (iframe)', 'Origines autorisées pour les iframes intégrées dans le site.' ),
		'frame-ancestors' => array( 'Intégration du site (anti-clickjacking)', 'Qui a le droit d’intégrer ce site dans une iframe. Utilisez « \'none\' » ou « \'self\' ».' ),
		'object-src'      => array( 'Objets', 'Origines autorisées pour &lt;object&gt;, &lt;embed&gt;, &lt;applet&gt;. « \'none\' » est recommandé.' ),
		'base-uri'        => array( 'URI de base', 'Restreint les valeurs autorisées pour &lt;base href&gt;.' ),
		'form-action'     => array( 'Cible des formulaires', 'Origines vers lesquelles un formulaire peut être soumis.' ),
		'media-src'       => array( 'Médias (audio/vidéo)', 'Origines autorisées pour &lt;audio&gt; et &lt;video&gt;.' ),
		'worker-src'      => array( 'Web Workers', 'Origines autorisées pour Worker, SharedWorker, ServiceWorker.' ),
		'manifest-src'    => array( 'Manifeste d’application', 'Origine autorisée pour le fichier manifest.json.' ),
	);

	const FLAG_DIRECTIVE_LABELS = array(
		'upgrade-insecure-requests' => array( 'Forcer HTTPS', 'Convertit automatiquement les requêtes http:// en https:// côté navigateur (upgrade-insecure-requests).' ),
		'block-all-mixed-content'   => array( 'Bloquer le contenu mixte', 'Bloque tout contenu chargé en http sur une page https. Directive dépréciée mais encore largement supportée.' ),
	);

	/**
	 * CSP3 -elem sub-directives fall back to these when we only manage the parent.
	 * -attr sub-directives are NOT aliased here: they're managed directly (see
	 * VALUE_DIRECTIVE_LABELS) since, unlike -elem, they can't be nonce-covered by the parent.
	 */
	const DIRECTIVE_ALIASES = array(
		'script-src-elem' => 'script-src',
		'style-src-elem'  => 'style-src',
	);

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_pardesign_csp_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_pardesign_csp_start_capture', array( $this, 'handle_start_capture' ) );
		add_action( 'admin_post_pardesign_csp_clear_reports', array( $this, 'handle_clear_reports' ) );
		add_action( 'admin_post_pardesign_csp_quick_add', array( $this, 'handle_quick_add' ) );
		add_action( 'admin_post_pardesign_csp_save_scan_config', array( $this, 'handle_save_scan_config' ) );
	}

	public function menu(): void {
		add_management_page(
			__( 'Sécurité PAR Design', 'pardesign-entretien' ),
			__( 'Sécurité PAR Design', 'pardesign-entretien' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Pardesign_Entretien_Csp::get_settings();
		$pending  = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		if ( false !== $pending ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		}
		$notice   = is_array( $pending ) && ! empty( $pending['msg'] ) ? (string) $pending['msg'] : '';
		$open_url = is_array( $pending ) && ! empty( $pending['open'] ) ? (string) $pending['open'] : '';
		$preview  = Pardesign_Entretien_Csp::build_header_value( $settings );
		$header   = ! empty( $settings['report_only'] ) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sécurité PAR Design — Politique CSP', 'pardesign-entretien' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Configure l’en-tête Content-Security-Policy du site : active les directives dont tu as besoin, personnalise leurs sources, et utilise le mode « Report-Only » pour tester sans rien bloquer.', 'pardesign-entretien' ); ?></p>

			<?php if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Cette version de WordPress ne fournit pas WP_HTML_Tag_Processor : l’injection automatique du nonce dans les balises <script>/<style> existantes est désactivée. L’en-tête CSP reste envoyé normalement.', 'pardesign-entretien' ); ?></p></div>
			<?php endif; ?>

			<?php if ( defined( 'W3TC_DIR' ) || function_exists( 'wp_cache_clean_cache' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Un plugin de mise en cache de page complète est actif sur ce site. Si le nonce est figé dans une page mise en cache, il devient prévisible et perd son utilité contre les attaques XSS : exclus les pages concernées du cache, ou vide le cache après chaque changement.', 'pardesign-entretien' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pardesign_csp_save' ); ?>
				<input type="hidden" name="action" value="pardesign_csp_save">

				<h2><?php esc_html_e( 'Réglages généraux', 'pardesign-entretien' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Politique CSP', 'pardesign-entretien' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
								<?php esc_html_e( 'Envoyer l’en-tête CSP sur le site', 'pardesign-entretien' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mode', 'pardesign-entretien' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="report_only" value="1" <?php checked( ! empty( $settings['report_only'] ) ); ?>>
								<?php esc_html_e( 'Report-Only (journalise les violations sans rien bloquer — recommandé pour tester)', 'pardesign-entretien' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Administration', 'pardesign-entretien' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="apply_admin" value="1" <?php checked( ! empty( $settings['apply_admin'] ) ); ?>>
								<?php esc_html_e( 'Appliquer aussi dans wp-admin', 'pardesign-entretien' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Déconseillé sauf test ciblé : de nombreuses extensions d’administration s’appuient sur du JS/CSS inline sans nonce.', 'pardesign-entretien' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="report_uri"><?php esc_html_e( 'URI de rapport (report-uri)', 'pardesign-entretien' ); ?></label></th>
						<td>
							<input name="report_uri" id="report_uri" type="url" class="regular-text" value="<?php echo esc_attr( $settings['report_uri'] ); ?>" placeholder="https://exemple.com/csp-report">
							<p class="description"><?php esc_html_e( 'Optionnel. Les navigateurs y enverront un rapport JSON à chaque violation détectée.', 'pardesign-entretien' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Directives', 'pardesign-entretien' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:1%"><?php esc_html_e( 'Actif', 'pardesign-entretien' ); ?></th>
							<th style="width:20%"><?php esc_html_e( 'Directive', 'pardesign-entretien' ); ?></th>
							<th><?php esc_html_e( 'Sources autorisées', 'pardesign-entretien' ); ?></th>
							<th style="width:10%"><?php esc_html_e( 'Nonce', 'pardesign-entretien' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( self::VALUE_DIRECTIVE_LABELS as $name => $label ) : ?>
						<?php $conf = $settings['directives'][ $name ]; ?>
						<tr>
							<td><input type="checkbox" name="directives[<?php echo esc_attr( $name ); ?>][enabled]" value="1" <?php checked( ! empty( $conf['enabled'] ) ); ?>></td>
							<td>
								<label for="dir-<?php echo esc_attr( $name ); ?>"><code><?php echo esc_html( $name ); ?></code></label>
								<p class="description"><?php echo esc_html( $label[0] ); ?> — <?php echo wp_kses( $label[1], array( 'code' => array() ) ); ?></p>
							</td>
							<td>
								<input type="text" id="dir-<?php echo esc_attr( $name ); ?>" name="directives[<?php echo esc_attr( $name ); ?>][value]" class="large-text code" value="<?php echo esc_attr( $conf['value'] ); ?>" placeholder="'self' https://exemple.com">
							</td>
							<td>
								<?php if ( in_array( $name, Pardesign_Entretien_Csp::NONCE_DIRECTIVES, true ) ) : ?>
									<label>
										<input type="checkbox" name="directives[<?php echo esc_attr( $name ); ?>][nonce]" value="1" <?php checked( ! empty( $conf['nonce'] ) ); ?>>
										<?php esc_html_e( 'Ajouter', 'pardesign-entretien' ); ?>
									</label>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php foreach ( self::FLAG_DIRECTIVE_LABELS as $name => $label ) : ?>
						<?php $conf = $settings['directives'][ $name ]; ?>
						<tr>
							<td><input type="checkbox" name="directives[<?php echo esc_attr( $name ); ?>][enabled]" value="1" <?php checked( ! empty( $conf['enabled'] ) ); ?>></td>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<p class="description"><?php echo esc_html( $label[0] ); ?> — <?php echo esc_html( $label[1] ); ?></p>
							</td>
							<td colspan="2"><em><?php esc_html_e( 'Directive booléenne, sans valeur.', 'pardesign-entretien' ); ?></em></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Le nonce est régénéré aléatoirement à chaque chargement de page, puis automatiquement ajouté aux balises <script> et <style> inline du site (thème, extensions, blocs) qui n’en ont pas déjà un.', 'pardesign-entretien' ); ?>
				</p>

				<?php submit_button( __( 'Enregistrer', 'pardesign-entretien' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Aperçu de l’en-tête actuel', 'pardesign-entretien' ); ?></h2>
			<?php if ( empty( $settings['enabled'] ) ) : ?>
				<p><em><?php esc_html_e( 'La politique CSP est désactivée : aucun en-tête n’est envoyé.', 'pardesign-entretien' ); ?></em></p>
			<?php elseif ( '' === $preview ) : ?>
				<p><em><?php esc_html_e( 'Aucune directive active : aucun en-tête ne serait envoyé.', 'pardesign-entretien' ); ?></em></p>
			<?php else : ?>
				<p><code><?php echo esc_html( $header ); ?>:</code></p>
				<textarea class="large-text code" rows="4" readonly><?php echo esc_textarea( $preview ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Le « nonce-… » affiché ici est un exemple : une nouvelle valeur est générée à chaque chargement de page.', 'pardesign-entretien' ); ?></p>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Capturer les violations', 'pardesign-entretien' ); ?></h2>
			<p><?php esc_html_e( 'Le navigateur seul peut évaluer une politique CSP et signaler ce qu’il bloque : entre une URL du site et clique sur « Visiter et capturer ». La page s’ouvre dans un nouvel onglet, pendant une fenêtre de 10 minutes, avec un lien de rapport propre à cette visite — le reste du trafic du site n’envoie jamais de rapport.', 'pardesign-entretien' ); ?></p>
			<p class="description"><?php esc_html_e( 'Pendant cette fenêtre, un court échantillon (~40 caractères, tronqué par le navigateur) du script/style inline ou de l’eval bloqué est aussi demandé, pour donner du contexte aux entrées « inline »/« eval »/« data ». Support variable selon le navigateur ; échantillon toujours affiché en texte brut ci-dessous, jamais interprété.', 'pardesign-entretien' ); ?></p>

			<?php if ( empty( $settings['enabled'] ) ) : ?>
				<p><em><?php esc_html_e( 'Active la politique CSP ci-dessus avant de lancer une capture : sans directive active, le navigateur n’a rien à signaler.', 'pardesign-entretien' ); ?></em></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'pardesign_csp_start_capture' ); ?>
					<input type="hidden" name="action" value="pardesign_csp_start_capture">
					<input type="url" name="capture_url" class="regular-text" value="<?php echo esc_attr( home_url( '/' ) ); ?>">
					<?php submit_button( __( 'Visiter et capturer', 'pardesign-entretien' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( Pardesign_Entretien_Csp_Reports::is_capturing() ) : ?>
					<p class="description"><?php esc_html_e( 'Une fenêtre de capture est actuellement ouverte (10 minutes).', 'pardesign-entretien' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php $this->render_scan_section( $settings ); ?>

			<?php $this->render_reports(); ?>
		</div>
		<?php
		$this->render_capture_opener( $open_url );
	}

	/**
	 * Auto-opens the freshly-started capture URL in a new tab, once, right after the
	 * redirect. The URL comes from the per-user transient set by handle_start_capture(),
	 * consumed on render, so a crafted link cannot make an admin's browser open anything.
	 */
	private function render_capture_opener( string $open_url ): void {
		$open_url = esc_url_raw( $open_url );
		if ( ! $open_url || ! wp_http_validate_url( $open_url ) ) {
			return;
		}
		?>
		<script>
		window.open(<?php echo wp_json_encode( $open_url, JSON_HEX_TAG | JSON_HEX_AMP ); ?>, '_blank');
		</script>
		<?php
	}

	/**
	 * Background scanner section: which archives/posts to visit (per post type), a
	 * full-site scan trigger, and a single-URL scan trigger — both run as a WP-Cron
	 * background job, with progress polled over REST rather than a blocking page load.
	 */
	private function render_scan_section( array $settings ): void {
		?>
		<hr>
		<h2><?php esc_html_e( 'Scanner le site (analyse statique)', 'pardesign-entretien' ); ?></h2>
		<p><?php esc_html_e( 'Récupère chaque page en arrière-plan et analyse son HTML par rapport à la politique enregistrée ci-dessus : scripts/styles inline sans nonce, attributs style=/onclick= sans \'unsafe-inline\', ressources externes absentes de la liste autorisée. Aucun navigateur requis, donc pratique pour vérifier plusieurs pages d’un coup — mais c’est une simulation, pas une vraie exécution : ce que le JavaScript de la page fait lui-même à l’exécution (eval(), un script qui insère un autre <script> après coup, un appel fetch()…) reste invisible. Pour ces cas, utilise la capture par navigateur ci-dessus.', 'pardesign-entretien' ); ?></p>

		<?php if ( empty( $settings['enabled'] ) ) : ?>
			<p><em><?php esc_html_e( 'Active la politique CSP ci-dessus avant de lancer un scan : sans directive active, rien ne serait détecté comme bloqué.', 'pardesign-entretien' ); ?></em></p>
			<?php return; ?>
		<?php endif; ?>
		<?php $this->render_scan_controls(); ?>
		<?php
	}

	private function render_scan_controls(): void {
		?>
		<h3><?php esc_html_e( 'Pages à inclure dans un scan complet', 'pardesign-entretien' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pardesign_csp_save_scan_config' ); ?>
			<input type="hidden" name="action" value="pardesign_csp_save_scan_config">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type de contenu', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Archive', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Nombre les plus récents', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Ou article(s)/page(s) spécifique(s) (remplace « les plus récents »)', 'pardesign-entretien' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$scan_settings = Pardesign_Entretien_Csp_Scanner::get_scan_settings();
				foreach ( $scan_settings['post_types'] as $post_type => $conf ) :
					$post_type_object = get_post_type_object( $post_type );
					$label            = $post_type_object ? $post_type_object->labels->name : $post_type;
					$has_archive      = 'page' !== $post_type;
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $post_type ); ?></code></td>
						<td>
							<?php if ( $has_archive ) : ?>
								<input type="checkbox" name="post_types[<?php echo esc_attr( $post_type ); ?>][archive]" value="1" <?php checked( ! empty( $conf['archive'] ) ); ?>>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td>
							<input type="number" min="0" max="20" style="width:5em" name="post_types[<?php echo esc_attr( $post_type ); ?>][count]" value="<?php echo esc_attr( (string) $conf['count'] ); ?>">
						</td>
						<td>
							<div class="pardesign-csp-picker" data-post-type="<?php echo esc_attr( $post_type ); ?>">
								<div class="pardesign-csp-picker-chips">
									<?php foreach ( $conf['specific_ids'] as $post_id ) : ?>
										<?php $title = get_the_title( $post_id ); ?>
										<span class="pardesign-csp-chip">
											<input type="hidden" name="post_types[<?php echo esc_attr( $post_type ); ?>][specific_ids][]" value="<?php echo esc_attr( (string) $post_id ); ?>">
											<?php echo esc_html( '' !== $title ? $title : ( '#' . $post_id ) ); ?>
											<button type="button" class="pardesign-csp-chip-remove" aria-label="<?php esc_attr_e( 'Retirer', 'pardesign-entretien' ); ?>">&times;</button>
										</span>
									<?php endforeach; ?>
								</div>
								<input type="text" class="regular-text pardesign-csp-picker-search" placeholder="<?php esc_attr_e( 'Rechercher…', 'pardesign-entretien' ); ?>" autocomplete="off">
								<ul class="pardesign-csp-picker-suggestions" hidden></ul>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Enregistrer la configuration du scan', 'pardesign-entretien' ) ); ?>
		</form>

		<style>
		.pardesign-csp-picker { position: relative; max-width: 320px; }
		.pardesign-csp-picker-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px; }
		.pardesign-csp-chip { display: inline-flex; align-items: center; gap: 4px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 3px; padding: 2px 6px; font-size: 12px; }
		.pardesign-csp-chip-remove { background: none; border: none; cursor: pointer; font-size: 14px; line-height: 1; padding: 0; color: #b32d2e; }
		.pardesign-csp-picker-suggestions { position: absolute; z-index: 10; background: #fff; border: 1px solid #dcdcde; box-shadow: 0 2px 6px rgba(0,0,0,.15); max-height: 220px; overflow-y: auto; margin: 0; padding: 0; list-style: none; width: 100%; max-width: 320px; }
		.pardesign-csp-picker-suggestions li { padding: 6px 8px; cursor: pointer; font-size: 13px; }
		.pardesign-csp-picker-suggestions li:hover { background: #2271b1; color: #fff; }
		</style>

		<h3><?php esc_html_e( 'Lancer un scan', 'pardesign-entretien' ); ?></h3>
		<p>
			<button type="button" id="pardesign-csp-scan-full" class="button button-primary"><?php esc_html_e( 'Lancer un scan complet', 'pardesign-entretien' ); ?></button>
			<span class="description"><?php esc_html_e( 'Page d’accueil + archives/articles configurés ci-dessus.', 'pardesign-entretien' ); ?></span>
		</p>
		<p>
			<input type="url" id="pardesign-csp-scan-url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/une-page' ) ); ?>">
			<button type="button" id="pardesign-csp-scan-one" class="button"><?php esc_html_e( 'Scanner cette URL', 'pardesign-entretien' ); ?></button>
		</p>
		<p id="pardesign-csp-scan-progress" class="description" style="display:none;"></p>

		<script>
		( function () {
			var restBase       = <?php echo wp_json_encode( esc_url_raw( rest_url( 'pardesign-entretien/v1/csp-scan/' ) ), JSON_HEX_TAG | JSON_HEX_AMP ); ?>;
			var nonce           = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var initiallyRunning = <?php echo wp_json_encode( Pardesign_Entretien_Csp_Scanner::is_running() ); ?>;

			var btnFull    = document.getElementById( 'pardesign-csp-scan-full' );
			var btnOne     = document.getElementById( 'pardesign-csp-scan-one' );
			var urlInput   = document.getElementById( 'pardesign-csp-scan-url' );
			var progressEl = document.getElementById( 'pardesign-csp-scan-progress' );

			function setBusy( busy ) {
				if ( btnFull ) { btnFull.disabled = busy; }
				if ( btnOne ) { btnOne.disabled = busy; }
			}

			function poll() {
				fetch( restBase + 'status', { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						if ( ! progressEl ) { return; }
						progressEl.style.display = 'block';
						if ( data.running ) {
							progressEl.textContent = data.done + ' / ' + data.total + ' pages scannées — en cours : ' + ( data.current_url || '…' );
							setTimeout( poll, 1500 );
						} else {
							setBusy( false );
							var msg = ( data.total || 0 ) + ' page(s) scannée(s).';
							if ( data.errors && data.errors.length ) {
								msg += ' ' + data.errors.length + ' page(s) inaccessible(s).';
							}
							progressEl.textContent = msg + ' Rechargement…';
							setTimeout( function () { window.location.reload(); }, 800 );
						}
					} )
					.catch( function () {
						setBusy( false );
						if ( progressEl ) { progressEl.textContent = 'Erreur de communication avec le serveur.'; }
					} );
			}

			function start( mode, url ) {
				setBusy( true );
				if ( progressEl ) {
					progressEl.style.display = 'block';
					progressEl.textContent = 'Démarrage du scan…';
				}
				var body = { mode: mode };
				if ( url ) { body.url = url; }
				fetch( restBase + 'start', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					credentials: 'same-origin',
					body: JSON.stringify( body ),
				} )
					.then( function ( r ) { return r.json().then( function ( data ) { return { ok: r.ok, data: data }; } ); } )
					.then( function ( res ) {
						if ( ! res.ok ) {
							setBusy( false );
							if ( progressEl ) { progressEl.textContent = 'Erreur : ' + ( res.data && res.data.error ? res.data.error : 'inconnue' ); }
							return;
						}
						poll();
					} )
					.catch( function () {
						setBusy( false );
						if ( progressEl ) { progressEl.textContent = 'Erreur de communication avec le serveur.'; }
					} );
			}

			if ( btnFull ) {
				btnFull.addEventListener( 'click', function () { start( 'full', null ); } );
			}
			if ( btnOne ) {
				btnOne.addEventListener( 'click', function () {
					var url = urlInput ? urlInput.value.trim() : '';
					if ( ! url ) {
						window.alert( 'Indique une URL.' );
						return;
					}
					start( 'url', url );
				} );
			}
			if ( initiallyRunning ) {
				setBusy( true );
				poll();
			}

			// Post picker: search-as-you-type chips, one per post-type row.
			document.querySelectorAll( '.pardesign-csp-picker' ).forEach( function ( picker ) {
				var postType      = picker.getAttribute( 'data-post-type' );
				var chipsEl        = picker.querySelector( '.pardesign-csp-picker-chips' );
				var searchEl       = picker.querySelector( '.pardesign-csp-picker-search' );
				var suggestEl      = picker.querySelector( '.pardesign-csp-picker-suggestions' );
				var debounceTimer  = null;

				function hasChip( id ) {
					return !! chipsEl.querySelector( 'input[value="' + id + '"]' );
				}

				function wireRemove( chip ) {
					var btn = chip.querySelector( '.pardesign-csp-chip-remove' );
					if ( btn ) {
						btn.addEventListener( 'click', function () { chip.remove(); } );
					}
				}

				function addChip( id, title ) {
					if ( hasChip( id ) ) { return; }
					var chip = document.createElement( 'span' );
					chip.className = 'pardesign-csp-chip';

					var hidden = document.createElement( 'input' );
					hidden.type = 'hidden';
					hidden.name = 'post_types[' + postType + '][specific_ids][]';
					hidden.value = id;
					chip.appendChild( hidden );
					chip.appendChild( document.createTextNode( title ) );

					var remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'pardesign-csp-chip-remove';
					remove.setAttribute( 'aria-label', 'Retirer' );
					remove.textContent = '×';
					chip.appendChild( remove );

					chipsEl.appendChild( chip );
					wireRemove( chip );
				}

				chipsEl.querySelectorAll( '.pardesign-csp-chip' ).forEach( wireRemove );

				function closeSuggestions() {
					suggestEl.hidden = true;
					suggestEl.innerHTML = '';
				}

				function search( term ) {
					var url = restBase + 'search-posts?post_type=' + encodeURIComponent( postType ) + '&s=' + encodeURIComponent( term );
					fetch( url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( results ) {
							suggestEl.innerHTML = '';
							if ( ! Array.isArray( results ) || 0 === results.length ) {
								closeSuggestions();
								return;
							}
							results.forEach( function ( item ) {
								var li = document.createElement( 'li' );
								li.textContent = item.id + ' – ' + item.title;
								li.addEventListener( 'mousedown', function ( e ) {
									e.preventDefault();
									addChip( item.id, item.title );
									searchEl.value = '';
									closeSuggestions();
								} );
								suggestEl.appendChild( li );
							} );
							suggestEl.hidden = false;
						} )
						.catch( closeSuggestions );
				}

				searchEl.addEventListener( 'input', function () {
					clearTimeout( debounceTimer );
					var term = searchEl.value.trim();
					debounceTimer = setTimeout( function () { search( term ); }, 300 );
				} );
				searchEl.addEventListener( 'focus', function () { search( searchEl.value.trim() ); } );
				searchEl.addEventListener( 'blur', function () { setTimeout( closeSuggestions, 150 ); } );
			} );
		} )();
		</script>
		<?php
	}

	private function render_reports(): void {
		$groups = Pardesign_Entretien_Csp_Reports::grouped();
		?>
		<h3><?php esc_html_e( 'Rapports capturés', 'pardesign-entretien' ); ?></h3>
		<?php if ( empty( $groups ) ) : ?>
			<p><em><?php esc_html_e( 'Aucun rapport capturé pour l’instant.', 'pardesign-entretien' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ressource bloquée', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Échantillon', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Directive', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Occurrences', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Dernière fois', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Page(s) concernée(s)', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Détecté via', 'pardesign-entretien' ); ?></th>
						<th><?php esc_html_e( 'Action', 'pardesign-entretien' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<?php
					$directive    = self::normalize_directive( $group['directive'] );
					$quick_origin = in_array( $directive, Pardesign_Entretien_Csp::VALUE_DIRECTIVES, true )
						? self::origin_for_quick_add( $group['blocked'] )
						: '';
					?>
					<tr>
						<td><?php self::render_blocked_resource( $group['blocked'] ); ?></td>
						<td>
							<?php if ( '' !== $group['sample'] ) : ?>
								<code style="white-space:pre-wrap;word-break:break-all;display:inline-block;max-width:32ch;">
									<?php // Always esc_html: this text can be an attacker's actual injected payload. Never render it as HTML. ?>
									<?php echo esc_html( $group['sample'] ); ?>
								</code>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td>
							<?php
							$source_title = $group['source']
								? sprintf( 'Source : %s', $group['source'] . ( $group['line'] ? ':' . $group['line'] : '' ) )
								: '';
							?>
							<code<?php echo $source_title ? ' style="text-decoration:underline dotted;cursor:help;" title="' . esc_attr( $source_title ) . '"' : ''; ?>><?php echo esc_html( $group['directive'] ); ?></code>
						</td>
						<td><?php echo esc_html( (string) $group['count'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								$group['last_seen']
									? wp_date( 'Y-m-d H:i', $group['last_seen'] )
									: ''
							);
							?>
						</td>
						<td>
							<?php foreach ( $group['documents'] as $doc ) : ?>
								<div><code><?php echo esc_html( self::display_path( $doc ) ); ?></code></div>
							<?php endforeach; ?>
						</td>
						<td><?php self::render_methods( $group['methods'] ); ?></td>
						<td>
							<?php if ( $quick_origin ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'pardesign_csp_quick_add' ); ?>
									<input type="hidden" name="action" value="pardesign_csp_quick_add">
									<input type="hidden" name="directive" value="<?php echo esc_attr( $directive ); ?>">
									<input type="hidden" name="origin" value="<?php echo esc_attr( $quick_origin ); ?>">
									<?php
									submit_button(
										sprintf(
											/* translators: 1: source à ajouter, 2: directive CSP cible */
											__( '+ Autoriser %1$s dans %2$s', 'pardesign-entretien' ),
											$quick_origin,
											$directive
										),
										'small',
										'submit',
										false
									);
									?>
								</form>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
				<?php wp_nonce_field( 'pardesign_csp_clear_reports' ); ?>
				<input type="hidden" name="action" value="pardesign_csp_clear_reports">
				<?php submit_button( __( 'Vider les rapports', 'pardesign-entretien' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_csp_save' );

		$posted_directives = isset( $_POST['directives'] ) && is_array( $_POST['directives'] ) ? wp_unslash( $_POST['directives'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$directives = array();
		foreach ( Pardesign_Entretien_Csp::VALUE_DIRECTIVES as $name ) {
			$posted = isset( $posted_directives[ $name ] ) && is_array( $posted_directives[ $name ] ) ? $posted_directives[ $name ] : array();
			$directives[ $name ] = array(
				'enabled' => ! empty( $posted['enabled'] ),
				'value'   => isset( $posted['value'] ) ? sanitize_text_field( $posted['value'] ) : '',
				'nonce'   => in_array( $name, Pardesign_Entretien_Csp::NONCE_DIRECTIVES, true ) && ! empty( $posted['nonce'] ),
			);
		}
		foreach ( Pardesign_Entretien_Csp::FLAG_DIRECTIVES as $name ) {
			$posted              = isset( $posted_directives[ $name ] ) && is_array( $posted_directives[ $name ] ) ? $posted_directives[ $name ] : array();
			$directives[ $name ] = array( 'enabled' => ! empty( $posted['enabled'] ) );
		}

		Pardesign_Entretien_Csp::update_settings(
			array(
				'enabled'     => ! empty( $_POST['enabled'] ),
				'report_only' => ! empty( $_POST['report_only'] ),
				'apply_admin' => ! empty( $_POST['apply_admin'] ),
				'report_uri'  => isset( $_POST['report_uri'] ) ? esc_url_raw( wp_unslash( $_POST['report_uri'] ) ) : '',
				'directives'  => $directives,
			)
		);

		$this->redirect( __( 'Réglages CSP enregistrés.', 'pardesign-entretien' ) );
	}

	/**
	 * Opens a capture window and redirects back with the capture URL, which the page
	 * then opens itself in a new tab (see the inline script in render()) — this is what
	 * "transparently visits" the page: the admin never has to build the URL by hand.
	 */
	public function handle_start_capture(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_csp_start_capture' );

		$target = isset( $_POST['capture_url'] ) ? esc_url_raw( wp_unslash( $_POST['capture_url'] ) ) : '';
		if ( '' === $target ) {
			$target = home_url( '/' );
		}

		$token       = Pardesign_Entretien_Csp_Reports::start_capture();
		$capture_url = add_query_arg( Pardesign_Entretien_Csp_Reports::QUERY_ARG, $token, $target );

		$this->redirect( __( 'Capture démarrée : la page s’ouvre dans un nouvel onglet, puis reviens ici dans quelques secondes.', 'pardesign-entretien' ), $capture_url );
	}

	/** Adds a blocked resource's origin to the allow-list of the directive that blocked it. */
	public function handle_quick_add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_csp_quick_add' );

		$directive = isset( $_POST['directive'] ) ? self::normalize_directive( sanitize_key( wp_unslash( $_POST['directive'] ) ) ) : '';
		$origin    = isset( $_POST['origin'] ) ? sanitize_text_field( wp_unslash( $_POST['origin'] ) ) : '';

		if ( ! in_array( $directive, Pardesign_Entretien_Csp::VALUE_DIRECTIVES, true ) || ! self::is_valid_source_expression( $origin ) ) {
			$this->redirect( __( 'Origine ou directive invalide.', 'pardesign-entretien' ) );
		}

		$settings = Pardesign_Entretien_Csp::get_settings();
		$conf     = $settings['directives'][ $directive ];

		if ( empty( $conf['enabled'] ) ) {
			// Was inheriting from default-src: seed with its current value so scoping
			// this directive doesn't silently drop what default-src used to allow.
			$conf['enabled'] = true;
			$conf['value']   = $settings['directives']['default-src']['value'];
		}

		$tokens = preg_split( '/\s+/', trim( $conf['value'] ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! in_array( $origin, $tokens, true ) ) {
			$tokens[] = $origin;
		}
		$conf['value'] = implode( ' ', $tokens );

		$settings['directives'][ $directive ] = $conf;
		Pardesign_Entretien_Csp::update_settings( $settings );

		$this->redirect(
			sprintf(
				/* translators: 1: source ajoutée, 2: directive CSP */
				__( '« %1$s » ajouté à %2$s.', 'pardesign-entretien' ),
				$origin,
				$directive
			)
		);
	}

	/** Saves which archives/posts the background scanner should visit for each post type. */
	public function handle_save_scan_config(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_csp_save_scan_config' );

		$posted = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$post_types = array();
		foreach ( Pardesign_Entretien_Csp_Scanner::scannable_post_types() as $post_type ) {
			$row = isset( $posted[ $post_type ] ) && is_array( $posted[ $post_type ] ) ? $posted[ $post_type ] : array();

			$specific_ids = array();
			if ( ! empty( $row['specific_ids'] ) && is_array( $row['specific_ids'] ) ) {
				foreach ( $row['specific_ids'] as $piece ) {
					$id = (int) $piece;
					if ( $id > 0 ) {
						$specific_ids[] = $id;
					}
				}
			}

			$post_types[ $post_type ] = array(
				'archive'      => ! empty( $row['archive'] ),
				'count'        => isset( $row['count'] ) ? max( 0, min( 20, (int) $row['count'] ) ) : 0,
				'specific_ids' => $specific_ids,
			);
		}

		Pardesign_Entretien_Csp_Scanner::update_scan_settings( array( 'post_types' => $post_types ) );

		$this->redirect( __( 'Configuration du scan enregistrée.', 'pardesign-entretien' ) );
	}

	/** Maps a CSP3 sub-directive (script-src-elem…) back to the parent directive we manage. */
	private static function normalize_directive( string $name ): string {
		return self::DIRECTIVE_ALIASES[ $name ] ?? $name;
	}

	/**
	 * Document URL for display: path relative to the site's own install path
	 * (+ remaining query), capture query arg stripped. E.g. on a site installed at
	 * https://example.com/site/, ".../site/blog/mon-article/" displays as "/blog/mon-article/"
	 * and the homepage itself displays as "/".
	 */
	private static function display_path( string $url ): string {
		$clean = remove_query_arg( Pardesign_Entretien_Csp_Reports::QUERY_ARG, $url );
		$parts = wp_parse_url( $clean );
		$path  = ! empty( $parts['path'] ) ? $parts['path'] : '/';

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) - 1 );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}
		return $path;
	}

	/** Renders the blocked-resource badge, truncated to 40 chars with the full value on hover. */
	private static function render_blocked_resource( string $blocked ): void {
		$text      = '' !== $blocked ? $blocked : '(vide)';
		$max       = 40;
		$truncated = mb_strlen( $text ) > $max;
		$display   = $truncated ? mb_substr( $text, 0, $max ) . '…' : $text;
		?>
		<code style="background:#f8d7da;color:#58151c;padding:2px 6px;border-radius:3px;"<?php echo $truncated ? ' title="' . esc_attr( $text ) . '"' : ''; ?>>
			<?php echo esc_html( $display ); ?>
		</code>
		<?php
	}

	/**
	 * Shows how a finding was detected: a real browser reporting it (ground truth) vs.
	 * our own static-HTML scan (a simulation against the saved policy — see
	 * Pardesign_Entretien_Csp_Scanner's doc comment for what that can and can't catch).
	 */
	private static function render_methods( array $methods ): void {
		$labels = array(
			'report' => __( '🌐 Navigateur', 'pardesign-entretien' ),
			'scan'   => __( '🔍 Analyse statique', 'pardesign-entretien' ),
		);
		$out = array();
		foreach ( $methods as $method ) {
			$out[] = $labels[ $method ] ?? $method;
		}
		echo esc_html( implode( ' + ', $out ) );
	}

	/** Best-effort origin (or scheme keyword) a blocked-uri can be turned into a source expression for. '' if not addable. */
	private static function origin_for_quick_add( string $blocked ): string {
		$schemes = array(
			'data'        => 'data:',
			'blob'        => 'blob:',
			'filesystem'  => 'filesystem:',
			'mediastream' => 'mediastream:',
		);
		if ( isset( $schemes[ $blocked ] ) ) {
			return $schemes[ $blocked ];
		}
		if ( in_array( $blocked, array( 'inline', 'eval', 'wasm-eval', 'about', '' ), true ) ) {
			return '';
		}

		$parts = wp_parse_url( $blocked );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$home = wp_parse_url( home_url() );
		if ( isset( $home['host'] ) && strtolower( $home['host'] ) === strtolower( $parts['host'] )
			&& ( empty( $home['scheme'] ) || strtolower( $home['scheme'] ) === strtolower( $parts['scheme'] ) )
		) {
			return "'self'";
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}
		return $origin;
	}

	private static function is_valid_source_expression( string $value ): bool {
		if ( "'self'" === $value ) {
			return true;
		}
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:$/i', $value ) ) {
			return true;
		}
		return (bool) preg_match( '#^[a-z][a-z0-9+.-]*://[a-zA-Z0-9.-]+(:\d+)?$#i', $value );
	}

	public function handle_clear_reports(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'pardesign-entretien' ) );
		}
		check_admin_referer( 'pardesign_csp_clear_reports' );

		Pardesign_Entretien_Csp_Reports::clear_reports();

		$this->redirect( __( 'Rapports capturés effacés.', 'pardesign-entretien' ) );
	}

	/** Stores the notice (and optional URL to open) for this user, then redirects to the page without any query parameter. */
	private function redirect( string $msg, string $open_url = '' ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array( 'msg' => $msg, 'open' => $open_url ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}
}
