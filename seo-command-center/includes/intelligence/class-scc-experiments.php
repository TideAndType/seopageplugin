<?php
/**
 * SEO Experiments.
 *
 * Records a change to a page, captures its GSC baseline, and after a measurement
 * window compares performance so the user can see whether the change moved the
 * needle. It never claims causation — results are expressed as correlation
 * ("likely improvement", "inconclusive", "negative movement") with a confidence,
 * and only when GSC data exists for both windows.
 *
 * `evaluate_result()` is pure and unit-tested; the rest persists/reads.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Experiments service.
 */
class SCC_Experiments {

	const STATUSES     = array( 'running', 'measuring', 'complete', 'abandoned' );
	const CHANGE_TYPES = array( 'title', 'meta_description', 'content_expansion', 'internal_links', 'schema', 'consolidation', 'refresh', 'other' );

	/**
	 * List experiments.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function all( $limit = 200 ) {
		global $wpdb;
		$table = SCC_DB::table( 'seo_experiments' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Find one experiment.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		$row = SCC_DB::get( 'seo_experiments', (int) $id );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Start an experiment: record the change + capture the GSC baseline.
	 *
	 * @param array $raw {post_id, change_type, note, measure_days}.
	 * @return int|WP_Error
	 */
	public static function start( array $raw ) {
		$post_id = (int) ( $raw['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'scc_no_post', __( 'Choose a valid page for the experiment.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$change = sanitize_key( (string) ( $raw['change_type'] ?? 'other' ) );
		$change = in_array( $change, self::CHANGE_TYPES, true ) ? $change : 'other';
		$days   = SCC_Security::sanitize_int( $raw['measure_days'] ?? 28, 7, 120 );

		$baseline = self::capture_metrics( get_permalink( $post_id ) );
		$now      = current_time( 'mysql' );

		$id = SCC_DB::insert(
			'seo_experiments',
			array(
				'post_id'      => $post_id,
				'url'          => get_permalink( $post_id ),
				'title'        => get_the_title( $post_id ),
				'change_type'  => $change,
				'note'         => SCC_Security::sanitize_textarea( $raw['note'] ?? '' ),
				'status'       => 'running',
				'baseline'     => wp_json_encode( $baseline ),
				'result'       => null,
				'measure_days' => $days,
				'start_date'   => gmdate( 'Y-m-d' ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		return $id ? (int) $id : new WP_Error( 'scc_create_failed', __( 'Could not start the experiment.', 'seo-command-center' ), array( 'status' => 500 ) );
	}

	/**
	 * Evaluate an experiment now: compare current GSC metrics to the baseline.
	 *
	 * @param int $id Experiment id.
	 * @return array|WP_Error
	 */
	public static function evaluate( $id ) {
		$exp = self::find( $id );
		if ( ! $exp ) {
			return new WP_Error( 'scc_no_exp', __( 'Experiment not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		$current = self::capture_metrics( (string) $exp['url'] );
		$result  = self::evaluate_result( (array) $exp['baseline'], $current, (int) $exp['measure_days'], (string) $exp['start_date'] );

		SCC_DB::update( 'seo_experiments', array(
			'result'     => wp_json_encode( $result ),
			'status'     => $result['status'],
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => (int) $id ) );

		return array_merge( $exp, array( 'result' => $result, 'status' => $result['status'] ) );
	}

	/**
	 * Pure evaluation: compare baseline vs current, using correlation language.
	 *
	 * @param array  $baseline   {clicks, impressions, position, available}.
	 * @param array  $current    Same shape.
	 * @param int    $measure_days Window.
	 * @param string $start_date Y-m-d.
	 * @return array
	 */
	public static function evaluate_result( array $baseline, array $current, $measure_days, $start_date ) {
		$b_ok = ! empty( $baseline['available'] );
		$c_ok = ! empty( $current['available'] );

		if ( ! $b_ok || ! $c_ok ) {
			return array(
				'status'      => 'measuring',
				'verdict'     => 'no_data',
				'label'       => __( 'Data unavailable', 'seo-command-center' ),
				'detail'      => __( 'Search Console data is not available for both the baseline and now, so this change cannot be measured.', 'seo-command-center' ),
				'confidence'  => 0,
				'deltas'      => array(),
			);
		}

		$elapsed = $start_date ? floor( ( time() - strtotime( $start_date . ' 00:00:00' ) ) / DAY_IN_SECONDS ) : 0;
		$ready   = $elapsed >= (int) $measure_days;

		$b_clicks = (int) ( $baseline['clicks'] ?? 0 );
		$c_clicks = (int) ( $current['clicks'] ?? 0 );
		$b_impr   = (int) ( $baseline['impressions'] ?? 0 );
		$c_impr   = (int) ( $current['impressions'] ?? 0 );
		$b_pos    = (float) ( $baseline['position'] ?? 0 );
		$c_pos    = (float) ( $current['position'] ?? 0 );

		$click_delta = $b_clicks > 0 ? ( $c_clicks - $b_clicks ) / $b_clicks : 0.0;
		$impr_delta  = $b_impr > 0 ? ( $c_impr - $b_impr ) / $b_impr : 0.0;
		$pos_delta   = ( $b_pos > 0 && $c_pos > 0 ) ? ( $b_pos - $c_pos ) : 0.0; // + = improved (lower position number).

		// Verdict from clicks primarily, with position as corroboration.
		$verdict = 'inconclusive';
		$label   = __( 'Inconclusive', 'seo-command-center' );
		if ( $click_delta >= 0.10 || ( $pos_delta >= 1.0 && $click_delta >= 0 ) ) {
			$verdict = 'positive';
			$label   = __( 'Likely improvement (positive correlation)', 'seo-command-center' );
		} elseif ( $click_delta <= -0.15 || $pos_delta <= -1.5 ) {
			$verdict = 'negative';
			$label   = __( 'Negative movement', 'seo-command-center' );
		}

		// Confidence grows with baseline size + elapsed measurement time.
		$confidence = (int) min( 90, 30 + ( $b_clicks >= 50 ? 25 : ( $b_clicks >= 10 ? 12 : 0 ) ) + ( $ready ? 25 : 0 ) + ( abs( $click_delta ) >= 0.25 ? 10 : 0 ) );

		return array(
			'status'     => $ready ? 'complete' : 'measuring',
			'verdict'    => $verdict,
			'label'      => $label,
			'detail'     => sprintf(
				/* translators: 1: clicks% 2: impressions% 3: position delta */
				__( 'Clicks %1$s, impressions %2$s, average position %3$s vs baseline. This is a correlation over the measurement window, not proof of causation.', 'seo-command-center' ),
				self::signed_pct( $click_delta ),
				self::signed_pct( $impr_delta ),
				( $pos_delta > 0 ? '+' : '' ) . round( $pos_delta, 1 )
			),
			'confidence' => $confidence,
			'deltas'     => array(
				'clicks'      => round( $click_delta, 3 ),
				'impressions' => round( $impr_delta, 3 ),
				'position'    => round( $pos_delta, 1 ),
				'elapsed_days'=> (int) $elapsed,
				'ready'       => $ready,
			),
		);
	}

	/**
	 * Delete an experiment.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return false !== $wpdb->delete( SCC_DB::table( 'seo_experiments' ), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Capture GSC metrics for a URL (28d), honestly marking availability.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	protected static function capture_metrics( $url ) {
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			return array( 'available' => false );
		}
		$m = SCC_GSC::page_metrics( $url, 28 );
		if ( ! is_array( $m ) ) {
			return array( 'available' => false );
		}
		return array(
			'available'   => true,
			'clicks'      => (int) ( $m['clicks'] ?? 0 ),
			'impressions' => (int) ( $m['impressions'] ?? 0 ),
			'position'    => (float) ( $m['position'] ?? 0 ),
			'captured_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Signed percentage string.
	 *
	 * @param float $ratio Ratio.
	 * @return string
	 */
	protected static function signed_pct( $ratio ) {
		$p = (int) round( $ratio * 100 );
		return ( $p > 0 ? '+' : '' ) . $p . '%';
	}

	/**
	 * Decode a DB row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	protected static function hydrate( array $row ) {
		$row['id']       = (int) $row['id'];
		$row['post_id']  = (int) $row['post_id'];
		$row['baseline'] = json_decode( (string) ( $row['baseline'] ?? '' ), true ) ?: array();
		$row['result']   = ! empty( $row['result'] ) ? json_decode( (string) $row['result'], true ) : null;
		return $row;
	}
}
