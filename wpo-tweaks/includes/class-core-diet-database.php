<?php
/**
 * DietPress database optimization features.
 *
 * Database cleanup and query optimizations.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Database {

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
		if ( $this->settings->is_enabled( 'clean_transients' ) ) {
			// Schedule transient cleanup if not already scheduled.
			if ( ! wp_next_scheduled( 'core_diet_clean_transients' ) ) {
				wp_schedule_event( time(), 'daily', 'core_diet_clean_transients' );
			}
		} elseif ( wp_next_scheduled( 'core_diet_clean_transients' ) ) {
			// Feature disabled: clear any previously scheduled cleanup.
			wp_clear_scheduled_hook( 'core_diet_clean_transients' );
		}

		// Always register the callback so the event runs when due.
		add_action( 'core_diet_clean_transients', array( $this, 'clean_expired_transients' ) );

		if ( $this->settings->is_enabled( 'optimize_comment_queries' ) ) {
			add_filter( 'comments_clauses', array( $this, 'optimize_comments_query' ), 10, 2 );
		}

		if ( $this->settings->is_enabled( 'optimize_main_queries' ) ) {
			add_action( 'pre_get_posts', array( $this, 'optimize_queries' ) );
		}
	}

	/**
	 * Clean expired transients using WordPress methods.
	 */
	public function clean_expired_transients() {
		// Use cache to prevent multiple executions
		$cache_key = 'core_diet_cleaning_transients';
		if ( wp_cache_get( $cache_key ) ) {
			return; // Already running
		}

		// Mark as running
		wp_cache_set( $cache_key, true, '', 300 ); // 5 minutes

		// Use WordPress functions for cleaning transients
		delete_expired_transients();

		// Clear cache after operation
		wp_cache_delete( $cache_key );

		// Cache that cleanup completed
		wp_cache_set( 'core_diet_last_transient_cleanup', time(), '', HOUR_IN_SECONDS );
	}

	/**
	 * Optimize comments queries
	 *
	 * Only filter standard comments (empty type or 'comment').
	 * Plugins like CusRev, WooCommerce Reviews, etc. use custom
	 * comment types with their own approval logic.
	 *
	 * @since 2.2.2 Added comment_type check to avoid conflicts with review plugins
	 */
	public function optimize_comments_query( $clauses, $query ) {
		if ( is_admin() || empty( $clauses['where'] ) ) {
			return $clauses;
		}

		// Only apply to standard comments, not custom comment types
		// This prevents conflicts with CusRev, WooCommerce Reviews, etc.
		$comment_type = isset( $query->query_vars['type'] ) ? $query->query_vars['type'] : '';

		if ( ! empty( $comment_type ) && $comment_type !== 'comment' ) {
			return $clauses;
		}

		// Avoid duplicate clause if already present
		if ( strpos( $clauses['where'], "comment_approved = '1'" ) === false ) {
			$clauses['where'] .= " AND comment_approved = '1'";
		}

		return $clauses;
	}

	/**
	 * Optimize main queries - FIXED VERSION FOR PAGINATION
	 */
	public function optimize_queries( $query ) {
		if ( ! is_admin() && $query->is_main_query() ) {
			// EXCLUDE ALL WOOCOMMERCE RELATED CONTENT
			if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
				return; // Exit without touching anything WooCommerce
			}

			// Exclude product categories specifically
			if ( function_exists( 'is_product_category' ) && is_product_category() ) {
				return;
			}

			// Exclude all WooCommerce taxonomies
			if ( is_tax( array( 'product_cat', 'product_tag', 'product_shipping_class' ) ) ) {
				return;
			}

			// Only apply no_found_rows when we DON'T need pagination
			if ( $query->is_archive() || $query->is_home() ) {
				// Check if there are more posts than posts_per_page limit
				$posts_per_page = get_option( 'posts_per_page', 10 );

				// Only apply no_found_rows if we don't need pagination
				// i.e., if we're sure there are no more posts to show
				$total_posts = wp_count_posts()->publish;

				// If few posts or on specific page that doesn't need pagination
				if ( $total_posts <= $posts_per_page && ! is_paged() ) {
					$query->set( 'no_found_rows', true );
				}
				// In any other case, DON'T apply no_found_rows to maintain pagination
			}
		}
	}
}
