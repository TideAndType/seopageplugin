<?php
/**
 * Keyword / topical strategy builder.
 *
 * Turns structured business inputs into a structured topical map via the AI
 * layer (not a flat keyword list). Persists to scc_keyword_strategies.
 *
 * The output is explicitly AI strategic opinion — it is NOT measured search
 * volume or ranking data. When DataForSEO / GSC are connected (Phase 6) those
 * real metrics augment this map; until then nothing here is presented as a
 * measured number.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyword strategy service.
 */
class SCC_Keyword_Strategy {

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
	 * Sanitize the raw business inputs.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	public static function sanitize_inputs( array $raw ) {
		$list = function ( $value ) {
			if ( is_array( $value ) ) {
				$items = $value;
			} else {
				$items = preg_split( '/[\r\n,]+/', (string) $value );
			}
			$items = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $items ) ) );
			return array_values( array_slice( $items, 0, 100 ) );
		};

		return array(
			'business_name' => SCC_Security::sanitize_text( $raw['business_name'] ?? '' ),
			'description'   => SCC_Security::sanitize_textarea( $raw['description'] ?? '' ),
			'services'      => $list( $raw['services'] ?? '' ),
			'products'      => $list( $raw['products'] ?? '' ),
			'locations'     => $list( $raw['locations'] ?? '' ),
			'audience'      => SCC_Security::sanitize_textarea( $raw['audience'] ?? '' ),
			'competitors'   => $list( $raw['competitors'] ?? '' ),
			'seed_keywords' => $list( $raw['seed_keywords'] ?? '' ),
			'website'       => esc_url_raw( $raw['website'] ?? home_url() ),
		);
	}

	/**
	 * Build the AI prompt for the topical map.
	 *
	 * @param array $inputs Sanitized inputs.
	 * @return array {system, messages, json}
	 */
	protected function build_prompt( array $inputs ) {
		$system = 'You are a senior SEO strategist. Build a STRUCTURED TOPICAL MAP for a business, '
			. 'not a random keyword list. Group by service (and location where the business is local). '
			. 'For each cluster, identify one primary keyword, several genuinely distinct supporting terms, '
			. 'the dominant search intent (informational, commercial, transactional, navigational, or local), '
			. 'a clean recommended URL slug path, and 2-4 related internal topics. '
			. 'Only propose location+service pages where they would carry genuinely unique local value — '
			. 'never propose near-duplicate doorway pages. Do not invent search volume or difficulty numbers; '
			. 'you are giving strategic recommendations, not measured data. '
			. 'Return JSON with this exact shape: '
			. '{"clusters":[{"service":str,"location":str|null,"primary_keyword":str,"supporting_terms":[str],'
			. '"intent":str,"recommended_url":str,"related":[str],"page_type":"pillar|service|location|article",'
			. '"rationale":str}],"entities":[str],"notes":str}';

		$payload = wp_json_encode( $inputs );

		return array(
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => "Business inputs (JSON):\n" . $payload . "\n\nProduce the topical map JSON now.",
				),
			),
			'json'       => true,
			'max_tokens' => 4000,
			'temperature'=> 0.4,
		);
	}

	/**
	 * Generate a topical map and persist it.
	 *
	 * @param array $raw Raw business inputs.
	 * @return array|WP_Error {strategy_id, inputs, map}
	 */
	public function generate( array $raw ) {
		$inputs = self::sanitize_inputs( $raw );

		if ( '' === $inputs['business_name'] && empty( $inputs['services'] ) && empty( $inputs['seed_keywords'] ) ) {
			return new WP_Error( 'scc_missing_inputs', __( 'Enter at least a business name, a service, or a seed keyword.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$response = $this->ai->complete( $this->build_prompt( $inputs ), 'keyword-strategy' );
		if ( $response->is_error() ) {
			return $response->error;
		}

		$map = $response->json();
		if ( ! is_array( $map ) || empty( $map['clusters'] ) ) {
			SCC_Logger::error( 'keyword-strategy', 'AI returned unparseable topical map' );
			return new WP_Error( 'scc_bad_ai_output', __( 'The AI response could not be parsed into a topical map. Try again.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$map = $this->normalize_map( $map );

		$strategy_id = SCC_DB::insert(
			'keyword_strategies',
			array(
				'created_at'  => current_time( 'mysql' ),
				'name'        => $inputs['business_name'] ? $inputs['business_name'] : __( 'Keyword strategy', 'seo-command-center' ),
				'inputs'      => wp_json_encode( $inputs ),
				'topical_map' => wp_json_encode( $map ),
				'status'      => 'draft',
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		SCC_Logger::info( 'keyword-strategy', 'Topical map generated', array( 'strategy_id' => $strategy_id, 'clusters' => count( $map['clusters'] ) ) );

		return array(
			'strategy_id' => $strategy_id,
			'inputs'      => $inputs,
			'map'         => $map,
		);
	}

	/**
	 * Normalize and sanitize the AI map structure defensively.
	 *
	 * @param array $map Raw decoded map.
	 * @return array
	 */
	protected function normalize_map( array $map ) {
		$valid_intent = array( 'informational', 'commercial', 'transactional', 'navigational', 'local' );
		$valid_type   = array( 'pillar', 'service', 'location', 'article' );

		$clusters = array();
		foreach ( (array) ( $map['clusters'] ?? array() ) as $c ) {
			$intent = strtolower( (string) ( $c['intent'] ?? 'informational' ) );
			$type   = strtolower( (string) ( $c['page_type'] ?? 'service' ) );
			$clusters[] = array(
				'service'          => SCC_Security::sanitize_text( $c['service'] ?? '' ),
				'location'         => empty( $c['location'] ) ? '' : SCC_Security::sanitize_text( $c['location'] ),
				'primary_keyword'  => SCC_Security::sanitize_text( $c['primary_keyword'] ?? '' ),
				'supporting_terms' => array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $c['supporting_terms'] ?? array() ) ) ) ),
				'intent'           => in_array( $intent, $valid_intent, true ) ? $intent : 'informational',
				'recommended_url'  => $this->clean_slug_path( $c['recommended_url'] ?? '' ),
				'related'          => array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $c['related'] ?? array() ) ) ) ),
				'page_type'        => in_array( $type, $valid_type, true ) ? $type : 'service',
				'rationale'        => SCC_Security::sanitize_textarea( $c['rationale'] ?? '' ),
			);
		}

		return array(
			'clusters' => $clusters,
			'entities' => array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $map['entities'] ?? array() ) ) ) ),
			'notes'    => SCC_Security::sanitize_textarea( $map['notes'] ?? '' ),
		);
	}

	/**
	 * Sanitize a recommended URL into a clean relative slug path.
	 *
	 * @param string $url Raw URL or path.
	 * @return string
	 */
	protected function clean_slug_path( $url ) {
		$url  = (string) $url;
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			$path = $url;
		}
		$segments = array_filter( explode( '/', $path ) );
		$clean    = array_map( 'sanitize_title', $segments );
		$clean    = array_filter( $clean );
		return '/' . implode( '/', $clean ) . ( $clean ? '/' : '' );
	}

	/**
	 * Fetch the latest strategy row (decoded).
	 *
	 * @return array|null
	 */
	public static function latest() {
		global $wpdb;
		$table = SCC_DB::table( 'keyword_strategies' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['inputs_data'] = json_decode( (string) $row['inputs'], true );
		$row['map_data']    = json_decode( (string) $row['topical_map'], true );
		return $row;
	}

	/**
	 * Fetch a strategy by id (decoded).
	 *
	 * @param int $id Strategy id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = SCC_DB::get( 'keyword_strategies', $id );
		if ( ! $row ) {
			return null;
		}
		$row['inputs_data'] = json_decode( (string) $row['inputs'], true );
		$row['map_data']    = json_decode( (string) $row['topical_map'], true );
		return $row;
	}
}
