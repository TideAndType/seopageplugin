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
	 * Per-operation routing: which named AI tasks can use their own
	 * provider/model. Key = settings prefix, value = human label.
	 *
	 * @return array
	 */
	public static function routable_operations() {
		return array(
			'keyword_strategy'   => __( 'Keyword Strategy / Topical Map', 'seo-command-center' ),
			'content_generation' => __( 'Content Generation (pages & articles)', 'seo-command-center' ),
			'content_brief'      => __( 'Content Briefs', 'seo-command-center' ),
			'meta_optimization'  => __( 'Metadata Optimization', 'seo-command-center' ),
		);
	}

	/**
	 * Map an operation label (as passed to complete()) to a routing key.
	 *
	 * @param string $operation Operation label.
	 * @return string Routing key, or '' if the operation is not routable.
	 */
	protected function route_key_for( $operation ) {
		$map = array(
			'keyword-strategy'    => 'keyword_strategy',
			'content-generation'  => 'content_generation',
			'regenerate-section'  => 'content_generation',
			'content-brief'       => 'content_brief',
			'meta-optimization'   => 'meta_optimization',
		);
		return isset( $map[ $operation ] ) ? $map[ $operation ] : '';
	}

	/**
	 * Resolve the per-operation provider/model override, if any.
	 *
	 * @param string $operation Operation label.
	 * @return array {provider:string, model:string} — empty strings mean "use default".
	 */
	protected function route_for( $operation ) {
		$key = $this->route_key_for( $operation );
		if ( '' === $key ) {
			return array( 'provider' => '', 'model' => '' );
		}
		$settings = get_option( 'scc_settings', array() );
		return array(
			'provider' => isset( $settings[ "route_{$key}_provider" ] ) ? (string) $settings[ "route_{$key}_provider" ] : '',
			'model'    => isset( $settings[ "route_{$key}_model" ] ) ? (string) $settings[ "route_{$key}_model" ] : '',
		);
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
			return isset( $settings['gemini_model'] ) ? $settings['gemini_model'] : 'gemini-flash-latest';
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
		// AI calls (especially local models) can take a while; lift PHP's
		// execution cap where the host allows it so we return a clean result
		// instead of a killed request. Best-effort — some hosts disable this.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

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

		// Per-operation routing override (e.g. use Gemini for the topical map).
		$route         = $this->route_for( $operation );
		$forced_model  = '';
		$default_prov  = isset( $settings['default_provider'] ) ? $settings['default_provider'] : 'claude';
		if ( ! empty( $route['provider'] ) && $this->get_provider( $route['provider'] ) ) {
			$primary      = $route['provider'];
			$forced_model = $route['model']; // may be '' -> use that provider's default model.
		} else {
			$primary = $default_prov;
		}
		$fallback = isset( $settings['fallback_provider'] ) ? $settings['fallback_provider'] : '';

		$order = array( $primary );
		if ( $fallback && $fallback !== $primary ) {
			$order[] = $fallback;
		}

		// Safety net: if the chosen provider(s) fail, try any other provider the
		// user has actually configured. This way a connected provider (e.g. a
		// local LM Studio server) is still used even if the primary provider is
		// left on a default with a missing or invalid key.
		foreach ( array_keys( $this->providers ) as $pid ) {
			if ( ! in_array( $pid, $order, true ) ) {
				$order[] = $pid;
			}
		}

		$last          = new SCC_AI_Response();
		$tried         = array();
		$configured    = array();
		$first_error   = null;
		foreach ( $order as $pid ) {
			$provider = $this->get_provider( $pid );
			if ( ! $provider || ! $provider->is_configured() ) {
				continue;
			}
			$configured[] = $pid;
			$req = $request;
			if ( empty( $req['model'] ) ) {
				// Use the forced model only for the routed primary provider.
				$req['model'] = ( $pid === $primary && '' !== $forced_model ) ? $forced_model : $this->model_for( $pid );
			}
			$response = $provider->complete( $req );
			SCC_AI_Usage::record( $response, $operation );
			if ( ! $response->is_error() ) {
				if ( $pid !== $primary ) {
					SCC_Logger::info( 'ai-manager', 'Primary provider unavailable; used a configured fallback', array( 'primary' => $primary, 'used' => $pid ) );
				}
				return $response;
			}
			$tried[] = $pid;
			// Report the first (primary) provider's error, not a later safety-net one.
			if ( null === $first_error ) {
				$first_error = $response;
			}
			$last = $response;
			SCC_Logger::error( 'ai-manager', 'Provider failed, trying next configured provider if available', array( 'provider' => $pid ) );
		}
		if ( null !== $first_error ) {
			$last = $first_error;
		}

		if ( ! $last->is_error() ) {
			$last->error = new WP_Error( 'scc_no_provider', __( 'No AI provider is configured. Add an API key under API Connections, or connect LM Studio, then choose it as your Primary provider under Settings → AI.', 'seo-command-center' ), array( 'status' => 400 ) );
		} elseif ( $last->is_error() ) {
			// Sharpen the message so the user knows which provider rejected the
			// request and what to do next (the raw "invalid x-api-key" is opaque).
			$failed = ! empty( $tried ) ? $tried[0] : $primary;
			$labels = array(
				'claude'   => 'Anthropic Claude',
				'openai'   => 'OpenAI',
				'gemini'   => 'Google Gemini',
				'lmstudio' => 'LM Studio',
			);
			$name = isset( $labels[ $failed ] ) ? $labels[ $failed ] : $failed;
			$msg  = $last->error->get_error_message();
			$hint = sprintf(
				/* translators: 1: provider name, 2: original error message */
				__( 'Your primary AI provider (%1$s) failed: %2$s — Under Settings → AI, set “Primary provider” to a provider you have connected (for example LM Studio), or fix that provider’s key under API Connections.', 'seo-command-center' ),
				$name,
				$msg
			);
			$last->error = new WP_Error( $last->error->get_error_code(), $hint, $last->error->get_error_data() );
		}
		return $last;
	}
}
