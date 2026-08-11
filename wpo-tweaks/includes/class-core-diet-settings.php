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
	 * A locked option never counts as enabled, whatever is stored for it: this
	 * is the single place that keeps a saved value from applying when it cannot
	 * do what it promises. Every module, the analyzer and the savings counter
	 * go through here, so none of them has to know about locks.
	 *
	 * A key missing from the stored option falls back to its built-in default,
	 * the same answer get() gives. It used to pass false instead, which never
	 * showed because Core_Diet::maybe_upgrade() wrote every default to the
	 * option on every single request, so no reader ever met a missing key. Now
	 * that it only does so after an update, the two had to agree: otherwise an
	 * option absent from an imported settings file would read as off here and as
	 * on everywhere else, including in its own checkbox.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_enabled( $key ) {
		if ( '' !== self::get_lock_group_for_state( $key, null ) ) {
			return false;
		}

		return (bool) $this->get( $key );
	}

	/**
	 * Options that cannot be applied while another option is in a given state.
	 *
	 * Each entry names the option that governs it, the state that locks it and
	 * the reason shown in its card. Used both to enforce the lock in PHP and to
	 * mirror it live in the settings page, so the two never drift apart.
	 *
	 * Locks are for options that would do nothing or undo another DietPress
	 * option. An option that merely deserves a warning is not locked; see
	 * get_notice().
	 *
	 * @return array
	 */
	public static function get_lock_rules() {
		return array(
			// Hard: the option contradicts another one, so it is switched off
			// and cannot be turned on until that other one changes.
			'disable_lazy_loading'       => array( 'enhance_images', true, 'images', 'hard' ),
			'disable_fetchpriority'      => array( 'enhance_images', true, 'images', 'hard' ),

			// Soft: the option simply has no effect right now. It stays usable,
			// so a site can be configured in any order, and only says so.
			'disable_feed_comments'      => array( 'disable_feed_all', true, 'feeds', 'soft' ),
			'disable_feed_taxonomies'    => array( 'disable_feed_all', true, 'feeds', 'soft' ),
			'disable_feed_authors'       => array( 'disable_feed_all', true, 'feeds', 'soft' ),
			'disable_feed_search'        => array( 'disable_feed_all', true, 'feeds', 'soft' ),
			'disable_feed_links_head'    => array( 'disable_feed_all', true, 'feeds', 'soft' ),
			// Two independent masters, one per tab. htaccess_rules governs
			// compression and connections, in Strict; htaccess_browser_cache
			// governs the caching rules, in Cache. Either one on is enough for
			// the .htaccess block to be written.
			'htaccess_gzip'              => array( 'htaccess_rules', false, 'htaccess', 'soft' ),
			'htaccess_brotli'            => array( 'htaccess_rules', false, 'htaccess', 'soft' ),
			'htaccess_cors_fonts'        => array( 'htaccess_rules', false, 'htaccess', 'soft' ),
			'htaccess_keepalive'         => array( 'htaccess_rules', false, 'htaccess', 'soft' ),

			'htaccess_expires'           => array( 'htaccess_browser_cache', false, 'browsercache', 'soft' ),
			'htaccess_cache_headers'     => array( 'htaccess_browser_cache', false, 'browsercache', 'soft' ),

			// The lifetimes belong to browser caching, which is their own
			// master inside the .htaccess block.
			'htaccess_expires_media'     => array( 'htaccess_expires', false, 'expires', 'soft' ),
			'htaccess_expires_assets'    => array( 'htaccess_expires', false, 'expires', 'soft' ),
			'htaccess_expires_fonts'     => array( 'htaccess_expires', false, 'expires', 'soft' ),
			'htaccess_etag'              => array( 'htaccess_cache_headers', false, 'headers', 'soft' ),

			// Sent from PHP, so it only depends on the browser cache master and
			// not on the .htaccess header rules.
			'htaccess_html_maxage'       => array( 'htaccess_browser_cache', false, 'browsercache', 'soft' ),
			'disable_customizer_widgets' => array( 'disable_customizer', true, 'customizer', 'soft' ),
		);
	}

	/**
	 * The text a key would show if it were locked, whatever its state now.
	 *
	 * Needed by the settings page, which has to be able to show the reason the
	 * moment the option it depends on is switched, before any page reload.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get_lock_text_for( $key ) {
		$rules = self::get_lock_rules();

		return isset( $rules[ $key ] ) ? self::get_lock_reason_text( $rules[ $key ][2] ) : '';
	}

	/**
	 * Whether an option is hard locked: switched off and not selectable.
	 *
	 * @param string     $key   Setting key.
	 * @param array|null $state Settings to judge against, or null for the saved ones.
	 * @return bool
	 */
	public static function is_hard_locked( $key, $state = null ) {
		if ( '' === self::get_lock_group_for_state( $key, $state ) ) {
			return false;
		}

		if ( 'disable_sitemap' === $key ) {
			return true;
		}

		$rules = self::get_lock_rules();

		return isset( $rules[ $key ] ) && 'hard' === $rules[ $key ][3];
	}

	/**
	 * The text explaining a lock, by reason group.
	 *
	 * Only the admin screens ever need these strings, and nothing may reach them
	 * before the init action: this class is instantiated while the plugin file
	 * is still being included, so a translation call on the path that answers
	 * "is this option locked?" would load the text domain far too early and trip
	 * the _load_textdomain_just_in_time notice added in WordPress 6.7.
	 *
	 * That is why the question is split in two: get_lock_group_for_state() knows
	 * whether an option is locked and returns a bare group slug, and only this
	 * method turns a group into words. Callers that just need the yes or no must
	 * use the former; ask for the text only when it is about to be printed.
	 *
	 * @param string $group Reason group from get_lock_rules(), or '' when unlocked.
	 * @return string Empty string when there is no lock to explain.
	 */
	private static function get_lock_reason_text( $group ) {
		switch ( $group ) {
			case 'images':
				return __( 'Needs "Enhance image loading attributes" off, in the Moderate tab: while it is on, DietPress already sets these attributes.', 'wpo-tweaks' );

			case 'feeds':
				return __( '"Disable ALL RSS feeds" already covers this, so it does nothing right now.', 'wpo-tweaks' );

			case 'htaccess':
				return __( '"Write compression rules to .htaccess" is off, so this does nothing right now.', 'wpo-tweaks' );

			case 'browsercache':
				return __( '"Write browser cache rules to .htaccess" is off, so this does nothing right now.', 'wpo-tweaks' );

			case 'expires':
				return __( '"Browser caching (mod_expires)" is off, so this lifetime does nothing right now.', 'wpo-tweaks' );

			case 'headers':
				return __( '"Cache-Control, Vary and ETag headers" is off, so this does nothing right now.', 'wpo-tweaks' );

			case 'customizer':
				return __( 'The whole Customizer is off, so this does nothing right now.', 'wpo-tweaks' );

			case 'sitemap':
				return __( 'Another plugin builds on the native sitemap. Turn its sitemap feature off first.', 'wpo-tweaks' );
		}

		return '';
	}

	/**
	 * Why an option cannot be applied right now, or '' when it can.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get_lock_reason( $key ) {
		return self::get_lock_reason_for_state( $key, null );
	}

	/**
	 * Why an option cannot be applied in a given set of settings.
	 *
	 * Passing the settings explicitly is what lets the quick profiles ask the
	 * question about the state they are ABOUT to save, instead of the one on
	 * disk: a profile that switches a master option on has every right to
	 * switch its sub-options on in the same move.
	 *
	 * @param string     $key   Setting key.
	 * @param array|null $state Settings to judge against, or null for the saved ones.
	 * @return string
	 */
	public static function get_lock_reason_for_state( $key, $state = null ) {
		return self::get_lock_reason_text( self::get_lock_group_for_state( $key, $state ) );
	}

	/**
	 * Which reason group locks an option in a given set of settings, or ''.
	 *
	 * The translation-free half of the lock question, and the one every module
	 * goes through via is_enabled(). It answers with the bare group slug from
	 * get_lock_rules() so that deciding whether an option applies never needs a
	 * text domain; see get_lock_reason_text() for why that matters.
	 *
	 * @param string     $key   Setting key.
	 * @param array|null $state Settings to judge against, or null for the saved ones.
	 * @return string Reason group, or '' when the option is not locked.
	 */
	public static function get_lock_group_for_state( $key, $state = null ) {
		// The native sitemap can be another plugin's foundation, in which case
		// removing it breaks that plugin rather than saving anything. This one
		// does not depend on any DietPress setting.
		if ( 'disable_sitemap' === $key ) {
			return self::native_sitemap_in_use() ? 'sitemap' : '';
		}

		$rules = self::get_lock_rules();

		if ( ! isset( $rules[ $key ] ) ) {
			return '';
		}

		list( $depends_on, $locked_when, $group ) = $rules[ $key ];

		/*
		 * A key missing from the stored option has to resolve to its built-in
		 * default here, exactly as get() and is_enabled() resolve it. This used
		 * to pass false as an explicit default, which is a different answer:
		 * get()'s second argument is the fallback, so a missing key came back
		 * false while the very same key rendered as true in its own checkbox.
		 *
		 * That contradiction is invisible for as long as every key is present,
		 * which is why it went unnoticed for years: Core_Diet::maybe_upgrade()
		 * used to write every default to the option on every request. Since
		 * 3.4.1 it only runs after an update, so a key added between releases
		 * is genuinely absent, and in 3.5.0 htaccess_browser_cache made it
		 * visible: its switch read as on and the two options it governs
		 * announced that it was off.
		 */
		$current = is_array( $state ) && array_key_exists( $depends_on, $state )
			? (bool) $state[ $depends_on ]
			: (bool) self::get_instance()->get( $depends_on );

		return ( $current === (bool) $locked_when ) ? $group : '';
	}

	/**
	 * Whether another plugin depends on the native WordPress sitemap.
	 *
	 * Visibility (Native AEO Pack) customizes wp-sitemap.xml through the
	 * wp_sitemaps_* filters, so it needs the native sitemap alive. Any other
	 * plugin in the same situation can say so through the filter.
	 *
	 * @return bool
	 */
	private static function native_sitemap_in_use() {
		static $in_use = null;

		if ( null !== $in_use ) {
			return $in_use;
		}

		$detected = class_exists( 'Native_AEO_Pack_Settings' )
			&& method_exists( 'Native_AEO_Pack_Settings', 'is_module_enabled' )
			&& Native_AEO_Pack_Settings::is_module_enabled( 'sitemap' );

		/**
		 * Filter whether a plugin depends on the native WordPress sitemap.
		 *
		 * Return true to lock the "Disable WordPress XML sitemap" option, so
		 * nobody can pull the sitemap from under your plugin by mistake.
		 *
		 * @since 3.4.0
		 * @param bool $detected Whether the native sitemap is in use.
		 */
		$detected = (bool) apply_filters( 'dietpress_native_sitemap_in_use', $detected );

		// DietPress loads with the plugin file, so an early call can happen
		// before the other plugin exists. Answer, but do not remember a "no"
		// that only means "not loaded yet".
		if ( ! did_action( 'plugins_loaded' ) ) {
			return $detected;
		}

		$in_use = $detected;

		return $in_use;
	}

	/**
	 * The note shown inside an option card, or null when there is nothing to say.
	 *
	 * A locked option reports its lock; the rest report what the admin should
	 * know before switching them on. Meant for the admin screens only: some of
	 * these count content.
	 *
	 * @param string $key Setting key.
	 * @return array|null Array with 'type' (locked or warning) and 'text'.
	 */
	public static function get_notice( $key ) {
		$lock = self::get_lock_reason( $key );

		if ( '' !== $lock ) {
			return array(
				'type' => self::is_hard_locked( $key ) ? 'locked' : 'inactive',
				'text' => $lock,
			);
		}

		if ( 'disable_posts_content_type' === $key ) {
			$total = self::count_content( 'post' );

			if ( ! $total ) {
				return null;
			}

			return array(
				'type' => 'warning',
				'text' => sprintf(
					/* translators: %s: number of posts, already formatted. */
					_n(
						'You have %s post: it would be hidden from the admin, the frontend and menus. Nothing is deleted.',
						'You have %s posts: they would be hidden from the admin, the frontend and menus. Nothing is deleted.',
						$total,
						'wpo-tweaks'
					),
					number_format_i18n( $total )
				),
			);
		}

		if ( 'disable_pages_content_type' === $key ) {
			$total = self::count_content( 'page' );

			if ( ! $total ) {
				return null;
			}

			return array(
				'type' => 'warning',
				'text' => sprintf(
					/* translators: %s: number of pages, already formatted. */
					_n(
						'You have %s page: it would be hidden from the admin, the frontend and menus. Nothing is deleted.',
						'You have %s pages: they would be hidden from the admin, the frontend and menus. Nothing is deleted.',
						$total,
						'wpo-tweaks'
					),
					number_format_i18n( $total )
				),
			);
		}

		return null;
	}

	/**
	 * Count the entries of a post type that would become unreachable.
	 *
	 * Trashed and auto-draft entries are left out: they are not reachable to
	 * begin with, so counting them would only inflate the warning.
	 *
	 * @param string $post_type Post type name.
	 * @return int
	 */
	private static function count_content( $post_type ) {
		$counts = wp_count_posts( $post_type );

		if ( ! is_object( $counts ) ) {
			return 0;
		}

		$total = 0;

		foreach ( array( 'publish', 'future', 'draft', 'pending', 'private' ) as $status ) {
			if ( isset( $counts->$status ) ) {
				$total += (int) $counts->$status;
			}
		}

		return $total;
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

			// --- Emails tab (all OFF by default; muting an email is the user's choice) ---
			// Updates.
			'disable_auto_core_update_email'      => false,
			'disable_core_update_available_email' => false,
			'disable_auto_plugin_update_email'    => false,
			'disable_auto_theme_update_email'     => false,
			// Comments.
			'disable_comment_moderation_email'    => false,
			'disable_comment_author_email'        => false,
			// Users & passwords.
			'disable_new_user_admin_email'        => false,
			'disable_new_user_email'              => false,
			'disable_password_reset_admin_email'  => false,
			'disable_password_change_email'       => false,
			'disable_email_change_email'          => false,
			'disable_admin_email_change_email'    => false,
			// System (moved from Light).
			'disable_email_check'                 => false,
			'disable_post_by_email'               => false,

			// --- Performance: scripts & assets ---
			'defer_js'                  => true,
			'optimize_google_fonts'     => true,
			'host_google_fonts'         => false,
			'resource_hints'            => true,
			'preload_assets'            => true,
			'preload_logo'              => true,
			'optimize_feeds'            => true,
			'disable_pdf_previews'      => true,

			// --- Performance: selective third-party loading (opt-in) ---
			'selective_woocommerce'     => false,
			'selective_cf7'             => false,
			'selective_blocks'          => false,
			'selective_revslider'       => false,
			'selective_tablepress'      => false,
			'selective_smash_balloon'   => false,
			'selective_formidable'      => false,
			'selective_everest_forms'   => false,

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
			'htaccess_browser_cache'    => true,
			'htaccess_expires'          => true,
			'htaccess_expires_media'    => '1 month',
			'htaccess_expires_assets'   => '1 year',
			'htaccess_expires_fonts'    => '1 year',
			'htaccess_html_maxage'      => '0',
			'htaccess_etag'             => true,
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
			'disable_update_notices', 'disable_comment_pagination',
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

			// Emails.
			'disable_auto_core_update_email', 'disable_core_update_available_email',
			'disable_auto_plugin_update_email', 'disable_auto_theme_update_email',
			'disable_comment_moderation_email', 'disable_comment_author_email',
			'disable_new_user_admin_email', 'disable_new_user_email',
			'disable_password_reset_admin_email', 'disable_password_change_email',
			'disable_email_change_email', 'disable_admin_email_change_email',
			'disable_email_check', 'disable_post_by_email',

			// Performance modules.
			'defer_js', 'optimize_google_fonts', 'host_google_fonts',
			'resource_hints', 'preload_assets',
			'preload_logo', 'optimize_feeds', 'disable_pdf_previews',
			'selective_woocommerce', 'selective_cf7', 'selective_blocks',
			'selective_revslider', 'selective_tablepress', 'selective_smash_balloon',
			'selective_formidable', 'selective_everest_forms',
			'enhance_images', 'add_image_dimensions',
			'clean_transients', 'optimize_comment_queries', 'optimize_main_queries',
			'critical_css_inline', 'critical_css_defer',
			// The three htaccess_expires_* lifetimes are NOT here: they are
			// selects, and a boolean cast would turn any submitted string into
			// true, which then survives the allowlist check further down
			// because that check only ever writes a value it recognises.
			'htaccess_rules', 'htaccess_browser_cache', 'htaccess_expires', 'htaccess_gzip', 'htaccess_brotli',
			'htaccess_cache_headers', 'htaccess_cors_fonts', 'htaccess_keepalive',
			'htaccess_etag',
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
		// Browser cache lifetimes. The value goes straight into an .htaccess
		// directive, so nothing outside the allowlist may ever reach it.
		foreach ( array( 'htaccess_expires_media', 'htaccess_expires_assets', 'htaccess_expires_fonts' ) as $key ) {
			if ( isset( $input[ $key ] ) && array_key_exists( $input[ $key ], self::get_expires_choices() ) ) {
				$sanitized[ $key ] = $input[ $key ];
			}
		}

		if ( isset( $input['htaccess_html_maxage'] ) && array_key_exists( $input['htaccess_html_maxage'], self::get_html_maxage_choices() ) ) {
			$sanitized['htaccess_html_maxage'] = $input['htaccess_html_maxage'];
		}

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
	/**
	 * Browser cache lifetimes offered for the .htaccess expires rules.
	 *
	 * The keys are the literal mod_expires periods, which is what makes the
	 * allowlist in sanitize() the only thing standing between a stored option
	 * and an Apache directive.
	 *
	 * @return array Period => label.
	 */
	public static function get_expires_choices() {
		return array(
			'1 day'    => __( '1 day', 'wpo-tweaks' ),
			'1 week'   => __( '1 week', 'wpo-tweaks' ),
			'1 month'  => __( '1 month', 'wpo-tweaks' ),
			'4 months' => __( '4 months', 'wpo-tweaks' ),
			'1 year'   => __( '1 year', 'wpo-tweaks' ),
		);
	}

	/**
	 * How long browsers may keep an HTML document.
	 *
	 * @return array Period => label.
	 */
	public static function get_html_maxage_choices() {
		return array(
			'0'         => __( 'Always revalidate', 'wpo-tweaks' ),
			'5 minutes' => __( '5 minutes', 'wpo-tweaks' ),
			'1 hour'   => __( '1 hour', 'wpo-tweaks' ),
			'1 day'    => __( '1 day', 'wpo-tweaks' ),
		);
	}

	/**
	 * Turn one of the periods above into seconds, for a max-age directive.
	 *
	 * Expires and Cache-Control describe the same thing, and browsers obey
	 * Cache-Control when the two disagree. Deriving the seconds from the very
	 * period that goes into the Expires rule is what keeps a site from choosing
	 * "1 month" for its images and being served a year regardless.
	 *
	 * @param string $period Period such as "4 months".
	 * @return int Seconds. 0 when the period is not one we offer.
	 */
	public static function period_to_seconds( $period ) {
		$map = array(
			'0'         => 0,
			'5 minutes' => 5 * MINUTE_IN_SECONDS,
			'1 hour'    => HOUR_IN_SECONDS,
			'1 day'     => DAY_IN_SECONDS,
			'1 week'    => WEEK_IN_SECONDS,
			'1 month'   => MONTH_IN_SECONDS,
			'4 months'  => 4 * MONTH_IN_SECONDS,
			'1 year'    => YEAR_IN_SECONDS,
		);

		return isset( $map[ $period ] ) ? (int) $map[ $period ] : 0;
	}

	public static function get_tab_keys( $tab ) {
		switch ( $tab ) {
			case 'light':
				// Diet fields plus the performance toggles rendered in this tab,
				// so per-tab "Restore defaults" resets them too.
				return array_merge(
					array_keys( self::get_fields( 'light' ) ),
					array(
						'optimize_google_fonts',
						'host_google_fonts',
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
					'selective_woocommerce',
					'selective_cf7',
					'selective_blocks',
					'selective_revslider',
					'selective_tablepress',
					'selective_smash_balloon',
					'selective_formidable',
					'selective_everest_forms',
					// Compression and connection rules stay here: they are
					// written to .htaccess like the caching ones, but they are
					// not caching. Only what caches moved to the Cache tab.
					'htaccess_rules',
					'htaccess_gzip',
					'htaccess_brotli',
					'htaccess_cors_fonts',
					'htaccess_keepalive',
				);

			case 'cache':
				return array(
					'htaccess_browser_cache',
					'htaccess_expires',
					'htaccess_expires_media',
					'htaccess_expires_assets',
					'htaccess_expires_fonts',
					'htaccess_cache_headers',
					'htaccess_html_maxage',
					'htaccess_etag',
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

			case 'emails':
				return array(
					'disable_auto_core_update_email',
					'disable_core_update_available_email',
					'disable_auto_plugin_update_email',
					'disable_auto_theme_update_email',
					'disable_comment_moderation_email',
					'disable_comment_author_email',
					'disable_new_user_admin_email',
					'disable_new_user_email',
					'disable_password_reset_admin_email',
					'disable_password_change_email',
					'disable_email_change_email',
					'disable_admin_email_change_email',
					'disable_email_check',
					'disable_post_by_email',
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
			'host_google_fonts'          => __( 'Host Google Fonts locally', 'wpo-tweaks' ),
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
			'selective_woocommerce'      => __( 'Load WooCommerce assets only on store pages', 'wpo-tweaks' ),
			'selective_cf7'              => __( 'Load Contact Form 7 assets only on pages with forms', 'wpo-tweaks' ),
			'selective_blocks'           => __( 'Load block styles only on pages that use blocks', 'wpo-tweaks' ),
			'selective_revslider'        => __( 'Load Slider Revolution libraries only on pages with a slider', 'wpo-tweaks' ),
			'selective_tablepress'       => __( 'Load TablePress styles only on pages with a table', 'wpo-tweaks' ),
			'selective_smash_balloon'    => __( 'Load Smash Balloon styles only on pages with a feed', 'wpo-tweaks' ),
			'selective_formidable'       => __( 'Load Formidable Forms styles only on pages with a form', 'wpo-tweaks' ),
			'selective_everest_forms'    => __( 'Load Everest Forms styles only on pages with a form', 'wpo-tweaks' ),
			'htaccess_rules'             => __( 'Write compression rules to .htaccess', 'wpo-tweaks' ),
			'htaccess_browser_cache'     => __( 'Write browser cache rules to .htaccess', 'wpo-tweaks' ),
			'htaccess_expires'           => __( 'Browser caching (mod_expires) (.htaccess)', 'wpo-tweaks' ),
			'htaccess_expires_media'     => __( 'Keep images, video and PDFs for', 'wpo-tweaks' ),
			'htaccess_expires_assets'    => __( 'Keep styles and scripts for', 'wpo-tweaks' ),
			'htaccess_expires_fonts'     => __( 'Keep fonts for', 'wpo-tweaks' ),
			'htaccess_html_maxage'       => __( 'Let browsers keep the HTML for', 'wpo-tweaks' ),
			'htaccess_etag'              => __( 'Remove ETags from static files (.htaccess)', 'wpo-tweaks' ),
			'htaccess_gzip'              => __( 'GZIP compression (mod_deflate) (.htaccess)', 'wpo-tweaks' ),
			'htaccess_brotli'            => __( 'Brotli compression (mod_brotli) (.htaccess)', 'wpo-tweaks' ),
			'htaccess_cache_headers'     => __( 'Cache-Control and Vary headers (mod_headers) (.htaccess)', 'wpo-tweaks' ),
			'htaccess_cors_fonts'        => __( 'Cross-origin font loading (CORS) (.htaccess)', 'wpo-tweaks' ),
			'htaccess_keepalive'         => __( 'Keep-alive connections (.htaccess)', 'wpo-tweaks' ),

			// Emails.
			'disable_auto_core_update_email'      => __( 'Core auto-update result email', 'wpo-tweaks' ),
			'disable_core_update_available_email' => __( 'Core update available email', 'wpo-tweaks' ),
			'disable_auto_plugin_update_email'    => __( 'Plugin auto-update result email', 'wpo-tweaks' ),
			'disable_auto_theme_update_email'     => __( 'Theme auto-update result email', 'wpo-tweaks' ),
			'disable_comment_moderation_email'    => __( 'Comment moderation email', 'wpo-tweaks' ),
			'disable_comment_author_email'        => __( 'New comment email to post author', 'wpo-tweaks' ),
			'disable_new_user_admin_email'        => __( 'New user email to admin', 'wpo-tweaks' ),
			'disable_new_user_email'              => __( 'New user email to the user', 'wpo-tweaks' ),
			'disable_password_reset_admin_email'  => __( 'Password reset email to admin', 'wpo-tweaks' ),
			'disable_password_change_email'       => __( 'Password changed email to user', 'wpo-tweaks' ),
			'disable_email_change_email'          => __( 'Email changed notice to user', 'wpo-tweaks' ),
			'disable_admin_email_change_email'    => __( 'Site admin email change notice', 'wpo-tweaks' ),
			'disable_email_check'                 => __( 'Disable admin email verification prompt', 'wpo-tweaks' ),
			'disable_post_by_email'               => __( 'Disable post by email (wp-mail.php)', 'wpo-tweaks' ),
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
			'disable_image_editor'    => __( 'Disables the built-in image editing tools (crop, rotate, resize) in the media library. Thumbnail generation is not affected.', 'wpo-tweaks' ),
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
			'disable_sitemap'         => __( 'Disables the native WordPress XML sitemap (wp-sitemap.xml). Disable if using an SEO plugin with its own sitemap. It stays unavailable while another plugin builds on the native sitemap, so nobody can pull it from under that plugin by mistake.', 'wpo-tweaks' ),
			'disable_lazy_loading'    => __( 'Stops WordPress core from adding loading="lazy" to images. Only useful when another plugin handles lazy loading, so it needs "Enhance image loading attributes" turned off: while that one is on, DietPress sets the attribute itself and this would change nothing, so it stays unavailable.', 'wpo-tweaks' ),
			'disable_fetchpriority'   => __( 'Stops WordPress core from adding fetchpriority="high" to the likely LCP image (WP 6.3+). Only useful when another plugin manages image priorities, so it needs "Enhance image loading attributes" turned off: while that one is on, DietPress sets the attribute itself and this would change nothing, so it stays unavailable.', 'wpo-tweaks' ),
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
			'host_google_fonts' => __( 'Downloads the Google Fonts your theme enqueues and serves them from your own server: faster fonts, GDPR-friendly. If a download fails, fonts keep loading from Google; theme-hardcoded fonts are not covered.', 'wpo-tweaks' ),
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
			'selective_woocommerce' => __( 'Removes WooCommerce styles and scripts on pages with no store content (shop, product, cart, checkout, account, WooCommerce shortcodes and blocks are detected). The cart fragments script is kept when a mini-cart widget is detected; if your theme has a hand-coded header cart, keep it with the dietpress_selective_wc_keep_cart_fragments filter. Only acts when WooCommerce is active.', 'wpo-tweaks' ),
			'selective_cf7' => __( 'Loads Contact Form 7 styles and scripts only on pages where a form is detected (shortcode or block in content or widgets). Pages built with Elementor, and any request an Elementor Theme Builder template applies to, keep the assets. If a form is injected via AJAX or template code, use the dietpress_selective_cf7_has_form filter. Only acts when Contact Form 7 is active.', 'wpo-tweaks' ),
			'selective_blocks' => __( 'Removes the block library stylesheets (wp-block-library and theme styles) on pages whose content uses no blocks. Skipped entirely on block themes, and Global Styles are never touched. If your classic theme reuses block styles in templates, turn this off or use the dietpress_selective_blocks_dequeue filter.', 'wpo-tweaks' ),
			'selective_revslider' => __( 'Slider Revolution loads around 660 KB of JavaScript on every page by default. This turns its own "Include libraries globally" setting off per visit, without saving anything, so Slider Revolution decides: its shortcodes, its widget and its own page list keep working, and DietPress covers what its check misses, such as a shortcode in a widget. If your theme prints sliders with add_revslider(), add those pages to the Slider Revolution list.', 'wpo-tweaks' ),
			'selective_tablepress' => __( 'On classic themes TablePress loads its stylesheet on every page; on block themes it already loads it only where a table is rendered. This turns that same conditional loading on for classic themes through the plugin own tablepress_frontend_legacy_css_loading filter, so no table can end up unstyled. Nothing is dequeued. Only acts when TablePress is active.', 'wpo-tweaks' ),
			'selective_smash_balloon' => __( 'Smash Balloon has a setting to load its 42 KB stylesheet only where a feed is shown, and it ships turned off. This turns it on for visitors, leaving the admin screens untouched, so Smash Balloon itself loads the styles when a feed is rendered. Nothing is dequeued. Only acts when Smash Balloon Social Photo Feed is active.', 'wpo-tweaks' ),
			'selective_formidable' => __( 'Removes the Formidable Forms stylesheet on pages with no form (shortcode or block in content or widgets). Only acts when Formidable is set to load its styles on all pages, which is the default, and its own footer fallback is put back in play so a form printed from a template still gets styled.', 'wpo-tweaks' ),
			'selective_everest_forms' => __( 'Removes the Everest Forms stylesheets on pages with no form (shortcode or block in content or widgets), including the Dashicons stylesheet it forces on every visitor. Dashicons is only removed for logged-out visitors and when no other stylesheet depends on it; the dietpress_selective_everest_forms_dequeue_dashicons filter turns that part off.', 'wpo-tweaks' ),
			'htaccess_rules' => __( 'Master switch for the compression and connection rules of the managed .htaccess block. The file is backed up before the first change and the block is removed cleanly when everything that writes to it is turned off. Apache or LiteSpeed only.', 'wpo-tweaks' ),
			'htaccess_browser_cache' => __( 'Master switch for the browser caching rules of the managed .htaccess block. Independent of the compression switch in the Strict tab: either one can be on without the other. Apache or LiteSpeed only.', 'wpo-tweaks' ),
			'htaccess_expires' => __( 'Sets far-future Expires headers for images, fonts, CSS, JS and media so returning visitors reuse cached files (mod_expires).', 'wpo-tweaks' ),
			'htaccess_gzip' => __( 'Compresses text-based responses (HTML, CSS, JS, JSON, SVG, fonts) before sending them, reducing transfer size (mod_deflate).', 'wpo-tweaks' ),
			'htaccess_brotli' => __( 'Adds Brotli compression for text assets on servers that support mod_brotli. Safely ignored if the module is unavailable.', 'wpo-tweaks' ),
			'htaccess_cache_headers' => __( 'Sends Cache-Control with the same lifetimes as the Expires rules above, marks versioned styles, scripts and fonts as immutable, and adds Vary Accept-Encoding (mod_headers).', 'wpo-tweaks' ),
			'htaccess_etag' => __( 'Drops the ETag header from static files. With a max-age already set it adds nothing, and on some clustered hosting the value differs per server and defeats the cache.', 'wpo-tweaks' ),
			'htaccess_cors_fonts' => __( 'Adds Access-Control-Allow-Origin to font files so they load from a CDN or different subdomain. Disable if your policy forbids a wildcard CORS origin.', 'wpo-tweaks' ),
			'htaccess_keepalive' => __( 'Sends a Connection keep-alive header to encourage connection reuse. Some managed hosts manage keep-alive themselves and may ignore it.', 'wpo-tweaks' ),

			// Emails.
			'disable_auto_core_update_email' => __( 'Stops the email WordPress sends after an automatic core update. Critical failure notices are always kept.', 'wpo-tweaks' ),
			'disable_core_update_available_email' => __( 'Stops the email warning that a new core version is available but will not auto-install. Warning: if core auto-updates are off, this is your only notice that an update (possibly a security release) is waiting.', 'wpo-tweaks' ),
			'disable_auto_plugin_update_email' => __( 'Stops the email WordPress sends after plugins auto-update.', 'wpo-tweaks' ),
			'disable_auto_theme_update_email' => __( 'Stops the email WordPress sends after themes auto-update.', 'wpo-tweaks' ),
			'disable_comment_moderation_email' => __( 'Stops the email sent to moderators when a comment is held for moderation. Useful with Akismet or panel moderation.', 'wpo-tweaks' ),
			'disable_comment_author_email' => __( 'Stops the email sent to the post author when a comment is published on their post.', 'wpo-tweaks' ),
			'disable_new_user_admin_email' => __( 'Stops the email sent to the admin when a new user account is created.', 'wpo-tweaks' ),
			'disable_new_user_email' => __( 'Stops the welcome email sent to a new user. Warning: this email carries the set-password link. Do not disable on sites with open registration or new accounts are left without a password.', 'wpo-tweaks' ),
			'disable_password_reset_admin_email' => __( 'Stops the email sent to the admin when a user resets their password.', 'wpo-tweaks' ),
			'disable_password_change_email' => __( 'Stops the email sent to a user after their password changes.', 'wpo-tweaks' ),
			'disable_email_change_email' => __( 'Stops the email sent to a user after their account email changes.', 'wpo-tweaks' ),
			'disable_admin_email_change_email' => __( 'Stops the notice sent to the old address when the site admin email changes. The confirmation sent to the new address is kept, as it completes the change.', 'wpo-tweaks' ),
		);
	}
}