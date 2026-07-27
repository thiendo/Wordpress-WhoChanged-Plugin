<?php
/**
 * Human-readable event mapper.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps normalized events into UI-friendly title/description.
 */
class WhoChanged_Mapper {

	/**
	 * Map event into presentational payload.
	 *
	 * @param array<string, mixed> $event Normalized event payload.
	 * @return array<string, mixed>
	 */
	public static function map( array $event ) {
		$type = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';

		switch ( $type ) {
			case 'theme_changed':
				return self::map_theme_changed( $event );
			case 'theme_upgraded':
				return self::map_theme_upgraded( $event );
			case 'plugin_upgraded':
				return self::map_plugin_upgraded( $event );
			case 'menu_assigned':
			case 'menu_saved':
				return self::map_menu_assigned( $event );
			case 'menu_created':
				return self::map_menu_created( $event );
			case 'menu_updated':
				return self::map_menu_updated( $event );
			case 'menu_deleted':
				return self::map_menu_deleted( $event );
			case 'activated_plugin':
			case 'deactivated_plugin':
			case 'installed_plugin':
			case 'deleted_plugin':
				return self::map_plugin_toggle( $event );
			case 'comment_deleted':
			case 'comment_trashed':
			case 'comment_restored':
			case 'post_deleted':
			case 'post_trashed':
			case 'post_restored':
			case 'page_deleted':
			case 'page_trashed':
			case 'page_restored':
			case 'product_deleted':
			case 'product_trashed':
			case 'product_restored':
			case 'order_deleted':
			case 'order_trashed':
			case 'order_restored':
				return self::map_content_deleted( $event );
			case 'cpt_deleted':
			case 'cpt_trashed':
			case 'cpt_restored':
				return self::map_cpt_content_deleted( $event );
			case 'wordpress_updated':
				return self::map_wordpress_updated( $event );
			case 'customize_save_after':
				return self::map_customizer_saved( $event );
			case 'option_updated':
			case 'option_added':
			case 'option_deleted':
				return self::map_option_updated( $event );
			case 'user_login':
				return self::map_user_login( $event );
			case 'user_logout':
				return self::map_user_logout( $event );
			case 'login_failed':
				return self::map_login_failed( $event );
			default:
				return self::map_unknown_event( $event );
		}
	}

