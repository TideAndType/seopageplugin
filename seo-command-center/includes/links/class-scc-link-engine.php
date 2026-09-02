<?php
/**
 * Advanced internal-link engine.
 *
 * Uses the content index to find genuinely relevant link opportunities in both
 * directions (the new page linking out, and existing pages linking in), with a
 * confidence score, a templated reason, a suggested natural anchor, and the
 * exact sentence where the link belongs. Recommendations are stored in
 * scc_internal_links; nothing is inserted unless the user (or Autopilot at high
 * confidence) applies it.
 *
 * Relevance over quantity: candidates below the medium threshold are ignored.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link engine.
 */
class SCC_Link_Engine {

	/** @var SCC_AI_Manager|null Optional AI manager for AI-assisted linking. */
	protected $ai = null;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager|null $ai AI manager (enables AI-assisted linking).
	 */
	public function __construct( $ai = null ) {
		if ( $ai instanceof SCC_AI_Manager ) {
			$this->ai = $ai;
		}
	}

	/**
	 * Whether AI-assisted internal linking should run: the toggle is on AND an AI
	 * manager is available.
	 *
	 * @return bool
	 */
	public function ai_enabled() {
		return $this->ai instanceof SCC_AI_Manager && (bool) SCC_Settings::get( 'link_ai_enabled', false );
	}

	/**
	 * Confidence thresholds from settings (with sane defaults).
	 *
	 * @return array {high:int, medium:int}
	 */
	public static function thresholds() {
		return array(
			'high'   => (int) SCC_Settings::get( 'link_high_confidence', 80 ),
			'medium' => (int) SCC_Settings::get( 'link_medium_confidence', 55 ),
		);
	}

	/**
	 * Generate link recommendations for a post in both directions.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $store   Persist recommendations to scc_internal_links.
	 * @return array {outbound:array, inbound:array}
	 */
	public function analyze( $post_id, $store = true ) {
		$post_id = (int) $post_id;
		// Ensure the subject is indexed.
		SCC_Content_Index::index_post( $post_id );

		$subject = SCC_Content_Index::get( $post_id );
		if ( ! $subject ) {
			return array( 'outbound' => array(), 'inbound' => array() );
		}

		$thresholds = self::thresholds();
		$others     = SCC_Content_Index::all( 3000 );

		$outbound = array();
		$inbound  = array();

		$subject_text = SCC_Content_Index::get_plain_text( get_post( $post_id ) );

		foreach ( $others as $other ) {
			if ( (int) $other['post_id'] === $post_id ) {
				continue;
			}

			// NEW -> EXISTING: subject links to $other.
			$rel_out = SCC_Content_Index::relevance( $subject, $other );
			if ( $rel_out >= $thresholds['medium'] && ! self::already_links( $subject, (int) $other['post_id'] ) ) {
				$rec = $this->build_rec( $post_id, (int) $other['post_id'], $subject_text, $other, $rel_out, $others );
				if ( $rec ) {
					$outbound[] = $rec;
				}
			}

			// EXISTING -> NEW: $other links to subject.
			$rel_in = SCC_Content_Index::relevance( $other, $subject );
			if ( $rel_in >= $thresholds['medium'] && ! self::already_links( $other, $post_id ) ) {
				$other_text = SCC_Content_Index::get_plain_text( get_post( (int) $other['post_id'] ) );
				$rec = $this->build_rec( (int) $other['post_id'], $post_id, $other_text, $subject, $rel_in, $others );
				if ( $rec ) {
					$inbound[] = $rec;
				}
			}
		}

		// AI-assisted refinement of the OUTBOUND links from this page (one AI call):
		// the model reads the page and picks the most natural anchor and the best
		// targets from our verified candidate list — it never invents a URL.
		if ( $this->ai_enabled() && ! empty( $outbound ) ) {
			$outbound = $this->refine_with_ai( $outbound, $subject_text );
		}

		// Sort by confidence desc.
		$sort = function ( $a, $b ) {
			return $b['confidence'] <=> $a['confidence'];
		};
		usort( $outbound, $sort );
		usort( $inbound, $sort );

		if ( $store ) {
			foreach ( $outbound as $r ) {
				$this->store( $r, 'outbound' );
			}
			foreach ( $inbound as $r ) {
				$this->store( $r, 'inbound' );
			}
		}

		return array( 'outbound' => $outbound, 'inbound' => $inbound );
	}

