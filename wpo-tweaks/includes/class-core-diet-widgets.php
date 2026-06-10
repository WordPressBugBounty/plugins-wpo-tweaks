<?php
/**
 * DietPress Widgets features.
 *
 * Disable unused widgets from dashboard, sidebars, block editor, and Customizer.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Widgets {

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
		$this->handle_dashboard_widgets();
		$this->handle_classic_widgets();
		$this->handle_block_widgets();
		$this->handle_customizer();
	}

	/**
	 * Disable selected dashboard widgets.
	 */
	private function handle_dashboard_widgets() {
		if ( ! is_admin() ) {
			return;
		}

		// Core dashboard widgets and welcome panel.
		// Must run at wp_dashboard_setup because wp_welcome_panel is hooked
		// in wp-admin/includes/dashboard.php, which loads AFTER plugins.
		add_action( 'wp_dashboard_setup', array( $this, 'remove_dashboard_widgets' ), 20 );
	}

	/**
	 * Remove selected core and third-party dashboard widgets.
	 */
	public function remove_dashboard_widgets() {
		// Welcome panel - must be removed here, after dashboard.php has loaded.
		if ( $this->settings->is_enabled( 'disable_welcome_panel' ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}

		$widget_map = array(
			'disable_dashboard_glance'      => array( 'dashboard_right_now', 'normal' ),
			'disable_dashboard_activity'    => array( 'dashboard_activity', 'normal' ),
			'disable_dashboard_quick_draft' => array( 'dashboard_quick_press', 'side' ),
			'disable_dashboard_events'      => array( 'dashboard_primary', 'side' ),
			'disable_dashboard_site_health' => array( 'dashboard_site_health', 'normal' ),
		);

		foreach ( $widget_map as $setting_key => $widget_info ) {
			if ( $this->settings->is_enabled( $setting_key ) ) {
				remove_meta_box( $widget_info[0], 'dashboard', $widget_info[1] );
			}
		}

		// Third-party dashboard widgets.
		$disabled_third_party = $this->settings->get( 'disable_dashboard_third_party' );
		if ( is_array( $disabled_third_party ) && ! empty( $disabled_third_party ) ) {
			foreach ( $disabled_third_party as $widget_id ) {
				$widget_id = sanitize_key( $widget_id );
				// Try removing from all possible contexts.
				remove_meta_box( $widget_id, 'dashboard', 'normal' );
				remove_meta_box( $widget_id, 'dashboard', 'side' );
				remove_meta_box( $widget_id, 'dashboard', 'column3' );
				remove_meta_box( $widget_id, 'dashboard', 'column4' );
			}
		}
	}

	/**
	 * Unregister selected classic sidebar widgets.
	 */
	private function handle_classic_widgets() {
		add_action( 'widgets_init', array( $this, 'unregister_classic_widgets' ), 20 );
	}

	/**
	 * Unregister classic widgets based on settings.
	 */
	public function unregister_classic_widgets() {
		$widget_map = array(
			'disable_widget_archives'    => 'WP_Widget_Archives',
			'disable_widget_audio'       => 'WP_Widget_Media_Audio',
			'disable_widget_calendar'    => 'WP_Widget_Calendar',
			'disable_widget_categories'  => 'WP_Widget_Categories',
			'disable_widget_custom_html' => 'WP_Widget_Custom_HTML',
			'disable_widget_gallery'     => 'WP_Widget_Media_Gallery',
			'disable_widget_image'       => 'WP_Widget_Media_Image',
			'disable_widget_meta'        => 'WP_Widget_Meta',
			'disable_widget_nav_menu'    => 'WP_Nav_Menu_Widget',
			'disable_widget_pages'       => 'WP_Widget_Pages',
			'disable_widget_comments'    => 'WP_Widget_Recent_Comments',
			'disable_widget_posts'       => 'WP_Widget_Recent_Posts',
			'disable_widget_rss'         => 'WP_Widget_RSS',
			'disable_widget_search'      => 'WP_Widget_Search',
			'disable_widget_tag_cloud'   => 'WP_Widget_Tag_Cloud',
			'disable_widget_text'        => 'WP_Widget_Text',
			'disable_widget_video'       => 'WP_Widget_Media_Video',
		);

		foreach ( $widget_map as $setting_key => $widget_class ) {
			if ( $this->settings->is_enabled( $setting_key ) ) {
				unregister_widget( $widget_class );
			}
		}
	}

	/**
	 * Disable selected block editor widget blocks.
	 *
	 * Uses unregister_block_type() to fully remove block types from the registry.
	 * This prevents them from appearing in the inserter AND removes already-placed
	 * instances from rendering. The allowed_block_types_all filter is kept as a
	 * secondary safeguard for the editor UI.
	 */
	private function handle_block_widgets() {
		$block_map = array(
			'disable_block_archives'        => 'core/archives',
			'disable_block_latest_comments' => 'core/latest-comments',
			'disable_block_latest_posts'    => 'core/latest-posts',
			'disable_block_rss'             => 'core/rss',
			'disable_block_tag_cloud'       => 'core/tag-cloud',
			'disable_block_calendar'        => 'core/calendar',
			'disable_block_search'          => 'core/search',
		);

		$blocks_to_remove = array();
		foreach ( $block_map as $setting_key => $block_name ) {
			if ( $this->settings->is_enabled( $setting_key ) ) {
				$blocks_to_remove[] = $block_name;
			}
		}

		if ( empty( $blocks_to_remove ) ) {
			return;
		}

		// Unregister block types after core blocks are registered (init priority 10).
		add_action( 'init', function() use ( $blocks_to_remove ) {
			$registry = WP_Block_Type_Registry::get_instance();
			foreach ( $blocks_to_remove as $block_name ) {
				if ( $registry->is_registered( $block_name ) ) {
					unregister_block_type( $block_name );
				}
			}
		}, 100 );

		// Fallback: also hide from the editor inserter UI.
		add_filter( 'allowed_block_types_all', function( $allowed_blocks ) use ( $blocks_to_remove ) {
			// Get all registered block types.
			$all_blocks  = WP_Block_Type_Registry::get_instance()->get_all_registered();
			$block_names = array_keys( $all_blocks );

			// If another filter already restricted blocks, work with that list.
			if ( is_array( $allowed_blocks ) ) {
				return array_values( array_diff( $allowed_blocks, $blocks_to_remove ) );
			}

			// Otherwise, remove our blocks from the full list.
			return array_values( array_diff( $block_names, $blocks_to_remove ) );
		}, 10, 2 );
	}

	/**
	 * Handle Customizer restrictions.
	 */
	private function handle_customizer() {
		// Disable the Widgets panel in the Customizer.
		// Uses customize_loaded_components filter (required since WP 4.5).
		// Must be added early, before the Customizer initializes its components.
		if ( $this->settings->is_enabled( 'disable_customizer_widgets' ) ) {
			add_filter( 'customize_loaded_components', array( $this, 'remove_customizer_widgets_component' ) );
		}

		// Disable the Customizer completely.
		if ( $this->settings->is_enabled( 'disable_customizer' ) ) {
			add_action( 'admin_init', array( $this, 'disable_customizer_admin' ) );
			add_action( 'admin_menu', array( $this, 'remove_customizer_menu' ), 999 );

			// Remove from admin bar on both admin and frontend.
			add_action( 'wp_before_admin_bar_render', array( $this, 'remove_customizer_admin_bar' ) );
		}
	}

	/**
	 * Remove the Widgets component from the Customizer.
	 *
	 * Filters the list of components loaded by WP_Customize_Manager
	 * to prevent the widgets panel from initializing at all.
	 *
	 * @param array $components List of active Customizer components.
	 * @return array Filtered components without 'widgets'.
	 */
	public function remove_customizer_widgets_component( $components ) {
		$key = array_search( 'widgets', $components, true );
		if ( false !== $key ) {
			unset( $components[ $key ] );
		}
		return $components;
	}

	/**
	 * Redirect Customizer access to the admin dashboard.
	 */
	public function disable_customizer_admin() {
		global $pagenow;

		if ( 'customize.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * Remove Customizer links from admin menus and admin bar.
	 *
	 * WordPress registers the Customizer submenu with a full URL including
	 * query parameters (customize.php?return=...), so remove_submenu_page()
	 * with just 'customize.php' does not match. We iterate the submenu array instead.
	 */
	public function remove_customizer_menu() {
		global $submenu;

		// Remove all Customizer entries from Appearance submenu.
		if ( isset( $submenu['themes.php'] ) ) {
			foreach ( $submenu['themes.php'] as $index => $item ) {
				if ( isset( $item[2] ) && false !== strpos( $item[2], 'customize.php' ) ) {
					unset( $submenu['themes.php'][ $index ] );
				}
			}
		}
	}

	/**
	 * Remove the Customize link from the admin bar.
	 *
	 * Hooked directly to wp_before_admin_bar_render (not inside admin_menu)
	 * so it works on both admin pages and the frontend.
	 */
	public function remove_customizer_admin_bar() {
		global $wp_admin_bar;
		$wp_admin_bar->remove_node( 'customize' );
	}
}