<?php
/**
 * Content Decay Engine.
 *
 * Detects pages whose Search Console performance is genuinely declining by
 * comparing two consecutive windows (recent vs. the period immediately before
 * it). It is deliberately conservative: a page is only flagged as decaying when
 * there is a MEANINGFUL BASELINE and a SIGNIFICANT drop — normal week-to-week
 * fluctuation is never called "decay". When GSC is not connected it reports that
 * honestly and returns no data (never fabricated).
 *
 * The scoring/threshold logic in `analyze()` is pure and unit-tested; `detect()`
 * pulls the live GSC comparison, maps URLs to posts, and caches the result.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content decay engine.
 */
class SCC_Content_Decay {

	const CACHE_KEY = 'scc_content_decay_v1';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	// Confidence thresholds — a drop must clear these to count as decay.
	const MIN_BASELINE_CLICKS = 10;   // Prior-period clicks needed to trust a click drop.
	const MIN_BASELINE_IMPR   = 300;  // Prior-period impressions needed to trust an impressions/position drop.
	const CLICK_DROP          = 0.30; // 30% fewer clicks.
	const IMPR_DROP           = 0.30; // 30% fewer impressions.
	const POS_DROP            = 3.0;  // Average position worsened by 3+.
	const STALE_MONTHS        = 12;   // Content not updated in a year is "stale".

	/**
	 * Detect decaying pages from live GSC data (cached).
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array {available:bool, reason?:string, days:int, items:array}
	 */
	public static function detect( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			$out = array( 'available' => false, 'reason' => 'gsc_not_connected', 'days' => 90, 'items' => array() );
			set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
			return $out;
		}

		$pages = SCC_GSC::compare_pages( '', 90 );
		if ( is_wp_error( $pages ) ) {
			return array( 'available' => false, 'reason' => $pages->get_error_message(), 'days' => 90, 'items' => array() );
		}

		// Enrich each page with post id + last-modified age, then analyze.
		$enriched = array();
		foreach ( (array) $pages as $p ) {
			$post_id = function_exists( 'url_to_postid' ) ? (int) url_to_postid( (string) $p['url'] ) : 0;
			$p['post_id']     = $post_id;
			$p['title']       = $post_id ? get_the_title( $post_id ) : (string) $p['url'];
			$p['age_months']  = $post_id ? self::months_since_modified( $post_id ) : null;
			$enriched[]       = $p;
		}

		$items = self::analyze( $enriched );

