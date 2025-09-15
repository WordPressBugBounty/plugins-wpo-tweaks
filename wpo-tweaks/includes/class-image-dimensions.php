<?php
/**
 * Image Dimensions Module
 * Automatically adds missing width and height attributes to images and picture elements
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_Image_Dimensions {
    
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
        add_filter('the_content', array($this, 'ayudawp_wpotweaks_add_missing_image_dimensions'), 999);
        add_filter('post_thumbnail_html', array($this, 'ayudawp_wpotweaks_add_missing_image_dimensions'), 999);
        add_filter('wp_get_attachment_image', array($this, 'ayudawp_wpotweaks_add_missing_image_dimensions'), 999);
    }
    
    /**
     * Add missing width and height attributes to images and picture elements - FOR ALL USERS
     */
    public function ayudawp_wpotweaks_add_missing_image_dimensions($content) {
        // Only skip admin, feeds, and REST requests - apply to ALL frontend users
        if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $content;
        }
        
        // Cache key for processed content
        $cache_key = 'ayudawp_wpotweaks_img_dimensions_' . md5($content);
        $cached_content = wp_cache_get($cache_key);
        
        if ($cached_content !== false) {
            return $cached_content;
        }
        
        // Process standalone img tags first
        $pattern_img = '/<img(?![^>]*(?:width|height))[^>]*src=["\']([^"\']+)["\'][^>]*>/i';
        $processed_content = preg_replace_callback($pattern_img, array($this, 'ayudawp_wpotweaks_process_image_dimensions'), $content);
        
        // Process picture elements
        $pattern_picture = '/<picture[^>]*>(.*?)<\/picture>/is';
        $processed_content = preg_replace_callback($pattern_picture, array($this, 'ayudawp_wpotweaks_process_picture_dimensions'), $processed_content);
        
        // Cache the processed content for 1 hour
        wp_cache_set($cache_key, $processed_content, '', HOUR_IN_SECONDS);
        
        return $processed_content;
    }
    
    /**
     * Process picture elements to add dimensions
     */
    private function ayudawp_wpotweaks_process_picture_dimensions($matches) {
        $picture_content = $matches[1];
        $full_picture = $matches[0];
        
        // Find the main img tag within picture (fallback image)
        $img_pattern = '/<img(?![^>]*(?:width|height))[^>]*src=["\']([^"\']+)["\'][^>]*>/i';
        
        $processed_picture = preg_replace_callback($img_pattern, function($img_matches) {
            $img_tag = $img_matches[0];
            $src = $img_matches[1];
            
            // Skip if image already has dimensions
            if (preg_match('/(?:width|height)=/i', $img_tag)) {
                return $img_tag;
            }
            
            // Skip external images and SVGs
            if (!$this->ayudawp_wpotweaks_is_local_image($src) || $this->ayudawp_wpotweaks_is_svg_image($src)) {
                return $img_tag;
            }
            
            // Get image dimensions
            $dimensions = $this->ayudawp_wpotweaks_get_image_dimensions($src);
            
            if (!$dimensions) {
                return $img_tag;
            }
            
            // Add width and height attributes
            $img_tag = str_replace('<img', '<img width="' . esc_attr($dimensions['width']) . '" height="' . esc_attr($dimensions['height']) . '"', $img_tag);
            
            return $img_tag;
            
        }, $picture_content);
        
        // Return the complete picture element with processed img
        return str_replace($picture_content, $processed_picture, $full_picture);
    }
    
    /**
     * Process individual image to add dimensions
     */
    private function ayudawp_wpotweaks_process_image_dimensions($matches) {
        $img_tag = $matches[0];
        $src = $matches[1];
        
        // Skip if image already has dimensions
        if (preg_match('/(?:width|height)=/i', $img_tag)) {
            return $img_tag;
        }
        
        // Skip external images and SVGs
        if (!$this->ayudawp_wpotweaks_is_local_image($src) || $this->ayudawp_wpotweaks_is_svg_image($src)) {
            return $img_tag;
        }
        
        // Get image dimensions
        $dimensions = $this->ayudawp_wpotweaks_get_image_dimensions($src);
        
        if (!$dimensions) {
            return $img_tag;
        }
        
        // Add width and height attributes
        $img_tag = str_replace('<img', '<img width="' . esc_attr($dimensions['width']) . '" height="' . esc_attr($dimensions['height']) . '"', $img_tag);
        
        return $img_tag;
    }
    
    /**
     * Check if image is local
     */
    private function ayudawp_wpotweaks_is_local_image($src) {
        $home_url = home_url();
        $upload_dir = wp_upload_dir();
        
        return (strpos($src, $home_url) === 0) || (strpos($src, $upload_dir['baseurl']) === 0) || (strpos($src, '/wp-content/') === 0);
    }
    
    /**
     * Check if image is SVG
     */
    private function ayudawp_wpotweaks_is_svg_image($src) {
        return (strpos($src, '.svg') !== false);
    }
    
    /**
     * Get image dimensions from attachment or file
     */
    private function ayudawp_wpotweaks_get_image_dimensions($src) {
        // Cache key for dimensions
        $cache_key = 'ayudawp_wpotweaks_img_dims_' . md5($src);
        $cached_dims = wp_cache_get($cache_key);
        
        if ($cached_dims !== false) {
            return $cached_dims;
        }
        
        $dimensions = false;
        
        // Try to get attachment ID from URL
        $attachment_id = attachment_url_to_postid($src);
        
        if ($attachment_id) {
            // Get dimensions from attachment metadata
            $metadata = wp_get_attachment_metadata($attachment_id);
            if ($metadata && isset($metadata['width']) && isset($metadata['height'])) {
                $dimensions = array(
                    'width' => $metadata['width'],
                    'height' => $metadata['height']
                );
            }
        } else {
            // Fallback: get dimensions from file system
            $dimensions = $this->ayudawp_wpotweaks_get_image_dimensions_from_file($src);
        }
        
        // Cache dimensions for 1 day
        if ($dimensions) {
            wp_cache_set($cache_key, $dimensions, '', DAY_IN_SECONDS);
        }
        
        return $dimensions;
    }
    
    /**
     * Get image dimensions from file system
     */
    private function ayudawp_wpotweaks_get_image_dimensions_from_file($src) {
        // Convert URL to file path
        $upload_dir = wp_upload_dir();
        
        if (strpos($src, $upload_dir['baseurl']) === 0) {
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $src);
        } elseif (strpos($src, '/wp-content/') === 0) {
            $file_path = ABSPATH . ltrim($src, '/');
        } else {
            return false;
        }
        
        // Security check
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }
        
        // Get image dimensions
        $image_info = getimagesize($file_path);
        
        if ($image_info && $image_info[0] > 0 && $image_info[1] > 0) {
            return array(
                'width' => $image_info[0],
                'height' => $image_info[1]
            );
        }
        
        return false;
    }
}