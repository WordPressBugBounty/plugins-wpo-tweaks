<?php
/**
 * Back-compat bridge for the developer filters published by the former
 * "Zero Config Performance Optimization" (wpo-tweaks 2.x) under the
 * ayudawp_wpotweaks_* prefix.
 *
 * Snippets written for 2.x (defer exclusions, critical CSS overrides,
 * resource hint customizations) keep working after the 3.0.0 rename: each
 * legacy filter is applied at an early priority (5) inside its renamed
 * dietpress_* counterpart, so dietpress_* callbacks at default priority can
 * still refine the legacy result. Scheduled for removal in the next major.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the ayudawp_wpotweaks_* to dietpress_* filter bridges.
 *
 * The bridge forwards up to two arguments because three of the legacy
 * filters (skip_defer_script_handles, skip_defer_style_handles,
 * preserve_plugin_version) received a second context argument in 2.x.
 *
 * Since 3.4.0 each bridge reports the deprecation through _deprecated_hook(),
 * but only when something is actually listening: the notice is for the site
 * that still runs a 2.x snippet, and it only surfaces with WP_DEBUG on. The
 * removal in 4.0 is then announced instead of a surprise.
 */
function dietpress_register_legacy_filter_bridges() {
	$suffixes = array(
		'critical_css',
		'critical_css_handles',
		'critical_fonts',
		'dns_prefetch_domains',
		'preconnect_hints',
		'preserve_plugin_version',
		'skip_defer_script_handles',
		'skip_defer_style_handles',
	);

	foreach ( $suffixes as $suffix ) {
		add_filter(
			'dietpress_' . $suffix,
			function ( ...$args ) use ( $suffix ) {
				// One notice per hook and request: some of these filters run
				// once per script handle, and a wall of identical notices helps
				// nobody. Each suffix gets its own closure, so its own static.
				static $reported = false;

				$legacy_hook = 'ayudawp_wpotweaks_' . $suffix;

				if ( ! $reported && has_filter( $legacy_hook ) ) {
					$reported = true;
					// The notice is rendered as HTML when WP_DEBUG_DISPLAY is
					// on, so both hook names are escaped even though they are
					// built from the hardcoded list above.
					_deprecated_hook( esc_html( $legacy_hook ), '3.0.0', esc_html( 'dietpress_' . $suffix ) );
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ayudawp_wpotweaks_ is this plugin's own 2.x prefix, applied for back-compat until 4.0.
				return apply_filters( $legacy_hook, ...$args );
			},
			5,
			2
		);
	}
}
dietpress_register_legacy_filter_bridges();
