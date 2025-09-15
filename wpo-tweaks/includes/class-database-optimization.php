<?php
/**
 * Database Optimization Module
 * Handles database cleanup and query optimizations
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Database_Optimization {
    
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
        add_action('wp_loaded', array($this, 'ayudawp_wpotweaks_database_optimizations'));
        add_action('wp_scheduled_delete', array($this, 'ayudawp_wpotweaks_clean_expired_transients'));
        add_filter('comments_clauses', array($this, 'ayudawp_wpotweaks_optimize_comments_query'), 10, 2);
        add_action('pre_get_posts', array($this, 'ayudawp_wpotweaks_optimize_queries'));
    }
    
    /**
     * Database optimizations setup
     */
    public function ayudawp_wpotweaks_database_optimizations() {
        // Schedule transient cleanup if not already scheduled
        if (!wp_next_scheduled('ayudawp_wpotweaks_clean_transients')) {
            wp_schedule_event(time(), 'daily', 'ayudawp_wpotweaks_clean_transients');
        }
    }
    
    /**
     * Clean expired transients using WordPress methods
     */
    public function ayudawp_wpotweaks_clean_expired_transients() {
        // Use cache to prevent multiple executions
        $cache_key = 'ayudawp_wpotweaks_cleaning_transients';
        if (wp_cache_get($cache_key)) {
            return; // Already running
        }
        
        // Mark as running
        wp_cache_set($cache_key, true, '', 300); // 5 minutes
        
        // Use WordPress functions for cleaning transients
        $this->ayudawp_wpotweaks_clean_transients_wp_way();
        
        // Clear cache after operation
        wp_cache_delete($cache_key);
        
        // Cache that cleanup completed
        wp_cache_set('ayudawp_wpotweaks_last_transient_cleanup', time(), '', HOUR_IN_SECONDS);
    }
    
    /**
     * Clean transients using WordPress methods
     */
    private function ayudawp_wpotweaks_clean_transients_wp_way() {
        $current_time = time();
        $cleaned = 0;
        
        // Clean known plugin transients
        $plugin_transients = array(
            'ayudawp_wpotweaks_critical_css_',
            'ayudawp_wpotweaks_cleaning_transients',
            'ayudawp_wpotweaks_last_transient_cleanup'
        );
        
        foreach ($plugin_transients as $transient_prefix) {
            $timeout_value = get_option('_transient_timeout_' . $transient_prefix);
            if ($timeout_value && $timeout_value < $current_time) {
                delete_transient($transient_prefix);
                $cleaned++;
            }
        }
        
        // Safety limit to prevent timeouts
        if ($cleaned > 50) {
            return;
        }
        
        // Use WordPress core function if available
        if (function_exists('delete_expired_transients')) {
            delete_expired_transients();
        }
    }
    
    /**
     * Optimize comments queries
     */
    public function ayudawp_wpotweaks_optimize_comments_query($clauses, $query) {
        if (!is_admin() && !empty($clauses['where'])) {
            $clauses['where'] .= " AND comment_approved = '1'";
        }
        return $clauses;
    }
    
    /**
     * Optimize main queries - FIXED VERSION FOR PAGINATION
     */
    public function ayudawp_wpotweaks_optimize_queries($query) {
        if (!is_admin() && $query->is_main_query()) {
            // EXCLUDE ALL WOOCOMMERCE RELATED CONTENT
            if (function_exists('is_woocommerce') && is_woocommerce()) {
                return; // Exit without touching anything WooCommerce
            }
            
            // Exclude product categories specifically
            if (function_exists('is_product_category') && is_product_category()) {
                return;
            }
            
            // Exclude all WooCommerce taxonomies
            if (is_tax(array('product_cat', 'product_tag', 'product_shipping_class'))) {
                return;
            }
            
            // Only apply no_found_rows when we DON'T need pagination
            if ($query->is_archive() || $query->is_home()) {
                // Check if there are more posts than posts_per_page limit
                $posts_per_page = get_option('posts_per_page', 10);
                
                // Only apply no_found_rows if we don't need pagination
                // i.e., if we're sure there are no more posts to show
                $total_posts = wp_count_posts()->publish;
                
                // If few posts or on specific page that doesn't need pagination
                if ($total_posts <= $posts_per_page && !is_paged()) {
                    $query->set('no_found_rows', true);
                }
                // In any other case, DON'T apply no_found_rows to maintain pagination
            }
        }
    }
    
    /**
     * Module activation tasks
     */
    public function on_activation() {
        // Schedule transient cleanup
        if (!wp_next_scheduled('ayudawp_wpotweaks_clean_transients')) {
            wp_schedule_event(time(), 'daily', 'ayudawp_wpotweaks_clean_transients');
        }
    }
    
    /**
     * Module deactivation tasks
     */
    public function on_deactivation() {
        // Clear scheduled transient cleanup
        wp_clear_scheduled_hook('ayudawp_wpotweaks_clean_transients');
        
        // Clean plugin transients
        $this->ayudawp_wpotweaks_clear_plugin_transients();
    }
    
    /**
     * Clear plugin transients using cache
     */
    private function ayudawp_wpotweaks_clear_plugin_transients() {
        // Use cache to avoid multiple executions
        $cache_key = 'ayudawp_wpotweaks_clearing_plugin_transients';
        if (wp_cache_get($cache_key)) {
            return;
        }
        
        wp_cache_set($cache_key, true, '', 300);
        
        // Use WordPress API instead of direct queries
        $plugin_transients = array(
            'ayudawp_wpotweaks_critical_css_' . get_template(),
            'ayudawp_wpotweaks_cleaning_transients',
            'ayudawp_wpotweaks_clearing_plugin_transients',
            'ayudawp_wpotweaks_last_transient_cleanup',
            'ayudawp_wpotweaks_plugin_transients_cleared',
            'ayudawp_wpotweaks_last_scheduled_cleanup',
            'ayudawp_wpotweaks_scheduled_cleanup_running'
        );
        
        $deleted_count = 0;
        foreach ($plugin_transients as $transient) {
            if (delete_transient($transient)) {
                $deleted_count++;
            }
        }
        
        wp_cache_delete($cache_key);
        wp_cache_set('ayudawp_wpotweaks_plugin_transients_cleared', $deleted_count, '', HOUR_IN_SECONDS);
    }
}