<?php
/**
 * DietPress image performance features: lazy loading, fetchpriority, missing dimensions, and PDF preview control.
 *
 * @package DietPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Core_Diet_Perf_Images {

	/** @var Core_Diet_Settings */
	private $settings;

	/**
	 * First image found flag.
	 *
	 * @var bool
	 */
	private $first_image_found = false;

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
		if ( $this->settings->is_enabled( 'enhance_images' ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'core_diet_optimize_attachment_image' ), 10, 3 );
			add_filter( 'the_content', array( $this, 'core_diet_optimize_content_images' ), 999 );
			add_filter( 'post_thumbnail_html', array( $this, 'core_diet_optimize_content_images' ), 999 );

			// Reset first image flag for each request.
			add_action( 'wp', array( $this, 'core_diet_reset_first_image_flag' ) );
		}

		if ( $this->settings->is_enabled( 'disable_pdf_previews' ) ) {
			add_filter( 'fallback_intermediate_image_sizes', array( $this, 'core_diet_disable_pdf_previews' ) );
		}

		if ( $this->settings->is_enabled( 'add_image_dimensions' ) ) {
			add_filter( 'the_content', array( $this, 'core_diet_add_missing_image_dimensions' ), 999 );
			add_filter( 'post_thumbnail_html', array( $this, 'core_diet_add_missing_image_dimensions' ), 999 );
			add_filter( 'wp_get_attachment_image', array( $this, 'core_diet_add_missing_image_dimensions' ), 999 );
		}
	}

	/**
	 * Reset first image flag.
	 */
	public function core_diet_reset_first_image_flag() {
		$this->first_image_found = false;
	}

	/**
	 * Optimize attachment images (via wp_get_attachment_image)
	 *
	 * Only adds attributes when NOT already present.
	 *
	 * @since 2.2.0 Added fetchpriority support
	 * @since 2.3.0 Added existence checks to avoid conflicts with core
	 */
	public function core_diet_optimize_attachment_image( $attr, $attachment, $size ) {
		// First image: high priority, no lazy loading
		if ( ! $this->first_image_found ) {
			$this->first_image_found = true;

			if ( ! isset( $attr['decoding'] ) ) {
				$attr['decoding'] = 'async';
			}

			if ( ! isset( $attr['fetchpriority'] ) ) {
				$attr['fetchpriority'] = 'high';
			}

			// Ensure no lazy loading on first image
			if ( isset( $attr['loading'] ) && $attr['loading'] === 'lazy' ) {
				unset( $attr['loading'] );
			}

			return $attr;
		}

		// Subsequent images: lazy load + low priority
		if ( ! isset( $attr['loading'] ) ) {
			$attr['loading'] = 'lazy';
		}

		if ( ! isset( $attr['decoding'] ) ) {
			$attr['decoding'] = 'async';
		}

		if ( ! isset( $attr['fetchpriority'] ) ) {
			$attr['fetchpriority'] = 'low';
		}

		return $attr;
	}

	/**
	 * Optimize images in post content and thumbnails
	 *
	 * Runs at priority 999 to catch ALL images including those added by
	 * themes, page builders, widgets, and custom templates that may bypass
	 * WordPress core processing. Only adds attributes when NOT present.
	 *
	 * @since 2.2.0 Added fetchpriority support
	 * @since 2.3.0 Added existence checks to avoid conflicts with core
	 */
	public function core_diet_optimize_content_images( $content ) {
		if ( is_admin() || is_feed() ) {
			return $content;
		}

		$image_count = 0;

		$content = preg_replace_callback(
			'/<img([^>]*)>/i',
			function ( $matches ) use ( &$image_count ) {
				$image_count++;
				$attrs = $matches[1];

				$is_logo = ( strpos( $attrs, 'site-logo' ) !== false ) ||
						  ( strpos( $attrs, 'custom-logo' ) !== false );

				// First image or logo: no lazy loading, high priority
				if ( ( $image_count === 1 && ! $this->first_image_found ) || $is_logo ) {
					$this->first_image_found = true;

					if ( strpos( $attrs, 'decoding=' ) === false ) {
						$attrs .= ' decoding="async"';
					}

					if ( strpos( $attrs, 'fetchpriority=' ) === false ) {
						$attrs .= ' fetchpriority="high"';
					}

					// Remove lazy loading if present on first image
					$attrs = preg_replace( '/\s*loading=["\'][^"\']*["\']/', '', $attrs );

					return '<img' . $attrs . '>';
				}

				// All other images: lazy load + low priority (only if not present)

				if ( strpos( $attrs, 'loading=' ) === false ) {
					$attrs .= ' loading="lazy"';
				}

				if ( strpos( $attrs, 'decoding=' ) === false ) {
					$attrs .= ' decoding="async"';
				}

				// fetchpriority="low" - unique ZCP value, core does NOT do this
				if ( strpos( $attrs, 'fetchpriority=' ) === false ) {
					$attrs .= ' fetchpriority="low"';
				}

				return '<img' . $attrs . '>';
			},
			$content
		);

		return $content;
	}

	/**
	 * Disable PDF thumbnail previews
	 */
	public function core_diet_disable_pdf_previews() {
		return array();
	}

	/**
	 * Add missing width and height attributes to images and picture elements - FOR ALL USERS
	 */
	public function core_diet_add_missing_image_dimensions( $content ) {
		// Only skip admin, feeds, and REST requests - apply to ALL frontend users
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $content;
		}

		// Cache key for processed content
		$cache_key      = 'core_diet_img_dimensions_' . md5( $content );
		$cached_content = wp_cache_get( $cache_key );

		if ( $cached_content !== false ) {
			return $cached_content;
		}

		// Process standalone img tags first
		$pattern_img       = '/<img(?![^>]*(?:width|height))[^>]*src=["\']([^"\']+)["\'][^>]*>/i';
		$processed_content = preg_replace_callback( $pattern_img, array( $this, 'core_diet_process_image_dimensions' ), $content );

		// Process picture elements
		$pattern_picture   = '/<picture[^>]*>(.*?)<\/picture>/is';
		$processed_content = preg_replace_callback( $pattern_picture, array( $this, 'core_diet_process_picture_dimensions' ), $processed_content );

		// Cache the processed content for 1 hour
		wp_cache_set( $cache_key, $processed_content, '', HOUR_IN_SECONDS );

		return $processed_content;
	}

	/**
	 * Process picture elements to add dimensions
	 */
	private function core_diet_process_picture_dimensions( $matches ) {
		$picture_content = $matches[1];
		$full_picture    = $matches[0];

		// Find the main img tag within picture (fallback image)
		$img_pattern = '/<img(?![^>]*(?:width|height))[^>]*src=["\']([^"\']+)["\'][^>]*>/i';

		$processed_picture = preg_replace_callback(
			$img_pattern,
			function ( $img_matches ) {
				$img_tag = $img_matches[0];
				$src     = $img_matches[1];

				// Skip if image already has dimensions
				if ( preg_match( '/(?:width|height)=/i', $img_tag ) ) {
					return $img_tag;
				}

				// Skip external images and SVGs
				if ( ! $this->core_diet_is_local_image( $src ) || $this->core_diet_is_svg_image( $src ) ) {
					return $img_tag;
				}

				// Get image dimensions
				$dimensions = $this->core_diet_get_image_dimensions( $src );

				if ( ! $dimensions ) {
					return $img_tag;
				}

				// Add width and height attributes
				$img_tag = str_replace( '<img', '<img width="' . esc_attr( $dimensions['width'] ) . '" height="' . esc_attr( $dimensions['height'] ) . '"', $img_tag );

				return $img_tag;
			},
			$picture_content
		);

		// Return the complete picture element with processed img
		return str_replace( $picture_content, $processed_picture, $full_picture );
	}

	/**
	 * Process individual image to add dimensions
	 */
	private function core_diet_process_image_dimensions( $matches ) {
		$img_tag = $matches[0];
		$src     = $matches[1];

		// Skip if image already has dimensions
		if ( preg_match( '/(?:width|height)=/i', $img_tag ) ) {
			return $img_tag;
		}

		// Skip external images and SVGs
		if ( ! $this->core_diet_is_local_image( $src ) || $this->core_diet_is_svg_image( $src ) ) {
			return $img_tag;
		}

		// Get image dimensions
		$dimensions = $this->core_diet_get_image_dimensions( $src );

		if ( ! $dimensions ) {
			return $img_tag;
		}

		// Add width and height attributes
		$img_tag = str_replace( '<img', '<img width="' . esc_attr( $dimensions['width'] ) . '" height="' . esc_attr( $dimensions['height'] ) . '"', $img_tag );

		return $img_tag;
	}

	/**
	 * Check if image is local
	 */
	private function core_diet_is_local_image( $src ) {
		$home_url   = home_url();
		$upload_dir = wp_upload_dir();

		return ( strpos( $src, $home_url ) === 0 ) || ( strpos( $src, $upload_dir['baseurl'] ) === 0 ) || ( strpos( $src, '/wp-content/' ) === 0 );
	}

	/**
	 * Check if image is SVG
	 */
	private function core_diet_is_svg_image( $src ) {
		return ( strpos( $src, '.svg' ) !== false );
	}

	/**
	 * Get image dimensions from attachment or file
	 */
	private function core_diet_get_image_dimensions( $src ) {
		// Cache key for dimensions
		$cache_key   = 'core_diet_img_dims_' . md5( $src );
		$cached_dims = wp_cache_get( $cache_key );

		if ( $cached_dims !== false ) {
			return $cached_dims;
		}

		$dimensions = false;

		// Try to get attachment ID from URL
		$attachment_id = attachment_url_to_postid( $src );

		if ( $attachment_id ) {
			// Get dimensions from attachment metadata
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( $metadata && isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
				$dimensions = array(
					'width'  => $metadata['width'],
					'height' => $metadata['height'],
				);
			}
		} else {
			// Fallback: get dimensions from file system
			$dimensions = $this->core_diet_get_image_dimensions_from_file( $src );
		}

		// Cache dimensions for 1 day
		if ( $dimensions ) {
			wp_cache_set( $cache_key, $dimensions, '', DAY_IN_SECONDS );
		}

		return $dimensions;
	}

	/**
	 * Get image dimensions from file system
	 */
	private function core_diet_get_image_dimensions_from_file( $src ) {
		// Convert URL to file path
		$upload_dir = wp_upload_dir();

		if ( strpos( $src, $upload_dir['baseurl'] ) === 0 ) {
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $src );
		} elseif ( strpos( $src, '/wp-content/' ) === 0 ) {
			$file_path = ABSPATH . ltrim( $src, '/' );
		} else {
			return false;
		}

		// Security check
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		// Get image dimensions
		$image_info = getimagesize( $file_path );

		if ( $image_info && $image_info[0] > 0 && $image_info[1] > 0 ) {
			return array(
				'width'  => $image_info[0],
				'height' => $image_info[1],
			);
		}

		return false;
	}
}
