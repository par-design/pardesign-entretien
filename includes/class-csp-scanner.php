<?php
/**
 * Background CSP scanner: fetches a set of the site's own URLs and statically parses
 * each response's HTML to find what the saved policy would block — script/style tags
 * and attributes without a nonce/unsafe-inline, and external resources whose origin
 * isn't in the relevant directive's allow-list.
 *
 * This is a simulation against the saved policy, not a real browser enforcing it: it
 * cannot see anything a page's own JavaScript does at runtime (eval(), a script that
 * injects another <script> tag, a fetch() call, etc.) — only what's present in the
 * server-rendered HTML. Real, browser-confirmed violations still come from
 * Pardesign_Entretien_Csp_Reports's live capture. Runs as a WP-Cron background job so
 * scanning many pages doesn't tie up the admin's request; the admin page polls
 * /csp-scan/status over REST for progress instead of a blocking page load.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Csp_Scanner {

	const NAMESPACE           = 'pardesign-entretien/v1';
	const RUN_HOOK            = 'pardesign_csp_scan_run';
	const PROGRESS_TRANSIENT  = 'pardesign_entretien_csp_scan_progress';
	const PROGRESS_TTL        = HOUR_IN_SECONDS;
	const OPTION_SCAN_SETTINGS = 'pardesign_entretien_csp_scan_settings';
	const MAX_URLS            = 200;
	const MAX_FINDINGS_PER_PAGE = 60;

	/** Tag => [attribute, directive] for elements that load an external resource. */
	const EXTERNAL_RESOURCE_MAP = array(
		'SCRIPT' => array( 'src', 'script-src' ),
		'IMG'    => array( 'src', 'img-src' ),
		'IFRAME' => array( 'src', 'frame-src' ),
		'AUDIO'  => array( 'src', 'media-src' ),
		'VIDEO'  => array( 'src', 'media-src' ),
		'SOURCE' => array( 'src', 'media-src' ),
		'OBJECT' => array( 'data', 'object-src' ),
		'EMBED'  => array( 'src', 'object-src' ),
	);

	/** CSP "fetch directive" fallback chain: if a directive isn't set, this is what governs it instead. */
	const FETCH_FALLBACK = array(
		'script-src-attr' => 'script-src',
		'script-src'      => 'default-src',
		'style-src-attr'  => 'style-src',
		'style-src'       => 'default-src',
		'img-src'         => 'default-src',
		'font-src'        => 'default-src',
		'connect-src'     => 'default-src',
		'frame-src'       => 'default-src',
		'object-src'      => 'default-src',
		'media-src'       => 'default-src',
		'worker-src'      => 'default-src',
		'manifest-src'    => 'default-src',
	);

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::RUN_HOOK, array( __CLASS__, 'run_scan' ), 10, 2 );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/csp-scan/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_start' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'show_in_index'       => false,
				'args'                => array(
					'mode' => array( 'type' => 'string', 'default' => 'full', 'enum' => array( 'full', 'url' ) ),
					'url'  => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/csp-scan/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/csp-scan/search-posts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_search_posts' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'show_in_index'       => false,
				'args'                => array(
					'post_type' => array( 'type' => 'string', 'required' => true ),
					's'         => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Post search for the admin's "specific post(s)" picker. Deliberately our own
	 * endpoint rather than core's /wp/v2/{post_type}: some post types here have
	 * show_in_rest=false (no core REST route exists for them at all), and this way
	 * search behavior/permissions stay uniform across every scannable post type.
	 *
	 * Uses $wpdb directly rather than WP_Query/get_posts(): search plugins commonly hook
	 * pre_get_posts/posts_where and rewrite post_type, posts_per_page or post_status on
	 * ANY query where the 's' query var is set and is_admin() is false — which a REST
	 * request also is. WP_Query can't be trusted for a title search across client
	 * sites; a plain parameterized query sidesteps that entirely.
	 */
	public function handle_search_posts( WP_REST_Request $request ) {
		global $wpdb;

		$post_type = (string) $request->get_param( 'post_type' );
		if ( ! in_array( $post_type, self::scannable_post_types(), true ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Type de contenu invalide.', 'pardesign-entretien' ) ), 400 );
		}

		$search = trim( (string) $request->get_param( 's' ) );

		if ( '' === $search ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_date DESC LIMIT 20",
					$post_type
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s ORDER BY post_date DESC LIMIT 20",
					$post_type,
					'%' . $wpdb->esc_like( $search ) . '%'
				)
			);
		}

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'id'    => (int) $row->ID,
				'title' => '' !== $row->post_title ? $row->post_title : ( '#' . $row->ID ),
			);
		}

		return new WP_REST_Response( $results, 200 );
	}

	public function handle_start( WP_REST_Request $request ) {
		if ( self::is_running() ) {
			return new WP_REST_Response( array( 'error' => __( 'Un scan est déjà en cours.', 'pardesign-entretien' ) ), 409 );
		}

		$mode = (string) $request->get_param( 'mode' );
		if ( 'url' === $mode ) {
			$url = esc_url_raw( (string) $request->get_param( 'url' ) );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return new WP_REST_Response( array( 'error' => __( 'URL invalide.', 'pardesign-entretien' ) ), 400 );
			}
			$urls = array( $url );
		} else {
			$urls = self::build_url_list( self::get_scan_settings() );
		}

		if ( empty( $urls ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Aucune URL à scanner.', 'pardesign-entretien' ) ), 400 );
		}

		$run_id = wp_generate_password( 12, false, false );
		self::set_progress(
			array(
				'run_id'      => $run_id,
				'total'       => count( $urls ),
				'done'        => 0,
				'current_url' => '',
				'running'     => true,
				'started_at'  => time(),
				'finished_at' => null,
				'errors'      => array(),
			)
		);

		wp_schedule_single_event( time(), self::RUN_HOOK, array( $urls, $run_id ) );
		self::nudge_cron();

		return new WP_REST_Response( array( 'ok' => true, 'run_id' => $run_id, 'total' => count( $urls ) ), 202 );
	}

	public function handle_status( WP_REST_Request $request ) {
		return new WP_REST_Response( self::get_progress(), 200 );
	}

	public static function is_running(): bool {
		$progress = self::get_progress();
		return ! empty( $progress['running'] );
	}

	public static function get_progress(): array {
		$progress = get_transient( self::PROGRESS_TRANSIENT );
		if ( is_array( $progress ) ) {
			return $progress;
		}
		return array(
			'running'     => false,
			'total'       => 0,
			'done'        => 0,
			'current_url' => '',
			'started_at'  => null,
			'finished_at' => null,
			'errors'      => array(),
		);
	}

	public static function set_progress( array $progress ): void {
		set_transient( self::PROGRESS_TRANSIENT, $progress, self::PROGRESS_TTL );
	}

	private static function record_error( string $url, string $message ): void {
		$progress              = self::get_progress();
		$progress['errors'][]  = array( 'url' => $url, 'message' => $message );
		self::set_progress( $progress );
	}

	// ---------------------------------------------------------------------
	// Scan target configuration (which post types / archives / posts to visit)
	// ---------------------------------------------------------------------

	/** Public post types eligible for scanning (attachments excluded: not meaningful CSP-wise). */
	public static function scannable_post_types(): array {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		unset( $objects['attachment'] );
		return array_keys( $objects );
	}

	public static function default_scan_settings(): array {
		$post_types = array();
		foreach ( self::scannable_post_types() as $post_type ) {
			$post_types[ $post_type ] = array(
				'archive'      => true,
				'count'        => 1,
				'specific_ids' => array(),
			);
		}
		return array( 'post_types' => $post_types );
	}

	public static function get_scan_settings(): array {
		$saved    = get_option( self::OPTION_SCAN_SETTINGS, array() );
		$defaults = self::default_scan_settings();
		if ( ! is_array( $saved ) || ! isset( $saved['post_types'] ) || ! is_array( $saved['post_types'] ) ) {
			$saved = array( 'post_types' => array() );
		}

		$post_types = array();
		foreach ( $defaults['post_types'] as $post_type => $default_conf ) {
			$saved_conf                 = isset( $saved['post_types'][ $post_type ] ) && is_array( $saved['post_types'][ $post_type ] ) ? $saved['post_types'][ $post_type ] : array();
			$post_types[ $post_type ]   = array_merge( $default_conf, $saved_conf );
		}

		return array( 'post_types' => $post_types );
	}

	public static function update_scan_settings( array $settings ): void {
		update_option( self::OPTION_SCAN_SETTINGS, $settings );
	}

	/** Builds the deduped URL list for a full scan: homepage + configured archives/posts per post type. */
	public static function build_url_list( array $scan_settings ): array {
		$urls = array( home_url( '/' ) );

		foreach ( $scan_settings['post_types'] as $post_type => $conf ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			if ( ! empty( $conf['archive'] ) ) {
				$archive = self::archive_url_for_post_type( $post_type );
				if ( $archive ) {
					$urls[] = $archive;
				}
			}

			$specific_ids = ! empty( $conf['specific_ids'] ) ? array_map( 'intval', (array) $conf['specific_ids'] ) : array();
			if ( ! empty( $specific_ids ) ) {
				foreach ( $specific_ids as $post_id ) {
					$link = get_permalink( $post_id );
					if ( $link ) {
						$urls[] = $link;
					}
				}
				continue;
			}

			$count = max( 0, (int) ( $conf['count'] ?? 0 ) );
			if ( $count > 0 ) {
				$post_ids = get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => 'publish',
						'posts_per_page' => $count,
						'orderby'        => 'date',
						'order'          => 'DESC',
						'fields'         => 'ids',
						'no_found_rows'  => true,
					)
				);
				foreach ( $post_ids as $post_id ) {
					$link = get_permalink( $post_id );
					if ( $link ) {
						$urls[] = $link;
					}
				}
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return array_slice( $urls, 0, self::MAX_URLS );
	}

	private static function archive_url_for_post_type( string $post_type ): string {
		if ( 'post' === $post_type ) {
			if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_for_posts' ) > 0 ) {
				$link = get_permalink( (int) get_option( 'page_for_posts' ) );
				return $link ? $link : '';
			}
			return home_url( '/' );
		}
		if ( 'page' === $post_type ) {
			return ''; // Pages have no collective archive of their own.
		}
		$link = get_post_type_archive_link( $post_type );
		return $link ? $link : '';
	}

	// ---------------------------------------------------------------------
	// The background job itself
	// ---------------------------------------------------------------------

	public static function run_scan( array $urls, string $run_id ): void {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$progress = self::get_progress();
		if ( ( $progress['run_id'] ?? '' ) !== $run_id ) {
			return; // Superseded by a newer run started in the meantime.
		}

		$settings = Pardesign_Entretien_Csp::get_settings();
		$total    = count( $urls );

		foreach ( $urls as $i => $url ) {
			$progress = self::get_progress();
			if ( ( $progress['run_id'] ?? '' ) !== $run_id ) {
				return;
			}
			$progress['current_url'] = $url;
			$progress['done']        = $i;
			self::set_progress( $progress );

			self::scan_url( $url, $settings );
		}

		$progress                = self::get_progress();
		$progress['done']        = $total;
		$progress['current_url'] = '';
		$progress['running']     = false;
		$progress['finished_at'] = time();
		self::set_progress( $progress );
	}

	public static function scan_url( string $url, array $settings ): void {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_error( $url, $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			self::record_error( $url, 'HTTP ' . $code );
			return;
		}

		if ( empty( $settings['enabled'] ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( '' === trim( $html ) ) {
			return;
		}

		self::analyze_html( $url, $html, $settings );
	}

	// ---------------------------------------------------------------------
	// Static HTML analysis
	// ---------------------------------------------------------------------

	private static function analyze_html( string $url, string $html, array $settings ): void {
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$processor = new WP_HTML_Tag_Processor( $html );
		$found     = 0;

		while ( $found < self::MAX_FINDINGS_PER_PAGE && $processor->next_tag() ) {
			$tag = (string) $processor->get_tag();

			// Inline event-handler attributes (onclick=, onload=…) — never nonce-able, ever.
			$on_attrs = $processor->get_attribute_names_with_prefix( 'on' );
			if ( ! empty( $on_attrs ) && ! self::attribute_is_allowed( 'script-src-attr', $settings ) ) {
				$attr_name = $on_attrs[0];
				$sample    = '<' . strtolower( $tag ) . ' ' . $attr_name . '="' . (string) $processor->get_attribute( $attr_name ) . '">';
				Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, 'script-src-attr', 'inline', $sample );
				++$found;
			}

			// Inline style="…" attribute — same story, never nonce-able.
			$style_attr = $processor->get_attribute( 'style' );
			if ( is_string( $style_attr ) && '' !== $style_attr && ! self::attribute_is_allowed( 'style-src-attr', $settings ) ) {
				$sample = '<' . strtolower( $tag ) . ' style="' . $style_attr . '">';
				Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, 'style-src-attr', 'inline', $sample );
				++$found;
			}

			if ( 'SCRIPT' === $tag ) {
				$src = $processor->get_attribute( 'src' );
				if ( is_string( $src ) && '' !== $src ) {
					if ( ! self::resource_is_allowed( 'script-src', $src, $settings, $home_host ) ) {
						Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, 'script-src', $src, '<script src="' . $src . '">' );
						++$found;
					}
				} else {
					$has_nonce = null !== $processor->get_attribute( 'nonce' );
					if ( ! self::element_is_inline_allowed( 'script-src', $settings, $has_nonce ) ) {
						$sample = self::inline_text( $processor );
						Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, 'script-src', 'inline', $sample );
						++$found;
					}
				}
				continue;
			}

			if ( 'STYLE' === $tag ) {
				$has_nonce = null !== $processor->get_attribute( 'nonce' );
				if ( ! self::element_is_inline_allowed( 'style-src', $settings, $has_nonce ) ) {
					$sample = self::inline_text( $processor );
					Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, 'style-src', 'inline', $sample );
					++$found;
				}
				continue;
			}

			if ( 'LINK' === $tag ) {
				$rel = strtolower( (string) $processor->get_attribute( 'rel' ) );
				if ( false !== strpos( $rel, 'stylesheet' ) ) {
					$href = $processor->get_attribute( 'href' );
					if ( is_string( $href ) && '' !== $href && ! self::resource_is_allowed( 'style-src', $href, $settings, $home_host ) ) {
						Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, 'style-src', $href, '<link rel="stylesheet" href="' . $href . '">' );
						++$found;
					}
				}
				continue;
			}

			if ( isset( self::EXTERNAL_RESOURCE_MAP[ $tag ] ) ) {
				list( $attr, $directive ) = self::EXTERNAL_RESOURCE_MAP[ $tag ];
				$value = $processor->get_attribute( $attr );
				if ( ( ! is_string( $value ) || '' === $value ) && 'IMG' === $tag ) {
					$value = self::first_srcset_url( (string) $processor->get_attribute( 'srcset' ) );
				}
				if ( is_string( $value ) && '' !== $value && ! self::resource_is_allowed( $directive, $value, $settings, $home_host ) ) {
					Pardesign_Entretien_Csp_Reports::record_scan_finding( $url, $directive, $value, '<' . strtolower( $tag ) . ' ' . $attr . '="' . $value . '">' );
					++$found;
				}
			}
		}
	}

	/** Text content of the current inline <script>/<style>; get_modifiable_text() only exists since WordPress 6.5. */
	private static function inline_text( WP_HTML_Tag_Processor $processor ): string {
		return method_exists( $processor, 'get_modifiable_text' ) ? trim( $processor->get_modifiable_text() ) : '';
	}

	private static function first_srcset_url( string $srcset ): string {
		$srcset = trim( $srcset );
		if ( '' === $srcset ) {
			return '';
		}
		$first_candidate = explode( ',', $srcset )[0];
		$parts            = preg_split( '/\s+/', trim( $first_candidate ), -1, PREG_SPLIT_NO_EMPTY );
		return $parts[0] ?? '';
	}

	// ---------------------------------------------------------------------
	// CSP source-list matching (mirrors browser fallback/matching rules)
	// ---------------------------------------------------------------------

	/** Walks the fetch-directive fallback chain to the nearest explicitly enabled directive, or null if none is (unrestricted). */
	private static function effective_config( string $start, array $settings ): ?array {
		$current = $start;
		$seen    = array();
		while ( null !== $current && ! isset( $seen[ $current ] ) ) {
			$seen[ $current ] = true;
			$conf              = $settings['directives'][ $current ] ?? null;
			if ( is_array( $conf ) && ! empty( $conf['enabled'] ) ) {
				return array(
					'value' => (string) ( $conf['value'] ?? '' ),
					'nonce' => ! empty( $conf['nonce'] ) && in_array( $current, Pardesign_Entretien_Csp::NONCE_DIRECTIVES, true ),
				);
			}
			$current = self::FETCH_FALLBACK[ $current ] ?? null;
		}
		return null;
	}

	/** For inline <script>/<style> ELEMENTS: a nonce (ours, already injected) or 'unsafe-inline' both work. */
	private static function element_is_inline_allowed( string $directive, array $settings, bool $has_nonce_attr ): bool {
		$conf = self::effective_config( $directive, $settings );
		if ( null === $conf ) {
			return true;
		}
		if ( $has_nonce_attr && $conf['nonce'] ) {
			return true;
		}
		return self::contains_token( $conf['value'], "'unsafe-inline'" );
	}

	/** For style=/onX= ATTRIBUTES: nonces never apply here per spec, only 'unsafe-inline' (or a hash + 'unsafe-hashes', not modeled). */
	private static function attribute_is_allowed( string $directive, array $settings ): bool {
		$conf = self::effective_config( $directive, $settings );
		if ( null === $conf ) {
			return true;
		}
		return self::contains_token( $conf['value'], "'unsafe-inline'" );
	}

	private static function resource_is_allowed( string $directive, string $resource_url, array $settings, string $home_host ): bool {
		$conf = self::effective_config( $directive, $settings );
		if ( null === $conf ) {
			return true;
		}
		return self::is_origin_allowed( $resource_url, $conf['value'], $home_host );
	}

	private static function contains_token( string $value, string $needle ): bool {
		foreach ( preg_split( '/\s+/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( 0 === strcasecmp( $token, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_origin_allowed( string $resource_url, string $source_list, string $home_host ): bool {
		$tokens = preg_split( '/\s+/', trim( $source_list ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $tokens ) ) {
			return false;
		}

		$parts  = wp_parse_url( $resource_url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		// A path-relative URL ("/x.js") has neither scheme nor host: same-origin.
		// A URL with a scheme but no host (data:, blob:, mailto:…) is NOT the same
		// origin — only an explicit scheme-only token (e.g. "data:") can allow it.
		$is_relative    = ( '' === $scheme && '' === $host );
		$is_same_origin = $is_relative || ( '' !== $host && $host === $home_host );

		// A protocol-relative URL ("//cdn.example.com/x.js") has a host but no scheme:
		// the browser resolves it against the containing page's own scheme.
		if ( '' === $scheme && '' !== $host ) {
			$scheme = strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );
		}

		foreach ( $tokens as $token ) {
			if ( self::token_allows_origin( $token, $scheme, $host, $is_same_origin ) ) {
				return true;
			}
		}
		return false;
	}

	private static function token_allows_origin( string $token, string $scheme, string $host, bool $is_same_origin ): bool {
		if ( '' === $token ) {
			return false;
		}
		$token_lc = strtolower( $token );
		if ( "'none'" === $token_lc ) {
			return false;
		}
		if ( "'self'" === $token_lc ) {
			return $is_same_origin;
		}
		if ( "'" === $token[0] ) {
			return false; // 'unsafe-inline', 'nonce-…', 'sha256-…' etc. — irrelevant to host matching.
		}

		// Scheme-only source, e.g. "data:", "blob:", "https:".
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:$/i', $token ) ) {
			return '' !== $scheme && strtolower( rtrim( $token, ':' ) ) === $scheme;
		}

		if ( '' === $host ) {
			return false; // Relative resource, already resolved via 'self' above.
		}

		$info = self::parse_source_host( $token );
		if ( empty( $info['host'] ) ) {
			return false;
		}
		if ( null !== $info['scheme'] && $info['scheme'] !== $scheme ) {
			return false;
		}
		return self::host_matches( $info['host'], $host );
	}

	private static function parse_source_host( string $token ): array {
		$normalized = ( false === strpos( $token, '//' ) ) ? '//' . $token : $token;
		$parts      = wp_parse_url( $normalized );
		if ( empty( $parts['host'] ) ) {
			return array();
		}
		return array(
			'scheme' => isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : null,
			'host'   => strtolower( $parts['host'] ),
		);
	}

	private static function host_matches( string $pattern_host, string $target_host ): bool {
		if ( 0 === strpos( $pattern_host, '*.' ) ) {
			$suffix = substr( $pattern_host, 1 );
			return strlen( $target_host ) > strlen( $suffix ) && substr( $target_host, -strlen( $suffix ) ) === $suffix;
		}
		return $pattern_host === $target_host;
	}

	/** Nudges WP-Cron to run right away instead of waiting for the next natural pageview-triggered check. */
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
}
