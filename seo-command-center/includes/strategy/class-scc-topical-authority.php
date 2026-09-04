<?php
/**
 * Topical Authority scorecard.
 *
 * Turns the EXISTING data (the latest topical map from SCC_Keyword_Strategy,
 * the analyzer summary, the internal-link graph and the cannibalization
 * detector) into an explainable 0-100 topical-authority score with per-cluster
 * status and a content-opportunity rollup. Deterministic: no AI calls, no new
 * tables. This is the read-model behind the Topical Authority dashboard.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Topical Authority service.
 */
class SCC_Topical_Authority {

	/**
	 * Default score component weights (sum to 100). Filterable so the model is
	 * configurable rather than hard-coded.
	 *
	 * @return array<string,int>
	 */
	public static function weights() {
		$weights = array(
			'topic'      => 30, // How much of the mapped topic set the site actually has.
			'keyword'    => 20, // Share of mapped keywords covered by an existing page.
			'intent'     => 15, // How many distinct search intents are covered.
			'supporting' => 15, // Supporting-article (subtopic) coverage under pillars.
			'depth'      => 10, // Content depth from the analyzer (thin content drags it down).
			'links'      => 10, // Internal-link health (orphans drag it down).
		);
		/**
		 * Filter the topical-authority score weights.
		 *
		 * @param array $weights Component => weight (should sum to 100).
		 */
		return (array) apply_filters( 'scc_topical_authority_weights', $weights );
	}

	/**
	 * Build the full scorecard from live data.
	 *
	 * @return array
	 */
	public static function scorecard() {
		$strategy = SCC_Keyword_Strategy::latest();
		if ( ! $strategy || empty( $strategy['map_data'] ) || empty( $strategy['map_data']['clusters'] ) ) {
			return array( 'has_map' => false );
		}
		$map = $strategy['map_data'];

		// Pull signals from the existing engines (no duplication).
		$signals = array(
			'cannibalization'   => 0,
			'link_pages'        => 0,
			'link_orphans'      => 0,
			'link_opportunities'=> 0,
			'depth_analyzed'    => 0,
			'depth_thin'        => 0,
		);

		if ( class_exists( 'SCC_Cannibalization' ) ) {
			$detector = new SCC_Cannibalization();
			$groups   = $detector->detect();
			$signals['cannibalization'] = is_array( $groups ) ? count( $groups ) : 0;
		}

		if ( class_exists( 'SCC_Link_Graph' ) ) {
			$graph  = new SCC_Link_Graph();
			$built  = $graph->build( 500 );
			$totals = isset( $built['totals'] ) ? $built['totals'] : array();
			$signals['link_pages']   = (int) ( $totals['pages'] ?? 0 );
			$signals['link_orphans'] = (int) ( $totals['orphans'] ?? 0 );
		}
		if ( class_exists( 'SCC_Link_Engine' ) ) {
			$recs = SCC_Link_Engine::recommendations( array( 'min_confidence' => 0, 'limit' => 500 ) );
			$signals['link_opportunities'] = is_array( $recs ) ? count( $recs ) : 0;
		}

		if ( class_exists( 'SCC_Analyzer' ) ) {
			$latest = SCC_Analyzer::latest();
			$totals = ( $latest && ! empty( $latest['summary_data']['totals'] ) ) ? $latest['summary_data']['totals'] : array();
			$signals['depth_analyzed'] = (int) ( $totals['analyzed'] ?? 0 );
			$signals['depth_thin']     = (int) ( $totals['thin_content'] ?? 0 );
		}

		// GSC quick-win queries boost opportunity priority.
		$quick = array();
		foreach ( (array) ( $map['gsc_quick_wins'] ?? array() ) as $w ) {
			$q = strtolower( trim( (string) ( $w['query'] ?? '' ) ) );
			if ( '' !== $q ) {
				$quick[ $q ] = true;
			}
		}

		$card                = self::compute( $map, $signals, $quick );
		$card['has_map']     = true;
		$card['strategy_id'] = isset( $strategy['id'] ) ? (int) $strategy['id'] : 0;
		$card['generated_by']    = isset( $map['generated_by'] ) ? (string) $map['generated_by'] : '';
		return $card;
	}

