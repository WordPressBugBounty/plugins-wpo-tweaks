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

	/** @var string Prefix of the transients holding the token of each self test in progress. */
	const DIAGNOSE_TRANSIENT = 'core_diet_cache_diagnose';

	/** @var string Option noting when a page was kept out for being built for a phone. */
	const MOBILE_MARKUP_OPTION = 'core_diet_cache_mobile_markup';

	/** @var string|null Request URI as it arrived, sanitized, photographed when the plugin loads. */
	private $request_uri = null;

	/** @var string Query string as it arrived, photographed with the URI. */
	private $query_string = '';

	/** @var string|false|null Cache directory of the request, fixed at the lookup. */
	private $lookup_dir = null;

	/** @var bool Whether the request path ends with a slash, fixed at the lookup. */
	private $lookup_slash = true;

	/** @var bool Whether the request gets the mobile copy, fixed at the lookup. */
	private $lookup_mobile = false;

	/** @var bool Whether this request is the self test and may be told why it was skipped. */
	private $diagnose = false;

	/** @var bool Whether WordPress answered "mobile" to anybody during this request. */
	private $built_for_mobile = false;

	/** @var bool Whether the page was kept out only for being built for a phone. */
	private $mobile_skipped = false;

	/** @var bool Whether the buffer was flushed or cleaned before the end of the page. */
	private $interrupted = false;

	/** @var int Lifetime in seconds, read before the capture. */
	private $ttl = 0;

	/** @var bool Whether to store the gzip twin, read before the capture. */
	private $compress = false;

	/** @var int|null Server clock offset seen through the accelerator rules. */
	private $clock_offset = null;

	/** @var string Rules for the cache root, should the capture have to create it. */
	private $hardening = '';

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

		/*
		 * The address is photographed at the same moment and for a similar
		 * reason. Code that rewrites REQUEST_URI during the request, such as a
		 * language plugin that routes /en/about/ to the page of /about/, used to
		 * make the capture file the page under the rewritten address, so the
		 * English page ended up served at the Spanish URL. The copy belongs to
		 * the address that was asked for, which is also the one the server
		 * sees when the accelerator looks it up.
		 */
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$this->request_uri = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$this->query_string = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
		}
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

		/*
		 * The rest of the key is fixed here too, for the same reason: the copy
		 * a request stores has to be the copy it looked up. The directory, the
		 * trailing slash and the mobile variant used to be worked out again at
		 * the end of the request, so code that rewrote REQUEST_URI or filtered
		 * wp_is_mobile() in between filed the page under the key of another one.
		 */
		$this->lookup_dir    = null === $this->request_uri ? false : Core_Diet_Cache_Store::dir_for_uri( $this->request_uri );
		$this->lookup_slash  = $this->has_trailing_slash();
		$separate_mobile     = $this->settings->is_enabled( 'separate_mobile' );
		$this->lookup_mobile = $separate_mobile && wp_is_mobile();
		$this->diagnose      = self::is_diagnostic_request();

		/*
		 * Without a separate mobile cache, one copy serves every device, so it
		 * has to be the one built for a desktop. A theme that asks
		 * wp_is_mobile() while building the page sends phones different HTML
		 * (Astra adds its mobile header class to the body, for one), and a phone
		 * that happened to be the first visitor used to set that version for
		 * everybody. The answer is watched for the whole request and the page is
		 * not stored when it was "mobile".
		 */
		if ( ! $separate_mobile ) {
			add_filter( 'wp_is_mobile', array( $this, 'watch_mobile' ), PHP_INT_MAX );
		}

		if ( $this->diagnose && ! headers_sent() ) {
			// Never a name another header of the plugin starts with: replacing a
			// header in PHP 8.5.3 also removes every header whose name starts with
			// the one replaced, so X-DietPress-Cache-Clock vanished with the
			// X-DietPress-Cache sent after it.
			header( 'X-DietPress-Clock: ' . ( null === Core_Diet_Cache_Accelerator::server_clock_offset() ? 'missing' : 'seen' ) );

			// The probe of this request of the test, back. A page built or served
			// for it carries it; a copy of an earlier visit that a cache in front
			// of the site hands out cannot, and that is how the test tells them
			// apart (Core_Diet_Cache_Self_Test::answered_in_front()).
			$probe = self::diagnostic_probe();
			if ( '' !== $probe ) {
				header( 'X-DietPress-Probe: ' . $probe );
			}
		}

		$reason = $this->get_request_bypass_reason();
		if ( '' !== $reason ) {
			$this->explain( $reason );
			return;
		}

		// A query string that is nothing but tracking parameters is answered
		// with the plain URL's copy; anything else is a different page.
		$reason = $this->get_query_bypass_reason();
		if ( '' !== $reason ) {
			$this->explain( $reason );
			return;
		}

		// A forced reload asks for a fresh copy. Honouring it means "clear your
		// browser cache and reload" is real advice instead of a shot in the
		// dark, and the page is rebuilt and re-stored on the way out.
		if ( $this->is_reload_forced() ) {
			return;
		}

		$dir = $this->lookup_dir;
		if ( ! $dir ) {
			$this->explain( 'address cannot be stored on disk' );
			return;
		}

		$plain = $dir . '/' . Core_Diet_Cache_Store::filename( $this->lookup_https, $this->lookup_slash, $this->lookup_mobile, false );
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

		// Served by PHP on a request that went through the accelerator rules
		// means the server found no name for this hour: put them back, so the
		// next visit is served without PHP. The copy built for a phone has its
		// own names when the separate mobile cache is on.
		Core_Diet_Cache_Accelerator::maybe_renew_hour_names( $dir, $this->lookup_https, $this->lookup_slash, $this->lookup_mobile, $plain, $mtime, $ttl, $this->diagnose );

		$this->send_cached( $plain, $mtime );
	}

	/**
	 * Remember whether WordPress called this request mobile.
	 *
	 * @param bool $is_mobile Final answer of wp_is_mobile().
	 * @return bool The same answer.
	 */
	public function watch_mobile( $is_mobile ) {
		if ( $is_mobile ) {
			$this->built_for_mobile = true;
		}

		return $is_mobile;
	}

	/**
	 * Whether this request is the self test of this site.
	 *
	 * Only a request carrying the token the test just stored may be told why it
	 * was not served from the cache. Anyone else gets the page, as always.
	 *
	 * @return bool
	 */
	private static function is_diagnostic_request() {
		if ( empty( $_SERVER['HTTP_X_DIETPRESS_DIAGNOSE'] ) ) {
			return false;
		}

		$sent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DIETPRESS_DIAGNOSE'] ) );

		// Only something shaped like a token is looked up: the transient is
		// named after it, and anything else is not one.
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32,64}$/D', $sent ) ) {
			return false;
		}

		$token = get_transient( self::diagnose_key( $sent ) );

		return is_string( $token ) && hash_equals( $token, $sent );
	}

	/**
	 * Name of the transient that holds the token of one self test.
	 *
	 * One per test, named after its token. With a single transient, two tests at
	 * the same time (two administrators, or a save that tests the accelerator
	 * while someone presses the button) replaced and deleted each other's token,
	 * and the requests of the first one stopped being recognised: its bypass
	 * reasons went missing, and since the probe is only echoed to the test, the
	 * test read its own answer as a copy kept by a cache in front of the site
	 * (found by the cross review of 3.7.1, 25 sep 2026).
	 *
	 * @param string $token Token of the test.
	 * @return string
	 */
	public static function diagnose_key( $token ) {
		return self::DIAGNOSE_TRANSIENT . '_' . substr( md5( (string) $token ), 0, 12 );
	}

	/**
	 * The probe a request of the self test carries, to be echoed.
	 *
	 * Only read for a request that already proved it is the test, and only
	 * letters and digits, so what goes back is never more than the test sent.
	 *
	 * @return string Probe, or empty.
	 */
	private static function diagnostic_probe() {
		if ( empty( $_SERVER['HTTP_X_DIETPRESS_PROBE'] ) ) {
			return '';
		}

		$probe = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_DIETPRESS_PROBE'] ) );

		return 1 === preg_match( '/^[A-Za-z0-9]{8,64}$/D', $probe ) ? $probe : '';
	}

	/**
	 * Tell the self test why this request is left out of the cache.
	 *
	 * The reasons are literals of this class, except for the name of a query
	 * parameter, and the header is only sent to the request that carries the
	 * token, but it is reduced to plain characters all the same.
	 *
	 * @param string $reason Reason.
	 */
	private function explain( $reason ) {
		if ( ! $this->diagnose || headers_sent() ) {
			return;
		}

		$reason = substr( (string) preg_replace( '#[^A-Za-z0-9 _.:/()=-]#', '', (string) $reason ), 0, 200 );

		header( 'X-DietPress-Bypass: ' . $reason );
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

		// The same Cache-Control a page WordPress builds gets, and the same the
		// server sends when the accelerator serves this copy, so a URL does not
		// change how long browsers keep it depending on who answered. Up to
		// 3.5.6 a hit always sent max-age=0, whatever the site had chosen for
		// its pages. With that setting at its default, it still does: the HTML
		// must not linger in the browser on top of the disk copy, or a purge
		// here would change nothing for a visitor who already has it.
		header( 'Cache-Control: ' . Core_Diet_Cache_Accelerator::hit_cache_control() );

		$vary = array();
		if ( $compress ) {
			$vary[] = 'Accept-Encoding';
		}
		// With a separate mobile cache the same address has a phone copy and a
		// desktop one, and whatever caches in front (a CDN, a proxy) has to know,
		// or it hands one of them to everybody. The server rules say the same.
		if ( $this->settings->is_enabled( 'separate_mobile' ) ) {
			$vary[] = 'Sec-CH-UA-Mobile';
			$vary[] = 'User-Agent';
		}
		if ( $vary ) {
			header( 'Vary: ' . implode( ', ', $vary ) );
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
			$this->explain( $reason );
			return;
		}
		$reason = $this->get_query_bypass_reason();
		if ( '' !== $reason ) {
			$this->explain( $reason );
			return;
		}
		$reason = $this->get_query_object_bypass_reason();
		if ( '' !== $reason ) {
			$this->explain( $reason );
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

		// The key the lookup fixed. Without a lookup there is none to reuse,
		// and the capture refuses to store anyway (see
		// get_output_bypass_reason()), so this only keeps the buffer and the
		// reason it gives the same as before.
		$dir = null === $this->lookup_dir && null !== $this->request_uri ? Core_Diet_Cache_Store::dir_for_uri( $this->request_uri ) : $this->lookup_dir;
		if ( ! $dir ) {
			$this->explain( 'address cannot be stored on disk' );
			return;
		}

		/*
		 * Everything the capture needs from the options is read now: it runs
		 * inside an output buffer handler, where a hook on an option that opens
		 * a buffer of its own, or a wp_die() on PHP 7.4, takes the page down.
		 * The rules for the cache root follow the accelerator rules of this very
		 * request, so a root deleted by hand comes back open to the server
		 * exactly when the server is serving from it.
		 */
		$this->ttl          = $this->settings->get_ttl();
		$this->compress     = $this->settings->is_enabled( 'precompress_gzip' ) && function_exists( 'gzencode' );
		$this->clock_offset = Core_Diet_Cache_Accelerator::may_name_copies( $this->diagnose ) ? Core_Diet_Cache_Accelerator::server_clock_offset() : null;
		$this->hardening    = null === $this->clock_offset
			? Core_Diet_Cache_Store::DENY_RULES
			: Core_Diet_Cache_Accelerator::get_cache_dir_rules( true, Core_Diet_Cache_Accelerator::hit_cache_control(), (string) get_bloginfo( 'charset' ), $this->settings->is_enabled( 'separate_mobile' ) );

		$this->target_dir = $dir;
		$this->capturing  = true;

		if ( ! headers_sent() ) {
			header( 'X-DietPress-Cache: MISS' );

			/*
			 * A page this cache is about to store goes out telling the caches in
			 * front not to keep it, unless something already said how long it
			 * may be kept. Since 3.7.0 a page WordPress builds says nothing by
			 * default, which is what lets the cache of a hosting store pages
			 * while this one is off. With this one on, that silence let the
			 * hosting cache keep the first build of each page and hand it out
			 * ahead of this cache: purging here stopped reaching visitors, and
			 * the self test read that copy as its own and blamed the cache
			 * folder (SiteGround, aulawp.com, 25 sep 2026). A hit already said
			 * max-age=0, so only the first build was being kept.
			 *
			 * Always this value and never the lifetime of hit_cache_control().
			 * A lifetime chosen for HTML is sent by set_html_cache_control()
			 * before this runs, so it is already there; and where that method
			 * stayed out on purpose (a page marked personal through its filter)
			 * a public lifetime must not come in through here, on a response
			 * that may still set a cookie before it ends (cross review of
			 * 3.7.1).
			 */
			if ( ! self::cache_control_sent() ) {
				header( 'Cache-Control: max-age=0, must-revalidate' );
			}
		}

		// After wp_ob_end_flush_all(), which flushes the buffers on shutdown
		// priority 1: by then the capture has run and a database write is safe.
		add_action( 'shutdown', array( $this, 'after_capture' ), 20 );

		ob_start( array( $this, 'capture' ) );
	}

	/**
	 * Leave the notes the capture itself may not write.
	 *
	 * A page kept out only for being built for a phone is noted at most once a
	 * day, so the Cache tab can suggest the separate mobile cache to a site
	 * whose theme needs it.
	 */
	public function after_capture() {
		if ( ! $this->mobile_skipped ) {
			return;
		}

		$last = (int) get_option( self::MOBILE_MARKUP_OPTION, 0 );

		if ( time() - $last > DAY_IN_SECONDS ) {
			update_option( self::MOBILE_MARKUP_OPTION, time(), false );
		}
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
	 * @param int    $phase  Bitmask of PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public function capture( $buffer, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		$phase = (int) $phase;

		/*
		 * An ob_flush() or ob_clean() in the middle of the page calls this
		 * handler before the end, and what arrives at the end is then only the
		 * rest of the page, which has a closing </html> and passes every check.
		 * Up to 3.5.6 that tail was stored as the page. A buffer closed with
		 * ob_end_clean() arrives here once, with the page the visitor never got.
		 */
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			$this->interrupted = true;
			return $buffer;
		}
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			$this->interrupted = true;
		}

		$this->capturing = false;

		try {
			$reason = $this->get_output_bypass_reason( $buffer );

			if ( '' === $reason ) {
				$stamp  = '<!-- Page cached by DietPress on ' . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n";
				$stored = $buffer . $stamp;
				$slash  = $this->lookup_slash;
				$mobile = $this->lookup_mobile;
				$https  = (bool) $this->lookup_https;
				$plain  = Core_Diet_Cache_Store::filename( $https, $slash, $mobile, false );

				// The gzip twin of the previous version goes before the new
				// page is written, not after: a request that dies in between
				// leaves a page without a twin, which is served plain, instead
				// of a fresh page next to the old twin, which every client that
				// accepts gzip would get.
				$leftover = $this->target_dir . '/' . Core_Diet_Cache_Store::filename( $https, $slash, $mobile, true );
				if ( file_exists( $leftover ) ) {
					wp_delete_file( $leftover );
				}

				if ( Core_Diet_Cache_Store::write( $this->target_dir, $plain, $stored, $this->hardening ) ) {
					if ( $this->compress ) {
						$gz = gzencode( $stored, 6 );
						if ( false !== $gz ) {
							Core_Diet_Cache_Store::write(
								$this->target_dir,
								Core_Diet_Cache_Store::filename( $https, $slash, $mobile, true ),
								$gz,
								$this->hardening
							);
						}
					}

					Core_Diet_Cache_Accelerator::link_hour_names(
						$this->target_dir,
						$https,
						$slash,
						$mobile,
						$this->target_dir . '/' . $plain,
						$this->ttl > 0 ? time() + $this->ttl : null,
						$this->clock_offset
					);
				}
			} elseif ( $this->diagnose || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				// Only with debugging on, or for the self test: in production
				// this would be a hint to anyone reading the source about how
				// the site is configured.
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

		/*
		 * The port counts as much as the host. Apache takes SERVER_PORT from the
		 * Host header, so a plain HTTP request for "site:443" makes is_ssl() true
		 * with no proxy header anywhere, and a theme that prints HTTP_HOST puts
		 * that port in every link. WordPress would redirect it to its canonical
		 * address (wp-includes/canonical.php:612-617), but not with canonical
		 * redirects switched off, and the copy would reach everybody.
		 */
		if ( Core_Diet_Cache_Store::get_request_port() !== Core_Diet_Cache_Store::get_home_port() ) {
			return 'request port is not the site port';
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

		$types = self::get_bypass_accept_types();
		if ( ! $types ) {
			return '';
		}

		$accept = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) );

		foreach ( $types as $type ) {
			if ( false !== strpos( $accept, $type ) ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Media types that keep a request out of the page cache, lowercased.
	 *
	 * Public because the accelerator writes the same list into its rules.
	 *
	 * @return string[]
	 */
	public static function get_bypass_accept_types() {
		/**
		 * Filter the media types that keep a request out of the page cache.
		 *
		 * A plugin that answers a post URL with something other than its HTML
		 * through content negotiation adds its media type here. With the
		 * accelerator on, the list is written into the server rules, so a type
		 * added only on some requests will not reach them.
		 *
		 * @param array $types Media types matched against the Accept header.
		 */
		$types = apply_filters( 'dietpress_cache_bypass_accept', array( 'text/markdown' ) );
		if ( ! is_array( $types ) ) {
			return array();
		}

		$out = array();
		foreach ( $types as $type ) {
			$type = strtolower( trim( (string) $type ) );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}

		return array_values( array_unique( $out ) );
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
		$query = $this->query_string;
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
	 * Whether this response already carries a Cache-Control header.
	 *
	 * @return bool
	 */
	private static function cache_control_sent() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'cache-control:' ) ) {
				return true;
			}
		}

		return false;
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
		if ( $this->interrupted ) {
			return 'output flushed or cleaned before the end of the page';
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
		// storing it hands that cookie's page to the next visitor. And one that
		// forbids shared caches to keep it, as nocache_headers() does, is not
		// stored either: a hit would go out with the lifetime of the site instead.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'set-cookie:' ) ) {
				// Named, because the name is what tells which plugin sets it, and
				// without it the self test could only say that one does. Only the
				// characters a cookie name normally has, and never a parenthesis,
				// which would end the note the test reads it from.
				$pair = explode( '=', trim( substr( $header, 11 ) ), 2 );
				$name = substr( (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', $pair[0] ), 0, 40 );

				return '' === $name ? 'response sets a cookie' : 'response sets a cookie: ' . $name;
			}
			if ( 0 === stripos( $header, 'cache-control:' ) && preg_match( '/\b(no-store|private)\b/i', $header ) ) {
				return 'response is marked private or no-store';
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

		// The coming soon page of WooCommerce, whatever route led to it. The
		// query check keeps it out already; this catches a page that becomes a
		// store page without anybody telling the cache, in "store pages only"
		// mode. The meta tag itself, as ComingSoonRequestHandler prints it
		// (WooCommerce 11.1), not the bare name: a post that writes about that
		// tag has it escaped, and must still be cached.
		if ( preg_match( '/<meta name=["\']woo-coming-soon-page["\']/', $buffer ) ) {
			return 'WooCommerce coming soon page';
		}

		/**
		 * Filter whether the current page is kept out of the cache.
		 *
		 * @param bool $bypass Whether to skip storing this page.
		 */
		if ( apply_filters( 'dietpress_cache_bypass', false ) ) {
			return 'dietpress_cache_bypass filter';
		}

		if ( $this->built_for_mobile ) {
			$this->mobile_skipped = true;
			return 'built for a mobile device';
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

		foreach ( self::get_bypass_cookie_prefixes() as $prefix ) {
			// PHP turns dots and spaces in a cookie name into underscores when it
			// fills $_COOKIE, so a prefix with them is compared in that form too.
			$as_php = strtr( $prefix, '. ', '__' );

			foreach ( $this->cookie_names as $name ) {
				$name = (string) $name;
				if ( 0 === strpos( $name, $prefix ) || 0 === strpos( $name, $as_php ) ) {
					return $prefix;
				}
			}
		}

		return '';
	}

	/**
	 * Cookie name prefixes that make a visitor non-anonymous.
	 *
	 * Public because the accelerator writes the same list into its rules.
	 *
	 * @return string[]
	 */
	public static function get_bypass_cookie_prefixes() {
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

		// A site can rename its login cookie in wp-config.php, and a visitor who
		// is logged in under that name would otherwise look anonymous to the
		// lookup, which runs before WordPress can say who they are.
		if ( defined( 'LOGGED_IN_COOKIE' ) && is_string( LOGGED_IN_COOKIE ) && '' !== LOGGED_IN_COOKIE && 0 !== strpos( LOGGED_IN_COOKIE, 'wordpress_logged_in' ) ) {
			$prefixes[] = LOGGED_IN_COOKIE;
		}

		/**
		 * Filter the cookie name prefixes that keep a visitor out of the cache.
		 *
		 * With the accelerator on, the list is written into the server rules,
		 * so a prefix added only on some requests will not reach them.
		 *
		 * @param array $prefixes Cookie name prefixes.
		 */
		$prefixes = apply_filters( 'dietpress_cache_bypass_cookies', $prefixes );

		$out = array();
		foreach ( (array) $prefixes as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix ) {
				$out[] = $prefix;
			}
		}

		return array_values( array_unique( $out ) );
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
		if ( null === $this->request_uri ) {
			return '/';
		}

		// sanitize_url() and not sanitize_text_field(), for the reason spelled
		// out in Core_Diet_Cache_Store::dir_for_request(); the constructor
		// photographed it that way. Decoded on the way out so the exclusion
		// patterns compare against the same spelling the site owner typed, and
		// so "/wp-%61dmin/" cannot walk past the reserved path check below.
		$parts = explode( '?', $this->request_uri, 2 );

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
	 * Only no-cache counts, which is what a forced reload sends. Up to 3.5.6
	 * max-age=0 counted too, and browsers send that on an ordinary reload, so
	 * any visitor who pressed reload rebuilt the page and stored it again
	 * (reported by Fernando on aulawp.com: MISS on every reload, HIT on a plain
	 * visit). The accelerator rules honour the same header.
	 *
	 * @return bool
	 */
	private function is_reload_forced() {
		if ( isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ) {
			$value = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CACHE_CONTROL'] ) ) );
			if ( false !== strpos( $value, 'no-cache' ) ) {
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
