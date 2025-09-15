<?php
/**
 * Image Optimization Module
 * Handles lazy loading and other image optimizations
 *
 * @package WPO_Tweaks
 * @since 2.1.0
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
        add_filter('wp_get_attachment_image_attributes', array($this, 'ayudawp_wpotweaks_add_loading_lazy'), 10, 3);
        add_filter('the_content', array($this, 'ayudawp_wpotweaks_add_lazy_loading_to_content'), 999);
        add_filter('post_thumbnail_html', array($this, 'ayudawp_wpotweaks_add_lazy_loading_to_content'), 999);
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
     * Add loading lazy and decoding async to attachment images - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_add_loading_lazy($attr, $attachment, $size) {
        // Don't add lazy loading to the first image (LCP optimization) - APPLIES TO ALL USERS
        if (!$this->first_image_found) {
            $this->first_image_found = true;
            
            // Only add decoding async to first image, no lazy loading
            if (!isset($attr['decoding'])) {
                $attr['decoding'] = 'async';
            }
            
            // Ensure no lazy loading on first image
            if (isset($attr['loading'])) {
                unset($attr['loading']);
            }
            
            return $attr;
        }
        
        // Add lazy loading for subsequent images - FOR ALL USERS
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        
        if (!isset($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        
        return $attr;
    }
    
    /**
     * Add lazy loading to images in content - SIMPLIFIED AND FIXED
     */
    public function ayudawp_wpotweaks_add_lazy_loading_to_content($content) {
        if (is_admin() || is_feed()) {
            return $content;
        }
        
        // Counter for images in content
        $image_count = 0;
        
        // Process all img tags in a single pass
        $content = preg_replace_callback(
            '/<img([^>]*)>/i',
            function($matches) use (&$image_count) {
                $image_count++;
                $img_attributes = $matches[1];
                $full_img_tag = $matches[0];
                
                // Check if it's a logo or first image - don't lazy load
                $is_logo = (strpos($img_attributes, 'site-logo') !== false) || 
                          (strpos($img_attributes, 'custom-logo') !== false);
                
                // Check if it's a Gravatar image
                $is_gravatar = (strpos($img_attributes, 'gravatar.com') !== false) ||
                              (strpos($img_attributes, 'secure.gravatar.com') !== false);
                
                // First image or logo - no lazy loading
                if (($image_count === 1 && !$this->first_image_found) || $is_logo) {
                    $this->first_image_found = true;
                    
                    // Only add decoding="async" to first image/logo, remove any lazy loading
                    if (strpos($img_attributes, 'decoding=') === false) {
                        $img_attributes .= ' decoding="async"';
                    }
                    
                    // Remove loading="lazy" if present on first image
                    $img_attributes = preg_replace('/\s*loading=["\'][^"\']*["\']/', '', $img_attributes);
                    
                    return '<img' . $img_attributes . '>';
                }
                
                // For all other images (including Gravatar), add lazy loading
                
                // Add loading="lazy" if not present (force it for Gravatar)
                if (strpos($img_attributes, 'loading=') === false) {
                    $img_attributes .= ' loading="lazy"';
                } elseif ($is_gravatar && strpos($img_attributes, 'loading="lazy"') === false) {
                    // Force lazy loading for Gravatar even if it has other loading attribute
                    $img_attributes = preg_replace('/loading=["\'][^"\']*["\']/', 'loading="lazy"', $img_attributes);
                }
                
                // Add decoding="async" if not present
                if (strpos($img_attributes, 'decoding=') === false) {
                    $img_attributes .= ' decoding="async"';
                }
                
                return '<img' . $img_attributes . '>';
            },
            $content
        );
        
        return $content;
    }
    
    /**
     * Optimize Gravatar images specifically - REMOVED (now handled in main function)
     */
    private function ayudawp_wpotweaks_optimize_gravatar_images($content) {
        // This function is no longer needed as Gravatar optimization 
        // is now handled in the main add_lazy_loading_to_content function
        return $content;
    }
    
    /**
     * Disable PDF thumbnail previews
     */
    public function ayudawp_wpotweaks_disable_pdf_previews() {
        return array();
    }
    
    /**
     * Add lazy loading specifically to avatar images - MÉTODO SIMPLE
     */
    public function ayudawp_wpotweaks_add_lazy_to_avatar($avatar) {
        if (!empty($avatar) && (strpos($avatar, 'gravatar.com') !== false || strpos($avatar, 'secure.gravatar.com') !== false)) {
            // Force add loading="lazy" if not present
            if (strpos($avatar, 'loading=') === false) {
                $avatar = str_replace('<img ', '<img loading="lazy" ', $avatar);
            }
        }
        return $avatar;
    }
}