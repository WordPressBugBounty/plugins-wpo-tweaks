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
			'//fonts.googleapis.com',
			'//fonts.gstatic.com',
			'//ajax.googleapis.com',
			'//www.google-analytics.com',
			'//stats.wp.com',
			'//gravatar.com',
			'//secure.gravatar.com',
			'//0.gravatar.com',
			'//1.gravatar.com',
			'//2.gravatar.com',
			'//s.w.org',
		);

		$prefetch_domains = apply_filters( 'dietpress_dns_prefetch_domains', $prefetch_domains );

		foreach ( $prefetch_domains as $domain ) {
			echo '<link rel="dns-prefetch" href="' . esc_url( $domain ) . '">' . "\n";
		}
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
	 * Optimize feeds
	 */
	public function core_diet_optimize_feeds() {
		add_action( 'do_feed_rss2', function() {
			header( 'Cache-Control: public, max-age=3600' );
		}, 1 );

		add_filter( 'pre_option_posts_per_rss', function() {
			return '10';
		} );
	}
}
