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
		'gemini-flash-latest'      => array( 0.30, 2.50 ),
		'gemini-pro-latest'        => array( 1.25, 10.0 ),
		'gemini-flash-lite-latest' => array( 0.10, 0.40 ),
		'gemini-3.6-flash'         => array( 0.30, 2.50 ),
		'gemini-3.5-flash'         => array( 0.30, 2.50 ),
		'gemini-3.1-pro-preview'   => array( 1.25, 10.0 ),
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
		// "-latest" aliases always resolve to Google's current model, so the
		// plugin does not go stale as model versions change. Live options can
		// also be discovered from the API via discover_models().
		$curated = array(
			'gemini-flash-latest'      => 'Gemini Flash (latest)',
			'gemini-pro-latest'        => 'Gemini Pro (latest)',
			'gemini-flash-lite-latest' => 'Gemini Flash-Lite (latest)',
			'gemini-3.6-flash'         => 'Gemini 3.6 Flash',
			'gemini-3.5-flash'         => 'Gemini 3.5 Flash',
			'gemini-3.1-pro-preview'   => 'Gemini 3.1 Pro',
		);
		$live = $this->discover_models();
		if ( ! empty( $live ) ) {
			// Keep the -latest aliases at the top, then live models.
			return array_merge(
				array_intersect_key( $curated, array_flip( array( 'gemini-flash-latest', 'gemini-pro-latest', 'gemini-flash-lite-latest' ) ) ),
				$live
			);
		}
		return $curated;
	}

	/**
	 * Discover models that support generateContent for the configured key.
	 * Cached for 12 hours. Returns id => label, or empty on failure.
	 *
	 * @return array
	 */
	public function discover_models() {
		$key = $this->get_key();
		if ( '' === $key ) {
			return array();
		}
		$cache_key = 'scc_gemini_models_' . md5( $key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::API_BASE . '/models?pageSize=200',
			array( 'timeout' => 15, 'headers' => array( 'x-goog-api-key' => $key ) )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return array();
		}
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( $data['models'] ?? array() ) as $m ) {
			$methods = (array) ( $m['supportedGenerationMethods'] ?? array() );
			if ( ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}
			$name = isset( $m['name'] ) ? preg_replace( '#^models/#', '', $m['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			// Text models only — skip image/tts/audio/robotics/etc.
			if ( preg_match( '/(image|tts|transcribe|audio|robotics|lyria|omni|nano-banana|computer-use)/i', $name ) ) {
				continue;
			}
			$models[ $name ] = isset( $m['displayName'] ) ? $m['displayName'] : $name;
		}
		set_transient( $cache_key, $models, 12 * HOUR_IN_SECONDS );
		return $models;
	}

	/**
	 * @inheritDoc
	 */
	public function estimate_cost( $input_tokens, $output_tokens, $model ) {
		if ( isset( $this->pricing[ $model ] ) ) {
			$rates = $this->pricing[ $model ];
		} elseif ( false !== stripos( $model, 'lite' ) ) {
			$rates = array( 0.10, 0.40 );
		} elseif ( false !== stripos( $model, 'pro' ) ) {
			$rates = array( 1.25, 10.0 );
		} else {
			$rates = array( 0.30, 2.50 );
		}
		return round( ( $input_tokens / 1000000 ) * $rates[0] + ( $output_tokens / 1000000 ) * $rates[1], 6 );
	}

	/**
	 * Map a retired/blocked model id to a working one. Old saved settings
	 * (e.g. gemini-2.5-flash, now retired for new users) must not wedge calls.
	 *
	 * @param string $model Requested model.
	 * @return string
	 */
	public static function resolve_model( $model ) {
		$model = trim( (string) $model );
		if ( '' === $model ) {
			return 'gemini-flash-latest';
		}
		// The 1.5 / 2.0 / 2.5 families are retired or blocked for new users.
		if ( preg_match( '/^gemini-(1\.5|2\.0|2\.5)/i', $model ) ) {
			return ( false !== stripos( $model, 'pro' ) ) ? 'gemini-pro-latest' : 'gemini-flash-latest';
		}
		return $model;
	}

	/**
	 * Build the Gemini request body from a normalized request.
	 *
	 * @param array $request Request.
	 * @return array
	 */
	protected function build_body( array $request ) {
		$body = array( 'contents' => $this->normalize_messages( $request ) );

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
		return $body;
	}

	/**
	 * POST a generateContent request. Returns [http_code, decoded, WP_Error|null].
	 *
	 * @param string $model Model id.
	 * @param string $key   API key.
	 * @param array  $body  Request body.
	 * @return array
	 */
	protected function post_generate( $model, $key, array $body ) {
		$url  = self::API_BASE . '/models/' . rawurlencode( $model ) . ':generateContent';
		$http = wp_remote_post(
			$url,
			array(
				'timeout' => self::DEFAULT_TIMEOUT,
				'headers' => array(
					'content-type'   => 'application/json',
					// Key travels in a header, not the URL/query string.
					'x-goog-api-key' => $key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $http ) ) {
			SCC_Logger::error( 'gemini', 'Transport error: ' . $http->get_error_message() );
			return array( 0, null, new WP_Error( 'scc_transport', $http->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $http );
		$data = json_decode( wp_remote_retrieve_body( $http ), true );
		return array( $code, $data, null );
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

		// Retired/blocked model ids are transparently mapped to a working model
		// so an old saved setting can never wedge the provider.
		$model = self::resolve_model( ! empty( $request['model'] ) ? $request['model'] : 'gemini-flash-latest' );
		$response->model = $model;

		$body = $this->build_body( $request );

		list( $code, $data, $err ) = $this->post_generate( $model, $key, $body );
		if ( $err instanceof WP_Error ) {
			$response->error = $err;
			return $response;
		}

		// Self-heal: if the chosen model is not found / retired at Google, retry
		// once on the always-current alias (the request still worked at the API
		// level, so usage may appear even though nothing was generated).
		if ( 404 === (int) $code && 'gemini-flash-latest' !== $model ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : '';
			if ( preg_match( '/not found|no longer available|not supported/i', $msg ) ) {
				SCC_Logger::info( 'gemini', 'Model unavailable, retrying on gemini-flash-latest', array( 'model' => $model ) );
				$model = 'gemini-flash-latest';
				$response->model = $model;
				list( $code, $data, $err ) = $this->post_generate( $model, $key, $body );
				if ( $err instanceof WP_Error ) {
					$response->error = $err;
					return $response;
				}
			}
		}

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
			$finish = isset( $data['candidates'][0]['finishReason'] ) ? (string) $data['candidates'][0]['finishReason'] : '';
			if ( 'MAX_TOKENS' === $finish ) {
				$msg = __( 'Gemini hit the token limit before returning text (this thinking model spent the budget reasoning). Increase Max tokens / word count and try again.', 'seo-command-center' );
			} elseif ( 'SAFETY' === $finish || 'PROHIBITED_CONTENT' === $finish ) {
				$msg = __( 'Gemini blocked the response with a safety filter.', 'seo-command-center' );
			} else {
				$msg = __( 'Gemini returned no content.', 'seo-command-center' );
			}
			$response->error = new WP_Error( 'scc_empty', $msg );
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
