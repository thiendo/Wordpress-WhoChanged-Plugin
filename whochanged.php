<?php
/**
 * Plugin Name: WhoChanged
 * Plugin URI: https://douple.net/whochanged/
 * Description: Track critical admin changes including options, Customizer updates, and plugin lifecycle events.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Douple
 * Author URI: https://douple.net/
 * Text Domain: whochanged
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WHOCHANGED_VERSION', '1.1.0' );
define( 'WHOCHANGED_PLUGIN_FILE', __FILE__ );
define( 'WHOCHANGED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WHOCHANGED_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Freemius as early as possible, per Freemius SDK integration guidelines.
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-pro.php';

require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-database.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-activator.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-event-normalizer.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-mapper.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-diff.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-logger.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-hooks.php';
require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'WhoChanged_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WhoChanged_Deactivator', 'deactivate' ) );

/**
 * Bootstrap plugin.
 *
 * @return void
 */
function whochanged_init() {
	load_plugin_textdomain( 'whochanged', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	WhoChanged_Database::maybe_upgrade_schema();

	// PRO: schedule daily cleanup for retention-based log deletion.
	if ( ! wp_next_scheduled( 'whochanged_pro_cleanup_logs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'whochanged_pro_cleanup_logs' );
	}

	$logger = new WhoChanged_Logger();
	$hooks  = new WhoChanged_Hooks( $logger );
	$admin  = new WhoChanged_Admin( $logger );

	$hooks->register();
	$admin->register();

	// Updates are handled by WordPress.org (for the free/Lite plan) and by the
	// Freemius SDK (for premium plan checkout, license and update delivery) —
	// see includes/class-pro.php. Plugins distributed on WordPress.org must not
	// ship a custom update checker, so no self-hosted updater is registered here.
}
add_action( 'plugins_loaded', 'whochanged_init' );

/**
 * Number of days of activity log kept on the Free plan. Not configurable —
 * PRO unlocks a configurable/unlimited retention window instead.
 */
define( 'WHOCHANGED_FREE_RETENTION_DAYS', 30 );

/**
 * Cleanup old logs based on retention days.
 *
 * Free plan: always enforces a fixed {@see WHOCHANGED_FREE_RETENTION_DAYS}
 * window, regardless of the `whochanged_pro_retention_days` option (that
 * control is PRO-only and disabled in the Settings UI on Free).
 *
 * PRO plan: honors the configured retention window, including "unlimited"
 * (no automatic cleanup).
 *
 * @return void
 */
function whochanged_pro_cleanup_logs_cron() {
	if ( WhoChanged_Pro::is_active() ) {
		$retention = get_option( 'whochanged_pro_retention_days', 'unlimited' );
		if ( 'unlimited' === (string) $retention ) {
			return;
		}

		$days = absint( $retention );
		if ( $days <= 0 ) {
			return;
		}
	} else {
		$days = WHOCHANGED_FREE_RETENTION_DAYS;
	}

	global $wpdb;
	$table      = WhoChanged_Database::table_name();
	$cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	if ( '' === (string) $cutoff_gmt ) {
		return;
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff_gmt ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
}

add_action( 'whochanged_pro_cleanup_logs', 'whochanged_pro_cleanup_logs_cron' );
