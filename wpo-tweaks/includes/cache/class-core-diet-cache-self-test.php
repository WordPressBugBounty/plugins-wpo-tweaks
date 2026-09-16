<?php
/**
 * Page cache self test: the site asks for its own home page, as a stranger would.
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

	/** @var string Transient holding the token of the test in progress. */
	const TOKEN_TRANSIENT = 'core_diet_cache_diagnose';

	/** @var string Request header that carries the token. */
	const TOKEN_HEADER = 'X-DietPress-Diagnose';

	/**
	 * Ask the site for its home page twice and explain what came back.
	 *
	 * @return array {
	 *     @type bool      $ok         Whether the page came from the cache.
	 *     @type bool      $static     Whether the server served it without PHP.
	 *     @type bool|null $compressed Whether that copy came compressed.
	 *     @type string    $message    Sentence to show.
	 * }
	 */
	public static function run() {
		if ( ! Core_Diet_Cache::is_enabled() ) {
			return self::result( false, false, null, __( 'The page cache is off, so there is nothing to test.', 'wpo-tweaks' ) );
		}

		$token = wp_generate_password( 40, false, false );
		set_transient( self::TOKEN_TRANSIENT, $token, 2 * MINUTE_IN_SECONDS );

		$args = array(
			'timeout'    => 10,
			'sslverify'  => false,
			'headers'    => array(
				'Accept-Encoding'  => 'gzip',
				self::TOKEN_HEADER => $token,
			),
			'user-agent' => 'DietPress cache self test',
		);

		$url   = home_url( '/' );
		$first = self::fetch( $url, $args );

		if ( is_wp_error( $first['response'] ) ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return self::result( false, false, null, self::describe_error( $first['response'] ) );
		}

		$second = self::fetch( $url, $args, $first['direct'] );

		if ( is_wp_error( $second['response'] ) ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return self::result( false, false, null, self::describe_error( $second['response'] ) );
		}

		$result = self::blame_error( self::read( $second['response'], $second['direct'] ), $second, $url, $args );

		delete_transient( self::TOKEN_TRANSIENT );

		return $result;
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
			sprintf( __( 'The server answers the home page with an error (%d) where the accelerator serves it, and with the page when the accelerator steps aside: it cannot read the stored copies, because the cache folder is a symbolic link it does not follow, for instance.', 'wpo-tweaks' ), $code ) . self::route_note( $second['direct'] )
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
	 * Explain the second answer: the first one only puts a copy in place.
	 *
	 * @param array $second Second response.
	 * @param bool  $direct Whether it had to go straight to this server.
	 * @return array
	 */
	private static function read( $second, $direct ) {
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
					? __( 'The server answered the home page from the cache folder with something that is not a page DietPress stored, or a layer in front of the site removed the comment DietPress adds to every stored page, as some minifiers do.', 'wpo-tweaks' ) . $route
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The server answered the home page from the cache folder with an error (%d) instead of the page: a rule of the server, or the permissions of the folder, keep it from reading the stored copy.', 'wpo-tweaks' ), $code ) . $route
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
				sprintf( __( 'The home page answered with a server error (%d). Check the error log of your hosting.', 'wpo-tweaks' ), $code ) . $route
			);
			return $result;
		}

		if ( 403 === $code && 'HIT-STATIC' !== $state ) {
			return self::result( false, false, null, __( 'The home page was refused (403). A firewall or security plugin may be blocking requests the site makes to itself.', 'wpo-tweaks' ) . $route );
		}

		if ( 'HIT-STATIC' === $state ) {
			// The copy the server handed out has to be the one of the scheme that
			// was asked for. Rules that read HTTPS from another place than PHP
			// does (on nginx, a variable other than the one fastcgi_param HTTPS
			// uses) serve the plain copy to HTTPS visitors, with its styles and
			// scripts on http://, which browsers block.
			$other = self::served_other_scheme( wp_remote_retrieve_body( $second ) );
			if ( '' !== $other ) {
				return self::result( false, false, null, $other . $route );
			}

			$encoding   = strtolower( self::header( $second, 'content-encoding' ) );
			$compressed = '' !== $encoding && 'identity' !== $encoding;
			$message    = __( 'The home page was served by the server straight from the cache, without starting PHP. The accelerator is working.', 'wpo-tweaks' );

			if ( ! $compressed ) {
				$message .= ' ' . __( 'The server sent it uncompressed, though. Switching on the compression rules of DietPress, or asking your hosting to enable mod_deflate, would make it several times lighter.', 'wpo-tweaks' );
			}

			return self::result( true, true, $compressed, $message . $route );
		}

		if ( 'HIT' === $state ) {
			if ( ! $on ) {
				return self::result( true, false, null, __( 'The home page was served from the cache. The engine is working.', 'wpo-tweaks' ) . $route );
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

			return self::result( true, false, null, __( 'The home page was served from the cache by PHP, not by the server.', 'wpo-tweaks' ) . ' ' . $why . $route );
		}

		$proxy = self::proxy_note();

		if ( '' !== $reason ) {
			return self::result(
				false,
				false,
				null,
				/* translators: %s: the reason the engine gave, in English, such as "cookie woocommerce_". */
				sprintf( __( 'The home page is being skipped by the cache. Reason given by DietPress for this request: %s.', 'wpo-tweaks' ), $reason ) . ' ' . self::advice_for( $reason ) . $proxy . $route
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
					sprintf( __( 'The home page is cacheable, but it was not stored. Reason given by DietPress: %s.', 'wpo-tweaks' ), $stored ) . ' ' . self::advice_for( $stored ) . $proxy . $route
				);
			}

			return self::result( false, false, null, __( 'The home page is cacheable but was rebuilt instead of served from disk. The most likely cause is that the cache directory cannot be written to, or that something purges the cache on every request.', 'wpo-tweaks' ) . $proxy . $route );
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
			return self::result( false, false, null, __( 'The home page asks for a password (401), as a site closed at server level while it is being built does, so the test cannot see it the way a visitor would. Test the cache again once the site is open.', 'wpo-tweaks' ) . $route );
		}
		if ( 429 === $code ) {
			return self::result( false, false, null, __( 'The home page was refused for too many requests (429). A firewall, a security plugin or a rate limit of the hosting may be blocking requests the site makes to itself.', 'wpo-tweaks' ) . $route );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return self::result( false, false, null, sprintf( __( 'The home page answered with an error (%d) that did not come from WordPress with DietPress, so the test cannot say anything about the cache.', 'wpo-tweaks' ), $code ) . $route );
		}

		return self::result( false, false, null, __( 'The answer did not come from WordPress with DietPress. The home page may be a static file the server hands out by itself (an index.html in the root of the site, for instance), or a proxy or CDN may have answered from its own cache. In both cases the rest of the pages can still be cached normally.', 'wpo-tweaks' ) . $route );
	}

	/**
	 * Whether the server handed out the copy of the other scheme.
	 *
	 * Compared with what is on disk rather than guessed from the links, so a site
	 * that already prints http:// addresses on its HTTPS pages is not blamed for
	 * it. When neither copy matches, a purge or a rebuild got in between, and the
	 * test does not claim anything.
	 *
	 * @param string $body Body the server served.
	 * @return string Sentence explaining the mismatch, or empty.
	 */
	private static function served_other_scheme( $body ) {
		$url = home_url( '/' );
		$dir = Core_Diet_Cache_Store::dir_for_url( $url );
		if ( ! $dir || '' === (string) $body ) {
			return '';
		}

		$https = 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
		$mine  = $dir . '/' . Core_Diet_Cache_Store::filename( $https, true );
		$other = $dir . '/' . Core_Diet_Cache_Store::filename( ! $https, true );
		$hash  = md5( (string) $body );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Comparing with the plugin's own cached copies on disk.
		if ( is_readable( $mine ) && md5( (string) file_get_contents( $mine ) ) === $hash ) {
			return '';
		}
		if ( ! is_readable( $other ) || md5( (string) file_get_contents( $other ) ) !== $hash ) {
			return '';
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

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
			return __( 'The home page matches one of the patterns in the exclusions section of this tab.', 'wpo-tweaks' );
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
		if ( 'response sets a cookie' === $reason ) {
			return __( 'A plugin sets a cookie on every visit, and a page that sets a cookie is personal by definition.', 'wpo-tweaks' );
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
