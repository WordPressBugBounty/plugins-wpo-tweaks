<?php
/**
 * Main DietPress plugin class.
 *
 * Loads all dependencies and hooks feature modules based on saved settings.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet {

	/** @var Core_Diet|null Singleton instance. */
	private static $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Core_Diet
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->maybe_upgrade();
		$this->init_features();
		$this->init_admin();
	}

	/**
	 * Load the files every request needs.
	 *
	 * The settings page classes are not among them: they are only reachable from
	 * the admin, so init_admin() requires them behind its is_admin() guard and a
	 * frontend request never parses them.
	 */
	private function load_dependencies() {
		require_once CORE_DIET_DIR . 'includes/class-core-diet-settings.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-light.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-moderate.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-strict.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-widgets.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-emails.php';

		// Performance modules (ported from the former wpo-tweaks codebase).
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-scripts.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-images.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-hints.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-criticalcss.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-selective.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-fonts.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-database.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-htaccess.php';

		// Keep the ayudawp_wpotweaks_* filters from 2.x working (bridge).
		require_once CORE_DIET_DIR . 'includes/legacy-filters.php';
	}

	/**
	 * Run upgrade routines if needed.
	 *
	 * Gated on the stored version, the same one maybe_run_upgrade_tasks() uses,
	 * because this runs while the plugin file is being included and therefore on
	 * every request, anonymous frontend hits included. There it used to rebuild
	 * the whole defaults map and walk it key by key to persist what was missing,
	 * and could even fire an update_option() during a visitor's page load.
	 *
	 * Skipping it costs nothing as long as every reader answers a missing key
	 * with its built-in default, which is what this routine used to guarantee by
	 * brute force. get() always did; is_enabled() did not, and had to be fixed
	 * alongside this guard, so mind that pair before adding a third reader.
	 */
	private function maybe_upgrade() {
		if ( CORE_DIET_VERSION === get_option( 'core_diet_version' ) ) {
			return;
		}

		$settings = get_option( 'core_diet_settings', array() );
		$changed  = false;

		// Migrate legacy key disable_rss_feeds → disable_feed_all.
		if ( isset( $settings['disable_rss_feeds'] ) ) {
			$settings['disable_feed_all'] = $settings['disable_rss_feeds'];
			unset( $settings['disable_rss_feeds'] );
			$changed = true;
		}

		/*
		 * 3.5.0 split the single .htaccess master in two, one per tab. The new
		 * one has to inherit what the old one said on this site, not its own
		 * default: a site that had the whole block switched off would otherwise
		 * find browser caching turned on by an update it never asked for. This
		 * has to happen before the defaults are merged below, which is what
		 * would introduce the key with its default value.
		 */
		if ( isset( $settings['htaccess_rules'] ) && ! array_key_exists( 'htaccess_browser_cache', $settings ) ) {
			$settings['htaccess_browser_cache'] = $settings['htaccess_rules'];
			$changed                            = true;
		}

		// Merge any new defaults for keys that don't exist yet.
		$defaults = Core_Diet_Settings::get_defaults();
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'core_diet_settings', $settings );
		}
	}

	/**
	 * Initialize feature modules.
	 *
	 * Each module reads settings and applies hooks only for enabled features.
	 */
	private function init_features() {
		$settings = Core_Diet_Settings::get_instance();

		$light    = new Core_Diet_Light( $settings );
		$moderate = new Core_Diet_Moderate( $settings );
		$strict   = new Core_Diet_Strict( $settings );
		$widgets  = new Core_Diet_Widgets( $settings );
		$emails   = new Core_Diet_Emails( $settings );

		$light->init();
		$moderate->init();
		$strict->init();
		$widgets->init();
		$emails->init();

		// Performance modules.
		$perf_scripts   = new Core_Diet_Perf_Scripts( $settings );
		$perf_images    = new Core_Diet_Perf_Images( $settings );
		$perf_hints     = new Core_Diet_Perf_Hints( $settings );
		$perf_critical  = new Core_Diet_Perf_Critical_Css( $settings );
		$perf_selective = new Core_Diet_Perf_Selective( $settings );
		$perf_fonts     = new Core_Diet_Perf_Fonts( $settings );
		$database       = new Core_Diet_Database( $settings );
		$htaccess       = new Core_Diet_Htaccess( $settings );

		$perf_scripts->init();
		$perf_images->init();
		$perf_hints->init();
		$perf_critical->init();
		$perf_selective->init();
		$perf_fonts->init();
		$database->init();
		$htaccess->init();
	}

	/**
	 * Initialize admin interface (only in admin context).
	 */
	private function init_admin() {
		if ( ! is_admin() ) {
			return;
		}

		// Loaded here, not in load_dependencies(), so that a frontend request
		// never parses the settings page. admin-ajax.php sets is_admin(), so the
		// Tools endpoints still find their class.
		require_once CORE_DIET_DIR . 'includes/class-core-diet-admin.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-tools.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-promo-banner.php';

		$admin = new Core_Diet_Admin();
		$tools = new Core_Diet_Tools();

		add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $admin, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . CORE_DIET_BASENAME, array( $admin, 'add_settings_link' ) );

		// Tools AJAX handlers.
		$tools->init();

		// One-time upgrade tasks (migration from wpo-tweaks, .htaccess refresh).
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_upgrade_tasks' ) );

		// One-time activation notice.
		add_action( 'admin_notices', array( __CLASS__, 'activation_notice' ) );

		// One-time notice about the removed security toggles (see Vigilant).
		add_action( 'admin_notices', array( __CLASS__, 'security_removed_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'html_maxage_notice' ) );
	}

	/**
	 * Activation callback.
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Set default options only if they don't exist.
		if ( false === get_option( 'core_diet_settings' ) ) {
			add_option( 'core_diet_settings', Core_Diet_Settings::get_defaults(), '', 'yes' );
		}

		// Write the server-level performance rules to .htaccess on activation.
		$htaccess = new Core_Diet_Htaccess( Core_Diet_Settings::get_instance() );
		$htaccess->on_settings_saved( array(), get_option( 'core_diet_settings', array() ) );

		// The page cache setting survives deactivation, so the cache comes back
		// on with the plugin. Its collector is scheduled again on the next
		// request (Core_Diet_Cache::maybe_schedule_gc()); the silence file of
		// the cache folder, which the cleanup of 3.5.0 to 3.5.5 deleted, is put
		// back here. Needed on activation because storing the version below
		// means the upgrade routine, which also restores it, will not run.
		// Only into a folder that already exists: activating from WP-CLI as
		// another system user would otherwise create the folder with an owner
		// PHP cannot write to, and the first page request does that correctly.
		if ( class_exists( 'Core_Diet_Cache' ) && Core_Diet_Cache::is_enabled() && is_dir( Core_Diet_Cache_Store::get_root() ) ) {
			Core_Diet_Cache_Store::prepare();
		}

		// Deactivating took the accelerator rules out and kept the setting. On
		// the same install, where they were tested, they come straight back.
		if ( class_exists( 'Core_Diet_Cache_Accelerator' ) && Core_Diet_Cache_Accelerator::is_verified_here() ) {
			Core_Diet_Cache_Accelerator::sync();
		}

		// Store version for future upgrades.
		update_option( 'core_diet_version', CORE_DIET_VERSION );

		// Flag to show the welcome notice once.
		set_transient( 'core_diet_activation_notice', true, 60 );
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		/*
		 * Before the capability check, on purpose. Deactivating from WP-CLI
		 * without --user has no current user, and returning here left the page
		 * cache in place; with the accelerator on, Apache would then go on
		 * serving the pages of a plugin that is off, with nothing left to expire
		 * them. Taking the rules out and emptying the cache only stops things.
		 */
		if ( class_exists( 'Core_Diet_Cache' ) ) {
			Core_Diet_Cache::deactivate();
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Clear any transients.
		delete_transient( 'core_diet_activation_notice' );
		delete_transient( 'core_diet_security_removed_notice' );
		delete_transient( 'core_diet_html_maxage_notice' );

		// Unschedule the transient-cleanup cron event.
		wp_clear_scheduled_hook( 'core_diet_clean_transients' );

		// Remove our .htaccess rules so they do not linger while inactive.
		$htaccess = new Core_Diet_Htaccess( Core_Diet_Settings::get_instance() );
		$htaccess->clean_htaccess();

		// Remove the locally hosted Google Fonts; they are rebuilt on demand
		// if the plugin is reactivated with the option on.
		$fonts = new Core_Diet_Perf_Fonts( Core_Diet_Settings::get_instance() );
		$fonts->core_diet_purge_local_fonts();
	}

	/**
	 * Run one-time upgrade tasks when the stored version changes.
	 *
	 * Runs on admin_init for users who can manage options. Handles the
	 * migration from the former wpo-tweaks codebase (legacy option cleanup,
	 * legacy wp-config cleanup) and (re)writes the .htaccess rules. This is the
	 * reliable path for plugin updates, where the activation hook does not fire.
	 */
	public static function maybe_run_upgrade_tasks() {
		if ( CORE_DIET_VERSION === get_option( 'core_diet_version' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Remove the legacy wpo-tweaks activation flag.
		delete_option( 'ayudawp_wpotweaks_show_activation_notice' );

		// Drop the security toggles retired in 3.0.0 (they live in Vigilant
		// now) and queue a one-time notice if any of them was enabled.
		self::migrate_removed_security_settings();

		// Must run before the rules are rewritten below, or the block goes to
		// disk with the value this very function is about to change.
		self::migrate_html_maxage_default();

		$htaccess = new Core_Diet_Htaccess( Core_Diet_Settings::get_instance() );

		// One-time removal of legacy on-disk artifacts: the old wp-config.php
		// trash-retention block and the obsolete backup/ directory.
		$htaccess->core_diet_cleanup_legacy_files();

		// (Re)write the current server-level performance rules.
		$htaccess->on_settings_saved( array(), get_option( 'core_diet_settings', array() ) );

		/*
		 * Anything already on disk was written under the previous version's
		 * rules, and 3.5.1 changed how a request is turned into a cache key:
		 * pages whose address is not plain ASCII used to share an entry, so on
		 * an affected site the copy stored for one address can be the page of
		 * another one. Those files cannot be repaired, only discarded, and the
		 * cache refills itself on the next visits.
		 */
		if ( class_exists( 'Core_Diet_Cache_Store' ) ) {
			Core_Diet_Cache_Store::purge_all();

			// Up to 3.5.5 the scheduled cleanup deleted the silence file of the
			// cache root, taking it for a cached page. prepare() writes the
			// hardening files that are missing or say something else.
			if ( Core_Diet_Cache::is_enabled() ) {
				Core_Diet_Cache_Store::prepare();
			}

			// The rules of the accelerator are rebuilt from the code of this
			// version, the same as the browser caching block above.
			if ( class_exists( 'Core_Diet_Cache_Accelerator' ) ) {
				Core_Diet_Cache_Accelerator::sync();
			}
		}

		// Mark this version as fully upgraded.
		update_option( 'core_diet_version', CORE_DIET_VERSION );
	}

	/**
	 * Move sites off the old "Always revalidate" default for the HTML.
	 *
	 * Until 3.7.0 the default made every page say max-age=0 and, with the
	 * Expires rules on, arrive with an Expires already in the past. A browser
	 * reads that as "ask me again", which is what it was chosen for, and every
	 * cache between the site and the visitor reads it as "do not store this",
	 * which nobody chose: on a SiteGround store it left 2 cache hits in 480.000
	 * requests, each miss paying for a full PHP render.
	 *
	 * Only the exact old default is moved, and only once, tracked by an option
	 * of its own rather than by the version so a site that later picks
	 * "Always revalidate" on purpose keeps it through the next update. A site
	 * that had chosen 5 minutes, an hour or a day is left alone: those are
	 * lifetimes a shared cache can work with.
	 */
	private static function migrate_html_maxage_default() {
		if ( get_option( 'core_diet_html_maxage_migrated' ) ) {
			return;
		}

		update_option( 'core_diet_html_maxage_migrated', CORE_DIET_VERSION, false );

		$settings = get_option( 'core_diet_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['htaccess_html_maxage'] ) ) {
			return;
		}

		if ( '0' !== (string) $settings['htaccess_html_maxage'] ) {
			return;
		}

		$settings['htaccess_html_maxage'] = Core_Diet_Htaccess::HTML_SEND_NOTHING;
		update_option( 'core_diet_settings', $settings );

		// The write above does not go through sanitize(), which is what clears
		// this everywhere else, and the caller rewrites the .htaccess right
		// after with the very instance that still holds the old value. Without
		// this the option said "send nothing" and the file on disk still said
		// zero seconds, which is the half of the bug that nobody would see.
		Core_Diet_Settings::get_instance()->refresh();

		set_transient( 'core_diet_html_maxage_notice', 1, MONTH_IN_SECONDS );
	}

	/**
	 * Explain, once, that the HTML caching rules stopped being sent.
	 */
	public static function html_maxage_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( 'core_diet_html_maxage_notice' ) ) {
			return;
		}

		/*
		 * Only on a screen where a notice is read, and this is not fussiness.
		 * The upgrade routine that queues this runs on admin_init, and the
		 * screen that fires it first is usually the one that updated the
		 * plugin: "upload a plugin" and the updater both print admin notices,
		 * in the middle of their own output and behind a progress view. The
		 * notice was printed there, the transient was spent, and the site owner
		 * never saw it. Measured on a real store on 23 sep 2026, where the
		 * setting had migrated and no notice ever appeared.
		 */
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';

		if ( ! in_array( $id, array( 'dashboard', 'dashboard-network', 'plugins', 'plugins-network' ), true )
			&& false === strpos( $id, 'dietpress' ) ) {
			return;
		}

		delete_transient( 'core_diet_html_maxage_notice' );

		$settings_url = admin_url( 'admin.php?page=dietpress&tab=cache' );
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'DietPress no longer tells browsers how long to keep your pages.', 'wpo-tweaks' ); ?></strong>
				<?php esc_html_e( 'The old default sent every page with a lifetime of zero, and that stopped any cache in front of your site, including the one your hosting runs, from storing a single page. Images, styles, scripts and fonts are unaffected and keep their lifetimes.', 'wpo-tweaks' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'See the Cache tab', 'wpo-tweaks' ); ?> &rarr;
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Remove the retired security-related setting keys from saved options.
	 *
	 * DietPress 3.0.0 dropped the security toggles that the standalone
	 * DietPress (core-diet) shipped: they overlap with the Vigilant security
	 * plugin, where those protections belong. Sites migrating from core-diet
	 * may still carry them in core_diet_settings. The keys are always pruned;
	 * if any of them was enabled, a flag is queued so admin_notices can
	 * explain once that the protection reverted to the WordPress default and
	 * point to Vigilant.
	 *
	 * DietPress 3.0.1 additionally retired the REST API access control
	 * (rest_api_mode); it is pruned and reported here the same way, but as a
	 * select it only counts as "enabled" when it was 'authenticated' or
	 * 'disable'.
	 */
	private static function migrate_removed_security_settings() {
		$removed = array(
			'disable_generator'      => __( 'Remove WordPress version meta tag', 'wpo-tweaks' ),
			'disable_feed_generator' => __( 'Remove generator tag from RSS feeds', 'wpo-tweaks' ),
			'disable_x_pingback'     => __( 'Remove X-Pingback HTTP header', 'wpo-tweaks' ),
			'disable_login_errors'   => __( 'Hide detailed login error messages', 'wpo-tweaks' ),
			'disable_pingbacks'      => __( 'Disable pingbacks and trackbacks globally', 'wpo-tweaks' ),
			'disable_xmlrpc'         => __( 'Disable XML-RPC', 'wpo-tweaks' ),
			'disable_app_passwords'  => __( 'Disable Application Passwords', 'wpo-tweaks' ),
		);

		$settings = get_option( 'core_diet_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$active  = array();
		$changed = false;

		foreach ( $removed as $key => $label ) {
			if ( array_key_exists( $key, $settings ) ) {
				if ( ! empty( $settings[ $key ] ) ) {
					$active[] = $label;
				}
				unset( $settings[ $key ] );
				$changed = true;
			}
		}

		// rest_api_mode (retired in 3.0.1) is a select, not a boolean: it only
		// restricted access when set to 'authenticated' or 'disable', never the
		// 'default' value. Handle it apart from the boolean toggles above so a
		// stored 'default' does not trigger a false "you had this enabled" line.
		if ( array_key_exists( 'rest_api_mode', $settings ) ) {
			$mode = $settings['rest_api_mode'];
			if ( 'authenticated' === $mode || 'disable' === $mode ) {
				$active[] = __( 'Restrict REST API access', 'wpo-tweaks' );
			}
			unset( $settings['rest_api_mode'] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'core_diet_settings', $settings );
		}

		if ( $active ) {
			set_transient( 'core_diet_security_removed_notice', $active, MONTH_IN_SECONDS );
		}
	}

	/**
	 * Show a one-time notice listing the removed security toggles that were
	 * enabled, pointing to the Vigilant security plugin.
	 */
	public static function security_removed_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active = get_transient( 'core_diet_security_removed_notice' );
		if ( empty( $active ) || ! is_array( $active ) ) {
			return;
		}

		delete_transient( 'core_diet_security_removed_notice' );

		$install_url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=vigilante' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'DietPress 3.0 focuses on performance and no longer includes these security options you had enabled:', 'wpo-tweaks' ); ?></strong>
				<?php echo esc_html( implode( ', ', $active ) ); ?>.
				<?php esc_html_e( 'Those features follow the default WordPress behavior again. For login, XML-RPC and version-hiding protection (and much more) we recommend our free security plugin, Vigilant.', 'wpo-tweaks' ); ?>
				<a href="<?php echo esc_url( $install_url ); ?>">
					<?php esc_html_e( 'Install Vigilant', 'wpo-tweaks' ); ?> &rarr;
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Show a one-time welcome notice after activation.
	 */
	public static function activation_notice() {
		if ( ! get_transient( 'core_diet_activation_notice' ) ) {
			return;
		}

		delete_transient( 'core_diet_activation_notice' );

		$settings_url = admin_url( 'admin.php?page=dietpress' );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong><?php esc_html_e( 'DietPress is ready!', 'wpo-tweaks' ); ?></strong>
				<?php esc_html_e( 'Performance optimizations are already active by default. Head to the settings to fine-tune them and trim the rest of the fat from WordPress.', 'wpo-tweaks' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Start now', 'wpo-tweaks' ); ?> &rarr;
				</a>
			</p>
		</div>
		<?php
	}
}