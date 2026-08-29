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
