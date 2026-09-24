<?php
/**
 * TideScore — diagnostic SEO/page quality model.
 *
 * Unlike keyword-density scores, TideScore focuses on intent coverage, topical
 * completeness, metadata, evidence, internal linking, schema, conversion and
 * structure. It is an editing diagnostic, never a ranking prediction.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_TideScore {

	public static function score_generated( array $piece ) {
		$html  = (string) ( $piece['html'] ?? '' );
		$text  = trim( wp_strip_all_tags( $html ) );
		$brief = (array) ( $piece['brief'] ?? array() );
		$plan  = (array) ( $brief['page_brain'] ?? array() );

		$entities  = (array) ( $plan['entities'] ?? $brief['entities'] ?? array() );
		$questions = (array) ( $plan['questions_to_answer'] ?? $brief['questions'] ?? array() );
		$covered_entities = self::coverage( $text, $entities );
		$covered_questions = self::question_coverage( $text, $questions );

		$factors = array();
		$factors[] = self::factor( 'Intent match', self::intent_score( $text, $brief, $plan ), 20, 'Does the page visibly satisfy the planned search intent?' );
		$factors[] = self::factor( 'Topical coverage', (int) round( ( $covered_entities + $covered_questions ) / 2 ), 20, sprintf( '%d%% entity / %d%% question coverage', $covered_entities, $covered_questions ) );
		$factors[] = self::factor( 'Metadata', self::metadata_score( $piece ), 12, 'Clear title/description with healthy lengths.' );
		$factors[] = self::factor( 'Internal linking', self::link_score( $html ), 12, 'Contextual internal links to real pages.' );
		$factors[] = self::factor( 'Evidence', self::evidence_score( $text, $plan ), 10, 'Uses available first-party proof without requiring invented claims.' );
		$factors[] = self::factor( 'Schema', ! empty( $piece['has_schema'] ) ? 100 : 0, 8, ! empty( $piece['has_schema'] ) ? 'Structured data attached.' : 'No structured data attached.' );
		$factors[] = self::factor( 'Conversion structure', self::cta_score( $piece, $plan ), 8, 'Clear next step where the page intent calls for one.' );
		$factors[] = self::factor( 'Readability / structure', self::structure_score( $html, $text ), 10, 'Scannable headings and manageable content density.' );

		$total = 0;
		$weight = 0;
		foreach ( $factors as $f ) {
			$total += $f['pct'] * $f['weight'];
			$weight += $f['weight'];
		}
		$score = $weight > 0 ? (int) round( $total / $weight ) : 0;

		$issues = array();
		foreach ( $factors as $f ) {
			if ( $f['pct'] < 70 ) {
				$issues[] = array(
					'factor' => $f['label'],
					'severity' => $f['pct'] < 45 ? 'high' : 'medium',
					'note' => $f['note'],
				);
			}
		}

		return array(
			'score'      => $score,
			'factors'    => $factors,
			'issues'     => $issues,
			'disclaimer' => __( 'TideScore is an internal optimization diagnostic, not a Google ranking prediction.', 'seo-command-center' ),
		);
	}

	public static function score_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) { return array( 'score' => 0, 'factors' => array(), 'issues' => array() ); }
		$brief_raw = get_post_meta( $post_id, '_scc_brief', true );
		$brief = is_string( $brief_raw ) ? json_decode( $brief_raw, true ) : array();
		if ( ! is_array( $brief ) ) { $brief = array(); }
		if ( empty( $brief['page_brain'] ) && class_exists( 'SCC_Page_Brain' ) ) {
			$brief['page_brain'] = SCC_Page_Brain::for_post( $post_id );
		}
		$schema = get_post_meta( $post_id, '_scc_schema', true );
		$piece = array(
			'html' => (string) $post->post_content,
			'brief' => $brief,
			'meta_title' => (string) get_post_meta( $post_id, '_scc_meta_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, '_scc_meta_description', true ),
			'has_schema' => '' !== (string) $schema,
			'cta' => (string) ( $brief['cta'] ?? '' ),
		);
		return self::score_generated( $piece );
	}

	protected static function factor( $label, $pct, $weight, $note ) {
		return array(
			'label' => __( $label, 'seo-command-center' ),
			'pct' => max( 0, min( 100, (int) $pct ) ),
			'weight' => (int) $weight,
			'note' => (string) $note,
		);
	}

	protected static function coverage( $text, array $items ) {
		if ( empty( $items ) ) { return 100; }
		$hit = 0;
		foreach ( $items as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( '' !== $item && false !== stripos( $text, $item ) ) { $hit++; }
		}
		return (int) round( 100 * $hit / max( 1, count( $items ) ) );
	}

	protected static function question_coverage( $text, array $questions ) {
		if ( empty( $questions ) ) { return 100; }
		$hit = 0;
		$tokens = array_keys( SCC_Content_Index::tokenize( $text ) );
		foreach ( $questions as $q ) {
			$q_tokens = array_keys( SCC_Content_Index::tokenize( (string) $q ) );
			if ( empty( $q_tokens ) ) { continue; }
			$ratio = count( array_intersect( $tokens, $q_tokens ) ) / count( $q_tokens );
			if ( $ratio >= 0.45 ) { $hit++; }
		}
		return (int) round( 100 * $hit / max( 1, count( $questions ) ) );
	}

	protected static function intent_score( $text, array $brief, array $plan ) {
		$intent = strtolower( (string) ( $plan['primary_intent'] ?? $brief['search_intent'] ?? '' ) );
		$words  = str_word_count( $text );
		if ( '' === $text ) { return 0; }
		if ( in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ) {
			$has_offer = false !== stripos( $text, 'service' ) || false !== stripos( $text, 'contact' ) || false !== stripos( $text, 'schedule' ) || false !== stripos( $text, 'get started' );
			return $has_offer ? 100 : 65;
		}
		return $words >= 500 ? 100 : ( $words >= 250 ? 75 : 45 );
	}

	protected static function metadata_score( array $piece ) {
		$t = trim( (string) ( $piece['meta_title'] ?? '' ) );
		$d = trim( (string) ( $piece['meta_description'] ?? '' ) );
		$score = 0;
		if ( '' !== $t ) { $score += strlen( $t ) <= 65 ? 50 : 35; }
		if ( '' !== $d ) { $score += ( strlen( $d ) >= 70 && strlen( $d ) <= 170 ) ? 50 : 35; }
		return min( 100, $score );
	}

	protected static function link_score( $html ) {
		if ( ! preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\']/i', $html, $m ) ) { return 35; }
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		foreach ( $m[1] as $u ) {
			$host = wp_parse_url( $u, PHP_URL_HOST );
			if ( ! $host || $host === $home || 0 === strpos( $u, '/' ) ) { $internal++; }
		}
		return $internal >= 3 ? 100 : ( $internal >= 1 ? 75 : 35 );
	}

	protected static function evidence_score( $text, array $plan ) {
		$slots = (array) ( $plan['evidence_slots'] ?? array() );
		if ( empty( $slots ) ) { return 100; }
		$hit = 0;
		foreach ( $slots as $slot ) {
			$value = $slot['value'] ?? '';
			if ( is_array( $value ) ) { $value = $value['quote'] ?? ''; }
			$value = trim( (string) $value );
			if ( '' !== $value && false !== stripos( $text, wp_trim_words( $value, 5, '' ) ) ) { $hit++; }
		}
		return min( 100, 55 + (int) round( 45 * $hit / max( 1, count( $slots ) ) ) );
	}

	protected static function cta_score( array $piece, array $plan ) {
		$intent = (string) ( $plan['primary_intent'] ?? '' );
		$needs = in_array( $intent, array( 'commercial', 'transactional', 'local' ), true );
		$has = '' !== trim( wp_strip_all_tags( (string) ( $piece['cta'] ?? '' ) ) );
		if ( ! $needs ) { return $has ? 100 : 90; }
		return $has ? 100 : 35;
	}

	protected static function structure_score( $html, $text ) {
		$words = max( 1, str_word_count( $text ) );
		$h2 = preg_match_all( '/<h2\b/i', $html );
		$p  = preg_match_all( '/<p\b/i', $html );
		$score = 40;
		if ( $h2 >= 2 ) { $score += 30; }
		if ( $p >= 3 ) { $score += 20; }
		if ( $words / max( 1, $h2 + 1 ) < 400 ) { $score += 10; }
		return min( 100, $score );
	}
}
