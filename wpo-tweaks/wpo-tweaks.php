<?php
/**
 * Plugin Name:       DietPress
 * Plugin URI:        https://servicios.ayudawp.com
 * Description:       Put your WordPress on a diet and speed it up. Disable unnecessary core features and enable performance optimizations, fully configurable.
 * Version:           3.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Fernando Tellado
 * Author URI:        https://ayudawp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpo-tweaks
 *
 * Note: the plugin slug and text domain remain "wpo-tweaks" to preserve the
 * existing WordPress.org listing, install base and translations. The public
 * brand is "DietPress" (formerly the "core-diet" codebase merged in here).
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Conflict guard for the legacy standalone DietPress (the retired "core-diet"
 * plugin). Both codebases share the Core_Diet_* class names, so loading both in
 * the same request would fatal on a duplicate class declaration.
 *
 * Two constraints shape this guard, both caused by PHP binding unconditional
 * top-level functions at include time, BEFORE any statement (guards included)
 * runs:
 *
 * 1. No function declared in this file may reuse a core_diet_* name: the clash
 *    would fatal before this guard could ever execute. Hence the dietpress_
 *    prefix below.
 * 2. The check must be class_exists() only, without autoload. The legacy 1.0.4
 *    plugin leaves its top-level core_diet_*() functions declared even when its
 *    own transition guard stands down, so function_exists() would report it as
 *    "running" when it has already stepped aside. Its class is only declared
 *    when it really runs.
 *
 * When the legacy plugin is the one running, stand down for this request,
 * deactivate it and let the next request load DietPress normally.
 */
if ( class_exists( 'Core_Diet', false ) ) {
	add_action(
		'admin_init',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			deactivate_plugins( 'core-diet/core-diet.php' );
		}
	);
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'DietPress detected the older standalone "core-diet" plugin and has deactivated it: they share the same code and cannot run together. DietPress already includes all of its features and your settings are kept, so you can safely delete the "core-diet" plugin.', 'wpo-tweaks' );
			echo '</p></div>';
		}
	);
	return;
}

// Plugin constants.
define( 'CORE_DIET_VERSION', '3.2.0' );
define( 'CORE_DIET_FILE', __FILE__ );
define( 'CORE_DIET_DIR', plugin_dir_path( __FILE__ ) );
define( 'CORE_DIET_URL', plugin_dir_url( __FILE__ ) );
define( 'CORE_DIET_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check minimum requirements.
 *
 * @return bool True if requirements are met.
 */
function dietpress_meets_requirements() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		return false;
	}
	if ( version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {
		return false;
	}
	return true;
}

if ( ! dietpress_meets_requirements() ) {
	add_action( 'admin_notices', 'dietpress_requirements_notice' );
	return;
}

/**
 * Display requirements notice.
 */
function dietpress_requirements_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'DietPress requires PHP 7.4+ and WordPress 6.3+.', 'wpo-tweaks' );
	echo '</p></div>';
}

/**
 * Get the current request user agent, sanitized.
 *
 * Ported from the former wpo-tweaks codebase. Used by the script defer logic.
 *
 * @return string
 */
function dietpress_get_user_agent() {
	if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return '';
	}
	return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
}

// Load the plugin.
require_once CORE_DIET_DIR . 'includes/class-core-diet.php';

// Lifecycle hooks (must be in the main file).
register_activation_hook( CORE_DIET_FILE, array( 'Core_Diet', 'activate' ) );
register_deactivation_hook( CORE_DIET_FILE, array( 'Core_Diet', 'deactivate' ) );

// Initialize.
Core_Diet::get_instance();
