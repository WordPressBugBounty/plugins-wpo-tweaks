<?php
/**
 * DietPress .htaccess rules.
 *
 * Writes server-level performance directives (browser caching, GZIP/Brotli
 * compression, cache headers, CORS for fonts, keep-alive) into the site
 * .htaccess via the WordPress markers API, with a backup and idempotent writes.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Htaccess {

	/** @var Core_Diet_Settings */
	private $settings;

	/**
	 * Marker name for .htaccess rules.
	 *
	 * @var string
	 */
	private $htaccess_marker = 'DietPress';

	/**
	 * Legacy marker names, cleaned up on every rewrite: the block written by
	 * wpo-tweaks 2.x ("Zero Config Performance") and the pre-2.x one. Sites
	 * updating get their old block replaced by the DietPress one the first
	 * time the rules are (re)written.
	 *
	 * @var array
	 */
	private $legacy_markers = array( 'Zero Config Performance', 'WPO Tweaks by Fernando Tellado' );

	/**
	 * @param Core_Diet_Settings $settings Settings instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Initialize hooks.
	 *
	 * The .htaccess is never written on activation. Instead we react to the
	 * settings option being saved. These listeners are registered
	 * unconditionally because they only fire on save; the master toggle is
	 * evaluated inside the callback.
	 */
	public function init() {
		add_action( 'update_option_' . Core_Diet_Settings::OPTION_NAME, array( $this, 'on_settings_saved' ), 10, 2 );
		add_action( 'add_option_' . Core_Diet_Settings::OPTION_NAME, array( $this, 'on_settings_added' ), 10, 2 );
	}

	/**
	 * Handle the option being created for the first time.
	 *
	 * add_option passes ( $option, $value ); normalize to the ( $old, $new )
	 * shape used by on_settings_saved.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New option value.
	 */
	public function on_settings_added( $option, $value ) {
		$this->on_settings_saved( array(), $value );
	}

	/**
	 * Handle the settings being saved.
	 *
	 * Rewrites or cleans the plugin .htaccess block depending on the master
	 * toggle. Idempotent: if the block already matches, nothing is written.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function on_settings_saved( $old, $new ) {
		// Disk writes are privileged. Bail if the current request lacks the cap.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Master toggle off: remove our block entirely.
		if ( ! $this->settings->is_enabled( 'htaccess_rules' ) ) {
			$this->clean_htaccess();
			return;
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// insert_with_markers() lives in wp-admin/includes/misc.php.
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// extract_from_markers() also lives in misc.php (loaded above).
		if ( ! function_exists( 'extract_from_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$htaccess_file = get_home_path() . '.htaccess';

		// Fail gracefully: do not write if the file is not writable.
		if ( $wp_filesystem->exists( $htaccess_file ) && ! $wp_filesystem->is_writable( $htaccess_file ) ) {
			add_settings_error(
				Core_Diet_Settings::OPTION_NAME,
				'core_diet_htaccess_not_writable',
				esc_html__( 'The .htaccess file is not writable, so the performance rules could not be saved.', 'wpo-tweaks' ),
				'error'
			);
			return;
		}

		// Build the new block as a normalized string for comparison.
		$lines      = $this->core_diet_get_htaccess_rules();
		$new_block  = is_array( $lines ) ? implode( "\n", $lines ) : (string) $lines;

		// Compare against what is currently written under our marker.
		// extract_from_markers() returns the lines between BEGIN/END.
		$current_block = '';
		if ( $wp_filesystem->exists( $htaccess_file ) && function_exists( 'extract_from_markers' ) ) {
			$current_block = implode( "\n", extract_from_markers( $htaccess_file, $this->htaccess_marker ) );
		}

		// Idempotent: if our block already matches, do nothing.
		if ( $current_block === $new_block ) {
			return;
		}

		// Back up the .htaccess before the first modification.
		$this->core_diet_create_backup_directory();
		$this->core_diet_backup_htaccess();

		$this->core_diet_modify_htaccess();
	}

	/**
	 * Create backup directory.
	 */
	private function core_diet_create_backup_directory() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$backup_dir = CORE_DIET_DIR . 'backup/';

		if ( ! $wp_filesystem->exists( $backup_dir ) ) {
			$wp_filesystem->mkdir( $backup_dir, 0755 );
		}

		// Prevent direct access to backup directory.
		$htaccess_backup = $backup_dir . '.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess_backup ) ) {
			$wp_filesystem->put_contents( $htaccess_backup, "deny from all\n" );
		}
	}

	/**
	 * Backup .htaccess before modification.
	 */
	private function core_diet_backup_htaccess() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$htaccess_path = get_home_path() . '.htaccess';
		$backup_path   = CORE_DIET_DIR . 'backup/.htaccess.bak';

		if ( $wp_filesystem->exists( $htaccess_path ) ) {
			$content = $wp_filesystem->get_contents( $htaccess_path );
			$wp_filesystem->put_contents( $backup_path, $content );
		}
	}

	/**
	 * Remove legacy wp-config.php modifications from previous versions.
	 *
	 * Previous versions (<= 2.2.0) added EMPTY_TRASH_DAYS to wp-config.php.
	 * This safely removes those additions on upgrade. Trash retention is now
	 * handled via cron in the Database Optimization module.
	 *
	 * Public so the orchestrator can call it once from the upgrade routine.
	 * It is NOT called automatically.
	 */
	public function core_diet_cleanup_legacy_wp_config() {
		// Writing to wp-config.php is privileged. Guard against unexpected callers.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$wp_config_path = ABSPATH . 'wp-config.php';

		if ( ! $wp_filesystem->exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
			return;
		}

		$content          = $wp_filesystem->get_contents( $wp_config_path );
		$original_content = $content;

		// Only remove our complete block: comment + define together as a unit.
		// This avoids accidentally deleting EMPTY_TRASH_DAYS set by the user or another plugin.
		$content = preg_replace(
			'/\n?\/\/\s*Zero Config Performance Configuration\s*\n\s*define\s*\(\s*[\'"]EMPTY_TRASH_DAYS[\'"]\s*,\s*\d+\s*\)\s*;\s*\n?/',
			"\n",
			$content
		);

		// Clean up multiple consecutive blank lines left behind.
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		// Only write if we actually changed something.
		if ( $content !== $original_content ) {
			$wp_filesystem->put_contents( $wp_config_path, $content );
		}

		// Also remove old wp-config backup file if it exists.
		$backup_path = CORE_DIET_DIR . 'backup/wp-config.php.bak';
		if ( $wp_filesystem->exists( $backup_path ) ) {
			$wp_filesystem->delete( $backup_path );
		}
	}

	/**
	 * Modify .htaccess with optimized rules.
	 */
	private function core_diet_modify_htaccess() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// insert_with_markers() lives in wp-admin/includes/misc.php.
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$htaccess_file = get_home_path() . '.htaccess';

		if ( ! $wp_filesystem->exists( $htaccess_file ) || ! $wp_filesystem->is_writable( $htaccess_file ) ) {
			return false;
		}

		// Clean any existing rules (both legacy and current).
		$this->core_diet_clean_existing_rules( $htaccess_file );

		// Build optimized .htaccess rules.
		$lines = $this->core_diet_get_htaccess_rules();

		return insert_with_markers( $htaccess_file, $this->htaccess_marker, $lines );
	}

	/**
	 * Get optimized .htaccess rules.
	 *
	 * Each <IfModule>/block is included only when its sub-toggle is enabled.
	 * The directives within each block are kept exactly as the original.
	 *
	 * @return array Array of .htaccess rules.
	 */
	private function core_diet_get_htaccess_rules() {
		$lines = array();

		// MIME types and charset. Always emitted with the master toggle on:
		// without an explicit AddType, older Apache builds serve AVIF as
		// application/octet-stream (so the ExpiresByType rule below never
		// applies). The charset is taken from the site option, defaulting to
		// UTF-8, and restricted to a safe token.
		$lines[] = '# MIME types and charset';
		$lines[] = '<IfModule mod_mime.c>';
		$lines[] = 'AddType image/avif .avif';
		$lines[] = 'AddType image/avif-sequence .avifs';
		$lines[] = '</IfModule>';
		$charset = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) get_bloginfo( 'charset' ) );
		if ( '' === $charset ) {
			$charset = 'UTF-8';
		}
		$lines[] = 'AddDefaultCharset ' . $charset;
		$lines[] = '';

		// Expires Headers.
		if ( $this->settings->is_enabled( 'htaccess_expires' ) ) {
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
		}

		// GZIP Compression.
		if ( $this->settings->is_enabled( 'htaccess_gzip' ) ) {
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
		}

		// Brotli Compression (modern servers).
		if ( $this->settings->is_enabled( 'htaccess_brotli' ) ) {
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
		}

		// Cache-Control Headers (mod_headers).
		//
		// This <IfModule> wraps several distinct concerns: immutable Cache-Control,
		// Vary, ETag removal, CORS for fonts, and keep-alive. The block is opened
		// when ANY of those sub-toggles is on, and each inner section is included
		// only when its own toggle is enabled, so unrelated directives never leak
		// in.
		$open_headers = (
			$this->settings->is_enabled( 'htaccess_cache_headers' ) ||
			$this->settings->is_enabled( 'htaccess_cors_fonts' ) ||
			$this->settings->is_enabled( 'htaccess_keepalive' )
		);

		if ( $open_headers ) {
			$lines[] = '# Cache-Control Headers';
			$lines[] = '<IfModule mod_headers.c>';
			$lines[] = '';

			if ( $this->settings->is_enabled( 'htaccess_cache_headers' ) ) {
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
			}

			if ( $this->settings->is_enabled( 'htaccess_cors_fonts' ) ) {
				$lines[] = '# CORS headers for fonts (CDN compatibility)';
				$lines[] = '<FilesMatch "\\.(?:ttf|ttc|otf|eot|woff2?|font\\.css|css)$">';
				$lines[] = 'Header set Access-Control-Allow-Origin "*"';
				$lines[] = '</FilesMatch>';
				$lines[] = '';
			}

			if ( $this->settings->is_enabled( 'htaccess_keepalive' ) ) {
				$lines[] = '# Keep-Alive for connection reuse';
				$lines[] = 'Header set Connection keep-alive';
				$lines[] = '';
			}

			$lines[] = '</IfModule>';
		}

		return $lines;
	}

	/**
	 * Clean existing rules from .htaccess (both legacy and current markers).
	 *
	 * @param string $htaccess_file Path to .htaccess file.
	 */
	private function core_diet_clean_existing_rules( $htaccess_file ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
			return;
		}

		$content          = $wp_filesystem->get_contents( $htaccess_file );
		$original_content = $content;

		// Remove current marker rules.
		$pattern_current = '/# BEGIN ' . preg_quote( $this->htaccess_marker, '/' ) . '.*?# END ' . preg_quote( $this->htaccess_marker, '/' ) . '\s*/s';
		$content         = preg_replace( $pattern_current, '', $content );

		// Remove legacy marker rules (2.x and pre-2.x block names).
		foreach ( $this->legacy_markers as $legacy_marker ) {
			$pattern_legacy = '/# BEGIN ' . preg_quote( $legacy_marker, '/' ) . '.*?# END ' . preg_quote( $legacy_marker, '/' ) . '\s*/s';
			$content        = preg_replace( $pattern_legacy, '', $content );
		}

		// Remove any orphaned empty lines that might accumulate.
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		// Only write if content changed.
		if ( $content !== $original_content ) {
			$wp_filesystem->put_contents( $htaccess_file, $content );
		}
	}

	/**
	 * Clean .htaccess rules completely.
	 *
	 * Removes both the current marker block and the legacy marker block.
	 * Public so the orchestrator can call it from plugin deactivation and
	 * uninstall.
	 */
	public function clean_htaccess() {
		// Disk writes are privileged. Guard against unexpected callers.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$htaccess_file = get_home_path() . '.htaccess';

		if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
			return;
		}

		// Use direct regex cleanup instead of insert_with_markers
		// This ensures complete removal including markers.
		$this->core_diet_clean_existing_rules( $htaccess_file );
	}
}
