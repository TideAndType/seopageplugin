<?php
/**
 * Topic Coverage / Content Gap diagnostics.
 *
 * Compares finished copy to the concepts and questions in the Page Brain plan.
 * This is coverage analysis, not keyword-density scoring. Full Brief mode can
 * enrich the plan with AI/site context; competitor research remains available
 * through the existing Competitor Gaps workflow.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Topic_Coverage {

	public static function analyze_text( $text, array $plan ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$topics = array_values( array_unique( array_filter( array_merge(
			(array) ( $plan['secondary_topics'] ?? array() ),
			(array) ( $plan['entities'] ?? array() )
		) ) ) );
		$questions = array_values( array_unique( array_filter( (array) ( $plan['questions_to_answer'] ?? array() ) ) );

		$topic_rows = array();
		foreach ( $topics as $topic ) {
			$covered = self::concept_covered( $text, (string) $topic, 0.65 );
			$topic_rows[] = array( 'topic' => (string) $topic, 'covered' => $covered );
		}

		$question_rows = array();
		foreach ( $questions as $question ) {
			$covered = self::concept_covered( $text, (string) $question, 0.45 );
			$question_rows[] = array( 'question' => (string) $question, 'covered' => $covered );
		}

		$topic_pct = self::pct( $topic_rows, 'covered' );
		$question_pct = self::pct( $question_rows, 'covered' );
		if ( empty( $topics ) && empty( $questions ) ) {
			$score = 100;
		} elseif ( empty( $topics ) ) {
			$score = $question_pct;
		} elseif ( empty( $questions ) ) {
			$score = $topic_pct;
		} else {
			$score = (int) round( 0.6 * $topic_pct + 0.4 * $question_pct );
		}

		return array(
			'score' => $score,
			'topic_coverage' => $topic_pct,
			'question_coverage' => $question_pct,
			'topics' => $topic_rows,
			'questions' => $question_rows,
			'missing_topics' => array_values( array_map(
				function ( $row ) { return $row['topic']; },
				array_filter( $topic_rows, function ( $row ) { return empty( $row['covered'] ); } )
			) ),
			'missing_questions' => array_values( array_map(
				function ( $row ) { return $row['question']; },
				array_filter( $question_rows, function ( $row ) { return empty( $row['covered'] ); } )
			) ),
		);
	}

	public static function for_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return array( 'score' => 0, 'missing_topics' => array(), 'missing_questions' => array() );
		}
		$plan = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();
		return self::analyze_text( $post->post_content, $plan );
	}

	protected static function concept_covered( $text, $concept, $threshold ) {
		$concept = trim( wp_strip_all_tags( (string) $concept ) );
		if ( '' === $concept ) { return true; }
		if ( false !== stripos( $text, $concept ) ) { return true; }

		$need = array_keys( SCC_Content_Index::tokenize( $concept ) );
		if ( empty( $need ) ) { return true; }
		$have = SCC_Content_Index::tokenize( $text );
		$hits = 0;
		foreach ( $need as $token ) {
			if ( isset( $have[ $token ] ) ) { $hits++; }
		}
		return ( $hits / count( $need ) ) >= (float) $threshold;
	}

	protected static function pct( array $rows, $field ) {
		if ( empty( $rows ) ) { return 100; }
		$hit = 0;
		foreach ( $rows as $row ) {
			if ( ! empty( $row[ $field ] ) ) { $hit++; }
		}
		return (int) round( 100 * $hit / count( $rows ) );
	}
}
