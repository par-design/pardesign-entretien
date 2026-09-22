<?php
/**
 * Plugin Name:       PAR Design — Entretien
 * Plugin URI:        https://pardesign.net
 * Description:        Capture l'état des versions (coeur WordPress + plugins) avant et après un entretien, puis déclenche l'envoi du rapport client via le backend PAR Design.
 * Version:           0.9.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            PAR Design
 * Author URI:        https://pardesign.net
 * Update URI:        https://pardesign.net/entretien
 * Text Domain:       pardesign-entretien
 * Domain Path:       /languages
 *
 * @package PardesignEntretien
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PARDESIGN_ENTRETIEN_VERSION', '0.9.0' );
define( 'PARDESIGN_ENTRETIEN_FILE', __FILE__ );
define( 'PARDESIGN_ENTRETIEN_DIR', plugin_dir_path( __FILE__ ) );

require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-settings.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-snapshot.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-server-audit.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-backup.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-api-client.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-lock.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-inbound-auth.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-entretien.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-rest.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-admin-ui.php';
require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-updater.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PARDESIGN_ENTRETIEN_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'pardesign entretien', 'Pardesign_Entretien_CLI' );
}

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'pardesign-entretien', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		( new Pardesign_Entretien_Admin_UI() )->hooks();
		( new Pardesign_Entretien_Rest() )->hooks();
		( new Pardesign_Entretien_Updater() )->hooks();
	}
);

// Nettoyage à la désactivation : polling « pull » et entretiens programmés (sinon
// événements cron orphelins), et verrou d'exécution.
register_deactivation_hook(
	__FILE__,
	static function () {
		// wp_unschedule_hook() drops every event of the hook whatever its arguments;
		// wp_clear_scheduled_hook() would only match events scheduled without arguments.
		wp_unschedule_hook( Pardesign_Entretien_Rest::POLL_HOOK );
		wp_unschedule_hook( Pardesign_Entretien_Rest::ASYNC_HOOK );
		delete_option( Pardesign_Entretien_Lock::OPTION );
	}
);
