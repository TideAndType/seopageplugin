<?php
/**
 * Internal Link Autopilot.
 *
 * Reacts to content being created/updated: keeps the index fresh and (when
 * enabled) analyzes the page for link opportunities in both directions,
 * auto-inserting only high-confidence links within the configured caps. Heavy
 * analysis runs in the background job queue so saving a post is never blocked.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autopilot.
 */
class SCC_Autopilot {

	/**
	 * Hook: a post was inserted/updated.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$types = SCC_Analyzer::analyzable_post_types();
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}

		// Always keep the index current (cheap).
		SCC_Content_Index::index_post( $post_id );

		if ( ! (bool) SCC_Settings::get( 'autopilot_enabled', false ) ) {
			return;
		}

		// Defer the heavier link analysis to the background queue.
		SCC_DB::insert(
			'jobs',
			array(
				'type'         => 'link_autopilot',
				'status'       => 'queued',
				'payload'      => wp_json_encode( array( 'post_id' => (int) $post_id ) ),
				'attempts'     => 0,
				'max_attempts' => 2,
				'scheduled_at' => current_time( 'mysql' ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		SCC_Jobs::ensure_scheduled();
	}

	/**
	 * Hook: a post was deleted — drop it from the index.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete_post( $post_id ) {
		SCC_Content_Index::remove( $post_id );
	}

	/**
	 * Run autopilot analysis for a post (called by the job dispatcher).
	 *
	 * @param int $post_id Post id.
	 * @return array {analyzed:bool, inserted:int}
	 */
	public function run_for_post( $post_id ) {
		$engine = new SCC_Link_Engine();
		$result = $engine->analyze( $post_id, true );

		$inserted = 0;
		if ( (bool) SCC_Settings::get( 'autopilot_auto_insert', true ) ) {
			$inserted = $this->auto_insert( $post_id );
		}

		SCC_Logger::info( 'autopilot', 'Analyzed post', array(
			'post_id'  => $post_id,
			'outbound' => count( $result['outbound'] ),
			'inbound'  => count( $result['inbound'] ),
			'inserted' => $inserted,
		) );

		return array( 'analyzed' => true, 'inserted' => $inserted );
	}

	/**
	 * Auto-insert high-confidence recommendations touching this post, within the
	 * per-article cap.
	 *
	 * @param int $post_id Post id.
	 * @return int Number inserted.
	 */
	protected function auto_insert( $post_id ) {
		$high = (int) SCC_Settings::get( 'link_high_confidence', 80 );
		$max  = (int) SCC_Settings::get( 'max_internal_links', 8 );

		global $wpdb;
		$table = SCC_DB::table( 'internal_links' );
		// Recommendations where this post is the SOURCE (outbound) at high confidence.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT id, source_post_id FROM {$table} WHERE status = 'recommended' AND confidence >= %d AND ( source_post_id = %d OR target_post_id = %d ) ORDER BY confidence DESC LIMIT 50",
			$high,
			(int) $post_id,
			(int) $post_id
		), ARRAY_A );

		$inserter   = new SCC_Link_Inserter();
		$inserted   = 0;
		$per_source = array();
		foreach ( (array) $rows as $row ) {
			$src = (int) $row['source_post_id'];
			if ( ! isset( $per_source[ $src ] ) ) {
				$per_source[ $src ] = 0;
			}
			if ( $per_source[ $src ] >= $max ) {
				continue;
			}
			$result = $inserter->apply( (int) $row['id'], 'autopilot' );
			if ( ! is_wp_error( $result ) ) {
				$inserted++;
				$per_source[ $src ]++;
			}
		}
		return $inserted;
	}
}
