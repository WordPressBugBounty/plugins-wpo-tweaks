<?php
/**
 * Page cache storage: URL to path mapping, atomic writes, hardening, GC.
 *
 * There is no database index and no metadata file: the directory tree IS the
 * index. Purging a post or a term is deleting its directory, which takes its
 * pagination with it for free, and purging everything is deleting the root.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Store {

	/** @var int Longest path segment accepted, in bytes (filesystem limit is 255). */
	const MAX_SEGMENT = 200;

	/** @var int Deepest URL accepted. Anything below this is a crawler artifact. */
	const MAX_DEPTH = 12;

	/**
	 * Rules for the .htaccess of the cache root while nothing may be served from it.
	 *
	 * Byte for byte what 3.5.x wrote, so updating does not rewrite the file on
	 * the sites that never switch the accelerator on.
	 */
	const DENY_RULES = "# DietPress page cache. Files here are served by PHP, never directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	/**
	 * Absolute path of the cache root, with no trailing slash.
	 *
	 * @return string
	 */
	public static function get_root() {
		return WP_CONTENT_DIR . '/cache/dietpress';
	}

	/**
	 * Whether the cache root exists and can be written to.
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$root = self::get_root();

		if ( is_dir( $root ) ) {
			return wp_is_writable( $root );
		}

		// Not created yet: the answer is whether we would be able to create it.
		$parent = dirname( $root );
		if ( is_dir( $parent ) ) {
			return wp_is_writable( $parent );
		}

		return wp_is_writable( WP_CONTENT_DIR );
	}

	/**
	 * Create the cache root and its hardening files.
	 *
	 * The .htaccess of the folder closes it to the web, except for the copies
	 * the accelerator serves when it is on; both Apache syntaxes are written
	 * because 2.2 and 2.4 are still both out there on shared hosting. It is
	 * rewritten when it no longer says what it should, and left alone when it
	 * does.
	 *
	 * @param string|null $rules Rules for the folder. Null asks the accelerator,
	 *                           which reads options; the capture passes them
	 *                           in, because it runs inside an output buffer
	 *                           handler where no option may be touched.
	 * @return bool True when the root exists and is usable afterwards.
	 */
	public static function prepare( $rules = null ) {
		$root = self::get_root();

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		if ( null === $rules ) {
			$rules = class_exists( 'Core_Diet_Cache_Accelerator', false )
				? Core_Diet_Cache_Accelerator::current_cache_dir_rules()
				: self::DENY_RULES;
		}

		self::write_hardening( $rules );

		$index = $root . '/index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.FileWriteFound, WordPress.WP.AlternativeFunctions -- Silence file for the plugin's own cache directory; see above.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
		}

		return is_dir( $root ) && wp_is_writable( $root );
	}

	/**
	 * Write the .htaccess of the cache root when it says something else.
	 *
	 * Through a temporary file and rename(), so the server never reads half a
	 * file: with the accelerator on, a torn .htaccess here is an error 500 on
	 * every page it serves.
	 *
	 * @param string $rules Content.
	 * @return bool Whether the file holds that content afterwards.
	 */
	public static function write_hardening( $rules ) {
		$root = self::get_root();
		if ( ! is_dir( $root ) ) {
			return false;
		}

		$file = $root . '/.htaccess';

		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.FileWriteFound -- Hardening file for the plugin's own cache directory, written during requests where WP_Filesystem has no credentials.
		if ( is_readable( $file ) && (string) @file_get_contents( $file ) === (string) $rules ) {
			return true;
		}

		$tmp = $root . '/.' . uniqid( 'dp', true ) . '.tmp';
		if ( false === @file_put_contents( $tmp, (string) $rules, LOCK_EX ) ) {
			return false;
		}

		@chmod( $tmp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		if ( ! @rename( $tmp, $file ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.CodeAnalysis.WriteFile.FileWriteFound

		return true;
	}

	/**
	 * Map the current request to its cache directory.
	 *
	 * @return string|false Absolute directory path, or false when the request
	 *                      cannot be mapped safely.
	 */
	public static function dir_for_request() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		/*
		 * Not sanitize_text_field(): that one deletes every percent encoded
		 * sequence it finds, and a browser percent encodes every byte of a
		 * permalink that is not ASCII. So "/café/" arrived here as "/caf/" and
		 * shared its cache entry with "/cafá/", and a slug written entirely in
		 * a non Latin script (Cyrillic, Greek, CJK) collapsed to "/" and
		 * overwrote the home page. sanitize_url() keeps both the escapes and
		 * the raw high bytes; the segment allowlist in dir_for_path() is what
		 * actually decides whether a path may be put on disk.
		 */
		return self::dir_for_uri( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	}

	/**
	 * Map a request URI, as sanitize_url() leaves it, to its cache directory.
	 *
	 * The engine photographs the URI when the plugin loads and maps that, so
	 * code that rewrites REQUEST_URI during the request (a language plugin
	 * routing /en/about/ to the page of /about/, for instance) cannot make the
	 * copy be filed under another address. It is also the address the server
	 * sees, which is what the accelerator looks the copy up under.
	 *
	 * @param string $uri Request URI.
	 * @return string|false
	 */
	public static function dir_for_uri( $uri ) {
		$uri = strtok( (string) $uri, '?' );

		return self::dir_for_path( (string) $uri, self::get_request_host() );
	}

	/**
	 * Map an absolute URL to its cache directory.
	 *
	 * Used by the purge routines, which always rederive the path on the server
	 * instead of trusting anything that arrived in a request.
	 *
	 * @param string $url Absolute URL.
	 * @return string|false
	 */
	public static function dir_for_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = self::normalize_host( $parts['host'] );
		if ( $host !== self::get_home_host() ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';

		return self::dir_for_path( $path, $host );
	}

	/**
	 * Map a request path to a cache directory, refusing anything ambiguous.
	 *
	 * Two URLs must never land on the same file. Rather than sanitize a segment
	 * into something safe (which maps "/a b/" and "/a-b/" onto each other and
	 * eventually serves one page at the other's URL), a segment that is not
	 * already safe means this URL simply is not cached.
	 *
	 * @param string $path Decoded or encoded request path.
	 * @param string $host Already normalized host.
	 * @return string|false
	 */
	private static function dir_for_path( $path, $host ) {
		if ( '' === $host ) {
			return false;
		}

		/*
		 * An escaped separator is refused before anything is decoded. Decoding
		 * it would turn one URL segment into two, so "/a%2Fb/" would land on
		 * the directory of "/a/b/" and be answered with that page, when
		 * WordPress resolves the two to different things and usually 404s the
		 * first. Refusing costs nothing: the request falls through and is built
		 * normally. "%5C" goes with it because Windows accepts it as a
		 * separator, and "%00" because a truncated name is a name for another
		 * file.
		 */
		if ( preg_match( '#%(2f|5c|00)#i', $path ) ) {
			return false;
		}

		// One decode pass only: decoding twice is how "%252e%252e" becomes "..".
		$path = rawurldecode( $path );

		if ( false !== strpos( $path, "\0" ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return false;
		}
		if ( false !== strpos( $path, '\\' ) ) {
			return false;
		}

		$trimmed = trim( $path, '/' );
		if ( '' === $trimmed ) {
			return self::get_root() . '/' . $host;
		}

		$segments = explode( '/', $trimmed );
		if ( count( $segments ) > self::MAX_DEPTH ) {
			return false;
		}

		foreach ( $segments as $segment ) {
			// Empty means a double slash; dots are traversal or hidden files.
			if ( '' === $segment || '.' === $segment || '..' === $segment || '.' === $segment[0] ) {
				return false;
			}
			if ( strlen( $segment ) > self::MAX_SEGMENT ) {
				return false;
			}
			// Bytes above 0x7F are kept: accented permalinks are legitimate and
			// arrive identically on write and on read.
			if ( preg_match( '#[^A-Za-z0-9_\-.~\x80-\xFF]#', $segment ) ) {
				return false;
			}
		}

		return self::get_root() . '/' . $host . '/' . implode( '/', $segments );
	}

	/**
	 * Build the file name for a cached variant.
	 *
	 * The trailing slash is part of the name, not of the directory, because a
	 * directory cannot tell "/hello" from "/hello/". Those are different URLs
	 * and WordPress canonically redirects one to the other, so collapsing them
	 * onto one file would answer 200 where a 301 belongs.
	 *
	 * @param bool $https          Request is over HTTPS.
	 * @param bool $trailing_slash Request path ends with a slash.
	 * @param bool $mobile         Mobile variant.
	 * @param bool $gzip           Precompressed variant.
	 * @return string
	 */
	public static function filename( $https, $trailing_slash, $mobile = false, $gzip = false ) {
		$name = 'index';
		if ( ! $trailing_slash ) {
			$name .= '-ns';
		}
		if ( $mobile ) {
			$name .= '-mobile';
		}
		if ( $https ) {
			$name .= '-https';
		}
		$name .= '.html';
		if ( $gzip ) {
			$name .= '.gz';
		}
		return $name;
	}

	/**
	 * Start of the names a copy gets for the hours it is valid in.
	 *
	 * The copy built for a phone has its own names, so the server hands each
	 * device the copy WordPress would have built for it.
	 *
	 * @param bool $https          HTTPS copy.
	 * @param bool $trailing_slash Trailing slash copy.
	 * @param bool $mobile         Copy built for a phone.
	 * @return string
	 */
	public static function hour_name_base( $https, $trailing_slash, $mobile = false ) {
		return 'index' . ( $trailing_slash ? '' : '-ns' ) . ( $mobile ? '-mobile' : '' ) . ( $https ? '-https' : '' );
	}

	/**
	 * Whether a file name is one of those hour names.
	 *
	 * @param string $name File name, without its directory.
	 * @return bool
	 */
	public static function is_hour_name( $name ) {
		return (bool) preg_match( '/^index(-ns)?(-mobile)?(-https)?\.[0-9]{10}\.html$/', (string) $name );
	}

	/**
	 * Write a cached page and, optionally, its gzipped twin.
	 *
	 * The write is atomic: a temporary file in the same directory followed by
	 * rename(), so a visitor can never be served a half-written page.
	 *
	 * @param string      $dir       Target directory.
	 * @param string      $filename  Target file name.
	 * @param string      $contents  Page HTML.
	 * @param string|null $hardening Rules for the root, if it has to be created.
	 * @return bool
	 */
	public static function write( $dir, $filename, $contents, $hardening = null ) {
		// The root comes with its hardening files, not as a side effect of
		// creating a page directory: a cache folder deleted by hand or left out
		// by a migration used to come back without its .htaccess and index.php.
		if ( ! is_dir( self::get_root() ) && ! self::prepare( null === $hardening ? self::DENY_RULES : $hardening ) ) {
			return false;
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$tmp = $dir . '/.' . uniqid( 'dp', true ) . '.tmp';

		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.FileWriteFound, WordPress.WP.AlternativeFunctions -- A page cache writes during anonymous front-end requests, where WP_Filesystem has no credentials to ask for.
		$written = @file_put_contents( $tmp, $contents, LOCK_EX );
		if ( false === $written ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Same context as the write above.
		@chmod( $tmp, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Atomic publish of the temporary file written above.
		if ( ! @rename( $tmp, $dir . '/' . $filename ) ) {
			wp_delete_file( $tmp );
			return false;
		}

		return true;
	}

	/**
	 * Delete the cached variants stored directly in a directory.
	 *
	 * Subdirectories are left alone except for the ones that hold the same
	 * page's own pagination, which would otherwise survive its purge.
	 *
	 * @param string $dir Directory to clear.
	 * @return int Number of files deleted.
	 */
	public static function delete_variants( $dir ) {
		if ( ! self::is_inside_root( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}

		$deleted = 0;

		// The copies first, their hour names after, and those listed again. glob()
		// sorts every name before its copy, and a capture that linked names to the
		// copy between the two deletes left them pointing at the page the purge
		// was removing, which the server then served until they ran out. Once the
		// copy is gone nothing can link to it, so the second listing catches every
		// name made in between.
		foreach ( (array) glob( $dir . '/index*.html' ) as $file ) {
			if ( self::is_hour_name( basename( (string) $file ) ) ) {
				continue;
			}
			wp_delete_file( $file );
			++$deleted;
		}
		foreach ( (array) glob( $dir . '/index*.html.gz' ) as $file ) {
			wp_delete_file( $file );
			++$deleted;
		}
		foreach ( (array) glob( $dir . '/index*.html' ) as $file ) {
			if ( self::is_hour_name( basename( (string) $file ) ) ) {
				wp_delete_file( $file );
				++$deleted;
			}
		}

		// Pagination and comment pagination of this very page. Two globs rather
		// than one GLOB_BRACE pattern, which is not available on every build.
		foreach ( array( '/page', '/comment-page-*' ) as $pattern ) {
			foreach ( (array) glob( $dir . $pattern, GLOB_ONLYDIR ) as $subdir ) {
				$deleted += self::delete_tree( $subdir );
			}
		}

		return $deleted;
	}

	/**
	 * Recursively delete a directory inside the cache root.
	 *
	 * @param string $dir Directory to remove.
	 * @return int Number of files deleted.
	 */
	public static function delete_tree( $dir ) {
		if ( ! self::is_inside_root( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}

		$deleted = 0;

		$items = self::get_iterator( $dir, RecursiveIteratorIterator::CHILD_FIRST );
		if ( ! $items ) {
			return 0;
		}

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Removing the plugin's own cache directories; WP_Filesystem is unavailable on front-end requests.
				@rmdir( $item->getPathname() );
			} else {
				wp_delete_file( $item->getPathname() );
				++$deleted;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- See above.
		@rmdir( $dir );

		return $deleted;
	}

	/**
	 * Empty the whole cache, keeping the root and its hardening files.
	 *
	 * @return int Number of files deleted.
	 */
	public static function purge_all() {
		$root = self::get_root();
		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $host_dir ) {
			$deleted += self::delete_tree( $host_dir );
		}

		return $deleted;
	}

	/**
	 * Garbage collection: drop expired pages, leftovers and empty directories.
	 *
	 * @param int $ttl Time to live in seconds. 0 disables expiry.
	 * @return int Number of files deleted.
	 */
	public static function collect_garbage( $ttl ) {
		$root = self::get_root();
		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$ttl     = (int) $ttl;
		$now     = time();
		$deleted = 0;

		$items = self::get_iterator( $root, RecursiveIteratorIterator::CHILD_FIRST );
		if ( ! $items ) {
			return 0;
		}

		foreach ( $items as $item ) {
			$path = $item->getPathname();

			if ( $item->isDir() ) {
				// rmdir() only succeeds on empty directories, which is exactly
				// the filter wanted here.
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Pruning the plugin's own empty cache directories.
				@rmdir( $path );
				continue;
			}

			$name = $item->getFilename();

			// The names a copy has for the hours it is valid in carry their hour,
			// so they go once that hour is long over, whatever the lifetime. The
			// hour is the server's, which can be up to fourteen hours either side
			// of UTC, hence the margin; until then they only keep a copy on disk.
			if ( self::is_hour_name( $name ) ) {
				preg_match( '/\.(\d{4})(\d{2})(\d{2})(\d{2})\.html$/', $name, $hour );
				$stamp = gmmktime( (int) $hour[4], 0, 0, (int) $hour[2], (int) $hour[3], (int) $hour[1] );
				if ( $stamp < $now - 16 * HOUR_IN_SECONDS ) {
					wp_delete_file( $path );
					++$deleted;
				}
				continue;
			}

			$is_tmp = '.tmp' === substr( $name, -4 );

			if ( ! $is_tmp && ! self::is_page_file( $name ) ) {
				continue;
			}

			// A purge, or the rename() that finishes a write, can remove a file
			// between the directory listing and this line, and getMTime()
			// throws when there is nothing left to stat. Uncaught, that ended
			// the whole wp-cron.php run.
			try {
				$age = $now - $item->getMTime();
			} catch ( RuntimeException $e ) {
				continue;
			}

			// A temporary file that outlived its request: the write died
			// mid-way. Anything older than an hour is certainly abandoned.
			if ( $is_tmp ) {
				if ( $age > HOUR_IN_SECONDS ) {
					wp_delete_file( $path );
					++$deleted;
				}
				continue;
			}

			if ( $ttl > 0 && $age > $ttl ) {
				wp_delete_file( $path );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Delete every name a copy has for the hours it is valid in.
	 *
	 * The pages themselves stay, and PHP keeps serving them. What goes is what
	 * lets a server serve them without PHP, which matters where the rules cannot
	 * be taken out from here: on nginx they live in its configuration, so
	 * switching the accelerator off, or a test that fails, has to leave them
	 * nothing to find.
	 *
	 * @return int Names deleted.
	 */
	public static function delete_hour_names() {
		$items = self::get_iterator( self::get_root(), RecursiveIteratorIterator::LEAVES_ONLY );
		if ( ! $items ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $items as $item ) {
			if ( $item->isFile() && self::is_hour_name( $item->getFilename() ) ) {
				wp_delete_file( $item->getPathname() );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Count cached pages and bytes on disk.
	 *
	 * Counted fresh every time the settings screen asks, and memoised only for
	 * the rest of that request, which renders the figure twice.
	 *
	 * It used to be kept in a five minute transient, and that was wrong in the
	 * one way that mattered: pages are written on anonymous front-end requests,
	 * and nothing there invalidated the transient. Only purging did. So after
	 * browsing the site the panel went on reporting whatever it had counted
	 * last, usually zero, and reloading changed nothing. Walking the tree costs
	 * a stat per file, which is a real cost on a very large cache but is paid
	 * only by an administrator who opened the screen that shows the number.
	 *
	 * @param bool $force Recount even within the same request.
	 * @return array {
	 *     @type int $pages Number of cached HTML files (gzip twins excluded).
	 *     @type int $files Number of files on disk.
	 *     @type int $bytes Total size.
	 * }
	 */
	public static function get_stats( $force = false ) {
		static $memo = null;

		if ( ! $force && is_array( $memo ) ) {
			return $memo;
		}

		$stats = array(
			'pages' => 0,
			'files' => 0,
			'bytes' => 0,
		);

		$items = self::get_iterator( self::get_root(), RecursiveIteratorIterator::LEAVES_ONLY );
		if ( $items ) {
			foreach ( $items as $item ) {
				$name = $item->getFilename();

				// index.php is the silence file of the root, not a cached page.
				if ( ! $item->isFile() || ! self::is_page_file( $name ) ) {
					continue;
				}

				// Same reason as in collect_garbage(): the file can be gone by now.
				try {
					$size = $item->getSize();
				} catch ( RuntimeException $e ) {
					continue;
				}

				++$stats['files'];
				$stats['bytes'] += $size;
				if ( '.html' === substr( $name, -5 ) ) {
					++$stats['pages'];
				}
			}
		}

		$memo = $stats;

		return $stats;
	}

	/**
	 * Whether a file name is one of the stored copies of a page.
	 *
	 * The garbage collector used to settle for the "index" prefix, and the
	 * silence file of the cache root is called index.php, so the first cleanup
	 * after it outlived the page lifetime deleted it. The counter above had
	 * always ruled it out; both now ask the same question.
	 *
	 * @param string $name File name, without its directory.
	 * @return bool
	 */
	private static function is_page_file( $name ) {
		if ( 0 !== strpos( $name, 'index' ) || self::is_hour_name( $name ) ) {
			return false;
		}

		return '.html' === substr( $name, -5 ) || '.html.gz' === substr( $name, -8 );
	}

	/**
	 * Build a recursive iterator over a directory, or null when it cannot be read.
	 *
	 * A directory the web user cannot open makes the constructor throw, and a
	 * cache directory left behind by another user is common enough on shared
	 * hosting that it must not take down a cron run or the settings screen.
	 *
	 * The try below only covers the directory the walk starts from. A
	 * subdirectory that cannot be opened throws later, while the loop descends
	 * into it, and that is what CATCH_GET_CHILD is for: the walk skips it and
	 * carries on. Without it, one unreadable folder took down every cleanup
	 * and the settings screen along with it. The purges walk the same way, so
	 * a folder like that is left where it is instead of stopping the purge,
	 * and its pages are neither counted nor collected until its permissions
	 * are put right.
	 *
	 * @param string $dir  Directory to walk.
	 * @param int    $mode RecursiveIteratorIterator mode.
	 * @return RecursiveIteratorIterator|null
	 */
	private static function get_iterator( $dir, $mode ) {
		if ( ! is_dir( $dir ) ) {
			return null;
		}

		try {
			return new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				$mode,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Whether a path is contained in the cache root.
	 *
	 * The last line of defence before anything is deleted. Compares resolved
	 * paths so a symlink cannot walk out of the root.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_inside_root( $path ) {
		$root = realpath( self::get_root() );
		if ( ! $root ) {
			return false;
		}

		$real = realpath( $path );
		if ( ! $real ) {
			return false;
		}

		return 0 === strpos( $real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR );
	}

	/**
	 * Host of the current request, normalized.
	 *
	 * @return string
	 */
	public static function get_request_host() {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		return self::normalize_host( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
	}

	/**
	 * Port the request names in its Host header, if it names one.
	 *
	 * @return int|null Null for none; -1 for something that is not a port.
	 */
	public static function get_request_port() {
		if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
			return null;
		}
		return self::port_of( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
	}

	/**
	 * Port of the site's home URL, if it names one.
	 *
	 * @return int|null
	 */
	public static function get_home_port() {
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		return is_int( $port ) ? $port : null;
	}

	/**
	 * The port part of a host header.
	 *
	 * @param string $authority Host, with or without port, IPv6 in brackets.
	 * @return int|null Null for none; -1 for something that is not a port.
	 */
	private static function port_of( $authority ) {
		$authority = trim( (string) $authority );

		if ( '[' === substr( $authority, 0, 1 ) ) {
			$end  = strpos( $authority, ']' );
			$rest = false === $end ? '' : substr( $authority, $end + 1 );
		} else {
			$colon = strpos( $authority, ':' );
			$rest  = false === $colon ? '' : substr( $authority, $colon );
		}

		if ( '' === $rest ) {
			return null;
		}

		$digits = substr( $rest, 1 );

		return ( ':' === $rest[0] && '' !== $digits && ctype_digit( $digits ) ) ? (int) $digits : -1;
	}

	/**
	 * Host of the site's home URL, normalized.
	 *
	 * @return string
	 */
	public static function get_home_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? self::normalize_host( $host ) : '';
	}

	/**
	 * Lowercase a host, drop its port and reject anything that is not a host.
	 *
	 * Keeping this strict is what stops a forged Host header from writing the
	 * cache key of another site.
	 *
	 * @param string $host Raw host.
	 * @return string Empty string when the value is not a plain host name.
	 */
	private static function normalize_host( $host ) {
		$host = strtolower( trim( $host ) );

		// Drop the port, IPv6 brackets included.
		if ( '[' === substr( $host, 0, 1 ) ) {
			$end  = strpos( $host, ']' );
			$host = false === $end ? '' : substr( $host, 1, $end - 1 );
		} elseif ( false !== strpos( $host, ':' ) ) {
			$host = substr( $host, 0, strpos( $host, ':' ) );
		}

		if ( '' === $host || strlen( $host ) > 253 ) {
			return '';
		}
		if ( preg_match( '/[^a-z0-9.\-]/', $host ) || false !== strpos( $host, '..' ) ) {
			return '';
		}

		return $host;
	}
}
