<?php
/**
 * Register uninstall cleanup with Freemius (or WP fallback).
 *
 * Freemius deployments must not ship uninstall.php — use after_uninstall instead.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'whochanged_register_uninstall_handler' ) ) {
	/**
	 * @return void
	 */
	function whochanged_register_uninstall_handler() {
		$fs = whochanged_fs();
		if ( is_object( $fs ) && method_exists( $fs, 'add_action' ) ) {
			$fs->add_action( 'after_uninstall', array( 'WhoChanged_Uninstall', 'run' ) );
			return;
		}

		register_uninstall_hook( WHOCHANGED_PLUGIN_FILE, 'whochanged_run_uninstall_cleanup' );
	}

	/**
	 * @return void
	 */
	function whochanged_run_uninstall_cleanup() {
		if ( ! class_exists( 'WhoChanged_Uninstall' ) ) {
			require_once WHOCHANGED_PLUGIN_DIR . 'includes/class-uninstall.php';
		}

		WhoChanged_Uninstall::run();
	}

	add_action( 'whochanged_fs_loaded', 'whochanged_register_uninstall_handler' );
}
