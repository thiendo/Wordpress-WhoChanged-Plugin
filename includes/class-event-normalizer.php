<?php
/**
 * Event Normalizer.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts raw hooks to high-level events.
 */
class WhoChanged_Event_Normalizer {

	/**
	 * Normalize option update event.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Old value.
	 * @param mixed  $new_value New value.
	 * @return array<string, mixed>|false
	 */
	public static function normalize_option_update( $option, $old, $new_value ) {
		$option = sanitize_text_field( (string) $option );

		if ( self::is_noise( $option ) ) {
			return false;
		}

		if ( 'template' === $option ) {
			// Parent theme option is updated during theme switch; use stylesheet as the single canonical event.
			return false;
		}

		if ( 'stylesheet' === $option ) {
			$from      = sanitize_text_field( (string) $old );
			$to        = sanitize_text_field( (string) $new_value );
			$from_name = self::get_theme_name( $from );
			$to_name   = self::get_theme_name( $to );

			return array(
				'type'  => 'theme_changed',
				'label' => __( 'Theme changed', 'whochanged' ),
				'meta'  => array(
					'from'      => $from,
					'to'        => $to,
					'from_name' => $from_name,
					'to_name'   => $to_name,
				),
				'diff'  => WhoChanged_Diff::build( $old, $new_value ),
			);
		}

		$active_stylesheet = sanitize_text_field( (string) get_stylesheet() );
		if ( 'theme_mods_' . $active_stylesheet === $option ) {
			$menu_event = self::handle_menu_change( $old, $new_value );
			if ( false !== $menu_event ) {
				return $menu_event;
			}
		}

		// Menu internals can be noisy; only keep normalized menu_saved from theme_mods handler above.
		if ( false !== strpos( $option, 'nav_menu' ) ) {
			return false;
		}

		return array(
			'type'  => 'option_updated',
			'label' => __( 'Setting updated', 'whochanged' ),
			'meta'  => array(
				'option' => $option,
			),
			'diff'  => WhoChanged_Diff::build( $old, $new_value ),
		);
	}

	/**
	 * Normalize menu assignment changes from theme_mods.
	 *
	 * @param mixed $old Old theme mods.
	 * @param mixed $new_value New theme mods.
	 * @return array<string, mixed>|false
	 */
	public static function handle_menu_change( $old, $new_value ) {
		$old_locations = array();
		$new_locations = array();

		if ( is_array( $old ) && isset( $old['nav_menu_locations'] ) && is_array( $old['nav_menu_locations'] ) ) {
			$old_locations = $old['nav_menu_locations'];
		}

		if ( is_array( $new_value ) && isset( $new_value['nav_menu_locations'] ) && is_array( $new_value['nav_menu_locations'] ) ) {
			$new_locations = $new_value['nav_menu_locations'];
		}

		if ( empty( $old_locations ) && empty( $new_locations ) ) {
			return false;
		}

		$changes = array();

		foreach ( $new_locations as $location => $menu_id ) {
			$location_key = sanitize_key( (string) $location );
			$new_menu_id  = absint( $menu_id );
			$old_menu_id  = isset( $old_locations[ $location ] ) ? absint( $old_locations[ $location ] ) : 0;

			if ( $old_menu_id !== $new_menu_id ) {
				$changes[] = array(
					'location'       => $location_key,
					'location_label' => self::get_location_label( $location_key ),
					'from'           => self::get_menu_name( $old_menu_id ),
					'to'             => self::get_menu_name( $new_menu_id ),
				);
			}
		}

		if ( empty( $changes ) ) {
			return false;
		}

		return array(
			'type'  => 'menu_saved',
			'label' => __( 'Menu updated', 'whochanged' ),
			'meta'  => $changes,
			'diff'  => array(
				'nav_menu_locations' => array(
					'before' => $old_locations,
					'after'  => $new_locations,
				),
			),
		);
	}

	/**
	 * Normalize option add event.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return array<string, mixed>|false
	 */
	public static function normalize_option_add( $option, $value ) {
		$option = sanitize_text_field( (string) $option );

		if ( self::is_noise( $option ) ) {
			return false;
		}

		return array(
			'type'  => 'option_added',
			'label' => __( 'Setting added', 'whochanged' ),
			'meta'  => array(
				'option' => $option,
			),
			'diff'  => WhoChanged_Diff::build( null, $value ),
		);
	}

