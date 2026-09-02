<?php
/**
 * SEO Opportunity Engine — the unified intelligence layer.
 *
 * This does NOT re-implement any analysis. It ORCHESTRATES the plugin's existing
 * systems (GSC signals, Topical Authority scorecard, cannibalization, the link
 * graph, and the latest site analysis) into a single ranked list of the
 * highest-value SEO actions, each with a TRANSPARENT score (explainable factor
 * breakdown), a confidence, and an explicit data-availability state.
 *
 * Core principles enforced here:
 *  - Never fabricate metrics. If GSC/DataForSEO is not connected, the relevant
 *    factors are simply absent and the opportunity is marked "estimated" or
 *    "partial" rather than invented.
 *  - The score is a sum of explainable factor points (label + points), not an
 *    opaque weighted average.
 *  - Read-model: results are computed from already-stored artifacts and cached,
 *    so this never runs a heavy crawl inside a page request.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opportunity engine.
 */
class SCC_Opportunity_Engine {

	const CACHE_KEY = 'scc_opportunities_v1';
	const CACHE_TTL = HOUR_IN_SECONDS;

	// Data-availability states.
	const DATA_VERIFIED    = 'verified';
	const DATA_ESTIMATED   = 'estimated';
	const DATA_PARTIAL     = 'partial';
	const DATA_UNAVAILABLE = 'unavailable';

	/**
	 * All opportunities, newest computation first, ranked by score.
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array
	 */
	public static function all( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$opps = self::compute( self::collect() );
		set_transient( self::CACHE_KEY, $opps, self::CACHE_TTL );
		return $opps;
	}

	/**
	 * The top N opportunities.
	 *
	 * @param int  $n       Count.
	 * @param bool $refresh Bypass the cache.
	 * @return array
	 */
	public static function top( $n = 5, $refresh = false ) {
		return array_slice( self::all( $refresh ), 0, max( 1, (int) $n ) );
	}

	/**
	 * Gather raw signals from the existing systems (guarded — a missing system
	 * or missing external data yields empty signals, never fabricated ones).
	 *
	 * @return array
	 */
	public static function collect() {
		$signals = array(
			'gsc'         => array( 'connected' => false, 'quick_wins' => array(), 'untapped' => array() ),
			'topical'     => array( 'has_map' => false, 'items' => array() ),
			'cannibal'    => array(),
			'orphans'     => array(),
			'thin'        => array(),
			'missing_meta'=> array(),
		);

		// Real Search Console demand.
		if ( class_exists( 'SCC_Keyword_Strategy' ) ) {
			$gsc = SCC_Keyword_Strategy::gsc_signals();
			if ( is_array( $gsc ) ) {
				$signals['gsc'] = array(
					'connected'  => ! empty( $gsc['connected'] ),
					'quick_wins' => (array) ( $gsc['quick_wins'] ?? array() ),
					'untapped'   => (array) ( $gsc['untapped'] ?? array() ),
				);
			}
		}

		// Topical authority gap opportunities (AI strategic, already scored).
		if ( class_exists( 'SCC_Topical_Authority' ) ) {
			$card = SCC_Topical_Authority::scorecard();
			if ( is_array( $card ) && ! empty( $card['has_map'] ) ) {
				$signals['topical'] = array(
					'has_map' => true,
					'items'   => (array) ( $card['opportunities']['items'] ?? array() ),
				);
			}
		}

		// Cannibalization groups.
		if ( class_exists( 'SCC_Cannibalization' ) ) {
			$detector = new SCC_Cannibalization();
			$groups   = $detector->detect();
			$signals['cannibal'] = is_array( $groups ) ? $groups : array();
		}

		// Orphan / under-linked pages.
		if ( class_exists( 'SCC_Link_Graph' ) ) {
			$graph = new SCC_Link_Graph();
			$built = $graph->build( 500 );
			if ( is_array( $built ) ) {
				$signals['orphans'] = (array) ( $built['orphans'] ?? array() );
			}
		}

		// Thin content + missing metadata from the latest analysis.
		if ( class_exists( 'SCC_Analyzer' ) ) {
			$latest = SCC_Analyzer::latest();
			if ( is_array( $latest ) && ! empty( $latest['items'] ) ) {
				foreach ( $latest['items'] as $item ) {
					$flags = $item['flags'] ?? array();
					if ( is_string( $flags ) ) {
						$decoded = json_decode( $flags, true );
						$flags   = is_array( $decoded ) ? $decoded : array();
					}
					$row = array(
						'post_id'    => (int) ( $item['post_id'] ?? 0 ),
						'title'      => (string) ( $item['title'] ?? '' ),
						'url'        => (string) ( $item['url'] ?? '' ),
						'word_count' => (int) ( $item['word_count'] ?? 0 ),
					);
					if ( in_array( 'thin_content', (array) $flags, true ) ) {
						$signals['thin'][] = $row;
					}
					if ( in_array( 'missing_meta', (array) $flags, true ) ) {
						$signals['missing_meta'][] = $row;
					}
				}
			}
		}

		return $signals;
	}

