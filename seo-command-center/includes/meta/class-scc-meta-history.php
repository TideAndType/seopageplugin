<?php
/**
 * Metadata change history + experiment store (scc_meta_history).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta history.
 */
class SCC_Meta_History {

	/** Default cooldown between automatic changes to the same field (days). */
	const COOLDOWN_DAYS = 30;

	/**
	 * Record a metadata change.
	 *
	 * @param array $args {post_id, field, previous_value, new_value, variants, reason, perf_before}.
	 * @return int|false
	 */
	public static function record( array $args ) {
		return SCC_DB::insert(
			'meta_history',
			array(
				'post_id'        => (int) ( $args['post_id'] ?? 0 ),
				'field'          => in_array( $args['field'] ?? '', array( 'title', 'description' ), true ) ? $args['field'] : 'title',
				'previous_value' => SCC_Security::sanitize_textarea( $args['previous_value'] ?? '' ),
				'new_value'      => SCC_Security::sanitize_textarea( $args['new_value'] ?? '' ),
				'variants'       => wp_json_encode( $args['variants'] ?? array() ),
				'reason'         => SCC_Security::sanitize_textarea( $args['reason'] ?? '' ),
				'perf_before'    => wp_json_encode( $args['perf_before'] ?? array() ),
				'perf_after'     => null,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * History rows for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function for_post( $post_id ) {
		global $wpdb;
		$table = SCC_DB::table( 'meta_history' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT 50", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}

	/**
	 * Whether the field is within the cooldown window (blocks auto-changes).
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   title|description.
	 * @return bool
	 */
	public static function in_cooldown( $post_id, $field ) {
		global $wpdb;
		$table  = SCC_DB::table( 'meta_history' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::COOLDOWN_DAYS * DAY_IN_SECONDS );
		$count  = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND field = %s AND created_at >= %s",
			(int) $post_id,
			$field,
			$cutoff
		) );
		return $count > 0;
	}
}
