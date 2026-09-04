<?php
/**
 * Search Intent Drift (GSC-only).
 *
 * True intent drift means the KIND of query a page satisfies is changing. Without
 * historical SERP snapshots we infer this from Search Console alone: for each
 * page we take the REAL queries it earns impressions for, classify each query's
 * intent from its wording, weight by impressions, and compare the intent mix of
 * the recent window against the prior window. When the dominant intent flips with
 * a meaningful baseline, that is an observable drift.
 *
 * Honesty: the query/impression data is verified GSC data; the per-query intent
 * classification is a transparent wording heuristic, so drift findings are marked
 * "partial" confidence, never "verified". When GSC is not connected it reports so
 * and returns nothing — never fabricated.
 *
 * `classify_intent()` and `analyze()` are pure and unit-tested; `detect()` pulls
 * the live GSC data and caches the result.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intent drift engine.
 */
class SCC_Intent_Drift {

	const CACHE_KEY = 'scc_intent_drift_v1';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	const MIN_IMPRESSIONS = 200; // Per-page per-window baseline to trust a mix.
	const MIN_SHIFT        = 0.15; // Dominant-intent share must move >=15 points.
	const MIN_DOMINANT     = 0.40; // The new dominant intent must hold >=40% share.

	// Wording lexicons (lowercased, word-ish matching).
	const COMMERCIAL = array( 'best', 'top', 'review', 'reviews', 'vs', 'versus', 'compare', 'comparison', 'price', 'pricing', 'cost', 'quote', 'cheap', 'affordable', 'deal', 'deals', 'buy', 'order', 'hire', 'company', 'companies', 'service', 'services', 'agency', 'agencies', 'for sale', 'discount', 'package', 'packages' );
	// Only strong geo-intent markers. "local" alone is a topic word (e.g. "local
	// SEO"), not a geo signal, so it is deliberately excluded.
	const LOCAL       = array( 'near me', 'nearby' );
	const INFO        = array( 'how', 'what', 'why', 'when', 'who', 'guide', 'guides', 'tips', 'tip', 'ideas', 'idea', 'examples', 'example', 'tutorial', 'meaning', 'definition', 'learn', 'checklist', 'template', 'templates' );

	/**
	 * Classify a single query's dominant intent from its wording.
	 *
	 * @param string $query Query text.
	 * @return string informational|commercial|local|unspecified
	 */
	public static function classify_intent( $query ) {
		$q = ' ' . strtolower( trim( (string) $query ) ) . ' ';
		if ( '' === trim( $q ) ) {
			return 'unspecified';
		}

		foreach ( self::LOCAL as $needle ) {
			if ( false !== strpos( $q, $needle ) ) {
				return 'local';
			}
		}
		foreach ( self::COMMERCIAL as $needle ) {
			if ( false !== strpos( $q, ' ' . $needle . ' ' ) || false !== strpos( $q, ' ' . $needle ) ) {
				return 'commercial';
			}
		}
		foreach ( self::INFO as $needle ) {
			if ( false !== strpos( $q, ' ' . $needle . ' ' ) || false !== strpos( $q, ' ' . $needle ) ) {
				return 'informational';
			}
		}
		return 'unspecified';
	}

	/**
	 * Intent distribution (share by impressions) for a set of queries.
	 *
	 * @param array $queries Each {query, impressions}.
	 * @return array {dist:array intent=>share, total:int, dominant:string}
	 */
	public static function distribution( array $queries ) {
		$buckets = array( 'informational' => 0, 'commercial' => 0, 'local' => 0, 'unspecified' => 0 );
		$total   = 0;
		foreach ( $queries as $q ) {
			$impr = (int) ( $q['impressions'] ?? 0 );
			if ( $impr <= 0 ) {
				continue;
			}
			$buckets[ self::classify_intent( $q['query'] ?? '' ) ] += $impr;
			$total += $impr;
		}
		$dist = array();
		foreach ( $buckets as $k => $v ) {
			$dist[ $k ] = $total > 0 ? $v / $total : 0.0;
		}
		// Dominant among the meaningful intents (ignore "unspecified" for the label).
		$dominant = 'unspecified';
		$best     = -1;
		foreach ( array( 'informational', 'commercial', 'local' ) as $k ) {
			if ( $dist[ $k ] > $best ) {
				$best     = $dist[ $k ];
				$dominant = $k;
			}
		}
		return array( 'dist' => $dist, 'total' => $total, 'dominant' => $dominant );
	}

