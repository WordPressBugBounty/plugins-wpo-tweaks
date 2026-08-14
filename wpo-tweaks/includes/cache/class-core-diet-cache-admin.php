<?php
/**
 * Cache tab.
 *
 * The status block at the top is not decoration. "It is not caching and I do
 * not know why" is the most expensive question a cache plugin can generate,
 * because the answer is almost always invisible from the outside: a cookie, a
 * conflicting plugin, a directory nobody can write to, or simply that the
 * person looking is logged in and therefore never gets a cached page.
 * Answering it on screen is cheaper than answering it one forum thread at a
 * time.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Admin {

	/** @var string Slug of the settings page this tab lives in. */
	const PAGE_SLUG = 'dietpress';

	/** @var string Tab id within that page. */
	const TAB_ID = 'cache';

	/** @var Core_Diet_Cache_Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Core_Diet_Cache_Settings $settings Module settings.
	 */
	public function __construct( Core_Diet_Cache_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the admin hooks.
	 *
	 * There is no menu entry of its own: the module is a tab of the DietPress
	 * settings page, placed after the diet levels and before the widgets, which
	 * is where it belongs by importance.
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'auto_disabled_notice' ) );

		// Purging and testing run over AJAX like the rest of the plugin's
		// actions. Doing it with a redirect left the result in the URL, so it
		// came back on every reload of the page.
		add_action( 'wp_ajax_core_diet_cache_purge', array( $this, 'ajax_purge' ) );
		add_action( 'wp_ajax_core_diet_cache_test', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_core_diet_cache_search', array( $this, 'ajax_search' ) );
	}

	/**
	 * AJAX: find content by title, to fill the purge field with its URL.
	 *
	 * Typing a title beats pasting a URL: the address that has to be purged is
	 * the one WordPress generated, and copying it by hand is where the trailing
	 * slash and the wrong domain creep in.
	 */
	public function ajax_search() {
		check_ajax_referer( 'core_diet_tools_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wpo-tweaks' ), 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		if ( mb_strlen( $term ) < 3 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		unset( $types['attachment'] );

		$query = new WP_Query(
			array(
				'post_type'              => array_values( $types ),
				'post_status'            => 'publish',
				'posts_per_page'         => 10,
				's'                      => $term,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$results = array();

		foreach ( $query->posts as $post ) {
			$link = get_permalink( $post );
			if ( ! $link ) {
				continue;
			}

			$type      = get_post_type_object( $post->post_type );
			$results[] = array(
				'label' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'type'  => $type ? $type->labels->singular_name : $post->post_type,
				'url'   => $link,
			);
		}

		// The front page is not a search result but is the page most likely to
		// need purging by hand.
		if ( false !== stripos( __( 'Home', 'wpo-tweaks' ), $term ) || false !== stripos( (string) get_bloginfo( 'name' ), $term ) ) {
			array_unshift(
				$results,
				array(
					'label' => __( 'Home page', 'wpo-tweaks' ),
					'type'  => __( 'Front page', 'wpo-tweaks' ),
					'url'   => home_url( '/' ),
				)
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * Registered in the plugin's own settings group, not one of its own, so the
	 * single save button of the settings page stores this option along with the
	 * shared one. A settings group can hold several options; options.php walks
	 * every option registered to the submitted group.
	 */
	public function register_settings() {
		register_setting(
			Core_Diet_Settings::OPTION_GROUP,
			Core_Diet_Cache_Settings::OPTION_NAME,
			array(
				'sanitize_callback' => array( 'Core_Diet_Cache_Settings', 'sanitize' ),
				'default'           => Core_Diet_Cache_Settings::get_defaults(),
			)
		);
	}

	/**
	 * Warn once when the module switched itself off.
	 */
	public function auto_disabled_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reason = get_transient( 'core_diet_cache_auto_disabled' );
		if ( ! $reason ) {
			return;
		}

		delete_transient( 'core_diet_cache_auto_disabled' );

		echo '<div class="notice notice-warning is-dismissible"><p><strong>';
		esc_html_e( 'DietPress turned its page cache off:', 'wpo-tweaks' );
		echo '</strong> ' . esc_html( $reason ) . '</p></div>';
	}

	/* ============================
	 * AJAX endpoints
	 * ============================ */

	/**
	 * AJAX: purge the whole cache, or one URL.
	 */
	public function ajax_purge() {
		check_ajax_referer( 'core_diet_tools_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wpo-tweaks' ), 403 );
		}

		$url = isset( $_POST['cache_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cache_url'] ) ) ) : '';

		if ( '' === $url ) {
			$deleted = Core_Diet_Cache_Store::purge_all();

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: %s: number of files. */
					esc_html( _n( 'Cache emptied: %s file deleted.', 'Cache emptied: %s files deleted.', $deleted, 'wpo-tweaks' ) ),
					number_format_i18n( $deleted )
				),
				'stats'   => $this->get_stats_payload( true ),
			) );
		}

		// The path is rederived from the site's own home URL, so nothing that
		// arrived in the request ever reaches the filesystem: a relative path
		// is resolved against home_url() and an absolute one is refused unless
		// its host is this site's.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( '/' . ltrim( $url, '/' ) );
		}

		$dir     = Core_Diet_Cache_Store::dir_for_url( $url );
		$deleted = $dir ? Core_Diet_Cache_Store::delete_variants( $dir ) : 0;

		if ( ! $deleted ) {
			wp_send_json_error( __( 'That URL had nothing cached. Check that it belongs to this site and that you copied it whole.', 'wpo-tweaks' ) );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: number of files. */
				esc_html( _n( '%s cached file deleted.', '%s cached files deleted.', $deleted, 'wpo-tweaks' ) ),
				number_format_i18n( $deleted )
			),
			'stats'   => $this->get_stats_payload( true ),
		) );
	}

	/**
	 * AJAX: ask the site for its own home page and report what came back.
	 */
	public function ajax_test() {
		check_ajax_referer( 'core_diet_tools_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'wpo-tweaks' ), 403 );
		}

		$result = $this->run_self_test();

		$payload = array(
			'message' => $result['message'],
			'stats'   => $this->get_stats_payload( true ),
		);

		if ( $result['ok'] ) {
			wp_send_json_success( $payload );
		}

		wp_send_json_error( $payload );
	}

	/**
	 * Current figures, formatted for the status cards.
	 *
	 * @param bool $force Recount instead of reading the cached answer.
	 * @return array
	 */
	private function get_stats_payload( $force = false ) {
		$stats = Core_Diet_Cache_Store::get_stats( $force );

		return array(
			'pages' => number_format_i18n( $stats['pages'] ),
			'bytes' => size_format( $stats['bytes'], 1 ),
		);
	}

	/**
	 * Ask the site for its own home page twice and read the cache header.
	 *
	 * The first request should store the page and the second should be served
	 * from disk. Doing it from the server side means the answer is about the
	 * site, not about the browser the admin happens to be using, and it is the
	 * only way to test as an anonymous visitor without logging out.
	 *
	 * @return array {
	 *     @type bool   $ok      Whether the cache answered as expected.
	 *     @type string $message Sentence to show.
	 * }
	 */
	private function run_self_test() {
		if ( ! Core_Diet_Cache::is_enabled() ) {
			return array(
				'ok'      => false,
				'message' => __( 'The page cache is off, so there is nothing to test.', 'wpo-tweaks' ),
			);
		}

		$args = array(
			'timeout'    => 10,
			'sslverify'  => false,
			'headers'    => array( 'Accept-Encoding' => 'gzip' ),
			'user-agent' => 'DietPress cache self test',
		);

		$first = wp_remote_get( home_url( '/' ), $args );
		if ( is_wp_error( $first ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: error message. */
					__( 'The site could not reach itself, so the test says nothing about the cache: %s', 'wpo-tweaks' ),
					$first->get_error_message()
				),
			);
		}

		$second = wp_remote_get( home_url( '/' ), $args );
		if ( is_wp_error( $second ) ) {
			return array(
				'ok'      => false,
				'message' => $second->get_error_message(),
			);
		}

		$header = wp_remote_retrieve_header( $second, 'x-dietpress-cache' );
		$header = is_array( $header ) ? (string) reset( $header ) : (string) $header;

		if ( 'HIT' === strtoupper( $header ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'The home page was served from the cache. The engine is working.', 'wpo-tweaks' ),
			);
		}

		if ( 'MISS' === strtoupper( $header ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The home page is cacheable but was rebuilt instead of served from disk. The most likely cause is that the cache directory cannot be written to, or that something purges the cache on every request.', 'wpo-tweaks' ),
			);
		}

		return array(
			'ok'      => false,
			'message' => __( 'The home page is being skipped by the cache. The usual causes are a plugin that sets a cookie on every visit, a plugin that declares the page uncacheable, or the home page being excluded below. Enable WP_DEBUG and read the HTML comment at the end of the page source: it names the exact reason.', 'wpo-tweaks' ),
		);
	}

	/* ============================
	 * Tab rendering
	 * ============================ */

	/**
	 * Render the whole Cache tab, inside the page's main settings form.
	 *
	 * @param Core_Diet_Admin $main_admin The settings page, which owns the
	 *                                    renderer for the shared options.
	 */
	public function render_tab( $main_admin = null ) {
		// Both render methods are public and could be called from anywhere, so
		// each checks for itself rather than trusting the page that hosts it.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$blocking = Core_Diet_Cache_Compat::get_blocking_reasons();
		$warnings = Core_Diet_Cache_Compat::get_warnings();
		?>
		<p class="core-diet-tab-description">
			<?php esc_html_e( 'Stores a static copy of each page on disk and serves it to anonymous visitors without building the page again. Logged in visitors, carts and forms always get the live site.', 'wpo-tweaks' ); ?>
		</p>

		<?php
		$this->render_section_title( __( 'Page cache', 'wpo-tweaks' ) );
		?>

		<p class="core-diet-option-notice core-diet-option-notice-inactive core-diet-tab-note">
			<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
			<span class="core-diet-option-notice-text">
				<strong><?php esc_html_e( 'Note:', 'wpo-tweaks' ); ?></strong>
				<?php esc_html_e( 'your own visits are never cached, because you are logged in. A cached page could otherwise show your admin bar or your account to a stranger. To watch the cache fill up, browse the site in a private window and reload this screen.', 'wpo-tweaks' ); ?>
			</span>
		</p>

		<?php
		$this->render_status_panel( $blocking, $warnings );
		?>

		<div class="core-diet-cards-grid">
		<?php
		$this->render_toggle(
			'enabled',
			__( 'Enable the page cache', 'wpo-tweaks' ),
			__( 'Off by default. Switching it off again empties the cache, so nothing stale is left on disk.', 'wpo-tweaks' ),
			(bool) $blocking
		);

		if ( $warnings ) {
			$this->render_toggle(
				'host_cache_ack',
				__( 'I understand the risk of a second cache', 'wpo-tweaks' ),
				__( 'Required to enable the cache when your hosting already serves one. Purging DietPress does not purge your hosting cache.', 'wpo-tweaks' )
			);
		}

		$this->render_number(
			'ttl_hours',
			__( 'Cached pages expire after', 'wpo-tweaks' ),
			__( 'Hours. 0 keeps pages until an edit purges them. The default of 12 is deliberate: WordPress security tokens embedded in forms stay valid for a day, so a page older than that can carry an expired one and break a comment form or an add to cart button.', 'wpo-tweaks' ),
			0,
			720
		);

		$this->render_toggle(
			'precompress_gzip',
			__( 'Store a compressed copy', 'wpo-tweaks' ),
			__( 'Writes a gzipped twin of every page and serves it to browsers that accept it. Costs a little disk, saves the compression work on every hit.', 'wpo-tweaks' )
		);

		$this->render_toggle(
			'separate_mobile',
			__( 'Separate cache for mobile', 'wpo-tweaks' ),
			__( 'Only needed if your theme sends different HTML to phones. Responsive themes, which is nearly all of them today, do not: leaving this off halves the disk used.', 'wpo-tweaks' )
		);
		?>
		</div>

		<?php $this->render_section_title( __( 'Page cache exclusions', 'wpo-tweaks' ) ); ?>

		<div class="core-diet-cards-grid">
		<?php
		$this->render_textarea(
			'exclude_urls',
			__( 'Never cache these URLs', 'wpo-tweaks' ),
			__( 'One path per line, starting with a slash. Use * as a wildcard, for example /promo/* to exclude a whole section.', 'wpo-tweaks' ),
			/* translators: Example paths shown as a placeholder. Use paths that read naturally in your language. */
			__( "/offer-of-the-week/\n/private-area/*", 'wpo-tweaks' )
		);

		$this->render_textarea(
			'ignore_query_params',
			__( 'Extra query parameters to ignore', 'wpo-tweaks' ),
			__( 'One name per line. A URL carrying only ignored parameters is served the cached copy of the plain URL. Tracking parameters from Google, Meta, Mailchimp, HubSpot and Matomo are already covered, and parameters that change what WordPress renders are refused.', 'wpo-tweaks' ),
			/* translators: Example query parameter names shown as a placeholder. */
			__( "my_campaign\nreferral_code", 'wpo-tweaks' )
		);
		?>
		</div>

		<?php
		// Everything above and this belongs to the page cache, so it comes
		// before the browser rules rather than after them.
		$this->render_purge_section();

		// Browser caching: the other half of the subject, written to .htaccess
		// instead of served from disk, and useful on its own whether or not the
		// page cache above is on.
		$this->render_section_title( __( 'Browser cache (.htaccess)', 'wpo-tweaks' ) );
		?>
		<p class="core-diet-tab-description">
			<?php esc_html_e( 'Rules written to your .htaccess file that tell browsers and CDNs how long to keep your images, styles, scripts and fonts. Nothing here depends on the page cache: they work whether it is on or off. Compression and keep-alive are written to the same file, but they are not caching, so they stayed in the Strict tab.', 'wpo-tweaks' ); ?>
		</p>
		<?php

		if ( $main_admin instanceof Core_Diet_Admin ) {
			$main_admin->render_browser_cache_group();
		}
	}

	/**
	 * Render the compact status block for the Scale tab.
	 */
	public function render_dashboard_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="core-diet-tools-section core-diet-savings-section">
			<h2><?php esc_html_e( 'Page cache', 'wpo-tweaks' ); ?></h2>
			<?php
			$this->render_status_panel(
				Core_Diet_Cache_Compat::get_blocking_reasons(),
				Core_Diet_Cache_Compat::get_warnings()
			);

			if ( ! Core_Diet_Cache::is_enabled() && ! Core_Diet_Cache_Compat::get_blocking_reasons() ) {
				echo '<p class="core-diet-tab-description">';
				esc_html_e( 'The page cache is off. Turn it on from the Cache tab, or apply any of the quick profiles below: all four switch it on, each with the lifetime that suits it.', 'wpo-tweaks' );
				echo '</p>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the status figures and any reason the module cannot run.
	 *
	 * There is no "Running / Off" badge: the coloured dot on the tab itself
	 * says which it is, and repeating it here was one label too many.
	 *
	 * @param array $blocking Blocking reasons.
	 * @param array $warnings Non-blocking warnings.
	 */
	private function render_status_panel( $blocking, $warnings ) {
		$enabled = Core_Diet_Cache::is_enabled();
		$stats   = Core_Diet_Cache_Store::get_stats();
		$gc      = self::describe_schedule( wp_next_scheduled( Core_Diet_Cache::CRON_HOOK ) );
		$ttl     = (int) $this->settings->get( 'ttl_hours' );
		?>
		<div class="core-diet-cache-status">

			<?php if ( $enabled && ! $blocking ) : ?>
				<div class="core-diet-savings-grid core-diet-cache-grid">
					<div class="core-diet-savings-card">
						<span class="core-diet-savings-value" id="core-diet-cache-pages"><?php echo esc_html( number_format_i18n( $stats['pages'] ) ); ?></span>
						<span class="core-diet-savings-label"><?php esc_html_e( 'Pages cached', 'wpo-tweaks' ); ?></span>
					</div>
					<div class="core-diet-savings-card">
						<span class="core-diet-savings-value" id="core-diet-cache-bytes"><?php echo esc_html( size_format( $stats['bytes'], 1 ) ); ?></span>
						<span class="core-diet-savings-label"><?php esc_html_e( 'Disk used', 'wpo-tweaks' ); ?></span>
					</div>
					<div class="core-diet-savings-card">
						<span class="core-diet-savings-value">
							<?php
							if ( $ttl > 0 ) {
								echo esc_html( number_format_i18n( $ttl ) );
								echo ' <small>' . esc_html__( 'h', 'wpo-tweaks' ) . '</small>';
							} else {
								echo '&infin;';
							}
							?>
						</span>
						<span class="core-diet-savings-label"><?php esc_html_e( 'Pages expire after', 'wpo-tweaks' ); ?></span>
					</div>
					<div class="core-diet-savings-card">
						<span class="core-diet-savings-value core-diet-cache-value-small<?php echo $gc['late'] ? ' core-diet-cache-value-late' : ''; ?>">
							<?php echo esc_html( $gc['text'] ); ?>
						</span>
						<span class="core-diet-savings-label"><?php esc_html_e( 'Next cleanup', 'wpo-tweaks' ); ?></span>
					</div>
				</div>

				<?php foreach ( self::get_cron_warnings( $gc ) as $cron_warning ) : ?>
					<p class="core-diet-option-notice core-diet-option-notice-warning core-diet-cache-block">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<span class="core-diet-option-notice-text"><?php echo esc_html( $cron_warning ); ?></span>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php foreach ( $blocking as $reason ) : ?>
				<p class="core-diet-option-notice core-diet-option-notice-locked core-diet-cache-block">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<span class="core-diet-option-notice-text"><?php echo esc_html( $reason ); ?></span>
				</p>
			<?php endforeach; ?>

			<?php foreach ( $warnings as $reason ) : ?>
				<p class="core-diet-option-notice core-diet-option-notice-warning core-diet-cache-block">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span class="core-diet-option-notice-text"><?php echo esc_html( $reason ); ?></span>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Describe the state of a scheduled event in one phrase.
	 *
	 * Written because the panel used to print human_time_diff() on its own, and
	 * that function has no direction: it answers "2 days" whether the cleanup
	 * runs in two days or ran two days late. So the one card that could have
	 * revealed an unattended cron was the card that hid it, and it read as
	 * healthy on exactly the sites where nothing was being collected.
	 *
	 * The hour of grace before calling it late is deliberate. WordPress fires
	 * its scheduled tasks on visits, so a few minutes past the hour is normal
	 * on any site and saying so would be noise.
	 *
	 * @param int|false $timestamp Next run, as wp_next_scheduled() returns it.
	 * @return array {
	 *     @type string $text      Phrase for the card.
	 *     @type bool   $late      Whether it is late enough to be worth explaining.
	 *     @type bool   $scheduled Whether there is an event at all.
	 * }
	 */
	private static function describe_schedule( $timestamp ) {
		if ( ! $timestamp ) {
			return array(
				'text'      => __( 'not scheduled', 'wpo-tweaks' ),
				'late'      => true,
				'scheduled' => false,
			);
		}

		$now = time();

		if ( $timestamp > $now ) {
			return array(
				'text'      => sprintf(
					/* translators: %s: time until the next run, for example "6 hours". */
					__( 'in %s', 'wpo-tweaks' ),
					human_time_diff( $now, $timestamp )
				),
				'late'      => false,
				'scheduled' => true,
			);
		}

		return array(
			'text'      => sprintf(
				/* translators: %s: how long the run is overdue, for example "2 days". */
				__( '%s late', 'wpo-tweaks' ),
				human_time_diff( $timestamp, $now )
			),
			'late'      => ( $now - $timestamp ) > HOUR_IN_SECONDS,
			'scheduled' => true,
		);
	}

	/**
	 * What to say when the cleanup is not running, if it is not.
	 *
	 * Only speaks when the event is actually overdue. A site with DISABLE_WP_CRON
	 * and a real system cron behind it is correctly configured, and warning it
	 * about a schedule that is being met would be the kind of permanent notice
	 * people learn to scroll past.
	 *
	 * @param array $gc Output of describe_schedule() for the collector.
	 * @return array Sentences to show.
	 */
	private static function get_cron_warnings( $gc ) {
		if ( empty( $gc['late'] ) ) {
			return array();
		}

		// No event at all is a different problem from an event nobody runs, and
		// it has a fix the site owner can apply from this very screen.
		if ( empty( $gc['scheduled'] ) ) {
			return array(
				__( 'There is no cleanup scheduled, so cached pages are never removed once they expire. Switching the page cache off and back on from this tab schedules it again.', 'wpo-tweaks' ),
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return array(
				__( 'The cleanup is overdue and WordPress cron is switched off on this site (DISABLE_WP_CRON), which is very likely the reason. With it off, the plugin scheduled tasks, this cleanup and the daily transient cleanup, only run if your server calls wp-cron.php on a real schedule. Check that cron job with your host. Meanwhile nothing is lost: publishing, editing and commenting still purge what they change, and only the expiry by age is affected.', 'wpo-tweaks' ),
			);
		}

		return array(
			__( 'The cleanup is overdue. WordPress runs its scheduled tasks on visits, so a site with very little traffic can go a long time without one. Expired pages stay on disk until it runs, although publishing, editing and commenting still purge what they change.', 'wpo-tweaks' ),
		);
	}

	/**
	 * Render the purge and test controls.
	 *
	 * Buttons, not a form: this whole tab lives inside the settings form and
	 * HTML has no nested forms. Both run over AJAX.
	 */
	private function render_purge_section() {
		$this->render_section_title( __( 'Page cache purge and diagnostics', 'wpo-tweaks' ) );
		?>
		<p class="core-diet-tab-description">
			<?php esc_html_e( 'Publishing, editing and approving comments already purge what they change. These are for the rest: a page that will not update, or a change made straight in the database.', 'wpo-tweaks' ); ?>
		</p>

		<div class="core-diet-cache-panel">
			<div class="core-diet-cache-search">
				<label class="core-diet-option-label" for="core-diet-cache-url">
					<?php esc_html_e( 'Purge one URL, or leave it empty to purge everything', 'wpo-tweaks' ); ?>
				</label>
				<input type="text"
				       id="core-diet-cache-url"
				       class="regular-text"
				       autocomplete="off"
				       role="combobox"
				       aria-expanded="false"
				       aria-autocomplete="list"
				       aria-controls="core-diet-cache-search-results"
				       placeholder="<?php esc_attr_e( 'Start typing a title, or paste a URL', 'wpo-tweaks' ); ?>">
				<div id="core-diet-cache-search-results" class="core-diet-search-results" role="listbox" hidden></div>
			</div>

			<p class="core-diet-cache-actions">
				<button type="button" class="button" id="core-diet-cache-purge"><?php esc_html_e( 'Purge cache', 'wpo-tweaks' ); ?></button>
				<button type="button" class="button" id="core-diet-cache-test"><?php esc_html_e( 'Test the cache now', 'wpo-tweaks' ); ?></button>
				<span class="core-diet-cache-actions-hint"><?php esc_html_e( 'The test asks the site for its own home page as an anonymous visitor would.', 'wpo-tweaks' ); ?></span>
			</p>

			<div id="core-diet-cache-result" class="core-diet-cache-result" hidden></div>
		</div>
		<?php
	}

	/* ============================
	 * Field helpers
	 * ============================ */

	/**
	 * Render a section title.
	 *
	 * @param string $title Section title.
	 */
	private function render_section_title( $title ) {
		echo '<h2 class="core-diet-section-title">' . esc_html( $title ) . '</h2>';
	}

	/**
	 * Render a toggle card.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param bool   $disabled    Whether the control is locked.
	 */
	private function render_toggle( $key, $label, $description = '', $disabled = false ) {
		$field_id = 'core_diet_cache_' . $key;
		$name     = Core_Diet_Cache_Settings::OPTION_NAME . '[' . $key . ']';
		$checked  = $this->settings->is_enabled( $key ) && ! $disabled;
		?>
		<div class="core-diet-option-card<?php echo $disabled ? ' core-diet-option-locked' : ''; ?>">
			<div class="core-diet-option-header">
				<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
				<label class="core-diet-toggle">
					<input type="checkbox"
					       id="<?php echo esc_attr( $field_id ); ?>"
					       name="<?php echo esc_attr( $name ); ?>"
					       value="1"
					       <?php checked( $checked ); ?>
					       <?php disabled( $disabled ); ?>>
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
	 * Render a number field card.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param int    $min         Minimum accepted.
	 * @param int    $max         Maximum accepted.
	 */
	private function render_number( $key, $label, $description, $min, $max ) {
		$field_id = 'core_diet_cache_' . $key;
		$name     = Core_Diet_Cache_Settings::OPTION_NAME . '[' . $key . ']';
		?>
		<div class="core-diet-option-card">
			<div class="core-diet-option-header">
				<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
				<input type="number"
				       id="<?php echo esc_attr( $field_id ); ?>"
				       name="<?php echo esc_attr( $name ); ?>"
				       value="<?php echo esc_attr( (string) $this->settings->get( $key ) ); ?>"
				       min="<?php echo esc_attr( (string) $min ); ?>"
				       max="<?php echo esc_attr( (string) $max ); ?>"
				       step="1"
				       class="small-text">
			</div>
			<?php if ( $description ) : ?>
				<p class="core-diet-option-desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a textarea card.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @param string $placeholder Two example lines, translatable so they read
	 *                            naturally in every language.
	 */
	private function render_textarea( $key, $label, $description, $placeholder = '' ) {
		$field_id = 'core_diet_cache_' . $key;
		$name     = Core_Diet_Cache_Settings::OPTION_NAME . '[' . $key . ']';
		?>
		<div class="core-diet-option-card core-diet-option-card-wide">
			<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( $label ); ?>
			</label>
			<textarea id="<?php echo esc_attr( $field_id ); ?>"
			          name="<?php echo esc_attr( $name ); ?>"
			          rows="4"
			          placeholder="<?php echo esc_attr( $placeholder ); ?>"
			          class="large-text code"><?php echo esc_textarea( (string) $this->settings->get( $key ) ); ?></textarea>
			<?php if ( $description ) : ?>
				<p class="core-diet-option-desc"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
