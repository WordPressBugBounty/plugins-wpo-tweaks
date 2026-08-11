<?php
/**
 * Page cache module bootstrap.
 *
 * Loaded from the main plugin file rather than from Core_Diet::init_features(),
 * because serving a cached page happens on plugins_loaded priority 1 and the
 * hook has to be registered before that.
 *
 * The module is self-contained on purpose: turning it off returns the plugin to
 * its exact behaviour without it, which is what makes "disable the cache and
 * tell me if it still happens" a useful first question in support.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache {

	/** @var string Cron hook for the garbage collector. */
	const CRON_HOOK = 'core_diet_cache_gc';

	/** @var Core_Diet_Cache|null */
	private static $instance = null;

	/** @var Core_Diet_Cache_Settings */
	private $settings;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Core_Diet_Cache
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load the module and wire whatever this request needs.
	 */
	private function __construct() {
		$dir = CORE_DIET_DIR . 'includes/cache/';

		require_once $dir . 'class-core-diet-cache-settings.php';
		require_once $dir . 'class-core-diet-cache-store.php';
		require_once $dir . 'class-core-diet-cache-compat.php';

		$this->settings = Core_Diet_Cache_Settings::get_instance();

		// The option is the source of truth for enable and disable, so the
		// listener is registered even when the module is off.
		add_action( 'update_option_' . Core_Diet_Cache_Settings::OPTION_NAME, array( $this, 'on_settings_saved' ), 10, 2 );
		add_action( 'add_option_' . Core_Diet_Cache_Settings::OPTION_NAME, array( $this, 'on_settings_added' ), 10, 2 );

		/*
		 * A cache plugin activated after this module was switched on would
		 * otherwise leave two page caches fighting over the same site.
		 *
		 * Registered outside the admin guard on purpose: activated_plugin also
		 * fires under WP-CLI and from any code that calls activate_plugin(),
		 * and those are exactly the paths a host's plugin manager or a staging
		 * script takes. With the listener behind is_admin() it never ran there,
		 * so `wp plugin activate wp-rocket` left both caches running at once.
		 * The hook only fires on an activation, so registering it always costs
		 * nothing.
		 */
		add_action( 'activated_plugin', array( __CLASS__, 'disable_on_new_conflict' ), 20 );

		if ( is_admin() ) {
			require_once $dir . 'class-core-diet-cache-admin.php';
			$admin = new Core_Diet_Cache_Admin( $this->settings );
			$admin->init();
		}

		if ( ! self::is_enabled() ) {
			return;
		}

		require_once $dir . 'class-core-diet-cache-engine.php';
		require_once $dir . 'class-core-diet-cache-purge.php';

		$engine = new Core_Diet_Cache_Engine( $this->settings );
		$purge  = new Core_Diet_Cache_Purge( $this->settings );

		$engine->init();
		$purge->init();
	}

	/**
	 * Whether the engine should run on this request.
	 *
	 * Deliberately cheap: one autoloaded option and two constant checks. The
	 * conflict detection, which touches the filesystem, runs when the settings
	 * are saved and when the settings page is rendered, not on every hit.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'DIETPRESS_DISABLE_CACHE' ) && DIETPRESS_DISABLE_CACHE ) {
			return false;
		}
		if ( is_multisite() ) {
			return false;
		}

		$settings = get_option( Core_Diet_Cache_Settings::OPTION_NAME, array() );

		return is_array( $settings ) && ! empty( $settings['enabled'] );
	}

	/**
	 * React to the module being switched on or off.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function on_settings_saved( $old, $new ) {
		$was = is_array( $old ) && ! empty( $old['enabled'] );
		$is  = is_array( $new ) && ! empty( $new['enabled'] );

		$this->settings->refresh();

		if ( $is && ! $was ) {
			self::on_enable();
			return;
		}

		if ( ! $is && $was ) {
			self::on_disable();
			return;
		}

		// Still on, but something else changed. Only the settings that decide
		// what gets written are worth a purge; wiping the cache every time the
		// form is submitted makes the module look like it never fills up, which
		// is the opposite of reassuring. The lifetime is read when a page is
		// served, so changing it needs no purge at all.
		if ( $is ) {
			foreach ( array( 'exclude_urls', 'ignore_query_params', 'precompress_gzip', 'separate_mobile' ) as $key ) {
				$before = isset( $old[ $key ] ) ? $old[ $key ] : null;
				$after  = isset( $new[ $key ] ) ? $new[ $key ] : null;

				if ( $before !== $after ) {
					Core_Diet_Cache_Store::purge_all();
					return;
				}
			}
		}
	}

	/**
	 * React to the option being created already enabled.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Stored value.
	 */
	public function on_settings_added( $option, $value ) {
		if ( is_array( $value ) && ! empty( $value['enabled'] ) ) {
			self::on_enable();
		}
	}

	/**
	 * Prepare the filesystem and schedule the garbage collector.
	 */
	public static function on_enable() {
		Core_Diet_Cache_Store::prepare();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Empty the cache and stop the garbage collector.
	 */
	public static function on_disable() {
		Core_Diet_Cache_Store::purge_all();
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin deactivation: leave nothing being served behind our back.
	 */
	public static function deactivate() {
		if ( ! class_exists( 'Core_Diet_Cache_Store', false ) ) {
			require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-store.php';
		}
		self::on_disable();
	}

	/**
	 * Switch the module off when a conflicting cache plugin is activated.
	 */
	public static function disable_on_new_conflict() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$blocking = Core_Diet_Cache_Compat::get_blocking_reasons();
		if ( ! $blocking ) {
			return;
		}

		$settings            = get_option( Core_Diet_Cache_Settings::OPTION_NAME, array() );
		$settings            = is_array( $settings ) ? $settings : array();
		$settings['enabled'] = false;

		update_option( Core_Diet_Cache_Settings::OPTION_NAME, $settings );

		set_transient( 'core_diet_cache_auto_disabled', reset( $blocking ), WEEK_IN_SECONDS );
	}
}