	/**
	 * Build a single recommendation (anchor, sentence, confidence, reason).
	 *
	 * @param int    $source_id   Source post id (does the linking).
	 * @param int    $target_id   Target post id (linked to).
	 * @param string $source_text Source plain text.
	 * @param array  $target_idx  Target index row.
	 * @param float  $relevance   Relevance score.
	 * @param array  $all_rows    Preloaded index rows (avoids re-querying).
	 * @return array|null
	 */
	protected function build_rec( $source_id, $target_id, $source_text, array $target_idx, $relevance, array $all_rows = array() ) {
		$used   = self::anchors_pointing_at( $target_id, $all_rows );
		$anchor = SCC_Anchor_Engine::choose( $target_idx, $source_text, $used );
		if ( ! $anchor ) {
			return null;
		}

		$sentence = $anchor['present'] ? $this->sentence_with( $source_text, $anchor['anchor'] ) : '';

		// Confidence: relevance, boosted when a natural placement exists.
		$confidence = (int) round( $relevance );
		if ( $anchor['present'] && '' !== $sentence ) {
			$confidence = min( 100, $confidence + 8 );
		} else {
			$confidence = max( 0, $confidence - 15 ); // harder to place naturally.
		}

		return array(
			'source_post_id' => (int) $source_id,
			'target_post_id' => (int) $target_id,
			'target_title'   => $target_idx['title'] ?? get_the_title( $target_id ),
			'target_url'     => $target_idx['url'] ?? get_permalink( $target_id ),
			'anchor'         => $anchor['anchor'],
			'natural'        => $anchor['present'],
			'sentence'       => $sentence,
			'confidence'     => $confidence,
			'relevance'      => $relevance,
			'reason'         => $this->reason( $source_id, $target_idx, $relevance ),
		);
	}

