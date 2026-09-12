<?php
/**
 * SEO Copilot — a thin natural-language router over the existing engines.
 *
 * The Copilot does NOT analyze anything itself and NEVER fabricates data. It
 * classifies a plain-language request ("find pages losing traffic", "what should
 * I work on this week?") to an intent, then returns the REAL, already-computed
 * opportunities from {@see SCC_Opportunity_Engine} that match — each one already
 * carrying its reason, evidence, recommended action, score and confidence. When
 * the data an intent needs is not connected (e.g. Google Search Console), it says
 * so explicitly instead of inventing results.
 *
 * This is deliberately reuse-only: one intelligence layer, surfaced
 * conversationally. No new analysis engine, no new data store.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Copilot.
 */
class SCC_Copilot {

	/**
	 * Intent catalogue. Each intent maps a set of keyword cues to the opportunity
	 * TYPES (from SCC_Opportunity_Engine) it surfaces, a human label, and which
	 * data sources it depends on (so we can report what is missing honestly).
	 *
	 * An empty `types` means "all opportunity types" (the general triage intent).
	 *
	 * @return array
	 */
	public static function intents() {
		return array(
			'refresh'         => array(
				'label'   => __( 'Content losing traffic', 'seo-command-center' ),
				'cues'    => array( 'losing traffic', 'lost traffic', 'declin', 'decay', 'refresh', 'outdated', 'update old', 'stale', 'dropping', 'dropped' ),
				'types'   => array( 'content_decay', 'intent_drift' ),
				'needs'   => array( 'gsc' ),
			),
			'striking'        => array(
				'label'   => __( 'Close to ranking (striking distance)', 'seo-command-center' ),
				'cues'    => array( 'close to ranking', 'almost ranking', 'striking distance', 'page 2', 'position 4', 'nearly rank', 'quick win', 'quick-win' ),
				'types'   => array( 'striking_distance' ),
				'needs'   => array( 'gsc' ),
			),
			'keywords'        => array(
				'label'   => __( 'Keyword opportunities', 'seo-command-center' ),
				'cues'    => array( 'keyword', 'queries', 'ranking for', 'search terms' ),
				'types'   => array( 'striking_distance', 'untapped_demand' ),
				'needs'   => array( 'gsc' ),
			),
			'metadata'        => array(
				'label'   => __( 'Metadata & click-through', 'seo-command-center' ),
				'cues'    => array( 'metadata', 'meta ', 'title', 'description', 'ctr', 'click-through', 'click through', 'snippet' ),
				'types'   => array( 'improve_meta', 'striking_distance' ),
				'needs'   => array(),
			),
			'cannibalization' => array(
				'label'   => __( 'Keyword cannibalization', 'seo-command-center' ),
				'cues'    => array( 'cannibal', 'competing', 'compete', 'same intent', 'same keyword', 'duplicate target' ),
				'types'   => array( 'fix_cannibalization' ),
				'needs'   => array(),
			),
			'links'           => array(
				'label'   => __( 'Internal link opportunities', 'seo-command-center' ),
				'cues'    => array( 'internal link', 'internal-link', 'orphan', 'need links', 'add links', 'link opportunit' ),
				'types'   => array( 'fix_orphan' ),
				'needs'   => array(),
			),
			'create'          => array(
				'label'   => __( 'Content to create', 'seo-command-center' ),
				'cues'    => array( 'create', 'write', 'articles i should', 'new page', 'new content', 'missing topic', 'ideas', 'should i create', 'content gap' ),
				'types'   => array( 'untapped_demand', 'missing_topic' ),
				'needs'   => array(),
			),
			'expand'          => array(
				'label'   => __( 'Thin content to expand', 'seo-command-center' ),
				'cues'    => array( 'thin', 'expand', 'too short', 'word count', 'more depth' ),
				'types'   => array( 'expand_content' ),
				'needs'   => array(),
			),
			'triage'          => array(
				'label'   => __( 'Your biggest opportunities', 'seo-command-center' ),
				'cues'    => array( 'biggest', 'what should i', 'this week', 'priorit', 'most important', 'top opportunit', 'where do i start', 'overview' ),
				'types'   => array(),
				'needs'   => array(),
			),
		);
	}

