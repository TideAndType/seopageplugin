<?php
/**
 * Paid, on-demand, direct API observations for Perplexity Agent and Gemini.
 * Treat source retrieval separately from source actually cited in an answer.
 * No user, lead, visitor, or medical data is transmitted to model providers.
 * Only an admin-supplied, non-sensitive search question is submitted.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_AI_Direct_Providers {
	const KEY_PERPLEXITY = 'perplexity_agent_key';
	const KEY_GEMINI = 'gemini_grounding_key';
	const COMPETITORS = 'scc_ai_competitor_domains';
	const PERPLEXITY = 'https://api.perplexity.ai/v1/agent';
	const GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

	protected static function creds() {
		$v = get_option( 'scc_credentials', array() );
		return is_array( $v ) ? $v : array();
	}
	public static function connected( $provider ) {
		$key = 'perplexity' === $provider ? self::KEY_PERPLEXITY : ( 'gemini' === $provider ? self::KEY_GEMINI : '' );
		$c = self::creds();
		return '' !== $key && ! empty( $c[$key] );
	}
	public static function competitors() {
		$a = get_option( self::COMPETITORS, array() );
		return is_array( $a ) ? array_values( $a ) : array();
	}
	public static function state() {
		return array(
			'perplexity_connected' => self::connected( 'perplexity' ),
			'gemini_connected' => self::connected( 'gemini' ),
			'competitor_domains' => self::competitors(),
			'perplexity_interface' => 'official_perplexity_agent_api_v1',
			'gemini_interface' => 'gemini_developer_api_grounded_google_search',
			'read_only_no_wp_content_changes' => true,
		);
	}
	public static function update_key( $provider, $token ) {
		$key = 'perplexity' === $provider ? self::KEY_PERPLEXITY : ( 'gemini' === $provider ? self::KEY_GEMINI : '' );
		if ( ! $key ) { return new WP_Error( 'scc_ai_provider', 'Unsupported AI provider.', array( 'status' => 400 ) ); }
		$token = trim( (string) $token );
		if ( '' !== $token && ( strlen( $token ) < 20 || strlen( $token ) > 4096 || preg_match( '/\s/', $token ) ) ) {
			return new WP_Error( 'scc_ai_key', 'Enter a valid provider API key.', array( 'status' => 400 ) );
		}
		$c = self::creds();
		if ( '' === $token && empty( $c[$key] ) ) { return new WP_Error( 'scc_ai_key_missing', 'Enter the provider API key first.', array( 'status' => 400 ) ); }
		if ( '' !== $token ) {
			$c[$key] = $token;
			update_option( 'scc_credentials', $c, false );
		}
		return self::state();
	}
	public static function disconnect( $provider ) {
		$key = 'perplexity' === $provider ? self::KEY_PERPLEXITY : ( 'gemini' === $provider ? self::KEY_GEMINI : '' );
		if ( ! $key ) { return new WP_Error( 'scc_ai_provider', 'Unsupported AI provider.' ); }
		$c = self::creds();
		unset( $c[$key] );
		update_option( 'scc_credentials', $c, false );
		// Stored historical measurements retain their timestamp/provider label.
		return self::state();
	}
	public static function validate_domain( $raw ) {
		$raw = strtolower( trim( (string) $raw ) );
		if ( preg_match( '#^https?://#', $raw ) ) {
			$u = wp_parse_url( $raw );
			if ( ! is_array( $u ) || ! empty( $u['path'] ) && '/' !== $u['path'] || ! empty( $u['query'] ) || ! empty( $u['user'] ) || ! empty( $u['pass'] ) || ! empty( $u['port'] ) ) { return ''; }
			$raw = (string) ( $u['host'] ?? '' );
		}
		$raw = preg_replace( '/^www\./', '', rtrim( $raw, '/.' ) );
		if ( strlen( $raw ) > 253 || ! preg_match( '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $raw ) ) { return ''; }
		return $raw;
	}
	public static function configure_competitors( $raw, $self_domain ) {
		$domains = preg_split( '/[\r\n,]+/', (string) $raw );
		$out = array();
		foreach ( $domains as $domain ) {
			if ( '' === trim( $domain ) ) { continue; }
			$verified = self::validate_domain( $domain );
			if ( '' === $verified ) { return new WP_Error( 'scc_ai_competitor', 'Competitor entries must be valid domains (no URL paths or IP addresses).', array( 'status' => 400 ) ); }
			if ( $verified === $self_domain ) { return new WP_Error( 'scc_ai_self_competitor', 'Your own website cannot be a competitor.', array( 'status' => 400 ) ); }
			if ( ! in_array( $verified, $out, true ) ) { $out[] = $verified; }
			if ( count( $out ) > 4 ) { return new WP_Error( 'scc_ai_competitor_limit', 'Use up to four competitors for one answer.', array( 'status' => 400 ) ); }
		}
		update_option( self::COMPETITORS, $out, false );
		return self::state();
	}

	public static function observed_citations( array $urls, $self_domain, array $competitors ) {
		$targets = array_merge( array( $self_domain ), $competitors );
		$found = array();
		foreach ( $targets as $host ) { $found[$host] = array(); }
		foreach ( $urls as $entry ) {
			$url = is_array( $entry ) ? (string) ( $entry['url'] ?? '' ) : (string) $entry;
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! in_array( $scheme, array( 'https', 'http' ), true ) || '' === $host || ! filter_var( $url, FILTER_VALIDATE_URL ) ) { continue; }
			foreach ( $targets as $target ) {
				if ( SCC_AI_Citation_Monitor::is_domain( $host, $target ) ) {
					$found[$target][$url] = true;
				}
			}
		}
		$summary = array();
		foreach ( $found as $domain => $matches ) {
			$summary[] = array( 'domain' => $domain, 'cited' => count( $matches ) > 0, 'cited_urls' => min( 10, count( $matches ) ) );
		}
		return $summary;
	}
	protected static function note( $brand, $answer ) {
		return strlen( trim( $brand ) ) >= 3 && false !== stripos( $answer, trim( $brand ) );
	}
	protected static function safe_source( $url, $title = '' ) {
		$url = trim( (string) $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( $scheme, array( 'https', 'http' ), true ) ) { return null; }
		return array( 'url' => esc_url_raw( $url ), 'title' => substr( sanitize_text_field( (string) $title ), 0, 140 ) );
	}
	protected static function base( $platform, $query, $domain, $brand, array $cited, array $retrieved, $answer, $model, $source, $citation_status, array $competitors ) {
		$own = self::observed_citations( $cited, $domain, $competitors );
		$citations = array();
		foreach ( $cited as $row ) {
			$url = (string) ( $row['url'] ?? '' );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( SCC_AI_Citation_Monitor::is_domain( $host, $domain ) ) {
				$citations[$url] = $row;
			}
		}
		return array(
			'state' => 'measured_api_observation',
			'surface' => $platform, 'source' => $source, 'model' => $model,
			'keyword' => $query, 'market' => 'API prompt (not geolocated)',
			'domain' => $domain, 'brand_mentioned' => self::note( $brand, $answer ),
			'domain_cited' => 'verified_citation_markers' === $citation_status || 'verified_grounding_supports' === $citation_status
				? (bool) count( $citations ) : null,
			'citation_evidence_state' => $citation_status,
			'citations' => array_slice( array_values( $citations ), 0, 10 ),
			'cited_source_count' => count( $cited ),
			'retrieved_source_count' => count( $retrieved ),
			'competitor_citations' => $own,
			'captured_at' => gmdate( 'c' ),
			'notice' => 'Controlled single API response, not every consumer-facing app answer. Retrieved sources are not necessarily cited in the final text; null citation status means not independently verifiable.',
		);
	}

	/** Parse Perplexity Agent API. Search results alone are NOT cited sources. */
	public static function normalize_perplexity( $body, $query, $domain, $brand, array $competitors ) {
		if ( ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'completed' || ! isset( $body['output'] ) || ! is_array( $body['output'] ) ) {
			return new WP_Error( 'scc_ai_perplexity_incomplete', 'Perplexity Agent API did not return a completed answer.', array( 'status' => 502 ) );
		}
		$text = ''; $sources = array(); $citations = array(); $verified = false;
		foreach ( $body['output'] as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			if ( 'search_results' === ( $item['type'] ?? '' ) ) {
				foreach ( (array) ( $item['results'] ?? array() ) as $source ) {
					if ( ! is_array( $source ) || ! isset( $source['id'] ) || ! is_numeric( $source['id'] ) ) { continue; }
					$normalized = self::safe_source( $source['url'] ?? '', $source['title'] ?? '' );
					if ( $normalized ) { $sources[(int) $source['id']] = $normalized; }
				}
			}
			if ( 'message' === ( $item['type'] ?? '' ) && 'assistant' === ( $item['role'] ?? '' ) ) {
				foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
					if ( ! is_array( $part ) || 'output_text' !== ( $part['type'] ?? '' ) ) { continue; }
					$text .= ' ' . (string) ( $part['text'] ?? '' );
					foreach ( (array) ( $part['annotations'] ?? array() ) as $annotation ) {
						if ( ! is_array( $annotation ) || ! in_array( $annotation['type'] ?? '', array( 'url_citation', 'citation' ), true ) ) { continue; }
						$link = self::safe_source( $annotation['url'] ?? '', $annotation['title'] ?? '' );
						if ( $link ) { $citations[$link['url']] = $link; $verified = true; }
					}
				}
			}
		}
		if ( '' === trim( $text ) ) { return new WP_Error( 'scc_ai_perplexity_empty', 'Perplexity returned no assistant answer text.', array( 'status' => 502 ) ); }
		// Perplexity Agent API inline citations may refer to search_results IDs
		// as [3] or [2][4]. Only those explicit markers count as citations.
		if ( preg_match_all( '/\[(\d{1,3})\]/', $text, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				if ( isset( $sources[(int) $id] ) ) { $citations[$sources[(int) $id]['url']] = $sources[(int) $id]; $verified = true; }
			}
		}
		return self::base( 'perplexity_agent_api', $query, $domain, $brand,
			array_values( $citations ), array_values( $sources ), $text, (string) ( $body['model'] ?? 'agent-preset-fast' ),
			'perplexity_agent_api', $verified ? 'verified_citation_markers' : 'retrieved_only_or_unverified', $competitors );
	}
	/** Parse Gemini Google Search grounding. Require groundingSupports indices. */
	public static function normalize_gemini( $body, $query, $domain, $brand, array $competitors ) {
		if ( ! is_array( $body ) || empty( $body['candidates'][0] ) || ! is_array( $body['candidates'][0] ) ) {
			return new WP_Error( 'scc_ai_gemini_incomplete', 'Gemini returned no completed response candidate.', array( 'status' => 502 ) );
		}
		$first = $body['candidates'][0];
		if ( ! in_array( (string) ( $first['finishReason'] ?? '' ), array( 'STOP' ), true ) ) {
			return new WP_Error( 'scc_ai_gemini_truncated', 'Gemini did not complete a grounded answer.', array( 'status' => 502 ) );
		}
		$text = '';
		foreach ( (array) ( $first['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) { $text .= ' ' . $part['text']; }
		}
		if ( '' === trim( $text ) ) { return new WP_Error( 'scc_ai_gemini_empty', 'Gemini returned no answer text.', array( 'status' => 502 ) ); }
		$grounding = $first['groundingMetadata'] ?? array();
		$sources = array();
		foreach ( (array) ( $grounding['groundingChunks'] ?? array() ) as $idx => $chunk ) {
			if ( ! is_array( $chunk ) || ! isset( $chunk['web'] ) || ! is_array( $chunk['web'] ) ) { continue; }
			$link = self::safe_source( $chunk['web']['uri'] ?? '', $chunk['web']['title'] ?? '' );
			if ( $link ) { $sources[(int) $idx] = $link; }
		}
		$citations = array(); $verified = false;
		foreach ( (array) ( $grounding['groundingSupports'] ?? array() ) as $support ) {
			if ( ! is_array( $support ) || ! isset( $support['groundingChunkIndices'] ) || ! is_array( $support['groundingChunkIndices'] ) ) { continue; }
			foreach ( $support['groundingChunkIndices'] as $index ) {
				if ( is_int( $index ) && isset( $sources[$index] ) ) {
					$citations[$sources[$index]['url']] = $sources[$index]; $verified = true;
				}
			}
		}
		return self::base( 'gemini_google_search_grounded', $query, $domain, $brand,
			array_values( $citations ), array_values( $sources ), $text, (string) ( $body['modelVersion'] ?? 'gemini-2.5-flash' ),
			'gemini_developer_api', $verified ? 'verified_grounding_supports' : 'retrieved_only_or_unverified', $competitors );
	}

	/** One actual provider call. No network action to user-supplied URLs. */
	public static function run( $surface, $query, $domain, $brand ) {
		$provider = 'perplexity_agent_api' === $surface ? 'perplexity' : ( 'gemini_grounded_api' === $surface ? 'gemini' : '' );
		if ( ! $provider || ! self::connected( $provider ) ) { return new WP_Error( 'scc_ai_direct_unavailable', 'Connect the selected provider API key before running.', array( 'status' => 400 ) ); }
		$creds = self::creds();
		if ( 'perplexity' === $provider ) {
			$url = self::PERPLEXITY;
			$body = array( 'preset' => 'fast', 'input' => $query );
			$headers = array( 'authorization' => 'Bearer ' . $creds[self::KEY_PERPLEXITY], 'content-type' => 'application/json' );
		} else {
			$url = self::GEMINI;
			$body = array(
				'contents' => array( array( 'parts' => array( array( 'text' => $query ) ) ) ),
				'tools' => array( array( 'google_search' => new stdClass() ) ),
				'generationConfig' => array( 'temperature' => 0.2, 'maxOutputTokens' => 2048 ),
			);
			$headers = array( 'x-goog-api-key' => $creds[self::KEY_GEMINI], 'content-type' => 'application/json' );
		}
		$response = wp_remote_post( $url, array(
			'timeout' => 90, 'redirection' => 0,
			'headers' => $headers, 'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'scc_ai_direct_network', 'AI provider request failed. Check server connectivity and provider balance.', array( 'status' => 502 ) ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'scc_ai_direct_http', $code === 429 ? 'Provider rate/quota limit was reached.' : 'AI provider rejected the request. Check the API key, model access or billing.', array( 'status' => $code >= 400 && $code < 600 ? $code : 502 ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$competitors = self::competitors();
		return 'perplexity' === $provider ?
			self::normalize_perplexity( $json, $query, $domain, $brand, $competitors ) :
			self::normalize_gemini( $json, $query, $domain, $brand, $competitors );
	}
}
