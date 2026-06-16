<?php
/**
 * DietPress script optimization features.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Perf_Scripts {

	/** @var Core_Diet_Settings */
	private $settings;

	/**
	 * Memoization cache for the defer eligibility check.
	 * Scoped per request to avoid recomputing the same handle multiple times
	 * across filter calls for different scripts.
	 *
	 * @var array
	 */
	private $defer_eligibility_cache = array();

	/**
	 * Compiled regex matching the URL patterns excluded from defer, or an
	 * empty string when there are no patterns. Built once per request from
	 * the default list plus the `dietpress_skip_defer_script_patterns` filter.
	 *
	 * @var string|null
	 */
	private $defer_skip_regex = null;

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
		if ( $this->settings->is_enabled( 'defer_js' ) ) {
			add_filter( 'script_loader_tag', array( $this, 'core_diet_defer_parsing_of_js' ), 10, 2 );
		}

		if ( $this->settings->is_enabled( 'optimize_google_fonts' ) ) {
			add_filter( 'style_loader_src', array( $this, 'core_diet_optimize_google_fonts' ), 10, 2 );
		}
	}

	/**
	 * Defer JavaScript parsing
	 *
	 * @since 2.1.3
	 * @since 2.3.1 Skip scripts whose dependency chain requires immediate execution (inline "after" code, translations, or transitive dependents with either). Prevents "wp is not defined" and related ReferenceError failures caused by partially-deferred dependency chains.
	 */
	public function core_diet_defer_parsing_of_js( $tag, $handle ) {
		if ( is_admin() ) {
			return $tag;
		}

		// Don't defer anything when Divi builder is active
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check for page builder detection, no form processing
		if ( isset( $_GET['et_fb'] ) && ! empty( $_GET['et_fb'] ) ) {
			return $tag;
		}

		// Exclude critical scripts from defer
		$excluded_handles = array(
			'jquery',
			'jquery-core',
			'jquery-migrate',
			'customize-support',
		);

		/**
		 * Filter the list of script handles excluded from the defer pass.
		 *
		 * Other plugins can opt their own handles out of the defer
		 * transformation here when their code depends on running before
		 * DOMContentLoaded (e.g. inline event handlers attached without
		 * jQuery.ready, or scripts that bootstrap critical UI features
		 * the visitor interacts with immediately).
		 *
		 * @since 2.3.2
		 * @param array  $excluded_handles Handles that should not be deferred.
		 * @param string $handle           Handle of the script currently being filtered.
		 */
		$excluded_handles = (array) apply_filters( 'dietpress_skip_defer_script_handles', $excluded_handles, $handle );

		if ( in_array( $handle, $excluded_handles, true ) ) {
			return $tag;
		}

		// Don't defer inline scripts
		if ( strpos( $tag, 'src=' ) === false ) {
			return $tag;
		}

		// Don't defer scripts whose source URL matches a known-problematic
		// pattern. The dependency-chain analysis below catches scripts WordPress
		// knows about; this covers third-party widgets that must run before
		// DOMContentLoaded (reCAPTCHA, Typekit, HubSpot forms, payment SDKs, etc.)
		// whose handles are unpredictable but whose URLs are stable.
		if ( preg_match( '/\ssrc=["\']([^"\']+)["\']/', $tag, $src_match ) ) {
			if ( $this->core_diet_src_matches_skip_pattern( $src_match[1] ) ) {
				return $tag;
			}
		}

		// Don't defer scripts that are already async
		if ( strpos( $tag, 'async' ) !== false ) {
			return $tag;
		}

		// Don't defer scripts whose dependency chain requires immediate
		// execution. A script must run immediately (not deferred) when:
		// - It has inline "after" code attached via wp_add_inline_script()
		// - It is registered with wp_set_script_translations()
		// - Any script that transitively depends on it has either of the above
		//
		// The third case is the important one: if script X can't be deferred
		// and X depends on Y, then deferring Y breaks X because Y would not
		// be available when X runs inline. Classic example: wp-i18n has inline
		// "after" code that calls wp.i18n.setLocaleData(). wp-i18n depends on
		// wp-hooks. Deferring wp-hooks while running wp-i18n immediately
		// causes "Cannot read properties of undefined (reading 'hooks')"
		// inside wp-i18n itself, and "wp is not defined" in every downstream
		// script that expects the wp.* globals to exist.
		global $wp_scripts;
		if ( isset( $wp_scripts->registered[ $handle ] ) ) {
			if ( $this->core_diet_script_must_execute_immediately( $handle, $wp_scripts ) ) {
				return $tag;
			}
		}

		// Check user agent for IE9 compatibility
		$user_agent = dietpress_get_user_agent();
		if ( ! empty( $user_agent ) && strpos( $user_agent, 'MSIE 9.' ) !== false ) {
			return $tag;
		}

		// Add defer attribute
		return str_replace( ' src', ' defer src', $tag );
	}

	/**
	 * Check whether a script requires immediate execution (cannot be deferred).
	 *
	 * A script requires immediate execution if it has inline "after" code or
	 * registered translations, OR if any script that transitively depends on
	 * it has either. The transitive check is what preserves dependency chains
	 * when some member of the chain can't be deferred.
	 *
	 * Results are memoized per-request in $this->defer_eligibility_cache to
	 * avoid redundant work across multiple filter invocations.
	 *
	 * @since 2.3.1
	 * @param string     $handle     Script handle to evaluate
	 * @param WP_Scripts $wp_scripts The global scripts registry
	 * @return bool True if the script must execute immediately
	 */
	private function core_diet_script_must_execute_immediately( $handle, $wp_scripts ) {
		// Return memoized result when available
		if ( array_key_exists( $handle, $this->defer_eligibility_cache ) ) {
			return $this->defer_eligibility_cache[ $handle ];
		}

		// Unknown handle: nothing to defer, safe default
		if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
			$this->defer_eligibility_cache[ $handle ] = false;
			return false;
		}

		$script_obj = $wp_scripts->registered[ $handle ];

		// Direct reasons this script cannot be deferred:
		// - extra['after'] contains inline code that runs right after the
		//   script tag and would execute before the deferred main script
		// - textdomain indicates wp_set_script_translations() was called,
		//   which injects a locale data inline "after" block at print time
		if ( ! empty( $script_obj->extra['after'] ) || ! empty( $script_obj->textdomain ) ) {
			$this->defer_eligibility_cache[ $handle ] = true;
			return true;
		}

		// Tentatively mark as deferrable to break potential recursion cycles
		// (circular dependencies shouldn't exist but this is defensive)
		$this->defer_eligibility_cache[ $handle ] = false;

		// Transitive check: walk the dependents. If any script that depends
		// on $handle (directly or transitively through another dependent)
		// needs immediate execution, $handle also needs immediate execution
		// to keep the dependency chain intact at load time.
		foreach ( $wp_scripts->registered as $other_handle => $other_script ) {
			if ( ! is_array( $other_script->deps ) || empty( $other_script->deps ) ) {
				continue;
			}
			if ( ! in_array( $handle, $other_script->deps, true ) ) {
				continue;
			}
			if ( $this->core_diet_script_must_execute_immediately( $other_handle, $wp_scripts ) ) {
				$this->defer_eligibility_cache[ $handle ] = true;
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a script source URL matches a pattern that must not be deferred.
	 *
	 * @since 3.1.0
	 * @param string $src Script source URL.
	 * @return bool
	 */
	private function core_diet_src_matches_skip_pattern( $src ) {
		$regex = $this->core_diet_get_defer_skip_regex();

		if ( '' === $regex ) {
			return false;
		}

		return 1 === preg_match( $regex, $src );
	}

	/**
	 * Build (and cache per request) the combined regex of URL patterns that
	 * must never be deferred.
	 *
	 * @since 3.1.0
	 * @return string Regex with delimiters, or '' when there are no patterns.
	 */
	private function core_diet_get_defer_skip_regex() {
		if ( null !== $this->defer_skip_regex ) {
			return $this->defer_skip_regex;
		}

		/**
		 * Filter the URL patterns excluded from the defer pass.
		 *
		 * Each entry is a regular expression fragment (without delimiters)
		 * matched, case-insensitively, against the script's `src`. Use it to
		 * stop deferring third-party scripts that must execute before
		 * DOMContentLoaded but whose handles are unpredictable.
		 *
		 * @since 3.1.0
		 * @param array $patterns Regex fragments matched against the script src.
		 */
		$patterns = (array) apply_filters( 'dietpress_skip_defer_script_patterns', $this->core_diet_get_default_defer_skip_patterns() );

		$patterns = array_filter( array_map( 'trim', $patterns ) );

		$this->defer_skip_regex = empty( $patterns ) ? '' : '#(?:' . implode( '|', $patterns ) . ')#i';

		return $this->defer_skip_regex;
	}

	/**
	 * Default list of script-src URL patterns that must not be deferred.
	 *
	 * Curated from real-world third-party scripts that break when deferred
	 * (widgets, captchas, payment SDKs) plus the WordPress dependency scripts
	 * the chain analysis already protects, kept here as a belt-and-braces net.
	 *
	 * @since 3.1.0
	 * @return array
	 */
	private function core_diet_get_default_defer_skip_patterns() {
		return array(
			'gist\.github\.com',
			'content\.jwplatform\.com',
			'js\.hsforms\.net',
			'js-eu1\.hsforms\.net',
			'www\.uplaunch\.com',
			'google\.com/recaptcha',
			'widget\.reviews\.co\.uk',
			'widget\.reviews\.io',
			'verify\.authorize\.net/anetseal',
			'webfont(\.min)?\.js',
			'app\.mailerlite\.com',
			'static\.mailerlite\.com/data/(.*)\.js',
			'simplybook\.(.*)/v2/widget/widget\.js',
			'cdn\.voxpow\.com/static/libs/v1/(.*)\.js',
			'cdn\.voxpow\.com/media/trackers/js/(.*)\.js',
			'use\.typekit\.net',
			'use\.typekit\.com',
			'www\.idxhome\.com',
			'www\.paypal\.com/sdk/js',
			'yanovis(.*)\.js',
			'glide\.min\.js',
			'kirki/assets/webfont\.js',
			'/api/scripts/lb_cs\.js',
			'js\.hscta\.net/cta/current\.js',
			'widget\.refari\.co',
			'player\.vdocipher\.com',
			'check-webp(\.min)?\.js',
			'/wp-includes/js/dist/i18n(\.min)?\.js',
			'/wp-includes/js/dist/hooks(\.min)?\.js',
			'/wp-includes/js/dist/url(\.min)?\.js',
			'/wp-includes/js/dist/api-fetch(\.min)?\.js',
			'/wp-includes/js/dist/vendor/lodash(\.min)?\.js',
			'/wp-includes/js/dist/vendor/wp-polyfill(\.min)?\.js',
			'wpfront-notification-bar(.*)\.js',
			'oxygen/component-framework/vendor/aos/aos\.js',
		);
	}

	/**
	 * Optimize Google Fonts
	 */
	public function core_diet_optimize_google_fonts( $src, $handle ) {
		if ( strpos( $src, 'fonts.googleapis.com' ) !== false ) {
			// Replace display=fallback with display=swap
			if ( strpos( $src, 'display=fallback' ) !== false ) {
				$src = str_replace( 'display=fallback', 'display=swap', $src );
			} elseif ( strpos( $src, 'display=' ) === false ) {
				// Add display=swap if no display parameter exists
				$src = add_query_arg( 'display', 'swap', $src );
			}
		}

		return $src;
	}
}