	/**
	 * User logged in.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_user_login( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();

		$ip = isset( $meta['ip'] ) ? sanitize_text_field( (string) $meta['ip'] ) : '';

		// The signed-in user already appears in the User column, so the
		// description only needs to add information not shown elsewhere (IP).
		$description = '' !== $ip
			? sprintf(
				/* translators: %s: IP address */
				__( 'Signed in from IP %s', 'whochanged' ),
				$ip
			)
			: __( 'Signed in successfully.', 'whochanged' );

		return array(
			'title'       => __( '🔑 Logged in', 'whochanged' ),
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * User logged out.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_user_logout( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();

		$ip = isset( $meta['ip'] ) ? sanitize_text_field( (string) $meta['ip'] ) : '';

		// The signed-out user already appears in the User column, so the
		// description only needs to add information not shown elsewhere (IP).
		$description = '' !== $ip
			? sprintf(
				/* translators: %s: IP address */
				__( 'Signed out from IP %s', 'whochanged' ),
				$ip
			)
			: __( 'Signed out.', 'whochanged' );

		return array(
			'title'       => __( '🚪 Logged out', 'whochanged' ),
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * Failed login attempt.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_login_failed( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();

		$attempt = isset( $meta['username'] ) ? sanitize_text_field( (string) $meta['username'] ) : '';
		if ( '' === $attempt ) {
			$attempt = __( '(unknown)', 'whochanged' );
		}

		$ip = isset( $meta['ip'] ) ? sanitize_text_field( (string) $meta['ip'] ) : '';

		$description = sprintf(
			/* translators: %s: attempted username */
			__( 'Failed login attempt for "%s".', 'whochanged' ),
			$attempt
		);
		if ( '' !== $ip ) {
			$description .= ' ' . sprintf(
				/* translators: %s: IP address */
				__( 'IP: %s', 'whochanged' ),
				$ip
			);
		}

		return array(
			'title'       => __( '⚠️ Login failed', 'whochanged' ),
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * Customizer save: never show raw setting keys to site owners.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_customizer_saved( array $event ) {
		$diff  = isset( $event['diff'] ) && is_array( $event['diff'] ) ? $event['diff'] : array();
		$count = self::count_diff_leaves( $diff );

		$description = __( 'Appearance was updated (colors, logo, typography, menus, widgets, and related options).', 'whochanged' );
		if ( $count > 0 ) {
			$description = sprintf(
				/* translators: %d: number of customizer changes */
				_n(
					'Appearance was updated (%d change in the Customizer).',
					'Appearance was updated (%d changes in the Customizer).',
					$count,
					'whochanged'
				),
				$count
			);
		}

		return array(
			'title'       => __( '🎨 Customizer saved', 'whochanged' ),
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * Count leaf before/after pairs in a diff tree.
	 *
	 * @param array<string, mixed> $diff Diff payload.
	 * @return int
	 */
	private static function count_diff_leaves( array $diff ) {
		$n = 0;
		foreach ( $diff as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			if ( array_key_exists( 'before', $change ) || array_key_exists( 'after', $change ) ) {
				++$n;
				continue;
			}
			$n += self::count_diff_leaves( $change );
		}
		return $n;
	}

	/**
	 * Friendly copy for event types we do not map explicitly yet.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_unknown_event( array $event ) {
		$type  = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$label = isset( $event['label'] ) ? sanitize_text_field( (string) $event['label'] ) : '';

		$titles = array(
			'update_option' => __( '⚙️ Setting updated', 'whochanged' ),
			'add_option'    => __( '⚙️ Setting added', 'whochanged' ),
			'delete_option' => __( '⚙️ Setting removed', 'whochanged' ),
		);

		$title = isset( $titles[ $type ] ) ? $titles[ $type ] : __( '📋 Site activity', 'whochanged' );

		$description = __( 'A change was recorded. If this looks unexpected, it may be an automatic background task.', 'whochanged' );
		if ( '' !== $label && str_replace( '_', ' ', $type ) !== $label ) {
			$description = $label;
		}

		return array(
			'title'       => $title,
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * Theme changed.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_theme_changed( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();

		$from = isset( $meta['from_name'] ) ? sanitize_text_field( (string) $meta['from_name'] ) : '';
		$to   = isset( $meta['to_name'] ) ? sanitize_text_field( (string) $meta['to_name'] ) : '';

		if ( '' === $from && isset( $meta['from'] ) ) {
			$from = sanitize_text_field( (string) $meta['from'] );
		}
		if ( '' === $to && isset( $meta['to'] ) ) {
			$to = sanitize_text_field( (string) $meta['to'] );
		}

		return array(
			'title'       => __( '🎨 Theme changed', 'whochanged' ),
			'description' => $from . ' → ' . $to,
			'lines'       => array(),
		);
	}

	/**
	 * Theme upgraded.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_theme_upgraded( array $event ) {
		$meta   = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$name   = isset( $meta['name'] ) ? sanitize_text_field( (string) $meta['name'] ) : '';
		$before = isset( $meta['version_before'] ) ? sanitize_text_field( (string) $meta['version_before'] ) : '';
		$after  = isset( $meta['version_after'] ) ? sanitize_text_field( (string) $meta['version_after'] ) : '';

		return array(
			'title'       => __( '🎨 Theme upgraded', 'whochanged' ),
			'description' => $name . ': v' . $before . ' → v' . $after,
			'lines'       => array(),
		);
	}

	/**
	 * Plugin upgraded.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_plugin_upgraded( array $event ) {
		$meta   = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$name   = isset( $meta['name'] ) ? sanitize_text_field( (string) $meta['name'] ) : '';
		$before = isset( $meta['version_before'] ) ? sanitize_text_field( (string) $meta['version_before'] ) : '';
		$after  = isset( $meta['version_after'] ) ? sanitize_text_field( (string) $meta['version_after'] ) : '';

		return array(
			'title'       => __( '🔌 Plugin upgraded', 'whochanged' ),
			'description' => $name . ': v' . $before . ' → v' . $after,
			'lines'       => array(),
		);
	}

	/**
	 * Menu assigned.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_menu_assigned( array $event ) {
		$meta  = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$lines = array();

		foreach ( $meta as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$location = isset( $change['location_label'] ) ? sanitize_text_field( (string) $change['location_label'] ) : '';
			$from     = isset( $change['from'] ) ? sanitize_text_field( (string) $change['from'] ) : '';
			$to       = isset( $change['to'] ) ? sanitize_text_field( (string) $change['to'] ) : '';
			$lines[]  = $location . ': ' . $from . ' → ' . $to;
		}

		return array(
			'title'       => __( '🧭 Menu updated', 'whochanged' ),
			'description' => implode( ', ', $lines ),
			'lines'       => array(),
		);
	}

	/**
	 * Menu created.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_menu_created( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$name = isset( $meta['menu_name'] ) ? sanitize_text_field( (string) $meta['menu_name'] ) : '';
		return array(
			'title'       => __( '🧭 Menu created', 'whochanged' ),
			'description' => $name,
			'lines'       => array(),
		);
	}

	/**
	 * Menu updated.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_menu_updated( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$name = isset( $meta['menu_name'] ) ? sanitize_text_field( (string) $meta['menu_name'] ) : '';
		return array(
			'title'       => __( '🧭 Menu updated', 'whochanged' ),
			'description' => $name,
			'lines'       => array(),
		);
	}

	/**
	 * Menu deleted.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_menu_deleted( array $event ) {
		$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$name = isset( $meta['menu_name'] ) ? sanitize_text_field( (string) $meta['menu_name'] ) : '';
		return array(
			'title'       => __( '🧭 Menu deleted', 'whochanged' ),
			'description' => $name,
			'lines'       => array(),
		);
	}

	/**
	 * Option updated fallback + special option mappings.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_option_updated( array $event ) {
		$meta       = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$option     = isset( $meta['option'] ) ? sanitize_text_field( (string) $meta['option'] ) : '';
		$diff       = isset( $event['diff'] ) && is_array( $event['diff'] ) ? $event['diff'] : array();
		$event_type = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : 'option_updated';

		if ( 'permalink_structure' === $option ) {
			return array(
				'title'       => __( '🌐 Permalink structure changed', 'whochanged' ),
				'description' => __( 'The way your site URLs are built was updated.', 'whochanged' ),
				'lines'       => array(),
			);
		}

		// Legacy rows / edge cases: never show raw plugin list dumps in UI.
		$core_plugin_opts = array( 'active_plugins', 'recently_activated', 'uninstall_plugins' );
		if ( in_array( $option, $core_plugin_opts, true ) ) {
			return array(
				'title'       => __( 'Plugins list refreshed', 'whochanged' ),
				'description' => __( 'WordPress updated the stored plugins list. Details are omitted here because install/activate actions are logged separately.', 'whochanged' ),
				'lines'       => array(),
			);
		}

		if ( self::is_security_credentials_option( $option ) ) {
			return self::summarize_security_credentials_event( $event_type );
		}

		$site_lines = self::map_site_settings( $diff );
		$diff_rest  = $diff;
		foreach ( array( 'blogname', 'blogdescription' ) as $sk ) {
			unset( $diff_rest[ $sk ] );
		}

		$lines = $site_lines;
		if ( ! empty( $diff_rest ) ) {
			$lines = array_merge( $lines, self::build_diff_lines( $diff_rest, $option ) );
		}

		$lines = self::maybe_collapse_verbose_diff_lines( $lines, $option );

		if ( self::mapped_lines_look_like_secret_details( $lines ) ) {
			return self::summarize_security_credentials_event( $event_type );
		}

		$cat   = self::detect_type( $option );
		$title = __( '⚙️ Site settings updated', 'whochanged' );
		if ( 'option_added' === $event_type ) {
			$title = __( '⚙️ Site setting added', 'whochanged' );
		} elseif ( 'option_deleted' === $event_type ) {
			$title = __( '⚙️ Site setting removed', 'whochanged' );
		}

		if ( 'theme' === $cat ) {
			$title = __( '🎨 Theme appearance updated', 'whochanged' );
			foreach ( $lines as $line ) {
				if ( isset( $line['label'] ) && false !== strpos( strtolower( (string) $line['label'] ), 'background image' ) ) {
					$title = __( '🎨 Background image updated', 'whochanged' );
					break;
				}
			}
		} elseif ( 'menu' === $cat ) {
			$title = __( '🧭 Menu settings updated', 'whochanged' );
		} elseif ( 'woocommerce' === $cat ) {
			if ( 'option_added' === $event_type ) {
				$title = __( '🛒 WooCommerce setting saved', 'whochanged' );
			} elseif ( 'option_deleted' === $event_type ) {
				$title = __( '🛒 WooCommerce setting removed', 'whochanged' );
			} else {
				$title = __( '🛒 WooCommerce settings updated', 'whochanged' );
			}
		}

		$option_pretty = self::get_option_display_label( $option );
		$description   = '' !== $option
			? self::format_option_event_description( $event_type, $option_pretty )
			: __( 'A site setting was changed.', 'whochanged' );

		if ( ! empty( $lines ) ) {
			return array(
				'title'       => $title,
				'description' => $description,
				'lines'       => $lines,
			);
		}

		return array(
			'title'       => $title,
			'description' => '' !== $option ? $description : __( 'A site setting was changed.', 'whochanged' ),
			'lines'       => array(),
		);
	}

	/**
	 * Map site title/tagline diff into human-readable lines.
	 *
	 * @param array<string, mixed> $diff Normalized diff.
	 * @return array<int, array<string, string>>
	 */
	public static function map_site_settings( array $diff ) {
		$lines = array();

		if ( isset( $diff['blogname'] ) && is_array( $diff['blogname'] ) ) {
			$lines[] = array(
				'label' => __( 'Site Title', 'whochanged' ),
				'from'  => self::format_change_value( isset( $diff['blogname']['before'] ) ? $diff['blogname']['before'] : '' ),
				'to'    => self::format_change_value( isset( $diff['blogname']['after'] ) ? $diff['blogname']['after'] : '' ),
			);
		}

		if ( isset( $diff['blogdescription'] ) && is_array( $diff['blogdescription'] ) ) {
			$lines[] = array(
				'label' => __( 'Tagline', 'whochanged' ),
				'from'  => self::format_change_value( isset( $diff['blogdescription']['before'] ) ? $diff['blogdescription']['before'] : '' ),
				'to'    => self::format_change_value( isset( $diff['blogdescription']['after'] ) ? $diff['blogdescription']['after'] : '' ),
			);
		}

		return $lines;
	}

	/**
	 * Convert technical key into human-readable label.
	 *
	 * @param string $key Diff key.
	 * @return string
	 */
	public static function humanize_key( $key ) {
		$key = sanitize_text_field( (string) $key );
		$key = str_replace( array( '_', '-' ), ' ', $key );
		$key = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $key );

		if ( ! is_string( $key ) ) {
			return '';
		}

		return ucwords( trim( $key ) );
	}

	/**
	 * Determine whether a key is useful for UI.
	 *
	 * @param string $key Diff key.
	 * @return bool
	 */
	public static function should_show_key( $key ) {
		$key = strtolower( sanitize_text_field( (string) $key ) );

		if ( is_numeric( $key ) ) {
			return false;
		}

		if ( false !== strpos( $key, '_transient' ) ) {
			return false;
		}

		if ( false !== strpos( $key, '_cache' ) ) {
			return false;
		}

		if ( false !== strpos( $key, '_timeout' ) ) {
			return false;
		}

		if ( false !== strpos( $key, 'auto_updater' ) || false !== strpos( $key, '.lock' ) ) {
			return false;
		}

		// Hide noisy keys from third-party logging plugins.
		if ( false !== strpos( $key, 'simple history total logged events count' ) ) {
			return false;
		}

		if ( false !== strpos( $key, 'wp security audit log' ) || false !== strpos( $key, 'wsal' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether an option name clearly stores credentials / recovery material.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_security_credentials_option( $option ) {
		$o = strtolower( (string) $option );
		if ( '' === $o ) {
			return false;
		}
		$markers = array(
			'recovery_keys',
			'recovery_key',
			'webauthn',
			'passkey',
			'wp_passkeys',
			'application_password',
		);
		foreach ( $markers as $m ) {
			if ( false !== strpos( $o, $m ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Friendly summary instead of raw hashes / key material in the activity UI.
	 *
	 * @param string $event_type option_* type.
	 * @return array<string, mixed>
	 */
	private static function summarize_security_credentials_event( $event_type ) {
		$event_type = sanitize_key( (string) $event_type );
		$title      = __( '🔐 Security keys or login data updated', 'whochanged' );
		if ( 'option_added' === $event_type ) {
			$title = __( '🔐 Security keys or login data saved', 'whochanged' );
		} elseif ( 'option_deleted' === $event_type ) {
			$title = __( '🔐 Security keys or login data removed', 'whochanged' );
		}
		return array(
			'title'       => $title,
			'description' => __( 'WordPress or a security feature updated recovery keys, passkeys, or hashed secrets. That can happen when you sign in with a passkey, renew a session, or a plugin syncs data — often without opening Settings. Raw values are not shown on purpose.', 'whochanged' ),
			'lines'       => array(),
		);
	}

	/**
	 * Detect diff lines that expose password hashes or recovery-key fields by label/value.
	 *
	 * @param array<int, array<string, string>> $lines Built lines.
	 * @return bool
	 */
	private static function mapped_lines_look_like_secret_details( array $lines ) {
		foreach ( $lines as $line ) {
			$label = strtolower( (string) ( isset( $line['label'] ) ? $line['label'] : '' ) );
			$hints = array( 'hashed key', 'hashed_key', 'recovery key', 'recovery_key', 'passkey', 'webauthn', 'credential' );
			foreach ( $hints as $hint ) {
				if ( false !== strpos( $label, $hint ) ) {
					return true;
				}
			}
			foreach ( array( 'to', 'from' ) as $k ) {
				if ( ! isset( $line[ $k ] ) ) {
					continue;
				}
				if ( self::string_looks_like_stored_secret( (string) $line[ $k ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Heuristic: value looks like a password hash or long encoded secret.
	 *
	 * @param string $s Display string.
	 * @return bool
	 */
	private static function string_looks_like_stored_secret( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s || '—' === $s ) {
			return false;
		}
		if ( 0 === strpos( $s, '[' ) ) {
			return false;
		}
		if ( 0 === strpos( $s, '$generic$' ) || 0 === strpos( $s, '$2y$' ) || 0 === strpos( $s, '$2a$' ) || 0 === strpos( $s, '$argon' ) || 0 === strpos( $s, '$P$' ) ) {
			return true;
		}
		$len = strlen( $s );
		if ( $len > 72 && preg_match( '/^[a-zA-Z0-9+\/=\$_-]+$/', $s ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Subtitle for option events (wording matches add/update/remove).
	 *
	 * @param string $event_type option_updated, option_added, or option_deleted.
	 * @param string $option_pretty Human-readable option label.
	 * @return string
	 */
	private static function format_option_event_description( $event_type, $option_pretty ) {
		if ( 'option_added' === $event_type ) {
			return sprintf(
				/* translators: %s: setting name */
				__( 'Saved: %s', 'whochanged' ),
				$option_pretty
			);
		}
		if ( 'option_deleted' === $event_type ) {
			return sprintf(
				/* translators: %s: setting name */
				__( 'Removed: %s', 'whochanged' ),
				$option_pretty
			);
		}
		return sprintf(
			/* translators: %s: setting name */
			__( 'Updated: %s', 'whochanged' ),
			$option_pretty
		);
	}

	/**
	 * Guess a plain-language label from a technical option name (WooCommerce, Action Scheduler, etc.).
	 *
	 * @param string $option Option name.
	 * @return string Empty when no heuristic applies.
	 */
	private static function infer_friendly_option_label( $option ) {
		$option = strtolower( sanitize_text_field( (string) $option ) );
		if ( '' === $option ) {
			return '';
		}

		if ( false !== strpos( $option, 'action_scheduler' ) ) {
			return __( 'Action Scheduler (background tasks)', 'whochanged' );
		}

		if ( false !== strpos( $option, 'ptk' ) && false !== strpos( $option, 'pattern' ) ) {
			return __( 'Block patterns (theme or plugin)', 'whochanged' );
		}

		$is_wc = ( 0 === strpos( $option, 'wc_' ) || false !== strpos( $option, 'woocommerce' ) );
		if ( ! $is_wc ) {
			return '';
		}

		if ( false !== strpos( $option, 'cleanup' ) || false !== strpos( $option, 'anonymize' ) || false !== strpos( $option, 'personal_data' ) || false !== strpos( $option, 'retention' ) || preg_match( '/trash_(pending|failed|cancelled)_orders/', $option ) ) {
			return __( 'WooCommerce: privacy & automated cleanup', 'whochanged' );
		}

		if ( false !== strpos( $option, 'delete_inactive' ) || false !== strpos( $option, 'inactive_account' ) ) {
			return __( 'WooCommerce: inactive accounts', 'whochanged' );
		}

		if ( strlen( $option ) > 40 ) {
			return __( 'WooCommerce: advanced store option', 'whochanged' );
		}

		return '';
	}

	/**
	 * Human label for a wp_option name (never show raw slug when we can avoid it).
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	public static function get_option_display_label( $option ) {
		$option = sanitize_text_field( (string) $option );
		if ( '' === $option ) {
			return __( 'Site setting', 'whochanged' );
		}

		$inferred = self::infer_friendly_option_label( $option );
		if ( '' !== $inferred ) {
			return $inferred;
		}

		$labels = array(
			'blogname'                      => __( 'Site title', 'whochanged' ),
			'blogdescription'               => __( 'Tagline', 'whochanged' ),
			'admin_email'                   => __( 'Administration email', 'whochanged' ),
			'users_can_register'            => __( 'Anyone can register', 'whochanged' ),
			'default_role'                  => __( 'New user default role', 'whochanged' ),
			'timezone_string'               => __( 'Timezone', 'whochanged' ),
			'start_of_week'                 => __( 'Week starts on', 'whochanged' ),
			'date_format'                   => __( 'Date format', 'whochanged' ),
			'time_format'                   => __( 'Time format', 'whochanged' ),
			'blog_public'                   => __( 'Search engine visibility', 'whochanged' ),
			'site_icon'                     => __( 'Site icon', 'whochanged' ),
			'custom_logo'                   => __( 'Site logo', 'whochanged' ),
			'WPLANG'                        => __( 'Site language', 'whochanged' ),
			'permalink_structure'           => __( 'Permalink structure', 'whochanged' ),
			'category_base'                 => __( 'Category URL base', 'whochanged' ),
			'tag_base'                      => __( 'Tag URL base', 'whochanged' ),
			'default_category'              => __( 'Default category', 'whochanged' ),
			'default_post_format'           => __( 'Default post format', 'whochanged' ),
			'posts_per_page'                => __( 'Blog pages show at most', 'whochanged' ),
			'mailserver_url'                => __( 'Mail server', 'whochanged' ),
			'default_pingback_flag'         => __( 'Pingbacks', 'whochanged' ),
			'default_ping_status'           => __( 'Allow pingbacks', 'whochanged' ),
			'default_comment_status'        => __( 'Allow comments on new posts', 'whochanged' ),
			'comments_notify'               => __( 'Comment notification emails', 'whochanged' ),
			'moderation_notify'             => __( 'Comment moderation emails', 'whochanged' ),
			'comment_moderation'            => __( 'Comment moderation', 'whochanged' ),
			'require_name_email'            => __( 'Comment name and email required', 'whochanged' ),
			'comment_registration'          => __( 'Users must be logged in to comment', 'whochanged' ),
			'close_comments_for_old_posts'  => __( 'Close comments on old posts', 'whochanged' ),
			'show_avatars'                  => __( 'Show avatars', 'whochanged' ),
			'thumbnail_size_w'              => __( 'Thumbnail width', 'whochanged' ),
			'thumbnail_size_h'              => __( 'Thumbnail height', 'whochanged' ),
			'medium_size_w'                 => __( 'Medium image width', 'whochanged' ),
			'medium_size_h'                 => __( 'Medium image height', 'whochanged' ),
			'large_size_w'                  => __( 'Large image width', 'whochanged' ),
			'large_size_h'                  => __( 'Large image height', 'whochanged' ),
			'upload_path'                   => __( 'Upload path', 'whochanged' ),
			'upload_url_path'               => __( 'Upload URL path', 'whochanged' ),
			'uploads_use_yearmonth_folders' => __( 'Organize uploads into month/year folders', 'whochanged' ),
		);

		if ( isset( $labels[ $option ] ) ) {
			return $labels[ $option ];
		}

		if ( 0 === strpos( $option, 'theme_mods_' ) ) {
			return __( 'Theme appearance options', 'whochanged' );
		}

		$soft = self::humanize_key( $option );
		if ( strlen( $soft ) > 50 ) {
			$low = strtolower( $option );
			if ( false !== strpos( $low, 'woocommerce' ) || 0 === strpos( $low, 'wc_' ) ) {
				return __( 'WooCommerce: advanced store option', 'whochanged' );
			}
			return __( 'Advanced site setting', 'whochanged' );
		}

		return $soft;
	}

	/**
	 * Replace huge diff line lists with one plain-language row.
	 *
	 * @param array<int, array<string, string>> $lines  Built lines.
	 * @param string                            $option Option name for context.
	 * @return array<int, array<string, string>>
	 */
	private static function maybe_collapse_verbose_diff_lines( array $lines, $option ) {
		$max = 12;
		if ( count( $lines ) <= $max ) {
			return $lines;
		}

		$label = self::get_option_display_label( $option );
		$cat   = self::detect_type( $option );
		$to    = sprintf(
			/* translators: %d: number of sub-changes */
			__( 'Updated (%d details grouped for readability)', 'whochanged' ),
			count( $lines )
		);
		if ( 'woocommerce' === $cat ) {
			$to = sprintf(
				/* translators: %d: number of sub-changes */
				__( 'Many store settings changed (%d details grouped for readability)', 'whochanged' ),
				count( $lines )
			);
		}

		return array(
			array(
				'label' => $label,
				'from'  => '—',
				'to'    => $to,
			),
		);
	}

	/**
	 * Readable label for WooCommerce cleanup / retention task slugs.
	 *
	 * @param string $slug Internal task name.
	 * @return string
	 */
	private static function describe_wc_cleanup_task_slug( $slug ) {
		$slug = sanitize_text_field( (string) $slug );
		$map  = array(
			'trash_pending_orders'       => __( 'Trash old pending orders', 'whochanged' ),
			'trash_failed_orders'        => __( 'Trash old failed orders', 'whochanged' ),
			'trash_cancelled_orders'     => __( 'Trash old cancelled orders', 'whochanged' ),
			'anonymize_refunded_orders'  => __( 'Anonymize refunded orders (privacy)', 'whochanged' ),
			'anonymize_completed_orders' => __( 'Anonymize completed orders (privacy)', 'whochanged' ),
			'delete_inactive_accounts'   => __( 'Delete inactive customer accounts', 'whochanged' ),
		);

		if ( isset( $map[ $slug ] ) ) {
			return $map[ $slug ];
		}

		return self::humanize_key( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Before/after text for WooCommerce task slug rows.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function format_wc_task_scalar( $value ) {
		if ( null === $value || '' === $value ) {
			return '—';
		}
		if ( is_string( $value ) || is_numeric( $value ) ) {
			return self::describe_wc_cleanup_task_slug( (string) $value );
		}

		return self::format_value( $value );
	}

	/**
	 * Format generic value for user-facing output.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function format_value( $value ) {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'whochanged' ) : __( 'No', 'whochanged' );
		}

		if ( is_numeric( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) ) {
			return __( '[complex data]', 'whochanged' );
		}

		$s = sanitize_text_field( (string) $value );
		if ( strlen( $s ) > 600 ) {
			return __( '[large value hidden]', 'whochanged' );
		}
		if ( false !== strpos( $s, 'PluginURI' ) && false !== strpos( $s, 'Version' ) && strlen( $s ) > 200 ) {
			return __( '[plugin metadata hidden]', 'whochanged' );
		}

		return $s;
	}

	/**
	 * Build generic readable diff lines from normalized diff.
	 *
	 * @param array<string, mixed> $diff            Normalized diff.
	 * @param string               $context_option  Parent option name when diff keys are nested under one option.
	 * @return array<int, array<string, string>>
	 */
	public static function build_diff_lines( array $diff, $context_option = '' ) {
		$lines          = array();
		$context_option = sanitize_text_field( (string) $context_option );

		foreach ( $diff as $key => $change ) {
			$raw_key = (string) $key;

			if ( 'value' === $raw_key && '' === $context_option ) {
				continue;
			}

			if ( 'value' === $raw_key && '' !== $context_option ) {
				$label_key = $context_option;
			} else {
				$label_key = $raw_key;
			}

			if ( ! self::should_show_key( $label_key ) ) {
				continue;
			}

			if ( ! is_array( $change ) ) {
				continue;
			}

			$before = isset( $change['before'] ) ? $change['before'] : null;
			$after  = isset( $change['after'] ) ? $change['after'] : null;

			if ( WhoChanged_Diff::values_are_equal( $before, $after ) ) {
				continue;
			}

			$label = self::diff_key_display_label( $raw_key, $context_option );
			if ( '' === $label ) {
				continue;
			}

			$fmt_key = $label_key;
			$from    = self::format_value_by_key( $fmt_key, $before );
			$to      = self::format_value_by_key( $fmt_key, $after );

			if ( preg_match( '/^\d+\.task$/', $raw_key ) ) {
				$slug = '';
				if ( is_string( $after ) && '' !== $after ) {
					$slug = $after;
				} elseif ( is_string( $before ) && '' !== $before ) {
					$slug = $before;
				}
				if ( '' !== $slug ) {
					$label = sprintf(
						/* translators: %s: human-readable task name */
						__( 'Cleanup job: %s', 'whochanged' ),
						self::describe_wc_cleanup_task_slug( $slug )
					);
				}
				$from = self::format_wc_task_scalar( $before );
				$to   = self::format_wc_task_scalar( $after );
			}

			$complex = __( '[complex data]', 'whochanged' );
			if ( $complex === $from && $complex === $to ) {
				$from = '—';
				$to   = __( 'Updated (structured data)', 'whochanged' );
			}

			$lines[] = array(
				'label' => $label,
				'from'  => $from,
				'to'    => $to,
			);
		}

		return $lines;
	}

	/**
	 * Turn a diff key into a short, human label.
	 *
	 * @param string $raw_key         Key from diff array.
	 * @param string $context_option  Parent option when applicable.
	 * @return string
	 */
	private static function diff_key_display_label( $raw_key, $context_option ) {
		$raw_key        = (string) $raw_key;
		$context_option = sanitize_text_field( (string) $context_option );

		if ( 'value' === $raw_key && '' !== $context_option ) {
			return self::get_option_display_label( $context_option );
		}

		if ( '' !== $context_option && $raw_key === $context_option ) {
			return self::get_option_display_label( $context_option );
		}

		if ( '' !== $context_option && 0 === strpos( $raw_key, $context_option . '.' ) ) {
			$tail   = substr( $raw_key, strlen( $context_option ) + 1 );
			$base   = self::get_option_display_label( $context_option );
			$tail_h = self::humanize_key( str_replace( '.', ' ', $tail ) );
			if ( strlen( $tail_h ) > 40 || substr_count( $raw_key, '.' ) > 3 ) {
				return $base;
			}
			return trim( $base . ( '' !== $tail_h ? ' · ' . $tail_h : '' ) );
		}

		if ( strlen( $raw_key ) > 60 || substr_count( $raw_key, '.' ) > 4 ) {
			return __( 'Part of a setting', 'whochanged' );
		}

		if ( preg_match( '/^\d+\.task$/', $raw_key ) ) {
			return __( 'Scheduled cleanup task', 'whochanged' );
		}

		if ( self::should_show_key( $raw_key ) ) {
			return self::humanize_key( str_replace( '.', ' ', $raw_key ) );
		}

		return '';
	}

	/**
	 * Detect setting category from option name.
	 *
	 * @param string $option Option name.
	 * @return string
	 */
	public static function detect_type( $option ) {
		$option = strtolower( sanitize_text_field( (string) $option ) );

		if ( false !== strpos( $option, 'theme_mods' ) ) {
			return 'theme';
		}

		if ( false !== strpos( $option, 'nav_menu' ) ) {
			return 'menu';
		}

		if ( false !== strpos( $option, 'woocommerce' ) || 0 === strpos( $option, 'wc_' ) ) {
			return 'woocommerce';
		}

		return 'settings';
	}

	/**
	 * Format value with key-aware handling (e.g., media IDs).
	 *
	 * @param string $key   Diff key.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	private static function format_value_by_key( $key, $value ) {
		$key_name = strtolower( sanitize_text_field( (string) $key ) );

		if ( self::is_image_related_key( $key_name ) ) {
			$media_value = self::format_image_value( $value );
			if ( '' !== $media_value ) {
				return $media_value;
			}
		}

		if ( in_array( $key_name, array( 'page_on_front', 'page_for_posts' ), true ) ) {
			$page_value = self::format_page_reference_value( $value );
			if ( '' !== $page_value ) {
				return $page_value;
			}
		}

		return self::format_value( $value );
	}

	/**
	 * Resolve a page ID setting (e.g. "Page on front") to its title so admins
	 * don't have to look up the numeric ID themselves.
	 *
	 * @param mixed $value Raw page ID.
	 * @return string
	 */
	private static function format_page_reference_value( $value ) {
		$page_id = absint( $value );
		if ( $page_id <= 0 ) {
			return __( '(none)', 'whochanged' );
		}

		$title = get_the_title( $page_id );
		if ( '' === (string) $title ) {
			return '';
		}

		return sprintf(
			/* translators: 1: page title, 2: page ID */
			__( '%1$s (#%2$d)', 'whochanged' ),
			sanitize_text_field( (string) $title ),
			$page_id
		);
	}

	/**
	 * Whether a diff key likely points to image/media settings.
	 *
	 * @param string $key_name Lower-cased key.
	 * @return bool
	 */
	private static function is_image_related_key( $key_name ) {
		$image_keys = array(
			'site_icon',
			'custom_logo',
			'header_image',
			'background_image',
			'background_image_thumb',
		);

		if ( in_array( $key_name, $image_keys, true ) ) {
			return true;
		}

		return false !== strpos( $key_name, 'image' ) || false !== strpos( $key_name, 'logo' ) || false !== strpos( $key_name, 'icon' );
	}

	/**
	 * Convert image value to readable output; resolve attachment ID to URL when possible.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function format_image_value( $value ) {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return '—';
		}

		if ( is_numeric( $value ) ) {
			$attachment_id = absint( $value );
			if ( $attachment_id > 0 ) {
				$mime_type = get_post_mime_type( $attachment_id );
				if ( is_string( $mime_type ) && 0 === strpos( $mime_type, 'image/' ) ) {
					$url = wp_get_attachment_url( $attachment_id );
					if ( is_string( $url ) && '' !== $url ) {
						return esc_url_raw( $url );
					}
				}
			}
		}

		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $value );
		}

		return '';
	}

	/**
	 * WordPress core updated.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_wordpress_updated( array $event ) {
		$meta   = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$before = isset( $meta['version_before'] ) ? sanitize_text_field( (string) $meta['version_before'] ) : '';
		$after  = isset( $meta['version_after'] ) ? sanitize_text_field( (string) $meta['version_after'] ) : '';
		return array(
			'title'       => __( '⚙️ WordPress updated', 'whochanged' ),
			'description' => $before . ' → ' . $after,
			'lines'       => array(),
		);
	}

	/**
	 * Plugin activate/deactivate mapping.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_plugin_toggle( array $event ) {
		$type        = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$meta        = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$plugin_name = isset( $meta['name'] ) ? sanitize_text_field( (string) $meta['name'] ) : '';

		if ( '' === $plugin_name && isset( $event['object_name'] ) ) {
			$plugin_name = sanitize_text_field( (string) $event['object_name'] );
		}

		$title = __( '🔌 Plugin activated', 'whochanged' );
		if ( 'deactivated_plugin' === $type ) {
			$title = __( '🔌 Plugin deactivated', 'whochanged' );
		} elseif ( 'installed_plugin' === $type ) {
			$title = __( '🔌 Plugin installed', 'whochanged' );
		} elseif ( 'deleted_plugin' === $type ) {
			$title = __( '🔌 Plugin deleted', 'whochanged' );
		}

		// Title already states the action (activated/installed/...), so the
		// line only needs the plugin name — no need to repeat the verb.
		$line = $plugin_name;

		return array(
			'title'       => $title,
			'description' => $line,
			'lines'       => array(),
		);
	}

	/**
	 * Verb phrase for the Changes column (matches event type).
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	private static function content_event_action_phrase( $type ) {
		$type = sanitize_key( (string) $type );
		if ( false !== strpos( $type, '_trashed' ) ) {
			return __( 'moved to trash', 'whochanged' );
		}
		if ( false !== strpos( $type, '_restored' ) ) {
			return __( 'restored', 'whochanged' );
		}
		if ( false !== strpos( $type, '_deleted' ) ) {
			return __( 'deleted', 'whochanged' );
		}
		return '';
	}

	/**
	 * Deleted content mapping.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_content_deleted( array $event ) {
		$type   = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$meta   = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$phrase = self::content_event_action_phrase( $type );

		$title = isset( $meta['title'] ) ? sanitize_text_field( (string) $meta['title'] ) : '';
		if ( '' === $title && isset( $event['object_name'] ) ) {
			$title = sanitize_text_field( (string) $event['object_name'] );
		}

		$activity_title = __( 'Item deleted', 'whochanged' );
		$description    = '';

		if ( false !== strpos( $type, 'comment_' ) ) {
			$preview = isset( $meta['comment_preview'] ) ? sanitize_text_field( (string) $meta['comment_preview'] ) : '';
			if ( '' === $preview ) {
				$preview = isset( $meta['post_title'] ) ? sanitize_text_field( (string) $meta['post_title'] ) : '';
			}
			if ( '' === $preview ) {
				$preview = __( '(no preview)', 'whochanged' );
			}

			if ( 'comment_deleted' === $type ) {
				$activity_title = __( '💬 Comment deleted', 'whochanged' );
			} elseif ( 'comment_trashed' === $type ) {
				$activity_title = __( '💬 Comment moved to trash', 'whochanged' );
			} else {
				$activity_title = __( '💬 Comment restored', 'whochanged' );
			}

			$description = sprintf(
				/* translators: 1: comment preview text, 2: deleted|moved to trash|restored */
				__( 'Comment: %1$s %2$s', 'whochanged' ),
				$preview,
				$phrase
			);
		} elseif ( 0 === strpos( $type, 'post_' ) ) {
			if ( 'post_deleted' === $type ) {
				$activity_title = __( '📝 Post deleted', 'whochanged' );
			} elseif ( 'post_trashed' === $type ) {
				$activity_title = __( '📝 Post moved to trash', 'whochanged' );
			} else {
				$activity_title = __( '📝 Post restored', 'whochanged' );
			}
			$entry       = '' !== $title ? $title : __( '(untitled)', 'whochanged' );
			$description = sprintf(
				/* translators: 1: post title, 2: action phrase */
				__( 'Post: %1$s %2$s', 'whochanged' ),
				$entry,
				$phrase
			);
		} elseif ( 0 === strpos( $type, 'page_' ) ) {
			if ( 'page_deleted' === $type ) {
				$activity_title = __( '📄 Page deleted', 'whochanged' );
			} elseif ( 'page_trashed' === $type ) {
				$activity_title = __( '📄 Page moved to trash', 'whochanged' );
			} else {
				$activity_title = __( '📄 Page restored', 'whochanged' );
			}
			$entry       = '' !== $title ? $title : __( '(untitled)', 'whochanged' );
			$description = sprintf(
				/* translators: %1$s: page title. %2$s: action phrase (e.g. "was deleted"). */
				__( 'Page: %1$s %2$s', 'whochanged' ),
				$entry,
				$phrase
			);
		} elseif ( 0 === strpos( $type, 'product_' ) ) {
			if ( 'product_deleted' === $type ) {
				$activity_title = __( '🏷️ Product deleted', 'whochanged' );
			} elseif ( 'product_trashed' === $type ) {
				$activity_title = __( '🏷️ Product moved to trash', 'whochanged' );
			} else {
				$activity_title = __( '🏷️ Product restored', 'whochanged' );
			}
			$entry       = '' !== $title ? $title : __( '(untitled)', 'whochanged' );
			$description = sprintf(
				/* translators: %1$s: product title. %2$s: action phrase (e.g. "was deleted"). */
				__( 'Product: %1$s %2$s', 'whochanged' ),
				$entry,
				$phrase
			);
		} elseif ( 0 === strpos( $type, 'order_' ) ) {
			if ( 'order_deleted' === $type ) {
				$activity_title = __( '📦 Order deleted', 'whochanged' );
			} elseif ( 'order_trashed' === $type ) {
				$activity_title = __( '📦 Order moved to trash', 'whochanged' );
			} else {
				$activity_title = __( '📦 Order restored', 'whochanged' );
			}
			$entry       = '' !== $title ? $title : __( '(untitled)', 'whochanged' );
			$description = sprintf(
				/* translators: %s: order title. */
				__( 'Order: %s', 'whochanged' ),
				$entry
			);
		}

		if ( '' === $description ) {
			$description = $title;
		}

		return array(
			'title'       => $activity_title,
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * Custom post type content deleted/trashed/restored.
	 *
	 * @param array<string, mixed> $event Event.
	 * @return array<string, mixed>
	 */
	public static function map_cpt_content_deleted( array $event ) {
		$type  = isset( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		$meta  = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
		$title = isset( $meta['title'] ) ? sanitize_text_field( (string) $meta['title'] ) : '';

		if ( '' === $title && isset( $event['object_name'] ) ) {
			$title = sanitize_text_field( (string) $event['object_name'] );
		}

		$pt_label = isset( $meta['post_type_label'] ) ? sanitize_text_field( (string) $meta['post_type_label'] ) : '';
		$pt_slug  = isset( $meta['post_type'] ) ? sanitize_key( (string) $meta['post_type'] ) : '';
		if ( '' === $pt_label ) {
			$pt_label = $pt_slug;
		}
		$is_variation = ( 'product_variation' === $pt_slug ) || ( false !== strpos( strtolower( $pt_label ), 'variation' ) );

		$phrase = self::content_event_action_phrase( $type );

		$activity_title = __( 'Content updated', 'whochanged' );
		if ( 'cpt_deleted' === $type ) {
			$activity_title = sprintf(
				/* translators: %s: post type singular name */
				__( '%s deleted', 'whochanged' ),
				$pt_label
			);
		} elseif ( 'cpt_trashed' === $type ) {
			$activity_title = sprintf(
				/* translators: %s: post type singular name */
				__( '%s moved to trash', 'whochanged' ),
				$pt_label
			);
		} elseif ( 'cpt_restored' === $type ) {
			$activity_title = sprintf(
				/* translators: %s: post type singular name */
				__( '%s restored', 'whochanged' ),
				$pt_label
			);
		}
		if ( $is_variation && '' !== $activity_title ) {
			$activity_title = '🎲 ' . $activity_title;
		}

		$entry       = '' !== $title ? $title : __( '(untitled)', 'whochanged' );
		$description = ( '' !== $pt_label )
			? sprintf(
				/* translators: 1: post type name, 2: entry title, 3: deleted|moved to trash|restored */
				__( '%1$s: %2$s %3$s', 'whochanged' ),
				$pt_label,
				$entry,
				$phrase
			)
			: $entry;

		return array(
			'title'       => $activity_title,
			'description' => $description,
			'lines'       => array(),
		);
	}

	/**
	 * Format a before/after value for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function format_change_value( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return '' === $value ? '—' : $value;
	}
}
