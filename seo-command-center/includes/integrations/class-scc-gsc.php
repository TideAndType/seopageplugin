<?php
/**
 * Google Search Console integration (optional).
 *
 * Uses an OAuth2 installed-app credential (client id/secret + refresh token) to
 * call the Search Analytics API. When not connected it reports so honestly and
 * returns no data — it never fabricates impressions, clicks, or positions.
 *
 * The refresh-token exchange and Search Analytics query are fully implemented;
 * obtaining the initial refresh token is done by the site owner via Google's
 * OAuth playground / their own client (documented in the UI), pending a bundled
 * OAuth redirect flow in a later iteration.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GSC client.
 */
class SCC_GSC {

	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
	const API_BASE    = 'https://searchconsole.googleapis.com/webmasters/v3';
	const TOKEN_CACHE = 'scc_gsc_access_token';
	const SCOPE       = 'https://www.googleapis.com/auth/webmasters.readonly';
	const STATE_KEY   = 'scc_gsc_oauth_state';

	/**
	 * The OAuth redirect URI (must be added to the Google OAuth client).
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=seo-command-center-connections' );
	}

	/**
	 * Whether an OAuth client (id + secret) is configured — enough to start the
	 * connect flow (the refresh token is obtained by the flow itself).
	 *
	 * @return bool
	 */
	public static function has_client() {
		$c = self::creds();
		return ! empty( $c['gsc_client_id'] ) && ! empty( $c['gsc_client_secret'] );
	}

	/**
	 * Build the Google consent URL to start the connect flow.
	 *
	 * @return string|WP_Error
	 */
	public static function auth_url() {
		$c = self::creds();
		if ( empty( $c['gsc_client_id'] ) ) {
			return new WP_Error( 'scc_no_client', __( 'Enter your OAuth Client ID and secret first, then Save.', 'seo-command-center' ) );
		}
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_KEY, $state, 15 * MINUTE_IN_SECONDS );

