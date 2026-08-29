<?php
/**
 * DataForSEO integration (optional).
 *
 * Retrieves real keyword metrics (search volume, competition, CPC) and related
 * keywords via the DataForSEO API using HTTP Basic auth. Entirely optional: the
 * plugin works without it and never fabricates volume or difficulty numbers.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DataForSEO client.
 */
class SCC_DataForSEO {

	const BASE = 'https://api.dataforseo.com/v3';

	/**
	 * Read credentials.
	 *
	 * @return array
	 */
	protected static function creds() {
		$c = get_option( 'scc_credentials', array() );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Whether DataForSEO is configured.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$c = self::creds();
		return ! empty( $c['dataforseo_login'] ) && ! empty( $c['dataforseo_key'] );
	}

	/**
	 * Authorization header value.
	 *
	 * @return string
	 */
	protected static function auth_header() {
		$c = self::creds();
		return 'Basic ' . base64_encode( $c['dataforseo_login'] . ':' . $c['dataforseo_key'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * POST a task-live request.
	 *
	 * @param string $path Endpoint path (after /v3).
	 * @param array  $task Single task payload.
	 * @return array|WP_Error Result array.
	 */
	protected static function post( $path, array $task ) {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'scc_dfs_not_connected', __( 'DataForSEO is not connected.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			self::BASE . $path,
			array(
				'timeout' => 45,
				'headers' => array(
					'authorization' => self::auth_header(),
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( $task ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			SCC_Logger::error( 'dataforseo', 'Transport error: ' . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'scc_dfs_http', sprintf( 'HTTP %d', $code ), array( 'status' => $code ) );
		}
		if ( isset( $body['status_code'] ) && 20000 !== (int) $body['status_code'] ) {
			$msg = isset( $body['status_message'] ) ? $body['status_message'] : __( 'DataForSEO error.', 'seo-command-center' );
			return new WP_Error( 'scc_dfs_api', $msg, array( 'status' => 502 ) );
		}

		return isset( $body['tasks'][0]['result'] ) ? (array) $body['tasks'][0]['result'] : array();
	}

	/**
	 * Search volume + competition for a set of keywords.
	 *
	 * @param array  $keywords    Keywords.
	 * @param string $location    Location name (default United States).
	 * @param string $language    Language code (default en).
	 * @return array|WP_Error List of {keyword, volume, competition, cpc}.
	 */
	public static function search_volume( array $keywords, $location = 'United States', $language = 'en' ) {
		$keywords = array_values( array_filter( array_map( 'sanitize_text_field', $keywords ) ) );
		if ( empty( $keywords ) ) {
			return array();
		}

		$result = self::post(
			'/keywords_data/google_ads/search_volume/live',
			array(
				'keywords'      => array_slice( $keywords, 0, 700 ),
				'location_name' => SCC_Security::sanitize_text( $location ),
				'language_code' => sanitize_key( $language ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array();
		foreach ( $result as $item ) {
			$out[] = array(
				'keyword'     => SCC_Security::sanitize_text( $item['keyword'] ?? '' ),
				'volume'      => isset( $item['search_volume'] ) ? (int) $item['search_volume'] : null,
				'competition' => isset( $item['competition'] ) ? SCC_Security::sanitize_text( (string) $item['competition'] ) : null,
				'cpc'         => isset( $item['cpc'] ) ? round( (float) $item['cpc'], 2 ) : null,
			);
		}
		return $out;
	}

	/**
	 * Related keywords for a seed term.
	 *
	 * @param string $keyword  Seed keyword.
	 * @param string $location Location name.
	 * @param string $language Language code.
	 * @return array|WP_Error
	 */
	public static function related_keywords( $keyword, $location = 'United States', $language = 'en' ) {
		$result = self::post(
			'/dataforseo_labs/google/related_keywords/live',
			array(
				'keyword'       => SCC_Security::sanitize_text( $keyword ),
				'location_name' => SCC_Security::sanitize_text( $location ),
				'language_code' => sanitize_key( $language ),
				'limit'         => 50,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out = array();
		foreach ( $result as $block ) {
			foreach ( (array) ( $block['items'] ?? array() ) as $item ) {
				$kw = $item['keyword_data']['keyword'] ?? ( $item['keyword'] ?? '' );
				if ( $kw ) {
					$out[] = SCC_Security::sanitize_text( $kw );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}
}
