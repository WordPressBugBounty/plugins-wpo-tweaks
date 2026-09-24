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

		require_once CORE_DIET_DIR . 'includes/class-core-diet-schedule.php';
		require_once $dir . 'class-core-diet-cache-settings.php';
		require_once $dir . 'class-core-diet-cache-store.php';
		require_once $dir . 'class-core-diet-cache-compat.php';
		require_once $dir . 'class-core-diet-cache-accelerator.php';

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

		/*
		 * The accelerator rules hold values that other settings decide: the
		 * lifetime browsers may keep a page (the main option) and the address of
		 * the site. Registered whether or not the cache is on, because the rules
		 * can also have to go away.
		 */
		add_action( 'update_option_core_diet_settings', array( __CLASS__, 'on_rules_input_changed' ) );
		add_action( 'update_option_home', array( __CLASS__, 'on_rules_input_changed' ) );
		add_action( 'update_option_siteurl', array( __CLASS__, 'on_rules_input_changed' ) );

		if ( is_admin() ) {
			/*
			 * The same check as on activated_plugin, because a cache does not
			 * always arrive as a plugin activation. Switching the file cache of
			 * a hosting plugin on writes advanced-cache.php and sets WP_CACHE
			 * from its own settings screen, and until 3.7.0 nothing noticed:
			 * the Cache tab said the module was blocked while the engine went
			 * on answering every front-end request, so the site had two page
			 * caches running and an X-DietPress-Cache header on a cache its
			 * owner believed was off.
			 */
			add_action( 'admin_init', array( __CLASS__, 'disable_on_new_conflict' ), 5 );
			add_action( 'admin_init', array( 'Core_Diet_Cache_Accelerator', 'maybe_sync' ), 20 );

			require_once $dir . 'class-core-diet-cache-admin.php';
			$admin = new Core_Diet_Cache_Admin( $this->settings );
			$admin->init();
		}

		if ( ! self::should_run() ) {
			return;
		}

		require_once $dir . 'class-core-diet-cache-engine.php';
		require_once $dir . 'class-core-diet-cache-purge.php';

		$engine = new Core_Diet_Cache_Engine( $this->settings );
		$purge  = new Core_Diet_Cache_Purge( $this->settings );

		$engine->init();
		$purge->init();

		/*
		 * The collector used to be scheduled only when the module was switched
		 * on, the one moment its option changes. Deactivating the plugin clears
		 * the event and keeps the module on, so a plugin deactivated and
		 * reactivated from the Plugins screen went on caching with nothing left
		 * to delete what expired, and so did a site that lost the event any
		 * other way. Checked on init rather than here: a plugin that replaces
		 * WP-Cron has registered its filters by then, and a cached hit has
		 * already been served and ended the request, so the check costs nothing
		 * on the requests the cache exists for.
		 */
		add_action( 'init', array( __CLASS__, 'maybe_schedule_gc' ) );
	}

	/**
	 * Whether the engine may run on this request.
	 *
	 * The option says yes and no other page cache is in charge. The second half
	 * costs one stat call, which is what a foreign advanced-cache.php amounts
	 * to, and it is the difference between a blocked module and a module that
	 * says it is blocked while it answers every request.
	 *
	 * @return bool
	 */
	public static function should_run() {
		return self::is_enabled() && ! Core_Diet_Cache_Compat::has_foreign_dropin();
	}

	/**
	 * Whether the module is switched on.
	 *
	 * Says what the site owner chose, which is not always what this request
	 * does: should_run() is what decides that. Everything that reports state,
	 * schedules work or renders a screen asks this one.
	 *
	 * Deliberately cheap: one autoloaded option and two constant checks. The
	 * conflict detection, which touches the filesystem, runs when the settings
	 * are saved, on admin_init and when the settings page is rendered, not on
	 * every hit.
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
		$old = is_array( $old ) ? $old : array();
		$new = is_array( $new ) ? $new : array();
		$was = ! empty( $old['enabled'] );
		$is  = ! empty( $new['enabled'] );

		$this->settings->refresh();

		if ( ! $is ) {
			if ( $was ) {
				self::on_disable();
			}
			return;
		}

		if ( ! $was ) {
			self::on_enable();
		} else {
			// Still on, but something else changed. Only the settings that
			// decide what gets written are worth a purge; wiping the cache every
			// time the form is submitted makes the module look like it never
			// fills up, which is the opposite of reassuring. The lifetime is
			// read when a page is served, so changing it needs no purge at all.
			foreach ( array( 'exclude_urls', 'ignore_query_params', 'precompress_gzip', 'separate_mobile' ) as $key ) {
				if ( self::changed( $old, $new, $key ) ) {
					Core_Diet_Cache_Store::purge_all();
					break;
				}
			}

			// With the accelerator on, the lifetime is baked into the names each
			// copy has for the hours it is valid in, so a shorter one would not
			// reach the copies the server already serves until those names ran
			// out. Only a shorter lifetime purges: a longer one, or none, keeps
			// every name valid.
			if ( ! empty( $new['accelerator'] ) && self::changed( $old, $new, 'ttl_hours' ) ) {
				$ttl_before = isset( $old['ttl_hours'] ) ? (int) $old['ttl_hours'] : 12;
				$ttl_after  = isset( $new['ttl_hours'] ) ? (int) $new['ttl_hours'] : 12;
				if ( $ttl_after > 0 && ( 0 === $ttl_before || $ttl_after < $ttl_before ) ) {
					Core_Diet_Cache_Store::purge_all();
				}
			}

			// Moved only when the schedule itself changed: rescheduling on every
			// save would push the next cleanup forward each time.
			if ( self::changed( $old, $new, 'gc_frequency' ) || self::changed( $old, $new, 'gc_hour' ) ) {
				Core_Diet_Schedule::reschedule( self::CRON_HOOK, $this->settings->get( 'gc_frequency' ), $this->settings->get( 'gc_hour' ) );
			}
		}

		$accelerator_was = $was && ! empty( $old['accelerator'] );
		$accelerator_is  = ! empty( $new['accelerator'] );

		if ( $accelerator_is && ! $accelerator_was ) {
			self::switch_accelerator_on( $new );
		} elseif ( ! $accelerator_is && $accelerator_was ) {
			Core_Diet_Cache_Accelerator::remove();
		} elseif ( $accelerator_is && ( self::changed( $old, $new, 'exclude_urls' ) || self::changed( $old, $new, 'separate_mobile' ) ) ) {
			// The rules carry the exclusions and the mobile test.
			Core_Diet_Cache_Accelerator::sync();
		}
	}

	/**
	 * Whether a key differs between two versions of the option.
	 *
	 * @param array  $old Previous value.
	 * @param array  $new New value.
	 * @param string $key Setting key.
	 * @return bool
	 */
	private static function changed( $old, $new, $key ) {
		// A key the stored option does not have yet holds its default: the
		// option of 3.5.6 has no cleanup schedule, and reading that as a change
		// moved the cleanup of every site on its first save after updating.
		$defaults = Core_Diet_Cache_Settings::get_defaults();
		$fallback = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
		$before   = array_key_exists( $key, $old ) ? $old[ $key ] : $fallback;
		$after    = array_key_exists( $key, $new ) ? $new[ $key ] : $fallback;

		return $before !== $after;
	}

	/**
	 * Switch the accelerator on, and back off when the site cannot prove it works.
	 *
	 * The setting was already saved when this runs, which is what lets the self
	 * test inside find it on. On failure it is saved again without it; that
	 * second save comes back here as "switched off" and removes whatever the
	 * attempt left.
	 *
	 * @param array $new Saved option value.
	 */
	private static function switch_accelerator_on( $new ) {
		$result = Core_Diet_Cache_Accelerator::enable();

		if ( ! $result['ok'] ) {
			$new['accelerator'] = false;
			update_option( Core_Diet_Cache_Settings::OPTION_NAME, $new );
		}

		/*
		 * Through the notice of the plugin itself, the same one the quick
		 * profiles use, and not through add_settings_error(): that one comes out
		 * in bold, because settings_errors() wraps the whole message in <strong>
		 * (wp-admin/includes/template.php, get_settings_errors() and the printing
		 * below it in WordPress 7.1), and it is only cleared on the load that
		 * carries settings-updated, so it could stay on the screen after a plain
		 * reload. This one is dismissible, reads like the rest and goes on the
		 * first load that shows it. The profiles and the analyzer read
		 * get_last_result(), and WP-CLI shows nothing. Escaped where it is
		 * printed (render_oneshot_notice()), because part of it comes from the
		 * answer the site gave to the self test.
		 */
		if ( function_exists( 'set_transient' ) && function_exists( 'get_current_user_id' ) && get_current_user_id() ) {
			set_transient(
				'core_diet_oneshot_notice_' . get_current_user_id(),
				array(
					'message' => (string) $result['message'],
					'type'    => $result['ok'] ? 'success' : 'warning',
				),
				MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Rewrite the accelerator rules when something they are built from changed.
	 */
	public static function on_rules_input_changed() {
		if ( Core_Diet_Cache_Accelerator::is_switched_on() || null !== Core_Diet_Cache_Accelerator::read_block() ) {
			Core_Diet_Cache_Accelerator::sync();
		}
	}

	/**
	 * React to the option being created already enabled.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Stored value.
	 */
	public function on_settings_added( $option, $value ) {
		if ( ! is_array( $value ) || empty( $value['enabled'] ) ) {
			return;
		}

		self::on_enable();

		// update_option() on an option that does not exist yet goes through
		// add_option() (wp-includes/option.php:928-929), so a quick profile on a
		// fresh install saved the accelerator on without writing its rules or
		// testing them. It is switched on here the same way a save does.
		if ( ! empty( $value['accelerator'] ) ) {
			$this->settings->refresh();
			self::switch_accelerator_on( $value );
		}
	}

	/**
	 * Prepare the filesystem and schedule the garbage collector.
	 */
	public static function on_enable() {
		Core_Diet_Cache_Store::prepare();
		self::maybe_schedule_gc();
	}

	/**
	 * Schedule the garbage collector unless it already is.
	 *
	 * An event that is overdue still counts as scheduled: it is WP-Cron that is
	 * late, and adding a second event would only run the cleanup twice once it
	 * catches up. Concurrent repairs on a busy site do not stack duplicates
	 * either, because every request saves the whole cron array from the copy it
	 * loaded, so the last one to save wins (_set_cron_array()).
	 */
	public static function maybe_schedule_gc() {
		$settings = Core_Diet_Cache_Settings::get_instance();

		Core_Diet_Schedule::ensure( self::CRON_HOOK, $settings->get( 'gc_frequency' ), $settings->get( 'gc_hour' ) );
	}

	/**
	 * Empty the cache, stop the garbage collector and take the rules out.
	 *
	 * The rules go first: with the accelerator on, the server serves from the
	 * cache folder, and removing the files before the rules would leave a
	 * window where it looks for copies that are being deleted.
	 */
	public static function on_disable() {
		Core_Diet_Cache_Accelerator::remove( false );
		Core_Diet_Cache_Store::purge_all();
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin deactivation: leave nothing being served behind our back.
	 *
	 * The module setting is kept, so the cache comes back on with the plugin,
	 * and maybe_schedule_gc() puts the collector back on the first request
	 * after that.
	 */
	public static function deactivate() {
		if ( ! class_exists( 'Core_Diet_Cache_Store', false ) ) {
			require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-store.php';
		}
		if ( ! class_exists( 'Core_Diet_Cache_Accelerator', false ) ) {
			require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-accelerator.php';
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

		$blocking = Core_Diet_Cache_Compat::get_conflict_reasons();
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
