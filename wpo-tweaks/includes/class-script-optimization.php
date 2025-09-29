<?php
/**
 * Script Optimization Module
 * Handles JavaScript and CSS optimizations
 *
 * @package WPO_Tweaks
 * @since 2.1.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Script_Optimization {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->ayudawp_wpotweaks_init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function ayudawp_wpotweaks_init_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'ayudawp_wpotweaks_optimize_scripts'), 999);
        add_filter('script_loader_tag', array($this, 'ayudawp_wpotweaks_defer_parsing_of_js'), 10, 2);
        add_filter('script_loader_src', array($this, 'ayudawp_wpotweaks_remove_script_version'), 15, 1);
        add_filter('style_loader_src', array($this, 'ayudawp_wpotweaks_remove_script_version'), 15, 1);
        add_filter('style_loader_src', array($this, 'ayudawp_wpotweaks_optimize_google_fonts'), 10, 2);
        add_filter('heartbeat_settings', array($this, 'ayudawp_wpotweaks_control_heartbeat'));
        
        // Remove unnecessary scripts
        add_action('init', array($this, 'ayudawp_wpotweaks_remove_unnecessary_scripts'));
        
        // Remove Dashicons for non-admin users even when logged in
        add_action('wp_enqueue_scripts', array($this, 'ayudawp_wpotweaks_remove_dashicons'), 999);
        
        // Initialize additional hooks for newer WordPress features
        $this->ayudawp_wpotweaks_init_additional_hooks();
    }
    
    /**
     * Initialize additional hooks for newer WordPress features
     */
    private function ayudawp_wpotweaks_init_additional_hooks() {
        // Remove versions from script modules (WordPress 6.5+)
        if (function_exists('wp_script_modules')) {
            add_filter('wp_script_modules_src', array($this, 'ayudawp_wpotweaks_remove_script_version'), 15, 1);
        }
        
        // Remove versions from importmaps
        add_filter('wp_get_script_modules_importmap', array($this, 'ayudawp_wpotweaks_clean_importmap'));
    }
    
    /**
     * Remove Dashicons for non-logged users only - FIXED v2.1.1
     */
    public function ayudawp_wpotweaks_remove_dashicons() {
        // Only remove Dashicons if user is NOT logged in
        // Any logged-in user (regardless of role) needs Dashicons for admin bar
        if (!is_user_logged_in()) {
            wp_dequeue_style('dashicons');
            wp_deregister_style('dashicons');
        }
    }
    
    /**
     * Optimize scripts and styles
     */
    public function ayudawp_wpotweaks_optimize_scripts() {
        // Remove jQuery Migrate if not necessary
        if (!is_admin() && !ayudawp_wpotweaks_is_login_page()) {
            global $wp_scripts;
            if (isset($wp_scripts->registered['jquery'])) {
                $jquery_dependencies = $wp_scripts->registered['jquery']->deps;
                $wp_scripts->registered['jquery']->deps = array_diff($jquery_dependencies, array('jquery-migrate'));
            }
        }
        
        // Remove unnecessary scripts in frontend
        if (!is_admin()) {
            wp_dequeue_script('wp-embed');
            wp_deregister_script('wp-embed');
        }
    }
    
    /**
     * Defer JavaScript parsing
     */
    public function ayudawp_wpotweaks_defer_parsing_of_js($tag, $handle) {
        if (is_admin()) {
            return $tag;
        }
        
        // Exclude critical scripts from defer
        $excluded_handles = array(
            'jquery',
            'jquery-core',
            'jquery-migrate',
            'customize-support'
        );
        
        if (in_array($handle, $excluded_handles)) {
            return $tag;
        }
        
        // Don't defer inline scripts
        if (strpos($tag, 'src=') === false) {
            return $tag;
        }
        
        // Don't defer scripts that are already async
        if (strpos($tag, 'async') !== false) {
            return $tag;
        }
        
        // Check user agent for IE9 compatibility
        $user_agent = ayudawp_wpotweaks_get_user_agent();
        if (!empty($user_agent) && strpos($user_agent, 'MSIE 9.') !== false) {
            return $tag;
        }
        
        // Add defer attribute
        return str_replace(' src', ' defer src', $tag);
    }
    
    /**
     * Remove version strings from scripts and styles - FIXED VERSION
     */
    public function ayudawp_wpotweaks_remove_script_version($src) {
        if (is_admin()) {
            return $src;
        }
        
        // Keep versions for critical scripts that need them
        $keep_versions = array(
            'jquery',
            'jquery-core',
            'jquery-migrate'
        );
        
        foreach ($keep_versions as $script) {
            if (strpos($src, $script) !== false) {
                return $src;
            }
        }
        
        // Remove version parameters
        $src = remove_query_arg('ver', $src);
        
        // Remove other version-like parameters
        $patterns = array('/\?ver=[^&]*/', '/&ver=[^&]*/', '/\?v=[^&]*/', '/&v=[^&]*/');
        $src = preg_replace($patterns, '', $src);
        
        return $src;
    }
    
    /**
     * Optimize Google Fonts
     */
    public function ayudawp_wpotweaks_optimize_google_fonts($src, $handle) {
        if (strpos($src, 'fonts.googleapis.com') !== false) {
            // Replace display=fallback with display=swap
            if (strpos($src, 'display=fallback') !== false) {
                $src = str_replace('display=fallback', 'display=swap', $src);
            } elseif (strpos($src, 'display=') === false) {
                // Add display=swap if no display parameter exists
                $src = add_query_arg('display', 'swap', $src);
            }
        }
        
        return $src;
    }
    
    /**
     * Control Heartbeat API
     */
    public function ayudawp_wpotweaks_control_heartbeat($settings) {
        $settings['interval'] = 60;
        return $settings;
    }
    
    /**
     * Remove unnecessary scripts and actions
     */
    public function ayudawp_wpotweaks_remove_unnecessary_scripts() {
        // Remove emoji scripts and styles
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        
        // Remove Capital P Dangit filter
        remove_filter('the_title', 'capital_P_dangit', 11);
        remove_filter('the_content', 'capital_P_dangit', 11);
        remove_filter('comment_text', 'capital_P_dangit', 31);
        
        // Disable JSON/REST API if not needed
        add_filter('json_enabled', '__return_false');
        add_filter('json_jsonp_enabled', '__return_false');
    }
    
    /**
     * Clean importmap URLs (WordPress 6.5+) - FIXED LOGIC
     */
    public function ayudawp_wpotweaks_clean_importmap($importmap) {
        // Don't modify in admin area
        if (is_admin() || !is_array($importmap) || !isset($importmap['imports'])) {
            return $importmap;
        }
        
        foreach ($importmap['imports'] as $key => $url) {
            $importmap['imports'][$key] = $this->ayudawp_wpotweaks_remove_script_version($url);
        }
        
        return $importmap;
    }
}