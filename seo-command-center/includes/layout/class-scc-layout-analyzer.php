<?php
/**
 * Layout Analyzer — turns a content object (or a generated post) into a
 * normalized "content structure" the layout engine can reason about: a
 * canonical content type, a search intent, and the concrete content pieces that
 * are actually available (intro, sections, services, benefits, steps, stats,
 * FAQs, related links, service areas, image).
 *
 * It never invents content — it only reports what the generated content already
 * contains, so the layout engine and content mapper can decide which blocks are
 * appropriate.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content structure analyzer.
 */
class SCC_Layout_Analyzer {

	/** Canonical content types the engine reasons about. */
	const TYPES = array( 'article', 'blog_post', 'service', 'local_service', 'location', 'landing', 'comparison', 'informational' );

	/** Canonical search intents. */
	const INTENTS = array( 'informational', 'commercial', 'transactional', 'local', 'navigational' );

	/**
	 * Normalize an arbitrary page_type/content_type string to a canonical type.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	public static function normalize_type( $type ) {
		$t = strtolower( trim( (string) $type ) );
		$t = str_replace( array( ' ', '-' ), '_', $t );
		$map = array(
			'blog'         => 'blog_post',
			'blog_post'    => 'blog_post',
			'post'         => 'blog_post',
			'article'      => 'article',
			'informational'=> 'informational',
			'pillar'       => 'article',
			'guide'        => 'article',
			'service'      => 'service',
			'service_page' => 'service',
			'local_service'=> 'local_service',
			'local'        => 'local_service',
			'location'     => 'location',
			'location_page'=> 'location',
			'landing'      => 'landing',
			'landing_page' => 'landing',
			'industry'     => 'landing',
			'comparison'   => 'comparison',
			'custom'       => 'landing',
		);
		if ( isset( $map[ $t ] ) ) {
			return $map[ $t ];
		}
		return in_array( $t, self::TYPES, true ) ? $t : 'article';
	}

	/**
	 * Normalize an arbitrary intent string to a canonical intent.
	 *
	 * @param string $intent Raw intent.
	 * @param string $type   Canonical content type (used as a sensible default).
	 * @return string
	 */
	public static function normalize_intent( $intent, $type = '' ) {
		$i = strtolower( trim( (string) $intent ) );
		$i = str_replace( array( ' ', '-', '/' ), '_', $i );
		if ( false !== strpos( $i, 'local' ) ) {
			return 'local';
		}
		if ( false !== strpos( $i, 'transact' ) || false !== strpos( $i, 'buy' ) ) {
			return 'transactional';
		}
		if ( false !== strpos( $i, 'commerc' ) ) {
			return 'commercial';
		}
		if ( false !== strpos( $i, 'navigat' ) ) {
			return 'navigational';
		}
		if ( false !== strpos( $i, 'inform' ) ) {
			return 'informational';
		}
		// Sensible default from the content type.
		if ( in_array( $type, array( 'service', 'landing' ), true ) ) {
			return 'commercial';
		}
		if ( in_array( $type, array( 'local_service', 'location' ), true ) ) {
			return 'local';
		}
		return 'informational';
	}

	/**
	 * Analyze a content object into a normalized structure.
	 *
	 * @param SCC_Content_Object $c Content object.
	 * @return array
	 */
	public static function analyze( SCC_Content_Object $c ) {
		$type   = self::normalize_type( $c->content_type );
		$intent = self::normalize_intent( $c->search_intent, $type );
		$html   = (string) $c->content;

		$sections = self::extract_headings( $html );
		$faqs     = self::normalize_faqs( $c->faq );
		if ( empty( $faqs ) ) {
			$faqs = self::extract_faqs_from_html( $html );
		}

		$services  = self::services_from_sections( $sections, $html );
		$benefits  = self::extract_benefits( $html );
		$process   = self::process_from_object( $c, $html );
		$stats     = self::extract_stats( $html );
		$related   = self::normalize_links( $c->internal_links );

		$image = is_array( $c->image ) ? $c->image : array();

		return array(
			'content_type'    => $type,
			'search_intent'   => $intent,
			'title'           => (string) ( $c->title ?: $c->h1 ),
			'h1'              => (string) ( $c->h1 ?: $c->title ),
			'primary_keyword' => (string) $c->primary_keyword,
			'intro'           => (string) ( $c->intro ?: self::first_paragraph( $html ) ),
			'content_html'    => $html,
			'sections'        => $sections,
			'services'        => $services,
			'benefits'        => $benefits,
			'process'         => $process,
			'stats'           => $stats,
			'faqs'            => $faqs,
			'related'         => $related,
			'areas'           => self::areas_from_object( $c ),
			'city'            => (string) $c->city,
			'service'         => (string) $c->service,
			'cta'             => (string) $c->cta,
			'cta_text'        => (string) $c->cta_text,
			'cta_url'         => (string) $c->cta_url,
			'image'           => $image,
			'has_image'       => ! empty( $image['url'] ) || ! empty( $image['id'] ),
			'is_local'        => in_array( $type, array( 'local_service', 'location' ), true ) || 'local' === $intent || '' !== (string) $c->city,
			'counts'          => array(
				'sections' => count( $sections ),
				'services' => count( $services ),
				'benefits' => count( $benefits ),
				'process'  => count( $process ),
				'stats'    => count( $stats ),
				'faqs'     => count( $faqs ),
				'related'  => count( $related ),
			),
		);
	}

