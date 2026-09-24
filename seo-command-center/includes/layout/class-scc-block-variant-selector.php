<?php
/**
 * Deterministic visual variant selector.
 *
 * This layer is design-only. It never uses keyword, search intent, location or
 * SEO strategy. It looks only at the finished content structure and available
 * media so the same content receives the same design treatment regardless of
 * how the article was planned upstream.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Block_Variant_Selector {

	public static function select( $block_id, array $analysis = array(), array $context = array() ) {
		$id = sanitize_key( (string) $block_id );

		$design = isset( $context['design'] ) && is_array( $context['design'] ) ? $context['design'] : array();
		if ( isset( $design['variants'][ $id ] ) && '' !== (string) $design['variants'][ $id ] ) {
			return sanitize_key( (string) $design['variants'][ $id ] );
		}

		$has_image     = ! empty( $analysis['has_image'] ) || ! empty( $analysis['image']['url'] ) || ! empty( $analysis['image']['id'] );
		$headline      = (string) ( $analysis['h1'] ?? $analysis['title'] ?? '' );
		$headline_len  = strlen( wp_strip_all_tags( $headline ) );
		$section_count = count( (array) ( $analysis['sections'] ?? array() ) );

		switch ( $id ) {
			case 'hero':
				if ( $has_image ) {
					return 'split-image';
				}
				return ( $headline_len > 58 || $section_count >= 5 ) ? 'editorial' : 'centered';

			case 'service-grid':
			case 'service-cards':
			case 'feature-grid':
			case 'blog-grid':
			case 'location-grid':
			case 'related-content':
				$count = count( (array) ( $analysis['services'] ?? $analysis['related'] ?? array() ) );
				return $count >= 4 ? 'bento' : 'cards';

			case 'benefits':
				return class_exists( 'SCC_Elementor_Widget_Catalog' ) && SCC_Elementor_Widget_Catalog::supports( 'icon-list' )
					? 'icon-list'
					: 'stacked';

			case 'stats':
				return class_exists( 'SCC_Elementor_Widget_Catalog' ) && SCC_Elementor_Widget_Catalog::supports( 'counter' )
					? 'counter-band'
					: 'band';

			case 'process-steps':
				return 'numbered-cards';

			case 'faq':
				return class_exists( 'SCC_Elementor_Widget_Catalog' ) && SCC_Elementor_Widget_Catalog::supports( 'accordion' )
					? 'accordion'
					: 'stacked';

			case 'cta':
				return 'split';

			case 'content':
				return 'composed-sections';

			default:
				return 'default';
		}
	}
}
