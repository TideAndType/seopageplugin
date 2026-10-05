<?php
/**
 * TideOrbit Browser Runtime.
 *
 * Routes browser-capable jobs to a paired Google-Maps-SERP instance (normally
 * exposed through a Cloudflare Tunnel) and falls back to DataForSEO in
 * Automatic mode when that browser runtime is unavailable.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCC_Browser_Runtime {
	const JOBS_OPTION   = 'scc_browser_runtime_jobs';
	const STATUS_OPTION = 'scc_browser_runtime_status';
	const CRON_HOOK     = 'scc_browser_runtime_poll';
	const POLL_SECONDS  = 20;
	const MAX_POLLS     = 90;

	public static function mode() {
		$mode = (string) SCC_Settings::get( 'browser_runtime_mode', 'auto' );
		return in_array( $mode, array( 'auto', 'browser', 'dataforseo' ), true ) ? $mode : 'auto';
	}

	public static function url() {
		return untrailingslashit( trim( (string) SCC_Settings::get( 'browser_runtime_url', '' ) ) );
	}

	public static function key() {
		$creds = get_option( 'scc_credentials', array() );
		return is_array( $creds ) ? trim( (string) ( $creds['browser_runtime_key'] ?? '' ) ) : '';
	}

	public static function configured() {
		return '' !== self::url() && '' !== self::key();
	}

	public static function last_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	public static function active_jobs() {
		$jobs = get_option( self::JOBS_OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	public static function health() {
		if ( ! self::configured() ) {
			return new WP_Error( 'scc_browser_not_configured', __( 'Add your Cloudflare Tunnel URL and TideOrbit pairing key first.', 'seo-command-center' ) );
		}
		$result = self::request( 'GET', '/api/tideorbit/health' );
		if ( is_wp_error( $result ) ) {
			self::set_status( 'offline', $result->get_error_message() );
			return $result;
		}
		if ( array_key_exists( 'browserReady', $result ) && empty( $result['browserReady'] ) ) {
			$error = new WP_Error( 'scc_browser_not_ready', __( 'The bridge is online, but Chromium is not installed or ready on the scanner machine.', 'seo-command-center' ) );
			self::set_status( 'offline', $error->get_error_message(), $result );
			return $error;
		}
		self::set_status( 'online', __( 'TideOrbit Browser Bridge and Chromium are online.', 'seo-command-center' ), $result );
		return $result;
	}

	public static function start_local_grid( array $args ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'scc_browser_not_configured', __( 'Browser Runtime is not paired.', 'seo-command-center' ) );
		}
		if ( empty( $args['business_name'] ) && empty( $args['place_id'] ) ) {
			return new WP_Error( 'scc_browser_target_required', __( 'The browser scanner needs a business name or Place ID to identify your listing.', 'seo-command-center' ) );
		}

		$health = self::health();
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		$points = SCC_Local_Grid::grid_points( $args['lat'], $args['lng'], $args['size'], $args['spacing_km'] );
		$payload = array(
			'keyword'      => $args['keyword'],
			'businessName' => $args['business_name'],
			'placeId'      => (string) ( $args['place_id'] ?? '' ),
			'lat'          => (float) $args['lat'],
			'lng'          => (float) $args['lng'],
			'gridSize'     => (int) $args['size'],
			'radius'       => max( 0.5, ( (int) $args['size'] - 1 ) * (float) $args['spacing_km'] / 2 ),
			'customPoints' => array_map(
				function ( $point ) {
					return array( 'lat' => (float) $point['lat'], 'lng' => (float) $point['lng'] );
				},
				$points
			),
		);

		$response = self::request( 'POST', '/api/tideorbit/scans', $payload );
		if ( is_wp_error( $response ) ) {
			self::set_status( 'offline', $response->get_error_message() );
			return $response;
		}
		$scan_id = sanitize_text_field( (string) ( $response['scanId'] ?? '' ) );
		if ( '' === $scan_id ) {
			return new WP_Error( 'scc_browser_bad_response', __( 'The browser bridge did not return a scan ID.', 'seo-command-center' ) );
		}

		$jobs = self::active_jobs();
		$jobs[ $scan_id ] = array(
			'scan_id'           => $scan_id,
			'status'            => 'queued',
			'args'              => $args,
			'points'            => $points,
			'started_at'        => time(),
			'updated_at'        => time(),
			'polls'             => 0,
			'connection_errors' => 0,
			'next_poll'         => time() + self::POLL_SECONDS,
		);
		update_option( self::JOBS_OPTION, $jobs, false );
		self::schedule_poll( $scan_id, self::POLL_SECONDS );
		self::set_status( 'online', sprintf( __( 'Browser scan %s was accepted by the local scanner.', 'seo-command-center' ), $scan_id ) );

		return array(
			'queued'       => true,
			'status'       => 'queued',
			'scan_id'      => $scan_id,
			'provider'     => 'TideOrbit Browser',
			'engine'       => 'browser',
			'total_points' => count( $points ),
			'message'      => __( 'The browser scan is running in the background. TideOrbit will import the grid automatically.', 'seo-command-center' ),
		);
	}

	public static function poll_job( $scan_id ) {
		$scan_id = sanitize_text_field( (string) $scan_id );
		$jobs = self::active_jobs();
		if ( empty( $jobs[ $scan_id ] ) || ! is_array( $jobs[ $scan_id ] ) ) {
			return;
		}
		$job = $jobs[ $scan_id ];
		$job['polls'] = (int) ( $job['polls'] ?? 0 ) + 1;
		$job['updated_at'] = time();

		$response = self::request( 'GET', '/api/tideorbit/scans/' . rawurlencode( $scan_id ) );
		if ( is_wp_error( $response ) ) {
			$job['connection_errors'] = (int) ( $job['connection_errors'] ?? 0 ) + 1;
			$job['status'] = 'bridge_unreachable';
			$jobs[ $scan_id ] = $job;
			update_option( self::JOBS_OPTION, $jobs, false );

			if ( $job['connection_errors'] >= 3 || $job['polls'] >= self::MAX_POLLS ) {
				self::fallback_or_fail( $scan_id, $job, $response->get_error_message() );
				return;
			}
			self::schedule_poll( $scan_id, self::POLL_SECONDS );
			return;
		}

		$scan = is_array( $response['scan'] ?? null ) ? $response['scan'] : array();
		$status = strtoupper( (string) ( $scan['status'] ?? '' ) );
		$job['connection_errors'] = 0;
		$job['status'] = strtolower( $status ?: 'running' );
		$jobs[ $scan_id ] = $job;
		update_option( self::JOBS_OPTION, $jobs, false );

		if ( 'COMPLETED' === $status ) {
			$result = self::normalize_grid_result( $scan, $job );
			SCC_Local_Grid::store_result( $result );
			unset( $jobs[ $scan_id ] );
			update_option( self::JOBS_OPTION, $jobs, false );
			self::set_status( 'online', sprintf( __( 'Browser scan %s completed and was imported.', 'seo-command-center' ), $scan_id ), array( 'scan_id' => $scan_id ) );
			return;
		}

		if ( in_array( $status, array( 'FAILED', 'STOPPED' ), true ) || $job['polls'] >= self::MAX_POLLS ) {
			self::fallback_or_fail( $scan_id, $job, sprintf( __( 'Browser scan ended with status %s.', 'seo-command-center' ), $status ?: 'timeout' ) );
			return;
		}

		$job['next_poll'] = time() + self::POLL_SECONDS;
		$jobs[ $scan_id ] = $job;
		update_option( self::JOBS_OPTION, $jobs, false );
		self::schedule_poll( $scan_id, self::POLL_SECONDS );
	}

	public static function poll_due_jobs() {
		$jobs = self::active_jobs();
		$now = time();
		$polled = 0;
		foreach ( $jobs as $scan_id => $job ) {
			if ( $polled >= 2 ) {
				break;
			}
			if ( (int) ( $job['next_poll'] ?? 0 ) <= $now ) {
				self::poll_job( $scan_id );
				$polled++;
			}
		}
	}

	public static function normalize_grid_result( array $scan, array $job ) {
		$args = (array) ( $job['args'] ?? array() );
		$expected = (array) ( $job['points'] ?? array() );
		$results = (array) ( $scan['results'] ?? array() );
		$map = array();
		foreach ( $results as $row ) {
			$key = self::point_key( $row['lat'] ?? 0, $row['lng'] ?? 0 );
			$map[ $key ] = $row;
		}

		$points = array();
		$found = 0;
		$rank_sum = 0;
		foreach ( $expected as $point ) {
			$key = self::point_key( $point['lat'], $point['lng'] );
			$row = isset( $map[ $key ] ) ? (array) $map[ $key ] : array();
			$rank = array_key_exists( 'rank', $row ) && null !== $row['rank'] ? (int) $row['rank'] : null;
			$top = (array) ( $row['topResults'] ?? array() );
			$point['rank'] = $rank;
			$point['matched_by'] = '' !== (string) ( $row['placeId'] ?? '' ) ? 'place_id' : ( '' !== (string) ( $row['targetName'] ?? '' ) ? 'name' : '' );
			$point['matched_title'] = (string) ( $row['targetName'] ?? '' );
			$point['error'] = empty( $row ) ? 'No result returned for this point.' : ( empty( $top ) && null === $rank ? 'Browser returned no map listings for this point.' : '' );
			if ( null !== $rank ) {
				$found++;
				$rank_sum += $rank;
			}
			$points[] = $point;
		}

		return array(
			'keyword'        => (string) ( $args['keyword'] ?? $scan['keyword'] ?? '' ),
			'business_name'  => (string) ( $args['business_name'] ?? $scan['businessName'] ?? '' ),
			'domain'         => (string) ( $args['domain'] ?? '' ),
			'center'         => array( 'lat' => (float) ( $args['lat'] ?? $scan['centerLat'] ?? 0 ), 'lng' => (float) ( $args['lng'] ?? $scan['centerLng'] ?? 0 ) ),
			'size'           => (int) ( $args['size'] ?? $scan['gridSize'] ?? 3 ),
			'spacing_km'     => (float) ( $args['spacing_km'] ?? 1 ),
			'points'         => $points,
			'found_points'   => $found,
			'total_points'   => count( $points ),
			'visibility_pct' => count( $points ) ? round( 100 * $found / count( $points ), 1 ) : 0,
			'average_rank'   => $found ? round( $rank_sum / $found, 1 ) : null,
			'generated_at'   => current_time( 'mysql' ),
			'cached'         => false,
			'provider'       => 'TideOrbit Browser via Cloudflare Tunnel',
			'engine'         => 'browser',
			'scan_id'        => (string) ( $scan['id'] ?? $job['scan_id'] ?? '' ),
		);
	}

	protected static function fallback_or_fail( $scan_id, array $job, $reason ) {
		$jobs = self::active_jobs();
		unset( $jobs[ $scan_id ] );
		update_option( self::JOBS_OPTION, $jobs, false );

		if ( 'auto' === self::mode() && class_exists( 'SCC_DataForSEO' ) && SCC_DataForSEO::is_connected() ) {
			$result = SCC_Local_Grid::scan_dataforseo( (array) $job['args'], true );
			if ( ! is_wp_error( $result ) ) {
				self::set_status( 'fallback', __( 'The local browser was unavailable, so TideOrbit completed the grid with DataForSEO.', 'seo-command-center' ), array( 'reason' => $reason ) );
				return;
			}
		}
		self::set_status( 'offline', $reason );
	}

	protected static function request( $method, $path, array $body = null ) {
		$base = self::url();
		$key  = self::key();
		if ( '' === $base || '' === $key ) {
			return new WP_Error( 'scc_browser_not_configured', __( 'Browser Runtime is not paired.', 'seo-command-center' ) );
		}
		$url = $base . '/' . ltrim( $path, '/' );
		if ( class_exists( 'SCC_URL' ) ) {
			$safe = SCC_URL::is_safe_outbound_url( $url );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
		}

		$args = array(
			'method'      => strtoupper( (string) $method ),
			'timeout'     => 15,
			'redirection' => 2,
			'headers'     => array(
				'Accept'          => 'application/json',
				'Content-Type'    => 'application/json',
				'X-TideOrbit-Key' => $key,
				'User-Agent'      => 'TideOrbit/' . ( defined( 'SCC_VERSION' ) ? SCC_VERSION : 'unknown' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $decoded ) && ! empty( $decoded['error'] ) ? (string) $decoded['error'] : sprintf( 'Browser bridge returned HTTP %d.', $code );
			return new WP_Error( 'scc_browser_http', $message, array( 'status' => $code ) );
		}
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'scc_browser_json', __( 'Browser bridge returned invalid JSON.', 'seo-command-center' ) );
		}
		return $decoded;
	}

	protected static function schedule_poll( $scan_id, $delay ) {
		$timestamp = time() + max( 10, (int) $delay );
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $scan_id ) ) ) {
			wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $scan_id ) );
		}
	}

	protected static function set_status( $state, $message, array $details = array() ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'state'      => sanitize_key( $state ),
				'message'    => sanitize_text_field( $message ),
				'details'    => $details,
				'updated_at' => current_time( 'mysql' ),
			),
			false
		);
	}

	protected static function point_key( $lat, $lng ) {
		return number_format( (float) $lat, 6, '.', '' ) . ',' . number_format( (float) $lng, 6, '.', '' );
	}
}
