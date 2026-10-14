<?php
/**
 * Google Business Profile Performance reporting only.
 * OAuth uses business.manage (Google's required scope) but this class only
 * issues GET requests to performance reporting endpoints. No profile writes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class SCC_GBP_Performance {
	const SCOPE = 'https://www.googleapis.com/auth/business.manage';
	const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN = 'https://oauth2.googleapis.com/token';
	const API = 'https://businessprofileperformance.googleapis.com/v1/';
	const STORE = 'scc_gbp_performance_history';
	const LOCATION = 'scc_gbp_location_id';
	const AUTO = 'scc_gbp_last_auto';
	const CACHE = 'scc_gbp_access_token';

	protected static function creds() {
		$c = get_option( 'scc_credentials', array() );
		return is_array( $c ) ? $c : array();
	}
	public static function redirect_uri() {
		return add_query_arg( 'scc_provider', 'gbp', admin_url( 'admin.php?page=seo-command-center-campaign' ) );
	}
	public static function has_client() {
		$c = self::creds();
		return ! empty( $c['gsc_client_id'] ) && ! empty( $c['gsc_client_secret'] );
	}
	public static function location_id() {
		$id = (string) get_option( self::LOCATION, '' );
		return preg_match( '/^\d{5,25}$/', $id ) ? $id : '';
	}
	public static function configure( $id ) {
		$id = trim( (string) $id );
		$id = preg_replace( '#^locations/#', '', $id );
		if ( ! preg_match( '/^\d{5,25}$/', $id ) ) {
			return new WP_Error( 'scc_gbp_id', 'Use the numeric Business Profile location ID (not the Maps CID or Place ID).', array( 'status' => 400 ) );
		}
		if ( $id !== self::location_id() ) {
			delete_option( self::STORE );
			delete_option( self::AUTO );
		}
		update_option( self::LOCATION, $id, false );
		return self::state();
	}
	public static function state() {
		$c = self::creds();
		return array(
			'status' => ! self::has_client() ? 'needs_google_client' : ( empty( $c['gbp_refresh_token'] ) ? 'not_connected' : ( ! self::location_id() ? 'needs_location' : ( self::snapshots() ? 'measured' : 'authorized_unverified' ) ) ),
			'location_id' => self::location_id(),
			'redirect_uri' => self::redirect_uri(),
			'google_oauth_configured' => self::has_client(),
			'has_token' => ! empty( $c['gbp_refresh_token'] ),
			'code_issues_get_only' => true,
			'google_oauth_scope' => self::SCOPE,
			'last_capture' => (int) get_option( self::AUTO, 0 ),
		);
	}
	public static function auth_url() {
		if ( ! self::has_client() || ! self::location_id() ) {
			return new WP_Error( 'scc_gbp_setup', 'Configure Google OAuth and your Profile location ID first.', array( 'status' => 400 ) );
		}
		$uid = (int) get_current_user_id();
		if ( $uid < 1 ) { return new WP_Error( 'scc_gbp_user', 'Sign in as a site administrator.', array( 'status' => 403 ) ); }
		$state = wp_generate_password( 48, false, false );
		set_transient( 'scc_gbp_oauth_' . $uid, $state, 15 * MINUTE_IN_SECONDS );
		$c = self::creds();
		return self::AUTH . '?' . http_build_query( array(
			'client_id' => $c['gsc_client_id'],
			'redirect_uri' => self::redirect_uri(),
			'response_type' => 'code',
			'scope' => self::SCOPE,
			'access_type' => 'offline',
			'prompt' => 'consent',
			'state' => $state,
		), '', '&', PHP_QUERY_RFC3986 );
	}
	public static function maybe_handle_callback() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) { return; }
		$provider = isset( $_GET['scc_provider'] ) ? sanitize_key( wp_unslash( $_GET['scc_provider'] ) ) : '';
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'gbp' !== $provider || 'seo-command-center-campaign' !== $page || ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) ) { return; }
		$uid = (int) get_current_user_id();
		$key = 'scc_gbp_oauth_' . $uid;
		$expected = get_transient( $key );
		delete_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$notice = 'failed';
		if ( $expected && $state && hash_equals( (string) $expected, $state ) && empty( $_GET['error'] ) ) {
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			$result = self::exchange_code( $code );
			if ( ! is_wp_error( $result ) ) { $notice = 'connected'; }
		}
		wp_safe_redirect( add_query_arg( 'scc_gbp_notice', $notice, admin_url( 'admin.php?page=seo-command-center-settings&tab=seo' ) ) );
		exit;
	}
	protected static function exchange_code( $code ) {
		if ( ! $code || ! self::has_client() ) { return new WP_Error( 'scc_gbp_code', 'Invalid OAuth code.' ); }
		$c = self::creds();
		$r = wp_remote_post( self::TOKEN, array( 'timeout' => 25, 'body' => array(
			'code' => $code, 'client_id' => $c['gsc_client_id'], 'client_secret' => $c['gsc_client_secret'],
			'redirect_uri' => self::redirect_uri(), 'grant_type' => 'authorization_code',
		) ) );
		if ( is_wp_error( $r ) ) { return new WP_Error( 'scc_gbp_oauth', 'Google OAuth request failed.' ); }
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || empty( $body['refresh_token'] ) ) {
			return new WP_Error( 'scc_gbp_no_refresh', 'Google did not authorize Business Profile access. Check API access and consent.', array( 'status' => 502 ) );
		}
		$c['gbp_refresh_token'] = trim( preg_replace( '/\s+/', '', (string) $body['refresh_token'] ) );
		update_option( 'scc_credentials', $c, false );
		delete_transient( self::CACHE );
		return true;
	}
	protected static function access_token() {
		$c = self::creds();
		if ( ! self::has_client() || empty( $c['gbp_refresh_token'] ) ) {
			return new WP_Error( 'scc_gbp_disconnected', 'Authorize Business Profile first.', array( 'status' => 400 ) );
		}
		$token = get_transient( self::CACHE );
		if ( is_string( $token ) && $token ) { return $token; }
		$r = wp_remote_post( self::TOKEN, array( 'timeout' => 25, 'body' => array(
			'grant_type' => 'refresh_token', 'refresh_token' => $c['gbp_refresh_token'],
			'client_id' => $c['gsc_client_id'], 'client_secret' => $c['gsc_client_secret'],
		) ) );
		if ( is_wp_error( $r ) ) { return new WP_Error( 'scc_gbp_token_network', 'Could not refresh Business Profile authorization.' ); }
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) || empty( $d['access_token'] ) ) {
			return new WP_Error( 'scc_gbp_token', 'Business Profile authorization expired or unavailable.', array( 'status' => 502 ) );
		}
		set_transient( self::CACHE, (string) $d['access_token'], max( 60, (int) ( $d['expires_in'] ?? 3600 ) - 60 ) );
		return (string) $d['access_token'];
	}
	public static function range_for_month( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) || $month > gmdate( 'Y-m' ) ) {
			return new WP_Error( 'scc_gbp_month', 'Invalid or future reporting month.', array( 'status' => 400 ) );
		}
		$start = $month . '-01';
		$last = gmdate( 'Y-m-t', strtotime( $start . ' UTC' ) );
		// Exclude most recent two days so incomplete periods are not reported as zero.
		$end = min( $last, gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) );
		if ( $end < $start ) { return new WP_Error( 'scc_gbp_recent', 'No completed reporting days yet.', array( 'status' => 409 ) ); }
		return array( 'start' => $start, 'end' => $end );
	}
	public static function daily_metrics() {
		return array(
			'WEBSITE_CLICKS', 'CALL_CLICKS', 'BUSINESS_DIRECTION_REQUESTS',
			'BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
			'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
		);
	}
	public static function normalize( $body, $location, $month, array $range ) {
		if ( ! is_array( $body ) || ! isset( $body['multiDailyMetricTimeSeries'] ) || ! is_array( $body['multiDailyMetricTimeSeries'] ) ) {
			return new WP_Error( 'scc_gbp_response', 'No measurable Business Profile response.', array( 'status' => 502 ) );
		}
		$metrics = array();
		foreach ( self::daily_metrics() as $name ) {
			$metrics[$name] = array( 'total' => null, 'measured_days' => 0 );
		}
		foreach ( $body['multiDailyMetricTimeSeries'] as $group ) {
			foreach ( (array) ( $group['dailyMetricTimeSeries'] ?? array() ) as $series ) {
				$name = (string) ( $series['dailyMetric'] ?? '' );
				if ( ! isset( $metrics[$name] ) || ! isset( $series['timeSeries']['datedValues'] ) || ! is_array( $series['timeSeries']['datedValues'] ) ) { continue; }
				$by_day = array();
				foreach ( $series['timeSeries']['datedValues'] as $item ) {
					$date = $item['date'] ?? array();
					$day = sprintf( '%04d-%02d-%02d', (int) ( $date['year'] ?? 0 ), (int) ( $date['month'] ?? 0 ), (int) ( $date['day'] ?? 0 ) );
					if ( $day < $range['start'] || $day > $range['end'] || ! isset( $item['value'] ) || ! is_numeric( $item['value'] ) || (float) $item['value'] < 0 ) { continue; }
					$by_day[$day] = (float) $item['value'];
				}
				if ( $by_day ) {
					$metrics[$name] = array( 'total' => array_sum( $by_day ), 'measured_days' => count( $by_day ) );
				}
			}
		}
		$covered = count( array_filter( $metrics, function ( $m ) { return $m['measured_days'] > 0; } ) );
		if ( ! $covered ) { return new WP_Error( 'scc_gbp_unavailable', 'Profile performance has no measured days in this range.', array( 'status' => 502 ) ); }
		return array(
			'state' => 'measured_partial_possible', 'source' => 'google_business_profile_performance',
			'location_id' => (string) $location, 'month' => $month,
			'start_date' => $range['start'], 'end_date' => $range['end'],
			'metrics' => $metrics, 'metrics_with_data' => $covered,
			'captured_at' => gmdate( 'c' ),
			'notice' => 'Profile call clicks, website clicks and direction requests are interactions, not verified phone calls, leads or revenue. Metrics with no reported dates remain unavailable.',
		);
	}
	public static function snapshots() {
		$a = get_option( self::STORE, array() );
		return is_array( $a ) ? $a : array();
	}
	public static function monthly( $month ) {
		$a = self::snapshots();
		$row = $a[$month] ?? null;
		return is_array( $row ) && (string) ( $row['location_id'] ?? '' ) === self::location_id() ? $row : null;
	}
	public static function capture( $month ) {
		$range = self::range_for_month( $month );
		if ( is_wp_error( $range ) ) { return $range; }
		if ( ! self::location_id() ) { return new WP_Error( 'scc_gbp_no_location', 'Configure a Business Profile location.', array( 'status' => 400 ) ); }
		$token = self::access_token();
		if ( is_wp_error( $token ) ) { return $token; }
		$params = array();
		foreach ( self::daily_metrics() as $metric ) { $params[] = 'dailyMetrics=' . rawurlencode( $metric ); }
		foreach ( array( 'start' => 'start_date', 'end' => 'end_date' ) as $key => $remote ) {
			$pieces = explode( '-', $range[$key] );
			foreach ( array( 'year', 'month', 'day' ) as $i => $field ) {
				$params[] = 'dailyRange.' . $remote . '.' . $field . '=' . (int) $pieces[$i];
			}
		}
		$url = self::API . 'locations/' . self::location_id() . ':fetchMultiDailyMetricsTimeSeries?' . implode( '&', $params );
		$r = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'authorization' => 'Bearer ' . $token ) ) );
		if ( is_wp_error( $r ) ) { return new WP_Error( 'scc_gbp_network', 'Profile performance request could not complete.', array( 'status' => 502 ) ); }
		$body = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return new WP_Error( 'scc_gbp_api', 'Google declined profile performance access. Check account permissions, location ID and API quota.', array( 'status' => 502 ) );
		}
		$result = self::normalize( $body, self::location_id(), $month, $range );
		if ( is_wp_error( $result ) ) { return $result; }
		$stored = self::snapshots();
		$stored[$month] = $result;
		ksort( $stored );
		update_option( self::STORE, array_slice( $stored, -24, null, true ), false );
		return $result;
	}
	public static function maybe_capture() {
		$c = self::creds();
		if ( empty( $c['gbp_refresh_token'] ) || ! self::location_id() ) { return; }
		$last = (int) get_option( self::AUTO, 0 );
		if ( $last > time() - 7 * DAY_IN_SECONDS ) { return; }
		update_option( self::AUTO, time(), false );
		self::capture( gmdate( 'Y-m' ) );
		if ( (int) gmdate( 'j' ) <= 7 ) { self::capture( gmdate( 'Y-m', strtotime( 'first day of last month UTC' ) ) ); }
	}
	public static function disconnect() {
		$c = self::creds();
		unset( $c['gbp_refresh_token'] );
		update_option( 'scc_credentials', $c, false );
		delete_transient( self::CACHE );
		delete_option( self::STORE );
		delete_option( self::AUTO );
		return self::state();
	}
}
