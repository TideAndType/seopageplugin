<?php
/**
 * Change history + revert log for automatic modifications (links, meta, schema).
 *
 * Every automatic or assisted change records enough to fully revert it.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change history.
 */
class SCC_Change_History {

	const TYPES = array( 'internal_link', 'meta_title', 'meta_description', 'schema' );

	/**
	 * Record a change.
	 *
	 * @param array $args {
	 *   @type int    $post_id
	 *   @type string $change_type   One of TYPES.
	 *   @type mixed  $previous_value
	 *   @type mixed  $new_value
	 *   @type string $reason
	 *   @type int    $confidence
	 *   @type string $trigger_source  autopilot|manual|batch.
	 * }
	 * @return int|false Change id.
	 */
	public static function record( array $args ) {
		$type = isset( $args['change_type'] ) ? $args['change_type'] : '';
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}
		return SCC_DB::insert(
			'change_history',
			array(
				'post_id'        => (int) ( $args['post_id'] ?? 0 ),
				'change_type'    => $type,
				'previous_value' => self::encode( $args['previous_value'] ?? '' ),
				'new_value'      => self::encode( $args['new_value'] ?? '' ),
				'reason'         => SCC_Security::sanitize_textarea( $args['reason'] ?? '' ),
				'confidence'     => SCC_Security::sanitize_int( $args['confidence'] ?? 0, 0, 100 ),
				'trigger_source' => sanitize_key( $args['trigger_source'] ?? 'manual' ),
				'reverted'       => 0,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * List history (optionally for one post).
	 *
	 * @param int $post_id Post id (0 = all).
	 * @param int $limit   Limit.
	 * @return array
	 */
	public static function all( $post_id = 0, $limit = 200 ) {
		global $wpdb;
		$table = SCC_DB::table( 'change_history' );
		$limit = SCC_Security::sanitize_int( $limit, 1, 1000 );
		if ( $post_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $post_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		if ( ! $rows ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['post_title'] = get_the_title( (int) $row['post_id'] );
		}
		return $rows;
	}

	/**
	 * Revert a change by id.
	 *
	 * @param int $id Change id.
	 * @return true|WP_Error
	 */
	public static function revert( $id ) {
		$row = SCC_DB::get( 'change_history', $id );
		if ( ! $row ) {
			return new WP_Error( 'scc_no_change', __( 'Change not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		if ( (int) $row['reverted'] === 1 ) {
			return new WP_Error( 'scc_already_reverted', __( 'This change was already reverted.', 'seo-command-center' ), array( 'status' => 409 ) );
		}
		$post_id = (int) $row['post_id'];
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}

		$previous = self::decode( $row['previous_value'] );

		switch ( $row['change_type'] ) {
			case 'internal_link':
				// previous = full post_content before insertion.
				$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => (string) $previous ), true );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				break;

			case 'meta_title':
			case 'meta_description':
				$field = ( 'meta_title' === $row['change_type'] ) ? 'title' : 'description';
				SCC_Metadata::restore_field( $post_id, $field, (string) $previous );
				break;

			case 'schema':
				if ( '' === (string) $previous || null === $previous ) {
					delete_post_meta( $post_id, '_scc_schema' );
				} else {
					update_post_meta( $post_id, '_scc_schema', wp_json_encode( $previous ) );
				}
				break;
		}

		SCC_DB::update( 'change_history', array( 'reverted' => 1 ), array( 'id' => (int) $id ) );
		SCC_Logger::info( 'change-history', 'Change reverted', array( 'id' => $id, 'type' => $row['change_type'], 'post_id' => $post_id ) );
		return true;
	}

	/**
	 * Encode a value for storage.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function encode( $value ) {
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Decode a stored value (JSON or scalar).
	 *
	 * @param string $value Stored value.
	 * @return mixed
	 */
	protected static function decode( $value ) {
		$decoded = json_decode( (string) $value, true );
		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $value;
	}
}
