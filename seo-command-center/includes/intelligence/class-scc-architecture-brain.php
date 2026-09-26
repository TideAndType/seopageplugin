<?php
/**
 * SEO Architecture Brain.
 *
 * Turns the structural tree into evidence-backed decisions using real page
 * content, internal site knowledge, Search Console query/page associations, and
 * conservative duplicate-intent detection.
 *
 * This class never performs destructive redirects/merges automatically. It can
 * persist user architecture preferences and promote reviewable work into the
 * Action Queue / Content Plan.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Architecture_Brain {

	const REPORT_OPTION    = 'scc_architecture_brain_report';
	const OVERRIDES_OPTION = 'scc_architecture_overrides';

	/**
	 * Analyze an architecture tree.
	 *
	 * @param array      $tree    Base SCC_Architecture tree.
	 * @param array|null $context Optional injected context for tests.
	 * @param array|null $overrides Optional injected overrides.
	 * @return array
	 */
	public function analyze( array $tree, $context = null, $overrides = null ) {
		$runtime = null === $context;
		if ( null === $context ) {
			$context = $this->context();
		}
		if ( null === $overrides ) {
			$overrides = self::overrides();
		}

		$tree = self::normalize_service_hierarchy( $tree );
		$tree = $this->enrich_tree( $tree, $context, $overrides );
		$tree = $this->apply_parent_overrides( $tree, $overrides );

		$consolidation = self::consolidation_plan(
			(array) ( $context['index'] ?? array() ),
			(array) ( $context['gsc'] ?? array() )
		);
		$health = self::health( $tree, $consolidation, (array) ( $context['technical'] ?? array() ) );

		$report = array(
			'tree'          => $tree,
			'health'        => $health,
			'consolidation' => $consolidation,
			'gsc_available' => ! empty( $context['gsc_available'] ),
			'generated_at'  => current_time( 'mysql' ),
			'disclaimer'    => __( 'Architecture Health is a TideOrbit diagnostic of structure, coverage and overlap. It is not a Google ranking score.', 'seo-command-center' ),
		);

		if ( $runtime ) {
			update_option( self::REPORT_OPTION, $report, false );
		}
		return $report;
	}

	/**
	 * Latest saved report.
	 *
	 * @return array|null
	 */
	public static function report() {
		$r = get_option( self::REPORT_OPTION, null );
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Gather real-site context.
	 *
	 * @return array
	 */
	protected function context() {
		if ( class_exists( 'SCC_Content_Index' ) && 0 === SCC_Content_Index::count() && class_exists( 'WP_Query' ) ) {
			SCC_Content_Index::reindex_all( 1000 );
		}

		$index = class_exists( 'SCC_Content_Index' ) ? SCC_Content_Index::all( 2000 ) : array();
		$gsc   = array();
		$gsc_available = false;
		if ( class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected() && class_exists( 'SCC_GSC_Learning' ) ) {
			$gsc = SCC_GSC_Learning::page_query_map( 90 );
			$gsc_available = true;
		}

		return array(
			'index'         => $index,
			'gsc'           => $gsc,
			'gsc_available' => $gsc_available,
			'technical'     => class_exists( 'SCC_Technical_SEO' ) ? ( SCC_Technical_SEO::report() ?: array() ) : array(),
		);
	}

	/**
	 * Nest service pillars under a real ancestor service hub when their URL
	 * structure proves the relationship. This prevents nested services from being
	 * rendered as unrelated top-level pillars.
	 *
	 * Example:
	 * /managed-it-services/
	 * /managed-it-services/24-7-monitoring-alerting/
	 *
	 * The second URL becomes a child of the first. Its sections/articles remain
	 * attached to the parent cluster so the architecture does not lose work.
	 *
	 * @param array $tree Base architecture tree.
	 * @return array
	 */
	public static function normalize_service_hierarchy( array $tree ) {
		$pillars = array_values( (array) ( $tree['pillars'] ?? array() ) );
		$path_to_index = array();
		foreach ( $pillars as $i => $pillar ) {
			$path = self::normalize_path( $pillar['url'] ?? '' );
			if ( '' !== $path ) {
				$path_to_index[ $path ] = $i;
			}
		}

		$moves = array();
		foreach ( $pillars as $i => $pillar ) {
			$path = self::normalize_path( $pillar['url'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$parts = array_values( array_filter( explode( '/', $path ) ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			// Find the nearest existing ancestor pillar, not merely the first segment.
			for ( $depth = count( $parts ) - 1; $depth >= 1; $depth-- ) {
				$parent_path = implode( '/', array_slice( $parts, 0, $depth ) );
				if ( isset( $path_to_index[ $parent_path ] ) && $path_to_index[ $parent_path ] !== $i ) {
					$intent = strtolower( (string) ( $pillar['intent'] ?? '' ) );
					$type   = (string) ( $pillar['page_type'] ?? '' );
					if ( in_array( $intent, array( 'commercial', 'transactional', 'local', 'navigational' ), true ) || in_array( $type, array( 'service', 'location', 'pillar' ), true ) ) {
						$moves[] = array( 'from' => $i, 'to' => $path_to_index[ $parent_path ], 'parent_path' => $parent_path );
					}
					break;
				}
			}
		}

		if ( empty( $moves ) ) {
			$tree['pillars'] = $pillars;
			return $tree;
		}

		// Deepest nodes move first so a child-of-child is attached before its
		// parent is copied into the next ancestor.
		usort(
			$moves,
			function ( $a, $b ) {
				return substr_count( $b['parent_path'], '/' ) <=> substr_count( $a['parent_path'], '/' );
			}
		);

		$remove = array();
		foreach ( $moves as $move ) {
			$child = $pillars[ $move['from'] ];
			$child['parent_url'] = '/' . $move['parent_path'] . '/';
			$child['children'] = (array) ( $child['children'] ?? array() );

			$parent =& $pillars[ $move['to'] ];
			$parent['children'][] = $child;
			unset( $parent );
			$remove[ $move['from'] ] = true;
		}

		$out = array();
		foreach ( $pillars as $i => $pillar ) {
			if ( ! isset( $remove[ $i ] ) ) {
				$out[] = $pillar;
			}
		}
		$tree['pillars'] = array_values( $out );
		return $tree;
	}

	/**
	 * Enrich every node with coverage, GSC evidence, post id and a clear decision.
	 *
	 * @param array $tree Tree.
	 * @param array $context Context.
	 * @param array $overrides User overrides.
	 * @return array
	 */
	protected function enrich_tree( array $tree, array $context, array $overrides ) {
		$index_by_path = array();
		foreach ( (array) ( $context['index'] ?? array() ) as $row ) {
			$path = self::normalize_path( $row['url'] ?? '' );
			if ( '' !== $path ) {
				$index_by_path[ $path ] = $row;
			}
		}

		$out = array();
		foreach ( (array) ( $tree['pillars'] ?? array() ) as $pillar ) {
			$out[] = $this->enrich_branch( $pillar, $context, $index_by_path, $overrides, true );
		}
		$tree['pillars'] = $out;
		return $tree;
	}

	/**
	 * Recursively enrich a service/page branch.
	 */
	protected function enrich_branch( array $node, array $context, array $index_by_path, array $overrides, $is_pillar = false ) {
		$node = $this->enrich_node( $node, $context, $index_by_path, $overrides, $is_pillar );

		$children = array();
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			$children[] = $this->enrich_branch( $child, $context, $index_by_path, $overrides, false );
		}
		$node['children'] = $children;

		foreach ( array( 'sections', 'articles' ) as $bucket ) {
			$out = array();
			foreach ( (array) ( $node[ $bucket ] ?? array() ) as $item ) {
				$out[] = $this->enrich_node( $item, $context, $index_by_path, $overrides, false );
			}
			$node[ $bucket ] = $out;
		}
		return $node;
	}

	/**
	 * Enrich one node.
	 *
	 * @param array $node Node.
	 * @param array $context Context.
	 * @param array $index_by_path Index.
	 * @param array $overrides Overrides.
	 * @param bool  $is_pillar Pillar.
	 * @return array
	 */
	protected function enrich_node( array $node, array $context, array $index_by_path, array $overrides, $is_pillar ) {
		$node['node_id'] = self::node_id( $node );
		$node['is_pillar'] = (bool) $is_pillar;

		$path = self::normalize_path( $node['url'] ?? '' );
		$row  = isset( $index_by_path[ $path ] ) ? $index_by_path[ $path ] : null;

		if ( ! $row && ! empty( $node['parent_url'] ) ) {
			$parent_path = self::normalize_path( $node['parent_url'] );
			$row = isset( $index_by_path[ $parent_path ] ) ? $index_by_path[ $parent_path ] : null;
		}

		if ( $row ) {
			$node['post_id'] = (int) ( $row['post_id'] ?? 0 );
			$node['edit_url'] = $node['post_id'] && function_exists( 'get_edit_post_link' )
				? (string) get_edit_post_link( $node['post_id'], '' )
				: '';
			$node['coverage'] = self::coverage( $node, $row );
		} else {
			$node['post_id']  = 0;
			$node['edit_url'] = '';
			$node['coverage'] = array(
				'available' => false,
				'score'     => null,
				'level'     => 'unknown',
				'missing'   => array(),
				'covered'   => array(),
			);
		}

		$node['gsc'] = self::gsc_evidence_for_node( $node, (array) ( $context['gsc'] ?? array() ) );

		// A new node can still be better handled by an existing page when GSC is
		// already strongly associating that topic with the page.
		$distinct_info = 'informational' === strtolower( (string) ( $node['intent'] ?? '' ) ) || 'article' === (string) ( $node['page_type'] ?? '' );
		if ( empty( $node['exists'] ) && ! $distinct_info && 'section' !== (string) ( $node['status'] ?? '' ) && ! empty( $node['gsc']['best_existing_url'] ) && (int) $node['gsc']['impressions'] >= 20 ) {
			$node['original_url']   = (string) ( $node['url'] ?? '' );
			$node['url']            = $node['gsc']['best_existing_url'];
			$node['status']         = 'gsc_covered';
			$node['page_candidate'] = false;
			$node['exists']         = true;
			$node['rationale']      = __( 'Search Console already shows an existing page for this topic. Strengthen that page before creating another URL.', 'seo-command-center' );
			$match_path = self::normalize_path( $node['url'] );
			if ( isset( $index_by_path[ $match_path ] ) ) {
				$row = $index_by_path[ $match_path ];
				$node['post_id'] = (int) ( $row['post_id'] ?? 0 );
				$node['edit_url'] = $node['post_id'] && function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $node['post_id'], '' ) : '';
				$node['coverage'] = self::coverage( $node, $row );
			}
		}

		$node['decision'] = self::decision( $node );

		$override = isset( $overrides[ $node['node_id'] ] ) && is_array( $overrides[ $node['node_id'] ] )
			? $overrides[ $node['node_id'] ]
			: array();
		if ( ! empty( $override['ignored'] ) ) {
			$node['decision']['action'] = 'ignore';
			$node['decision']['label'] = __( 'Ignored', 'seo-command-center' );
			$node['page_candidate'] = false;
			$node['ignored'] = true;
		}
		if ( ! empty( $override['covered'] ) ) {
			$node['decision']['action'] = 'keep';
			$node['decision']['label'] = __( 'Marked covered', 'seo-command-center' );
			$node['page_candidate'] = false;
			$node['user_covered'] = true;
		}
		if ( ! empty( $override['parent_url'] ) ) {
			$node['parent_override'] = self::normalize_display_path( $override['parent_url'] );
		}
		$node['has_override'] = ! empty( $override );

		return $node;
	}

	/**
	 * Page-topic coverage based on the indexed rendered content signals.
	 *
	 * @param array $node Topic node.
	 * @param array $row  Content-index row.
	 * @return array
	 */
	public static function coverage( array $node, array $row ) {
		$phrases = array_merge(
			array( $node['title'] ?? '', $node['primary_keyword'] ?? '' ),
			(array) ( $node['related'] ?? array() )
		);
		$phrases = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $phrases ) ) ) ) );
		$tokens = array_keys( (array) ( $row['tokens'] ?? array() ) );
		$headings = strtolower( implode( ' ', (array) ( $row['headings'] ?? array() ) ) );
		$title = strtolower( (string) ( $row['title'] ?? '' ) );

		$scores = array();
		$covered = array();
		$missing = array();

		foreach ( $phrases as $phrase ) {
			$pt = class_exists( 'SCC_Content_Index' )
				? array_keys( SCC_Content_Index::tokenize( $phrase ) )
				: self::tokens( $phrase );
			if ( empty( $pt ) ) {
				continue;
			}
			$hit = 0;
			foreach ( $pt as $t ) {
				if ( in_array( $t, $tokens, true ) || false !== strpos( $headings, $t ) || false !== strpos( $title, $t ) ) {
					$hit++;
				}
			}
			$ratio = $hit / max( 1, count( $pt ) );
			$scores[] = $ratio;
			if ( $ratio >= 0.75 ) {
				$covered[] = $phrase;
			} elseif ( $ratio < 0.60 ) {
				$missing[] = $phrase;
			}
		}

		$score = $scores ? (int) round( 100 * array_sum( $scores ) / count( $scores ) ) : 0;
		$level = $score >= 75 ? 'strong' : ( $score >= 45 ? 'partial' : 'weak' );

		return array(
			'available' => true,
			'score'     => $score,
			'level'     => $level,
			'missing'   => array_slice( array_values( array_unique( $missing ) ), 0, 6 ),
			'covered'   => array_slice( array_values( array_unique( $covered ) ), 0, 6 ),
		);
	}

	/**
	 * Decide what should happen to a topic.
	 *
	 * @param array $node Enriched node.
	 * @return array
	 */
	public static function decision( array $node ) {
		$status   = (string) ( $node['status'] ?? 'new' );
		$intent   = strtolower( (string) ( $node['intent'] ?? '' ) );
		$type     = (string) ( $node['page_type'] ?? '' );
		$coverage = (array) ( $node['coverage'] ?? array() );
		$score    = isset( $coverage['score'] ) ? (int) $coverage['score'] : null;

		if ( 'section' === $status ) {
			return self::decision_row( 'expand_existing', __( 'Add to existing service page', 'seo-command-center' ), 96, __( 'Same commercial intent; it belongs on the parent service page, not a new URL.', 'seo-command-center' ) );
		}
		if ( in_array( $status, array( 'covered', 'gsc_covered' ), true ) ) {
			if ( null !== $score && $score < 75 ) {
				return self::decision_row( 'expand_existing', __( 'Expand existing page', 'seo-command-center' ), 92, __( 'The topic maps to an existing page, but its current content coverage is incomplete.', 'seo-command-center' ) );
			}
			return self::decision_row( 'keep', __( 'Use existing page', 'seo-command-center' ), 95, __( 'An existing URL already satisfies this topic/intent.', 'seo-command-center' ) );
		}
		if ( ! empty( $node['exists'] ) ) {
			if ( null !== $score && $score < 55 ) {
				return self::decision_row( 'expand_existing', __( 'Expand existing page', 'seo-command-center' ), 90, __( 'The URL exists, but the indexed content only weakly covers the planned topic.', 'seo-command-center' ) );
			}
			return self::decision_row( 'keep', __( 'Keep existing page', 'seo-command-center' ), 94, __( 'The page exists and its topic belongs at this URL.', 'seo-command-center' ) );
		}
		if ( 'local' === $intent || 'location' === $type ) {
			return self::decision_row( 'create_location', __( 'Create location page', 'seo-command-center' ), 86, __( 'This is a distinct local search intent and can justify a location URL when the content is genuinely local.', 'seo-command-center' ) );
		}
		if ( 'informational' === $intent || 'article' === $type ) {
			return self::decision_row( 'create_article', __( 'Create supporting article', 'seo-command-center' ), 88, __( 'This is a distinct informational task that can support the service hub.', 'seo-command-center' ) );
		}
		return self::decision_row( 'create_page', __( 'Create service page', 'seo-command-center' ), 82, __( 'No existing page currently satisfies this distinct commercial intent.', 'seo-command-center' ) );
	}

	protected static function decision_row( $action, $label, $confidence, $reason ) {
		return array(
			'action'     => $action,
			'label'      => $label,
			'confidence' => (int) $confidence,
			'reason'     => $reason,
		);
	}

	/**
	 * Search Console evidence for a topic across existing pages.
	 *
	 * @param array $node Node.
	 * @param array $map Page/query map.
	 * @return array
	 */
	public static function gsc_evidence_for_node( array $node, array $map ) {
		$topic_tokens = self::tokens( implode( ' ', array_merge(
			array( $node['title'] ?? '', $node['primary_keyword'] ?? '' ),
			(array) ( $node['related'] ?? array() )
		) ) );
		if ( empty( $topic_tokens ) || empty( $map ) ) {
			return array( 'available' => ! empty( $map ), 'impressions' => 0, 'clicks' => 0, 'queries' => array(), 'best_existing_url' => '' );
		}

		$best_url = '';
		$best_impressions = 0;
		$total_clicks = 0;
		$matches = array();

		foreach ( $map as $url => $rows ) {
			$page_impressions = 0;
			$page_clicks = 0;
			$page_queries = array();
			foreach ( (array) $rows as $row ) {
				$query = (string) ( $row['query'] ?? '' );
				$qt = self::tokens( $query );
				if ( empty( $qt ) ) {
					continue;
				}
				$intersection = count( array_intersect( $qt, $topic_tokens ) );
				$overlap = $intersection / max( 1, min( count( $qt ), count( $topic_tokens ) ) );
				if ( $intersection >= 2 && $overlap >= 0.60 ) {
					$impr = (int) ( $row['impressions'] ?? 0 );
					$clicks = (int) ( $row['clicks'] ?? 0 );
					$page_impressions += $impr;
					$page_clicks += $clicks;
					$page_queries[] = array(
						'query'       => $query,
						'impressions' => $impr,
						'clicks'      => $clicks,
						'position'    => (float) ( $row['position'] ?? 0 ),
					);
				}
			}
			if ( $page_impressions > $best_impressions ) {
				$best_impressions = $page_impressions;
				$total_clicks = $page_clicks;
				$best_url = (string) $url;
				$matches = $page_queries;
			}
		}

		return array(
			'available'         => true,
			'impressions'       => $best_impressions,
			'clicks'            => $total_clicks,
			'queries'           => array_slice( $matches, 0, 5 ),
			'best_existing_url' => $best_url,
		);
	}

	/**
	 * Conservative duplicate/cannibalization consolidation planner.
	 *
	 * @param array $rows Content index rows.
	 * @param array $gsc  GSC map.
	 * @return array
	 */
	public static function consolidation_plan( array $rows, array $gsc = array() ) {
		$out = array();
		$n = count( $rows );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$a = $rows[ $i ];
				$b = $rows[ $j ];
				$ta = self::tokens( $a['title'] ?? '' );
				$tb = self::tokens( $b['title'] ?? '' );
				if ( count( $ta ) < 2 || count( $tb ) < 2 ) {
					continue;
				}
				$inter = count( array_intersect( $ta, $tb ) );
				$union = count( array_unique( array_merge( $ta, $tb ) ) );
				$title_overlap = $union ? $inter / $union : 0;
				$content_rel = class_exists( 'SCC_Content_Index' ) ? SCC_Content_Index::relevance( $a, $b ) : ( 100 * $title_overlap );

				if ( $title_overlap < 0.72 || $content_rel < 62 ) {
					continue;
				}

				$a_url = (string) ( $a['url'] ?? '' );
				$b_url = (string) ( $b['url'] ?? '' );
				$a_impr = self::page_impressions( $a_url, $gsc );
				$b_impr = self::page_impressions( $b_url, $gsc );

				if ( $a_impr !== $b_impr ) {
					$keep = $a_impr > $b_impr ? $a : $b;
					$merge = $a_impr > $b_impr ? $b : $a;
					$why = __( 'Keep the URL with stronger Search Console visibility.', 'seo-command-center' );
				} else {
					$a_depth = substr_count( trim( (string) wp_parse_url( $a_url, PHP_URL_PATH ), '/' ), '/' );
					$b_depth = substr_count( trim( (string) wp_parse_url( $b_url, PHP_URL_PATH ), '/' ), '/' );
					$keep = $a_depth <= $b_depth ? $a : $b;
					$merge = $a_depth <= $b_depth ? $b : $a;
					$why = __( 'Search Console does not break the tie, so TideOrbit prefers the cleaner established URL. Review before changing anything.', 'seo-command-center' );
				}

				$id = 'merge:' . sha1( self::normalize_path( $keep['url'] ?? '' ) . '|' . self::normalize_path( $merge['url'] ?? '' ) );
				$out[] = array(
					'id'              => $id,
					'keep_url'        => (string) ( $keep['url'] ?? '' ),
					'keep_title'      => (string) ( $keep['title'] ?? '' ),
					'keep_post_id'    => (int) ( $keep['post_id'] ?? 0 ),
					'merge_url'       => (string) ( $merge['url'] ?? '' ),
					'merge_title'     => (string) ( $merge['title'] ?? '' ),
					'merge_post_id'   => (int) ( $merge['post_id'] ?? 0 ),
					'similarity'      => (int) round( max( 100 * $title_overlap, $content_rel ) ),
					'reason'          => $why,
					'recommended_steps' => array(
						__( 'Review both pages and select the canonical keeper.', 'seo-command-center' ),
						__( 'Move any unique useful content from the weaker page into the keeper.', 'seo-command-center' ),
						__( 'Update internal links to the keeper.', 'seo-command-center' ),
						__( 'Only after review, 301 redirect the retired URL to the keeper.', 'seo-command-center' ),
					),
				);
				if ( count( $out ) >= 20 ) {
					break 2;
				}
			}
		}
		usort( $out, function ( $a, $b ) { return $b['similarity'] <=> $a['similarity']; } );
		return $out;
	}

	/**
	 * Diagnostic architecture health.
	 *
	 * @param array $tree Enriched tree.
	 * @param array $consolidation Merge candidates.
	 * @param array $technical Technical SEO report.
	 * @return array
	 */
	public static function health( array $tree, array $consolidation, array $technical = array() ) {
		$stats = array(
			'new_pages'       => 0,
			'expand_existing' => 0,
			'weak_coverage'   => 0,
			'service_hubs'    => count( (array) ( $tree['pillars'] ?? array() ) ),
			'empty_hubs'      => 0,
			'merge_candidates'=> count( $consolidation ),
			'orphans'         => 0,
		);

		foreach ( (array) ( $tree['pillars'] ?? array() ) as $pillar ) {
			if ( empty( $pillar['children'] ) && empty( $pillar['sections'] ) && empty( $pillar['articles'] ) ) {
				$stats['empty_hubs']++;
			}
			self::accumulate_health_node( $pillar, $stats );
		}

		foreach ( (array) ( $technical['issues'] ?? array() ) as $issue ) {
			if ( in_array( (string) ( $issue['id'] ?? '' ), array( 'orphan_page', 'unreachable_from_home' ), true ) ) {
				$stats['orphans'] += (int) ( $issue['affected_count'] ?? 0 );
			}
		}

		$penalty = min( 28, 12 * $stats['merge_candidates'] )
			+ min( 22, 4 * $stats['weak_coverage'] )
			+ min( 18, 5 * $stats['orphans'] )
			+ min( 12, 4 * $stats['empty_hubs'] )
			+ min( 12, 2 * $stats['expand_existing'] );
		$score = max( 0, 100 - $penalty );

		return array(
			'score' => (int) $score,
			'stats' => $stats,
			'label' => $score >= 85 ? __( 'Strong', 'seo-command-center' ) : ( $score >= 65 ? __( 'Needs refinement', 'seo-command-center' ) : __( 'Needs restructuring', 'seo-command-center' ) ),
		);
	}

	/**
	 * Recursively accumulate architecture-health node counts.
	 *
	 * @param array $node Node.
	 * @param array $stats Mutable stats.
	 * @return void
	 */
	protected static function accumulate_health_node( array $node, array &$stats ) {
		$action = (string) ( $node['decision']['action'] ?? '' );
		if ( in_array( $action, array( 'create_page', 'create_article', 'create_location' ), true ) ) {
			$stats['new_pages']++;
		}
		if ( 'expand_existing' === $action ) {
			$stats['expand_existing']++;
		}
		if ( 'weak' === (string) ( $node['coverage']['level'] ?? '' ) ) {
			$stats['weak_coverage']++;
		}
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			self::accumulate_health_node( $child, $stats );
		}
		foreach ( array( 'sections', 'articles' ) as $bucket ) {
			foreach ( (array) ( $node[ $bucket ] ?? array() ) as $child ) {
				self::accumulate_health_node( $child, $stats );
			}
		}
	}

	/**
	 * Re-parent nodes from user drag/drop overrides.
	 *
	 * @param array $tree Enriched tree.
	 * @param array $overrides Overrides.
	 * @return array
	 */
	protected function apply_parent_overrides( array $tree, array $overrides ) {
		$parents = array();
		foreach ( (array) ( $tree['pillars'] ?? array() ) as $i => $pillar ) {
			$parents[ self::normalize_path( $pillar['url'] ?? '' ) ] = $i;
		}

		$moves = array();
		foreach ( (array) ( $tree['pillars'] ?? array() ) as $pi => $pillar ) {
			foreach ( array( 'children', 'sections', 'articles' ) as $bucket ) {
				foreach ( (array) ( $pillar[ $bucket ] ?? array() ) as $ni => $node ) {
					$id = (string) ( $node['node_id'] ?? '' );
					$target = isset( $overrides[ $id ]['parent_url'] ) ? self::normalize_path( $overrides[ $id ]['parent_url'] ) : '';
					if ( '' !== $target && isset( $parents[ $target ] ) && $parents[ $target ] !== $pi ) {
						$moves[] = array( 'from' => $pi, 'bucket' => $bucket, 'index' => $ni, 'to' => $parents[ $target ], 'node' => $node );
					}
				}
			}
		}
		if ( empty( $moves ) ) {
			return $tree;
		}

		// Remove in reverse source order.
		usort( $moves, function ( $a, $b ) {
			return ( $b['from'] <=> $a['from'] ) ?: ( $b['index'] <=> $a['index'] );
		} );
		foreach ( $moves as $m ) {
			unset( $tree['pillars'][ $m['from'] ][ $m['bucket'] ][ $m['index'] ] );
		}
		foreach ( $tree['pillars'] as &$pillar ) {
			foreach ( array( 'children', 'sections', 'articles' ) as $bucket ) {
				$pillar[ $bucket ] = array_values( (array) ( $pillar[ $bucket ] ?? array() ) );
			}
		}
		unset( $pillar );

		foreach ( $moves as $m ) {
			$bucket = (string) ( $m['node']['page_type'] ?? '' ) === 'article' ? 'articles' : ( (string) ( $m['node']['status'] ?? '' ) === 'section' ? 'sections' : 'children' );
			$tree['pillars'][ $m['to'] ][ $bucket ][] = $m['node'];
		}
		return $tree;
	}

	/**
	 * User overrides.
	 *
	 * @return array
	 */
	public static function overrides() {
		$value = get_option( self::OVERRIDES_OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Save fields on a node override.
	 *
	 * @param string $node_id Node id.
	 * @param array  $changes Changes.
	 * @return bool
	 */
	public static function save_override( $node_id, array $changes ) {
		$node_id = sanitize_text_field( (string) $node_id );
		if ( '' === $node_id ) {
			return false;
		}
		$all = self::overrides();
		$current = isset( $all[ $node_id ] ) && is_array( $all[ $node_id ] ) ? $all[ $node_id ] : array();

		$allowed = array();
		if ( array_key_exists( 'ignored', $changes ) ) {
			$allowed['ignored'] = (bool) $changes['ignored'];
		}
		if ( array_key_exists( 'covered', $changes ) ) {
			$allowed['covered'] = (bool) $changes['covered'];
		}
		if ( array_key_exists( 'parent_url', $changes ) ) {
			$allowed['parent_url'] = self::normalize_display_path( $changes['parent_url'] );
		}
		$allowed['updated_at'] = current_time( 'mysql' );
		$all[ $node_id ] = array_merge( $current, $allowed );
		update_option( self::OVERRIDES_OPTION, $all, false );
		return true;
	}

	public static function clear_override( $node_id ) {
		$all = self::overrides();
		if ( isset( $all[ $node_id ] ) ) {
			unset( $all[ $node_id ] );
			update_option( self::OVERRIDES_OPTION, $all, false );
		}
		return true;
	}

	/**
	 * Find an enriched node by stable id.
	 *
	 * @param array  $report Report.
	 * @param string $node_id Node id.
	 * @return array|null
	 */
	public static function find_node( array $report, $node_id ) {
		foreach ( (array) ( $report['tree']['pillars'] ?? array() ) as $pillar ) {
			$found = self::find_node_branch( $pillar, $node_id );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	protected static function find_node_branch( array $node, $node_id ) {
		if ( (string) ( $node['node_id'] ?? '' ) === (string) $node_id ) {
			return $node;
		}
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			$found = self::find_node_branch( $child, $node_id );
			if ( $found ) { return $found; }
		}
		foreach ( array( 'sections', 'articles' ) as $bucket ) {
			foreach ( (array) ( $node[ $bucket ] ?? array() ) as $child ) {
				if ( (string) ( $child['node_id'] ?? '' ) === (string) $node_id ) {
					return $child;
				}
			}
		}
		return null;
	}

	public static function node_id( array $node ) {
		$topic = class_exists( 'SCC_Keyword_Strategy' )
			? SCC_Keyword_Strategy::normalize_topic_phrase( (string) ( $node['title'] ?? $node['primary_keyword'] ?? '' ) )
			: strtolower( trim( (string) ( $node['title'] ?? '' ) ) );
		$url = self::normalize_path( $node['original_url'] ?? $node['url'] ?? '' );
		return 'arch:' . sha1( $topic . '|' . $url );
	}

	protected static function page_impressions( $url, array $gsc ) {
		$key = untrailingslashit( (string) $url );
		$total = 0;
		foreach ( (array) ( $gsc[ $key ] ?? array() ) as $row ) {
			$total += (int) ( $row['impressions'] ?? 0 );
		}
		return $total;
	}

	protected static function tokens( $text ) {
		if ( class_exists( 'SCC_Content_Index' ) ) {
			return array_keys( SCC_Content_Index::tokenize( (string) $text ) );
		}
		$text = strtolower( preg_replace( '/[^a-z0-9 ]+/', ' ', (string) $text ) );
		return array_values( array_unique( array_filter( explode( ' ', $text ), function ( $w ) { return strlen( $w ) >= 3; } ) ) );
	}

	public static function normalize_path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return strtolower( trim( $path, '/' ) );
	}

	public static function normalize_display_path( $url ) {
		$path = self::normalize_path( $url );
		return '' === $path ? '/' : '/' . $path . '/';
	}
}
