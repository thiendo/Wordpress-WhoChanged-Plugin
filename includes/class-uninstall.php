<?php
/**
 * Uninstall cleanup.
 *
 * Hooked via Freemius `after_uninstall` (required for Freemius deployments).
 * Falls back to register_uninstall_hook() when the SDK is not configured.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permanent data removal on plugin delete (opt-in).
 */
class WhoChanged_Uninstall {

	/**
	 * Run uninstall cleanup.
	 *
	 * @return void
	 */
	public static function run() {
		wp_clear_scheduled_hook( 'whochanged_pro_cleanup_logs' );

		if ( 1 !== (int) get_option( 'whochanged_delete_data_on_uninstall', 0 ) ) {
			return;
		}

		global $wpdb;

		$table_name = esc_sql( WhoChanged_Database::table_name() );
		// Custom table drop on uninstall (opt-in). Table slug is plugin-owned, not user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

		$option_names = array(
			'whochanged_schema_version',
			'whochanged_delete_data_on_uninstall',
			'whochanged_pro_retention_days',
			'whochanged_pro_email_enabled',
			'whochanged_pro_email_events',
			'whochanged_pro_email_recipient',
			'whochanged_pro_license_key',
			'whochanged_pro_license_active',
			'whochanged_pro_license_activated_at',
			'whochanged_pro_allowed_roles',
			'whochanged_pro_viewer_roles',
			'whochanged_pro_include_system_logs',
		);

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
			delete_site_option( $option_name );
		}
	}
}
