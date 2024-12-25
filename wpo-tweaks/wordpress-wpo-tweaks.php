<?php
/**
 * Plugin Name: Performance Optimizations & WPO Tweaks
 * Plugin URI: https://servicios.ayudawp.com/
 * Description: Several WordPress Performance Optimizations for WPO to save hosting resources, Speed Up WordPress and get better results in Google PageSpeed, GTMetrix, Pingdom Tools & WebPageTest
 * Version: 1.0.7
 * Author: Fernando Tellado
 * Author URI: https://tellado.es/
 *
 * @package Nombre del plugin
 * License: GPL2+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wpo-tweaks
 *
 * WordPress WPO is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * WordPress WPO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with WordPress WPO. If not, see https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || die( 'No script kiddies please!' );

/* INIT FOR TRANSLATION READY */
function wpo_tweaks_init() {
	load_plugin_textdomain( 'wpo-tweaks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wpo_tweaks_init' );

/* DISABLE SELF PINGBACKS */
function wpo_tweaks_no_self_ping( &$links ) {

$home = get_option( 'home' );

foreach ( $links as $l => $link )

  if ( 0 === strpos( $link, $home ) )

    unset($links[$l]);
}

add_action( 'pre_ping', 'wpo_tweaks_no_self_ping' );

/** ADMIN FOOTER TEXT **/
function wpo_tweaks_change_admin_footer_text( $text ) {
	return sprintf( __( 'Powered by <a target="_blank" href="https://wordpress.org/">WordPress</a> | Optimized with <a href="%s" title="WordPress WPO Tweaks by Fernando Tellado" target="_blank">WPO Tweaks</a>', 'wpo-tweaks'  ), 'https://wordpress.org/plugins/wpo-tweaks/' );
}
add_filter( 'admin_footer_text', 'wpo_tweaks_change_admin_footer_text' );

/** REMOVE DASHICONS FROM ADMIN BAR FOR NON LOGGED IN USERS **/
add_action( 'wp_print_styles', function() {
if ( ! is_admin_bar_showing() && ! is_customize_preview() ) {
  wp_deregister_style( 'dashicons' );
}
}, 100);

/** DISABLE REST API **/
add_filter('json_enabled', '__return_false');
add_filter('json_jsonp_enabled', '__return_false');

/** CONTROL HEARTBEAT API **/
function wpo_tweaks_control_heartbeat( $settings ) {
    $settings['interval'] = 60;
    return $settings;
}
add_filter( 'heartbeat_settings', 'wpo_tweaks_control_heartbeat' );

/** REMOVE QUERIES FROM STATIC RESOURCES **/
function wpo_tweaks_remove_script_version( $src ) {
	$parts = explode( '?ver', $src );

	return $parts[0];
}
add_filter( 'script_loader_src', 'wpo_tweaks_remove_script_version', 15, 1 );
add_filter( 'style_loader_src', 'wpo_tweaks_remove_script_version', 15, 1 );

/** REMOVE GRAVATAR QUERY STRINGS **/
function wpo_tweaks_avatar_remove_querystring( $url ) {
	$url_parts = explode( '?', $url );
	return $url_parts[0];
}
add_filter( 'get_avatar_url', 'wpo_tweaks_avatar_remove_querystring' );

/** REMOVE CAPITAL P DANGIT **/
remove_filter( 'the_title', 'capital_P_dangit', 11 );
remove_filter( 'the_content', 'capital_P_dangit', 11 );
remove_filter( 'comment_text', 'capital_P_dangit', 31 );

/** DISABLE PDF THUMBNAILS PREVIEW **/
function wpo_tweaks_disable_pdf_previews() {
$fallbacksizes = array();
return $fallbacksizes;
}
add_filter('fallback_intermediate_image_sizes', 'wpo_tweaks_disable_pdf_previews');

/**
 * Header items cleaning.
 *
 * @return void
 *
 * @since 0.9.2/3
 */
function wpo_tweaks_clean_header() {
	remove_action( 'wp_head', 'wp_generator' ); // REMOVE WORDPRESS GENERATOR VERSION.
	remove_action( 'wp_head', 'wp_resource_hints', 2 ); // REMOVE S.W.ORG DNS-PREFETCH.
	remove_action( 'wp_head', 'wlwmanifest_link' ); // REMOVE wlwmanifest.xml.
	remove_action( 'wp_head', 'rsd_link' ); // REMOVE REALLY SIMPLE DISCOVERY LINK.
	remove_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 ); // REMOVE SHORTLINK URL.
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 ); // REMOVE EMOJI'S STYLES AND SCRIPTS.
	remove_action( 'wp_print_styles', 'print_emoji_styles' ); // REMOVE EMOJI'S STYLES AND SCRIPTS.
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' ); // REMOVE EMOJI'S STYLES AND SCRIPTS.
	remove_action( 'admin_print_styles', 'print_emoji_styles' ); // REMOVE EMOJI'S STYLES AND SCRIPTS.
    remove_action( 'wp_head', 'index_rel_link' ); // REMOVE LINK TO HOME PAGE.
	remove_action( 'wp_head', 'feed_links_extra', 3 ); // REMOVE EVERY EXTRA LINKS TO RSS FEEDS.
	remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 ); // REMOVE PREV-NEXT LINKS FROM HEADER -NOT FROM POST-.
	remove_action( 'wp_head', 'adjacent_posts_rel_link', 10, 0 ); // REMOVE PREV-NEXT LINKS.
	remove_action( 'wp_head', 'start_post_rel_link', 10, 0 ); // REMOVE RANDOM LINK POST.
	remove_action( 'wp_head', 'parent_post_rel_link', 10, 0 ); // REMOVE PARENT POST LINK.

	add_filter( 'the_generator', '__return_false' ); // REMOVE GENERATOR NAME FROM RSS FEEDS.
}
add_action( 'after_setup_theme', 'wpo_tweaks_clean_header' );

