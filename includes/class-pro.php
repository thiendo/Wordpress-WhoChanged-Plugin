<?php
/**
 * Freemius bootstrap + PRO gating — FREE / WordPress.org package overlay.
 *
 * Differences from production source:
 * - No WHOCHANGED_PRO_DEV unlock path.
 * - No legacy whochanged_pro_license_active unlock.
 * - PRO is unlocked only via Freemius can_use_premium_code().
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WHOCHANGED_FS_PRODUCT_ID' ) ) {
	define( 'WHOCHANGED_FS_PRODUCT_ID', '35452' );
}

if ( ! defined( 'WHOCHANGED_FS_PUBLIC_KEY' ) ) {
	define( 'WHOCHANGED_FS_PUBLIC_KEY', 'pk_d88ab214f8e85fa3dd71509ff15fe' );
}

require_once WHOCHANGED_PLUGIN_DIR . 'includes/freemius-bootstrap.php';

/**
 * Resolves PRO vs Free for the WordPress.org Free package.
 */
class WhoChanged_Pro {

	/**
	 * Whether PRO features should be unlocked.
	 *
	 * Free package: Freemius license only (no DEV constant, no legacy option).
	 *
	 * @return bool
	 */
	public static function is_active() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$fs = whochanged_fs();
		if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) ) {
			$cache = (bool) $fs->can_use_premium_code();

			return $cache;
		}

		$cache = false;

		return $cache;
	}

	/**
	 * Free package always uses Freemius account/billing UI (no legacy key field).
	 *
	 * @return bool
	 */
	public static function is_using_legacy_license() {
		return false;
	}
}
