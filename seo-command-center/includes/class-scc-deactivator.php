<?php
/**
 * Plugin deactivation: clear scheduled jobs. Data is preserved.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation routine.
 */
class SCC_Deactivator {

	/**
	 * Run on deactivation. Deactivation is not uninstall: no data is removed.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'scc_run_jobs' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'scc_run_jobs' );
		}
		wp_clear_scheduled_hook( 'scc_run_jobs' );
		flush_rewrite_rules();
	}
}
