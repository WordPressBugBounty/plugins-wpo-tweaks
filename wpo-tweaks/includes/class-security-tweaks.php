<?php
/**
 * Security Tweaks Module
 * Handles basic security improvements
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Security_Tweaks {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->ayudawp_wpotweaks_init_hooks();
    }
    
    /**
     * Initialize hooks - FOR ALL USERS
     */
    private function ayudawp_wpotweaks_init_hooks() {
        // Apply security tweaks to ALL users
        add_action('init', array($this, 'ayudawp_wpotweaks_security_tweaks'));
        add_action('after_setup_theme', array($this, 'ayudawp_wpotweaks_clean_header'));
        add_action('pre_ping', array($this, 'ayudawp_wpotweaks_no_self_ping'));
        add_filter('get_avatar_url', array($this, 'ayudawp_wpotweaks_avatar_remove_querystring'));
    }
    
    /**
     * Apply security tweaks - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_security_tweaks() {
        // Remove version information - FOR ALL USERS
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');
        
        // Hide login errors - User enumeration vulnerability - FOR ALL USERS
        add_filter('login_errors', function() {
            return __('Incorrect login information.', 'wpo-tweaks');
        });
        
        // Remove X-Pingback header - FOR ALL USERS
        add_filter('wp_headers', function($headers) {
            unset($headers['X-Pingback']);
            return $headers;
        });
        
        // Disable XML-RPC if not needed - FOR ALL USERS
        if (!ayudawp_wpotweaks_needs_xmlrpc()) {
            add_filter('xmlrpc_enabled', '__return_false');
        }
        
        // Disable pingbacks via XML-RPC - FOR ALL USERS
        add_filter('xmlrpc_methods', function($methods) {
            unset($methods['pingback.ping']);
            unset($methods['pingback.extensions.getPingbacks']);
            return $methods;
        });
    }
    
    /**
     * Clean WordPress header - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_clean_header() {
        // Remove unnecessary links - FOR ALL USERS
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wp_resource_hints', 2);
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0);
        remove_action('wp_head', 'index_rel_link');
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
        remove_action('wp_head', 'adjacent_posts_rel_link', 10, 0);
        remove_action('wp_head', 'start_post_rel_link', 10, 0);
        remove_action('wp_head', 'parent_post_rel_link', 10, 0);
        
        // Clean feeds only if not needed for SEO - FOR ALL USERS
        if (!ayudawp_wpotweaks_needs_feeds()) {
            remove_action('wp_head', 'feed_links', 2);
        }
        
        add_filter('the_generator', '__return_false');
    }
    
    /**
     * Disable self pingbacks - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_no_self_ping(&$links) {
        $home = get_option('home');
        foreach ($links as $l => $link) {
            if (0 === strpos($link, $home)) {
                unset($links[$l]);
            }
        }
    }
    
    /**
     * Remove query strings from Gravatar - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_avatar_remove_querystring($url) {
        $url_parts = explode('?', $url);
        return $url_parts[0];
    }
}