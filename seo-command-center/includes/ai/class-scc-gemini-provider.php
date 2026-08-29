<?php
/**
 * Google Gemini provider (Generative Language API — generateContent).
 *
 * @see https://ai.google.dev/gemini-api/docs
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini provider.
 */
class SCC_Gemini_Provider implements SCC_AI_Provider_Interface {

	const API_BASE        = 'https://generativelanguage.googleapis.com/v1beta';
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Approx USD per 1M tokens (input, output) by model. Estimates only.
	 *
	 * @var array<string,array{0:float,1:float}>
	 */
	protected $pricing = array(
		'gemini-2.5-pro'   => array( 1.25, 10.0 ),
		'gemini-2.5-flash' => array( 0.30, 2.50 ),
		'gemini-2.0-flash' => array( 0.10, 0.40 ),
		'gemini-1.5-flash' => array( 0.075, 0.30 ),
		'gemini-1.5-pro'   => array( 1.25, 5.0 ),
	);

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'gemini';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Google Gemini', 'seo-command-center' );
	}

	/**
	 * Retrieve the stored API key.
	 *
	 * @return string
	 */
	protected function get_key() {
		$creds = get_option( 'scc_credentials', array() );
		return isset( $creds['gemini_key'] ) ? (string) $creds['gemini_key'] : '';
	}

	/**
	 * @inheritDoc
	 */
	public function is_configured() {
		return '' !== $this->get_key();
	}

	/**
	 * @inheritDoc
	 */
	public function list_models() {
		return array(
			'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
			'gemini-2.5-flash' => 'Gemini 2.5 Flash',
			'gemini-2.0-flash' => 'Gemini 2.0 Flash',
			'gemini-1.5-flash' => 'Gemini 1.5 Flash',
			'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function estimate_cost( $input_tokens, $output_tokens, $model ) {
		$rates = isset( $this->pricing[ $model ] ) ? $this->pricing[ $model ] : array( 0.30, 2.50 );
		return round( ( $input_tokens / 1000000 ) * $rates[0] + ( $output_tokens / 1000000 ) * $rates[1], 6 );
	}

	/**
	 * @inheritDoc
	 */
	public function complete( array $request ) {
		$response = new SCC_AI_Response();
		$response->provider = $this->get_id();

		$key = $this->get_key();
		if ( '' === $key ) {
			$response->error = new WP_Error( 'scc_no_key', __( 'Gemini API key is not configured.', 'seo-command-center' ) );
			return $response;
		}

		$model = ! empty( $request['model'] ) ? $request['model'] : 'gemini-2.5-flash';
		$response->model = $model;

		// Build request body.
		$body = array(
			'contents' => $this->normalize_messages( $request ),
		);

		$system = isset( $request['system'] ) ? (string) $request['system'] : '';
		if ( '' !== $system ) {
			$body['system_instruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}

		$gen = array();
		if ( isset( $request['max_tokens'] ) ) {
			$gen['maxOutputTokens'] = (int) $request['max_tokens'];
		}
		if ( isset( $request['temperature'] ) ) {
			$gen['temperature'] = (float) $request['temperature'];
		}
		if ( ! empty( $request['json'] ) ) {
			$gen['responseMimeType'] = 'application/json';
		}
		if ( ! empty( $gen ) ) {
			$body['generationConfig'] = $gen;
		}

		$url = self::API_BASE . '/models/' . rawurlencode( $model ) . ':generateContent';

		$http = wp_remote_post(
			$url,
			array(
				'timeout' => self::DEFAULT_TIMEOUT,
				'headers' => array(
					'content-type'     => 'application/json',
					// Key travels in a header, not the URL/query string, so it is
					// not captured in server access logs.
					'x-goog-api-key'   => $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $http ) ) {
			SCC_Logger::error( 'gemini', 'Transport error: ' . $http->get_error_message() );
			$response->error = new WP_Error( 'scc_transport', $http->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $http );
		$data = json_decode( wp_remote_retrieve_body( $http ), true );

		if ( 200 !== (int) $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			SCC_Logger::error( 'gemini', 'API error: ' . $msg, array( 'status' => $code ) );
			$response->error = new WP_Error( 'scc_api_error', $msg, array( 'status' => $code ) );
			return $response;
		}

		// Extract text from the first candidate's parts.
		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$response->content       = $text;
		$response->input_tokens  = isset( $data['usageMetadata']['promptTokenCount'] ) ? (int) $data['usageMetadata']['promptTokenCount'] : 0;
		$response->output_tokens = isset( $data['usageMetadata']['candidatesTokenCount'] ) ? (int) $data['usageMetadata']['candidatesTokenCount'] : 0;
		$response->cost          = $this->estimate_cost( $response->input_tokens, $response->output_tokens, $model );

		if ( '' === trim( $text ) ) {
			$response->error = new WP_Error( 'scc_empty', __( 'Gemini returned no content (it may have been blocked by a safety filter).', 'seo-command-center' ) );
		}

		return $response;
	}

	/**
	 * Normalize messages to Gemini "contents" (roles: user / model).
	 *
	 * @param array $request Request.
	 * @return array
	 */
	protected function normalize_messages( array $request ) {
		$out = array();
		$messages = isset( $request['messages'] ) && is_array( $request['messages'] ) ? $request['messages'] : array();
		foreach ( $messages as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'model' : 'user';
			$out[] = array(
				'role'  => $role,
				'parts' => array( array( 'text' => (string) ( $m['content'] ?? '' ) ) ),
			);
		}
		if ( empty( $out ) ) {
			$out[] = array(
				'role'  => 'user',
				'parts' => array( array( 'text' => (string) ( $request['prompt'] ?? 'Hello' ) ) ),
			);
		}
		return $out;
	}
}
