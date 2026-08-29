<?php
/**
 * Anchor text engine.
 *
 * Produces natural anchor candidates for a destination and picks one that:
 *  - actually appears in the source sentence (so the link reads naturally),
 *  - is not an over-used exact-match anchor across the site,
 *  - varies from anchors already pointing at the destination.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anchor engine.
 */
class SCC_Anchor_Engine {

	/**
	 * Candidate anchor phrases for a destination, richest first.
	 *
	 * @param array $target_index Indexed row for the destination.
	 * @return string[]
	 */
	public static function candidates( array $target_index ) {
		$candidates = array();

		$title = trim( (string) ( $target_index['title'] ?? '' ) );
		$kw    = trim( (string) ( $target_index['primary_keyword'] ?? '' ) );

		if ( $kw ) {
			$candidates[] = $kw;
		}
		if ( $title ) {
			// Strip a trailing brand/site suffix after a pipe or dash.
			$clean = preg_split( '/\s*[|\-–—]\s*/', $title );
			$candidates[] = trim( $clean[0] );
			$candidates[] = $title;
		}

		// Descriptive variants from the top content tokens (contextual phrasing).
		$tokens = array_keys( (array) ( $target_index['tokens'] ?? array() ) );
		if ( count( $tokens ) >= 2 ) {
			$candidates[] = $tokens[0] . ' ' . $tokens[1];
		}

		// Normalize + dedupe (case-insensitive), drop empties/too-short.
		$seen = array();
		$out  = array();
		foreach ( $candidates as $c ) {
			$c = trim( preg_replace( '/\s+/', ' ', $c ) );
			$key = strtolower( $c );
			if ( strlen( $c ) < 4 || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = $c;
		}
		return $out;
	}

	/**
	 * How often an exact anchor is already used across the site index.
	 *
	 * @param string $anchor Anchor.
	 * @return int
	 */
	public static function usage_count( $anchor ) {
		$anchor = strtolower( trim( $anchor ) );
		if ( '' === $anchor ) {
			return 0;
		}
		$count = 0;
		foreach ( SCC_Content_Index::all( 3000 ) as $row ) {
			foreach ( (array) $row['anchors'] as $a ) {
				if ( strtolower( trim( $a ) ) === $anchor ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Choose the best anchor that appears naturally in the given text.
	 *
	 * Prefers a candidate already present in the sentence; falls back to the
	 * least-used candidate to avoid exact-match repetition.
	 *
	 * @param array  $target_index Destination index row.
	 * @param string $source_text  Plain source text to search within.
	 * @param array  $used_anchors Anchors already pointing at this destination.
	 * @return array|null {anchor, present:bool}
	 */
	public static function choose( array $target_index, $source_text, array $used_anchors = array() ) {
		$candidates = self::candidates( $target_index );
		if ( empty( $candidates ) ) {
			return null;
		}
		$lc_text = strtolower( $source_text );
		$used    = array_map( 'strtolower', array_map( 'trim', $used_anchors ) );

		// 1) A candidate that already appears in the text and is not already used.
		foreach ( $candidates as $c ) {
			$lc = strtolower( $c );
			if ( false !== strpos( $lc_text, $lc ) && ! in_array( $lc, $used, true ) ) {
				return array( 'anchor' => $c, 'present' => true );
			}
		}
		// 2) Any candidate present in the text (even if used elsewhere).
		foreach ( $candidates as $c ) {
			if ( false !== strpos( $lc_text, strtolower( $c ) ) ) {
				return array( 'anchor' => $c, 'present' => true );
			}
		}
		// 3) Least-used candidate site-wide (link will need a natural sentence).
		usort(
			$candidates,
			function ( $a, $b ) {
				return self::usage_count( $a ) <=> self::usage_count( $b );
			}
		);
		return array( 'anchor' => $candidates[0], 'present' => false );
	}
}
