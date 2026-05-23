<?php
/**
 * Script Optimization Module
 * Handles JavaScript and CSS optimizations
 *
 * @package Zero_Config_Performance
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Script_Optimization {
    
    /**
     * Memoization cache for the defer eligibility check.
     * Scoped per request to avoid recomputing the same handle multiple times
     * across filter calls for different scripts.
     *
     * @since 2.3.1
     * @var array
     */
    private $defer_eligibility_cache = array();
    
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
     * Remove Dashicons for non-logged users only
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
     * 
     * @since 2.1.3
     * @since 2.3.1 Skip scripts whose dependency chain requires immediate execution (inline "after" code, translations, or transitive dependents with either). Prevents "wp is not defined" and related ReferenceError failures caused by partially-deferred dependency chains.
     */
    public function ayudawp_wpotweaks_defer_parsing_of_js($tag, $handle) {
        if (is_admin()) {
            return $tag;
        }
        
        // Don't defer anything when Divi builder is active
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for page builder detection, no form processing
        if (isset($_GET['et_fb']) && !empty($_GET['et_fb'])) {
            return $tag;
        }
        
        // Exclude critical scripts from defer
        $excluded_handles = array(
            'jquery',
            'jquery-core',
            'jquery-migrate',
            'customize-support'
        );

        /**
         * Filter the list of script handles excluded from the defer pass.
         *
         * Other plugins can opt their own handles out of the defer
         * transformation here when their code depends on running before
         * DOMContentLoaded (e.g. inline event handlers attached without
         * jQuery.ready, or scripts that bootstrap critical UI features
         * the visitor interacts with immediately).
         *
         * @since 2.3.2
         * @param array  $excluded_handles Handles that should not be deferred.
         * @param string $handle           Handle of the script currently being filtered.
         */
        $excluded_handles = (array) apply_filters('ayudawp_wpotweaks_skip_defer_script_handles', $excluded_handles, $handle);

        if (in_array($handle, $excluded_handles, true)) {
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
        
        // Don't defer scripts whose dependency chain requires immediate
        // execution. A script must run immediately (not deferred) when:
        // - It has inline "after" code attached via wp_add_inline_script()
        // - It is registered with wp_set_script_translations()
        // - Any script that transitively depends on it has either of the above
        //
        // The third case is the important one: if script X can't be deferred
        // and X depends on Y, then deferring Y breaks X because Y would not
        // be available when X runs inline. Classic example: wp-i18n has inline
        // "after" code that calls wp.i18n.setLocaleData(). wp-i18n depends on
        // wp-hooks. Deferring wp-hooks while running wp-i18n immediately
        // causes "Cannot read properties of undefined (reading 'hooks')"
        // inside wp-i18n itself, and "wp is not defined" in every downstream
        // script that expects the wp.* globals to exist.
        global $wp_scripts;
        if (isset($wp_scripts->registered[$handle])) {
            if ($this->ayudawp_wpotweaks_script_must_execute_immediately($handle, $wp_scripts)) {
                return $tag;
            }
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
     * Check whether a script requires immediate execution (cannot be deferred).
     *
     * A script requires immediate execution if it has inline "after" code or
     * registered translations, OR if any script that transitively depends on
     * it has either. The transitive check is what preserves dependency chains
     * when some member of the chain can't be deferred.
     *
     * Results are memoized per-request in $this->defer_eligibility_cache to
     * avoid redundant work across multiple filter invocations.
     *
     * @since 2.3.1
     * @param string     $handle     Script handle to evaluate
     * @param WP_Scripts $wp_scripts The global scripts registry
     * @return bool True if the script must execute immediately
     */
    private function ayudawp_wpotweaks_script_must_execute_immediately($handle, $wp_scripts) {
        // Return memoized result when available
        if (array_key_exists($handle, $this->defer_eligibility_cache)) {
            return $this->defer_eligibility_cache[$handle];
        }
        
        // Unknown handle: nothing to defer, safe default
        if (!isset($wp_scripts->registered[$handle])) {
            $this->defer_eligibility_cache[$handle] = false;
            return false;
        }
        
        $script_obj = $wp_scripts->registered[$handle];
        
        // Direct reasons this script cannot be deferred:
        // - extra['after'] contains inline code that runs right after the
        //   script tag and would execute before the deferred main script
        // - textdomain indicates wp_set_script_translations() was called,
        //   which injects a locale data inline "after" block at print time
        if (!empty($script_obj->extra['after']) || !empty($script_obj->textdomain)) {
            $this->defer_eligibility_cache[$handle] = true;
            return true;
        }
        
        // Tentatively mark as deferrable to break potential recursion cycles
        // (circular dependencies shouldn't exist but this is defensive)
        $this->defer_eligibility_cache[$handle] = false;
        
        // Transitive check: walk the dependents. If any script that depends
        // on $handle (directly or transitively through another dependent)
        // needs immediate execution, $handle also needs immediate execution
        // to keep the dependency chain intact at load time.
        foreach ($wp_scripts->registered as $other_handle => $other_script) {
            if (!is_array($other_script->deps) || empty($other_script->deps)) {
                continue;
            }
            if (!in_array($handle, $other_script->deps, true)) {
                continue;
            }
            if ($this->ayudawp_wpotweaks_script_must_execute_immediately($other_handle, $wp_scripts)) {
                $this->defer_eligibility_cache[$handle] = true;
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Remove version strings from scripts and styles
     *
     * Cache-busting query strings are aggressively stripped from the URLs
     * of WordPress core, theme assets, and `wp-includes/` resources — those
     * change rarely and the saved bytes / cleaner audits are worth it.
     *
     * Plugins are LEFT ALONE on purpose. Combined with the immutable
     * `Cache-Control` headers this module emits in `.htaccess`, removing
     * the `?ver=` from a plugin asset would freeze it in browser caches
     * for one year — any update the plugin author ships would never reach
     * existing visitors until they manually hard-refreshed.
     *
     * @since 2.3.2 Plugin asset URLs preserve their `?ver=` for cache busting.
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

        // Preserve `?ver=` on URLs that point at /wp-content/plugins/ —
        // plugins ship updates regularly and this query string is the only
        // mechanism that invalidates the long-cached file in the browser.
        //
        // Filterable so site owners or other plugins can override the
        // detection (e.g. to also keep `?ver=` on theme assets, or to
        // strip it from a specific misbehaving plugin).
        $content_url = content_url('plugins/');
        $is_plugin_asset = (strpos($src, $content_url) !== false);
        if (apply_filters('ayudawp_wpotweaks_preserve_plugin_version', $is_plugin_asset, $src)) {
            return $src;
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
        
        // Remove dns-prefetch for s.w.org (only used by emoji scripts we already disabled)
        remove_action('wp_head', 'wp_resource_hints', 2);
    }
    
    /**
     * Clean importmap URLs (WordPress 6.5+)
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