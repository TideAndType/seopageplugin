<?php
/**
 * Microsoft Clarity dashboard export: private read-only behavior snapshots.
 *
 * Microsoft only permits 1-3 trailing days per call, up to 10 API requests
 * per day. This collector preserves independently dated 24-hour windows,
 * without fabricating cumulative monthly users or behavioral totals.
 * Does not inject Clarity tracking code (avoid duplicate tracking and obtain
 * required privacy/consent configuration in the official Clarity install).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Clarity {
	const API = 'https://www.clarity.ms/export-data/api/v1/project-live-insights';
	const STORE = 'scc_clarity_daily_snapshots';
	const PROJECT = 'scc_clarity_project_id';
	const LAST = 'scc_clarity_last_capture';
	const QUOTA_KEY = 'scc_clarity_api_quota';
	const MAX_REQUESTS = 8; // Leave two requests of Microsoft's 10 for manual use.

	protected static function creds() {
		$c = get_option( 'scc_credentials', array() );
		return is_array( $c ) ? $c : array();
	}

	public static function project() {
		return (string) get_option( self::PROJECT, '' );
	}

	public static function connected() {
		$c = self::creds();
		return ! empty( $c['clarity_api_token'] ) && '' !== self::project();
	}

	public static function state() {
		return array(
			'status' => ! self::connected() ? 'not_connected' : ( self::snapshots() ? 'measured' : 'configured_unverified' ),
			'project_id' => self::project(),
			'has_api_token' => ! empty( self::creds()['clarity_api_token'] ),
			'read_only' => true,
			'tracking_script_installed_by_tideorbit' => false,
			'last_capture' => (int) get_option( self::LAST, 0 ),
			'requests_reserved_today' => self::quota_used(),
			'request_limit' => self::MAX_REQUESTS,
		);
	}

	public static function configure( $token, $project ) {
		$project = strtolower( trim( (string) $project ) );
		if ( ! preg_match( '/^[a-z0-9]{5,32}$/', $project ) ) {
			return new WP_Error( 'scc_clarity_project', 'Enter a valid Microsoft Clarity Project ID.', array( 'status' => 400 ) );
		}
		$token = trim( (string) $token );
		if ( '' !== $token && ( strlen( $token ) < 25 || strlen( $token ) > 4096 || preg_match( '/\s/', $token ) ) ) {
			return new WP_Error( 'scc_clarity_token', 'The Clarity Data Export API token is malformed.', array( 'status' => 400 ) );
		}
		$creds = self::creds();
		if ( '' === $token && empty( $creds['clarity_api_token'] ) ) {
			return new WP_Error( 'scc_clarity_token', 'Enter a Clarity Data Export API token.', array( 'status' => 400 ) );
		}
		if ( '' !== $token ) {
			$changed = ! isset( $creds['clarity_api_token'] ) || ! hash_equals( (string) $creds['clarity_api_token'], $token );
			$creds['clarity_api_token'] = $token;
			update_option( 'scc_credentials', $creds, false );
			if ( $changed ) { delete_option( self::STORE ); }
		}
		if ( $project !== self::project() ) {
			delete_option( self::STORE ); delete_option( self::LAST );
		}
		update_option( self::PROJECT, $project, false );
		return self::state();
	}

	protected static function quota_used() {
		$quota = get_option( self::QUOTA_KEY, array() );
		return is_array( $quota ) && ( $quota['day'] ?? '' ) === gmdate( 'Ymd' ) ? (int) ( $quota['count'] ?? 0 ) : 0;
	}

	/** Best effort local quota: Microsoft still enforces its own project quota. */
	public static function quota_available() {
		return self::quota_used() < self::MAX_REQUESTS;
	}

	protected static function reserve_request() {
		if ( ! self::quota_available() ) {
			return new WP_Error( 'scc_clarity_quota', 'TideOrbit daily Clarity API budget reached; try tomorrow.', array( 'status' => 429 ) );
		}
		update_option( self::QUOTA_KEY, array( 'day' => gmdate( 'Ymd' ), 'count' => self::quota_used() + 1 ), false );
		return true;
	}

	public static function parse_insights( $body, $project_id, $capture_at ) {
		if ( ! is_array( $body ) || ! array_key_exists( 0, $body ) ) {
			return new WP_Error( 'scc_clarity_response', 'Clarity returned no validated metrics. Missing results are not zero.', array( 'status' => 502 ) );
		}
		$metrics = array();
		$allow = array( 'Traffic', 'Engagement Time', 'Scroll Depth', 'Dead Click Count', 'Rage Click Count', 'Quickback Click', 'Excessive Scroll', 'Script Error Count', 'Error Click Count' );
		foreach ( $body as $block ) {
			if ( ! is_array( $block ) ) { continue; }
			$name = (string) ( $block['metricName'] ?? '' );
			if ( ! in_array( $name, $allow, true ) || ! isset( $block['information'] ) || ! is_array( $block['information'] ) ) { continue; }
			$info = array();
			foreach ( array_slice( $block['information'], 0, 8 ) as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$numbers = array();
				foreach ( $row as $field => $value ) {
					if ( is_string( $field ) && preg_match( '/^[A-Za-z][A-Za-z0-9]{0,59}$/', $field ) && is_numeric( $value ) && (float) $value >= 0 && is_finite( (float) $value ) ) {
						$numbers[$field] = round( (float) $value, 4 );
					}
				}
				if ( $numbers ) { $info[] = $numbers; }
			}
			$metrics[$name] = array(
				'rows' => $info,
				'provider_rows' => count( $block['information'] ),
				'truncated' => count( $block['information'] ) > 8,
			);
		}
		$has_numeric_measurement = false;
		foreach ( $metrics as $record ) { if ( ! empty( $record['rows'] ) ) { $has_numeric_measurement = true; break; } }
		if ( ! $metrics || ! $has_numeric_measurement ) {
			return new WP_Error( 'scc_clarity_empty', 'Clarity response contained no supported measurable behavior metrics.', array( 'status' => 502 ) );
		}
		return array(
			'state' => 'measured', 'source' => 'microsoft_clarity_export_api',
			'project_id' => (string) $project_id,
			'window' => 'trailing_24_hours', 'captured_at' => gmdate( 'c', (int) $capture_at ),
			'metrics' => $metrics,
			'notice' => 'Clarity snapshots are rolling 24-hour behavioral exports. Do not sum them as calendar-month unique users or infer a full-month conversion rate. Review the Clarity dashboard for heatmaps and recordings.',
		);
	}

	public static function capture() {
		if ( ! self::connected() ) {
			return new WP_Error( 'scc_clarity_disconnected', 'Configure a Clarity project and its Data Export API token first.', array( 'status' => 400 ) );
		}
		$reserved = self::reserve_request();
		if ( is_wp_error( $reserved ) ) { return $reserved; }
		$token = (string) self::creds()['clarity_api_token'];
		$response = wp_remote_get( self::API . '?numOfDays=1', array(
			'timeout' => 25,
			'redirection' => 0,
			'headers' => array( 'authorization' => 'Bearer ' . $token, 'accept' => 'application/json' ),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'scc_clarity_network', 'Could not contact Clarity Data Export API.', array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new WP_Error( 'scc_clarity_http', 429 === $status ? 'Microsoft Clarity API daily request limit reached.' : 'Microsoft Clarity denied the Data Export API request. Verify the project token.', array( 'status' => $status ?: 502 ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$at = time();
		$parsed = self::parse_insights( $body, self::project(), $at );
		if ( is_wp_error( $parsed ) ) { return $parsed; }
		$stored = self::snapshots();
		// One snapshot per UTC date: replacing latest capture avoids accidental
		// double-counting of overlapping windows after manual refreshes.
		$stored[gmdate( 'Y-m-d', $at )] = $parsed;
		ksort( $stored );
		update_option( self::STORE, array_slice( $stored, -90, null, true ), false );
		update_option( self::LAST, $at, false );
		return $parsed;
	}

	public static function snapshots() {
		$data = get_option( self::STORE, array() );
		return is_array( $data ) ? $data : array();
	}

	public static function monthly( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) { return array(); }
		$all = self::snapshots();
		$rows = array();
		foreach ( $all as $day => $snapshot ) {
			if ( 0 === strpos( (string) $day, $month ) && is_array( $snapshot ) && (string) ( $snapshot['project_id'] ?? '' ) === self::project() ) {
				$rows[] = $snapshot;
			}
		}
		return array_slice( $rows, -31 );
	}

	public static function maybe_capture() {
		if ( ! self::connected() || ! self::quota_available() ) { return; }
		$last = (int) get_option( self::LAST, 0 );
		if ( $last > time() - DAY_IN_SECONDS ) { return; }
		// Failure backoff: avoid repeated scheduled calls if token is expired.
		$attempt = (int) get_option( 'scc_clarity_last_attempt', 0 );
		if ( $attempt > time() - 12 * HOUR_IN_SECONDS ) { return; }
		update_option( 'scc_clarity_last_attempt', time(), false );
		$result = self::capture();
		if ( is_wp_error( $result ) && class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'clarity', 'Clarity export did not complete: ' . $result->get_error_code() );
		}
	}

	public static function disconnect() {
		$c = self::creds();
		unset( $c['clarity_api_token'] );
		update_option( 'scc_credentials', $c, false );
		delete_option( self::STORE ); delete_option( self::PROJECT ); delete_option( self::LAST );
		delete_option( 'scc_clarity_last_attempt' );
		return self::state();
	}
}
