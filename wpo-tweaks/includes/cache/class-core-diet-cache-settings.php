<?php
/**
 * Page cache settings.
 *
 * The module keeps its own option instead of growing core_diet_settings: that
 * one travels whole in every POST of the seven settings tabs, so saving any
 * unrelated tab would fire the cache listeners with foreign form data.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Cache_Settings {

	/** @var string Option name in wp_options. */
	const OPTION_NAME = 'core_diet_cache_settings';

	/** @var string Settings group for register_setting(). */
	const OPTION_GROUP = 'core_diet_cache_group';

	/** @var Core_Diet_Cache_Settings|null */
	private static $instance = null;

	/** @var array|null Cached settings. */
	private $settings = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Core_Diet_Cache_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default values.
	 *
	 * No string here is translatable, on purpose: a translated default written
	 * to the database freezes in the language of whoever saved it. Query
	 * parameter names and numbers are safe; user-facing copy is not, and none
	 * of it is stored.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'             => false,
			'ttl_hours'           => 12,
			'separate_mobile'     => false,
			'precompress_gzip'    => true,
			'exclude_urls'        => '',
			'ignore_query_params' => '',
			'host_cache_ack'      => false,
		);
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		if ( null === $this->settings ) {
			$this->settings = get_option( self::OPTION_NAME, array() );
			if ( ! is_array( $this->settings ) ) {
				$this->settings = array();
			}
		}

		if ( array_key_exists( $key, $this->settings ) ) {
			return $this->settings[ $key ];
		}

		$defaults = self::get_defaults();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}

	/**
	 * Whether a boolean setting is on.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_enabled( $key ) {
		return ! empty( $this->get( $key ) );
	}

	/**
	 * Drop the in-memory copy so the next read hits the database again.
	 */
	public function refresh() {
		$this->settings = null;
	}

	/**
	 * Time to live in seconds, or 0 when the cache only expires on events.
	 *
	 * @return int
	 */
	public function get_ttl() {
		return max( 0, (int) $this->get( 'ttl_hours' ) ) * HOUR_IN_SECONDS;
	}

	/**
	 * Query parameters that never make a request uncacheable.
	 *
	 * Tracking parameters only: they change the URL but not the page. A request
	 * carrying nothing but these is served the plain URL's cached copy.
	 *
	 * The list is code, not stored data, so sites get the additions of every
	 * release without touching their settings. The textarea in the settings
	 * page adds to this list, it does not replace it.
	 *
	 * @return array
	 */
	public static function get_builtin_ignored_params() {
		return array(
			// Google / Analytics.
			'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			'utm_id', 'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
			'gclid', 'gclsrc', 'dclid', 'wbraid', 'gbraid', 'gad_source', 'gad_campaignid',
			'srsltid', '_ga', '_gl', 'gdffi', 'gdfms', 'gdftrk',
			// Meta, Microsoft, X, TikTok, LinkedIn, Pinterest, Snapchat, Yandex.
			'fbclid', 'msclkid', 'twclid', 'ttclid', 'li_fat_id', 'epik', 'igshid',
			'sc_cid', 'yclid', '_openstat', 's_kwcid',
			// Email and marketing platforms.
			'mc_cid', 'mc_eid', 'ml_subscriber', 'ml_subscriber_hash', 'ck_subscriber_id',
			'vero_id', 'vero_conv', '_bta_c', '_bta_tid', '_kx',
			'hsa_acc', 'hsa_cam', 'hsa_grp', 'hsa_ad', 'hsa_src', 'hsa_tgt',
			'hsa_kw', 'hsa_mt', 'hsa_net', 'hsa_ver', 'hsCtaTracking',
			'__hssc', '__hstc', '__hsfp', '_hsenc', '_hsmi',
			// Matomo / Piwik.
			'pk_campaign', 'pk_kwd', 'pk_source', 'pk_medium', 'pk_content',
			'piwik_campaign', 'piwik_kwd', 'matomo_campaign', 'matomo_keyword',
			'mtm_campaign', 'mtm_keyword', 'mtm_source', 'mtm_medium', 'mtm_content',
			'mtm_cid', 'mtm_group', 'mtm_placement',
			// Miscellaneous ad networks and link shorteners.
			'at_medium', 'at_campaign', 'cmpid', 'campaignid', 'adgroupid', 'awc',
			'guccounter', 'guce_referrer', 'guce_referrer_sig', 'trk', 'trkCampaign',
			'rb_clickid', 'oly_anon_id', 'oly_enc_id', 'wickedid',
			'_branch_match_id', 'redirect_log_mongo_id', 'redirect_mongo_id',
			'sb_referer_host',
		);
	}

	/**
	 * Query parameters that may never be added to the ignore list.
	 *
	 * Every one of these changes what WordPress renders, so treating the URL as
	 * if the parameter were not there would serve the wrong page. The two that
	 * cost other cache plugins the most support are at the top: WordPress
	 * appends unapproved and moderation-hash to the redirect after a comment is
	 * posted, and that pair is the only reason a commenter whose comment is
	 * held for moderation sees the "awaiting moderation" notice at all. It has
	 * been the fallback since the comment cookies became opt-in in 4.9.6, so
	 * ignoring the parameters silently swallows the notice and produces the
	 * classic "my comment disappeared" ticket.
	 *
	 * @return array
	 */
	public static function get_protected_params() {
		return array(
			'unapproved', 'moderation-hash', 'replytocom',
			'preview', 'preview_id', 'preview_nonce', 'p', 'page_id', 'page', 'paged',
			's', 'author', 'cat', 'tag', 'year', 'monthnum', 'day', 'order', 'orderby',
			'customize_changeset_uuid', 'customize_theme', 'customize_messenger_channel',
			'wp_customize', 'doing_wp_cron', '_wpnonce', 'action', 'key', 'login',
			'wc-ajax', 'add-to-cart', 'removed_item', 'undo_item', 'filter_', 'min_price',
			'max_price', 'product_orderby', 'product_count', 'rest_route', 'lang',
		);
	}

	/**
	 * Whether a parameter name may never be added to the ignore list.
	 *
	 * An entry ending in an underscore is a prefix, not a name. WooCommerce
	 * layered navigation sends one parameter per attribute (filter_color,
	 * filter_size, and as many more as the shop has), so the list can only ever
	 * carry "filter_": comparing for equality let filter_color through, and a
	 * filtered shop page was then answered with the unfiltered one.
	 *
	 * @param string $name Parameter name.
	 * @return bool
	 */
	private static function is_protected_param( $name ) {
		foreach ( self::get_protected_params() as $protected ) {
			if ( '_' === substr( $protected, -1 ) ) {
				if ( 0 === strpos( $name, $protected ) ) {
					return true;
				}
			} elseif ( $name === $protected ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The full ignore list: built-in tracking parameters plus the site's own.
	 *
	 * @return array
	 */
	public function get_ignored_params() {
		$params = self::get_builtin_ignored_params();

		$extra = (string) $this->get( 'ignore_query_params' );
		if ( '' !== trim( $extra ) ) {
			$params = array_merge( $params, self::parse_lines( $extra ) );
		}

		/**
		 * Filter the query parameters that never make a request uncacheable.
		 *
		 * @param array $params Parameter names.
		 */
		$params = apply_filters( 'dietpress_cache_ignored_params', $params );

		$params = array_unique( array_map( 'strval', $params ) );

		// The filter runs before this, on purpose: a site that adds a
		// parameter of its own still cannot hand itself the wrong page.
		return array_values( array_filter( $params, array( __CLASS__, 'is_allowed_param' ) ) );
	}

	/**
	 * Callback form of the protected check, for array_filter().
	 *
	 * @param string $name Parameter name.
	 * @return bool
	 */
	public static function is_allowed_param( $name ) {
		return ! self::is_protected_param( (string) $name );
	}

	/**
	 * URL patterns excluded from the cache, one per line, "*" as wildcard.
	 *
	 * @return array
	 */
	public function get_exclude_patterns() {
		$patterns = self::parse_lines( (string) $this->get( 'exclude_urls' ) );

		/**
		 * Filter the URL patterns excluded from the page cache.
		 *
		 * @param array $patterns Patterns, relative paths with "*" as wildcard.
		 */
		return apply_filters( 'dietpress_cache_exclude_urls', $patterns );
	}

	/**
	 * Split a textarea value into trimmed, non-empty lines.
	 *
	 * @param string $value Raw textarea value.
	 * @return array
	 */
	private static function parse_lines( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
	}

	/**
	 * Sanitize the whole option before it is stored.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::get_defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$clean = $defaults;

		foreach ( array( 'enabled', 'separate_mobile', 'precompress_gzip', 'host_cache_ack' ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		// 0 means "expire on events only". The ceiling is a month: past that the
		// pages carry nonces older than their own lifetime.
		if ( isset( $input['ttl_hours'] ) ) {
			$ttl                = (int) $input['ttl_hours'];
			$clean['ttl_hours'] = ( $ttl >= 0 && $ttl <= 720 ) ? $ttl : $defaults['ttl_hours'];
		}

		if ( isset( $input['exclude_urls'] ) ) {
			$clean['exclude_urls'] = self::sanitize_patterns( $input['exclude_urls'] );
		}

		if ( isset( $input['ignore_query_params'] ) ) {
			$clean['ignore_query_params'] = self::sanitize_params( $input['ignore_query_params'] );
		}

		// Refusing to serve is the whole point of the conflict check, so a saved
		// "enabled" that the environment blocks is turned back off here rather
		// than left on and quietly ignored.
		if ( $clean['enabled'] ) {
			$blocking = Core_Diet_Cache_Compat::get_blocking_reasons();
			if ( $blocking ) {
				$clean['enabled'] = false;
				add_settings_error(
					self::OPTION_NAME,
					'core_diet_cache_blocked',
					reset( $blocking ),
					'error'
				);
			}
		}

		// A hosting cache in front of this one is the third of the three ticket
		// types this module can generate, and the only one the site owner can
		// see coming. Enabling on top of it takes a deliberate second click.
		if ( $clean['enabled'] && ! $clean['host_cache_ack'] && Core_Diet_Cache_Compat::get_warnings() ) {
			$clean['enabled'] = false;
			add_settings_error(
				self::OPTION_NAME,
				'core_diet_cache_needs_ack',
				__( 'Your hosting already serves a page cache. Tick the confirmation checkbox to enable this one on top of it.', 'wpo-tweaks' ),
				'error'
			);
		}

		return $clean;
	}

	/**
	 * Sanitize the URL exclusion textarea.
	 *
	 * Patterns are matched against the request path, so anything that is not a
	 * path character is dropped. A full URL pasted by the user keeps working:
	 * the scheme and host are stripped instead of rejecting the line.
	 *
	 * @param string $value Raw textarea value.
	 * @return string
	 */
	private static function sanitize_patterns( $value ) {
		$out = array();

		foreach ( self::parse_lines( (string) $value ) as $line ) {
			$line = wp_strip_all_tags( $line );

			// Accept a pasted absolute URL by keeping only its path.
			if ( preg_match( '#^https?://#i', $line ) ) {
				$parsed = wp_parse_url( $line, PHP_URL_PATH );
				$line   = is_string( $parsed ) ? $parsed : '';
			}

			// Stored decoded, which is the form the request path is compared
			// in. It also makes both spellings of a non ASCII path converge:
			// the address bar shows "/café/" and the clipboard usually holds
			// "/caf%C3%A9/", and until now the second one was the only one that
			// survived, because the accented bytes were stripped.
			$line = rawurldecode( $line );
			$line = preg_replace( '#[^A-Za-z0-9_\-/.*%~\x80-\xFF]#', '', $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '/' !== $line[0] ) {
				$line = '/' . $line;
			}

			$out[] = $line;
		}

		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Sanitize the extra ignored-parameters textarea.
	 *
	 * @param string $value Raw textarea value.
	 * @return string
	 */
	private static function sanitize_params( $value ) {
		$out = array();

		foreach ( self::parse_lines( (string) $value ) as $line ) {
			$line = preg_replace( '/[^A-Za-z0-9_\-\[\]]/', '', $line );
			if ( '' === $line || self::is_protected_param( $line ) ) {
				continue;
			}
			$out[] = $line;
		}

		return implode( "\n", array_unique( $out ) );
	}
}
