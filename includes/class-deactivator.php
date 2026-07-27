<?php
/**
 * Deactivator.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs deactivation logic.
 */
class WhoChanged_Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * Data (logs table, options) is intentionally kept so re-activating the
	 * plugin does not lose history; see WhoChanged_Uninstall for permanent removal.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'whochanged_pro_cleanup_logs' );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'whochanged_pro_cleanup_logs' );
		}

		wp_clear_scheduled_hook( 'whochanged_pro_cleanup_logs' );
	}
}
