<?php
/**
 * Plugin Name: WPO Tweaks & Performance Optimizations
 * Plugin URI: https://servicios.ayudawp.com/
 * Description: Advanced performance optimizations for WordPress. Improve speed, reduce server resources, and optimize Google PageSpeed.
 * Version: 2.1.0
 * Author: Fernando Tellado
 * Author URI: https://ayudawp.com/
 * Text Domain: wpo-tweaks
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AYUDAWP_WPOTWEAKS_VERSION', '2.1.0');
define('AYUDAWP_WPOTWEAKS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AYUDAWP_WPOTWEAKS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AYUDAWP_WPOTWEAKS_INCLUDES_PATH', AYUDAWP_WPOTWEAKS_PLUGIN_PATH . 'includes/');

/**
 * Main WPO Tweaks Plugin Class
 */
class AyudaWP_WPO_Tweaks {
    
    /**
     * Plugin modules
     */
    public $modules = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load modules immediately
        $this->ayudawp_wpotweaks_load_modules();
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'ayudawp_wpotweaks_on_activation'));
        register_deactivation_hook(__FILE__, array($this, 'ayudawp_wpotweaks_on_deactivation'));
    }
    
    /**
     * Load all optimization modules - WITH ADMIN NOTICE MODULE
     */
    public function ayudawp_wpotweaks_load_modules() {
        $modules = array(
            'file-management'       => 'File_Management',
            'admin-notice'          => 'Admin_Notice',
            'critical-css'          => 'Critical_CSS',
            'image-optimization'    => 'Image_Optimization', 
            'image-dimensions'      => 'Image_Dimensions',
            'database-optimization' => 'Database_Optimization',
            'script-optimization'   => 'Script_Optimization',
            'security-tweaks'       => 'Security_Tweaks',
            'admin-optimization'    => 'Admin_Optimization',
            'cache-optimization'    => 'Cache_Optimization'
        );
        
        foreach ($modules as $file => $class) {
            $this->ayudawp_wpotweaks_load_module($file, $class);
        }
    }
    
    /**
     * Load individual module
     */
    private function ayudawp_wpotweaks_load_module($file, $class) {
        $file_path = AYUDAWP_WPOTWEAKS_INCLUDES_PATH . 'class-' . $file . '.php';
        
        if (file_exists($file_path)) {
            require_once $file_path;
            
            $class_name = 'AyudaWP_WPO_' . $class;
            
            if (class_exists($class_name)) {
                $this->modules[$file] = new $class_name();
            }
        }
    }
    
    /**
     * Plugin activation
     */
    public function ayudawp_wpotweaks_on_activation() {
        // Check PHP version compatibility
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(esc_html__('This plugin requires PHP 7.4 or higher.', 'wpo-tweaks'));
        }
        
        // Let modules handle their own activation tasks
        foreach ($this->modules as $module) {
            if (method_exists($module, 'on_activation')) {
                $module->on_activation();
            }
        }
        
        // Flush cache if available
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function ayudawp_wpotweaks_on_deactivation() {
        // Let modules handle their own deactivation tasks
        foreach ($this->modules as $module) {
            if (method_exists($module, 'on_deactivation')) {
                $module->on_deactivation();
            }
        }
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    /**
     * Get module instance
     */
    public function ayudawp_wpotweaks_get_module($module_name) {
        return isset($this->modules[$module_name]) ? $this->modules[$module_name] : false;
    }
}

// Helper functions with correct prefixes
function ayudawp_wpotweaks_get_user_agent() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return '';
    }
    return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
}

function ayudawp_wpotweaks_needs_xmlrpc() {
    return class_exists('Jetpack') || 
           is_plugin_active('jetpack/jetpack.php') ||
           apply_filters('ayudawp_wpotweaks_keep_xmlrpc', false);
}

function ayudawp_wpotweaks_needs_feeds() {
    return apply_filters('ayudawp_wpotweaks_keep_feeds', true);
}

function ayudawp_wpotweaks_is_login_page() {
    if (!isset($GLOBALS['pagenow'])) {
        return false;
    }
    return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
}

// Initialize the plugin
new AyudaWP_WPO_Tweaks();