<?php
/**
 * DietPress Promotional Banner.
 *
 * Displays random AyudaWP plugins and services with rotation.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DietPress Promo Banner class.
 */
class Core_Diet_Promo_Banner {

	/**
	 * Current plugin slug to exclude from recommendations.
	 *
	 * @var string
	 */
	private $current_plugin_slug;

	/**
	 * Text domain for translations.
	 *
	 * @var string
	 */
	private $textdomain;

	/**
	 * CSS class prefix.
	 *
	 * @var string
	 */
	private $css_prefix;

	/**
	 * Constructor.
	 *
	 * @param string $current_plugin_slug Current plugin slug.
	 * @param string $textdomain          Text domain for translations.
	 * @param string $css_prefix          CSS class prefix.
	 */
	public function __construct( $current_plugin_slug, $textdomain, $css_prefix ) {
		$this->current_plugin_slug = $current_plugin_slug;
		$this->textdomain          = $textdomain;
		$this->css_prefix          = $css_prefix;
	}

	/**
	 * Get plugins catalog.
	 *
	 * @return array
	 */
	private function get_plugins_catalog() {
		return array(
			'vigilante'          => array(
				'icon'        => 'dashicons-shield',
				'title'       => __( 'Complete WordPress security', 'wpo-tweaks' ),
				'description' => __( 'All-in-one security plugin: firewall, login protection, security headers, 2FA, file integrity monitoring, and activity logging.', 'wpo-tweaks' ),
				'button'      => __( 'Install Vigilant', 'wpo-tweaks' ),
			),
			'gozer'              => array(
				'icon'        => 'dashicons-admin-network',
				'title'       => __( 'Restrict site access', 'wpo-tweaks' ),
				'description' => __( 'Force visitors to log in before accessing your site with extensive exception controls for pages, posts, and user roles.', 'wpo-tweaks' ),
				'button'      => __( 'Install Gozer', 'wpo-tweaks' ),
			),
			'vigia'              => array(
				'icon'        => 'dashicons-visibility',
				'title'       => __( 'Monitor AI crawler activity', 'wpo-tweaks' ),
				'description' => __( 'Track which AI bots visit your site, analyze their behavior, and take control with blocking rules and robots.txt management.', 'wpo-tweaks' ),
				'button'      => __( 'Install VigIA', 'wpo-tweaks' ),
			),
			'ai-share-summarize' => array(
				'icon'        => 'dashicons-share',
				'title'       => __( 'Boost your AI presence', 'wpo-tweaks' ),
				'description' => __( 'Add social sharing and AI summarize buttons. Help visitors share your content and let AIs learn from your site while getting backlinks.', 'wpo-tweaks' ),
				'button'      => __( 'Install AI Share & Summarize', 'wpo-tweaks' ),
			),
			'ai-content-signals' => array(
				'icon'        => 'dashicons-flag',
				'title'       => __( 'Control AI content usage', 'wpo-tweaks' ),
				'description' => __( 'Cloudflare-endorsed plugin to define how AI systems can use your content: for training, search results, or both.', 'wpo-tweaks' ),
				'button'      => __( 'Install AI Content Signals', 'wpo-tweaks' ),
			),
			'wpo-tweaks'         => array(
				'icon'        => 'dashicons-food',
				'title'       => __( 'Put WordPress on a diet', 'wpo-tweaks' ),
				'description' => __( 'Disable bloat and apply 30+ performance tweaks (critical CSS, lazy loading, cache rules) with zero configuration for a leaner, faster site.', 'wpo-tweaks' ),
				'button'      => __( 'Install DietPress', 'wpo-tweaks' ),
			),
			'no-gutenberg'       => array(
				'icon'        => 'dashicons-edit-page',
				'title'       => __( 'Back to Classic Editor', 'wpo-tweaks' ),
				'description' => __( 'Completely remove Gutenberg, FSE styles, and block widgets. Restore the classic editing experience with better performance.', 'wpo-tweaks' ),
				'button'      => __( 'Install No Gutenberg', 'wpo-tweaks' ),
			),
			'anticache'          => array(
				'icon'        => 'dashicons-hammer',
				'title'       => __( 'Development toolkit', 'wpo-tweaks' ),
				'description' => __( 'Bypass all caching during development. Auto-detects cache plugins, enables debug mode, and includes maintenance screen.', 'wpo-tweaks' ),
				'button'      => __( 'Install Anti-Cache Kit', 'wpo-tweaks' ),
			),
			'auto-capitalize-names-ayudawp' => array(
				'icon'        => 'dashicons-editor-textcolor',
				'title'       => __( 'Fix customer names', 'wpo-tweaks' ),
				'description' => __( 'Auto-capitalize names and addresses in WordPress and WooCommerce. Keep invoices and reports professionally formatted.', 'wpo-tweaks' ),
				'button'      => __( 'Install Auto Capitalize', 'wpo-tweaks' ),
			),
			'easy-actions-scheduler-cleaner-ayudawp' => array(
				'icon'        => 'dashicons-database-remove',
				'title'       => __( 'Clean Action Scheduler', 'wpo-tweaks' ),
				'description' => __( 'Remove millions of completed, failed, and old actions from WooCommerce Action Scheduler. Reduce database size instantly.', 'wpo-tweaks' ),
				'button'      => __( 'Install Scheduler Cleaner', 'wpo-tweaks' ),
			),
			'native-aeo-pack'    => array(
				'icon'        => 'dashicons-visibility',
				'title'       => __( 'All-in-one SEO, AEO & GEO', 'wpo-tweaks' ),
				'description' => __( 'Meta tags, Open Graph, JSON-LD schema, robots and native sitemap control: the clean metadata search engines and AI assistants read, built on WordPress core.', 'wpo-tweaks' ),
				'button'      => __( 'Install Visibility', 'wpo-tweaks' ),
			),
			'post-visibility-control' => array(
				'icon'        => 'dashicons-hidden',
				'title'       => __( 'Control post visibility', 'wpo-tweaks' ),
				'description' => __( 'Hide posts from homepage, archives, feeds, or REST API while keeping them accessible via direct URL.', 'wpo-tweaks' ),
				'button'      => __( 'Install Post Visibility', 'wpo-tweaks' ),
			),
			'widget-visibility-control' => array(
				'icon'        => 'dashicons-welcome-widgets-menus',
				'title'       => __( 'Smart widget display', 'wpo-tweaks' ),
				'description' => __( 'Show or hide widgets based on pages, post types, categories, user roles, and more. Works with any theme.', 'wpo-tweaks' ),
				'button'      => __( 'Install Widget Visibility', 'wpo-tweaks' ),
			),
			'search-replace-text-blocks' => array(
				'icon'        => 'dashicons-search',
				'title'       => __( 'Search & replace in blocks', 'wpo-tweaks' ),
				'description' => __( 'Find and replace text across all your Gutenberg blocks. Bulk edit content without touching the database directly.', 'wpo-tweaks' ),
				'button'      => __( 'Install Search Replace Blocks', 'wpo-tweaks' ),
			),
			'seo-read-more-buttons-ayudawp' => array(
				'icon'        => 'dashicons-admin-links',
				'title'       => __( 'Better read more links', 'wpo-tweaks' ),
				'description' => __( 'Customize excerpt "read more" links with buttons, custom text, and nofollow option. Improve CTR and SEO.', 'wpo-tweaks' ),
				'button'      => __( 'Install SEO Read More', 'wpo-tweaks' ),
			),
			'show-only-lowest-prices-in-woocommerce-variable-products' => array(
				'icon'        => 'dashicons-tag',
				'title'       => __( 'Cleaner variable prices', 'wpo-tweaks' ),
				'description' => __( 'Display only the lowest price for WooCommerce variable products instead of confusing price ranges.', 'wpo-tweaks' ),
				'button'      => __( 'Install Lowest Price', 'wpo-tweaks' ),
			),
			'multiple-sale-prices-scheduler' => array(
				'icon'        => 'dashicons-calendar-alt',
				'title'       => __( 'Schedule sale prices', 'wpo-tweaks' ),
				'description' => __( 'Set multiple future sale prices for WooCommerce products. Plan promotions in advance with start and end dates.', 'wpo-tweaks' ),
				'button'      => __( 'Install Sale Scheduler', 'wpo-tweaks' ),
			),
			'easy-store-management-ayudawp' => array(
				'icon'        => 'dashicons-store',
				'title'       => __( 'Simplify store management', 'wpo-tweaks' ),
				'description' => __( 'Clean up WordPress admin for Store Managers. Hide unnecessary menus, keep only orders, products, and customers, plus quick access shortcuts.', 'wpo-tweaks' ),
				'button'      => __( 'Install Easy Store', 'wpo-tweaks' ),
			),
			'lightbox-images-for-divi' => array(
				'icon'        => 'dashicons-format-gallery',
				'title'       => __( 'Lightbox for Divi', 'wpo-tweaks' ),
				'description' => __( 'Add native lightbox functionality to Divi theme images. No jQuery, fast loading, fully customizable.', 'wpo-tweaks' ),
				'button'      => __( 'Install Divi Lightbox', 'wpo-tweaks' ),
			),
			'scheduled-posts-showcase' => array(
				'icon'        => 'dashicons-clock',
				'title'       => __( 'Show visitors what is coming up next', 'wpo-tweaks' ),
				'description' => __( 'Display your scheduled and future posts on the frontend to gain and retain visits.', 'wpo-tweaks' ),
				'button'      => __( 'Install Scheduled Posts Showcase', 'wpo-tweaks' ),
			),
			'periscopio'              => array(
				'icon'        => 'dashicons-rss',
				'title'       => __( 'Custom Dashboard News', 'wpo-tweaks' ),
				'description' => __( 'Add your own custom feeds and links to the news and events dashboard widget and replace WordPress default one.', 'wpo-tweaks' ),
				'button'      => __( 'Install Periscope', 'wpo-tweaks' ),
			),
			'eu-withdrawal-compliance' => array(
				'icon'        => 'dashicons-undo',
				'title'       => __( 'EU withdrawal compliance', 'wpo-tweaks' ),
				'description' => __( 'Add the EU online withdrawal function required by Directive 2023/2673 from June 2026. Public form, My Account button, email notice and SHA-256 receipt hash.', 'wpo-tweaks' ),
				'button'      => __( 'Install EU Withdrawal', 'wpo-tweaks' ),
			),
			'terms-conditions-consent-log' => array(
				'icon'        => 'dashicons-yes-alt',
				'title'       => __( 'Tamper-evident consent log', 'wpo-tweaks' ),
				'description' => __( 'GDPR art. 7.1 audit trail for any acceptance checkbox: WooCommerce checkout, CF7, WPForms, comments and shortcode. Timestamp, IP, version and SHA-256 sealed text.', 'wpo-tweaks' ),
				'button'      => __( 'Install Consent Log', 'wpo-tweaks' ),
			),
		);
	}

