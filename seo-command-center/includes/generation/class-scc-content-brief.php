<?php
/**
 * Site-aware content brief generator.
 *
 * Full Brief mode runs the Page Brain first, then asks the configured AI for a
 * structured editorial brief grounded in that plan. Quick Generate does not use
 * this class and therefore keeps its single-AI-call behavior.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Content_Brief {

	/** @var SCC_AI_Manager */
	protected $ai;

	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	public function generate( array $entry ) {
		$page_brain = class_exists( 'SCC_Page_Brain' )
			? ( new SCC_Page_Brain( $this->ai ) )->plan( $entry, true )
			: array();

		$system = 'You are an SEO content strategist creating a people-first content brief. '
			. 'Use the supplied Page Brain plan and known brand/site facts. Do not invent claims, statistics, testimonials, credentials, locations or services. '
			. 'Prioritize search-intent satisfaction, topical completeness, first-party evidence, helpful examples, and natural internal links. '
			. 'Do not optimize for keyword density or arbitrary word count. '
			. 'Return JSON: {"h1":str,"search_intent":str,"summary":str,"recommended_words":int,'
			. '"outline":[{"heading":str,"purpose":str,"evidence_needed":str}],"entities":[str],"questions":[str],'
			. '"internal_link_targets":[str],"external_reference_types":[str],"cta":str}.';

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
			'page_brain'      => $page_brain,
		);

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => "Planning context (JSON):
" . wp_json_encode( $context ) . "

Produce the content brief JSON now.",
					),
				),
				'json'        => true,
				'max_tokens'  => SCC_AI_Manager::token_budget( 3200 ),
				'temperature' => 0.35,
			),
			'content-brief'
		);

		if ( $response->is_error() ) { return $response->error; }
		$brief = $response->json();
		if ( ! is_array( $brief ) ) {
			return new WP_Error( 'scc_bad_ai_output', __( 'The AI brief could not be parsed. Try again.', 'seo-command-center' ), array( 'status' => 502 ) );
		}
		return $this->normalize( $brief, $context );
	}

	protected function normalize( array $brief, array $context ) {
		$strip = function ( $items ) {
			return array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) $items ) ) );
		};

		$outline = array();
		foreach ( (array) ( $brief['outline'] ?? array() ) as $section ) {
			$heading = SCC_Security::sanitize_text( is_array( $section ) ? ( $section['heading'] ?? '' ) : $section );
			if ( '' === $heading ) { continue; }
			$outline[] = array(
				'heading'         => $heading,
				'purpose'         => SCC_Security::sanitize_text( is_array( $section ) ? ( $section['purpose'] ?? '' ) : '' ),
				'evidence_needed' => SCC_Security::sanitize_text( is_array( $section ) ? ( $section['evidence_needed'] ?? '' ) : '' ),
			);
		}

		$words = (int) ( $brief['recommended_words'] ?? $context['target_words'] );
		$words = SCC_Security::sanitize_int( $words, 300, 6000 );
		$page_brain = (array) ( $context['page_brain'] ?? array() );

		$entities = $strip( $brief['entities'] ?? array() );
		if ( empty( $entities ) ) { $entities = $strip( $page_brain['entities'] ?? array() ); }
		$questions = $strip( $brief['questions'] ?? array() );
		if ( empty( $questions ) ) { $questions = $strip( $page_brain['questions_to_answer'] ?? array() ); }

		return array(
			'h1'                       => SCC_Security::sanitize_text( $brief['h1'] ?? $context['title'] ),
			'search_intent'            => SCC_Security::sanitize_text( $brief['search_intent'] ?? $context['intent'] ),
			'summary'                  => SCC_Security::sanitize_textarea( $brief['summary'] ?? '' ),
			'recommended_words'        => $words,
			'outline'                  => $outline,
			'entities'                 => $entities,
			'questions'                => $questions,
			'internal_link_targets'    => $strip( $brief['internal_link_targets'] ?? wp_list_pluck( (array) ( $page_brain['internal_links'] ?? array() ), 'url' ) ),
			'external_reference_types' => $strip( $brief['external_reference_types'] ?? array() ),
			'cta'                      => SCC_Security::sanitize_textarea( $brief['cta'] ?? ( $page_brain['conversion_goal'] ?? '' ) ),
			'page_brain'               => $page_brain,
			'context'                  => $context,
		);
	}
}
