<?php
/**
 * Page Brain — site-aware SEO planning.
 *
 * Produces one normalized plan that content generation, schema, internal links
 * and page architecture can share. The deterministic path adds no AI round trip;
 * the deeper path may ask the configured AI to refine topics/questions while
 * explicitly forbidding invented business facts.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Page_Brain {

	/** @var SCC_AI_Manager|null */
	protected $ai;

	public function __construct( $ai = null ) {
		$this->ai = $ai instanceof SCC_AI_Manager ? $ai : null;
	}

	public function plan( array $entry, $use_ai = false ) {
		$knowledge = SCC_Site_Knowledge::snapshot();
		$brand     = (array) ( $knowledge['brand'] ?? array() );
		$type      = sanitize_key( (string) ( $entry['page_type'] ?? 'article' ) );
		$intent    = sanitize_key( (string) ( $entry['intent'] ?? '' ) );
		if ( '' === $intent ) {
			$intent = in_array( $type, array( 'service', 'local_service', 'landing', 'location' ), true ) ? 'commercial' : 'informational';
		}
		$primary   = SCC_Security::sanitize_text( $entry['primary_keyword'] ?? '' );
		$secondary = self::str_list( $entry['secondary'] ?? array() );
		$location  = SCC_Security::sanitize_text( $entry['location'] ?? ( $entry['city'] ?? '' ) );

		$entities = array();
		foreach ( (array) ( $knowledge['entities']['nodes'] ?? array() ) as $node ) {
			$label = (string) ( $node['label'] ?? '' );
			if ( '' === $label ) { continue; }
			$hay = strtolower( $primary . ' ' . implode( ' ', $secondary ) . ' ' . $location . ' ' . (string) ( $entry['title'] ?? '' ) );
			$needle = strtolower( $label );
			if ( false !== strpos( $hay, $needle ) || false !== strpos( $needle, strtolower( $location ) ) ) {
				$entities[] = $label;
			}
		}
		$entities = array_values( array_unique( array_merge( $entities, $secondary ) ) );

		$links = SCC_Site_Knowledge::candidates_for_entry( $entry, 8 );
		$risk  = SCC_Site_Knowledge::cannibalization_risk( $entry );
		$plan  = array(
			'version'             => 1,
			'page_type'           => $type,
			'primary_intent'      => $intent,
			'primary_keyword'     => $primary,
			'secondary_topics'    => $secondary,
			'location'            => $location,
			'entities'            => $entities,
			'questions_to_answer' => self::default_questions( $type, $primary, $location ),
			'proof_requirements'  => self::proof_requirements( $type, $brand ),
			'evidence_slots'      => self::evidence_slots( $brand ),
			'conversion_goal'     => self::conversion_goal( $intent, $brand ),
			'internal_links'      => $links,
			'schema_types'        => self::schema_types( $type ),
			'cannibalization'     => $risk,
			'brand_context'       => array(
				'business_name' => (string) ( $brand['business_name'] ?? '' ),
				'voice'         => (string) ( $brand['voice'] ?? '' ),
				'primary_cta'   => (string) ( $brand['primary_cta'] ?? '' ),
				'forbidden_claims' => (array) ( $brand['forbidden_claims'] ?? array() ),
			),
			'source'              => 'deterministic-site-aware',
		);

		if ( $use_ai && $this->ai ) {
			$plan = $this->refine_with_ai( $plan, $entry, $knowledge );
		}
		return apply_filters( 'scc_page_brain_plan', $plan, $entry );
	}

	public static function for_post( $post_id ) {
		$raw = get_post_meta( (int) $post_id, '_scc_page_brain', true );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( is_array( $decoded ) && ! empty( $decoded ) ) { return $decoded; }

		$post = get_post( (int) $post_id );
		if ( ! $post ) { return array(); }
		$brief_raw = get_post_meta( (int) $post_id, '_scc_brief', true );
		$brief = is_string( $brief_raw ) ? json_decode( $brief_raw, true ) : array();
		$entry = array(
			'post_id'         => (int) $post_id,
			'title'           => get_the_title( $post ),
			'primary_keyword' => (string) ( $brief['context']['primary_keyword'] ?? $brief['primary_keyword'] ?? '' ),
			'secondary'       => (array) ( $brief['secondary_topics'] ?? $brief['secondary'] ?? array() ),
			'intent'          => (string) get_post_meta( $post_id, '_scc_search_intent', true ),
			'page_type'       => (string) get_post_meta( $post_id, '_scc_content_type', true ),
			'location'        => (string) ( $brief['context']['location'] ?? $brief['location'] ?? '' ),
		);
		return ( new self() )->plan( $entry, false );
	}

	public static function store( $post_id, array $plan ) {
		return update_post_meta( (int) $post_id, '_scc_page_brain', wp_json_encode( $plan ) );
	}

	protected function refine_with_ai( array $plan, array $entry, array $knowledge ) {
		$context = array(
			'target' => array(
				'title' => (string) ( $entry['title'] ?? '' ),
				'url' => (string) ( $entry['url'] ?? '' ),
			),
			'plan' => $plan,
			'site_entities' => array_slice( (array) ( $knowledge['entities']['nodes'] ?? array() ), 0, 40 ),
			'known_brand_facts' => (array) ( $knowledge['brand'] ?? array() ),
			'related_pages' => array_slice( (array) $plan['internal_links'], 0, 8 ),
		);
		$system = 'You are TideOrbit Page Brain. Refine an SEO page plan using ONLY the supplied site facts. '
			. 'Do not invent testimonials, statistics, credentials, services, locations or business claims. '
			. 'Return JSON with keys secondary_topics, entities, questions_to_answer, proof_requirements, conversion_goal, schema_types. '
			. 'Focus on satisfying search intent, topical completeness, useful first-party evidence and natural internal linking; never keyword density.';
		$response = $this->ai->complete(
			array(
				'system' => $system,
				'messages' => array( array( 'role' => 'user', 'content' => wp_json_encode( $context ) ) ),
				'json' => true,
				'max_tokens' => SCC_AI_Manager::token_budget( 2200 ),
				'temperature' => 0.3,
			),
			'content-brief'
		);
		if ( $response->is_error() ) { return $plan; }
		$ai = $response->json();
		if ( ! is_array( $ai ) ) { return $plan; }
		foreach ( array( 'secondary_topics', 'entities', 'questions_to_answer', 'proof_requirements', 'schema_types' ) as $key ) {
			if ( isset( $ai[ $key ] ) ) { $plan[ $key ] = self::str_list( $ai[ $key ] ); }
		}
		if ( ! empty( $ai['conversion_goal'] ) ) {
			$plan['conversion_goal'] = SCC_Security::sanitize_text( $ai['conversion_goal'] );
		}
		$plan['source'] = 'ai-refined-site-aware';
		return $plan;
	}

	protected static function schema_types( $type ) {
		$types = array( 'BreadcrumbList' );
		if ( in_array( $type, array( 'article', 'blog', 'blog_post', 'post' ), true ) ) {
			array_unshift( $types, 'Article' );
		} elseif ( in_array( $type, array( 'service', 'local_service', 'landing' ), true ) ) {
			array_unshift( $types, 'Service' );
			if ( 'local_service' === $type ) { $types[] = 'LocalBusiness'; }
		} elseif ( 'location' === $type ) {
			array_unshift( $types, 'LocalBusiness' );
		}
		return array_values( array_unique( $types ) );
	}

	protected static function conversion_goal( $intent, array $brand ) {
		if ( in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ) {
			return (string) ( $brand['primary_cta'] ?? '' );
		}
		return (string) ( $brand['secondary_cta'] ?? '' );
	}

	protected static function proof_requirements( $type, array $brand ) {
		$req = array();
		if ( in_array( $type, array( 'service', 'local_service', 'landing', 'location' ), true ) ) {
			$req[] = 'Use real first-party proof where available';
			$req[] = 'Explain the actual process or delivery model';
		}
		if ( ! empty( $brand['credentials'] ) ) { $req[] = 'Use verified credentials only'; }
		if ( ! empty( $brand['testimonials'] ) ) { $req[] = 'Use an existing testimonial where it supports the claim'; }
		return $req;
	}

	protected static function evidence_slots( array $brand ) {
		$slots = array();
		foreach ( (array) ( $brand['proof_points'] ?? array() ) as $v ) {
			$slots[] = array( 'type' => 'proof', 'value' => $v );
		}
		foreach ( (array) ( $brand['credentials'] ?? array() ) as $v ) {
			$slots[] = array( 'type' => 'credential', 'value' => $v );
		}
		foreach ( (array) ( $brand['testimonials'] ?? array() ) as $v ) {
			if ( ! empty( $v['quote'] ) ) { $slots[] = array( 'type' => 'testimonial', 'value' => $v ); }
		}
		return $slots;
	}

	protected static function default_questions( $type, $keyword, $location ) {
		$subject = '' !== $keyword ? $keyword : 'this topic';
		$q = array(
			'What is ' . $subject . ' and who is it for?',
			'What should someone consider before choosing an option?',
			'What does the process look like?',
		);
		if ( in_array( $type, array( 'service', 'local_service', 'landing' ), true ) ) {
			$q[] = 'What is included and what outcomes should a customer realistically expect?';
			$q[] = 'How is this different from alternative approaches?';
		}
		if ( '' !== $location ) {
			$q[] = 'What is genuinely specific about serving ' . $location . '?';
		}
		return $q;
	}

	protected static function str_list( $v ) {
		$out = array();
		foreach ( (array) $v as $x ) {
			$x = SCC_Security::sanitize_text( is_scalar( $x ) ? $x : '' );
			if ( '' !== $x ) { $out[] = $x; }
		}
		return array_values( array_unique( $out ) );
	}
}
