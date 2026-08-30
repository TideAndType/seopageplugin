<?php
/**
 * OpenAI provider (Chat Completions API).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI provider.
 */
class SCC_OpenAI_Provider implements SCC_AI_Provider_Interface {

	const API_URL         = 'https://api.openai.com/v1/chat/completions';
	const DEFAULT_TIMEOUT = 60;

	/**
	 * Approx USD per 1M tokens (input, output) by model. Estimates only.
	 *
	 * @var array<string,array{0:float,1:float}>
	 */
	protected $pricing = array(
		'gpt-4o'      => array( 2.5, 10.0 ),
		'gpt-4o-mini' => array( 0.15, 0.60 ),
		'gpt-4.1'     => array( 2.0, 8.0 ),
	);

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'openai';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'OpenAI', 'seo-command-center' );
	}

	/**
	 * Retrieve the stored API key.
	 *
	 * @return string
	 */
	protected function get_key() {
		$creds = get_option( 'scc_credentials', array() );
		return isset( $creds['openai_key'] ) ? (string) $creds['openai_key'] : '';
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
			'gpt-4o'      => 'GPT-4o',
			'gpt-4o-mini' => 'GPT-4o mini',
			'gpt-4.1'     => 'GPT-4.1',
		);
	}

	/**
	 * @inheritDoc
	 */
	public function estimate_cost( $input_tokens, $output_tokens, $model ) {
		$rates = isset( $this->pricing[ $model ] ) ? $this->pricing[ $model ] : array( 2.5, 10.0 );
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
			$response->error = new WP_Error( 'scc_no_key', __( 'OpenAI API key is not configured.', 'seo-command-center' ) );
			return $response;
		}

		$model = ! empty( $request['model'] ) ? $request['model'] : 'gpt-4o-mini';
		$response->model = $model;

		$messages = array();
		$system   = isset( $request['system'] ) ? (string) $request['system'] : '';
		if ( ! empty( $request['json'] ) ) {
			$system .= "\n\nRespond ONLY with valid JSON.";
		}
		if ( '' !== $system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}
		$req_messages = isset( $request['messages'] ) && is_array( $request['messages'] ) ? $request['messages'] : array();
		foreach ( $req_messages as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$messages[] = array(
				'role'    => $role,
				'content' => (string) ( $m['content'] ?? '' ),
			);
		}
		if ( empty( $req_messages ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => (string) ( $request['prompt'] ?? 'Hello' ),
			);
		}

		$body = array(
			'model'      => $model,
			'messages'   => $messages,
			'max_tokens' => isset( $request['max_tokens'] ) ? (int) $request['max_tokens'] : 1024,
		);
		if ( isset( $request['temperature'] ) ) {
			$body['temperature'] = (float) $request['temperature'];
		}
		if ( ! empty( $request['json'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$http = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => self::DEFAULT_TIMEOUT,
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $http ) ) {
			SCC_Logger::error( 'openai', 'Transport error: ' . $http->get_error_message() );
			$response->error = new WP_Error( 'scc_transport', $http->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $http );
		$data = json_decode( wp_remote_retrieve_body( $http ), true );

		if ( 200 !== (int) $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : sprintf( 'HTTP %d', $code );
			SCC_Logger::error( 'openai', 'API error: ' . $msg, array( 'status' => $code ) );
			$response->error = new WP_Error( 'scc_api_error', $msg, array( 'status' => $code ) );
			return $response;
		}

		$response->content       = isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
		$response->input_tokens  = isset( $data['usage']['prompt_tokens'] ) ? (int) $data['usage']['prompt_tokens'] : 0;
		$response->output_tokens = isset( $data['usage']['completion_tokens'] ) ? (int) $data['usage']['completion_tokens'] : 0;
		$response->cost          = $this->estimate_cost( $response->input_tokens, $response->output_tokens, $model );

		return $response;
	}
}
