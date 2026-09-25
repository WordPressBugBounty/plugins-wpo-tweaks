<?php
/**
 * Page cache self test: the site asks for one of its pages, as a stranger would.
 *
 * Doing it from the server means the answer is about the site and not about
 * the browser of the administrator, who is logged in and never gets a cached
 * page anyway. Used by the "Test the cache now" button and by the accelerator,
 * which only stays on when this test gets a page served by the server.
 *
 * Up to 3.5.6 it could only say "hit", "rebuilt" or "skipped", and for the
 * skipped case it sent people to enable WP_DEBUG and read an HTML comment that
 * only exists when the reason is decided during the render. A visit kept out
 * earlier (a cookie, the proxy headers, an exclusion, a filter) left nothing to
 * read. Now the test carries a one-time token, and for that request alone the
 * engine names the reason in a response header.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Self_Test {

	/** @var string Prefix of the transients holding the token of each test in progress; see Core_Diet_Cache_Engine::diagnose_key(). */
	const TOKEN_TRANSIENT = 'core_diet_cache_diagnose';

	/** @var string Request header that carries the token. */
	const TOKEN_HEADER = 'X-DietPress-Diagnose';

	/** @var string Header with a value of its own for each request, which the engine sends back. */
	const PROBE_HEADER = 'X-DietPress-Probe';

	/**
	 * Ask the site for a page twice and explain what came back.
	 *
	 * The page chosen in the Cache tab, or the home page. The accelerator always
	 * tests the home page (Core_Diet_Cache_Accelerator::enable()): whether the
	 * server serves the copies is a property of the rules, and the home page is
	 * the one every site has. Before 3.7.1 the button only ever tested the home
	 * page, under the field where a page is chosen for the purge.
	 *
	 * @param string $url Page to test, relative or absolute; empty for the home page.
	 * @return array {
	 *     @type bool      $ok         Whether the page came from the cache.
	 *     @type bool      $static     Whether the server served it without PHP.
	 *     @type bool|null $compressed Whether that copy came compressed.
	 *     @type string    $message    Sentence to show.
	 * }
	 */
	public static function run( $url = '' ) {
		if ( ! Core_Diet_Cache::is_enabled() ) {
			return self::result( false, false, null, __( 'The page cache is off, so there is nothing to test.', 'wpo-tweaks' ) );
		}

		$url = self::address_to_test( $url );
		if ( '' === $url ) {
			return self::result( false, false, null, __( 'That address is not a page of this site that the cache can store. Pick a page from the search, or paste its full address.', 'wpo-tweaks' ) );
		}

		// The engine names the transient; it is not loaded where the page cache
		// does not run on this request.
		if ( ! class_exists( 'Core_Diet_Cache_Engine', false ) ) {
			require_once CORE_DIET_DIR . 'includes/cache/class-core-diet-cache-engine.php';
		}

		// A transient of its own, so a test running at the same time neither
		// replaces this token nor deletes it.
		$token = wp_generate_password( 40, false, false );
		$key   = Core_Diet_Cache_Engine::diagnose_key( $token );
		set_transient( $key, $token, 2 * MINUTE_IN_SECONDS );

		$result = self::ask( $url, $token );

		delete_transient( $key );

		// Every message speaks of "the page", so it says which one it was: the
		// field it came from may have changed since the button was pressed.
		/* translators: %s: address of the page the test asked for. */
		$result['message'] .= ' ' . sprintf( __( 'Page tested: %s', 'wpo-tweaks' ), $url );

		return $result;
	}

	/**
	 * The two requests of the test, and what they say.
	 *
	 * @param string $url   Page to test.
	 * @param string $token Token of this test.
	 * @return array Result, as result() shapes it.
	 */
	private static function ask( $url, $token ) {
		$args = array(
			'timeout'    => 10,
			'sslverify'  => false,
			'headers'    => array(
				'Accept-Encoding'  => 'gzip',
				self::TOKEN_HEADER => $token,
			),
			'user-agent' => 'DietPress cache self test',
		);

		$args['headers'][ self::PROBE_HEADER ] = wp_generate_password( 20, false, false );

		$first = self::fetch( $url, $args );

		if ( is_wp_error( $first['response'] ) ) {
			return self::result( false, false, null, self::describe_error( $first['response'] ) );
		}

		$probe                                 = wp_generate_password( 20, false, false );
		$args['headers'][ self::PROBE_HEADER ] = $probe;

		$second = self::fetch( $url, $args, $first['direct'] );

		if ( is_wp_error( $second['response'] ) ) {
			return self::result( false, false, null, self::describe_error( $second['response'] ) );
		}

		$front = self::answered_in_front( $second['response'], $probe, $second['direct'] );
		if ( null !== $front ) {
			return $front;
		}

		return self::blame_error( self::read( $second['response'], $second['direct'], $url ), $second, $url, $args );
	}

	/**
	 * The address to test: the one chosen in the Cache tab, or the home page.
	 *
	 * Only a page of this very site the cache can store: the check the purge
	 * makes (Core_Diet_Cache_Store::dir_for_url(), the same host and a path the
	 * cache accepts), and under the address of the site, or a site in a subfolder
	 * would test the one next to it on the same host, whose engine does not know
	 * this token. The query string and the fragment go: the server never serves a
	 * copy to an address with a query string, and the copy the test checks is
	 * the one of the page itself.
	 *
	 * @param string $raw Address as typed or picked, relative or absolute.
	 * @return string Absolute address, or empty when it is not one to test.
	 */
	private static function address_to_test( $raw ) {
		$home = home_url( '/' );
		$raw  = trim( (string) $raw );

		if ( '' === $raw ) {
			return $home;
		}

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = home_url( '/' . ltrim( $raw, '/' ) );
		}

		$raw = substr( $raw, 0, strcspn( $raw, '?#' ) );

		if ( ! Core_Diet_Cache_Store::dir_for_url( $raw ) ) {
			return '';
		}

		$path      = (string) wp_parse_url( $raw, PHP_URL_PATH );
		$path      = '' === $path ? '/' : $path;
		$home_path = trailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );

		if ( 0 !== strpos( trailingslashit( $path ), $home_path ) ) {
			return '';
		}

		// The address asked for is the origin of the site and the path that was
		// checked, never the text that arrived: whatever the HTTP client makes of
		// a user name, a backslash or an odd scheme, the request goes to this site
		// and nowhere else, and a pasted http:// address gets the scheme the site
		// uses.
		return (string) preg_replace( '#^(https?://[^/?\#]+).*$#i', '$1', $home ) . $path;
	}

	/**
	 * Tell whether an error without the header of the rules is the accelerator's.
	 *
	 * The rules of the cache folder only add their header once the server gets
	 * that far: a symbolic link it does not follow fails before, with a bare 403,
	 * and so does a PHP error with a 500. Only with the accelerator on, the same
	 * request is asked again with no-cache, which the rules leave to PHP. A page
	 * then means the error comes from where the server serves the copies, and the
	 * test marks it broken; an error again means it is not the accelerator's, and
	 * switching it off would not help anyone.
	 *
	 * @param array  $result Result read from the second answer.
	 * @param array  $second Second answer, as fetch() returns it.
	 * @param string $url    Address tested.
	 * @param array  $args   Arguments of the requests.
	 * @return array
	 */
	private static function blame_error( array $result, array $second, $url, array $args ) {
		$code  = (int) wp_remote_retrieve_response_code( $second['response'] );
		$state = strtoupper( self::header( $second['response'], 'x-dietpress-cache' ) );

		if ( ! empty( $result['broken'] ) || 'HIT-STATIC' === $state || ! ( 403 === $code || 404 === $code || $code >= 500 ) || ! Core_Diet_Cache_Accelerator::is_switched_on() ) {
			return $result;
		}

		$args['headers']['Cache-Control'] = 'no-cache';
		$third = self::fetch( $url, $args, $second['direct'] );

		if ( is_wp_error( $third['response'] ) || 200 !== (int) wp_remote_retrieve_response_code( $third['response'] ) ) {
			return $result;
		}

		$result = self::result(
			false,
			false,
			null,
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Where the accelerator serves the page, the server answers with an error (%d), and when the accelerator steps aside it answers with the page: it cannot read the stored copies, because the cache folder is a symbolic link it does not follow, for instance.', 'wpo-tweaks' ), $code ) . self::route_note( $second['direct'] )
		);
		$result['broken'] = true;

		return $result;
	}

	/**
	 * The note added when the test had to go straight to this server.
	 *
	 * @param bool $direct Whether it did.
	 * @return string
	 */
	private static function route_note( $direct ) {
		return $direct ? ' ' . __( 'The request to the address of the site did not come back, so the test went straight to this server instead, past any CDN or proxy in front of it.', 'wpo-tweaks' ) : '';
	}

	/**
	 * The result to give when the second answer was not built for this test.
	 *
	 * The engine sends back the probe of each request of the test, so a page PHP
	 * built or served for that request carries it. An answer that says PHP built
	 * it and carries another probe, or none, is a copy of an earlier visit that
	 * something in front of the site kept and handed out. The dynamic cache of
	 * SiteGround does that with every page that goes out without Cache-Control,
	 * which is what WordPress pages do by default since 3.7.0, and until this
	 * check the test read the MISS of that copy as its own and blamed the cache
	 * folder (aulawp.com, 25 sep 2026).
	 *
	 * @param array  $response Second answer.
	 * @param string $probe    Probe sent with it.
	 * @param bool   $direct   Whether it went straight to this server.
	 * @return array|null Result, or null when the answer was built for this test.
	 */
	private static function answered_in_front( $response, $probe, $direct ) {
		$state = strtoupper( self::header( $response, 'x-dietpress-cache' ) );

		// Only answers that say PHP built them or served them. HIT-STATIC is the
		// server, which never runs PHP, and an answer without any header of the
		// engine is explained by read().
		if ( ! in_array( $state, array( 'HIT', 'MISS' ), true ) && '' === self::header( $response, 'x-dietpress-bypass' ) ) {
			return null;
		}

		if ( '' === (string) $probe || hash_equals( (string) $probe, self::header( $response, strtolower( self::PROBE_HEADER ) ) ) ) {
			return null;
		}

		return self::result( false, false, null, self::front_cache_message( self::front_cache_header( $response ) ) . self::route_note( $direct ) );
	}

	/**
	 * The header that shows a cache in front of the site answered, if one does.
	 *
	 * The ones the usual hosting caches and CDNs send, and Age, which any shared
	 * cache must send with a stored answer (RFC 9111, section 5.1). The value is
	 * reduced to plain characters: it is shown to the administrator.
	 *
	 * @param array $response Response.
	 * @return string "name: value", or empty.
	 */
	private static function front_cache_header( $response ) {
		$names = array( 'x-proxy-cache', 'cf-cache-status', 'x-cache', 'x-cache-status', 'x-litespeed-cache', 'x-kinsta-cache', 'x-sucuri-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'x-srcache-fetch-status', 'x-varnish-cache' );

		foreach ( $names as $name ) {
			$value = self::header( $response, $name );
			if ( '' !== $value && preg_match( '/\bhit\b/i', $value ) ) {
				return $name . ': ' . substr( (string) preg_replace( '#[^A-Za-z0-9 ,.:_/-]#', '', $value ), 0, 60 );
			}
		}

		$age = self::header( $response, 'age' );
		if ( '' !== $age && ctype_digit( $age ) && (int) $age > 0 ) {
			return 'age: ' . (int) $age;
		}

		return '';
	}

	/**
	 * Explain an answer that came from a cache in front of the site.
	 *
	 * @param string $front Header that gave it away, or empty.
	 * @return string
	 */
	private static function front_cache_message( $front ) {
		if ( '' === $front ) {
			return __( 'The answer was not built for this test: it lacks the mark DietPress puts on the pages it builds for the test. Most likely a cache in front of the site answered with a copy of an earlier visit, so the test never reached WordPress: purge the cache of your hosting or CDN and test again. If there is no such cache, the site did not recognise the request of the test.', 'wpo-tweaks' );
		}

		$message = sprintf(
			/* translators: %s: the response header that gave the cache away, such as "x-proxy-cache: HIT". */
			__( 'The page came from a cache in front of the site (%s), with a copy of an earlier visit, so the test never reached WordPress. Purge that cache and test again. If it keeps answering first, it is storing the pages DietPress builds, and purging DietPress does not reach them: use one of the two page caches, not both.', 'wpo-tweaks' ),
			$front
		);

		if ( 0 === strpos( $front, 'x-proxy-cache:' ) ) {
			$message .= ' ' . __( 'On SiteGround that cache is its dynamic cache: purge it from Speed Optimizer or from Site Tools.', 'wpo-tweaks' );
		}

		return $message;
	}

	/**
	 * Explain the second answer: the first one only puts a copy in place.
	 *
	 * @param array  $second Second response.
	 * @param bool   $direct Whether it had to go straight to this server.
	 * @param string $url    Page tested; empty for the home page.
	 * @return array
	 */
	private static function read( $second, $direct, $url = '' ) {
		$code   = (int) wp_remote_retrieve_response_code( $second );
		$state  = strtoupper( self::header( $second, 'x-dietpress-cache' ) );
		$reason = self::header( $second, 'x-dietpress-bypass' );
		$clock  = self::header( $second, 'x-dietpress-clock' );
		$route  = self::route_note( $direct );
		$on     = Core_Diet_Cache_Accelerator::is_switched_on();

		// The server rules send their header with any answer from the cache
		// folder, errors included, and only check that the name exists, not that
		// it can be read. So a copy counts as served when it is a 200 carrying the
		// footprint the capture adds to every stored page, and anything else from
		// that folder is the accelerator failing visitors.
		$body = (string) wp_remote_retrieve_body( $second );
		if ( 'HIT-STATIC' === $state && ( 200 !== $code || false === strpos( $body, '<!-- Page cached by DietPress on' ) ) ) {
			$result           = self::result(
				false,
				false,
				null,
				200 === $code
					? __( 'The server answered the page from the cache folder with something that is not a page DietPress stored, or a layer in front of the site removed the comment DietPress adds to every stored page, as some minifiers do.', 'wpo-tweaks' ) . $route
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The server answered from the cache folder with an error (%d) instead of the page: a rule of the server, or the permissions of the folder, keep it from reading the stored copy.', 'wpo-tweaks' ), $code ) . $route
			);
			$result['broken'] = true;
			return $result;
		}

		if ( $code >= 500 ) {
			$result = self::result(
				false,
				false,
				null,
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The page answered with a server error (%d). Check the error log of your hosting.', 'wpo-tweaks' ), $code ) . $route
			);
			return $result;
		}

		if ( 403 === $code && 'HIT-STATIC' !== $state ) {
			return self::result( false, false, null, __( 'The page was refused (403). A firewall or security plugin may be blocking requests the site makes to itself.', 'wpo-tweaks' ) . $route );
		}

		if ( 'HIT-STATIC' === $state ) {
			// The copy the server handed out has to be the one of the scheme that
			// was asked for. Rules that read HTTPS from another place than PHP
			// does (on nginx, a variable other than the one fastcgi_param HTTPS
			// uses) serve the plain copy to HTTPS visitors, with its styles and
			// scripts on http://, which browsers block.
			$other = self::served_other_scheme( wp_remote_retrieve_body( $second ), $url );
			if ( '' !== $other ) {
				return self::result( false, false, null, $other . $route );
			}

			$encoding   = strtolower( self::header( $second, 'content-encoding' ) );
			$compressed = '' !== $encoding && 'identity' !== $encoding;
			$message    = __( 'The page was served by the server straight from the cache, without starting PHP. The accelerator is working.', 'wpo-tweaks' );

			if ( ! $compressed ) {
				$message .= ' ' . __( 'The server sent it uncompressed, though. Switching on the compression rules of DietPress, or asking your hosting to enable mod_deflate, would make it several times lighter.', 'wpo-tweaks' );
			}

			return self::result( true, true, $compressed, $message . $route );
		}

		if ( 'HIT' === $state ) {
			if ( ! $on ) {
				return self::result( true, false, null, __( 'The page was served from the cache. The engine is working.', 'wpo-tweaks' ) . $route );
			}

			if ( 'nginx' === Core_Diet_Cache_Accelerator::server() ) {
				$why = 'seen' === $clock
					? __( 'nginx passes its clock to PHP, so part 4 of the rules is in place, but it did not serve the stored copy: check parts 1 to 3, and that nginx was reloaded after pasting them.', 'wpo-tweaks' )
					: __( 'nginx did not pass its clock to PHP: part 4 of the rules is missing from the location that sends PHP files to PHP-FPM, or nginx was not reloaded after pasting the rules.', 'wpo-tweaks' );
			} else {
				$why = 'seen' === $clock
					? __( 'The server read the accelerator rules but did not find a copy it may serve for this hour, or could not open the cache folder. PHP put the copy in place, so the next visit should be served by the server; if it is not, the content folder may be served from a path the rules do not know.', 'wpo-tweaks' )
					: __( 'The server did not run the accelerator rules: mod_rewrite or mod_headers may be missing, the hosting may not allow rules in .htaccess, or another rule answers the request first.', 'wpo-tweaks' );
			}

			return self::result( true, false, null, __( 'The page was served from the cache by PHP, not by the server.', 'wpo-tweaks' ) . ' ' . $why . $route );
		}

		$proxy = self::proxy_note();

		if ( '' !== $reason ) {
			return self::result(
				false,
				false,
				null,
				/* translators: %s: the reason the engine gave, in English, such as "cookie woocommerce_". */
				sprintf( __( 'The page is being skipped by the cache. Reason given by DietPress for this request: %s.', 'wpo-tweaks' ), $reason ) . ' ' . self::advice_for( $reason ) . $proxy . $route
			);
		}

		if ( 'MISS' === $state ) {
			$stored = self::not_stored_reason( wp_remote_retrieve_body( $second ) );

			if ( '' !== $stored ) {
				return self::result(
					false,
					false,
					null,
					/* translators: %s: the reason the engine gave, in English, such as "response sets a cookie". */
					sprintf( __( 'The page is cacheable, but it was not stored. Reason given by DietPress: %s.', 'wpo-tweaks' ), $stored ) . ' ' . self::advice_for( $stored ) . $proxy . $route
				);
			}

			return self::result( false, false, null, __( 'The page is cacheable but was rebuilt instead of served from disk. The most likely cause is that the cache directory cannot be written to, or that something purges the cache on every request.', 'wpo-tweaks' ) . $proxy . $route );
		}

		// A stored copy with none of the headers: the server served the file but
		// skipped the headers of the rules, so without the Cache-Control that
		// keeps browsers from keeping the page on their own. The rules wait for
		// mod_headers on Apache; a server that reads only the rewrite rules of
		// .htaccess serves anyway. The accelerator must not run so.
		if ( false !== strpos( $body, '<!-- Page cached by DietPress on' ) ) {
			$result               = self::result( false, false, null, __( 'The server served the stored copy by itself, but without the headers the accelerator sends with it, so browsers could keep the page on their own and a purge would not reach them. This server does not apply the headers set in .htaccess, or reads only the rewrite rules from that file. The accelerator cannot run here.', 'wpo-tweaks' ) . $route );
			$result['headerless'] = true;
			return $result;
		}

		// Nothing from DietPress at all: the request never reached the engine.
		// An error says why better than a guess about static files does.
		if ( 401 === $code ) {
			return self::result( false, false, null, __( 'The page asks for a password (401), as a site closed at server level while it is being built does, so the test cannot see it the way a visitor would. Test the cache again once the site is open.', 'wpo-tweaks' ) . $route );
		}
		if ( 429 === $code ) {
			return self::result( false, false, null, __( 'The page was refused for too many requests (429). A firewall, a security plugin or a rate limit of the hosting may be blocking requests the site makes to itself.', 'wpo-tweaks' ) . $route );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return self::result( false, false, null, sprintf( __( 'The page answered with an error (%d) that did not come from WordPress with DietPress, so the test cannot say anything about the cache.', 'wpo-tweaks' ), $code ) . $route );
		}

		// A cache in front that says so is named, instead of guessed at below.
		$front = self::front_cache_header( $second );
		if ( '' !== $front ) {
			return self::result( false, false, null, self::front_cache_message( $front ) . $route );
		}

		return self::result( false, false, null, __( 'The answer did not come from WordPress with DietPress. The page may be a static file the server hands out by itself (an index.html in its folder, for instance), or a proxy or CDN may have answered from its own cache. In both cases the rest of the pages can still be cached normally.', 'wpo-tweaks' ) . $route );
	}

	/**
	 * Whether the server handed out the copy of the other scheme.
	 *
	 * Compared with what is on disk rather than guessed from the links, so a site
	 * that already prints http:// addresses on its HTTPS pages is not blamed for
	 * it. When neither copy matches, a purge or a rebuild got in between, and the
	 * test does not claim anything.
	 *
	 * Both names of each scheme are compared, with the trailing slash and
	 * without: a page asked for without its slash is redirected to it, and the
	 * copy served is then the one of the other name, which lives in the same
	 * folder. Reading only the name of the address asked for skipped the check
	 * without a word for those pages (second cross review of 3.7.1); the home
	 * page, the only one tested before, always has its slash.
	 *
	 * @param string $body Body the server served.
	 * @param string $url  Page tested; empty for the home page.
	 * @return string Sentence explaining the mismatch, or empty.
	 */
	private static function served_other_scheme( $body, $url = '' ) {
		$url = '' === $url ? home_url( '/' ) : $url;
		$dir = Core_Diet_Cache_Store::dir_for_url( $url );
		if ( ! $dir || '' === (string) $body ) {
			return '';
		}

		$https   = 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
		$hash    = md5( (string) $body );
		$matches = static function ( $scheme_https ) use ( $dir, $hash ) {
			foreach ( array( true, false ) as $slash ) {
				$file = $dir . '/' . Core_Diet_Cache_Store::filename( $scheme_https, $slash );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Comparing with the plugin's own cached copies on disk.
				if ( is_readable( $file ) && md5( (string) file_get_contents( $file ) ) === $hash ) {
					return true;
				}
			}
			return false;
		};

		if ( $matches( $https ) || ! $matches( ! $https ) ) {
			return '';
		}

		return 'nginx' === Core_Diet_Cache_Accelerator::server()
			? __( 'nginx served the copy stored for the other scheme, so its rules read HTTPS from a different variable than the one PHP gets: in part 1, set $dietpress_https to the variable your fastcgi_param HTTPS uses, and reload nginx.', 'wpo-tweaks' )
			: __( 'The server served the copy stored for the other scheme, so its rules and PHP disagree about HTTPS on this site. The accelerator cannot be used safely here until they agree.', 'wpo-tweaks' );
	}

	/**
	 * One piece of advice for the reasons that come back most often.
	 *
	 * @param string $reason Reason as the engine words it.
	 * @return string
	 */
	private static function advice_for( $reason ) {
		if ( 0 === strpos( $reason, 'cookie ' ) ) {
			return __( 'A visitor carrying that cookie is not anonymous for the cache. The test sends no cookies, so something on the site is setting it during the request.', 'wpo-tweaks' );
		}
		if ( 'excluded URL' === $reason ) {
			return __( 'The page matches one of the patterns in the exclusions section of this tab.', 'wpo-tweaks' );
		}
		if ( 0 === strpos( $reason, 'dietpress_cache_bypass' ) || 'DONOTCACHEPAGE' === $reason ) {
			return __( 'A plugin or the theme declares the page uncacheable.', 'wpo-tweaks' );
		}
		if ( 'WooCommerce coming soon mode' === $reason || 'WooCommerce coming soon page' === $reason ) {
			return __( 'WooCommerce is showing its coming soon page. Pages are cached once the store is launched.', 'wpo-tweaks' );
		}
		if ( 0 === strpos( $reason, 'request port' ) || 0 === strpos( $reason, 'request host' ) ) {
			return __( 'The test reached the site under an address that is not the one set in Settings, General.', 'wpo-tweaks' );
		}
		if ( 0 === strpos( $reason, 'response sets a cookie' ) ) {
			$advice = __( 'A plugin sets a cookie on every visit, and a page that sets a cookie is personal by definition.', 'wpo-tweaks' );

			if ( 'response sets a cookie: PHPSESSID' === $reason ) {
				return $advice . ' ' . __( 'PHPSESSID is the cookie of a PHP session, which some plugin opens on every visit whether it needs one or not.', 'wpo-tweaks' );
			}

			return 'response sets a cookie' === $reason ? $advice : $advice . ' ' . __( 'Its name usually tells which plugin sets it.', 'wpo-tweaks' );
		}
		if ( 'response is marked private or no-store' === $reason ) {
			return __( 'A plugin or the theme sends a Cache-Control header that forbids shared caches to keep the page, usually with nocache_headers().', 'wpo-tweaks' );
		}

		return '';
	}

	/**
	 * The "not stored" reason the engine leaves at the end of the page for the test.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private static function not_stored_reason( $body ) {
		if ( preg_match( '/<!-- DietPress page cache: not stored \(([^)<>]{1,200})\) -->\s*$/', (string) $body, $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * The proxy note, when visits are being kept out for their proxy headers.
	 *
	 * @return string
	 */
	private static function proxy_note() {
		if ( ! class_exists( 'Core_Diet_Cache_Admin' ) || ! Core_Diet_Cache_Admin::has_proxy_mismatch() ) {
			return '';
		}

		return ' ' . __( 'Visits with proxy headers that disagree with the HTTPS WordPress detects are also being kept out of the cache: if this test goes through a proxy or CDN, that is the likely reason, and the status block of this tab explains the fix.', 'wpo-tweaks' );
	}

	/**
	 * Explain a request that did not come back at all.
	 *
	 * @param WP_Error $error Error from the HTTP API.
	 * @return string
	 */
	private static function describe_error( $error ) {
		$message = $error->get_error_message();
		$lower   = strtolower( $message );

		if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'error 28' ) || false !== strpos( $lower, 'could not resolve' ) || false !== strpos( $lower, 'connection refused' ) ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'The site could not reach itself, so the test says nothing about the cache: %s. The usual cause is a CDN such as Cloudflare, or a firewall of the hosting, that does not let the server call its own address. Visitors are not affected; check the cache from a private window instead, and look for the X-DietPress-Cache header of the page.', 'wpo-tweaks' ),
				$message
			);
		}

		return sprintf(
			/* translators: %s: error message. */
			__( 'The site could not reach itself, so the test says nothing about the cache: %s', 'wpo-tweaks' ),
			$message
		);
	}

	/**
	 * Request a URL, and when it does not come back, ask this server directly.
	 *
	 * Behind a CDN the request to the address of the site can loop through the
	 * CDN and time out. Pinning the host name to the address this request
	 * arrived on skips everything in front, and the certificate check is off
	 * already for sites with a certificate of their own.
	 *
	 * @param string $url    URL.
	 * @param array  $args   Request arguments.
	 * @param bool   $direct Go straight to this server from the start.
	 * @return array {
	 *     @type array|WP_Error $response Response.
	 *     @type bool           $direct   Whether it went straight to this server.
	 * }
	 */
	private static function fetch( $url, $args, $direct = false ) {
		if ( ! $direct ) {
			$response = wp_remote_get( $url, $args );
			if ( ! is_wp_error( $response ) ) {
				return array(
					'response' => $response,
					'direct'   => false,
				);
			}
		}

		$pin = self::pin_to_this_server( $url );
		if ( ! $pin ) {
			return array(
				'response' => isset( $response ) ? $response : wp_remote_get( $url, $args ),
				'direct'   => false,
			);
		}

		add_action( 'http_api_curl', $pin );
		$pinned = wp_remote_get( $url, $args );
		remove_action( 'http_api_curl', $pin );

		if ( is_wp_error( $pinned ) && isset( $response ) ) {
			return array(
				'response' => $response,
				'direct'   => false,
			);
		}

		return array(
			'response' => $pinned,
			'direct'   => true,
		);
	}

	/**
	 * A cURL hook that resolves the host of a URL to the address of this server.
	 *
	 * @param string $url URL.
	 * @return callable|null Null when there is no usable address.
	 */
	private static function pin_to_this_server( $url ) {
		if ( ! defined( 'CURLOPT_RESOLVE' ) || empty( $_SERVER['SERVER_ADDR'] ) ) {
			return null;
		}

		$address = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
		if ( ! filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}

		$port = wp_parse_url( $url, PHP_URL_PORT );
		$port = $port ? (int) $port : ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 443 : 80 );

		if ( false !== strpos( $address, ':' ) ) {
			$address = '[' . $address . ']';
		}

		$entry = $host . ':' . $port . ':' . $address;

		return static function ( $handle ) use ( $entry ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Through the http_api_curl action, the hook WordPress offers for this, and only for the two requests of the self test.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) );
		};
	}

	/**
	 * First value of a response header, as a string.
	 *
	 * @param array  $response Response.
	 * @param string $name     Header name.
	 * @return string
	 */
	private static function header( $response, $name ) {
		$value = wp_remote_retrieve_header( $response, $name );
		$value = is_array( $value ) ? (string) reset( $value ) : (string) $value;

		return trim( $value );
	}

	/**
	 * Shape a result.
	 *
	 * @param bool      $ok         Whether the page came from the cache.
	 * @param bool      $static     Whether the server served it without PHP.
	 * @param bool|null $compressed Whether that copy came compressed.
	 * @param string    $message    Sentence to show.
	 * @return array
	 */
	private static function result( $ok, $static, $compressed, $message ) {
		return array(
			'ok'         => (bool) $ok,
			'static'     => (bool) $static,
			'compressed' => $compressed,
			'message'    => $message,
		);
	}
}
