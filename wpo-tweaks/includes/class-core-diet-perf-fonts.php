<?php
/**
 * DietPress Google Fonts local hosting.
 *
 * Serves the Google Fonts a site enqueues from its own uploads directory:
 * the stylesheet is fetched once from fonts.googleapis.com, its woff2 files
 * are downloaded from fonts.gstatic.com, and the enqueued URL is swapped for
 * the local copy. Removes the DNS + TCP + TLS round trips to Google and the
 * visitor-IP transfer to a third party (GDPR).
 *
 * This first version covers stylesheets enqueued through the WordPress API
 * (`style_loader_src`); fonts hardcoded by themes directly in wp_head are
 * left untouched. Every failure falls back silently to the Google CDN, and a
 * failed attempt is remembered in a transient so it is not retried on every
 * page load.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Perf_Fonts {

	/** @var string Directory name inside uploads where fonts are stored. */
	const FONTS_DIR = 'dietpress-fonts';

	/** @var string Transient prefix for failed download attempts. */
	const FAIL_TRANSIENT = 'core_diet_fonts_fail_';

	/** @var string Transient prefix for the anti-stampede download lock. */
	const LOCK_TRANSIENT = 'core_diet_fonts_lock_';

	/** @var int Maximum number of font files downloaded per stylesheet. */
	const MAX_FONT_FILES = 30;

	/**
	 * Chrome desktop user agent used to fetch the Google stylesheet.
	 * Google serves woff2-only CSS to modern Chrome, so no format
	 * negotiation is needed.
	 *
	 * @var string
	 */
	const CHROME_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	/** @var Core_Diet_Settings */
	private $settings;

	/**
	 * @param Core_Diet_Settings $settings Settings instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		if ( $this->settings->is_enabled( 'host_google_fonts' ) ) {
			// Priority 20: run after optimize_google_fonts (10) so the URL
			// already carries display=swap when that option is on.
			add_filter( 'style_loader_src', array( $this, 'core_diet_localize_google_fonts' ), 20, 2 );

			// A theme switch usually changes the fonts in use: start clean.
			add_action( 'switch_theme', array( $this, 'core_diet_purge_local_fonts' ) );
		}

		// Registered unconditionally so turning the option OFF cleans up.
		add_action( 'update_option_' . Core_Diet_Settings::OPTION_NAME, array( $this, 'core_diet_on_settings_saved' ), 10, 2 );
	}

	/**
	 * Swap an enqueued Google Fonts stylesheet for its local copy.
	 *
	 * @param string $src    Stylesheet source URL.
	 * @param string $handle Style handle (unused, kept for filter signature).
	 * @return string Local URL when available, the original URL otherwise.
	 */
	public function core_diet_localize_google_fonts( $src, $handle ) {
		if ( is_admin() || ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		// Normalize protocol-relative URLs before parsing.
		$normalized = ( 0 === strpos( $src, '//' ) ) ? 'https:' . $src : $src;

		$host = wp_parse_url( $normalized, PHP_URL_HOST );
		if ( 'fonts.googleapis.com' !== $host ) {
			return $src;
		}

		/**
		 * Filter the exclusion list for Google Fonts local hosting.
		 *
		 * Each entry is a plain substring matched against the stylesheet
		 * URL; matching stylesheets keep loading from the Google CDN.
		 *
		 * @since 3.3.0
		 * @param array $exclusions Substrings of URLs to exclude.
		 */
		$exclusions = (array) apply_filters( 'dietpress_exclude_local_fonts', array() );
		foreach ( $exclusions as $exclusion ) {
			if ( is_string( $exclusion ) && '' !== $exclusion && false !== strpos( $normalized, $exclusion ) ) {
				return $src;
			}
		}

		$local = $this->get_local_stylesheet_url( $normalized );

		return $local ? $local : $src;
	}

	/**
	 * Get (building it on first demand) the local URL for a remote
	 * Google Fonts stylesheet.
	 *
	 * @param string $remote_url Normalized fonts.googleapis.com URL.
	 * @return string|false Local stylesheet URL, or false to keep the CDN.
	 */
	private function get_local_stylesheet_url( $remote_url ) {
		$hash    = md5( $remote_url );
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$dir      = trailingslashit( $uploads['basedir'] ) . self::FONTS_DIR;
		$url      = trailingslashit( $uploads['baseurl'] ) . self::FONTS_DIR;
		$css_file = $dir . '/google-fonts-' . $hash . '.css';
		$css_url  = $url . '/google-fonts-' . $hash . '.css';

		// Already built on a previous request.
		if ( file_exists( $css_file ) ) {
			return $css_url;
		}

		// A recent attempt failed: serve the CDN, retry after the transient expires.
		if ( get_transient( self::FAIL_TRANSIENT . $hash ) ) {
			return false;
		}

		// Another request is already downloading this stylesheet.
		if ( get_transient( self::LOCK_TRANSIENT . $hash ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT . $hash, 1, MINUTE_IN_SECONDS );

		$built = $this->build_local_stylesheet( $remote_url, $dir, $css_file );

		delete_transient( self::LOCK_TRANSIENT . $hash );

		if ( ! $built ) {
			set_transient( self::FAIL_TRANSIENT . $hash, 1, 12 * HOUR_IN_SECONDS );
			return false;
		}

		return $css_url;
	}

	/**
	 * Download the Google stylesheet and its font files, rewrite the URLs
	 * and store everything in the local fonts directory.
	 *
	 * @param string $remote_url Remote stylesheet URL.
	 * @param string $dir        Absolute path of the local fonts directory.
	 * @param string $css_file   Absolute path of the local stylesheet.
	 * @return bool Whether the local stylesheet was written.
	 */
	private function build_local_stylesheet( $remote_url, $dir, $css_file ) {
		$css = $this->fetch_remote( $remote_url, 512 * KB_IN_BYTES );

		if ( false === $css || false === strpos( $css, '@font-face' ) ) {
			return false;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$filesystem = $this->get_filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		// Extract the font file URLs. Only fonts.gstatic.com sources are
		// downloaded; anything else keeps its remote URL.
		preg_match_all( '/url\(\s*([\'"]?)(https:\/\/fonts\.gstatic\.com\/[^)\'"\s]+)\1\s*\)/i', $css, $matches );

		$font_urls = array();
		if ( ! empty( $matches[2] ) ) {
			$font_urls = array_slice( array_unique( $matches[2] ), 0, self::MAX_FONT_FILES );
		}

		foreach ( $font_urls as $font_url ) {
			if ( 'fonts.gstatic.com' !== wp_parse_url( $font_url, PHP_URL_HOST ) ) {
				continue;
			}

			$extension = strtolower( pathinfo( (string) wp_parse_url( $font_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ) {
				continue;
			}

			$font_name = md5( $font_url ) . '.' . $extension;
			$font_path = $dir . '/' . $font_name;

			if ( ! file_exists( $font_path ) ) {
				$font_data = $this->fetch_remote( $font_url, 2 * MB_IN_BYTES );

				// A single failed font is skipped, not fatal: its URL stays
				// remote in the stylesheet and keeps loading from Google.
				if ( false === $font_data || ! $filesystem->put_contents( $font_path, $font_data, FS_CHMOD_FILE ) ) {
					continue;
				}
			}

			// Relative URL: the font lives next to the stylesheet, so the
			// rewrite survives domain or scheme changes.
			$css = str_replace( $font_url, $font_name, $css );
		}

		// Block directory listing in hosts that allow it.
		if ( ! file_exists( $dir . '/index.html' ) ) {
			$filesystem->put_contents( $dir . '/index.html', '', FS_CHMOD_FILE );
		}

		return (bool) $filesystem->put_contents( $css_file, $css, FS_CHMOD_FILE );
	}

	/**
	 * Fetch a remote resource from the Google Fonts infrastructure.
	 *
	 * @param string $url       URL to fetch (already host-validated by callers).
	 * @param int    $max_bytes Response size cap.
	 * @return string|false Response body, or false on any failure.
	 */
	private function fetch_remote( $url, $max_bytes ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'user-agent'          => self::CHROME_UA,
				'limit_response_size' => $max_bytes,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		return ( '' !== $body ) ? $body : false;
	}

	/**
	 * Remove the local fonts directory and everything in it.
	 *
	 * Runs on theme switch (the new theme likely uses other fonts), when the
	 * option is turned off, and on plugin deactivation. Stylesheets are
	 * rebuilt on demand the next time the option acts.
	 */
	public function core_diet_purge_local_fonts() {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::FONTS_DIR;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$filesystem = $this->get_filesystem();
		if ( $filesystem ) {
			$filesystem->rmdir( $dir, true );
		}
	}

	/**
	 * Purge the local fonts when the option is turned off.
	 *
	 * @param mixed $old Previous settings value.
	 * @param mixed $new New settings value.
	 */
	public function core_diet_on_settings_saved( $old, $new ) {
		$was_on = is_array( $old ) && ! empty( $old['host_google_fonts'] );
		$is_on  = is_array( $new ) && ! empty( $new['host_google_fonts'] );

		if ( $was_on && ! $is_on ) {
			$this->core_diet_purge_local_fonts();
		}
	}

	/**
	 * Get the initialized WP_Filesystem, or false when unavailable.
	 *
	 * @return WP_Filesystem_Base|false
	 */
	private function get_filesystem() {
		global $wp_filesystem;

		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return empty( $wp_filesystem ) ? false : $wp_filesystem;
	}
}
