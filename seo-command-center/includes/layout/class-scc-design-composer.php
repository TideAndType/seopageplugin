<?php
/**
 * Professional Elementor Design Composer.
 *
 * Receives the design-only handoff and creates a deterministic visual plan.
 * It does not read keywords, SEO intent or page strategy. The article/content
 * engine decides what is said; this class decides how that finished content is
 * presented.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Design_Composer {

	public static function compose( array $handoff, array $profile = array() ) {
		$signals = (array) ( $handoff['signals'] ?? array() );
		$layout  = array( 'hero' );

		if ( ! empty( $signals['has_structured_stats'] ) && ! empty( $handoff['stats'] ) ) {
			$layout[] = 'stats';
		}
		if ( ! empty( $signals['has_structured_steps'] ) && ! empty( $handoff['steps'] ) ) {
			$layout[] = 'process-steps';
		}

		if ( '' !== trim( wp_strip_all_tags( (string) ( $handoff['body_html'] ?? '' ) ) ) ) {
			$layout[] = 'content';
		}
		if ( ! empty( $handoff['faqs'] ) ) {
			$layout[] = 'faq';
		}
		if ( ! empty( $handoff['related'] ) ) {
			$layout[] = 'related-content';
		}
		if ( ! empty( $signals['has_explicit_cta'] ) ) {
			$layout[] = 'cta';
		}

		$layout = array_values( array_unique( $layout ) );

		$section_count = (int) ( $signals['section_count'] ?? 0 );
		$headline_len  = (int) ( $signals['headline_length'] ?? 0 );
		$has_media     = ! empty( $signals['has_media'] );
		$content_width = (int) ( $profile['layout']['content_width'] ?? 1140 );

		$variants = array(
			'hero'            => $has_media ? 'split-image' : ( ( $headline_len > 58 || $section_count >= 5 ) ? 'editorial' : 'centered' ),
			'content'         => 'composed-sections',
			'stats'           => self::widget_available( 'counter' ) ? 'counter-band' : 'band',
			'process-steps'   => 'numbered-cards',
			'faq'             => self::widget_available( 'accordion' ) ? 'accordion' : 'stacked',
			'related-content' => count( (array) ( $handoff['related'] ?? array() ) ) >= 4 ? 'bento' : 'cards',
			'cta'             => $content_width >= 960 ? 'split' : 'centered',
		);

		return array(
			'layout'   => $layout,
			'variants' => $variants,
			'sections' => self::decorate_sections( (array) ( $handoff['sections'] ?? array() ) ),
			'source'   => 'design_composer',
		);
	}

	/**
	 * Adds visual treatment only. No copy is changed.
	 */
	public static function decorate_sections( array $sections ) {
		$out = array();
		$visual_index = 0;
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) ) { continue; }
			$layout = 'editorial';

			if ( ! empty( $section['has_image'] ) ) {
				$layout = ( $visual_index % 2 ) ? 'media-left' : 'media-right';
			} elseif ( ! empty( $section['has_list'] ) && (int) ( $section['list_items'] ?? 0 ) >= 3 ) {
				$layout = 'split-list';
			} elseif ( ! empty( $section['has_quote'] ) ) {
				$layout = 'callout';
			} elseif ( ! empty( $section['has_table'] ) ) {
				$layout = 'wide';
			} elseif ( (int) ( $section['word_count'] ?? 0 ) >= 220 ) {
				$layout = 'readable';
			}

			$section['layout']  = $layout;
			$section['surface'] = ( $visual_index % 2 ) ? 'tint' : 'plain';
			$out[] = $section;
			$visual_index++;
		}
		return $out;
	}

	protected static function widget_available( $widget ) {
		return class_exists( 'SCC_Elementor_Widget_Catalog' )
			&& SCC_Elementor_Widget_Catalog::supports( $widget );
	}
}