		$out = array( 'available' => true, 'days' => 90, 'items' => $items );
		set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Pure decay analysis over a set of page period-comparisons.
	 *
	 * @param array $pages Each: {url, post_id, title, curr_clicks, prev_clicks, curr_impr, prev_impr, curr_pos, prev_pos, age_months?}.
	 * @return array Decay records, worst first.
	 */
	public static function analyze( array $pages ) {
		$items = array();

		foreach ( $pages as $p ) {
			$prev_clicks = (int) ( $p['prev_clicks'] ?? 0 );
			$curr_clicks = (int) ( $p['curr_clicks'] ?? 0 );
			$prev_impr   = (int) ( $p['prev_impr'] ?? 0 );
			$curr_impr   = (int) ( $p['curr_impr'] ?? 0 );
			$prev_pos    = (float) ( $p['prev_pos'] ?? 0 );
			$curr_pos    = (float) ( $p['curr_pos'] ?? 0 );

			$has_click_baseline = $prev_clicks >= self::MIN_BASELINE_CLICKS;
			$has_impr_baseline  = $prev_impr >= self::MIN_BASELINE_IMPR;

			// No trustworthy baseline → never call it decay (avoid noise).
			if ( ! $has_click_baseline && ! $has_impr_baseline ) {
				continue;
			}

			$click_change = $prev_clicks > 0 ? ( $curr_clicks - $prev_clicks ) / $prev_clicks : 0.0;
			$impr_change  = $prev_impr > 0 ? ( $curr_impr - $prev_impr ) / $prev_impr : 0.0;
			$pos_change   = ( $prev_pos > 0 && $curr_pos > 0 ) ? ( $curr_pos - $prev_pos ) : 0.0; // + = worse.

			$causes   = array();
			$is_decay = false;

			if ( $has_click_baseline && $click_change <= -self::CLICK_DROP ) {
				$is_decay = true;
				$causes[] = array( 'code' => 'clicks_down', 'label' => sprintf( 'Clicks down %d%% (%d → %d)', (int) round( abs( $click_change ) * 100 ), $prev_clicks, $curr_clicks ) );
			}
			if ( $has_impr_baseline && $impr_change <= -self::IMPR_DROP ) {
				$is_decay = true;
				$causes[] = array( 'code' => 'impressions_down', 'label' => sprintf( 'Impressions down %d%% (%s → %s)', (int) round( abs( $impr_change ) * 100 ), self::fmt( $prev_impr ), self::fmt( $curr_impr ) ) );
			}
			if ( $has_impr_baseline && $pos_change >= self::POS_DROP ) {
				$is_decay = true;
				$causes[] = array( 'code' => 'rankings_declining', 'label' => sprintf( 'Average position worsened by %.1f (%.1f → %.1f)', $pos_change, $prev_pos, $curr_pos ) );
			}

			if ( ! $is_decay ) {
				continue;
			}

			// Staleness is a contributing cause, not a trigger on its own.
			$age = isset( $p['age_months'] ) ? $p['age_months'] : null;
			if ( null !== $age && $age >= self::STALE_MONTHS ) {
				$causes[] = array( 'code' => 'stale', 'label' => sprintf( 'Not updated in %d months', (int) $age ) );
			}

			// Severity: magnitude of the worst drop, weighted by baseline size.
			$magnitude = max( abs( min( 0, $click_change ) ), abs( min( 0, $impr_change ) ) );
			$baseline  = max( $prev_clicks * 3, $prev_impr );
			$severity  = (int) round( min( 100, $magnitude * 70 + min( 30, $baseline / 100 ) ) );

			// Confidence: bigger baseline + more corroborating causes = more certain.
			$confidence = min( 100, 55 + ( $has_click_baseline ? 15 : 0 ) + ( $has_impr_baseline ? 15 : 0 ) + ( count( $causes ) - 1 ) * 5 );

			$items[] = array(
				'url'          => (string) ( $p['url'] ?? '' ),
				'post_id'      => (int) ( $p['post_id'] ?? 0 ),
				'title'        => (string) ( $p['title'] ?? $p['url'] ?? '' ),
				'click_change' => round( $click_change, 3 ),
				'impr_change'  => round( $impr_change, 3 ),
				'pos_change'   => round( $pos_change, 1 ),
				'metrics'      => array(
					'prev_clicks' => $prev_clicks, 'curr_clicks' => $curr_clicks,
					'prev_impr'   => $prev_impr,   'curr_impr'   => $curr_impr,
					'prev_pos'    => $prev_pos,    'curr_pos'    => $curr_pos,
				),
				'causes'       => $causes,
				'severity'     => $severity,
				'confidence'   => (int) $confidence,
				'refresh_plan' => self::refresh_plan( $causes ),
			);
		}

		usort( $items, function ( $a, $b ) {
			return $b['severity'] <=> $a['severity'];
		} );

		return $items;
	}

	/**
	 * A concrete refresh checklist derived from the detected causes.
	 *
	 * @param array $causes Cause records.
	 * @return string[]
	 */
	protected static function refresh_plan( array $causes ) {
		$codes = array_map( function ( $c ) { return $c['code']; }, $causes );
		$plan  = array();
		if ( in_array( 'rankings_declining', $codes, true ) ) {
			$plan[] = __( 'Refresh the content for depth and current accuracy; competitors may have published newer material.', 'seo-command-center' );
			$plan[] = __( 'Add supporting internal links to the page.', 'seo-command-center' );
		}
		if ( in_array( 'impressions_down', $codes, true ) ) {
			$plan[] = __( 'Expand coverage of the queries this page used to appear for; add missing questions and entities.', 'seo-command-center' );
		}
		if ( in_array( 'clicks_down', $codes, true ) ) {
			$plan[] = __( 'Review the title and meta description for click-through; the snippet may have weakened.', 'seo-command-center' );
		}
		if ( in_array( 'stale', $codes, true ) ) {
			$plan[] = __( 'Update statistics, examples and dates so the page reads as current.', 'seo-command-center' );
		}
		if ( empty( $plan ) ) {
			$plan[] = __( 'Review and refresh the page against current search intent.', 'seo-command-center' );
		}
		return $plan;
	}

	/**
	 * Months since a post was last modified.
	 *
	 * @param int $post_id Post id.
	 * @return float
	 */
	protected static function months_since_modified( $post_id ) {
		$modified = get_post_modified_time( 'U', true, (int) $post_id );
		if ( ! $modified ) {
			return 0.0;
		}
		return round( ( time() - (int) $modified ) / ( 30 * DAY_IN_SECONDS ), 1 );
	}

	/**
	 * Locale-friendly integer.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	protected static function fmt( $n ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( (int) $n ) : (string) (int) $n;
	}

	/**
	 * Bust the cache.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
