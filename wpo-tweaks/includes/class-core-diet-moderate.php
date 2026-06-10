<?php
/**
 * DietPress Moderate features.
 *
 * Features that may be required by specific plugins, themes, or workflows.
 * Evaluate before disabling.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Moderate {

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
		if ( $this->settings->is_enabled( 'disable_oembed' ) ) {
			$this->disable_oembed();
		}

		if ( $this->settings->is_enabled( 'disable_rest_api_link' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		if ( $this->settings->is_enabled( 'disable_jquery_migrate' ) ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}

		if ( $this->settings->is_enabled( 'disable_dashicons' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_dashicons' ) );
		}

		if ( $this->settings->is_enabled( 'disable_adjacent_posts' ) ) {
			remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		}

		if ( $this->settings->is_enabled( 'disable_block_directory' ) ) {
			remove_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' );
		}

		if ( $this->settings->is_enabled( 'disable_remote_patterns' ) ) {
			add_filter( 'should_load_remote_block_patterns', '__return_false' );
		}

		if ( $this->settings->is_enabled( 'disable_global_styles' ) ) {
			$this->disable_global_styles();
		}

		if ( $this->settings->is_enabled( 'disable_duotone' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'remove_duotone' ), 100 );
		}

		if ( $this->settings->is_enabled( 'disable_avatars' ) ) {
			$this->disable_avatars();
			// Hide avatar options in Settings > Discussion.
			add_action( 'admin_enqueue_scripts', array( $this, 'hide_avatars_admin_ui' ) );
			add_action( 'admin_footer-options-discussion.php', array( $this, 'hide_avatars_admin_js' ) );
		}

		if ( $this->settings->is_enabled( 'disable_comment_threading' ) ) {
			// Force threading off; prevents nested reply markup and comment-reply.js.
			add_filter( 'pre_option_thread_comments', '__return_zero' );
			add_action( 'wp_enqueue_scripts', function() {
				wp_dequeue_script( 'comment-reply' );
			} );
			// Hide threading options in Settings > Discussion.
			add_action( 'admin_enqueue_scripts', array( $this, 'hide_comment_threading_admin_ui' ) );
			add_action( 'admin_footer-options-discussion.php', array( $this, 'hide_threading_admin_js' ) );
		}
	}

	/**
	 * Disable oEmbed discovery and embedding.
	 */
	private function disable_oembed() {
		// Remove oEmbed discovery links from head.
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

		// Remove oEmbed REST route.
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );

		// Prevent oEmbed auto-discovery.
		add_filter( 'embed_oembed_discover', '__return_false' );

		// Remove oEmbed-specific JavaScript.
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );

		// Dequeue wp-embed script.
		add_action( 'wp_footer', function() {
			wp_dequeue_script( 'wp-embed' );
		} );

		// Remove the oEmbed filter from content.
		remove_filter( 'pre_oembed_result', 'wp_filter_pre_oembed_result', 10 );
	}

	/**
	 * Remove jQuery Migrate from the frontend.
	 *
	 * @param WP_Scripts $scripts WordPress scripts registry.
	 */
	public function remove_jquery_migrate( $scripts ) {
		if ( is_admin() ) {
			return;
		}

		if ( isset( $scripts->registered['jquery'] ) ) {
			$deps = $scripts->registered['jquery']->deps;
			$scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
		}
	}

	/**
	 * Dequeue Dashicons on frontend for non-logged-in users.
	 */
	public function dequeue_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_dequeue_style( 'dashicons' );
			wp_deregister_style( 'dashicons' );
		}
	}

	/**
	 * Remove Global Styles inline CSS from frontend.
	 *
	 * Removes the actions that generate global styles rather than
	 * dequeueing, which may not work in WP 6.7+ where styles
	 * can be rendered via a different path.
	 */
	public function remove_global_styles() {
		wp_dequeue_style( 'global-styles' );
	}

	/**
	 * Remove Global Styles generation hooks.
	 *
	 * Must be called early enough to prevent the styles from being enqueued.
	 */
	private function disable_global_styles() {
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		// Dequeue as fallback for any remaining inline output.
		add_action( 'wp_enqueue_scripts', array( $this, 'remove_global_styles' ), 100 );
	}

	/**
	 * Remove Duotone SVG inline filters from frontend.
	 */
	public function remove_duotone() {
		remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		remove_action( 'wp_footer', 'wp_global_styles_render_svg_filters' );
	}

	/**
	 * Disable avatars and prevent Gravatar HTTP requests.
	 */
	private function disable_avatars() {
		// Disable avatar display site-wide.
		add_filter( 'pre_option_show_avatars', '__return_zero' );

		// Return empty string for any get_avatar call as a safety net.
		add_filter( 'pre_get_avatar', '__return_empty_string' );

		// Remove avatar-related options from Discussion settings.
		add_filter( 'default_avatar_select', '__return_empty_string' );
	}

	/**
	 * Hide avatar settings in Settings > Discussion.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function hide_avatars_admin_ui( $hook ) {
		if ( 'options-discussion.php' !== $hook ) {
			return;
		}
		// Target the avatar section: the last <h2> in the form (always "Avatars"),
		// its following <p> description, and the 3 form-tables (visibility, rating,
		// default avatar). Use table:has() as primary selector since each table
		// contains a unique input.
		$css  = '.wrap form > h2:last-of-type, .wrap form > h2:last-of-type + p { display: none !important; }';
		$css .= ' table.form-table:has(#show_avatars) { display: none !important; }';
		$css .= ' table.form-table:has(input[name="avatar_rating"]) { display: none !important; }';
		$css .= ' table.form-table:has(.avatar-default) { display: none !important; }';
		$css .= ' table.form-table:has(input[name="avatar_default"]) { display: none !important; }';
		wp_add_inline_style( 'common', $css );
	}

	/**
	 * Hide comment threading options in Settings > Discussion.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function hide_comment_threading_admin_ui( $hook ) {
		if ( 'options-discussion.php' !== $hook ) {
			return;
		}
		// Hide all threading-related elements individually.
		// The checkbox may be inside or outside the label depending on WP version.
		$css  = '#thread_comments { display: none !important; }';
		$css .= ' label[for="thread_comments"] { display: none !important; }';
		$css .= ' #thread_comments_depth { display: none !important; }';
		$css .= ' label[for="thread_comments_depth"] { display: none !important; }';
		$css .= ' select[name="thread_comments_depth"] { display: none !important; }';
		wp_add_inline_style( 'common', $css );
	}

	/**
	 * JS fallback: hide the entire Avatars section on the Discussion page.
	 *
	 * Walks the DOM from #show_avatars upward to find the section heading,
	 * then hides everything from the heading to the submit button.
	 * Uses wp_print_inline_script_tag (WP 5.7+).
	 */
	public function hide_avatars_admin_js() {
		$js = <<<'JS'
(function(){
	var cb = document.getElementById('show_avatars');
	if (!cb) return;
	var node = cb.closest('table');
	if (!node) return;
	/* Walk backwards from the table to find the <h2> heading. */
	var heading = node.previousElementSibling;
	while (heading && heading.tagName !== 'H2') {
		heading = heading.previousElementSibling;
	}
	/* Hide from heading onwards until we hit .submit or end of form. */
	var el = heading;
	while (el) {
		var next = el.nextElementSibling;
		if (el.classList && el.classList.contains('submit')) break;
		el.style.display = 'none';
		el = next;
	}
})();
JS;
		wp_print_inline_script_tag( $js );
	}

	/**
	 * JS fallback: hide threading options on the Discussion page.
	 *
	 * Finds the #thread_comments checkbox and hides it plus any
	 * surrounding text nodes and the depth select.
	 * Uses wp_print_inline_script_tag (WP 5.7+).
	 */
	public function hide_threading_admin_js() {
		$js = <<<'JS'
(function(){
	var cb = document.getElementById('thread_comments');
	if (!cb) return;
	/* The label wrapping the checkbox, text, and depth select. */
	var label = cb.closest('label') || cb.parentElement;
	if (label && label.tagName === 'LABEL') {
		label.style.display = 'none';
	} else {
		cb.style.display = 'none';
	}
	/* Also hide the depth select if it is outside the label. */
	var depth = document.getElementById('thread_comments_depth');
	if (depth) {
		var depthLabel = depth.closest('label');
		if (depthLabel) {
			depthLabel.style.display = 'none';
		} else {
			depth.style.display = 'none';
		}
	}
})();
JS;
		wp_print_inline_script_tag( $js );
	}
}