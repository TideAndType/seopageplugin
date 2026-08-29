<?php
/**
 * AI manager: provider registry, primary/fallback routing, budget guard,
 * usage recording. The only AI entry point the rest of the plugin uses.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI manager.
 */
class SCC_AI_Manager {

	/** @var array<string,SCC_AI_Provider_Interface> */
	protected $providers = array();

	/**
	 * Constructor: register built-in providers.
	 */
	public function __construct() {
		$this->register( new SCC_Claude_Provider() );
		$this->register( new SCC_OpenAI_Provider() );
		$this->register( new SCC_Gemini_Provider() );
		$this->register( new SCC_LMStudio_Provider() );

		/**
		 * Allow add-ons to register additional providers.
		 *
		 * @param SCC_AI_Manager $manager The manager instance.
		 */
		do_action( 'scc_register_ai_providers', $this );
	}

	/**
	 * Register a provider.
	 *
	 * @param SCC_AI_Provider_Interface $provider Provider.
	 */
	public function register( SCC_AI_Provider_Interface $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get a provider by id.
	 *
	 * @param string $id Provider id.
	 * @return SCC_AI_Provider_Interface|null
	 */
	public function get_provider( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * All registered providers.
	 *
	 * @return array<string,SCC_AI_Provider_Interface>
	 */
	public function get_providers() {
		return $this->providers;
	}

	/**
	 * Resolve the configured model for a provider from settings.
	 *
	 * @param string $provider_id Provider id.
	 * @return string
	 */
	protected function model_for( $provider_id ) {
		$settings = get_option( 'scc_settings', array() );
		if ( 'claude' === $provider_id ) {
			return isset( $settings['claude_model'] ) ? $settings['claude_model'] : 'claude-sonnet-5';
		}
		if ( 'openai' === $provider_id ) {
			return isset( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-4o-mini';
		}
		if ( 'gemini' === $provider_id ) {
			return isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-2.5-flash';
		}
		if ( 'lmstudio' === $provider_id ) {
			return isset( $settings['lmstudio_model'] ) ? $settings['lmstudio_model'] : 'local-model';
		}
		return '';
	}

	/**
	 * Whether the monthly budget has been exceeded.
	 *
	 * @return bool
	 */
	public function budget_exceeded() {
		$settings = get_option( 'scc_settings', array() );
		$budget   = isset( $settings['monthly_budget'] ) ? (float) $settings['monthly_budget'] : 0;
		if ( $budget <= 0 ) {
			return false; // No limit.
		}
		return SCC_AI_Usage::month_to_date_cost() >= $budget;
	}

	/**
	 * Run a completion using the primary provider, falling back if configured.
	 *
	 * @param array  $request   Normalized request (system, messages, json, etc.).
	 * @param string $operation Operation label for usage tracking.
	 * @return SCC_AI_Response
	 */
	public function complete( array $request, $operation = 'generic' ) {
		if ( $this->budget_exceeded() ) {
			$r = new SCC_AI_Response();
			$r->error = new WP_Error(
				'scc_budget',
				__( 'Monthly AI budget reached. Increase or remove the limit in Settings to continue.', 'seo-command-center' ),
				array( 'status' => 402 )
			);
			return $r;
		}

		$settings = get_option( 'scc_settings', array() );
		$primary  = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'claude';
		$fallback = isset( $settings['fallback_provider'] ) ? $settings['fallback_provider'] : '';

		$order = array( $primary );
		if ( $fallback && $fallback !== $primary ) {
			$order[] = $fallback;
		}

		$last = new SCC_AI_Response();
		foreach ( $order as $pid ) {
			$provider = $this->get_provider( $pid );
			if ( ! $provider || ! $provider->is_configured() ) {
				continue;
			}
			$req = $request;
			if ( empty( $req['model'] ) ) {
				$req['model'] = $this->model_for( $pid );
			}
			$response = $provider->complete( $req );
			SCC_AI_Usage::record( $response, $operation );
			if ( ! $response->is_error() ) {
				return $response;
			}
			$last = $response;
			SCC_Logger::error( 'ai-manager', 'Provider failed, trying fallback if available', array( 'provider' => $pid ) );
		}

		if ( ! $last->is_error() ) {
			$last->error = new WP_Error( 'scc_no_provider', __( 'No AI provider is configured. Add an API key under API Connections.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		return $last;
	}
}
