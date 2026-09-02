<?php
/**
 * Unified SEO Action Queue.
 *
 * Opportunities (from SCC_Opportunity_Engine) are transient, computed signals.
 * When the user decides to act on one, it is PROMOTED into this queue as a
 * persistent action with a lifecycle (new → approved → in_progress → completed,
 * or dismissed / snoozed / failed). Every state change and execution is logged.
 *
 * Execution NEVER silently modifies content. Only genuinely SAFE, deterministic
 * actions can be executed here (currently: adding already-recommended internal
 * links, which is reversible via change history). Everything else routes the
 * user to the existing workflow (Content Plan, Meta optimizer, etc.) and stays
 * "approved" until a human completes it.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action queue.
 */
class SCC_Action_Queue {

	const STATUSES = array( 'new', 'reviewing', 'approved', 'in_progress', 'completed', 'dismissed', 'snoozed', 'failed' );

	/** Action types that can be executed safely and deterministically. */
	const SAFE_TYPES = array( 'add_internal_links', 'fix_orphan' );

	/**
	 * List actions, optionally filtered by status.
	 *
	 * @param string $status Status filter (empty = all except dismissed/snoozed in the past).
	 * @param int    $limit  Max rows.
	 * @return array
	 */
	public static function all( $status = '', $limit = 200 ) {
		global $wpdb;
		$table = SCC_DB::table( 'seo_actions' );
		$limit = max( 1, min( 500, (int) $limit ) );

		if ( '' !== $status && in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY score DESC, id DESC LIMIT %d", $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY FIELD(status,'new','reviewing','approved','in_progress','completed','snoozed','dismissed','failed'), score DESC, id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Find one action.
	 *
	 * @param int $id Action id.
	 * @return array|null
	 */
	public static function find( $id ) {
		$row = SCC_DB::get( 'seo_actions', (int) $id );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Promote an opportunity into the queue (idempotent by opportunity_id — an
	 * existing open action for the same opportunity is returned, not duplicated).
	 *
	 * @param array  $opp    Opportunity from the engine.
	 * @param string $status Initial status.
	 * @return int|false Action id.
	 */
	public static function promote( array $opp, $status = 'new' ) {
		$opp_id = (string) ( $opp['id'] ?? '' );
		if ( '' === $opp_id ) {
			return false;
		}
		$status = in_array( $status, self::STATUSES, true ) ? $status : 'new';

		global $wpdb;
		$table = SCC_DB::table( 'seo_actions' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE opportunity_id = %s AND status NOT IN ('dismissed','completed') ORDER BY id DESC LIMIT 1", $opp_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$now = current_time( 'mysql' );
		$id  = SCC_DB::insert(
			'seo_actions',
			array(
				'opportunity_id'  => $opp_id,
				'type'            => (string) ( $opp['action_type'] ?? $opp['type'] ?? 'review' ),
				'title'           => (string) ( $opp['title'] ?? '' ),
				'target'          => wp_json_encode( $opp['target'] ?? array() ),
				'score'           => (int) ( $opp['score'] ?? 0 ),
				'confidence'      => (int) ( $opp['confidence'] ?? 0 ),
				'priority'        => (string) ( $opp['priority'] ?? 'medium' ),
				'reason'          => (string) ( $opp['reason'] ?? '' ),
				'expected_impact' => (string) ( $opp['expected_impact'] ?? '' ),
				'effort'          => (string) ( $opp['effort'] ?? '' ),
				'risk'            => (string) ( $opp['risk'] ?? '' ),
				'status'          => $status,
				'source'          => (string) ( $opp['source'] ?? '' ),
				'payload'         => wp_json_encode( array( 'factors' => $opp['factors'] ?? array(), 'metrics' => $opp['metrics'] ?? array(), 'recommended_action' => $opp['recommended_action'] ?? '' ) ),
				'result'          => null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( $id && class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'action-queue', 'Opportunity promoted to action', array( 'id' => $id, 'type' => $opp['action_type'] ?? '', 'opportunity_id' => $opp_id ) );
		}
		return $id;
	}

	/**
	 * Change an action's status.
	 *
	 * @param int    $id     Action id.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$ok = false !== SCC_DB::update( 'seo_actions', array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		if ( $ok && class_exists( 'SCC_Logger' ) ) {
			SCC_Logger::info( 'action-queue', 'Action status changed', array( 'id' => (int) $id, 'status' => $status ) );
		}
		return $ok;
	}

	/**
	 * Snooze an action for N days (kept out of the "next" list until then).
	 *
	 * @param int $id   Action id.
	 * @param int $days Days.
	 * @return bool
	 */
	public static function snooze( $id, $days = 14 ) {
		$days = max( 1, min( 365, (int) $days ) );
		global $wpdb;
		$table = SCC_DB::table( 'seo_actions' );
		$until = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		$ok = false !== $wpdb->update( $table, array( 'status' => 'snoozed', 'snoozed_until' => $until, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok;
	}

	/**
	 * Whether an action type is safe to execute deterministically.
	 *
	 * @param string $type Action type.
	 * @return bool
	 */
	public static function is_safe( $type ) {
		return in_array( (string) $type, self::SAFE_TYPES, true );
	}

	/**
	 * Execute a SAFE, deterministic action. Refuses anything not on the safe
	 * list — those require a human to complete via the existing workflow.
	 *
	 * @param int $id Action id.
	 * @return array|WP_Error
	 */
	public static function execute( $id ) {
		$action = self::find( $id );
		if ( ! $action ) {
			return new WP_Error( 'scc_no_action', __( 'Action not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_safe( $action['type'] ) ) {
			return new WP_Error( 'scc_not_safe', __( 'This action is not a safe automatic action — complete it from its workflow (Content Plan, Meta, etc.).', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		self::set_status( $id, 'in_progress' );

		$result = self::run_safe( $action );
		if ( is_wp_error( $result ) ) {
			SCC_DB::update( 'seo_actions', array( 'status' => 'failed', 'result' => wp_json_encode( array( 'error' => $result->get_error_message() ) ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
			return $result;
		}

		SCC_DB::update( 'seo_actions', array( 'status' => 'completed', 'result' => wp_json_encode( $result ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		SCC_Opportunity_Engine::flush();
		return $result;
	}

	/**
	 * Run one safe action against the existing execution systems.
	 *
	 * @param array $action Hydrated action.
	 * @return array|WP_Error
	 */
	protected static function run_safe( array $action ) {
		$target  = (array) ( $action['target'] ?? array() );
		$post_id = (int) ( $target['post_id'] ?? 0 );

		switch ( $action['type'] ) {
			case 'add_internal_links':
			case 'fix_orphan':
				if ( $post_id <= 0 || ! class_exists( 'SCC_Link_Engine' ) || ! class_exists( 'SCC_Link_Inserter' ) ) {
					return new WP_Error( 'scc_cannot_run', __( 'No target page for internal linking.', 'seo-command-center' ), array( 'status' => 400 ) );
				}
				// Generate + store recommendations for this page (deterministic path),
				// then apply the high-confidence inbound links — all reversible.
				$engine = new SCC_Link_Engine();
				$engine->analyze( $post_id, true );

				$high = (int) SCC_Settings::get( 'link_high_confidence', 80 );
				$recs = SCC_Link_Engine::recommendations( array( 'min_confidence' => $high, 'limit' => 50 ) );
				$inserter = new SCC_Link_Inserter();
				$applied  = 0;
				$skipped  = 0;
				foreach ( $recs as $rec ) {
					// Only links that point AT this page (fixing the orphan) or FROM it.
					$src = (int) ( $rec['source_post_id'] ?? 0 );
					$dst = (int) ( $rec['target_post_id'] ?? 0 );
					if ( $post_id !== $src && $post_id !== $dst ) {
						continue;
					}
					$r = $inserter->apply( (int) $rec['id'], 'batch' );
					if ( is_wp_error( $r ) ) {
						$skipped++;
					} else {
						$applied++;
					}
				}
				return array( 'applied' => $applied, 'skipped' => $skipped, 'message' => sprintf( '%d internal link(s) inserted.', $applied ) );

			default:
				return new WP_Error( 'scc_not_safe', __( 'Unsupported safe action.', 'seo-command-center' ), array( 'status' => 409 ) );
		}
	}

	/**
	 * Count of safe, approved/new actions that "Fix Everything Safe" would run.
	 *
	 * @return int
	 */
	public static function safe_pending_count() {
		$count = 0;
		foreach ( self::all( '', 500 ) as $a ) {
			if ( self::is_safe( $a['type'] ) && in_array( $a['status'], array( 'new', 'approved' ), true ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Execute every safe, approved/new action. Never touches risky types.
	 *
	 * @return array {executed, failed, results}
	 */
	public static function fix_everything_safe() {
		$executed = 0;
		$failed   = 0;
		$results  = array();
		foreach ( self::all( '', 500 ) as $a ) {
			if ( ! self::is_safe( $a['type'] ) || ! in_array( $a['status'], array( 'new', 'approved' ), true ) ) {
				continue;
			}
			$r = self::execute( (int) $a['id'] );
			if ( is_wp_error( $r ) ) {
				$failed++;
			} else {
				$executed++;
				$results[] = array( 'id' => (int) $a['id'], 'result' => $r );
			}
		}
		return array( 'executed' => $executed, 'failed' => $failed, 'results' => $results );
	}

	/**
	 * Decode a DB row into an action array.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	protected static function hydrate( array $row ) {
		$row['target']  = json_decode( (string) ( $row['target'] ?? '' ), true ) ?: array();
		$row['payload'] = json_decode( (string) ( $row['payload'] ?? '' ), true ) ?: array();
		if ( ! empty( $row['result'] ) ) {
			$row['result'] = json_decode( (string) $row['result'], true );
		}
		$row['id']    = (int) $row['id'];
		$row['score'] = (int) $row['score'];
		$row['safe']  = self::is_safe( $row['type'] );
		return $row;
	}
}
