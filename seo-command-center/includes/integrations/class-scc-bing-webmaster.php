<?php
/**
 * Bing Webmaster Tools read-only JSON API bridge for TideOrbit campaigns.
 *
 * No site mutation, URL submission, or cloud middleware.
 * API key belongs to the verified site's owner and is never returned via REST.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Bing_Webmaster {
	const API = 'https://ssl.bing.com/webmaster/api.svc/json/';
	const STORE = 'scc_bing_campaign_snapshots';
	const CHECK = 'scc_bing_last_capture';
	const SITE = 'scc_bing_site_url';

	protected static function credentials() {
		$c = get_option( 'scc_credentials', array() );
		return is_array( $c ) ? $c : array();
	}

	public static function connected() {
		$c = self::credentials();
		return ! empty( $c['bing_api_key'] ) && '' !== (string) get_option( self::SITE, '' );
	}

	public static function site() {
		return (string) get_option( self::SITE, '' );
	}

	public static function status() {
		if ( ! self::connected() ) { return 'not_connected'; }
		return self::snapshots() ? 'measured' : 'configured_unverified';
	}

	public static function state() {
		return array(
			'status' => self::status(),
			'site_url' => self::site(),
			'has_api_key' => ! empty( self::credentials()['bing_api_key'] ),
			'read_only' => true,
		);
	}

	public static function configure( $api_key, $site_url ) {
		$site_url = trim( esc_url_raw( (string) $site_url ) );
		$parsed = wp_parse_url( $site_url );
		if ( ! is_array( $parsed ) || ! in_array( $parsed['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parsed['host'] ) || ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) || ! empty( $parsed['query'] ) ) {
			return new WP_Error( 'scc_bing_site', 'Enter the exact verified Bing site URL (https://example.com/).', array( 'status' => 400 ) );
		}
		// Only match verified website origin to prevent using an API key
		// for a different/unrelated domain by accidental configuration.
		$config_host = strtolower( (string) $parsed['host'] );
		$own_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( $config_host !== $own_host ) {
			return new WP_Error( 'scc_bing_host', 'The Bing Webmaster site must match this WordPress site host.', array( 'status' => 400 ) );
		}
		$key = trim( (string) $api_key );
		if ( '' !== $key && ( strlen( $key ) < 12 || strlen( $key ) > 256 || preg_match( '/\s/', $key ) ) ) {
			return new WP_Error( 'scc_bing_key', 'The Bing API key is malformed.', array( 'status' => 400 ) );
		}
		$creds = self::credentials();
		if ( '' === $key && empty( $creds['bing_api_key'] ) ) {
			return new WP_Error( 'scc_bing_key', 'Enter a Bing Webmaster API key.', array( 'status' => 400 ) );
		}
		$prior_site = self::site();
		if ( '' !== $key ) {
			$changed = ! isset( $creds['bing_api_key'] ) || ! hash_equals( (string) $creds['bing_api_key'], $key );
			$creds['bing_api_key'] = $key;
			update_option( 'scc_credentials', $creds, false );
			if ( $changed ) { delete_option( self::STORE ); }
		}
		update_option( self::SITE, $site_url, false );
		if ( $prior_site !== $site_url ) {
			delete_option( self::STORE );
			delete_option( self::CHECK );
		}
		return self::state();
	}

	/** Allow-listed Microsoft REST JSON API, no arbitrary URL handling. */
	protected static function request( $method, array $params = array() ) {
		if ( ! in_array( $method, array( 'GetUserSites', 'GetRankAndTrafficStats', 'GetQueryStats' ), true ) ) {
			return new WP_Error( 'scc_bing_method', 'Unsupported Bing API operation.' );
		}
		$creds = self::credentials();
		if ( empty( $creds['bing_api_key'] ) ) {
			return new WP_Error( 'scc_bing_disconnected', 'Bing Webmaster API key is not configured.' );
		}
		$params['apikey'] = $creds['bing_api_key'];
		$url = self::API . $method . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$response = wp_remote_get( $url, array(
			'timeout' => 25,
			'redirection' => 0,
			'headers' => array( 'accept' => 'application/json' ),
		) );
		if ( is_wp_error( $response ) ) {
			// The API key is in the URL by Microsoft's contract: do not return
			// WordPress transport errors that may echo URLs and credentials.
			return new WP_Error( 'scc_bing_network', 'Could not contact Bing Webmaster Tools.', array( 'status' => 502 ) );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'scc_bing_http', 'Bing Webmaster rejected the request. Check site verification and API key.', array( 'status' => 502 ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || ! array_key_exists( 'd', $json ) || ! is_array( $json['d'] ) || isset( $json['ErrorCode'] ) ) {
			return new WP_Error( 'scc_bing_response', 'Bing Webmaster returned invalid or incomplete JSON.', array( 'status' => 502 ) );
		}
		return $json['d'];
	}

	public static function sites() {
		$rows = self::request( 'GetUserSites' );
		if ( is_wp_error( $rows ) ) { return $rows; }
		$verified = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$verified[] = array(
				'url' => (string) ( $row['Url'] ?? '' ),
				'verified' => ! empty( $row['IsVerified'] ),
			);
		}
		return $verified;
	}

	/** Microsoft JSON dates use /Date(milliseconds+offset)/; ISO is supported too. */
	public static function stat_date( $raw ) {
		$raw = (string) $raw;
		if ( preg_match( '#^/Date\((\d{10,16})([+-]\d{4})?\)/$#', $raw, $m ) ) {
			$ms = (float) $m[1];
			return gmdate( 'Y-m-d', (int) floor( $ms / 1000 ) );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw ) ) {
			return substr( $raw, 0, 10 );
		}
		return '';
	}

	public static function aggregate( array $traffic, array $query_stats, $month, $site_url ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) {
			return new WP_Error( 'scc_bing_month', 'Invalid report month.', array( 'status' => 400 ) );
		}
		$clicks = 0; $impressions = 0; $days = array();
		foreach ( $traffic as $row ) {
			if ( ! is_array( $row ) || 0 !== strpos( self::stat_date( $row['Date'] ?? '' ), $month ) ) { continue; }
			$c = $row['Clicks'] ?? null;
			$i = $row['Impressions'] ?? null;
			if ( ! is_numeric( $c ) || ! is_numeric( $i ) || (float) $c < 0 || (float) $i < 0 ) { continue; }
			$date = self::stat_date( $row['Date'] );
			if ( isset( $days[$date] ) ) { continue; } // Avoid duplicate daily rows.
			$days[$date] = true;
			$clicks += (int) $c; $impressions += (int) $i;
		}
		$terms = array();
		foreach ( $query_stats as $row ) {
			if ( ! is_array( $row ) || 0 !== strpos( self::stat_date( $row['Date'] ?? '' ), $month ) ) { continue; }
			$term = trim( (string) ( $row['Query'] ?? '' ) );
			if ( '' === $term ) { continue; }
			$c = $row['Clicks'] ?? null; $i = $row['Impressions'] ?? null;
			if ( ! is_numeric( $c ) || ! is_numeric( $i ) || (float) $c < 0 || (float) $i < 0 ) { continue; }
			if ( ! isset( $terms[$term] ) ) { $terms[$term] = array( 'query' => $term, 'clicks' => 0, 'impressions' => 0, 'avg_impression_position' => null, 'position_weight' => 0, 'sum_position' => 0 ); }
			$terms[$term]['clicks'] += (int) $c;
			$terms[$term]['impressions'] += (int) $i;
			$pos = $row['AvgImpressionPosition'] ?? null;
			if ( is_numeric( $pos ) && (float) $pos > 0 && (int) $i > 0 ) {
				$terms[$term]['sum_position'] += (float) $pos * (int) $i;
				$terms[$term]['position_weight'] += (int) $i;
			}
		}
		foreach ( $terms as &$term ) {
			$term['avg_impression_position'] = $term['position_weight'] ? round( $term['sum_position'] / $term['position_weight'], 1 ) : null;
			unset( $term['sum_position'], $term['position_weight'] );
		}
		unset( $term );
		uasort( $terms, function ( $a, $b ) { return $b['impressions'] <=> $a['impressions']; } );
		return array(
			'source' => 'bing_webmaster_tools', 'status' => count( $days ) ? 'measured' : 'unavailable',
			'site_url' => (string) $site_url, 'month' => $month,
			'clicks' => count( $days ) ? $clicks : null,
			'impressions' => count( $days ) ? $impressions : null,
			'measured_days' => count( $days ), 'query_rows' => array_slice( array_values( $terms ), 0, 30 ),
			'captured_at' => gmdate( 'c' ),
			'notice' => 'Bing Webmaster traffic includes multiple Bing surfaces and may not match Google Search Console or GA4 organic sessions.',
		);
	}

	public static function snapshots() {
		$stored = get_option( self::STORE, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public static function monthly( $month ) {
		$all = self::snapshots();
		$row = $all[$month] ?? null;
		return is_array( $row ) && self::site() === (string) ( $row['site_url'] ?? '' ) ? $row : null;
	}

	public static function capture( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) || $month > gmdate( 'Y-m' ) ) {
			return new WP_Error( 'scc_bing_month', 'Invalid or future Bing report month.', array( 'status' => 400 ) );
		}
		if ( ! self::connected() ) { return new WP_Error( 'scc_bing_not_connected', 'Configure Bing Webmaster first.', array( 'status' => 400 ) ); }
		$site = self::site();
		$traffic = self::request( 'GetRankAndTrafficStats', array( 'siteUrl' => $site ) );
		if ( is_wp_error( $traffic ) ) { return $traffic; }
		$queries = self::request( 'GetQueryStats', array( 'siteUrl' => $site ) );
		if ( is_wp_error( $queries ) ) { return $queries; }
		$report = self::aggregate( $traffic, $queries, $month, $site );
		if ( is_wp_error( $report ) ) { return $report; }
		$all = self::snapshots();
		$all[$month] = $report;
		ksort( $all );
		update_option( self::STORE, array_slice( $all, -24, null, true ), false );
		return $report;
	}

	public static function maybe_capture() {
		if ( ! self::connected() ) { return; }
		$checked = (int) get_option( self::CHECK, 0 );
		if ( $checked > time() - 7 * DAY_IN_SECONDS ) { return; }
		update_option( self::CHECK, time(), false );
		$current = self::capture( gmdate( 'Y-m' ) );
		if ( is_wp_error( $current ) && class_exists( 'SCC_Logger' ) ) { SCC_Logger::info( 'bing', 'Bing snapshot unavailable.' ); }
		if ( (int) gmdate( 'j' ) <= 7 ) { self::capture( gmdate( 'Y-m', strtotime( 'first day of last month UTC' ) ) ); }
	}

	public static function disconnect() {
		$c = self::credentials();
		unset( $c['bing_api_key'] );
		update_option( 'scc_credentials', $c, false );
		delete_option( self::SITE ); delete_option( self::STORE ); delete_option( self::CHECK );
		return self::state();
	}
}