	/**
	 * Normalize option delete event.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @return array<string, mixed>|false
	 */
	public static function normalize_option_delete( $option, $old_value ) {
		$option = sanitize_text_field( (string) $option );

		if ( self::is_noise( $option ) ) {
			return false;
		}

		return array(
			'type'  => 'option_deleted',
			'label' => __( 'Setting deleted', 'whochanged' ),
			'meta'  => array(
				'option' => $option,
			),
			'diff'  => WhoChanged_Diff::build( $old_value, null ),
		);
	}

	/**
	 * Normalize plugin upgraded event.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @param string $name        Plugin name.
	 * @param string $before      Previous version.
	 * @param string $after       New version.
	 * @return array<string, mixed>
	 */
	public static function normalize_plugin_upgrade( $plugin_file, $name, $before, $after ) {
		return array(
			'type'  => 'plugin_upgraded',
			'label' => __( 'Plugin upgraded', 'whochanged' ),
			'meta'  => array(
				'plugin'         => sanitize_text_field( (string) $plugin_file ),
				'name'           => sanitize_text_field( (string) $name ),
				'version_before' => sanitize_text_field( (string) $before ),
				'version_after'  => sanitize_text_field( (string) $after ),
			),
			'diff'  => WhoChanged_Diff::build( $before, $after ),
		);
	}

	/**
	 * Normalize theme upgraded event.
	 *
	 * @param string $slug   Theme slug.
	 * @param string $name   Theme name.
	 * @param string $before Previous version.
	 * @param string $after  New version.
	 * @return array<string, mixed>
	 */
	public static function normalize_theme_upgrade( $slug, $name, $before, $after ) {
		return array(
			'type'  => 'theme_upgraded',
			'label' => __( 'Theme upgraded', 'whochanged' ),
			'meta'  => array(
				'theme'          => sanitize_text_field( (string) $slug ),
				'name'           => sanitize_text_field( (string) $name ),
				'version_before' => sanitize_text_field( (string) $before ),
				'version_after'  => sanitize_text_field( (string) $after ),
			),
			'diff'  => WhoChanged_Diff::build( $before, $after ),
		);
	}

	/**
	 * Normalize WordPress core update event.
	 *
	 * @param string $before Previous version.
	 * @param string $after  New version.
	 * @return array<string, mixed>
	 */
	public static function normalize_wordpress_update( $before, $after ) {
		return array(
			'type'  => 'wordpress_updated',
			'label' => __( 'WordPress updated', 'whochanged' ),
			'meta'  => array(
				'version_before' => sanitize_text_field( (string) $before ),
				'version_after'  => sanitize_text_field( (string) $after ),
			),
			'diff'  => WhoChanged_Diff::build( $before, $after ),
		);
	}

	/**
	 * Normalize menu created event.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $name    Menu name.
	 * @return array<string, mixed>
	 */
	public static function normalize_menu_created( $menu_id, $name ) {
		return array(
			'type'  => 'menu_created',
			'label' => __( 'Menu created', 'whochanged' ),
			'meta'  => array(
				'menu_id'   => absint( $menu_id ),
				'menu_name' => sanitize_text_field( $name ),
			),
			'diff'  => array(),
		);
	}

	/**
	 * Normalize menu updated event.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $name    Menu name.
	 * @return array<string, mixed>
	 */
	public static function normalize_menu_updated( $menu_id, $name ) {
		return array(
			'type'  => 'menu_updated',
			'label' => __( 'Menu updated', 'whochanged' ),
			'meta'  => array(
				'menu_id'   => absint( $menu_id ),
				'menu_name' => sanitize_text_field( $name ),
			),
			'diff'  => array(),
		);
	}

	/**
	 * Normalize menu deleted event.
	 *
	 * @param int    $menu_id Menu ID.
	 * @param string $name    Menu name.
	 * @return array<string, mixed>
	 */
	public static function normalize_menu_deleted( $menu_id, $name ) {
		return array(
			'type'  => 'menu_deleted',
			'label' => __( 'Menu deleted', 'whochanged' ),
			'meta'  => array(
				'menu_id'   => absint( $menu_id ),
				'menu_name' => sanitize_text_field( $name ),
			),
			'diff'  => array(),
		);
	}