	/**
	 * AI-assisted refinement of a page's outbound link candidates.
	 *
	 * The model is given the page's own text plus the verified candidate targets
	 * (id + title + keyword). It returns, per link it endorses, the most natural
	 * anchor phrase copied verbatim from the page, a confidence, and a short
	 * reason. We then: (1) accept only ids that were in the candidate list — the
	 * AI can never introduce a page or URL of its own; (2) accept an AI anchor
	 * only if it literally appears in the page text; otherwise we keep the
	 * deterministic anchor. Candidates the AI omits stay at a reduced confidence
	 * rather than being dropped, so the deterministic safety net is preserved.
	 *
	 * @param array  $recs        Deterministic outbound recommendations.
	 * @param string $source_text The page's plain text.
	 * @return array Refined recommendations.
	 */
	protected function refine_with_ai( array $recs, $source_text ) {
		$candidates = array();
		$by_id      = array();
		foreach ( $recs as $rec ) {
			$id                = (int) $rec['target_post_id'];
			$by_id[ $id ]      = $rec;
			$candidates[]      = array(
				'id'      => $id,
				'title'   => (string) $rec['target_title'],
				'keyword' => (string) ( SCC_Content_Index::get( $id )['primary_keyword'] ?? '' ),
			);
		}

		$text = mb_substr( wp_strip_all_tags( (string) $source_text ), 0, 6000 );

		$system = 'You are an internal-linking editor. You are given the FULL TEXT of one web page and a list '
			. 'of CANDIDATE target pages on the same site (each with an id, title and keyword). Choose which '
			. 'candidates this page should link to, and for each, pick the most NATURAL anchor phrase that appears '
			. 'VERBATIM in the page text (copy it exactly, 2-6 words, not a whole sentence, never inside a heading). '
			. 'Only use ids from the candidate list — never invent a page or URL. Prefer relevance over quantity; '
			. 'it is fine to endorse only a few. Give each a confidence 0-100 and a one-line reason. '
			. 'Return JSON: {"links":[{"id":int,"anchor":str,"confidence":int,"reason":str}]}';

		$payload = wp_json_encode( array( 'page_text' => $text, 'candidates' => $candidates ) );

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array( 'role' => 'user', 'content' => "Page + candidates (JSON):\n" . $payload . "\n\nReturn the internal-link JSON now." ),
				),
				'json'        => true,
				'max_tokens'  => 1400,
				'temperature' => 0.3,
			),
			'internal-linking'
		);
		if ( $response->is_error() ) {
			// AI unavailable: fall back to the deterministic recommendations.
			SCC_Logger::info( 'link-engine', 'AI link refinement failed; using deterministic recs', array( 'error' => $response->error->get_error_message() ) );
			return $recs;
		}

		$parsed = $response->json();
		if ( ! is_array( $parsed ) || empty( $parsed['links'] ) ) {
			return $recs;
		}

		return self::merge_ai_links( $recs, (array) $parsed['links'], (string) $source_text );
	}

	/**
	 * Merge the AI's endorsed links back onto the deterministic recommendations,
	 * enforcing the two safety rules that keep AI linking honest:
	 *
	 *   1. Only ids already in the candidate set are accepted — the AI can never
	 *      introduce a page or URL of its own.
	 *   2. An AI anchor is accepted only if it appears VERBATIM in the page text;
	 *      otherwise the deterministic anchor is kept.
	 *
	 * Candidates the AI does not endorse are retained at a reduced confidence, so
	 * the deterministic safety net is never silently lost. Pure function (no AI /
	 * DB), so it is directly unit-tested.
	 *
	 * @param array  $recs        Deterministic recommendations.
	 * @param array  $ai_links    AI links [{id, anchor, confidence, reason}].
	 * @param string $source_text The page's plain text.
	 * @return array
	 */
	public static function merge_ai_links( array $recs, array $ai_links, $source_text ) {
		$by_id = array();
		foreach ( $recs as $rec ) {
			$by_id[ (int) $rec['target_post_id'] ] = $rec;
		}

		$endorsed = array();
		foreach ( $ai_links as $link ) {
			$id = (int) ( $link['id'] ?? 0 );
			if ( ! isset( $by_id[ $id ] ) ) {
				continue; // Never accept a target the AI invented.
			}
			$rec    = $by_id[ $id ];
			$anchor = trim( (string) ( $link['anchor'] ?? '' ) );

			// Accept the AI anchor only if it genuinely appears in the page.
			if ( '' !== $anchor && false !== stripos( (string) $source_text, $anchor ) ) {
				$rec['anchor']  = $anchor;
				$rec['natural'] = true;
			}

			$conf = isset( $link['confidence'] ) ? (int) $link['confidence'] : $rec['confidence'];
			$rec['confidence'] = max( 0, min( 100, $conf ) );

			$reason = trim( (string) ( $link['reason'] ?? '' ) );
			if ( '' !== $reason && class_exists( 'SCC_Security' ) ) {
				$rec['reason'] = SCC_Security::sanitize_text( $reason );
			} elseif ( '' !== $reason ) {
				$rec['reason'] = $reason;
			}
			$rec['ai'] = true;

			$endorsed[ $id ] = $rec;
		}

		// Keep any candidate the AI did not endorse, at a reduced confidence.
		foreach ( $by_id as $id => $rec ) {
			if ( isset( $endorsed[ $id ] ) ) {
				continue;
			}
			$rec['confidence'] = max( 0, (int) $rec['confidence'] - 15 );
			$endorsed[ $id ]   = $rec;
		}

		return array_values( $endorsed );
	}

	/**
	 * A templated, human reason for the link.
	 *
	 * @param int   $source_id  Source id.
	 * @param array $target_idx Target index row.
	 * @param float $relevance  Relevance.
	 * @return string
	 */
	protected function reason( $source_id, array $target_idx, $relevance ) {
		$intent = strtolower( (string) ( $target_idx['intent'] ?? '' ) );
		if ( in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ) {
			return __( 'Directly relevant commercial/service page for this topic.', 'seo-command-center' );
		}
		$source = SCC_Content_Index::get( $source_id );
		if ( $source && ! empty( $target_idx['primary_keyword'] ) ) {
			$kw = strtolower( $target_idx['primary_keyword'] );
			foreach ( array_filter( explode( ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', $kw ) ) ) as $w ) {
				if ( strlen( $w ) >= 3 && isset( $source['tokens'][ $w ] ) ) {
					/* translators: %s: keyword */
					return sprintf( __( 'Closely related to “%s”, a topic this page discusses.', 'seo-command-center' ), $target_idx['primary_keyword'] );
				}
			}
		}
		if ( $relevance >= 80 ) {
			return __( 'Strong topical overlap between the two pages.', 'seo-command-center' );
		}
		return __( 'Contextually related content that helps readers go deeper.', 'seo-command-center' );
	}

	/**
	 * Find the sentence in $text that contains $phrase.
	 *
	 * @param string $text   Text.
	 * @param string $phrase Phrase.
	 * @return string
	 */
	protected function sentence_with( $text, $phrase ) {
		$phrase = strtolower( trim( $phrase ) );
		if ( '' === $phrase ) {
			return '';
		}
		$sentences = preg_split( '/(?<=[.!?])\s+/', (string) $text );
		foreach ( (array) $sentences as $sentence ) {
			if ( false !== strpos( strtolower( $sentence ), $phrase ) ) {
				return trim( $sentence );
			}
		}
		return '';
	}

	/**
	 * Whether the source index already links to a target id.
	 *
	 * @param array $source_idx Source index row.
	 * @param int   $target_id  Target id.
	 * @return bool
	 */
	protected static function already_links( array $source_idx, $target_id ) {
		return in_array( (int) $target_id, array_map( 'intval', (array) ( $source_idx['outbound'] ?? array() ) ), true );
	}

	/**
	 * Anchors already pointing at a destination (site-wide).
	 *
	 * @param int   $target_id Target id.
	 * @param array $all_rows  Preloaded index rows; loaded if empty.
	 * @return string[]
	 */
	protected static function anchors_pointing_at( $target_id, array $all_rows = array() ) {
		$rows    = $all_rows ? $all_rows : SCC_Content_Index::all( 3000 );
		$anchors = array();
		foreach ( $rows as $row ) {
			if ( in_array( (int) $target_id, array_map( 'intval', (array) $row['outbound'] ), true ) ) {
				$anchors = array_merge( $anchors, (array) $row['anchors'] );
			}
		}
		return $anchors;
	}

	/**
	 * Persist a recommendation if not already stored.
	 *
	 * @param array  $rec       Recommendation.
	 * @param string $direction outbound|inbound.
	 * @return int|false
	 */
	protected function store( array $rec, $direction ) {
		global $wpdb;
		$table = SCC_DB::table( 'internal_links' );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM {$table} WHERE source_post_id = %d AND target_post_id = %d AND status IN ('recommended','applied')",
			$rec['source_post_id'],
			$rec['target_post_id']
		) );
		if ( $exists > 0 ) {
			return false;
		}
		return SCC_DB::insert(
			'internal_links',
			array(
				'source_post_id' => $rec['source_post_id'],
				'target_post_id' => $rec['target_post_id'],
				'anchor'         => $rec['anchor'],
				'context'        => $rec['natural'] ? 'natural' : 'needs-sentence',
				'direction'      => $direction,
				'confidence'     => $rec['confidence'],
				'reason'         => $rec['reason'],
				'sentence'       => $rec['sentence'],
				'status'         => 'recommended',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Scan the whole site for internal-link opportunities.
	 *
	 * @param int $limit Max posts to consider.
	 * @return array {opportunities:int}
	 */
	public function scan_site( $limit = 500 ) {
		$rows  = SCC_Content_Index::all( $limit );
		$total = 0;
		foreach ( $rows as $row ) {
			$result = $this->analyze( (int) $row['post_id'], true );
			$total += count( $result['outbound'] );
		}
		SCC_Logger::info( 'link-engine', 'Site scan complete', array( 'opportunities' => $total ) );
		return array( 'opportunities' => $total );
	}

	/**
	 * Find internal-link opportunities for a content object BEFORE it is saved
	 * (operates on the content object, renderer-independent). Returns high-value,
	 * naturally-placeable links to existing pages.
	 *
	 * @param SCC_Content_Object $content Content object.
	 * @param int                $limit   Max links.
	 * @return array List of {target_id, target_url, anchor, confidence, reason}.
	 */
	public function opportunities_for_content( SCC_Content_Object $content, $limit = 5 ) {
		$text = wp_strip_all_tags( $content->content . ' ' . $content->intro );

		$subject = array(
			'title'           => $content->title,
			'primary_keyword' => $content->primary_keyword,
			'intent'          => $content->search_intent,
			'url'             => '',
			'tokens'          => SCC_Content_Index::tokenize( $content->title . ' ' . $text ),
		);

		$high  = self::thresholds()['high'];
		$out   = array();
		$rows  = SCC_Content_Index::all( 3000 );

		foreach ( $rows as $other ) {
			$rel = SCC_Content_Index::relevance( $subject, $other );
			if ( $rel < $high ) {
				continue;
			}
			$anchor = SCC_Anchor_Engine::choose( $other, $text, self::anchors_pointing_at( (int) $other['post_id'], $rows ) );
			if ( ! $anchor || ! $anchor['present'] ) {
				continue; // Only insert where a natural anchor already exists.
			}
			$out[] = array(
				'target_id'  => (int) $other['post_id'],
				'target_url' => $other['url'],
				'anchor'     => $anchor['anchor'],
				'confidence' => (int) round( $rel ),
				'reason'     => $this->reason( 0, $other, $rel ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * List stored recommendations, optionally filtered.
	 *
	 * @param array $args {direction, min_confidence, status, limit}.
	 * @return array
	 */
	public static function recommendations( array $args = array() ) {
		global $wpdb;
		$table = SCC_DB::table( 'internal_links' );
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'recommended';
		$min    = SCC_Security::sanitize_int( $args['min_confidence'] ?? 0, 0, 100 );
		$limit  = SCC_Security::sanitize_int( $args['limit'] ?? 300, 1, 2000 );

		$where = $wpdb->prepare( 'status = %s AND confidence >= %d', $status, $min );
		if ( ! empty( $args['direction'] ) ) {
			$where .= $wpdb->prepare( ' AND direction = %s', sanitize_key( $args['direction'] ) );
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY confidence DESC LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$sid                    = (int) $row['source_post_id'];
			$row['source_title']    = get_the_title( $sid );
			$row['source_url']      = get_permalink( $sid );
			$row['source_edit_url'] = get_edit_post_link( $sid, 'raw' );
			$row['target_title']    = get_the_title( (int) $row['target_post_id'] );
			$row['target_url']      = get_permalink( (int) $row['target_post_id'] );
		}
		return $rows;
	}
}
