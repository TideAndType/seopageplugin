<?php
/**
 * Internal REST API controller for namespace seo-command/v1.
 *
 * Every route requires the plugin capability and is called from admin JS with
 * the wp_rest nonce. No route ever returns a stored API key.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller.
 */
class SCC_REST {

	const NS = 'seo-command/v1';

	/** @var SCC_AI_Manager */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai AI manager.
	 */
	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$perm = array( 'SCC_Security', 'rest_permission' );

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/ai/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_ai' ),
				'permission_callback' => $perm,
				'args'                => array(
					'provider' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $v ) {
							return in_array( $v, array( 'claude', 'openai' ), true );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => $perm,
				'args'                => array(
					'limit' => array(
						'sanitize_callback' => 'absint',
						'default'           => 200,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/analysis/latest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'latest_analysis' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * GET /status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$providers = array();
		foreach ( $this->ai->get_providers() as $id => $provider ) {
			$providers[ $id ] = array(
				'label'      => $provider->get_label(),
				'configured' => $provider->is_configured(),
			);
		}

		return $this->ok(
			array(
				'version'      => SCC_VERSION,
				'seo_plugin'   => SCC_SEO_Meta::label( SCC_SEO_Meta::detect() ),
				'elementor'    => defined( 'ELEMENTOR_VERSION' ),
				'providers'    => $providers,
				'post_types'   => SCC_Analyzer::analyzable_post_types(),
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return $this->ok(
			array(
				'settings'    => SCC_Settings::all(),
				'credentials' => SCC_Settings::credential_hints(),
			)
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
			SCC_Settings::update( $params['settings'] );
		}
		if ( isset( $params['credentials'] ) && is_array( $params['credentials'] ) ) {
			SCC_Settings::update_credentials( $params['credentials'] );
		}

		return $this->ok(
			array(
				'settings'    => SCC_Settings::all(),
				'credentials' => SCC_Settings::credential_hints(),
				'saved'       => true,
			)
		);
	}

	/**
	 * POST /ai/test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_ai( WP_REST_Request $request ) {
		$provider_id = $request->get_param( 'provider' );
		$provider    = $this->ai->get_provider( $provider_id );

		if ( ! $provider ) {
			return $this->fail( 'unknown_provider', __( 'Unknown provider.', 'seo-command-center' ), 400 );
		}
		if ( ! $provider->is_configured() ) {
			return $this->fail( 'not_configured', __( 'Add an API key for this provider first.', 'seo-command-center' ), 400 );
		}

		$start    = microtime( true );
		$response = $this->ai->complete(
			array(
				'system'     => 'You are a connectivity test. Reply with the single word: OK',
				'messages'   => array( array( 'role' => 'user', 'content' => 'Say OK' ) ),
				'max_tokens' => 16,
				'provider'   => $provider_id,
				'model'      => ( 'claude' === $provider_id ) ? SCC_Settings::get( 'claude_model' ) : SCC_Settings::get( 'openai_model' ),
			),
			'connectivity-test'
		);
		$latency = round( ( microtime( true ) - $start ) * 1000 );

		if ( $response->is_error() ) {
			return $this->fail( 'ai_error', $response->error->get_error_message(), 502 );
		}

		return $this->ok(
			array(
				'reply'      => trim( $response->content ),
				'model'      => $response->model,
				'latency_ms' => $latency,
			)
		);
	}

	/**
	 * POST /analyze.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function analyze( WP_REST_Request $request ) {
		$limit = SCC_Security::sanitize_int( $request->get_param( 'limit' ), 1, 2000 );
		$analyzer = new SCC_Analyzer();
		$result   = $analyzer->run( array( 'limit' => $limit ) );
		return $this->ok( $result );
	}

	/**
	 * GET /analysis/latest.
	 *
	 * @return WP_REST_Response
	 */
	public function latest_analysis() {
		$latest = SCC_Analyzer::latest();
		if ( ! $latest ) {
			return $this->ok( array( 'analysis' => null ) );
		}
		return $this->ok( array( 'analysis' => $latest ) );
	}

	/**
	 * GET /usage.
	 *
	 * @return WP_REST_Response
	 */
	public function usage() {
		return $this->ok(
			array(
				'month'  => SCC_AI_Usage::month_summary(),
				'budget' => (float) SCC_Settings::get( 'monthly_budget', 0 ),
			)
		);
	}

	/**
	 * GET /logs.
	 *
	 * @return WP_REST_Response
	 */
	public function logs() {
		return $this->ok( array( 'logs' => SCC_Logger::recent( 100 ) ) );
	}

	/**
	 * Success envelope.
	 *
	 * @param array $data Data.
	 * @return WP_REST_Response
	 */
	protected function ok( array $data ) {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), 200 );
	}

	/**
	 * Error response.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	protected function fail( $code, $message, $status ) {
		return new WP_Error( 'scc_' . $code, $message, array( 'status' => $status ) );
	}
}
