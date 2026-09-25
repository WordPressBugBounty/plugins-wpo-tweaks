<?php
/**
 * Page cache accelerator: the server answers with the stored copy, PHP never starts.
 *
 * The engine serves on plugins_loaded, with WordPress and every plugin already
 * loaded. On a shared host with a store on it that start is most of the time a
 * cached page takes: measured on a client site, 1.07 s at best for a hit
 * against 0.22 s for a static image on the same server. On Apache and LiteSpeed
 * this class writes a block of rewrite rules to the site .htaccess so the
 * server itself hands out the copy, and the engine stays behind it for
 * everything the rules leave alone, and for nginx, which reads no .htaccess.
 *
 * Four decisions shape it, each measured before it was written:
 *
 * - The marker is "Page Cache by DietPress". insert_with_markers() and
 *   extract_from_markers() look for "# BEGIN {marker}" with str_contains()
 *   (wp-admin/includes/misc.php:198 and :92), so a block called "DietPress
 *   Page Cache" would be taken for the "DietPress" block and overwritten the
 *   first time the browser caching rules were saved.
 * - Expiry does not depend on any cleanup. The server cannot tell how old a
 *   file is, and a site whose visits are all served by the server is exactly
 *   the one where WP-Cron runs least, so a copy would outlive its lifetime,
 *   nonces included. Each stored copy is therefore also linked under the name
 *   of every hour it is still valid in (index-https.2026091515.html), and the
 *   rule only looks for the name of the current hour, which it builds from the
 *   server clock. When that name is missing the request falls through to PHP,
 *   which checks the age itself and puts the names back.
 * - [L] with a guard, not [END]. A flag the server does not know is an error
 *   500 on every request, dashboard included, and the self test that would
 *   catch it cannot run on every site. The guard keeps the second pass of the
 *   per directory rewrite from matching again.
 * - HTTPS the way WordPress sees it. %{HTTPS} is off behind a proxy that
 *   terminates TLS, where HTTPS is set by SetEnvIf and only %{ENV:HTTPS} sees
 *   it; reading only the first would hand the plain copy to HTTPS visitors.
 *   The rule mirrors is_ssl() and Core_Diet_Cache_Engine::proxy_contradicts_wordpress().
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Accelerator {

	/** @var string .htaccess marker. Must never contain "DietPress" right after "BEGIN ". */
	const MARKER = 'Page Cache by DietPress';

	/** @var string Environment variable the rules use to hand the server clock to PHP. */
	const CLOCK_VAR = 'DPC_TIME';

	/** @var string Request header the rules repeat that clock in, for servers that keep environment variables from PHP. */
	const CLOCK_HEADER = 'X-DietPress-Server-Time';

	/** @var string Option holding the secret that proves the header comes from the rules. */
	const CLOCK_KEY_OPTION = 'core_diet_cache_clock_key';

	/** @var int Most hour names a copy is linked under. */
	const HOUR_NAMES_MAX = 24;

	/** @var string Option holding the result of the last successful self test. */
	const VERIFIED_OPTION = 'core_diet_cache_accelerator';

	/** @var string Cache folder rules while the accelerator is off; see the store. */
	const DENY_RULES = Core_Diet_Cache_Store::DENY_RULES;

	/** @var string First line of the cache folder rules that let the server serve the hour names. */
	const SERVING_RULES_HEAD = '# DietPress page cache. Only the copies named after an hour may be served, and only by the accelerator.';

	/** @var array|null Outcome of the last enable(), for callers that report it. */
	private static $last_result = null;

	/* ============================
	 * State
	 * ============================ */

	/**
	 * Whether the accelerator is switched on in the settings.
	 *
	 * @return bool
	 */
	public static function is_switched_on() {
		$settings = get_option( Core_Diet_Cache_Settings::OPTION_NAME, array() );

		return is_array( $settings ) && ! empty( $settings['enabled'] ) && ! empty( $settings['accelerator'] );
	}

	/**
	 * Fingerprint of the place the accelerator was tested in.
	 *
	 * @return string
	 */
	private static function fingerprint() {
		// The stored home option, not home_url(): that one takes the scheme of
		// the request, so an HTTPS visit and a plain one would disagree.
		return md5( ABSPATH . '|' . untrailingslashit( (string) get_option( 'home' ) ) . '|' . Core_Diet_Cache_Store::get_root() );
	}

	/**
	 * Whether the accelerator passed its test on this very install.
	 *
	 * A site moved to another server or address keeps its settings and, very
	 * often, its .htaccess, and a server that refuses the rules of the cache
	 * folder turns every page it would serve into an error. So PHP only names
	 * copies after the hour, which is what lets the server serve them at all,
	 * where the test last passed. Read on plugins_loaded and template_redirect,
	 * never inside the capture, from an autoloaded option.
	 *
	 * @return bool
	 */
	public static function is_verified_here() {
		$verified = get_option( self::VERIFIED_OPTION );

		return is_array( $verified ) && ! empty( $verified['time'] ) && self::tested_here( $verified );
	}

	/**
	 * Whether a test result belongs to this install and to this web server.
	 *
	 * The software of the server counts as well as the place: a site moved to
	 * the same path and address on a server of another kind (Apache to
	 * LiteSpeed is the usual upgrade) keeps its options and its .htaccess, and
	 * the new server may refuse the rules of the cache folder, which would turn
	 * every stored page into an error. Only where the request knows its server;
	 * WP-CLI does not, and never serves pages.
	 *
	 * @param array $verified Stored test result.
	 * @return bool
	 */
	private static function tested_here( array $verified ) {
		if ( ! isset( $verified['where'] ) || ! hash_equals( self::fingerprint(), (string) $verified['where'] ) ) {
			return false;
		}

		$software = self::request_software();

		return '' === $software || empty( $verified['software'] ) || $software === (string) $verified['software'];
	}

	/**
	 * The kind of web server this request came through, as PHP was told.
	 *
	 * @return string apache, litespeed, nginx and so on, lowercase, or empty.
	 */
	public static function request_software() {
		if ( empty( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return '';
		}

		$software = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) );

		return (string) preg_replace( '/[^a-z0-9_-].*$/s', '', $software );
	}

	/**
	 * Whether this request may name copies after the hour.
	 *
	 * Only with the accelerator switched on. Where the test passed, any request.
	 * While a test is running on this install,
	 * only the requests of that test, which carry its token: a test that never
	 * finishes (a request killed by the time limit of the host, for instance)
	 * must not leave the server serving copies nobody has checked it can serve.
	 *
	 * @param bool $is_test_request Whether the engine recognised the token of the self test.
	 * @return bool
	 */
	public static function may_name_copies( $is_test_request ) {
		// Switched off, never: a block left behind (by a rollback to a version
		// that does not know it, for instance) must find nothing to serve.
		if ( ! self::is_switched_on() ) {
			return false;
		}

		$verified = get_option( self::VERIFIED_OPTION );

		// Paused by maybe_sync() while the accelerator cannot run: rules that
		// stay where this class cannot reach them (nginx) must find nothing new.
		if ( ! is_array( $verified ) || ! empty( $verified['paused'] ) || ! self::tested_here( $verified ) ) {
			return false;
		}

		return ! empty( $verified['time'] ) || $is_test_request;
	}

	/**
	 * Whether the server should be serving copies right now.
	 *
	 * @return bool
	 */
	public static function should_serve() {
		return self::is_switched_on() && '' === self::get_unavailable_reason();
	}

	/**
	 * The web server that answers this site, as WordPress detected it.
	 *
	 * @return string apache (LiteSpeed included), nginx, or an empty string.
	 */
	public static function server() {
		global $is_apache, $is_nginx;

		if ( ! empty( $is_nginx ) ) {
			return 'nginx';
		}
		if ( ! empty( $is_apache ) ) {
			return 'apache';
		}

		return '';
	}

	/**
	 * Why the accelerator cannot run on this site, if it cannot.
	 *
	 * Every sentence names the cause and what still works, because the page
	 * cache itself keeps working through PHP in all of these cases.
	 *
	 * @param bool $assume_cache_on Leave out the page cache being off, the one
	 *                              reason the Cache tab lifts by itself when its
	 *                              toggle is switched on, so the tab can tell that
	 *                              lock apart from the rest.
	 * @return string Empty when it can run.
	 */
	public static function get_unavailable_reason( $assume_cache_on = false ) {
		if ( is_multisite() ) {
			return __( 'The accelerator is not available on a network of sites.', 'wpo-tweaks' );
		}
		if ( ( defined( 'DIETPRESS_DISABLE_CACHE' ) && DIETPRESS_DISABLE_CACHE ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
			return __( 'The page cache is switched off by a constant on this site (DIETPRESS_DISABLE_CACHE or DONOTCACHEPAGE), so the server must not serve stored copies either.', 'wpo-tweaks' );
		}
		if ( ! $assume_cache_on && ! Core_Diet_Cache::is_enabled() ) {
			return self::cache_off_reason();
		}
		// WP-CLI has no web server to ask, so neither is detected there; the self
		// test is what proves the rules work, from any context.
		if ( '' === self::server() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return __( 'The accelerator needs Apache, LiteSpeed or nginx, and this site runs on another web server. The page cache keeps working through PHP.', 'wpo-tweaks' );
		}
		if ( ! function_exists( 'link' ) ) {
			return __( 'PHP cannot create file links on this server (the link() function is disabled), and the accelerator needs them to make stored copies expire on time. The page cache keeps working through PHP.', 'wpo-tweaks' );
		}

		$context = self::get_rule_context();
		if ( is_string( $context ) ) {
			return $context;
		}

		// On nginx the rules are pasted by hand, so there is no file to write.
		$file = self::get_htaccess_path();
		if ( 'nginx' !== self::server() && ( file_exists( $file ) ? ! wp_is_writable( $file ) : ! wp_is_writable( dirname( $file ) ) ) ) {
			return __( 'The .htaccess file of the site cannot be written, so the rules cannot be added. The page cache keeps working through PHP.', 'wpo-tweaks' );
		}

		return '';
	}

	/**
	 * Why the accelerator waits while the page cache is off.
	 *
	 * Apart, because the Cache tab also writes it into the card, so the lock can
	 * come back without a reload when the cache toggle is switched off.
	 *
	 * @return string
	 */
	public static function cache_off_reason() {
		return __( 'Switch the page cache on first: the accelerator serves the copies it stores.', 'wpo-tweaks' );
	}

	/* ============================
	 * Rules
	 * ============================ */

	/**
	 * Everything the rules are built from, or the reason they cannot be.
	 *
	 * What PHP decides with code the server cannot run gives a reason instead of
	 * a context: a server that serves anyway would hand those requests a copy
	 * the site said they must not get.
	 *
	 * @return array|string Context, or the sentence explaining why not.
	 */
	public static function get_rule_context() {
		if ( ! class_exists( 'Core_Diet_Cache_Engine', false ) ) {
			require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-engine.php';
		}

		$host = Core_Diet_Cache_Store::get_home_host();
		if ( '' === $host ) {
			return __( 'The address of the site could not be read as a plain host name.', 'wpo-tweaks' );
		}

		$root = Core_Diet_Cache_Store::get_root();
		if ( preg_match( '/["%$\\\\\x00-\x1F\x7F]/', $root ) ) {
			return __( 'The folder of the cache has a character in its path that a server rule cannot hold.', 'wpo-tweaks' );
		}

		$content_url  = content_url();
		$content_host = wp_parse_url( $content_url, PHP_URL_HOST );
		$content_port = wp_parse_url( $content_url, PHP_URL_PORT );
		if ( is_string( $content_host ) && ( strtolower( $content_host ) !== $host || $content_port !== Core_Diet_Cache_Store::get_home_port() ) ) {
			return __( 'The content folder of the site is served from another address, so the server cannot hand out the stored copies under this one.', 'wpo-tweaks' );
		}

		$content_path = (string) wp_parse_url( $content_url, PHP_URL_PATH );
		$url_root     = untrailingslashit( $content_path ) . '/cache/dietpress';
		if ( ! preg_match( '#^/[A-Za-z0-9/_.~-]*$#D', $url_root ) ) {
			return __( 'The address of the content folder has a character that a server rule cannot hold.', 'wpo-tweaks' );
		}

		if ( has_filter( 'dietpress_cache_bypass_request' ) ) {
			return __( 'Code on this site decides in PHP which visits must not get a stored copy (the dietpress_cache_bypass_request filter), and the server cannot run that code. The page cache keeps working through PHP.', 'wpo-tweaks' );
		}

		$settings = Core_Diet_Cache_Settings::get_instance();
		$mobile   = $settings->is_enabled( 'separate_mobile' );
		if ( $mobile && self::has_foreign_mobile_filter() ) {
			return __( 'Code on this site changes which visitors WordPress treats as mobile, and with a separate mobile cache the server would have to know that. The page cache keeps working through PHP.', 'wpo-tweaks' );
		}

		$cookies = Core_Diet_Cache_Engine::get_bypass_cookie_prefixes();
		foreach ( $cookies as $prefix ) {
			if ( ! preg_match( '/^[A-Za-z0-9_.\-]+$/D', $prefix ) ) {
				return __( 'A cookie that keeps visitors out of the cache has a name a server rule cannot hold.', 'wpo-tweaks' );
			}
		}

		$accept = Core_Diet_Cache_Engine::get_bypass_accept_types();
		foreach ( $accept as $type ) {
			if ( ! preg_match( '#^[a-z0-9.+\-/]+$#D', $type ) ) {
				return __( 'A media type that keeps requests out of the cache cannot be written as a server rule.', 'wpo-tweaks' );
			}
		}

		$exclusions = array();
		foreach ( $settings->get_exclude_patterns() as $pattern ) {
			$pattern = (string) $pattern;
			if ( '' === $pattern ) {
				continue;
			}
			// The same characters the settings screen keeps. A pattern added by
			// code with anything else in it would be matched differently by the
			// server, and a page PHP excludes could then be served anyway.
			if ( ! preg_match( '#^[A-Za-z0-9_\-/.*%~\x80-\xFF]+$#D', $pattern ) ) {
				return __( 'An excluded URL pattern added by code has a character that a server rule cannot hold.', 'wpo-tweaks' );
			}
			$exclusions[] = $pattern;
		}

		return array(
			'clock_key'  => self::clock_key(),
			'host'       => $host,
			'port'       => Core_Diet_Cache_Store::get_home_port(),
			'root_disk'  => $root,
			'root_url'   => $url_root,
			'cookies'    => $cookies,
			'accept'     => $accept,
			'exclusions' => $exclusions,
			'mobile'     => $mobile,
		);
	}

	/**
	 * Whether code other than the engine filters wp_is_mobile().
	 *
	 * The engine watches that answer itself while the separate mobile cache is
	 * off, and that watcher is still hooked on the request that switches the
	 * mobile cache on, so counting it took the rules out at the very moment they
	 * were needed.
	 *
	 * @return bool
	 */
	private static function has_foreign_mobile_filter() {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_is_mobile'] ) || ! is_object( $wp_filter['wp_is_mobile'] ) || ! isset( $wp_filter['wp_is_mobile']->callbacks ) ) {
			return (bool) has_filter( 'wp_is_mobile' );
		}

		foreach ( (array) $wp_filter['wp_is_mobile']->callbacks as $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( is_array( $function ) && isset( $function[0], $function[1] ) && $function[0] instanceof Core_Diet_Cache_Engine && 'watch_mobile' === $function[1] ) {
					continue;
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * The rewrite block, without its markers.
	 *
	 * A pure function of its context, so the release checks can read the rules
	 * without a WordPress around them. Every condition only keeps a request away
	 * from the stored copy; none of them chooses a copy from something the
	 * visitor sends (rule 21 of the plugins handbook).
	 *
	 * @param array $c Context from get_rule_context().
	 * @return string[]
	 */
	public static function build_rules( array $c ) {
		$host  = preg_quote( $c['host'], '#' ) . ( null !== $c['port'] ? ':' . (int) $c['port'] : '' );
		$disk  = $c['root_disk'] . '/' . $c['host'];
		$url   = $c['root_url'] . '/' . $c['host'];
		$hour  = '%{TIME_YEAR}%{TIME_MON}%{TIME_DAY}%{TIME_HOUR}';
		$lines = array();

		$lines[] = '# Serves the pages DietPress stored without starting PHP. Written from the';
		$lines[] = '# Cache tab, and removed when the accelerator or the plugin is switched off.';
		$lines[] = '<IfModule mod_rewrite.c>';
		// Without mod_headers a copy would leave without its Cache-Control, and
		// browsers would keep it for a lifetime of their own that no purge
		// reaches. The whole block goes inside, so no condition is left dangling
		// for the first rule of the WordPress block.
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = 'RewriteEngine On';

		// The server clock, handed to PHP so it can name each copy after the
		// hours it stays valid in.
		$lines[] = 'RewriteRule ^ - [E=' . self::CLOCK_VAR . ':%{TIME}]';
		// The same clock as a request header, for servers that do not pass the
		// environment to PHP. RequestHeader set overwrites whatever the visitor
		// sent, and the secret says it comes from here.
		if ( ! empty( $c['clock_key'] ) ) {
			$lines[] = 'RequestHeader set ' . self::CLOCK_HEADER . ' "' . $c['clock_key'] . ':%{' . self::CLOCK_VAR . '}e"';
		}

		// Scheme, the way is_ssl() sees it. "skip" serves nothing.
		$lines[] = 'RewriteRule ^ - [E=DPC_SCHEME:skip]';
		$lines[] = 'RewriteCond %{HTTPS} =on [OR]';
		$lines[] = 'RewriteCond %{ENV:HTTPS} ^(on|1)$ [NC]';
		$lines[] = 'RewriteRule ^ - [E=DPC_SCHEME:-https]';
		// Plain HTTP only when no HTTPS variable is set, the port is not 443 (where
		// WordPress would deduce HTTPS) and no proxy header claims HTTPS: the six
		// the engine knows, and four more that a site can translate into HTTPS in
		// PHP, where the server cannot see it. More headers only mean fewer plain
		// copies served by the server, never the wrong one.
		$lines[] = 'RewriteCond %{HTTPS} !=on';
		$lines[] = 'RewriteCond %{ENV:HTTPS} =""';
		$lines[] = 'RewriteCond %{SERVER_PORT} !=443';
		$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Proto} !(^|,)\s*https\s*(,|$) [NC]';
		$lines[] = 'RewriteCond %{HTTP:CloudFront-Forwarded-Proto} !^\s*https\s*$ [NC]';
		$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Scheme} !^\s*https\s*$ [NC]';
		$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Ssl} !^\s*(on|1)\s*$ [NC]';
		$lines[] = 'RewriteCond %{HTTP:CF-Visitor} !https [NC]';
		$lines[] = 'RewriteCond %{HTTP:X-ARR-SSL} =""';
		$lines[] = 'RewriteCond %{HTTP:Forwarded} !proto=.?https [NC]';
		$lines[] = 'RewriteCond %{HTTP:Fastly-SSL} =""';
		$lines[] = 'RewriteCond %{HTTP:Front-End-Https} !^\s*on\s*$ [NC]';
		$lines[] = 'RewriteCond %{HTTP:X-Forwarded-Port} !^\s*443\s*$';
		$lines[] = 'RewriteRule ^ - [E=DPC_SCHEME:]';

		// File name: the trailing slash is part of the name, not of the folder.
		// With a separate mobile cache, the copy a phone gets is its own. Same test
		// as wp_is_mobile() (wp-includes/vars.php:163-181 in WordPress 7.1): the
		// client hint decides when it is sent, the user agent when it is not. The
		// visitor chooses both, and that is the point: PHP builds and stores the copy
		// from those very headers, so the key of the copy comes from the same thing
		// the page was built with, and a request gets the page it would have got from
		// PHP. A filter on wp_is_mobile() would break that, and it already takes the
		// rules out (get_rule_context()). One case cannot be told apart in .htaccess:
		// a header that is sent empty reads the same as one that is not sent, so a
		// client that sends "Sec-CH-UA-Mobile:" empty with a phone user agent gets the
		// phone copy where PHP would build the desktop one. No browser does that.
		// Without that cache there is one copy for every device, so there is nothing
		// to choose and the rules serve it to phones as well.
		//
		// wp_is_mobile() looks for "Opera Mini" and "Opera Mobi" with a space
		// (vars.php:175-176 in WordPress 7.1.2), and up to 3.7.0 the rules had a dot
		// there, which also matched any other character. \x20 is that space: the
		// arguments of a rewrite directive are split at spaces, so the pattern
		// cannot carry one written as it is.
		if ( ! empty( $c['mobile'] ) ) {
			$lines[] = 'RewriteRule ^ - [E=DPC_M:]';
			$lines[] = 'RewriteCond %{HTTP:Sec-CH-UA-Mobile} ^\?1$';
			$lines[] = 'RewriteRule ^ - [E=DPC_M:-mobile]';
			$lines[] = 'RewriteCond %{HTTP:Sec-CH-UA-Mobile} =""';
			$lines[] = 'RewriteCond %{HTTP_USER_AGENT} (Mobile|Android|Silk/|Kindle|BlackBerry|Opera\x20Mini|Opera\x20Mobi)';
			$lines[] = 'RewriteRule ^ - [E=DPC_M:-mobile]';
		}

		$lines[] = 'RewriteRule ^ - [E=DPC_FILE:/index-ns%{ENV:DPC_M}%{ENV:DPC_SCHEME}.' . $hour . '.html]';
		$lines[] = 'RewriteCond %{REQUEST_URI} /$';
		$lines[] = 'RewriteRule ^ - [E=DPC_FILE:index%{ENV:DPC_M}%{ENV:DPC_SCHEME}.' . $hour . '.html]';

		$lines[] = 'RewriteCond %{ENV:DPC_SCHEME} !=skip';
		$lines[] = 'RewriteCond %{REQUEST_METHOD} =GET';
		$lines[] = 'RewriteCond %{QUERY_STRING} =""';
		$lines[] = 'RewriteCond %{REQUEST_URI} !^' . str_replace( '.', '\\.', $c['root_url'] ) . '/';
		$lines[] = 'RewriteCond %{HTTP_HOST} ^' . $host . '$ [NC]';
		$lines[] = 'RewriteCond %{HTTP:Authorization} =""';

		if ( $c['cookies'] ) {
			$names   = array_map(
				static function ( $prefix ) {
					return str_replace( '.', '\.', $prefix );
				},
				$c['cookies']
			);
			$lines[] = 'RewriteCond %{HTTP:Cookie} !(^|;\s*)(' . implode( '|', $names ) . ')';
		}

		if ( $c['accept'] ) {
			$types   = array_map(
				static function ( $type ) {
					return str_replace( array( '.', '+' ), array( '\.', '\+' ), $type );
				},
				$c['accept']
			);
			$lines[] = 'RewriteCond %{HTTP:Accept} !(' . implode( '|', $types ) . ') [NC]';
		}

		// A forced reload asks for a fresh copy; PHP rebuilds it.
		$lines[] = 'RewriteCond %{HTTP:Cache-Control} !no-cache [NC]';
		$lines[] = 'RewriteCond %{HTTP:Pragma} !no-cache [NC]';

		foreach ( $c['exclusions'] as $pattern ) {
			$lines[] = 'RewriteCond %{REQUEST_URI} !^' . self::pattern_to_regex( $pattern ) . '/*$ [NC]';
		}


		// The copy is looked for under the root the server itself uses, not under
		// the path PHP is given: a hosting can hand PHP a mapped path for the same
		// files (measured on SiteGround on 16 sep 2026, where Apache works in
		// /home/u3-.../public_html and PHP is told /home/customer/...), and then a
		// rule written with the PHP path never finds anything. The path of PHP is
		// kept as a second chance, for a server that serves the content folder
		// from somewhere else through an alias.
		$lines[] = 'RewriteCond "%{DOCUMENT_ROOT}' . $url . '%{REQUEST_URI}%{ENV:DPC_FILE}" -f [OR]';
		$lines[] = 'RewriteCond "' . $disk . '%{REQUEST_URI}%{ENV:DPC_FILE}" -f';
		$lines[] = 'RewriteRule ^ "' . $url . '%{REQUEST_URI}%{ENV:DPC_FILE}" [L]';
		$lines[] = '</IfModule>';
		$lines[] = '</IfModule>';

		return $lines;
	}

	/**
	 * Translate an exclusion pattern into the regular expression the server uses.
	 *
	 * The engine matches the pattern against the path and against the path
	 * without its trailing slash; "/*$" after it covers both, and a few more
	 * paths with doubled slashes, which only excludes more.
	 *
	 * @param string $pattern Pattern with "*" as wildcard, already allowlisted.
	 * @return string
	 */
	public static function pattern_to_regex( $pattern ) {
		$out    = '';
		$length = strlen( $pattern );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];
			if ( '*' === $char ) {
				$out .= '.*';
			} elseif ( '.' === $char ) {
				$out .= '\.';
			} else {
				$out .= $char;
			}
		}

		return $out;
	}

	/**
	 * The rules for nginx, which reads no .htaccess and has to be told by hand.
	 *
	 * The same decisions as build_rules(), written the nginx way: flags set with
	 * if in the server block, which is one of the places nginx documents if as
	 * safe; a file test on the absolute path; and a rewrite into a location that
	 * is internal, so it sets the headers of the served copy and cannot be
	 * reached from outside. That last part also closes the cache folder, which on
	 * nginx could be read by URL until now. Four parts go in three places of the
	 * configuration, and each one says where.
	 *
	 * The HTTPS copy follows the variable set in $dietpress_https, $https by
	 * default, which is what most nginx setups pass to PHP as HTTPS. A server that
	 * feeds fastcgi_param HTTPS from something else, a map of X-Forwarded-Proto
	 * behind a load balancer for instance, has to put that same variable there;
	 * the self test catches a mismatch by comparing the page the server served
	 * with the copy of the right scheme.
	 *
	 * @param array  $c             Context from get_rule_context().
	 * @param string $cache_control Cache-Control for the served copy.
	 * @param string $charset       Charset of the site.
	 * @return string
	 */
	public static function build_nginx_rules( array $c, $cache_control = 'max-age=0, must-revalidate', $charset = 'UTF-8' ) {
		$host_re       = preg_quote( $c['host'], '#' ) . ( null !== $c['port'] ? ':' . (int) $c['port'] : '' );
		$name          = '${dietpress_name}${dietpress_m}${dietpress_scheme}.${dietpress_hour}.html';
		$cache_control = preg_match( '/^[a-z0-9=, -]+$/D', $cache_control ) ? $cache_control : 'max-age=0, must-revalidate';
		$charset       = preg_replace( '/[^A-Za-z0-9_-]/', '', $charset );
		$charset       = '' === $charset ? 'UTF-8' : $charset;
		$l             = array();

		$l[] = '# Page Cache by DietPress: rules for nginx. Paste each part where it says,';
		$l[] = '# reload nginx and switch the accelerator on in the Cache tab, which tests';
		$l[] = '# them. Copy them again whenever the Cache tab says they changed.';
		$l[] = '';
		$l[] = '# --- Part 1 of 4: inside the server { } block of this site, before any location.';
		$l[] = '# The variable your PHP location passes as fastcgi_param HTTPS.';
		$l[] = 'set $dietpress_https $https;';
		$l[] = 'set $dietpress_skip "";';
		$l[] = 'set $dietpress_m "";';
		$l[] = 'if ($request_method != GET) { set $dietpress_skip 1; }';
		$l[] = 'if ($query_string != "") { set $dietpress_skip 1; }';
		$l[] = 'if ($http_host !~* "^' . $host_re . '$") { set $dietpress_skip 1; }';
		$l[] = 'if ($http_authorization != "") { set $dietpress_skip 1; }';

		if ( $c['cookies'] ) {
			$names = array_map(
				static function ( $prefix ) {
					return str_replace( '.', '\.', $prefix );
				},
				$c['cookies']
			);
			$l[]   = 'if ($http_cookie ~ "(^|;\s*)(' . implode( '|', $names ) . ')") { set $dietpress_skip 1; }';
		}

		if ( $c['accept'] ) {
			$types = array_map(
				static function ( $type ) {
					return str_replace( array( '.', '+' ), array( '\.', '\+' ), $type );
				},
				$c['accept']
			);
			$l[]   = 'if ($http_accept ~* "(' . implode( '|', $types ) . ')") { set $dietpress_skip 1; }';
		}

		$l[] = 'if ($http_cache_control ~* "no-cache") { set $dietpress_skip 1; }';
		$l[] = 'if ($http_pragma ~* "no-cache") { set $dietpress_skip 1; }';

		foreach ( $c['exclusions'] as $pattern ) {
			$l[] = 'if ($uri ~* "^' . self::pattern_to_regex( $pattern ) . '/*$") { set $dietpress_skip 1; }';
		}

		if ( ! empty( $c['mobile'] ) ) {
			$l[] = 'set $dietpress_mobile "$http_sec_ch_ua_mobile|$http_user_agent";';
			$l[] = 'set $dietpress_m "";';
			$l[] = 'if ($dietpress_mobile ~ "^\?1\|") { set $dietpress_m "-mobile"; }';
			$l[] = 'if ($dietpress_mobile ~ "^\|.*(Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi)") { set $dietpress_m "-mobile"; }';
		}

		// Plain HTTP only with no proxy header claiming HTTPS and not on port 443,
		// where WordPress would deduce HTTPS; the HTTPS copy whenever PHP gets HTTPS.
		$l[] = 'set $dietpress_plain 1;';
		$l[] = 'if ($http_x_forwarded_proto ~* "(^|,)\s*https\s*(,|$)") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_cloudfront_forwarded_proto ~* "^\s*https\s*$") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_x_forwarded_scheme ~* "^\s*https\s*$") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_x_forwarded_ssl ~* "^\s*(on|1)\s*$") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_cf_visitor ~* "https") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_x_arr_ssl != "") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_forwarded ~* "proto=.?https") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_fastly_ssl != "") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_front_end_https ~* "^\s*on\s*$") { set $dietpress_plain ""; }';
		$l[] = 'if ($http_x_forwarded_port ~ "^\s*443\s*$") { set $dietpress_plain ""; }';
		$l[] = 'if ($server_port = 443) { set $dietpress_plain ""; }';
		$l[] = 'set $dietpress_scheme "skip";';
		$l[] = 'if ($dietpress_plain) { set $dietpress_scheme ""; }';
		$l[] = 'if ($dietpress_https ~* "^(on|1)$") { set $dietpress_scheme "-https"; }';
		$l[] = 'if ($dietpress_scheme = "skip") { set $dietpress_skip 1; }';

		$l[] = 'set $dietpress_name "/index-ns";';
		$l[] = 'if ($uri ~ "/$") { set $dietpress_name "index"; }';
		$l[] = 'set $dietpress_hour "none";';
		$l[] = 'if ($time_iso8601 ~ "^(\d{4})-(\d{2})-(\d{2})T(\d{2})") { set $dietpress_hour "$1$2$3$4"; }';
		$l[] = 'set $dietpress_disk "${document_root}' . $c['root_url'] . '/' . $c['host'] . '${uri}' . $name . '";';
		$l[] = 'set $dietpress_disk2 "' . $c['root_disk'] . '/' . $c['host'] . '${uri}' . $name . '";';
		$l[] = 'set $dietpress_url "' . $c['root_url'] . '/' . $c['host'] . '${uri}' . $name . '";';
		$l[] = 'if ($dietpress_skip) { set $dietpress_disk "/dev/null/dietpress"; }';
		$l[] = 'if ($dietpress_skip) { set $dietpress_disk2 "/dev/null/dietpress"; }';
		$l[] = '';
		$l[] = '# --- Part 2 of 4: inside the server { } block too, next to your other locations.';
		$l[] = '# It only serves what part 3 sends it; nobody can open it from outside.';
		$l[] = '# Access rules inside your location / { } block (allow, deny, auth_basic,';
		$l[] = '# auth_request) do not reach the pages served from here: nginx checks them';
		$l[] = '# after part 3 has sent the request on. Move them up to the server block, or';
		$l[] = '# repeat them inside this location.';
		$l[] = 'location ^~ ' . $c['root_url'] . '/ {';
		$l[] = '	internal;';
		$l[] = '	expires off;';
		$l[] = '	charset ' . $charset . ';';
		$l[] = '	add_header Cache-Control "' . $cache_control . '" always;';
		$l[] = '	add_header X-DietPress-Cache "HIT-STATIC" always;';
		if ( ! empty( $c['mobile'] ) ) {
			$l[] = '	add_header Vary "Sec-CH-UA-Mobile, User-Agent" always;';
		}
		$l[] = '}';
		$l[] = '';
		$l[] = '# --- Part 3 of 4: first line inside your location / { } block, before try_files.';
		$l[] = 'if (-f $dietpress_disk) { rewrite ^ $dietpress_url last; }';
		$l[] = 'if (-f $dietpress_disk2) { rewrite ^ $dietpress_url last; }';
		$l[] = '';
		$l[] = '# --- Part 4 of 4: inside the location that passes PHP files to PHP-FPM.';
		$l[] = '# It hands the server clock to PHP, which names each copy after the hours it';
		$l[] = '# stays valid in.';
		$l[] = 'fastcgi_param ' . self::CLOCK_VAR . ' $time_iso8601;';

		return implode( "\n", $l );
	}

	/**
	 * The nginx rules as they should be pasted right now.
	 *
	 * @return string Empty when they cannot be built.
	 */
	public static function current_nginx_rules() {
		$context = self::get_rule_context();

		return is_array( $context ) ? self::build_nginx_rules( $context, self::hit_cache_control(), (string) get_bloginfo( 'charset' ) ) : '';
	}

	/**
	 * Rules for the .htaccess of the cache folder.
	 *
	 * Off, nothing in the folder is reachable. On, only the hour names are, with
	 * the same Cache-Control a hit served by PHP sends and nothing from
	 * mod_expires, which would otherwise hand the page the lifetime of an image
	 * and keep a purge from ever reaching a visitor who has it.
	 *
	 * @param bool   $serving       Whether the accelerator is on.
	 * @param string $cache_control Value of the Cache-Control header.
	 * @param string $charset       Charset of the site.
	 * @param bool   $vary_device   Whether a URL has a phone copy and a desktop one.
	 * @return string
	 */
	public static function get_cache_dir_rules( $serving, $cache_control = 'max-age=0, must-revalidate', $charset = 'UTF-8', $vary_device = false ) {
		if ( ! $serving ) {
			return self::DENY_RULES;
		}

		$cache_control = preg_match( '/^[a-z0-9=, -]+$/D', $cache_control ) ? $cache_control : 'max-age=0, must-revalidate';
		$charset       = preg_replace( '/[^A-Za-z0-9_-]/', '', $charset );
		$charset       = '' === $charset ? 'UTF-8' : $charset;
		$names         = '^index(-ns)?(-mobile)?(-https)?\.[0-9]{10}\.html$';

		// Only the internal redirect of the accelerator rules may reach a name,
		// never a request for its address. Granting it to everybody would also
		// replace, for these files, any Require the site sets in its own
		// .htaccess or in the <Directory> of the server (AuthMerging is Off by
		// default), so a site closed by IP address or single sign-on would hand
		// its pages to anyone who asks for them by the folder address. The
		// request that was rewritten here already passed the access rules of its
		// own address, and only a redirect carries the clock the rules set under
		// the REDIRECT_ prefix.
		return self::SERVING_RULES_HEAD . "\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n\t<FilesMatch \"{$names}\">\n\t\tRequire env REDIRECT_" . self::CLOCK_VAR . "\n\t</FilesMatch>\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n\t<FilesMatch \"{$names}\">\n\t\tOrder allow,deny\n\t\tAllow from env=REDIRECT_" . self::CLOCK_VAR . "\n\t</FilesMatch>\n</IfModule>\n"
			. "<IfModule mod_expires.c>\n\tExpiresActive Off\n</IfModule>\n"
			. "<IfModule mod_headers.c>\n\t<FilesMatch \"{$names}\">\n\t\tHeader always set Cache-Control \"{$cache_control}\"\n\t\tHeader always set X-DietPress-Cache \"HIT-STATIC\"\n" . ( $vary_device ? "\t\tHeader always set Vary \"Sec-CH-UA-Mobile, User-Agent\"\n" : '' ) . "\t</FilesMatch>\n</IfModule>\n"
			. "AddDefaultCharset {$charset}\n";
	}

	/**
	 * The rules the cache folder should have right now.
	 *
	 * @return string
	 */
	public static function current_cache_dir_rules() {
		// On nginx as well. nginx ignores .htaccess and its own rules close the
		// folder, but PHP reads the first line of this file before it names a
		// copy, on every server (folder_serves_names()).
		if ( ! self::should_serve() ) {
			return self::DENY_RULES;
		}

		return self::get_cache_dir_rules( true, self::hit_cache_control(), (string) get_bloginfo( 'charset' ), Core_Diet_Cache_Settings::get_instance()->is_enabled( 'separate_mobile' ) );
	}

	/**
	 * Cache-Control for a page served from the cache, by either route.
	 *
	 * The same value a page WordPress builds gets from
	 * Core_Diet_Htaccess::set_html_cache_control(), so a URL does not change its
	 * caching depending on whether PHP, the server or WordPress answered it.
	 * With browser caching off that method sends nothing; a cached page cannot
	 * send nothing, because a browser would then guess a lifetime of its own,
	 * so it asks to revalidate every time.
	 *
	 * @return string
	 */
	public static function hit_cache_control() {
		if ( ! class_exists( 'Core_Diet_Settings', false ) ) {
			return 'max-age=0, must-revalidate';
		}

		// Read from the option itself, not from the Core_Diet_Settings instance:
		// that one keeps what it read first in the request, and the rules are
		// rewritten from the hook that saves a new value.
		$defaults = Core_Diet_Settings::get_defaults();
		$settings = get_option( Core_Diet_Settings::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? array_merge( $defaults, $settings ) : $defaults;

		if ( ! empty( $settings['htaccess_browser_cache'] ) ) {
			$seconds = Core_Diet_Settings::period_to_seconds( (string) $settings['htaccess_html_maxage'] );
			if ( $seconds > 0 ) {
				return 'max-age=' . $seconds . ', public';
			}
		}

		/*
		 * "Send no rules for HTML", the default since 3.7.0, is honoured by
		 * WordPress pages and not by the copies this module serves, and the
		 * difference is Last-Modified. A page built by WordPress carries none,
		 * so a browser with no Cache-Control has nothing to guess a lifetime
		 * from and asks again. A stored copy carries the time it was written,
		 * which is exactly the input heuristic caching uses (RFC 9111, section
		 * 4.2.2), and a tenth of the age of a page stored last week is hours in
		 * which purging here changes nothing for whoever already has it.
		 *
		 * The cost is that a cache in front of this one will not store these
		 * responses either. That combination is not meant to exist: a page
		 * cache belongs either here or at the hosting, never in both places,
		 * and Core_Diet_Cache_Compat says so before this module can be switched
		 * on. The page the engine builds and stores goes out with max-age=0 as
		 * well when nothing set a lifetime for it
		 * (Core_Diet_Cache_Engine::maybe_start_capture()), or the cache in front
		 * keeps that first build and hands it out ahead of this one.
		 */
		return 'max-age=0, must-revalidate';
	}

	/* ============================
	 * Files
	 * ============================ */

	/**
	 * Path of the site .htaccess.
	 *
	 * @return string
	 */
	public static function get_htaccess_path() {
		$home = self::home_path_from( ABSPATH, (string) get_option( 'home' ), (string) get_option( 'siteurl' ) );

		if ( null === $home ) {
			if ( ! function_exists( 'get_home_path' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$home = get_home_path();
		}

		return rtrim( $home, '/' ) . '/.htaccess';
	}

	/**
	 * The folder of the site .htaccess, worked out from where WordPress lives.
	 *
	 * get_home_path() finds the root of a site that gives WordPress its own
	 * folder through SCRIPT_FILENAME (wp-admin/includes/file.php:107-117), which
	 * under WP-CLI is the path of wp-cli.phar, so it answered "/" there, and
	 * deactivating or uninstalling from WP-CLI left the block in the real
	 * .htaccess. ABSPATH ends with the folder that siteurl adds to home.
	 *
	 * @param string $abspath ABSPATH.
	 * @param string $home    The home option.
	 * @param string $siteurl The siteurl option.
	 * @return string|null The folder, or null when it cannot be told from these.
	 */
	public static function home_path_from( $abspath, $home, $siteurl ) {
		$abspath = rtrim( str_replace( '\\', '/', (string) $abspath ), '/' );
		$home    = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', (string) $home ), '/' );
		$siteurl = rtrim( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', (string) $siteurl ), '/' );

		if ( '' === $home || '' === $siteurl || '' === $abspath ) {
			return null;
		}
		if ( 0 === strcasecmp( $home, $siteurl ) ) {
			return $abspath . '/';
		}
		if ( 0 !== stripos( $siteurl, $home . '/' ) ) {
			return null;
		}

		$tail = '/' . trim( substr( $siteurl, strlen( $home ) ), '/' );
		if ( strlen( $abspath ) <= strlen( $tail ) || 0 !== strcasecmp( substr( $abspath, -strlen( $tail ) ), $tail ) ) {
			return null;
		}

		return substr( $abspath, 0, -strlen( $tail ) ) . '/';
	}

	/**
	 * Put the block in the content of a .htaccess, or take it out.
	 *
	 * Pure, so it can be tested on strings. Any block already there is removed,
	 * with the blank line written after it, and the new one goes right before
	 * "# BEGIN WordPress", which puts it after the rules other plugins write
	 * above WordPress, security plugins included, and before the WordPress
	 * catch-all that would otherwise send every page to index.php first. With
	 * no WordPress block it goes at the top.
	 *
	 * @param string        $content Current content.
	 * @param string[]|null $lines   Rules, or null to remove the block.
	 * @return string|null New content, or null when the file has a BEGIN of ours
	 *                     with no END, which is left for a person to look at.
	 */
	public static function splice( $content, $lines ) {
		$begin = '# BEGIN ' . self::MARKER;
		$end   = '# END ' . self::MARKER;
		$rows  = preg_split( "/\r\n|\n|\r/", (string) $content );
		$kept  = array();
		$state = 'out';

		foreach ( $rows as $row ) {
			$trimmed = trim( $row );

			if ( 'in' === $state ) {
				if ( $end === $trimmed ) {
					$state = 'after';
				}
				continue;
			}
			if ( 'after' === $state ) {
				$state = 'out';
				if ( '' === $trimmed ) {
					continue;
				}
			}
			if ( $begin === $trimmed ) {
				$state = 'in';
				continue;
			}

			$kept[] = $row;
		}

		if ( 'in' === $state ) {
			return null;
		}

		if ( null === $lines ) {
			return implode( "\n", $kept );
		}

		$block = array_merge( array( $begin ), $lines, array( $end, '' ) );
		$at    = 0;

		foreach ( $kept as $index => $row ) {
			if ( '# BEGIN WordPress' === trim( $row ) ) {
				$at = $index;
				break;
			}
		}

		array_splice( $kept, $at, 0, $block );

		return implode( "\n", $kept );
	}

	/**
	 * Read the rules currently written between our markers.
	 *
	 * @return string[]|null Lines, or null when there is no block.
	 */
	public static function read_block() {
		$file = self::get_htaccess_path();
		if ( ! is_readable( $file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the site .htaccess, a local file, to compare the block with what it should be.
		$content = (string) file_get_contents( $file );
		$rows    = preg_split( "/\r\n|\n|\r/", $content );
		$lines   = null;

		foreach ( $rows as $row ) {
			$trimmed = trim( $row );
			if ( null === $lines ) {
				if ( '# BEGIN ' . self::MARKER === $trimmed ) {
					$lines = array();
				}
				continue;
			}
			if ( '# END ' . self::MARKER === $trimmed ) {
				return $lines;
			}
			$lines[] = $row;
		}

		return null;
	}

	/**
	 * Write the block, or remove it, under the same lock WordPress uses.
	 *
	 * Read, change and write happen inside one exclusive lock, the way
	 * insert_with_markers() does it (wp-admin/includes/misc.php:175-247), so a
	 * permalink save running at the same time cannot write over a half-changed
	 * file.
	 *
	 * @param string[]|null $lines Rules, or null to remove.
	 * @return bool Whether the file now says what was asked.
	 */
	private static function write_block( $lines ) {
		$file = self::get_htaccess_path();

		if ( ! file_exists( $file ) ) {
			if ( null === $lines ) {
				return true;
			}
			if ( ! wp_is_writable( dirname( $file ) ) ) {
				return false;
			}
		} elseif ( ! wp_is_writable( $file ) ) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Locked read-modify-write of the site .htaccess, the same sequence insert_with_markers() uses; WP_Filesystem offers no lock.
		$handle = fopen( $file, 'c+' );
		if ( ! $handle ) {
			return false;
		}

		flock( $handle, LOCK_EX );

		$content = stream_get_contents( $handle );
		$content = false === $content ? '' : $content;
		$updated = self::splice( $content, $lines );
		$result  = true;

		if ( null === $updated ) {
			$result = false;
		} elseif ( $updated !== $content ) {
			rewind( $handle );
			$bytes = fwrite( $handle, $updated );
			if ( false === $bytes ) {
				$result = false;
			} else {
				ftruncate( $handle, ftell( $handle ) );
			}
			fflush( $handle );
		}

		flock( $handle, LOCK_UN );
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return $result;
	}

	/**
	 * Whether the current request may write the site .htaccess.
	 *
	 * The accelerator never runs in a network, but the file is shared by every
	 * site there, and a block can be left in it from before the site became a
	 * network. So the bar is the one Core_Diet_Htaccess::can_write_shared_files()
	 * sets (class-core-diet-htaccess.php:152): in a network, the network
	 * capability. WP-CLI runs with shell access, which is more than any
	 * capability grants.
	 *
	 * @return bool
	 */
	private static function can_write() {
		return ( is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' ) ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Bring both files in line with the settings.
	 *
	 * Writing goes cache folder first, so the first copy the server hands out
	 * already carries its headers; removing goes the other way round.
	 *
	 * @return bool Whether the files now match the settings.
	 */
	public static function sync() {
		// Both directions need the capability here. The unconditional way out
		// is remove(), which deactivation calls directly.
		if ( ! self::can_write() ) {
			return false;
		}

		if ( ! self::should_serve() ) {
			return self::remove( false );
		}

		// nginx reads the rules from its own configuration, pasted by hand. The
		// cache folder is opened all the same, because PHP names copies only in
		// an open folder; a block left in .htaccess from an Apache past only goes
		// away, and the names of the hours stay: they are what nginx serves.
		if ( 'nginx' === self::server() ) {
			return Core_Diet_Cache_Store::write_hardening( self::current_cache_dir_rules() ) && self::write_block( null );
		}

		$context = self::get_rule_context();
		if ( ! is_array( $context ) ) {
			return self::remove( false );
		}

		if ( ! Core_Diet_Cache_Store::write_hardening( self::current_cache_dir_rules() ) ) {
			return false;
		}

		return self::write_block( self::build_rules( $context ) );
	}

	/**
	 * Take the block out and close the cache folder again.
	 *
	 * Never gated on a capability: it only stops the server from serving, which
	 * is the safe direction, and it has to work when the plugin is deactivated
	 * from WP-CLI without a user, or Apache would go on serving pages of a
	 * plugin that is off. It writes nothing of anyone's choosing: it takes out
	 * this block, if there is one, and puts back the closed rules of 3.5.x.
	 *
	 * @param bool $forget Also forget the last successful test.
	 * @return bool Whether the block is gone.
	 */
	public static function remove( $forget = true ) {
		$removed = self::write_block( null );

		if ( is_dir( Core_Diet_Cache_Store::get_root() ) ) {
			Core_Diet_Cache_Store::write_hardening( self::DENY_RULES );

			// Without the names of the hours, rules that stay somewhere this
			// class cannot reach (nginx, or a copy of .htaccess another tool
			// restores) have nothing to serve, and PHP serves the pages as before.
			Core_Diet_Cache_Store::delete_hour_names();
		}

		if ( $forget ) {
			delete_option( self::VERIFIED_OPTION );
		}

		return $removed;
	}

	/**
	 * Stop the server from serving while the accelerator stays switched on.
	 *
	 * What remove() does, and PHP is told to name no copies until maybe_sync()
	 * finds the accelerator able to run again: on nginx the pasted rules stay
	 * where this class cannot reach them, and those names are all they serve.
	 * The test is kept, so the accelerator comes back without a new one.
	 *
	 * @return bool Whether it ran: only for whoever may write the site .htaccess.
	 */
	public static function pause() {
		if ( ! self::can_write() ) {
			return false;
		}

		$verified = get_option( self::VERIFIED_OPTION );

		self::remove( false );

		if ( is_array( $verified ) && empty( $verified['paused'] ) ) {
			$verified['paused'] = time();
			update_option( self::VERIFIED_OPTION, $verified, true );
		}

		return true;
	}

	/**
	 * Switch the accelerator on for real: write the rules and prove they work.
	 *
	 * The setting only stays on when the site answers its own home page with a
	 * copy served by the server. Anything else takes the rules out again and
	 * says why, because every way this fails from the outside looks like "it is
	 * on and nothing changed", or like a broken site.
	 *
	 * @return array {
	 *     @type bool      $ok         Whether the server serves the copies now.
	 *     @type string    $message    Sentence to show.
	 *     @type bool|null $compressed Whether the served copy came compressed.
	 * }
	 */
	public static function enable() {
		$reason = self::get_unavailable_reason();
		if ( '' !== $reason ) {
			return self::result( false, $reason, null );
		}

		if ( ! self::can_write() ) {
			return self::result( false, __( 'Only an administrator can switch the accelerator on, from the Cache tab.', 'wpo-tweaks' ), null );
		}

		if ( ! self::sync() ) {
			self::remove();
			return self::result( false, __( 'The rules could not be written to the .htaccess file, so the accelerator stayed off. The page cache keeps working through PHP.', 'wpo-tweaks' ), null );
		}

		require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-self-test.php';

		// Marked as this install before the test, or PHP would not name the
		// copy the test needs the server to find.
		update_option(
			self::VERIFIED_OPTION,
			array(
				'time'       => 0,
				'compressed' => null,
				'where'      => self::fingerprint(),
			),
			true
		);

		$test = Core_Diet_Cache_Self_Test::run();

		if ( empty( $test['static'] ) ) {
			self::remove();
			return self::result( false, __( 'The accelerator stayed off because the test did not get a page served by the server:', 'wpo-tweaks' ) . ' ' . $test['message'], null );
		}

		update_option(
			self::VERIFIED_OPTION,
			array(
				'time'       => time(),
				'compressed' => (bool) $test['compressed'],
				'where'      => self::fingerprint(),
				'server'     => self::server(),
				'software'   => self::request_software(),
				'rules'      => 'nginx' === self::server() ? md5( self::current_nginx_rules() ) : '',
			),
			true
		);

		return self::result( true, $test['message'], (bool) $test['compressed'] );
	}

	/**
	 * Remember and return the outcome of enable().
	 *
	 * @param bool      $ok         Whether the server serves the copies now.
	 * @param string    $message    Sentence to show.
	 * @param bool|null $compressed Whether the served copy came compressed.
	 * @return array
	 */
	private static function result( $ok, $message, $compressed ) {
		self::$last_result = array(
			'ok'         => (bool) $ok,
			'message'    => (string) $message,
			'compressed' => $compressed,
		);

		return self::$last_result;
	}

	/**
	 * Outcome of the last enable() on this request.
	 *
	 * @return array|null
	 */
	public static function get_last_result() {
		return self::$last_result;
	}

	/**
	 * Put the files right when they drifted from the settings.
	 *
	 * Runs for administrators on every dashboard load, and when the Cache tab is
	 * drawn. It catches what no save hook sees: a constant that switches the
	 * cache off, a new address for the site, a filter that changed what the
	 * rules must contain (a plugin just activated, for instance), or another tool
	 * that removed the block. Up to the first 3.6.0 builds it ran at most once an
	 * hour, and all of those reached the server that late. What it does is read
	 * two small files and compare strings, and it writes only what differs. It
	 * never runs the self test; a block that was tested once is rewritten with
	 * the values of today.
	 *
	 * When the accelerator cannot run, the rules go, the names of the hours go,
	 * and PHP is told to stop naming copies until it can run again: on nginx the
	 * pasted rules stay where this class cannot reach them, and those names are
	 * all they serve.
	 *
	 * @param bool $force Kept for callers; every call checks now.
	 */
	public static function maybe_sync( $force = false ) {
		unset( $force );

		if ( ! self::can_write() || wp_doing_ajax() ) {
			return;
		}

		$present  = null !== self::read_block();
		$verified = get_option( self::VERIFIED_OPTION );

		if ( ! self::should_serve() ) {
			$on = self::is_switched_on();

			// Settled already: switched on and paused, or with nothing tested to
			// pause; switched off and the test forgotten. The names went on the
			// way here, PHP makes no new ones, and a page that was being built when
			// the folder closed takes its own back (link_hour_names()), so only the
			// block may still have to go, which is all a read only .htaccess keeps
			// from happening. Walking the whole cache and saving the option again
			// on every load of the dashboard, as the first builds without the
			// hourly check did, only cost time while the file stayed read only.
			$settled = $on ? ( ! is_array( $verified ) || ! empty( $verified['paused'] ) ) : ! is_array( $verified );

			if ( $settled ) {
				if ( $present ) {
					self::write_block( null );
				}
				return;
			}

			if ( $on ) {
				self::pause();
			} else {
				self::remove();
			}
			return;
		}

		if ( is_array( $verified ) && ! empty( $verified['paused'] ) ) {
			unset( $verified['paused'] );
			update_option( self::VERIFIED_OPTION, $verified, true );
		}

		// On nginx only the cache folder, which PHP reads before it names a copy,
		// and an .htaccess block from an Apache past.
		if ( 'nginx' === self::server() ) {
			Core_Diet_Cache_Store::write_hardening( self::current_cache_dir_rules() );
			if ( $present ) {
				self::write_block( null );
			}
			return;
		}

		$context = self::get_rule_context();
		if ( ! is_array( $context ) || self::build_rules( $context ) !== self::read_block() ) {
			self::sync();
		} else {
			Core_Diet_Cache_Store::write_hardening( self::current_cache_dir_rules() );
		}
	}

	/* ============================
	 * Clock and hour names
	 * ============================ */

	/**
	 * Secret the rules write into the clock header, so PHP can tell it apart from
	 * one a visitor sends.
	 *
	 * Sixteen hex characters from random_bytes(), not from a WordPress helper:
	 * this runs while the rules are built, which the release checks do without
	 * WordPress. It lives in its own option because it has to outlive a test
	 * that is forgotten, and it is rewritten with the rules whenever it changes:
	 * maybe_sync() compares the whole block and rewrites it when it differs.
	 *
	 * @return string
	 */
	public static function clock_key() {
		$key = get_option( self::CLOCK_KEY_OPTION );

		if ( is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{16}$/D', $key ) ) {
			return $key;
		}

		try {
			$key = bin2hex( random_bytes( 8 ) );
		} catch ( Exception $e ) {
			// No secret rather than a weak one: without it the rules leave the
			// header out and the clock travels only in the environment variable,
			// which is what every other server hands PHP anyway.
			return '';
		}
		update_option( self::CLOCK_KEY_OPTION, $key, true );

		return $key;
	}

	/**
	 * Difference between the server clock the rules read and UTC, in seconds.
	 *
	 * The rules hand %{TIME} to PHP in an environment variable, so a request that
	 * went through them knows the local time of the server, daylight saving
	 * included, without guessing a time zone. Rounded to the quarter hour, which
	 * absorbs the seconds between the rewrite and PHP; every time zone in use is
	 * a multiple of fifteen minutes.
	 *
	 * @param int|null $now Reference time, for tests.
	 * @return int|null Null when the request did not go through the rules.
	 */
	public static function server_clock_offset( $now = null ) {
		$raw = '';

		foreach ( array( self::CLOCK_VAR, 'REDIRECT_' . self::CLOCK_VAR ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				break;
			}
		}

		/*
		 * Some Apache setups never hand PHP the environment variables the rules
		 * set (a site on SiteGround, measured on 16 sep 2026: the rules run, the
		 * rewrite works, and $_SERVER has nothing). There the rules repeat the
		 * clock in a request header, which every way of running PHP does pass.
		 * A header is chosen by whoever calls, so it only counts with the secret
		 * the rules write next to it: RequestHeader set replaces whatever the
		 * visitor sent (measured), and the secret is in a file nobody can read
		 * from the web. Without it, a forged clock could only misname the copies
		 * of the pages that visitor asks for, which the server then does not
		 * serve, because reaching them needs the redirect of the rules.
		 */
		if ( '' === $raw && ! empty( $_SERVER[ self::clock_header_key() ] ) ) {
			$sent  = sanitize_text_field( wp_unslash( $_SERVER[ self::clock_header_key() ] ) );
			$parts = explode( ':', $sent, 2 );
			$secreto = self::clock_key();
			if ( 2 === count( $parts ) && '' !== $secreto && hash_equals( $secreto, $parts[0] ) ) {
				$raw = $parts[1];
			}
		}

		// nginx hands over $time_iso8601, which carries its own offset.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|([+-])(\d{2}):?(\d{2}))$/', $raw, $iso ) ) {
			$offset = 'Z' === $iso[1] ? 0 : ( '-' === $iso[2] ? -1 : 1 ) * ( (int) $iso[3] * HOUR_IN_SECONDS + (int) $iso[4] * MINUTE_IN_SECONDS );
			return abs( $offset ) > 14 * HOUR_IN_SECONDS ? null : $offset;
		}

		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $raw, $m ) ) {
			return null;
		}

		$local = gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );

		if ( null === $now ) {
			$now = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();
		}

		$offset = (int) ( round( ( $local - (int) $now ) / 900 ) * 900 );

		return abs( $offset ) > 14 * HOUR_IN_SECONDS ? null : $offset;
	}

	/**
	 * The $_SERVER key the clock header arrives under.
	 *
	 * @return string
	 */
	private static function clock_header_key() {
		return 'HTTP_' . strtoupper( str_replace( '-', '_', self::CLOCK_HEADER ) );
	}

	/**
	 * Hour stamps, in server time, whose whole hour a copy stays valid in.
	 *
	 * The hour that is running counts when it ends before the copy expires.
	 *
	 * @param int      $offset      Server clock offset.
	 * @param int      $now         Current UTC time.
	 * @param int|null $valid_until UTC time the copy expires at, null for never.
	 * @return string[] Stamps as YYYYMMDDHH.
	 */
	public static function hour_stamps( $offset, $now, $valid_until ) {
		$local  = (int) $now + (int) $offset;
		$start  = $local - ( $local % HOUR_IN_SECONDS );
		$stamps = array();

		for ( $i = 0; $i < self::HOUR_NAMES_MAX; $i++ ) {
			$hour_start = $start + $i * HOUR_IN_SECONDS;
			$hour_end   = $hour_start + HOUR_IN_SECONDS - (int) $offset;

			if ( null !== $valid_until && $hour_end > $valid_until ) {
				break;
			}

			$stamps[] = gmdate( 'YmdH', $hour_start );
		}

		return $stamps;
	}

	/**
	 * Link a stored copy under the names of the hours it is valid in.
	 *
	 * Names of the same copy outside that window are removed: they point at an
	 * older version of the page, or at hours that are over. Only file
	 * operations, no options, because this runs inside the output buffer
	 * handler of the capture.
	 *
	 * @param string   $dir         Folder of the page.
	 * @param bool     $https       HTTPS copy.
	 * @param bool     $slash       Trailing slash copy.
	 * @param bool     $mobile      Copy built for a phone.
	 * @param string   $source      Absolute path of the stored copy.
	 * @param int|null $valid_until UTC time the copy expires at, null for never.
	 * @param int|null $offset      Server clock offset; null does nothing.
	 * @param int|null $now         Current UTC time, for tests.
	 * @return int Names now pointing at the copy.
	 */
	public static function link_hour_names( $dir, $https, $slash, $mobile, $source, $valid_until, $offset, $now = null ) {
		if ( null === $offset || ! function_exists( 'link' ) || ! self::folder_serves_names() ) {
			return 0;
		}

		$now   = null === $now ? time() : (int) $now;
		$base  = Core_Diet_Cache_Store::hour_name_base( $https, $slash, $mobile );
		$inode = @fileinode( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The copy can be purged between the write and this line.
		if ( ! $inode ) {
			return 0;
		}

		$want = array();
		foreach ( self::hour_stamps( $offset, $now, $valid_until ) as $stamp ) {
			$want[ $base . '.' . $stamp . '.html' ] = true;
		}

		$linked = 0;

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- Hard links inside the plugin's own cache folder during an anonymous request, where WP_Filesystem has no credentials; any of these can race with a purge and is allowed to fail quietly.
		foreach ( array_keys( $want ) as $name ) {
			$target = $dir . '/' . $name;

			if ( @fileinode( $target ) === $inode ) {
				++$linked;
				continue;
			}

			$tmp = $dir . '/.' . uniqid( 'dh', true ) . '.tmp';

			if ( ! @link( $source, $tmp ) ) {
				break;
			}

			if ( ! @rename( $tmp, $target ) ) {
				wp_delete_file( $tmp );
				break;
			}

			// rename() onto another name of the same file does nothing and
			// reports success, leaving the temporary name behind.
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			// A purge that ran whole between link() and rename() deleted the copy
			// and listed the names before this one existed: the name would outlive
			// the purge with the page it was removing. The copy tells.
			clearstatcache( true, $source );
			if ( @fileinode( $source ) !== $inode ) {
				wp_delete_file( $target );
				break;
			}

			++$linked;
		}
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

		foreach ( (array) glob( $dir . '/' . $base . '.[0-9]*.html' ) as $file ) {
			$name = basename( (string) $file );
			if ( ! isset( $want[ $name ] ) && Core_Diet_Cache_Store::is_hour_name( $name ) && 0 === strpos( $name, $base . '.' ) ) {
				wp_delete_file( $file );
			}
		}

		// The folder is read again once the names exist. remove() closes it
		// before it walks the tree, so a name linked before the close is found
		// by that walk, and one linked after is found here. The first read alone
		// left open the time between the start of the page and its last link:
		// milliseconds on Apache, the whole build of the page on nginx, where
		// nothing else stands in between.
		if ( $linked > 0 && ! self::folder_serves_names() ) {
			foreach ( (array) glob( $dir . '/' . $base . '.[0-9]*.html' ) as $file ) {
				$name = basename( (string) $file );
				if ( Core_Diet_Cache_Store::is_hour_name( $name ) && 0 === strpos( $name, $base . '.' ) ) {
					wp_delete_file( $file );
				}
			}
			return 0;
		}

		return $linked;
	}

	/**
	 * Whether the server may serve a name PHP is about to create.
	 *
	 * Only while the cache folder is open to the names. On Apache and LiteSpeed a
	 * name in a closed folder is an error 403 for every visitor of that page for
	 * as long as the name lasts, and the folder gets closed with the block of
	 * rules still in .htaccess when that file cannot be written any more: a
	 * security plugin or a host that makes it read only, for instance. nginx
	 * ignores the file, but it is opened and closed there too, and it is the one
	 * thing a page being built can read, without touching an option, to learn
	 * that the accelerator stopped meanwhile: the rules pasted into nginx would
	 * serve its names until they expire. Reading it here, right before linking
	 * and once more after, keeps the two in step whoever closed the folder.
	 *
	 * @return bool
	 */
	private static function folder_serves_names() {
		$file = Core_Diet_Cache_Store::get_root() . '/.htaccess';

		clearstatcache( true, $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- The rules of the plugin's own cache folder, read inside the output buffer handler of the capture, where no option may be touched.
		$rules = is_readable( $file ) ? (string) @file_get_contents( $file ) : '';

		return 0 === strpos( $rules, self::SERVING_RULES_HEAD . "\n" );
	}

	/**
	 * Put the hour names back for a copy PHP just served, when they are missing.
	 *
	 * A hit served by PHP on a request that went through the rules means the
	 * server did not find the name of this hour: the copy was stored before the
	 * accelerator was on, or its names ran out. One stat decides, so a hit that
	 * the rules skipped for another reason (a tracking parameter, a cookie)
	 * costs nothing.
	 *
	 * @param string $dir             Folder of the page.
	 * @param bool   $https           HTTPS copy.
	 * @param bool   $slash           Trailing slash copy.
	 * @param bool   $mobile          Copy built for a phone.
	 * @param string $file            Absolute path of the copy.
	 * @param int    $mtime           Its modification time.
	 * @param int    $ttl             Lifetime in seconds, 0 for never.
	 * @param bool   $is_test_request Whether this is a request of the self test.
	 */
	public static function maybe_renew_hour_names( $dir, $https, $slash, $mobile, $file, $mtime, $ttl, $is_test_request = false ) {
		$offset = self::server_clock_offset();
		if ( null === $offset || ! self::may_name_copies( $is_test_request ) ) {
			return;
		}

		$now     = time();
		$stamps  = self::hour_stamps( $offset, $now, null );
		$current = $dir . '/' . Core_Diet_Cache_Store::hour_name_base( $https, $slash, $mobile ) . '.' . reset( $stamps ) . '.html';

		if ( file_exists( $current ) ) {
			return;
		}

		self::link_hour_names( $dir, $https, $slash, $mobile, $file, $ttl > 0 ? (int) $mtime + (int) $ttl : null, $offset, $now );
	}
}
