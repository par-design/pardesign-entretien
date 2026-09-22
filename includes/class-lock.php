<?php
/**
 * Site-wide lock around an automatic maintenance run (schedule → run → report).
 *
 * Two runs overlapping on the same site would race on the plugin directories being replaced
 * and produce two database dumps; anyone able to call /run could also pile runs up. The lock
 * is taken atomically with add_option() when a run is scheduled, carried through the cron
 * execution, and released when the report is generated (or by the shutdown safety net). A
 * lock older than MAX_AGE is considered abandoned (process killed, cron never fired) and can
 * be taken over.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pardesign_Entretien_Lock {

	const OPTION = 'pardesign_entretien_lock';

	/** Longest a run may hold the lock before it is considered abandoned. */
	const MAX_AGE = 3 * HOUR_IN_SECONDS;

	/**
	 * Current live lock, or null when free or stale.
	 *
	 * @return array{entretien_id:string,stage:string,started_at:int,updated_at:int}|null
	 */
	public static function current(): ?array {
		$lock = get_option( self::OPTION );
		if ( ! is_array( $lock ) || empty( $lock['entretien_id'] ) ) {
			return null;
		}
		$updated = isset( $lock['updated_at'] ) ? (int) $lock['updated_at'] : 0;
		if ( time() - $updated > self::MAX_AGE ) {
			return null; // stale
		}
		return $lock;
	}

	/**
	 * Take the lock for a run. Atomic: add_option() is a single INSERT that fails when the row
	 * exists. A stale row is replaced first.
	 */
	public static function acquire( string $entretien_id, string $stage = 'scheduled' ): bool {
		$value = array(
			'entretien_id' => $entretien_id,
			'stage'        => $stage,
			'started_at'   => time(),
			'updated_at'   => time(),
		);
		if ( add_option( self::OPTION, $value, '', 'no' ) ) {
			return true;
		}
		$existing = self::current();
		if ( null !== $existing ) {
			return $existing['entretien_id'] === $entretien_id; // re-entrant for the owner
		}
		// Stale or malformed: clear and retry once.
		delete_option( self::OPTION );
		return (bool) add_option( self::OPTION, $value, '', 'no' );
	}

	/** Update the stage / heartbeat of the lock owned by this run. */
	public static function refresh( string $entretien_id, string $stage ): void {
		$lock = get_option( self::OPTION );
		if ( is_array( $lock ) && isset( $lock['entretien_id'] ) && $lock['entretien_id'] === $entretien_id ) {
			$lock['stage']      = $stage;
			$lock['updated_at'] = time();
			update_option( self::OPTION, $lock, false );
		}
	}

	/** Release the lock, only if this run owns it. */
	public static function release( string $entretien_id ): void {
		$lock = get_option( self::OPTION );
		if ( is_array( $lock ) && isset( $lock['entretien_id'] ) && $lock['entretien_id'] === $entretien_id ) {
			delete_option( self::OPTION );
		}
	}
}
