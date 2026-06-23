<?php
/**
 * DietPress Emails features.
 *
 * Silences the automatic emails WordPress sends on its own (update results,
 * comment notifications, user and password notices). Every toggle is OFF by
 * default: muting an email is always the user's choice, never a default.
 *
 * Also hosts the two email-related toggles moved here from the Light module
 * (admin email verification prompt and post-by-email), which are not
 * notifications themselves but belong with the email settings.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Emails {

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
		// --- Updates group ---

		// Auto-update result for core. Critical-type emails are always kept:
		// they warn about a failed update that may have left the site broken.
		if ( $this->settings->is_enabled( 'disable_auto_core_update_email' ) ) {
			add_filter( 'auto_core_update_send_email', array( $this, 'filter_auto_core_update_email' ), 10, 2 );
		}

		// "A new version of WordPress is available" that will NOT auto-install
		// (core auto-updates disabled, or no filesystem credentials). This is
		// the only heads-up when core auto-updates are off, so it carries a
		// stronger risk notice in the UI.
		if ( $this->settings->is_enabled( 'disable_core_update_available_email' ) ) {
			add_filter( 'send_core_update_notification_email', '__return_false' );
		}

		// Auto-update result for plugins.
		if ( $this->settings->is_enabled( 'disable_auto_plugin_update_email' ) ) {
			add_filter( 'auto_plugin_update_send_email', '__return_false' );
		}

		// Auto-update result for themes.
		if ( $this->settings->is_enabled( 'disable_auto_theme_update_email' ) ) {
			add_filter( 'auto_theme_update_send_email', '__return_false' );
		}

		// --- Comments group ---

		// Comment awaiting moderation, notice to moderators.
		if ( $this->settings->is_enabled( 'disable_comment_moderation_email' ) ) {
			add_filter( 'notify_moderator', '__return_false' );
		}

		// Published comment, notice to the post author.
		if ( $this->settings->is_enabled( 'disable_comment_author_email' ) ) {
			add_filter( 'notify_post_author', '__return_false' );
		}

		// --- Users & passwords group ---

		// New user registered, notice to the admin (WP 6.1+).
		if ( $this->settings->is_enabled( 'disable_new_user_admin_email' ) ) {
			add_filter( 'wp_send_new_user_notification_to_admin', '__return_false' );
		}

		// New user registered, notice to the user (WP 6.1+). This email carries
		// the set-your-password link, so the UI warns against disabling it on
		// sites with open registration.
		if ( $this->settings->is_enabled( 'disable_new_user_email' ) ) {
			add_filter( 'wp_send_new_user_notification_to_user', '__return_false' );
		}

		// Password reset, notice to the admin.
		if ( $this->settings->is_enabled( 'disable_password_reset_admin_email' ) ) {
			remove_action( 'after_password_reset', 'wp_password_change_notification' );
		}

		// Password changed, notice to the user.
		if ( $this->settings->is_enabled( 'disable_password_change_email' ) ) {
			add_filter( 'send_password_change_email', '__return_false' );
		}

		// Email changed, notice to the user.
		if ( $this->settings->is_enabled( 'disable_email_change_email' ) ) {
			add_filter( 'send_email_change_email', '__return_false' );
		}

		// Site admin email changed, notice to the OLD address. The confirmation
		// email sent to the NEW address is left untouched: it completes the change.
		if ( $this->settings->is_enabled( 'disable_admin_email_change_email' ) ) {
			add_filter( 'send_site_admin_email_change_email', '__return_false' );
		}

		// --- System group (moved from Light; not notifications) ---

		// Periodic admin email verification prompt (every 6 months).
		if ( $this->settings->is_enabled( 'disable_email_check' ) ) {
			add_filter( 'admin_email_check_interval', '__return_false' );
		}

		// Post by email (wp-mail.php).
		if ( $this->settings->is_enabled( 'disable_post_by_email' ) ) {
			add_action( 'init', array( $this, 'block_wp_mail_php' ), 1 );
			// Hide "Post via email" section in Settings > Writing.
			add_action( 'admin_enqueue_scripts', array( $this, 'hide_post_by_email_admin_ui' ) );
		}
	}

	/**
	 * Filter the core auto-update result email, keeping critical notices.
	 *
	 * @param bool   $send Whether to send the email.
	 * @param string $type The type of email: 'success', 'fail' or 'critical'.
	 * @return bool
	 */
	public function filter_auto_core_update_email( $send, $type ) {
		// Always keep critical emails: a failed update can leave the site unusable.
		if ( 'critical' === $type ) {
			return $send;
		}
		return false;
	}

	/**
	 * Block access to wp-mail.php (posting by email).
	 */
	public function block_wp_mail_php() {
		// $_SERVER['SCRIPT_FILENAME'] is set by the server, not user-controllable
		// through a URL, so no nonce applies. Unslash and sanitize, then compare
		// the basename against the known script name.
		$script = isset( $_SERVER['SCRIPT_FILENAME'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) )
			: '';
		if ( 'wp-mail.php' === basename( $script ) ) {
			wp_die(
				esc_html__( 'Post by email is disabled.', 'wpo-tweaks' ),
				esc_html__( 'Forbidden', 'wpo-tweaks' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Hide "Post via email" section in Settings > Writing.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function hide_post_by_email_admin_ui( $hook ) {
		if ( 'options-writing.php' !== $hook ) {
			return;
		}
		// Hide the form-table containing the mailserver_url field, plus the
		// preceding h2 ("Post via email") and descriptive paragraph (random
		// strings). The default_email_category row uses a <select>, so it is
		// covered by hiding the entire form-table.
		$css  = '.form-table:has(input[name="mailserver_url"]) { display: none !important; }';
		$css .= ' h2.title:has(+ p + .form-table input[name="mailserver_url"]),';
		$css .= ' h2.title:has(+ p + .form-table input[name="mailserver_url"]) + p { display: none !important; }';
		wp_add_inline_style( 'common', $css );

		// JS fallback for browsers without :has() support.
		add_action( 'admin_footer-options-writing.php', function() {
			$js = <<<'JS'
(function(){
	var input = document.querySelector('input[name="mailserver_url"]');
	if (!input) return;
	var table = input.closest('table.form-table');
	if (!table) return;
	table.style.display = 'none';
	var prev = table.previousElementSibling;
	if (prev && prev.tagName === 'P') {
		prev.style.display = 'none';
		prev = prev.previousElementSibling;
	}
	if (prev && prev.tagName === 'H2') {
		prev.style.display = 'none';
	}
})();
JS;
			wp_print_inline_script_tag( $js );
		} );
	}
}
