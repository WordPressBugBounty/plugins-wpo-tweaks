<?php
/**
 * DietPress uninstall script.
 *
 * Runs when the plugin is deleted. Removes all plugin data, unschedules cron
 * events and cleans up the managed .htaccess block.
 *
 * @package DietPress
 */

// Prevent direct access.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'core_diet_settings' );
delete_option( 'core_diet_version' );

// Page cache module: its own option, its bookkeeping and the whole cache tree.
delete_option( 'core_diet_cache_settings' );
delete_option( 'core_diet_cache_last_gc' );
delete_transient( 'core_diet_cache_auto_disabled' );
wp_clear_scheduled_hook( 'core_diet_cache_gc' );

require_once plugin_dir_path( __FILE__ ) . 'includes/cache/class-core-diet-cache-store.php';

if ( class_exists( 'Core_Diet_Cache_Store' ) ) {
	Core_Diet_Cache_Store::purge_all();

	// purge_all() keeps the root and its hardening files, which have to go too
	// when the plugin is being deleted outright.
	$dietpress_cache_root = Core_Diet_Cache_Store::get_root();
	foreach ( array( '/.htaccess', '/index.php' ) as $dietpress_cache_file ) {
		if ( file_exists( $dietpress_cache_root . $dietpress_cache_file ) ) {
			wp_delete_file( $dietpress_cache_root . $dietpress_cache_file );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions -- Removing the plugin's own now-empty cache directory during uninstall.
	@rmdir( $dietpress_cache_root );
}

// Remove the legacy wpo-tweaks activation flag, if it somehow remains.
delete_option( 'ayudawp_wpotweaks_show_activation_notice' );

// Delete any transients.
delete_transient( 'core_diet_activation_notice' );
delete_transient( 'core_diet_security_removed_notice' );

// Unschedule the transient-cleanup cron event.
wp_clear_scheduled_hook( 'core_diet_clean_transients' );

// Defensive cleanup of the managed .htaccess block. This is normally already
// removed on deactivation (which fires before deletion); reusing the class here
// keeps the WordPress markers + WP_Filesystem handling. WordPress removes the
// whole plugin directory on uninstall, so any obsolete backup/ folder goes with it.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-core-diet-htaccess.php';

if ( class_exists( 'Core_Diet_Htaccess' ) ) {
	$dietpress_htaccess = new Core_Diet_Htaccess( null );
	$dietpress_htaccess->clean_htaccess();
}

// Remove the locally hosted Google Fonts directory from uploads. Also normally
// removed on deactivation; repeated here so deleting the plugin never leaves
// generated files behind. Download-state transients expire on their own.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-core-diet-perf-fonts.php';

if ( class_exists( 'Core_Diet_Perf_Fonts' ) ) {
	$dietpress_fonts = new Core_Diet_Perf_Fonts( null );
	$dietpress_fonts->core_diet_purge_local_fonts();
}
