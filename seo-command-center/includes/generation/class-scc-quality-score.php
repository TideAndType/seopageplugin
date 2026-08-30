<?php
/**
 * Internal content optimization score.
 *
 * A deterministic 0-100 heuristic across on-page factors. It is explicitly an
 * INTERNAL optimization score to guide editing — it does NOT predict or
 * guarantee Google rankings, and the UI must say so.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quality scorer.
 */
class SCC_Quality_Score {

	/**
	 * Score a generated piece.
	 *
	 * @param array $piece {
	 *     @type string $html            Rendered content HTML.
	 *     @type array  $brief           The brief used.
	 *     @type string $meta_title      Meta title.
	 *     @type string $meta_description Meta description.
	 *     @type array  $faqs            FAQ list.
	 *     @type bool   $has_schema      Whether schema was generated.
	 *     @type string $cta             CTA text.
	 * }
	 * @return array {score:int, factors:array}
	 */
	public static function score( array $piece ) {
		$html    = (string) ( $piece['html'] ?? '' );
		$brief   = (array) ( $piece['brief'] ?? array() );
		$text    = wp_strip_all_tags( $html );
		$words   = str_word_count( $text );
		$target  = (int) ( $brief['recommended_words'] ?? 1000 );
		$factors = array();

		// 1. Length vs. target (20).
		$ratio        = $target > 0 ? min( 1, $words / $target ) : 1;
		$len_points   = (int) round( 20 * $ratio );
		$factors[]    = self::factor( __( 'Content depth', 'seo-command-center' ), $len_points, 20, sprintf( '%d / %d words', $words, $target ) );

		// 2. Heading structure (15).
		$h2 = preg_match_all( '/<h2/i', $html );
		$head_points = $h2 >= 3 ? 15 : (int) round( 15 * ( $h2 / 3 ) );
		$factors[]   = self::factor( __( 'Heading structure', 'seo-command-center' ), $head_points, 15, sprintf( '%d H2 sections', $h2 ) );

		// 3. Keyword presence (15) — primary keyword appears, not stuffed.
		$kw       = strtolower( (string) ( $brief['context']['primary_keyword'] ?? '' ) );
		$kw_count = ( '' !== $kw ) ? substr_count( strtolower( $text ), $kw ) : 0;
		$density  = $words > 0 ? $kw_count / $words : 0;
		if ( $kw_count >= 1 && $density <= 0.03 ) {
			$kw_points = 15;
			$kw_note   = sprintf( 'appears %d× (healthy)', $kw_count );
		} elseif ( $density > 0.03 ) {
			$kw_points = 6;
			$kw_note   = __( 'possible over-optimization', 'seo-command-center' );
		} else {
			$kw_points = 0;
			$kw_note   = __( 'primary keyword not found', 'seo-command-center' );
		}
		$factors[] = self::factor( __( 'Keyword targeting', 'seo-command-center' ), $kw_points, 15, $kw_note );

		// 4. Metadata (15).
		$mt = trim( (string) ( $piece['meta_title'] ?? '' ) );
		$md = trim( (string) ( $piece['meta_description'] ?? '' ) );
		$meta_points = 0;
		if ( '' !== $mt && strlen( $mt ) <= 65 ) {
			$meta_points += 7;
		}
		if ( '' !== $md && strlen( $md ) >= 80 && strlen( $md ) <= 160 ) {
			$meta_points += 8;
		}
		$factors[] = self::factor( __( 'Metadata', 'seo-command-center' ), $meta_points, 15, sprintf( 'title %d chars, desc %d chars', strlen( $mt ), strlen( $md ) ) );

		// 5. FAQ / questions coverage (10).
		$faq_points = ! empty( $piece['faqs'] ) ? 10 : 0;
		$factors[]  = self::factor( __( 'FAQ coverage', 'seo-command-center' ), $faq_points, 10, ! empty( $piece['faqs'] ) ? sprintf( '%d Q&A', count( $piece['faqs'] ) ) : __( 'none', 'seo-command-center' ) );

		// 6. CTA (10).
		$cta_points = ! empty( $piece['cta'] ) ? 10 : 0;
		$factors[]  = self::factor( __( 'Call to action', 'seo-command-center' ), $cta_points, 10, ! empty( $piece['cta'] ) ? __( 'present', 'seo-command-center' ) : __( 'missing', 'seo-command-center' ) );

		// 7. Schema (10).
		$schema_points = ! empty( $piece['has_schema'] ) ? 10 : 0;
		$factors[]     = self::factor( __( 'Structured data', 'seo-command-center' ), $schema_points, 10, ! empty( $piece['has_schema'] ) ? __( 'generated', 'seo-command-center' ) : __( 'none', 'seo-command-center' ) );

		// 8. Entity coverage (5).
		$entities = (array) ( $brief['entities'] ?? array() );
		$covered  = 0;
		foreach ( $entities as $e ) {
			if ( '' !== $e && false !== stripos( $text, $e ) ) {
				$covered++;
			}
		}
		$ent_points = $entities ? (int) round( 5 * ( $covered / count( $entities ) ) ) : 5;
		$factors[]  = self::factor( __( 'Topical coverage', 'seo-command-center' ), $ent_points, 5, $entities ? sprintf( '%d / %d entities', $covered, count( $entities ) ) : __( 'n/a', 'seo-command-center' ) );

		$total = 0;
		$max   = 0;
		foreach ( $factors as $f ) {
			$total += $f['points'];
			$max   += $f['max'];
		}
		$score = $max > 0 ? (int) round( 100 * $total / $max ) : 0;

		return array(
			'score'   => $score,
			'factors' => $factors,
		);
	}

	/**
	 * Build a factor row.
	 *
	 * @param string $label  Label.
	 * @param int    $points Points.
	 * @param int    $max    Max points.
	 * @param string $note   Note.
	 * @return array
	 */
	protected static function factor( $label, $points, $max, $note ) {
		return array(
			'label'  => $label,
			'points' => (int) $points,
			'max'    => (int) $max,
			'note'   => $note,
		);
	}
}
