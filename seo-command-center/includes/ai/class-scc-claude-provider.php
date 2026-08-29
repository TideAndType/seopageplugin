<?php
/**
 * Anthropic Claude provider (Messages API).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude provider.
 */
class SCC_Claude_Provider implements SCC_AI_Provider_Interface {

	const API_URL         = 'https://api.anthropic.com/v1/messages';
	const API_VERSION     = '2023-06-01';
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Approx USD per 1M tokens (input, output) by model.
	 * Used only for cost *estimates*; clearly labelled as estimates in the UI.
	 *
	 * @var array<string,array{0:float,1:float}>
	 */
	protected $pricing = array(
		'claude-opus-5'    => array( 15.0, 75.0 ),
		'claude-sonnet-5'  => array( 3.0, 15.0 ),
		'claude-haiku-4-5' => array( 0.80, 4.0 ),
	);

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'claude';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Anthropic Claude', 'seo-command-center' );
	}

	/**
	 * Retrieve the stored API key.
	 *
	 * @return string
	 */
	protected function get_key() {
		$creds = get_option( 'scc_credentials', array() );
		return isset( $creds['claude_key'] ) ? (string) $creds['claude_key'] : '';
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
			'claude-opus-5'    => 'Claude Opus 5',
			'claude-sonnet-5'  => 'Claude Sonnet 5',
			'claude-haiku-4-5' => 'Claude Haiku 4.5',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function estimate_cost( $input_tokens, $output_tokens, $model ) {
		$rates = isset( $this->pricing[ $model ] ) ? $this->pricing[ $model ] : array( 3.0, 15.0 );
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
			$response->error = new WP_Error( 'scc_no_key', __( 'Claude API key is not configured.', 'seo-command-center' ) );
			return $response;
		}

		$model = ! empty( $request['model'] ) ? $request['model'] : 'claude-sonnet-5';
		$response->model = $model;

		$system = isset( $request['system'] ) ? (string) $request['system'] : '';
		if ( ! empty( $request['json'] ) ) {
			$system .= "\n\nRespond ONLY with valid minified JSON. Do not include any prose or Markdown code fences.";
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 1024,
			'messages'   => $this->normalize_messages( $request ),
		);
		if ( '' !== $system ) {
			$body['system'] = $system;
		}
		if ( isset( $request['temperature'] ) ) {
			$body['temperature'] = (float) $request['temperature'];
		}

		$http = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => self::DEFAULT_TIMEOUT,
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $http ) ) {
			SCC_Logger::error( 'claude', 'Transport error: ' . $http->get_error_message() );
			$response->error = new WP_Error( 'scc_transport', $http->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $http );
		$data = json_decode( wp_remote_retrieve_body( $http ), true );

		if ( 200 !== (int) $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			SCC_Logger::error( 'claude', 'API error: ' . $msg, array( 'status' => $code ) );
			$response->error = new WP_Error( 'scc_api_error', $msg, array( 'status' => $code ) );
			return $response;
		}

		// Extract text content.
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		$response->content       = $text;
		$response->input_tokens  = isset( $data['usage']['input_tokens'] ) ? (int) $data['usage']['input_tokens'] : 0;
		$response->output_tokens = isset( $data['usage']['output_tokens'] ) ? (int) $data['usage']['output_tokens'] : 0;
		$response->cost          = $this->estimate_cost( $response->input_tokens, $response->output_tokens, $model );

		return $response;
	}

	/**
	 * Normalize messages to Anthropic format (user/assistant only).
	 *
	 * @param array $request Request.
	 * @return array
	 */
	protected function normalize_messages( array $request ) {
		$out = array();
		$messages = isset( $request['messages'] ) && is_array( $request['messages'] ) ? $request['messages'] : array();
		foreach ( $messages as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$out[] = array(
				'role'    => $role,
				'content' => (string) ( $m['content'] ?? '' ),
			);
		}
		if ( empty( $out ) ) {
			$out[] = array(
				'role'    => 'user',
				'content' => (string) ( $request['prompt'] ?? 'Hello' ),
			);
		}
		return $out;
	}
}
