<?php
/**
 * HighLevel sub-account opportunity aggregate snapshots (no contact PII).
 * Private Integration Token with opportunities.readonly scope is required.
 * GET API only, bounded pagination and fail-closed coverage.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class SCC_GHL_Attribution {
	const API = 'https://services.leadconnectorhq.com/opportunities/search';
	const CONFIG = 'scc_ghl_attribution_config';
	const STORE = 'scc_ghl_attribution_snapshots';
	const AUTO = 'scc_ghl_last_capture';
	const MAX_PAGES = 5;
	const PAGE_SIZE = 100;

	protected static function creds() {
		$c = get_option( 'scc_credentials', array() );
		return is_array( $c ) ? $c : array();
	}
	public static function config() {
		$c = get_option( self::CONFIG, array() );
		return is_array( $c ) ? $c : array();
	}
	public static function connected() {
		$c = self::creds();
		$config = self::config();
		return ! empty( $c['ghl_private_integration_token'] ) && ! empty( $config['location_id'] );
	}
	public static function state() {
		$c = self::config();
		return array(
			'status' => ! self::connected() ? 'not_connected' : ( self::snapshots() ? 'measured' : 'configured_unverified' ),
			'location_id' => (string) ( $c['location_id'] ?? '' ),
			'pipeline_id' => (string) ( $c['pipeline_id'] ?? '' ),
			'has_private_token' => ! empty( self::creds()['ghl_private_integration_token'] ),
			'read_only' => true,
			'data_retained' => 'aggregate_only_no_contacts',
			'last_capture' => (int) get_option( self::AUTO, 0 ),
		);
	}
	public static function configure( $token, $location_id, $pipeline_id ) {
		$location_id = trim( (string) $location_id );
		$pipeline_id = trim( (string) $pipeline_id );
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{10,80}$/', $location_id ) || ( '' !== $pipeline_id && ! preg_match( '/^[a-zA-Z0-9_-]{10,80}$/', $pipeline_id ) ) ) {
			return new WP_Error( 'scc_ghl_location', 'Provide a valid HighLevel sub-account location ID and optional pipeline ID.', array( 'status' => 400 ) );
		}
		$token = trim( (string) $token );
		if ( '' !== $token && ( strlen( $token ) < 30 || strlen( $token ) > 4096 || preg_match( '/\s/', $token ) ) ) {
			return new WP_Error( 'scc_ghl_token', 'Invalid Private Integration Token format.', array( 'status' => 400 ) );
		}
		$c = self::creds();
		if ( '' === $token && empty( $c['ghl_private_integration_token'] ) ) {
			return new WP_Error( 'scc_ghl_token', 'Enter a HighLevel Private Integration Token with opportunities.readonly permission.', array( 'status' => 400 ) );
		}
		$prior = self::config();
		$changed = $location_id !== (string) ( $prior['location_id'] ?? '' ) || $pipeline_id !== (string) ( $prior['pipeline_id'] ?? '' );
		if ( '' !== $token ) {
			$changed = $changed || ! isset( $c['ghl_private_integration_token'] ) || ! hash_equals( (string) $c['ghl_private_integration_token'], $token );
			$c['ghl_private_integration_token'] = $token;
			update_option( 'scc_credentials', $c, false );
		}
		update_option( self::CONFIG, array( 'location_id' => $location_id, 'pipeline_id' => $pipeline_id ), false );
		if ( $changed ) { delete_option( self::STORE ); delete_option( self::AUTO ); }
		return self::state();
	}
	/** Exact allow-listed API; no arbitrary URL from provider nextPageUrl. */
	protected static function fetch_page( $page, $month ) {
		$c = self::config();
		$creds = self::creds();
		$begin = gmdate( 'm-d-Y', strtotime( $month . '-01 UTC' ) );
		$end = gmdate( 'm-d-Y', strtotime( gmdate( 'Y-m-t', strtotime( $month . '-01 UTC' ) ) . ' UTC' ) );
		$query = array(
			'locationId' => $c['location_id'], 'status' => 'all',
			'page' => max( 1, min( self::MAX_PAGES, (int) $page ) ),
			'limit' => self::PAGE_SIZE,
			'order' => 'added_desc',
			'date' => $begin, 'endDate' => $end,
		);
		if ( ! empty( $c['pipeline_id'] ) ) { $query['pipelineId'] = $c['pipeline_id']; }
		$r = wp_remote_get( self::API . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), array(
			'timeout' => 30, 'redirection' => 0,
			'headers' => array(
				'authorization' => 'Bearer ' . $creds['ghl_private_integration_token'],
				'Version' => 'v3', 'accept' => 'application/json',
			),
		) );
		if ( is_wp_error( $r ) ) { return new WP_Error( 'scc_ghl_network', 'HighLevel API request could not complete.', array( 'status' => 502 ) ); }
		$status = (int) wp_remote_retrieve_response_code( $r );
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $status ) { return new WP_Error( 'scc_ghl_api', 'HighLevel denied the read-only opportunity request. Confirm token permissions and API version.', array( 'status' => 502 ) ); }
		if ( ! is_array( $d ) || ! isset( $d['opportunities'] ) || ! is_array( $d['opportunities'] ) || ! isset( $d['meta'] ) || ! is_array( $d['meta'] ) || ! isset( $d['meta']['total'] ) || ! is_numeric( $d['meta']['total'] ) ) {
			return new WP_Error( 'scc_ghl_schema', 'HighLevel opportunity response is missing required pagination data; no partial metrics stored.', array( 'status' => 502 ) );
		}
		return $d;
	}
	public static function classify_source( $raw ) {
		$s = strtolower( trim( (string) $raw ) );
		if ( '' === $s ) { return 'unknown'; }
		// Tight, explicit labels, rather than guessing "Google" traffic was SEO.
		if ( preg_match( '/\b(google|bing)\s+(organic|seo)\b|\borganic\s+(search|seo)\b|\bseo\b/', $s ) ) { return 'organic_labeled'; }
		return 'other';
	}
	/** No names, phone numbers, emails, contact IDs, or individual opportunities are stored. */
	public static function summarize( array $rows, $month, $total ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) { return new WP_Error( 'scc_ghl_month', 'Invalid month.' ); }
		$counts = array( 'organic_labeled' => 0, 'other' => 0, 'unknown' => 0 );
		$statuses = array( 'open' => 0, 'won' => 0, 'lost' => 0, 'abandoned' => 0 );
		$won_value = 0.0;
		$seen = array();
		$valid = 0;
		foreach ( $rows as $opp ) {
			if ( ! is_array( $opp ) ) { continue; }
			$id = (string) ( $opp['id'] ?? '' );
			if ( '' === $id || isset( $seen[$id] ) ) { continue; }
			$seen[$id] = true;
			$created = (string) ( $opp['createdAt'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T/', $created ) ) {
				return new WP_Error( 'scc_ghl_created', 'HighLevel returned an opportunity without a verifiable creation date.' );
			}
			if ( 0 !== strpos( $created, $month ) ) { continue; }
			$valid++;
			$source = self::classify_source( $opp['source'] ?? '' );
			$counts[$source]++;
			$status = strtolower( (string) ( $opp['status'] ?? '' ) );
			if ( isset( $statuses[$status] ) ) { $statuses[$status]++; }
			$value = $opp['monetaryValue'] ?? null;
			if ( 'won' === $status && is_numeric( $value ) && (float) $value >= 0 && is_finite( (float) $value ) ) { $won_value += (float) $value; }
		}
		if ( 0 === $valid && $total > 0 ) {
			return new WP_Error( 'scc_ghl_coverage', 'No matching created-month opportunities were present in the API result; verify date filtering.' );
		}
		return array(
			'state' => 'measured', 'source' => 'highlevel_opportunities_v3',
			'month' => $month, 'opportunities_created_in_month' => $valid,
			'source_breakdown' => $counts, 'current_pipeline_status' => $statuses,
			'current_won_opportunity_value' => round( $won_value, 2 ),
			'captured_at' => gmdate( 'c' ),
			'notice' => 'Counts reflect CRM opportunities created in this month, classified by a stored source label. Won value is current opportunity value, not collected revenue. Missing or generic sources are NOT credited to SEO. Current status may change later.',
		);
	}
	public static function snapshots() {
		$d = get_option( self::STORE, array() );
		return is_array( $d ) ? $d : array();
	}
	public static function monthly( $month ) {
		$a = self::snapshots();
		$row = $a[$month] ?? null;
		$c = self::config();
		return is_array( $row ) && ( $row['location_id'] ?? '' ) === ( $c['location_id'] ?? '' ) && ( $row['pipeline_id'] ?? '' ) === ( $c['pipeline_id'] ?? '' ) ? $row : null;
	}
	public static function capture( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) || $month > gmdate( 'Y-m' ) ) {
			return new WP_Error( 'scc_ghl_month', 'Invalid or future month.', array( 'status' => 400 ) );
		}
		if ( ! self::connected() ) { return new WP_Error( 'scc_ghl_disconnected', 'Configure HighLevel first.', array( 'status' => 400 ) ); }
		$rows = array();
		$total = null;
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$payload = self::fetch_page( $page, $month );
			if ( is_wp_error( $payload ) ) { return $payload; }
			$reported = (int) $payload['meta']['total'];
			if ( $reported < 0 || $reported > self::MAX_PAGES * self::PAGE_SIZE ) {
				return new WP_Error( 'scc_ghl_too_many', 'More than 500 opportunities match; this report requires a narrower pipeline or a cursor-based integration. No partial totals saved.', array( 'status' => 409 ) );
			}
			if ( null !== $total && $total !== $reported ) {
				return new WP_Error( 'scc_ghl_shift', 'CRM count changed during pagination; retry the snapshot.', array( 'status' => 409 ) );
			}
			$total = $reported;
			$items = $payload['opportunities'];
			$rows = array_merge( $rows, $items );
			if ( count( $rows ) >= $total ) { break; }
			if ( ! $items ) { return new WP_Error( 'scc_ghl_missing_page', 'CRM pagination returned incomplete opportunity data.' ); }
		}
		if ( count( $rows ) < $total ) { return new WP_Error( 'scc_ghl_incomplete', 'Not all CRM pages could be retrieved; no partial results stored.' ); }
		$result = self::summarize( $rows, $month, $total );
		if ( is_wp_error( $result ) ) { return $result; }
		$c = self::config();
		$result['location_id'] = $c['location_id'];
		$result['pipeline_id'] = $c['pipeline_id'];
		$history = self::snapshots();
		$history[$month] = $result;
		ksort( $history );
		update_option( self::STORE, array_slice( $history, -24, null, true ), false );
		return $result;
	}
	public static function maybe_capture() {
		if ( ! self::connected() ) { return; }
		$last = (int) get_option( self::AUTO, 0 );
		if ( $last > time() - 7 * DAY_IN_SECONDS ) { return; }
		update_option( self::AUTO, time(), false );
		self::capture( gmdate( 'Y-m' ) );
	}
	public static function disconnect() {
		$c = self::creds();
		unset( $c['ghl_private_integration_token'] );
		update_option( 'scc_credentials', $c, false );
		delete_option( self::STORE );
		delete_option( self::AUTO );
		return self::state();
	}
}
