<?php
/**
 * Immutable content bank for the Elementor design agent.
 *
 * The model receives reference keys + short previews, never permission to
 * invent replacement page copy. The compiler resolves those keys back to the
 * exact finished content after the composition has passed validation.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Elementor_Content_Bank {

	public static function build( array $analysis ) {
		$items = array();
		$add = function ( $key, $type, $value, $required = true, $meta = array() ) use ( &$items ) {
			$key = (string) $key;
			if ( '' === $key ) { return; }
			if ( is_string( $value ) && '' === trim( wp_strip_all_tags( $value ) ) && ! in_array( $type, array( 'url', 'image' ), true ) ) { return; }
			if ( 'url' === $type && '' === trim( (string) $value ) ) { return; }
			if ( 'image' === $type && ( ! is_array( $value ) || ( empty( $value['url'] ) && empty( $value['id'] ) ) ) ) { return; }
			if ( 'collection' === $type && empty( $value ) ) { return; }
			$items[ $key ] = array(
				'type' => $type,
				'value' => $value,
				'required' => (bool) $required,
				'meta' => is_array( $meta ) ? $meta : array(),
			);
		};

		$handoff = class_exists( 'SCC_Design_Handoff' ) ? SCC_Design_Handoff::from_analysis( $analysis ) : array();
		$headline = trim( (string) ( $handoff['headline'] ?? $analysis['h1'] ?? $analysis['title'] ?? '' ) );
		$intro    = trim( (string) ( $handoff['intro'] ?? $analysis['intro'] ?? '' ) );
		$media    = is_array( $handoff['media'] ?? null ) ? $handoff['media'] : array();

		$add( 'hero.title', 'text', $headline, true, array( 'role' => 'h1' ) );
		$add( 'hero.intro', 'text', $intro, true, array( 'role' => 'lead' ) );
		$add( 'hero.media', 'image', $media, true, array( 'role' => 'hero-image' ) );

		// Reuse the mapper's lifting rules so FAQs, CTA, stats and process data
		// do not remain duplicated inside long-form body HTML.
		$lift = array( 'content', 'stats', 'process-steps', 'faq', 'related-content', 'cta' );
		$body = class_exists( 'SCC_Content_Mapper' )
			? SCC_Content_Mapper::body_html( $analysis, $lift )
			: (string) ( $analysis['content_html'] ?? '' );
		$body = self::strip_leading_intro( $body, $intro );

		$sections = class_exists( 'SCC_Design_Handoff' ) ? SCC_Design_Handoff::sections( $body ) : array();
		foreach ( $sections as $i => $section ) {
			if ( ! is_array( $section ) ) { continue; }
			$heading = trim( (string) ( $section['heading'] ?? '' ) );
			$html = (string) ( $section['html'] ?? '' );
			$images = array_values( (array) ( $section['images'] ?? array() ) );

			// Images are separate bank items so the agent can actually compose
			// media-left/media-right/overlap layouts without duplicating <img>.
			$html_without_images = preg_replace( '#<p[^>]*>\s*<img\b[^>]*>\s*</p>#is', '', $html );
			$html_without_images = preg_replace( '#<img\b[^>]*>#is', '', (string) $html_without_images );
			$html_without_images = trim( (string) $html_without_images );

			$add( 'section.' . $i . '.heading', 'text', $heading, '' !== $heading, array( 'role' => 'h2', 'section' => $i ) );
			$add( 'section.' . $i . '.body', 'html', $html_without_images, '' !== trim( wp_strip_all_tags( $html_without_images ) ), array( 'role' => 'body', 'section' => $i ) );
			foreach ( $images as $j => $image ) {
				$add( 'section.' . $i . '.image.' . $j, 'image', $image, true, array( 'role' => 'section-image', 'section' => $i ) );
			}
		}

		$stats = array_values( (array) ( $handoff['stats'] ?? $analysis['stats'] ?? array() ) );
		$steps = array_values( (array) ( $handoff['steps'] ?? $analysis['process'] ?? array() ) );
		$faqs  = array_values( (array) ( $handoff['faqs'] ?? $analysis['faqs'] ?? array() ) );
		$related = array_values( (array) ( $handoff['related'] ?? $analysis['related'] ?? array() ) );
		$areas = array_values( (array) ( $handoff['areas'] ?? $analysis['areas'] ?? array() ) );

		// Structured services are substantive content on a service page, not
		// decorative widgets. Carry them into the immutable content bank so the
		// professional builder cannot discard them during visual composition.
		$services = array_values( (array) ( $analysis['services'] ?? array() ) );
		$add( 'services.items', 'collection', $services, true, array( 'collection' => 'services' ) );
		$add( 'stats.items', 'collection', $stats, true, array( 'collection' => 'stats' ) );
		$add( 'steps.items', 'collection', $steps, true, array( 'collection' => 'steps' ) );
		$add( 'faq.items', 'collection', $faqs, true, array( 'collection' => 'faq' ) );
		$add( 'related.items', 'collection', $related, true, array( 'collection' => 'related' ) );
		$add( 'areas.items', 'collection', $areas, true, array( 'collection' => 'areas' ) );

		$cta = is_array( $handoff['cta'] ?? null ) ? $handoff['cta'] : array(
			'copy' => (string) ( $analysis['cta'] ?? '' ),
			'label' => (string) ( $analysis['cta_text'] ?? '' ),
			'url' => (string) ( $analysis['cta_url'] ?? '' ),
		);
		$cta_copy = trim( wp_strip_all_tags( (string) ( $cta['copy'] ?? '' ) ) );
		$cta_label = trim( wp_strip_all_tags( (string) ( $cta['label'] ?? '' ) ) );
		$cta_url = esc_url_raw( (string) ( $cta['url'] ?? '' ) );

		$add( 'cta.copy', 'text', $cta_copy, '' !== $cta_copy, array( 'role' => 'cta-copy' ) );
		$add( 'cta.label', 'text', $cta_label, '' !== $cta_label, array( 'role' => 'cta-label' ) );
		$add( 'cta.url', 'url', $cta_url, '' !== $cta_url, array( 'role' => 'cta-url' ) );

		return $items;
	}

	public static function catalog( array $bank ) {
		$out = array();
		foreach ( $bank as $key => $entry ) {
			if ( ! is_array( $entry ) ) { continue; }
			$type = (string) ( $entry['type'] ?? 'text' );
			$value = $entry['value'] ?? '';
			$preview = '';
			$count = null;
			if ( 'collection' === $type ) {
				$count = count( (array) $value );
				$preview = self::collection_preview( (array) $value );
			} elseif ( 'image' === $type ) {
				$preview = is_array( $value ) ? (string) ( $value['url'] ?? ( $value['id'] ?? '' ) ) : '';
			} else {
				$preview = wp_trim_words( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) ), 28, '…' );
			}
			$row = array(
				'ref' => (string) $key,
				'type' => $type,
				'required' => ! empty( $entry['required'] ),
				'preview' => $preview,
			);
			if ( null !== $count ) { $row['count'] = $count; }
			if ( ! empty( $entry['meta'] ) ) { $row['meta'] = $entry['meta']; }
			$out[] = $row;
		}
		return $out;
	}

	public static function required_keys( array $bank ) {
		$out = array();
		foreach ( $bank as $key => $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['required'] ) ) { $out[] = (string) $key; }
		}
		return $out;
	}

	public static function get( array $bank, $key ) {
		$key = (string) $key;
		return isset( $bank[ $key ] ) && is_array( $bank[ $key ] ) ? $bank[ $key ] : null;
	}

	protected static function strip_leading_intro( $html, $intro ) {
		$html = trim( (string) $html );
		$intro_text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $intro ) ) );
		if ( '' === $html || '' === $intro_text ) { return $html; }
		if ( preg_match( '#^<p\b[^>]*>(.*?)</p>#is', $html, $m ) ) {
			$first = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[1] ) ) );
			if ( '' !== $first && ( $first === $intro_text || 0 === strpos( $intro_text, $first ) || 0 === strpos( $first, $intro_text ) ) ) {
				return trim( substr( $html, strlen( $m[0] ) ) );
			}
		}
		return $html;
	}

	protected static function collection_preview( array $items ) {
		$parts = array();
		foreach ( array_slice( $items, 0, 4 ) as $item ) {
			if ( is_array( $item ) ) {
				foreach ( array( 'question', 'title', 'label', 'value', 'name' ) as $k ) {
					if ( ! empty( $item[ $k ] ) ) {
						$parts[] = wp_trim_words( wp_strip_all_tags( (string) $item[ $k ] ), 8, '…' );
						break;
					}
				}
			} elseif ( is_scalar( $item ) ) {
				$parts[] = wp_trim_words( wp_strip_all_tags( (string) $item ), 8, '…' );
			}
		}
		return implode( ' | ', $parts );
	}
}
