<?php
/**
 * Event hooks.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers tracked hooks and forwards events to logger.
 */
class WhoChanged_Hooks {

	/**
	 * Logger instance.
	 *
	 * @var WhoChanged_Logger
	 */
	private $logger;

	/**
	 * After a stylesheet change, suppress related option noise for a short window (seconds).
	 *
	 * @var float
	 */
	private $suppress_theme_noise_until = 0.0;

	/**
	 * Plugin basename => version string captured before upgrade.
	 *
	 * @var array<string, string>
	 */
	private $plugin_version_before = array();

	/**
	 * Theme slug => version string captured before upgrade.
	 *
	 * @var array<string, string>
	 */
	private $theme_version_before = array();

	/**
	 * WordPress version captured before core upgrade.
	 *
	 * @var string
	 */
	private $core_version_before = '';

	/**
	 * Captured old option values from pre_update_option.
	 *
	 * @var array<string, mixed>
	 */
	private $captured_old_values = array();

	/**
	 * Direct DB snapshots captured before update.
	 *
	 * @var array<string, mixed>
	 */
	private $captured_db_old_values = array();

	/**
	 * Customizer setting_id => raw value captured just before save, so the
	 * after-save diff only reports settings that actually changed.
	 *
	 * @var array<string, mixed>
	 */
	private $customizer_old_values = array();

	/**
	 * After plugin activate/deactivate, suppress noisy option logs briefly.
	 *
	 * @var float
	 */
	private $suppress_plugin_noise_until = 0.0;

	/**
	 * Constructor.
	 *
	 * @param WhoChanged_Logger $logger Logger.
	 */
	public function __construct( WhoChanged_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'upgrader_pre_install', array( $this, 'on_upgrader_pre_install' ), 10, 2 );
		add_filter( 'pre_update_option', array( $this, 'on_pre_update_option' ), 10, 3 );

		add_action( 'update_option', array( $this, 'on_update_option' ), 10, 3 );
		add_action( 'add_option', array( $this, 'on_add_option' ), 10, 2 );
		add_action( 'delete_option', array( $this, 'on_delete_option' ), 10, 1 );
		add_action( 'customize_save', array( $this, 'on_customize_save' ), 10, 1 );
		add_action( 'customize_save_after', array( $this, 'on_customize_save_after' ), 10, 1 );

