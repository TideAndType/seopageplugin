<?php
/**
 * AI layout provider (OPTIONAL) — asks the configured AI provider to choose an
 * ordered layout from the CONTROLLED block registry. The AI is given only the
 * content type, intent, keyword, available content summary and the allowed
 * block IDs, and must return a JSON list of block IDs. It can NEVER return
 * Elementor JSON, HTML, PHP or arbitrary structures — anything it returns is
 * validated against the registry and discarded if invalid, so the deterministic
 * provider always remains a safe fallback.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-backed layout provider.
 */
class SCC_Layout_AI_Provider {

	/** @var SCC_AI_Manager|null */
	protected $ai;

	/**
	 * @param SCC_AI_Manager|null $ai AI manager (optional).
	 */
	public function __construct( $ai = null ) {
		$this->ai = $ai;
	}

	/**
	 * Whether an AI provider is available to call.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->ai instanceof SCC_AI_Manager;
	}

	/**
	 * Ask the AI for an ordered list of block IDs. Returns null on any failure so
	 * the caller falls back to the deterministic provider.
	 *
	 * @param array $analysis Analyzed content structure.
	 * @return string[]|null Ordered block IDs, or null.
	 */
	public function decide( array $analysis ) {
		if ( ! $this->is_available() ) {
			return null;
		}

		$allowed = SCC_Block_Registry::ids();
		$catalog = array();
		foreach ( SCC_Block_Registry::all() as $id => $meta ) {
			$catalog[] = array(
				'id'      => $id,
				'purpose' => $meta['purpose'],
				'about'   => $meta['description'],
				'repeat'  => (bool) $meta['repeatable'],
			);
		}

		$counts = (array) ( $analysis['counts'] ?? array() );
		$payload = array(
			'content_type'   => (string) ( $analysis['content_type'] ?? '' ),
			'search_intent'  => (string) ( $analysis['search_intent'] ?? '' ),
			'primary_keyword'=> (string) ( $analysis['primary_keyword'] ?? '' ),
			'available'      => array(
				'intro'    => '' !== (string) ( $analysis['intro'] ?? '' ),
				'sections' => (int) ( $counts['sections'] ?? 0 ),
				'services' => (int) ( $counts['services'] ?? 0 ),
				'benefits' => (int) ( $counts['benefits'] ?? 0 ),
				'process'  => (int) ( $counts['process'] ?? 0 ),
				'stats'    => (int) ( $counts['stats'] ?? 0 ),
				'faqs'     => (int) ( $counts['faqs'] ?? 0 ),
				'related'  => (int) ( $counts['related'] ?? 0 ),
				'is_local' => ! empty( $analysis['is_local'] ),
				'has_image'=> ! empty( $analysis['has_image'] ),
			),
			'allowed_blocks' => $allowed,
			'blocks'         => $catalog,
		);

		$system = 'You are a web layout strategist. Choose the best ORDER of page sections for an SEO page, '
			. 'picking ONLY from allowed_blocks (use each id exactly as given). Base the choice on content_type, '
			. 'search_intent and what content is actually available — never include a block whose content is 0/false. '
			. 'A page should open with "hero" and end with "cta". Do NOT invent blocks, HTML, or Elementor code. '
			. 'Return ONLY JSON of this exact shape: {"layout":["hero","content-intro", ...]} using ids from allowed_blocks.';

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array( 'role' => 'user', 'content' => "Context (JSON):\n" . wp_json_encode( $payload ) . "\n\nReturn the layout JSON now." ),
				),
				'json'        => true,
				'max_tokens'  => SCC_AI_Manager::token_budget( 800 ),
				'temperature' => 0.2,
			),
			'layout-engine'
		);

		if ( $response->is_error() ) {
			return null;
		}
		$parsed = $response->json();
		if ( ! is_array( $parsed ) || empty( $parsed['layout'] ) || ! is_array( $parsed['layout'] ) ) {
			return null;
		}

		// Keep ONLY strings that are registered block IDs. Everything else is
		// silently dropped — the AI can never introduce an unknown block.
		$layout = array();
		foreach ( $parsed['layout'] as $id ) {
			if ( is_string( $id ) ) {
				$id = sanitize_key( $id );
				if ( SCC_Block_Registry::exists( $id ) ) {
					$layout[] = $id;
				}
			}
		}
		return ! empty( $layout ) ? $layout : null;
	}
}
