<?php
/**
 * Admin Notice Module
 * Handles activation notice with optimization summary
 *
 * @package WPO_Tweaks
 * @since 2.1.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Admin_Notice {
    
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
        add_action('admin_notices', array($this, 'ayudawp_wpotweaks_show_activation_notice'));
        add_action('admin_enqueue_scripts', array($this, 'ayudawp_wpotweaks_enqueue_admin_assets'));
        add_action('wp_ajax_ayudawp_wpotweaks_dismiss_notice', array($this, 'ayudawp_wpotweaks_dismiss_notice'));
    }
    
    /**
     * Module activation tasks
     */
    public function on_activation() {
        // Set flag to show notice after activation
        update_option('ayudawp_wpotweaks_show_activation_notice', true);
    }
    
    /**
     * Enqueue admin CSS and JS
     */
    public function ayudawp_wpotweaks_enqueue_admin_assets($hook) {
        // Only load on plugins page and if notice should be shown
        if ($hook !== 'plugins.php' || !get_option('ayudawp_wpotweaks_show_activation_notice')) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'ayudawp-wpotweaks-admin-notice',
            AYUDAWP_WPOTWEAKS_PLUGIN_URL . 'assets/css/admin-notice.css',
            array(),
            AYUDAWP_WPOTWEAKS_VERSION
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'ayudawp-wpotweaks-admin-notice',
            AYUDAWP_WPOTWEAKS_PLUGIN_URL . 'assets/js/admin-notice.js',
            array('jquery'),
            AYUDAWP_WPOTWEAKS_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('ayudawp-wpotweaks-admin-notice', 'ayudawpWpoTweaks', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ayudawp_wpotweaks_dismiss_notice')
        ));
    }
    
    /**
     * Show activation notice
     */
    public function ayudawp_wpotweaks_show_activation_notice() {
        // Only show on admin pages and if not dismissed
        if (!current_user_can('manage_options') || !get_option('ayudawp_wpotweaks_show_activation_notice')) {
            return;
        }
        
        // Get current screen
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'plugins') {
            return;
        }
        
        ?>
        <div class="notice notice-success is-dismissible ayudawp-wpotweaks-notice" data-notice="activation">
            <div class="ayudawp-notice-content">
                <h3 class="ayudawp-notice-title">
                    <span class="dashicons dashicons-performance"></span>
                    <?php esc_html_e('WPO Tweaks Successfully Activated!', 'wpo-tweaks'); ?>
                </h3>
                
                <p class="ayudawp-notice-intro">
                    <?php esc_html_e('The following performance optimizations have been applied to your website:', 'wpo-tweaks'); ?>
                </p>
                
                <div class="ayudawp-optimizations-grid">
                    <div class="ayudawp-optimization-column">
                        <h4 class="ayudawp-optimization-title">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php esc_html_e('Frontend Optimizations:', 'wpo-tweaks'); ?>
                        </h4>
                        <ul class="ayudawp-optimization-list">
                            <li><?php esc_html_e('Critical CSS generation and deferred loading', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Image lazy loading with async decoding', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Automatic image dimensions for better CLS', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('JavaScript defer parsing', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Google Fonts display=swap optimization', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('DNS prefetch and preconnect hints', 'wpo-tweaks'); ?></li>
                        </ul>
                    </div>
                    
                    <div class="ayudawp-optimization-column">
                        <h4 class="ayudawp-optimization-title">
                            <span class="dashicons dashicons-shield"></span>
                            <?php esc_html_e('Backend & Security:', 'wpo-tweaks'); ?>
                        </h4>
                        <ul class="ayudawp-optimization-list">
                            <li><?php esc_html_e('Browser cache and GZIP compression rules', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Header cleanup and security headers', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Database transients cleanup', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Heartbeat API optimization (60s)', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Post revisions limited to 3', 'wpo-tweaks'); ?></li>
                            <li><?php esc_html_e('Trash retention reduced to 7 days', 'wpo-tweaks'); ?></li>
                        </ul>
                    </div>
                </div>
                
                <div class="ayudawp-notice-info">
                    <p>
                        <span class="dashicons dashicons-info"></span>
                        <?php esc_html_e('All optimizations are applied automatically. No configuration needed!', 'wpo-tweaks'); ?>
                    </p>
                </div>
                
                <div class="ayudawp-notice-footer">
                    <p>
                        <?php
                        printf(
                            // translators: 1: link to wordpress.or reviews page, 2: link to plugin author professional services website
                            esc_html__('If this plugin has been helpful, I would appreciate if you publish a %1$s. For professional WordPress services, contact me at %2$s.', 'wpo-tweaks'),
                            '<a href="https://wordpress.org/support/plugin/wpo-tweaks/reviews/#new-post" target="_blank" rel="noopener">' . esc_html__('5-star review on WordPress.org', 'wpo-tweaks') . '</a>',
                            '<a rel="nofollow noopener" target="_blank" href="https://servicios.ayudawp.com/">' . esc_html__('AyudaWP WordPress Services', 'wpo-tweaks') . '</a>'
                        );
                        ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle notice dismissal - FIXED SECURITY ISSUES
     */
    public function ayudawp_wpotweaks_dismiss_notice() {
        // Check if nonce exists in $_POST
        if (!isset($_POST['nonce'])) {
            wp_die('Security check failed: missing nonce');
        }
        
        // Sanitize and unslash the nonce
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
        
        // Verify nonce for security
        if (!wp_verify_nonce($nonce, 'ayudawp_wpotweaks_dismiss_notice')) {
            wp_die('Security check failed: invalid nonce');
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Remove the option to show notice
        delete_option('ayudawp_wpotweaks_show_activation_notice');
        
        wp_die(); // Required for AJAX
    }
}