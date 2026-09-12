<?php
/**
 * Content Mapper — turns the analyzed content structure + a chosen layout into
 * per-block variables the renderer can consume. It extracts and populates each
 * block's declared fields from the real generated content, and slices FAQ / CTA
 * / lifted regions out of the full-body "content" block so nothing is rendered
 * twice.
 *
 * Every block variable is a token (HERO_TITLE, CARDS, FAQ_ITEMS, …) so the
 * output stays compatible with the plugin's token/placeholder philosophy.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content-to-block mapper.
 */
class SCC_Content_Mapper {

	/**
	 * Map a validated layout to a list of renderable blocks.
	 *
	 * @param array $layout   Ordered, validated block IDs.
	 * @param array $analysis Analyzed content structure.
	 * @param array $context  Optional { business: array }.
	 * @return array List of { id, name, render, vars, empty:bool }.
	 */
	public static function map( array $layout, array $analysis, array $context = array() ) {
		$blocks = array();
		foreach ( $layout as $id ) {
			$meta = SCC_Block_Registry::get( $id );
			if ( ! $meta ) {
				continue;
			}
			$vars  = self::vars_for( $id, $analysis, $layout, $context );
			$blocks[] = array(
				'id'     => $id,
				'name'   => $meta['name'],
				'render' => $meta['render'],
				'vars'   => $vars,
				'empty'  => self::is_empty( $meta['render'], $vars ),
			);
		}
		return $blocks;
	}

	/**
	 * Build the variables for one block.
	 *
	 * @param string $id       Block id.
	 * @param array  $analysis Analysis.
	 * @param array  $layout   Full layout (to know what is lifted elsewhere).
	 * @param array  $context  Context.
	 * @return array TOKEN => value (string or array).
	 */
	protected static function vars_for( $id, array $analysis, array $layout, array $context ) {
		switch ( $id ) {
			case 'hero':
				return array(
					'HERO_TITLE'    => (string) ( $analysis['h1'] ?: $analysis['title'] ),
					'HERO_SUBTITLE' => self::trim_words( (string) $analysis['intro'], 32 ),
					'CTA_TEXT'      => self::cta_text( $analysis ),
					'CTA_URL'       => self::cta_url( $analysis, $context ),
				);

			case 'content-intro':
				return array( 'INTRO' => (string) $analysis['intro'] );

			case 'toc':
				$items = array();
				foreach ( (array) $analysis['sections'] as $s ) {
					if ( 'h2' === ( $s['level'] ?? '' ) ) {
						$items[] = array( 'text' => $s['text'], 'anchor' => $s['anchor'] );
					}
				}
				return array( 'TOC_ITEMS' => $items );

			case 'content':
				return array( 'CONTENT' => self::body_html( $analysis, $layout ) );

			case 'benefits':
				return array(
					'SECTION_TITLE'  => __( 'Benefits', 'seo-command-center' ),
					'BENEFIT_ITEMS'  => array_values( (array) $analysis['benefits'] ),
				);

			case 'feature-grid':
			case 'service-grid':
			case 'service-cards':
				$cards = array();
				foreach ( (array) $analysis['services'] as $svc ) {
					$cards[] = array(
						'SERVICE_TITLE'       => (string) ( $svc['title'] ?? '' ),
						'SERVICE_DESCRIPTION' => (string) ( $svc['description'] ?? '' ),
						'SERVICE_URL'         => (string) ( $svc['url'] ?? '' ),
					);
				}
				return array(
					'SECTION_TITLE' => 'service-grid' === $id || 'service-cards' === $id ? __( 'Our services', 'seo-command-center' ) : __( 'What you get', 'seo-command-center' ),
					'CARDS'         => $cards,
				);

			case 'stats':
				return array(
					'SECTION_TITLE' => __( 'By the numbers', 'seo-command-center' ),
					'STATS'         => array_values( (array) $analysis['stats'] ),
				);

			case 'process-steps':
				return array(
					'SECTION_TITLE' => __( 'How it works', 'seo-command-center' ),
					'STEPS'         => array_values( (array) $analysis['process'] ),
				);

			case 'faq':
				return array(
					'SECTION_TITLE' => __( 'Frequently asked questions', 'seo-command-center' ),
					'FAQ_ITEMS'     => array_values( (array) $analysis['faqs'] ),
				);

			case 'related-content':
			case 'blog-grid':
				return array(
					'SECTION_TITLE'  => __( 'Related pages', 'seo-command-center' ),
					'RELATED_ITEMS'  => array_values( (array) $analysis['related'] ),
				);

			case 'service-area':
			case 'location-grid':
				$items = array_values( (array) $analysis['related'] );
				if ( empty( $items ) ) {
					foreach ( (array) $analysis['areas'] as $a ) {
						$items[] = array( 'title' => $a, 'url' => '' );
					}
				}
				return array(
					'SECTION_TITLE' => __( 'Areas we serve', 'seo-command-center' ),
					'AREA_ITEMS'    => $items,
				);

			case 'highlight-box':
				$text = ! empty( $analysis['benefits'] ) ? (string) $analysis['benefits'][0] : self::trim_words( (string) $analysis['intro'], 40 );
				return array(
					'HIGHLIGHT_TITLE' => __( 'Key takeaway', 'seo-command-center' ),
					'HIGHLIGHT_TEXT'  => $text,
				);

			case 'split-content':
			case 'image-content':
				return array(
					'SPLIT_TITLE' => (string) ( $analysis['sections'][0]['text'] ?? $analysis['title'] ),
					'SPLIT_TEXT'  => self::trim_words( (string) $analysis['intro'], 60 ),
					'SPLIT_IMAGE' => (string) ( $analysis['image']['url'] ?? '' ),
				);

			case 'testimonial':
				return array( 'QUOTE' => '', 'ATTRIBUTION' => '' );

			case 'comparison':
				$table = '';
				if ( preg_match( '/<table\b.*?<\/table>/is', (string) $analysis['content_html'], $m ) ) {
					$table = $m[0];
				}
				return array( 'SECTION_TITLE' => __( 'Comparison', 'seo-command-center' ), 'COMPARISON_HTML' => $table );

			case 'cta':
				return array(
					'CTA_TITLE' => self::cta_title( $analysis ),
					'CTA_TEXT'  => self::cta_text( $analysis ),
					'CTA_URL'   => self::cta_url( $analysis, $context ),
				);
		}
		return array();
	}