	/**
	 * Classify a query to one intent key. Pure and deterministic (no AI, no
	 * network) so it is predictable and unit-testable. Falls back to "triage".
	 *
	 * @param string $query Natural-language request.
	 * @return string Intent key.
	 */
	public static function classify( $query ) {
		$q = strtolower( trim( (string) $query ) );
		if ( '' === $q ) {
			return 'triage';
		}

		$best       = 'triage';
		$best_score = 0;
		foreach ( self::intents() as $key => $intent ) {
			$score = 0;
			foreach ( (array) $intent['cues'] as $cue ) {
				if ( '' !== $cue && false !== strpos( $q, $cue ) ) {
					// Longer cues are more specific — weight by length.
					$score += strlen( $cue );
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $key;
			}
		}
		return $best;
	}

	/**
	 * Filter a list of opportunities to an intent's types and cap the count.
	 * Pure — unit-tested. An intent with no types returns the top of the list.
	 *
	 * @param array  $opportunities Opportunities (from the engine).
	 * @param array  $types         Allowed types (empty = all).
	 * @param int    $limit         Max results.
	 * @return array
	 */
	public static function filter_opportunities( array $opportunities, array $types, $limit = 8 ) {
		$out = array();
		foreach ( $opportunities as $op ) {
			if ( ! is_array( $op ) ) {
				continue;
			}
			if ( empty( $types ) || in_array( (string) ( $op['type'] ?? '' ), $types, true ) ) {
				$out[] = $op;
			}
		}
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Which required data sources for an intent are NOT available right now.
	 * Returns human-readable, actionable messages — never invents data.
	 *
	 * @param array $needs Required source keys (e.g. ['gsc']).
	 * @return array<int,array{key:string,message:string}>
	 */
	public static function missing_data( array $needs ) {
		$missing = array();
		foreach ( $needs as $need ) {
			if ( 'gsc' === $need && ! self::gsc_connected() ) {
				$missing[] = array(
					'key'     => 'gsc',
					'message' => __( 'Connect Google Search Console for this — it needs your real impressions, clicks and positions. Add it under Connections.', 'seo-command-center' ),
				);
			}
		}
		return $missing;
	}

	/**
	 * Whether Search Console is connected (guarded).
	 *
	 * @return bool
	 */
	protected static function gsc_connected() {
		if ( class_exists( 'SCC_GSC' ) && method_exists( 'SCC_GSC', 'is_connected' ) ) {
			return (bool) SCC_GSC::is_connected();
		}
		return false;
	}

	/**
	 * Answer a Copilot request: classify, pull the matching real opportunities,
	 * and report anything missing. Returns a structured, render-ready payload.
	 *
	 * @param string     $query         The user's request.
	 * @param array|null $opportunities Optional pre-fetched opportunities (tests);
	 *                                  defaults to SCC_Opportunity_Engine::all().
	 * @return array {intent,label,what,why,missing,opportunities}
	 */
	public function answer( $query, $opportunities = null ) {
		$intent_key = self::classify( $query );
		$intents    = self::intents();
		$intent     = $intents[ $intent_key ];

		if ( null === $opportunities ) {
			$opportunities = class_exists( 'SCC_Opportunity_Engine' ) ? SCC_Opportunity_Engine::all() : array();
		}
		$opportunities = is_array( $opportunities ) ? $opportunities : array();

		$matched = self::filter_opportunities( $opportunities, (array) $intent['types'], 8 );
		$missing = self::missing_data( (array) $intent['needs'] );

		return array(
			'intent'        => $intent_key,
			'label'         => (string) $intent['label'],
			'what'          => self::summarize( $intent_key, $intent['label'], count( $matched ), $missing ),
			'why'           => self::why( $intent_key ),
			'missing'       => $missing,
			'opportunities' => $matched,
		);
	}

	/**
	 * A one-line "what I found" summary (honest about empties + missing data).
	 *
	 * @param string $intent_key Intent.
	 * @param string $label      Intent label.
	 * @param int    $count      Matches found.
	 * @param array  $missing    Missing-data messages.
	 * @return string
	 */
	protected static function summarize( $intent_key, $label, $count, array $missing ) {
		if ( $count > 0 ) {
			return sprintf(
				/* translators: 1: count, 2: intent label */
				_n( 'Found %1$d opportunity for %2$s.', 'Found %1$d opportunities for %2$s.', $count, 'seo-command-center' ),
				$count,
				strtolower( $label )
			);
		}
		if ( ! empty( $missing ) ) {
			return __( 'I can’t answer this yet — some data isn’t connected. See below.', 'seo-command-center' );
		}
		return __( 'Nothing to act on here right now. Run a fresh analysis (and connect Search Console) for sharper results.', 'seo-command-center' );
	}

	/**
	 * A short "why it matters" line per intent.
	 *
	 * @param string $intent_key Intent.
	 * @return string
	 */
	protected static function why( $intent_key ) {
		$why = array(
			'refresh'         => __( 'Recovering traffic you already earned is faster and cheaper than winning it from scratch.', 'seo-command-center' ),
			'striking'        => __( 'Pages already on page 1–2 need only a small push to win real clicks.', 'seo-command-center' ),
			'keywords'        => __( 'Real Search Console demand shows exactly where a little work converts to traffic.', 'seo-command-center' ),
			'metadata'        => __( 'Better titles and descriptions lift click-through without touching rankings.', 'seo-command-center' ),
			'cannibalization' => __( 'Multiple pages targeting one intent split your ranking signals — consolidating concentrates them.', 'seo-command-center' ),
			'links'           => __( 'Internal links pass relevance and help pages get found and ranked.', 'seo-command-center' ),
			'create'          => __( 'Filling real gaps in demand and your topical map builds authority.', 'seo-command-center' ),
			'expand'          => __( 'Depth that matches search intent improves relevance and rankings.', 'seo-command-center' ),
			'triage'          => __( 'These are the highest-value actions across your whole site, ranked by an explainable score.', 'seo-command-center' ),
		);
		return isset( $why[ $intent_key ] ) ? $why[ $intent_key ] : $why['triage'];
	}
}
