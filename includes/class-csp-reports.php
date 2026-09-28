<?php
/**
 * Capture of CSP violation reports, scoped to short manual "capture" windows so the
 * collector REST endpoint isn't a standing target exposed to normal site traffic.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Csp_Reports {

	const NAMESPACE      = 'pardesign-entretien/v1';
	const ROUTE          = '/csp-report';
	const OPTION_REPORTS = 'pardesign_entretien_csp_reports';
	const TRANSIENT      = 'pardesign_entretien_csp_capture';
	const QUERY_ARG      = 'pardesign_csp_capture';
	const CAPTURE_TTL    = 10 * MINUTE_IN_SECONDS;
	const MAX_REPORTS    = 200;

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_report' ),
				// Public on purpose: browsers POST CSP reports with no auth of any kind.
				// The token query arg is what gates acceptance, checked in handle_report().
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
	}

	/** Opens (or renews) a 10-minute capture window and returns its token. */
	public static function start_capture(): string {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::TRANSIENT, $token, self::CAPTURE_TTL );
		return $token;
	}

	/** Token of the currently open capture window, or '' if none is active. */
	public static function active_token(): string {
		$token = get_transient( self::TRANSIENT );
		return is_string( $token ) && '' !== $token ? $token : '';
	}

	public static function is_capturing(): bool {
		return '' !== self::active_token();
	}

	/** Whether the current front-end request carries the active capture token. */
	public static function is_capturing_this_request(): bool {
		$token = self::active_token();
		if ( '' === $token || ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return hash_equals( $token, sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** URL to hand to the CSP `report-uri` directive for a given capture token. */
	public static function report_endpoint( string $token ): string {
		return add_query_arg( 'token', $token, rest_url( self::NAMESPACE . self::ROUTE ) );
	}

	public function handle_report( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token || ! hash_equals( self::active_token(), $token ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( null, 400 );
		}

		// Two possible shapes: the legacy `{"csp-report": {...}}` (report-uri) or the
		// newer Reporting API array of reports (`application/reports+json`).
		$items = array();
		if ( isset( $body['csp-report'] ) && is_array( $body['csp-report'] ) ) {
			$items[] = $body['csp-report'];
		} elseif ( wp_is_numeric_array( $body ) ) {
			foreach ( $body as $entry ) {
				if ( is_array( $entry ) && isset( $entry['body'] ) && is_array( $entry['body'] ) ) {
					$items[] = $entry['body'];
				}
			}
		}

		foreach ( $items as $item ) {
			self::store_report( $item );
		}

		return new WP_REST_Response( null, 204 );
	}

	private static function store_report( array $item ): void {
		self::add_row(
			array(
				'time'        => time(),
				'document'    => (string) ( $item['document-uri'] ?? $item['documentURL'] ?? '' ),
				'blocked'     => (string) ( $item['blocked-uri'] ?? $item['blockedURL'] ?? '' ),
				'directive'   => (string) ( $item['effective-directive'] ?? $item['violated-directive'] ?? $item['effectiveDirective'] ?? '' ),
				'disposition' => (string) ( $item['disposition'] ?? '' ),
				'source'      => (string) ( $item['source-file'] ?? $item['sourceFile'] ?? '' ),
				'line'        => (string) ( $item['line-number'] ?? $item['lineNumber'] ?? '' ),
				'sample'      => self::sanitize_sample( (string) ( $item['script-sample'] ?? $item['sample'] ?? '' ) ),
				'method'      => 'report',
			)
		);
	}

	/**
	 * Records a finding from the static-HTML scanner (Pardesign_Entretien_Csp_Scanner),
	 * distinct from a real browser-submitted violation report: it's a simulation against
	 * the saved policy, not confirmed by an actual browser enforcing it. $sample here is
	 * the actual offending markup we parsed — always run through sanitize_sample() and,
	 * like report-sample, must always be esc_html()'d wherever it's displayed.
	 */
	public static function record_scan_finding( string $document, string $directive, string $blocked, string $sample = '' ): void {
		self::add_row(
			array(
				'time'        => time(),
				'document'    => $document,
				'blocked'     => $blocked,
				'directive'   => $directive,
				'disposition' => '',
				'source'      => '',
				'line'        => '',
				'sample'      => self::sanitize_sample( $sample ),
				'method'      => 'scan',
			)
		);
	}

	private static function add_row( array $row ): void {
		$reports = self::get_reports();
		array_unshift( $reports, $row );
		update_option( self::OPTION_REPORTS, array_slice( $reports, 0, self::MAX_REPORTS ), false );
	}

	/**
	 * The sample is a snippet of the actual blocked inline code — verbatim attacker input
	 * if the violation is a real XSS attempt. Only bound its size and strip control
	 * characters here; do NOT strip tags or otherwise "clean" it, that would hide the very
	 * payload this is meant to surface. It MUST always be run through esc_html() wherever
	 * it's displayed — never echoed raw, never used in wp_kses_post()/similar.
	 */
	private static function sanitize_sample( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		return mb_substr( trim( (string) $value ), 0, 200 );
	}

	public static function get_reports(): array {
		$reports = get_option( self::OPTION_REPORTS, array() );
		return is_array( $reports ) ? $reports : array();
	}

	public static function clear_reports(): void {
		delete_option( self::OPTION_REPORTS );
	}

	/** Reports grouped by (directive, blocked resource), most frequent first. */
	public static function grouped(): array {
		$groups = array();

		foreach ( self::get_reports() as $report ) {
			$key = $report['directive'] . '|' . $report['blocked'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'directive' => $report['directive'],
					'blocked'   => $report['blocked'],
					'source'    => $report['source'],
					'line'      => $report['line'],
					'sample'    => '',
					'count'     => 0,
					'last_seen' => 0,
					'documents' => array(),
					'methods'   => array(),
				);
			}

			++$groups[ $key ]['count'];
			$groups[ $key ]['last_seen'] = max( $groups[ $key ]['last_seen'], (int) $report['time'] );

			$method = ! empty( $report['method'] ) ? $report['method'] : 'report';
			if ( ! in_array( $method, $groups[ $key ]['methods'], true ) ) {
				$groups[ $key ]['methods'][] = $method;
			}

			if ( ! empty( $report['sample'] ) ) {
				$groups[ $key ]['sample'] = $report['sample'];
			}

			if ( $report['document'] && count( $groups[ $key ]['documents'] ) < 3 && ! in_array( $report['document'], $groups[ $key ]['documents'], true ) ) {
				$groups[ $key ]['documents'][] = $report['document'];
			}
		}

		$groups = array_values( $groups );
		usort(
			$groups,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return $groups;
	}
}
