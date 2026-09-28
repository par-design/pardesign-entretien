<?php
/**
 * CSP (Content-Security-Policy) engine: settings, header building,
 * per-request nonce, and nonce injection into inline <script>/<style> tags.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Csp {

	const OPTION = 'pardesign_entretien_csp_settings';

	/** @var string|null Nonce generated for the current request (shared between the header and the injected tags). */
	private static $nonce = null;

	/** "Fetch" directives that take a source list. */
	const VALUE_DIRECTIVES = array(
		'default-src',
		'script-src',
		'script-src-attr',
		'style-src',
		'style-src-attr',
		'img-src',
		'font-src',
		'connect-src',
		'frame-src',
		'frame-ancestors',
		'object-src',
		'base-uri',
		'form-action',
		'media-src',
		'worker-src',
		'manifest-src',
	);

	/** Boolean directives: presence-only in the header, no value. */
	const FLAG_DIRECTIVES = array(
		'upgrade-insecure-requests',
		'block-all-mixed-content',
	);

	/** Directives that can have a per-request nonce added. */
	const NONCE_DIRECTIVES = array( 'script-src', 'style-src' );

	public function hooks(): void {
		add_action( 'send_headers', array( $this, 'send_header' ) );
		// 'send_headers' only fires for front-end requests (WP::send_headers()); wp-admin
		// never reaches it, so the "apply in wp-admin" option needs its own hook. Priority 20:
		// core's send_frame_options_header() (admin_init, 10, registered after plugins load)
		// sends "Content-Security-Policy: frame-ancestors 'self'" with replace=true and would
		// otherwise wipe ours in enforcing mode.
		add_action( 'admin_init', array( $this, 'send_header' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_start_buffer' ), 0 );
		add_action( 'admin_init', array( $this, 'maybe_start_buffer' ), 0 );
	}

	/** Unique nonce for the current request (random, not tied to the user or the time). */
	public static function nonce(): string {
		if ( null === self::$nonce ) {
			self::$nonce = base64_encode( random_bytes( 16 ) );
		}
		return self::$nonce;
	}

	public static function default_settings(): array {
		$directives = array();
		foreach ( self::VALUE_DIRECTIVES as $name ) {
			$directives[ $name ] = array(
				'enabled' => false,
				'value'   => "'self'",
				'nonce'   => false,
			);
		}
		foreach ( self::FLAG_DIRECTIVES as $name ) {
			$directives[ $name ] = array( 'enabled' => false );
		}

		// A reasonable starting point, disabled by default (requires explicit activation).
		$directives['default-src']['enabled'] = true;
		$directives['script-src']['enabled']  = true;
		$directives['script-src']['nonce']    = true;
		$directives['style-src']['enabled']   = true;
		$directives['style-src']['nonce']     = true;
		$directives['img-src']['enabled']     = true;
		$directives['img-src']['value']       = "'self' data:";
		$directives['object-src']['enabled']  = true;
		$directives['object-src']['value']    = "'none'";
		$directives['base-uri']['enabled']    = true;

		// Attribute-level (style="…", onclick="…") directives can't be covered by a nonce
		// or by script-src/style-src at all — they only ever fall back to 'unsafe-inline'
		// or per-value hashes with 'unsafe-hashes'. Off by default: enabling either one is
		// a deliberate trade-off the admin makes, not something to switch on silently.
		$directives['script-src-attr']['value'] = "'unsafe-inline'";
		$directives['style-src-attr']['value']  = "'unsafe-inline'";

		return array(
			'enabled'     => false,
			'report_only' => true,
			'apply_admin' => false,
			'report_uri'  => '',
			'directives'  => $directives,
		);
	}

	public static function get_settings(): array {
		$saved    = get_option( self::OPTION, array() );
		$defaults = self::default_settings();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings              = array_merge( $defaults, $saved );
		$settings['directives'] = array_replace_recursive(
			$defaults['directives'],
			isset( $saved['directives'] ) && is_array( $saved['directives'] ) ? $saved['directives'] : array()
		);

		return $settings;
	}

	public static function update_settings( array $settings ): void {
		update_option( self::OPTION, $settings );
	}

	/**
	 * Builds the CSP header value from the settings. Empty string if no directive is active.
	 *
	 * @param array $settings     Settings from get_settings().
	 * @param array $extra_tokens Optional map of directive name => extra source token to append
	 *                            (used to add 'report-sample' only for the request being captured,
	 *                            without it leaking into the normal header or the admin preview).
	 */
	public static function build_header_value( array $settings, array $extra_tokens = array() ): string {
		$parts = array();

		foreach ( self::VALUE_DIRECTIVES as $name ) {
			$conf = isset( $settings['directives'][ $name ] ) ? $settings['directives'][ $name ] : array();
			if ( empty( $conf['enabled'] ) ) {
				continue;
			}

			$value = self::sanitize_token( isset( $conf['value'] ) ? $conf['value'] : '' );
			if ( in_array( $name, self::NONCE_DIRECTIVES, true ) && ! empty( $conf['nonce'] ) ) {
				$value = trim( $value . " 'nonce-" . self::nonce() . "'" );
			}
			if ( ! empty( $extra_tokens[ $name ] ) ) {
				$value = trim( $value . ' ' . self::sanitize_token( $extra_tokens[ $name ] ) );
			}

			if ( '' === $value ) {
				continue;
			}

			$parts[] = $name . ' ' . $value;
		}

		foreach ( self::FLAG_DIRECTIVES as $name ) {
			$conf = isset( $settings['directives'][ $name ] ) ? $settings['directives'][ $name ] : array();
			if ( ! empty( $conf['enabled'] ) ) {
				$parts[] = $name;
			}
		}

		if ( ! empty( $settings['report_uri'] ) ) {
			$parts[] = 'report-uri ' . self::sanitize_token( $settings['report_uri'] );
		}

		return implode( '; ', $parts );
	}

	/** Prevents header injection via a newline entered in a field. */
	private static function sanitize_token( string $value ): string {
		return trim( str_replace( array( "\r", "\n" ), ' ', $value ) );
	}

	public function send_header(): void {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( is_admin() && empty( $settings['apply_admin'] ) ) {
			return;
		}

		// Only the specific request carrying a valid, live capture token gets a
		// report-uri (and 'report-sample' on script-src/style-src) pointing at our
		// collector — regular traffic never does, and the admin preview never shows it.
		$is_capturing = Pardesign_Entretien_Csp_Reports::is_capturing_this_request();
		$extra_tokens = $is_capturing ? array(
			'script-src' => "'report-sample'",
			'style-src'  => "'report-sample'",
		) : array();

		$value = self::build_header_value( $settings, $extra_tokens );

		if ( $is_capturing ) {
			$endpoint = self::sanitize_token( Pardesign_Entretien_Csp_Reports::report_endpoint( Pardesign_Entretien_Csp_Reports::active_token() ) );
			$value    = trim( trim( $value, '; ' ) . '; report-uri ' . $endpoint, '; ' );
		}

		if ( '' === $value ) {
			return;
		}

		$header = ! empty( $settings['report_only'] ) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
		// Append rather than replace: browsers enforce every CSP header they receive, and
		// core already sends "frame-ancestors 'self'" in wp-admin, which must survive.
		header( $header . ': ' . $value, false );
	}

	/**
	 * Starts output buffering to inject the nonce into existing inline
	 * <script>/<style> tags (theme, other plugins, Gutenberg blocks…).
	 * Only activates if a nonce is actually required by the settings.
	 */
	public function maybe_start_buffer(): void {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( is_admin() && empty( $settings['apply_admin'] ) ) {
			return;
		}

		$needs_nonce = false;
		foreach ( self::NONCE_DIRECTIVES as $name ) {
			if ( ! empty( $settings['directives'][ $name ]['enabled'] ) && ! empty( $settings['directives'][ $name ]['nonce'] ) ) {
				$needs_nonce = true;
				break;
			}
		}
		if ( ! $needs_nonce ) {
			return;
		}

		ob_start( array( $this, 'inject_nonces' ) );
	}

	/** Output buffer callback: adds the nonce attribute to inline <script> and <style> tags. */
	public function inject_nonces( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		$nonce     = self::nonce();

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'SCRIPT' === $tag && null === $processor->get_attribute( 'src' ) && null === $processor->get_attribute( 'nonce' ) ) {
				$processor->set_attribute( 'nonce', $nonce );
			} elseif ( 'STYLE' === $tag && null === $processor->get_attribute( 'nonce' ) ) {
				$processor->set_attribute( 'nonce', $nonce );
			}
		}

		return $processor->get_updated_html();
	}
}
