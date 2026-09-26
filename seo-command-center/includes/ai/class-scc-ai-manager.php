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
	 * Resolve the max_tokens budget for an AI call, giving every feature enough
	 * headroom that a "reasoning" local model (e.g. Qwen3, which spends tokens on
	 * an internal <think> phase before it answers) does not exhaust the budget
	 * before it writes anything and return an empty completion.
	 *
	 * - When the user turns on "Unlimited output tokens", returns -1 (LM Studio
	 *   runs to completion; hosted providers apply their own high ceiling).
	 * - Otherwise returns the caller's default raised to a sane floor so thinking
	 *   tokens never crowd out the answer.
	 *
	 * Raising a ceiling is always safe: models still stop when the answer is
	 * complete, and hosted providers bill only for tokens actually produced.
	 *
	 * @param int $default The task's natural output size.
	 * @return int max_tokens to pass to complete() (-1 = unlimited).
	 */
	public static function token_budget( $default ) {
		if ( class_exists( 'SCC_Settings' ) && SCC_Settings::get( 'generation_unlimited_tokens', false ) ) {
			return -1;
		}
		$default = (int) $default;
		return $default > 0 ? max( $default, 4000 ) : 4000;
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
			'layout_design'      => __( 'Elementor Layout Planning', 'seo-command-center' ),
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
			'layout-design'       => 'layout_design',
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
			$hint   = self::failure_message( $primary, $failed, in_array( $primary, $configured, true ), $last->error->get_error_message() );
			$data   = $last->error->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				// The AI service failed, not WordPress — 502, not a generic 500.
				$data = array_merge( is_array( $data ) ? $data : array(), array( 'status' => 502 ) );
			}
			$last->error = new WP_Error( $last->error->get_error_code(), $hint, $data );
		}
		return $last;
	}

	/**
	 * Explain a failed completion. Names the provider that actually failed and,
	 * when the primary provider was skipped for having no key, says so instead
	 * of blaming the fallback that happened to be tried. Pure — unit-tested.
	 *
	 * @param string $primary            Primary provider id.
	 * @param string $failed             Provider whose error is being reported.
	 * @param bool   $primary_configured Whether the primary provider had a key.
	 * @param string $error              That provider's error message.
	 * @return string
	 */
	public static function failure_message( $primary, $failed, $primary_configured, $error ) {
		$labels = array(
			'claude'   => 'Anthropic Claude',
			'openai'   => 'OpenAI',
			'gemini'   => 'Google Gemini',
			'lmstudio' => 'LM Studio',
		);
		$primary_name = isset( $labels[ $primary ] ) ? $labels[ $primary ] : $primary;
		$failed_name  = isset( $labels[ $failed ] ) ? $labels[ $failed ] : $failed;

		if ( ! $primary_configured && $failed !== $primary ) {
			return sprintf(
				/* translators: 1: primary provider name, 2: fallback provider name, 3: its error message */
				__( 'No API key is set for your primary AI provider (%1$s), so %2$s was tried instead and failed: %3$s — Add your %1$s API key under API Connections, or under Settings → AI set “Primary provider” to a provider you have connected.', 'seo-command-center' ),
				$primary_name,
				$failed_name,
				$error
			);
		}
		return sprintf(
			/* translators: 1: provider name, 2: original error message */
			__( 'Your primary AI provider (%1$s) failed: %2$s — Under Settings → AI, set “Primary provider” to a provider you have connected (for example LM Studio), or fix that provider’s key under API Connections.', 'seo-command-center' ),
			$failed_name,
			$error
		);
	}
}
