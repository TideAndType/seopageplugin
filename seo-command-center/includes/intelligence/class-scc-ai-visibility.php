<?php
/**
 * AI Search / GEO Visibility (provider-agnostic scaffold).
 *
 * Monitoring whether a site is cited in AI-generated answers (Google AI
 * Overviews, ChatGPT, Perplexity, Gemini, Copilot) requires a data source /
 * integration this plugin does not have. Rather than FABRICATE AI-visibility
 * metrics, this module exposes an honest, provider-agnostic status: each provider
 * reports "not connected" and there is no visibility data until a real
 * integration is wired via the `scc_ai_visibility_providers` filter.
 *
 * It also surfaces the on-page factors that genuinely help a page be cited as a
 * source — these ARE derived from real site data (schema, FAQ/answers, headings)
 * and are actionable today.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI visibility.
 */
class SCC_AI_Visibility {

	const PROVIDERS = array( 'google_ai_overviews', 'chatgpt', 'perplexity', 'gemini', 'copilot' );

	/**
	 * Provider connection status. Honest by default — nothing is connected and no
	 * data is invented. A real integration registers itself via the filter.
	 *
	 * @return array
	 */
	public static function status() {
		$labels = array(
			'google_ai_overviews' => __( 'Google AI Overviews', 'seo-command-center' ),
			'chatgpt'             => __( 'ChatGPT', 'seo-command-center' ),
			'perplexity'          => __( 'Perplexity', 'seo-command-center' ),
			'gemini'              => __( 'Gemini', 'seo-command-center' ),
			'copilot'             => __( 'Microsoft Copilot', 'seo-command-center' ),
		);
		$providers = array();
		foreach ( self::PROVIDERS as $key ) {
			$providers[] = array(
				'key'       => $key,
				'label'     => $labels[ $key ] ?? $key,
				'connected' => false,
				'state'     => 'unavailable',
				'message'   => __( 'Not connected — no visibility data available.', 'seo-command-center' ),
			);
		}
		/**
		 * Allow a real AI-visibility integration to supply provider data.
		 * Implementations MUST return measured data or leave state "unavailable" —
		 * never fabricated metrics.
		 *
		 * @param array $providers Provider status list.
		 */
		$providers = apply_filters( 'scc_ai_visibility_providers', $providers );

		$any_connected = false;
		foreach ( (array) $providers as $p ) {
			if ( ! empty( $p['connected'] ) ) {
				$any_connected = true;
				break;
			}
		}

		return array(
			'connected'   => $any_connected,
			'providers'   => $providers,
			'readiness'   => self::citation_readiness(),
			'disclaimer'  => __( 'AI-answer visibility requires a monitoring integration. Until one is connected, no visibility numbers are shown — the readiness factors below are what you can improve today.', 'seo-command-center' ),
		);
	}

	/**
	 * On-page "citation readiness" factors, derived from REAL site data — the
	 * things that make a page more likely to be cited as a source.
	 *
	 * @return array
	 */
	protected static function citation_readiness() {
		$factors = array();

		// Schema coverage + FAQ presence from the latest analysis (measured).
		$schema_pct = null;
		$analyzed   = 0;
		if ( class_exists( 'SCC_Analyzer' ) ) {
			$latest = SCC_Analyzer::latest();
			$totals = ( $latest && ! empty( $latest['summary_data']['totals'] ) ) ? $latest['summary_data']['totals'] : array();
			$analyzed = (int) ( $totals['analyzed'] ?? 0 );
			if ( $analyzed > 0 ) {
				$schema_pct = (int) round( 100 * min( $analyzed, (int) ( $totals['has_schema'] ?? 0 ) ) / $analyzed );
			}
		}

		$factors[] = array(
			'key'   => 'structured_data',
			'label' => __( 'Structured data (schema) coverage', 'seo-command-center' ),
			'known' => null !== $schema_pct,
			'pct'   => (int) $schema_pct,
			'hint'  => __( 'Schema helps AI engines extract facts confidently.', 'seo-command-center' ),
		);
		$factors[] = array(
			'key'   => 'direct_answers',
			'label' => __( 'Direct answers & FAQs', 'seo-command-center' ),
			'known' => false,
			'pct'   => 0,
			'hint'  => __( 'Pages that answer questions concisely (with FAQ schema) are cited more often.', 'seo-command-center' ),
		);
		$factors[] = array(
			'key'   => 'entity_clarity',
			'label' => __( 'Entity clarity', 'seo-command-center' ),
			'known' => false,
			'pct'   => 0,
			'hint'  => __( 'Clear, consistent entities (see the Entity Graph) make you a more citable source.', 'seo-command-center' ),
		);

		return $factors;
	}
}
