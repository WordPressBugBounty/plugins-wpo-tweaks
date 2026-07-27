<?php
/**
 * DietPress Light features.
 *
 * Safe to disable on any site: legacy, cosmetic, or no-impact features.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Light {

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
		if ( $this->settings->is_enabled( 'disable_emojis' ) ) {
			$this->disable_emojis();
		}

		if ( $this->settings->is_enabled( 'disable_rsd_link' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( $this->settings->is_enabled( 'disable_wlw_manifest' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( $this->settings->is_enabled( 'disable_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( $this->settings->is_enabled( 'disable_self_pingbacks' ) ) {
			add_action( 'pre_ping', array( $this, 'remove_self_pingbacks' ) );
		}

		if ( $this->settings->is_enabled( 'disable_capital_p' ) ) {
			remove_filter( 'the_title', 'capital_P_dangit', 11 );
			remove_filter( 'the_content', 'capital_P_dangit', 11 );
			remove_filter( 'comment_text', 'capital_P_dangit', 31 );
		}

		if ( $this->settings->is_enabled( 'disable_update_notices' ) ) {
			add_action( 'admin_head', array( $this, 'hide_update_notices' ), 1 );
		}

		if ( $this->settings->is_enabled( 'disable_comment_pagination' ) ) {
			// Force comment pagination off.
			add_filter( 'pre_option_page_comments', '__return_zero' );
			// 301 redirect /comment-page-N/ URLs to the canonical post.
			add_action( 'template_redirect', array( $this, 'redirect_comment_pages' ) );
			// Hide pagination options in Settings > Discussion.
			add_action( 'admin_enqueue_scripts', array( $this, 'hide_comment_pagination_admin_ui' ) );
		}

		if ( $this->settings->is_enabled( 'disable_wp_logo_admin_bar' ) ) {
			add_action( 'admin_bar_menu', array( $this, 'remove_wp_logo_admin_bar' ), 999 );
		}

		if ( $this->settings->is_enabled( 'disable_image_editor' ) ) {
			// Remove the AJAX endpoints that serve and save the editor.
			add_action( 'admin_init', array( $this, 'remove_image_editor_endpoints' ) );
			// Hide the "Edit Image" entry points and skip the editor script.
			add_action( 'admin_enqueue_scripts', array( $this, 'hide_image_editor_ui' ) );
			add_action( 'admin_print_footer_scripts', array( $this, 'dequeue_image_editor_script' ), 1 );
		}
	}

	/**
	 * Disable WordPress emoji scripts, styles, and DNS prefetch.
	 */
	private function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', array( $this, 'disable_emojis_tinymce' ) );
		add_filter( 'wp_resource_hints', array( $this, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove wpemoji TinyMCE plugin.
	 *
	 * @param array $plugins TinyMCE plugins.
	 * @return array
	 */
	public function disable_emojis_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}
		return array();
	}

	/**
	 * Remove DNS prefetch for s.w.org (emoji CDN).
	 *
	 * @param array  $urls          URLs to prefetch.
	 * @param string $relation_type Relation type.
	 * @return array
	 */
	public function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$urls = array_filter( $urls, function( $url ) {
				return ! is_string( $url ) || false === strpos( $url, 'https://s.w.org' );
			} );
		}
		return $urls;
	}

	/**
	 * Remove self-pingbacks from the ping list.
	 *
	 * @param array $links Ping URLs.
	 */
	public function remove_self_pingbacks( &$links ) {
		$home_url = home_url();
		foreach ( $links as $key => $link ) {
			if ( 0 === strpos( $link, $home_url ) ) {
				unset( $links[ $key ] );
			}
		}
	}

	/**
	 * Hide update notices for non-administrator users.
	 */
	public function hide_update_notices() {
		if ( ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'admin_notices', 'maintenance_nag', 10 );
		}
	}

	/**
	 * 301 redirect /comment-page-N/ URLs to the canonical post URL.
	 */
	public function redirect_comment_pages() {
		if ( is_singular() && get_query_var( 'cpage' ) ) {
			wp_safe_redirect( get_permalink(), 301 );
			exit;
		}
	}

	/**
	 * Remove the WordPress logo from the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function remove_wp_logo_admin_bar( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'wp-logo' );
	}

	/**
	 * Remove the AJAX endpoints that power the media library image editor.
	 *
	 * The editor markup and every crop, rotate, scale and restore action travel
	 * over these two endpoints, so dropping them disables the editor entirely.
	 * Note that admin-ajax.php registers them at priority 1, before admin_init.
	 */
	public function remove_image_editor_endpoints() {
		remove_action( 'wp_ajax_image-editor', 'wp_ajax_image_editor', 1 );
		remove_action( 'wp_ajax_imgedit-preview', 'wp_ajax_imgedit_preview', 1 );
	}

	/**
	 * Hide the "Edit Image" entry points across the admin.
	 *
	 * Covers the button on the attachment edit screen and in the legacy uploader
	 * (both rendered with an imgedit-open-btn-<ID> identifier), plus the links in
	 * the media modal templates.
	 */
	public function hide_image_editor_ui() {
		$css  = '[id^="imgedit-open-btn-"] { display: none !important; }';
		$css .= ' .edit-attachment { display: none !important; }';
		$css .= ' .imgedit-wrap { display: none !important; }';
		wp_add_inline_style( 'common', $css );
	}

	/**
	 * Skip the image editor script, which prints in the admin footer.
	 */
	public function dequeue_image_editor_script() {
		wp_dequeue_script( 'image-edit' );
	}

	/**
	 * Hide comment pagination options in Settings > Discussion.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function hide_comment_pagination_admin_ui( $hook ) {
		if ( 'options-discussion.php' !== $hook ) {
			return;
		}
		// Hide the entire pagination row: checkbox, label, sub-options.
		$css  = '#page_comments { display: none !important; }';
		$css .= ' label[for="page_comments"] { display: none !important; }';
		$css .= ' #comments-per-page, input[name="comments_per_page"] { display: none !important; }';
		$css .= ' #default_comments_page { display: none !important; }';
		$css .= ' #comment_order { display: none !important; }';
		// Hide entire row via :has() where supported.
		$css .= ' tr:has(#page_comments) { display: none !important; }';
		wp_add_inline_style( 'common', $css );

		// JS fallback: hide the entire <tr> containing the pagination checkbox.
		add_action( 'admin_footer-options-discussion.php', function() {
			$js = <<<'JS'
(function(){
	var cb = document.getElementById('page_comments');
	if (!cb) return;
	var tr = cb.closest('tr');
	if (tr) tr.style.display = 'none';
})();
JS;
			wp_print_inline_script_tag( $js );
		} );
	}
}
