<?php
/**
 * DietPress Promotional Banner.
 *
 * Promotional banner that rotates AyudaWP's WordPress services. On every
 * settings-page load it shows a random subset of service cards.
 *
 * Mirror of ayudawp-promo-banner-catalog-servicios.md (the single source of
 * truth). Plugin cross-promotion was dropped in 3.3.0: the banner shows only
 * services, so there is no host/sibling plugin to exclude and no
 * wordpress.org install modal (Thickbox) to load for it.
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
	 * How many service cards the banner shows at once.
	 *
	 * @var int
	 */
	const CARDS = 3;

	/**
	 * CSS class prefix.
	 *
	 * @var string
	 */
	private $css_prefix;

	/**
	 * Constructor.
	 *
	 * @param string $css_prefix CSS class prefix.
	 */
	public function __construct( $css_prefix ) {
		$this->css_prefix = $css_prefix;
	}

	/**
	 * Get services catalog.
	 *
	 * Mirror of ayudawp-promo-banner-catalog-servicios.md. All strings use the
	 * literal text domain (WPCS / make-pot requirement).
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
				'url'         => __( 'https://mantenimiento.ayudawp.com/en/', 'wpo-tweaks' ),
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
	 * Get a random subset of services to display.
	 *
	 * @param int $count Number of services to return.
	 * @return array Subset of the catalog, keyed by service key.
	 */
	private function get_random_services( $count = self::CARDS ) {
		$services = $this->get_services_catalog();

		$count       = max( 1, min( (int) $count, count( $services ) ) );
		$random_keys = array_rand( $services, $count );
		if ( ! is_array( $random_keys ) ) {
			$random_keys = array( $random_keys );
		}

		$result = array();
		foreach ( $random_keys as $key ) {
			$result[ $key ] = $services[ $key ];
		}

		return $result;
	}

	/**
	 * Render the promotional banner (service cards).
	 */
	public function render() {
		$services = $this->get_random_services();
		$prefix   = $this->css_prefix;
		?>
		<!-- Promotional notice -->
		<div class="<?php echo esc_attr( $prefix ); ?>-promo-notice">
			<div class="<?php echo esc_attr( $prefix ); ?>-promo-columns">

				<?php foreach ( $services as $service ) : ?>
				<div class="<?php echo esc_attr( $prefix ); ?>-promo-column">
					<span class="dashicons <?php echo esc_attr( $service['icon'] ); ?>"></span>
					<h5><?php echo esc_html( $service['title'] ); ?></h5>
					<p><?php echo esc_html( $service['description'] ); ?></p>
					<a href="<?php echo esc_url( $service['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
						<?php echo esc_html( $service['button'] ); ?>
					</a>
				</div>
				<?php endforeach; ?>

			</div>
		</div>
		<?php
	}
}
