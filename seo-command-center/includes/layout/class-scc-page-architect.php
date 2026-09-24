<?php
/**
 * Page Architect.
 *
 * Chooses semantic Elementor component sequences by page purpose, then exposes
 * a large controlled component library as visual aliases of the battle-tested
 * native TideOrbit blocks. Aliases never invent copy; they reuse the same
 * mapper/renderer data contracts with different design identities.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Page_Architect {

	public static function register_blocks( $blocks ) {
		$blocks = is_array( $blocks ) ? $blocks : array();
		$aliases = self::aliases();
		foreach ( $aliases as $id => $cfg ) {
			$base = $cfg['base'];
			if ( ! isset( $blocks[ $base ] ) ) { continue; }
			$meta = $blocks[ $base ];
			$meta['name'] = $cfg['name'];
			$meta['description'] = $cfg['description'];
			$meta['required'] = false;
			$meta['min'] = 0;
			$meta['max'] = 1;
			$meta['repeatable'] = false;
			$blocks[ $id ] = $meta;
		}
		return $blocks;
	}

	public static function base_block( $id ) {
		$a = self::aliases();
		return isset( $a[ $id ] ) ? $a[ $id ]['base'] : $id;
	}

	public static function variant_for( $id ) {
		$a = self::aliases();
		return isset( $a[ $id ] ) ? $a[ $id ]['variant'] : '';
	}

	public static function layout_for_post( $post_id, array $analysis = array() ) {
		$plan = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();
		return self::layout_for_plan( $plan, $analysis );
	}

	public static function layout_for_plan( array $plan, array $analysis = array() ) {
		$type = sanitize_key( (string) ( $plan['page_type'] ?? ( $analysis['content_type'] ?? 'article' ) ) );
		$layout = array();

		switch ( $type ) {
			case 'local_service':
				$layout = array( 'hero-local', 'proof-bar', 'intro-editorial', 'service-bento', 'benefits-numbered', 'editorial-body', 'process-timeline', 'testimonial-spotlight', 'local-coverage', 'faq-accordion', 'resource-grid', 'cta-split' );
				break;
			case 'service':
				$layout = array( 'hero-service', 'proof-bar', 'intro-editorial', 'value-props', 'service-bento', 'editorial-body', 'process-cards', 'testimonial-spotlight', 'faq-accordion', 'resource-grid', 'cta-split' );
				break;
			case 'landing':
				$layout = array( 'hero-split', 'proof-bar', 'value-props', 'feature-cards', 'comparison-table', 'testimonial-grid', 'editorial-body', 'faq-accordion', 'cta-banner' );
				break;
			case 'location':
				$layout = array( 'hero-local', 'intro-editorial', 'local-coverage', 'service-bento', 'editorial-body', 'proof-story', 'faq-accordion', 'resource-grid', 'cta-split' );
				break;
			default:
				$layout = array( 'hero-editorial', 'intro-editorial', 'toc-card', 'editorial-body', 'expert-callout', 'faq-accordion', 'resource-grid' );
				if ( ! empty( $plan['conversion_goal'] ) ) { $layout[] = 'cta-banner'; }
				break;
		}

		// Only choose proof components when real proof-shaped content exists.
		if ( empty( $analysis['stats'] ) ) {
			$layout = array_values( array_diff( $layout, array( 'proof-bar', 'kpi-band', 'trust-stats' ) ) );
		}
		if ( empty( $analysis['faqs'] ) ) {
			$layout = array_values( array_diff( $layout, array( 'faq-accordion', 'faq-stack' ) ) );
		}
		if ( empty( $analysis['process'] ) ) {
			$layout = array_values( array_diff( $layout, array( 'process-timeline', 'process-cards', 'process-roadmap' ) ) );
		}
		if ( empty( $analysis['related'] ) && empty( $analysis['areas'] ) ) {
			$layout = array_values( array_diff( $layout, array( 'resource-grid', 'related-cards', 'local-coverage', 'location-pills', 'location-grid-pro' ) ) );
		}
		return SCC_Layout_Validator::validate( $layout );
	}

	public static function aliases() {
		return array(
			'hero-split' => self::a( 'hero', 'Split Hero', 'Image-forward split hero.', 'split-image' ),
			'hero-centered' => self::a( 'hero', 'Centered Hero', 'Centered conversion hero.', 'centered' ),
			'hero-editorial' => self::a( 'hero', 'Editorial Hero', 'Editorial long-headline hero.', 'editorial' ),
			'hero-local' => self::a( 'hero', 'Local Hero', 'Local service hero.', 'split-image' ),
			'hero-service' => self::a( 'hero', 'Service Hero', 'Service-led conversion hero.', 'split-image' ),
			'intro-editorial' => self::a( 'content-intro', 'Editorial Intro', 'Readable opening context.', 'editorial' ),
			'intro-split' => self::a( 'content-intro', 'Split Intro', 'Compact opening section.', 'split' ),
			'benefits-grid' => self::a( 'benefits', 'Benefits Grid', 'Benefit/value point treatment.', 'grid' ),
			'benefits-numbered' => self::a( 'benefits', 'Numbered Benefits', 'Numbered benefit sequence.', 'numbered' ),
			'value-props' => self::a( 'benefits', 'Value Propositions', 'High-value proposition list.', 'stacked' ),
			'feature-cards' => self::a( 'feature-grid', 'Feature Cards', 'Feature card grid.', 'cards' ),
			'icon-grid' => self::a( 'feature-grid', 'Icon Grid', 'Icon-oriented feature grid.', 'cards' ),
			'capability-grid' => self::a( 'feature-grid', 'Capability Grid', 'Capabilities in cards.', 'bento' ),
			'service-bento' => self::a( 'service-grid', 'Service Bento', 'Bento-style service grid.', 'bento' ),
			'service-list' => self::a( 'service-grid', 'Service List', 'Compact service list.', 'cards' ),
			'service-carousel' => self::a( 'service-grid', 'Service Carousel', 'Scrollable service cards.', 'carousel' ),
			'proof-bar' => self::a( 'stats', 'Proof Bar', 'Verified headline proof points.', 'band' ),
			'kpi-band' => self::a( 'stats', 'KPI Band', 'KPI/counter band.', 'counter-band' ),
			'trust-stats' => self::a( 'stats', 'Trust Stats', 'Trust-building stats.', 'counter-band' ),
			'process-timeline' => self::a( 'process-steps', 'Process Timeline', 'Sequential process timeline.', 'timeline' ),
			'process-cards' => self::a( 'process-steps', 'Process Cards', 'Numbered process cards.', 'numbered-cards' ),
			'process-roadmap' => self::a( 'process-steps', 'Process Roadmap', 'Roadmap-style process.', 'timeline' ),
			'testimonial-spotlight' => self::a( 'testimonial', 'Testimonial Spotlight', 'Single verified testimonial.', 'spotlight' ),
			'testimonial-grid' => self::a( 'testimonial', 'Testimonial Grid', 'Verified proof treatment.', 'cards' ),
			'proof-story' => self::a( 'testimonial', 'Proof Story', 'Narrative customer proof.', 'editorial' ),
			'comparison-table' => self::a( 'comparison', 'Comparison Table', 'Structured comparison.', 'table' ),
			'option-comparison' => self::a( 'comparison', 'Option Comparison', 'Compare alternatives.', 'table' ),
			'key-takeaway' => self::a( 'highlight-box', 'Key Takeaway', 'Prominent takeaway.', 'callout' ),
			'expert-callout' => self::a( 'highlight-box', 'Expert Callout', 'Expertise/context callout.', 'callout' ),
			'resource-grid' => self::a( 'related-content', 'Resource Grid', 'Related resource links.', 'bento' ),
			'related-cards' => self::a( 'related-content', 'Related Cards', 'Related content cards.', 'cards' ),
			'local-coverage' => self::a( 'service-area', 'Local Coverage', 'Served-area links.', 'cards' ),
			'location-pills' => self::a( 'service-area', 'Location Pills', 'Compact served-area list.', 'pills' ),
			'location-grid-pro' => self::a( 'location-grid', 'Location Grid Pro', 'Location navigation grid.', 'bento' ),
			'cta-banner' => self::a( 'cta', 'CTA Banner', 'Full-width closing CTA.', 'centered' ),
			'cta-split' => self::a( 'cta', 'Split CTA', 'Split conversion CTA.', 'split' ),
			'cta-form' => self::a( 'cta', 'Form CTA', 'Form-oriented CTA handoff.', 'split' ),
			'faq-accordion' => self::a( 'faq', 'FAQ Accordion', 'Accessible FAQ accordion.', 'accordion' ),
			'faq-stack' => self::a( 'faq', 'FAQ Stack', 'Stacked Q&A.', 'stacked' ),
			'editorial-body' => self::a( 'content', 'Editorial Body', 'Composed editorial sections.', 'composed-sections' ),
			'section-stack' => self::a( 'content', 'Section Stack', 'Structured section stack.', 'composed-sections' ),
			'toc-card' => self::a( 'toc', 'TOC Card', 'Table of contents card.', 'card' ),
			'media-split' => self::a( 'image-content', 'Media Split', 'Image and content split.', 'media-right' ),
		);
	}

	protected static function a( $base, $name, $description, $variant ) {
		return array( 'base' => $base, 'name' => $name, 'description' => $description, 'variant' => $variant );
	}
}
