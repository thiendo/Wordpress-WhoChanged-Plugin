<?php
/**
 * Freemius bootstrap + PRO gating — FREE / WordPress.org package overlay.
 *
 * Differences from production source:
 * - No WHOCHANGED_PRO_DEV unlock path.
 * - No legacy whochanged_pro_license_active unlock.
 * - is_active() always false: this package does not ship includes/pro/.
 * - Freemius SDK remains for Account / Upgrade / checkout UI only.
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
	 * The WordPress.org Free package never unlocks local PRO features — it
	 * doesn't even ship the includes/pro/ implementations (see
	 * {@see ships_premium_modules()} and playground/build-free-zip.sh), so
	 * there is nothing for a license to unlock here. PRO functionality is
	 * only available via the separate Freemius PRO download. The Freemius
	 * SDK is still loaded (see freemius-bootstrap.php) purely for account/
	 * upgrade UI (checkout, "Manage Account", etc.), not to gate local code.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return false;
	}

	/**
	 * Free package always uses Freemius account/billing UI (no legacy key field).
	 *
	 * @return bool
	 */
	public static function is_using_legacy_license() {
		return false;
	}

	/**
	 * The Free package never ships includes/pro/ (excluded by
	 * playground/build-free-zip.sh), so this always resolves to false.
	 * Kept as a real filesystem check (rather than a hardcoded `false`) so
	 * behavior stays identical to production if that ever changes.
	 *
	 * @return bool
	 */
	public static function ships_premium_modules() {
		return is_readable( WHOCHANGED_PLUGIN_DIR . 'includes/pro/load.php' );
	}

	/**
	 * @return string
	 */
	public static function get_upgrade_url() {
		$fs = whochanged_fs();
		if ( is_object( $fs ) && method_exists( $fs, 'get_upgrade_url' ) ) {
			$url = $fs->get_upgrade_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return 'https://douple.net/whochanged/#pricing';
	}
}
