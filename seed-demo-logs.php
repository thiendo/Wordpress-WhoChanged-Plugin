<?php
/**
 * Seed WhoChanged demo logs for WordPress Playground.
 *
 * Safe to re-run: truncates the logs table first so Statistics always match
 * the seeded rows. Requires WhoChanged to be active (table created).
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once '/wordpress/wp-load.php';
}

global $wpdb;

if ( ! class_exists( 'WhoChanged_Database' ) ) {
	echo 'WhoChanged not loaded — skip seed.';
	return;
}

WhoChanged_Database::create_table();
$table = WhoChanged_Database::table_name();

// Fresh demo dataset every boot.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM {$table}" );

// Ensure an Editor demo user exists (ID will typically be 2).
$editor = get_user_by( 'login', 'editor' );
if ( ! $editor ) {
	$editor_id = wp_insert_user(
		array(
			'user_login'   => 'editor',
			'user_pass'    => 'password',
			'user_email'   => 'editor@example.com',
			'display_name' => 'Demo Editor',
			'role'         => 'editor',
		)
	);
	$editor = ! is_wp_error( $editor_id ) ? get_user_by( 'id', $editor_id ) : false;
}
$admin_id  = 1;
$editor_id = $editor ? (int) $editor->ID : 2;

update_option( 'whochanged_pro_include_system_logs', 1, false );
update_option( 'whochanged_pro_license_active', 1, false );
update_option(
	'whochanged_pro_settings',
	array(
		'retention_days'      => 'unlimited',
		'include_system_logs' => 1,
		'email_alerts'        => 0,
	),
	false
);

/**
 * Insert one log row.
 *
 * @param int                  $user_id User id (0 = system).
 * @param string               $action  Action type key.
 * @param string               $object_type Object type.
 * @param string               $object_name Object name.
 * @param string               $label   Display label.
 * @param array<string,mixed>  $diff    Before/after diff.
 * @param array<string,mixed>  $meta    Meta payload.
 * @param string               $created_at GMT datetime Y-m-d H:i:s.
 * @return void
 */
