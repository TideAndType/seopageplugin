<?php
/**
 * AI usage / cost ledger.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and summarizes AI usage.
 */
class SCC_AI_Usage {

	/**
	 * Record a usage row.
	 *
	 * @param SCC_AI_Response $response  Response.
	 * @param string          $operation Operation label.
	 */
	public static function record( SCC_AI_Response $response, $operation = '' ) {
		SCC_DB::insert(
			'api_usage',
			array(
				'created_at'    => current_time( 'mysql' ),
				'provider'      => substr( (string) $response->provider, 0, 40 ),
				'model'         => substr( (string) $response->model, 0, 80 ),
				'operation'     => substr( (string) $operation, 0, 80 ),
				'input_tokens'  => (int) $response->input_tokens,
				'output_tokens' => (int) $response->output_tokens,
				'cost'          => (float) $response->cost,
				'status'        => $response->is_error() ? 'error' : 'ok',
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s' )
		);
	}

	/**
	 * Total estimated cost in the current calendar month.
	 *
	 * @return float
	 */
	public static function month_to_date_cost() {
		global $wpdb;
		$table = SCC_DB::table( 'api_usage' );
		$start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$sum   = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM {$table} WHERE created_at >= %s", $start ) ); // phpcs:ignore WordPress.DB
		return (float) $sum;
	}

	/**
	 * A summary for the current month.
	 *
	 * @return array
	 */
	public static function month_summary() {
		global $wpdb;
		$table = SCC_DB::table( 'api_usage' );
		$start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS calls, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(cost) AS cost FROM {$table} WHERE created_at >= %s", $start ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'calls'         => (int) ( $row['calls'] ?? 0 ),
			'input_tokens'  => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $row['output_tokens'] ?? 0 ),
			'cost'          => round( (float) ( $row['cost'] ?? 0 ), 4 ),
		);
	}
}
