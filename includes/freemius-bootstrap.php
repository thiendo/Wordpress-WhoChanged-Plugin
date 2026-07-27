<?php
/**
 * Freemius SDK bootstrap.
 *
 * Kept in its own file (separate from class-pro.php) because a single PHP
 * file must not mix loose function declarations with OO declarations under
 * WordPress-Extra/Universal.Files.SeparateFunctionsFromOO.
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
	 * Returns null when the product has not been configured yet (see the
	 * placeholders in class-pro.php), so the rest of the plugin must always
	 * guard calls with `is_object( whochanged_fs() )`.
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
				// WhoChanged ships ONE codebase to every user (Free and PRO alike);
				// PRO features are unlocked at runtime via WhoChanged_Pro::is_active(),
				// not by shipping a separate stripped-down package. That's exactly
				// the scenario Freemius' own SDK defaults `is_premium` to `true`
				// for (see Freemius::init()) — it only needs to be `false` for
				// plugins that generate a literal, separate "free" build with the
				// premium files removed. Setting this to `true` is what makes the
				// "Activate License" button appear on the Account page for anyone
				// who bought PRO but hasn't entered their key yet.
				'is_premium'        => true,
				'premium_suffix'    => 'PRO',
				'has_addons'        => false,
				'has_paid_plans'    => true,
				// Marks the plugin as WordPress.org-compliant (free version listed
				// on wp.org). Freemius auto-strips premium-only code from the build
				// it deploys there.
				'is_org_compliant'  => true,
				// Security token tying this build to the auto-generated free version
				// on wp.org. Freemius removes this line automatically from the free
				// build; keep it only in the premium/source code.
				'wp_org_gatekeeper' => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
				// No 'parent' key: Freemius attaches Account/Upgrade as submenus
				// of WhoChanged's own top-level menu (same slug), matching how
				// other Freemius-connected plugins on this site are set up.
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
