<?php
/**
 * LM Studio provider (local, OpenAI-compatible server).
 *
 * LM Studio exposes an OpenAI-compatible API (default
 * http://localhost:1234/v1). Runs on the user's own machine, so there is no
 * per-token cost and the API key is optional. The base URL is configurable so
 * WordPress can reach LM Studio on localhost, a LAN IP, or a tunnel.
 *
 * @see https://lmstudio.ai/docs/app/api/endpoints/openai
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LM Studio provider.
 */
class SCC_LMStudio_Provider implements SCC_AI_Provider_Interface {

	const DEFAULT_BASE    = 'http://localhost:1234/v1';
	const DEFAULT_TIMEOUT = 300; // Local models can be slow; allow long generations.

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'lmstudio';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'LM Studio (local)', 'seo-command-center' );
	}

	/**
	 * Configured base URL (trailing slash trimmed), from settings.
	 *
	 * @return string
	 */
	protected function base_url() {
		$base = (string) SCC_Settings::get( 'lmstudio_base_url', self::DEFAULT_BASE );
		$base = trim( $base );
		return '' !== $base ? untrailingslashit( $base ) : self::DEFAULT_BASE;
	}

	/**
	 * Optional API key (LM Studio ignores it by default).
	 *
	 * @return string
	 */
	protected function get_key() {
		$creds = get_option( 'scc_credentials', array() );
		return isset( $creds['lmstudio_key'] ) ? (string) $creds['lmstudio_key'] : '';
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured() {
		// A base URL is always present (default localhost); LM Studio needs no key.
		return '' !== $this->base_url();
	}

	/**
	 * @inheritDoc
	 */
	public function list_models() {
		// The actual model id is whatever is loaded in LM Studio; the user sets it
		// in Settings. 'local-model' is accepted by LM Studio as "use the loaded model".
		$configured = (string) SCC_Settings::get( 'lmstudio_model', 'local-model' );
		return array( $configured => $configured );
	}

	/**
	 * Query LM Studio's /v1/models endpoint to list the loaded model ids.
	 * Doubles as a reachability check for the configured (or given) server URL.
	 *
	 * @param string $base_override Optional base URL to test without saving.
	 * @return array {ok:bool, models:string[], base:string, error:string}
	 */
	public function discover_models( $base_override = '' ) {
		$base = '' !== trim( (string) $base_override ) ? untrailingslashit( trim( (string) $base_override ) ) : $this->base_url();

		$safe = SCC_URL::is_safe_outbound_url( $base . '/models' );
		if ( is_wp_error( $safe ) ) {
			return array( 'ok' => false, 'models' => array(), 'base' => $base, 'error' => $safe->get_error_message() );
		}

		$headers = array();
		$key     = $this->get_key();
		if ( '' !== $key ) {
			$headers['authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_get(
			$base . '/models',
			array(
				'timeout'   => 20,
				'headers'   => $headers,
				'sslverify' => ( 0 === strpos( $base, 'https://' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'models' => array(),
				'base'   => $base,
				'error'  => sprintf(
					/* translators: %s: error */
					__( 'Could not reach LM Studio at %1$s (%2$s). Make sure the server is running and the URL is reachable from your WordPress host (a hosted site cannot reach localhost — use a tunnel).', 'seo-command-center' ),
					$base,
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'ok' => false, 'models' => array(), 'base' => $base, 'error' => sprintf( 'HTTP %d from %s', $code, $base . '/models' ) );
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $m ) {
			if ( ! empty( $m['id'] ) ) {
				$models[] = SCC_Security::sanitize_text( $m['id'] );
			}
		}
		return array( 'ok' => true, 'models' => $models, 'base' => $base, 'error' => '' );
	}

	/**
	 * @inheritDoc
	 */
	public function estimate_cost( $input_tokens, $output_tokens, $model ) {
		return 0.0; // Local inference — no API cost.
	}

	/**
	 * @inheritDoc
	 */
	public function complete( array $request ) {
		$response = new SCC_AI_Response();
		$response->provider = $this->get_id();

		$model = ! empty( $request['model'] ) ? $request['model'] : (string) SCC_Settings::get( 'lmstudio_model', 'local-model' );
		$response->model = $model;

		// Build OpenAI-compatible chat messages.
		$messages = array();
		$system   = isset( $request['system'] ) ? (string) $request['system'] : '';
		if ( ! empty( $request['json'] ) ) {
			$system .= "\n\nRespond ONLY with valid JSON.";
		}
		if ( '' !== $system ) {
			$messages[] = array( 'role' => 'system', 'content' => $system );
		}
		$req_messages = isset( $request['messages'] ) && is_array( $request['messages'] ) ? $request['messages'] : array();
		foreach ( $req_messages as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$messages[] = array( 'role' => $role, 'content' => (string) ( $m['content'] ?? '' ) );
		}
		if ( empty( $req_messages ) ) {
			$messages[] = array( 'role' => 'user', 'content' => (string) ( $request['prompt'] ?? 'Hello' ) );
		}

		$body = array(
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 1024,
			'stream'     => false,
		);
		if ( isset( $request['temperature'] ) ) {
			$body['temperature'] = (float) $request['temperature'];
		}
		// NOTE: We deliberately do NOT send response_format. LM Studio builds vary
		// wildly here — some accept {"type":"json_object"}, newer ones reject it
		// with HTTP 400 ("must be 'json_schema' or 'text'"), and json_schema needs
		// a full schema per request. JSON is requested via the system prompt
		// ("Respond ONLY with valid JSON") and recovered by the tolerant parser,
		// which is reliable across every LM Studio version without a failed
		// round-trip. See $request['json'] handling above (adds the instruction).

		$headers = array( 'content-type' => 'application/json' );
		$key     = $this->get_key();
		if ( '' !== $key ) {
			$headers['authorization'] = 'Bearer ' . $key;
		}

		$url = $this->base_url() . '/chat/completions';

		// SSRF guard, checked immediately before the outbound request (not only at
		// save time). Loopback is allowed so local LM Studio keeps working.
		$safe = SCC_URL::is_safe_outbound_url( $url );
		if ( is_wp_error( $safe ) ) {
			SCC_Logger::error( 'lmstudio', 'Blocked outbound URL: ' . $safe->get_error_message() );
			$response->error = $safe;
			return $response;
		}

		$http = $this->post_chat( $url, $headers, $body );

		if ( is_wp_error( $http ) ) {
			SCC_Logger::error( 'lmstudio', 'Transport error: ' . $http->get_error_message() );
			$response->error = new WP_Error(
				'scc_transport',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach LM Studio (%s). Make sure the local server is running and its address is reachable from your WordPress server.', 'seo-command-center' ),
					$http->get_error_message()
				)
			);
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $http );
		$data = json_decode( wp_remote_retrieve_body( $http ), true );

		// Many local models/engines reject JSON mode (response_format) or other
		// optional params with a 400. Retry once without the optional params —
		// the system prompt already instructs "Respond ONLY with valid JSON".
		if ( 400 === (int) $code && ( isset( $body['response_format'] ) || isset( $body['temperature'] ) ) ) {
			SCC_Logger::info( 'lmstudio', 'Retrying without response_format/temperature after HTTP 400' );
			unset( $body['response_format'], $body['temperature'] );
			$retry = $this->post_chat( $url, $headers, $body );
			if ( ! is_wp_error( $retry ) ) {
				$http = $retry;
				$code = wp_remote_retrieve_response_code( $http );
				$data = json_decode( wp_remote_retrieve_body( $http ), true );
			}
		}

		if ( 200 !== (int) $code ) {
			$msg = $this->extract_error( $data, wp_remote_retrieve_body( $http ), (int) $code );
			SCC_Logger::error( 'lmstudio', 'API error: ' . $msg, array( 'status' => $code ) );
			$response->error = new WP_Error( 'scc_api_error', $msg, array( 'status' => $code ) );
			return $response;
		}

		$response->content       = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		$response->input_tokens  = isset( $data['usage']['prompt_tokens'] ) ? (int) $data['usage']['prompt_tokens'] : 0;
		$response->output_tokens = isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0;
		$response->cost          = 0.0;

		return $response;
	}

	/**
	 * POST a chat-completions body to the server.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body (will be JSON-encoded).
	 * @return array|WP_Error wp_remote_post result.
	 */
	protected function post_chat( $url, array $headers, array $body ) {
		return wp_remote_post(
			$url,
			array(
				'timeout'   => self::DEFAULT_TIMEOUT,
				'headers'   => $headers,
				'body'      => wp_json_encode( $body ),
				// Local endpoints are typically plain HTTP; only verify for HTTPS.
				'sslverify' => ( 0 === strpos( $url, 'https://' ) ),
			)
		);
	}

	/**
	 * Pull a human-readable error out of an LM Studio / OpenAI-style error body.
	 * LM Studio returns the error as either {error:{message}} or {error:"..."}
	 * (a plain string), so handle both plus a raw-body fallback.
	 *
	 * @param mixed  $data Decoded JSON body (array) or null.
	 * @param string $raw  Raw response body.
	 * @param int    $code HTTP status code.
	 * @return string
	 */
	protected function extract_error( $data, $raw, $code ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) && '' !== (string) $data['error']['message'] ) {
				return sprintf( 'HTTP %d — %s', $code, (string) $data['error']['message'] );
			}
			if ( isset( $data['error'] ) && is_string( $data['error'] ) && '' !== $data['error'] ) {
				return sprintf( 'HTTP %d — %s', $code, $data['error'] );
			}
			if ( isset( $data['message'] ) && is_string( $data['message'] ) && '' !== $data['message'] ) {
				return sprintf( 'HTTP %d — %s', $code, $data['message'] );
			}
		}
		$raw = trim( (string) $raw );
		if ( '' !== $raw ) {
			return sprintf( 'HTTP %d — %s', $code, mb_substr( wp_strip_all_tags( $raw ), 0, 300 ) );
		}
		return sprintf( 'HTTP %d', $code );
	}
}
