<?php
/**
 * SEO Growth Architect.
 *
 * Converts the evidence-backed Architecture Brain into a future-state SEO
 * blueprint and prioritized roadmap. This is intentionally deterministic:
 * recommendations are traceable to current URLs, coverage, GSC evidence,
 * architecture overlap and technical signals instead of opaque AI guesses.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_SEO_Growth_Architect {

	/**
	 * Build a recommended future-state blueprint from an Architecture Brain report.
	 *
	 * @param array $brain Architecture Brain report.
	 * @return array
	 */
	public static function build( array $brain ) {
		$tree = (array) ( $brain['tree'] ?? array() );
		$roadmap = array( 'now' => array(), 'next' => array(), 'later' => array() );
		$recommended_tree = $tree;

		foreach ( (array) ( $recommended_tree['pillars'] ?? array() ) as $i => $pillar ) {
			$recommended_tree['pillars'][ $i ] = self::annotate_branch( $pillar, $roadmap, 0 );
		}

		foreach ( (array) ( $brain['consolidation'] ?? array() ) as $merge ) {
			$score = (int) ( $merge['similarity'] ?? 0 );
			$phase = $score >= 85 ? 'now' : 'next';
			$roadmap[ $phase ][] = array(
				'id'       => (string) ( $merge['id'] ?? '' ),
				'type'     => 'consolidate',
				'title'    => sprintf(
					__( 'Consolidate %1$s into %2$s', 'seo-command-center' ),
					(string) ( $merge['merge_title'] ?? $merge['merge_url'] ?? '' ),
					(string) ( $merge['keep_title'] ?? $merge['keep_url'] ?? '' )
				),
				'url'      => (string) ( $merge['keep_url'] ?? '' ),
				'phase'    => $phase,
				'priority' => min( 100, max( 1, $score ) ),
				'reason'   => (string) ( $merge['reason'] ?? __( 'Overlapping pages can dilute topical signals and internal authority.', 'seo-command-center' ) ),
				'outcome'  => __( 'Concentrate relevance, links and citations on one canonical URL.', 'seo-command-center' ),
			);
		}

		$health = (array) ( $brain['health']['stats'] ?? array() );
		if ( ! empty( $health['orphans'] ) ) {
			$roadmap['now'][] = array(
				'id'       => 'growth:orphans',
				'type'     => 'internal_links',
				'title'    => sprintf( __( 'Connect %d orphan/unreachable page(s)', 'seo-command-center' ), (int) $health['orphans'] ),
				'url'      => '',
				'phase'    => 'now',
				'priority' => 92,
				'reason'   => __( 'Important pages that are difficult to reach waste crawl paths and topical authority.', 'seo-command-center' ),
				'outcome'  => __( 'Improve discoverability and pass authority through deliberate internal links.', 'seo-command-center' ),
			);
		}
		if ( ! empty( $health['deep_pages'] ) ) {
			$roadmap['next'][] = array(
				'id'       => 'growth:depth',
				'type'     => 'architecture',
				'title'    => sprintf( __( 'Reduce click depth for %d page(s)', 'seo-command-center' ), (int) $health['deep_pages'] ),
				'url'      => '',
				'phase'    => 'next',
				'priority' => 74,
				'reason'   => __( 'Deep pages are harder for users and crawlers to discover from key hubs.', 'seo-command-center' ),
				'outcome'  => __( 'Move priority pages closer to service hubs and strengthen contextual navigation.', 'seo-command-center' ),
			);
		}

		// Pull the highest-value AEO site actions into the same growth roadmap so
		// the future architecture accounts for both classic search and AI answers.
		if ( class_exists( 'SCC_AEO_Expert' ) ) {
			$aeo = SCC_AEO_Expert::site_report( 60 );
			foreach ( array_slice( (array) ( $aeo['recommendations'] ?? array() ), 0, 4 ) as $rec ) {
				$phase = ( (int) ( $rec['priority'] ?? 0 ) >= 80 ) ? 'now' : 'next';
				$roadmap[ $phase ][] = array(
					'id'       => 'aeo:' . sanitize_key( (string) ( $rec['key'] ?? uniqid( 'rec', false ) ) ),
					'type'     => 'aeo',
					'title'    => (string) ( $rec['title'] ?? __( 'Improve AI citation readiness', 'seo-command-center' ) ),
					'url'      => (string) ( $rec['url'] ?? '' ),
					'phase'    => $phase,
					'priority' => (int) ( $rec['priority'] ?? 70 ),
					'reason'   => (string) ( $rec['reason'] ?? '' ),
					'outcome'  => (string) ( $rec['outcome'] ?? __( 'Make the site easier for answer engines to discover, understand and cite accurately.', 'seo-command-center' ) ),
				);
			}
		}

		foreach ( $roadmap as &$items ) {
			usort(
				$items,
				function ( $a, $b ) {
					return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
				}
			);
			$items = array_slice( $items, 0, 18 );
		}
		unset( $items );

		return array(
			'recommended_tree' => $recommended_tree,
			'roadmap'          => $roadmap,
			'summary'          => array(
				'now'   => count( $roadmap['now'] ),
				'next'  => count( $roadmap['next'] ),
				'later' => count( $roadmap['later'] ),
			),
			'principle'        => __( 'Build the smallest site architecture that completely satisfies the important search intents. Strengthen an existing URL before creating another one.', 'seo-command-center' ),
		);
	}

	/**
	 * Recursively annotate the recommended tree and populate the roadmap.
	 *
	 * @param array $node Node.
	 * @param array $roadmap Roadmap by reference.
	 * @param int   $depth Depth.
	 * @return array
	 */
	protected static function annotate_branch( array $node, array &$roadmap, $depth ) {
		$decision = (array) ( $node['decision'] ?? array() );
		$action   = (string) ( $decision['action'] ?? 'keep' );
		$coverage = (array) ( $node['coverage'] ?? array() );
		$gsc      = (array) ( $node['gsc'] ?? array() );
		$priority = self::priority_score( $node );
		$phase    = self::phase( $action, $priority );

		$node['growth'] = array(
			'phase'    => $phase,
			'priority' => $priority,
			'label'    => self::growth_label( $action ),
		);

		if ( ! in_array( $action, array( 'keep', 'ignore' ), true ) ) {
			$roadmap[ $phase ][] = array(
				'id'       => (string) ( $node['node_id'] ?? '' ),
				'type'     => $action,
				'title'    => self::roadmap_title( $node, $action ),
				'url'      => (string) ( $node['url'] ?? '' ),
				'phase'    => $phase,
				'priority' => $priority,
				'reason'   => (string) ( $decision['reason'] ?? '' ),
				'outcome'  => self::expected_outcome( $node, $action ),
				'evidence' => array(
					'coverage'    => isset( $coverage['score'] ) ? (int) $coverage['score'] : null,
					'impressions' => (int) ( $gsc['impressions'] ?? 0 ),
					'depth'       => (int) $depth,
				),
			);
		}

		$children = array();
		foreach ( (array) ( $node['children'] ?? array() ) as $child ) {
			$children[] = self::annotate_branch( $child, $roadmap, $depth + 1 );
		}
		$node['children'] = $children;

		foreach ( array( 'sections', 'articles' ) as $bucket ) {
			$out = array();
			foreach ( (array) ( $node[ $bucket ] ?? array() ) as $child ) {
				$out[] = self::annotate_branch( $child, $roadmap, $depth + 1 );
			}
			$node[ $bucket ] = $out;
		}

		return $node;
	}

	/**
	 * Priority 1-100 from measurable/explicit evidence.
	 */
	public static function priority_score( array $node ) {
		$action   = (string) ( $node['decision']['action'] ?? 'keep' );
		$coverage = (array) ( $node['coverage'] ?? array() );
		$gsc      = (array) ( $node['gsc'] ?? array() );
		$intent   = strtolower( (string) ( $node['intent'] ?? '' ) );

		$score = 45;
		if ( 'expand_existing' === $action ) {
			$score += 24;
		} elseif ( in_array( $action, array( 'create_page', 'create_location' ), true ) ) {
			$score += 18;
		} elseif ( 'create_article' === $action ) {
			$score += 10;
		}

		if ( isset( $coverage['score'] ) ) {
			$score += max( 0, ( 70 - (int) $coverage['score'] ) / 4 );
		}
		$impressions = (int) ( $gsc['impressions'] ?? 0 );
		if ( $impressions >= 100 ) {
			$score += 15;
		} elseif ( $impressions >= 20 ) {
			$score += 9;
		}
		if ( in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ) {
			$score += 7;
		}
		if ( ! empty( $node['is_pillar'] ) ) {
			$score += 5;
		}

		return (int) min( 100, max( 1, round( $score ) ) );
	}

	protected static function phase( $action, $priority ) {
		if ( 'keep' === $action || 'ignore' === $action ) {
			return 'later';
		}
		if ( $priority >= 78 ) {
			return 'now';
		}
		if ( $priority >= 58 ) {
			return 'next';
		}
		return 'later';
	}

	protected static function growth_label( $action ) {
		$labels = array(
			'keep'            => __( 'Keep', 'seo-command-center' ),
			'expand_existing' => __( 'Strengthen', 'seo-command-center' ),
			'create_page'     => __( 'New service page', 'seo-command-center' ),
			'create_article'  => __( 'Supporting article', 'seo-command-center' ),
			'create_location' => __( 'Location page', 'seo-command-center' ),
			'ignore'          => __( 'Ignored', 'seo-command-center' ),
		);
		return $labels[ $action ] ?? ucwords( str_replace( '_', ' ', $action ) );
	}

	protected static function roadmap_title( array $node, $action ) {
		$title = (string) ( $node['title'] ?? $node['primary_keyword'] ?? '' );
		switch ( $action ) {
			case 'expand_existing':
				return sprintf( __( 'Strengthen %s', 'seo-command-center' ), $title );
			case 'create_article':
				return sprintf( __( 'Publish supporting article: %s', 'seo-command-center' ), $title );
			case 'create_location':
				return sprintf( __( 'Build local landing page: %s', 'seo-command-center' ), $title );
			default:
				return sprintf( __( 'Build service page: %s', 'seo-command-center' ), $title );
		}
	}

	protected static function expected_outcome( array $node, $action ) {
		if ( 'expand_existing' === $action ) {
			return __( 'Increase topical completeness on the URL that already owns this search intent and avoid cannibalization.', 'seo-command-center' );
		}
		if ( 'create_article' === $action ) {
			return __( 'Capture an informational intent and create a contextual internal-link path into the commercial service hub.', 'seo-command-center' );
		}
		if ( 'create_location' === $action ) {
			return __( 'Create a genuinely local search destination with unique local evidence and a clear service relationship.', 'seo-command-center' );
		}
		return __( 'Fill a distinct search-intent gap with one focused URL connected to the correct service hub.', 'seo-command-center' );
	}
}
