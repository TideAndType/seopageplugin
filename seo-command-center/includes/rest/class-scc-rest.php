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
	public function seed_content_plan() {
		$strategy = SCC_Keyword_Strategy::latest();
		if ( ! $strategy || empty( $strategy['map_data'] ) ) {
			return $this->fail( 'no_strategy', __( 'Generate a keyword strategy first.', 'seo-command-center' ), 400 );
		}
		$builder = new SCC_Architecture();
		$tree    = $builder->build( $strategy['map_data'] );
		$created = SCC_Content_Plan::seed_from_architecture( $tree );
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
