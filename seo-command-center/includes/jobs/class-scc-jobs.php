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
	const SECRET_OPTION  = 'scc_worker_secret';
	const STALE_SECONDS  = 900; // A 'processing' job older than this is presumed dead.

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
	 * Enqueue a single "build the topical map from the site" job and kick off a
	 * worker immediately. Runs the AI generation off the web request so the page
	 * never times out, no matter how slow the model is.
	 *
	 * @return array {job_id:int}
	 */
	public function enqueue_keyword_auto( array $opts = array() ) {
		$clean = array();
		foreach ( array( 'map_type', 'depth', 'language' ) as $k ) {
			if ( isset( $opts[ $k ] ) ) {
				$clean[ $k ] = (string) $opts[ $k ];
			}
		}
		$id = SCC_DB::insert(
			'jobs',
			array(
				'type'         => 'keyword_auto',
				'status'       => 'queued',
				'payload'      => wp_json_encode( $clean ),
				'attempts'     => 0,
				'max_attempts' => 1,
				'scheduled_at' => current_time( 'mysql' ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		// NOTE: we intentionally do NOT spawn the loopback worker or schedule cron
		// here. On hosts that block loopback, a half-executed worker can claim the
		// job (mark it "processing") and then die when the non-blocking request is
		// dropped, leaving it stuck and preventing the real trigger from running
		// it. The browser's /keywords/auto/process request is the sole trigger.
		SCC_Logger::info( 'jobs', 'Keyword-auto job enqueued', array( 'job_id' => $id ) );
		return array( 'job_id' => (int) $id );
	}

	/**
	 * Shared worker secret (created once), used to authenticate the loopback
	 * request that triggers immediate job processing.
	 *
	 * @return string
	 */
	public static function worker_secret() {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = wp_generate_password( 40, false, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Fire a non-blocking loopback request that processes queued jobs right now,
	 * so interactive work does not wait for the next WP-Cron tick. Best-effort:
	 * if the loopback is blocked, the scheduled cron event still runs it.
	 */
	public static function spawn_worker() {
		$url = rest_url( SCC_REST::NS . '/jobs/run' );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => ( 0 === strpos( $url, 'https://' ) && apply_filters( 'https_local_ssl_verify', false ) ),
				'body'      => array( 'secret' => self::worker_secret() ),
				'cookies'   => array(),
			)
		);
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

		self::recover_stale_jobs();

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
	 * Recover jobs that got stuck in 'processing' (e.g. a worker died mid-run on a
	 * host that dropped the request). A stale job with retries left is requeued;
	 * one that has exhausted its attempts is failed with a clear reason, so it
	 * never stays locked forever.
	 *
	 * @return int Number of jobs recovered.
	 */
	public static function recover_stale_jobs() {
		global $wpdb;
		$table  = SCC_DB::table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT id, attempts, max_attempts FROM {$table} WHERE status = 'processing' AND started_at IS NOT NULL AND started_at < %s", $cutoff ),
			ARRAY_A
		);
		$recovered = 0;
		foreach ( (array) $rows as $job ) {
			$attempts = (int) $job['attempts'] + 1;
			if ( $attempts >= (int) $job['max_attempts'] ) {
				SCC_DB::update(
					'jobs',
					array( 'status' => 'failed', 'attempts' => $attempts, 'last_error' => 'Recovered stale job: worker did not finish', 'finished_at' => current_time( 'mysql' ) ),
					array( 'id' => (int) $job['id'] )
				);
			} else {
				SCC_DB::update(
					'jobs',
					array( 'status' => 'queued', 'attempts' => $attempts, 'last_error' => 'Requeued after a stalled run' ),
					array( 'id' => (int) $job['id'] )
				);
			}
			$recovered++;
		}
		if ( $recovered > 0 ) {
			SCC_Logger::info( 'jobs', 'Recovered stale jobs', array( 'count' => $recovered ) );
		}
		return $recovered;
	}

	/**
	 * Process a single job.
	 *
	 * @param array $job Job row.
	 */
	protected function process( array $job ) {
		$id = (int) $job['id'];

		// Atomically claim the job: only proceed if it was still 'queued'. This
		// prevents the immediate loopback worker and the WP-Cron tick from
		// running the same job twice (which would create duplicate output).
		$claimed = SCC_DB::update(
			'jobs',
			array( 'status' => 'processing', 'started_at' => current_time( 'mysql' ) ),
			array( 'id' => $id, 'status' => 'queued' )
		);
		if ( ! $claimed ) {
			return; // Someone else already claimed it.
		}

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

			case 'keyword_auto':
				$inputs = SCC_Keyword_Strategy::infer_inputs_from_site();
				foreach ( array( 'map_type', 'depth', 'language' ) as $k ) {
					if ( isset( $payload[ $k ] ) ) {
						$inputs[ $k ] = $payload[ $k ];
					}
				}
				$service = new SCC_Keyword_Strategy( $this->ai );
				$result  = $service->generate( $inputs );
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
	 * Process one specific job immediately (used after the HTTP response has
	 * been flushed to the browser). The atomic claim inside process() makes this
	 * safe to race with the loopback worker and WP-Cron.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function process_job_now( $job_id ) {
		$row = SCC_DB::get( 'jobs', (int) $job_id );
		if ( $row && 'queued' === $row['status'] ) {
			$this->process( $row );
		}
	}

	/**
	 * Fetch one job row (decoded status fields) for polling.
	 *
	 * @param int $id Job id.
	 * @return array|null {status, last_error, attempts}
	 */
	public static function find_job( $id ) {
		global $wpdb;
		$table = SCC_DB::table( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, status, last_error, attempts, max_attempts FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : null;
	}

	/**
	 * Run the queue now if the caller presents the worker secret. Used by the
	 * non-blocking loopback in spawn_worker(). Lifts execution limits so a slow
	 * AI call is not killed.
	 *
	 * @param string $secret Provided secret.
	 * @return bool Whether processing ran.
	 */
	public function run_authenticated( $secret ) {
		if ( ! hash_equals( self::worker_secret(), (string) $secret ) ) {
			return false;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$this->run();
		return true;
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