$insert = static function ( $user_id, $action, $object_type, $object_name, $label, array $diff, array $meta, $created_at ) use ( $wpdb, $table ) {
	$event = array(
		'type'        => $action,
		'label'       => $label,
		'meta'        => $meta,
		'diff'        => $diff,
		'action_type' => $action,
		'object_type' => $object_type,
		'object_name' => $object_name,
	);

	$wpdb->insert(
		$table,
		array(
			'user_id'     => (int) $user_id,
			'group_id'    => '',
			'type'        => $action,
			'label'       => $label,
			'meta'        => wp_json_encode( $meta ),
			'action_type' => $action,
			'object_type' => $object_type,
			'object_name' => $object_name,
			'changes'     => wp_json_encode( $event ),
			'created_at'  => $created_at,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
};

/**
 * GMT timestamp N days ago at a given hour/minute.
 *
 * @param int $days_ago Days ago.
 * @param int $hour     Hour 0-23.
 * @param int $minute   Minute.
 * @return string
 */
$at = static function ( $days_ago, $hour, $minute = 0 ) {
	$ts = time() - ( (int) $days_ago * DAY_IN_SECONDS );
	return gmdate( 'Y-m-d', $ts ) . sprintf( ' %02d:%02d:00', $hour, $minute );
};

// ——— Admin activity (user_id = 1) ———
$admin_rows = array(
	array( 0, 9, 12, 'user_login', 'user', 'admin', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 0, 9, 20, 'option_updated', 'option', 'blogname', 'Setting updated', array( 'blogname' => array( 'before' => 'My Site', 'after' => 'WhoChanged Demo Store' ) ), array( 'option' => 'blogname' ) ),
	array( 0, 10, 5, 'option_updated', 'option', 'blogdescription', 'Setting updated', array( 'blogdescription' => array( 'before' => 'Just another WordPress site', 'after' => 'Activity log demo' ) ), array( 'option' => 'blogdescription' ) ),
	array( 0, 11, 0, 'activated_plugin', 'plugin', 'WooCommerce', 'Plugin activated', array( 'network_wide' => array( 'before' => null, 'after' => false ) ), array( 'name' => 'WooCommerce', 'plugin_file' => 'woocommerce/woocommerce.php' ) ),
	array( 0, 11, 30, 'menu_updated', 'menu', 'Primary', 'Menu updated', array( 'items' => array( 'before' => 3, 'after' => 5 ) ), array( 'menu' => 'Primary' ) ),
	array( 1, 14, 10, 'customize_save_after', 'theme', 'Customizer', 'Customizer saved', array( 'blogname' => array( 'before' => 'WhoChanged Demo Store', 'after' => 'WhoChanged Demo' ) ), array() ),
	array( 1, 15, 0, 'product_trashed', 'product', 'Blue Cap', 'Product moved to trash', array( 'status' => array( 'before' => 'publish', 'after' => 'trash' ) ), array( 'post_type' => 'product' ) ),
	array( 1, 15, 20, 'product_restored', 'product', 'Blue Cap', 'Product restored', array( 'status' => array( 'before' => 'trash', 'after' => 'publish' ) ), array( 'post_type' => 'product' ) ),
	array( 2, 9, 40, 'option_updated', 'option', 'woocommerce_currency', 'Setting updated', array( 'woocommerce_currency' => array( 'before' => 'GBP', 'after' => 'USD' ) ), array( 'option' => 'woocommerce_currency' ) ),
	array( 2, 10, 15, 'page_trashed', 'page', 'Shipping Policy', 'Page moved to trash', array( 'status' => array( 'before' => 'publish', 'after' => 'trash' ) ), array( 'post_type' => 'page' ) ),
	array( 2, 16, 0, 'installed_plugin', 'plugin', 'WhoChanged', 'Plugin installed', array( 'version' => array( 'before' => null, 'after' => '1.1.0' ) ), array( 'name' => 'WhoChanged' ) ),
	array( 3, 11, 0, 'deactivated_plugin', 'plugin', 'Hello Dolly', 'Plugin deactivated', array( 'network_wide' => array( 'before' => false, 'after' => null ) ), array( 'name' => 'Hello Dolly' ) ),
	array( 3, 11, 10, 'deleted_plugin', 'plugin', 'Hello Dolly', 'Plugin deleted', array( 'value' => array( 'before' => 'hello.php', 'after' => null ) ), array( 'name' => 'Hello Dolly' ) ),
	array( 3, 13, 45, 'menu_created', 'menu', 'Footer Links', 'Menu created', array( 'name' => array( 'before' => null, 'after' => 'Footer Links' ) ), array() ),
	array( 4, 8, 30, 'user_login', 'user', 'admin', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 4, 9, 0, 'option_updated', 'option', 'users_can_register', 'Setting updated', array( 'users_can_register' => array( 'before' => '0', 'after' => '1' ) ), array( 'option' => 'users_can_register' ) ),
	array( 4, 12, 20, 'order_trashed', 'shop_order', 'Order #1042', 'Order moved to trash', array( 'status' => array( 'before' => 'wc-processing', 'after' => 'trash' ) ), array( 'post_type' => 'shop_order' ) ),
	array( 5, 10, 0, 'theme_changed', 'theme', 'Twenty Twenty-Five', 'Theme changed', array( 'stylesheet' => array( 'before' => 'twentytwentyfour', 'after' => 'twentytwentyfive' ) ), array() ),
	array( 5, 14, 30, 'option_updated', 'option', 'permalink_structure', 'Setting updated', array( 'permalink_structure' => array( 'before' => '', 'after' => '/%postname%/' ) ), array( 'option' => 'permalink_structure' ) ),
	array( 6, 9, 15, 'activated_plugin', 'plugin', 'WhoChanged', 'Plugin activated', array( 'network_wide' => array( 'before' => null, 'after' => false ) ), array( 'name' => 'WhoChanged' ) ),
	array( 6, 11, 40, 'product_deleted', 'product', 'Sample SKU-9', 'Product deleted', array( 'status' => array( 'before' => 'trash', 'after' => null ) ), array( 'post_type' => 'product' ) ),
	array( 7, 15, 10, 'option_updated', 'option', 'timezone_string', 'Setting updated', array( 'timezone_string' => array( 'before' => '', 'after' => 'America/New_York' ) ), array( 'option' => 'timezone_string' ) ),
	array( 8, 10, 5, 'menu_updated', 'menu', 'Primary', 'Menu updated', array( 'items' => array( 'before' => 5, 'after' => 6 ) ), array( 'menu' => 'Primary' ) ),
	array( 9, 13, 0, 'page_restored', 'page', 'Shipping Policy', 'Page restored', array( 'status' => array( 'before' => 'trash', 'after' => 'publish' ) ), array( 'post_type' => 'page' ) ),
	array( 10, 9, 50, 'user_login', 'user', 'admin', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 10, 16, 20, 'option_updated', 'option', 'woocommerce_store_address', 'Setting updated', array( 'woocommerce_store_address' => array( 'before' => '', 'after' => '123 Demo Street' ) ), array( 'option' => 'woocommerce_store_address' ) ),
	array( 11, 11, 25, 'customize_save_after', 'theme', 'Customizer', 'Customizer saved', array( 'custom_logo' => array( 'before' => null, 'after' => 42 ) ), array() ),
	array( 12, 14, 0, 'comment_deleted', 'comment', 'Spam on Hello world', 'Comment deleted', array( 'status' => array( 'before' => 'spam', 'after' => null ) ), array() ),
	array( 13, 10, 10, 'option_updated', 'option', 'default_comment_status', 'Setting updated', array( 'default_comment_status' => array( 'before' => 'open', 'after' => 'closed' ) ), array( 'option' => 'default_comment_status' ) ),
	array( 13, 17, 40, 'user_logout', 'user', 'admin', 'Logged out', array( 'value' => array( 'before' => 'session', 'after' => null ) ), array() ),
);

foreach ( $admin_rows as $row ) {
	$insert( $admin_id, $row[3], $row[4], $row[5], $row[6], $row[7], $row[8], $at( $row[0], $row[1], $row[2] ) );
}

// ——— Editor activity ———
$editor_rows = array(
	array( 0, 10, 40, 'user_login', 'user', 'editor', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 0, 11, 10, 'post_trashed', 'post', 'Summer sale draft', 'Post moved to trash', array( 'status' => array( 'before' => 'draft', 'after' => 'trash' ) ), array( 'post_type' => 'post' ) ),
	array( 1, 9, 30, 'post_restored', 'post', 'Summer sale draft', 'Post restored', array( 'status' => array( 'before' => 'trash', 'after' => 'draft' ) ), array( 'post_type' => 'post' ) ),
	array( 1, 12, 0, 'comment_trashed', 'comment', 'Nice product!', 'Comment moved to trash', array( 'status' => array( 'before' => '1', 'after' => 'trash' ) ), array() ),
	array( 2, 14, 20, 'page_trashed', 'page', 'About us', 'Page moved to trash', array( 'status' => array( 'before' => 'publish', 'after' => 'trash' ) ), array( 'post_type' => 'page' ) ),
	array( 2, 14, 50, 'page_restored', 'page', 'About us', 'Page restored', array( 'status' => array( 'before' => 'trash', 'after' => 'publish' ) ), array( 'post_type' => 'page' ) ),
	array( 3, 10, 0, 'user_login', 'user', 'editor', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 3, 15, 30, 'post_trashed', 'post', 'Old promo', 'Post moved to trash', array( 'status' => array( 'before' => 'publish', 'after' => 'trash' ) ), array( 'post_type' => 'post' ) ),
	array( 4, 11, 45, 'comment_restored', 'comment', 'Nice product!', 'Comment restored', array( 'status' => array( 'before' => 'trash', 'after' => '1' ) ), array() ),
	array( 5, 13, 15, 'post_deleted', 'post', 'Old promo', 'Post deleted', array( 'status' => array( 'before' => 'trash', 'after' => null ) ), array( 'post_type' => 'post' ) ),
	array( 6, 9, 5, 'user_login', 'user', 'editor', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 7, 16, 10, 'comment_trashed', 'comment', 'Off-topic reply', 'Comment moved to trash', array( 'status' => array( 'before' => '1', 'after' => 'trash' ) ), array() ),
	array( 8, 12, 30, 'page_trashed', 'page', 'Careers', 'Page moved to trash', array( 'status' => array( 'before' => 'draft', 'after' => 'trash' ) ), array( 'post_type' => 'page' ) ),
	array( 9, 10, 20, 'post_trashed', 'post', 'Behind the scenes', 'Post moved to trash', array( 'status' => array( 'before' => 'publish', 'after' => 'trash' ) ), array( 'post_type' => 'post' ) ),
	array( 9, 10, 55, 'post_restored', 'post', 'Behind the scenes', 'Post restored', array( 'status' => array( 'before' => 'trash', 'after' => 'publish' ) ), array( 'post_type' => 'post' ) ),
	array( 11, 15, 0, 'user_login', 'user', 'editor', 'Logged in', array( 'value' => array( 'before' => null, 'after' => 'success' ) ), array() ),
	array( 12, 9, 40, 'comment_deleted', 'comment', 'Off-topic reply', 'Comment deleted', array( 'status' => array( 'before' => 'trash', 'after' => null ) ), array() ),
	array( 13, 11, 5, 'user_logout', 'user', 'editor', 'Logged out', array( 'value' => array( 'before' => 'session', 'after' => null ) ), array() ),
);

foreach ( $editor_rows as $row ) {
	$insert( $editor_id, $row[3], $row[4], $row[5], $row[6], $row[7], $row[8], $at( $row[0], $row[1], $row[2] ) );
}

// ——— System activity (user_id = 0) ———
$system_rows = array(
	array( 0, 3, 10, 'login_failed', 'user', 'unknown', 'Login failed', array( 'username' => array( 'before' => null, 'after' => 'hacker' ) ), array() ),
	array( 1, 4, 0, 'plugin_upgraded', 'plugin', 'WooCommerce', 'Plugin upgraded', array( 'version' => array( 'before' => '9.0.0', 'after' => '9.1.0' ) ), array( 'name' => 'WooCommerce' ) ),
	array( 2, 3, 30, 'login_failed', 'user', 'unknown', 'Login failed', array( 'username' => array( 'before' => null, 'after' => 'admin' ) ), array() ),
	array( 3, 2, 15, 'theme_upgraded', 'theme', 'Twenty Twenty-Five', 'Theme upgraded', array( 'version' => array( 'before' => '1.0', 'after' => '1.1' ) ), array() ),
	array( 5, 3, 45, 'wordpress_updated', 'core', 'WordPress', 'WordPress updated', array( 'version' => array( 'before' => '6.7', 'after' => '6.8' ) ), array() ),
	array( 6, 4, 20, 'login_failed', 'user', 'unknown', 'Login failed', array( 'username' => array( 'before' => null, 'after' => 'demo' ) ), array() ),
	array( 7, 5, 0, 'plugin_upgraded', 'plugin', 'WhoChanged', 'Plugin upgraded', array( 'version' => array( 'before' => '1.0.0', 'after' => '1.1.0' ) ), array( 'name' => 'WhoChanged' ) ),
	array( 8, 2, 50, 'login_failed', 'user', 'unknown', 'Login failed', array( 'username' => array( 'before' => null, 'after' => 'root' ) ), array() ),
	array( 10, 3, 5, 'theme_upgraded', 'theme', 'Twenty Twenty-Five', 'Theme upgraded', array( 'version' => array( 'before' => '1.1', 'after' => '1.2' ) ), array() ),
	array( 11, 4, 40, 'login_failed', 'user', 'unknown', 'Login failed', array( 'username' => array( 'before' => null, 'after' => 'test' ) ), array() ),
	array( 12, 1, 20, 'plugin_upgraded', 'plugin', 'WooCommerce', 'Plugin upgraded', array( 'version' => array( 'before' => '9.1.0', 'after' => '9.2.0' ) ), array( 'name' => 'WooCommerce' ) ),
	array( 13, 2, 10, 'login_failed', 'user', 'unknown', 'Login failed', array( 'username' => array( 'before' => null, 'after' => 'wp' ) ), array() ),
);

foreach ( $system_rows as $row ) {
	$insert( 0, $row[3], $row[4], $row[5], $row[6], $row[7], $row[8], $at( $row[0], $row[1], $row[2] ) );
}

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id > 0" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$system = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id = 0" );

echo "WhoChanged demo seed OK — total={$total} user={$users} system={$system}";
