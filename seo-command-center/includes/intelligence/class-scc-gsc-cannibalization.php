<?php
/**
 * Search Console cannibalization intelligence.
 *
 * Detects queries where Google is splitting impressions across multiple URLs.
 * This is evidence-led: no Search Console connection means no fabricated result.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_GSC_Cannibalization {
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function detect( $refresh = false, $days = 90 ) {
		$days = max( 28, min( 180, (int) $days ) );
		if ( ! class_exists( 'SCC_GSC' ) || ! SCC_GSC::is_connected() ) {
			return array( 'available' => false, 'reason' => 'gsc_not_connected', 'groups' => array() );
		}
		$key = 'scc_gsc_cannibal_' . $days;
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) { return $cached; }
		}
		$rows = SCC_GSC::query( '', array( 'query', 'page' ), $days, 25000 );
		if ( is_wp_error( $rows ) ) {
			return array( 'available' => false, 'reason' => $rows->get_error_message(), 'groups' => array() );
		}
		$out = array(
			'available'    => true,
			'days'         => $days,
			'groups'       => self::analyze_rows( (array) $rows ),
			'generated_at' => current_time( 'mysql' ),
		);
		set_transient( $key, $out, self::CACHE_TTL );
		return $out;
	}

	public static function analyze_rows( array $rows, $min_total_impressions = 30 ) {
		$queries = array();
		foreach ( $rows as $row ) {
			$keys = (array) ( $row['keys'] ?? array() );
			$query = trim( (string) ( $keys[0] ?? '' ) );
			$url   = trim( (string) ( $keys[1] ?? '' ) );
			if ( '' === $query || '' === $url ) { continue; }

			$qkey = self::normalize_query( $query );
			$ukey = untrailingslashit( $url );
			if ( '' === $qkey || '' === $ukey ) { continue; }
			if ( ! isset( $queries[ $qkey ] ) ) {
				$queries[ $qkey ] = array( 'query' => $query, 'pages' => array() );
			}
			if ( ! isset( $queries[ $qkey ]['pages'][ $ukey ] ) ) {
				$queries[ $qkey ]['pages'][ $ukey ] = array(
					'url' => $url, 'clicks' => 0, 'impressions' => 0,
					'weighted_position' => 0.0, 'position_weight' => 0,
				);
			}
			$impr = max( 0, (int) ( $row['impressions'] ?? 0 ) );
			$clicks = max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$pos = max( 0.0, (float) ( $row['position'] ?? 0 ) );
			$page =& $queries[ $qkey ]['pages'][ $ukey ];
			$page['clicks'] += $clicks;
			$page['impressions'] += $impr;
			if ( $pos > 0 && $impr > 0 ) {
				$page['weighted_position'] += $pos * $impr;
				$page['position_weight'] += $impr;
			}
			unset( $page );
		}

		$groups = array();
		foreach ( $queries as $bucket ) {
			if ( count( $bucket['pages'] ) < 2 ) { continue; }
			$pages = array_values( $bucket['pages'] );
			$total_impr = 0;
			$total_clicks = 0;
			foreach ( $pages as &$page ) {
				$page['position'] = $page['position_weight'] > 0
					? round( $page['weighted_position'] / $page['position_weight'], 1 ) : 0.0;
				unset( $page['weighted_position'], $page['position_weight'] );
				$total_impr += (int) $page['impressions'];
				$total_clicks += (int) $page['clicks'];
				$page['strength'] = self::page_strength( $page );
			}
			unset( $page );
			if ( $total_impr < (int) $min_total_impressions ) { continue; }

			usort( $pages, function ( $a, $b ) {
				if ( $a['strength'] === $b['strength'] ) {
					return $b['impressions'] <=> $a['impressions'];
				}
				return $b['strength'] <=> $a['strength'];
			} );

			$keeper = $pages[0];
			$secondary_impr = $total_impr - (int) $keeper['impressions'];
			$secondary_share = $total_impr > 0 ? $secondary_impr / $total_impr : 0;
			$second = $pages[1];
			$position_gap = ( $keeper['position'] > 0 && $second['position'] > 0 )
				? abs( $keeper['position'] - $second['position'] ) : 99;

			$risk = 'low';
			if ( $secondary_share >= 0.30 && $position_gap <= 10 ) {
				$risk = 'high';
			} elseif ( $secondary_share >= 0.15 || (int) $second['impressions'] >= 25 ) {
				$risk = 'medium';
			}
			if ( 'low' === $risk && $total_impr < 100 ) { continue; }

			$merge = array();
			$redirect = array();
			foreach ( array_slice( $pages, 1 ) as $p ) {
				$share = $total_impr > 0 ? (int) $p['impressions'] / $total_impr : 0;
				if ( (int) $p['clicks'] <= 1 && $share < 0.15 ) {
					$redirect[] = $p['url'];
				} else {
					$merge[] = $p['url'];
				}
			}

			$groups[] = array(
				'query'             => $bucket['query'],
				'risk'              => $risk,
				'total_impressions' => $total_impr,
				'total_clicks'      => $total_clicks,
				'secondary_share'   => round( $secondary_share, 3 ),
				'keeper'            => $keeper,
				'pages'             => $pages,
				'merge_candidates'  => $merge,
				'redirect_candidates' => $redirect,
				'recommendation'    => self::recommendation( $keeper, $merge, $redirect ),
			);
		}
		$rank = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
		usort( $groups, function ( $a, $b ) use ( $rank ) {
			$sev = ( $rank[ $b['risk'] ] ?? 0 ) <=> ( $rank[ $a['risk'] ] ?? 0 );
			return 0 !== $sev ? $sev : $b['total_impressions'] <=> $a['total_impressions'];
		} );
		return $groups;
	}

	public static function for_url( $url, $refresh = false ) {
		$needle = untrailingslashit( (string) $url );
		$report = self::detect( $refresh );
		if ( empty( $report['available'] ) ) { return array(); }
		$out = array();
		foreach ( (array) $report['groups'] as $group ) {
			foreach ( (array) $group['pages'] as $page ) {
				if ( untrailingslashit( (string) $page['url'] ) === $needle ) {
					$out[] = $group;
					break;
				}
			}
		}
		return $out;
	}

	protected static function page_strength( array $page ) {
		$pos = max( 1.0, (float) ( $page['position'] ?? 100 ) );
		return round(
			( (int) ( $page['clicks'] ?? 0 ) * 20 )
			+ ( (int) ( $page['impressions'] ?? 0 ) / $pos ),
			2
		);
	}

	protected static function recommendation( array $keeper, array $merge, array $redirect ) {
		$parts = array( 'Keep ' . (string) $keeper['url'] . ' as the primary URL.' );
		if ( $merge ) { $parts[] = 'Review useful sections on ' . count( $merge ) . ' competing URL(s) for consolidation into the keeper.'; }
		if ( $redirect ) { $parts[] = 'Review ' . count( $redirect ) . ' very weak URL(s) as possible redirect candidates after content/backlink checks.'; }
		$parts[] = 'Point relevant internal links at the keeper and differentiate any page that remains live.';
		return implode( ' ', $parts );
	}

	protected static function normalize_query( $query ) {
		$query = strtolower( trim( (string) $query ) );
		$query = preg_replace( '/[^a-z0-9\s]+/i', ' ', $query );
		return trim( preg_replace( '/\s+/', ' ', $query ) );
	}
}
