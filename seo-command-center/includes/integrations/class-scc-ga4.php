<?php
/**
 * Read-only GA4 Data API integration for SEO Campaign Operations.
 *
 * Reuses the already configured Google OAuth Web Client (not the GSC grant).
 * GA4 receives an independent, user-approved analytics.readonly refresh token.
 * The Data API is not queried until the administrator explicitly authorizes it.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_GA4 {
	const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const DATA_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/';
	const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
	const TOKEN_CACHE = 'scc_ga4_access_token';
	const HISTORY_OPTION = 'scc_ga4_monthly_reports';
	const PROPERTY_OPTION = 'scc_ga4_property_id';
	const LAST_AUTO_OPTION = 'scc_ga4_last_auto_capture';

	public static function redirect_uri() {
		return admin_url( 'admin.php?page=seo-command-center-campaign' );
	}

	protected static function credentials() {
		$data = get_option( 'scc_credentials', array() );
		return is_array( $data ) ? $data : array();
	}

	public static function has_client() {
		$data = self::credentials();
		return ! empty( $data['gsc_client_id'] ) && ! empty( $data['gsc_client_secret'] );
	}

	public static function property_id() {
		$id = (string) get_option( self::PROPERTY_OPTION, '' );
		return preg_match( '/^[0-9]{4,20}$/', $id ) ? $id : '';
	}

	public static function set_property( $id ) {
		$id = trim( (string) $id );
		if ( ! preg_match( '/^[0-9]{4,20}$/', $id ) ) {
			return new WP_Error( 'scc_ga4_property', __( 'Enter the GA4 numeric Property ID, not the G- measurement ID.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$prior = self::property_id();
		update_option( self::PROPERTY_OPTION, $id, false );
		if ( $prior !== $id ) {
			// Different properties must never inherit old performance snapshots.
			delete_option( self::HISTORY_OPTION );
			delete_option( self::LAST_AUTO_OPTION );
		}
		return array( 'property_id' => $id, 'status' => self::status() );
	}

	public static function status() {
		$creds = self::credentials();
		if ( ! self::has_client() ) { return 'needs_google_client'; }
		if ( empty( $creds['ga4_refresh_token'] ) ) { return 'not_connected'; }
		if ( ! self::property_id() ) { return 'needs_property'; }
		return 'authorized_unverified';
	}

	public static function state() {
		return array(
			'status' => self::status(),
			'property_id' => self::property_id(),
			'google_oauth_configured' => self::has_client(),
			'redirect_uri' => self::redirect_uri(),
			'read_only' => true,
			'last_capture' => self::last_capture_at(),
		);
	}

	public static function auth_url() {
		if ( ! self::has_client() ) {
			return new WP_Error( 'scc_ga4_client', __( 'Configure the Google OAuth Client ID and Client Secret in TideOrbit Connections first.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		if ( ! self::property_id() ) {
			return new WP_Error( 'scc_ga4_property', __( 'Save your GA4 Property ID before connecting.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$creds = self::credentials();
		$uid = (int) get_current_user_id();
		if ( $uid < 1 ) {
			return new WP_Error( 'scc_ga4_user', 'A logged-in administrator is required.', array( 'status' => 403 ) );
		}
		$state = wp_generate_password( 48, false, false );
		set_transient( 'scc_ga4_oauth_' . $uid, $state, 15 * MINUTE_IN_SECONDS );
		return self::AUTH_URL . '?' . http_build_query( array(
			'client_id' => $creds['gsc_client_id'],
			'redirect_uri' => self::redirect_uri(),
			'response_type' => 'code',
			'scope' => self::SCOPE,
			'access_type' => 'offline',
			'prompt' => 'consent',
			'state' => $state,
		), '', '&', PHP_QUERY_RFC3986 );
	}

	/** Handle only the GA4 callback routed to the campaign screen. */
	public static function maybe_handle_callback() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) { return; }
		// OAuth callback requests use state verification instead of WP REST nonces.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'seo-command-center-campaign' !== $page || ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) ) { return; }
		$uid = (int) get_current_user_id();
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'scc_ga4_oauth_' . $uid );
		delete_transient( 'scc_ga4_oauth_' . $uid );
		$notice = 'failed';
		if ( $expected && '' !== $state && hash_equals( (string) $expected, $state ) ) {
			if ( ! isset( $_GET['error'] ) ) {
				$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
				$result = self::exchange_code( $code );
				$notice = is_wp_error( $result ) ? 'failed' : 'connected';
				if ( is_wp_error( $result ) && class_exists( 'SCC_Logger' ) ) {
					SCC_Logger::error( 'ga4', 'GA4 OAuth callback: ' . $result->get_error_message() );
				}
			}
		}
		wp_safe_redirect( add_query_arg( 'scc_ga4_notice', $notice, self::redirect_uri() ) );
		exit;
	}

	protected static function exchange_code( $code ) {
		if ( '' === $code || ! self::has_client() ) {
			return new WP_Error( 'scc_ga4_code', 'Invalid OAuth callback.' );
		}
		$c = self::credentials();
		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 25,
			'body' => array(
				'code' => $code, 'client_id' => $c['gsc_client_id'],
				'client_secret' => $c['gsc_client_secret'],
				'redirect_uri' => self::redirect_uri(),
				'grant_type' => 'authorization_code',
			),
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) || empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'scc_ga4_oauth', 'Google did not issue a GA4 refresh token. Retry consent with Analytics read access.', array( 'status' => 502 ) );
		}
		$c['ga4_refresh_token'] = trim( preg_replace( '/\s+/', '', (string) $data['refresh_token'] ) );
		update_option( 'scc_credentials', $c, false );
		delete_transient( self::TOKEN_CACHE );
		return true;
	}

	protected static function access_token() {
		$c = self::credentials();
		if ( ! self::has_client() || empty( $c['ga4_refresh_token'] ) ) {
			return new WP_Error( 'scc_ga4_disconnected', 'GA4 OAuth permission is not connected.', array( 'status' => 400 ) );
		}
		$cached = get_transient( self::TOKEN_CACHE );
		if ( is_string( $cached ) && '' !== $cached ) { return $cached; }
		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'body' => array(
				'client_id' => $c['gsc_client_id'],
				'client_secret' => $c['gsc_client_secret'],
				'refresh_token' => $c['ga4_refresh_token'],
				'grant_type' => 'refresh_token',
			),
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new WP_Error( 'scc_ga4_token', 'GA4 access was denied or expired. Reconnect Google Analytics.', array( 'status' => 502 ) );
		}
		$token = (string) $data['access_token'];
		set_transient( self::TOKEN_CACHE, $token, max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
		return $token;
	}

	/**
	 * A UTC month-to-date range ends two days ago to avoid reporting incomplete
	 * ingestion as zero. Historical months always query their complete dates.
	 */
	public static function date_range( $month, $today = '' ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) {
			return new WP_Error( 'scc_ga4_month', 'Invalid calendar month.', array( 'status' => 400 ) );
		}
		$today = '' !== $today ? $today : gmdate( 'Y-m-d' );
		$today_time = strtotime( $today . ' 00:00:00 UTC' );
		if ( false === $today_time ) { return new WP_Error( 'scc_ga4_date', 'Invalid current date.' ); }
		$start = $month . '-01';
		$last = gmdate( 'Y-m-t', strtotime( $start . ' 00:00:00 UTC' ) );
		if ( $start > $today ) { return new WP_Error( 'scc_ga4_future', 'The requested month has not started.', array( 'status' => 400 ) ); }
		$end = min( $last, gmdate( 'Y-m-d', $today_time - 2 * DAY_IN_SECONDS ) );
		if ( $end < $start ) { return new WP_Error( 'scc_ga4_ingestion', 'GA4 data for this month is not available yet.', array( 'status' => 409 ) ); }
		return array( 'start' => $start, 'end' => $end );
	}

	/** Data API v1beta read-only, session-scoped Organic Search channel. */
	public static function run_report( $month ) {
		$property = self::property_id();
		if ( ! $property ) { return new WP_Error( 'scc_ga4_property', 'Configure a numeric GA4 Property ID.', array( 'status' => 400 ) ); }
		$range = self::date_range( $month );
		if ( is_wp_error( $range ) ) { return $range; }
		$token = self::access_token();
		if ( is_wp_error( $token ) ) { return $token; }
		$metrics = array( 'sessions', 'totalUsers', 'keyEvents' );
		$body = array(
			'dateRanges' => array( array( 'startDate' => $range['start'], 'endDate' => $range['end'] ) ),
			'metrics' => array_map( function ( $name ) { return array( 'name' => $name ); }, $metrics ),
			'dimensionFilter' => array( 'filter' => array(
				'fieldName' => 'sessionDefaultChannelGroup',
				'stringFilter' => array( 'matchType' => 'EXACT', 'value' => 'Organic Search', 'caseSensitive' => false ),
			) ),
		);
		$response = wp_remote_post( self::DATA_URL . $property . ':runReport', array(
			'timeout' => 30,
			'headers' => array( 'authorization' => 'Bearer ' . $token, 'content-type' => 'application/json' ),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] )
				? sanitize_text_field( $data['error']['message'] ) : 'GA4 reporting request failed.';
			return new WP_Error( 'scc_ga4_api', $msg, array( 'status' => $status > 0 ? $status : 502 ) );
		}
		return self::normalize_report( $data, $month, $range, $property );
	}

	public static function normalize_report( $data, $month, array $range, $property ) {
		$expected = array( 'sessions', 'totalUsers', 'keyEvents' );
		if ( ! is_array( $data ) || ! isset( $data['metricHeaders'] ) || ! isset( $data['rowCount'] ) ) {
			return new WP_Error( 'scc_ga4_response', 'GA4 response has no verifiable metric headers or row count.', array( 'status' => 502 ) );
		}
		$headers = array_map( function ( $h ) { return (string) ( $h['name'] ?? '' ); }, (array) $data['metricHeaders'] );
		if ( $headers !== $expected ) {
			return new WP_Error( 'scc_ga4_headers', 'GA4 response does not match the requested metrics.', array( 'status' => 502 ) );
		}
		$count = (int) $data['rowCount'];
		$rows = isset( $data['rows'] ) ? (array) $data['rows'] : array();
		if ( $count > 0 && empty( $rows ) ) {
			return new WP_Error( 'scc_ga4_rows', 'GA4 did not include the measured rows.', array( 'status' => 502 ) );
		}
		if ( count( $rows ) > 1 ) { return new WP_Error( 'scc_ga4_rows', 'GA4 returned unexpected extra metric rows.', array( 'status' => 502 ) ); }
		$values = array( 'sessions' => 0, 'users' => 0, 'key_events' => 0 );
		if ( $rows ) {
			$items = (array) ( $rows[0]['metricValues'] ?? array() );
			if ( count( $items ) !== 3 ) { return new WP_Error( 'scc_ga4_metrics', 'GA4 returned incomplete metrics.', array( 'status' => 502 ) ); }
			$keys = array_keys( $values );
			foreach ( $keys as $i => $name ) {
				$number = $items[$i]['value'] ?? null;
				if ( null === $number || ! is_numeric( $number ) || (float) $number < 0 || ! is_finite( (float) $number ) ) {
					return new WP_Error( 'scc_ga4_value', 'GA4 returned a nonnumeric metric.', array( 'status' => 502 ) );
				}
				$values[$name] = (float) $number;
			}
		}
		return array(
			'state' => 'measured', 'provenance' => 'ga4_data_api',
			'property_id' => (string) $property, 'month' => (string) $month,
			'date_start' => $range['start'], 'date_end' => $range['end'],
			'channel' => 'Organic Search', 'channel_scope' => 'sessionDefaultChannelGroup',
			'sessions' => $values['sessions'], 'total_users' => $values['users'],
			'key_events' => $values['key_events'],
			'thresholded' => ! empty( $data['metadata']['subjectToThresholding'] ),
			'captured_at' => gmdate( 'c' ),
			'notice' => 'GA4 key events are configured Analytics goals, not necessarily qualified leads. Google Analytics metrics may differ from Search Console clicks.',
		);
	}

	public static function snapshots() {
		$data = get_option( self::HISTORY_OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	public static function monthly( $month ) {
		$stored = self::snapshots();
		$item = $stored[$month] ?? null;
		return is_array( $item ) && (string) ( $item['property_id'] ?? '' ) === self::property_id() ? $item : null;
	}

	public static function last_capture_at() {
		$data = self::snapshots();
		if ( ! $data ) { return null; }
		$latest = end( $data );
		return is_array( $latest ) ? ( $latest['captured_at'] ?? null ) : null;
	}

	public static function capture_month( $month ) {
		$result = self::run_report( $month );
		if ( is_wp_error( $result ) ) { return $result; }
		$stored = self::snapshots();
		$stored[$month] = $result;
		ksort( $stored );
		update_option( self::HISTORY_OPTION, array_slice( $stored, -24, null, true ), false );
		return $result;
	}

	public static function maybe_capture() {
		if ( 'authorized_unverified' !== self::status() ) { return; }
		$checked = (int) get_option( self::LAST_AUTO_OPTION, 0 );
		if ( $checked > time() - 7 * DAY_IN_SECONDS ) { return; }
		// Reserve the next attempt before HTTP calls. WP-Cron can trigger on
		// multiple concurrent requests; duplicate capture is harmless read-only.
		update_option( self::LAST_AUTO_OPTION, time(), false );
		$result = self::capture_month( gmdate( 'Y-m' ) );
		if ( is_wp_error( $result ) && class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'ga4', 'GA4 monthly snapshot not available: ' . $result->get_error_message() );
		}
		if ( (int) gmdate( 'j' ) <= 7 ) {
			self::capture_month( gmdate( 'Y-m', strtotime( 'first day of last month UTC' ) ) );
		}
	}

	public static function disconnect() {
		$c = self::credentials();
		unset( $c['ga4_refresh_token'] );
		update_option( 'scc_credentials', $c, false );
		delete_transient( self::TOKEN_CACHE );
		delete_option( self::HISTORY_OPTION );
		delete_option( self::LAST_AUTO_OPTION );
		// Never revoke GSC's possibly shared Google grant on GA4 disconnect.
		return self::state();
	}
}
