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

	/** @var string Bypass reason for proxy headers that disagree with WordPress. */
	const PROXY_MISMATCH = 'proxy headers disagree with the HTTPS WordPress detects';

	/** @var string Storage refusal when HTTPS changed after the lookup. */
	const HTTPS_CHANGED = 'HTTPS changed after the cache lookup';

	/** @var string Option holding when either of the two last happened. */
	const PROXY_MISMATCH_OPTION = 'core_diet_cache_proxy_mismatch';

	/** @var bool|null Whether this request's proxy headers disagreed with WordPress on plugins_loaded. */
	private static $lookup_mismatch = null;

	/** @var bool|null Whether WordPress saw HTTPS when the cache was looked up. */
	private $lookup_https = null;

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
		// Noted before any early return: the capture at the end of the request
		// compares against it, and a forced reload skips the lookup below but
		// still stores the page it rebuilds.
		$this->lookup_https    = $this->is_https();
		self::$lookup_mismatch = self::proxy_contradicts_wordpress();

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

		$is_mobile = $this->settings->is_enabled( 'separate_mobile' ) && wp_is_mobile();
		$slash     = $this->has_trailing_slash();

		$plain = $dir . '/' . Core_Diet_Cache_Store::filename( $this->lookup_https, $slash, $is_mobile, false );
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
		$reason = $this->get_request_bypass_reason();
		if ( '' !== $reason ) {
			// Noted here and not on plugins_loaded: only now is it known that
			// the page is not a 404, a search or a URL with a query string,
			// which a forged header on any of them would otherwise turn into a
			// note for the site owner. What only the response can rule out, a
			// cookie or DONOTCACHEPAGE during the render, is not known yet.
			if ( self::PROXY_MISMATCH === $reason && '' === $this->get_query_bypass_reason() && '' === $this->get_query_object_bypass_reason() ) {
				self::note_proxy_mismatch();
			}
			return;
		}
		if ( '' !== $this->get_query_bypass_reason() ) {
			return;
		}
		if ( '' !== $this->get_query_object_bypass_reason() ) {
			return;
		}

		// HTTPS that changed since the lookup, switched on by a proxy fix that
		// runs on init for instance, means capture() will refuse to store this
		// page, and the site owner deserves the same note as for headers that
		// disagree. Noted here and not there: capture() runs inside an output
		// buffer handler, where a hook on a database write that opens a buffer
		// of its own, or a wp_die() on PHP 7.4, takes the visitor's page down.
		if ( null !== $this->lookup_https && $this->lookup_https !== $this->is_https() ) {
			self::note_proxy_mismatch();
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
	 * headers and the full HTML are all known. Nothing in here may write to
	 * the database: inside an output buffer handler, a hook on that write
	 * that opens a buffer of its own is a fatal error no catch can stop, and
	 * so is the exit of a wp_die() on PHP 7.4, and the visitor gets an empty
	 * page.
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
		} catch ( Throwable $e ) {
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

		/*
		 * A request that asks for something other than HTML is not asking for
		 * the page this cache stores. Markdown for AI agents is the live case:
		 * VigIA and Visibility both answer `Accept: text/markdown` on the
		 * ordinary post URL, from template_redirect, which is far later than
		 * the HIT that has already been sent and ended the request. Without
		 * this the agent silently receives the HTML copy and the negotiation
		 * never happens at all. Reported against 3.5.3.
		 */
		$accept = $this->get_bypass_accept_type();
		if ( '' !== $accept ) {
			return 'Accept: ' . $accept;
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
		// them is how a cache directory grows without anybody noticing. The .md
		// here is the Markdown address a post gets from VigIA or Visibility: the
		// output check rejects it at the end anyway, for not being an HTML
		// document, so recognising it up front only saves opening a buffer and
		// rendering a page that was always going to be thrown away.
		if ( preg_match( '#/(feed|embed)/?$#', $path ) || preg_match( '#\.(php|xml|txt|xsl|md)$#i', $path ) ) {
			return 'not an HTML page';
		}

		/**
		 * Filter whether this request ignores the page cache altogether.
		 *
		 * `dietpress_cache_bypass` decides whether a rendered page is stored,
		 * so it cannot stop a copy that is already on disk from being served.
		 * This one runs before that, on plugins_loaded priority 1, and is the
		 * hook for keeping a request from getting a HIT at all. WordPress and
		 * the plugins are loaded by then, but the main query is not, so it can
		 * read the request and nothing about the queried object.
		 *
		 * @param bool $bypass Whether to ignore the cache for this request.
		 */
		if ( apply_filters( 'dietpress_cache_bypass_request', false ) ) {
			return 'dietpress_cache_bypass_request filter';
		}

		/*
		 * Proxy headers that disagree with the HTTPS WordPress detects leave the
		 * request alone, neither served nor stored. They cannot pick a copy: the
		 * scheme of a copy is the one WordPress detects (is_https()), and a
		 * header the caller chooses may only ever keep a request out. Kept out,
		 * and not given a copy of their own, because such a request can be a
		 * forged one on a site that answers plain HTTP, a visit through a proxy
		 * nobody translates for WordPress, or a visit whose HTTPS is switched on
		 * later in the request, possibly only for trusted proxies, and nothing
		 * at this point tells those apart. Checked last, so that only a request
		 * that would otherwise have been cached leaves the note the Cache tab
		 * reads: a login page or a foreign Host with a forged header does not.
		 */
		if ( self::proxy_contradicts_wordpress() ) {
			return self::PROXY_MISMATCH;
		}

		return '';
	}

	/**
	 * The negotiated media type that takes this request out of the cache.
	 *
	 * Only types answered on the same URL as the HTML page belong here: a
	 * dedicated address like /entry.md is a different cache entry already. No
	 * browser asks for any of them, so ordinary traffic never matches.
	 *
	 * What comes back is the matched entry from the list, never the header it
	 * was found in, so the bypass reason this feeds is a known literal and not
	 * something a visitor chose. The reasons are not printed anywhere today,
	 * and this is what keeps that safe to change.
	 *
	 * @return string Matched media type, or empty string.
	 */
	private function get_bypass_accept_type() {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return '';
		}

		/**
		 * Filter the media types that keep a request out of the page cache.
		 *
		 * A plugin that answers a post URL with something other than its HTML
		 * through content negotiation adds its media type here.
		 *
		 * @param array $types Media types matched against the Accept header.
		 */
		$types = apply_filters( 'dietpress_cache_bypass_accept', array( 'text/markdown' ) );
		if ( ! is_array( $types ) || ! $types ) {
			return '';
		}

		$accept = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) );

		foreach ( $types as $type ) {
			$type = strtolower( trim( (string) $type ) );
			if ( '' !== $type && false !== strpos( $accept, $type ) ) {
				return $type;
			}
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

		// A copy is filed under the scheme the page was built with and looked
		// up under the one seen on plugins_loaded. Code that switches HTTPS on
		// between the two, a proxy fix placed below the line of wp-config.php
		// that loads WordPress for instance, makes them disagree, and storing
		// would hand this page to requests of the other scheme. With no lookup
		// at all there is nothing to compare, so nothing is stored either.
		// Checked last, so that WP_DEBUG only names these reasons for a page
		// nothing else rules out. The note for the Cache tab is left earlier,
		// in maybe_start_capture().
		if ( null === $this->lookup_https ) {
			return 'the cache lookup did not run';
		}
		if ( $this->lookup_https !== $this->is_https() ) {
			return self::HTTPS_CHANGED;
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

		// A store that has not opened yet renders a placeholder instead of the
		// page. Caching it means the placeholder outlives the launch.
		if ( $this->is_woocommerce_coming_soon() ) {
			return 'WooCommerce coming soon mode';
		}

		return '';
	}

	/**
	 * Whether WooCommerce shows its coming soon page instead of this one.
	 *
	 * Asked the way WooCommerce decides it (ComingSoonHelper), because the
	 * option alone says too much: it stays in the database after WooCommerce
	 * is deactivated, where it no longer does anything, and with "store pages
	 * only" every page that is not part of the store stays public. Up to 3.5.5
	 * either case kept the whole site out of the cache without a word.
	 * Logged-in managers, who WooCommerce lets through, never reach this.
	 *
	 * @return bool
	 */
	private function is_woocommerce_coming_soon() {
		if ( 'yes' !== get_option( 'woocommerce_coming_soon' ) || ! class_exists( 'WooCommerce', false ) ) {
			return false;
		}
		if ( 'yes' !== get_option( 'woocommerce_store_pages_only' ) ) {
			return true;
		}

		$helper = 'Automattic\WooCommerce\Admin\WCAdminHelper';
		if ( class_exists( $helper ) && method_exists( $helper, 'is_current_page_store_page' ) ) {
			return (bool) call_user_func( array( $helper, 'is_current_page_store_page' ) );
		}

		// A WooCommerce too old or too new to ask: keep the page out, as before.
		return true;
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
	 * Whether this request gets the HTTPS copy.
	 *
	 * Asks WordPress, never the request. Up to 3.5.5 this also honoured
	 * X-Forwarded-Proto, a header whoever sends the request chooses, while
	 * WordPress builds the page's asset URLs from is_ssl(), which ignores it
	 * (set_url_scheme() in wp-includes/link-template.php). On a site that also
	 * answers plain HTTP, a request carrying that header was built with
	 * http:// assets and filed as the HTTPS copy, and visitors on HTTPS were
	 * then served a page whose styles and scripts the browser blocks. The
	 * reproduction lives in the release checks, not here.
	 *
	 * @return bool
	 */
	private function is_https() {
		return is_ssl();
	}

	/**
	 * Whether this request's proxy headers disagree with the HTTPS WordPress detects.
	 *
	 * Two cases count. Headers that claim HTTPS while WordPress does not
	 * detect it, whatever else they say. And headers that claim plain HTTP
	 * when WordPress only knows about HTTPS from port 443: code that reads the
	 * headers itself to build URLs (TranslatePress does whenever the HTTPS
	 * variable is not set, and never looks at the port) can then build the
	 * page for the other scheme. HTTPS set by the
	 * server variable is final instead: WordPress and the code that asks it
	 * agree whatever the headers say, which keeps a chain of proxies that
	 * mixes values, a CDN in front of a local nginx for instance, cacheable.
	 *
	 * Public and static because the Cache tab asks it about the request that
	 * loads the tab.
	 *
	 * @return bool
	 */
	public static function proxy_contradicts_wordpress() {
		$claims = self::get_proxy_schemes();
		if ( ! $claims ) {
			return false;
		}

		if ( ! is_ssl() ) {
			return in_array( 'https', $claims, true );
		}

		return ! isset( $_SERVER['HTTPS'] ) && in_array( 'http', $claims, true );
	}

	/**
	 * Whether this request's proxy headers disagreed with WordPress on plugins_loaded.
	 *
	 * The Cache tab asks this instead of asking again when it draws itself,
	 * because by then a proxy fix that runs late has switched HTTPS on, and
	 * the request would look fixed while every visit through that proxy is
	 * still kept out.
	 *
	 * @return bool|null Null when the cache lookup did not run on this request.
	 */
	public static function lookup_mismatch() {
		return self::$lookup_mismatch;
	}

	/**
	 * Remember that a visit was kept out of the cache for its proxy headers.
	 *
	 * Written at most once an hour and never autoloaded, so the requests that
	 * trigger it do not all write to the database and the value does not ride
	 * along on every page load; the Cache tab reads it to explain the fix.
	 */
	private static function note_proxy_mismatch() {
		$last = (int) get_option( self::PROXY_MISMATCH_OPTION, 0 );

		if ( time() - $last > HOUR_IN_SECONDS ) {
			update_option( self::PROXY_MISMATCH_OPTION, time(), false );
		}
	}

	/**
	 * The schemes the proxy headers of this request claim.
	 *
	 * The same six headers SSL Insecure Content Fixer knows how to translate
	 * into HTTPS, with the values it accepts as HTTPS. Any other value that is
	 * present counts as plain HTTP rather than as nothing, because code that
	 * reads the headers to build URLs treats it that way, and an unexpected
	 * value is the easiest one to forge. A chain of proxies can send a list in
	 * X-Forwarded-Proto, so every entry counts. Values are read through
	 * sanitize_text_field(), as TranslatePress reads them too, so one made only
	 * of markup counts as absent.
	 *
	 * @return array Unique values among 'http' and 'https'.
	 */
	private static function get_proxy_schemes() {
		$claims = array();

		$read = static function ( $key ) {
			return isset( $_SERVER[ $key ] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) ) : '';
		};

		$proto = $read( 'HTTP_X_FORWARDED_PROTO' );
		if ( '' !== $proto ) {
			foreach ( explode( ',', $proto ) as $entry ) {
				$claims[] = 'https' === trim( $entry ) ? 'https' : 'http';
			}
		}

		foreach ( array( 'HTTP_CLOUDFRONT_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME' ) as $key ) {
			$value = $read( $key );
			if ( '' !== $value ) {
				$claims[] = 'https' === $value ? 'https' : 'http';
			}
		}

		$ssl = $read( 'HTTP_X_FORWARDED_SSL' );
		if ( '' !== $ssl ) {
			$claims[] = ( 'on' === $ssl || '1' === $ssl ) ? 'https' : 'http';
		}

		$visitor = $read( 'HTTP_CF_VISITOR' );
		if ( '' !== $visitor ) {
			$claims[] = false !== strpos( $visitor, 'https' ) ? 'https' : 'http';
		}

		if ( '' !== $read( 'HTTP_X_ARR_SSL' ) ) {
			$claims[] = 'https';
		}

		return array_values( array_unique( $claims ) );
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
