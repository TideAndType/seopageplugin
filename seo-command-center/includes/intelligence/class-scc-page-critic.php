<?php
/**
 * SEO + design critic for smart pages.
 *
 * Reviews the proposed component architecture before Elementor writes anything.
 * It can safely repair structural issues (duplicates, missing body/CTA/FAQ) but
 * never rewrites business claims or fabricates content.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Page_Critic {

	public static function critique_layout( array $layout, array $analysis, array $plan = array() ) {
		$base = array_map( array( 'SCC_Page_Architect', 'base_block' ), $layout );
		$issues = array();

		$variety = count( array_unique( $base ) ) / max( 1, count( $base ) );
		$section_variety = (int) round( 100 * $variety );
		if ( $section_variety < 70 ) {
			$issues[] = self::issue( 'medium', 'Section variety', 'Too many sections repeat the same presentation pattern.' );
		}

		$has_body = in_array( 'content', $base, true );
		if ( ! $has_body ) {
			$issues[] = self::issue( 'high', 'Content body', 'The layout needs a main content section.' );
		}

		$intent = (string) ( $plan['primary_intent'] ?? '' );
		$needs_cta = in_array( $intent, array( 'commercial', 'transactional', 'local' ), true );
		$has_cta = in_array( 'cta', $base, true );
		if ( $needs_cta && ! $has_cta ) {
			$issues[] = self::issue( 'medium', 'CTA visibility', 'Commercial/local pages should expose a clear next step when explicit CTA copy exists.' );
		}

		$has_faq = in_array( 'faq', $base, true );
		if ( ! empty( $analysis['faqs'] ) && ! $has_faq ) {
			$issues[] = self::issue( 'low', 'FAQ coverage', 'Real FAQ content exists but is not surfaced as a dedicated section.' );
		}

		$readability = 100;
		$body_words = str_word_count( wp_strip_all_tags( (string) ( $analysis['content_html'] ?? '' ) ) );
		$sections = max( 1, count( (array) ( $analysis['sections'] ?? array() ) ) );
		if ( $body_words / $sections > 420 ) { $readability = 68; }
		if ( $body_words / $sections > 650 ) { $readability = 45; }

		$cta_score = ! $needs_cta || $has_cta ? 100 : 55;
		$seo_structure = $has_body ? 100 : 40;
		$accessibility = 100; // Renderer uses semantic headings, links, details/summary and escaped output.

		return array(
			'scores' => array(
				'visual_hierarchy' => in_array( 'hero', $base, true ) ? 100 : 60,
				'section_variety'  => $section_variety,
				'readability'      => $readability,
				'cta_visibility'   => $cta_score,
				'accessibility'    => $accessibility,
				'seo_structure'    => $seo_structure,
			),
			'issues' => $issues,
		);
	}

	public static function autofix_layout( array $layout, array $analysis, array $plan = array() ) {
		$out = array();
		$last_base = '';
		foreach ( $layout as $id ) {
			$base = SCC_Page_Architect::base_block( $id );
			if ( $base === $last_base && ! in_array( $base, array( 'split-content', 'image-content' ), true ) ) { continue; }
			$out[] = $id;
			$last_base = $base;
		}
		$bases = array_map( array( 'SCC_Page_Architect', 'base_block' ), $out );
		if ( ! in_array( 'content', $bases, true ) ) { $out[] = 'editorial-body'; }
		if ( ! empty( $analysis['faqs'] ) && ! in_array( 'faq', $bases, true ) ) { $out[] = 'faq-accordion'; }

		$intent = (string) ( $plan['primary_intent'] ?? '' );
		$needs_cta = in_array( $intent, array( 'commercial', 'transactional', 'local' ), true );
		if ( $needs_cta && ! in_array( 'cta', $bases, true ) && ! empty( $analysis['cta'] ) ) { $out[] = 'cta-split'; }

		return SCC_Layout_Validator::validate( $out );
	}

	public static function critique_post( $post_id ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( (int) $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$plan = SCC_Page_Brain::for_post( $post_id );
		$layout = SCC_Page_Architect::layout_for_plan( $plan, $analysis );
		$critique = self::critique_layout( $layout, $analysis, $plan );
		$critique['layout'] = $layout;
		$critique['tidescore'] = class_exists( 'SCC_TideScore' ) ? SCC_TideScore::score_post( $post_id ) : array();
		return $critique;
	}

	protected static function issue( $severity, $label, $message ) {
		return array( 'severity' => $severity, 'label' => $label, 'message' => $message );
	}
}