	/**
	 * Get services catalog.
	 *
	 * @return array
	 */
	private function get_services_catalog() {
		return array(
			'maintenance' => array(
				'icon'        => 'dashicons-admin-tools',
				'title'       => __( 'Need help with your website?', 'wpo-tweaks' ),
				'description' => __( 'Professional WordPress maintenance: security monitoring, regular backups, performance optimization, and priority support.', 'wpo-tweaks' ),
				'button'      => __( 'Learn more', 'wpo-tweaks' ),
				'url'         => 'https://mantenimiento.ayudawp.com',
			),
			'consultancy' => array(
				'icon'        => 'dashicons-businessman',
				'title'       => __( 'WordPress consultancy', 'wpo-tweaks' ),
				'description' => __( 'One-on-one online sessions to solve your WordPress doubts, get expert advice, and make better decisions for your project.', 'wpo-tweaks' ),
				'button'      => __( 'Book a session', 'wpo-tweaks' ),
				'url'         => 'https://servicios.ayudawp.com/producto/consultoria-online-wordpress/',
			),
			'hacked'      => array(
				'icon'        => 'dashicons-sos',
				'title'       => __( 'Hacked website?', 'wpo-tweaks' ),
				'description' => __( 'Fast recovery service for compromised WordPress sites. We clean malware, fix vulnerabilities, and restore your site security.', 'wpo-tweaks' ),
				'button'      => __( 'Get help now', 'wpo-tweaks' ),
				'url'         => 'https://servicios.ayudawp.com/producto/wordpress-hackeado/',
			),
			'development' => array(
				'icon'        => 'dashicons-editor-code',
				'title'       => __( 'Custom development', 'wpo-tweaks' ),
				'description' => __( 'Need a custom plugin, theme modifications, or specific functionality? We build tailored WordPress solutions for your needs.', 'wpo-tweaks' ),
				'button'      => __( 'Request a quote', 'wpo-tweaks' ),
				'url'         => 'https://servicios.ayudawp.com/producto/desarrollo-wordpress/',
			),
			'hosting'     => array(
				'icon'        => 'dashicons-cloud-saved',
				'title'       => __( 'Hosting built for WordPress', 'wpo-tweaks' ),
				'description' => __( 'Google Cloud servers, automatic geo-located daily backups, and 24/7 expert support. Speed, security, and migration tools included.', 'wpo-tweaks' ),
				'button'      => __( 'Learn more', 'wpo-tweaks' ),
				/* translators: SiteGround affiliate URL. Change this URL in translations to use a localized landing page. */
				'url'         => __( 'https://stgrnd.co/telladowpbox', 'wpo-tweaks' ),
			),
		);
	}

