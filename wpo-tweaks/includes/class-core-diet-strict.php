<?php
/**
 * DietPress Strict features.
 *
 * Site-specific settings. Impact depends on the site type and requirements.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Strict {

	/** @var Core_Diet_Settings */
	private $settings;

	/**
	 * @param Core_Diet_Settings $settings Settings instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Initialize hooks for enabled features.
	 */
	public function init() {
		$this->handle_heartbeat();
		$this->handle_revisions();
		$this->handle_autosave();
		$this->handle_rest_api();

		if ( $this->settings->is_enabled( 'disable_comments' ) ) {
			$this->disable_comments();
		}

		if ( $this->settings->is_enabled( 'disable_feed_all' ) ) {
			// Nuclear option: disable ALL feeds.
			$this->disable_all_feeds();
		} else {
			// Granular feed control.
			$this->handle_granular_feeds();
		}

		if ( $this->settings->is_enabled( 'enable_classic_widgets' ) ) {
			add_filter( 'gutenberg_use_widgets_block_editor', '__return_false' );
			add_filter( 'use_widgets_block_editor', '__return_false' );
		}

		if ( $this->settings->is_enabled( 'disable_sitemap' ) ) {
			add_filter( 'wp_sitemaps_enabled', '__return_false' );
		}

		if ( $this->settings->is_enabled( 'disable_lazy_loading' ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_false' );
		}

		if ( $this->settings->is_enabled( 'disable_fetchpriority' ) ) {
			add_filter( 'wp_get_loading_optimization_attributes', array( $this, 'remove_fetchpriority' ), 10, 1 );
		}

		if ( $this->settings->is_enabled( 'disable_version_params' ) ) {
			add_filter( 'style_loader_src', array( $this, 'remove_version_param' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'remove_version_param' ), 9999 );
			add_filter( 'wp_get_script_modules_importmap', array( $this, 'clean_importmap' ) );
		}

		if ( $this->settings->is_enabled( 'disable_privacy_tools' ) ) {
			$this->disable_privacy_tools();
		}

		if ( $this->settings->is_enabled( 'disable_internal_search' ) ) {
			$this->disable_internal_search();
		}

		if ( $this->settings->is_enabled( 'disable_posts_content_type' ) ) {
			$this->disable_posts_content_type();
		}

		if ( $this->settings->is_enabled( 'disable_pages_content_type' ) ) {
			$this->disable_pages_content_type();
		}
	}

	/**
	 * Handle Heartbeat API configuration.
	 */
	private function handle_heartbeat() {
		$mode = $this->settings->get( 'heartbeat_mode' );

		if ( 'default' === $mode ) {
			return;
		}

		if ( 'disable' === $mode ) {
			add_action( 'init', array( $this, 'deregister_heartbeat' ), 1 );
			return;
		}

		if ( 'admin_only' === $mode ) {
			add_action( 'init', array( $this, 'heartbeat_admin_only' ), 1 );
			return;
		}

		if ( 'reduce' === $mode ) {
			add_filter( 'heartbeat_settings', array( $this, 'reduce_heartbeat_frequency' ) );
		}
	}

	/**
	 * Deregister Heartbeat script completely.
	 */
	public function deregister_heartbeat() {
		wp_deregister_script( 'heartbeat' );

		// Remove heartbeat from wp-auth-check dependencies (WP 6.9+ notice fix).
		global $wp_scripts;
		if ( isset( $wp_scripts->registered['wp-auth-check'] ) ) {
			$wp_scripts->registered['wp-auth-check']->deps = array_values(
				array_diff( $wp_scripts->registered['wp-auth-check']->deps, array( 'heartbeat' ) )
			);
		}
	}

	/**
	 * Only allow Heartbeat on admin pages (disable on post editor and frontend).
	 */
	public function heartbeat_admin_only() {
		global $pagenow;

		// Allow on admin dashboard only, disable on post editor.
		if ( 'post.php' === $pagenow || 'post-new.php' === $pagenow || ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );

			// Remove heartbeat from wp-auth-check dependencies (WP 6.9+ notice fix).
			global $wp_scripts;
			if ( isset( $wp_scripts->registered['wp-auth-check'] ) ) {
				$wp_scripts->registered['wp-auth-check']->deps = array_values(
					array_diff( $wp_scripts->registered['wp-auth-check']->deps, array( 'heartbeat' ) )
				);
			}
		}
	}

	/**
	 * Reduce Heartbeat interval to 60 seconds.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function reduce_heartbeat_frequency( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

	/**
	 * Disable the comment system completely.
	 */
	private function disable_comments() {
		// Close comments and pingbacks on all existing content.
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );

		// Hide existing comments.
		add_filter( 'comments_array', '__return_empty_array', 10 );

		// Remove comment-related admin elements.
		add_action( 'admin_init', array( $this, 'disable_comments_admin' ) );
		add_action( 'admin_menu', array( $this, 'disable_comments_menu' ), 999 );

		// Remove comment support from all post types.
		add_action( 'init', array( $this, 'remove_comment_support' ), 100 );

		// Remove comments from admin bar.
		add_action( 'wp_before_admin_bar_render', function() {
			global $wp_admin_bar;
			$wp_admin_bar->remove_node( 'comments' );
		} );

		// Remove comments feed link from head.
		add_filter( 'feed_links_show_comments_feed', '__return_false' );

		// Remove comment count from dashboard Activity widget.
		add_action( 'admin_enqueue_scripts', array( $this, 'hide_comments_dashboard_css' ) );
	}

	/**
	 * Remove comment-related admin pages and redirect.
	 */
	public function disable_comments_admin() {
		// Remove Comments metabox from dashboard.
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );

		// Remove comment column from post and page lists.
		add_filter( 'manage_posts_columns', array( $this, 'remove_comments_column' ) );
		add_filter( 'manage_pages_columns', array( $this, 'remove_comments_column' ) );

		// Redirect if someone tries to access comments page directly.
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect only, no data processing.
		if ( 'edit-comments.php' === $pagenow || 'options-discussion.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * Remove comment-related menu items.
	 */
	public function disable_comments_menu() {
		// Remove Comments top-level menu.
		remove_menu_page( 'edit-comments.php' );

		// Remove Discussion submenu from Settings.
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/**
	 * Remove comments column from post/page lists.
	 *
	 * @param array $columns Admin list table columns.
	 * @return array
	 */
	public function remove_comments_column( $columns ) {
		unset( $columns['comments'] );
		return $columns;
	}

	/**
	 * Hide comment-related elements from the dashboard Activity widget.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function hide_comments_dashboard_css( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		$css = '#dashboard_activity .comments, #latest-comments { display: none !important; }';
		wp_add_inline_style( 'common', $css );
	}

	/**
	 * Remove comment support from all registered post types.
	 */
	public function remove_comment_support() {
		$post_types = get_post_types( array(), 'names' );
		foreach ( $post_types as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/**
	 * Disable ALL RSS/Atom feeds and redirect to homepage.
	 */
	private function disable_all_feeds() {
		$feed_hooks = array(
			'do_feed', 'do_feed_rdf', 'do_feed_rss',
			'do_feed_rss2', 'do_feed_atom',
		);

		foreach ( $feed_hooks as $hook ) {
			add_action( $hook, array( $this, 'redirect_feeds' ), 1 );
		}

		// Remove all feed links from head.
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
	}

	/**
	 * Handle individual granular feed settings.
	 */
	private function handle_granular_feeds() {
		if ( $this->settings->is_enabled( 'disable_feed_comments' ) ) {
			// Disable global comment feed and per-post comment feeds.
			add_action( 'do_feed_rss2', array( $this, 'maybe_redirect_comment_feed' ), 1, 1 );
			add_action( 'do_feed_atom', array( $this, 'maybe_redirect_comment_feed' ), 1, 1 );
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
		}

		if ( $this->settings->is_enabled( 'disable_feed_taxonomies' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_taxonomy_feeds' ) );
		}

		if ( $this->settings->is_enabled( 'disable_feed_authors' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_author_feeds' ) );
		}

		if ( $this->settings->is_enabled( 'disable_feed_search' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_search_feeds' ) );
		}

		if ( $this->settings->is_enabled( 'disable_feed_links_head' ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}
	}

	/**
	 * Redirect feed requests to the homepage.
	 */
	public function redirect_feeds() {
		wp_safe_redirect( home_url(), 301 );
		exit;
	}

	/**
	 * Redirect comment feeds (global and per-post) to homepage.
	 *
	 * @param bool $is_comment_feed Whether this is a comment feed.
	 */
	public function maybe_redirect_comment_feed( $is_comment_feed ) {
		if ( $is_comment_feed ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Redirect taxonomy (category/tag/custom) feeds to homepage.
	 */
	public function redirect_taxonomy_feeds() {
		if ( is_feed() && ( is_category() || is_tag() || is_tax() ) ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Redirect author feeds to homepage.
	 */
	public function redirect_author_feeds() {
		if ( is_feed() && is_author() ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Redirect search result feeds to homepage.
	 */
	public function redirect_search_feeds() {
		if ( is_feed() && is_search() ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Remove Privacy tools from admin.
	 */
	private function disable_privacy_tools() {
		add_action( 'admin_menu', function() {
			remove_submenu_page( 'options-general.php', 'options-privacy.php' );
			remove_submenu_page( 'tools.php', 'export-personal-data' );
			remove_submenu_page( 'tools.php', 'erase-personal-data' );
		}, 999 );

		// Redirect if someone accesses the pages directly.
		add_action( 'admin_init', function() {
			global $pagenow;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect only, no data processing.
			$blocked = array( 'options-privacy.php', 'export-personal-data.php', 'erase-personal-data.php' );
			if ( in_array( $pagenow, $blocked, true ) ) {
				wp_safe_redirect( admin_url() );
				exit;
			}
		} );
	}

	/**
	 * Disable internal site search.
	 */
	private function disable_internal_search() {
		// Disable search query.
		add_action( 'parse_query', function( $query ) {
			if ( ! is_admin() && $query->is_search ) {
				$query->is_search = false;
				$query->query_vars['s'] = '';
				$query->set( 'post__in', array( 0 ) ); // Return no results.
			}
		} );

		// Redirect frontend search to homepage.
		add_action( 'template_redirect', function() {
			if ( is_search() ) {
				wp_safe_redirect( home_url(), 301 );
				exit;
			}
		} );

		// Remove search widget from available widgets.
		add_action( 'widgets_init', function() {
			unregister_widget( 'WP_Widget_Search' );
		} );
	}

	/**
	 * Disable Posts content type completely.
	 *
	 * Uses register_post_type_args to properly hide the post type from
	 * admin UI, nav menus, search, and frontend. Additional hooks handle
	 * the admin bar and redirects as safety nets.
	 */
	private function disable_posts_content_type() {
		// Modify the post type registration to hide it everywhere.
		add_filter( 'register_post_type_args', function( $args, $post_type ) {
			if ( 'post' === $post_type ) {
				$args['show_ui']            = false;
				$args['show_in_nav_menus']  = false;
				$args['exclude_from_search'] = true;
				$args['publicly_queryable'] = false;
				$args['has_archive']        = false;
			}
			return $args;
		}, 10, 2 );

		// Remove +New > Post from admin bar.
		add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
			$wp_admin_bar->remove_node( 'new-post' );
		}, 999 );

		// Redirect single post views to homepage (safety net).
		add_action( 'template_redirect', function() {
			if ( is_singular( 'post' ) ) {
				wp_safe_redirect( home_url(), 301 );
				exit;
			}
		} );

		// If site uses "latest posts" as homepage, show nothing.
		add_action( 'pre_get_posts', function( $query ) {
			if ( ! is_admin() && $query->is_main_query() && $query->is_home() ) {
				$query->set( 'post__in', array( 0 ) );
			}
		} );

		// Remove already-added post items from existing nav menus.
		add_filter( 'wp_get_nav_menu_items', function( $items ) {
			return array_filter( $items, function( $item ) {
				return ! ( 'post_type' === $item->type && 'post' === $item->object );
			} );
		} );

		// Hide post activity from dashboard Activity widget.
		add_action( 'admin_enqueue_scripts', function( $hook ) {
			if ( 'index.php' !== $hook ) {
				return;
			}
			$css = '#dashboard_activity .post-type-post { display: none !important; }';
			wp_add_inline_style( 'common', $css );
		} );
	}

	/**
	 * Disable Pages content type completely.
	 *
	 * Uses register_post_type_args to hide pages from admin UI, nav menus,
	 * and search. Keeps publicly_queryable true so the static front page
	 * (if set as a page) continues to work.
	 */
	private function disable_pages_content_type() {
		// Modify the page post type registration.
		add_filter( 'register_post_type_args', function( $args, $post_type ) {
			if ( 'page' === $post_type ) {
				$args['show_ui']            = false;
				$args['show_in_nav_menus']  = false;
				$args['exclude_from_search'] = true;
				// Keep publicly_queryable true — the static front page needs it.
			}
			return $args;
		}, 10, 2 );

		// Remove +New > Page from admin bar.
		add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
			$wp_admin_bar->remove_node( 'new-page' );
		}, 999 );

		// Remove "Edit Page" link from admin bar on frontend.
		add_action( 'wp_before_admin_bar_render', function() {
			if ( is_singular( 'page' ) ) {
				global $wp_admin_bar;
				$wp_admin_bar->remove_node( 'edit' );
			}
		} );

		// Redirect single page views to homepage (except front page).
		add_action( 'template_redirect', function() {
			if ( is_singular( 'page' ) && ! is_front_page() ) {
				wp_safe_redirect( home_url(), 301 );
				exit;
			}
		} );

		// Remove already-added page items from existing nav menus.
		add_filter( 'wp_get_nav_menu_items', function( $items ) {
			return array_filter( $items, function( $item ) {
				return ! ( 'post_type' === $item->type && 'page' === $item->object );
			} );
		} );

		// Exclude pages from auto-generated fallback menus (wp_page_menu).
		add_filter( 'wp_page_menu_args', function( $args ) {
			$args['include'] = '0';
			return $args;
		} );

		// Hide page activity from dashboard Activity widget.
		add_action( 'admin_enqueue_scripts', function( $hook ) {
			if ( 'index.php' !== $hook ) {
				return;
			}
			$css = '#dashboard_activity .post-type-page { display: none !important; }';
			wp_add_inline_style( 'common', $css );
		} );
	}

	/**
	 * Handle revisions configuration.
	 */
	private function handle_revisions() {
		$mode = $this->settings->get( 'revisions_mode' );

		if ( 'default' === $mode ) {
			return;
		}

		if ( 'disable' === $mode ) {
			add_filter( 'wp_revisions_to_keep', '__return_zero' );
			return;
		}

		if ( 'limit' === $mode ) {
			$limit = absint( $this->settings->get( 'revisions_limit' ) );
			if ( $limit > 0 ) {
				add_filter( 'wp_revisions_to_keep', function() use ( $limit ) {
					return $limit;
				} );
			}
		}
	}

	/**
	 * Handle autosave interval.
	 *
	 * WordPress has two autosave systems:
	 * - Classic editor: uses the 'autosave' script and AUTOSAVE_INTERVAL constant.
	 * - Block editor (Gutenberg): uses internal REST API calls and reads
	 *   autosaveInterval from block_editor_settings_all.
	 *
	 * Both must be handled for complete coverage.
	 */
	private function handle_autosave() {
		$interval = (int) $this->settings->get( 'autosave_interval' );

		// Default (60) = no changes needed.
		if ( 60 === $interval ) {
			return;
		}

		// Define AUTOSAVE_INTERVAL for both classic and block editors.
		// Must be defined early, before the editor loads.
		if ( ! defined( 'AUTOSAVE_INTERVAL' ) ) {
			// When disabling, set to a very high value so Gutenberg
			// effectively never triggers (0 would be ignored as falsy).
			define( 'AUTOSAVE_INTERVAL', 0 === $interval ? 86400 : $interval );
		}

		// Disable autosave completely.
		if ( 0 === $interval ) {
			// Deregister classic editor autosave script.
			add_action( 'admin_enqueue_scripts', function() {
				wp_deregister_script( 'autosave' );
			} );

			// Override block editor autosave interval.
			add_filter( 'block_editor_settings_all', function( $settings ) {
				$settings['autosaveInterval'] = 86400; // 24 hours = effectively disabled.
				return $settings;
			} );

			return;
		}

		// Custom interval: also pass to block editor.
		add_filter( 'block_editor_settings_all', function( $settings ) use ( $interval ) {
			$settings['autosaveInterval'] = $interval;
			return $settings;
		} );
	}

	/**
	 * Handle REST API access restrictions.
	 */
	private function handle_rest_api() {
		$mode = $this->settings->get( 'rest_api_mode' );

		if ( 'default' === $mode ) {
			return;
		}

		if ( 'authenticated' === $mode ) {
			add_filter( 'rest_authentication_errors', array( $this, 'restrict_rest_to_authenticated' ) );
			return;
		}

		if ( 'disable' === $mode ) {
			add_filter( 'rest_authentication_errors', array( $this, 'disable_rest_api' ) );
		}
	}

	/**
	 * Restrict REST API to authenticated users only.
	 *
	 * @param WP_Error|null|true $result Current auth result.
	 * @return WP_Error|null|true
	 */
	public function restrict_rest_to_authenticated( $result ) {
		if ( true === $result || is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access the REST API.', 'wpo-tweaks' ),
				array( 'status' => 401 )
			);
		}

		return $result;
	}

	/**
	 * Disable REST API for non-administrator users.
	 *
	 * Administrators retain access so the block editor can function.
	 *
	 * @param WP_Error|null|true $result Current auth result.
	 * @return WP_Error|null|true
	 */
	public function disable_rest_api( $result ) {
		if ( true === $result || is_wp_error( $result ) ) {
			return $result;
		}

		// Allow administrators to keep the block editor working.
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return $result;
		}

		return new WP_Error(
			'rest_disabled',
			__( 'The REST API is disabled on this site.', 'wpo-tweaks' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Remove the ?ver= parameter from asset URLs.
	 *
	 * Cache-busting query strings are stripped from WordPress core, theme and
	 * wp-includes assets. Plugin assets are LEFT ALONE on purpose: combined with
	 * the immutable Cache-Control headers DietPress writes to .htaccess, removing
	 * the ?ver= from a plugin asset would freeze it in browser caches for a year,
	 * so any plugin update would never reach existing visitors. jQuery is also
	 * preserved, and admin requests are skipped.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function remove_version_param( $src ) {
		if ( is_admin() ) {
			return $src;
		}

		// Keep versions for jQuery scripts that may rely on them.
		$keep_versions = array( 'jquery', 'jquery-core', 'jquery-migrate' );
		foreach ( $keep_versions as $script ) {
			if ( strpos( $src, $script ) !== false ) {
				return $src;
			}
		}

		// Preserve ?ver= on /wp-content/plugins/ assets so plugin updates are not
		// frozen in browser caches. Filterable to override the detection (for
		// example to also keep theme assets, or to strip a specific plugin).
		$content_url     = content_url( 'plugins/' );
		$is_plugin_asset = ( strpos( $src, $content_url ) !== false );
		if ( apply_filters( 'dietpress_preserve_plugin_version', $is_plugin_asset, $src ) ) {
			return $src;
		}

		// Remove version parameters.
		$src = remove_query_arg( 'ver', $src );

		// Remove other version-like parameters.
		$patterns = array( '/\?ver=[^&]*/', '/&ver=[^&]*/', '/\?v=[^&]*/', '/&v=[^&]*/' );
		$src      = preg_replace( $patterns, '', $src );

		return $src;
	}

	/**
	 * Remove version strings from script module importmaps (WordPress 6.5+).
	 *
	 * @param array $importmap The script modules importmap.
	 * @return array
	 */
	public function clean_importmap( $importmap ) {
		if ( is_admin() || ! is_array( $importmap ) || ! isset( $importmap['imports'] ) ) {
			return $importmap;
		}

		foreach ( $importmap['imports'] as $key => $url ) {
			$importmap['imports'][ $key ] = $this->remove_version_param( $url );
		}

		return $importmap;
	}

	/**
	 * Remove fetchpriority attribute from images.
	 *
	 * Filters the loading optimization attributes added since WordPress 6.3.
	 *
	 * @param array $attrs Loading optimization attributes.
	 * @return array
	 */
	public function remove_fetchpriority( $attrs ) {
		unset( $attrs['fetchpriority'] );
		return $attrs;
	}
}