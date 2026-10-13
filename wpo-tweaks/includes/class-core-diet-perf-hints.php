<?php
/**
 * DietPress performance hints: resource hints, asset preloading, and feed optimization.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Perf_Hints {

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
		if ( $this->settings->is_enabled( 'resource_hints' ) ) {
			// Resource hints
			add_action( 'wp_head', array( $this, 'core_diet_add_preconnect_hints' ), 1 );
			add_action( 'wp_head', array( $this, 'core_diet_add_dns_prefetch' ), 1 );
		}

		if ( $this->settings->is_enabled( 'preload_assets' ) ) {
			add_action( 'wp_head', array( $this, 'core_diet_preload_critical_resources' ), 1 );
		}

		if ( $this->settings->is_enabled( 'preload_logo' ) ) {
			add_action( 'wp_head', array( $this, 'core_diet_preload_site_logo' ), 2 );
		}

		if ( $this->settings->is_enabled( 'optimize_feeds' ) ) {
			// Feed optimization
			add_action( 'init', array( $this, 'core_diet_optimize_feeds' ) );
		}
	}

	/**
	 * Add preconnect hints
	 */
	public function core_diet_add_preconnect_hints() {
		$preconnects = array(
			'https://fonts.googleapis.com',
			'https://fonts.gstatic.com',
			'https://www.google-analytics.com',
			'https://www.googletagmanager.com',
		);

		$preconnects = apply_filters( 'dietpress_preconnect_hints', $preconnects );

		foreach ( $preconnects as $url ) {
			echo '<link rel="preconnect" href="' . esc_url( $url ) . '" crossorigin>' . "\n";
		}
	}

	/**
	 * Add DNS prefetch
	 */
	public function core_diet_add_dns_prefetch() {
		$prefetch_domains = array(
			'//ajax.googleapis.com', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Not offloading: dns-prefetch hint only, the plugin never requests an asset from this domain.
			'//stats.wp.com',
			'//secure.gravatar.com',
			'//s.w.org', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Not offloading: dns-prefetch hint only, the plugin never requests an asset from this domain.
		);

		/*
		 * Do not warm up a domain the site has just been told never to contact.
		 * On the front end s.w.org only ever serves the emoji images and
		 * secure.gravatar.com only the avatars, so with either of those switched
		 * off the hint resolves a host nothing will ever request, and it
		 * contradicts what the option itself promises to remove: the emoji one
		 * says out loud that it drops the DNS prefetch to s.w.org, and this
		 * module was adding it straight back. Dropped before the filter runs, so
		 * anyone who wants them anyway can add them with the filter below.
		 */
		if ( $this->settings->is_enabled( 'disable_emojis' ) ) {
			$prefetch_domains = array_diff( $prefetch_domains, array( '//s.w.org' ) ); // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Not offloading: the domain is named here in order to drop its dns-prefetch hint, nothing is ever loaded from it.
		}

		if ( $this->settings->is_enabled( 'disable_avatars' ) ) {
			$prefetch_domains = array_diff( $prefetch_domains, array( '//secure.gravatar.com' ) );
		}

		/*
		 * The other two were printed on every page of every site, and on most
		 * of them nothing is ever requested from either: WordPress does not load
		 * jQuery from Google, and stats.wp.com is Jetpack's. They stay only
		 * where the page is about to load something from them, which is what a
		 * hint is for. This runs on wp_head right after wp_enqueue_scripts, so
		 * what the page has queued is known. Dropped before the filter runs,
		 * like the two above.
		 */
		foreach ( array( 'ajax.googleapis.com', 'stats.wp.com' ) as $host ) { // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Not offloading: the domain is named here in order to drop its dns-prefetch hint, nothing is ever loaded from it.
			if ( ! $this->page_loads_from( $host ) ) {
				$prefetch_domains = array_diff( $prefetch_domains, array( '//' . $host ) );
			}
		}

		$prefetch_domains = apply_filters( 'dietpress_dns_prefetch_domains', $prefetch_domains );

		foreach ( $prefetch_domains as $domain ) {
			echo '<link rel="dns-prefetch" href="' . esc_url( $domain ) . '">' . "\n";
		}
	}

	/**
	 * Whether this page is about to load a script or a style from a host.
	 *
	 * Looks at what is queued and at what the queued handles depend on. A file
	 * queued later, from the footer, is not seen. For stats.wp.com that would
	 * be the common case rather than the odd one, since a stats script has no
	 * reason to be queued this early, so Jetpack is asked whether its Stats
	 * module is on as well.
	 *
	 * @param string $host Host name, without scheme.
	 * @return bool
	 */
	private function page_loads_from( $host ) {
		if ( 'stats.wp.com' === $host
			&& class_exists( 'Jetpack' )
			&& method_exists( 'Jetpack', 'is_module_active' )
			&& Jetpack::is_module_active( 'stats' ) ) {
			return true;
		}

		foreach ( array( wp_scripts(), wp_styles() ) as $registry ) {
			if ( ! $registry instanceof WP_Dependencies ) {
				continue;
			}

			$pending = (array) $registry->queue;
			$seen    = array();

			while ( $pending ) {
				$handle = array_pop( $pending );

				if ( isset( $seen[ $handle ] ) || ! isset( $registry->registered[ $handle ] ) ) {
					continue;
				}

				$seen[ $handle ] = true;
				$item            = $registry->registered[ $handle ];

				if ( is_string( $item->src ) && false !== strpos( $item->src, '//' . $host . '/' ) ) {
					return true;
				}

				foreach ( (array) $item->deps as $dep ) {
					$pending[] = $dep;
				}
			}
		}

		return false;
	}

	/**
	 * Preload critical resources
	 */
	public function core_diet_preload_critical_resources() {
		// Preload theme CSS
		$theme_css = get_stylesheet_uri();
		echo '<link rel="preload" href="' . esc_url( $theme_css ) . '" as="style">' . "\n";

		// Preload critical fonts if they exist
		$critical_fonts = apply_filters( 'dietpress_critical_fonts', array() );
		foreach ( $critical_fonts as $font_url ) {
			echo '<link rel="preload" href="' . esc_url( $font_url ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
		}
	}

	/**
	 * Preload site logo for LCP optimization
	 *
	 * @since 2.2.0
	 */
	public function core_diet_preload_site_logo() {
		// Skip in admin, feeds, and REST requests
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// Get custom logo ID
		$custom_logo_id = get_theme_mod( 'custom_logo' );

		if ( ! $custom_logo_id ) {
			return;
		}

		// Get logo image data
		$logo_data = $this->core_diet_get_logo_preload_data( $custom_logo_id );

		if ( ! $logo_data ) {
			return;
		}

		// Output preload link with fetchpriority
		printf(
			'<link rel="preload" href="%s" as="image" type="%s" fetchpriority="high">' . "\n",
			esc_url( $logo_data['url'] ),
			esc_attr( $logo_data['type'] )
		);
	}

	/**
	 * Get logo data for preload
	 *
	 * @param int $logo_id Attachment ID of the logo
	 * @return array|false Array with url and type, or false on failure
	 */
	private function core_diet_get_logo_preload_data( $logo_id ) {
		// Try cache first
		$cache_key   = 'core_diet_logo_preload_' . $logo_id;
		$cached_data = wp_cache_get( $cache_key );

		if ( $cached_data !== false ) {
			return $cached_data;
		}

		// Get logo URL
		$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );

		if ( ! $logo_url ) {
			return false;
		}

		// Determine MIME type
		$mime_type = get_post_mime_type( $logo_id );

		// Map common image MIME types
		$type_map = array(
			'image/jpeg'    => 'image/jpeg',
			'image/png'     => 'image/png',
			'image/gif'     => 'image/gif',
			'image/webp'    => 'image/webp',
			'image/avif'    => 'image/avif',
			'image/svg+xml' => 'image/svg+xml',
		);

		$preload_type = isset( $type_map[ $mime_type ] ) ? $type_map[ $mime_type ] : 'image/png';

		$logo_data = array(
			'url'  => $logo_url,
			'type' => $preload_type,
		);

		// Cache for 1 day
		wp_cache_set( $cache_key, $logo_data, '', DAY_IN_SECONDS );

		return $logo_data;
	}

	/**
	 * Let the RSS feed be kept for an hour, when it is the same for everybody.
	 *
	 * Up to 3.7.1 this header went out on every request for the feed. For a
	 * logged in visitor WordPress had already sent "no-store, private"
	 * (WP::send_headers(), wp-includes/class-wp.php:443 in 7.1.3), and this
	 * replaced it with "public": the feed of somebody who can read private
	 * posts includes them, and a cache in front that took the header at its
	 * word could hand that feed to anybody for an hour. Measured on 13 oct 2026.
	 */
	public function core_diet_feed_cache_control() {
		$sent = function_exists( 'headers_list' ) ? headers_list() : array();

		if ( ! self::feed_may_be_public( $sent, is_user_logged_in(), defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
			return;
		}

		header( 'Cache-Control: public, max-age=3600' );
	}

	/**
	 * Whether a feed may be marked as public.
	 *
	 * Not for a logged in visitor, not for a response that has declared itself
	 * uncacheable or sets a cookie, and not over a Cache-Control that already
	 * says the response is for one visitor or must not be kept.
	 *
	 * @param array $sent         Header lines, as headers_list() returns them.
	 * @param bool  $logged_in    Whether the visitor is logged in.
	 * @param bool  $do_not_cache Whether DONOTCACHEPAGE is set.
	 * @return bool
	 */
	private static function feed_may_be_public( array $sent, $logged_in, $do_not_cache ) {
		if ( $logged_in || $do_not_cache ) {
			return false;
		}

		foreach ( $sent as $header ) {
			if ( 0 === stripos( $header, 'set-cookie:' ) ) {
				return false;
			}

			if ( 0 === stripos( $header, 'cache-control:' ) && preg_match( '/\b(private|no-store|no-cache)\b/i', $header ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Optimize feeds
	 */
	public function core_diet_optimize_feeds() {
		add_action( 'do_feed_rss2', array( $this, 'core_diet_feed_cache_control' ), 1 );

		add_filter( 'pre_option_posts_per_rss', function() {
			return '10';
		} );
	}
}