	/**
	 * Pure scoring from a normalized map + engine signals. Unit-testable.
	 *
	 * @param array $map     Topical map (clusters[], each with subtopics[]).
	 * @param array $signals Engine signals (see scorecard()).
	 * @param array $quick   Map of lowercased quick-win queries => true.
	 * @return array
	 */
	public static function compute( array $map, array $signals = array(), array $quick = array() ) {
		$clusters = isset( $map['clusters'] ) ? (array) $map['clusters'] : array();

		$total_topics   = 0;
		$existing_topics= 0;
		$total_subs     = 0;
		$existing_subs  = 0;
		$total_kw       = 0;
		$covered_kw     = 0;
		$intents_all    = array();
		$intents_cov    = array();

		$cluster_rows   = array();
		$opps           = array( 'high' => 0, 'medium' => 0, 'low' => 0, 'items' => array() );

		$is_existing = function ( $node ) {
			return isset( $node['status'] ) && 'existing' === $node['status'];
		};

		foreach ( $clusters as $c ) {
			$c_exists = $is_existing( $c );
			$total_topics++;
			if ( $c_exists ) {
				$existing_topics++;
			}

			// Keywords for the pillar: primary + supporting terms.
			$kw = 1 + count( (array) ( $c['supporting_terms'] ?? array() ) );
			$total_kw += $kw;
			if ( $c_exists ) {
				$covered_kw += $kw;
			}

			$intent = strtolower( (string) ( $c['intent'] ?? '' ) );
			if ( '' !== $intent ) {
				$intents_all[ $intent ] = true;
				if ( $c_exists ) {
					$intents_cov[ $intent ] = true;
				}
			}

			$c_total_subs    = 0;
			$c_existing_subs = 0;
			foreach ( (array) ( $c['subtopics'] ?? array() ) as $s ) {
				$s_exists = $is_existing( $s );
				$total_topics++;
				$total_subs++;
				$c_total_subs++;
				$total_kw++; // subtopic primary keyword
				$si = strtolower( (string) ( $s['intent'] ?? '' ) );
				if ( '' !== $si ) {
					$intents_all[ $si ] = true;
				}
				if ( $s_exists ) {
					$existing_topics++;
					$existing_subs++;
					$c_existing_subs++;
					$covered_kw++;
					if ( '' !== $si ) {
						$intents_cov[ $si ] = true;
					}
				} else {
					// New subtopic = a content opportunity.
					$q  = strtolower( trim( (string) ( $s['primary_keyword'] ?? $s['title'] ?? '' ) ) );
					$pr = isset( $quick[ $q ] ) ? 'high' : 'medium';
					$opps[ $pr ]++;
					$opps['items'][] = array(
						'title'    => (string) ( $s['title'] ?? '' ),
						'url'      => (string) ( $s['recommended_url'] ?? '' ),
						'intent'   => $si,
						'priority' => $pr,
						'pillar'   => (string) ( $c['service'] ?? '' ),
					);
				}
			}

			// Cluster coverage: pillar counts as one node alongside its subtopics.
			$cl_total = 1 + $c_total_subs;
			$cl_have  = ( $c_exists ? 1 : 0 ) + $c_existing_subs;
			$cl_score = $cl_total > 0 ? (int) round( 100 * $cl_have / $cl_total ) : 0;

			if ( ! $c_exists ) {
				$status = 'missing';
			} elseif ( $c_total_subs > 0 && $c_existing_subs < $c_total_subs ) {
				$status = 'attention';
			} else {
				$status = 'strong';
			}

			$cluster_rows[] = array(
				'name'          => (string) ( $c['service'] ?? ( $c['primary_keyword'] ?? '' ) ),
				'score'         => $cl_score,
				'status'        => $status,
				'existing_subs' => $c_existing_subs,
				'new_subs'      => $c_total_subs - $c_existing_subs,
				'priority'      => (string) ( $c['priority'] ?? 'medium' ),
				'exists'        => $c_exists,
				'url'           => (string) ( $c['recommended_url'] ?? '' ),
			);

			// A brand-new pillar is itself a high-value opportunity.
			if ( ! $c_exists ) {
				$pr = (string) ( $c['priority'] ?? 'medium' );
				$pr = in_array( $pr, array( 'high', 'medium', 'low' ), true ) ? $pr : 'medium';
				$opps[ $pr ]++;
				$opps['items'][] = array(
					'title'    => (string) ( $c['service'] ?? '' ),
					'url'      => (string) ( $c['recommended_url'] ?? '' ),
					'intent'   => $intent,
					'priority' => $pr,
					'pillar'   => (string) ( $c['service'] ?? '' ),
				);
			}
		}

		// --- Component percentages (0-100) --------------------------------
		$pct = function ( $have, $total ) {
			return $total > 0 ? (int) round( 100 * $have / $total ) : 0;
		};

		$topic_pct      = $pct( $existing_topics, $total_topics );
		$keyword_pct    = $pct( $covered_kw, $total_kw );
		$intent_pct     = $pct( count( $intents_cov ), max( 1, count( $intents_all ) ) );
		$supporting_pct = $total_subs > 0 ? $pct( $existing_subs, $total_subs ) : $topic_pct;

		// Depth from the analyzer: non-thin share of analyzed pages.
		$analyzed = (int) ( $signals['depth_analyzed'] ?? 0 );
		$thin     = (int) ( $signals['depth_thin'] ?? 0 );
		$depth_known = $analyzed > 0;
		$depth_pct   = $depth_known ? $pct( max( 0, $analyzed - $thin ), $analyzed ) : 0;

		// Internal-link health: non-orphan share of pages in the graph.
		$lpages   = (int) ( $signals['link_pages'] ?? 0 );
		$lorphans = (int) ( $signals['link_orphans'] ?? 0 );
		$links_known = $lpages > 0;
		$links_pct   = $links_known ? $pct( max( 0, $lpages - $lorphans ), $lpages ) : 0;

		$parts = array(
			'topic'      => array( 'label' => __( 'Topic coverage', 'seo-command-center' ),         'pct' => $topic_pct,      'known' => true ),
			'keyword'    => array( 'label' => __( 'Keyword coverage', 'seo-command-center' ),        'pct' => $keyword_pct,    'known' => true ),
			'intent'     => array( 'label' => __( 'Search intent coverage', 'seo-command-center' ),  'pct' => $intent_pct,     'known' => true ),
			'supporting' => array( 'label' => __( 'Supporting content', 'seo-command-center' ),      'pct' => $supporting_pct, 'known' => true ),
			'depth'      => array( 'label' => __( 'Content depth', 'seo-command-center' ),            'pct' => $depth_pct,      'known' => $depth_known ),
			'links'      => array( 'label' => __( 'Internal linking', 'seo-command-center' ),         'pct' => $links_pct,      'known' => $links_known ),
		);

		// Weighted overall. Unknown components are excluded and the remaining
		// weights are renormalized, so a missing analysis never tanks the score.
		$weights   = self::weights();
		$num       = 0.0;
		$den       = 0.0;
		$components = array();
		foreach ( $parts as $key => $p ) {
			$w = (int) ( $weights[ $key ] ?? 0 );
			if ( $p['known'] ) {
				$num += $p['pct'] * $w;
				$den += $w;
			}
			$components[] = array(
				'key'    => $key,
				'label'  => $p['label'],
				'pct'    => (int) $p['pct'],
				'weight' => $w,
				'known'  => (bool) $p['known'],
			);
		}
		$score = $den > 0 ? (int) round( $num / $den ) : 0;

		// Sort opportunities: high → medium → low.
		$rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
		usort( $opps['items'], function ( $a, $b ) use ( $rank ) {
			return ( $rank[ $a['priority'] ] ?? 3 ) <=> ( $rank[ $b['priority'] ] ?? 3 );
		} );

		return array(
			'score'      => $score,
			'components' => $components,
			'clusters'   => $cluster_rows,
			'opportunities' => array(
				'high'   => $opps['high'],
				'medium' => $opps['medium'],
				'low'    => $opps['low'],
				'items'  => array_slice( $opps['items'], 0, 200 ),
			),
			'totals'     => array(
				'topics'             => $total_topics,
				'keywords'           => $total_kw,
				'covered_keywords'   => $covered_kw,
				'missing_keywords'   => max( 0, $total_kw - $covered_kw ),
				'existing_topics'    => $existing_topics,
				'missing_topics'     => max( 0, $total_topics - $existing_topics ),
				'clusters'           => count( $cluster_rows ),
				'clusters_strong'    => count( array_filter( $cluster_rows, function ( $c ) { return 'strong' === $c['status']; } ) ),
				'clusters_attention' => count( array_filter( $cluster_rows, function ( $c ) { return 'attention' === $c['status']; } ) ),
				'clusters_missing'   => count( array_filter( $cluster_rows, function ( $c ) { return 'missing' === $c['status']; } ) ),
				'cannibalization'    => (int) ( $signals['cannibalization'] ?? 0 ),
				'link_opportunities' => (int) ( $signals['link_opportunities'] ?? 0 ),
			),
		);
	}
}
