<?php
/**
 * Internal link recommender.
 *
 * Suggests contextual internal links by topical overlap between posts, favoring
 * links INTO under-linked / orphan pages. Recommendations are stored in
 * scc_internal_links with status "recommended" — nothing is inserted until the
 * user applies it.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link recommender.
 */
class SCC_Link_Recommender {

	const STOP_WORDS = array( 'the', 'a', 'an', 'and', 'or', 'for', 'of', 'in', 'to', 'services', 'service', 'company', 'agency', 'best', 'top', 'your', 'our', 'how', 'what', 'why', 'with' );

	/** Minimum shared significant tokens to consider a link relevant. */
	const MIN_OVERLAP = 2;

	/**
	 * Generate recommendations across the site graph.
	 *
	 * @param int $limit Max posts to consider.
	 * @return array {created:int, recommendations:array}
	 */
	public function generate( $limit = 300 ) {
		$graph   = new SCC_Link_Graph();
		$data    = $graph->build( $limit );
		$nodes   = $data['nodes'];
		$max     = (int) SCC_Settings::get( 'max_internal_links', 5 );

		// Pre-tokenize.
		$tokens = array();
		foreach ( $nodes as $id => $node ) {
			$tokens[ $id ] = $this->tokens( $node['title'] );
		}

		// Priority targets: fewer inbound links first.
		$targets_by_need = $nodes;
		usort(
			$targets_by_need,
			function ( $a, $b ) {
				return $a['inbound_count'] <=> $b['inbound_count'];
			}
		);

		$created = 0;
		$recs    = array();

		foreach ( $nodes as $source_id => $source ) {
			$existing = array_flip( $source['outbound'] );
			$added    = 0;

			foreach ( $targets_by_need as $target ) {
				$target_id = $target['post_id'];
				if ( $target_id === $source_id || isset( $existing[ $target_id ] ) ) {
					continue;
				}
				if ( $added >= $max ) {
					break;
				}
				$overlap = count( array_intersect( $tokens[ $source_id ], $tokens[ $target_id ] ?? array() ) );
				if ( $overlap < self::MIN_OVERLAP ) {
					continue;
				}
				// Anchor: prefer the target title as a natural phrase.
				$anchor = $this->anchor_for( $target, $source );
				if ( '' === $anchor ) {
					continue;
				}
				if ( $this->already_recorded( $source_id, $target_id ) ) {
					continue;
				}

				SCC_DB::insert(
					'internal_links',
					array(
						'source_post_id' => $source_id,
						'target_post_id' => $target_id,
						'anchor'         => $anchor,
						'context'        => sprintf( 'overlap:%d inbound:%d', $overlap, $target['inbound_count'] ),
						'status'         => 'recommended',
						'created_at'     => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s' )
				);
				$created++;
				$added++;
				$recs[] = array(
					'source' => $source['title'],
					'target' => $target['title'],
					'anchor' => $anchor,
				);
			}
		}

		SCC_Logger::info( 'link-recommender', 'Recommendations generated', array( 'created' => $created ) );
		return array( 'created' => $created, 'recommendations' => $recs );
	}

	/**
	 * Choose a natural anchor phrase that actually appears in the source content.
	 *
	 * @param array $target Target node.
	 * @param array $source Source node.
	 * @return string Anchor text, or '' if none is present in the source.
	 */
	protected function anchor_for( array $target, array $source ) {
		$source_post = get_post( $source['post_id'] );
		if ( ! $source_post ) {
			return '';
		}
		$text = strtolower( wp_strip_all_tags( $source_post->post_content ) );

		// Candidate phrases: target title, and the target's stored primary keyword.
		$candidates = array( $target['title'] );
		$plan_kw    = $this->plan_keyword_for( $target['post_id'] );
		if ( $plan_kw ) {
			$candidates[] = $plan_kw;
		}
		foreach ( $candidates as $phrase ) {
			$phrase = trim( (string) $phrase );
			if ( strlen( $phrase ) < 4 ) {
				continue;
			}
			if ( false !== strpos( $text, strtolower( $phrase ) ) ) {
				return $phrase; // Natural: the phrase already exists in the source.
			}
		}
		return '';
	}

	/**
	 * Look up a stored primary keyword for a post from the content plan.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	protected function plan_keyword_for( $post_id ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_plan' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT primary_keyword FROM {$table} WHERE post_id = %d LIMIT 1", (int) $post_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Whether a recommendation already exists for this pair.
	 *
	 * @param int $source_id Source.
	 * @param int $target_id Target.
	 * @return bool
	 */
	protected function already_recorded( $source_id, $target_id ) {
		global $wpdb;
		$table = SCC_DB::table( 'internal_links' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_post_id = %d AND target_post_id = %d", $source_id, $target_id ) ); // phpcs:ignore WordPress.DB
		return $count > 0;
	}

	/**
	 * Tokenize a title into significant words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	protected function tokens( $text ) {
		$text  = strtolower( wp_strip_all_tags( (string) $text ) );
		$text  = preg_replace( '/[^a-z0-9 ]+/', ' ', $text );
		$out   = array();
		foreach ( array_filter( explode( ' ', $text ) ) as $w ) {
			if ( strlen( $w ) < 3 || in_array( $w, self::STOP_WORDS, true ) ) {
				continue;
			}
			$out[ $w ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * List stored recommendations (optionally by status).
	 *
	 * @param string $status Status filter.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function list_recommendations( $status = 'recommended', $limit = 200 ) {
		global $wpdb;
		$table = SCC_DB::table( 'internal_links' );
		$limit = SCC_Security::sanitize_int( $limit, 1, 1000 );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['source_title'] = get_the_title( (int) $row['source_post_id'] );
			$row['target_title'] = get_the_title( (int) $row['target_post_id'] );
			$row['target_url']   = get_permalink( (int) $row['target_post_id'] );
		}
		return $rows;
	}
}
