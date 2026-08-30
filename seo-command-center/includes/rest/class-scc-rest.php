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

	/** @var SCC_Jobs|null */
	protected $jobs;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai   AI manager.
	 * @param SCC_Jobs|null  $jobs Job queue.
	 */
	public function __construct( SCC_AI_Manager $ai, $jobs = null ) {
		$this->ai   = $ai;
		$this->jobs = $jobs;
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
							return in_array( $v, array( 'claude', 'openai', 'gemini', 'lmstudio' ), true );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/lmstudio/models',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lmstudio_models' ),
				'permission_callback' => $perm,
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

		// ---- Phase 2: strategy ----------------------------------------
		register_rest_route(
			self::NS,
			'/keywords',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_keywords' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_keywords' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/keywords/auto',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'auto_keywords' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/architecture',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_architecture' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/content-plan',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_plan' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_content_plan' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/content-plan/seed',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'seed_content_plan' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/content-plan/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_content_plan' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_content_plan' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/cannibalization',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'cannibalization' ),
				'permission_callback' => $perm,
			)
		);

		// ---- Phase 3: generation --------------------------------------
		register_rest_route(
			self::NS,
			'/brief',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'brief' ),
				'permission_callback' => $perm,
				'args'                => array(
					'entry_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => $perm,
				'args'                => array(
					'entry_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/regenerate-section',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'regenerate_section' ),
				'permission_callback' => $perm,
				'args'                => array(
					'post_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
					'section' => array( 'sanitize_callback' => 'sanitize_key', 'required' => true ),
				),
			)
		);

		// ---- Phase 4: Elementor templates -----------------------------
		register_rest_route(
			self::NS,
			'/templates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_templates' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/templates/map',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'map_template' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/templates/map/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_mapping' ),
				'permission_callback' => $perm,
			)
		);

		// ---- Phase 5: internal links ----------------------------------
		register_rest_route(
			self::NS,
			'/internal-links',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_internal_links' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/internal-links/recommend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recommend_links' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/internal-links/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_link' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
				),
			)
		);

		// ---- Phase 6: data integrations -------------------------------
		register_rest_route(
			self::NS,
			'/gsc/quick-wins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'gsc_quick_wins' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/gsc/verify',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'gsc_verify' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/gsc/auth-url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'gsc_auth_url' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/dataforseo/keywords',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dataforseo_keywords' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/competitors/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze_competitor' ),
				'permission_callback' => $perm,
				'args'                => array(
					'url' => array( 'sanitize_callback' => 'esc_url_raw', 'required' => true ),
				),
			)
		);

		// ---- Phase 7: batch jobs + publishing -------------------------
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'jobs_status' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'jobs_batch' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/jobs/(?P<action>pause|resume|retry)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'jobs_control' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/publishing',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'publishing_queue' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/publishing/(?P<action>approve|unapprove|publish|schedule)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'publishing_action' ),
				'permission_callback' => $perm,
				'args'                => array(
					'post_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
				),
			)
		);

		// ---- Advanced internal linking, meta, schema ------------------
		$post_arg = array( 'post_id' => array( 'sanitize_callback' => 'absint', 'required' => true ) );

		register_rest_route( self::NS, '/index/reindex', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'reindex' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/index/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'index_status' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/links/analyze', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'links_analyze' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/links/recommendations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'links_recommendations' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/links/apply', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'links_apply' ),
			'permission_callback' => $perm,
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
		) );
		register_rest_route( self::NS, '/links/apply-high', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'links_apply_high' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/links/scan', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'links_scan' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/meta/variants', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'meta_variants' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/meta/apply', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'meta_apply' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/meta/opportunities', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'meta_opportunities' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/meta/history', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'meta_history' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/schema/recommend', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'schema_recommend' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/schema/generate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'schema_generate' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/schema/save', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'schema_save' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/schema/disable', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'schema_disable' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );
		register_rest_route( self::NS, '/schema/settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'schema_settings_get' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'schema_settings_save' ),
				'permission_callback' => $perm,
			),
		) );
		register_rest_route( self::NS, '/history', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'history_list' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/history/revert', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'history_revert' ),
			'permission_callback' => $perm,
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint', 'required' => true ) ),
		) );
		register_rest_route( self::NS, '/seo-report', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'seo_report' ),
			'permission_callback' => $perm,
			'args'                => $post_arg,
		) );

		// ---- CMS-agnostic templates + renderers -----------------------
		register_rest_route( self::NS, '/templates/native', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'templates_list' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'templates_create' ),
				'permission_callback' => $perm,
			),
		) );
		register_rest_route( self::NS, '/templates/native/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'templates_version' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'templates_delete' ),
				'permission_callback' => $perm,
			),
		) );
		register_rest_route( self::NS, '/templates/native/clone', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'templates_clone' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/templates/native/map', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'templates_map' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/templates/native/import-elementor', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'templates_import_elementor' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/templates/native/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'templates_preview' ),
			'permission_callback' => $perm,
		) );
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

		// Resolve the model for whichever provider was clicked.
		$models = array(
			'claude'   => SCC_Settings::get( 'claude_model' ),
			'openai'   => SCC_Settings::get( 'openai_model' ),
			'gemini'   => SCC_Settings::get( 'gemini_model' ),
			'lmstudio' => SCC_Settings::get( 'lmstudio_model' ),
		);

		// Call the selected provider DIRECTLY so Test always exercises that
		// provider (not the configured default), then record usage.
		$start    = microtime( true );
		$response = $provider->complete(
			array(
				'system'     => 'You are a connectivity test. Reply with the single word: OK',
				'messages'   => array( array( 'role' => 'user', 'content' => 'Say OK' ) ),
				// Generous budget so "thinking" models (Gemini 3.x, etc.) still
				// produce visible output after their internal reasoning tokens.
				'max_tokens' => 256,
				'model'      => isset( $models[ $provider_id ] ) ? $models[ $provider_id ] : '',
			)
		);
		SCC_AI_Usage::record( $response, 'connectivity-test' );
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
	 * POST /lmstudio/models — detect the models a local LM Studio server exposes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function lmstudio_models( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$base   = isset( $params['base_url'] ) ? esc_url_raw( trim( (string) $params['base_url'] ) ) : '';

		$provider = $this->ai->get_provider( 'lmstudio' );
		if ( ! $provider ) {
			return $this->fail( 'no_provider', __( 'LM Studio provider unavailable.', 'seo-command-center' ), 500 );
		}
		return $this->ok( $provider->discover_models( $base ) );
	}

	/**
	 * POST /analyze.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function analyze( WP_REST_Request $request ) {
		$limit = SCC_Security::sanitize_int( $request->get_param( 'limit' ), 1, 2000 );
		$deep  = SCC_Security::sanitize_bool( $request->get_param( 'deep' ) );
		$analyzer = new SCC_Analyzer();
		$result   = $analyzer->run( array( 'limit' => $limit, 'deep' => $deep ) );
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
	 * GET /keywords — latest strategy.
	 *
	 * @return WP_REST_Response
	 */
	public function get_keywords() {
		return $this->ok( array( 'strategy' => SCC_Keyword_Strategy::latest() ) );
	}

	/**
	 * POST /keywords — generate a topical map.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_keywords( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : $request->get_params();
		$service = new SCC_Keyword_Strategy( $this->ai );
		$result  = $service->generate( is_array( $params ) ? $params : array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * POST /keywords/auto — infer inputs from the site, then build the map.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function auto_keywords() {
		$inputs  = SCC_Keyword_Strategy::infer_inputs_from_site();
		$service = new SCC_Keyword_Strategy( $this->ai );
		$result  = $service->generate( $inputs );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['inferred'] = true;
		return $this->ok( $result );
	}

	/**
	 * GET /architecture — build tree from the latest strategy.
	 *
	 * @return WP_REST_Response
	 */
	public function get_architecture() {
		$strategy = SCC_Keyword_Strategy::latest();
		if ( ! $strategy || empty( $strategy['map_data'] ) ) {
			return $this->ok( array( 'tree' => null ) );
		}
		$builder = new SCC_Architecture();
		return $this->ok( array( 'tree' => $builder->build( $strategy['map_data'] ) ) );
	}

	/**
	 * GET /content-plan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_content_plan( WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		return $this->ok( array( 'entries' => SCC_Content_Plan::all( $status ) ) );
	}

	/**
	 * POST /content-plan — create an entry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_content_plan( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$id     = SCC_Content_Plan::create( is_array( $params ) ? $params : array() );
		if ( ! $id ) {
			return $this->fail( 'create_failed', __( 'Could not create the plan entry.', 'seo-command-center' ), 500 );
		}
		return $this->ok( array( 'id' => $id, 'entries' => SCC_Content_Plan::all() ) );
	}

	/**
	 * POST /content-plan/seed — seed the plan from the latest architecture.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function seed_content_plan( WP_REST_Request $request ) {
		$strategy = SCC_Keyword_Strategy::latest();
		if ( ! $strategy || empty( $strategy['map_data'] ) ) {
			return $this->fail( 'no_strategy', __( 'Generate a keyword strategy first.', 'seo-command-center' ), 400 );
		}

		// Optional allow-list of URLs to seed (from the checkboxes). When absent,
		// seed every new/gap page (back-compatible).
		$only  = null;
		$param = $request->get_param( 'urls' );
		if ( is_array( $param ) ) {
			$only = array_values( array_filter( array_map( array( 'SCC_Security', 'sanitize_text' ), $param ) ) );
		}

		$builder = new SCC_Architecture();
		$tree    = $builder->build( $strategy['map_data'] );
		$created = SCC_Content_Plan::seed_from_architecture( $tree, $only );
		return $this->ok( array( 'created' => $created, 'entries' => SCC_Content_Plan::all() ) );
	}

	/**
	 * PUT /content-plan/{id} — update status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_content_plan( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$status = isset( $params['status'] ) ? $params['status'] : '';
		if ( ! SCC_Content_Plan::set_status( $id, $status ) ) {
			return $this->fail( 'update_failed', __( 'Invalid status or entry.', 'seo-command-center' ), 400 );
		}
		return $this->ok( array( 'entries' => SCC_Content_Plan::all() ) );
	}

	/**
	 * DELETE /content-plan/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_content_plan( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		SCC_Content_Plan::delete( $id );
		return $this->ok( array( 'entries' => SCC_Content_Plan::all() ) );
	}

	/**
	 * GET /cannibalization.
	 *
	 * @return WP_REST_Response
	 */
	public function cannibalization() {
		$detector = new SCC_Cannibalization();
		return $this->ok( array( 'groups' => $detector->detect() ) );
	}

	/**
	 * POST /brief — generate a content brief for approval.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function brief( WP_REST_Request $request ) {
		$entry = SCC_Content_Plan::find( (int) $request->get_param( 'entry_id' ) );
		if ( ! $entry ) {
			return $this->fail( 'no_entry', __( 'Content plan entry not found.', 'seo-command-center' ), 404 );
		}
		$service = new SCC_Content_Brief( $this->ai );
		$brief   = $service->generate( $entry );
		if ( is_wp_error( $brief ) ) {
			return $brief;
		}
		return $this->ok( array( 'brief' => $brief, 'entry' => $entry ) );
	}

	/**
	 * POST /generate — run the pipeline and create a draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$entry = SCC_Content_Plan::find( (int) $request->get_param( 'entry_id' ) );
		if ( ! $entry ) {
			return $this->fail( 'no_entry', __( 'Content plan entry not found.', 'seo-command-center' ), 404 );
		}

		$params = $request->get_json_params();
		$brief  = ( is_array( $params ) && ! empty( $params['brief'] ) && is_array( $params['brief'] ) ) ? $params['brief'] : null;

		$generator = new SCC_Generator( $this->ai );
		$result    = $generator->generate( $entry, $brief );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * POST /regenerate-section.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function regenerate_section( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->fail( 'forbidden', __( 'You cannot edit this post.', 'seo-command-center' ), 403 );
		}
		$generator = new SCC_Generator( $this->ai );
		$result    = $generator->regenerate_section( $post_id, $request->get_param( 'section' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * GET /templates — Elementor templates + current mappings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_templates() {
		return $this->ok(
			array(
				'elementor'     => SCC_Elementor::is_active(),
				'templates'     => SCC_Elementor::is_active() ? SCC_Elementor::list_templates() : array(),
				'mappings'      => SCC_Template_Mapping::all(),
				'content_types' => SCC_Template_Mapping::CONTENT_TYPES,
			)
		);
	}

	/**
	 * POST /templates/map — map a template to a content type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function map_template( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$id     = SCC_Template_Mapping::save( is_array( $params ) ? $params : array() );
		if ( ! $id ) {
			return $this->fail( 'map_failed', __( 'Provide a valid template and content type.', 'seo-command-center' ), 400 );
		}
		return $this->ok( array( 'id' => $id, 'mappings' => SCC_Template_Mapping::all() ) );
	}

	/**
	 * DELETE /templates/map/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete_mapping( WP_REST_Request $request ) {
		SCC_Template_Mapping::delete( (int) $request->get_param( 'id' ) );
		return $this->ok( array( 'mappings' => SCC_Template_Mapping::all() ) );
	}

	/**
	 * GET /internal-links — graph report + pending recommendations.
	 *
	 * @return WP_REST_Response
	 */
	public function get_internal_links() {
		$graph = new SCC_Link_Graph();
		$data  = $graph->build( 500 );
		return $this->ok(
			array(
				'totals'          => $data['totals'],
				'orphans'         => array_slice( $data['orphans'], 0, 50 ),
				'under_linked'    => array_slice( $data['under_linked'], 0, 50 ),
				'over_linked'     => array_slice( $data['over_linked'], 0, 50 ),
				'recommendations' => SCC_Link_Recommender::list_recommendations( 'recommended', 200 ),
			)
		);
	}

	/**
	 * POST /internal-links/recommend — regenerate recommendations.
	 *
	 * @return WP_REST_Response
	 */
	public function recommend_links() {
		$recommender = new SCC_Link_Recommender();
		$result      = $recommender->generate( 300 );
		return $this->ok(
			array(
				'created'         => $result['created'],
				'recommendations' => SCC_Link_Recommender::list_recommendations( 'recommended', 200 ),
			)
		);
	}

	/**
	 * POST /internal-links/apply — insert one approved link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_link( WP_REST_Request $request ) {
		$inserter = new SCC_Link_Inserter();
		$result   = $inserter->apply( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * GET /gsc/verify — end-to-end connection diagnostics.
	 *
	 * @return WP_REST_Response
	 */
	public function gsc_verify() {
		return $this->ok( array( 'verify' => SCC_GSC::verify() ) );
	}

	/**
	 * GET /gsc/auth-url — start the OAuth connect flow.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function gsc_auth_url() {
		$url = SCC_GSC::auth_url();
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		return $this->ok( array( 'url' => $url ) );
	}

	/**
	 * GET /gsc/quick-wins.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function gsc_quick_wins( WP_REST_Request $request ) {
		if ( ! SCC_GSC::is_connected() ) {
			return $this->ok( array( 'connected' => false, 'wins' => array() ) );
		}
		$site = SCC_Security::sanitize_text( (string) $request->get_param( 'site' ) );
		$wins = SCC_GSC::quick_wins( $site, 50 );
		if ( is_wp_error( $wins ) ) {
			return $wins;
		}
		return $this->ok( array( 'connected' => true, 'wins' => $wins ) );
	}

	/**
	 * POST /dataforseo/keywords.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dataforseo_keywords( WP_REST_Request $request ) {
		if ( ! SCC_DataForSEO::is_connected() ) {
			return $this->ok( array( 'connected' => false, 'keywords' => array() ) );
		}
		$params   = $request->get_json_params();
		$keywords = ( is_array( $params ) && ! empty( $params['keywords'] ) ) ? (array) $params['keywords'] : array();
		$result   = SCC_DataForSEO::search_volume( $keywords );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'connected' => true, 'keywords' => $result ) );
	}

	/**
	 * POST /competitors/analyze.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze_competitor( WP_REST_Request $request ) {
		$analyzer = new SCC_Competitor_Analysis();
		$result   = $analyzer->analyze( (string) $request->get_param( 'url' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'analysis' => $result ) );
	}

	/**
	 * GET /jobs — queue status.
	 *
	 * @return WP_REST_Response
	 */
	public function jobs_status() {
		return $this->ok( array( 'jobs' => SCC_Jobs::status() ) );
	}

	/**
	 * POST /jobs/batch — enqueue a batch of approved entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function jobs_batch( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$ids    = isset( $params['entry_ids'] ) ? (array) $params['entry_ids'] : array();

		// Enforce batch cap from settings.
		$max = (int) SCC_Settings::get( 'max_pages_per_batch', 25 );
		if ( count( $ids ) > $max ) {
			return $this->fail( 'batch_too_large', sprintf(
				/* translators: %d: max batch size */
				__( 'That exceeds the batch limit of %d. Reduce the selection or raise the limit in Settings.', 'seo-command-center' ),
				$max
			), 400 );
		}
		if ( ! $this->jobs ) {
			return $this->fail( 'no_queue', __( 'Job queue unavailable.', 'seo-command-center' ), 500 );
		}
		$result = $this->jobs->enqueue_generation_batch( $ids );
		return $this->ok( array_merge( $result, array( 'jobs' => SCC_Jobs::status() ) ) );
	}

	/**
	 * POST /jobs/{pause|resume|retry}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function jobs_control( WP_REST_Request $request ) {
		switch ( $request->get_param( 'action' ) ) {
			case 'pause':
				SCC_Jobs::pause();
				break;
			case 'resume':
				SCC_Jobs::resume();
				break;
			case 'retry':
				SCC_Jobs::retry_failed();
				break;
		}
		return $this->ok( array( 'jobs' => SCC_Jobs::status() ) );
	}

	/**
	 * GET /publishing — the queue.
	 *
	 * @return WP_REST_Response
	 */
	public function publishing_queue() {
		return $this->ok( array( 'queue' => SCC_Publishing::queue() ) );
	}

	/**
	 * POST /publishing/{approve|unapprove|publish|schedule}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publishing_action( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$action  = $request->get_param( 'action' );
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();

		switch ( $action ) {
			case 'approve':
				SCC_Publishing::set_approved( $post_id, true );
				break;
			case 'unapprove':
				SCC_Publishing::set_approved( $post_id, false );
				break;
			case 'publish':
				$r = SCC_Publishing::publish( $post_id );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'schedule':
				$r = SCC_Publishing::schedule( $post_id, (string) ( $params['datetime'] ?? '' ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
		}
		return $this->ok( array( 'queue' => SCC_Publishing::queue() ) );
	}

	/**
	 * POST /index/reindex.
	 *
	 * @return WP_REST_Response
	 */
	public function reindex() {
		$count = SCC_Content_Index::reindex_all( 2000 );
		return $this->ok( array( 'indexed' => $count ) );
	}

	/**
	 * GET /index/status.
	 *
	 * @return WP_REST_Response
	 */
	public function index_status() {
		return $this->ok( array( 'indexed' => SCC_Content_Index::count() ) );
	}

	/**
	 * POST /links/analyze.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function links_analyze( WP_REST_Request $request ) {
		$engine = new SCC_Link_Engine();
		$result = $engine->analyze( (int) $request->get_param( 'post_id' ), true );
		return $this->ok( $result );
	}

	/**
	 * GET /links/recommendations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function links_recommendations( WP_REST_Request $request ) {
		return $this->ok( array(
			'recommendations' => SCC_Link_Engine::recommendations( array(
				'direction'      => sanitize_key( (string) $request->get_param( 'direction' ) ),
				'min_confidence' => (int) $request->get_param( 'min_confidence' ),
			) ),
		) );
	}

	/**
	 * POST /links/apply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function links_apply( WP_REST_Request $request ) {
		$inserter = new SCC_Link_Inserter();
		$result   = $inserter->apply( (int) $request->get_param( 'id' ), 'manual' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * POST /links/apply-high — apply all high-confidence recommendations.
	 *
	 * @return WP_REST_Response
	 */
	public function links_apply_high() {
		$high = (int) SCC_Settings::get( 'link_high_confidence', 80 );
		$recs = SCC_Link_Engine::recommendations( array( 'min_confidence' => $high, 'limit' => 200 ) );
		$inserter = new SCC_Link_Inserter();
		$applied  = 0;
		$skipped  = 0;
		foreach ( $recs as $rec ) {
			$r = $inserter->apply( (int) $rec['id'], 'batch' );
			if ( is_wp_error( $r ) ) {
				$skipped++;
			} else {
				$applied++;
			}
		}
		return $this->ok( array( 'applied' => $applied, 'skipped' => $skipped ) );
	}

	/**
	 * POST /links/scan — site-wide reoptimization scan.
	 *
	 * @return WP_REST_Response
	 */
	public function links_scan() {
		// Ensure the index exists before scanning.
		if ( SCC_Content_Index::count() < 1 ) {
			SCC_Content_Index::reindex_all( 2000 );
		}
		$engine = new SCC_Link_Engine();
		return $this->ok( $engine->scan_site( 500 ) );
	}

	/**
	 * POST /meta/variants.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function meta_variants( WP_REST_Request $request ) {
		$optimizer = new SCC_Meta_Optimizer( $this->ai );
		$result    = $optimizer->generate_variants( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * POST /meta/apply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function meta_apply( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$optimizer = new SCC_Meta_Optimizer( $this->ai );
		$result = $optimizer->apply(
			(int) $request->get_param( 'post_id' ),
			array(
				'title'       => $params['title'] ?? '',
				'description' => $params['description'] ?? '',
			),
			(string) ( $params['reason'] ?? '' ),
			! empty( $params['force'] )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/**
	 * GET /meta/opportunities.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function meta_opportunities() {
		$optimizer = new SCC_Meta_Optimizer( $this->ai );
		$ops = $optimizer->opportunities();
		if ( is_wp_error( $ops ) ) {
			return $ops;
		}
		return $this->ok( array( 'connected' => SCC_GSC::is_connected(), 'opportunities' => $ops ) );
	}

	/**
	 * GET /meta/history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function meta_history( WP_REST_Request $request ) {
		return $this->ok( array( 'history' => SCC_Meta_History::for_post( (int) $request->get_param( 'post_id' ) ) ) );
	}

	/**
	 * POST /schema/recommend.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function schema_recommend( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$rec     = SCC_Schema_Engine::recommend( $post_id );
		$rec['conflicts'] = SCC_Schema_Engine::detect_conflicts( $post_id, $rec['recommended'] );
		return $this->ok( $rec );
	}

	/**
	 * POST /schema/generate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function schema_generate( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$types   = isset( $params['types'] ) ? array_map( 'sanitize_text_field', (array) $params['types'] ) : array();
		$post_id = (int) $request->get_param( 'post_id' );
		$result  = SCC_Schema_Engine::generate( $post_id, $types );
		$result['conflicts'] = SCC_Schema_Engine::detect_conflicts( $post_id, wp_list_pluck( $result['nodes'], '@type' ) );
		return $this->ok( $result );
	}

	/**
	 * POST /schema/save.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function schema_save( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$post_id = (int) $request->get_param( 'post_id' );

		// Accept either generated types (regenerate then save) or explicit nodes.
		if ( ! empty( $params['nodes'] ) && is_array( $params['nodes'] ) ) {
			$nodes = $params['nodes'];
		} else {
			$types = isset( $params['types'] ) ? array_map( 'sanitize_text_field', (array) $params['types'] ) : array();
			$gen   = SCC_Schema_Engine::generate( $post_id, $types );
			$nodes = $gen['nodes'];
		}
		$result = SCC_Schema_Engine::save( $post_id, $nodes );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'saved' => true, 'count' => count( $nodes ) ) );
	}

	/**
	 * POST /schema/disable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function schema_disable( WP_REST_Request $request ) {
		$result = SCC_Schema_Engine::disable( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'disabled' => true ) );
	}

	/**
	 * GET /schema/settings.
	 *
	 * @return WP_REST_Response
	 */
	public function schema_settings_get() {
		return $this->ok( array( 'settings' => SCC_Schema_Engine::business() ) );
	}

	/**
	 * POST /schema/settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function schema_settings_save( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		return $this->ok( array( 'settings' => SCC_Schema_Engine::save_business( is_array( $params ) ? $params : array() ) ) );
	}

	/**
	 * GET /history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function history_list( WP_REST_Request $request ) {
		return $this->ok( array( 'history' => SCC_Change_History::all( (int) $request->get_param( 'post_id' ), 200 ) ) );
	}

	/**
	 * POST /history/revert.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function history_revert( WP_REST_Request $request ) {
		$result = SCC_Change_History::revert( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( array( 'reverted' => true ) );
	}

	/**
	 * GET /seo-report — unified per-page optimization readiness.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function seo_report( WP_REST_Request $request ) {
		return $this->ok( SCC_SEO_Report::build( (int) $request->get_param( 'post_id' ) ) );
	}

	/**
	 * GET /templates/native — templates, map, renderers, Elementor sources.
	 *
	 * @return WP_REST_Response
	 */
	public function templates_list() {
		$manager   = new SCC_Renderer_Manager();
		$renderers = array();
		foreach ( $manager->all() as $id => $r ) {
			$renderers[ $id ] = array( 'label' => $r->get_label(), 'available' => $r->is_available() );
		}
		$templates = array();
		foreach ( SCC_Template_Store::all_active() as $row ) {
			$templates[] = array(
				'id' => (int) $row['id'], 'family' => $row['family'], 'name' => $row['name'],
				'content_type' => $row['content_type'], 'renderer' => $row['renderer'],
				'version' => (int) $row['version'], 'elementor_source_id' => (int) $row['elementor_source_id'],
				'sections' => count( (array) ( $row['structure']['sections'] ?? array() ) ),
			);
		}
		return $this->ok( array(
			'templates'        => $templates,
			'map'              => SCC_Template_Map::all(),
			'types'            => SCC_Template::TYPES,
			'renderers'        => $renderers,
			'default_renderer' => SCC_Settings::get( 'default_renderer', 'gutenberg' ),
			'elementor_active' => SCC_Elementor::is_active(),
			'elementor_sources'=> SCC_Elementor::is_active() ? SCC_Elementor::list_templates() : array(),
		) );
	}

	/**
	 * POST /templates/native — create a template (from a type default if no structure).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function templates_create( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$id     = SCC_Template_Store::create( is_array( $params ) ? $params : array() );
		if ( ! $id ) {
			return $this->fail( 'create_failed', __( 'Could not create the template.', 'seo-command-center' ), 500 );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	/**
	 * PUT /templates/native/{id} — save as a new version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function templates_version( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_params();
		$id     = SCC_Template_Store::update_as_new_version( (int) $request->get_param( 'id' ), is_array( $params ) ? $params : array() );
		if ( ! $id ) {
			return $this->fail( 'version_failed', __( 'Could not save a new version.', 'seo-command-center' ), 500 );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	/**
	 * DELETE /templates/native/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function templates_delete( WP_REST_Request $request ) {
		SCC_Template_Store::delete( (int) $request->get_param( 'id' ) );
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * POST /templates/native/clone.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function templates_clone( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$id     = SCC_Template_Store::clone_template( (int) ( $params['id'] ?? 0 ), (string) ( $params['name'] ?? '' ) );
		if ( ! $id ) {
			return $this->fail( 'clone_failed', __( 'Could not clone the template.', 'seo-command-center' ), 500 );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	/**
	 * POST /templates/native/map — content-type -> template + renderer + default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function templates_map( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		if ( isset( $params['content_type'] ) ) {
			SCC_Template_Map::set(
				(string) $params['content_type'],
				(string) ( $params['family'] ?? '' ),
				(string) ( $params['renderer'] ?? '' )
			);
		}
		if ( isset( $params['default_family'] ) ) {
			SCC_Template_Map::set_default_family( (string) $params['default_family'] );
		}
		if ( isset( $params['default_renderer'] ) ) {
			SCC_Settings::update( array( 'default_renderer' => (string) $params['default_renderer'] ) );
		}
		return $this->ok( array( 'map' => SCC_Template_Map::all() ) );
	}

	/**
	 * POST /templates/native/import-elementor — register an Elementor page as a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function templates_import_elementor( WP_REST_Request $request ) {
		if ( ! SCC_Elementor::is_active() ) {
			return $this->fail( 'no_elementor', __( 'Elementor is not active.', 'seo-command-center' ), 400 );
		}
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$source = (int) ( $params['source_id'] ?? 0 );
		if ( $source <= 0 || ! SCC_Elementor::get_data( $source ) ) {
			return $this->fail( 'bad_source', __( 'That page has no Elementor data.', 'seo-command-center' ), 400 );
		}
		$id = SCC_Template_Store::create( array(
			'name'                => (string) ( $params['name'] ?? get_the_title( $source ) ),
			'content_type'        => (string) ( $params['content_type'] ?? 'service' ),
			'renderer'            => 'elementor',
			'elementor_source_id' => $source,
		) );
		if ( ! $id ) {
			return $this->fail( 'import_failed', __( 'Could not import the template.', 'seo-command-center' ), 500 );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	/**
	 * POST /templates/native/preview — render a sample of a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function templates_preview( WP_REST_Request $request ) {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$type    = sanitize_key( $params['content_type'] ?? 'service' );
		$family  = (string) ( $params['family'] ?? '' );

		$selection = SCC_Template_Selector::select( $type, $family );
		$template  = $selection['template'];
		$renderer_id = SCC_Template_Selector::renderer_for( $type, $template );
		$manager   = new SCC_Renderer_Manager();
		$renderer  = $manager->pick( $renderer_id, $type );

		// Build a sample content object (deterministic placeholder content).
		$content = new SCC_Content_Object();
		$content->content_type = $type;
		$content->title   = SCC_Security::sanitize_text( $params['service'] ?? 'Sample Service' ) . ( ! empty( $params['city'] ) ? ' — ' . SCC_Security::sanitize_text( $params['city'] ) : '' );
		$content->h1      = $content->title;
		$content->service = SCC_Security::sanitize_text( $params['service'] ?? 'Sample Service' );
		$content->city    = SCC_Security::sanitize_text( $params['city'] ?? '' );
		$content->primary_keyword = SCC_Security::sanitize_text( $params['primary_keyword'] ?? '' );
		$content->intro   = __( 'This is a preview of how your template will be populated. Real content replaces this when you generate a page.', 'seo-command-center' );
		$content->content = '<p>' . esc_html( $content->intro ) . '</p>';
		$content->benefits = array( __( 'Benefit one', 'seo-command-center' ), __( 'Benefit two', 'seo-command-center' ) );
		$content->faq     = array( array( 'question' => __( 'A sample question?', 'seo-command-center' ), 'answer' => __( 'A sample answer.', 'seo-command-center' ) ) );
		$content->cta     = __( 'Contact us to get started.', 'seo-command-center' );

		$rendered = $renderer->render( $content, $template );
		if ( is_wp_error( $rendered ) ) {
			$rendered = ( new SCC_WordPress_Renderer() )->render( $content, $template );
		}

		return $this->ok( array(
			'template' => $template->name,
			'renderer' => $renderer->get_id(),
			'source'   => $selection['source'],
			'html'     => is_wp_error( $rendered ) ? '' : $rendered['post_content'],
		) );
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
