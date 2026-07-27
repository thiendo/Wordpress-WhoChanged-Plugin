<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is deleted from wp-admin (never on deactivate).
 * By default no data is removed so re-installing the plugin keeps history;
 * site owners can opt in to a full wipe via the "Delete all data on
 * uninstall" checkbox in WhoChanged → Settings → Danger Zone.
 *
 * @package WhoChanged
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'get_option' ) ) {
	exit;
}

$whochanged_delete_all_data = (int) get_option( 'whochanged_delete_data_on_uninstall', 0 );

wp_clear_scheduled_hook( 'whochanged_pro_cleanup_logs' );

if ( 1 !== $whochanged_delete_all_data ) {
	return;
}

global $wpdb;

$whochanged_table = $wpdb->prefix . 'whochanged_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$whochanged_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.

$whochanged_options = array(
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

foreach ( $whochanged_options as $whochanged_option_name ) {
	delete_option( $whochanged_option_name );
	delete_site_option( $whochanged_option_name );
}
