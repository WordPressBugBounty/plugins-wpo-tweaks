<?php
/**
 * File Management Module
 * Handles wp-config.php and .htaccess modifications with backups
 *
 * @package Zero_Config_Performance
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_File_Management {
    
    /**
     * Marker name for .htaccess rules
     */
    private $htaccess_marker = 'Zero Config Performance';
    
    /**
     * Legacy marker name (for cleanup of old installations)
     */
    private $legacy_marker = 'WPO Tweaks by Fernando Tellado';
    
    /**
     * Constructor
     */
    public function __construct() {
        // No hooks needed, only activation/deactivation methods
    }
    
    /**
     * Module activation tasks
     */
    public function on_activation() {
        $this->ayudawp_wpotweaks_create_backup_directory();
        $this->ayudawp_wpotweaks_backup_and_modify_files();
    }
    
    /**
     * Module deactivation tasks
     */
    public function on_deactivation() {
        $this->ayudawp_wpotweaks_restore_files();
        $this->ayudawp_wpotweaks_clean_htaccess();
    }
    
    /**
     * Create backup directory
     */
    private function ayudawp_wpotweaks_create_backup_directory() {
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $backup_dir = AYUDAWP_WPOTWEAKS_PLUGIN_PATH . 'backup/';
        
        if (!$wp_filesystem->exists($backup_dir)) {
            $wp_filesystem->mkdir($backup_dir, 0755);
        }
        
        // Add .htaccess to prevent direct access
        $htaccess_backup = $backup_dir . '.htaccess';
        if (!$wp_filesystem->exists($htaccess_backup)) {
            $wp_filesystem->put_contents($htaccess_backup, "deny from all\n");
        }
    }
    
    /**
     * Backup and modify wp-config.php and .htaccess
     */
    private function ayudawp_wpotweaks_backup_and_modify_files() {
        $this->ayudawp_wpotweaks_backup_wp_config();
        $this->ayudawp_wpotweaks_backup_htaccess();
        $this->ayudawp_wpotweaks_modify_wp_config();
        $this->ayudawp_wpotweaks_modify_htaccess();
    }
    
    /**
     * Backup wp-config.php
     */
    private function ayudawp_wpotweaks_backup_wp_config() {
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $wp_config_path = ABSPATH . 'wp-config.php';
        $backup_path = AYUDAWP_WPOTWEAKS_PLUGIN_PATH . 'backup/wp-config.php.bak';
        
        if ($wp_filesystem->exists($wp_config_path)) {
            $content = $wp_filesystem->get_contents($wp_config_path);
            $wp_filesystem->put_contents($backup_path, $content);
        }
    }
    
    /**
     * Backup .htaccess
     */
    private function ayudawp_wpotweaks_backup_htaccess() {
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        if (!function_exists('get_home_path')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $htaccess_path = get_home_path() . '.htaccess';
        $backup_path = AYUDAWP_WPOTWEAKS_PLUGIN_PATH . 'backup/.htaccess.bak';
        
        if ($wp_filesystem->exists($htaccess_path)) {
            $content = $wp_filesystem->get_contents($htaccess_path);
            $wp_filesystem->put_contents($backup_path, $content);
        }
    }
    
    /**
     * Modify wp-config.php
     */
    private function ayudawp_wpotweaks_modify_wp_config() {
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $wp_config_path = ABSPATH . 'wp-config.php';
        
        if (!$wp_filesystem->exists($wp_config_path) || !$wp_filesystem->is_writable($wp_config_path)) {
            return false;
        }
        
        $content = $wp_filesystem->get_contents($wp_config_path);
        
        // Remove existing EMPTY_TRASH_DAYS if exists
        $content = preg_replace('/define\s*\(\s*[\'"]EMPTY_TRASH_DAYS[\'"]\s*,\s*[^)]+\)\s*;?\s*/', '', $content);
        
        // Find the insertion point (before /* That's all, stop editing! */)
        $insertion_point = "/* That's all, stop editing! Happy publishing. */";
        $our_config = "\n// Zero Config Performance Configuration\ndefine('EMPTY_TRASH_DAYS', 7);\n\n";
        
        if (strpos($content, $insertion_point) !== false) {
            $content = str_replace($insertion_point, $our_config . $insertion_point, $content);
        } else {
            // Fallback: add after opening PHP tag
            $content = str_replace('<?php', '<?php' . $our_config, $content);
        }
        
        return $wp_filesystem->put_contents($wp_config_path, $content);
    }
    
    /**
     * Modify .htaccess with optimized rules
     */
    private function ayudawp_wpotweaks_modify_htaccess() {
        if (!function_exists('get_home_path')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $htaccess_file = get_home_path() . '.htaccess';
        
        if (!$wp_filesystem->exists($htaccess_file) || !$wp_filesystem->is_writable($htaccess_file)) {
            return false;
        }
        
        // Clean any existing rules (both legacy and current)
        $this->ayudawp_wpotweaks_clean_existing_rules($htaccess_file);
        
        // Build optimized .htaccess rules
        $lines = $this->ayudawp_wpotweaks_get_htaccess_rules();
        
        return insert_with_markers($htaccess_file, $this->htaccess_marker, $lines);
    }
    
    /**
     * Get optimized .htaccess rules
     * 
     * @return array Array of .htaccess rules
     */
    private function ayudawp_wpotweaks_get_htaccess_rules() {
        $lines = array();
        
        // Expires Headers
        $lines[] = '# Browser Caching with Expires Headers';
        $lines[] = '<IfModule mod_expires.c>';
        $lines[] = 'ExpiresActive On';
        $lines[] = 'ExpiresDefault "access plus 1 month"';
        $lines[] = '';
        $lines[] = '# Images';
        $lines[] = 'ExpiresByType image/x-icon "access plus 1 year"';
        $lines[] = 'ExpiresByType image/gif "access plus 1 month"';
        $lines[] = 'ExpiresByType image/png "access plus 1 month"';
        $lines[] = 'ExpiresByType image/jpg "access plus 1 month"';
        $lines[] = 'ExpiresByType image/jpeg "access plus 1 month"';
        $lines[] = 'ExpiresByType image/webp "access plus 1 month"';
        $lines[] = 'ExpiresByType image/avif "access plus 1 month"';
        $lines[] = 'ExpiresByType image/svg+xml "access plus 1 month"';
        $lines[] = '';
        $lines[] = '# Video and Audio';
        $lines[] = 'ExpiresByType video/mp4 "access plus 1 month"';
        $lines[] = 'ExpiresByType video/ogg "access plus 1 month"';
        $lines[] = 'ExpiresByType video/webm "access plus 1 month"';
        $lines[] = 'ExpiresByType audio/ogg "access plus 1 month"';
        $lines[] = '';
        $lines[] = '# CSS and JavaScript';
        $lines[] = 'ExpiresByType text/css "access plus 1 year"';
        $lines[] = 'ExpiresByType application/javascript "access plus 1 year"';
        $lines[] = 'ExpiresByType application/x-javascript "access plus 1 year"';
        $lines[] = 'ExpiresByType text/javascript "access plus 1 year"';
        $lines[] = '';
        $lines[] = '# Fonts';
        $lines[] = 'ExpiresByType font/woff "access plus 1 year"';
        $lines[] = 'ExpiresByType font/woff2 "access plus 1 year"';
        $lines[] = 'ExpiresByType application/font-woff "access plus 1 year"';
        $lines[] = 'ExpiresByType application/font-woff2 "access plus 1 year"';
        $lines[] = 'ExpiresByType font/otf "access plus 1 year"';
        $lines[] = 'ExpiresByType font/ttf "access plus 1 year"';
        $lines[] = 'ExpiresByType application/font-otf "access plus 1 year"';
        $lines[] = 'ExpiresByType application/font-ttf "access plus 1 year"';
        $lines[] = 'ExpiresByType application/vnd.ms-fontobject "access plus 1 year"';
        $lines[] = '';
        $lines[] = '# Other files';
        $lines[] = 'ExpiresByType application/pdf "access plus 1 month"';
        $lines[] = 'ExpiresByType application/manifest+json "access plus 1 year"';
        $lines[] = 'ExpiresByType application/x-web-app-manifest+json "access plus 0 seconds"';
        $lines[] = 'ExpiresByType text/cache-manifest "access plus 0 seconds"';
        $lines[] = 'ExpiresByType application/xml "access plus 0 seconds"';
        $lines[] = 'ExpiresByType text/xml "access plus 0 seconds"';
        $lines[] = 'ExpiresByType application/json "access plus 0 seconds"';
        $lines[] = '</IfModule>';
        $lines[] = '';
        
        // GZIP Compression
        $lines[] = '# GZIP Compression';
        $lines[] = '<IfModule mod_deflate.c>';
        $lines[] = 'SetOutputFilter DEFLATE';
        $lines[] = '';
        $lines[] = '# Exclude already compressed files';
        $lines[] = 'SetEnvIfNoCase Request_URI \\.(?:gif|jpe?g|png|webp|avif)$ no-gzip dont-vary';
        $lines[] = 'SetEnvIfNoCase Request_URI \\.(?:exe|t?gz|zip|bz2|sit|rar)$ no-gzip dont-vary';
        $lines[] = 'SetEnvIfNoCase Request_URI \\.pdf$ no-gzip dont-vary';
        $lines[] = 'SetEnvIfNoCase Request_URI \\.(?:avi|mov|mp4|webm|mp3|ogg)$ no-gzip dont-vary';
        $lines[] = '';
        $lines[] = '# Compress text-based files';
        $lines[] = 'AddOutputFilterByType DEFLATE text/plain text/html';
        $lines[] = 'AddOutputFilterByType DEFLATE text/xml application/xml application/xhtml+xml';
        $lines[] = 'AddOutputFilterByType DEFLATE application/rdf+xml application/rss+xml application/atom+xml';
        $lines[] = 'AddOutputFilterByType DEFLATE image/svg+xml';
        $lines[] = 'AddOutputFilterByType DEFLATE text/css';
        $lines[] = 'AddOutputFilterByType DEFLATE text/javascript application/javascript application/x-javascript';
        $lines[] = 'AddOutputFilterByType DEFLATE application/json application/ld+json';
        $lines[] = 'AddOutputFilterByType DEFLATE application/manifest+json';
        $lines[] = '';
        $lines[] = '# Compress fonts';
        $lines[] = 'AddOutputFilterByType DEFLATE font/otf font/opentype';
        $lines[] = 'AddOutputFilterByType DEFLATE font/ttf font/truetype';
        $lines[] = 'AddOutputFilterByType DEFLATE application/font-otf application/x-font-otf';
        $lines[] = 'AddOutputFilterByType DEFLATE application/font-ttf application/x-font-ttf';
        $lines[] = 'AddOutputFilterByType DEFLATE application/vnd.ms-fontobject';
        $lines[] = '</IfModule>';
        $lines[] = '';
        
        // Brotli Compression (modern servers)
        $lines[] = '# Brotli Compression (if available)';
        $lines[] = '<IfModule mod_brotli.c>';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/xml';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS text/css';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS text/javascript application/javascript application/x-javascript';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS application/json application/ld+json';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS application/xml application/xhtml+xml';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS application/rss+xml application/atom+xml';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS image/svg+xml';
        $lines[] = 'AddOutputFilterByType BROTLI_COMPRESS application/manifest+json';
        $lines[] = '</IfModule>';
        $lines[] = '';
        
        // Cache-Control Headers
        $lines[] = '# Cache-Control Headers';
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = '';
        $lines[] = '# Cache static files with immutable flag';
        $lines[] = '<FilesMatch "\\.(?:css|js|png|jpe?g|gif|webp|avif|woff2?|ttf|otf|eot|svg|ico)$">';
        $lines[] = 'Header set Cache-Control "public, max-age=31536000, immutable"';
        $lines[] = '</FilesMatch>';
        $lines[] = '';
        $lines[] = '# Cache HTML for 1 hour';
        $lines[] = '<FilesMatch "\\.(?:html|htm)$">';
        $lines[] = 'Header set Cache-Control "max-age=3600, public"';
        $lines[] = '</FilesMatch>';
        $lines[] = '';
        $lines[] = '# Remove ETags for static files';
        $lines[] = '<FilesMatch "\\.(?:css|js|png|jpe?g|gif|webp|avif|woff2?|ttf|otf|eot|svg|ico)$">';
        $lines[] = 'Header unset ETag';
        $lines[] = 'FileETag None';
        $lines[] = '</FilesMatch>';
        $lines[] = '';
        $lines[] = '# Vary Accept-Encoding for better CDN caching';
        $lines[] = '<FilesMatch "\\.(?:js|css|xml|gz|html|svg)$">';
        $lines[] = 'Header append Vary: Accept-Encoding';
        $lines[] = '</FilesMatch>';
        $lines[] = '';
        $lines[] = '# CORS headers for fonts (CDN compatibility)';
        $lines[] = '<FilesMatch "\\.(?:ttf|ttc|otf|eot|woff2?|font\\.css|css)$">';
        $lines[] = 'Header set Access-Control-Allow-Origin "*"';
        $lines[] = '</FilesMatch>';
        $lines[] = '';
        $lines[] = '# Keep-Alive for connection reuse';
        $lines[] = 'Header set Connection keep-alive';
        $lines[] = '';
        $lines[] = '</IfModule>';
        
        return $lines;
    }
    
    /**
     * Clean existing rules from .htaccess (both legacy and current markers)
     * 
     * @param string $htaccess_file Path to .htaccess file
     */
    private function ayudawp_wpotweaks_clean_existing_rules($htaccess_file) {
        global $wp_filesystem;
        
        if (!$wp_filesystem->exists($htaccess_file)) {
            return;
        }
        
        $content = $wp_filesystem->get_contents($htaccess_file);
        $original_content = $content;
        
        // Remove current marker rules
        $pattern_current = '/# BEGIN ' . preg_quote($this->htaccess_marker, '/') . '.*?# END ' . preg_quote($this->htaccess_marker, '/') . '\s*/s';
        $content = preg_replace($pattern_current, '', $content);
        
        // Remove legacy marker rules
        $pattern_legacy = '/# BEGIN ' . preg_quote($this->legacy_marker, '/') . '.*?# END ' . preg_quote($this->legacy_marker, '/') . '\s*/s';
        $content = preg_replace($pattern_legacy, '', $content);
        
        // Remove any orphaned empty lines that might accumulate
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // Only write if content changed
        if ($content !== $original_content) {
            $wp_filesystem->put_contents($htaccess_file, $content);
        }
    }
    
    /**
     * Restore wp-config.php from backup
     */
    private function ayudawp_wpotweaks_restore_files() {
        $this->ayudawp_wpotweaks_restore_wp_config();
    }
    
    /**
     * Restore wp-config.php from backup
     */
    private function ayudawp_wpotweaks_restore_wp_config() {
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $wp_config_path = ABSPATH . 'wp-config.php';
        $backup_path = AYUDAWP_WPOTWEAKS_PLUGIN_PATH . 'backup/wp-config.php.bak';
        
        if ($wp_filesystem->exists($backup_path)) {
            $content = $wp_filesystem->get_contents($backup_path);
            $wp_filesystem->put_contents($wp_config_path, $content);
        }
    }
    
    /**
     * Clean .htaccess rules completely on deactivation
     */
    private function ayudawp_wpotweaks_clean_htaccess() {
        if (!function_exists('get_home_path')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $htaccess_file = get_home_path() . '.htaccess';
        
        if (!$wp_filesystem->exists($htaccess_file)) {
            return;
        }
        
        // Use direct regex cleanup instead of insert_with_markers
        // This ensures complete removal including markers
        $this->ayudawp_wpotweaks_clean_existing_rules($htaccess_file);
    }
}
