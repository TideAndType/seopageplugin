<?php
/**
 * Background job queue (scc_jobs) with a WP-Cron dispatcher.
 *
 * Long batch generation runs never block a web request: work is enqueued as
 * jobs and processed a few at a time on cron. Jobs are resumable, retried on
 * failure, and pause automatically when the monthly AI budget is reached.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job queue.
 */
class SCC_Jobs {

	const CRON_HOOK      = 'scc_run_jobs';
	const PER_TICK       = 3;   // Max jobs processed per cron tick.
	const PAUSED_OPTION  = 'scc_jobs_paused';

	/** @var SCC_AI_Manager */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai AI manager.
	 */
	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Enqueue a batch of content-plan entries for generation.
	 *
	 * @param int[] $entry_ids Content-plan entry ids.
	 * @return array {queued:int}
	 */
	public function enqueue_generation_batch( array $entry_ids ) {
		$entry_ids = array_values( array_unique( array_filter( array_map( 'absint', $entry_ids ) ) ) );
		$queued    = 0;
		foreach ( $entry_ids as $entry_id ) {
			$id = SCC_DB::insert(
				'jobs',
				array(
					'type'         => 'generate',
					'status'       => 'queued',
					'payload'      => wp_json_encode( array( 'entry_id' => $entry_id ) ),
					'attempts'     => 0,
					'max_attempts' => 3,
					'scheduled_at' => current_time( 'mysql' ),
					'created_at'   => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			if ( $id ) {
				$queued++;
			}
		}

		if ( $queued > 0 ) {
			self::ensure_scheduled();
		}
		SCC_Logger::info( 'jobs', 'Batch enqueued', array( 'queued' => $queued ) );
		return array( 'queued' => $queued );
	}

	/**
	 * Ensure the cron dispatcher is scheduled soon.
	 */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Whether the queue is paused.
	 *
	 * @return bool
	 */
	public static function is_paused() {
		return (bool) get_option( self::PAUSED_OPTION, false );
	}

	/**
	 * Pause the queue.
	 */
	public static function pause() {
		update_option( self::PAUSED_OPTION, true );
	}

	/**
	 * Resume the queue.
	 */
	public static function resume() {
		update_option( self::PAUSED_OPTION, false );
		self::ensure_scheduled();
	}

	/**
	 * Requeue failed jobs.
	 *
	 * @return int Number requeued.
	 */
	public static function retry_failed() {
		global $wpdb;
		$table = SCC_DB::table( 'jobs' );
		$count = $wpdb->query( "UPDATE {$table} SET status = 'queued', attempts = 0, last_error = NULL WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB
		self::ensure_scheduled();
		return (int) $count;
	}

	/**
	 * Cron dispatcher: process a bounded number of queued jobs.
	 */
	public function run() {
		if ( self::is_paused() ) {
			return;
		}

		global $wpdb;
		$table = SCC_DB::table( 'jobs' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT %d", self::PER_TICK ), ARRAY_A ); // phpcs:ignore WordPress.DB

		foreach ( (array) $rows as $job ) {
			$this->process( $job );
		}

		// Reschedule if more work remains.
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ); // phpcs:ignore WordPress.DB
		if ( $remaining > 0 && ! self::is_paused() ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	/**
	 * Process a single job.
	 *
	 * @param array $job Job row.
	 */
	protected function process( array $job ) {
		$id = (int) $job['id'];
		SCC_DB::update( 'jobs', array( 'status' => 'processing', 'started_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );

		$result = $this->dispatch( $job );

		if ( is_wp_error( $result ) ) {
			// Budget exhaustion pauses the whole queue rather than burning retries.
			if ( 'scc_budget' === $result->get_error_code() ) {
				self::pause();
				SCC_DB::update( 'jobs', array( 'status' => 'queued', 'last_error' => 'Paused: monthly budget reached' ), array( 'id' => $id ) );
				SCC_Logger::error( 'jobs', 'Queue paused: budget reached' );
				return;
			}

			$attempts = (int) $job['attempts'] + 1;
			$status   = ( $attempts >= (int) $job['max_attempts'] ) ? 'failed' : 'queued';
			SCC_DB::update(
				'jobs',
				array(
					'status'      => $status,
					'attempts'    => $attempts,
					'last_error'  => substr( $result->get_error_message(), 0, 250 ),
					'finished_at' => ( 'failed' === $status ) ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $id )
			);
			SCC_Logger::error( 'jobs', 'Job failed', array( 'job_id' => $id, 'attempts' => $attempts, 'status' => $status ) );
			return;
		}

		SCC_DB::update( 'jobs', array( 'status' => 'completed', 'finished_at' => current_time( 'mysql' ), 'last_error' => null ), array( 'id' => $id ) );
	}

	/**
	 * Dispatch a job by type.
	 *
	 * @param array $job Job row.
	 * @return true|WP_Error
	 */
	protected function dispatch( array $job ) {
		$payload = json_decode( (string) $job['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();

		switch ( $job['type'] ) {
			case 'generate':
				$entry = SCC_Content_Plan::find( (int) ( $payload['entry_id'] ?? 0 ) );
				if ( ! $entry ) {
					return new WP_Error( 'scc_no_entry', 'Content plan entry not found.' );
				}
				if ( ! empty( $entry['post_id'] ) ) {
					return true; // Already generated; treat as done (idempotent).
				}
				$generator = new SCC_Generator( $this->ai );
				$result    = $generator->generate( $entry );
				return is_wp_error( $result ) ? $result : true;

			case 'link_autopilot':
				$post_id = (int) ( $payload['post_id'] ?? 0 );
				if ( ! $post_id || ! get_post( $post_id ) ) {
					return true; // Post gone; nothing to do (idempotent).
				}
				$autopilot = new SCC_Autopilot();
				$autopilot->run_for_post( $post_id );
				return true;

			default:
				return new WP_Error( 'scc_unknown_job', 'Unknown job type.' );
		}
	}

	/**
	 * Queue status summary.
	 *
	 * @return array
	 */
	public static function status() {
		global $wpdb;
		$table = SCC_DB::table( 'jobs' );
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
		$counts = array( 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		$counts['paused'] = self::is_paused();
		return $counts;
	}
}