		$args = array(
			'client_id'              => $c['gsc_client_id'],
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent', // Force a refresh_token every time.
			'state'                  => $state,
		);
		return self::AUTH_URL . '?' . http_build_query( $args );
	}

	/**
	 * Handle the OAuth callback: exchange the code for tokens and store the
	 * refresh token. Runs on the Connections admin page.
	 *
	 * @return array {ok:bool, message:string}
	 */
	public static function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth uses its own state token, validated below.
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'message' => __( 'Insufficient permissions.', 'seo-command-center' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			return array( 'ok' => false, 'message' => sprintf( /* translators: %s: error */ __( 'Google returned an error: %s', 'seo-command-center' ), sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
		}
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return array( 'ok' => false, 'message' => '' ); // Not a callback.
		}
		$expected = get_transient( self::STATE_KEY );
		if ( ! $expected || ! hash_equals( (string) $expected, $state ) ) {
			return array( 'ok' => false, 'message' => __( 'Security check failed (state mismatch). Please try connecting again.', 'seo-command-center' ) );
		}
		delete_transient( self::STATE_KEY );

		$c = self::creds();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 25,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $c['gsc_client_id'] ?? '',
					'client_secret' => $c['gsc_client_secret'] ?? '',
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Google did not return a refresh token. Remove the app under your Google Account → Security → Third-party access, then connect again.', 'seo-command-center' );
			return array( 'ok' => false, 'message' => $msg );
		}

		// Store the refresh token.
		$creds = get_option( 'scc_credentials', array() );
		$creds = is_array( $creds ) ? $creds : array();
		$creds['gsc_refresh_token'] = self::sanitize_token( $body['refresh_token'] );
		update_option( 'scc_credentials', $creds, false );
		delete_transient( self::TOKEN_CACHE );

		// Auto-select the property when the account has exactly one.
		$sites = self::sites();
		if ( ! is_wp_error( $sites ) && 1 === count( $sites ) ) {
			SCC_Settings::update( array( 'gsc_site_url' => $sites[0]['siteUrl'] ) );
		}

		SCC_Logger::info( 'gsc', 'OAuth connected; refresh token stored.' );
		return array( 'ok' => true, 'message' => __( 'Google Search Console connected.', 'seo-command-center' ) );
	}

	/**
	 * Sanitize an OAuth token (keep its full charset, strip only whitespace).
	 *
	 * @param string $token Token.
	 * @return string
	 */
	protected static function sanitize_token( $token ) {
		return trim( preg_replace( '/\s+/', '', (string) $token ) );
	}

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
	 * Whether GSC is connected (has an OAuth client + refresh token).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$c = self::creds();
		return ! empty( $c['gsc_client_id'] ) && ! empty( $c['gsc_client_secret'] ) && ! empty( $c['gsc_refresh_token'] );
	}

	/**
	 * Which of the three OAuth fields are present (for diagnostics).
	 *
	 * @return array {client_id:bool, client_secret:bool, refresh_token:bool}
	 */
	public static function field_status() {
		$c = self::creds();
		return array(
			'client_id'     => ! empty( $c['gsc_client_id'] ),
			'client_secret' => ! empty( $c['gsc_client_secret'] ),
			'refresh_token' => ! empty( $c['gsc_refresh_token'] ),
		);
	}

	/**
	 * The GSC property to query. Uses the configured property, else the site URL.
	 *
	 * @return string
	 */
	public static function property() {
		$configured = trim( (string) SCC_Settings::get( 'gsc_site_url', '' ) );
		return '' !== $configured ? $configured : home_url( '/' );
	}

	/**
	 * List the verified Search Console properties this token can access.
	 *
	 * @return array|WP_Error List of {siteUrl, permissionLevel}.
	 */
	public static function sites() {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_get(
			self::API_BASE . '/sites',
			array(
				'timeout' => 20,
				'headers' => array( 'authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'scc_gsc_api', $msg, array( 'status' => $code ) );
		}
		$out = array();
		foreach ( (array) ( $body['siteEntry'] ?? array() ) as $entry ) {
			$out[] = array(
				'siteUrl'         => (string) ( $entry['siteUrl'] ?? '' ),
				'permissionLevel' => (string) ( $entry['permissionLevel'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * End-to-end connection check: token exchange + accessible properties, and
	 * whether the configured property is among them. Never throws.
	 *
	 * @return array
	 */
	public static function verify() {
		$fields = self::field_status();
		$result = array(
			'fields'             => $fields,
			'has_all_fields'     => $fields['client_id'] && $fields['client_secret'] && $fields['refresh_token'],
			'token_ok'           => false,
			'properties'         => array(),
			'configured_property'=> self::property(),
			'property_matches'   => false,
			'error'              => '',
		);

		if ( ! $result['has_all_fields'] ) {
			$missing = array();
			foreach ( $fields as $key => $ok ) {
				if ( ! $ok ) {
					$missing[] = $key;
				}
			}
			$result['error'] = sprintf(
				/* translators: %s: comma-separated field names */
				__( 'Missing OAuth field(s): %s. All three (Client ID, Client secret, Refresh token) are required.', 'seo-command-center' ),
				implode( ', ', $missing )
			);
			return $result;
		}

		// Force a fresh token so we truly test the refresh exchange.
		delete_transient( self::TOKEN_CACHE );
		$sites = self::sites();
		if ( is_wp_error( $sites ) ) {
			$result['error'] = $sites->get_error_message();
			return $result;
		}

		$result['token_ok']   = true;
		$result['properties'] = $sites;
		foreach ( $sites as $s ) {
			if ( untrailingslashit( $s['siteUrl'] ) === untrailingslashit( $result['configured_property'] ) ) {
				$result['property_matches'] = true;
				break;
			}
		}
		return $result;
	}

	/**
	 * Get a valid access token (cached), refreshing when needed.
	 *
	 * @return string|WP_Error
	 */
	protected static function access_token() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'scc_gsc_not_connected', __( 'Google Search Console is not connected.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$cached = get_transient( self::TOKEN_CACHE );
		if ( $cached ) {
			return $cached;
		}

		$c = self::creds();
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $c['gsc_client_id'],
					'client_secret' => $c['gsc_client_secret'],
					'refresh_token' => $c['gsc_refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			SCC_Logger::error( 'gsc', 'Token refresh transport error: ' . $response->get_error_message() );
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Could not obtain a Google access token.', 'seo-command-center' );
			SCC_Logger::error( 'gsc', 'Token refresh failed: ' . $msg );
			return new WP_Error( 'scc_gsc_token', $msg, array( 'status' => 502 ) );
		}

		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
		set_transient( self::TOKEN_CACHE, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	/**
	 * Query Search Analytics.
	 *
	 * @param string $site_url    Verified GSC property (e.g. https://example.com/ or sc-domain:example.com).
	 * @param array  $dimensions  e.g. ['query'] or ['page','query'].
	 * @param int    $days        Lookback window.
	 * @param int    $row_limit   Max rows.
	 * @return array|WP_Error
	 */
	public static function query( $site_url, array $dimensions = array( 'query' ), $days = 90, $row_limit = 250 ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$site_url = $site_url ? $site_url : self::property();
		$end      = gmdate( 'Y-m-d' );
		$start    = gmdate( 'Y-m-d', time() - SCC_Security::sanitize_int( $days, 1, 480 ) * DAY_IN_SECONDS );

		$endpoint = self::API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'authorization' => 'Bearer ' . $token,
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'startDate'  => $start,
						'endDate'    => $end,
						'dimensions' => array_values( $dimensions ),
						'rowLimit'   => SCC_Security::sanitize_int( $row_limit, 1, 25000 ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) $code ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'scc_gsc_api', $msg, array( 'status' => $code ) );
		}

		return isset( $body['rows'] ) ? $body['rows'] : array();
	}

	/**
	 * Identify quick-win opportunities: queries with meaningful impressions
	 * that rank just outside the top results (positions 4-20).
	 *
	 * @param string $site_url        GSC property.
	 * @param int    $min_impressions Minimum impressions to consider.
	 * @return array|WP_Error
	 */
	public static function quick_wins( $site_url = '', $min_impressions = 50 ) {
		$rows = self::query( $site_url, array( 'query' ), 90, 500 );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$wins = array();
		foreach ( $rows as $row ) {
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );
			if ( $impressions < $min_impressions ) {
				continue;
			}
			if ( $position >= 4 && $position <= 20 ) {
				$wins[] = array(
					'query'       => SCC_Security::sanitize_text( $row['keys'][0] ?? '' ),
					'impressions' => $impressions,
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
					'position'    => round( $position, 1 ),
				);
			}
		}

		// Highest impressions first — biggest opportunity.
		usort(
			$wins,
			function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);

		return array_slice( $wins, 0, 100 );
	}
}
