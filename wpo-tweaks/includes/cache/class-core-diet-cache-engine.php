<?php
/**
 * Page cache engine: decides, serves and captures.
 *
 * Serving happens on plugins_loaded priority 1. That is later than a drop-in
 * would run, so WordPress and the plugins are already loaded, but it still
 * skips the main query, the theme and the bulk of the TTFB, and it works the
 * same on Apache and nginx without touching wp-config.php.
 *
 * One consequence shapes the whole early path: pluggable.php has not been
 * loaded yet, so is_user_logged_in() does not exist. Authentication is decided
 * by reading the cookie names directly, which is also what makes the rule
 * conservative by design.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Engine {

	/** @var int Shortest page worth storing. Below this it is an error page. */
	const MIN_LENGTH = 255;

	/** @var Core_Diet_Cache_Settings */
	private $settings;

	/** @var string|false Directory the current request will be written to. */
	private $target_dir = false;

	/** @var bool Whether the buffer is ours and still open. */
	private $capturing = false;

	/** @var array Cookie names exactly as the browser sent them. */
	private $cookie_names = array();

	/**
	 * Constructor.
	 *
	 * @param Core_Diet_Cache_Settings $settings Module settings.
	 */
	public function __construct( Core_Diet_Cache_Settings $settings ) {
		$this->settings = $settings;

		/*
		 * The cookie names are photographed here, while the plugin file is
		 * still being included, because $_COOKIE is not read-only in practice:
		 * WooCommerce rewrites and deletes its own entries on init through
		 * wc_setcookie(), and multilingual plugins do the same with their
		 * language cookie. By template_redirect the array no longer describes
		 * what the browser sent, so a visitor who arrived with a cart cookie
		 * would look anonymous and their page would be stored for everyone.
		 * Verified against WooCommerce 11.0.1.
		 */
		$this->cookie_names = ( isset( $_COOKIE ) && is_array( $_COOKIE ) ) ? array_keys( $_COOKIE ) : array();
	}

	/**
	 * Register the front-end hooks.
	 */
	public function init() {
		add_action( 'plugins_loaded', array( $this, 'maybe_serve' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_start_capture' ), 2 );
	}

	/**
	 * Serve a cached page and stop, when there is one to serve.
	 */
	public function maybe_serve() {
		if ( '' !== $this->get_request_bypass_reason() ) {
			return;
		}

		// A query string that is nothing but tracking parameters is answered
		// with the plain URL's copy; anything else is a different page.
		if ( '' !== $this->get_query_bypass_reason() ) {
			return;
		}

		// A forced reload asks for a fresh copy. Honouring it means "clear your
		// browser cache and reload" is real advice instead of a shot in the
		// dark, and the page is rebuilt and re-stored on the way out.
		if ( $this->is_reload_forced() ) {
			return;
		}

		$dir = Core_Diet_Cache_Store::dir_for_request();
		if ( ! $dir ) {
			return;
		}

		$is_https  = $this->is_https();
		$is_mobile = $this->settings->is_enabled( 'separate_mobile' ) && wp_is_mobile();
		$slash     = $this->has_trailing_slash();

		$plain = $dir . '/' . Core_Diet_Cache_Store::filename( $is_https, $slash, $is_mobile, false );
		if ( ! is_readable( $plain ) ) {
			return;
		}

		$mtime = filemtime( $plain );
		if ( ! $mtime ) {
			return;
		}

		$ttl = $this->settings->get_ttl();
		if ( $ttl > 0 && ( time() - $mtime ) > $ttl ) {
			return;
		}

		$this->send_cached( $plain, $mtime );
	}

	/**
	 * Emit a cached file and end the request.
	 *
	 * @param string $file  Absolute path of the plain HTML file.
	 * @param int    $mtime Its modification time.
	 */
	private function send_cached( $file, $mtime ) {
		$gzip     = false;
		$gz_file  = $file . '.gz';
		$compress = $this->settings->is_enabled( 'precompress_gzip' );

		if ( $compress && is_readable( $gz_file ) && $this->client_accepts_gzip() && $this->disable_php_compression() ) {
			$gzip = true;
		}

		$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

		header( 'X-DietPress-Cache: HIT' );
		header( 'Last-Modified: ' . $last_modified );

		// The HTML must not linger in the browser on top of the disk copy, or a
		// purge here would change nothing for a visitor who already has it. The
		// cache directory has its own .htaccess so the max-age of the DietPress
		// block never applies to these files.
		header( 'Cache-Control: max-age=0, must-revalidate' );

		if ( $compress ) {
			header( 'Vary: Accept-Encoding' );
		}

		if ( $this->client_has_current_copy( $mtime ) ) {
			status_header( 304 );
			exit;
		}

		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );

		$path = $gzip ? $gz_file : $file;

		if ( $gzip ) {
			header( 'Content-Encoding: gzip' );
		}

		$size = filesize( $path );
		if ( $size ) {
			header( 'Content-Length: ' . $size );
		}

		/*
		 * Streamed, never echoed. The fallback used to be
		 * `echo file_get_contents()`, which needs an EscapeOutput suppression,
		 * and a suppression on a security sniff is a rejection trigger in the
		 * wordpress.org review no matter how sound the justification. Both
		 * functions here write straight to the output stream, so there is no
		 * echo of a variable to escape in the first place. Some hosts disable
		 * one or the other; if neither is available the request simply falls
		 * through and WordPress builds the page as usual.
		 */
		if ( function_exists( 'readfile' ) ) {
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Streaming the plugin's own cache file on an anonymous request; WP_Filesystem would read the whole page into memory and needs credentials that do not exist here.
			exit;
		}

		if ( function_exists( 'fpassthru' ) ) {
			$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- See above.
			if ( $handle ) {
				fpassthru( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- See above.
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- See above.
				exit;
			}
		}

		// Nothing could stream the file: let the request carry on and be built
		// normally rather than answering with an empty 200.
		header_remove( 'Content-Length' );
		header_remove( 'Content-Encoding' );
		header( 'X-DietPress-Cache: MISS' );
	}

	/**
	 * Open the output buffer that will store this page.
	 */
	public function maybe_start_capture() {
		if ( '' !== $this->get_request_bypass_reason() ) {
			return;
		}
		if ( '' !== $this->get_query_bypass_reason() ) {
			return;
		}
		if ( '' !== $this->get_query_object_bypass_reason() ) {
			return;
		}

		$dir = Core_Diet_Cache_Store::dir_for_request();
		if ( ! $dir ) {
			return;
		}

		$this->target_dir = $dir;
		$this->capturing  = true;

		if ( ! headers_sent() ) {
			header( 'X-DietPress-Cache: MISS' );
		}

		ob_start( array( $this, 'capture' ) );
	}

	/**
	 * Output buffer callback: store the page, then return it untouched.
	 *
	 * Runs at shutdown, which is the only moment the response status, the
	 * headers and the full HTML are all known.
	 *
	 * @param string $buffer Rendered page.
	 * @return string
	 */
	public function capture( $buffer ) {
		$this->capturing = false;

		try {
			$reason = $this->get_output_bypass_reason( $buffer );

			if ( '' === $reason ) {
				$stamp   = '<!-- Page cached by DietPress on ' . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
				$stored  = $buffer . $stamp;
				$is_gz   = false;
				$slash   = $this->has_trailing_slash();
				$mobile  = $this->settings->is_enabled( 'separate_mobile' ) && wp_is_mobile();
				$https   = $this->is_https();
				$plain   = Core_Diet_Cache_Store::filename( $https, $slash, $mobile, false );

				if ( Core_Diet_Cache_Store::write( $this->target_dir, $plain, $stored ) ) {
					if ( $this->settings->is_enabled( 'precompress_gzip' ) && function_exists( 'gzencode' ) ) {
						$gz = gzencode( $stored, 6 );
						if ( false !== $gz ) {
							$is_gz = Core_Diet_Cache_Store::write(
								$this->target_dir,
								Core_Diet_Cache_Store::filename( $https, $slash, $mobile, true ),
								$gz
							);
						}
					}

					// A stale gzip twin next to a fresh HTML file would be
					// served to every client that accepts gzip, which is all of
					// them. Better none than wrong.
					if ( ! $is_gz ) {
						$leftover = $this->target_dir . '/' . Core_Diet_Cache_Store::filename( $https, $slash, $mobile, true );
						if ( file_exists( $leftover ) ) {
							wp_delete_file( $leftover );
						}
					}
				}
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// Only with debugging on: in production this would be a hint to
				// anyone reading the source about how the site is configured.
				return $buffer . "\n<!-- DietPress page cache: not stored (" . esc_html( $reason ) . ") -->\n";
			}
		} catch ( Exception $e ) {
			// A cache that cannot store must never break the page it failed to
			// store. The buffer is returned untouched either way.
			return $buffer;
		}

		return $buffer;
	}

	/**
	 * Why this request must not touch the cache at all, if it must not.
	 *
	 * Everything here is answerable before WordPress is loaded, so it is used
	 * both by the early serve path and by the capture path.
	 *
	 * @return string Empty when the request is cacheable.
	 */
	public function get_request_bypass_reason() {
		if ( defined( 'DIETPRESS_DISABLE_CACHE' ) && DIETPRESS_DISABLE_CACHE ) {
			return 'disabled by constant';
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'DONOTCACHEPAGE';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI';
		}
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return 'cron or ajax';
		}
		if ( is_admin() ) {
			return 'admin request';
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return 'not a GET request';
		}

		// Basic auth means a staging site or a protected area; either way the
		// response is not the public one.
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return 'HTTP authentication';
		}

		if ( Core_Diet_Cache_Store::get_request_host() !== Core_Diet_Cache_Store::get_home_host() ) {
			return 'request host is not the site host';
		}

		$cookie = $this->get_bypass_cookie();
		if ( '' !== $cookie ) {
			return 'cookie ' . $cookie;
		}

		$path = $this->get_request_path();

		if ( $this->is_excluded_path( $path ) ) {
			return 'excluded URL';
		}

		foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json/', '/wp-comments-post.php', '/wp-signup.php', '/wp-activate.php', '/wp-trackback.php' ) as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return 'reserved path';
			}
		}

		// Feeds, sitemaps and robots.txt are either XML or tiny, and caching
		// them is how a cache directory grows without anybody noticing.
		if ( preg_match( '#/(feed|embed)/?$#', $path ) || preg_match( '#\.(php|xml|txt|xsl)$#i', $path ) ) {
			return 'not an HTML page';
		}

		return '';
	}

	/**
	 * Why the query string makes this request uncacheable, if it does.
	 *
	 * Checked apart from the rest because the early serve path has to answer it
	 * too, but only after it knows the request is otherwise cacheable.
	 *
	 * @return string Empty when the query string is harmless.
	 */
	public function get_query_bypass_reason() {
		$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		if ( '' === $query ) {
			return '';
		}

		$parsed = array();
		parse_str( $query, $parsed );
		if ( ! $parsed ) {
			return '';
		}

		$ignored = $this->settings->get_ignored_params();

		foreach ( array_keys( $parsed ) as $key ) {
			if ( ! in_array( (string) $key, $ignored, true ) ) {
				return 'query parameter ' . $key;
			}
		}

		return '';
	}

	/**
	 * Why the rendered page must not be stored, if it must not.
	 *
	 * @param string $buffer Rendered page.
	 * @return string Empty when the page is storable.
	 */
	private function get_output_bypass_reason( $buffer ) {
		if ( ! $this->target_dir ) {
			return 'no target directory';
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			// WooCommerce defines this on wp_headers priority 5 for the cart,
			// the checkout and my account, and any plugin can define it later,
			// so it is re-read here and not only at the start of the request.
			return 'DONOTCACHEPAGE';
		}

		$status = http_response_code();
		if ( 200 !== $status && false !== $status ) {
			return 'HTTP ' . $status;
		}

		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return 'logged in user';
		}

		// A response that sets a cookie is personalised almost by definition:
		// storing it hands that cookie's page to the next visitor.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'set-cookie:' ) ) {
				return 'response sets a cookie';
			}
		}

		if ( strlen( $buffer ) < self::MIN_LENGTH ) {
			return 'response too short';
		}

		// A truncated page, a fatal error mid-render or a JSON/XML body all fail
		// this one check.
		if ( false === stripos( substr( $buffer, -1024 ), '</html>' ) ) {
			return 'not a complete HTML document';
		}

		/**
		 * Filter whether the current page is kept out of the cache.
		 *
		 * @param bool $bypass Whether to skip storing this page.
		 */
		if ( apply_filters( 'dietpress_cache_bypass', false ) ) {
			return 'dietpress_cache_bypass filter';
		}

		return '';
	}

	/**
	 * Conditions only WordPress can answer, checked when the template loads.
	 *
	 * @return string Empty when the query is cacheable.
	 */
	public function get_query_object_bypass_reason() {
		if ( is_user_logged_in() ) {
			return 'logged in user';
		}
		if ( is_404() ) {
			// The defence against a crawler filling the disk with one directory
			// per invented URL.
			return '404';
		}
		if ( is_search() || is_feed() || is_trackback() || is_robots() || is_preview() || is_customize_preview() ) {
			return 'not a public page';
		}
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return 'embed';
		}
		if ( function_exists( 'is_sitemap' ) && is_sitemap() ) {
			return 'sitemap';
		}
		if ( post_password_required() ) {
			return 'password protected';
		}

		// A store that has not opened yet renders a placeholder on every URL.
		// Caching it means the placeholder outlives the launch.
		if ( 'yes' === get_option( 'woocommerce_coming_soon' ) ) {
			return 'WooCommerce coming soon mode';
		}

		return '';
	}

	/**
	 * The name of the first cookie that makes this visitor non-anonymous.
	 *
	 * Matched by prefix against an explicit list. A blanket "any unknown cookie
	 * bypasses" rule is the reason so many sites never cache anything at all:
	 * one consent banner or analytics script sets a cookie for every visitor
	 * and the cache is dead without a word of explanation.
	 *
	 * @return string Cookie name, or empty string.
	 */
	private function get_bypass_cookie() {
		if ( ! $this->cookie_names ) {
			return '';
		}

		$prefixes = array(
			'wordpress_logged_in',       // Authenticated session.
			'wp-postpass_',              // Unlocked a password protected post.
			'comment_author_',           // Left a comment; the page shows it back.
			'woocommerce_',              // Cart, session and store notices.
			'wp_woocommerce_session_',
			'wcml_client_currency',      // Multi currency: same URL, other prices.
			'edd_items_in_cart',
			'wp-resetpass-',
		);

		/**
		 * Filter the cookie name prefixes that keep a visitor out of the cache.
		 *
		 * @param array $prefixes Cookie name prefixes.
		 */
		$prefixes = apply_filters( 'dietpress_cache_bypass_cookies', $prefixes );

		foreach ( $this->cookie_names as $name ) {
			$name = (string) $name;
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return $prefix;
				}
			}
		}

		return '';
	}

	/**
	 * Whether the request path matches one of the site's exclusion patterns.
	 *
	 * @param string $path Request path.
	 * @return bool
	 */
	private function is_excluded_path( $path ) {
		foreach ( $this->settings->get_exclude_patterns() as $pattern ) {
			// fnmatch() is not everywhere (it is missing on Windows builds), so
			// the wildcard is translated into a regular expression instead.
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			if ( preg_match( $regex, $path ) || preg_match( $regex, untrailingslashit( $path ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The current request path, without the query string.
	 *
	 * @return string
	 */
	private function get_request_path() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		// sanitize_url() and not sanitize_text_field(), for the reason spelled
		// out in Core_Diet_Cache_Store::dir_for_request(). Decoded on the way
		// out so the exclusion patterns compare against the same spelling the
		// site owner typed, and so "/wp-%61dmin/" cannot walk past the reserved
		// path check below.
		$uri   = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$parts = explode( '?', $uri, 2 );

		return '' === $parts[0] ? '/' : rawurldecode( $parts[0] );
	}

	/**
	 * Whether the request path ends with a slash.
	 *
	 * @return bool
	 */
	private function has_trailing_slash() {
		$path = $this->get_request_path();
		return '/' === substr( $path, -1 );
	}

	/**
	 * Whether the request arrived over HTTPS, proxies included.
	 *
	 * @return bool
	 */
	private function is_https() {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTPS'] ) ) ) ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
			if ( 'https' === $proto ) {
				return true;
			}
		}
		if ( ! isset( $_SERVER['SERVER_PORT'] ) ) {
			return false;
		}

		return '443' === sanitize_text_field( wp_unslash( $_SERVER['SERVER_PORT'] ) );
	}

	/**
	 * Whether the visitor asked the browser for a fresh copy.
	 *
	 * @return bool
	 */
	private function is_reload_forced() {
		if ( isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ) {
			$value = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CACHE_CONTROL'] ) ) );
			if ( false !== strpos( $value, 'no-cache' ) || false !== strpos( $value, 'no-store' ) || false !== strpos( $value, 'max-age=0' ) ) {
				return true;
			}
		}
		if ( isset( $_SERVER['HTTP_PRAGMA'] ) ) {
			$value = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_PRAGMA'] ) ) );
			if ( false !== strpos( $value, 'no-cache' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the client already holds this exact copy.
	 *
	 * @param int $mtime Modification time of the cached file.
	 * @return bool
	 */
	private function client_has_current_copy( $mtime ) {
		if ( empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			return false;
		}
		$since = strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) );
		return $since && $since >= $mtime;
	}

	/**
	 * Whether the client accepts gzip.
	 *
	 * @return bool
	 */
	private function client_accepts_gzip() {
		if ( empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
			return false;
		}
		$accepted = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) );
		return false !== strpos( $accepted, 'gzip' );
	}

	/**
	 * Make sure PHP will not compress an already compressed payload.
	 *
	 * Serving the .gz twin while zlib.output_compression is on produces a
	 * double-gzipped body, which every browser renders as a wall of binary. If
	 * it cannot be turned off for this request, the plain file is served.
	 *
	 * @return bool True when it is safe to send Content-Encoding: gzip.
	 */
	private function disable_php_compression() {
		if ( in_array( 'ob_gzhandler', ob_list_handlers(), true ) ) {
			return false;
		}

		if ( ! ini_get( 'zlib.output_compression' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- The only way to stop PHP from gzipping an already gzipped file. It is read back below rather than trusted, and hosts that forbid ini_set simply get the uncompressed copy.
		@ini_set( 'zlib.output_compression', 'Off' );

		return ! ini_get( 'zlib.output_compression' );
	}
}
