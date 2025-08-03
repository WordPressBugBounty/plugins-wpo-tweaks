<?php
/**
 * Plugin Name: WPO Tweaks & Performance Optimizations
 * Plugin URI: https://servicios.ayudawp.com/
 * Description: Advanced performance optimizations for WordPress. Improve speed, reduce server resources, and optimize Google PageSpeed.
 * Version: 2.0.0
 * Author: Fernando Tellado
 * Author URI: https://tellado.es/
 * Text Domain: wpo-tweaks
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('AYUDAWP_WPO_VERSION', '2.0.0');
define('AYUDAWP_WPO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AYUDAWP_WPO_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Clase principal del plugin WPO Tweaks
 */
class AyudaWP_WPO_Tweaks {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', array($this, 'ayudawp_init'));
        add_action('wp_enqueue_scripts', array($this, 'ayudawp_optimize_scripts'), 999);
        add_action('wp_head', array($this, 'ayudawp_add_preconnect_hints'), 1);
        add_action('wp_head', array($this, 'ayudawp_add_dns_prefetch'), 1);
        add_action('wp_footer', array($this, 'ayudawp_defer_non_critical_css'), 999);
        
        // Optimizaciones de backend
        add_action('admin_init', array($this, 'ayudawp_admin_optimizations'));
        
        // Optimizaciones de base de datos
        add_action('wp_loaded', array($this, 'ayudawp_database_optimizations'));
        
        // Optimizaciones de imágenes
        add_filter('wp_get_attachment_image_attributes', array($this, 'ayudawp_add_loading_lazy'), 10, 3);
        
        // Optimizaciones de seguridad ligeras
        add_action('init', array($this, 'ayudawp_security_tweaks'));
        
        // Limpieza del head
        add_action('after_setup_theme', array($this, 'ayudawp_clean_header'));
        
        // Optimizaciones de caché
        add_action('init', array($this, 'ayudawp_cache_optimizations'));
        
        // Optimización de CSS crítico
        add_action('wp_head', array($this, 'ayudawp_inline_critical_css'), 1);
        add_filter('style_loader_tag', array($this, 'ayudawp_defer_non_critical_css_filter'), 10, 4);
        
        // JavaScript diferido (método original mejorado)
        add_filter('script_loader_tag', array($this, 'ayudawp_defer_parsing_of_js'), 10, 2);
        
        // Optimizaciones clásicas del plugin original
        add_action('pre_ping', array($this, 'ayudawp_no_self_ping'));
        add_filter('admin_footer_text', array($this, 'ayudawp_change_admin_footer_text'));
        add_action('wp_print_styles', array($this, 'ayudawp_remove_dashicons_for_non_logged'), 100);
        add_filter('heartbeat_settings', array($this, 'ayudawp_control_heartbeat'));
        add_filter('script_loader_src', array($this, 'ayudawp_remove_script_version'), 15, 1);
        add_filter('style_loader_src', array($this, 'ayudawp_remove_script_version'), 15, 1);
        add_filter('get_avatar_url', array($this, 'ayudawp_avatar_remove_querystring'));
        add_filter('fallback_intermediate_image_sizes', array($this, 'ayudawp_disable_pdf_previews'));
        
        // REST API y JSON
        add_filter('json_enabled', '__return_false');
        add_filter('json_jsonp_enabled', '__return_false');
        
