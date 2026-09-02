<?php
/**
 * SEO Health Timeline.
 *
 * Captures a periodic snapshot of the site's SEO health so progress is visible
 * over time and correlatable with the actions taken. The health score is a
 * transparent blend of MEASURED site coverage (metadata, schema, content depth,
 * headings) — unknown components are excluded, never guessed — plus recorded GSC
 * clicks/impressions when connected. Snapshots are stored once per day.
 *
 * `compute_health()` is pure and unit-tested; `snapshot()`/`timeline()` persist
 * and read.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health timeline.
 */
class SCC_Health_Timeline {

	/**
	 * Component weights for the site health score.
	 *
	 * @return array<string,array{label:string,weight:int}>
	 */
	public static function weights() {
		return array(
			'metadata' => array( 'label' => __( 'Metadata coverage', 'seo-command-center' ), 'weight' => 25 ),
			'schema'   => array( 'label' => __( 'Schema coverage', 'seo-command-center' ), 'weight' => 25 ),
			'content'  => array( 'label' => __( 'Content depth', 'seo-command-center' ), 'weight' => 30 ),
			'headings' => array( 'label' => __( 'Heading structure', 'seo-command-center' ), 'weight' => 20 ),
		);
	}

	/**
	 * Compute a transparent site health score from analysis totals.
	 *
	 * @param array $totals Analyzer totals {analyzed, missing_meta, has_schema, thin_content, no_h1}.
	 * @return array {score:int, components:array}
	 */
	public static function compute_health( array $totals ) {
		$analyzed = (int) ( $totals['analyzed'] ?? 0 );
		$weights  = self::weights();

		if ( $analyzed <= 0 ) {
			$comps = array();
			foreach ( $weights as $k => $m ) {
				$comps[] = array( 'key' => $k, 'label' => $m['label'], 'weight' => $m['weight'], 'known' => false, 'pct' => 0 );
			}
			return array( 'score' => 0, 'components' => $comps );
		}

		$pcts = array(
			'metadata' => 100 - (int) round( 100 * min( $analyzed, (int) ( $totals['missing_meta'] ?? 0 ) ) / $analyzed ),
			'schema'   => (int) round( 100 * min( $analyzed, (int) ( $totals['has_schema'] ?? 0 ) ) / $analyzed ),
			'content'  => 100 - (int) round( 100 * min( $analyzed, (int) ( $totals['thin_content'] ?? 0 ) ) / $analyzed ),
			'headings' => 100 - (int) round( 100 * min( $analyzed, (int) ( $totals['no_h1'] ?? 0 ) ) / $analyzed ),
		);

		$total_weight = 0;
		$acc          = 0;
		$comps        = array();
		foreach ( $weights as $k => $m ) {
			$pct           = max( 0, min( 100, (int) $pcts[ $k ] ) );
			$total_weight += (int) $m['weight'];
			$acc          += (int) $m['weight'] * $pct;
			$comps[]       = array( 'key' => $k, 'label' => $m['label'], 'weight' => (int) $m['weight'], 'known' => true, 'pct' => $pct );
		}
		$score = $total_weight > 0 ? (int) round( $acc / $total_weight ) : 0;
		return array( 'score' => $score, 'components' => $comps );
	}

	/**
	 * Capture a snapshot now (idempotent per day unless forced).
	 *
	 * @param bool $force Capture even if one exists today.
	 * @return int|false Snapshot id, or false if skipped.
	 */
	public static function snapshot( $force = false ) {
		$today = gmdate( 'Y-m-d' );
		if ( ! $force ) {
			global $wpdb;
			$table = SCC_DB::table( 'seo_snapshots' );
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE captured_on = %s", $today ) ); // phpcs:ignore WordPress.DB
			if ( $exists > 0 ) {
				return false;
			}
		}

		$latest = class_exists( 'SCC_Analyzer' ) ? SCC_Analyzer::latest() : null;
		$totals = ( $latest && ! empty( $latest['summary_data']['totals'] ) ) ? $latest['summary_data']['totals'] : array();
		$health = self::compute_health( is_array( $totals ) ? $totals : array() );

		$open = 0;
		if ( class_exists( 'SCC_Opportunity_Engine' ) ) {
			$open = count( SCC_Opportunity_Engine::all() );
		}
		$completed = 0;
		if ( class_exists( 'SCC_Action_Queue' ) ) {
			$completed = count( SCC_Action_Queue::all( 'completed', 500 ) );
		}

		// GSC site totals (28d) when connected — real numbers only.
		$clicks = 0; $impr = 0; $pos = 0.0;
		if ( class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected() ) {
			$rows = SCC_GSC::query( '', array(), 28, 1 );
			if ( ! is_wp_error( $rows ) && ! empty( $rows[0] ) ) {
				$clicks = (int) ( $rows[0]['clicks'] ?? 0 );
				$impr   = (int) ( $rows[0]['impressions'] ?? 0 );
				$pos    = round( (float) ( $rows[0]['position'] ?? 0 ), 1 );
			}
		}

		return SCC_DB::insert(
			'seo_snapshots',
			array(
				'captured_on'        => $today,
				'created_at'         => current_time( 'mysql' ),
				'health_score'       => (int) $health['score'],
				'clicks'             => $clicks,
				'impressions'        => $impr,
				'avg_position'       => $pos,
				'opportunities_open' => (int) $open,
				'actions_completed'  => (int) $completed,
				'components'         => wp_json_encode( $health['components'] ),
				'meta'               => wp_json_encode( array( 'gsc' => ( class_exists( 'SCC_GSC' ) && SCC_GSC::is_connected() ) ) ),
			)
		);
	}

	/**
	 * Capture at most one snapshot per day (safe to call on admin load).
	 */
	public static function maybe_capture() {
		self::snapshot( false );
	}

	/**
	 * Read the timeline, oldest first (for charting).
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function timeline( $limit = 180 ) {
		global $wpdb;
		$table = SCC_DB::table( 'seo_snapshots' );
		$limit = max( 1, min( 730, (int) $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY captured_on DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows  = array_reverse( (array) $rows );
		foreach ( $rows as &$r ) {
			$r['health_score'] = (int) $r['health_score'];
			$r['clicks']       = (int) $r['clicks'];
			$r['impressions']  = (int) $r['impressions'];
			$r['components']   = json_decode( (string) ( $r['components'] ?? '' ), true ) ?: array();
		}
		unset( $r );
		return $rows;
	}
}
