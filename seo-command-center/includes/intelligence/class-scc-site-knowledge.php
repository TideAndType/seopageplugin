<?php
/**
 * Site Knowledge Graph facade.
 *
 * Builds a compact site-aware context from the content index, entity graph,
 * internal links, brand facts and Search Console connection. It is deliberately
 * bounded and cached so one-click generation stays fast.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Site_Knowledge {

	const CACHE_KEY = 'scc_site_knowledge_v2';

	public static function snapshot( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) { return $cached; }
		}

		if ( class_exists( 'SCC_Content_Index' ) && 0 === SCC_Content_Index::count() ) {
			SCC_Content_Index::reindex_all( 300 );
		}

		$rows = class_exists( 'SCC_Content_Index' ) ? SCC_Content_Index::all( 500 ) : array();
		$pages = array();
		foreach ( (array) $rows as $row ) {
			$pages[] = array(
				'post_id'         => (int) ( $row['post_id'] ?? 0 ),
				'title'           => (string) ( $row['title'] ?? '' ),
				'url'             => (string) ( $row['url'] ?? '' ),
				'primary_keyword' => (string) ( $row['primary_keyword'] ?? '' ),
				'intent'          => (string) ( $row['intent'] ?? '' ),
			);
		}

		$entities = class_exists( 'SCC_Entity_Graph' ) ? SCC_Entity_Graph::build() : array();
		$links    = class_exists( 'SCC_Link_Graph' ) ? ( new SCC_Link_Graph() )->build( 500 ) : array();
		$brand    = class_exists( 'SCC_Brand_Brain' ) ? SCC_Brand_Brain::profile() : array();

		$data = array(
			'generated_at'  => current_time( 'mysql' ),
			'site_name'     => get_bloginfo( 'name' ),
			'home_url'      => home_url( '/' ),
			'brand'         => $brand,
			'entities'      => $entities,
			'pages'         => $pages,
			'link_totals'   => (array) ( $links['totals'] ?? array() ),
			'orphans'       => array_slice( (array) ( $links['orphans'] ?? array() ), 0, 25 ),
			'gsc_connected' => class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected(),
		);
		set_transient( self::CACHE_KEY, $data, 30 * MINUTE_IN_SECONDS );
		return $data;
	}

	public static function invalidate() {
		delete_transient( self::CACHE_KEY );
	}

	public static function candidates_for_entry( array $entry, $limit = 8 ) {
		$k = self::snapshot();
		$subject = SCC_Content_Index::tokenize(
			(string) ( $entry['title'] ?? '' ) . ' ' .
			(string) ( $entry['primary_keyword'] ?? '' ) . ' ' .
			implode( ' ', array_map( 'strval', (array) ( $entry['secondary'] ?? array() ) ) )
		);
		$scored = array();
		foreach ( (array) $k['pages'] as $p ) {
			if ( empty( $p['url'] ) || (int) ( $entry['post_id'] ?? 0 ) === (int) $p['post_id'] ) { continue; }
			$other = SCC_Content_Index::tokenize( $p['title'] . ' ' . $p['primary_keyword'] );
			$score = self::jaccard( array_keys( $subject ), array_keys( $other ) );
			if ( $score <= 0 ) { continue; }
			$p['relevance'] = round( $score, 3 );
			$scored[] = $p;
		}
		usort( $scored, function ( $a, $b ) { return $b['relevance'] <=> $a['relevance']; } );
		return array_slice( $scored, 0, max( 1, (int) $limit ) );
	}

	public static function cannibalization_risk( array $entry ) {
		$candidates = self::candidates_for_entry( $entry, 5 );
		$top = ! empty( $candidates ) ? $candidates[0] : null;
		$score = $top ? (float) $top['relevance'] : 0.0;
		$level = $score >= 0.70 ? 'high' : ( $score >= 0.45 ? 'medium' : 'low' );
		return array(
			'level'      => $level,
			'score'      => (int) round( $score * 100 ),
			'closest'    => $top,
			'candidates' => $candidates,
		);
	}

	protected static function jaccard( array $a, array $b ) {
		$a = array_values( array_unique( $a ) );
		$b = array_values( array_unique( $b ) );
		if ( empty( $a ) || empty( $b ) ) { return 0.0; }
		$i = count( array_intersect( $a, $b ) );
		$u = count( array_unique( array_merge( $a, $b ) ) );
		return $u > 0 ? $i / $u : 0.0;
	}
}
