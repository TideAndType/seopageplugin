<?php
/**
 * Design Handoff.
 *
 * Converts the completed article/page content into a design-only contract.
 * SEO strategy, keywords and search intent stop before this boundary. The
 * Elementor design layer receives only content, media and structural signals.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Design_Handoff {

	public static function from_analysis( array $analysis ) {
		$html     = (string) ( $analysis['content_html'] ?? '' );
		$sections = self::sections( $html );
		$headline = trim( (string) ( $analysis['h1'] ?? $analysis['title'] ?? '' ) );
		$intro    = trim( (string) ( $analysis['intro'] ?? '' ) );
		$media    = is_array( $analysis['image'] ?? null ) ? $analysis['image'] : array();

		return array(
			'headline' => $headline,
			'intro'    => $intro,
			'body_html'=> $html,
			'sections' => $sections,
			'media'    => $media,
			'stats'    => array_values( (array) ( $analysis['stats'] ?? array() ) ),
			'steps'    => array_values( (array) ( $analysis['process'] ?? array() ) ),
			'faqs'     => array_values( (array) ( $analysis['faqs'] ?? array() ) ),
			'related'  => array_values( (array) ( $analysis['related'] ?? array() ) ),
			'areas'    => array_values( (array) ( $analysis['areas'] ?? array() ) ),
			'cta'      => array(
				'copy'  => trim( (string) ( $analysis['cta'] ?? '' ) ),
				'label' => trim( (string) ( $analysis['cta_text'] ?? '' ) ),
				'url'   => trim( (string) ( $analysis['cta_url'] ?? '' ) ),
			),
			'signals'  => array(
				'headline_length' => strlen( wp_strip_all_tags( $headline ) ),
				'intro_words'     => self::word_count( $intro ),
				'body_words'      => self::word_count( $html ),
				'section_count'   => count( $sections ),
				'faq_count'       => count( (array) ( $analysis['faqs'] ?? array() ) ),
				'stats_count'     => count( (array) ( $analysis['stats'] ?? array() ) ),
				'steps_count'     => count( (array) ( $analysis['process'] ?? array() ) ),
				'related_count'   => count( (array) ( $analysis['related'] ?? array() ) ),
				'has_media'       => ! empty( $media['url'] ) || ! empty( $media['id'] ),
				'has_explicit_cta'=> '' !== trim( (string) ( $analysis['cta'] ?? '' ) )
					|| '' !== trim( (string) ( $analysis['cta_text'] ?? '' ) )
					|| '' !== trim( (string) ( $analysis['cta_url'] ?? '' ) )
					|| false !== stripos( $html, 'scc-cta' ),
				'has_structured_stats' => false !== stripos( $html, 'scc-stats' ),
				'has_structured_steps' => false !== stripos( $html, 'scc-steps' ),
			),
		);
	}

	/**
	 * Split finished body HTML into designable H2-led sections. Each section
	 * keeps its real copy; these signals only control presentation.
	 */
	public static function sections( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) { return array(); }

		$parts = preg_split( '/(?=<h2\b)/i', $html );
		$out   = array();
		$index = 0;

		foreach ( (array) $parts as $segment ) {
			$segment = trim( (string) $segment );
			if ( '' === $segment || '' === trim( wp_strip_all_tags( $segment ) ) ) { continue; }

			$heading = '';
			$body    = $segment;
			if ( preg_match( '#^<h2\b[^>]*>(.*?)</h2>#is', $segment, $m ) ) {
				$heading = trim( wp_strip_all_tags( $m[1] ) );
				$body    = trim( (string) preg_replace( '#^<h2\b[^>]*>.*?</h2>#is', '', $segment, 1 ) );
			}

			$images = array();
			if ( preg_match_all( '#<img\b[^>]*src=["\']([^"\']+)["\'][^>]*>#is', $body, $img_matches ) ) {
				foreach ( $img_matches[1] as $src ) {
					$src = esc_url_raw( (string) $src );
					if ( '' !== $src ) { $images[] = array( 'url' => $src, 'id' => 0 ); }
				}
			}

			preg_match_all( '#<li\b[^>]*>.*?</li>#is', $body, $li_matches );
			preg_match_all( '#<p\b[^>]*>.*?</p>#is', $body, $p_matches );

			$out[] = array(
				'index'       => $index,
				'heading'     => $heading,
				'html'        => $body,
				'word_count'  => self::word_count( $body ),
				'paragraphs'  => count( $p_matches[0] ?? array() ),
				'list_items'  => count( $li_matches[0] ?? array() ),
				'has_list'    => (bool) preg_match( '#<(?:ul|ol)\b#i', $body ),
				'has_quote'   => (bool) preg_match( '#<blockquote\b#i', $body ),
				'has_table'   => (bool) preg_match( '#<table\b#i', $body ),
				'has_image'   => ! empty( $images ),
				'images'      => $images,
			);
			$index++;
		}
		return $out;
	}

	public static function word_count( $value ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
		if ( '' === $text ) { return 0; }
		$parts = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return count( $parts );
	}
}
