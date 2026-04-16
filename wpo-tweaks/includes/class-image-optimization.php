<?php
/**
 * Image Optimization Module
 * Handles lazy loading, fetchpriority, and other image optimizations
 *
 * Runs at high priority (999) to catch all images regardless of source.
 * Every attribute addition checks for existence first, so it never
 * conflicts with WordPress core or other plugins that may have already
 * set the attribute.
 *
 * @package Zero_Config_Performance
 * @since 2.3.0 Added existence checks to complement core without duplicating
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Image_Optimization {
    
    /**
     * First image found flag
     */
    private $first_image_found = false;
    
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
        add_filter('wp_get_attachment_image_attributes', array($this, 'ayudawp_wpotweaks_optimize_attachment_image'), 10, 3);
        add_filter('the_content', array($this, 'ayudawp_wpotweaks_optimize_content_images'), 999);
        add_filter('post_thumbnail_html', array($this, 'ayudawp_wpotweaks_optimize_content_images'), 999);
        add_filter('fallback_intermediate_image_sizes', array($this, 'ayudawp_wpotweaks_disable_pdf_previews'));
        
        // Reset first image flag for each request
        add_action('wp', array($this, 'ayudawp_wpotweaks_reset_first_image_flag'));
    }
    
    /**
     * Reset first image flag
     */
    public function ayudawp_wpotweaks_reset_first_image_flag() {
        $this->first_image_found = false;
    }
    
    /**
     * Optimize attachment images (via wp_get_attachment_image)
     * 
     * Only adds attributes when NOT already present.
     * 
     * @since 2.2.0 Added fetchpriority support
     * @since 2.3.0 Added existence checks to avoid conflicts with core
     */
    public function ayudawp_wpotweaks_optimize_attachment_image($attr, $attachment, $size) {
        // First image: high priority, no lazy loading
        if (!$this->first_image_found) {
            $this->first_image_found = true;
            
            if (!isset($attr['decoding'])) {
                $attr['decoding'] = 'async';
            }
            
            if (!isset($attr['fetchpriority'])) {
                $attr['fetchpriority'] = 'high';
            }
            
            // Ensure no lazy loading on first image
            if (isset($attr['loading']) && $attr['loading'] === 'lazy') {
                unset($attr['loading']);
            }
            
            return $attr;
        }
        
        // Subsequent images: lazy load + low priority
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        
        if (!isset($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        
        if (!isset($attr['fetchpriority'])) {
            $attr['fetchpriority'] = 'low';
        }
        
        return $attr;
    }
    
    /**
     * Optimize images in post content and thumbnails
     * 
     * Runs at priority 999 to catch ALL images including those added by
     * themes, page builders, widgets, and custom templates that may bypass
     * WordPress core processing. Only adds attributes when NOT present.
     * 
     * @since 2.2.0 Added fetchpriority support
     * @since 2.3.0 Added existence checks to avoid conflicts with core
     */
    public function ayudawp_wpotweaks_optimize_content_images($content) {
        if (is_admin() || is_feed()) {
            return $content;
        }
        
        $image_count = 0;
        
        $content = preg_replace_callback(
            '/<img([^>]*)>/i',
            function ($matches) use (&$image_count) {
                $image_count++;
                $attrs = $matches[1];
                
                $is_logo = (strpos($attrs, 'site-logo') !== false) ||
                          (strpos($attrs, 'custom-logo') !== false);
                
                // First image or logo: no lazy loading, high priority
                if (($image_count === 1 && !$this->first_image_found) || $is_logo) {
                    $this->first_image_found = true;
                    
                    if (strpos($attrs, 'decoding=') === false) {
                        $attrs .= ' decoding="async"';
                    }
                    
                    if (strpos($attrs, 'fetchpriority=') === false) {
                        $attrs .= ' fetchpriority="high"';
                    }
                    
                    // Remove lazy loading if present on first image
                    $attrs = preg_replace('/\s*loading=["\'][^"\']*["\']/', '', $attrs);
                    
                    return '<img' . $attrs . '>';
                }
                
                // All other images: lazy load + low priority (only if not present)
                
                if (strpos($attrs, 'loading=') === false) {
                    $attrs .= ' loading="lazy"';
                }
                
                if (strpos($attrs, 'decoding=') === false) {
                    $attrs .= ' decoding="async"';
                }
                
                // fetchpriority="low" - unique ZCP value, core does NOT do this
                if (strpos($attrs, 'fetchpriority=') === false) {
                    $attrs .= ' fetchpriority="low"';
                }
                
                return '<img' . $attrs . '>';
            },
            $content
        );
        
        return $content;
    }
    
    /**
     * Disable PDF thumbnail previews
     */
    public function ayudawp_wpotweaks_disable_pdf_previews() {
        return array();
    }
}
