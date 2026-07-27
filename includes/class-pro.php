<?php
/**
 * Freemius bootstrap + PRO plan gating.
 *
 * HOW TO FINISH THE FREEMIUS INTEGRATION
 * ---------------------------------------
 * 1. Create your product at https://dashboard.freemius.com and copy the
 *    generated "Plugin ID" and "Public Key" from the SDK integration form.
 * 2. Replace the two placeholder constants below (WHOCHANGED_FS_PRODUCT_ID
 *    and WHOCHANGED_FS_PUBLIC_KEY) with the real values.
 * 3. That's it — {@see whochanged_fs()} will automatically start reporting
 *    to Freemius and {@see WhoChanged_Pro::is_active()} will automatically
 *    switch from the legacy license-key fallback to real Freemius licensing.
 *
 * Until the placeholders are filled in, the SDK never boots (no network
 * calls, no admin notices) and PRO status falls back to the legacy
 * `whochanged_pro_license_active` option so the plugin keeps working during
 * development.
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemius product ID for WhoChanged (dashboard.freemius.com).
 */
if ( ! defined( 'WHOCHANGED_FS_PRODUCT_ID' ) ) {
	define( 'WHOCHANGED_FS_PRODUCT_ID', '35452' );
}

/**
 * Freemius SDK public key for WhoChanged.
 */
if ( ! defined( 'WHOCHANGED_FS_PUBLIC_KEY' ) ) {
	define( 'WHOCHANGED_FS_PUBLIC_KEY', 'pk_d88ab214f8e85fa3dd71509ff15fe' );
}

require_once WHOCHANGED_PLUGIN_DIR . 'includes/freemius-bootstrap.php';

/**
 * Resolves the active plan status (PRO vs Free) regardless of whether the
 * Freemius product has been configured yet.
 */
class WhoChanged_Pro {

	/**
	 * Whether PRO features should be unlocked for the current site.
	 *
	 * Resolution order:
	 * 1. `WHOCHANGED_PRO_DEV` constant (local development override).
	 * 2. Freemius plan (once the product is configured; see class-pro.php header).
	 * 3. Legacy manual license option (kept for continuity pre-Freemius).
	 *
	 * @return bool
	 */
	public static function is_active() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		if ( defined( 'WHOCHANGED_PRO_DEV' ) && WHOCHANGED_PRO_DEV ) {
			$cache = true;

			return $cache;
		}

		$fs = whochanged_fs();
		if ( is_object( $fs ) && method_exists( $fs, 'can_use_premium_code' ) ) {
			$cache = (bool) $fs->can_use_premium_code();

			return $cache;
		}

		$cache = 1 === (int) get_option( 'whochanged_pro_license_active', 0 );

		/**
		 * Filters the resolved PRO status. Mainly useful for tests/staging.
		 *
		 * @param bool $is_active Whether PRO is currently active.
		 */
		$cache = (bool) apply_filters( 'whochanged_is_pro_active', $cache );

		return $cache;
	}

	/**
	 * Whether the site is running on the legacy (pre-Freemius) manual license flow.
	 * Used by the settings screen to decide whether to still show the manual
	 * license key field or defer entirely to the Freemius account/billing UI.
	 *
	 * @return bool
	 */
	public static function is_using_legacy_license() {
		$fs = whochanged_fs();

		return ! is_object( $fs );
	}
}
