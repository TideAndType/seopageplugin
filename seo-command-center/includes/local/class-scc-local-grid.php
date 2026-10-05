<?php
/**
 * Google Maps geo-grid rank tracker powered by DataForSEO.
 *
 * Each point is measured independently at its own map coordinate; no rank is
 * estimated. Results are cached to avoid accidental repeated API spend.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Local_Grid {
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const LAST_OPTION = 'scc_local_grid_last';

	public static function grid_points( $lat, $lng, $size = 3, $spacing_km = 1.0 ) {
		$lat = (float) $lat;
		$lng = (float) $lng;
		$size = in_array( (int) $size, array( 3, 5 ), true ) ? (int) $size : 3;
		$spacing_km = max( 0.2, min( 10.0, (float) $spacing_km ) );
		$half = ( $size - 1 ) / 2;
		$lat_step = $spacing_km / 110.574;
		$cos = max( 0.1, cos( deg2rad( $lat ) ) );
		$lng_step = $spacing_km / ( 111.320 * $cos );

		$points = array();
		for ( $row = 0; $row < $size; $row++ ) {
			for ( $col = 0; $col < $size; $col++ ) {
				$points[] = array(
					'row' => $row,
					'col' => $col,
					'lat' => round( $lat + ( ( $half - $row ) * $lat_step ), 7 ),
					'lng' => round( $lng + ( ( $col - $half ) * $lng_step ), 7 ),
				);
			}
		}
		return $points;
	}

	public static function scan( array $args, $refresh = false ) {
		if ( ! class_exists( 'SCC_DataForSEO' ) || ! SCC_DataForSEO::is_connected() ) {
			return new WP_Error( 'scc_grid_no_provider', __( 'Connect DataForSEO before running a Maps grid scan.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$keyword = sanitize_text_field( $args['keyword'] ?? '' );
		$name = sanitize_text_field( $args['business_name'] ?? '' );
		$domain = self::normalize_domain( $args['domain'] ?? home_url( '/' ) );
		$lat = (float) ( $args['lat'] ?? 0 );
		$lng = (float) ( $args['lng'] ?? 0 );
		$size = in_array( (int) ( $args['size'] ?? 3 ), array( 3, 5 ), true ) ? (int) $args['size'] : 3;
		$spacing = max( 0.2, min( 10.0, (float) ( $args['spacing_km'] ?? 1.0 ) ) );

		if ( '' === $keyword || ( '' === $name && '' === $domain ) || 0.0 === $lat || 0.0 === $lng ) {
			return new WP_Error( 'scc_grid_input', __( 'Keyword, coordinates, and a business name or domain are required.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$key = 'scc_grid_' . md5( wp_json_encode( array( $keyword, $name, $domain, $lat, $lng, $size, $spacing ) ) );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) { $cached['cached'] = true; return $cached; }
		}

		$points = self::grid_points( $lat, $lng, $size, $spacing );
		$found = 0;
		$rank_sum = 0;
		foreach ( $points as &$point ) {
			$coordinate = $point['lat'] . ',' . $point['lng'] . ',16z';
			$items = SCC_DataForSEO::maps_search( $keyword, $coordinate, 'en', 20 );
			if ( is_wp_error( $items ) ) {
				$point['rank'] = null;
				$point['error'] = $items->get_error_message();
				continue;
			}
			$match = self::identify_rank( (array) $items, $name, $domain );
			$point['rank'] = $match['rank'];
			$point['matched_by'] = $match['matched_by'];
			$point['matched_title'] = $match['title'];
			$point['error'] = '';
			if ( null !== $match['rank'] ) {
				$found++;
				$rank_sum += (int) $match['rank'];
			}
		}
		unset( $point );

		$out = array(
			'keyword' => $keyword,
			'business_name' => $name,
			'domain' => $domain,
			'center' => array( 'lat' => $lat, 'lng' => $lng ),
			'size' => $size,
			'spacing_km' => $spacing,
			'points' => $points,
			'found_points' => $found,
			'total_points' => count( $points ),
			'visibility_pct' => count( $points ) ? round( 100 * $found / count( $points ), 1 ) : 0,
			'average_rank' => $found ? round( $rank_sum / $found, 1 ) : null,
			'generated_at' => current_time( 'mysql' ),
			'cached' => false,
			'provider' => 'DataForSEO Google Maps',
		);
		set_transient( $key, $out, self::CACHE_TTL );
		update_option( self::LAST_OPTION, $out, false );
		return $out;
	}

	public static function identify_rank( array $items, $business_name = '', $domain = '' ) {
		$name = self::normalize_name( $business_name );
		$domain = self::normalize_domain( $domain );
		foreach ( $items as $item ) {
			$item_domain = self::normalize_domain( $item['domain'] ?? ( $item['url'] ?? '' ) );
			$item_name = self::normalize_name( $item['title'] ?? '' );
			$rank = isset( $item['rank_group'] ) ? (int) $item['rank_group'] : ( isset( $item['rank'] ) ? (int) $item['rank'] : null );
			if ( $domain && $item_domain && ( $domain === $item_domain || substr( $item_domain, -strlen( '.' . $domain ) ) === '.' . $domain ) ) {
				return array( 'rank' => $rank, 'matched_by' => 'domain', 'title' => (string) ( $item['title'] ?? '' ) );
			}
			if ( $name && $item_name && $name === $item_name ) {
				return array( 'rank' => $rank, 'matched_by' => 'name', 'title' => (string) ( $item['title'] ?? '' ) );
			}
		}
		return array( 'rank' => null, 'matched_by' => '', 'title' => '' );
	}

	public static function last() {
		$last = get_option( self::LAST_OPTION, null );
		return is_array( $last ) ? $last : null;
	}

	protected static function normalize_domain( $value ) {
		$value = trim( strtolower( (string) $value ) );
		if ( '' === $value ) { return ''; }
		if ( ! preg_match( '#^https?://#', $value ) ) { $value = 'https://' . $value; }
		$host = (string) wp_parse_url( $value, PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	protected static function normalize_name( $value ) {
		$value = strtolower( remove_accents( (string) $value ) );
		return preg_replace( '/[^a-z0-9]+/', '', $value );
	}
}
