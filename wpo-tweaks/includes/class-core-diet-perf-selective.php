<?php
/**
 * DietPress selective third-party loading.
 *
 * Dequeues assets that WooCommerce, Contact Form 7 and the block library
 * enqueue site-wide, on the pages where their target content is not present.
 * Each module only acts when its target plugin (or feature) is active, and
 * every detection has an escape filter so themes and plugins with uncommon
 * setups can opt out.
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
	 * @param Core_Diet_Settings $settings Settings instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Initialize hooks for enabled features.
	 */
	public function init() {
		if ( ! $this->settings->is_enabled( 'selective_woocommerce' )
			&& ! $this->settings->is_enabled( 'selective_cf7' )
			&& ! $this->settings->is_enabled( 'selective_blocks' ) ) {
			return;
		}

		// Priority 99: run after the target plugins have enqueued their assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'core_diet_selective_dequeue' ), 99 );
	}

	/**
	 * Run the enabled selective-loading modules on the current page.
	 */
	public function core_diet_selective_dequeue() {
		// Never touch editors or previews: builders and the Customizer load
		// assets speculatively and a missing stylesheet breaks their canvas.
		if ( is_admin() || is_customize_preview() || is_preview() ) {
			return;
		}

		if ( $this->settings->is_enabled( 'selective_woocommerce' ) ) {
			$this->maybe_dequeue_woocommerce();
		}

		if ( $this->settings->is_enabled( 'selective_cf7' ) ) {
			$this->maybe_dequeue_cf7();
		}

		if ( $this->settings->is_enabled( 'selective_blocks' ) ) {
			$this->maybe_dequeue_block_library();
		}
	}

	/* ============================
	 * WooCommerce
	 * ============================ */

	/**
	 * Dequeue WooCommerce frontend assets on pages with no WooCommerce content.
	 */
	private function maybe_dequeue_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( $this->is_woocommerce_page() ) {
			return;
		}

		$styles = array(
			'woocommerce-general',
			'woocommerce-layout',
			'woocommerce-smallscreen',
			'woocommerce-inline',
			'wc-blocks-style',
			'wc-blocks-vendors-style',
		);

		/**
		 * Filter the WooCommerce style handles dequeued on non-WooCommerce pages.
		 *
		 * @since 3.3.0
		 * @param array $styles Style handles to dequeue.
		 */
		$styles = (array) apply_filters( 'dietpress_selective_wc_styles', $styles );

		$scripts = array(
			'woocommerce',
			'wc-add-to-cart',
			'wc-single-product',
			'wc-order-attribution',
			'sourcebuster-js',
			'js-cookie',
			'selectWoo',
			'select2',
		);

		/**
		 * Filter the WooCommerce script handles dequeued on non-WooCommerce pages.
		 *
		 * The cart fragments script is handled separately: it stays enqueued
		 * whenever a mini-cart is detected (see
		 * `dietpress_selective_wc_keep_cart_fragments`).
		 *
		 * @since 3.3.0
		 * @param array $scripts Script handles to dequeue.
		 */
		$scripts = (array) apply_filters( 'dietpress_selective_wc_scripts', $scripts );

		foreach ( $styles as $handle ) {
			wp_dequeue_style( $handle );
		}

		foreach ( $scripts as $handle ) {
			wp_dequeue_script( $handle );
		}

		// Cart fragments keeps the mini-cart count in sync on every page, so
		// it is only removed when no mini-cart widget is detected. Headers
		// with a hand-coded mini-cart can force-keep it through the filter.
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
	 * Whether the current page shows WooCommerce content.
	 *
	 * Covers the WooCommerce conditional tags (shop, product, archives, cart,
	 * checkout, account), plus WooCommerce shortcodes and blocks in the
	 * current singular content.
	 *
	 * @return bool
	 */
	private function is_woocommerce_page() {
		$is_wc = false;

		// Conditional tags. is_woocommerce() covers shop, products and
		// product taxonomy archives; the endpoints pages have their own tags.
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			$is_wc = true;
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$is_wc = true;
		} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$is_wc = true;
		} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$is_wc = true;
		}

		// WooCommerce shortcodes or blocks embedded in regular content.
		if ( ! $is_wc ) {
			$needles = array( '[products', '[product_', '[add_to_cart', '[woocommerce_', '[shop_messages', '<!-- wp:woocommerce/' );
			$is_wc   = $this->queried_content_contains( $needles );
		}

		/**
		 * Filter whether the current page counts as a WooCommerce page for
		 * the selective loading module.
		 *
		 * Return true to keep the WooCommerce assets loaded on this page.
		 *
		 * @since 3.3.0
		 * @param bool $is_wc Whether the page shows WooCommerce content.
		 */
		return (bool) apply_filters( 'dietpress_selective_is_wc_page', $is_wc );
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
	 * Dequeue Contact Form 7 assets on pages without a form.
	 */
	private function maybe_dequeue_cf7() {
		if ( ! class_exists( 'WPCF7' ) ) {
			return;
		}

		$has_form = $this->page_has_cf7_form();

		/**
		 * Filter whether the current page contains a Contact Form 7 form.
		 *
		 * Return true when a form is injected in a way the content scan
		 * cannot see (AJAX-loaded content, template parts, popups).
		 *
		 * @since 3.3.0
		 * @param bool $has_form Whether a CF7 form was detected on this page.
		 */
		$has_form = (bool) apply_filters( 'dietpress_selective_cf7_has_form', $has_form );

		if ( $has_form ) {
			return;
		}

		$styles  = array( 'contact-form-7', 'contact-form-7-rtl' );
		$scripts = array( 'contact-form-7', 'swv', 'wpcf7-recaptcha', 'google-recaptcha' );

		foreach ( $styles as $handle ) {
			wp_dequeue_style( $handle );
		}

		foreach ( $scripts as $handle ) {
			wp_dequeue_script( $handle );
		}
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
	private function page_has_cf7_form() {
		$needles = array( '[contact-form-7', '[contact-form', '<!-- wp:contact-form-7/' );

		if ( $this->queried_content_contains( $needles ) ) {
			return true;
		}

		return $this->widgets_contain( array( '[contact-form-7', '[contact-form', 'contact-form-7/contact-form-selector' ) );
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
	 * Shared detection helpers
	 * ============================ */

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