/** SECURE METHOD FOR DEFER PARSING OF JAVASCRIPT MOVING ALL JS FROM HEADER TO FOOTER **/
function wpo_defer_parsing_of_js($tag, $handle) {
    if (is_admin()){
        return $tag;
    }
    if (strpos($tag, '/wp-includes/js/jquery/jquery')) {
        return $tag;
    }
    if ((!empty($_SERVER['HTTP_USER_AGENT'])) && (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 9.') !==false)) {
		return $tag;
	} else {
		return str_replace(' src',' defer src', $tag);
	}
}
add_filter('script_loader_tag', 'wpo_defer_parsing_of_js',10,2);

/** BROWSER CACHE EXPIRES & GZIP COMPRESSION **/
function wpo_tweaks_htaccess() {
	// We get the main WordPress .htaccess filepath.
	$ruta_htaccess = get_home_path() . '.htaccess'; // https://codex.wordpress.org/Function_Reference/get_home_path !

	$lineas = array();
    $lineas[] = '<IfModule mod_expires.c>';
	$lineas[] = '# Activar caducidad de contenido';
	$lineas[] = 'ExpiresActive On';
	$lineas[] = '# Directiva de caducidad por defecto';
	$lineas[] = 'ExpiresDefault "access plus 1 month"';
	$lineas[] = '# Para el favicon';
	$lineas[] = 'ExpiresByType image/x-icon "access plus 1 year"';
	$lineas[] = '# Imagenes';
	$lineas[] = 'ExpiresByType image/gif "access plus 1 month"';
	$lineas[] = 'ExpiresByType image/png "access plus 1 month"';
	$lineas[] = 'ExpiresByType image/jpg "access plus 1 month"';
	$lineas[] = 'ExpiresByType image/jpeg "access plus 1 month"';
	$lineas[] = '# CSS';
	$lineas[] = 'ExpiresByType text/css "access 1 month"';
	$lineas[] = '# Javascript';
	$lineas[] = 'ExpiresByType application/javascript "access plus 1 year"';
    $lineas[] = '</IfModule>';
    $lineas[] = '<IfModule mod_deflate.c>';
    $lineas[] = '# Activar compresión de contenidos estáticos';
	$lineas[] = 'AddOutputFilterByType DEFLATE text/plain text/html';
	$lineas[] = 'AddOutputFilterByType DEFLATE text/xml application/xml application/xhtml+xml application/xml-dtd';
	$lineas[] = 'AddOutputFilterByType DEFLATE application/rdf+xml application/rss+xml application/atom+xml image/svg+xml';
	$lineas[] = 'AddOutputFilterByType DEFLATE text/css text/javascript application/javascript application/x-javascript';
	$lineas[] = 'AddOutputFilterByType DEFLATE font/otf font/opentype application/font-otf application/x-font-otf';
	$lineas[] = 'AddOutputFilterByType DEFLATE font/ttf font/truetype application/font-ttf application/x-font-ttf';
    $lineas[] = '</IfModule>';

	insert_with_markers( $ruta_htaccess, 'WordPress WPO Tweaks by Fernando Tellado', $lineas ); // https://developer.wordpress.org/reference/functions/insert_with_markers/ !
}

function wpo_delete_tweaks_htaccess() {
	// We get the mail WordPress .htaccess filepath.
	$ruta_htaccess = get_home_path() . '.htaccess'; // https://codex.wordpress.org/Function_Reference/get_home_path !

	$lineas = array();

	$lineas[] = '# Optimizaciones eliminadas al desactivar el plugin';

	insert_with_markers( $ruta_htaccess, 'WordPress Performance Optimizations by Fernando Tellado', $lineas ); // https://developer.wordpress.org/reference/functions/insert_with_markers/ !
}
/**
 * We run the function that ckecks here
 * if there are $lineas content between:
 * # BEGIN WordPress WPO Tweaks by Fernando Tellado
 * and...
 * # END WordPress WPO Tweaks by Fernando Tellado
 * If exist and it's the same we don't do anything,
 * if has changed, we update it to the new one
 * if it doesn't exist we write it.
 */
register_activation_hook( __FILE__, 'wpo_tweaks_htaccess' );
register_deactivation_hook( __FILE__, 'wpo_delete_tweaks_htaccess' );