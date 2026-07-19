<?php
/**
 * DietPress Admin interface.
 *
 * Handles the settings page with tabs, field rendering, and admin assets.
 *
 * All four tabs are rendered inside a single <form> so that every field
 * is always present in the POST. Inactive tabs are hidden with CSS and
 * toggled with JavaScript — no page reload needed to switch tabs.
 *
 * @package Core_Diet
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Admin {

	/** @var string Settings page slug. */
	const PAGE_SLUG = 'dietpress';

	/** @var array Available tabs. */
	private $tabs = array();

	/**
	 * Get available tabs (lazy-loaded to avoid early translation calls).
	 *
	 * @return array Tab ID => Label pairs.
	 */
	private function get_tabs() {
		if ( empty( $this->tabs ) ) {
			$this->tabs = array(
				'scale'    => __( 'Scale', 'wpo-tweaks' ),
				'light'    => __( 'Light', 'wpo-tweaks' ),
				'moderate' => __( 'Moderate', 'wpo-tweaks' ),
				'strict'   => __( 'Strict', 'wpo-tweaks' ),
				'widgets'  => __( 'Widgets', 'wpo-tweaks' ),
				'emails'   => __( 'Emails', 'wpo-tweaks' ),
				'tools'    => __( 'Tools', 'wpo-tweaks' ),
			);
		}
		return $this->tabs;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Tabs are populated lazily via get_tabs() to avoid early translation loading.
	}

	/**
	 * Register the top-level DietPress admin menu page.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'DietPress', 'wpo-tweaks' ),
			__( 'DietPress', 'wpo-tweaks' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-food',
			999
		);
	}

	/**
	 * Add settings link to the plugins list.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'wpo-tweaks' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register settings with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			Core_Diet_Settings::OPTION_GROUP,
			Core_Diet_Settings::OPTION_NAME,
			array(
				'sanitize_callback' => array( 'Core_Diet_Settings', 'sanitize' ),
				'default'           => Core_Diet_Settings::get_defaults(),
			)
		);
	}

	/**
	 * Enqueue admin assets only on the plugin settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'core-diet-admin',
			CORE_DIET_URL . 'assets/css/admin.css',
			array(),
			CORE_DIET_VERSION
		);

		wp_enqueue_script(
			'core-diet-admin',
			CORE_DIET_URL . 'assets/js/admin.js',
			array(),
			CORE_DIET_VERSION,
			true
		);

		// Pass AJAX data for Tools tab.
		wp_localize_script( 'core-diet-admin', 'coreDietAdmin', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'core_diet_tools_nonce' ),
			'strings'  => array(
				/* translators: %s: profile name */
				'confirmProfile'    => __( 'This will overwrite all your current settings with the "%s" profile. Are you sure?', 'wpo-tweaks' ),
				'confirmImport'     => __( 'This will overwrite all your current settings with the imported configuration. Are you sure?', 'wpo-tweaks' ),
				'confirmRestore'    => __( 'This will restore all settings to defaults and remove applied optimizations. Are you sure?', 'wpo-tweaks' ),
				'analyzing'         => __( 'Analyzing your site...', 'wpo-tweaks' ),
				'noRecommendations' => __( 'Your site is fully optimized! No additional recommendations at this time.', 'wpo-tweaks' ),
				'error'             => __( 'An error occurred. Please try again.', 'wpo-tweaks' ),
				'importSuccess'     => __( 'Settings imported successfully. Reloading...', 'wpo-tweaks' ),
				'profileSuccess'    => __( 'Profile applied successfully. Reloading...', 'wpo-tweaks' ),
				'restoreSuccess'    => __( 'Settings restored to defaults. Reloading...', 'wpo-tweaks' ),
				'riskSafe'          => __( 'Safe', 'wpo-tweaks' ),
				'riskRecommended'   => __( 'Recommended', 'wpo-tweaks' ),
				'riskModerate'      => __( 'Evaluate', 'wpo-tweaks' ),
				'analyzeBtn'        => __( 'Analyze my site', 'wpo-tweaks' ),
				'saveRecs'          => __( 'Save settings', 'wpo-tweaks' ),
				'saving'            => __( 'Saving...', 'wpo-tweaks' ),
				'savedRecs'         => __( 'Settings saved. Reloading...', 'wpo-tweaks' ),
				'filterAll'         => __( 'All', 'wpo-tweaks' ),
				'filterSafe'        => __( 'Safe only', 'wpo-tweaks' ),
				'filterSafeRec'     => __( 'Safe + Recommended', 'wpo-tweaks' ),
				/* translators: %1$d: enabled count, %2$d: total count */
				'recCounter'        => __( '%1$d of %2$d selected', 'wpo-tweaks' ),
				/* translators: %s: savings summary like "3 req, 52.0 KB" */
				'recSavings'        => __( ' — ≈ %s saved', 'wpo-tweaks' ),
				'tabLight'          => __( 'Light', 'wpo-tweaks' ),
				'tabModerate'       => __( 'Moderate', 'wpo-tweaks' ),
				'tabStrict'         => __( 'Strict', 'wpo-tweaks' ),
				'recTip'            => __( 'Adjust this in the Strict tab.', 'wpo-tweaks' ),
				'quickSelect'       => __( 'Quick select:', 'wpo-tweaks' ),
			),
		) );
	}

	/**
	 * Render the settings page.
	 *
	 * Two-column layout: form on the left, promo sidebar on the right.
	 * Settings tabs (Light, Moderate, Strict, Widgets) are inside the form.
	 * The Tools tab is rendered outside the form (uses AJAX, not Settings API).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpo-tweaks' ) );
		}

		$current_tab   = $this->get_current_tab();
		$settings_tabs = array( 'light', 'moderate', 'strict', 'widgets', 'emails' );
		$ajax_tabs     = array( 'scale', 'tools' );
		?>
		<div class="wrap core-diet-wrap">
			<h1>
				<span class="dashicons dashicons-food core-diet-title-icon"></span>
				<?php echo esc_html( get_admin_page_title() ); ?>
			</h1>

			<?php settings_errors( 'core_diet_settings' ); ?>

			<div class="core-diet-layout">
				<div class="core-diet-main">

					<nav class="nav-tab-wrapper core-diet-tabs">
						<?php foreach ( $this->get_tabs() as $tab_id => $tab_label ) : ?>
							<a href="#<?php echo esc_attr( $tab_id ); ?>"
							   class="nav-tab core-diet-nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>"
							   data-tab="<?php echo esc_attr( $tab_id ); ?>">
								<?php echo esc_html( $tab_label ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<?php
					// Scale tab: outside the form (uses AJAX endpoints).
					$scale_class = ( 'scale' === $current_tab )
						? 'core-diet-tab-panel core-diet-tab-active'
						: 'core-diet-tab-panel';
					?>
					<div id="core-diet-tab-scale" class="<?php echo esc_attr( $scale_class ); ?>">
						<?php
						$tools = new Core_Diet_Tools();
						$tools->render_scale_tab();
						?>
					</div>

					<form method="post" action="options.php" class="core-diet-form">
						<?php settings_fields( Core_Diet_Settings::OPTION_GROUP ); ?>

						<?php
						// Render settings tabs (Light, Moderate, Strict, Widgets).
						foreach ( $settings_tabs as $tab_id ) {
							$is_active = ( $tab_id === $current_tab );
							$class     = $is_active ? 'core-diet-tab-panel core-diet-tab-active' : 'core-diet-tab-panel';
							?>
							<div id="core-diet-tab-<?php echo esc_attr( $tab_id ); ?>"
							     class="<?php echo esc_attr( $class ); ?>">
								<?php
								switch ( $tab_id ) {
									case 'light':
										$this->render_tab_light();
										break;
									case 'moderate':
										$this->render_tab_moderate();
										break;
									case 'strict':
										$this->render_tab_strict();
										break;
									case 'widgets':
										$this->render_tab_widgets();
										break;
									case 'emails':
										$this->render_tab_emails();
										break;
								}
								?>
							</div>
							<?php
						}
						?>

						<div class="core-diet-submit-wrap<?php echo in_array( $current_tab, $ajax_tabs, true ) ? ' core-diet-hidden' : ''; ?>">
							<?php submit_button( __( 'Save settings', 'wpo-tweaks' ) ); ?>
							<button type="button" id="core-diet-restore-defaults" class="button button-link-delete">
								<?php echo esc_html__( 'Restore defaults', 'wpo-tweaks' ); ?>
							</button>
						</div>
					</form>

					<?php
					// Tools tab: outside the form (import/export only).
					$tools_class = ( 'tools' === $current_tab )
						? 'core-diet-tab-panel core-diet-tab-active'
						: 'core-diet-tab-panel';
					?>
					<div id="core-diet-tab-tools" class="<?php echo esc_attr( $tools_class ); ?>">
						<?php
						if ( ! isset( $tools ) ) {
							$tools = new Core_Diet_Tools();
						}
						$tools->render_tools_tab();
						?>
					</div>

				</div><!-- .core-diet-main -->

				<div class="core-diet-sidebar">
					<?php
					// Promotional banner in sidebar.
					$promo_banner = new Core_Diet_Promo_Banner( 'core-diet' );
					$promo_banner->render();
					?>
				</div><!-- .core-diet-sidebar -->

			</div><!-- .core-diet-layout -->
		</div>
		<?php
	}

	/**
	 * Render the Light tab content.
	 *
	 * Organized in sections: Header cleanup, Legacy features, Admin & media,
	 * Performance. Each section has its own toggle-all button.
	 */
	private function render_tab_light() {
		$descriptions = Core_Diet_Settings::get_descriptions();

		echo '<p class="core-diet-tab-description">';
		echo esc_html__( 'Safe to disable on any site. These features are legacy, cosmetic, or have no impact on core functionality.', 'wpo-tweaks' );
		echo '</p>';

		echo '<div class="core-diet-cards-grid">';

		// Section: Header cleanup.
		$this->render_section_title( __( 'Header cleanup', 'wpo-tweaks' ) );

		$header_fields = array(
			'disable_emojis'         => __( 'Disable emoji scripts and styles', 'wpo-tweaks' ),
			'disable_rsd_link'       => __( 'Remove RSD (Really Simple Discovery) link', 'wpo-tweaks' ),
			'disable_wlw_manifest'   => __( 'Remove Windows Live Writer manifest', 'wpo-tweaks' ),
			'disable_shortlink'      => __( 'Remove shortlink from head', 'wpo-tweaks' ),
		);
		foreach ( $header_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Section: Legacy features.
		$this->render_section_title( __( 'Legacy features', 'wpo-tweaks' ) );

		$security_fields = array(
			'disable_self_pingbacks' => __( 'Disable self-pingbacks', 'wpo-tweaks' ),
		);
		foreach ( $security_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Section: Admin & media.
		$this->render_section_title( __( 'Admin & media', 'wpo-tweaks' ) );

		$admin_fields = array(
			'disable_capital_p'          => __( 'Disable Capital P Dangit filter', 'wpo-tweaks' ),
			'disable_update_notices'     => __( 'Hide update notices for non-administrators', 'wpo-tweaks' ),
			'disable_comment_pagination' => __( 'Disable comment pagination', 'wpo-tweaks' ),
			'disable_wp_logo_admin_bar'  => __( 'Remove WordPress logo from admin bar', 'wpo-tweaks' ),
			'disable_image_editor'       => __( 'Disable media library image editor', 'wpo-tweaks' ),
		);
		foreach ( $admin_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}


		// Section: Performance (ported from wpo-tweaks, on by default).
		$this->render_section_title( __( 'Performance', 'wpo-tweaks' ) );

		$perf_fields = array(
			'optimize_google_fonts' => __( 'Optimize Google Fonts loading', 'wpo-tweaks' ),
			'host_google_fonts'     => __( 'Host Google Fonts locally', 'wpo-tweaks' ),
			'resource_hints'        => __( 'Add resource hints (preconnect and dns-prefetch)', 'wpo-tweaks' ),
			'preload_assets'        => __( 'Preload theme stylesheet and critical fonts', 'wpo-tweaks' ),
			'preload_logo'          => __( 'Preload the site logo (LCP)', 'wpo-tweaks' ),
			'optimize_feeds'        => __( 'Optimize RSS feeds', 'wpo-tweaks' ),
			'disable_pdf_previews'  => __( 'Disable PDF thumbnail previews', 'wpo-tweaks' ),
			'clean_transients'      => __( 'Clean expired transients daily', 'wpo-tweaks' ),
		);
		foreach ( $perf_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		echo '</div>';
	}

	/**
	 * Render the Moderate tab content.
	 *
	 * Organized in three sections: Protocols & discovery,
	 * Frontend performance, Editor & content.
	 */
	private function render_tab_moderate() {
		$descriptions = Core_Diet_Settings::get_descriptions();

		echo '<p class="core-diet-tab-description">';
		echo esc_html__( 'Evaluate before disabling. These features may be required by specific plugins, themes, or workflows.', 'wpo-tweaks' );
		echo '</p>';

		echo '<div class="core-diet-cards-grid">';

		// Section: Protocols & discovery.
		$this->render_section_title( __( 'Protocols & discovery', 'wpo-tweaks' ) );

		$protocol_fields = array(
			'disable_oembed'        => __( 'Disable oEmbed discovery and wp-embed.js', 'wpo-tweaks' ),
			'disable_rest_api_link' => __( 'Remove REST API link from head', 'wpo-tweaks' ),
		);
		foreach ( $protocol_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Section: Frontend performance.
		$this->render_section_title( __( 'Frontend performance', 'wpo-tweaks' ) );

		$performance_fields = array(
			'disable_jquery_migrate' => __( 'Remove jQuery Migrate', 'wpo-tweaks' ),
			'disable_dashicons'      => __( 'Remove Dashicons from frontend (non-logged-in users)', 'wpo-tweaks' ),
			'disable_adjacent_posts' => __( 'Remove adjacent post links (rel prev/next)', 'wpo-tweaks' ),
			'disable_global_styles'  => __( 'Remove Global Styles inline CSS', 'wpo-tweaks' ),
			'disable_duotone'        => __( 'Remove Duotone SVG inline filters', 'wpo-tweaks' ),
		);
		foreach ( $performance_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Section: Editor & content.
		$this->render_section_title( __( 'Editor & content', 'wpo-tweaks' ) );

		$editor_fields = array(
			'disable_block_directory'   => __( 'Disable remote block directory in editor', 'wpo-tweaks' ),
			'disable_remote_patterns'   => __( 'Disable remote block patterns from repository', 'wpo-tweaks' ),
			'disable_avatars'           => __( 'Disable avatars and Gravatar', 'wpo-tweaks' ),
			'disable_comment_threading' => __( 'Disable comment threading (nested replies)', 'wpo-tweaks' ),
		);
		foreach ( $editor_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}


		// Section: Images & database (ported from wpo-tweaks, on by default).
		$this->render_section_title( __( 'Images & database', 'wpo-tweaks' ) );

		$img_db_fields = array(
			'enhance_images'           => __( 'Enhance image loading attributes', 'wpo-tweaks' ),
			'add_image_dimensions'     => __( 'Add missing image dimensions (CLS)', 'wpo-tweaks' ),
			'optimize_comment_queries' => __( 'Optimize comment queries', 'wpo-tweaks' ),
			'optimize_main_queries'    => __( 'Optimize main queries', 'wpo-tweaks' ),
		);
		foreach ( $img_db_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		echo '</div>';
	}

	/**
	 * Render the Strict tab content.
	 *
	 * All options rendered as cards in a single grid with section titles.
	 */
	private function render_tab_strict() {
		$settings     = Core_Diet_Settings::get_instance();
		$descriptions = Core_Diet_Settings::get_descriptions();

		echo '<p class="core-diet-tab-description">';
		echo esc_html__( 'Site-specific settings. The impact depends on your site type, installed plugins, and requirements.', 'wpo-tweaks' );
		echo '</p>';

		echo '<div class="core-diet-cards-grid">';

		// --- RSS feeds section ---
		$this->render_section_title( __( 'RSS feeds', 'wpo-tweaks' ) );

		$this->render_toggle_card(
			'disable_feed_all',
			__( 'Disable all feeds (nuclear option)', 'wpo-tweaks' ),
			$descriptions['disable_feed_all']
		);
		$this->render_toggle_card(
			'disable_feed_comments',
			__( 'Disable comment feeds', 'wpo-tweaks' ),
			$descriptions['disable_feed_comments']
		);
		$this->render_toggle_card(
			'disable_feed_taxonomies',
			__( 'Disable category, tag, and taxonomy feeds', 'wpo-tweaks' ),
			$descriptions['disable_feed_taxonomies']
		);
		$this->render_toggle_card(
			'disable_feed_authors',
			__( 'Disable author feeds', 'wpo-tweaks' ),
			$descriptions['disable_feed_authors']
		);
		$this->render_toggle_card(
			'disable_feed_search',
			__( 'Disable search result feeds', 'wpo-tweaks' ),
			$descriptions['disable_feed_search']
		);
		$this->render_toggle_card(
			'disable_feed_links_head',
			__( 'Remove feed discovery links from head', 'wpo-tweaks' ),
			$descriptions['disable_feed_links_head']
		);

		// --- Core behavior section (selects only, no toggle-all) ---
		$this->render_section_title( __( 'Core behavior', 'wpo-tweaks' ), false );

		// Heartbeat API.
		$this->render_select_card(
			'heartbeat_mode',
			__( 'Heartbeat API', 'wpo-tweaks' ),
			array(
				'default'    => __( 'Default (no changes)', 'wpo-tweaks' ),
				'disable'    => __( 'Disable completely', 'wpo-tweaks' ),
				'admin_only' => __( 'Admin dashboard only', 'wpo-tweaks' ),
				'reduce'     => __( 'Reduce frequency (60 seconds)', 'wpo-tweaks' ),
			),
			__( 'The Heartbeat API sends periodic AJAX requests. Reducing or disabling it saves server resources but affects auto-save and real-time features.', 'wpo-tweaks' )
		);

		// Revisions (select + conditional number input).
		$revisions_mode  = $settings->get( 'revisions_mode' );
		$revisions_limit = $settings->get( 'revisions_limit' );
		?>
		<div class="core-diet-option-card">
			<label class="core-diet-option-label" for="core_diet_revisions_mode">
				<?php echo esc_html__( 'Post revisions', 'wpo-tweaks' ); ?>
			</label>
			<select name="<?php echo esc_attr( Core_Diet_Settings::OPTION_NAME ); ?>[revisions_mode]"
			        id="core_diet_revisions_mode" class="core-diet-select-toggle">
				<option value="default" <?php selected( $revisions_mode, 'default' ); ?>>
					<?php echo esc_html__( 'Default (no changes)', 'wpo-tweaks' ); ?>
				</option>
				<option value="disable" <?php selected( $revisions_mode, 'disable' ); ?>>
					<?php echo esc_html__( 'Disable revisions', 'wpo-tweaks' ); ?>
				</option>
				<option value="limit" <?php selected( $revisions_mode, 'limit' ); ?>>
					<?php echo esc_html__( 'Limit revisions', 'wpo-tweaks' ); ?>
				</option>
			</select>
			<span id="core_diet_revisions_limit_wrap"
			      class="core-diet-conditional" data-show-when="limit">
				<input type="number"
				       name="<?php echo esc_attr( Core_Diet_Settings::OPTION_NAME ); ?>[revisions_limit]"
				       value="<?php echo absint( $revisions_limit ); ?>"
				       min="1" max="50" class="small-text">
				<span class="description"><?php echo esc_html__( 'revisions per post', 'wpo-tweaks' ); ?></span>
			</span>
			<p class="core-diet-option-desc">
				<?php echo esc_html__( 'Limiting revisions reduces database bloat. Disabling them saves space but removes version history.', 'wpo-tweaks' ); ?>
			</p>
		</div>
		<?php

		// Autosave interval.
		$this->render_select_card(
			'autosave_interval',
			__( 'Autosave interval', 'wpo-tweaks' ),
			array(
				60  => __( 'Default (60 seconds)', 'wpo-tweaks' ),
				120 => __( '120 seconds', 'wpo-tweaks' ),
				300 => __( '300 seconds (5 minutes)', 'wpo-tweaks' ),
				0   => __( 'Disable autosave', 'wpo-tweaks' ),
			),
			__( 'How often WordPress auto-saves post drafts in the editor. Disabling it stops auto-saves entirely.', 'wpo-tweaks' )
		);

		// --- Advanced section (toggles only, with toggle-all) ---
		$this->render_section_title( __( 'Advanced', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'disable_comments', __( 'Disable comments', 'wpo-tweaks' ), $descriptions['disable_comments'] );
		$this->render_toggle_card( 'enable_classic_widgets', __( 'Restore classic widget editor', 'wpo-tweaks' ), $descriptions['enable_classic_widgets'] );
		$this->render_toggle_card( 'disable_sitemap', __( 'Disable WordPress XML sitemap', 'wpo-tweaks' ), $descriptions['disable_sitemap'] );
		$this->render_toggle_card( 'disable_lazy_loading', __( 'Disable native lazy loading', 'wpo-tweaks' ), $descriptions['disable_lazy_loading'] );
		$this->render_toggle_card( 'disable_fetchpriority', __( 'Disable fetchpriority attribute', 'wpo-tweaks' ), $descriptions['disable_fetchpriority'] );
		$this->render_toggle_card( 'disable_version_params', __( 'Remove version parameter from assets', 'wpo-tweaks' ), $descriptions['disable_version_params'] );
		$this->render_toggle_card( 'disable_privacy_tools', __( 'Remove privacy tools from admin', 'wpo-tweaks' ), $descriptions['disable_privacy_tools'] );
		$this->render_toggle_card( 'disable_internal_search', __( 'Disable internal site search', 'wpo-tweaks' ), $descriptions['disable_internal_search'] );
		$this->render_toggle_card( 'disable_posts_content_type', __( 'Disable Posts content type', 'wpo-tweaks' ), $descriptions['disable_posts_content_type'] );
		$this->render_toggle_card( 'disable_pages_content_type', __( 'Disable Pages content type', 'wpo-tweaks' ), $descriptions['disable_pages_content_type'] );


		// --- Frontend performance (ported from wpo-tweaks, on by default) ---
		$this->render_section_title( __( 'Frontend performance', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'defer_js', __( 'Defer JavaScript parsing', 'wpo-tweaks' ), $descriptions['defer_js'] );
		$this->render_toggle_card( 'critical_css_inline', __( 'Inline critical CSS in head', 'wpo-tweaks' ), $descriptions['critical_css_inline'] );
		$this->render_toggle_card( 'critical_css_defer', __( 'Defer non-critical stylesheets (experimental)', 'wpo-tweaks' ), $descriptions['critical_css_defer'] );

		// --- Selective third-party loading ---
		$this->render_section_title( __( 'Selective loading', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'selective_woocommerce', __( 'Load WooCommerce assets only on store pages', 'wpo-tweaks' ), $descriptions['selective_woocommerce'] );
		$this->render_toggle_card( 'selective_cf7', __( 'Load Contact Form 7 assets only on pages with forms', 'wpo-tweaks' ), $descriptions['selective_cf7'] );
		$this->render_toggle_card( 'selective_blocks', __( 'Load block styles only on pages that use blocks', 'wpo-tweaks' ), $descriptions['selective_blocks'] );

		// --- Server-level rules (.htaccess) ---
		$this->render_section_title( __( 'Server-level rules (.htaccess)', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'htaccess_rules', __( 'Write server performance rules to .htaccess', 'wpo-tweaks' ), $descriptions['htaccess_rules'] );
		$this->render_toggle_card( 'htaccess_expires', __( 'Browser caching (mod_expires)', 'wpo-tweaks' ), $descriptions['htaccess_expires'] );
		$this->render_toggle_card( 'htaccess_gzip', __( 'GZIP compression (mod_deflate)', 'wpo-tweaks' ), $descriptions['htaccess_gzip'] );
		$this->render_toggle_card( 'htaccess_brotli', __( 'Brotli compression (mod_brotli)', 'wpo-tweaks' ), $descriptions['htaccess_brotli'] );
		$this->render_toggle_card( 'htaccess_cache_headers', __( 'Cache-Control, Vary and ETag headers (mod_headers)', 'wpo-tweaks' ), $descriptions['htaccess_cache_headers'] );
		$this->render_toggle_card( 'htaccess_cors_fonts', __( 'Cross-origin font loading (CORS)', 'wpo-tweaks' ), $descriptions['htaccess_cors_fonts'] );
		$this->render_toggle_card( 'htaccess_keepalive', __( 'Keep-alive connections', 'wpo-tweaks' ), $descriptions['htaccess_keepalive'] );

		echo '</div>'; // End cards grid.
	}

	/**
	 * Render the Widgets tab content.
	 *
	 * All widget options as cards in a single grid with section titles.
	 */
	private function render_tab_widgets() {
		echo '<p class="core-diet-tab-description">';
		echo esc_html__( 'Disable unused widgets from the dashboard, sidebars, block editor, and Customizer.', 'wpo-tweaks' );
		echo '</p>';

		$descriptions = Core_Diet_Settings::get_descriptions();

		echo '<div class="core-diet-cards-grid">';

		// Section: Dashboard widgets.
		$this->render_section_title( __( 'Dashboard', 'wpo-tweaks' ) );

		$dashboard_fields = array(
			'disable_welcome_panel'         => __( 'Welcome panel', 'wpo-tweaks' ),
			'disable_dashboard_glance'      => __( 'At a Glance', 'wpo-tweaks' ),
			'disable_dashboard_activity'    => __( 'Activity', 'wpo-tweaks' ),
			'disable_dashboard_quick_draft' => __( 'Quick Draft', 'wpo-tweaks' ),
			'disable_dashboard_events'      => __( 'WordPress Events and News', 'wpo-tweaks' ),
			'disable_dashboard_site_health' => __( 'Site Health Status', 'wpo-tweaks' ),
		);
		foreach ( $dashboard_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Third-party dashboard widgets (dynamic).
		$this->render_third_party_widgets();

		// Section: Block editor widgets.
		$this->render_section_title( __( 'Block editor widgets', 'wpo-tweaks' ) );

		$block_fields = array(
			'disable_block_archives'        => __( 'Archives block', 'wpo-tweaks' ),
			'disable_block_latest_comments' => __( 'Latest Comments block', 'wpo-tweaks' ),
			'disable_block_latest_posts'    => __( 'Latest Posts block', 'wpo-tweaks' ),
			'disable_block_rss'             => __( 'RSS block', 'wpo-tweaks' ),
			'disable_block_tag_cloud'       => __( 'Tag Cloud block', 'wpo-tweaks' ),
			'disable_block_calendar'        => __( 'Calendar block', 'wpo-tweaks' ),
			'disable_block_search'          => __( 'Search block', 'wpo-tweaks' ),
		);
		foreach ( $block_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Section: Customizer.
		$this->render_section_title( __( 'Customizer', 'wpo-tweaks' ) );

		$customizer_fields = array(
			'disable_customizer_widgets' => __( 'Disable Widgets panel in Customizer', 'wpo-tweaks' ),
			'disable_customizer'         => __( 'Disable Customizer completely', 'wpo-tweaks' ),
		);
		foreach ( $customizer_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		// Section: Classic sidebar widgets.
		$this->render_section_title( __( 'Classic widgets', 'wpo-tweaks' ) );

		$classic_fields = array(
			'disable_widget_archives'    => __( 'Archives', 'wpo-tweaks' ),
			'disable_widget_audio'       => __( 'Audio', 'wpo-tweaks' ),
			'disable_widget_calendar'    => __( 'Calendar', 'wpo-tweaks' ),
			'disable_widget_categories'  => __( 'Categories', 'wpo-tweaks' ),
			'disable_widget_custom_html' => __( 'Custom HTML', 'wpo-tweaks' ),
			'disable_widget_gallery'     => __( 'Gallery', 'wpo-tweaks' ),
			'disable_widget_image'       => __( 'Image', 'wpo-tweaks' ),
			'disable_widget_meta'        => __( 'Meta', 'wpo-tweaks' ),
			'disable_widget_nav_menu'    => __( 'Navigation Menu', 'wpo-tweaks' ),
			'disable_widget_pages'       => __( 'Pages', 'wpo-tweaks' ),
			'disable_widget_comments'    => __( 'Recent Comments', 'wpo-tweaks' ),
			'disable_widget_posts'       => __( 'Recent Posts', 'wpo-tweaks' ),
			'disable_widget_rss'         => __( 'RSS', 'wpo-tweaks' ),
			'disable_widget_search'      => __( 'Search', 'wpo-tweaks' ),
			'disable_widget_tag_cloud'   => __( 'Tag Cloud', 'wpo-tweaks' ),
			'disable_widget_text'        => __( 'Text', 'wpo-tweaks' ),
			'disable_widget_video'       => __( 'Video', 'wpo-tweaks' ),
		);
		foreach ( $classic_fields as $key => $label ) {
			$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $desc );
		}

		echo '</div>'; // End cards grid.
	}

	/**
	 * Render the Emails tab content.
	 *
	 * Toggles to silence the automatic emails WordPress sends on its own,
	 * grouped by area. Every option is OFF by default.
	 */
	private function render_tab_emails() {
		$descriptions = Core_Diet_Settings::get_descriptions();

		echo '<p class="core-diet-tab-description">';
		echo esc_html__( 'Silence the automatic emails WordPress sends on its own. Every option is off by default: muting an email is always your choice. Disable only what you are sure you do not need.', 'wpo-tweaks' );
		echo '</p>';

		echo '<div class="core-diet-cards-grid">';

		// Section: Updates.
		$this->render_section_title( __( 'Updates', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'disable_auto_core_update_email', __( 'Core auto-update result email', 'wpo-tweaks' ), $descriptions['disable_auto_core_update_email'] );
		$this->render_toggle_card( 'disable_core_update_available_email', __( 'New core version available email', 'wpo-tweaks' ), $descriptions['disable_core_update_available_email'] );
		$this->render_toggle_card( 'disable_auto_plugin_update_email', __( 'Plugin auto-update result email', 'wpo-tweaks' ), $descriptions['disable_auto_plugin_update_email'] );
		$this->render_toggle_card( 'disable_auto_theme_update_email', __( 'Theme auto-update result email', 'wpo-tweaks' ), $descriptions['disable_auto_theme_update_email'] );

		// Section: Comments.
		$this->render_section_title( __( 'Comments', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'disable_comment_moderation_email', __( 'Comment awaiting moderation', 'wpo-tweaks' ), $descriptions['disable_comment_moderation_email'] );
		$this->render_toggle_card( 'disable_comment_author_email', __( 'New comment notice to the post author', 'wpo-tweaks' ), $descriptions['disable_comment_author_email'] );

		// Section: Users & passwords.
		$this->render_section_title( __( 'Users & passwords', 'wpo-tweaks' ) );

		// Card order is laid out so each two-column row pairs cards of similar
		// height. The two longest cards (the welcome email with its password-link
		// warning and the admin email change notice) share the top row; the
		// "to the admin" notices and the "to the user" notices follow as even rows.
		$this->render_toggle_card( 'disable_new_user_email', __( 'New user welcome email (carries the password link)', 'wpo-tweaks' ), $descriptions['disable_new_user_email'] );
		$this->render_toggle_card( 'disable_admin_email_change_email', __( 'Site admin email change notice', 'wpo-tweaks' ), $descriptions['disable_admin_email_change_email'] );
		$this->render_toggle_card( 'disable_new_user_admin_email', __( 'New user notice to the admin', 'wpo-tweaks' ), $descriptions['disable_new_user_admin_email'] );
		$this->render_toggle_card( 'disable_password_reset_admin_email', __( 'Password reset notice to the admin', 'wpo-tweaks' ), $descriptions['disable_password_reset_admin_email'] );
		$this->render_toggle_card( 'disable_password_change_email', __( 'Password changed notice to the user', 'wpo-tweaks' ), $descriptions['disable_password_change_email'] );
		$this->render_toggle_card( 'disable_email_change_email', __( 'Email changed notice to the user', 'wpo-tweaks' ), $descriptions['disable_email_change_email'] );

		// Section: System (email-related features, not notifications).
		$this->render_section_title( __( 'System', 'wpo-tweaks' ) );

		$this->render_toggle_card( 'disable_email_check', __( 'Disable admin email verification prompt', 'wpo-tweaks' ), $descriptions['disable_email_check'] );
		$this->render_toggle_card( 'disable_post_by_email', __( 'Disable post by email (wp-mail.php)', 'wpo-tweaks' ), $descriptions['disable_post_by_email'] );

		echo '</div>'; // End cards grid.
	}

	/* ============================
	 * Rendering helpers
	 * ============================ */

	/**
	 * Render a group of boolean toggle fields as a cards grid.
	 *
	 * @param array $fields Associative array of key => label.
	 */
	private function render_toggle_fields( $fields ) {
		$settings     = Core_Diet_Settings::get_instance();
		$descriptions = Core_Diet_Settings::get_descriptions();

		echo '<div class="core-diet-cards-grid">';

		foreach ( $fields as $key => $label ) {
			$description = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
			$this->render_toggle_card( $key, $label, $description );
		}

		echo '</div>';
	}

	/**
	 * Render a single toggle option card.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Optional description.
	 */
	private function render_toggle_card( $key, $label, $description = '' ) {
		$settings = Core_Diet_Settings::get_instance();
		$checked  = $settings->is_enabled( $key );
		$field_id = 'core_diet_' . $key;
		$name     = Core_Diet_Settings::OPTION_NAME . '[' . $key . ']';
		?>
		<div class="core-diet-option-card">
			<div class="core-diet-option-header">
				<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
				<label class="core-diet-toggle">
					<input type="checkbox"
					       id="<?php echo esc_attr( $field_id ); ?>"
					       name="<?php echo esc_attr( $name ); ?>"
					       value="1"
					       <?php checked( $checked ); ?>>
					<span class="core-diet-toggle-slider"></span>
				</label>
			</div>
			<?php if ( $description ) : ?>
				<p class="core-diet-option-desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a select option card.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param array  $options     Associative array of value => label.
	 * @param string $description Optional description.
	 */
	private function render_select_card( $key, $label, $options, $description = '' ) {
		$settings = Core_Diet_Settings::get_instance();
		$current  = $settings->get( $key );
		$field_id = 'core_diet_' . $key;
		$name     = Core_Diet_Settings::OPTION_NAME . '[' . $key . ']';
		?>
		<div class="core-diet-option-card">
			<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<select id="<?php echo esc_attr( $field_id ); ?>"
			        name="<?php echo esc_attr( $name ); ?>"
			        class="core-diet-select-toggle">
				<?php foreach ( $options as $value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
						<?php echo esc_html( $option_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $description ) : ?>
				<p class="core-diet-option-desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a section title, optionally with a toggle-all button.
	 *
	 * @param string $title       Section title.
	 * @param bool   $with_toggle Whether to show toggle-all (default true).
	 */
	private function render_section_title( $title, $with_toggle = true ) {
		if ( $with_toggle ) {
			echo '<div class="core-diet-section-header">';
			echo '<h2 class="core-diet-section-title">' . esc_html( $title ) . '</h2>';
			echo '<button type="button" class="core-diet-toggle-all">';
			echo esc_html__( 'Toggle all', 'wpo-tweaks' );
			echo '</button>';
			echo '</div>';
		} else {
			echo '<h2 class="core-diet-section-title">' . esc_html( $title ) . '</h2>';
		}
	}

	/**
	 * Render third-party dashboard widgets.
	 *
	 * Detects widgets registered by common plugins and shows them as option cards.
	 * Must be called inside an open .core-diet-cards-grid container.
	 */
	private function render_third_party_widgets() {
		$known_widgets = $this->get_known_third_party_widgets();

		if ( empty( $known_widgets ) ) {
			return;
		}

		$settings         = Core_Diet_Settings::get_instance();
		$disabled_widgets = $settings->get( 'disable_dashboard_third_party' );

		if ( ! is_array( $disabled_widgets ) ) {
			$disabled_widgets = array();
		}

		echo '<p class="core-diet-subsection-label">' . esc_html__( 'Third-party dashboard widgets', 'wpo-tweaks' ) . '</p>';

		foreach ( $known_widgets as $widget_id => $widget_label ) {
			$field_id = 'core_diet_tp_' . sanitize_key( $widget_id );
			$checked  = in_array( $widget_id, $disabled_widgets, true );
			?>
			<div class="core-diet-option-card">
				<div class="core-diet-option-header">
					<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
						<?php echo esc_html( $widget_label ); ?>
					</label>
					<label class="core-diet-toggle">
						<input type="checkbox"
						       id="<?php echo esc_attr( $field_id ); ?>"
						       name="<?php echo esc_attr( Core_Diet_Settings::OPTION_NAME ); ?>[disable_dashboard_third_party][]"
						       value="<?php echo esc_attr( $widget_id ); ?>"
						       <?php checked( $checked ); ?>>
						<span class="core-diet-toggle-slider"></span>
					</label>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Detect known third-party dashboard widgets.
	 *
	 * Checks if common plugins are active and returns their widget IDs.
	 *
	 * @return array Associative array of widget_id => label.
	 */
	private function get_known_third_party_widgets() {
		$widgets = array();

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$widgets['wpseo-dashboard-overview'] = __( 'Yoast SEO overview', 'wpo-tweaks' );
		}

		// WooCommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			$widgets['woocommerce_dashboard_status']       = __( 'WooCommerce Status', 'wpo-tweaks' );
			$widgets['woocommerce_dashboard_recent_reviews'] = __( 'WooCommerce Recent Reviews', 'wpo-tweaks' );
		}

		// Elementor.
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$widgets['e-dashboard-overview'] = __( 'Elementor overview', 'wpo-tweaks' );
		}

		// Jetpack.
		if ( defined( 'JETPACK__VERSION' ) ) {
			$widgets['jetpack_summary_widget'] = __( 'Jetpack Site Stats', 'wpo-tweaks' );
		}

		// Wordfence.
		if ( defined( 'WORDFENCE_VERSION' ) ) {
			$widgets['wordfence_activity_report_widget'] = __( 'Wordfence activity', 'wpo-tweaks' );
		}

		// Rank Math.
		if ( class_exists( 'RankMath' ) ) {
			$widgets['rank_math_dashboard_widget'] = __( 'Rank Math overview', 'wpo-tweaks' );
		}

		// Gravity Forms.
		if ( class_exists( 'GFForms' ) ) {
			$widgets['rg_forms_dashboard'] = __( 'Gravity Forms', 'wpo-tweaks' );
		}

		/**
		 * Filter to register additional third-party dashboard widgets.
		 *
		 * @param array $widgets Associative array of widget_id => label.
		 */
		return apply_filters( 'core_diet_third_party_widgets', $widgets );
	}

	/**
	 * Get the current active tab.
	 *
	 * Reads from URL parameter (used on initial load and after save redirect).
	 *
	 * @return string
	 */
	private function get_current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab display only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'scale';
		return array_key_exists( $tab, $this->get_tabs() ) ? $tab : 'scale';
	}
}