	/**
	 * WooCommerce / WP Background Processing queue rows in wp_options (not human "settings").
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_wc_background_queue_option( $option ) {
		$o = strtolower( (string) $option );

		// e.g. wp_1_wc_privacy_cleanup_batch_… — order cleanup queue.
		if ( false !== strpos( $o, 'wc_privacy_cleanup' ) ) {
			return true;
		}

		// Generic WC background batch keys: …_wc_*_batch_….
		if ( preg_match( '/_wc_[a-z0-9_]+_batch_/i', $option ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether option is noise.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function is_noise( $option ) {
		$option = sanitize_text_field( (string) $option );

		// Core plugin list options — changes are huge and duplicate install/activate logs.
		$core_plugin_list = array(
			'active_plugins',
			'recently_activated',
			'uninstall_plugins',
			'wp_calendar_block_has_published_posts',
		);
		if ( in_array( $option, $core_plugin_list, true ) ) {
			return true;
		}

		// Third-party "plugin info snapshot" style options (e.g. Simple Plugin Logger).
		$lower = strtolower( $option );
		if ( false !== strpos( $lower, 'simple_plugin' ) || false !== strpos( $lower, 'plugin_logger' ) ) {
			return true;
		}
		// Plugin onboarding/activation flags (e.g. cky_first_time_activated_plugin).
		if (
			false !== strpos( $lower, 'first_time_activated' ) ||
			false !== strpos( $lower, 'activated_plugin' ) ||
			false !== strpos( $lower, 'activation_redirect' ) ||
			false !== strpos( $lower, '_welcome_' ) ||
			false !== strpos( $lower, 'plugin_info_before_update' ) ||
			false !== strpos( $lower, 'plugin_info_after_update' ) ||
			false !== strpos( $lower, 'block_pattern' )
		) {
			return true;
		}

		if ( false !== strpos( $option, '_transient_' ) ) {
			return true;
		}

		if ( false !== strpos( $option, '_site_transient_' ) ) {
			return true;
		}

		if ( 'cron' === $option ) {
			return true;
		}

		if ( false !== strpos( $option, '_doing_cron' ) ) {
			return true;
		}

		if ( false !== strpos( $option, 'recovery_mode' ) ) {
			return true;
		}

		if ( false !== strpos( $option, '_last_sent' ) ) {
			return true;
		}

		if ( 'sidebars_widgets' === $option ) {
			return true;
		}

		// Third-party audit/logging plugin internals (high noise, low value for site owners).
		if ( false !== strpos( $option, 'simple_history' ) ) {
			return true;
		}

		if ( false !== strpos( $option, 'wp_security_audit_log' ) || false !== strpos( $option, 'wsal' ) ) {
			return true;
		}

		if ( '_process_lock' === $option ) {
			return true;
		}

		if ( false !== strpos( $option, 'wc_regenerate_images' ) ) {
			return true;
		}

		/*
		 * WordPress stores upgrader / background-update locks as options named *.lock
		 * (e.g. auto_updater.lock). Values are timestamps for internal locking, not admin settings.
		 */
		$len = strlen( $option );
		if ( $len > 5 && substr( $option, -5 ) === '.lock' ) {
			return true;
		}

		if ( self::is_wc_background_queue_option( $option ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve theme display name from slug.
	 *
	 * @param string $slug Theme slug.
	 * @return string
	 */
	private static function get_theme_name( $slug ) {
		$slug = sanitize_text_field( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		$theme = wp_get_theme( $slug );

		if ( $theme->exists() ) {
			return sanitize_text_field( (string) $theme->get( 'Name' ) );
		}

		return $slug;
	}

	/**
	 * Convert menu ID to menu name.
	 *
	 * @param int $menu_id Menu ID.
	 * @return string
	 */
	public static function get_menu_name( $menu_id ) {
		$menu_id = absint( $menu_id );
		if ( 0 === $menu_id ) {
			return __( 'None', 'whochanged' );
		}

		$menu = wp_get_nav_menu_object( $menu_id );
		if ( $menu && isset( $menu->name ) ) {
			return sanitize_text_field( (string) $menu->name );
		}

		return __( 'Unknown', 'whochanged' );
	}

	/**
	 * Convert menu location key to human label.
	 *
	 * @param string $location Location key.
	 * @return string
	 */
	public static function get_location_label( $location ) {
		$location  = sanitize_key( (string) $location );
		$locations = get_registered_nav_menus();

		if ( isset( $locations[ $location ] ) ) {
			return sanitize_text_field( (string) $locations[ $location ] );
		}

		return $location;
	}
}