	/**
	 * Get random plugins excluding current.
	 *
	 * @param int $count Number of plugins to return.
	 * @return array
	 */
	private function get_random_plugins( $count = 2 ) {
		$plugins = $this->get_plugins_catalog();

		// Remove current plugin.
		unset( $plugins[ $this->current_plugin_slug ] );

		// Get random keys.
		$random_keys = array_rand( $plugins, min( $count, count( $plugins ) ) );

		if ( ! is_array( $random_keys ) ) {
			$random_keys = array( $random_keys );
		}

		$result = array();
		foreach ( $random_keys as $key ) {
			$result[ $key ] = $plugins[ $key ];
		}

		return $result;
	}

	/**
	 * Get random service.
	 *
	 * @return array
	 */
	private function get_random_service() {
		$services   = $this->get_services_catalog();
		$random_key = array_rand( $services );

		return $services[ $random_key ];
	}

	/**
	 * Render the promotional banner (horizontal 3-column layout).
	 */
	public function render() {
		$plugins = $this->get_random_plugins( 2 );
		$service = $this->get_random_service();
		$prefix  = $this->css_prefix;
		?>
		<!-- Promotional notice -->
		<div class="<?php echo esc_attr( $prefix ); ?>-promo-notice">
			<h4><?php esc_html_e( 'Starter kit for your site', 'wpo-tweaks' ); ?></h4>
			<div class="<?php echo esc_attr( $prefix ); ?>-promo-columns">

				<?php foreach ( $plugins as $slug => $plugin ) : ?>
				<div class="<?php echo esc_attr( $prefix ); ?>-promo-column">
					<span class="dashicons <?php echo esc_attr( $plugin['icon'] ); ?>"></span>
					<h5><?php echo esc_html( $plugin['title'] ); ?></h5>
					<p><?php echo esc_html( $plugin['description'] ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $slug . '&TB_iframe=true&width=772&height=618' ) ); ?>" class="button thickbox">
						<?php echo esc_html( $plugin['button'] ); ?>
					</a>
				</div>
				<?php endforeach; ?>

				<div class="<?php echo esc_attr( $prefix ); ?>-promo-column">
					<span class="dashicons <?php echo esc_attr( $service['icon'] ); ?>"></span>
					<h5><?php echo esc_html( $service['title'] ); ?></h5>
					<p><?php echo esc_html( $service['description'] ); ?></p>
					<a href="<?php echo esc_url( $service['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
						<?php echo esc_html( $service['button'] ); ?>
					</a>
				</div>

			</div>
		</div>
		<?php
	}
}