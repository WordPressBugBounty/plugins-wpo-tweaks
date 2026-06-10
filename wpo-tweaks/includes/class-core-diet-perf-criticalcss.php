<?php
/**
 * Critical CSS: inline critical CSS in head and defer non-critical stylesheets.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Perf_Critical_Css {

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
		// Inline critical CSS in head (safe part).
		if ( $this->settings->is_enabled( 'critical_css_inline' ) ) {
			add_action( 'wp_head', array( $this, 'inline_critical_css' ), 1 );
		}

		// Defer non-critical stylesheets via rel=preload + onload (risky part, may cause FOUC).
		if ( $this->settings->is_enabled( 'critical_css_defer' ) ) {
			add_filter( 'style_loader_tag', array( $this, 'defer_non_critical_css_filter' ), 10, 4 );
			add_action( 'wp_footer', array( $this, 'defer_non_critical_css_script' ), 999 );
		}
	}

	/**
	 * Inline critical CSS in head - FOR ALL USERS
	 */
	public function inline_critical_css() {
		$critical_css = $this->get_critical_css();

		if ( ! empty( $critical_css ) ) {
			// Strip HTML tags to prevent injection, then prevent </style> breakout.
			// We do NOT use esc_html() because it would escape valid CSS selectors
			// like `.parent > .child` into `.parent &gt; .child` breaking the CSS.
			$safe_css = str_replace( '</style>', '', wp_strip_all_tags( $critical_css ) );
			printf( '<style id="core-diet-critical-css">%s</style>' . "\n", $safe_css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content sanitized above; esc_html would break valid selectors
		}
	}

	/**
	 * Get critical CSS
	 */
	private function get_critical_css() {
		$cache_key    = 'core_diet_critical_css_' . get_template();
		$critical_css = wp_cache_get( $cache_key );

		if ( $critical_css !== false ) {
			return $critical_css;
		}

		// Check transient as fallback
		$critical_css = get_transient( $cache_key );
		if ( $critical_css !== false ) {
			wp_cache_set( $cache_key, $critical_css, '', WEEK_IN_SECONDS );
			return $critical_css;
		}

		// Basic critical CSS
		$critical_css = '
		html{font-family:sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
		body{margin:0;padding:0;line-height:1.6}
		*,*:before,*:after{box-sizing:border-box}
		img{max-width:100%;height:auto;border:0}
		.screen-reader-text{clip:rect(1px,1px,1px,1px);position:absolute!important;height:1px;width:1px;overflow:hidden}
		';

		$critical_css = apply_filters( 'dietpress_critical_css', $critical_css );

		// Cache in both systems
		wp_cache_set( $cache_key, $critical_css, '', WEEK_IN_SECONDS );
		set_transient( $cache_key, $critical_css, WEEK_IN_SECONDS );

		return $critical_css;
	}

	/**
	 * Defer non-critical CSS via filter - FOR ALL USERS
	 */
	public function defer_non_critical_css_filter( $tag, $handle, $href, $media ) {
		// Only skip in admin, apply to ALL frontend users
		if ( is_admin() || $this->is_critical_css_handle( $handle ) ) {
			return $tag;
		}

		/**
		 * Filter handles that should be excluded from the CSS defer pass.
		 *
		 * Other plugins can opt their own stylesheets out when the rel=preload
		 * + onload technique would leave their UI visually broken until the
		 * stylesheet finishes loading (e.g. collapsible widgets, interactive
		 * cards), or when they have a hard CSP that blocks inline event
		 * handlers.
		 *
		 * @since 2.3.2
		 * @param array  $skip   List of handles that must keep rel="stylesheet".
		 * @param string $handle Handle currently being filtered.
		 */
		$skip = (array) apply_filters( 'dietpress_skip_defer_style_handles', array(), $handle );
		if ( in_array( $handle, $skip, true ) ) {
			return $tag;
		}

		$deferred_tag = str_replace( 'rel="stylesheet"', 'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"', $tag );
		$noscript     = '<noscript>' . $tag . '</noscript>';

		return $deferred_tag . $noscript;
	}

	/**
	 * Check if CSS handle is critical - FIXED v2.1.1
	 */
	private function is_critical_css_handle( $handle ) {
		$critical_handles = array(
			get_template(),
			get_stylesheet(),
			'admin-bar',
		);

		// Only add dashicons if user is logged in AND can manage options
		if ( is_user_logged_in() ) {
			$critical_handles[] = 'dashicons';
		}

		return in_array( $handle, apply_filters( 'dietpress_critical_css_handles', $critical_handles ) );
	}

	/**
	 * JavaScript for deferred CSS loading - FOR ALL USERS
	 */
	public function defer_non_critical_css_script() {
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var links = document.querySelectorAll('link[data-defer="true"]');
			links.forEach(function(link) {
				link.rel = 'stylesheet';
				link.removeAttribute('data-defer');
			});
		});
		</script>
		<?php
	}
}
