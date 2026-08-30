<?php
/**
 * Keyword cannibalization detector.
 *
 * Flags groups of existing pages that appear to target the same intent, using
 * a deterministic token-overlap heuristic over the latest analysis. Presents
 * recommendation OPTIONS only — it never merges, redirects, or deletes anything.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cannibalization detector.
 */
class SCC_Cannibalization {

	/** Words ignored when comparing titles. */
	const STOP_WORDS = array( 'the', 'a', 'an', 'and', 'or', 'for', 'of', 'in', 'to', 'services', 'service', 'company', 'agency', 'best', 'top', 'your', 'we', 'our' );

	/**
	 * Detect cannibalization groups from the latest analysis.
	 *
	 * @return array List of groups with pages + recommendation options.
	 */
	public function detect() {
		$latest = SCC_Analyzer::latest();
		if ( ! $latest || empty( $latest['items'] ) ) {
			return array();
		}

		// Build a token signature per page.
		$pages = array();
		foreach ( $latest['items'] as $item ) {
			$tokens = $this->tokens( $item['title'] . ' ' . $item['meta_title'] );
			if ( empty( $tokens ) ) {
				continue;
			}
			$pages[] = array(
				'post_id' => (int) $item['post_id'],
				'title'   => $item['title'],
				'url'     => $item['url'],
				'tokens'  => $tokens,
			);
		}

		// Compare pairwise; union pages that share a strong signature.
		$groups = array();
		$used   = array();
		$count  = count( $pages );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( isset( $used[ $i ] ) ) {
				continue;
			}
			$group = array( $pages[ $i ] );
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( isset( $used[ $j ] ) ) {
					continue;
				}
				if ( $this->similar( $pages[ $i ]['tokens'], $pages[ $j ]['tokens'] ) ) {
					$group[]     = $pages[ $j ];
					$used[ $j ]  = true;
				}
			}
			if ( count( $group ) > 1 ) {
				$used[ $i ] = true;
				$groups[]   = $this->format_group( $group );
			}
		}

		return $groups;
	}

	/**
	 * Tokenize a string into significant lowercase words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	protected function tokens( $text ) {
		$text   = strtolower( wp_strip_all_tags( (string) $text ) );
		$text   = preg_replace( '/[^a-z0-9 ]+/', ' ', $text );
		$words  = array_filter( explode( ' ', $text ) );
		$tokens = array();
		foreach ( $words as $w ) {
			if ( strlen( $w ) < 3 || in_array( $w, self::STOP_WORDS, true ) ) {
				continue;
			}
			$tokens[ $w ] = true;
		}
		return array_keys( $tokens );
	}

	/**
	 * Whether two token sets are similar enough to flag (Jaccard >= 0.6).
	 *
	 * @param string[] $a Tokens A.
	 * @param string[] $b Tokens B.
	 * @return bool
	 */
	protected function similar( array $a, array $b ) {
		$intersect = count( array_intersect( $a, $b ) );
		if ( 0 === $intersect ) {
			return false;
		}
		$union = count( array_unique( array_merge( $a, $b ) ) );
		if ( 0 === $union ) {
			return false;
		}
		return ( $intersect / $union ) >= 0.6;
	}

	/**
	 * Format a group with recommendation options.
	 *
	 * @param array $group Pages in the group.
	 * @return array
	 */
	protected function format_group( array $group ) {
		// Shared tokens as the apparent topic.
		$shared = null;
		foreach ( $group as $p ) {
			$shared = ( null === $shared ) ? $p['tokens'] : array_intersect( $shared, $p['tokens'] );
		}
		$topic = implode( ' ', array_slice( (array) $shared, 0, 5 ) );

		return array(
			'topic' => $topic,
			'pages' => array_map(
				function ( $p ) {
					return array(
						'post_id' => $p['post_id'],
						'title'   => $p['title'],
						'url'     => $p['url'],
					);
				},
				$group
			),
			// Options the user may choose — the plugin never acts automatically.
			'options' => array(
				'keep_separate'  => __( 'Keep separate (differentiate the target keyword / intent of each)', 'seo-command-center' ),
				'merge'          => __( 'Merge into one stronger page', 'seo-command-center' ),
				'redirect'       => __( 'Redirect weaker pages to the strongest', 'seo-command-center' ),
				'change_target'  => __( 'Change the target keyword of one page', 'seo-command-center' ),
				'change_intent'  => __( 'Reposition one page to a different search intent', 'seo-command-center' ),
			),
		);
	}
}
