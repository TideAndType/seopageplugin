<?php
/**
 * Google Search Console integration (optional).
 *
 * Uses a one-click TideOrbit Google authorization broker by default, while
 * retaining a self-hosted OAuth client flow for advanced users. The broker keeps
 * TideOrbit's Google client secret off customer WordPress sites and returns an
 * opaque, sealed connection token rather than a reusable Google refresh token.
 *
 * Search Console access is read-only. When not connected this class reports so
 * honestly and returns no data — it never fabricates impressions, clicks, CTR,
 * positions, or properties.
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

	const TOKEN_URL           = 'https://oauth2.googleapis.com/token';
	const AUTH_URL            = 'https://accounts.google.com/o/oauth2/v2/auth';
	const API_BASE            = 'https://searchconsole.googleapis.com/webmasters/v3';
	const TOKEN_CACHE         = 'scc_gsc_access_token';
	const SCOPE               = 'https://www.googleapis.com/auth/webmasters.readonly';
	const STATE_KEY           = 'scc_gsc_oauth_state';
	const BROKER_STATE_PREFIX = 'scc_gsc_broker_';
	const DEFAULT_BROKER_BASE = 'https://auth.tideandtype.com';

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
	 * Central TideOrbit authorization broker URL.
	 *
	 * Filterable for staging/self-hosting without exposing it as a normal setting.
	 *
	 * @return string
	 */
	public static function broker_base() {
		$base = (string) apply_filters( 'scc_gsc_broker_url', self::DEFAULT_BROKER_BASE );
		return untrailingslashit( esc_url_raw( trim( $base ) ) );
	}

	/**
	 * Whether a broker URL is configured.
	 *
	 * @return bool
	 */
	public static function broker_available() {
		return '' !== self::broker_base();
	}

	/**
	 * Current connection mode: broker, manual, or none.
	 *
	 * @return string
	 */
	public static function connection_mode() {
		$c = self::creds();
		if ( ! empty( $c['gsc_broker_connection'] ) ) {
			return 'broker';
		}
		if ( ! empty( $c['gsc_client_id'] ) && ! empty( $c['gsc_client_secret'] ) && ! empty( $c['gsc_refresh_token'] ) ) {
			return 'manual';
		}
		return 'none';
	}

	/**
	 * Build the recommended one-click broker authorization URL.
	 *
	 * The verifier remains only in WordPress. The broker receives a SHA-256
	 * challenge and will only release the sealed connection after the callback
	 * presents the original verifier.
	 *
	 * @return string|WP_Error
	 */
	public static function broker_auth_url() {
		$base = self::broker_base();
		if ( '' === $base ) {
			return new WP_Error( 'scc_gsc_broker_missing', __( 'The TideOrbit Google connection service is not configured.', 'seo-command-center' ) );
		}

		$state    = bin2hex( random_bytes( 24 ) );
		$verifier = bin2hex( random_bytes( 48 ) );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$key       = self::BROKER_STATE_PREFIX . hash( 'sha256', $state );

		set_transient(
			$key,
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'user_id'  => get_current_user_id(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$args = array(
			'callback'  => self::redirect_uri(),
			'state'     => $state,
			'challenge' => $challenge,
			'site'      => home_url( '/' ),
			'version'   => defined( 'SCC_VERSION' ) ? SCC_VERSION : '',
		);
		return $base . '/api/gsc/connect?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Build the legacy/self-hosted Google consent URL.
	 *
	 * @return string|WP_Error
	 */
	public static function manual_auth_url() {
		$c = self::creds();
		if ( empty( $c['gsc_client_id'] ) || empty( $c['gsc_client_secret'] ) ) {
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
			'prompt'                 => 'consent',
			'state'                  => $state,
		);
		return self::AUTH_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Default connect URL — broker for one-click setup, manual when requested.
	 *
	 * @param string $mode Connection mode.
	 * @return string|WP_Error
	 */
	public static function auth_url( $mode = 'broker' ) {
		return 'manual' === $mode ? self::manual_auth_url() : self::broker_auth_url();
	}

	/**
	 * Handle the OAuth callback: exchange the code for tokens and store the
	 * refresh token. Runs on the Connections admin page.
	 *
	 * @return array {ok:bool, message:string}
	 */
	public static function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth uses its own state token, validated below.
		if ( isset( $_GET['scc_gsc_broker'] ) || isset( $_GET['ticket'] ) ) {
			return self::handle_broker_callback();
		}
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
	 * Complete the broker callback and store only the opaque connection token.
	 *
	 * @return array {ok:bool,message:string}
	 */
	protected static function handle_broker_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'message' => __( 'Insufficient permissions.', 'seo-command-center' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- broker state is the CSRF protection.
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$ticket = isset( $_GET['ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['ticket'] ) ) : '';
		$error  = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$key      = self::BROKER_STATE_PREFIX . hash( 'sha256', $state );
		$expected = get_transient( $key );
		if ( '' === $state || ! is_array( $expected ) || empty( $expected['state'] ) || ! hash_equals( (string) $expected['state'], $state ) ) {
			return array( 'ok' => false, 'message' => __( 'Google connection security check failed. Please try connecting again.', 'seo-command-center' ) );
		}
		if ( (int) ( $expected['user_id'] ?? 0 ) !== get_current_user_id() ) {
			delete_transient( $key );
			return array( 'ok' => false, 'message' => __( 'This Google connection was started by a different WordPress user.', 'seo-command-center' ) );
		}
		if ( '' !== $error ) {
			delete_transient( $key );
			return array( 'ok' => false, 'message' => sprintf( __( 'Google connection failed: %s', 'seo-command-center' ), $error ) );
		}
		if ( '' === $ticket ) {
			return array( 'ok' => false, 'message' => __( 'The Google connection did not return a completion ticket.', 'seo-command-center' ) );
		}

		$url = self::broker_base() . '/api/gsc/exchange';
		if ( class_exists( 'SCC_URL' ) ) {
			$safe = SCC_URL::is_safe_outbound_url( $url );
			if ( is_wp_error( $safe ) ) {
				return array( 'ok' => false, 'message' => $safe->get_error_message() );
			}
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 25,
				'sslverify' => true,
				'headers'   => array( 'content-type' => 'application/json' ),
				'body'      => wp_json_encode(
					array(
						'ticket'   => $ticket,
						'verifier' => (string) ( $expected['verifier'] ?? '' ),
						'site'     => home_url( '/' ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['connection_token'] ) ) {
			$message = isset( $body['error'] ) ? sanitize_text_field( (string) $body['error'] ) : sprintf( 'HTTP %d', $code );
			return array( 'ok' => false, 'message' => sprintf( __( 'TideOrbit could not finish the Google connection: %s', 'seo-command-center' ), $message ) );
		}

		delete_transient( $key );
		$creds = self::creds();
		$creds['gsc_broker_connection'] = self::sanitize_token( $body['connection_token'] );
		update_option( 'scc_credentials', $creds, false );
		delete_transient( self::TOKEN_CACHE );

		self::auto_select_property();
		SCC_Logger::info( 'gsc', 'Connected through TideOrbit Google authorization broker.' );
		return array( 'ok' => true, 'message' => __( 'Google Search Console connected.', 'seo-command-center' ) );
	}

	/**
	 * Pick the best accessible Search Console property for this WordPress site.
	 *
	 * @return void
	 */
	protected static function auto_select_property() {
		$sites = self::sites();
		if ( is_wp_error( $sites ) || empty( $sites ) ) {
			return;
		}
		if ( 1 === count( $sites ) ) {
			SCC_Settings::update( array( 'gsc_site_url' => $sites[0]['siteUrl'] ) );
			return;
		}
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$home = untrailingslashit( home_url( '/' ) );
		foreach ( $sites as $site ) {
			$value = (string) ( $site['siteUrl'] ?? '' );
			if ( 'sc-domain:' . $host === strtolower( $value ) || $home === untrailingslashit( $value ) ) {
				SCC_Settings::update( array( 'gsc_site_url' => $value ) );
				return;
			}
		}
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
		return 'none' !== self::connection_mode();
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
		$mode   = self::connection_mode();
		$result = array(
			'fields'              => $fields,
			'has_all_fields'      => 'broker' === $mode || ( $fields['client_id'] && $fields['client_secret'] && $fields['refresh_token'] ),
			'connected'           => 'none' !== $mode,
			'mode'                => $mode,
			'token_ok'            => false,
			'properties'          => array(),
			'configured_property' => self::property(),
			'property_matches'    => false,
			'error'               => '',
		);

		if ( 'none' === $mode ) {
			$result['error'] = __( 'Google Search Console is not connected.', 'seo-command-center' );
			return $result;
		}

		delete_transient( self::TOKEN_CACHE );
		$sites = self::sites();
		if ( is_wp_error( $sites ) ) {
			$result['error'] = $sites->get_error_message();
			return $result;
		}

		$result['token_ok']    = true;
		$result['properties']  = $sites;
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
		if ( ! empty( $c['gsc_broker_connection'] ) ) {
			return self::broker_access_token( (string) $c['gsc_broker_connection'] );
		}

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
	 * Ask the TideOrbit broker for a short-lived Google access token.
	 *
	 * @param string $connection Opaque broker connection token.
	 * @return string|WP_Error
	 */
	protected static function broker_access_token( $connection ) {
		$url = self::broker_base() . '/api/gsc/token';
		if ( class_exists( 'SCC_URL' ) ) {
			$safe = SCC_URL::is_safe_outbound_url( $url );
			if ( is_wp_error( $safe ) ) {
				return $safe;
			}
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 25,
				'sslverify' => true,
				'headers'   => array( 'content-type' => 'application/json' ),
				'body'      => wp_json_encode( array( 'connection_token' => $connection ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			SCC_Logger::error( 'gsc', 'Broker token transport error: ' . $response->get_error_message() );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error'] ) ? sanitize_text_field( (string) $body['error'] ) : sprintf( 'HTTP %d', $code );
			return new WP_Error( 'scc_gsc_broker_token', $msg, array( 'status' => 502 ) );
		}
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
		set_transient( self::TOKEN_CACHE, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	/**
	 * Disconnect Search Console. Broker connections are revoked best-effort.
	 *
	 * @return array {ok:bool,message:string}
	 */
	public static function disconnect() {
		$c = self::creds();
		if ( ! empty( $c['gsc_broker_connection'] ) ) {
			$url = self::broker_base() . '/api/gsc/revoke';
			if ( '' !== self::broker_base() ) {
				wp_remote_post(
					$url,
					array(
						'timeout'   => 15,
						'sslverify' => true,
						'headers'   => array( 'content-type' => 'application/json' ),
						'body'      => wp_json_encode( array( 'connection_token' => (string) $c['gsc_broker_connection'] ) ),
					)
				);
			}
			unset( $c['gsc_broker_connection'] );
		} else {
			unset( $c['gsc_refresh_token'] );
		}
		update_option( 'scc_credentials', $c, false );
		delete_transient( self::TOKEN_CACHE );
		return array( 'ok' => true, 'message' => __( 'Google Search Console disconnected.', 'seo-command-center' ) );
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
	 * Query Search Analytics for an explicit date range (used by period
	 * comparisons such as content-decay detection).
	 *
	 * @param string $site_url   GSC property.
	 * @param array  $dimensions Dimensions.
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param int    $row_limit  Max rows.
	 * @return array|WP_Error
	 */
	public static function query_range( $site_url, array $dimensions, $start_date, $end_date, $row_limit = 5000 ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$site_url = $site_url ? $site_url : self::property();
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
						'startDate'  => $start_date,
						'endDate'    => $end_date,
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
	 * Compare page-level performance across two consecutive windows of $days each
	 * (recent window vs the window immediately before it). Returns a per-page map
	 * keyed by page URL with current + previous clicks/impressions/position.
	 *
	 * @param string $site_url GSC property.
	 * @param int    $days     Window length in days (each period).
	 * @return array|WP_Error
	 */
	public static function compare_pages( $site_url = '', $days = 90 ) {
		$days      = SCC_Security::sanitize_int( $days, 14, 240 );
		$now       = time();
		$recent_end   = gmdate( 'Y-m-d', $now );
		$recent_start = gmdate( 'Y-m-d', $now - $days * DAY_IN_SECONDS );
		$prev_end     = gmdate( 'Y-m-d', $now - ( $days + 1 ) * DAY_IN_SECONDS );
		$prev_start   = gmdate( 'Y-m-d', $now - ( 2 * $days + 1 ) * DAY_IN_SECONDS );

		$recent = self::query_range( $site_url, array( 'page' ), $recent_start, $recent_end, 5000 );
		if ( is_wp_error( $recent ) ) {
			return $recent;
		}
		$prev = self::query_range( $site_url, array( 'page' ), $prev_start, $prev_end, 5000 );
		if ( is_wp_error( $prev ) ) {
			return $prev;
		}

		$map = array();
		$fold = function ( $rows, $which ) use ( &$map ) {
			foreach ( (array) $rows as $row ) {
				$url = (string) ( $row['keys'][0] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				if ( ! isset( $map[ $url ] ) ) {
					$map[ $url ] = array(
						'url'          => $url,
						'curr_clicks'  => 0, 'prev_clicks' => 0,
						'curr_impr'    => 0, 'prev_impr'   => 0,
						'curr_pos'     => 0.0, 'prev_pos'   => 0.0,
					);
				}
				$map[ $url ][ $which . '_clicks' ] = (int) ( $row['clicks'] ?? 0 );
				$map[ $url ][ $which . '_impr' ]   = (int) ( $row['impressions'] ?? 0 );
				$map[ $url ][ $which . '_pos' ]    = round( (float) ( $row['position'] ?? 0 ), 1 );
			}
		};
		$fold( $recent, 'curr' );
		$fold( $prev, 'prev' );

		return array_values( $map );
	}

	/**
	 * A cached URL → {clicks, impressions, ctr, position} map for the last N days,
	 * so per-page callers (e.g. the Page Optimizer) share one API call.
	 *
	 * @param int $days Lookback window.
	 * @return array<string,array>
	 */
	public static function page_metrics_map( $days = 90 ) {
		$key    = 'scc_gsc_page_metrics_' . (int) $days;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! self::is_connected() ) {
			return array();
		}
		$rows = self::query( '', array( 'page' ), $days, 5000 );
		$map  = array();
		if ( ! is_wp_error( $rows ) ) {
			foreach ( (array) $rows as $row ) {
				$url = (string) ( $row['keys'][0] ?? '' );
				if ( '' === $url ) {
					continue;
				}
				$map[ untrailingslashit( $url ) ] = array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
					'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				);
			}
		}
		set_transient( $key, $map, 6 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Metrics for one page URL (from the cached map), or null if none.
	 *
	 * @param string $url Page URL.
	 * @param int    $days Lookback.
	 * @return array|null
	 */
	public static function page_metrics( $url, $days = 90 ) {
		$map = self::page_metrics_map( $days );
		$key = untrailingslashit( (string) $url );
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
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
