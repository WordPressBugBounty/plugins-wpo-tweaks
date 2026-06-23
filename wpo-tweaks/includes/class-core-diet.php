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
	 * Load required files.
	 */
	private function load_dependencies() {
		require_once CORE_DIET_DIR . 'includes/class-core-diet-settings.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-admin.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-tools.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-light.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-moderate.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-strict.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-widgets.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-emails.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-promo-banner.php';

		// Performance modules (ported from the former wpo-tweaks codebase).
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-scripts.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-images.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-hints.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-perf-criticalcss.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-database.php';
		require_once CORE_DIET_DIR . 'includes/class-core-diet-htaccess.php';

		// Keep the ayudawp_wpotweaks_* filters from 2.x working (bridge).
		require_once CORE_DIET_DIR . 'includes/legacy-filters.php';
	}

	/**
	 * Run upgrade routines if needed.
	 */
	private function maybe_upgrade() {
		$settings = get_option( 'core_diet_settings', array() );
		$changed  = false;

		// Migrate legacy key disable_rss_feeds → disable_feed_all.
		if ( isset( $settings['disable_rss_feeds'] ) ) {
			$settings['disable_feed_all'] = $settings['disable_rss_feeds'];
			unset( $settings['disable_rss_feeds'] );
			$changed = true;
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
		$perf_scripts  = new Core_Diet_Perf_Scripts( $settings );
		$perf_images   = new Core_Diet_Perf_Images( $settings );
		$perf_hints    = new Core_Diet_Perf_Hints( $settings );
		$perf_critical = new Core_Diet_Perf_Critical_Css( $settings );
		$database      = new Core_Diet_Database( $settings );
		$htaccess      = new Core_Diet_Htaccess( $settings );

		$perf_scripts->init();
		$perf_images->init();
		$perf_hints->init();
		$perf_critical->init();
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

		// Clean up the legacy wpo-tweaks activation flag, if present.
		delete_option( 'ayudawp_wpotweaks_show_activation_notice' );

		// Write the server-level performance rules to .htaccess on activation.
		$htaccess = new Core_Diet_Htaccess( Core_Diet_Settings::get_instance() );
		$htaccess->on_settings_saved( array(), get_option( 'core_diet_settings', array() ) );

		// Store version for future upgrades.
		update_option( 'core_diet_version', CORE_DIET_VERSION );

		// Flag to show the welcome notice once.
		set_transient( 'core_diet_activation_notice', true, 60 );
	}

	/**
	 * Deactivation callback.
	 */
	public static function deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Clear any transients.
		delete_transient( 'core_diet_third_party_widgets' );
		delete_transient( 'core_diet_activation_notice' );
		delete_transient( 'core_diet_security_removed_notice' );

		// Unschedule the transient-cleanup cron event.
		wp_clear_scheduled_hook( 'core_diet_clean_transients' );

		// Remove our .htaccess rules so they do not linger while inactive.
		$htaccess = new Core_Diet_Htaccess( Core_Diet_Settings::get_instance() );
		$htaccess->clean_htaccess();
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

		$htaccess = new Core_Diet_Htaccess( Core_Diet_Settings::get_instance() );

		// One-time removal of legacy on-disk artifacts: the old wp-config.php
		// trash-retention block and the obsolete backup/ directory.
		$htaccess->core_diet_cleanup_legacy_files();

		// (Re)write the current server-level performance rules.
		$htaccess->on_settings_saved( array(), get_option( 'core_diet_settings', array() ) );

		// Mark this version as fully upgraded.
		update_option( 'core_diet_version', CORE_DIET_VERSION );
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