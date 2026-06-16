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
		// Leave images untouched inside page builder editors and previews,
		// where the builder manipulates the DOM and our attributes interfere.
		if ( $this->core_diet_is_builder_or_preview() ) {
			return $attr;
		}

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
		if ( is_admin() || is_feed() || $this->core_diet_is_builder_or_preview() ) {
			return $content;
		}

		$image_count = 0;

		$content = preg_replace_callback(
			'/<img([^>]*)>/i',
			function ( $matches ) use ( &$image_count ) {
				$image_count++;
				$attrs = $matches[1];

				$is_logo = false;
				foreach ( $this->core_diet_get_logo_class_patterns() as $logo_pattern ) {
					if ( '' !== $logo_pattern && strpos( $attrs, $logo_pattern ) !== false ) {
						$is_logo = true;
						break;
					}
				}

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

				// Leave images already handled by another lazy-load solution
				// (sliders, captchas, WC placeholder, skip-lazy markers) alone.
				if ( $this->core_diet_img_skips_lazy( $attrs ) ) {
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

				// Skip external images.
				if ( ! $this->core_diet_is_local_image( $src ) ) {
					return $img_tag;
				}

				// Read dimensions: SVG from its markup, raster from metadata/file.
				if ( $this->core_diet_is_svg_image( $src ) ) {
					$dimensions = $this->core_diet_get_svg_dimensions( $src );
				} else {
					$dimensions = $this->core_diet_get_image_dimensions( $src );
				}

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

		// Skip external images.
		if ( ! $this->core_diet_is_local_image( $src ) ) {
			return $img_tag;
		}

		// Read dimensions: SVG from its markup, raster from metadata/file.
		if ( $this->core_diet_is_svg_image( $src ) ) {
			$dimensions = $this->core_diet_get_svg_dimensions( $src );
		} else {
			$dimensions = $this->core_diet_get_image_dimensions( $src );
		}

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

	/**
	 * Whether the current request is a page builder editor or a preview.
	 *
	 * Builders rewrite the DOM as the user edits, so injecting lazy/priority
	 * attributes there causes flicker or broken previews.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	private function core_diet_is_builder_or_preview() {
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return true;
		}

		// Read-only detection of the active page builder; no form is processed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['fl_builder'] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ct_builder'] ) ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['et_fb'] ) && ! empty( $_GET['et_fb'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Substrings used to detect the site logo image in content.
	 *
	 * The logo is treated like the first image (high priority, never lazy).
	 *
	 * @since 3.1.0
	 * @return array
	 */
	private function core_diet_get_logo_class_patterns() {
		/**
		 * Filter the substrings used to detect the site logo image.
		 *
		 * @since 3.1.0
		 * @param array $patterns Substrings matched against the <img> attributes.
		 */
		return (array) apply_filters( 'dietpress_logo_class_patterns', array( 'site-logo', 'custom-logo' ) );
	}

	/**
	 * Whether an <img> is already managed by another lazy-load solution.
	 *
	 * Mirrors the markers used by popular sliders, captchas and lazy-load
	 * plugins so we never double-process those images. Covers the de facto
	 * `skip-lazy` / `data-skip-lazy` opt-out attributes too.
	 *
	 * @since 3.1.0
	 * @param string $attrs The inner attributes of the <img> tag.
	 * @return bool
	 */
	private function core_diet_img_skips_lazy( $attrs ) {
		$markers = array(
			'data-src=',
			'data-no-lazy',
			'data-lazy-original',
			'data-lazy-src',
			'data-lazysrc',
			'data-lazyload',
			'data-bgposition',
			'data-envira-src',
			'data-srcset',
			'fullurl=',
			'lazy-slider-img',
			'skip-lazy',
			'class="ls-l',
			'class="ls-bg',
			'soliloquy-image',
			'loading="eager"',
			"loading='eager'",
			'swatch-img',
			'data-height-percentage',
			'data-large_image',
			'avia-bg-style-fixed',
			'image-compare__',
			'/wpcf7_captcha/',
			'timthumb.php?src',
			'woocommerce/assets/images/placeholder.png',
		);

		foreach ( $markers as $marker ) {
			if ( strpos( $attrs, $marker ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read intrinsic dimensions from a local SVG file.
	 *
	 * Parses the opening <svg> tag for width/height, falling back to viewBox
	 * and computing the missing side from the aspect ratio. getimagesize()
	 * does not support SVG, so these would otherwise be skipped.
	 *
	 * @since 3.1.0
	 * @param string $src Image URL.
	 * @return array|false { width, height } or false when undeterminable.
	 */
	private function core_diet_get_svg_dimensions( $src ) {
		$cache_key   = 'core_diet_svg_dims_' . md5( $src );
		$cached_dims = wp_cache_get( $cache_key );

		if ( $cached_dims !== false ) {
			return $cached_dims;
		}

		$dimensions = false;
		$file_path  = $this->core_diet_resolve_local_path( $src );

		if ( $file_path ) {
			global $wp_filesystem;

			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem && $wp_filesystem->exists( $file_path ) && $wp_filesystem->is_readable( $file_path ) ) {
				$contents = $wp_filesystem->get_contents( $file_path );

				if ( $contents && preg_match( '/<svg\b[^>]*>/is', $contents, $svg_tag ) ) {
					$tag    = $svg_tag[0];
					$width  = 0.0;
					$height = 0.0;

					if ( preg_match( '/\bwidth=["\']\s*([\d.]+)(?:px)?\s*["\']/i', $tag, $w ) ) {
						$width = (float) $w[1];
					}
					if ( preg_match( '/\bheight=["\']\s*([\d.]+)(?:px)?\s*["\']/i', $tag, $h ) ) {
						$height = (float) $h[1];
					}

					if ( ( $width <= 0 || $height <= 0 ) && preg_match( '/\bviewBox=["\']\s*[\d.-]+\s+[\d.-]+\s+([\d.]+)\s+([\d.]+)\s*["\']/i', $tag, $vb ) ) {
						$vb_w = (float) $vb[1];
						$vb_h = (float) $vb[2];

						if ( $vb_w > 0 && $vb_h > 0 ) {
							if ( $width > 0 && $height <= 0 ) {
								$height = $width * ( $vb_h / $vb_w );
							} elseif ( $height > 0 && $width <= 0 ) {
								$width = $height * ( $vb_w / $vb_h );
							} else {
								$width  = $vb_w;
								$height = $vb_h;
							}
						}
					}

					if ( $width > 0 && $height > 0 ) {
						$dimensions = array(
							'width'  => (int) round( $width ),
							'height' => (int) round( $height ),
						);
					}
				}
			}
		}

		wp_cache_set( $cache_key, $dimensions, '', DAY_IN_SECONDS );

		return $dimensions;
	}

	/**
	 * Resolve a local image URL to an absolute, readable file path.
	 *
	 * @since 3.1.0
	 * @param string $src Image URL.
	 * @return string|false Absolute path or false when not a local file.
	 */
	private function core_diet_resolve_local_path( $src ) {
		$upload_dir = wp_upload_dir();

		if ( strpos( $src, $upload_dir['baseurl'] ) === 0 ) {
			$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $src );
		} elseif ( strpos( $src, '/wp-content/' ) === 0 ) {
			$file_path = ABSPATH . ltrim( $src, '/' );
		} else {
			return false;
		}

		// Confine the resolved path to the WordPress install or uploads dir, so
		// a crafted src (e.g. "/wp-content/../../secret") cannot escape upward.
		$real = realpath( $file_path );
		if ( false === $real ) {
			return false;
		}

		$bases = array_filter( array( realpath( ABSPATH ), realpath( $upload_dir['basedir'] ) ) );
		foreach ( $bases as $base ) {
			if ( strpos( $real, $base ) === 0 ) {
				return $real;
			}
		}

		return false;
	}
}
