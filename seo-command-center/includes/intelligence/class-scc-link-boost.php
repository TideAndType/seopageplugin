<?php
/**
 * GSC-aware internal-link authority routing.
 *
 * Ranks inbound internal-link opportunities by contextual relevance plus the
 * target's real Search Console opportunity and source-page search visibility.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Link_Boost {

	public static function recommendations( $post_id, $limit = 15 ) {
		$post_id = (int) $post_id;
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== (string) $post->post_status ) {
			return array( 'available' => false, 'reason' => 'published_page_required', 'recommendations' => array() );
		}

		$engine = new SCC_Link_Engine();
		$analysis = $engine->analyze( $post_id, false );
		$target_url = get_permalink( $post_id );
		$target_metrics = class_exists( 'SCC_GSC' ) ? SCC_GSC::page_metrics( $target_url, 90 ) : null;
		$page_metrics = class_exists( 'SCC_GSC' ) ? SCC_GSC::page_metrics_map( 90 ) : array();

		$ranked = array();
		foreach ( (array) ( $analysis['inbound'] ?? array() ) as $rec ) {
			$source_url = get_permalink( (int) ( $rec['source_post_id'] ?? 0 ) );
			$source_metrics = $source_url && isset( $page_metrics[ untrailingslashit( $source_url ) ] )
				? $page_metrics[ untrailingslashit( $source_url ) ] : null;
			$rec['source_url'] = $source_url;
			$rec['source_metrics'] = $source_metrics;
			$rec['boost_score'] = self::score_candidate( $rec, $target_metrics, $source_metrics );
			$ranked[] = $rec;
		}
		usort( $ranked, function ( $a, $b ) {
			return $b['boost_score'] <=> $a['boost_score'];
		} );

		return array(
			'available'       => true,
			'post_id'         => $post_id,
			'target_url'      => $target_url,
			'target_metrics'  => $target_metrics,
			'recommendations' => array_slice( $ranked, 0, max( 1, min( 50, (int) $limit ) ) ),
		);
	}

	public static function score_candidate( array $rec, $target_metrics = null, $source_metrics = null ) {
		$score = min( 45, max( 0, (int) ( $rec['confidence'] ?? 0 ) ) * 0.45 );
		if ( ! empty( $rec['natural'] ) ) { $score += 10; }

		if ( is_array( $target_metrics ) ) {
			$pos = (float) ( $target_metrics['position'] ?? 0 );
			$impr = (int) ( $target_metrics['impressions'] ?? 0 );
			if ( $pos >= 4 && $pos <= 20 ) { $score += 25; }
			elseif ( $pos > 0 && $pos <= 30 ) { $score += 14; }
			$score += min( 8, log( max( 1, $impr ), 2 ) );
		}
		if ( is_array( $source_metrics ) ) {
			$clicks = (int) ( $source_metrics['clicks'] ?? 0 );
			$impr   = (int) ( $source_metrics['impressions'] ?? 0 );
			$score += min( 7, log( max( 1, $clicks + 1 ), 2 ) );
			$score += min( 5, log( max( 1, $impr + 1 ), 2 ) / 2 );
		}
		return (int) round( min( 100, $score ) );
	}

	public static function strengthen( $post_id, $limit = 5 ) {
		$post_id = (int) $post_id;
		$limit = max( 1, min( 10, (int) $limit ) );
		$ranked = self::recommendations( $post_id, 50 );
		if ( empty( $ranked['available'] ) ) { return $ranked; }

		// Store current recommendations so the existing, rollback-aware inserter
		// remains the only code path that actually changes page content.
		$engine = new SCC_Link_Engine();
		$engine->analyze( $post_id, true );

		$score_by_source = array();
		foreach ( (array) $ranked['recommendations'] as $rec ) {
			$score_by_source[ (int) $rec['source_post_id'] ] = (int) $rec['boost_score'];
		}

		$stored = SCC_Link_Engine::recommendations( array(
			'status' => 'recommended',
			'min_confidence' => SCC_Link_Engine::thresholds()['high'],
			'limit' => 1000,
		) );
		$candidates = array();
		foreach ( (array) $stored as $row ) {
			if ( (int) ( $row['target_post_id'] ?? 0 ) !== $post_id ) { continue; }
			if ( 'natural' !== (string) ( $row['context'] ?? '' ) ) { continue; }
			$row['boost_score'] = (int) ( $score_by_source[ (int) $row['source_post_id'] ] ?? 0 );
			$candidates[] = $row;
		}
		usort( $candidates, function ( $a, $b ) { return $b['boost_score'] <=> $a['boost_score']; } );

		$inserter = new SCC_Link_Inserter();
		$applied = array();
		$errors = array();
		foreach ( array_slice( $candidates, 0, $limit ) as $row ) {
			$result = $inserter->apply( (int) $row['id'], 'gsc-link-boost' );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				$applied[] = array(
					'id' => (int) $row['id'],
					'source_post_id' => (int) $row['source_post_id'],
					'source_title' => (string) ( $row['source_title'] ?? '' ),
					'anchor' => (string) ( $row['anchor'] ?? '' ),
					'boost_score' => (int) $row['boost_score'],
				);
			}
		}
		return array(
			'available' => true,
			'applied' => $applied,
			'applied_count' => count( $applied ),
			'errors' => $errors,
			'remaining_recommendations' => max( 0, count( $candidates ) - count( $applied ) ),
		);
	}
}