		add_action( 'activated_plugin', array( $this, 'on_activated_plugin' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated_plugin' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'on_deleted_plugin' ), 10, 2 );
		add_action( 'trash_comment', array( $this, 'on_trash_comment' ), 10, 1 );
		add_action( 'untrash_comment', array( $this, 'on_untrash_comment' ), 10, 1 );
		add_action( 'delete_comment', array( $this, 'on_delete_comment' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_wp_trash_post' ), 10, 2 );
		add_action( 'untrash_post', array( $this, 'on_untrash_post' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'on_woocommerce_before_delete_order' ), 10, 1 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_process_complete' ), 10, 2 );
		add_action( 'wp_create_nav_menu', array( $this, 'on_create_nav_menu' ), 10, 2 );
		add_action( 'wp_update_nav_menu', array( $this, 'on_update_nav_menu' ), 10, 2 );
		add_action( 'wp_delete_nav_menu', array( $this, 'on_delete_nav_menu' ), 10, 1 );

		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'on_wp_logout' ), 10, 1 );
		add_action( 'wp_login_failed', array( $this, 'on_wp_login_failed' ), 10, 1 );
		add_action( 'set_user_role', array( $this, 'on_set_user_role' ), 10, 3 );
	}

	/**
	 * User role changed.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $new_role  New role slug.
	 * @param array  $old_roles Previous role slugs.
	 * @return void
	 */
	public function on_set_user_role( $user_id, $new_role, $old_roles ) {
		$user_id   = absint( $user_id );
		$new_role  = sanitize_key( (string) $new_role );
		$old_roles = is_array( $old_roles ) ? array_map( 'sanitize_key', $old_roles ) : array();

		$new_roles = array();
		$user      = get_userdata( $user_id );
		if ( $user && isset( $user->roles ) && is_array( $user->roles ) ) {
			$new_roles = array_map( 'sanitize_key', $user->roles );
		}
		if ( empty( $new_roles ) && '' !== $new_role ) {
			$new_roles = array( $new_role );
		}

		// Alert-worthy when admin role is assigned/removed or any role actually changed.
		$admin_changed = in_array( 'administrator', $old_roles, true ) !== in_array( 'administrator', $new_roles, true );
		if ( ! $admin_changed && wp_json_encode( array_values( $old_roles ) ) === wp_json_encode( array_values( $new_roles ) ) ) {
			return;
		}

		$display_name = $user ? (string) $user->display_name : sprintf(
			/* translators: %d: user ID. */
			__( 'User #%d', 'whochanged' ),
			$user_id
		);

		$event = array(
			'type'        => 'admin_role_changed',
			'label'       => __( 'Admin role changed', 'whochanged' ),
			'meta'        => array(
				'user_id'   => $user_id,
				'user_name' => sanitize_text_field( $display_name ),
			),
			'diff'        => array(
				'roles' => array(
					'before' => array_values( $old_roles ),
					'after'  => array_values( $new_roles ),
				),
			),
			'action_type' => 'admin_role_changed',
			'object_type' => 'user',
			'object_name' => sanitize_text_field( $display_name ),
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Capture real old value before option update runs.
	 *
	 * @param mixed  $new_value New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value from DB before update.
	 * @return mixed
	 */
	public function on_pre_update_option( $new_value, $option, $old_value ) {
		$option_name = sanitize_text_field( (string) $option );

		// Avoid reading arbitrary / sensitive options that we would never log.
		if ( ! $this->should_track_option_event( $option_name ) || $this->should_skip_option( $option_name ) || $this->is_sensitive_option_name( $option_name ) ) {
			return $new_value;
		}

		$this->captured_old_values[ $option_name ]    = maybe_unserialize( $old_value );
		$this->captured_db_old_values[ $option_name ] = maybe_unserialize( get_option( $option_name, null ) );

		return $new_value;
	}

	/**
	 * True during WP-Cron, real cron, or WP-CLI — not a normal admin screen save.
	 *
	 * @return bool
	 */
	private function is_unattended_request() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}

		return false;
	}

	/**
	 * Option names that are usually updated by background queues/workers.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	private function is_background_option_event( $option_name ) {
		$option_name = strtolower( sanitize_text_field( (string) $option_name ) );
		if ( '' === $option_name ) {
			return false;
		}

		if ( false !== strpos( $option_name, 'action_scheduler' ) ) {
			return true;
		}

		if ( false !== strpos( $option_name, '_batch_' ) || false !== strpos( $option_name, '_queue_' ) ) {
			return true;
		}

		if ( false !== strpos( $option_name, '_process_lock' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Background option updates are logged as system (user_id = 0).
	 *
	 * @param string $option_name Option name.
	 * @return int
	 */
	private function resolve_option_actor_id( $option_name ) {
		if ( $this->is_background_option_event( $option_name ) ) {
			return 0;
		}

		return get_current_user_id();
	}

	/**
	 * Capture versions before install/upgrade so we can diff after.
	 *
	 * @param bool|WP_Error $result     Continue flag.
	 * @param array         $hook_extra Extra hook data.
	 * @return bool|WP_Error
	 */
	public function on_upgrader_pre_install( $result, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return $result;
		}

		// During plugin installs/updates, many plugins write onboarding options.
		// Suppress option-noise and keep only lifecycle logs.
		$hook_type   = isset( $hook_extra['type'] ) ? sanitize_key( (string) $hook_extra['type'] ) : '';
		$hook_action = isset( $hook_extra['action'] ) ? sanitize_key( (string) $hook_extra['action'] ) : '';
		if ( 'plugin' === $hook_type || isset( $hook_extra['plugin'] ) || isset( $hook_extra['plugins'] ) ) {
			$this->suppress_plugin_noise_until = microtime( true ) + 20.0;
		}

		if ( isset( $hook_extra['plugin'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$plugin = sanitize_text_field( (string) $hook_extra['plugin'] );
			$file   = WP_PLUGIN_DIR . '/' . $plugin;

			if ( is_readable( $file ) ) {
				$data = get_plugin_data( $file, false, false );

				if ( isset( $data['Version'] ) ) {
					$this->plugin_version_before[ $plugin ] = sanitize_text_field( (string) $data['Version'] );
				}
			}
		}

		if ( isset( $hook_extra['theme'] ) ) {
			$slug  = sanitize_text_field( (string) $hook_extra['theme'] );
			$theme = wp_get_theme( $slug );

			if ( $theme->exists() ) {
				$this->theme_version_before[ $slug ] = sanitize_text_field( (string) $theme->get( 'Version' ) );
			}
		}

		if ( isset( $hook_extra['type'] ) && 'core' === $hook_extra['type'] ) {
			global $wp_version;

			$this->core_version_before = sanitize_text_field( (string) $wp_version );
		}

		return $result;
	}

	/**
	 * Option updated.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 * @return void
	 */
	public function on_update_option( $option, $old_value, $value ) {
		if ( 'template' === $option ) {
			// Avoid duplicate "theme changed" event for a single theme switch action.
			unset( $this->captured_old_values[ sanitize_text_field( (string) $option ) ] );
			unset( $this->captured_db_old_values[ sanitize_text_field( (string) $option ) ] );
			return;
		}

		$option_name = sanitize_text_field( (string) $option );
		if ( ! $this->should_track_option_event( $option_name ) ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}
		if ( $this->is_unattended_request() ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		if ( $this->should_skip_option( $option_name ) ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		if ( $this->is_within_plugin_toggle_suppression() ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		if ( $this->is_plugin_settings_option( $option_name ) ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		$filter_old = array_key_exists( $option_name, $this->captured_old_values )
			? $this->captured_old_values[ $option_name ]
			: $old_value;
		$db_old     = array_key_exists( $option_name, $this->captured_db_old_values )
			? $this->captured_db_old_values[ $option_name ]
			: null;

		$real_old = maybe_unserialize( $filter_old );
		$db_old   = maybe_unserialize( $db_old );
		$value    = maybe_unserialize( $value );

		/*
		 * If hook-provided old value looks unchanged but DB snapshot differs,
		 * prefer DB snapshot as the reliable "before" state.
		 */
		if ( $this->values_are_equal( $real_old, $value ) && ! $this->values_are_equal( $db_old, $value ) ) {
			$real_old = $db_old;
		}

		if ( $this->values_are_equal( $real_old, $value ) ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		$event = WhoChanged_Event_Normalizer::normalize_option_update( $option_name, $real_old, $value );
		if ( false === $event ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		if ( $this->is_within_theme_switch_suppression() && $this->is_theme_switch_related_option( $option_name ) ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		if ( isset( $event['type'] ) && 'theme_changed' === $event['type'] && 'stylesheet' === $option_name ) {
			$this->suppress_theme_noise_until = microtime( true ) + 2.0;
		}

		if ( ! isset( $event['diff'] ) || ! is_array( $event['diff'] ) || empty( $event['diff'] ) ) {
			unset( $this->captured_old_values[ $option_name ] );
			unset( $this->captured_db_old_values[ $option_name ] );
			return;
		}

		$event['action_type'] = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : 'option_updated';
		$event['object_type'] = 'option';
		$event['object_name'] = $option_name;

		$this->logger->log_event( $event, $this->resolve_option_actor_id( $option_name ) );
		unset( $this->captured_old_values[ $option_name ] );
		unset( $this->captured_db_old_values[ $option_name ] );
	}

	/**
	 * Option added.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_add_option( $option, $value ) {
		$option_name = sanitize_text_field( (string) $option );
		if ( ! $this->should_track_option_event( $option_name ) ) {
			return;
		}
		if ( $this->is_unattended_request() ) {
			return;
		}

		if ( $this->should_skip_option( $option_name ) ) {
			return;
		}

		if ( $this->is_within_plugin_toggle_suppression() ) {
			return;
		}

		if ( $this->is_plugin_settings_option( $option_name ) ) {
			return;
		}

		$event = WhoChanged_Event_Normalizer::normalize_option_add( $option_name, $value );
		if ( false === $event ) {
			return;
		}

		if ( $this->is_within_theme_switch_suppression() && $this->is_theme_switch_related_option( $option_name ) ) {
			return;
		}

		$event['action_type'] = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : 'option_added';
		$event['object_type'] = 'option';
		$event['object_name'] = $option_name;

		$this->logger->log_event( $event, $this->resolve_option_actor_id( $option_name ) );
	}

	/**
	 * Option deleted.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public function on_delete_option( $option ) {
		$option_name = sanitize_text_field( (string) $option );
		if ( ! $this->should_track_option_event( $option_name ) ) {
			return;
		}
		if ( $this->is_unattended_request() ) {
			return;
		}

		if ( $this->should_skip_option( $option_name ) ) {
			return;
		}

		if ( $this->is_within_plugin_toggle_suppression() ) {
			return;
		}

		if ( $this->is_plugin_settings_option( $option_name ) ) {
			return;
		}

		if ( $this->is_sensitive_option_name( $option_name ) ) {
			return;
		}

		$old_value = get_option( $option_name );
		$event     = WhoChanged_Event_Normalizer::normalize_option_delete( $option_name, $old_value );
		if ( false === $event ) {
			return;
		}

		if ( $this->is_within_theme_switch_suppression() && $this->is_theme_switch_related_option( $option_name ) ) {
			return;
		}

		$event['action_type'] = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : 'option_deleted';
		$event['object_type'] = 'option';
		$event['object_name'] = $option_name;

		$this->logger->log_event( $event, $this->resolve_option_actor_id( $option_name ) );
	}

	/**
	 * Capture each Customizer setting's real stored value just before save,
	 * so the after-save diff can tell which settings actually changed.
	 *
	 * Runs on `customize_save`, which fires before individual settings are
	 * persisted, so `get_option()`/`get_theme_mod()` still return the old value.
	 *
	 * @param WP_Customize_Manager $manager Manager.
	 * @return void
	 */
	public function on_customize_save( $manager ) {
		$this->customizer_old_values = array();

		if ( ! is_object( $manager ) || ! method_exists( $manager, 'unsanitized_post_values' ) || ! method_exists( $manager, 'get_setting' ) ) {
			return;
		}

		foreach ( array_keys( (array) $manager->unsanitized_post_values() ) as $setting_id ) {
			$setting = $manager->get_setting( (string) $setting_id );
			if ( is_object( $setting ) ) {
				$this->customizer_old_values[ (string) $setting_id ] = $this->get_raw_customizer_setting_value( $setting );
			}
		}
	}

	/**
	 * Read a Customizer setting's current stored value directly from the
	 * options/theme_mods table, bypassing the setting's own value()/preview
	 * filters so it reflects what is actually in the database right now.
	 *
	 * @param WP_Customize_Setting $setting Setting instance.
	 * @return mixed
	 */
	private function get_raw_customizer_setting_value( $setting ) {
		if ( ! method_exists( $setting, 'id_data' ) ) {
			return null;
		}

		$id_data = $setting->id_data();
		$base    = isset( $id_data['base'] ) ? (string) $id_data['base'] : '';
		if ( '' === $base ) {
			return null;
		}
		$keys = isset( $id_data['keys'] ) && is_array( $id_data['keys'] ) ? $id_data['keys'] : array();

		$type = isset( $setting->type ) ? (string) $setting->type : 'option';
		if ( 'theme_mod' === $type ) {
			$value = get_theme_mod( $base );
		} else {
			if ( $this->is_sensitive_option_name( $base ) ) {
				return null;
			}
			$value = get_option( $base );
		}

		foreach ( $keys as $key ) {
			if ( is_array( $value ) && array_key_exists( $key, $value ) ) {
				$value = $value[ $key ];
			} else {
				return null;
			}
		}

		return $value;
	}

	/**
	 * Customizer saved.
	 *
	 * @param WP_Customize_Manager $manager Manager.
	 * @return void
	 */
	public function on_customize_save_after( $manager ) {
		$changeset_data = array();

		if ( is_object( $manager ) && method_exists( $manager, 'unsanitized_post_values' ) ) {
			$changeset_data = (array) $manager->unsanitized_post_values();
		}

		$before = array();
		foreach ( array_keys( $changeset_data ) as $setting_id ) {
			if ( array_key_exists( (string) $setting_id, $this->customizer_old_values ) ) {
				$before[ $setting_id ] = $this->customizer_old_values[ (string) $setting_id ];
			}
		}

		$diff = WhoChanged_Diff::build( $before, $changeset_data );
		if ( empty( $diff ) ) {
			$this->customizer_old_values = array();
			return;
		}

		$this->logger->log(
			'customize_save_after',
			'customizer',
			'changeset',
			$diff,
			get_current_user_id()
		);

		$this->customizer_old_values = array();
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Network-wide activation flag.
	 * @return void
	 */
	public function on_activated_plugin( $plugin, $network_wide ) {
		$this->suppress_plugin_noise_until = microtime( true ) + 3.0;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = sanitize_text_field( (string) $plugin );
		$plugin_name = $plugin_file;
		$file_path   = WP_PLUGIN_DIR . '/' . $plugin_file;

		if ( is_readable( $file_path ) ) {
			$data = get_plugin_data( $file_path, false, false );
			if ( isset( $data['Name'] ) && '' !== $data['Name'] ) {
				$plugin_name = sanitize_text_field( (string) $data['Name'] );
			}
		}

		$event = array(
			'type'        => 'activated_plugin',
			'label'       => __( 'Plugin activated', 'whochanged' ),
			'meta'        => array(
				'name'         => $plugin_name,
				'plugin_file'  => $plugin_file,
				'network_wide' => (bool) $network_wide,
			),
			'diff'        => WhoChanged_Diff::build(
				null,
				array(
					'network_wide' => (bool) $network_wide,
				)
			),
			'action_type' => 'activated_plugin',
			'object_type' => 'plugin',
			'object_name' => $plugin_name,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin             Plugin basename.
	 * @param bool   $network_deactivate Network-wide deactivation flag.
	 * @return void
	 */
	public function on_deactivated_plugin( $plugin, $network_deactivate ) {
		$this->suppress_plugin_noise_until = microtime( true ) + 3.0;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = sanitize_text_field( (string) $plugin );
		$plugin_name = $plugin_file;
		$file_path   = WP_PLUGIN_DIR . '/' . $plugin_file;

		if ( is_readable( $file_path ) ) {
			$data = get_plugin_data( $file_path, false, false );
			if ( isset( $data['Name'] ) && '' !== $data['Name'] ) {
				$plugin_name = sanitize_text_field( (string) $data['Name'] );
			}
		}

		$event = array(
			'type'        => 'deactivated_plugin',
			'label'       => __( 'Plugin deactivated', 'whochanged' ),
			'meta'        => array(
				'name'               => $plugin_name,
				'plugin_file'        => $plugin_file,
				'network_deactivate' => (bool) $network_deactivate,
			),
			'diff'        => WhoChanged_Diff::build(
				array(
					'network_wide' => (bool) $network_deactivate,
				),
				null
			),
			'action_type' => 'deactivated_plugin',
			'object_type' => 'plugin',
			'object_name' => $plugin_name,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $plugin  Plugin basename.
	 * @param bool   $deleted Whether deletion succeeded.
	 * @return void
	 */
	public function on_deleted_plugin( $plugin, $deleted ) {
		if ( ! $deleted ) {
			return;
		}

		$plugin_file = sanitize_text_field( (string) $plugin );
		$plugin_name = $plugin_file;
		$parts       = explode( '/', $plugin_file );
		if ( ! empty( $parts[0] ) ) {
			$plugin_name = sanitize_text_field( (string) $parts[0] );
		}

		$event = array(
			'type'        => 'deleted_plugin',
			'label'       => __( 'Plugin deleted', 'whochanged' ),
			'meta'        => array(
				'name'        => $plugin_name,
				'plugin_file' => $plugin_file,
			),
			'diff'        => array(),
			'action_type' => 'deleted_plugin',
			'object_type' => 'plugin',
			'object_name' => $plugin_name,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Parent post type label for comment context (Post, Page, CPT…).
	 *
	 * @param int $post_id Post ID.
	 * @return array{post_type:string,post_type_label:string}
	 */
	private function get_comment_parent_post_context( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array(
				'post_type'       => '',
				'post_type_label' => '',
			);
		}
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return array(
				'post_type'       => '',
				'post_type_label' => '',
			);
		}
		$pt  = sanitize_key( (string) $post->post_type );
		$pto = get_post_type_object( $pt );
		$lbl = '';
		if ( $pto && isset( $pto->labels->singular_name ) && '' !== (string) $pto->labels->singular_name ) {
			$lbl = sanitize_text_field( (string) $pto->labels->singular_name );
		} elseif ( $pto && isset( $pto->label ) && '' !== (string) $pto->label ) {
			$lbl = sanitize_text_field( (string) $pto->label );
		} else {
			$lbl = $pt;
		}
		return array(
			'post_type'       => $pt,
			'post_type_label' => $lbl,
		);
	}

	/**
	 * Short plain-text preview of comment body for activity display.
	 *
	 * @param WP_Comment|null $comment Comment object.
	 * @param int             $comment_id Fallback ID.
	 * @return string
	 */
	private function get_comment_preview_text( $comment, $comment_id = 0 ) {
		if ( ! ( $comment instanceof WP_Comment ) ) {
			return $comment_id > 0 ? '#' . (string) absint( $comment_id ) : '';
		}
		$text = wp_strip_all_tags( (string) $comment->comment_content );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );
		if ( '' === $text ) {
			return '#' . (string) absint( $comment->comment_ID );
		}
		if ( strlen( $text ) > 120 ) {
			$text = substr( $text, 0, 117 ) . '...';
		}
		return sanitize_text_field( $text );
	}

	/**
	 * Build readable WooCommerce order title for logs.
	 * Example: "#2602 Alex Davis".
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private function get_order_log_title( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return '';
		}

		$title = '#' . (string) $order_id;
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && is_object( $order ) ) {
				$full_name = '';
				if ( method_exists( $order, 'get_formatted_billing_full_name' ) ) {
					$full_name = sanitize_text_field( (string) $order->get_formatted_billing_full_name() );
				}
				if ( '' === $full_name && method_exists( $order, 'get_billing_first_name' ) && method_exists( $order, 'get_billing_last_name' ) ) {
					$full_name = trim(
						sanitize_text_field( (string) $order->get_billing_first_name() ) . ' ' .
						sanitize_text_field( (string) $order->get_billing_last_name() )
					);
				}
				if ( '' !== $full_name ) {
					$title .= ' ' . $full_name;
				}
			}
		}

		return $title;
	}

	/**
	 * Comment deleted.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object.
	 * @return void
	 */
	public function on_delete_comment( $comment_id, $comment = null ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 ) {
			return;
		}

		if ( ! ( $comment instanceof WP_Comment ) ) {
			$comment = get_comment( $comment_id );
		}

		$post_id    = ( $comment instanceof WP_Comment ) ? absint( $comment->comment_post_ID ) : 0;
		$post_title = $post_id > 0 ? get_the_title( $post_id ) : '';
		$ctx        = $this->get_comment_parent_post_context( $post_id );
		$preview    = $this->get_comment_preview_text( $comment instanceof WP_Comment ? $comment : null, $comment_id );

		$event = array(
			'type'        => 'comment_deleted',
			'label'       => __( 'Comment deleted', 'whochanged' ),
			'meta'        => array(
				'comment_id'             => $comment_id,
				'post_id'                => $post_id,
				'post_title'             => sanitize_text_field( (string) $post_title ),
				'comment_preview'        => $preview,
				'parent_post_type'       => (string) $ctx['post_type'],
				'parent_post_type_label' => (string) $ctx['post_type_label'],
			),
			'diff'        => array(),
			'action_type' => 'comment_deleted',
			'object_type' => 'comment',
			'object_name' => 'comment_' . (string) $comment_id,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Comment moved to trash.
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function on_trash_comment( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 ) {
			return;
		}

		$comment    = get_comment( $comment_id );
		$post_id    = ( $comment instanceof WP_Comment ) ? absint( $comment->comment_post_ID ) : 0;
		$post_title = $post_id > 0 ? get_the_title( $post_id ) : '';
		$ctx        = $this->get_comment_parent_post_context( $post_id );
		$preview    = $this->get_comment_preview_text( $comment instanceof WP_Comment ? $comment : null, $comment_id );

		$event = array(
			'type'        => 'comment_trashed',
			'label'       => __( 'Comment moved to trash', 'whochanged' ),
			'meta'        => array(
				'comment_id'             => $comment_id,
				'post_id'                => $post_id,
				'post_title'             => sanitize_text_field( (string) $post_title ),
				'comment_preview'        => $preview,
				'parent_post_type'       => (string) $ctx['post_type'],
				'parent_post_type_label' => (string) $ctx['post_type_label'],
			),
			'diff'        => array(),
			'action_type' => 'comment_trashed',
			'object_type' => 'comment',
			'object_name' => 'comment_' . (string) $comment_id,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Comment restored from trash.
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function on_untrash_comment( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 ) {
			return;
		}

		$comment    = get_comment( $comment_id );
		$post_id    = ( $comment instanceof WP_Comment ) ? absint( $comment->comment_post_ID ) : 0;
		$post_title = $post_id > 0 ? get_the_title( $post_id ) : '';
		$ctx        = $this->get_comment_parent_post_context( $post_id );
		$preview    = $this->get_comment_preview_text( $comment instanceof WP_Comment ? $comment : null, $comment_id );

		$event = array(
			'type'        => 'comment_restored',
			'label'       => __( 'Comment restored', 'whochanged' ),
			'meta'        => array(
				'comment_id'             => $comment_id,
				'post_id'                => $post_id,
				'post_title'             => sanitize_text_field( (string) $post_title ),
				'comment_preview'        => $preview,
				'parent_post_type'       => (string) $ctx['post_type'],
				'parent_post_type_label' => (string) $ctx['post_type_label'],
			),
			'diff'        => array(),
			'action_type' => 'comment_restored',
			'object_type' => 'comment',
			'object_name' => 'comment_' . (string) $comment_id,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Post/page/product/order deleted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function on_before_delete_post( $post_id, $post = null ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$post_type = sanitize_key( (string) $post->post_type );
		if ( 'revision' === $post_type || 'attachment' === $post_type || 'nav_menu_item' === $post_type ) {
			return;
		}

		$event_type  = '';
		$object_type = '';
		$label       = '';

		if ( 'post' === $post_type ) {
			$event_type  = 'post_deleted';
			$object_type = 'post';
			$label       = __( 'Post deleted', 'whochanged' );
		} elseif ( 'page' === $post_type ) {
			$event_type  = 'page_deleted';
			$object_type = 'page';
			$label       = __( 'Page deleted', 'whochanged' );
		} elseif ( 'product' === $post_type ) {
			$event_type  = 'product_deleted';
			$object_type = 'product';
			$label       = __( 'Product deleted', 'whochanged' );
		} elseif ( 'shop_order' === $post_type ) {
			$event_type  = 'order_deleted';
			$object_type = 'order';
			$label       = __( 'Order deleted', 'whochanged' );
		} else {
			$pto    = get_post_type_object( $post_type );
			$pt_lbl = '';
			if ( $pto && isset( $pto->labels->singular_name ) && '' !== (string) $pto->labels->singular_name ) {
				$pt_lbl = sanitize_text_field( (string) $pto->labels->singular_name );
			} elseif ( $pto && isset( $pto->label ) && '' !== (string) $pto->label ) {
				$pt_lbl = sanitize_text_field( (string) $pto->label );
			} else {
				$pt_lbl = sanitize_text_field( (string) $post_type );
			}
			$event_type  = 'cpt_deleted';
			$object_type = sanitize_key( (string) $post_type );
			$label       = sprintf(
				/* translators: %s: post type singular name */
				__( '%s deleted', 'whochanged' ),
				$pt_lbl
			);
		}

		$title = sanitize_text_field( (string) get_the_title( $post_id ) );
		if ( 'shop_order' === $post_type ) {
			$order_title = $this->get_order_log_title( $post_id );
			if ( '' !== $order_title ) {
				$title = $order_title;
			}
		}
		if ( '' === $title ) {
			$title = '#' . (string) $post_id;
		}

		$meta = array(
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'title'     => $title,
		);
		if ( isset( $pt_lbl ) && '' !== (string) $pt_lbl ) {
			$meta['post_type_label'] = (string) $pt_lbl;
		}

		$event = array(
			'type'        => $event_type,
			'label'       => $label,
			'meta'        => $meta,
			'diff'        => array(),
			'action_type' => $event_type,
			'object_type' => $object_type,
			'object_name' => $title,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Post/page/product/order moved to trash.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $previous_status Previous status.
	 * @return void
	 */
	public function on_wp_trash_post( $post_id, $previous_status = '' ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$post_type = sanitize_key( (string) $post->post_type );
		if ( 'revision' === $post_type || 'attachment' === $post_type || 'nav_menu_item' === $post_type ) {
			return;
		}

		$event_type  = '';
		$object_type = '';
		$label       = '';

		if ( 'post' === $post_type ) {
			$event_type  = 'post_trashed';
			$object_type = 'post';
			$label       = __( 'Post moved to trash', 'whochanged' );
		} elseif ( 'page' === $post_type ) {
			$event_type  = 'page_trashed';
			$object_type = 'page';
			$label       = __( 'Page moved to trash', 'whochanged' );
		} elseif ( 'product' === $post_type ) {
			$event_type  = 'product_trashed';
			$object_type = 'product';
			$label       = __( 'Product moved to trash', 'whochanged' );
		} elseif ( 'shop_order' === $post_type ) {
			$event_type  = 'order_trashed';
			$object_type = 'order';
			$label       = __( 'Order moved to trash', 'whochanged' );
		} else {
			$pto    = get_post_type_object( $post_type );
			$pt_lbl = '';
			if ( $pto && isset( $pto->labels->singular_name ) && '' !== (string) $pto->labels->singular_name ) {
				$pt_lbl = sanitize_text_field( (string) $pto->labels->singular_name );
			} elseif ( $pto && isset( $pto->label ) && '' !== (string) $pto->label ) {
				$pt_lbl = sanitize_text_field( (string) $pto->label );
			} else {
				$pt_lbl = sanitize_text_field( (string) $post_type );
			}
			$event_type  = 'cpt_trashed';
			$object_type = sanitize_key( (string) $post_type );
			$label       = sprintf(
				/* translators: %s: post type singular name */
				__( '%s moved to trash', 'whochanged' ),
				$pt_lbl
			);
		}

		$title = sanitize_text_field( (string) get_the_title( $post_id ) );
		if ( 'shop_order' === $post_type ) {
			$order_title = $this->get_order_log_title( $post_id );
			if ( '' !== $order_title ) {
				$title = $order_title;
			}
		}
		if ( '' === $title ) {
			$title = '#' . (string) $post_id;
		}

		$meta = array(
			'post_id'         => $post_id,
			'post_type'       => $post_type,
			'title'           => $title,
			'previous_status' => sanitize_key( (string) $previous_status ),
		);
		if ( isset( $pt_lbl ) && '' !== (string) $pt_lbl ) {
			$meta['post_type_label'] = (string) $pt_lbl;
		}

		$event = array(
			'type'        => $event_type,
			'label'       => $label,
			'meta'        => $meta,
			'diff'        => array(),
			'action_type' => $event_type,
			'object_type' => $object_type,
			'object_name' => $title,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Post/page/product/order restored from trash.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_untrash_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$post_type = sanitize_key( (string) $post->post_type );
		if ( 'revision' === $post_type || 'attachment' === $post_type || 'nav_menu_item' === $post_type ) {
			return;
		}

		$event_type  = '';
		$object_type = '';
		$label       = '';

		if ( 'post' === $post_type ) {
			$event_type  = 'post_restored';
			$object_type = 'post';
			$label       = __( 'Post restored', 'whochanged' );
		} elseif ( 'page' === $post_type ) {
			$event_type  = 'page_restored';
			$object_type = 'page';
			$label       = __( 'Page restored', 'whochanged' );
		} elseif ( 'product' === $post_type ) {
			$event_type  = 'product_restored';
			$object_type = 'product';
			$label       = __( 'Product restored', 'whochanged' );
		} elseif ( 'shop_order' === $post_type ) {
			$event_type  = 'order_restored';
			$object_type = 'order';
			$label       = __( 'Order restored', 'whochanged' );
		} else {
			$pto    = get_post_type_object( $post_type );
			$pt_lbl = '';
			if ( $pto && isset( $pto->labels->singular_name ) && '' !== (string) $pto->labels->singular_name ) {
				$pt_lbl = sanitize_text_field( (string) $pto->labels->singular_name );
			} elseif ( $pto && isset( $pto->label ) && '' !== (string) $pto->label ) {
				$pt_lbl = sanitize_text_field( (string) $pto->label );
			} else {
				$pt_lbl = sanitize_text_field( (string) $post_type );
			}
			$event_type  = 'cpt_restored';
			$object_type = sanitize_key( (string) $post_type );
			$label       = sprintf(
				/* translators: %s: post type singular name */
				__( '%s restored', 'whochanged' ),
				$pt_lbl
			);
		}

		$title = sanitize_text_field( (string) get_the_title( $post_id ) );
		if ( 'shop_order' === $post_type ) {
			$order_title = $this->get_order_log_title( $post_id );
			if ( '' !== $order_title ) {
				$title = $order_title;
			}
		}
		if ( '' === $title ) {
			$title = '#' . (string) $post_id;
		}

		$meta = array(
			'post_id'   => $post_id,
			'post_type' => $post_type,
			'title'     => $title,
		);
		if ( isset( $pt_lbl ) && '' !== (string) $pt_lbl ) {
			$meta['post_type_label'] = (string) $pt_lbl;
		}

		$event = array(
			'type'        => $event_type,
			'label'       => $label,
			'meta'        => $meta,
			'diff'        => array(),
			'action_type' => $event_type,
			'object_type' => $object_type,
			'object_name' => $title,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * WooCommerce HPOS order deleted.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_woocommerce_before_delete_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}

		$order_title = $this->get_order_log_title( $order_id );
		if ( '' === $order_title ) {
			$order_title = '#' . (string) $order_id;
		}

		$event = array(
			'type'        => 'order_deleted',
			'label'       => __( 'Order deleted', 'whochanged' ),
			'meta'        => array(
				'order_id' => $order_id,
				'title'    => $order_title,
			),
			'diff'        => array(),
			'action_type' => 'order_deleted',
			'object_type' => 'order',
			'object_name' => $order_title,
		);

		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Menu created.
	 *
	 * @param int   $menu_id   Menu ID.
	 * @param array $menu_data Menu data.
	 * @return void
	 */
	public function on_create_nav_menu( $menu_id, $menu_data = array() ) {
		$menu_id = absint( $menu_id );
		$name    = '';

		if ( is_array( $menu_data ) && isset( $menu_data['menu-name'] ) ) {
			$name = sanitize_text_field( (string) $menu_data['menu-name'] );
		}

		if ( '' === $name ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			if ( $menu && isset( $menu->name ) ) {
				$name = sanitize_text_field( (string) $menu->name );
			}
		}

		$event                = WhoChanged_Event_Normalizer::normalize_menu_created( $menu_id, $name );
		$event['action_type'] = 'menu_created';
		$event['object_type'] = 'menu';
		$event['object_name'] = $name;
		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Menu updated.
	 *
	 * @param int   $menu_id   Menu ID.
	 * @param array $menu_data Menu data.
	 * @return void
	 */
	public function on_update_nav_menu( $menu_id, $menu_data = array() ) {
		$menu_id = absint( $menu_id );
		$name    = '';

		if ( is_array( $menu_data ) && isset( $menu_data['menu-name'] ) ) {
			$name = sanitize_text_field( (string) $menu_data['menu-name'] );
		}

		if ( '' === $name ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			if ( $menu && isset( $menu->name ) ) {
				$name = sanitize_text_field( (string) $menu->name );
			}
		}

		$event                = WhoChanged_Event_Normalizer::normalize_menu_updated( $menu_id, $name );
		$event['action_type'] = 'menu_updated';
		$event['object_type'] = 'menu';
		$event['object_name'] = $name;
		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Menu deleted.
	 *
	 * @param int $menu_id Menu ID.
	 * @return void
	 */
	public function on_delete_nav_menu( $menu_id ) {
		$menu_id = absint( $menu_id );
		$menu    = wp_get_nav_menu_object( $menu_id );
		$name    = ( $menu && isset( $menu->name ) ) ? sanitize_text_field( (string) $menu->name ) : '';

		$event                = WhoChanged_Event_Normalizer::normalize_menu_deleted( $menu_id, $name );
		$event['action_type'] = 'menu_deleted';
		$event['object_type'] = 'menu';
		$event['object_name'] = $name;
		$this->logger->log_event( $event, get_current_user_id() );
	}

	/**
	 * Plugin/theme/core upgrade lifecycle.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Extra data.
	 * @return void
	 */
	public function on_upgrader_process_complete( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return;
		}

		$type   = isset( $hook_extra['type'] ) ? sanitize_key( $hook_extra['type'] ) : '';
		$action = isset( $hook_extra['action'] ) ? sanitize_key( $hook_extra['action'] ) : '';

		if ( 'core' === $type ) {
			global $wp_version;

			$after = sanitize_text_field( (string) get_bloginfo( 'version' ) );

			if ( '' === $after ) {
				$after = sanitize_text_field( (string) $wp_version );
			}

			$before = '' !== $this->core_version_before ? $this->core_version_before : $after;

			$event                = WhoChanged_Event_Normalizer::normalize_wordpress_update( $before, $after );
			$event['action_type'] = 'wordpress_updated';
			$event['object_type'] = 'core';
			$event['object_name'] = 'WordPress';
			$this->logger->log_event( $event, get_current_user_id() );

			$this->core_version_before = '';

			return;
		}

		if ( 'update' !== $action && 'install' !== $action && 'delete' !== $action ) {
			return;
		}

		if ( 'plugin' === $type ) {
			// Keep install/update/delete output clean: prefer plugin lifecycle events.
			$this->suppress_plugin_noise_until = microtime( true ) + 20.0;
		}

		if ( 'theme' === $type ) {
			$themes = array();

			if ( isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
				$themes = array_map( 'sanitize_text_field', $hook_extra['themes'] );
			} elseif ( isset( $hook_extra['theme'] ) ) {
				$themes = array( sanitize_text_field( (string) $hook_extra['theme'] ) );
			}

			foreach ( $themes as $slug ) {
				$before = isset( $this->theme_version_before[ $slug ] ) ? $this->theme_version_before[ $slug ] : '';
				$theme  = wp_get_theme( $slug );
				$after  = $theme->exists() ? sanitize_text_field( (string) $theme->get( 'Version' ) ) : '';

				if ( '' === $before && '' === $after ) {
					continue;
				}

				$event                = WhoChanged_Event_Normalizer::normalize_theme_upgrade( $slug, (string) $theme->get( 'Name' ), $before, $after );
				$event['action_type'] = 'theme_upgraded';
				$event['object_type'] = 'theme';
				$event['object_name'] = sanitize_text_field( $slug );
				$this->logger->log_event( $event, get_current_user_id() );

				unset( $this->theme_version_before[ $slug ] );
			}

			return;
		}

		if ( 'plugin' === $type ) {
			$plugins = array();

			if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$plugins = array_map( 'sanitize_text_field', $hook_extra['plugins'] );
			} elseif ( isset( $hook_extra['plugin'] ) ) {
				$plugins = array( sanitize_text_field( (string) $hook_extra['plugin'] ) );
			}

			foreach ( $plugins as $plugin ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';

				$file = WP_PLUGIN_DIR . '/' . $plugin; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_exists

				$before = isset( $this->plugin_version_before[ $plugin ] ) ? $this->plugin_version_before[ $plugin ] : '';
				$after  = '';
				$name   = $plugin;

				if ( is_readable( $file ) ) {
					$data = get_plugin_data( $file, false, false );

					if ( isset( $data['Version'] ) ) {
						$after = sanitize_text_field( (string) $data['Version'] );
					}

					if ( isset( $data['Name'] ) && '' !== $data['Name'] ) {
						$name = sanitize_text_field( (string) $data['Name'] );
					}
				}

				if ( 'install' === $action ) {
					$event = array(
						'type'        => 'installed_plugin',
						'label'       => __( 'Plugin installed', 'whochanged' ),
						'meta'        => array(
							'name'        => $name,
							'plugin_file' => sanitize_text_field( $plugin ),
						),
						'diff'        => array(),
						'action_type' => 'installed_plugin',
						'object_type' => 'plugin',
						'object_name' => $name,
					);
					$this->logger->log_event( $event, get_current_user_id() );
				}

				if ( 'update' === $action ) {
					$event = array(
						'type'        => 'plugin_upgraded',
						'label'       => __( 'Plugin upgraded', 'whochanged' ),
						'meta'        => array(
							'name'           => $name,
							'plugin_file'    => sanitize_text_field( $plugin ),
							'version_before' => $before,
							'version_after'  => $after,
						),
						'diff'        => WhoChanged_Diff::build(
							array(
								'version' => $before,
							),
							array(
								'version' => $after,
							)
						),
						'action_type' => 'plugin_upgraded',
						'object_type' => 'plugin',
						'object_name' => $name,
					);
					$this->logger->log_event( $event, get_current_user_id() );
				}

				if ( 'delete' === $action ) {
					$event = array(
						'type'        => 'deleted_plugin',
						'label'       => __( 'Plugin deleted', 'whochanged' ),
						'meta'        => array(
							'name'        => $name,
							'plugin_file' => sanitize_text_field( $plugin ),
						),
						'diff'        => array(),
						'action_type' => 'deleted_plugin',
						'object_type' => 'plugin',
						'object_name' => $name,
					);
					$this->logger->log_event( $event, get_current_user_id() );
				}

				unset( $this->plugin_version_before[ $plugin ] );
			}

			// Fallback: some installer flows do not provide plugin basenames in hook_extra.
			if ( empty( $plugins ) && 'install' === $action ) {
				$destination_name = '';
				if ( is_object( $upgrader ) && isset( $upgrader->result ) && is_array( $upgrader->result ) && isset( $upgrader->result['destination_name'] ) ) {
					$destination_name = sanitize_key( (string) $upgrader->result['destination_name'] );
				}

				if ( '' !== $destination_name ) {
					$plugin_name = $this->resolve_plugin_name_from_slug( $destination_name );
					if ( '' === $plugin_name ) {
						$plugin_name = $destination_name;
					}

					$event = array(
						'type'        => 'installed_plugin',
						'label'       => __( 'Plugin installed', 'whochanged' ),
						'meta'        => array(
							'name'        => $plugin_name,
							'plugin_file' => $destination_name,
						),
						'diff'        => array(),
						'action_type' => 'installed_plugin',
						'object_type' => 'plugin',
						'object_name' => $plugin_name,
					);
					$this->logger->log_event( $event, get_current_user_id() );
				}
			}
		}
	}

	/**
	 * Resolve installed plugin display name by folder slug.
	 *
	 * @param string $slug Plugin folder slug.
	 * @return string
	 */
	private function resolve_plugin_name_from_slug( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = get_plugins( '/' . $slug );
		if ( empty( $all_plugins ) || ! is_array( $all_plugins ) ) {
			return '';
		}

		foreach ( $all_plugins as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$name = isset( $data['Name'] ) ? sanitize_text_field( (string) $data['Name'] ) : '';
			if ( '' !== $name ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Whether theme-switch suppression window is active.
	 *
	 * @return bool
	 */
	private function is_within_theme_switch_suppression() {
		return microtime( true ) <= $this->suppress_theme_noise_until;
	}

	/**
	 * Whether plugin-toggle suppression window is active.
	 *
	 * @return bool
	 */
	private function is_within_plugin_toggle_suppression() {
		return microtime( true ) <= $this->suppress_plugin_noise_until;
	}

	/**
	 * Options commonly updated during theme switch (noise after stylesheet change).
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function is_theme_switch_related_option( $option ) {
		$option_name = sanitize_text_field( (string) $option );

		if ( 'stylesheet' === $option_name || 'template' === $option_name ) {
			return true;
		}

		if ( 0 === strpos( $option_name, 'theme_mods_' ) ) {
			return true;
		}

		if ( 'sidebars_widgets' === $option_name ) {
			return true;
		}

		if ( 0 === strpos( $option_name, 'widget_' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Skip noisy system options and hidden background tasks.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function should_skip_option( $option ) {
		return WhoChanged_Event_Normalizer::is_noise( $option );
	}

	/**
	 * Detect options that are likely plugin-owned settings.
	 * We keep lifecycle logs only and ignore plugin internal setting churn.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function is_plugin_settings_option( $option ) {
		$option_name = strtolower( sanitize_key( (string) $option ) );
		if ( '' === $option_name ) {
			return false;
		}
		$option_compact = str_replace( '_', '', $option_name );

		$core_prefixes = array(
			'blog',
			'site',
			'home',
			'admin',
			'gmt',
			'date',
			'time',
			'permalink',
			'users',
			'posts',
			'comments',
			'thumbnail',
			'medium',
			'large',
			'widget',
			'sidebars',
			'template',
			'stylesheet',
			'active_plugins',
			'can_compress_scripts',
		);

		foreach ( $core_prefixes as $prefix ) {
			if ( 0 === strpos( $option_name, $prefix ) ) {
				return false;
			}
		}

		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}

		if ( is_multisite() ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network_active ) ) {
				$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
			}
		}

		$slugs = array();
		foreach ( $active_plugins as $plugin_file ) {
			$plugin_file = sanitize_text_field( (string) $plugin_file );
			$parts       = explode( '/', $plugin_file );
			$slug        = isset( $parts[0] ) ? sanitize_key( (string) $parts[0] ) : '';
			if ( '' !== $slug && strlen( $slug ) >= 4 ) {
				$slugs[] = $slug;
			}
		}

		$slugs = array_unique( $slugs );
		foreach ( $slugs as $slug ) {
			$slug_compact = str_replace( '_', '', $slug );
			if ( false !== strpos( $option_name, $slug ) || ( '' !== $slug_compact && false !== strpos( $option_compact, $slug_compact ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Track only high-signal settings; skip plugin-internal/background noise.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function should_track_option_event( $option ) {
		$option_name = strtolower( sanitize_text_field( (string) $option ) );
		if ( '' === $option_name ) {
			return false;
		}

		if ( $this->should_skip_option( $option_name ) ) {
			return false;
		}

		if ( $this->is_plugin_settings_option( $option_name ) ) {
			return false;
		}

		$allow_exact = array(
			'blogname',
			'blogdescription',
			'siteurl',
			'home',
			'admin_email',
			'users_can_register',
			'default_role',
			'permalink_structure',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'blog_public',
			'default_comment_status',
			'comment_moderation',
			'comment_registration',
			'close_comments_for_old_posts',
			'comment_max_links',
			'moderation_keys',
			'blacklist_keys',
		);
		if ( in_array( $option_name, $allow_exact, true ) ) {
			return true;
		}

		$allow_prefixes = array(
			'theme_mods_',
			'stylesheet',
			'template',
			'nav_menu_',
		);
		foreach ( $allow_prefixes as $prefix ) {
			if ( 0 === strpos( $option_name, $prefix ) ) {
				return true;
			}
		}

		// Do not track options whose names look like secrets / credentials.
		if ( $this->is_sensitive_option_name( $option_name ) ) {
			return false;
		}

		return false;
	}

	/**
	 * Whether an option name looks like a secret/credential that must not be
	 * read into the activity log (or even fetched "just in case").
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	private function is_sensitive_option_name( $option_name ) {
		$option_name = strtolower( (string) $option_name );
		$markers     = array(
			'password',
			'passkey',
			'webauthn',
			'2fa',
			'mfa',
			'captcha',
			'firewall',
			'jwt',
			'secret',
			'token',
			'api_key',
			'apikey',
			'license_key',
			'recovery',
			'auth_key',
			'secure_auth',
			'logged_in_key',
			'nonce_key',
			'salt',
		);

		foreach ( $markers as $marker ) {
			if ( false !== strpos( $option_name, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Successful login.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 * @return void
	 */
	public function on_wp_login( $user_login, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$event = array(
			'type'        => 'user_login',
			'label'       => __( 'User logged in', 'whochanged' ),
			'meta'        => array(
				'username'     => sanitize_user( (string) $user_login, true ),
				'display_name' => sanitize_text_field( (string) $user->display_name ),
				'ip'           => $this->get_request_ip(),
			),
			'diff'        => array(),
			'action_type' => 'user_login',
			'object_type' => 'user',
			'object_name' => 'user_' . $user->ID,
		);

		$this->logger->log_event( $event, (int) $user->ID );
	}

	/**
	 * User logged out.
	 *
	 * @param int $user_id User ID (WordPress 5.5+).
	 * @return void
	 */
	public function on_wp_logout( $user_id = 0 ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$user  = get_userdata( $user_id );
		$login = $user instanceof WP_User ? sanitize_user( (string) $user->user_login, true ) : '';
		$name  = $user instanceof WP_User ? sanitize_text_field( (string) $user->display_name ) : '';

		if ( '' === $name ) {
			$name = __( 'User', 'whochanged' );
		}

		$event = array(
			'type'        => 'user_logout',
			'label'       => __( 'User logged out', 'whochanged' ),
			'meta'        => array(
				'username'     => $login,
				'display_name' => $name,
				'ip'           => $this->get_request_ip(),
			),
			'diff'        => array(),
			'action_type' => 'user_logout',
			'object_type' => 'user',
			'object_name' => 'user_' . $user_id,
		);

		$this->logger->log_event( $event, $user_id );
	}

	/**
	 * Failed login attempt (wrong password, unknown user, etc.).
	 *
	 * @param string $username Attempted username or email.
	 * @return void
	 */
	public function on_wp_login_failed( $username ) {
		$username = sanitize_text_field( (string) $username );

		$event = array(
			'type'        => 'login_failed',
			'label'       => __( 'Login failed', 'whochanged' ),
			'meta'        => array(
				'username' => $username,
				'ip'       => $this->get_request_ip(),
			),
			'diff'        => array(),
			'action_type' => 'login_failed',
			'object_type' => 'authentication',
			'object_name' => 'login_attempt_' . wp_generate_uuid4(),
		);

		$this->logger->log_event( $event, 0 );
	}

	/**
	 * Best-effort client IP for audit (may be proxy header).
	 *
	 * @return string
	 */
	private function get_request_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$first = isset( $parts[0] ) ? trim( $parts[0] ) : '';
			if ( '' !== $first ) {
				return $first;
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '';
	}

	/**
	 * Compare values safely for scalars and complex arrays.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 * @return bool
	 */
	private function values_are_equal( $old_value, $new_value ) {
		return WhoChanged_Diff::values_are_equal( $old_value, $new_value );
	}
}