	/**
	 * Build a content object from a generated post so the layout engine can run
	 * against pages the user has already created. Reads the stored brief for
	 * keyword/intent/cta and parses the post body for structure.
	 *
	 * @param int $post_id Post id.
	 * @return SCC_Content_Object|WP_Error
	 */
	public static function content_object_from_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new WP_Error( 'scc_no_post', __( 'That post could not be found.', 'seo-command-center' ) );
		}
		$brief = json_decode( (string) get_post_meta( $post_id, '_scc_brief', true ), true );
		$brief = is_array( $brief ) ? $brief : array();

		$obj                  = new SCC_Content_Object();
		$obj->title           = (string) $post->post_title;
		$obj->h1              = (string) ( $brief['h1'] ?? $post->post_title );
		$obj->slug            = (string) $post->post_name;
		$obj->content_type    = (string) ( get_post_meta( $post_id, '_scc_content_type', true ) ?: ( $brief['page_type'] ?? ( 'page' === $post->post_type ? 'service' : 'article' ) ) );
		$obj->primary_keyword = (string) ( $brief['primary_keyword'] ?? '' );
		$obj->secondary_keywords = (array) ( $brief['secondary'] ?? array() );
		$obj->search_intent   = (string) ( get_post_meta( $post_id, '_scc_search_intent', true ) ?: ( $brief['search_intent'] ?? '' ) );
		$obj->content         = (string) $post->post_content;
		$obj->cta             = (string) ( $brief['cta'] ?? '' );

		$img = json_decode( (string) get_post_meta( $post_id, '_scc_image_recommendation', true ), true );
		$obj->image = is_array( $img ) ? $img : array();

		// Featured image wins as a concrete, real URL when one is set.
		$thumb = get_post_thumbnail_id( $post_id );
		if ( $thumb ) {
			$src = wp_get_attachment_image_url( $thumb, 'large' );
			if ( $src ) {
				$obj->image = array( 'id' => (int) $thumb, 'url' => $src, 'alt' => (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) );
			}
		}

		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $obj->content, $m ) ) {
			$obj->intro = trim( wp_strip_all_tags( $m[1] ) );
		}
		return $obj;
	}

	/* ---------------------------------------------------------------------
	 * Extraction helpers (pure).
	 * ------------------------------------------------------------------- */

	/**
	 * Extract H2/H3 headings as [{level, text, anchor}].
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function extract_headings( $html ) {
		$out = array();
		if ( preg_match_all( '/<(h2|h3)\b[^>]*>(.*?)<\/\1>/is', (string) $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$text = trim( wp_strip_all_tags( $row[2] ) );
				if ( '' === $text ) {
					continue;
				}
				$out[] = array(
					'level'  => strtolower( $row[1] ),
					'text'   => $text,
					'anchor' => sanitize_title( $text ),
				);
			}
		}
		return $out;
	}

	/**
	 * The first paragraph's plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function first_paragraph( $html ) {
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', (string) $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * Build service cards from the top-level H2 sections: each H2 becomes a card
	 * title, with the paragraph text that follows it as the description.
	 *
	 * @param array  $headings Headings from extract_headings().
	 * @param string $html     HTML.
	 * @return array [{title, description, url}]
	 */
	public static function services_from_sections( array $headings, $html ) {
		$cards = array();
		foreach ( $headings as $h ) {
			if ( 'h2' !== $h['level'] ) {
				continue;
			}
			// Skip obvious non-service sections.
			if ( preg_match( '/^(faq|frequently|conclusion|summary|table of contents|in this|introduction|about)\b/i', $h['text'] ) ) {
				continue;
			}
			$desc = self::text_after_heading( $html, $h['text'] );
			$cards[] = array(
				'title'       => $h['text'],
				'description' => $desc,
				'url'         => '',
			);
			if ( count( $cards ) >= 6 ) {
				break;
			}
		}
		return $cards;
	}

	/**
	 * The plain text of the first paragraph following a given heading text.
	 *
	 * @param string $html    HTML.
	 * @param string $heading Heading text (plain).
	 * @return string
	 */
	protected static function text_after_heading( $html, $heading ) {
		$q = preg_quote( $heading, '/' );
		if ( preg_match( '/<h2\b[^>]*>\s*' . $q . '\s*<\/h2>\s*(?:<[^>]+>\s*)*?<p[^>]*>(.*?)<\/p>/is', (string) $html, $m ) ) {
			return trim( wp_strip_all_tags( $m[1] ) );
		}
		return '';
	}

	/**
	 * Benefit bullets: the first meaningful <ul> in the body.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function extract_benefits( $html ) {
		if ( ! preg_match_all( '/<ul\b[^>]*>(.*?)<\/ul>/is', (string) $html, $lists ) ) {
			return array();
		}
		foreach ( $lists[1] as $listHtml ) {
			if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $listHtml, $items ) ) {
				$vals = array();
				foreach ( $items[1] as $li ) {
					$t = trim( wp_strip_all_tags( $li ) );
					if ( '' !== $t ) {
						$vals[] = $t;
					}
				}
				if ( count( $vals ) >= 3 ) {
					return array_slice( $vals, 0, 8 );
				}
			}
		}
		return array();
	}

	/**
	 * Process steps from the content object, or from an <ol> in the body.
	 *
	 * @param SCC_Content_Object $c    Content object.
	 * @param string             $html HTML.
	 * @return array [{title, description}]
	 */
	public static function process_from_object( SCC_Content_Object $c, $html ) {
		$steps = array();
		if ( ! empty( $c->process ) && is_array( $c->process ) ) {
			foreach ( $c->process as $s ) {
				if ( is_array( $s ) ) {
					$steps[] = array( 'title' => (string) ( $s['title'] ?? '' ), 'description' => (string) ( $s['description'] ?? '' ) );
				} else {
					$steps[] = array( 'title' => (string) $s, 'description' => '' );
				}
			}
			return array_slice( $steps, 0, 8 );
		}
		// Look for an <ol> under a process/steps heading.
		if ( preg_match( '/<h[23]\b[^>]*>\s*[^<]*\b(process|steps|how it works|how to|methodology|workflow)\b[^<]*<\/h[23]>\s*(?:<[^>]+>\s*)*?<ol\b[^>]*>(.*?)<\/ol>/is', (string) $html, $m ) ) {
			if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $m[2], $items ) ) {
				foreach ( $items[1] as $li ) {
					$t = trim( wp_strip_all_tags( $li ) );
					if ( '' !== $t ) {
						$steps[] = array( 'title' => $t, 'description' => '' );
					}
				}
			}
		}
		return array_slice( $steps, 0, 8 );
	}

	/**
	 * Stats: "By the numbers" style list items "value — label" or "label: value".
	 *
	 * @param string $html HTML.
	 * @return array [{value, label}]
	 */
	public static function extract_stats( $html ) {
		$stats = array();
		// Reuse the presenter's stat markup if present.
		if ( preg_match_all( '/<div class="scc-stat">\s*<span class="scc-stat__value">(.*?)<\/span>(?:\s*<span class="scc-stat__label">(.*?)<\/span>)?/is', (string) $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$stats[] = array( 'value' => trim( wp_strip_all_tags( $row[1] ) ), 'label' => trim( wp_strip_all_tags( $row[2] ?? '' ) ) );
			}
		}
		return array_slice( $stats, 0, 6 );
	}

	/**
	 * FAQs from the content object array — [{question, answer}].
	 *
	 * @param array $faq Raw FAQ.
	 * @return array
	 */
	public static function normalize_faqs( $faq ) {
		$out = array();
		foreach ( (array) $faq as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$q = trim( (string) ( $f['question'] ?? '' ) );
			$a = trim( (string) ( $f['answer'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		return $out;
	}

	/**
	 * Parse FAQs from rendered <details>/<summary> or the plugin's .scc-faq markup.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function extract_faqs_from_html( $html ) {
		$out = array();
		if ( preg_match_all( '/<details\b[^>]*>\s*<summary[^>]*>(.*?)<\/summary>(.*?)<\/details>/is', (string) $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $row ) {
				$q = trim( wp_strip_all_tags( $row[1] ) );
				$a = trim( wp_strip_all_tags( $row[2] ) );
				if ( '' !== $q && '' !== $a ) {
					$out[] = array( 'question' => $q, 'answer' => $a );
				}
			}
		}
		return $out;
	}

	/**
	 * Normalize internal-link recommendations to [{title, url}].
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function normalize_links( $links ) {
		$out = array();
		foreach ( (array) $links as $l ) {
			if ( is_array( $l ) ) {
				$url   = (string) ( $l['url'] ?? $l['target_url'] ?? '' );
				$title = (string) ( $l['title'] ?? $l['anchor'] ?? '' );
				if ( '' !== $url ) {
					$out[] = array( 'title' => ( '' !== $title ? $title : $url ), 'url' => $url );
				}
			}
		}
		return array_slice( $out, 0, 8 );
	}

	/**
	 * Service areas from the content object (city/state/service metadata).
	 *
	 * @param SCC_Content_Object $c Content object.
	 * @return string[]
	 */
	public static function areas_from_object( SCC_Content_Object $c ) {
		$areas = array();
		if ( '' !== (string) $c->city ) {
			$areas[] = trim( $c->city . ( $c->state ? ', ' . $c->state : '' ) );
		}
		return $areas;
	}
}
