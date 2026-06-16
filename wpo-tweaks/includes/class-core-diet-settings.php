<?php
/**
 * DietPress Settings handler.
 *
 * Manages all plugin options: defaults, retrieval, and sanitization.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Settings {

	/** @var string Option name in wp_options. */
	const OPTION_NAME = 'core_diet_settings';

	/** @var string Settings group for register_setting(). */
	const OPTION_GROUP = 'core_diet_options_group';

	/** @var Core_Diet_Settings|null */
	private static $instance = null;

	/** @var array|null Cached settings. */
	private $settings = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Core_Diet_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional fallback (uses built-in default if null).
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		if ( null === $this->settings ) {
			$this->settings = get_option( self::OPTION_NAME, self::get_defaults() );
		}

		if ( isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}

		$defaults = self::get_defaults();
		if ( null !== $default ) {
			return $default;
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}

	/**
	 * Check if a boolean toggle is enabled.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_enabled( $key ) {
		return (bool) $this->get( $key, false );
	}

	/**
	 * Get all default settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			// --- Light tab ---
			'disable_emojis'            => true,
			'disable_rsd_link'          => false,
			'disable_wlw_manifest'      => false,
			'disable_shortlink'         => false,
			'disable_self_pingbacks'    => true,
			'disable_capital_p'         => true,
			'disable_update_notices'    => false,
			'disable_email_check'       => false,
			'disable_post_by_email'      => false,
			'disable_comment_pagination' => false,
			'disable_wp_logo_admin_bar'  => false,
			'disable_image_editor'       => false,

			// --- Moderate tab ---
			'disable_oembed'            => false,
			'disable_rest_api_link'     => false,
			'disable_jquery_migrate'    => true,
			'disable_dashicons'         => true,
			'disable_adjacent_posts'    => false,
			'disable_block_directory'   => false,
			'disable_remote_patterns'   => false,
			'disable_global_styles'     => false,
			'disable_duotone'           => false,
			'disable_avatars'            => false,
			'disable_comment_threading'  => false,

			// --- Strict tab ---
			'heartbeat_mode'            => 'reduce',
			'disable_comments'          => false,
			'disable_feed_all'           => false,
			'disable_feed_comments'      => false,
			'disable_feed_taxonomies'    => false,
			'disable_feed_authors'       => false,
			'disable_feed_search'        => false,
			'disable_feed_links_head'    => false,
			'revisions_mode'            => 'limit',
			'revisions_limit'           => 3,
			'autosave_interval'         => 60,
			'enable_classic_widgets'    => false,
			'disable_sitemap'           => false,
			'disable_lazy_loading'      => false,
			'disable_fetchpriority'     => false,
			'disable_version_params'    => true,
			'disable_privacy_tools'      => false,
			'disable_internal_search'    => false,
			'disable_posts_content_type' => false,
			'disable_pages_content_type' => false,

			// --- Widgets: Dashboard ---
			'disable_welcome_panel'         => false,
			'disable_dashboard_glance'      => false,
			'disable_dashboard_activity'    => false,
			'disable_dashboard_quick_draft' => true,
			'disable_dashboard_events'      => true,
			'disable_dashboard_site_health' => false,
			'disable_dashboard_third_party' => array(),

			// --- Widgets: Classic sidebar ---
			'disable_widget_archives'       => false,
			'disable_widget_audio'          => false,
			'disable_widget_calendar'       => false,
			'disable_widget_categories'     => false,
			'disable_widget_custom_html'    => false,
			'disable_widget_gallery'        => false,
			'disable_widget_image'          => false,
			'disable_widget_meta'           => false,
			'disable_widget_nav_menu'       => false,
			'disable_widget_pages'          => false,
			'disable_widget_comments'       => false,
			'disable_widget_posts'          => false,
			'disable_widget_rss'            => false,
			'disable_widget_search'         => false,
			'disable_widget_tag_cloud'      => false,
			'disable_widget_text'           => false,
			'disable_widget_video'          => false,

			// --- Widgets: Block editor ---
			'disable_block_archives'        => false,
			'disable_block_latest_comments' => false,
			'disable_block_latest_posts'    => false,
			'disable_block_rss'             => false,
			'disable_block_tag_cloud'       => false,
			'disable_block_calendar'        => false,
			'disable_block_search'          => false,

			// --- Widgets: Customizer ---
			'disable_customizer_widgets'    => false,
			'disable_customizer'            => false,

			// --- Performance: scripts & assets ---
			'defer_js'                  => true,
			'optimize_google_fonts'     => true,
			'resource_hints'            => true,
			'preload_assets'            => true,
			'preload_logo'              => true,
			'optimize_feeds'            => true,
			'disable_pdf_previews'      => true,

			// --- Performance: images ---
			'enhance_images'            => true,
			'add_image_dimensions'      => true,

			// --- Performance: database ---
			'clean_transients'          => true,
			'optimize_comment_queries'  => true,
			'optimize_main_queries'     => true,

			// --- Performance: critical CSS ---
			'critical_css_inline'       => true,
			'critical_css_defer'        => false,

			// --- Performance: .htaccess server rules ---
			'htaccess_rules'            => true,
			'htaccess_expires'          => true,
			'htaccess_gzip'             => true,
			'htaccess_brotli'           => true,
			'htaccess_cache_headers'    => true,
			'htaccess_cors_fonts'       => true,
			'htaccess_keepalive'        => true,
		);
	}

	/**
	 * Complete list of boolean (checkbox) setting keys.
	 *
	 * Centralised here so sanitize() and any future validation
	 * reference the same list.
	 *
	 * @return array
	 */
	private static function get_boolean_keys() {
		return array(
			// Light.
			'disable_emojis', 'disable_rsd_link', 'disable_wlw_manifest',
			'disable_shortlink', 'disable_self_pingbacks', 'disable_capital_p',
			'disable_update_notices', 'disable_email_check',
			'disable_post_by_email', 'disable_comment_pagination',
			'disable_wp_logo_admin_bar', 'disable_image_editor',

			// Moderate.
			'disable_oembed', 'disable_rest_api_link',
			'disable_jquery_migrate', 'disable_dashicons',
			'disable_adjacent_posts', 'disable_block_directory', 'disable_remote_patterns',
			'disable_global_styles', 'disable_duotone',
			'disable_avatars', 'disable_comment_threading',

			// Strict (booleans only, selects handled separately).
			'disable_comments', 'disable_feed_all', 'disable_feed_comments',
			'disable_feed_taxonomies', 'disable_feed_authors', 'disable_feed_search',
			'disable_feed_links_head', 'enable_classic_widgets',
			'disable_sitemap', 'disable_lazy_loading', 'disable_fetchpriority',
			'disable_version_params', 'disable_privacy_tools', 'disable_internal_search',
			'disable_posts_content_type', 'disable_pages_content_type',

			// Widgets: dashboard.
			'disable_welcome_panel', 'disable_dashboard_glance',
			'disable_dashboard_activity', 'disable_dashboard_quick_draft',
			'disable_dashboard_events', 'disable_dashboard_site_health',

			// Widgets: classic sidebar.
			'disable_widget_archives', 'disable_widget_audio', 'disable_widget_calendar',
			'disable_widget_categories', 'disable_widget_custom_html', 'disable_widget_gallery',
			'disable_widget_image', 'disable_widget_meta', 'disable_widget_nav_menu',
			'disable_widget_pages', 'disable_widget_comments', 'disable_widget_posts',
			'disable_widget_rss', 'disable_widget_search', 'disable_widget_tag_cloud',
			'disable_widget_text', 'disable_widget_video',

			// Widgets: block editor.
			'disable_block_archives', 'disable_block_latest_comments',
			'disable_block_latest_posts', 'disable_block_rss', 'disable_block_tag_cloud',
			'disable_block_calendar', 'disable_block_search',

			// Widgets: customizer.
			'disable_customizer_widgets', 'disable_customizer',

			// Performance modules.
			'defer_js', 'optimize_google_fonts', 'resource_hints', 'preload_assets',
			'preload_logo', 'optimize_feeds', 'disable_pdf_previews',
			'enhance_images', 'add_image_dimensions',
			'clean_transients', 'optimize_comment_queries', 'optimize_main_queries',
			'critical_css_inline', 'critical_css_defer',
			'htaccess_rules', 'htaccess_expires', 'htaccess_gzip', 'htaccess_brotli',
			'htaccess_cache_headers', 'htaccess_cors_fonts', 'htaccess_keepalive',
		);
	}

	/**
	 * Sanitize all settings on save.
	 *
	 * The form always renders ALL tabs (inactive ones hidden via CSS),
	 * so every field is present in the POST on every submit:
	 *
	 *   - Checked checkbox   → key exists in $input → true
	 *   - Unchecked checkbox → key absent from $input → false
	 *   - Select / number    → key always present with a value
	 *
	 * No tab-detection logic is needed.
	 *
	 * @param mixed $input Raw input from the form.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return self::get_defaults();
		}

		// Start from defaults so every key is guaranteed to exist.
		$sanitized = self::get_defaults();

		// --- All boolean (checkbox) fields ---
		// Use ! empty() instead of isset() because WordPress may call
		// the sanitize callback twice (once in options.php processing,
		// once inside update_option). On the second pass the false values
		// from the first pass arrive as empty strings — isset('') is true
		// but empty('') is true, so ! empty() correctly returns false.
		foreach ( self::get_boolean_keys() as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] );
		}

		// --- Heartbeat mode (safelist) ---
		$heartbeat_allowed = array( 'default', 'disable', 'admin_only', 'reduce' );
		if ( isset( $input['heartbeat_mode'] ) && in_array( $input['heartbeat_mode'], $heartbeat_allowed, true ) ) {
			$sanitized['heartbeat_mode'] = $input['heartbeat_mode'];
		}

		// --- Revisions mode (safelist) ---
		$revisions_allowed = array( 'default', 'disable', 'limit' );
		if ( isset( $input['revisions_mode'] ) && in_array( $input['revisions_mode'], $revisions_allowed, true ) ) {
			$sanitized['revisions_mode'] = $input['revisions_mode'];
		}

		// --- Revisions limit (integer 1-50) ---
		if ( isset( $input['revisions_limit'] ) ) {
			$limit = absint( $input['revisions_limit'] );
			$sanitized['revisions_limit'] = ( $limit >= 1 && $limit <= 50 ) ? $limit : 5;
		}

		// --- Autosave interval (safelist) ---
		$autosave_allowed = array( 60, 120, 300, 0 );
		if ( isset( $input['autosave_interval'] ) ) {
			$interval = absint( $input['autosave_interval'] );
			$sanitized['autosave_interval'] = in_array( $interval, $autosave_allowed, true ) ? $interval : 60;
		}

		// --- Third-party dashboard widgets (array of IDs) ---
		if ( isset( $input['disable_dashboard_third_party'] ) && is_array( $input['disable_dashboard_third_party'] ) ) {
			$sanitized['disable_dashboard_third_party'] = array_map( 'sanitize_key', $input['disable_dashboard_third_party'] );
		} else {
			$sanitized['disable_dashboard_third_party'] = array();
		}

		// Clear cached settings so the next get() reads fresh values.
		$instance = self::get_instance();
		$instance->settings = null;

		return $sanitized;
	}

	/**
	 * Get field definitions for a specific tab.
	 *
	 * Used by the admin class to render toggle fields.
	 *
	 * @param string $tab Tab identifier.
	 * @return array Associative array of key => label.
	 */
	public static function get_fields( $tab ) {
		$fields = array();

		switch ( $tab ) {
			case 'light':
				$fields = array(
					'disable_emojis'         => __( 'Disable emoji scripts and styles', 'wpo-tweaks' ),
					'disable_rsd_link'       => __( 'Remove RSD (Really Simple Discovery) link', 'wpo-tweaks' ),
					'disable_wlw_manifest'   => __( 'Remove Windows Live Writer manifest', 'wpo-tweaks' ),
					'disable_shortlink'      => __( 'Remove shortlink from head', 'wpo-tweaks' ),
					'disable_self_pingbacks' => __( 'Disable self-pingbacks', 'wpo-tweaks' ),
					'disable_capital_p'      => __( 'Disable Capital P Dangit filter', 'wpo-tweaks' ),
					'disable_update_notices' => __( 'Hide update notices for non-administrators', 'wpo-tweaks' ),
					'disable_email_check'    => __( 'Disable admin email verification prompt', 'wpo-tweaks' ),
					'disable_post_by_email'      => __( 'Disable post by email (wp-mail.php)', 'wpo-tweaks' ),
					'disable_comment_pagination' => __( 'Disable comment pagination', 'wpo-tweaks' ),
					'disable_wp_logo_admin_bar'  => __( 'Remove WordPress logo from admin bar', 'wpo-tweaks' ),
					'disable_image_editor'       => __( 'Disable media library image editor', 'wpo-tweaks' ),
				);
				break;

			case 'moderate':
				$fields = array(
					'disable_oembed'          => __( 'Disable oEmbed discovery and wp-embed.js', 'wpo-tweaks' ),
					'disable_rest_api_link'   => __( 'Remove REST API link from head', 'wpo-tweaks' ),
					'disable_jquery_migrate'  => __( 'Remove jQuery Migrate', 'wpo-tweaks' ),
					'disable_dashicons'       => __( 'Remove Dashicons from frontend (non-logged-in users)', 'wpo-tweaks' ),
					'disable_adjacent_posts'  => __( 'Remove adjacent post links (rel prev/next)', 'wpo-tweaks' ),
					'disable_block_directory' => __( 'Disable remote block directory in editor', 'wpo-tweaks' ),
					'disable_remote_patterns' => __( 'Disable remote block patterns from repository', 'wpo-tweaks' ),
					'disable_global_styles'   => __( 'Remove Global Styles inline CSS', 'wpo-tweaks' ),
					'disable_duotone'         => __( 'Remove Duotone SVG inline filters', 'wpo-tweaks' ),
					'disable_avatars'            => __( 'Disable avatars and Gravatar', 'wpo-tweaks' ),
					'disable_comment_threading'  => __( 'Disable comment threading (nested replies)', 'wpo-tweaks' ),
				);
				break;
		}

		return $fields;
	}

	/**
	 * Get the setting keys that belong to a specific tab.
	 *
	 * Used by the per-tab "Restore defaults" feature.
	 *
	 * @param string $tab Tab slug (light, moderate, strict, widgets).
	 * @return array Flat array of setting keys.
	 */
	public static function get_tab_keys( $tab ) {
		switch ( $tab ) {
			case 'light':
				// Diet fields plus the performance toggles rendered in this tab,
				// so per-tab "Restore defaults" resets them too.
				return array_merge(
					array_keys( self::get_fields( 'light' ) ),
					array(
						'optimize_google_fonts',
						'resource_hints',
						'preload_assets',
						'preload_logo',
						'optimize_feeds',
						'disable_pdf_previews',
						'clean_transients',
					)
				);

			case 'moderate':
				return array_merge(
					array_keys( self::get_fields( 'moderate' ) ),
					array(
						'enhance_images',
						'add_image_dimensions',
						'optimize_comment_queries',
						'optimize_main_queries',
					)
				);

			case 'strict':
				return array(
					'heartbeat_mode',
					'disable_comments',
					'disable_feed_all',
					'disable_feed_comments',
					'disable_feed_taxonomies',
					'disable_feed_authors',
					'disable_feed_search',
					'disable_feed_links_head',
					'revisions_mode',
					'revisions_limit',
					'autosave_interval',
					'enable_classic_widgets',
					'disable_sitemap',
					'disable_lazy_loading',
					'disable_fetchpriority',
					'disable_version_params',
					'disable_privacy_tools',
					'disable_internal_search',
					'disable_posts_content_type',
					'disable_pages_content_type',
					'defer_js',
					'critical_css_inline',
					'critical_css_defer',
					'htaccess_rules',
					'htaccess_expires',
					'htaccess_gzip',
					'htaccess_brotli',
					'htaccess_cache_headers',
					'htaccess_cors_fonts',
					'htaccess_keepalive',
				);

			case 'widgets':
				return array(
					'disable_welcome_panel',
					'disable_dashboard_glance',
					'disable_dashboard_activity',
					'disable_dashboard_quick_draft',
					'disable_dashboard_events',
					'disable_dashboard_site_health',
					'disable_dashboard_third_party',
					'disable_widget_archives',
					'disable_widget_audio',
					'disable_widget_calendar',
					'disable_widget_categories',
					'disable_widget_custom_html',
					'disable_widget_gallery',
					'disable_widget_image',
					'disable_widget_meta',
					'disable_widget_nav_menu',
					'disable_widget_pages',
					'disable_widget_comments',
					'disable_widget_posts',
					'disable_widget_rss',
					'disable_widget_search',
					'disable_widget_tag_cloud',
					'disable_widget_text',
					'disable_widget_video',
					'disable_block_archives',
					'disable_block_latest_comments',
					'disable_block_latest_posts',
					'disable_block_rss',
					'disable_block_tag_cloud',
					'disable_block_calendar',
					'disable_block_search',
					'disable_customizer_widgets',
					'disable_customizer',
				);

			default:
				return array();
		}
	}

	/**
	 * Get a human-readable label for a setting key.
	 *
	 * @param string $key Setting key.
	 * @return string Label or the key itself as fallback.
	 */
	public static function get_field_label( $key ) {
		// Merge labels from all tabs.
		$all_labels = array_merge(
			self::get_fields( 'light' ),
			self::get_fields( 'moderate' )
		);

		// Add strict/widgets labels that are defined inline in admin.
		$extra_labels = array(
			'heartbeat_mode'            => __( 'Heartbeat API', 'wpo-tweaks' ),
			'disable_comments'          => __( 'Disable comments', 'wpo-tweaks' ),
			'disable_feed_all'           => __( 'Disable all RSS/Atom feeds', 'wpo-tweaks' ),
			'disable_feed_comments'      => __( 'Disable comment feeds', 'wpo-tweaks' ),
			'disable_feed_taxonomies'    => __( 'Disable category, tag, and taxonomy feeds', 'wpo-tweaks' ),
			'disable_feed_authors'       => __( 'Disable author feeds', 'wpo-tweaks' ),
			'disable_feed_search'        => __( 'Disable search result feeds', 'wpo-tweaks' ),
			'disable_feed_links_head'    => __( 'Remove feed discovery links from head', 'wpo-tweaks' ),
			'revisions_mode'            => __( 'Post revisions', 'wpo-tweaks' ),
			'autosave_interval'         => __( 'Autosave interval', 'wpo-tweaks' ),
			'enable_classic_widgets'    => __( 'Restore classic widget editor', 'wpo-tweaks' ),
			'disable_sitemap'           => __( 'Disable WordPress XML sitemap', 'wpo-tweaks' ),
			'disable_lazy_loading'      => __( 'Disable native lazy loading', 'wpo-tweaks' ),
			'disable_fetchpriority'     => __( 'Disable fetchpriority attribute', 'wpo-tweaks' ),
			'disable_version_params'    => __( 'Remove version parameter from assets', 'wpo-tweaks' ),
			'disable_customizer'        => __( 'Disable Customizer completely', 'wpo-tweaks' ),
			'disable_privacy_tools'      => __( 'Remove privacy tools from admin', 'wpo-tweaks' ),
			'disable_internal_search'    => __( 'Disable internal site search', 'wpo-tweaks' ),
			'disable_posts_content_type' => __( 'Disable Posts content type', 'wpo-tweaks' ),
			'disable_pages_content_type' => __( 'Disable Pages content type', 'wpo-tweaks' ),
			'disable_customizer_widgets' => __( 'Disable Widgets panel in Customizer', 'wpo-tweaks' ),

			// Performance options (rendered across the Light, Moderate and
			// Strict tabs). Labels match the toggle cards in the admin UI.
			'optimize_google_fonts'      => __( 'Optimize Google Fonts loading', 'wpo-tweaks' ),
			'resource_hints'             => __( 'Add resource hints (preconnect and dns-prefetch)', 'wpo-tweaks' ),
			'preload_assets'             => __( 'Preload theme stylesheet and critical fonts', 'wpo-tweaks' ),
			'preload_logo'               => __( 'Preload the site logo (LCP)', 'wpo-tweaks' ),
			'optimize_feeds'             => __( 'Optimize RSS feeds', 'wpo-tweaks' ),
			'disable_pdf_previews'       => __( 'Disable PDF thumbnail previews', 'wpo-tweaks' ),
			'clean_transients'           => __( 'Clean expired transients daily', 'wpo-tweaks' ),
			'enhance_images'             => __( 'Enhance image loading attributes', 'wpo-tweaks' ),
			'add_image_dimensions'       => __( 'Add missing image dimensions (CLS)', 'wpo-tweaks' ),
			'optimize_comment_queries'   => __( 'Optimize comment queries', 'wpo-tweaks' ),
			'optimize_main_queries'      => __( 'Optimize main queries', 'wpo-tweaks' ),
			'defer_js'                   => __( 'Defer JavaScript parsing', 'wpo-tweaks' ),
			'critical_css_inline'        => __( 'Inline critical CSS in head', 'wpo-tweaks' ),
			'critical_css_defer'         => __( 'Defer non-critical stylesheets (experimental)', 'wpo-tweaks' ),
			'htaccess_rules'             => __( 'Write server performance rules to .htaccess', 'wpo-tweaks' ),
			'htaccess_expires'           => __( 'Browser caching (mod_expires)', 'wpo-tweaks' ),
			'htaccess_gzip'              => __( 'GZIP compression (mod_deflate)', 'wpo-tweaks' ),
			'htaccess_brotli'            => __( 'Brotli compression (mod_brotli)', 'wpo-tweaks' ),
			'htaccess_cache_headers'     => __( 'Cache-Control, Vary and ETag headers (mod_headers)', 'wpo-tweaks' ),
			'htaccess_cors_fonts'        => __( 'Cross-origin font loading (CORS)', 'wpo-tweaks' ),
			'htaccess_keepalive'         => __( 'Keep-alive connections', 'wpo-tweaks' ),
		);

		$all_labels = array_merge( $all_labels, $extra_labels );

		return isset( $all_labels[ $key ] ) ? $all_labels[ $key ] : $key;
	}

	/**
	 * Get descriptions/tooltips for fields that need extra context.
	 *
	 * @return array
	 */
	public static function get_descriptions() {
		return array(
			'disable_emojis'          => __( 'Removes wp-emoji-release.min.js, related styles, and DNS prefetch to s.w.org. Modern browsers render emojis natively.', 'wpo-tweaks' ),
			'disable_rsd_link'        => __( 'Only needed for XML-RPC clients like Windows Live Writer. Safe to remove for most sites.', 'wpo-tweaks' ),
			'disable_wlw_manifest'    => __( 'Windows Live Writer has been discontinued. This link serves no purpose.', 'wpo-tweaks' ),
			'disable_shortlink'       => __( 'Removes the ?p=123 shortlink tag. Rarely used and adds nothing to SEO.', 'wpo-tweaks' ),
			'disable_self_pingbacks'  => __( 'Prevents your site from sending pingbacks to itself when linking to your own posts.', 'wpo-tweaks' ),
			'disable_capital_p'       => __( 'Stops the filter that auto-corrects "Wordpress" to "WordPress" in content.', 'wpo-tweaks' ),
			'disable_update_notices'  => __( 'Non-admin users will not see plugin, theme, or core update notifications.', 'wpo-tweaks' ),
			'disable_email_check'     => __( 'Disables the periodic admin email verification screen that appears every 6 months.', 'wpo-tweaks' ),
			'disable_post_by_email'   => __( 'Blocks access to wp-mail.php, which handles posting via email. Unused by most sites.', 'wpo-tweaks' ),
			'disable_comment_pagination' => __( 'Disables comment pagination and adds 301 redirects from /comment-page-N/ URLs. Reduces duplicate content.', 'wpo-tweaks' ),
			'disable_wp_logo_admin_bar'  => __( 'Removes the WordPress logo and its dropdown menu from the admin bar.', 'wpo-tweaks' ),
			'disable_image_editor'    => __( 'Disables the built-in image editing tools (crop, rotate, resize) in the media library.', 'wpo-tweaks' ),
			'disable_oembed'          => __( 'Prevents other sites from embedding your posts and removes wp-embed.js. Does not affect your ability to embed external content.', 'wpo-tweaks' ),
			'disable_rest_api_link'   => __( 'Removes the REST API discovery link from the HTML head. Does not disable the REST API itself.', 'wpo-tweaks' ),
			'disable_jquery_migrate'  => __( 'Removes the jQuery Migrate compatibility layer. Some older plugins may need it.', 'wpo-tweaks' ),
			'disable_dashicons'       => __( 'Removes Dashicons CSS on the frontend for non-logged-in visitors. The admin bar requires Dashicons for logged-in users.', 'wpo-tweaks' ),
			'disable_adjacent_posts'  => __( 'Removes rel="prev" and rel="next" link tags from single post pages.', 'wpo-tweaks' ),
			'disable_block_directory' => __( 'Prevents the block editor from showing remote block suggestions in the inserter.', 'wpo-tweaks' ),
			'disable_remote_patterns' => __( 'Prevents WordPress from fetching block patterns from the WordPress.org pattern directory.', 'wpo-tweaks' ),
			'disable_global_styles'   => __( 'Removes the large inline CSS block added by WordPress 6.1+ for Global Styles. May affect block theme styling.', 'wpo-tweaks' ),
			'disable_duotone'         => __( 'Removes the inline SVG filters used for duotone image effects in the block editor.', 'wpo-tweaks' ),
			'disable_avatars'         => __( 'Disables all avatar display and prevents Gravatar HTTP requests. Improves privacy and page speed.', 'wpo-tweaks' ),
			'disable_comment_threading' => __( 'Disables nested/threaded comment replies. Comments display as a flat list.', 'wpo-tweaks' ),
			'disable_comments'        => __( 'Completely disables the WordPress comment system: closes comments, removes admin menus, and hides existing comments.', 'wpo-tweaks' ),
			'disable_feed_all'        => __( 'Disables ALL RSS/Atom feeds and redirects feed URLs to the homepage. Overrides all other feed settings.', 'wpo-tweaks' ),
			'disable_feed_comments'   => __( 'Disables comment feeds (global and per-post). Reduces crawlable URLs and server load.', 'wpo-tweaks' ),
			'disable_feed_taxonomies' => __( 'Disables feeds for categories, tags, and custom taxonomies.', 'wpo-tweaks' ),
			'disable_feed_authors'    => __( 'Disables author archive feeds. Useful if your site has a single author.', 'wpo-tweaks' ),
			'disable_feed_search'     => __( 'Disables search result feeds. Rarely used and safe to remove.', 'wpo-tweaks' ),
			'disable_feed_links_head' => __( 'Removes RSS/Atom discovery link tags from the HTML head.', 'wpo-tweaks' ),
			'disable_sitemap'         => __( 'Disables the native WordPress XML sitemap (wp-sitemap.xml). Disable if using an SEO plugin with its own sitemap.', 'wpo-tweaks' ),
			'disable_lazy_loading'    => __( 'Stops WordPress core from adding loading="lazy" to images. Enable only if a third-party plugin handles lazy loading, and turn off the DietPress image enhancements too, or they will keep adding it.', 'wpo-tweaks' ),
			'disable_fetchpriority'   => __( 'Stops WordPress core from adding fetchpriority="high" to the likely LCP image (WP 6.3+). Enable only if a third-party plugin manages image priorities, and turn off the DietPress image enhancements too, or they will keep adding it.', 'wpo-tweaks' ),
			'disable_version_params'  => __( 'Removes the ?ver= parameter from CSS and JS URLs for cleaner caching. Plugin assets keep their version so plugin updates still reach visitors; jQuery and admin assets are left untouched.', 'wpo-tweaks' ),
			'enable_classic_widgets'  => __( 'Reverts to the classic widget editor instead of the block-based widget editor.', 'wpo-tweaks' ),
			'disable_customizer_widgets' => __( 'Removes the Widgets panel from the Customizer.', 'wpo-tweaks' ),
			'disable_customizer'      => __( 'Completely disables the Customizer. Recommended for block themes that use the Site Editor instead.', 'wpo-tweaks' ),
			'disable_privacy_tools'   => __( 'Removes the Privacy menu and tools (export/erase personal data) from the admin. Only disable if not required by law.', 'wpo-tweaks' ),
			'disable_internal_search' => __( 'Disables the WordPress internal search. Search forms redirect to homepage. Use if relying on external search (Algolia, Google CSE).', 'wpo-tweaks' ),
			'disable_posts_content_type' => __( 'Completely disables the Posts content type: removes admin menus, blocks creation and editing, hides from navigation menus and frontend. Use for non-blog sites.', 'wpo-tweaks' ),
			'disable_pages_content_type' => __( 'Completely disables the Pages content type: removes admin menus, blocks creation and editing, hides from navigation menus and frontend. Rarely needed.', 'wpo-tweaks' ),
			'defer_js' => __( 'Adds the defer attribute to non-critical scripts so they no longer block rendering. Skips jQuery and any script whose dependency chain must run immediately, and is bypassed inside the Divi builder and legacy IE9.', 'wpo-tweaks' ),
			'optimize_google_fonts' => __( 'Appends display=swap to Google Fonts URLs so text stays visible with a fallback font while the web font loads.', 'wpo-tweaks' ),
			'resource_hints' => __( 'Outputs preconnect and dns-prefetch link tags for common third-party origins (Google Fonts, Analytics, Tag Manager, Gravatar). Speeds up the first connection to those domains.', 'wpo-tweaks' ),
			'preload_assets' => __( 'Adds a preload link for the active theme stylesheet plus any critical fonts registered through the dietpress_critical_fonts filter.', 'wpo-tweaks' ),
			'preload_logo' => __( 'Preloads the custom site logo with fetchpriority high to improve Largest Contentful Paint. Skipped in admin, feeds and REST requests.', 'wpo-tweaks' ),
			'optimize_feeds' => __( 'Sends a one-hour Cache-Control header on the RSS feed and limits feeds to 10 posts. Reduces feed payload and server load.', 'wpo-tweaks' ),
			'disable_pdf_previews' => __( 'Stops WordPress from generating fallback thumbnail previews for uploaded PDFs. Saves disk space and processing time.', 'wpo-tweaks' ),
			'enhance_images' => __( 'Adds fetchpriority="high" and decoding="async" to the first image (or logo), and loading="lazy" with fetchpriority="low" to later images. Existence checks ensure it never overrides attributes already set by core, themes or other plugins.', 'wpo-tweaks' ),
			'add_image_dimensions' => __( 'Adds missing width and height attributes to images and picture fallbacks in content and thumbnails, using attachment metadata or a getimagesize lookup. Skips external images and SVGs and caches results to reduce layout shift (CLS).', 'wpo-tweaks' ),
			'clean_transients' => __( 'Schedules a daily cron job that removes expired transients from the options table using core functions. Keeps the database lean.', 'wpo-tweaks' ),
			'optimize_comment_queries' => __( 'Restricts front-end comment queries to approved comments only. Skips custom comment types so review plugins like CusRev or WooCommerce keep their own logic.', 'wpo-tweaks' ),
			'optimize_main_queries' => __( 'Adds no_found_rows to archive and home queries when pagination is not needed, skipping the expensive row count. Excludes WooCommerce pages.', 'wpo-tweaks' ),
			'critical_css_inline' => __( 'Prints a small block of critical CSS inline in the head so the page can start rendering before external stylesheets load. Cached in the object cache with a transient fallback. Filterable via dietpress_critical_css.', 'wpo-tweaks' ),
			'critical_css_defer' => __( 'Experimental: converts non-critical stylesheets to rel=preload + onload so they load asynchronously. Can cause a flash of unstyled content (FOUC) on first paint. Theme, child theme and admin-bar styles stay synchronous.', 'wpo-tweaks' ),
			'htaccess_rules' => __( 'Master switch for the managed .htaccess block (browser caching, compression and cache headers). The file is backed up before the first change and the block is removed cleanly when turned off or on deactivation. Apache or LiteSpeed only.', 'wpo-tweaks' ),
			'htaccess_expires' => __( 'Sets far-future Expires headers for images, fonts, CSS, JS and media so returning visitors reuse cached files (mod_expires).', 'wpo-tweaks' ),
			'htaccess_gzip' => __( 'Compresses text-based responses (HTML, CSS, JS, JSON, SVG, fonts) before sending them, reducing transfer size (mod_deflate).', 'wpo-tweaks' ),
			'htaccess_brotli' => __( 'Adds Brotli compression for text assets on servers that support mod_brotli. Safely ignored if the module is unavailable.', 'wpo-tweaks' ),
			'htaccess_cache_headers' => __( 'Marks static assets as immutable with a one-year max-age, caches HTML for one hour, removes ETags and adds Vary Accept-Encoding (mod_headers).', 'wpo-tweaks' ),
			'htaccess_cors_fonts' => __( 'Adds Access-Control-Allow-Origin to font files so they load from a CDN or different subdomain. Disable if your policy forbids a wildcard CORS origin.', 'wpo-tweaks' ),
			'htaccess_keepalive' => __( 'Sends a Connection keep-alive header to encourage connection reuse. Some managed hosts manage keep-alive themselves and may ignore it.', 'wpo-tweaks' ),
		);
	}
}