	/**
	 * The body HTML for the "content" block: the full article with FAQ, any
	 * trailing CTA block, and regions lifted into their own blocks (stats /
	 * process steps) removed — so nothing is rendered twice.
	 *
	 * @param array $analysis Analysis.
	 * @param array $layout   Layout (to know what is lifted).
	 * @return string
	 */
	public static function body_html( array $analysis, array $layout ) {
		$html = (string) $analysis['content_html'];

		// Always lift the FAQ and any appended CTA out of the prose body.
		$html = preg_replace( '/<div class="scc-faq">.*?<\/div>\s*(?=<|$)/is', '', $html );
		$html = preg_replace( '/<details\b[^>]*>.*?<\/details>/is', '', $html );
		$html = preg_replace( '/<div class="scc-cta">.*?<\/div>\s*(?=<|$)/is', '', $html );
		// A trailing FAQ heading with nothing after it.
		$html = preg_replace( '/<h2\b[^>]*>\s*(?:frequently asked questions|faq)\s*<\/h2>\s*$/is', '', $html );

		if ( in_array( 'stats', $layout, true ) ) {
			$html = preg_replace( '/<div class="scc-stats">.*?<\/div>\s*/is', '', $html );
		}
		if ( in_array( 'process-steps', $layout, true ) ) {
			$html = preg_replace( '/<ol class="scc-steps">.*?<\/ol>\s*/is', '', $html );
		}
		return trim( (string) $html );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Is a mapped block effectively empty (no content to show)?
	 *
	 * @param string $render Render hint.
	 * @param array  $vars   Variables.
	 * @return bool
	 */
	protected static function is_empty( $render, array $vars ) {
		switch ( $render ) {
			case 'hero':
				return '' === trim( (string) ( $vars['HERO_TITLE'] ?? '' ) );
			case 'text':
				return '' === trim( wp_strip_all_tags( (string) ( $vars['INTRO'] ?? '' ) ) );
			case 'html':
				foreach ( $vars as $v ) {
					if ( is_string( $v ) && '' !== trim( wp_strip_all_tags( $v ) ) ) {
						return false;
					}
				}
				return true;
			case 'list':
				return empty( $vars['BENEFIT_ITEMS'] );
			case 'grid':
				return empty( $vars['CARDS'] );
			case 'stats':
				return empty( $vars['STATS'] );
			case 'steps':
				return empty( $vars['STEPS'] );
			case 'faq':
				return empty( $vars['FAQ_ITEMS'] );
			case 'related':
				return empty( $vars['RELATED_ITEMS'] ) && empty( $vars['AREA_ITEMS'] );
			case 'cta':
				return '' === trim( (string) ( $vars['CTA_TITLE'] ?? '' ) ) && '' === trim( (string) ( $vars['CTA_TEXT'] ?? '' ) );
		}
		return false;
	}

	/**
	 * CTA title from content, with a sensible default.
	 *
	 * @param array $analysis Analysis.
	 * @return string
	 */
	protected static function cta_title( array $analysis ) {
		$kw = trim( (string) $analysis['primary_keyword'] );
		if ( '' !== $kw ) {
			/* translators: %s: primary keyword */
			return sprintf( __( 'Ready to get started with %s?', 'seo-command-center' ), $kw );
		}
		return __( 'Ready to get started?', 'seo-command-center' );
	}

	/**
	 * CTA button text.
	 *
	 * @param array $analysis Analysis.
	 * @return string
	 */
	protected static function cta_text( array $analysis ) {
		$t = trim( (string) ( $analysis['cta_text'] ?? '' ) );
		return '' !== $t ? $t : __( 'Get in touch', 'seo-command-center' );
	}

	/**
	 * CTA URL: explicit → business url → home url. Never invented.
	 *
	 * @param array $analysis Analysis.
	 * @param array $context  Context (business).
	 * @return string
	 */
	protected static function cta_url( array $analysis, array $context ) {
		$url = trim( (string) ( $analysis['cta_url'] ?? '' ) );
		if ( '' !== $url ) {
			return $url;
		}
		$business = isset( $context['business'] ) && is_array( $context['business'] ) ? $context['business'] : array();
		$burl = trim( (string) ( $business['url'] ?? '' ) );
		if ( '' !== $burl ) {
			return $burl;
		}
		return home_url( '/contact/' );
	}

	/**
	 * Trim text to a word count.
	 *
	 * @param string $text  Text.
	 * @param int    $words Max words.
	 * @return string
	 */
	protected static function trim_words( $text, $words ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			return '';
		}
		return wp_trim_words( $text, (int) $words, '…' );
	}
}
