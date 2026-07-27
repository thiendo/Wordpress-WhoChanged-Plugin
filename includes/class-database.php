<?php
/**
 * Database layer.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database concerns for logs table.
 */
class WhoChanged_Database {

	/**
	 * Table name without prefix.
	 */
	const TABLE_SLUG     = 'whochanged_logs';
	const SCHEMA_VERSION = '1.2.0';
	const SCHEMA_OPTION  = 'whochanged_schema_version';

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SLUG;
	}

	/**
	 * Create log table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			group_id VARCHAR(50) NOT NULL DEFAULT '',
			type VARCHAR(50) NOT NULL DEFAULT '',
			label VARCHAR(191) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			action_type VARCHAR(50) NOT NULL,
			object_type VARCHAR(50) NOT NULL,
			object_name VARCHAR(191) NOT NULL,
			changes LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY user_id (user_id),
			KEY action_type (action_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Ensure schema is upgraded for existing installs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_schema() {
		$stored_version = get_option( self::SCHEMA_OPTION, '' );

		if ( self::SCHEMA_VERSION === $stored_version ) {
			return;
		}

		self::create_table();
	}
}
