<?php
/**
 * Cache Optimization Module
 * Handles caching optimizations and resource preloading
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Cache_Optimization {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->ayudawp_wpotweaks_init_hooks();
    }
    
    /**
     * Initialize hooks - APPLY TO ALL USERS
     */
    private function ayudawp_wpotweaks_init_hooks() {
        // These optimizations apply to ALL users (logged in and not logged in)
        add_action('wp_head', array($this, 'ayudawp_wpotweaks_add_preconnect_hints'), 1);
        add_action('wp_head', array($this, 'ayudawp_wpotweaks_add_dns_prefetch'), 1);
        add_action('wp_head', array($this, 'ayudawp_wpotweaks_preload_critical_resources'), 1);
        add_action('init', array($this, 'ayudawp_wpotweaks_optimize_feeds'));
    }
    
    /**
     * Add preconnect hints
     */
    public function ayudawp_wpotweaks_add_preconnect_hints() {
        $preconnects = array(
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
            'https://www.google-analytics.com',
            'https://www.googletagmanager.com'
        );
        
        $preconnects = apply_filters('ayudawp_wpotweaks_preconnect_hints', $preconnects);
        
        foreach ($preconnects as $url) {
            echo '<link rel="preconnect" href="' . esc_url($url) . '" crossorigin>' . "\n";
        }
    }
    
    /**
     * Add DNS prefetch - UPDATED WITH GRAVATAR
     */
    public function ayudawp_wpotweaks_add_dns_prefetch() {
        $prefetch_domains = array(
            '//fonts.googleapis.com',
            '//fonts.gstatic.com',
            '//ajax.googleapis.com',
            '//www.google-analytics.com',
            '//stats.wp.com',
            '//gravatar.com',
            '//secure.gravatar.com',
            '//0.gravatar.com',
            '//1.gravatar.com',
            '//2.gravatar.com',
            '//s.w.org'
        );
        
        $prefetch_domains = apply_filters('ayudawp_wpotweaks_dns_prefetch_domains', $prefetch_domains);
        
        foreach ($prefetch_domains as $domain) {
            echo '<link rel="dns-prefetch" href="' . esc_url($domain) . '">' . "\n";
        }
    }
    
    /**
     * Preload critical resources
     */
    public function ayudawp_wpotweaks_preload_critical_resources() {
        // Preload theme CSS
        $theme_css = get_stylesheet_uri();
        echo '<link rel="preload" href="' . esc_url($theme_css) . '" as="style">' . "\n";
        
        // Preload critical fonts if they exist
        $critical_fonts = apply_filters('ayudawp_wpotweaks_critical_fonts', array());
        foreach ($critical_fonts as $font_url) {
            echo '<link rel="preload" href="' . esc_url($font_url) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        }
    }
    
    /**
     * Optimize feeds
     */
    public function ayudawp_wpotweaks_optimize_feeds() {
        add_action('do_feed_rss2', function() {
            header('Cache-Control: public, max-age=3600');
        }, 1);
        
        add_filter('pre_option_posts_per_rss', function() {
            return '10';
        });
    }
}