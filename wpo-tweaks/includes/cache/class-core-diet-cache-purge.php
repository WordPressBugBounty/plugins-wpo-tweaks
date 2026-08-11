<?php
/**
 * Page cache invalidation.
 *
 * Selective where it is cheap and obvious, total where guessing would be worse
 * than rebuilding. "Cache did not clear after I published" is the single most
 * common complaint in every page cache plugin's support forum, so the bias
 * here is deliberate: purge more than strictly necessary rather than leave a
 * stale page behind.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Purge {

	/** @var Core_Diet_Cache_Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Core_Diet_Cache_Settings $settings Module settings.
	 */
	public function __construct( Core_Diet_Cache_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the invalidation hooks.
	 */
	public function init() {
		// Content.
		add_action( 'transition_post_status', array( $this, 'on_post_transition' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );

		// Comments.
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_transition' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'on_comment_edited' ) );
		add_action( 'deleted_comment', array( $this, 'on_comment_edited' ) );

		// Taxonomies.
		add_action( 'edited_term', array( $this, 'on_term_changed' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_changed' ), 10, 3 );

		// Anything that changes every page at once. Cheap and safe: refining
		// these into selective purges is a job for a later version, and getting
		// it wrong costs far more than a rebuilt cache.
		foreach ( array(
			'switch_theme',
			'customize_save_after',
			'wp_update_nav_menu',
			'update_option_sidebars_widgets',
			'activated_plugin',
			'deactivated_plugin',
			'upgrader_process_complete',
			'permalink_structure_changed',
			'update_option_home',
			'update_option_siteurl',
			'update_option_blogname',
			'update_option_blogdescription',
			'update_option_show_on_front',
			'update_option_page_on_front',
			'update_option_page_for_posts',
			// A store that leaves "coming soon" mode renders different HTML on
			// every URL. WooCommerce publishes this change but has no way to
			// tell us, so the option itself is the signal.
			'update_option_woocommerce_coming_soon',
			'update_option_woocommerce_store_pages_only',
		) as $hook ) {
			add_action( $hook, array( $this, 'purge_all' ) );
		}

		// Public API.
		add_action( 'dietpress_cache_purge_all', array( $this, 'purge_all' ) );

		// Scheduled cleanup.
		add_action( Core_Diet_Cache::CRON_HOOK, array( $this, 'collect_garbage' ) );
	}

	/**
	 * Empty the whole cache.
	 *
	 * @return int Files deleted.
	 */
	public function purge_all() {
		return Core_Diet_Cache_Store::purge_all();
	}

	/**
	 * Purge one URL, its pagination and its comment pagination.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return int Files deleted.
	 */
	public function purge_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return 0;
		}

		$dir = Core_Diet_Cache_Store::dir_for_url( $url );
		if ( ! $dir ) {
			return 0;
		}

		return Core_Diet_Cache_Store::delete_variants( $dir );
	}

	/**
	 * Purge a post and everything that lists it.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return int Files deleted.
	 */
	public function purge_post( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $this->get_post_urls( $post ) as $url ) {
			$deleted += $this->purge_url( $url );
		}

		/**
		 * Fires after a post's cached pages have been purged.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'dietpress_cache_purged_post', $post->ID );

		return $deleted;
	}

	/**
	 * Every front-end URL that shows a given post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Absolute URLs.
	 */
	private function get_post_urls( WP_Post $post ) {
		$urls = array();

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		// The front page and the posts page both list it.
		$urls[] = home_url( '/' );

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page && 'page' === get_option( 'show_on_front' ) ) {
			$link = get_permalink( $posts_page );
			if ( $link ) {
				$urls[] = $link;
			}
		}

		// Its post type archive.
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}

		// Its author archive.
		if ( $post->post_author ) {
			$author = get_author_posts_url( (int) $post->post_author );
			if ( $author ) {
				$urls[] = $author;
			}
		}

		// Every archive of every term it belongs to.
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		// Date archives, which only exist for posts.
		if ( 'post' === $post->post_type ) {
			$year  = (int) get_the_time( 'Y', $post );
			$month = (int) get_the_time( 'm', $post );
			if ( $year ) {
				$urls[] = get_year_link( $year );
				if ( $month ) {
					$urls[] = get_month_link( $year, $month );
				}
			}
		}

		/**
		 * Filter the URLs purged along with a post.
		 *
		 * @param array   $urls Absolute URLs.
		 * @param WP_Post $post Post being purged.
		 */
		$urls = apply_filters( 'dietpress_cache_post_urls', $urls, $post );

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Purge when a post enters or leaves the published state.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_post_transition( $new_status, $old_status, $post ) {
		if ( ! $this->is_public_post( $post ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		$this->purge_post( $post );
	}

	/**
	 * Purge the previous permalink when a post moves.
	 *
	 * Renaming a slug leaves the old URL cached and still being served, which
	 * reads to the site owner as "the plugin ignored my change". The old
	 * address has to be purged explicitly because nothing else will ever
	 * regenerate it.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ) {
		if ( ! $this->is_public_post( $post_after ) ) {
			return;
		}

		$moved = $post_after->post_name !== $post_before->post_name
			|| $post_after->post_parent !== $post_before->post_parent
			|| $post_after->post_type !== $post_before->post_type;

		if ( ! $moved ) {
			return;
		}

		$old = get_permalink( $post_before );
		if ( $old ) {
			$this->purge_url( $old );
		}
	}

	/**
	 * Purge when a post is deleted for good.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_deleted( $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && $this->is_public_post( $post ) ) {
			$this->purge_post( $post );
		}
	}

	/**
	 * Purge the commented post when the comment is published straight away.
	 *
	 * A comment held for moderation does not purge anything: an open comment
	 * form is a flood vector, and a spam wave would rebuild the same page
	 * hundreds of times. The commenter still sees their own notice, because
	 * WordPress sends them back with unapproved and moderation-hash in the URL
	 * and those parameters can never be added to the ignore list.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved 1 when already approved.
	 */
	public function on_comment_post( $comment_id, $comment_approved ) {
		if ( 1 !== (int) $comment_approved ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->purge_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Purge when a comment is approved, unapproved, spammed or trashed.
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Previous status.
	 * @param WP_Comment $comment    Comment object.
	 */
	public function on_comment_transition( $new_status, $old_status, $comment ) {
		if ( $comment instanceof WP_Comment ) {
			$this->purge_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Purge when a comment is edited or deleted.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment_edited( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->purge_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * Purge a term archive when the term changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy name.
	 */
	public function on_term_changed( $term_id, $tt_id, $taxonomy ) {
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->purge_url( $link );
		}

		// The term may be listed on the front page too (a category widget, a
		// menu with a counter), and there is no cheap way to know.
		$this->purge_url( home_url( '/' ) );
	}

	/**
	 * Whether a post is the kind of thing that has a public URL.
	 *
	 * @param mixed $post Post object.
	 * @return bool
	 */
	private function is_public_post( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		if ( 'nav_menu_item' === $post->post_type ) {
			return false;
		}

		$type = get_post_type_object( $post->post_type );

		return $type && ( $type->public || $type->publicly_queryable );
	}

	/**
	 * Scheduled cleanup: expired pages, abandoned temporary files, empty dirs.
	 */
	public function collect_garbage() {
		Core_Diet_Cache_Store::collect_garbage( $this->settings->get_ttl() );
		update_option( 'core_diet_cache_last_gc', time(), false );
	}

}

if ( ! function_exists( 'dietpress_purge_page_cache' ) ) {
	/**
	 * Purge the page cache: one URL, or everything.
	 *
	 * Public helper for other plugins and for site-specific code.
	 *
	 * @param string|null $url Absolute URL, or null for the whole cache.
	 * @return int Files deleted.
	 */
	function dietpress_purge_page_cache( $url = null ) {
		if ( ! class_exists( 'Core_Diet_Cache_Store' ) ) {
			return 0;
		}

		if ( null === $url ) {
			return Core_Diet_Cache_Store::purge_all();
		}

		$dir = Core_Diet_Cache_Store::dir_for_url( $url );

		return $dir ? Core_Diet_Cache_Store::delete_variants( $dir ) : 0;
	}
}
