<?php
/**
 * Content Ideas — ask in plain language, get SEO-driven page suggestions.
 *
 * The user describes what they want ("I have no industry pages — create industry
 * pages optimized for CTR and top keywords") and this proposes concrete pages,
 * GROUNDED in the site's real context: the pages it already has (so nothing is
 * duplicated), real Search Console demand (top queries / quick wins / untapped),
 * the business profile, and the topical map. Each idea is ready to drop into the
 * Content Plan and generate with the existing pipeline.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content ideas service.
 */
class SCC_Content_Ideas {

	/** @var SCC_AI_Manager */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai AI manager.
	 */
	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Suggest pages for a natural-language request. Optionally REFINE a previous
	 * set (e.g. "make them more local", "add 5 more", "focus on commercial intent").
	 *
	 * @param string $question The original request.
	 * @param int    $count    How many ideas to return (1-20).
	 * @param string $refine   A refinement instruction (optional).
	 * @param array  $previous The previously-returned ideas to refine (optional).
	 * @return array|WP_Error {ideas:array, grounded:array}
	 */
	public function suggest( $question, $count = 8, $refine = '', $previous = array() ) {
		$question = trim( wp_strip_all_tags( (string) $question ) );
		$refine   = trim( wp_strip_all_tags( (string) $refine ) );
		if ( '' === $question && '' === $refine ) {
			return new WP_Error( 'scc_no_question', __( 'Tell me what you want ideas for.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$count    = max( 1, min( 20, (int) $count ) );
		$previous = self::sanitize_ideas( array( 'ideas' => is_array( $previous ) ? $previous : array() ) );

		// Real site context — never invent what the site is or already has.
		$existing = class_exists( 'SCC_Keyword_Strategy' ) ? SCC_Keyword_Strategy::existing_site_pages( 200 ) : array();
		$gsc      = class_exists( 'SCC_Keyword_Strategy' ) ? SCC_Keyword_Strategy::gsc_signals() : array();
		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();

		$pillars = array();
		if ( class_exists( 'SCC_Keyword_Strategy' ) ) {
			$strategy = SCC_Keyword_Strategy::latest();
			$map      = ( $strategy && ! empty( $strategy['map_data'] ) ) ? $strategy['map_data'] : array();
			foreach ( (array) ( $map['clusters'] ?? array() ) as $c ) {
				$svc = trim( (string) ( $c['service'] ?? '' ) );
				if ( '' !== $svc ) {
					$pillars[] = $svc;
				}
			}
		}

		$context = array(
			'request'          => $question,
			'business'         => array(
				'name'          => (string) ( $business['organization_name'] ?? get_bloginfo( 'name' ) ),
				'city'          => (string) ( $business['city'] ?? '' ),
				'region'        => (string) ( $business['region'] ?? '' ),
				'service_areas' => array_slice( (array) ( $business['service_areas'] ?? array() ), 0, 20 ),
			),
			'existing_pages'   => array_slice( $existing, 0, 150 ),
			'pillars'          => array_values( array_unique( $pillars ) ),
			'gsc_top_queries'  => array_slice( (array) ( $gsc['top_queries'] ?? array() ), 0, 40 ),
			'gsc_quick_wins'   => array_slice( (array) ( $gsc['quick_wins'] ?? array() ), 0, 25 ),
			'gsc_untapped'     => array_slice( (array) ( $gsc['untapped'] ?? array() ), 0, 25 ),
			'count'            => $count,
		);
		if ( '' !== $refine ) {
			$context['refine_instruction'] = $refine;
			$context['previous_ideas']     = array_map( function ( $i ) {
				return array( 'title' => $i['title'], 'primary_keyword' => $i['primary_keyword'], 'page_type' => $i['page_type'], 'intent' => $i['intent'], 'recommended_url' => $i['recommended_url'] );
			}, $previous );
		}

		$refine_rule = ( '' !== $refine )
			? 'This is a REFINEMENT of PREVIOUS_IDEAS. Apply REFINE_INSTRUCTION: keep the ideas that already fit, '
				. 'revise the others to match, and add new ones so there are EXACTLY ' . $count . ' in total. Keep '
				. 'everything grounded and never duplicate existing_pages. '
			: '';

		$system = 'You are a senior SEO content strategist. The user will describe pages they want. Propose EXACTLY '
			. $count . ' concrete, distinct pages that fulfil the request, each optimized for SEO and click-through. '
			. $refine_rule
			. 'GROUND every idea in the provided context: infer the real business from existing_pages + business; '
			. 'reuse REAL demand from gsc_top_queries / gsc_quick_wins / gsc_untapped for keywords where relevant; '
			. 'and NEVER duplicate a page already in existing_pages. If the request names a page TYPE (e.g. industry '
			. 'pages, location pages, service pages, comparison pages), produce that type. For each page give: a '
			. 'natural H1-style title; a click-optimized meta_title (<=60 chars, earns the click, no clickbait); a '
			. 'meta_description (<=155 chars, a real reason to click); the primary_keyword (prefer real GSC phrasing); '
			. '2-5 secondary_keywords; the search intent; a page_type (pillar|service|location|industry|comparison|'
			. 'article); a clean recommended_url slug path that does NOT collide with existing_pages; a strategic '
			. 'priority (high|medium|low); and a short "why" citing the keyword/demand or gap it targets. '
			. 'Return JSON: {"ideas":[{"title":str,"meta_title":str,"meta_description":str,"primary_keyword":str,'
			. '"secondary_keywords":[str],"intent":str,"page_type":str,"recommended_url":str,"priority":str,"why":str}],'
			. '"notes":str}';

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array( 'role' => 'user', 'content' => "Context (JSON):\n" . wp_json_encode( $context ) . "\n\nReturn the ideas JSON now." ),
				),
				'json'        => true,
				'max_tokens'  => 2600,
				'temperature' => 0.6,
			),
			'content-ideas'
		);
		if ( $response->is_error() ) {
			return $response->error;
		}
		$parsed = $response->json();
		$ideas  = self::sanitize_ideas( is_array( $parsed ) ? $parsed : array() );
		if ( empty( $ideas ) ) {
			return new WP_Error( 'scc_no_ideas', __( 'No ideas came back. Try rephrasing your request.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		return array(
			'ideas'    => $ideas,
			'notes'    => isset( $parsed['notes'] ) ? SCC_Security::sanitize_text( $parsed['notes'] ) : '',
			'grounded' => array(
				'gsc'            => ! empty( $gsc['connected'] ),
				'existing_pages' => count( $existing ),
			),
		);
	}

	/**
	 * Sanitize the AI ideas payload into safe, typed rows. Pure.
	 *
	 * @param array $parsed Decoded AI JSON.
	 * @return array
	 */
	public static function sanitize_ideas( array $parsed ) {
		$rows  = array();
		$types = array( 'pillar', 'service', 'location', 'industry', 'comparison', 'article' );
		foreach ( (array) ( $parsed['ideas'] ?? array() ) as $idea ) {
			if ( ! is_array( $idea ) ) {
				continue;
			}
			$title = SCC_Security::sanitize_text( $idea['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$type = strtolower( (string) ( $idea['page_type'] ?? 'article' ) );
			$prio = strtolower( (string) ( $idea['priority'] ?? 'medium' ) );
			$sec  = array();
			foreach ( (array) ( $idea['secondary_keywords'] ?? array() ) as $s ) {
				$s = SCC_Security::sanitize_text( $s );
				if ( '' !== $s ) {
					$sec[] = $s;
				}
			}
			$rows[] = array(
				'title'              => $title,
				'meta_title'         => SCC_Security::sanitize_text( $idea['meta_title'] ?? '' ),
				'meta_description'   => SCC_Security::sanitize_textarea( $idea['meta_description'] ?? '' ),
				'primary_keyword'    => SCC_Security::sanitize_text( $idea['primary_keyword'] ?? '' ),
				'secondary_keywords' => array_slice( $sec, 0, 8 ),
				'intent'             => SCC_Security::sanitize_text( $idea['intent'] ?? '' ),
				'page_type'          => in_array( $type, $types, true ) ? $type : 'article',
				'recommended_url'    => SCC_Security::sanitize_text( $idea['recommended_url'] ?? '' ),
				'priority'           => in_array( $prio, array( 'high', 'medium', 'low' ), true ) ? $prio : 'medium',
				'why'                => SCC_Security::sanitize_textarea( $idea['why'] ?? '' ),
			);
		}
		return array_slice( $rows, 0, 20 );
	}
}
