<?php
/**
 * Overlap detection for the page cache module.
 *
 * Two levels. A blocking reason keeps the module off and disables the toggle,
 * because two page caches on the same site do not degrade gracefully: they
 * serve each other's stale HTML and the ticket that follows is unreadable. A
 * warning explains a real risk but leaves the decision to the site owner,
 * behind an explicit confirmation.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Compat {

	/**
	 * Page cache plugins that cannot coexist with this module.
	 *
	 * Directory name of the plugin, matched against the active plugin list.
	 * Taken from WP Rocket's incompatible-plugins list, plus Surge.
	 *
	 * @return array Slug => display name.
	 */
	public static function get_conflicting_plugins() {
		return array(
			'w3-total-cache'       => 'W3 Total Cache',
			'wp-super-cache'       => 'WP Super Cache',
			'litespeed-cache'      => 'LiteSpeed Cache',
			'wp-fastest-cache'     => 'WP Fastest Cache',
			'cache-enabler'        => 'Cache Enabler',
			'surge'                => 'Surge',
			'wp-rocket'            => 'WP Rocket',
			'hyper-cache'          => 'Hyper Cache',
			'hyper-cache-extended' => 'Hyper Cache Extended',
			'quick-cache'          => 'Quick Cache',
			'comet-cache'          => 'Comet Cache',
			'rapid-cache'          => 'Rapid Cache',
			'lite-cache'           => 'Lite Cache',
			'gator-cache'          => 'Gator Cache',
			'super-static-cache'   => 'Super Static Cache',
			'swift-performance'    => 'Swift Performance',
			'swift-performance-lite' => 'Swift Performance Lite',
			'wp-optimize'          => 'WP-Optimize',
			'speed-booster-pack'   => 'Speed Booster Pack',
			'airlift'              => 'Airlift',
			'flexicache'           => 'FlexiCache',
			'wp-ffpc'              => 'WP-FFPC',
			'wp-fast-cache'        => 'WP Fast Cache',
			'page-optimize'        => 'Page Optimize',
			'psn-pagespeed-ninja'  => 'PageSpeed Ninja',
			'nitropack'            => 'NitroPack',
			'breeze'               => 'Breeze',
			'wpcacheon'            => 'WPCacheOn',
			'powered-cache'        => 'Powered Cache',
			'redis-cache'          => null, // Object cache only: never conflicts.
		);
	}

	/**
	 * Hosting platforms that already serve a page cache of their own.
	 *
	 * Signals lifted from WP Rocket's HostResolver.
	 *
	 * @return string Display name, or empty string when none is detected.
	 */
	public static function detect_host_cache() {
		if ( isset( $_SERVER['KINSTA_CACHE_ZONE'] ) ) {
			return 'Kinsta';
		}
		if ( class_exists( 'WpeCommon' ) || function_exists( 'wpe_param' ) ) {
			return 'WP Engine';
		}
		if ( defined( 'IS_PRESSABLE' ) && IS_PRESSABLE ) {
			return 'Pressable';
		}
		if ( defined( 'WPCOMSH_VERSION' ) ) {
			return 'WordPress.com';
		}
		if ( getenv( 'SPINUPWP_CACHE_PATH' ) ) {
			return 'SpinupWP';
		}
		if ( defined( 'O2SWITCH_VARNISH_PURGE_KEY' ) ) {
			return 'o2switch';
		}
		if ( class_exists( '\WPaas\Plugin' ) ) {
			return 'GoDaddy Managed WordPress';
		}
		if ( defined( 'WP_NINUKIS_WP_NAME' ) || class_exists( 'NinukisCaching' ) ) {
			return 'Pressidium';
		}
		if ( isset( $_SERVER['HTTP_WPXCLOUD'] ) ) {
			return 'WPX Cloud';
		}
		if ( isset( $_SERVER['cw_allowed_ip'] ) ) {
			return 'Cloudways';
		}
		if ( isset( $_SERVER['GROUPONE_BRAND_NAME'] ) ) {
			return 'one.com';
		}

		return '';
	}

	/**
	 * Whether the server runs LiteSpeed, which has a far better native option.
	 *
	 * @return bool
	 */
	public static function is_litespeed() {
		if ( isset( $_SERVER['X-LSCACHE'] ) ) {
			return true;
		}
		if ( ! isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return false;
		}
		$software = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) );
		return false !== strpos( $software, 'litespeed' );
	}

	/**
	 * Active page cache plugins that conflict with this module.
	 *
	 * @return array Display names.
	 */
	public static function get_active_conflicts() {
		$found = array();

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$conflicts = self::get_conflicting_plugins();

		foreach ( $active as $plugin ) {
			$slug = strtok( $plugin, '/' );
			if ( isset( $conflicts[ $slug ] ) && null !== $conflicts[ $slug ] ) {
				$found[] = $conflicts[ $slug ];
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether a foreign advanced-cache.php drop-in is in charge.
	 *
	 * @return bool
	 */
	public static function has_foreign_dropin() {
		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			return false;
		}
		return file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	/**
	 * Reasons the module must not run at all.
	 *
	 * @return array Human readable sentences, empty when the module can run.
	 */
	public static function get_blocking_reasons() {
		$reasons = array();

		if ( is_multisite() ) {
			$reasons['multisite'] = __( 'The page cache does not support multisite yet: one cache directory per site in a network needs a key design this version does not have. It is planned for a later release.', 'wpo-tweaks' );
		}

		$conflicts = self::get_active_conflicts();
		if ( $conflicts ) {
			$reasons['plugins'] = sprintf(
				/* translators: %s: comma separated list of plugin names. */
				__( 'Another page cache plugin is active: %s. Running two page caches on the same site serves stale pages that are very hard to diagnose, so this module stays off. Deactivate the other plugin first, or keep using it.', 'wpo-tweaks' ),
				implode( ', ', $conflicts )
			);
		}

		if ( self::has_foreign_dropin() ) {
			$reasons['dropin'] = __( 'An advanced-cache.php drop-in from another plugin is active with WP_CACHE enabled. That drop-in runs before this module and would win every request.', 'wpo-tweaks' );
		}

		if ( ! Core_Diet_Cache_Store::is_writable() ) {
			$reasons['writable'] = sprintf(
				/* translators: %s: directory path. */
				__( 'The cache directory cannot be created or written to (%s). Check the permissions of wp-content, or ask your host to make it writable.', 'wpo-tweaks' ),
				Core_Diet_Cache_Store::get_root()
			);
		}

		return $reasons;
	}

	/**
	 * Risks worth stating that do not stop the module from running.
	 *
	 * @return array Human readable sentences.
	 */
	public static function get_warnings() {
		$warnings = array();

		$host = self::detect_host_cache();
		if ( $host ) {
			$warnings['host'] = sprintf(
				/* translators: %s: hosting provider name. */
				__( '%s already serves a page cache of its own. Adding a second one can serve outdated content, because purging one does not purge the other. Enable this only if you know your hosting cache is off.', 'wpo-tweaks' ),
				$host
			);
		}

		if ( self::is_litespeed() ) {
			$warnings['litespeed'] = __( 'This server runs LiteSpeed. Its own LiteSpeed Cache plugin caches at server level, which is faster than any PHP cache including this one. We recommend using it instead.', 'wpo-tweaks' );
		}

		return $warnings;
	}
}
