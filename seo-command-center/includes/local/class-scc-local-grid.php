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
	const GEO_CACHE_TTL = 30 * DAY_IN_SECONDS;
	const LAST_OPTION = 'scc_local_grid_last';

	public static function coverage_profile( $coverage ) {
		$coverage = sanitize_key( (string) $coverage );
		$profiles = array(
			'neighborhood' => array( 'size' => 3, 'spacing_km' => 0.75, 'label' => __( 'Neighborhood', 'seo-command-center' ) ),
			'city'         => array( 'size' => 5, 'spacing_km' => 1.5, 'label' => __( 'City', 'seo-command-center' ) ),
			'metro'        => array( 'size' => 5, 'spacing_km' => 3.0, 'label' => __( 'Wider area', 'seo-command-center' ) ),
		);
		return isset( $profiles[ $coverage ] ) ? $profiles[ $coverage ] : $profiles['city'];
	}

	public static function default_location( array $business ) {
		$parts = array_filter(
			array(
				trim( (string) ( $business['street'] ?? '' ) ),
				trim( (string) ( $business['city'] ?? '' ) ),
				trim( (string) ( $business['region'] ?? '' ) ),
				trim( (string) ( $business['postal_code'] ?? '' ) ),
				trim( (string) ( $business['country'] ?? '' ) ),
			)
		);
		return implode( ', ', array_values( $parts ) );
	}

	public static function resolve_location( $location, $business_name = '', $domain = '', $place_id = '' ) {
		$location = sanitize_text_field( (string) $location );
		if ( '' === $location ) {
			return new WP_Error( 'scc_grid_location_required', __( 'Enter the city, ZIP code, or address you want to scan.', 'seo-command-center' ) );
		}

		$cache_key = 'scc_grid_geo_' . md5( strtolower( $location . '|' . $business_name . '|' . $domain . '|' . $place_id ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) ) {
			return $cached;
		}

		$browser_error = null;
		if ( class_exists( 'SCC_Browser_Runtime' ) && 'dataforseo' !== SCC_Browser_Runtime::mode() && SCC_Browser_Runtime::configured() ) {
			$resolved = SCC_Browser_Runtime::geocode( $location );
			if ( ! is_wp_error( $resolved ) ) {
				set_transient( $cache_key, $resolved, self::GEO_CACHE_TTL );
				return $resolved;
			}
			$browser_error = $resolved;
		}

		if ( class_exists( 'SCC_DataForSEO' ) && SCC_DataForSEO::is_connected() ) {
			$resolved = SCC_DataForSEO::resolve_maps_location( $location, $business_name, $domain, $place_id );
			if ( ! is_wp_error( $resolved ) ) {
				set_transient( $cache_key, $resolved, self::GEO_CACHE_TTL );
				return $resolved;
			}
			if ( ! $browser_error ) {
				$browser_error = $resolved;
			}
		}

		if ( is_wp_error( $browser_error ) ) {
			return $browser_error;
		}
		return new WP_Error(
			'scc_grid_location_provider',
			__( 'TideOrbit could not resolve that scan area. Connect the Rank Tracker or DataForSEO, then try the city and state or a full address.', 'seo-command-center' )
		);
	}

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
		$args = self::normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$cache_key = self::cache_key( $args );
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		if ( class_exists( 'SCC_Browser_Runtime' ) && 'dataforseo' !== SCC_Browser_Runtime::mode() ) {
			if ( SCC_Browser_Runtime::configured() ) {
				$browser = SCC_Browser_Runtime::start_local_grid( $args );
				if ( ! is_wp_error( $browser ) ) {
					return $browser;
				}
				if ( 'browser' === SCC_Browser_Runtime::mode() ) {
					return $browser;
				}
			} elseif ( 'browser' === SCC_Browser_Runtime::mode() ) {
				return new WP_Error( 'scc_grid_browser_unpaired', __( 'Browser-only mode is selected, but no TideOrbit Browser Bridge is paired.', 'seo-command-center' ) );
			}
		}

		return self::scan_dataforseo( $args, true );
	}

	/**
	 * Run the existing paid API path directly. Public so the Browser Runtime can
	 * use it as a background fallback without recursing through scan().
	 *
	 * @param array $args    Normalized or raw scan args.
	 * @param bool  $refresh Ignore cache.
	 * @return array|WP_Error
	 */
	public static function scan_dataforseo( array $args, $refresh = false ) {
		$args = self::normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		if ( ! class_exists( 'SCC_DataForSEO' ) || ! SCC_DataForSEO::is_connected() ) {
			return new WP_Error( 'scc_grid_no_provider', __( 'No browser runtime is available and DataForSEO is not connected.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$key = self::cache_key( $args );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$points = self::grid_points( $args['lat'], $args['lng'], $args['size'], $args['spacing_km'] );
		$found = 0;
		$rank_sum = 0;
		foreach ( $points as &$point ) {
			$coordinate = $point['lat'] . ',' . $point['lng'] . ',16z';
			$items = SCC_DataForSEO::maps_search( $args['keyword'], $coordinate, 'en', 20 );
			if ( is_wp_error( $items ) ) {
				$point['rank'] = null;
				$point['error'] = $items->get_error_message();
				continue;
			}
			$match = self::identify_rank( (array) $items, $args['business_name'], $args['domain'] );
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
			'keyword' => $args['keyword'],
			'location' => (string) ( $args['location'] ?? '' ),
			'resolved_location' => (string) ( $args['resolved_location'] ?? $args['location'] ?? '' ),
			'business_name' => $args['business_name'],
			'domain' => $args['domain'],
			'center' => array( 'lat' => $args['lat'], 'lng' => $args['lng'] ),
			'size' => $args['size'],
			'spacing_km' => $args['spacing_km'],
			'points' => $points,
			'found_points' => $found,
			'total_points' => count( $points ),
			'visibility_pct' => count( $points ) ? round( 100 * $found / count( $points ), 1 ) : 0,
			'average_rank' => $found ? round( $rank_sum / $found, 1 ) : null,
			'generated_at' => current_time( 'mysql' ),
			'cached' => false,
			'provider' => 'DataForSEO Google Maps',
			'engine' => 'dataforseo',
		);
		self::store_result( $out, $key );
		return $out;
	}

	/**
	 * Persist a completed grid regardless of which engine measured it.
	 *
	 * @param array  $result    Normalized grid result.
	 * @param string $cache_key Optional cache key.
	 */
	public static function store_result( array $result, $cache_key = '' ) {
		if ( '' !== $cache_key ) {
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}
		update_option( self::LAST_OPTION, $result, false );
	}

	protected static function normalize_args( array $args ) {
		$coverage = sanitize_key( (string) ( $args['coverage'] ?? '' ) );
		$profile  = '' !== $coverage ? self::coverage_profile( $coverage ) : null;
		$out = array(
			'keyword'       => sanitize_text_field( $args['keyword'] ?? '' ),
			'business_name' => sanitize_text_field( $args['business_name'] ?? '' ),
			'domain'        => self::normalize_domain( $args['domain'] ?? home_url( '/' ) ),
			'place_id'      => sanitize_text_field( $args['place_id'] ?? '' ),
			'location'      => sanitize_text_field( $args['location'] ?? '' ),
			'coverage'      => '' !== $coverage ? $coverage : 'custom',
			'lat'           => (float) ( $args['lat'] ?? 0 ),
			'lng'           => (float) ( $args['lng'] ?? 0 ),
			'size'          => $profile ? (int) $profile['size'] : ( in_array( (int) ( $args['size'] ?? 3 ), array( 3, 5 ), true ) ? (int) $args['size'] : 3 ),
			'spacing_km'    => $profile ? (float) $profile['spacing_km'] : max( 0.2, min( 10.0, (float) ( $args['spacing_km'] ?? 1.0 ) ) ),
		);

		if ( '' === $out['location'] && class_exists( 'SCC_Schema_Engine' ) ) {
			$out['location'] = self::default_location( SCC_Schema_Engine::business() );
		}
		if ( '' === $out['keyword'] || ( '' === $out['business_name'] && '' === $out['domain'] && '' === $out['place_id'] ) ) {
			return new WP_Error( 'scc_grid_input', __( 'Keyword and business name are required.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		if ( 0.0 === $out['lat'] || 0.0 === $out['lng'] ) {
			$resolved = self::resolve_location( $out['location'], $out['business_name'], $out['domain'], $out['place_id'] );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$out['lat'] = (float) $resolved['lat'];
			$out['lng'] = (float) $resolved['lng'];
			$out['resolved_location'] = sanitize_text_field( (string) ( $resolved['display_name'] ?? $out['location'] ) );
			$out['location_source'] = sanitize_key( (string) ( $resolved['source'] ?? '' ) );
		} else {
			$out['resolved_location'] = $out['location'];
			$out['location_source'] = 'coordinates';
		}
		return $out;
	}

	protected static function cache_key( array $args ) {
		return 'scc_grid_' . md5(
			wp_json_encode(
				array(
					$args['keyword'], $args['business_name'], $args['domain'], $args['place_id'],
					$args['location'] ?? '', $args['lat'], $args['lng'], $args['size'], $args['spacing_km'],
				)
			)
		);
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
