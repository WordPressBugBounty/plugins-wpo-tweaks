<?php
/**
 * Critical CSS Module
 * Handles critical CSS generation and deferred CSS loading
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Critical_CSS {
    
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
        add_action('wp_head', array($this, 'ayudawp_wpotweaks_inline_critical_css'), 1);
        add_filter('style_loader_tag', array($this, 'ayudawp_wpotweaks_defer_non_critical_css_filter'), 10, 4);
        add_action('wp_footer', array($this, 'ayudawp_wpotweaks_defer_non_critical_css_script'), 999);
    }
    
    /**
     * Inline critical CSS in head - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_inline_critical_css() {
        $critical_css = $this->ayudawp_wpotweaks_get_critical_css();
        
        if (!empty($critical_css)) {
            echo '<style id="ayudawp-wpotweaks-critical-css">' . esc_html(wp_strip_all_tags($critical_css)) . '</style>' . "\n";
        }
    }
    
    /**
     * Get critical CSS
     */
    private function ayudawp_wpotweaks_get_critical_css() {
        $cache_key = 'ayudawp_wpotweaks_critical_css_' . get_template();
        $critical_css = wp_cache_get($cache_key);
        
        if ($critical_css !== false) {
            return $critical_css;
        }
        
        // Check transient as fallback
        $critical_css = get_transient($cache_key);
        if ($critical_css !== false) {
            wp_cache_set($cache_key, $critical_css, '', WEEK_IN_SECONDS);
            return $critical_css;
        }
        
        // Basic critical CSS
        $critical_css = '
        html{font-family:sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
        body{margin:0;padding:0;line-height:1.6}
        *,*:before,*:after{box-sizing:border-box}
        img{max-width:100%;height:auto;border:0}
        .screen-reader-text{clip:rect(1px,1px,1px,1px);position:absolute!important;height:1px;width:1px;overflow:hidden}
        ';
        
        $critical_css = apply_filters('ayudawp_wpotweaks_critical_css', $critical_css);
        
        // Cache in both systems
        wp_cache_set($cache_key, $critical_css, '', WEEK_IN_SECONDS);
        set_transient($cache_key, $critical_css, WEEK_IN_SECONDS);
        
        return $critical_css;
    }
    
    /**
     * Defer non-critical CSS via filter - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_defer_non_critical_css_filter($tag, $handle, $href, $media) {
        // Only skip in admin, apply to ALL frontend users
        if (is_admin() || $this->ayudawp_wpotweaks_is_critical_css_handle($handle)) {
            return $tag;
        }
        
        $deferred_tag = str_replace('rel="stylesheet"', 'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"', $tag);
        $noscript = '<noscript>' . $tag . '</noscript>';
        
        return $deferred_tag . $noscript;
    }
    
    /**
     * Check if CSS handle is critical - FIXED FOR NON-LOGGED USERS
     */
    private function ayudawp_wpotweaks_is_critical_css_handle($handle) {
        $critical_handles = array(
            get_template(),
            get_stylesheet(),
            'admin-bar'
        );
        
        // Only add dashicons if user is logged in AND can manage options
        if (is_user_logged_in() && current_user_can('manage_options')) {
            $critical_handles[] = 'dashicons';
        }
        
        return in_array($handle, apply_filters('ayudawp_wpotweaks_critical_css_handles', $critical_handles));
    }
    
    /**
     * JavaScript for deferred CSS loading - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_defer_non_critical_css_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var links = document.querySelectorAll('link[data-defer="true"]');
            links.forEach(function(link) {
                link.rel = 'stylesheet';
                link.removeAttribute('data-defer');
            });
        });
        </script>
        <?php
    }
}