	/**
	 * Pure drift analysis over per-page query mixes for two windows.
	 *
	 * @param array $pages Each {url, post_id, title, prev_queries:[{query,impressions}], curr_queries:[...]}.
	 * @return array Drift records, biggest shift first.
	 */
	public static function analyze( array $pages ) {
		$items = array();

		foreach ( $pages as $p ) {
			$prev = self::distribution( (array) ( $p['prev_queries'] ?? array() ) );
			$curr = self::distribution( (array) ( $p['curr_queries'] ?? array() ) );

			// Need a trustworthy baseline in BOTH windows.
			if ( $prev['total'] < self::MIN_IMPRESSIONS || $curr['total'] < self::MIN_IMPRESSIONS ) {
				continue;
			}

			$prev_dom = $prev['dominant'];
			$curr_dom = $curr['dominant'];

			// Total-variation distance between the two mixes (0..1) — the magnitude.
			$tvd = 0.0;
			foreach ( $curr['dist'] as $k => $share ) {
				$tvd += abs( $share - ( $prev['dist'][ $k ] ?? 0 ) );
			}
			$tvd = $tvd / 2;

			// Drift requires the dominant label to FLIP, the new dominant to hold a
			// real share, and its share to have moved by a meaningful amount.
			$share_shift = ( $curr['dist'][ $curr_dom ] ?? 0 ) - ( $prev['dist'][ $curr_dom ] ?? 0 );
			$drifted = ( $prev_dom !== $curr_dom )
				&& ( ( $curr['dist'][ $curr_dom ] ?? 0 ) >= self::MIN_DOMINANT )
				&& ( $share_shift >= self::MIN_SHIFT );

			if ( ! $drifted ) {
				continue;
			}

			$severity   = (int) round( min( 100, $tvd * 120 ) );
			$confidence = (int) min( 85, 50 + (int) round( $tvd * 60 ) + ( min( $prev['total'], $curr['total'] ) >= 1000 ? 10 : 0 ) );

			$items[] = array(
				'url'          => (string) ( $p['url'] ?? '' ),
				'post_id'      => (int) ( $p['post_id'] ?? 0 ),
				'title'        => (string) ( $p['title'] ?? $p['url'] ?? '' ),
				'prev_dominant'=> $prev_dom,
				'curr_dominant'=> $curr_dom,
				'prev_dist'    => self::pct( $prev['dist'] ),
				'curr_dist'    => self::pct( $curr['dist'] ),
				'shift'        => round( $tvd, 3 ),
				'severity'     => $severity,
				'confidence'   => $confidence,
				'recommendation' => self::recommendation( $prev_dom, $curr_dom ),
			);
		}

		usort( $items, function ( $a, $b ) {
			return $b['severity'] <=> $a['severity'];
		} );

		return $items;
	}

	/**
	 * Detect intent drift from live GSC data (cached).
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

		$days = 90;
		$now  = time();
		$recent = SCC_GSC::query_range( '', array( 'page', 'query' ), gmdate( 'Y-m-d', $now - $days * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now ), 20000 );
		if ( is_wp_error( $recent ) ) {
			return array( 'available' => false, 'reason' => $recent->get_error_message(), 'days' => $days, 'items' => array() );
		}
		$prev = SCC_GSC::query_range( '', array( 'page', 'query' ), gmdate( 'Y-m-d', $now - ( 2 * $days + 1 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now - ( $days + 1 ) * DAY_IN_SECONDS ), 20000 );
		if ( is_wp_error( $prev ) ) {
			return array( 'available' => false, 'reason' => $prev->get_error_message(), 'days' => $days, 'items' => array() );
		}

		$pages = self::group_by_page( $recent, $prev );
		$items = self::analyze( $pages );

		$out = array( 'available' => true, 'days' => $days, 'items' => $items );
		set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Group [page, query] rows from both windows into per-page query mixes.
	 *
	 * @param array $recent Recent rows.
	 * @param array $prev   Prior rows.
	 * @return array
	 */
	protected static function group_by_page( array $recent, array $prev ) {
		$pages = array();
		$fold  = function ( $rows, $which ) use ( &$pages ) {
			foreach ( (array) $rows as $row ) {
				$url   = (string) ( $row['keys'][0] ?? '' );
				$query = (string) ( $row['keys'][1] ?? '' );
				if ( '' === $url || '' === $query ) {
					continue;
				}
				if ( ! isset( $pages[ $url ] ) ) {
					$pages[ $url ] = array( 'url' => $url, 'prev_queries' => array(), 'curr_queries' => array() );
				}
				$pages[ $url ][ $which . '_queries' ][] = array( 'query' => $query, 'impressions' => (int) ( $row['impressions'] ?? 0 ) );
			}
		};
		$fold( $recent, 'curr' );
		$fold( $prev, 'prev' );

		foreach ( $pages as $url => &$p ) {
			$post_id     = function_exists( 'url_to_postid' ) ? (int) url_to_postid( $url ) : 0;
			$p['post_id']= $post_id;
			$p['title']  = $post_id ? get_the_title( $post_id ) : $url;
		}
		unset( $p );

		return array_values( $pages );
	}

	/**
	 * A recommendation for a given intent transition.
	 *
	 * @param string $from Previous dominant intent.
	 * @param string $to   Current dominant intent.
	 * @return string
	 */
	protected static function recommendation( $from, $to ) {
		if ( 'informational' === $from && in_array( $to, array( 'commercial', 'local' ), true ) ) {
			return __( 'Searches for this page now lean commercial. Consider adding a comparison, pricing/what-you-get and a clear call to action, or point the demand to a service page.', 'seo-command-center' );
		}
		if ( in_array( $from, array( 'commercial', 'local' ), true ) && 'informational' === $to ) {
			return __( 'Searches for this page now lean informational. Consider leading with a helpful guide/explainer and moving the sales pitch lower.', 'seo-command-center' );
		}
		if ( 'commercial' === $from && 'local' === $to ) {
			return __( 'Searches now show local intent. Add location signals (service areas, address, local proof) and consider a location-specific page.', 'seo-command-center' );
		}
		if ( 'local' === $from && 'commercial' === $to ) {
			return __( 'Searches are broadening beyond local. Strengthen the core commercial content and comparisons.', 'seo-command-center' );
		}
		return __( 'The mix of searches this page serves has shifted. Review the page against the new dominant intent.', 'seo-command-center' );
	}

	/**
	 * Convert a share map to whole-percent integers for display.
	 *
	 * @param array $dist Share map.
	 * @return array
	 */
	protected static function pct( array $dist ) {
		$out = array();
		foreach ( $dist as $k => $v ) {
			$out[ $k ] = (int) round( $v * 100 );
		}
		return $out;
	}

	/**
	 * Bust the cache.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
