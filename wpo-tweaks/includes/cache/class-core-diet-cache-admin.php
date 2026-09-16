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

		$result = self::test_and_react();

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
	 * Run the test of the Cache tab, and switch the accelerator off when it fails visitors.
	 *
	 * Apart from the AJAX handler, which checks the nonce and the capability
	 * before calling it, so the release checks can run it as it runs there.
	 *
	 * @return array Result of the self test or of enable().
	 */
	public static function test_and_react() {
		// A switched on accelerator that was tested somewhere else, a site that
		// moved server or address, is tested again for real, which is what lets
		// it serve again. Otherwise the test only reads what the site does.
		if ( Core_Diet_Cache_Accelerator::is_switched_on() && ! Core_Diet_Cache_Accelerator::is_verified_here() ) {
			$result = Core_Diet_Cache_Accelerator::enable();

			// Failing here takes the setting off too, the same as when it is
			// switched on from the form: a switch that says on and does
			// nothing is the one thing the tab must never show.
			if ( ! $result['ok'] ) {
				Core_Diet_Tools::set_cache_option( 'accelerator', false );
			}

			return $result;
		}

		require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-self-test.php';
		$result = Core_Diet_Cache_Self_Test::run();

		// Switched on but unable to run: the rules are out and PHP serves, so
		// what the test says about the rules is beside the point. The reason is.
		// Unless the server still answered from the cache by itself, or failed
		// where it does: names the pause did not reach (a link made by hand, a
		// copy of the cache restored from a backup), or a pause that has not
		// happened yet, because maybe_sync() does not run on this AJAX request.
		// Either way visitors get that answer, so the pause happens now.
		$reason = Core_Diet_Cache_Accelerator::is_switched_on() ? Core_Diet_Cache_Accelerator::get_unavailable_reason() : '';
		if ( '' !== $reason ) {
			$served  = ! empty( $result['static'] ) || ! empty( $result['broken'] ) || ! empty( $result['headerless'] );
			$cleared = $served && Core_Diet_Cache_Accelerator::pause();

			if ( $cleared ) {
				$after = __( 'The server was still answering the home page from the cache by itself, so the names it finds the stored pages under have been removed, and PHP serves them again.', 'wpo-tweaks' );
			} elseif ( ! $served && ! empty( $result['ok'] ) ) {
				$after = __( 'Meanwhile the page cache is working through PHP.', 'wpo-tweaks' );
			} else {
				$after = $result['message'];
			}

			$result['message'] = sprintf(
				/* translators: %s: why the accelerator cannot run, a full sentence. */
				__( 'The accelerator is switched on but cannot run right now: %s', 'wpo-tweaks' ),
				$reason
			) . ' ' . $after;

			return $result;
		}

		// The server stopped sending the headers the accelerator was tested with
		// (a host that dropped mod_headers), or it answers from the cache folder
		// with an error, or the home page fails with a server error: switched
		// off, as a failed test would, because visitors get whatever came back.
		if ( ( ! empty( $result['headerless'] ) || ! empty( $result['broken'] ) ) && Core_Diet_Cache_Accelerator::is_switched_on() ) {
			Core_Diet_Tools::set_cache_option( 'accelerator', false );
			$result['message'] .= ' ' . __( 'The accelerator has been switched off.', 'wpo-tweaks' );
		}

		return $result;
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

		// The rules can drift from the settings without any save to notice it
		// (a constant, a new address, another tool editing .htaccess), so the
		// tab puts them right before it describes them.
		Core_Diet_Cache_Accelerator::maybe_sync( true );
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

		$this->render_accelerator_card( (bool) $blocking );

		if ( $warnings ) {
			$this->render_toggle(
				'host_cache_ack',
				__( 'I understand the risk of a second cache', 'wpo-tweaks' ),
				__( 'Required to enable the cache when your hosting already serves one. Purging DietPress does not purge your hosting cache.', 'wpo-tweaks' )
			);
		}

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

		$this->render_number(
			'ttl_hours',
			__( 'Cached pages expire after', 'wpo-tweaks' ),
			__( "0 keeps pages until an edit purges them. The default of 12 is deliberate: WordPress security tokens embedded in forms stay valid for a day, so a page older than that can carry an expired one and break a comment form or an add to cart button.\n\nThis is not the cleanup next to it: a page past this age is never served to anybody, whether the cleanup has run or not, and the cleanup only takes those copies off the disk.", 'wpo-tweaks' ),
			0,
			720,
			__( 'Hours', 'wpo-tweaks' )
		);

		$this->render_schedule_card();
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

				<?php if ( self::has_mobile_markup() ) : ?>
					<p class="core-diet-option-notice core-diet-option-notice-warning core-diet-cache-block">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<span class="core-diet-option-notice-text"><?php esc_html_e( 'Your theme or a plugin builds different HTML for phones: it asks WordPress whether the visitor is on a mobile device while the page is built. So that desktop visitors never get a page built for a phone, pages are only stored from desktop visits, and pages visited mostly from phones stay out of the cache. Switch on the separate cache for mobile below and phones get cached pages of their own.', 'wpo-tweaks' ); ?></span>
					</p>
				<?php endif; ?>

				<?php if ( self::has_proxy_mismatch() ) : ?>
					<p class="core-diet-option-notice core-diet-option-notice-warning core-diet-cache-block">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<span class="core-diet-option-notice-text"><?php esc_html_e( 'Some visits reached this site with proxy headers, such as X-Forwarded-Proto, that disagree with the HTTPS WordPress detects, or WordPress only switched HTTPS on or off after loading, so those visits were not cached: a copy stored from them could reach other visitors with the wrong scheme. If the site is behind a proxy or CDN, including one your hosting puts in front of it, tell WordPress that the connection is HTTPS before it loads, in the server configuration or near the top of wp-config.php, before the comment that says to stop editing. If there is no proxy, or WordPress is already told that way and the note keeps coming back, those visits did not come through the proxy and carried forged headers, and there is nothing to fix. This note stays while it keeps happening and clears itself a day after the last time.', 'wpo-tweaks' ); ?></span>
					</p>
				<?php endif; ?>
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
	 * Only speaks when the event is actually overdue or missing. A site with
	 * DISABLE_WP_CRON and a real system cron behind it is correctly configured,
	 * and warning it about a schedule that is being met would be the kind of
	 * permanent notice people learn to scroll past.
	 *
	 * Each sentence also says what is not affected. The engine checks the age
	 * of a copy before serving it and rebuilds it when it is too old, so a
	 * cleanup that does not run leaves expired copies taking up disk and
	 * nothing else. The first wording left that out and read as if the expiry
	 * itself had stopped working.
	 *
	 * @param array $gc Output of describe_schedule() for the collector.
	 * @return array Sentences to show.
	 */
	private static function get_cron_warnings( $gc ) {
		if ( empty( $gc['late'] ) ) {
			return array();
		}

		// Core_Diet_Cache::maybe_schedule_gc() has already run on this request,
		// so an event still missing here was refused or removed again. The
		// advice this used to give, switching the cache off and back on, only
		// emptied the cache and ran into the same wall.
		if ( empty( $gc['scheduled'] ) ) {
			return array(
				__( 'The cleanup is not scheduled, although DietPress schedules it again whenever it goes missing, so something on this site is removing it or keeping WordPress from saving it, usually a plugin that manages scheduled tasks. Visitors are not affected: an expired page is never served, it is rebuilt on its next visit. Expired copies only take up disk space until a cleanup runs.', 'wpo-tweaks' ),
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return array(
				__( 'The cleanup is overdue and WordPress cron is switched off on this site (DISABLE_WP_CRON), which is very likely the reason. With it off, no scheduled task runs unless your server calls wp-cron.php on a real schedule, and that includes scheduled posts, not only this cleanup. Check that cron job with your host. Visitors are not affected by the delay: an expired page is never served, it is rebuilt on its next visit. Expired copies only take up disk space until the cleanup runs.', 'wpo-tweaks' ),
			);
		}

		return array(
			__( 'The cleanup is overdue. WordPress runs its scheduled tasks when a page is built, and a page served from the cache is not built, so a site whose visits are mostly served from the cache, or that has little traffic, can go a long time without running them. Scheduled posts wait too. Visitors are not affected by the cleanup delay: an expired page is never served, it is rebuilt on its next visit. Expired copies only take up disk space until the cleanup runs.', 'wpo-tweaks' ),
		);
	}

	/**
	 * Whether pages were recently kept out for being built for a phone.
	 *
	 * Only while the separate mobile cache is off, which is the case it is
	 * about, and for two days after the last time, since the engine notes it at
	 * most once a day.
	 *
	 * @return bool
	 */
	private function has_mobile_markup() {
		if ( $this->settings->is_enabled( 'separate_mobile' ) || ! class_exists( 'Core_Diet_Cache_Engine' ) ) {
			return false;
		}

		$last = (int) get_option( Core_Diet_Cache_Engine::MOBILE_MARKUP_OPTION, 0 );

		return $last > 0 && ( time() - $last ) < 2 * DAY_IN_SECONDS;
	}

	/**
	 * Render the accelerator card: its toggle, and what it is doing right now.
	 *
	 * @param bool $blocking Whether the page cache itself is blocked.
	 */
	private function render_accelerator_card( $blocking ) {
		$reason   = Core_Diet_Cache_Accelerator::get_unavailable_reason();
		$on       = $this->settings->is_enabled( 'accelerator' );
		$verified = get_option( Core_Diet_Cache_Accelerator::VERIFIED_OPTION );

		// Switched on but with the cache off, the reason is only "switch the
		// cache on first": the setting is kept and shown as it is.
		$cache_off = ! Core_Diet_Cache::is_enabled();
		$locked    = $blocking || ( '' !== $reason && ! $cache_off );

		$nginx = 'nginx' === Core_Diet_Cache_Accelerator::server();

		$this->render_toggle(
			'accelerator',
			__( 'Serve cached pages from the server (accelerator)', 'wpo-tweaks' ),
			$nginx
				? __( 'The server answers with the stored copy by itself, without starting PHP or WordPress, which is the fastest a page can be served. nginx does not read .htaccess, so the rules are yours to paste: copy the block below into the configuration of this site as its comments say, reload nginx, and then switch this on. It is tested on your home page before it stays on.', 'wpo-tweaks' )
				: __( 'The server answers with the stored copy by itself, without starting PHP or WordPress, which is the fastest a page can be served. Switching it on writes a block of rules to your .htaccess and tests them on your home page: if the test fails, it stays off and says why.', 'wpo-tweaks' ),
			$locked,
			$locked ? $reason : ''
		);

		if ( $nginx && ! $locked && ! $cache_off ) {
			$this->render_nginx_rules( $on, $verified );
		}

		if ( ! $on || $locked || $cache_off ) {
			return;
		}

		$pending = is_array( $verified ) && empty( $verified['time'] );

		if ( ! Core_Diet_Cache_Accelerator::is_verified_here() ) {
			$status  = $pending
				? __( 'The last test of the accelerator did not finish, so cached pages are served by PHP for now. Press "Test the cache now" below to run it again.', 'wpo-tweaks' )
				: __( 'The site moved to another server or address since the accelerator was tested, so cached pages are served by PHP for now. Press "Test the cache now" below to test it here.', 'wpo-tweaks' );
			$variant = 'warning';
		} elseif ( is_array( $verified ) && ! empty( $verified['time'] ) ) {
			$status = sprintf(
				/* translators: %s: date and time of the last successful test. */
				__( 'Tested on %s: the server serves the cached pages.', 'wpo-tweaks' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $verified['time'] )
			);
			$variant = 'inactive';

			if ( array_key_exists( 'compressed', $verified ) && false === $verified['compressed'] ) {
				$status .= ' ' . __( 'They go out uncompressed, though: switch on the compression rules in the Strict tab, or ask your hosting to enable mod_deflate.', 'wpo-tweaks' );
				$variant  = 'warning';
			}
		} else {
			return;
		}
		?>
		<p class="core-diet-option-notice core-diet-option-notice-<?php echo esc_attr( $variant ); ?> core-diet-cache-block core-diet-option-card-wide">
			<span class="dashicons dashicons-<?php echo 'warning' === $variant ? 'warning' : 'info-outline'; ?>" aria-hidden="true"></span>
			<span class="core-diet-option-notice-text"><?php echo esc_html( $status ); ?></span>
		</p>
		<?php
	}

	/**
	 * Render the nginx rules to paste, and whether the pasted ones went stale.
	 *
	 * @param bool        $on       Whether the accelerator is switched on.
	 * @param array|false $verified Last successful test.
	 */
	private function render_nginx_rules( $on, $verified ) {
		$rules = Core_Diet_Cache_Accelerator::current_nginx_rules();
		if ( '' === $rules ) {
			return;
		}

		$stale = $on && is_array( $verified ) && ! empty( $verified['rules'] ) && md5( $rules ) !== $verified['rules'];
		?>
		<div class="core-diet-option-card core-diet-option-card-wide">
			<label class="core-diet-option-label" for="core-diet-cache-nginx-rules"><?php esc_html_e( 'Rules for nginx', 'wpo-tweaks' ); ?></label>
			<?php if ( $stale ) : ?>
				<p class="core-diet-option-notice core-diet-option-notice-warning">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<span class="core-diet-option-notice-text"><?php esc_html_e( 'These rules changed since the accelerator was tested, because an exclusion, a cookie that keeps visitors out, the mobile cache or the time browsers keep pages changed. Paste them again and reload nginx: until then nginx follows the old ones.', 'wpo-tweaks' ); ?></span>
				</p>
			<?php endif; ?>
			<textarea id="core-diet-cache-nginx-rules" class="large-text code" rows="14" readonly><?php echo esc_textarea( $rules ); ?></textarea>
			<p class="core-diet-option-desc"><?php esc_html_e( 'If your server block adds security headers with add_header, repeat them inside the location of part 2: nginx does not pass them down to a location that adds headers of its own. After pasting, check the configuration with nginx -t before reloading.', 'wpo-tweaks' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the cleanup schedule card: how often and at what hour.
	 */
	private function render_schedule_card() {
		$frequency = Core_Diet_Schedule::sanitize_frequency( $this->settings->get( 'gc_frequency' ) );
		$hour      = Core_Diet_Schedule::sanitize_hour( $this->settings->get( 'gc_hour' ) );
		$name      = Core_Diet_Cache_Settings::OPTION_NAME;
		?>
		<div class="core-diet-option-card">
			<div class="core-diet-option-header">
				<span class="core-diet-option-label"><?php esc_html_e( 'Clean up expired pages', 'wpo-tweaks' ); ?></span>
			</div>
			<p class="core-diet-cache-schedule">
				<label class="screen-reader-text" for="core_diet_cache_gc_frequency"><?php esc_html_e( 'How often', 'wpo-tweaks' ); ?></label>
				<select id="core_diet_cache_gc_frequency" name="<?php echo esc_attr( $name . '[gc_frequency]' ); ?>">
					<?php foreach ( Core_Diet_Schedule::get_frequencies() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $frequency, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="core_diet_cache_gc_hour"><?php esc_html_e( 'At what time', 'wpo-tweaks' ); ?></label>
				<select id="core_diet_cache_gc_hour" name="<?php echo esc_attr( $name . '[gc_hour]' ); ?>" <?php disabled( 'hourly', $frequency ); ?>>
					<option value="-1" <?php selected( Core_Diet_Schedule::AUTO_HOUR, $hour ); ?>><?php esc_html_e( 'at any time', 'wpo-tweaks' ); ?></option>
					<?php for ( $h = 0; $h < 24; $h++ ) : ?>
						<option value="<?php echo esc_attr( (string) $h ); ?>" <?php selected( $h, $hour ); ?>>
							<?php
							/* translators: %s: hour of the day, such as 04:00. */
							echo esc_html( sprintf( __( 'from %s', 'wpo-tweaks' ), sprintf( '%02d:00', $h ) ) );
							?>
						</option>
					<?php endfor; ?>
				</select>
			</p>
			<p class="core-diet-option-desc"><?php esc_html_e( 'Removes expired copies from the disk; an expired page is never served in the meantime. The hour is in the time zone of the site, and twice a day means that hour and twelve hours later. WordPress runs scheduled tasks with the first page it builds after that time, so on a quiet site it can run later. At any time, it keeps the pace it already had.', 'wpo-tweaks' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Whether visits have been kept out of the cache for their proxy headers.
	 *
	 * Asked two ways because either can be the only one that knows: the
	 * engine notes the last page it kept out, and the request that loads this
	 * screen may come through that same proxy. For this request the answer is
	 * the one the engine got on plugins_loaded, not a new one: a proxy fix
	 * that runs late has switched HTTPS on by the time this screen is drawn,
	 * and asking again would call the site fixed while its visits are still
	 * being kept out. Without any of this, a site behind a CDN that does not
	 * tell WordPress about HTTPS would stop caching those visits in silence,
	 * while the self test went on reporting a working cache.
	 *
	 * The note can also be left by somebody sending forged headers, which is
	 * why it lasts a day and the text says so.
	 *
	 * @return bool
	 */
	public static function has_proxy_mismatch() {
		if ( ! class_exists( 'Core_Diet_Cache_Engine' ) ) {
			return false;
		}

		$now = Core_Diet_Cache_Engine::lookup_mismatch();
		if ( null === $now ) {
			$now = Core_Diet_Cache_Engine::proxy_contradicts_wordpress();
		}
		if ( $now ) {
			return true;
		}

		$last = (int) get_option( Core_Diet_Cache_Engine::PROXY_MISMATCH_OPTION, 0 );

		return $last > 0 && ( time() - $last ) < DAY_IN_SECONDS;
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
	 * @param string $locked_why  Why it is locked, shown under the help text.
	 */
	private function render_toggle( $key, $label, $description = '', $disabled = false, $locked_why = '' ) {
		$field_id = 'core_diet_cache_' . $key;
		$name     = Core_Diet_Cache_Settings::OPTION_NAME . '[' . $key . ']';
		$stored   = $this->settings->is_enabled( $key );

		// A locked accelerator that is switched on stays a live checkbox, checked:
		// a disabled one is not sent with the form, so the next save of any
		// setting used to switch it off and forget its test, exactly while the
		// .htaccess could not be written for a moment; and it has to stay
		// possible to switch it off. The lock note says why it cannot run now.
		// Only switching it on is locked.
		if ( $disabled && $stored && 'accelerator' === $key ) {
			$disabled = false;
		}
		$checked = $stored && ! $disabled;
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
			<?php if ( '' !== $locked_why ) : ?>
				<p class="core-diet-option-notice core-diet-option-notice-locked">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<span class="core-diet-option-notice-text"><?php echo esc_html( $locked_why ); ?></span>
				</p>
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
	private function render_number( $key, $label, $description, $min, $max, $unit = '' ) {
		$field_id = 'core_diet_cache_' . $key;
		$name     = Core_Diet_Cache_Settings::OPTION_NAME . '[' . $key . ']';
		// The label takes the whole width and the field goes under it, with its
		// unit beside it: with both on one line the label wrapped and the card
		// read as a jumble. Two blank lines in the description start a paragraph.
		?>
		<div class="core-diet-option-card">
			<div class="core-diet-option-header core-diet-option-header-stacked">
				<label class="core-diet-option-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
			</div>
			<p class="core-diet-option-field">
				<input type="number"
				       id="<?php echo esc_attr( $field_id ); ?>"
				       name="<?php echo esc_attr( $name ); ?>"
				       value="<?php echo esc_attr( (string) $this->settings->get( $key ) ); ?>"
				       min="<?php echo esc_attr( (string) $min ); ?>"
				       max="<?php echo esc_attr( (string) $max ); ?>"
				       step="1"
				       class="small-text">
				<?php if ( '' !== $unit ) : ?>
					<span class="core-diet-option-unit"><?php echo esc_html( $unit ); ?></span>
				<?php endif; ?>
			</p>
			<?php foreach ( preg_split( '/\n\s*\n/', (string) $description, -1, PREG_SPLIT_NO_EMPTY ) as $parrafo ) : ?>
				<p class="core-diet-option-desc"><?php echo esc_html( trim( $parrafo ) ); ?></p>
			<?php endforeach; ?>
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
