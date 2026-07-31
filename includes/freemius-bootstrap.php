<?php
/**
 * Freemius SDK bootstrap — FREE / WordPress.org package overlay.
 *
 * is_premium is false for the org Free build. wp_org_gatekeeper is omitted
 * (Freemius docs: remove it from the free version before uploading to wp.org).
 *
 * @package WhoChanged
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'whochanged_fs' ) ) {
	/**
	 * Freemius SDK singleton accessor.
	 *
	 * @return Freemius|null
	 */
	function whochanged_fs() {
		global $whochanged_fs;

		if ( isset( $whochanged_fs ) ) {
			return $whochanged_fs;
		}

		if ( '' === WHOCHANGED_FS_PRODUCT_ID || '' === WHOCHANGED_FS_PUBLIC_KEY ) {
			$whochanged_fs = null;

			return $whochanged_fs;
		}

		$sdk_start = WHOCHANGED_PLUGIN_DIR . 'vendor/freemius/wordpress-sdk/start.php';
		if ( ! is_readable( $sdk_start ) ) {
			$whochanged_fs = null;

			return $whochanged_fs;
		}

		require_once $sdk_start;

		$whochanged_fs = fs_dynamic_init(
			array(
				'id'                => WHOCHANGED_FS_PRODUCT_ID,
				'slug'              => 'whochanged',
				'type'              => 'plugin',
				'public_key'        => WHOCHANGED_FS_PUBLIC_KEY,
				// Free / WordPress.org package — premium code unlocked only after
				// Freemius license activation (Upgrade / Account).
				'is_premium'        => false,
				'premium_suffix'    => 'PRO',
				'has_addons'        => false,
				'has_paid_plans'    => true,
				'has_premium_version' => true,
				'is_org_compliant'  => true,
				'menu'              => array(
					'slug'        => 'whochanged',
					'account'     => true,
					'pricing'     => true,
					'support'     => false,
					'contact'     => false,
					'affiliation' => false,
				),
				'is_live'           => true,
			)
		);

		return $whochanged_fs;
	}

	whochanged_fs();
	do_action( 'whochanged_fs_loaded' );
}
