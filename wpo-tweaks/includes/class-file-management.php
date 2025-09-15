<?php
/**
 * File Management Module
 * Handles wp-config.php and .htaccess modifications with backups
 *
 * @package WPO_Tweaks
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AyudaWP_WPO_File_Management {
    
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
        $our_config = "\n// WPO Tweaks Configuration\ndefine('EMPTY_TRASH_DAYS', 7);\n\n";
        
        if (strpos($content, $insertion_point) !== false) {
            $content = str_replace($insertion_point, $our_config . $insertion_point, $content);
        } else {
            // Fallback: add after opening PHP tag
            $content = str_replace('<?php', '<?php' . $our_config, $content);
        }
        
        return $wp_filesystem->put_contents($wp_config_path, $content);
    }
    
    /**
     * Modify .htaccess (clean and add)
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
        
        // First clean any existing WPO Tweaks rules
        $this->ayudawp_wpotweaks_clean_existing_rules($htaccess_file);
        
        // Then add our rules
        $lines = array(
            '# WPO Tweaks Cache and Compression Rules',
            '<IfModule mod_expires.c>',
            'ExpiresActive On',
            'ExpiresDefault "access plus 1 month"',
            '# Images',
            'ExpiresByType image/x-icon "access plus 1 year"',
            'ExpiresByType image/gif "access plus 1 month"',
            'ExpiresByType image/png "access plus 1 month"',
            'ExpiresByType image/jpg "access plus 1 month"',
            'ExpiresByType image/jpeg "access plus 1 month"',
            'ExpiresByType image/webp "access plus 1 month"',
            'ExpiresByType image/svg+xml "access plus 1 month"',
            '# CSS and JavaScript',
            'ExpiresByType text/css "access plus 1 month"',
            'ExpiresByType application/javascript "access plus 1 year"',
            'ExpiresByType application/x-javascript "access plus 1 year"',
            'ExpiresByType text/javascript "access plus 1 year"',
            '# Fonts',
            'ExpiresByType font/woff "access plus 1 year"',
            'ExpiresByType font/woff2 "access plus 1 year"',
            'ExpiresByType application/font-woff "access plus 1 year"',
            'ExpiresByType application/font-woff2 "access plus 1 year"',
            'ExpiresByType font/otf "access plus 1 year"',
            'ExpiresByType font/ttf "access plus 1 year"',
            'ExpiresByType application/font-otf "access plus 1 year"',
            'ExpiresByType application/font-ttf "access plus 1 year"',
            '# Other files',
            'ExpiresByType application/pdf "access plus 1 month"',
            'ExpiresByType application/xml "access plus 0 seconds"',
            'ExpiresByType text/xml "access plus 0 seconds"',
            'ExpiresByType application/json "access plus 0 seconds"',
            '</IfModule>',
            '',
            '<IfModule mod_deflate.c>',
            '# Enable compression',
            'SetOutputFilter DEFLATE',
            '# Exclude already compressed files',
            'SetEnvIfNoCase Request_URI \\.(?:gif|jpe?g|png)$ no-gzip dont-vary',
            'SetEnvIfNoCase Request_URI \\.(?:exe|t?gz|zip|bz2|sit|rar)$ no-gzip dont-vary',
            'SetEnvIfNoCase Request_URI \\.pdf$ no-gzip dont-vary',
            '# Compress text files',
            'AddOutputFilterByType DEFLATE text/plain text/html',
            'AddOutputFilterByType DEFLATE text/xml application/xml application/xhtml+xml',
            'AddOutputFilterByType DEFLATE application/rdf+xml application/rss+xml application/atom+xml',
            'AddOutputFilterByType DEFLATE image/svg+xml',
            'AddOutputFilterByType DEFLATE text/css',
            'AddOutputFilterByType DEFLATE text/javascript application/javascript application/x-javascript',
            'AddOutputFilterByType DEFLATE application/json',
            '# Compress fonts',
            'AddOutputFilterByType DEFLATE font/otf font/opentype',
            'AddOutputFilterByType DEFLATE font/ttf font/truetype',
            'AddOutputFilterByType DEFLATE application/font-otf application/x-font-otf',
            'AddOutputFilterByType DEFLATE application/font-ttf application/x-font-ttf',
            '</IfModule>',
            '',
            '<IfModule mod_headers.c>',
            '# Cache static files for 1 year',
            '<FilesMatch "\\.(?:css|js|png|jpg|jpeg|gif|webp|woff|woff2|ttf|otf|eot|svg|ico)$">',
            'Header set Cache-Control "max-age=31536000, public"',
            '</FilesMatch>',
            '# Cache HTML for 1 hour',
            '<FilesMatch "\\.(?:html|htm)$">',
            'Header set Cache-Control "max-age=3600, public"',
            '</FilesMatch>',
            '# Remove ETags for static files',
            '<FilesMatch "\\.(?:css|js|png|jpg|jpeg|gif|webp|woff|woff2|ttf|otf|eot|svg|ico)$">',
            'Header unset ETag',
            'FileETag None',
            '</FilesMatch>',
            '</IfModule>'
        );
        
        return insert_with_markers($htaccess_file, 'WPO Tweaks by Fernando Tellado', $lines);
    }
    
    /**
     * Clean existing WPO Tweaks rules from .htaccess
     */
    private function ayudawp_wpotweaks_clean_existing_rules($htaccess_file) {
        global $wp_filesystem;
        
        if (!$wp_filesystem->exists($htaccess_file)) {
            return;
        }
        
        $content = $wp_filesystem->get_contents($htaccess_file);
        
        // Remove everything between our markers (including markers)
        $pattern = '/# BEGIN WPO Tweaks by Fernando Tellado.*?# END WPO Tweaks by Fernando Tellado\s*/s';
        $content = preg_replace($pattern, '', $content);
        
        $wp_filesystem->put_contents($htaccess_file, $content);
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
     * Clean .htaccess rules (called on deactivation)
     */
    private function ayudawp_wpotweaks_clean_htaccess() {
        if (!function_exists('get_home_path')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $htaccess_file = get_home_path() . '.htaccess';
        
        // Use insert_with_markers with empty array to clean our rules
        insert_with_markers($htaccess_file, 'WPO Tweaks by Fernando Tellado', array());
    }
}