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

// Remove the legacy wpo-tweaks activation flag, if it somehow remains.
delete_option( 'ayudawp_wpotweaks_show_activation_notice' );

// Delete any transients.
delete_transient( 'core_diet_third_party_widgets' );
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
