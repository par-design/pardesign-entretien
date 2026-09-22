<?php
/**
 * Uninstall: remove everything the plugin stored on this site.
 *
 * Runs when the plugin is deleted from the Plugins screen or with `wp plugin uninstall`
 * (not on deactivation). Deletes every database dump and the private backup directory,
 * the plugin options (including the API key, unless it lives in wp-config.php), the update
 * cache, and the scheduled events. Constants defined in wp-config.php are untouched.
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-backup.php';

// Dumps and backup directories (private one and the legacy fixed-name one).
Pardesign_Entretien_Backup::delete_all();

// Scheduled events (hook names duplicated here: the REST class is not loaded during uninstall).
wp_unschedule_hook( 'pardesign_entretien_poll' );
wp_unschedule_hook( 'pardesign_entretien_run_async' ); // all pending runs, whatever their arguments

// Options and caches.
delete_option( 'pardesign_entretien_settings' );
delete_site_option( 'pardesign_entretien_settings' ); // multisite: network option
delete_option( 'pardesign_entretien_current' );
delete_option( 'pardesign_entretien_update_error' );
delete_option( 'pardesign_entretien_lock' );
delete_transient( 'pardesign_entretien_update_meta_v2' );

// Remembered request nonces (one option each) and any per-user admin notices.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'pardesign_entretien_nonce_' ) . '%',
		$wpdb->esc_like( '_transient_pardesign_entretien_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_pardesign_entretien_notice_' ) . '%'
	)
);
