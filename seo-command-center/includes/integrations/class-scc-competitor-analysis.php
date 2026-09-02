<?php
/**
 * Competitor analysis (public information only).
 *
 * Fetches a competitor URL through the robots-respecting crawler and extracts
 * public structural signals (title, headings, schema, link counts). Compares
 * their heading topics to the current site's analyzed content to surface
 * content gaps for strategic comparison — never to copy content, and never
 * bypassing robots.txt, authentication, paywalls, or access controls.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Competitor analyzer.
 */
class SCC_Competitor_Analysis {

	const STOP_WORDS = array( 'the', 'a', 'an', 'and', 'or', 'for', 'of', 'in', 'to', 'with', 'your', 'our', 'how', 'what', 'why', 'we', 'is', 'are' );

	/** @var SCC_AI_Manager|null */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager|null $ai AI manager (required only for gap_map()).
	 */
	public function __construct( $ai = null ) {
		$this->ai = $ai;
	}

	/**
	 * Analyze one or more competitor URLs and produce a strategic CONTENT MAP of
	 * the pages your site is missing — the topics they cover that you do not.
	 *
	 * Crawls each competitor (robots-respecting, public HTML only), aggregates
	 * their heading topics, grounds the comparison against your real published
	 * pages, and asks the AI to turn the gaps into concrete pages to create.
	 *
	 * @param array $urls Competitor URLs (max 5).
	 * @return array|WP_Error {competitors:[...], gaps:[...], notes:string}
	 */
	public function gap_map( array $urls ) {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $urls ) ) ) );
		$urls = array_slice( array_unique( $urls ), 0, 5 );
		if ( empty( $urls ) ) {
			return new WP_Error( 'scc_no_urls', __( 'Add at least one competitor URL.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		if ( ! $this->ai instanceof SCC_AI_Manager ) {
			return new WP_Error( 'scc_no_ai', __( 'AI is not configured. Connect a provider in Settings first.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$crawler     = new SCC_Crawler();
		$competitors = array();
		foreach ( $urls as $url ) {
			$data = $crawler->fetch( $url, true );
			if ( is_wp_error( $data ) ) {
				$competitors[] = array( 'url' => $url, 'error' => $data->get_error_message(), 'headings' => array() );
				continue;
			}
			$competitors[] = array(
				'url'              => $data['url'],
				'title'            => $data['title'],
				'meta_description' => (string) ( $data['meta_description'] ?? '' ),
				'headings'         => array_slice( array_values( array_filter( array_merge( (array) $data['h1'], (array) $data['h2'], (array) ( $data['h3'] ?? array() ) ) ) ), 0, 60 ),
				'schema_types'     => $data['schema_types'],
				// The actual page CONTENT (trimmed), so the AI compares substance,
				// not just headings.
				'content_excerpt'  => mb_substr( (string) ( $data['text_excerpt'] ?? '' ), 0, 2500 ),
				'word_count'       => str_word_count( (string) ( $data['text_excerpt'] ?? '' ) ),
			);
		}

		$reachable = array_filter( $competitors, function ( $c ) { return empty( $c['error'] ); } );
		if ( empty( $reachable ) ) {
			return new WP_Error( 'scc_unreachable', __( 'None of those competitor URLs could be fetched (blocked by robots.txt, offline, or protected).', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$our_pages = class_exists( 'SCC_Keyword_Strategy' ) ? SCC_Keyword_Strategy::existing_site_pages( 200 ) : array();

		$gaps  = $this->ai_gap_map( $reachable, $our_pages );
		if ( is_wp_error( $gaps ) ) {
			return $gaps;
		}

		// Return a lean competitor summary to the UI (drop the big excerpt).
		$competitor_summary = array_map( function ( $c ) {
			return array(
				'url'        => $c['url'] ?? '',
				'title'      => $c['title'] ?? '',
				'error'      => $c['error'] ?? '',
				'headings'   => $c['headings'] ?? array(),
				'word_count' => (int) ( $c['word_count'] ?? 0 ),
			);
		}, $competitors );

		return array(
			'competitors' => $competitor_summary,
			'gaps'        => $gaps['gaps'],
			'notes'       => $gaps['notes'],
		);
	}

	/**
	 * Ask the AI to turn competitor structure + our pages into a content map.
	 *
	 * @param array $competitors Reachable competitor data.
	 * @param array $our_pages   Our existing {title, path} pages.
	 * @return array|WP_Error {gaps:array, notes:string}
	 */
	protected function ai_gap_map( array $competitors, array $our_pages ) {
		$system = 'You are a senior SEO content strategist doing a competitive gap analysis. '
			. 'You are given (1) COMPETITORS: real competitor pages, each with its title, meta description, the '
			. 'FULL heading outline (h1/h2/h3), schema types, an approximate word count, and a CONTENT_EXCERPT of '
			. 'the actual visible page text; and (2) OUR_PAGES: the {title, path} of pages our site ALREADY has. '
			. 'READ the competitor CONTENT_EXCERPT and headings carefully. Identify the specific topics, subtopics, '
			. 'services, comparisons, buyer questions, entities, and content sections the competitors genuinely '
			. 'cover that OUR site does NOT already have a page for. Base every gap on evidence you actually see in '
			. 'the competitor content — do not guess generic SEO topics. For each real gap, propose ONE concrete '
			. 'page to create that would match or beat the competitor, and in "why" cite which competitor(s) cover '
			. 'it and what they include. NEVER propose a page that duplicates one of OUR_PAGES. Do not copy '
			. 'competitor wording — describe the page WE should build. Ignore navigation, cookie, legal and '
			. 'boilerplate headings. Return 8-20 of the highest-value gaps, best first. '
			. 'Return JSON with this exact shape: '
			. '{"gaps":[{"title":str,"primary_keyword":str,"intent":"informational|commercial|transactional|local",'
			. '"page_type":"pillar|service|location|article","recommended_url":str,"priority":"high|medium|low",'
			. '"why":str,"covered_by":[str]}],"notes":str}';

		$payload = wp_json_encode( array(
			'competitors' => $competitors,
			'our_pages'   => $our_pages,
		) );

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array( 'role' => 'user', 'content' => "Data (JSON):\n" . $payload . "\n\nProduce the content-gap map JSON now." ),
				),
				'json'        => true,
				'max_tokens'  => 3000,
				'temperature' => 0.4,
			),
			'competitor-analysis'
		);
		if ( $response->is_error() ) {
			return $response->error;
		}
		$parsed = $response->json();
		if ( ! is_array( $parsed ) || empty( $parsed['gaps'] ) ) {
			return new WP_Error( 'scc_no_gaps', __( 'The AI did not return any gaps. Try different or more competitor URLs.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$gaps = array();
		foreach ( (array) $parsed['gaps'] as $g ) {
			$title = SCC_Security::sanitize_text( $g['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$prio = strtolower( (string) ( $g['priority'] ?? 'medium' ) );
			$gaps[] = array(
				'title'           => $title,
				'primary_keyword' => SCC_Security::sanitize_text( $g['primary_keyword'] ?? '' ),
				'intent'          => SCC_Security::sanitize_text( $g['intent'] ?? '' ),
				'page_type'       => SCC_Security::sanitize_text( $g['page_type'] ?? 'article' ),
				'recommended_url' => SCC_Security::sanitize_text( $g['recommended_url'] ?? '' ),
				'priority'        => in_array( $prio, array( 'high', 'medium', 'low' ), true ) ? $prio : 'medium',
				'why'             => SCC_Security::sanitize_text( $g['why'] ?? '' ),
				'covered_by'      => array_slice( array_map( array( 'SCC_Security', 'sanitize_text' ), (array) ( $g['covered_by'] ?? array() ) ), 0, 5 ),
			);
		}

		return array(
			'gaps'  => array_slice( $gaps, 0, 30 ),
			'notes' => SCC_Security::sanitize_text( $parsed['notes'] ?? '' ),
		);
	}

	/**
	 * Analyze a single competitor URL.
	 *
	 * @param string $url Competitor URL.
	 * @return array|WP_Error
	 */
	public function analyze( $url ) {
		$crawler = new SCC_Crawler();
		$data    = $crawler->fetch( $url, true ); // robots respected.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$their_topics = $this->topics( array_merge( $data['h1'], $data['h2'] ) );
		$our_topics   = $this->our_topics();
		$gaps         = array_values( array_diff( $their_topics, $our_topics ) );

		return array(
			'url'             => $data['url'],
			'title'           => $data['title'],
			'meta_description'=> $data['meta_description'],
			'headings'        => array_slice( array_merge( $data['h1'], $data['h2'] ), 0, 40 ),
			'schema_types'    => $data['schema_types'],
			'internal_links'  => $data['internal_links'],
			'external_links'  => $data['external_links'],
			'images'          => $data['images'],
			'content_gaps'    => array_slice( $gaps, 0, 30 ),
		);
	}

	/**
	 * Topic tokens from a set of heading strings.
	 *
	 * @param array $headings Headings.
	 * @return string[]
	 */
	protected function topics( array $headings ) {
		$topics = array();
		foreach ( $headings as $h ) {
			$h = strtolower( wp_strip_all_tags( (string) $h ) );
			$h = preg_replace( '/[^a-z0-9 ]+/', ' ', $h );
			foreach ( array_filter( explode( ' ', $h ) ) as $w ) {
				if ( strlen( $w ) < 4 || in_array( $w, self::STOP_WORDS, true ) ) {
					continue;
				}
				$topics[ $w ] = true;
			}
		}
		return array_keys( $topics );
	}

	/**
	 * Topic tokens present across our own analyzed content.
	 *
	 * @return string[]
	 */
	protected function our_topics() {
		$latest = SCC_Analyzer::latest();
		if ( ! $latest || empty( $latest['items'] ) ) {
			return array();
		}
		$titles = array();
		foreach ( $latest['items'] as $item ) {
			$titles[] = $item['title'];
			if ( ! empty( $item['h1'] ) ) {
				$titles[] = $item['h1'];
			}
		}
		return $this->topics( $titles );
	}
}
