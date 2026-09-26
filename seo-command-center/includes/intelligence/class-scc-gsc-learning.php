<?php
/**
 * Search Console learning loop.
 *
 * Turns real page/query performance into bounded optimization recommendations.
 * Recommendations are advisory and stored on the page; nothing auto-publishes.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_GSC_Learning {

	const LAST_RUN = 'scc_gsc_learning_last_run';

	public static function recommendations_for_post( $post_id, $days = 90 ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			return array( 'available' => false, 'recommendations' => array() );
		}
		$url = get_permalink( $post );
		$metrics = SCC_GSC::page_metrics( $url, $days );
		$queries = self::queries_for_url( $url, $days );
		$recs = array();

		if ( is_array( $metrics ) ) {
			$impr = (int) ( $metrics['impressions'] ?? 0 );
			$ctr  = (float) ( $metrics['ctr'] ?? 0 );
			$pos  = (float) ( $metrics['position'] ?? 0 );
			if ( $impr >= 50 && $ctr < 2.0 && $pos > 0 && $pos <= 15 ) {
				$recs[] = self::rec( 'metadata', 'Improve title/meta for CTR', 'The page is earning impressions but relatively few clicks.', $metrics );
			}
			if ( $impr >= 50 && $pos >= 8 && $pos <= 20 ) {
				$recs[] = self::rec( 'content', 'Strengthen near-ranking topics', 'The page is visible near page one; expand genuinely useful coverage around its real queries.', $metrics );
			}
		}

		$text = strtolower( SCC_Content_Index::get_plain_text( $post ) );
		foreach ( array_slice( $queries, 0, 12 ) as $q ) {
			$query = (string) ( $q['query'] ?? '' );
			if ( '' === $query ) { continue; }
			$tokens = array_keys( SCC_Content_Index::tokenize( $query ) );
			$miss = 0;
			foreach ( $tokens as $t ) { if ( false === strpos( $text, strtolower( $t ) ) ) { $miss++; } }
			if ( count( $tokens ) >= 2 && $miss >= max( 1, (int) ceil( count( $tokens ) / 2 ) ) && (int) ( $q['impressions'] ?? 0 ) >= 10 ) {
				$recs[] = self::rec( 'topic_gap', 'Review an emerging query', 'Search Console shows this page for a query that is only weakly covered in the copy.', array( 'query' => $query, 'impressions' => (int) $q['impressions'], 'position' => (float) $q['position'] ) );
			}
		}

		return array(
			'available' => true,
			'metrics' => $metrics,
			'queries' => $queries,
			'recommendations' => array_slice( $recs, 0, 10 ),
		);
	}

	public static function maybe_refresh( $limit = 30 ) {
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) { return; }
		$last = (int) get_option( self::LAST_RUN, 0 );
		if ( $last && ( time() - $last ) < 12 * HOUR_IN_SECONDS ) { return; }

		$q = new WP_Query( array(
			'post_type' => SCC_Analyzer::analyzable_post_types(),
			'post_status' => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $limit ) ),
			'meta_key' => '_scc_generated',
			'no_found_rows' => true,
		) );
		foreach ( $q->posts as $post ) {
			$data = self::recommendations_for_post( $post->ID, 90 );
			update_post_meta( $post->ID, '_scc_gsc_learning', wp_json_encode( $data ) );
		}
		update_option( self::LAST_RUN, time(), false );
	}

	/**
	 * Cached Search Console queries grouped by page URL.
	 *
	 * Public so site-level systems such as the Architecture Brain can decide
	 * whether Google is already associating a topic with an existing page.
	 *
	 * @param int $days Lookback days.
	 * @return array<string,array>
	 */
	public static function page_query_map( $days = 90 ) {
		$days = max( 7, min( 180, (int) $days ) );
		return self::query_map( $days );
	}

	protected static function queries_for_url( $url, $days ) {
		$map = self::query_map( $days );
		$key = untrailingslashit( $url );
		return isset( $map[ $key ] ) ? $map[ $key ] : array();
	}

	/**
	 * Fetch page/query data once and fan it out to every generated page.
	 *
	 * @param int $days Lookback days.
	 * @return array<string,array>
	 */
	protected static function query_map( $days ) {
		$key = 'scc_gsc_qmap_' . (int) $days;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }

		$rows = SCC_GSC::query( '', array( 'page', 'query' ), $days, 25000 );
		$map = array();
		if ( ! is_wp_error( $rows ) ) {
			foreach ( (array) $rows as $row ) {
				$page = untrailingslashit( (string) ( $row['keys'][0] ?? '' ) );
				$query = (string) ( $row['keys'][1] ?? '' );
				if ( '' === $page || '' === $query ) { continue; }
				$map[ $page ][] = array(
					'query'       => $query,
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'ctr'         => round( 100 * (float) ( $row['ctr'] ?? 0 ), 2 ),
					'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				);
			}
		}
		foreach ( $map as $page => $queries ) {
			usort( $queries, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
			$map[ $page ] = array_slice( $queries, 0, 30 );
		}
		set_transient( $key, $map, 6 * HOUR_IN_SECONDS );
		return $map;
	}

	protected static function rec( $type, $title, $reason, array $data ) {
		return array( 'type' => $type, 'title' => $title, 'reason' => $reason, 'data' => $data );
	}
}