        // Activación y desactivación del plugin
        register_activation_hook(__FILE__, array($this, 'ayudawp_on_activation'));
        register_deactivation_hook(__FILE__, array($this, 'ayudawp_on_deactivation'));
    }
    
    /**
     * Inicialización del plugin
     */
    public function ayudawp_init() {
        // Eliminar acciones innecesarias
        $this->ayudawp_remove_unnecessary_actions();
        
        // Optimizar queries
        $this->ayudawp_optimize_queries();
        
        // Optimizar feeds
        $this->ayudawp_optimize_feeds();
    }
    
    /**
     * Deshabilitar self pingbacks (función original)
     */
    public function ayudawp_no_self_ping(&$links) {
        $home = get_option('home');
        foreach ($links as $l => $link) {
            if (0 === strpos($link, $home)) {
                unset($links[$l]);
            }
        }
    }
    
    /**
     * Cambiar texto del footer del admin (función original)
     */
    public function ayudawp_change_admin_footer_text($text) {
        return sprintf(
            /* translators: %s: URL to WPO Tweaks plugin page */
            __('Powered by <a target="_blank" href="https://wordpress.org/">WordPress</a> | Optimized with <a href="%s" title="WPO Tweaks by Fernando Tellado" target="_blank">WPO Tweaks</a>', 'wpo-tweaks'),
            'https://wordpress.org/plugins/wpo-tweaks/'
        );
    }
    
    /**
     * Eliminar Dashicons para usuarios no logueados (función original mejorada)
     */
    public function ayudawp_remove_dashicons_for_non_logged() {
        if (!is_admin_bar_showing() && !is_customize_preview()) {
            wp_deregister_style('dashicons');
        }
    }
    
    /**
     * Control del Heartbeat API (función original)
     */
    public function ayudawp_control_heartbeat($settings) {
        $settings['interval'] = 60;
        return $settings;
    }
    
    /**
     * Eliminar versiones de scripts y estilos (función original)
     */
    public function ayudawp_remove_script_version($src) {
        $parts = explode('?ver', $src);
        return $parts[0];
    }
    
    /**
     * Eliminar query strings de Gravatar (función original)
     */
    public function ayudawp_avatar_remove_querystring($url) {
        $url_parts = explode('?', $url);
        return $url_parts[0];
    }
    
    /**
     * Deshabilitar previews de PDF (función original)
     */
    public function ayudawp_disable_pdf_previews() {
        return array();
    }
    
    /**
     * Diferir parsing de JavaScript (función original mejorada)
     */
    public function ayudawp_defer_parsing_of_js($tag, $handle) {
        if (is_admin()) {
            return $tag;
        }
        
        // No diferir jQuery
        if (strpos($tag, '/wp-includes/js/jquery/jquery')) {
            return $tag;
        }
        
        // Verificar user agent para compatibilidad con IE9
        $user_agent = ayudawp_get_user_agent();
        if (!empty($user_agent) && strpos($user_agent, 'MSIE 9.') !== false) {
            return $tag;
        }
        
        return str_replace(' src', ' defer src', $tag);
    }
    
    /**
     * Optimizaciones de scripts y estilos (nuevas funcionalidades)
     */
    public function ayudawp_optimize_scripts() {
        // Eliminar jQuery Migrate si no es necesario
        if (!is_admin() && !ayudawp_is_login_page()) {
            global $wp_scripts;
            if (isset($wp_scripts->registered['jquery'])) {
                $jquery_dependencies = $wp_scripts->registered['jquery']->deps;
                $wp_scripts->registered['jquery']->deps = array_diff($jquery_dependencies, array('jquery-migrate'));
            }
        }
        
        // Optimizar Google Fonts
        add_filter('style_loader_src', array($this, 'ayudawp_optimize_google_fonts'), 10, 2);
        
        // Eliminar scripts innecesarios en frontend
        if (!is_admin()) {
            wp_dequeue_script('wp-embed');
            wp_deregister_script('wp-embed');
            
            // Eliminar Dashicons para usuarios no logueados
            if (!is_user_logged_in()) {
                wp_dequeue_style('dashicons');
                wp_deregister_style('dashicons');
            }
        }
    }
    
    /**
     * Añadir hints de preconnect
     */
    public function ayudawp_add_preconnect_hints() {
        $preconnects = array(
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
            'https://www.google-analytics.com',
            'https://www.googletagmanager.com'
        );
        
        $preconnects = apply_filters('ayudawp_preconnect_hints', $preconnects);
        
        foreach ($preconnects as $url) {
            echo '<link rel="preconnect" href="' . esc_url($url) . '" crossorigin>' . "\n";
        }
    }
    
    /**
     * Añadir DNS prefetch
     */
    public function ayudawp_add_dns_prefetch() {
        $prefetch_domains = array(
            '//fonts.googleapis.com',
            '//fonts.gstatic.com',
            '//ajax.googleapis.com',
            '//www.google-analytics.com',
            '//stats.wp.com',
            '//gravatar.com',
            '//s.w.org'
        );
        
        $prefetch_domains = apply_filters('ayudawp_dns_prefetch_domains', $prefetch_domains);
        
        foreach ($prefetch_domains as $domain) {
            echo '<link rel="dns-prefetch" href="' . esc_url($domain) . '">' . "\n";
        }
    }
    
    /**
     * Script para diferir CSS no crítico
     */
    public function ayudawp_defer_non_critical_css() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var links = document.querySelectorAll('link[data-defer="true"]');
            links.forEach(function(link) {
                link.rel = 'stylesheet';
                link.removeAttribute('data-defer');
            });
        });
        </script>
        <?php
    }
    
    /**
     * Optimizaciones del área de administración
     */
    public function ayudawp_admin_optimizations() {
        // Reducir revisiones automáticas
        if (!defined('WP_POST_REVISIONS')) {
            define('WP_POST_REVISIONS', 3);
        }
        
        // Aumentar el tiempo de limpieza de papelera
        if (!defined('EMPTY_TRASH_DAYS')) {
            define('EMPTY_TRASH_DAYS', 7);
        }
        
        // Desactivar el editor de archivos
        if (!defined('DISALLOW_FILE_EDIT')) {
            define('DISALLOW_FILE_EDIT', true);
        }
        
        // Eliminar widgets innecesarios del dashboard
        add_action('wp_dashboard_setup', array($this, 'ayudawp_remove_dashboard_widgets'));
    }
    
    /**
     * Eliminar widgets del dashboard
     */
    public function ayudawp_remove_dashboard_widgets() {
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        remove_meta_box('dashboard_secondary', 'dashboard', 'side');
        remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_recent_drafts', 'dashboard', 'side');
        remove_meta_box('dashboard_php_nag', 'dashboard', 'normal');
        remove_meta_box('dashboard_browser_nag', 'dashboard', 'normal');
        remove_meta_box('health_check_status', 'dashboard', 'normal');
    }
    
    /**
     * Optimizaciones de base de datos
     */
    public function ayudawp_database_optimizations() {
        // Limpiar transients caducados automáticamente
        add_action('wp_scheduled_delete', array($this, 'ayudawp_clean_expired_transients'));
        
        // Optimizar consultas de comentarios
        add_filter('comments_clauses', array($this, 'ayudawp_optimize_comments_query'), 10, 2);
    }
    
    /**
     * Limpiar transients caducados usando WordPress methods
     */
    public function ayudawp_clean_expired_transients() {
        // Usar caché de WordPress y verificar si ya existe
        $cache_key = 'ayudawp_cleaning_transients';
        if (wp_cache_get($cache_key)) {
            return; // Ya se está ejecutando
        }
        
        // Marcar que se está ejecutando
        wp_cache_set($cache_key, true, '', 300); // 5 minutos
        
        // Usar WordPress functions para limpiar transients en lugar de SQL directo
        $this->ayudawp_clean_transients_wp_way();
        
        // Limpiar caché después de la operación
        wp_cache_delete($cache_key);
        
        // Cachear que la limpieza se completó
        wp_cache_set('ayudawp_last_transient_cleanup', time(), '', HOUR_IN_SECONDS);
    }
    
    /**
     * Limpiar transients usando métodos de WordPress
     */
    private function ayudawp_clean_transients_wp_way() {
        // Obtener todos los transients usando WordPress API
        $transients = get_option('_transient_timeout_*', array());
        
        if (empty($transients)) {
            return;
        }
        
        $current_time = time();
        $cleaned = 0;
        
        // Iterar sobre transients conocidos del plugin
        $plugin_transients = array(
            'ayudawp_critical_css_',
            'ayudawp_cleaning_transients',
            'ayudawp_last_transient_cleanup'
        );
        
        foreach ($plugin_transients as $transient_prefix) {
            $timeout_value = get_option('_transient_timeout_' . $transient_prefix);
            if ($timeout_value && $timeout_value < $current_time) {
                delete_transient($transient_prefix);
                $cleaned++;
            }
        }
        
        // Límite de seguridad para evitar timeouts
        if ($cleaned > 50) {
            return;
        }
    }
    
    /**
     * Optimizar consultas de comentarios
     */
    public function ayudawp_optimize_comments_query($clauses, $query) {
        if (!is_admin() && !empty($clauses['where'])) {
            $clauses['where'] .= " AND comment_approved = '1'";
        }
        return $clauses;
    }
    
    /**
     * Añadir loading lazy a imágenes
     */
    public function ayudawp_add_loading_lazy($attr, $attachment, $size) {
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        
        if (!isset($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        
        return $attr;
    }
    
    /**
     * Tweaks de seguridad ligeros
     */
    public function ayudawp_security_tweaks() {
        // Eliminar información de versión
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');
        
        // Ocultar errores de login
        add_filter('login_errors', function() {
            return __('Incorrect login information.', 'wpo-tweaks');
        });
        
        // Eliminar X-Pingback header
        add_filter('wp_headers', function($headers) {
            unset($headers['X-Pingback']);
            return $headers;
        });
        
        // Desactivar XML-RPC si no se necesita
        if (!ayudawp_needs_xmlrpc()) {
            add_filter('xmlrpc_enabled', '__return_false');
        }
    }
    
    /**
     * Limpiar el head de WordPress (función original expandida)
     */
    public function ayudawp_clean_header() {
        // Eliminar enlaces innecesarios
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wp_resource_hints', 2);
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wp_shortlink_wp_head', 10, 0);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_action('wp_head', 'index_rel_link');
        remove_action('wp_head', 'feed_links_extra', 3);
        remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
        remove_action('wp_head', 'adjacent_posts_rel_link', 10, 0);
        remove_action('wp_head', 'start_post_rel_link', 10, 0);
        remove_action('wp_head', 'parent_post_rel_link', 10, 0);
        
        // Limpiar solo si no es necesario para SEO
        if (!ayudawp_needs_feeds()) {
            remove_action('wp_head', 'feed_links', 2);
        }
        
        // Eliminar filtros Capital P Dangit
        remove_filter('the_title', 'capital_P_dangit', 11);
        remove_filter('the_content', 'capital_P_dangit', 11);
        remove_filter('comment_text', 'capital_P_dangit', 31);
        
        add_filter('the_generator', '__return_false');
    }
    
    /**
     * Optimizaciones de caché
     */
    public function ayudawp_cache_optimizations() {
        // Precargar recursos críticos
        add_action('wp_head', array($this, 'ayudawp_preload_critical_resources'), 1);
    }
    
    /**
     * Precargar recursos críticos
     */
    public function ayudawp_preload_critical_resources() {
        // Precargar CSS del tema
        $theme_css = get_stylesheet_uri();
        echo '<link rel="preload" href="' . esc_url($theme_css) . '" as="style">' . "\n";
        
        // Precargar fuentes críticas si existen
        $critical_fonts = apply_filters('ayudawp_critical_fonts', array());
        foreach ($critical_fonts as $font_url) {
            echo '<link rel="preload" href="' . esc_url($font_url) . '" as="font" type="font/woff2" crossorigin>' . "\n";
        }
    }
    
    /**
     * CSS crítico inline automático
     */
    public function ayudawp_inline_critical_css() {
        $critical_css = $this->ayudawp_get_critical_css();
        
        if (!empty($critical_css)) {
            echo '<style id="ayudawp-critical-css">' . esc_html($critical_css) . '</style>' . "\n";
        }
    }
    
    /**
     * Obtener CSS crítico (versión simplificada)
     */
    private function ayudawp_get_critical_css() {
        $cache_key = 'ayudawp_critical_css_' . get_template();
        $critical_css = wp_cache_get($cache_key);
        
        if ($critical_css !== false) {
            return $critical_css;
        }
        
        // Verificar transient como fallback
        $critical_css = get_transient($cache_key);
        if ($critical_css !== false) {
            wp_cache_set($cache_key, $critical_css, '', WEEK_IN_SECONDS);
            return $critical_css;
        }
        
        // CSS crítico básico
        $critical_css = '
        html{font-family:sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
        body{margin:0;padding:0;line-height:1.6}
        *,*:before,*:after{box-sizing:border-box}
        img{max-width:100%;height:auto;border:0}
        .screen-reader-text{clip:rect(1px,1px,1px,1px);position:absolute!important;height:1px;width:1px;overflow:hidden}
        ';
        
        $critical_css = apply_filters('ayudawp_critical_css', $critical_css);
        
        // Cachear en ambos sistemas
        wp_cache_set($cache_key, $critical_css, '', WEEK_IN_SECONDS);
        set_transient($cache_key, $critical_css, WEEK_IN_SECONDS);
        
        return $critical_css;
    }
    
    /**
     * Diferir CSS no crítico mediante filtro
     */
    public function ayudawp_defer_non_critical_css_filter($tag, $handle, $href, $media) {
        if (is_admin() || $this->ayudawp_is_critical_css_handle($handle)) {
            return $tag;
        }
        
        $deferred_tag = str_replace('rel="stylesheet"', 'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"', $tag);
        $noscript = '<noscript>' . $tag . '</noscript>';
        
        return $deferred_tag . $noscript;
    }
    
    /**
     * Verificar si el handle CSS es crítico
     */
    private function ayudawp_is_critical_css_handle($handle) {
        $critical_handles = array(
            get_template(),
            get_stylesheet(),
            'admin-bar'
        );
        
        if (is_user_logged_in()) {
            $critical_handles[] = 'dashicons';
        }
        
        return in_array($handle, apply_filters('ayudawp_critical_css_handles', $critical_handles));
    }
    
    /**
     * Eliminar acciones innecesarias
     */
    private function ayudawp_remove_unnecessary_actions() {
        // Optimizar heartbeat ya configurado en función original
        
        // Desactivar pingbacks automáticos
        add_filter('xmlrpc_methods', function($methods) {
            unset($methods['pingback.ping']);
            unset($methods['pingback.extensions.getPingbacks']);
            return $methods;
        });
    }
    
    /**
     * Optimizar queries principales
     */
    private function ayudawp_optimize_queries() {
        add_action('pre_get_posts', function($query) {
            if (!is_admin() && $query->is_main_query()) {
                if ($query->is_archive() || $query->is_home()) {
                    $query->set('no_found_rows', true);
                }
            }
        });
    }
    
    /**
     * Optimizar feeds
     */
    private function ayudawp_optimize_feeds() {
        add_action('do_feed_rss2', function() {
            header('Cache-Control: public, max-age=3600');
        }, 1);
        
        add_filter('pre_option_posts_per_rss', function() {
            return '10';
        });
    }
    
    /**
     * Optimizar Google Fonts
     */
    public function ayudawp_optimize_google_fonts($src, $handle) {
        if (strpos($src, 'fonts.googleapis.com') !== false) {
            if (strpos($src, 'display=') === false) {
                $src = add_query_arg('display', 'swap', $src);
            }
        }
        
        return $src;
    }
    
    /**
     * Activación del plugin
     */
    public function ayudawp_on_activation() {
        // Verificar compatibilidad
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(esc_html__('This plugin requires PHP 7.4 or higher.', 'wpo-tweaks'));
        }
        
        // Limpiar caché si existe
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Programar limpieza de transients
        if (!wp_next_scheduled('ayudawp_clean_transients')) {
            wp_schedule_event(time(), 'daily', 'ayudawp_clean_transients');
        }
        
        // Añadir reglas .htaccess (función original)
        $this->ayudawp_htaccess();
    }
    
    /**
     * Desactivación del plugin
     */
    public function ayudawp_on_deactivation() {
        // Limpiar eventos programados
        wp_clear_scheduled_hook('ayudawp_clean_transients');
        
        // Limpiar transients del plugin
        $this->ayudawp_clear_plugin_transients();
        
        // Eliminar reglas .htaccess (función original)
        $this->ayudawp_delete_tweaks_htaccess();
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    /**
     * Limpiar transients del plugin usando caché
     */
    private function ayudawp_clear_plugin_transients() {
        // Usar caché para evitar ejecutar múltiples veces
        $cache_key = 'ayudawp_clearing_plugin_transients';
        if (wp_cache_get($cache_key)) {
            return;
        }
        
        wp_cache_set($cache_key, true, '', 300);
        
        // Usar WordPress API en lugar de consultas directas
        $plugin_transients = array(
            'ayudawp_critical_css_' . get_template(),
            'ayudawp_cleaning_transients',
            'ayudawp_clearing_plugin_transients',
            'ayudawp_last_transient_cleanup',
            'ayudawp_plugin_transients_cleared',
            'ayudawp_last_scheduled_cleanup',
            'ayudawp_scheduled_cleanup_running'
        );
        
        $deleted_count = 0;
        foreach ($plugin_transients as $transient) {
            if (delete_transient($transient)) {
                $deleted_count++;
            }
        }
        
        wp_cache_delete($cache_key);
        wp_cache_set('ayudawp_plugin_transients_cleared', $deleted_count, '', HOUR_IN_SECONDS);
    }
    
    /**
     * Reglas .htaccess usando WP_Filesystem
     */
    public function ayudawp_htaccess() {
        if (!function_exists('get_home_path')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $htaccess_file = get_home_path() . '.htaccess';
        
        // Usar WP_Filesystem para verificar permisos
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        if (!$wp_filesystem->exists($htaccess_file) || !$wp_filesystem->is_writable($htaccess_file)) {
            return;
        }
        
        $lines = array();
        $lines[] = '<IfModule mod_expires.c>';
        $lines[] = '# Activar caducidad de contenido';
        $lines[] = 'ExpiresActive On';
        $lines[] = '# Directiva de caducidad por defecto';
        $lines[] = 'ExpiresDefault "access plus 1 month"';
        $lines[] = '# Para el favicon';
        $lines[] = 'ExpiresByType image/x-icon "access plus 1 year"';
        $lines[] = '# Imagenes';
        $lines[] = 'ExpiresByType image/gif "access plus 1 month"';
        $lines[] = 'ExpiresByType image/png "access plus 1 month"';
        $lines[] = 'ExpiresByType image/jpg "access plus 1 month"';
        $lines[] = 'ExpiresByType image/jpeg "access plus 1 month"';
        $lines[] = 'ExpiresByType image/webp "access plus 1 month"';
        $lines[] = '# CSS';
        $lines[] = 'ExpiresByType text/css "access 1 month"';
        $lines[] = '# Javascript';
        $lines[] = 'ExpiresByType application/javascript "access plus 1 year"';
        $lines[] = '# Fuentes';
        $lines[] = 'ExpiresByType font/woff "access plus 1 year"';
        $lines[] = 'ExpiresByType font/woff2 "access plus 1 year"';
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_deflate.c>';
        $lines[] = '# Activar compresión de contenidos estáticos';
        $lines[] = 'AddOutputFilterByType DEFLATE text/plain text/html';
        $lines[] = 'AddOutputFilterByType DEFLATE text/xml application/xml application/xhtml+xml application/xml-dtd';
        $lines[] = 'AddOutputFilterByType DEFLATE application/rdf+xml application/rss+xml application/atom+xml image/svg+xml';
        $lines[] = 'AddOutputFilterByType DEFLATE text/css text/javascript application/javascript application/x-javascript';
        $lines[] = 'AddOutputFilterByType DEFLATE font/otf font/opentype application/font-otf application/x-font-otf';
        $lines[] = 'AddOutputFilterByType DEFLATE font/ttf font/truetype application/font-ttf application/x-font-ttf';
        $lines[] = '</IfModule>';
        $lines[] = '<IfModule mod_headers.c>';
        $lines[] = '<FilesMatch "\.(css|js|png|jpg|jpeg|gif|webp|woff|woff2)$">';
        $lines[] = 'Header set Cache-Control "max-age=31536000, public"';
        $lines[] = '</FilesMatch>';
        $lines[] = '</IfModule>';
        
        insert_with_markers($htaccess_file, 'WPO Tweaks by Fernando Tellado', $lines);
    }
    
    /**
     * Eliminar reglas .htaccess usando WP_Filesystem
     */
    public function ayudawp_delete_tweaks_htaccess() {
        if (!function_exists('get_home_path')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $htaccess_file = get_home_path() . '.htaccess';
        
        // Usar WP_Filesystem para verificar permisos
        global $wp_filesystem;
        
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        if ($wp_filesystem->exists($htaccess_file) && $wp_filesystem->is_writable($htaccess_file)) {
            insert_with_markers($htaccess_file, 'WPO Tweaks by Fernando Tellado', array());
        }
    }
}

// Funciones auxiliares

/**
 * Obtener user agent sanitizado
 */
function ayudawp_get_user_agent() {
    if (!isset($_SERVER['HTTP_USER_AGENT'])) {
        return '';
    }
    
    return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
}

/**
 * Verificar si se necesita XML-RPC
 */
function ayudawp_needs_xmlrpc() {
    return class_exists('Jetpack') || 
           is_plugin_active('jetpack/jetpack.php') ||
           apply_filters('ayudawp_keep_xmlrpc', false);
}

/**
 * Verificar si se necesitan feeds
 */
function ayudawp_needs_feeds() {
    return apply_filters('ayudawp_keep_feeds', true);
}

/**
 * Verificar si es página de login
 */
function ayudawp_is_login_page() {
    return in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));
}

// Inicializar el plugin
new AyudaWP_WPO_Tweaks();

// Acción para limpiar transients programada con caché
add_action('ayudawp_clean_transients', function() {
    // Usar caché para evitar ejecuciones múltiples
    $cache_key = 'ayudawp_scheduled_cleanup_running';
    if (wp_cache_get($cache_key)) {
        return;
    }
    
    wp_cache_set($cache_key, true, '', 300); // 5 minutos
    
    // Usar WordPress API en lugar de consultas SQL directas
    $cleaned_count = 0;
    $current_time = time();
    
    // Lista de transients conocidos para limpiar
    $known_transients = array(
        'ayudawp_critical_css_',
        'ayudawp_cleaning_transients',
        'ayudawp_last_transient_cleanup'
    );
    
    foreach ($known_transients as $transient_prefix) {
        // Verificar timeout y eliminar si ha caducado
        $timeout_option = get_option('_transient_timeout_' . $transient_prefix);
        if ($timeout_option && $timeout_option < $current_time) {
            if (delete_transient($transient_prefix)) {
                $cleaned_count++;
            }
        }
    }
    
    // Limpiar transients genéricos caducados usando WordPress core
    if (function_exists('delete_expired_transients')) {
        delete_expired_transients();
    }
    
    wp_cache_delete($cache_key);
    wp_cache_set('ayudawp_last_scheduled_cleanup', $cleaned_count, '', DAY_IN_SECONDS);
});