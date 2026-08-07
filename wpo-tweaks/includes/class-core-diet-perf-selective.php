<?php
/**
 * DietPress selective third-party loading.
 *
 * Stops plugins that enqueue their assets site-wide from loading them on the
 * pages where their content is not present. Every module is described in a
 * single registry (target guard, detection, handle lists), and each one only
 * acts when its target plugin is active. All detections have escape filters so
 * themes and plugins with uncommon setups can opt out.
 *
 * Two kinds of module live here:
 *
 *   - Dequeue modules (WooCommerce, Contact Form 7, block library, Formidable
 *     Forms, Everest Forms): they run on wp_enqueue_scripts at priority 99,
 *     once the target plugin has already enqueued everything, and remove the
 *     handles listed in the registry.
 *   - Native-switch modules (Slider Revolution, TablePress, Smash Balloon):
 *     instead of dequeuing, they flip the plugin's own conditional-loading
 *     path through its public filter or setting, so the plugin itself decides
 *     when to load. Their filters are registered at plugin load time because
 *     the target reads them very early.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Perf_Selective {

	/** @var Core_Diet_Settings */
	private $settings;

	/**
	 * Per-request cache for the widget-content scan.
	 *
	 * Keyed by search-needle group; avoids re-reading the widget options for
	 * every module on the same request.
	 *
	 * @var array
	 */
	private $widget_scan_cache = array();

	/**
	 * Per-request cache for the Elementor detection.
	 *
	 * @var bool|null
	 */
	private $elementor_scan_cache = null;

	/**
	 * @param Core_Diet_Settings $settings Settings instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Initialize hooks for enabled modules.
	 */
	public function init() {
		$has_dequeue_module = false;

		foreach ( $this->get_modules() as $module ) {
			if ( ! $this->settings->is_enabled( $module['setting'] ) ) {
				continue;
			}

			// Native-switch modules hook their own filter right away: their
			// target reads it as early as the init action.
			if ( isset( $module['early'] ) ) {
				call_user_func( $module['early'] );
				continue;
			}

			$has_dequeue_module = true;
		}

		if ( ! $has_dequeue_module ) {
			return;
		}

		// Priority 99: run after the target plugins have enqueued their assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'core_diet_selective_dequeue' ), 99 );
	}

	/* ============================
	 * Module registry
	 * ============================ */

	/**
	 * Definitions of every selective loading module.
	 *
	 * Keys of the returned array are the module slug, used to build the public
	 * filter names: dietpress_selective_{slug}_styles,
	 * dietpress_selective_{slug}_scripts and
	 * dietpress_selective_{slug}_has_content.
	 *
	 * Recognized members:
	 *
	 *   setting         Setting key that switches the module on.
	 *   early           Callback that registers the module hooks at plugin load
	 *                   time (native-switch modules only).
	 *   active          Callback returning whether the target plugin is present.
	 *   detect          Callback returning whether this page shows its content.
	 *   styles          Style handles removed when no content is detected.
	 *   scripts         Script handles removed when no content is detected.
	 *   after           Callback run right after the handles are removed.
	 *   builder_blind   Whether an Elementor-rendered page must keep the assets,
	 *                   because its content never reaches post_content.
	 *   legacy_detect   Detection filter published before the registry existed.
	 *
	 * @return array
	 */
	private function get_modules() {
		return array(
			'wc' => array(
				'setting'       => 'selective_woocommerce',
				'active'        => array( $this, 'wc_is_active' ),
				'detect'        => array( $this, 'wc_has_content' ),
				'styles'        => array(
					'woocommerce-general',
					'woocommerce-layout',
					'woocommerce-smallscreen',
					'woocommerce-inline',
					'wc-blocks-style',
					'wc-blocks-vendors-style',
				),
				'scripts'       => array(
					'woocommerce',
					'wc-add-to-cart',
					'wc-single-product',
					'wc-order-attribution',
					'sourcebuster-js',
					'js-cookie',
					'selectWoo',
					'select2',
				),
				'after'         => array( $this, 'wc_after_dequeue' ),
				'builder_blind' => true,
				'legacy_detect' => 'dietpress_selective_is_wc_page',
			),

			'cf7' => array(
				'setting'       => 'selective_cf7',
				'active'        => array( $this, 'cf7_is_active' ),
				'detect'        => array( $this, 'cf7_has_content' ),
				'styles'        => array( 'contact-form-7', 'contact-form-7-rtl' ),
				'scripts'       => array( 'contact-form-7', 'swv', 'wpcf7-recaptcha', 'google-recaptcha' ),
				'builder_blind' => true,
				'legacy_detect' => 'dietpress_selective_cf7_has_form',
			),

			'formidable' => array(
				'setting'       => 'selective_formidable',
				'active'        => array( $this, 'formidable_is_active' ),
				'detect'        => array( $this, 'formidable_has_content' ),
				'styles'        => array( 'formidable' ),
				'scripts'       => array(),
				'after'         => array( $this, 'formidable_after_dequeue' ),
				'builder_blind' => true,
			),

			'everest_forms' => array(
				'setting'       => 'selective_everest_forms',
				'active'        => array( $this, 'everest_forms_is_active' ),
				'detect'        => array( $this, 'everest_forms_has_content' ),
				'styles'        => array( 'everest-forms-general', 'jquery-intl-tel-input' ),
				'scripts'       => array(),
				'after'         => array( $this, 'everest_forms_after_dequeue' ),
				'builder_blind' => true,
			),

			'blocks' => array(
				'setting' => 'selective_blocks',
				'handler' => array( $this, 'maybe_dequeue_block_library' ),
			),

			'revslider' => array(
				'setting' => 'selective_revslider',
				'early'   => array( $this, 'hook_revslider' ),
			),

			'tablepress' => array(
				'setting' => 'selective_tablepress',
				'early'   => array( $this, 'hook_tablepress' ),
			),

			'smash_balloon' => array(
				'setting' => 'selective_smash_balloon',
				'early'   => array( $this, 'hook_smash_balloon' ),
			),
		);
	}

	/**
	 * Run the enabled dequeue modules on the current page.
	 */
	public function core_diet_selective_dequeue() {
		// Never touch editors or previews: builders and the Customizer load
		// assets speculatively and a missing stylesheet breaks their canvas.
		if ( is_admin() || is_customize_preview() || is_preview() ) {
			return;
		}

		foreach ( $this->get_modules() as $slug => $module ) {
			if ( isset( $module['early'] ) ) {
				continue;
			}

			if ( ! $this->settings->is_enabled( $module['setting'] ) ) {
				continue;
			}

			if ( isset( $module['handler'] ) ) {
				call_user_func( $module['handler'] );
				continue;
			}

			$this->run_dequeue_module( $slug, $module );
		}
	}

	/**
	 * Apply one dequeue module to the current page.
	 *
	 * @param string $slug   Module slug, used to build the filter names.
	 * @param array  $module Module definition.
	 */
	private function run_dequeue_module( $slug, $module ) {
		if ( isset( $module['active'] ) && ! call_user_func( $module['active'] ) ) {
			return;
		}

		$has_content = isset( $module['detect'] ) ? (bool) call_user_func( $module['detect'] ) : false;

		// A page built with Elementor keeps its content out of post_content,
		// so the scan above cannot see it. Assume the worst and keep the assets.
		if ( ! $has_content && ! empty( $module['builder_blind'] ) && $this->page_hides_content_from_scan() ) {
			$has_content = true;
		}

		if ( ! empty( $module['legacy_detect'] ) ) {
			/**
			 * Filter whether the current page shows this module's content.
			 *
			 * Published in 3.3.0, before the module registry existed. Kept as
			 * the first pass so existing snippets keep working; the generic
			 * dietpress_selective_{slug}_has_content filter runs after it.
			 *
			 * @since 3.3.0
			 * @param bool $has_content Whether the content was detected.
			 */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The name comes from the hardcoded registry above and is always one of this plugin's own dietpress_selective_* filters.
			$has_content = (bool) apply_filters( $module['legacy_detect'], $has_content );
		}

		/**
		 * Filter whether the current page shows this module's content.
		 *
		 * Return true to keep the assets loaded on this page. The dynamic
		 * portion of the hook name, `$slug`, is the module slug: wc, cf7,
		 * formidable, everest_forms, revslider, tablepress or smash_balloon.
		 *
		 * @since 3.4.0
		 * @param bool $has_content Whether the content was detected.
		 */
		$has_content = (bool) apply_filters( "dietpress_selective_{$slug}_has_content", $has_content );

		if ( $has_content ) {
			return;
		}

		$styles  = isset( $module['styles'] ) ? $module['styles'] : array();
		$scripts = isset( $module['scripts'] ) ? $module['scripts'] : array();

		/**
		 * Filter the style handles dequeued when this module finds no content.
		 *
		 * The dynamic portion of the hook name, `$slug`, is the module slug.
		 *
		 * @since 3.3.0 For the wc module.
		 * @since 3.4.0 For every other module.
		 * @param array $styles Style handles to dequeue.
		 */
		$styles = (array) apply_filters( "dietpress_selective_{$slug}_styles", $styles );

		/**
		 * Filter the script handles dequeued when this module finds no content.
		 *
		 * The dynamic portion of the hook name, `$slug`, is the module slug.
		 *
		 * @since 3.3.0 For the wc module.
		 * @since 3.4.0 For every other module.
		 * @param array $scripts Script handles to dequeue.
		 */
		$scripts = (array) apply_filters( "dietpress_selective_{$slug}_scripts", $scripts );

		foreach ( $styles as $handle ) {
			wp_dequeue_style( $handle );
		}

		foreach ( $scripts as $handle ) {
			wp_dequeue_script( $handle );
		}

		if ( isset( $module['after'] ) ) {
			call_user_func( $module['after'] );
		}
	}

	/* ============================
	 * WooCommerce
	 * ============================ */

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private function wc_is_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether the current page shows WooCommerce content.
	 *
	 * Covers the WooCommerce conditional tags (shop, product, archives, cart,
	 * checkout, account), plus WooCommerce shortcodes and blocks in the
	 * current singular content.
	 *
	 * @return bool
	 */
	private function wc_has_content() {
		// Conditional tags. is_woocommerce() covers shop, products and
		// product taxonomy archives; the endpoints pages have their own tags.
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			return true;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		// WooCommerce shortcodes or blocks embedded in regular content.
		$needles = array( '[products', '[product_', '[add_to_cart', '[woocommerce_', '[shop_messages', '<!-- wp:woocommerce/' );

		return $this->queried_content_contains( $needles );
	}

	/**
	 * Keep the cart fragments script when the site shows a mini-cart.
	 *
	 * Cart fragments keeps the mini-cart count in sync on every page, so it is
	 * only removed when no mini-cart widget is detected. Headers with a
	 * hand-coded mini-cart can force-keep it through the filter.
	 */
	private function wc_after_dequeue() {
		$keep_fragments = $this->site_uses_mini_cart();

		/**
		 * Filter whether the cart fragments script must be kept on
		 * non-WooCommerce pages.
		 *
		 * Return true when your theme renders a mini-cart the widget
		 * detection cannot see (e.g. a hand-coded header cart).
		 *
		 * @since 3.3.0
		 * @param bool $keep_fragments Whether to keep wc-cart-fragments enqueued.
		 */
		$keep_fragments = (bool) apply_filters( 'dietpress_selective_wc_keep_cart_fragments', $keep_fragments );

		if ( ! $keep_fragments ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
	}

	/**
	 * Whether a mini-cart widget is active anywhere on the site.
	 *
	 * Detects the classic cart widget and the mini-cart block inside block
	 * widgets. Hand-coded header carts are not detectable; use the
	 * `dietpress_selective_wc_keep_cart_fragments` filter for those.
	 *
	 * @return bool
	 */
	private function site_uses_mini_cart() {
		if ( is_active_widget( false, false, 'woocommerce_widget_cart', true ) ) {
			return true;
		}

		return $this->widgets_contain( array( 'woocommerce/mini-cart', 'woocommerce_widget_cart' ) );
	}

	/* ============================
	 * Contact Form 7
	 * ============================ */

	/**
	 * Whether Contact Form 7 is active.
	 *
	 * @return bool
	 */
	private function cf7_is_active() {
		return class_exists( 'WPCF7' );
	}

	/**
	 * Whether the current page renders a Contact Form 7 form.
	 *
	 * Scans the queried content (singular post or the posts in the main
	 * query for archive-style views) for the CF7 shortcodes and block, and
	 * the active widgets as a fallback.
	 *
	 * @return bool
	 */
	private function cf7_has_content() {
		$needles = array( '[contact-form-7', '[contact-form', '<!-- wp:contact-form-7/' );

		if ( $this->queried_content_contains( $needles ) ) {
			return true;
		}

		return $this->widgets_contain( array( '[contact-form-7', '[contact-form', 'contact-form-7/contact-form-selector' ) );
	}

	/* ============================
	 * Formidable Forms
	 * ============================ */

	/**
	 * Whether Formidable Forms is active and loading its CSS on every page.
	 *
	 * Formidable ships with the "load_style" setting on 'all', which enqueues
	 * the generated stylesheet everywhere. When the admin has already picked
	 * one of the conditional modes there is nothing to do.
	 *
	 * @return bool
	 */
	private function formidable_is_active() {
		if ( ! class_exists( 'FrmAppHelper' ) || ! method_exists( 'FrmAppHelper', 'get_settings' ) ) {
			return false;
		}

		$frm_settings = FrmAppHelper::get_settings();

		return isset( $frm_settings->load_style ) && 'all' === $frm_settings->load_style;
	}

	/**
	 * Whether the current page renders a Formidable form or view.
	 *
	 * @return bool
	 */
	private function formidable_has_content() {
		$needles = array( '[formidable', '[display-frm-data', '[frm-', '<!-- wp:formidable/' );

		if ( $this->queried_content_contains( $needles ) ) {
			return true;
		}

		return $this->widgets_contain( $needles );
	}

	/**
	 * Restore the Formidable late-loading safety net after dequeuing its CSS.
	 *
	 * With load_style on 'all', Formidable marks its stylesheet as loaded in
	 * the head and its wp_footer fallback then does nothing. Clearing that flag
	 * puts the fallback back in play, so a form printed from a template still
	 * gets its styles (in the footer, which is what Formidable's own
	 * conditional mode does).
	 */
	private function formidable_after_dequeue() {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $frm_vars belongs to Formidable Forms; the point of this method is to write to it.
		global $frm_vars;

		if ( is_array( $frm_vars ) ) {
			$frm_vars['css_loaded'] = false;
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	}

	/* ============================
	 * Everest Forms
	 * ============================ */

	/**
	 * Whether Everest Forms is active.
	 *
	 * @return bool
	 */
	private function everest_forms_is_active() {
		return defined( 'EVF_VERSION' );
	}

	/**
	 * Whether the current page renders an Everest Forms form.
	 *
	 * @return bool
	 */
	private function everest_forms_has_content() {
		$needles = array( '[everest_form', '<!-- wp:everest-forms/' );

		if ( $this->queried_content_contains( $needles ) ) {
			return true;
		}

		return $this->widgets_contain( $needles );
	}

	/**
	 * Remove the Dashicons stylesheet Everest Forms forces on every visitor.
	 *
	 * Everest Forms enqueues dashicons unconditionally for everybody, so on a
	 * page with no form it is 59 KB nobody uses. It is only removed for
	 * logged-out visitors (logged-in users get it with the admin bar) and when
	 * no other queued stylesheet depends on it.
	 */
	private function everest_forms_after_dequeue() {
		/**
		 * Filter whether the Everest Forms module also removes Dashicons.
		 *
		 * Return false if another plugin enqueues Dashicons directly (not as a
		 * dependency) and needs it on pages without forms.
		 *
		 * @since 3.4.0
		 * @param bool $dequeue Whether to dequeue the dashicons handle.
		 */
		if ( ! apply_filters( 'dietpress_selective_everest_forms_dequeue_dashicons', true ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		if ( $this->style_is_a_dependency( 'dashicons' ) ) {
			return;
		}

		wp_dequeue_style( 'dashicons' );
	}

	/* ============================
	 * Block library
	 * ============================ */

	/**
	 * Dequeue the block library styles on pages that render no blocks.
	 */
	private function maybe_dequeue_block_library() {
		// Block themes build every template out of blocks.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return;
		}

		$page_has_blocks = $this->queried_content_contains( array( '<!-- wp:' ) );

		// Block widgets render blocks in sidebars on every page.
		if ( ! $page_has_blocks && $this->widgets_contain( array( '<!-- wp:' ) ) ) {
			$page_has_blocks = true;
		}

		$dequeue = ! $page_has_blocks;

		/**
		 * Filter whether the block library styles are dequeued on this page.
		 *
		 * Some classic themes reuse block styles in templates even when the
		 * content has no blocks; return false to keep the styles loaded.
		 *
		 * @since 3.3.0
		 * @param bool $dequeue Whether wp-block-library styles will be dequeued.
		 */
		if ( ! apply_filters( 'dietpress_selective_blocks_dequeue', $dequeue ) ) {
			return;
		}

		// global-styles stays untouched on purpose: many themes depend on it.
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'classic-theme-styles' );
	}

	/* ============================
	 * Slider Revolution
	 * ============================ */

	/**
	 * Hook the Slider Revolution library switches.
	 *
	 * Slider Revolution ships with its JS libraries loading on every page
	 * (roughly 660 KB). Two of its own hooks are used, one for each direction:
	 *
	 *   - rs_get_global_settings turns its "Include libraries globally" setting
	 *     off for this request only, which hands the decision back to Slider
	 *     Revolution: preview mode, its shortcodes in the current post, its own
	 *     widget, and the "List of pages to include RevSlider libraries" that
	 *     the setting enables. Nothing is stored, and a site that already has
	 *     that setting off is never touched.
	 *   - revslider_include_libraries can only force loading on (it receives
	 *     false and everything after it ORs true), so it is used exactly for
	 *     that: to cover the cases its own check misses.
	 */
	private function hook_revslider() {
		add_filter( 'rs_get_global_settings', array( $this, 'core_diet_revslider_global_settings' ) );
		add_filter( 'revslider_include_libraries', array( $this, 'core_diet_revslider_libraries' ) );
	}

	/**
	 * Turn off the Slider Revolution site-wide library loading for this request.
	 *
	 * @param mixed $global Slider Revolution global settings.
	 * @return mixed
	 */
	public function core_diet_revslider_global_settings( $global ) {
		if ( ! is_array( $global ) || is_admin() ) {
			return $global;
		}

		// Slider Revolution reads these settings once before the main query is
		// resolved and again while enqueuing, which is the read that decides.
		// With no query there is nothing to inspect, so leave them as they are.
		if ( ! did_action( 'wp' ) ) {
			return $global;
		}

		if ( is_customize_preview() || is_preview() ) {
			return $global;
		}

		// The site already has the global loading off. Its page list is in
		// charge and nothing here may interfere with it.
		if ( ! $this->revslider_loads_globally( $global ) ) {
			return $global;
		}

		// A page whose content cannot be scanned keeps everything as it is.
		if ( $this->page_hides_content_from_scan() ) {
			return $global;
		}

		$global['allinclude'] = false;

		return $global;
	}

	/**
	 * Whether Slider Revolution is set to load its libraries on every page.
	 *
	 * Mirrors how Slider Revolution reads the setting: missing means on, and
	 * only its own set of truthy values counts as on.
	 *
	 * @param array $global Slider Revolution global settings.
	 * @return bool
	 */
	private function revslider_loads_globally( $global ) {
		if ( ! array_key_exists( 'allinclude', $global ) ) {
			return true;
		}

		return in_array( $global['allinclude'], array( 'true', true, 'on' ), true );
	}

	/**
	 * Keep the Slider Revolution libraries where its own check cannot see them.
	 *
	 * Slider Revolution looks for its shortcodes in the content of a singular
	 * post, so a slider inside a text widget or printed by an archive template
	 * would lose its libraries. This adds those cases back.
	 *
	 * @param bool $load Whether Slider Revolution loads its libraries.
	 * @return bool
	 */
	public function core_diet_revslider_libraries( $load ) {
		// This filter can only turn loading on: Slider Revolution passes false
		// and ORs true for every one of its own checks afterwards.
		if ( $load || is_admin() || ! did_action( 'wp' ) ) {
			return $load;
		}

		$needles     = array( '[rev_slider', '[sr7' );
		$has_content = $this->queried_content_contains( $needles ) || $this->widgets_contain( $needles );

		/**
		 * Filter whether this page shows a Slider Revolution slider.
		 *
		 * Return true to keep the libraries loaded, for example when your
		 * theme prints a slider from a template.
		 *
		 * @since 3.4.0
		 * @param bool $has_content Whether a slider was detected on this page.
		 */
		if ( apply_filters( 'dietpress_selective_revslider_has_content', $has_content ) ) {
			return true;
		}

		return $load;
	}

	/* ============================
	 * TablePress
	 * ============================ */

	/**
	 * Hook the TablePress CSS loading switch.
	 *
	 * On classic themes TablePress loads its frontend CSS everywhere; on block
	 * themes it already loads it only where a table is rendered. This turns the
	 * conditional path on for classic themes too, which is TablePress's own
	 * behavior, not a dequeue.
	 */
	private function hook_tablepress() {
		add_filter( 'tablepress_frontend_legacy_css_loading', array( $this, 'core_diet_tablepress_legacy_css' ) );
	}

	/**
	 * Turn off the TablePress site-wide CSS loading.
	 *
	 * TablePress also keeps the site-wide loading on inside the Elementor editor
	 * preview, which it recognizes by its query argument. That exception is not
	 * reproduced here: it would mean reading a query argument outside any nonce
	 * check, and Elementor's own is_preview_mode() cannot answer this early
	 * (the filter runs on init, before there is a queried post). The editor
	 * canvas still gets the stylesheet when TablePress renders a table, which
	 * is exactly what happens on every block theme today.
	 *
	 * @param bool $legacy Whether TablePress loads its CSS on every page.
	 * @return bool
	 */
	public function core_diet_tablepress_legacy_css( $legacy ) {
		if ( ! $legacy || is_admin() ) {
			return $legacy;
		}

		return false;
	}

	/* ============================
	 * Smash Balloon
	 * ============================ */

	/**
	 * Hook the Smash Balloon stylesheet switch.
	 *
	 * Smash Balloon has a native "load CSS only where a feed is shown" setting
	 * that ships turned off. Forcing it on the frontend registers the
	 * stylesheet instead of enqueuing it, and Smash Balloon itself enqueues it
	 * when a feed is rendered, so no feed can end up unstyled.
	 */
	private function hook_smash_balloon() {
		add_filter( 'option_sb_instagram_settings', array( $this, 'core_diet_smash_balloon_settings' ) );
	}

	/**
	 * Force the Smash Balloon conditional stylesheet mode on the frontend.
	 *
	 * @param mixed $value Stored Smash Balloon settings.
	 * @return mixed
	 */
	public function core_diet_smash_balloon_settings( $value ) {
		if ( ! is_array( $value ) || is_admin() ) {
			return $value;
		}

		if ( did_action( 'wp' ) && ( is_customize_preview() || is_preview() ) ) {
			return $value;
		}

		$value['enqueue_css_in_shortcode'] = true;

		return $value;
	}

	/* ============================
	 * Shared detection helpers
	 * ============================ */

	/**
	 * Whether this page can render content the post_content scan cannot see.
	 *
	 * Elementor keeps its layout in postmeta, and its Theme Builder can inject
	 * headers, footers, popups and whole templates into any request, so a form
	 * or a slider may be on screen without leaving a trace in post_content.
	 * When that is the case the modules keep their assets loaded.
	 *
	 * @return bool
	 */
	private function page_hides_content_from_scan() {
		if ( null !== $this->elementor_scan_cache ) {
			return $this->elementor_scan_cache;
		}

		$hidden = false;

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			// 1. The page itself is built with Elementor.
			if ( is_singular() ) {
				$post_id = get_queried_object_id();
				if ( $post_id && 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
					$hidden = true;
				}
			}

			// 2. Elementor Pro's Theme Builder applies a template to this request.
			if ( ! $hidden && defined( 'ELEMENTOR_PRO_VERSION' ) ) {
				$hidden = $this->elementor_theme_builder_applies();
			}

			// 3. A template is pulled in by shortcode.
			if ( ! $hidden ) {
				$needles = array( '[elementor-template' );
				$hidden  = $this->queried_content_contains( $needles ) || $this->widgets_contain( $needles );
			}
		}

		/**
		 * Filter whether the current page renders content the content scan
		 * cannot reach.
		 *
		 * Return true to keep every selective loading module from removing
		 * assets on this page, for example with a page builder DietPress does
		 * not know about.
		 *
		 * @since 3.4.0
		 * @param bool $hidden Whether unscannable content may be rendered.
		 */
		$this->elementor_scan_cache = (bool) apply_filters( 'dietpress_selective_page_hides_content', $hidden );

		return $this->elementor_scan_cache;
	}

	/**
	 * Whether Elementor Pro's Theme Builder has a template for this request.
	 *
	 * The conditions manager is not a documented API, so every step is guarded
	 * and an unreachable API is read as "yes, there may be a template": the
	 * modules then keep their assets, which is the safe answer.
	 *
	 * @return bool
	 */
	private function elementor_theme_builder_applies() {
		$module_class = '\ElementorPro\Modules\ThemeBuilder\Module';

		if ( ! class_exists( $module_class ) || ! method_exists( $module_class, 'instance' ) ) {
			return true;
		}

		$module = call_user_func( array( $module_class, 'instance' ) );

		if ( ! is_object( $module ) || ! method_exists( $module, 'get_conditions_manager' ) ) {
			return true;
		}

		$conditions = $module->get_conditions_manager();

		if ( ! is_object( $conditions ) || ! method_exists( $conditions, 'get_documents_for_location' ) ) {
			return true;
		}

		foreach ( array( 'header', 'footer', 'single', 'archive', 'popup' ) as $location ) {
			$documents = $conditions->get_documents_for_location( $location );
			if ( ! empty( $documents ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a queued stylesheet declares the given handle as a dependency.
	 *
	 * Used before removing a shared handle such as dashicons: a plugin that
	 * enqueued it directly cannot be told apart, but one that declared it as a
	 * dependency can.
	 *
	 * @param string $handle Style handle to look for.
	 * @return bool
	 */
	private function style_is_a_dependency( $handle ) {
		$styles = wp_styles();

		if ( ! $styles instanceof WP_Styles ) {
			return false;
		}

		foreach ( $styles->queue as $queued ) {
			if ( $queued === $handle || ! isset( $styles->registered[ $queued ] ) ) {
				continue;
			}

			if ( in_array( $handle, (array) $styles->registered[ $queued ]->deps, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the content about to be rendered contains any of the needles.
	 *
	 * On singular views it checks the queried post; on archive-style views it
	 * checks every post in the main query (some themes print full content in
	 * loops), capped to a sane number to keep the scan cheap.
	 *
	 * @param array $needles Plain substrings to search for.
	 * @return bool
	 */
	private function queried_content_contains( $needles ) {
		$contents = array();

		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$contents[] = $post->post_content;
			}
		} else {
			global $wp_query;
			if ( $wp_query instanceof WP_Query && ! empty( $wp_query->posts ) ) {
				$posts = array_slice( $wp_query->posts, 0, 50 );
				foreach ( $posts as $post ) {
					if ( $post instanceof WP_Post ) {
						$contents[] = $post->post_content;
					}
				}
			}
		}

		foreach ( $contents as $content ) {
			if ( '' === $content ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $content, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether any active widget area contains one of the needles.
	 *
	 * Scans the stored content of text, custom HTML and block widgets, which
	 * are the widget types that can carry shortcodes or block markup. Results
	 * are memoized per request.
	 *
	 * @param array $needles Plain substrings to search for.
	 * @return bool
	 */
	private function widgets_contain( $needles ) {
		$cache_key = md5( implode( '|', $needles ) );

		if ( isset( $this->widget_scan_cache[ $cache_key ] ) ) {
			return $this->widget_scan_cache[ $cache_key ];
		}

		$found = false;

		foreach ( array( 'widget_text', 'widget_custom_html', 'widget_block' ) as $option ) {
			$instances = get_option( $option );
			if ( ! is_array( $instances ) ) {
				continue;
			}

			$id_base = str_replace( 'widget_', '', $option );

			foreach ( $instances as $number => $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}

				$haystack = '';
				foreach ( array( 'text', 'content' ) as $field ) {
					if ( ! empty( $instance[ $field ] ) && is_string( $instance[ $field ] ) ) {
						$haystack .= $instance[ $field ];
					}
				}

				if ( '' === $haystack ) {
					continue;
				}

				foreach ( $needles as $needle ) {
					if ( false !== strpos( $haystack, $needle ) ) {
						// Content matches: only count it when this widget
						// instance sits in an active sidebar.
						if ( is_active_widget( false, $id_base . '-' . $number, $id_base, true ) ) {
							$found = true;
							break 3;
						}
						break;
					}
				}
			}
		}

		$this->widget_scan_cache[ $cache_key ] = $found;

		return $found;
	}
}
