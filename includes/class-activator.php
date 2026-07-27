<?php
/**
 * Activator.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs activation logic.
 */
class WhoChanged_Activator {

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		WhoChanged_Database::create_table();

		// Seed one activation entry so first-time installs immediately show data.
		if ( class_exists( 'WhoChanged_Logger' ) ) {
			$actor_id = absint( get_current_user_id() );
			if ( $actor_id <= 0 ) {
				$user = wp_get_current_user();
				if ( $user && isset( $user->ID ) ) {
					$actor_id = absint( $user->ID );
				}
			}

			$event = array(
				'type'        => 'activated_plugin',
				'label'       => __( 'Plugin activated', 'whochanged' ),
				'meta'        => array(
					'name'         => 'WhoChanged',
					'plugin_file'  => plugin_basename( WHOCHANGED_PLUGIN_FILE ),
					'network_wide' => false,
				),
				'diff'        => WhoChanged_Diff::build(
					null,
					array(
						'network_wide' => false,
					)
				),
				'action_type' => 'activated_plugin',
				'object_type' => 'plugin',
				'object_name' => 'WhoChanged',
			);

			$logger = new WhoChanged_Logger();
			$logger->log_event( $event, $actor_id );
		}
	}
}
