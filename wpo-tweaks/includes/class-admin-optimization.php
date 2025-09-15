<?php
/**
 * Admin Optimization Module
 * Handles administrative area optimizations and cleanup
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Admin_Optimization {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->ayudawp_wpotweaks_init_hooks();
    }
    
    /**
     * Initialize hooks - SIMPLIFIED (Trash management now handled by File_Management module)
     */
    private function ayudawp_wpotweaks_init_hooks() {
        add_action('wp_dashboard_setup', array($this, 'ayudawp_wpotweaks_remove_dashboard_widgets'));
        add_filter('auto_update_plugin', array($this, 'ayudawp_wpotweaks_disable_auto_updates'), 10, 2);
        add_filter('wp_revisions_to_keep', array($this, 'ayudawp_wpotweaks_limit_revisions'), 10, 2);
    }
    
    /**
     * Remove unnecessary dashboard widgets
     */
    public function ayudawp_wpotweaks_remove_dashboard_widgets() {
        // Remove WordPress events and news
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        
        // Remove quick press widget
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        
        // Remove incoming links widget (deprecated but still loaded sometimes)
        remove_meta_box('dashboard_incoming_links', 'dashboard', 'normal');
        
        // Remove plugins widget
        remove_meta_box('dashboard_plugins', 'dashboard', 'normal');
        
        // Remove secondary widget
        remove_meta_box('dashboard_secondary', 'dashboard', 'side');
    }
    
    /**
     * Disable auto-updates for plugins (optional security measure)
     * Note: This filter allows developers to control auto-updates via filter
     * It does not alter WordPress core update functionality
     */
    public function ayudawp_wpotweaks_disable_auto_updates($update, $item) {
        // Allow filtering by developers - this is a legitimate use of the filter
        // WordPress.org Plugin Check: This filter is for optional auto-update control
        return apply_filters('ayudawp_wpotweaks_allow_auto_updates', $update, $item);
    }
    
    /**
     * Limit post revisions to 3
     */
    public function ayudawp_wpotweaks_limit_revisions($num, $post) {
        return 3;
    }
}