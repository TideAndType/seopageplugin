<?php
/**
 * Content brief generator.
 *
 * Produces a structured brief the user approves BEFORE any full content is
 * generated: target keyword, intent, URL, recommended length, related topics,
 * entities, questions to answer, internal-link targets, external reference
 * types, and a CTA angle. One AI call (JSON), defensively normalized.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content brief service.
 */
class SCC_Content_Brief {

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
	 * Build a brief from a content-plan entry.
	 *
	 * @param array $entry Decoded content-plan row.
	 * @return array|WP_Error
	 */
	public function generate( array $entry ) {
		$system = 'You are an SEO content strategist creating a content brief. '
			. 'Given a target page, produce a brief that will guide a genuinely useful, specific, original article or page — '
			. 'never keyword-stuffed, padded, or generic. For a location page, the brief must call for real local specificity, '
			. 'not a find-and-replace of a city name. '
			. 'Return JSON with this shape: '
			. '{"h1":str,"search_intent":str,"summary":str,"recommended_words":int,'
			. '"outline":[{"heading":str,"purpose":str}],"entities":[str],"questions":[str],'
			. '"internal_link_targets":[str],"external_reference_types":[str],"cta":str}';

		$context = array(
			'title'           => $entry['title'] ?? '',
			'url'             => $entry['url'] ?? '',
			'primary_keyword' => $entry['primary_keyword'] ?? '',
			'secondary'       => $entry['secondary'] ?? array(),
			'intent'          => $entry['intent'] ?? '',
			'page_type'       => $entry['page_type'] ?? 'article',
			'parent'          => $entry['parent'] ?? '',
			'target_words'    => (int) ( $entry['word_count'] ?? SCC_Settings::get( 'default_word_count', 1200 ) ),
			'site_name'       => get_bloginfo( 'name' ),
		);

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => "Target page (JSON):\n" . wp_json_encode( $context ) . "\n\nProduce the content brief JSON now.",
					),
				),
				'json'        => true,
				'max_tokens'  => 2500,
				'temperature' => 0.5,
			),
			'content-brief'
		);

		if ( $response->is_error() ) {
			return $response->error;
		}

		$brief = $response->json();
		if ( ! is_array( $brief ) ) {
			return new WP_Error( 'scc_bad_ai_output', __( 'The AI brief could not be parsed. Try again.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		return $this->normalize( $brief, $context );
	}

	/**
	 * Normalize/sanitize a brief.
	 *
	 * @param array $brief   Raw brief.
	 * @param array $context Context used to fill gaps.
	 * @return array
	 */
	protected function normalize( array $brief, array $context ) {
		$text  = array( 'SCC_Security', 'sanitize_text' );
		$strip = function ( $items ) {
			return array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) $items ) ) );
		};

		$outline = array();
		foreach ( (array) ( $brief['outline'] ?? array() ) as $section ) {
			$heading = SCC_Security::sanitize_text( is_array( $section ) ? ( $section['heading'] ?? '' ) : $section );
			if ( '' === $heading ) {
				continue;
			}
			$outline[] = array(
				'heading' => $heading,
				'purpose' => SCC_Security::sanitize_text( is_array( $section ) ? ( $section['purpose'] ?? '' ) : '' ),
			);
		}

		$words = (int) ( $brief['recommended_words'] ?? $context['target_words'] );
		$words = SCC_Security::sanitize_int( $words, 300, 6000 );

		return array(
			'h1'                      => SCC_Security::sanitize_text( $brief['h1'] ?? $context['title'] ),
			'search_intent'          => SCC_Security::sanitize_text( $brief['search_intent'] ?? $context['intent'] ),
			'summary'                => SCC_Security::sanitize_textarea( $brief['summary'] ?? '' ),
			'recommended_words'      => $words,
			'outline'                => $outline,
			'entities'               => $strip( $brief['entities'] ?? array() ),
			'questions'              => $strip( $brief['questions'] ?? array() ),
			'internal_link_targets'  => $strip( $brief['internal_link_targets'] ?? array() ),
			'external_reference_types'=> $strip( $brief['external_reference_types'] ?? array() ),
			'cta'                    => SCC_Security::sanitize_textarea( $brief['cta'] ?? '' ),
			// Carry context forward for the generator.
			'context'                => $context,
		);
	}
}
