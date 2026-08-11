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
				'cache'    => __( 'Cache', 'wpo-tweaks' ),
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
				'tabCache'          => __( 'Cache', 'wpo-tweaks' ),
				'recTip'            => __( 'Adjust this in the Strict tab.', 'wpo-tweaks' ),
				'quickSelect'       => __( 'Quick select:', 'wpo-tweaks' ),
				'working'           => __( 'Working...', 'wpo-tweaks' ),
				'confirmPurgeAll'   => __( 'This will delete every cached page. They are rebuilt as visitors arrive. Continue?', 'wpo-tweaks' ),
				'searchNoResults'   => __( 'Nothing found. You can paste a URL instead.', 'wpo-tweaks' ),
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

		// The cache tab is inside the main form like any other settings tab: it
		// carries the .htaccess options, which live in the shared settings, and
		// its own option is registered in the same settings group so one save
		// button stores both. Its purge and test controls run over AJAX, so
		// they need no form of their own.
		$settings_tabs = array( 'light', 'moderate', 'strict', 'cache', 'widgets', 'emails' );
		$ajax_tabs     = array( 'scale', 'tools' );
		?>
		<div class="wrap core-diet-wrap">
			<h1>
				<span class="dashicons dashicons-food core-diet-title-icon"></span>
				<?php echo esc_html( get_admin_page_title() ); ?>
			</h1>

			<?php
			/*
			 * With no argument, so every tab gets feedback when it saves.
			 * options.php files its own "Settings saved." under the general
			 * slug and stashes the whole set in a transient, so asking for one
			 * option's slug (which is what this used to do) never matched it
			 * and no tab ever confirmed anything.
			 */
			settings_errors();
			$this->render_restored_notice();
			?>

			<div class="core-diet-layout">
				<div class="core-diet-main">

					<nav class="nav-tab-wrapper core-diet-tabs">
						<?php
						foreach ( $this->get_tabs() as $tab_id => $tab_label ) :
							$tab_class = 'nav-tab core-diet-nav-tab';
							if ( $current_tab === $tab_id ) {
								$tab_class .= ' nav-tab-active';
							}
							?>
							<a href="#<?php echo esc_attr( $tab_id ); ?>"
							   class="<?php echo esc_attr( $tab_class ); ?>"
							   data-tab="<?php echo esc_attr( $tab_id ); ?>">
								<?php
								echo esc_html( $tab_label );

								/*
								 * A red dot when the page cache is off, and
								 * nothing at all when it is on. A green dot
								 * would be noise, and dimming the whole tab was
								 * misleading: plenty of options on it are on by
								 * default whatever the engine is doing.
								 */
								if ( 'cache' === $tab_id && class_exists( 'Core_Diet_Cache' ) && ! Core_Diet_Cache::is_enabled() ) {
									printf(
										'<span class="core-diet-tab-dot core-diet-tab-dot-off" title="%s" aria-hidden="true"></span><span class="screen-reader-text">%s</span>',
										esc_attr__( 'Page cache off', 'wpo-tweaks' ),
										esc_html__( 'Page cache off', 'wpo-tweaks' )
									);
								}
								?>
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
									case 'cache':
										if ( class_exists( 'Core_Diet_Cache_Admin' ) ) {
											$cache_admin = new Core_Diet_Cache_Admin( Core_Diet_Cache_Settings::get_instance() );
											$cache_admin->render_tab( $this );
										}
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
	 * Confirm a per-tab restore, once.
	 *
	 * Restoring defaults used to say nothing at all: the page reloaded, in the
	 * same scroll position, with no way to tell whether anything happened.
	 */
	private function render_restored_notice() {
		$key = 'core_diet_restored_notice_' . get_current_user_id();
		$tab = get_transient( $key );

		if ( ! $tab ) {
			return;
		}

		delete_transient( $key );

		$tabs  = $this->get_tabs();
		$label = isset( $tabs[ $tab ] ) ? $tabs[ $tab ] : $tab;

		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: %s: tab name, for example "Light". */
			esc_html__( 'The %s tab is back to its default settings.', 'wpo-tweaks' ),
			'<strong>' . esc_html( $label ) . '</strong>'
		);
		echo '</p></div>';
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
		$this->render_card_group( $header_fields );

		// Section: Legacy features.
		$this->render_section_title( __( 'Legacy features', 'wpo-tweaks' ) );

		$security_fields = array(
			'disable_self_pingbacks' => __( 'Disable self-pingbacks', 'wpo-tweaks' ),
		);
		$this->render_card_group( $security_fields );

		// Section: Admin & media.
		$this->render_section_title( __( 'Admin & media', 'wpo-tweaks' ) );

		$admin_fields = array(
			'disable_capital_p'          => __( 'Disable Capital P Dangit filter', 'wpo-tweaks' ),
			'disable_update_notices'     => __( 'Hide update notices for non-administrators', 'wpo-tweaks' ),
			'disable_comment_pagination' => __( 'Disable comment pagination', 'wpo-tweaks' ),
			'disable_wp_logo_admin_bar'  => __( 'Remove WordPress logo from admin bar', 'wpo-tweaks' ),
			'disable_image_editor'       => __( 'Disable media library image editor', 'wpo-tweaks' ),
		);
		$this->render_card_group( $admin_fields );


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
		$this->render_card_group( $perf_fields );

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
		$this->render_card_group( $protocol_fields );

		// Section: Frontend performance.
		$this->render_section_title( __( 'Frontend performance', 'wpo-tweaks' ) );

		$performance_fields = array(
			'disable_jquery_migrate' => __( 'Remove jQuery Migrate', 'wpo-tweaks' ),
			'disable_dashicons'      => __( 'Remove Dashicons from frontend (non-logged-in users)', 'wpo-tweaks' ),
			'disable_adjacent_posts' => __( 'Remove adjacent post links (rel prev/next)', 'wpo-tweaks' ),
			'disable_global_styles'  => __( 'Remove Global Styles inline CSS', 'wpo-tweaks' ),
			'disable_duotone'        => __( 'Remove Duotone SVG inline filters', 'wpo-tweaks' ),
		);
		$this->render_card_group( $performance_fields );

		// Section: Editor & content.
		$this->render_section_title( __( 'Editor & content', 'wpo-tweaks' ) );

		$editor_fields = array(
			'disable_block_directory'   => __( 'Disable remote block directory in editor', 'wpo-tweaks' ),
			'disable_remote_patterns'   => __( 'Disable remote block patterns from repository', 'wpo-tweaks' ),
			'disable_avatars'           => __( 'Disable avatars and Gravatar', 'wpo-tweaks' ),
			'disable_comment_threading' => __( 'Disable comment threading (nested replies)', 'wpo-tweaks' ),
		);
		$this->render_card_group( $editor_fields );


		// Section: Images & database (ported from wpo-tweaks, on by default).
		$this->render_section_title( __( 'Images & database', 'wpo-tweaks' ) );

		$img_db_fields = array(
			'enhance_images'           => __( 'Enhance image loading attributes', 'wpo-tweaks' ),
			'add_image_dimensions'     => __( 'Add missing image dimensions (CLS)', 'wpo-tweaks' ),
			'optimize_comment_queries' => __( 'Optimize comment queries', 'wpo-tweaks' ),
			'optimize_main_queries'    => __( 'Optimize main queries', 'wpo-tweaks' ),
		);
		$this->render_card_group( $img_db_fields );

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

		// The nuclear option is pinned first: it governs the rest of the group.
		$feed_fields = array(
			'disable_feed_all'        => __( 'Disable all feeds (nuclear option)', 'wpo-tweaks' ),
			'disable_feed_comments'   => __( 'Disable comment feeds', 'wpo-tweaks' ),
			'disable_feed_taxonomies' => __( 'Disable category, tag, and taxonomy feeds', 'wpo-tweaks' ),
			'disable_feed_authors'    => __( 'Disable author feeds', 'wpo-tweaks' ),
			'disable_feed_search'     => __( 'Disable search result feeds', 'wpo-tweaks' ),
			'disable_feed_links_head' => __( 'Remove feed discovery links from head', 'wpo-tweaks' ),
		);
		$this->render_card_group( $feed_fields, array( 'disable_feed_all' ) );

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

		$advanced_fields = array(
			'disable_comments'            => __( 'Disable comments', 'wpo-tweaks' ),
			'enable_classic_widgets'      => __( 'Restore classic widget editor', 'wpo-tweaks' ),
			'disable_sitemap'             => __( 'Disable WordPress XML sitemap', 'wpo-tweaks' ),
			'disable_lazy_loading'        => __( 'Disable native lazy loading', 'wpo-tweaks' ),
			'disable_fetchpriority'       => __( 'Disable fetchpriority attribute', 'wpo-tweaks' ),
			'disable_version_params'      => __( 'Remove version parameter from assets', 'wpo-tweaks' ),
			'disable_privacy_tools'       => __( 'Remove privacy tools from admin', 'wpo-tweaks' ),
			'disable_internal_search'     => __( 'Disable internal site search', 'wpo-tweaks' ),
			'disable_posts_content_type'  => __( 'Disable Posts content type', 'wpo-tweaks' ),
			'disable_pages_content_type'  => __( 'Disable Pages content type', 'wpo-tweaks' ),
		);
		$this->render_card_group( $advanced_fields );


		// --- Frontend performance (ported from wpo-tweaks, on by default) ---
		$this->render_section_title( __( 'Frontend performance', 'wpo-tweaks' ) );

		$frontend_perf_fields = array(
			'defer_js'             => __( 'Defer JavaScript parsing', 'wpo-tweaks' ),
			'critical_css_inline'  => __( 'Inline critical CSS in head', 'wpo-tweaks' ),
			'critical_css_defer'   => __( 'Defer non-critical stylesheets (experimental)', 'wpo-tweaks' ),
		);
		$this->render_card_group( $frontend_perf_fields );

		// --- Selective third-party loading ---
		$this->render_section_title( __( 'Selective loading', 'wpo-tweaks' ) );

		$selective_fields = array(
			'selective_woocommerce'    => __( 'Load WooCommerce assets only on store pages', 'wpo-tweaks' ),
			'selective_cf7'            => __( 'Load Contact Form 7 assets only on pages with forms', 'wpo-tweaks' ),
			'selective_blocks'         => __( 'Load block styles only on pages that use blocks', 'wpo-tweaks' ),
			'selective_revslider'      => __( 'Load Slider Revolution libraries only on pages with a slider', 'wpo-tweaks' ),
			'selective_tablepress'     => __( 'Load TablePress styles only on pages with a table', 'wpo-tweaks' ),
			'selective_smash_balloon'  => __( 'Load Smash Balloon styles only on pages with a feed', 'wpo-tweaks' ),
			'selective_formidable'     => __( 'Load Formidable Forms styles only on pages with a form', 'wpo-tweaks' ),
			'selective_everest_forms'  => __( 'Load Everest Forms styles only on pages with a form', 'wpo-tweaks' ),
		);
		$this->render_card_group( $selective_fields );

		// --- Compression and connections (.htaccess) ---
		//
		// What is written to .htaccess but is not caching stays here, where the
		// whole block used to live, with a master of its own. The caching half
		// moved to the Cache tab with its own master, so either group can be on
		// without the other.
		$this->render_section_title( __( 'Compression and connections (.htaccess)', 'wpo-tweaks' ) );

		$htaccess_fields = array(
			'htaccess_rules'      => __( 'Write compression rules to .htaccess', 'wpo-tweaks' ),
			'htaccess_gzip'       => __( 'GZIP compression (mod_deflate)', 'wpo-tweaks' ),
			'htaccess_brotli'     => __( 'Brotli compression (mod_brotli)', 'wpo-tweaks' ),
			'htaccess_cors_fonts' => __( 'Cross-origin font loading (CORS)', 'wpo-tweaks' ),
			'htaccess_keepalive'  => __( 'Keep-alive connections', 'wpo-tweaks' ),
		);
		$this->render_card_group( $htaccess_fields, array( 'htaccess_rules' ) );

		echo '</div>'; // End cards grid.
	}

	/**
	 * The browser caching rules, rendered by the Cache tab.
	 *
	 * Only what caches: the Expires rules with a lifetime per family of files,
	 * and the Cache-Control headers. Compression and keep-alive stayed in
	 * Strict, because they are written to the same file but are not caching.
	 */
	public function render_browser_cache_group() {
		echo '<div class="core-diet-cards-grid">';

		// The master first, pinned, so the two options it governs never appear
		// above the switch that explains why they are inactive.
		$this->render_card_group(
			array(
				'htaccess_browser_cache' => __( 'Write browser cache rules to .htaccess', 'wpo-tweaks' ),
				'htaccess_expires'       => __( 'Browser caching (mod_expires)', 'wpo-tweaks' ),
				'htaccess_cache_headers' => __( 'Cache-Control and Vary headers (mod_headers)', 'wpo-tweaks' ),
			),
			array( 'htaccess_browser_cache' )
		);

		// How long each family of files is kept. Three groups rather than one
		// figure, because the right answer is different for each: media is
		// replaced by hand and cannot be cache-busted, while styles, scripts
		// and fonts carry a version in their URL and can be kept for a year.
		$choices  = Core_Diet_Settings::get_expires_choices();
		$defaults = Core_Diet_Settings::get_defaults();

		$this->render_select_card(
			'htaccess_expires_media',
			__( 'Keep images, video and PDFs for', 'wpo-tweaks' ),
			$this->label_default( $choices, $defaults['htaccess_expires_media'] ),
			__( 'Replacing one of these keeps the same URL, so a visitor who already has it will not see the new version until this runs out. A month is a safe middle ground.', 'wpo-tweaks' )
		);

		$this->render_select_card(
			'htaccess_expires_assets',
			__( 'Keep styles and scripts for', 'wpo-tweaks' ),
			$this->label_default( $choices, $defaults['htaccess_expires_assets'] ),
			__( 'WordPress adds a version to these URLs and changes it on every update, so a long lifetime costs nothing and saves a request on every visit.', 'wpo-tweaks' )
		);

		$this->render_select_card(
			'htaccess_expires_fonts',
			__( 'Keep fonts for', 'wpo-tweaks' ),
			$this->label_default( $choices, $defaults['htaccess_expires_fonts'] ),
			__( 'Fonts almost never change. A year is the usual recommendation.', 'wpo-tweaks' )
		);


		$this->render_select_card(
			'htaccess_html_maxage',
			__( 'Let browsers keep the HTML for', 'wpo-tweaks' ),
			$this->label_default( Core_Diet_Settings::get_html_maxage_choices(), $defaults['htaccess_html_maxage'] ),
			__( 'Recommended: leave it on "Always revalidate", which is not the same as sending nothing and lets an edit reach visitors at once. Raise it only for a site that almost never changes.', 'wpo-tweaks' )
		);

		$this->render_card_group(
			array(
				'htaccess_etag' => __( 'Remove ETags from static files', 'wpo-tweaks' ),
			)
		);


		echo '</div>';
	}

	/**
	 * Mark which choice is the built-in default, in its own label.
	 *
	 * @param array  $choices Value => label.
	 * @param string $default The default value.
	 * @return array
	 */
	private function label_default( $choices, $default ) {
		if ( isset( $choices[ $default ] ) ) {
			$choices[ $default ] = sprintf(
				/* translators: %s: a lifetime such as "1 year". */
				__( '%s (default)', 'wpo-tweaks' ),
				$choices[ $default ]
			);
		}

		return $choices;
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
		$this->render_card_group( $dashboard_fields );

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
		$this->render_card_group( $block_fields );

		// Section: Customizer.
		$this->render_section_title( __( 'Customizer', 'wpo-tweaks' ) );

		$customizer_fields = array(
			'disable_customizer_widgets' => __( 'Disable Widgets panel in Customizer', 'wpo-tweaks' ),
			'disable_customizer'         => __( 'Disable Customizer completely', 'wpo-tweaks' ),
		);
		$this->render_card_group( $customizer_fields );

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
		$this->render_card_group( $classic_fields );

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

		$update_email_fields = array(
			'disable_auto_core_update_email'       => __( 'Core auto-update result email', 'wpo-tweaks' ),
			'disable_core_update_available_email'  => __( 'New core version available email', 'wpo-tweaks' ),
			'disable_auto_plugin_update_email'     => __( 'Plugin auto-update result email', 'wpo-tweaks' ),
			'disable_auto_theme_update_email'      => __( 'Theme auto-update result email', 'wpo-tweaks' ),
		);
		$this->render_card_group( $update_email_fields );

		// Section: Comments.
		$this->render_section_title( __( 'Comments', 'wpo-tweaks' ) );

		$comment_email_fields = array(
			'disable_comment_moderation_email'  => __( 'Comment awaiting moderation', 'wpo-tweaks' ),
			'disable_comment_author_email'      => __( 'New comment notice to the post author', 'wpo-tweaks' ),
		);
		$this->render_card_group( $comment_email_fields );

		// Section: Users & passwords.
		$this->render_section_title( __( 'Users & passwords', 'wpo-tweaks' ) );

		$user_email_fields = array(
			'disable_new_user_email'              => __( 'New user welcome email (carries the password link)', 'wpo-tweaks' ),
			'disable_admin_email_change_email'    => __( 'Site admin email change notice', 'wpo-tweaks' ),
			'disable_new_user_admin_email'        => __( 'New user notice to the admin', 'wpo-tweaks' ),
			'disable_password_reset_admin_email'  => __( 'Password reset notice to the admin', 'wpo-tweaks' ),
			'disable_password_change_email'       => __( 'Password changed notice to the user', 'wpo-tweaks' ),
			'disable_email_change_email'          => __( 'Email changed notice to the user', 'wpo-tweaks' ),
		);
		$this->render_card_group( $user_email_fields );

		// Section: System (email-related features, not notifications).
		$this->render_section_title( __( 'System', 'wpo-tweaks' ) );

		$email_system_fields = array(
			'disable_email_check'    => __( 'Disable admin email verification prompt', 'wpo-tweaks' ),
			'disable_post_by_email'  => __( 'Disable post by email (wp-mail.php)', 'wpo-tweaks' ),
		);
		$this->render_card_group( $email_system_fields );

		echo '</div>'; // End cards grid.
	}

	/* ============================
	 * Rendering helpers
	 * ============================ */

	/**
	 * Render the toggle cards of one section, evening out the grid rows.
	 *
	 * The cards sit in a two-column grid, so a long card next to a short one
	 * leaves a ragged gap and the section ends up looking like a masonry wall.
	 * Ordering each section by how much text a card carries, longest first,
	 * puts cards of similar height side by side. The order follows the texts,
	 * so it keeps working when they are edited or translated.
	 *
	 * @param array $fields Associative array of setting key => label.
	 * @param array $pinned Keys that must stay first whatever their length,
	 *                      such as the master switch of a group.
	 */
	private function render_card_group( $fields, $pinned = array() ) {
		$descriptions = Core_Diet_Settings::get_descriptions();

		$first = array();
		foreach ( (array) $pinned as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$first[ $key ] = $fields[ $key ];
				unset( $fields[ $key ] );
			}
		}

		$weights = array();
		foreach ( $fields as $key => $label ) {
			$text            = $label . ( isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '' );
			$weights[ $key ] = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		}

		// Longest first, and alphabetical on a tie so the order never wobbles.
		uksort(
			$fields,
			static function ( $a, $b ) use ( $weights ) {
				if ( $weights[ $a ] === $weights[ $b ] ) {
					return strcmp( $a, $b );
				}
				return $weights[ $b ] - $weights[ $a ];
			}
		);

		foreach ( $first + $fields as $key => $label ) {
			$this->render_toggle_card( $key, $label, isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '' );
		}
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
		$field_id = 'core_diet_' . $key;
		$name     = Core_Diet_Settings::OPTION_NAME . '[' . $key . ']';
		$notice   = Core_Diet_Settings::get_notice( $key );
		$type     = $notice ? $notice['type'] : '';
		$locked   = 'locked' === $type;

		/*
		 * The checkbox shows what is STORED, never what currently applies.
		 *
		 * Those are different questions and the card answers both: the box is
		 * the preference, which is what a save will write back, and the note
		 * underneath is whether it can do anything right now. Ticking the box
		 * from is_enabled() looked equivalent and was not, because is_enabled()
		 * returns false for anything under a lock. A soft lock leaves the box
		 * enabled and submittable, so it rendered unticked, and the very next
		 * save wrote that false over the site's real preference. Switching the
		 * governing option back on then restored nothing, because there was
		 * nothing left to restore.
		 *
		 * A hard lock was already immune, by accident rather than design: its
		 * box is disabled, never submitted, and a hidden field carries the
		 * stored value through. Both cases read the same value now.
		 */
		$stored  = (bool) $settings->get( $key );
		$checked = $stored;

		$card_class = 'core-diet-option-card';
		if ( $locked ) {
			$card_class .= ' core-diet-option-locked';
		} elseif ( 'inactive' === $type ) {
			$card_class .= ' core-diet-option-inactive';
		}

		// Most locks depend on another toggle on this same page, so the card
		// carries what the script needs to mirror the lock without a reload.
		// The rule is enforced in PHP either way.
		$rules     = Core_Diet_Settings::get_lock_rules();
		$lock_on   = isset( $rules[ $key ] ) ? 'core_diet_' . $rules[ $key ][0] : '';
		$lock_when = isset( $rules[ $key ] ) && $rules[ $key ][1] ? '1' : '0';
		$lock_mode = isset( $rules[ $key ] ) ? $rules[ $key ][3] : '';
		$lock_text = isset( $rules[ $key ] ) ? Core_Diet_Settings::get_lock_reason( $key ) : '';

		// When the card is not locked right now the reason is still needed, so
		// the script can show it the moment the option it depends on changes.
		if ( $lock_on && '' === $lock_text ) {
			$lock_text = Core_Diet_Settings::get_lock_text_for( $key );
		}
		?>
		<div class="<?php echo esc_attr( $card_class ); ?>"
			<?php if ( $lock_on ) : ?>
				data-lock-on="<?php echo esc_attr( $lock_on ); ?>"
				data-lock-when="<?php echo esc_attr( $lock_when ); ?>"
				data-lock-mode="<?php echo esc_attr( $lock_mode ); ?>"
				data-lock-text="<?php echo esc_attr( $lock_text ); ?>"
			<?php endif; ?>>
			<div class="core-diet-option-header">
				<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
				<label class="core-diet-toggle">
					<input type="checkbox"
					       id="<?php echo esc_attr( $field_id ); ?>"
					       name="<?php echo esc_attr( $name ); ?>"
					       value="1"
					       <?php checked( $locked ? $stored : $checked ); ?>
					       <?php disabled( $locked ); ?>>
					<span class="core-diet-toggle-slider"></span>
				</label>
			</div>
			<?php if ( $locked ) : ?>
				<input type="hidden"
				       class="core-diet-locked-value"
				       name="<?php echo esc_attr( $name ); ?>"
				       value="<?php echo $stored ? '1' : ''; ?>">
			<?php endif; ?>
			<?php if ( $description ) : ?>
				<p class="core-diet-option-desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
			<?php
			// The note is only printed when there is something to say, or when
			// the card can be locked later without a reload and the script
			// needs somewhere to write.
			if ( $notice || $lock_on ) :
				$notice_class = 'core-diet-option-notice';
				if ( $type ) {
					$notice_class .= ' core-diet-option-notice-' . $type;
				}
				?>
				<p class="<?php echo esc_attr( $notice_class ); ?>" <?php echo $notice ? '' : 'hidden'; ?>>
					<span class="dashicons <?php echo esc_attr( $locked ? 'dashicons-lock' : 'dashicons-warning' ); ?>" aria-hidden="true"></span>
					<span class="core-diet-option-notice-text"><?php echo $notice ? esc_html( $notice['text'] ) : ''; ?></span>
				</p>
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

		// Selects can be governed by another option too, and used to be the only
		// control that never said so: a lifetime under a switched-off master
		// looked perfectly applicable and did nothing.
		$rules     = Core_Diet_Settings::get_lock_rules();
		$locked    = '' !== Core_Diet_Settings::get_lock_group_for_state( $key );
		$lock_on   = isset( $rules[ $key ] ) ? 'core_diet_' . $rules[ $key ][0] : '';
		$lock_when = isset( $rules[ $key ] ) && $rules[ $key ][1] ? '1' : '0';
		$lock_mode = isset( $rules[ $key ] ) ? $rules[ $key ][3] : '';
		$lock_text = $lock_on ? Core_Diet_Settings::get_lock_text_for( $key ) : '';
		?>
		<div class="core-diet-option-card<?php echo $locked ? ' core-diet-option-inactive' : ''; ?>"
			<?php if ( $lock_on ) : ?>
				data-lock-on="<?php echo esc_attr( $lock_on ); ?>"
				data-lock-when="<?php echo esc_attr( $lock_when ); ?>"
				data-lock-mode="<?php echo esc_attr( $lock_mode ); ?>"
				data-lock-text="<?php echo esc_attr( $lock_text ); ?>"
			<?php endif; ?>>
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
			<?php if ( $lock_on ) : ?>
				<p class="core-diet-option-notice core-diet-option-notice-inactive" <?php echo $locked ? '' : 'hidden'; ?>>
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span class="core-diet-option-notice-text"><?php echo $locked ? esc_html( $lock_text ) : ''; ?></span>
				</p>
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Which tab to draw. No state changes, and the value is checked against the tab list below before it is used, so a nonce here would only break bookmarks.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scale';

		// Allowlist: anything that is not one of our own tabs falls back.
		return array_key_exists( $tab, $this->get_tabs() ) ? $tab : 'scale';
	}
}