	/**
	 * Turn collected signals into ranked, explained opportunity objects.
	 *
	 * Pure function of its input — unit-tested directly. No DB or external calls.
	 *
	 * @param array $signals Signals from collect().
	 * @return array
	 */
	public static function compute( array $signals ) {
		$opps = array();

		$gsc_connected = ! empty( $signals['gsc']['connected'] );

		// 1) Striking-distance queries (GSC): real impressions, ranking 4-20.
		foreach ( (array) ( $signals['gsc']['quick_wins'] ?? array() ) as $q ) {
			$query = (string) ( $q['query'] ?? '' );
			if ( '' === $query ) {
				continue;
			}
			$impr = (int) ( $q['impressions'] ?? 0 );
			$pos  = (float) ( $q['position'] ?? 0 );

			$factors = array();
			$factors[] = self::factor( 'Already ranking on page 1–2 (position ' . round( $pos, 1 ) . ')', $pos > 0 && $pos <= 10 ? 22 : 16 );
			$factors[] = self::factor( self::fmt_int( $impr ) . ' monthly impressions', min( 20, (int) round( $impr / 250 ) + 4 ) );
			$factors[] = self::factor( 'Small ranking gap to close', 12 );

			$opps[] = self::build( array(
				'type'            => 'striking_distance',
				'title'           => sprintf( 'Improve the page ranking for “%s”', $query ),
				'target'          => array( 'query' => $query ),
				'factors'         => $factors,
				'data_confidence' => self::DATA_VERIFIED,
				'metrics'         => array( 'impressions' => $impr, 'position' => round( $pos, 1 ), 'clicks' => (int) ( $q['clicks'] ?? 0 ) ),
				'reason'          => sprintf( 'Search Console shows %s impressions/month for “%s” at position %s — a small push can win real clicks.', self::fmt_int( $impr ), $query, round( $pos, 1 ) ),
				'expected_impact' => $impr >= 500 ? 'high' : 'medium',
				'effort'          => 'M',
				'risk'            => 'low',
				'action_type'     => 'optimize_page',
				'recommended_action' => 'Improve title/meta for CTR and expand the section that targets this query.',
				'source'          => 'gsc',
			) );
		}

		// 2) Untapped demand (GSC): impressions, no page winning them.
		foreach ( (array) ( $signals['gsc']['untapped'] ?? array() ) as $q ) {
			$query = (string) ( $q['query'] ?? '' );
			if ( '' === $query ) {
				continue;
			}
			$impr = (int) ( $q['impressions'] ?? 0 );
			$factors = array(
				self::factor( self::fmt_int( $impr ) . ' impressions the site does not win', min( 22, (int) round( $impr / 200 ) + 6 ) ),
				self::factor( 'No dedicated page for this demand', 16 ),
			);
			$opps[] = self::build( array(
				'type'            => 'untapped_demand',
				'title'           => sprintf( 'Create or expand a page for “%s”', $query ),
				'target'          => array( 'query' => $query ),
				'factors'         => $factors,
				'data_confidence' => self::DATA_VERIFIED,
				'metrics'         => array( 'impressions' => $impr, 'position' => round( (float) ( $q['position'] ?? 0 ), 1 ) ),
				'reason'          => sprintf( 'Search Console shows %s impressions for “%s” but the site does not win the clicks — real demand with no strong page.', self::fmt_int( $impr ), $query ),
				'expected_impact' => 'medium',
				'effort'          => 'L',
				'risk'            => 'low',
				'action_type'     => 'create_page',
				'recommended_action' => 'Create a focused page that directly answers this query.',
				'source'          => 'gsc',
			) );
		}

		// 3) Topical authority gaps (AI strategic opinion — estimated, not measured).
		foreach ( (array) ( $signals['topical']['items'] ?? array() ) as $it ) {
			$title = (string) ( $it['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$prio  = strtolower( (string) ( $it['priority'] ?? 'medium' ) );
			$pts   = 'high' === $prio ? 20 : ( 'low' === $prio ? 8 : 14 );
			$factors = array(
				self::factor( 'Fills a gap in your topical map', $pts ),
				self::factor( 'Strengthens the “' . (string) ( $it['pillar'] ?? '' ) . '” cluster', 10 ),
			);
			$intent = strtolower( (string) ( $it['intent'] ?? '' ) );
			if ( in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ) {
				$factors[] = self::factor( 'Commercial intent', 10 );
			}
			$opps[] = self::build( array(
				'type'            => 'missing_topic',
				'title'           => sprintf( 'Create page: %s', $title ),
				'target'          => array( 'topic' => $title, 'url' => (string) ( $it['url'] ?? '' ), 'pillar' => (string) ( $it['pillar'] ?? '' ) ),
				'factors'         => $factors,
				'data_confidence' => $gsc_connected ? self::DATA_PARTIAL : self::DATA_ESTIMATED,
				'metrics'         => array(),
				'reason'          => sprintf( 'Your topical map has no page for “%s” under %s. Covering it builds authority for the cluster.', $title, (string) ( $it['pillar'] ?? 'this pillar' ) ),
				'expected_impact' => 'high' === $prio ? 'high' : 'medium',
				'effort'          => 'L',
				'risk'            => 'low',
				'action_type'     => 'create_page',
				'recommended_action' => 'Add this page to the Content Plan and generate a draft.',
				'source'          => 'topical_authority',
			) );
		}

		// 4) Cannibalization (measured from the latest analysis).
		foreach ( (array) ( $signals['cannibal'] ?? array() ) as $group ) {
			$pages = (array) ( $group['pages'] ?? array() );
			if ( count( $pages ) < 2 ) {
				continue;
			}
			$kw = (string) ( $group['keyword'] ?? ( $group['topic'] ?? '' ) );
			$label = '' !== $kw ? $kw : ( $pages[0]['title'] ?? 'this topic' );
			$factors = array(
				self::factor( count( $pages ) . ' pages competing for the same intent', 16 ),
				self::factor( 'Consolidating focuses ranking signals', 12 ),
			);
			$opps[] = self::build( array(
				'type'            => 'fix_cannibalization',
				'title'           => sprintf( 'Resolve cannibalization for “%s”', $label ),
				'target'          => array( 'keyword' => $kw, 'pages' => array_map( function ( $p ) {
					return array( 'post_id' => (int) ( $p['post_id'] ?? 0 ), 'url' => (string) ( $p['url'] ?? '' ), 'title' => (string) ( $p['title'] ?? '' ) );
				}, $pages ) ),
				'factors'         => $factors,
				'data_confidence' => self::DATA_VERIFIED,
				'metrics'         => array( 'competing_pages' => count( $pages ) ),
				'reason'          => sprintf( '%d pages target “%s”, splitting ranking signals. Consolidating or differentiating them concentrates authority on one URL.', count( $pages ), $label ),
				'expected_impact' => 'medium',
				'effort'          => 'M',
				'risk'            => 'medium',
				'action_type'     => 'review',
				'recommended_action' => 'Review the group and pick a primary URL; add internal links to it and differentiate the others.',
				'source'          => 'cannibalization',
			) );
		}

		// 5) Orphan pages (measured from the link graph).
		$orphans = (array) ( $signals['orphans'] ?? array() );
		if ( count( $orphans ) > 0 ) {
			foreach ( array_slice( $orphans, 0, 15 ) as $node ) {
				$factors = array(
					self::factor( 'Page has no internal links pointing to it', 16 ),
					self::factor( 'Internal links pass relevance + crawl equity', 10 ),
				);
				$opps[] = self::build( array(
					'type'            => 'fix_orphan',
					'title'           => sprintf( 'Add internal links to: %s', (string) ( $node['title'] ?? $node['url'] ?? '' ) ),
					'target'          => array( 'post_id' => (int) ( $node['post_id'] ?? 0 ), 'url' => (string) ( $node['url'] ?? '' ) ),
					'factors'         => $factors,
					'data_confidence' => self::DATA_VERIFIED,
					'metrics'         => array( 'inbound_links' => 0 ),
					'reason'          => 'This page has no internal links pointing to it, so search engines and readers struggle to find it. Adding relevant internal links helps it rank.',
					'expected_impact' => 'medium',
					'effort'          => 'S',
					'risk'            => 'low',
					'action_type'     => 'add_internal_links',
					'recommended_action' => 'Insert relevant internal links from related pages (safe, reversible).',
					'source'          => 'link_graph',
				) );
			}
		}

		// 6) Thin content (measured).
		foreach ( array_slice( (array) ( $signals['thin'] ?? array() ), 0, 15 ) as $row ) {
			$wc = (int) ( $row['word_count'] ?? 0 );
			$factors = array(
				self::factor( 'Thin content (' . self::fmt_int( $wc ) . ' words)', 14 ),
				self::factor( 'Depth improves relevance + rankings', 10 ),
			);
			$opps[] = self::build( array(
				'type'            => 'expand_content',
				'title'           => sprintf( 'Expand thin page: %s', (string) ( $row['title'] ?? $row['url'] ?? '' ) ),
				'target'          => array( 'post_id' => (int) ( $row['post_id'] ?? 0 ), 'url' => (string) ( $row['url'] ?? '' ) ),
				'factors'         => $factors,
				'data_confidence' => self::DATA_VERIFIED,
				'metrics'         => array( 'word_count' => $wc ),
				'reason'          => sprintf( 'This page has only %s words. Expanding it with genuinely useful depth improves topical relevance.', self::fmt_int( $wc ) ),
				'expected_impact' => 'medium',
				'effort'          => 'M',
				'risk'            => 'low',
				'action_type'     => 'expand_content',
				'recommended_action' => 'Expand with sections, FAQs and examples that match search intent.',
				'source'          => 'analyzer',
			) );
		}

		// 7) Missing metadata (measured).
		foreach ( array_slice( (array) ( $signals['missing_meta'] ?? array() ), 0, 15 ) as $row ) {
			$factors = array(
				self::factor( 'Missing meta title or description', 12 ),
				self::factor( 'Metadata drives click-through from search', 8 ),
			);
			$opps[] = self::build( array(
				'type'            => 'improve_meta',
				'title'           => sprintf( 'Add metadata: %s', (string) ( $row['title'] ?? $row['url'] ?? '' ) ),
				'target'          => array( 'post_id' => (int) ( $row['post_id'] ?? 0 ), 'url' => (string) ( $row['url'] ?? '' ) ),
				'factors'         => $factors,
				'data_confidence' => self::DATA_VERIFIED,
				'metrics'         => array(),
				'reason'          => 'This page is missing a meta title or description, so Google writes its own snippet. Adding optimized metadata improves click-through.',
				'expected_impact' => 'low',
				'effort'          => 'S',
				'risk'            => 'low',
				'action_type'     => 'improve_meta',
				'recommended_action' => 'Generate and apply an optimized meta title + description.',
				'source'          => 'analyzer',
			) );
		}

		// Rank by score desc, then by expected impact.
		usort( $opps, function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		return $opps;
	}

	/**
	 * Finalize an opportunity: compute score from factors + confidence + id.
	 *
	 * @param array $o Partial opportunity.
	 * @return array
	 */
	protected static function build( array $o ) {
		$factors = (array) ( $o['factors'] ?? array() );
		$score   = 0;
		foreach ( $factors as $f ) {
			$score += (int) ( $f['points'] ?? 0 );
		}
		$score = max( 0, min( 100, $score ) );

		$o['factors'] = $factors;
		$o['score']   = $score;
		$o['priority']= $score >= 70 ? 'high' : ( $score >= 45 ? 'medium' : 'low' );

		// Confidence from data-availability + number of contributing factors.
		$conf_base = array(
			self::DATA_VERIFIED    => 90,
			self::DATA_PARTIAL     => 70,
			self::DATA_ESTIMATED   => 55,
			self::DATA_UNAVAILABLE => 30,
		);
		$dc = $o['data_confidence'] ?? self::DATA_ESTIMATED;
		$o['confidence'] = max( 0, min( 100, ( $conf_base[ $dc ] ?? 55 ) + min( 8, count( $factors ) * 2 ) - 4 ) );

		// Stable id so the same opportunity keeps identity across refreshes and
		// can be de-duplicated when promoted into the action queue.
		$target_key = wp_json_encode( $o['target'] ?? array() );
		$o['id']    = 'op_' . substr( md5( ( $o['type'] ?? '' ) . '|' . $target_key ), 0, 16 );

		return $o;
	}

	/**
	 * Build a scoring factor.
	 *
	 * @param string $label  Human label.
	 * @param int    $points Contribution.
	 * @return array
	 */
	protected static function factor( $label, $points ) {
		return array( 'label' => (string) $label, 'points' => (int) $points );
	}

	/**
	 * Locale-friendly integer.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	protected static function fmt_int( $n ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( (int) $n ) : (string) (int) $n;
	}

	/**
	 * Bust the cache (call after site changes that affect opportunities).
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
