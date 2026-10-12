<?php
/**
 * Evidence-to-action planner for AI search citations.
 *
 * Reads existing measured snapshots only. No AI requests, no scraping,
 * no live page edits, no auto-publishing, no assumed SEO causation.
 *
 * Approved suggestions can enter TideOrbit's existing Content Plan as
 * "recommended" (human review required), or be linked to an existing
 * WP post/page for manual Elementor/WordPress editing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_AI_Opportunities {
	const STORE = 'scc_ai_evidence_opportunities';
	const LIMIT = 120;

	public static function saved() {
		$v = get_option( self::STORE, array() );
		return is_array( $v ) ? $v : array();
	}
	protected static function persist( array $rows ) {
		uasort( $rows, function ( $a, $b ) {
			return strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) );
		} );
		update_option( self::STORE, array_slice( $rows, 0, self::LIMIT, true ), false );
	}
	public static function for_month( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) { return array(); }
		$out = array();
		foreach ( self::saved() as $row ) {
			if ( is_array( $row ) && ( $row['month'] ?? '' ) === $month ) { $out[] = $row; }
		}
		usort( $out, function ( $a, $b ) {
			$weights = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
			return ( $weights[$b['priority']] ?? 0 ) <=> ( $weights[$a['priority']] ?? 0 );
		} );
		return $out;
	}
	/** A cited domain in the *same* verified answer, never merely retrieved. */
	public static function verified_competitors( array $row ) {
		$out = array();
		// Unknown citation mapping is insufficient for a competitor gap.
		if ( isset( $row['citation_evidence_state'] ) &&
			! in_array( $row['citation_evidence_state'], array( 'verified_grounding_supports', 'verified_citation_markers' ), true ) ) {
			return $out;
		}
		foreach ( (array) ( $row['competitor_citations'] ?? array() ) as $competitor ) {
			if ( ! is_array( $competitor ) || true !== ( $competitor['cited'] ?? null ) ) { continue; }
			$domain = class_exists( 'SCC_AI_Direct_Providers' )
				? SCC_AI_Direct_Providers::validate_domain( (string) ( $competitor['domain'] ?? '' ) ) : '';
			if ( $domain && count( $out ) < 4 && ! in_array( $domain, $out, true ) ) { $out[] = $domain; }
		}
		return $out;
	}
	public static function from_observation( array $row, $site_host ) {
		$surface = sanitize_key( (string) ( $row['surface'] ?? '' ) );
		$month = substr( (string) ( $row['captured_at'] ?? '' ), 0, 7 );
		$question = substr( sanitize_text_field( (string) ( $row['keyword'] ?? '' ) ), 0, 180 );
		$status = (string) ( $row['state'] ?? '' );
		// Indexed dataset aggregates are NOT per-question evidence.
		if ( ! in_array( $status, array( 'measured', 'measured_api_observation' ), true ) ||
			'' === $question || ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', $month ) ||
			! in_array( $surface, array( 'google_ai_overview', 'google_ai_mode', 'gemini_grounded_api', 'perplexity_agent_api' ), true ) ||
			( $row['domain'] ?? '' ) !== $site_host ) {
			return null;
		}
		$domain_cited = $row['domain_cited'] ?? null;
		$competitors = self::verified_competitors( $row );
		$brand_mentioned = ! empty( $row['brand_mentioned'] );
		if ( true === $domain_cited ) { return null; } // Good results are not "gaps".
		if ( false === $domain_cited ) {
			$type = $competitors ? 'competitor_citation_gap' : ( $brand_mentioned ? 'brand_without_citation' : 'uncited_observed_answer' );
			$priority = $competitors ? 'high' : 'medium';
			$headline = $competitors ? 'Review why competitors are cited for this query'
				: ( $brand_mentioned ? 'Review why a brand mention lacks a source citation'
				: 'Review source coverage for this AI question' );
			$steps = array(
				'Check whether an existing page answers the exact question with verifiable, accurate facts.',
				'Compare the cited sources and the query intent; do not claim content is missing without reviewing the site.',
				'If appropriate, update an existing page with useful first-hand evidence, clear headings, FAQs and supporting citations.',
				'Add relevant internal links, then repeat a comparable AI observation to measure changes.',
			);
		} else {
			$type = 'citation_evidence_unverified';
			$priority = 'low';
			$headline = 'Verify whether the AI answer included first-party citations';
			$steps = array(
				'Inspect the provider grounding/citation metadata or run a comparable check.',
				'Do not create a new page or mark the domain absent on uncertain citation evidence.',
			);
		}
		$id = sha1( $site_host . '|' . $surface . '|' . strtolower( $question ) . '|' . (string) ( $row['market'] ?? '' ) . '|' . $month );
		return array(
			'id' => $id, 'month' => $month, 'surface' => $surface,
			'question' => $question, 'market' => substr( sanitize_text_field( (string) ( $row['market'] ?? '' ) ), 0, 120 ),
			'type' => $type, 'priority' => $priority,
			'title' => $headline, 'steps' => $steps,
			'competitor_domains' => $competitors,
			'brand_mentioned' => $brand_mentioned, 'own_domain_cited' => $domain_cited,
			'captured_at' => (string) $row['captured_at'],
			'source' => (string) ( $row['source'] ?? 'unknown' ),
			'evidence_state' => (string) ( $row['citation_evidence_state'] ?? ( false === $domain_cited ? 'source_urls_observed' : 'unknown' ) ),
			'status' => 'proposed', 'review_notes' => '',
			'handoff' => null, 'updated_at' => gmdate( 'c' ),
		);
	}
	/** Derived from saved provider observations; never consumes paid requests. */
	public static function refresh( $month ) {
		if ( ! preg_match( '/^20\d\d-(0[1-9]|1[0-2])$/', (string) $month ) ) {
			return new WP_Error( 'scc_ai_opportunity_month', 'Select a valid report month.', array( 'status' => 400 ) );
		}
		$host = SCC_AI_Citation_Monitor::domain();
		$history = SCC_AI_Citation_Monitor::monthly( $month );
		$candidates = array();
		foreach ( $history as $snapshot ) {
			$recommendation = self::from_observation( (array) $snapshot, $host );
			if ( ! $recommendation ) { continue; }
			$key = $recommendation['id'];
			if ( ! isset( $candidates[$key] ) || strcmp( $recommendation['captured_at'], $candidates[$key]['captured_at'] ) > 0 ) {
				$candidates[$key] = $recommendation;
			}
		}
		$stored = self::saved();
		$added = 0; $updated = 0;
		foreach ( $candidates as $key => $candidate ) {
			if ( ! isset( $stored[$key] ) ) {
				$stored[$key] = $candidate;
				$added++;
			} elseif ( 'proposed' === ( $stored[$key]['status'] ?? '' ) && strcmp( $candidate['captured_at'], (string) ( $stored[$key]['captured_at'] ?? '' ) ) > 0 ) {
				$stored[$key] = $candidate;
				$updated++;
			}
		}
		if ( $added || $updated ) { self::persist( $stored ); }
		return array( 'added' => $added, 'updated' => $updated, 'month' => $month, 'opportunities' => self::for_month( $month ) );
	}
	public static function review( $id, $decision, $notes = '' ) {
		$id = strtolower( trim( (string) $id ) );
		$decision = sanitize_key( (string) $decision );
		if ( ! preg_match( '/^[a-f0-9]{40}$/', $id ) || ! in_array( $decision, array( 'approved', 'dismissed' ), true ) ) {
			return new WP_Error( 'scc_ai_review_params', 'Select a valid opportunity and review action.', array( 'status' => 400 ) );
		}
		$rows = self::saved();
		if ( ! isset( $rows[$id] ) ) { return new WP_Error( 'scc_ai_review_missing', 'Opportunity no longer exists.', array( 'status' => 404 ) ); }
		if ( 'handed_off' === ( $rows[$id]['status'] ?? '' ) ) {
			return new WP_Error( 'scc_ai_review_locked', 'This opportunity was already handed off. Open the Content Plan or existing page.', array( 'status' => 409 ) );
		}
		$rows[$id]['status'] = $decision;
		$rows[$id]['review_notes'] = substr( sanitize_textarea_field( (string) $notes ), 0, 1000 );
		$rows[$id]['updated_at'] = gmdate( 'c' );
		self::persist( $rows );
		return $rows[$id];
	}

	/** Safe handoff: never modify any existing post or auto-publish. */
	public static function handoff( $id, array $payload ) {
		$id = strtolower( trim( (string) $id ) );
		$rows = self::saved();
		if ( ! isset( $rows[$id] ) ) { return new WP_Error( 'scc_ai_handoff_missing', 'Opportunity not found.', array( 'status' => 404 ) ); }
		$entry = $rows[$id];
		if ( 'approved' !== ( $entry['status'] ?? '' ) ) {
			return new WP_Error( 'scc_ai_handoff_approval', 'Approve this opportunity before sending it to a content workflow.', array( 'status' => 409 ) );
		}
		$mode = sanitize_key( (string) ( $payload['mode'] ?? '' ) );
		if ( 'existing' === $mode ) {
			$post_id = max( 0, (int) ( $payload['post_id'] ?? 0 ) );
			$post = $post_id ? get_post( $post_id ) : null;
			if ( ! $post || ! in_array( (string) ( $post->post_type ?? '' ), array( 'page', 'post' ), true ) ||
				! in_array( (string) ( $post->post_status ?? '' ), array( 'publish', 'private', 'draft', 'pending' ), true ) ||
				! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'scc_ai_existing_invalid', 'Choose a post/page you are allowed to edit.', array( 'status' => 400 ) );
			}
			$handoff = array(
				'mode' => 'existing', 'post_id' => $post_id,
				'post_title' => (string) $post->post_title,
				'edit_url' => (string) get_edit_post_link( $post_id, '' ),
				'notice' => 'Existing post is only linked for editorial review; no content or Elementor data was changed.',
			);
		} elseif ( 'content_plan' === $mode ) {
			if ( 'citation_evidence_unverified' === ( $entry['type'] ?? '' ) ) {
				return new WP_Error( 'scc_ai_unverified_content', 'Unverified citation evidence cannot justify creating a new page; first verify or choose an existing page for review.', array( 'status' => 409 ) );
			}
			$title = trim( sanitize_text_field( (string) ( $payload['title'] ?? '' ) ) );
			$path = trim( (string) ( $payload['path'] ?? '' ) );
			$path = preg_replace( '#^/+|/+$#', '', $path );
			if ( strlen( $title ) < 8 || strlen( $title ) > 150 ||
				! preg_match( '#^[a-z0-9]+(?:[a-z0-9/-]*[a-z0-9])?$#', $path ) ||
				false !== strpos( $path, '//' ) || strlen( $path ) > 180 ) {
				return new WP_Error( 'scc_ai_plan_fields', 'Enter a descriptive content title and a valid new lowercase URL slug/path.', array( 'status' => 400 ) );
			}
			if ( ! class_exists( 'SCC_Content_Plan' ) ) { return new WP_Error( 'scc_ai_plan_unavailable', 'Content Plan is unavailable.', array( 'status' => 503 ) ); }
			$slug = '/' . $path . '/';
			if ( get_page_by_path( $path, OBJECT, array( 'post', 'page' ) ) ) {
				return new WP_Error( 'scc_ai_page_exists', 'An existing WordPress page already uses this path; choose existing-page review instead.', array( 'status' => 409 ) );
			}
			$idea = array( 'title' => $title, 'primary_keyword' => $entry['question'], 'url' => $slug );
			if ( SCC_Content_Plan::is_taken( $idea, SCC_Content_Plan::taken_signatures() ) ) {
				return new WP_Error( 'scc_ai_plan_duplicate', 'A similar title, keyword, or URL already exists in Content Plan. Review the existing entry instead.', array( 'status' => 409 ) );
			}
			$created = SCC_Content_Plan::create( array(
				'title' => $title, 'url' => $slug,
				'primary_keyword' => $entry['question'], 'secondary' => array(),
				'intent' => 'informational', 'page_type' => 'article',
				'word_count' => 1200, 'schema_type' => 'BlogPosting',
				'priority' => $entry['priority'] === 'high' ? 'high' : 'medium',
				'status' => 'recommended',
			) );
			if ( ! $created ) {
				return new WP_Error( 'scc_ai_plan_write', 'Could not insert the recommendation into Content Plan.', array( 'status' => 500 ) );
			}
			$handoff = array(
				'mode' => 'content_plan', 'plan_id' => (int) $created,
				'title' => $title, 'url' => $slug,
				'edit_url' => admin_url( 'admin.php?page=seo-command-center-content-plan' ),
				'notice' => 'Content Plan recommendation created. Approve its brief/generation separately. No post, draft, or live page was modified.',
			);
		} else {
			return new WP_Error( 'scc_ai_handoff_mode', 'Choose existing page review or a new Content Plan recommendation.', array( 'status' => 400 ) );
		}
		$rows[$id]['status'] = 'handed_off';
		$rows[$id]['handoff'] = $handoff;
		$rows[$id]['updated_at'] = gmdate( 'c' );
		self::persist( $rows );
		return $rows[$id];
	}
}
