<?php
/**
 * Measured AI citations from DataForSEO's live Google SERPs and
 * indexed ChatGPT/Google LLM mentions datasets. Not an AI readiness score.
 *
 * Paid API calls are ALWAYS admin-initiated, never scheduled. These are
 * observations of one query/market or sampled database citations, not a
 * comprehensive measurement of every AI answer shown to all consumers.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_AI_Citation_Monitor {
	const SETTINGS = 'scc_ai_citation_config';
	const HISTORY = 'scc_ai_citation_history';
	const QUOTA = 'scc_ai_citation_paid_quota';
	const MAX_DAILY = 10;
	const MAX_HISTORY = 80;

	public static function domain() {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return preg_replace( '/^www\./', '', $host );
	}

	public static function config() {
		$v = get_option( self::SETTINGS, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function configure( array $data ) {
		$brand = trim( sanitize_text_field( (string) ( $data['brand'] ?? '' ) ) );
		$brand = substr( $brand, 0, 120 );
		if ( '' === $brand || strlen( $brand ) < 2 ) {
			return new WP_Error( 'scc_ai_brand', 'Enter the exact business/brand name to check.', array( 'status' => 400 ) );
		}
		$aliases = (string) ( $data['aliases'] ?? '' );
		$list = preg_split( '/[\r\n,]+/', $aliases );
		$out = array( $brand );
		foreach ( $list as $raw ) {
			$alias = substr( trim( sanitize_text_field( $raw ) ), 0, 120 );
			if ( strlen( $alias ) >= 3 && ! in_array( $alias, $out, true ) ) { $out[] = $alias; }
			if ( count( $out ) >= 5 ) { break; }
		}
		$before = self::config();
		$config = array( 'brand' => $brand, 'aliases' => array_slice( $out, 1 ), 'domain' => self::domain() );
		if ( $config !== $before ) { delete_option( self::HISTORY ); }
		update_option( self::SETTINGS, $config, false );
		return $config;
	}

	public static function quota_used() {
		$q = get_option( self::QUOTA, array() );
		return is_array( $q ) && ( $q['day'] ?? '' ) === gmdate( 'Ymd' ) ? (int) ( $q['used'] ?? 0 ) : 0;
	}
	protected static function reserve() {
		$used = self::quota_used();
		if ( $used >= self::MAX_DAILY ) { return new WP_Error( 'scc_ai_quota', 'Maximum 10 paid AI visibility requests per WordPress site per day reached.', array( 'status' => 429 ) ); }
		// Best-effort per-site limit; an account-level financial cap remains necessary.
		update_option( self::QUOTA, array( 'day' => gmdate( 'Ymd' ), 'used' => $used + 1 ), false );
		return true;
	}

	public static function state() {
		return array(
			'config' => self::config(),
			'dataforseo_connected' => class_exists( 'SCC_DataForSEO' ) && SCC_DataForSEO::is_connected(),
			'paid_calls_today' => self::quota_used(), 'daily_soft_cap' => self::MAX_DAILY,
			'direct_providers' => class_exists( 'SCC_AI_Direct_Providers' ) ? SCC_AI_Direct_Providers::state() : array(),
			'platforms' => array(
				'google_ai_overview' => 'measurable_per_query_paid',
				'google_ai_mode' => 'measurable_per_query_paid',
				'chatgpt' => 'dataforseo_sampled_citations_paid',
				'google_dataset' => 'dataforseo_sampled_citations_paid',
				'perplexity' => class_exists( 'SCC_AI_Direct_Providers' ) && SCC_AI_Direct_Providers::connected( 'perplexity' ) ? 'direct_api_ready_paid' : 'needs_api_key',
				'gemini' => class_exists( 'SCC_AI_Direct_Providers' ) && SCC_AI_Direct_Providers::connected( 'gemini' ) ? 'grounded_api_ready_paid' : 'needs_api_key',
			),
		);
	}

	/** Public, deterministic parser. Never treat ordinary organic links as AI citations. */
	public static function ai_serp_observation( $results, $keyword, $market, $domain, array $brands, $surface ) {
		if ( ! is_array( $results ) || ! isset( $results[0] ) || ! is_array( $results[0] ) || ( ! isset( $results[0]['items'] ) || ! is_array( $results[0]['items'] ) ) && ! ( 'google_ai_mode' === $surface && isset( $results[0]['ai_overview'] ) && is_array( $results[0]['ai_overview'] ) ) ) {
			return new WP_Error( 'scc_ai_serp_incomplete', 'Paid SERP response lacked structured items; no visibility measurement recorded.', array( 'status' => 502 ) );
		}
		$block = $results[0];
		$ai = array();
		if ( 'google_ai_mode' === $surface && ! empty( $block['ai_overview'] ) && is_array( $block['ai_overview'] ) ) { $ai[] = $block['ai_overview']; }
		foreach ( (array) ( $block['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && 'ai_overview' === ( $item['type'] ?? '' ) ) { $ai[] = $item; }
		}
		if ( ! $ai ) {
			// No AI result in this measured SERP is a legitimate "not shown".
			return array(
				'state' => 'ai_result_not_shown', 'surface' => $surface, 'keyword' => $keyword,
				'market' => $market, 'domain' => $domain, 'brand_mentioned' => false,
				'domain_cited' => false, 'citations' => array(), 'references_observed' => 0,
				'captured_at' => gmdate( 'c' ), 'source' => 'dataforseo_live_serp',
				'notice' => 'No AI answer was returned for this exact SERP check. This does not mean Google never shows AI answers for the query.',
			);
		}
		$references = array();
		$texts = array();
		foreach ( $ai as $overview ) {
			foreach ( array( 'markdown', 'text' ) as $k ) {
				if ( isset( $overview[$k] ) && is_string( $overview[$k] ) ) { $texts[] = $overview[$k]; }
			}
			self::extract_references( $overview, $references, $texts );
		}
		$cites = array();
		$seen_urls = array();
		$all_ref_count = 0;
		foreach ( $references as $reference ) {
			if ( ! is_array( $reference ) ) { continue; }
			$url = trim( (string) ( $reference['url'] ?? '' ) );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'https', 'http' ), true ) ) { continue; }
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host === '' ) { continue; }
			$all_ref_count++;
			if ( ! self::is_domain( $host, $domain ) || isset( $seen_urls[$url] ) ) { continue; }
			$seen_urls[$url] = true;
			$cites[] = array(
				'url' => esc_url_raw( $url ),
				'title' => substr( sanitize_text_field( (string) ( $reference['title'] ?? '' ) ), 0, 180 ),
			);
			if ( count( $cites ) >= 15 ) { break; }
		}
		$answer = implode( ' ', $texts );
		$mentioned = false;
		foreach ( $brands as $brand ) {
			$brand = trim( (string) $brand );
			if ( strlen( $brand ) >= 3 && false !== stripos( $answer, $brand ) ) { $mentioned = true; break; }
		}
		return array(
			'state' => 'measured', 'surface' => $surface, 'keyword' => $keyword,
			'market' => $market, 'domain' => $domain,
			'brand_mentioned' => $mentioned, 'domain_cited' => count( $cites ) > 0,
			'citations' => $cites, 'references_observed' => $all_ref_count,
			'captured_at' => gmdate( 'c' ), 'source' => 'dataforseo_live_serp',
			'notice' => 'One observed live Google AI answer. Brand mention is not a citation; a citation requires an actual matching cited URL. Location, time and user context can change answers.',
		);
	}

	protected static function extract_references( $element, array &$refs, array &$texts, $depth = 0 ) {
		if ( $depth > 5 || ! is_array( $element ) ) { return; }
		if ( isset( $element['references'] ) && is_array( $element['references'] ) ) {
			foreach ( array_slice( $element['references'], 0, 70 ) as $r ) { $refs[] = $r; }
		}
		foreach ( (array) ( $element['items'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) ) { continue; }
			foreach ( array( 'text', 'markdown' ) as $field ) {
				if ( isset( $child[$field] ) && is_string( $child[$field] ) ) { $texts[] = $child[$field]; }
			}
			self::extract_references( $child, $refs, $texts, $depth + 1 );
		}
	}

	public static function is_domain( $candidate, $domain ) {
		$candidate = strtolower( preg_replace( '/^www\./', '', trim( (string) $candidate ) ) );
		$domain = strtolower( preg_replace( '/^www\./', '', trim( (string) $domain ) ) );
		return $candidate === $domain || ( '' !== $domain && substr( $candidate, -strlen( '.' . $domain ) ) === '.' . $domain );
	}

	/** Domain citations in DataForSEO's indexed LLM-response dataset. */
	public static function normalize_dataset( $results, $platform, $domain ) {
		if ( ! is_array( $results ) || ! isset( $results[0]['aggregated_metrics']['total'] ) || ! is_array( $results[0]['aggregated_metrics']['total'] ) ) {
			return new WP_Error( 'scc_ai_dataset_incomplete', 'No valid LLM citations aggregate was returned. Missing data cannot be treated as zero.', array( 'status' => 502 ) );
		}
		$total = $results[0]['aggregated_metrics']['total'];
		if ( ! isset( $total['mentions'] ) || ! is_numeric( $total['mentions'] ) || (float) $total['mentions'] < 0 ) {
			return new WP_Error( 'scc_ai_mentions_invalid', 'Provider did not return a valid citations count.', array( 'status' => 502 ) );
		}
		$volume = $total['ai_search_volume'] ?? null;
		return array(
			'state' => 'measured_sample', 'surface' => $platform === 'chat_gpt' ? 'chatgpt_dataset' : 'google_ai_dataset',
			'domain' => $domain, 'cited_domain_mentions' => (int) $total['mentions'],
			'ai_search_volume_estimate' => is_numeric( $volume ) && (float) $volume >= 0 ? (float) $volume : null,
			'location' => 'United States', 'captured_at' => gmdate( 'c' ), 'source' => 'dataforseo_llm_mentions_dataset',
			'notice' => 'Count reflects citations in the DataForSEO indexed response dataset, NOT every conversation, live ChatGPT response or global AI visibility. Do not interpret as impressions or conversions.',
		);
	}

	public static function history() {
		$h = get_option( self::HISTORY, array() );
		return is_array( $h ) ? $h : array();
	}
	public static function monthly( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) { return array(); }
		return array_values( array_filter( self::history(), function ( $r ) use ( $month ) {
			return is_array( $r ) && substr( (string) ( $r['captured_at'] ?? '' ), 0, 7 ) === $month;
		} ) );
	}
	protected static function save( array $r ) {
		$history = self::history();
		$history[] = $r;
		update_option( self::HISTORY, array_slice( $history, -self::MAX_HISTORY ), false );
		return $r;
	}
	public static function measure( array $data ) {
		if ( empty( $data['confirm_paid'] ) ) { return new WP_Error( 'scc_ai_confirmation', 'Confirm the paid DataForSEO API call before scanning.', array( 'status' => 400 ) ); }
		$surface = sanitize_key( (string) ( $data['surface'] ?? '' ) );
		$direct = in_array( $surface, array( 'perplexity_agent_api', 'gemini_grounded_api' ), true );
		if ( ! $direct && ( ! class_exists( 'SCC_DataForSEO' ) || ! SCC_DataForSEO::is_connected() ) ) {
			return new WP_Error( 'scc_ai_no_provider', 'Connect DataForSEO in TideOrbit Connections first.', array( 'status' => 400 ) );
		}
		$cfg = self::config();
		if ( empty( $cfg['brand'] ) ) { return new WP_Error( 'scc_ai_no_brand', 'Save your exact business name first.', array( 'status' => 400 ) ); }
		$domain = self::domain();
		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) { return new WP_Error( 'scc_ai_domain', 'Site host is invalid.', array( 'status' => 400 ) ); }
		$dataset = in_array( $surface, array( 'chatgpt_dataset', 'google_ai_dataset' ), true );
		if ( ! $dataset && ! $direct && ! in_array( $surface, array( 'google_ai_overview', 'google_ai_mode' ), true ) ) {
			return new WP_Error( 'scc_ai_unavailable', 'This platform is not integrated for verified measurements.', array( 'status' => 400 ) );
		}
		$keyword = '';
		$market = '';
		if ( ! $dataset ) {
			$keyword = trim( sanitize_text_field( (string) ( $data['keyword'] ?? '' ) ) );
			$market = $direct ? 'API prompt (not geolocated)' : trim( sanitize_text_field( (string) ( $data['market'] ?? '' ) ) );
			if ( strlen( $keyword ) < 3 || strlen( $keyword ) > 180 || strlen( $market ) < 3 || strlen( $market ) > 120 ) {
				return new WP_Error( 'scc_ai_query', 'Enter a keyword or question and supported Google search location.', array( 'status' => 400 ) );
			}
		}
		$allowed = self::reserve();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( $direct ) {
			$result = SCC_AI_Direct_Providers::run( $surface, $keyword, $domain, (string) $cfg['brand'] );
		} elseif ( $dataset ) {
			$platform = $surface === 'chatgpt_dataset' ? 'chat_gpt' : 'google';
			$raw = SCC_DataForSEO::ai_cited_domain_metrics( $domain, $platform );
			if ( is_wp_error( $raw ) ) { return $raw; }
			$result = self::normalize_dataset( $raw, $platform, $domain );
		} else {
			$raw = SCC_DataForSEO::ai_serp_response( $keyword, $market, $surface );
			if ( is_wp_error( $raw ) ) { return $raw; }
			$names = array_merge( array( $cfg['brand'] ), (array) ( $cfg['aliases'] ?? array() ) );
			$result = self::ai_serp_observation( $raw, $keyword, $market, $domain, $names, $surface );
		}
		return is_wp_error( $result ) ? $result : self::save( $result );
	}
}
