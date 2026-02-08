<?php
/**
 * Admin Optimization Module
 * Handles administrative area optimizations and cleanup
 *
 * @package Zero_Config_Performance
 * @since 2.2.0
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
     * Initialize hooks
     */
    private function ayudawp_wpotweaks_init_hooks() {
        add_action('wp_dashboard_setup', array($this, 'ayudawp_wpotweaks_remove_dashboard_widgets'));
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
     * Limit post revisions to 3
     */
    public function ayudawp_wpotweaks_limit_revisions($num, $post) {
        return 3;
    }
}