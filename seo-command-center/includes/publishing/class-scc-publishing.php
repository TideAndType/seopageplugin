<?php
/**
 * Publishing queue + workflow.
 *
 * Lists plugin-generated drafts and provides the review actions: approve,
 * publish, schedule. Publishing is always an explicit, capability-checked
 * action — there is no path here that publishes without the user asking.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishing service.
 */
class SCC_Publishing {

	/**
	 * List plugin-generated posts for the queue.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function queue( $limit = 100 ) {
		$limit = SCC_Security::sanitize_int( $limit, 1, 500 );
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'draft', 'pending', 'future', 'publish' ),
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_key'       => '_scc_generated', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$rows[] = array(
				'post_id'   => (int) $post->ID,
				'title'     => get_the_title( $post ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'approved'  => '1' === get_post_meta( $post->ID, '_scc_approved', true ),
				'score'     => (int) get_post_meta( $post->ID, '_scc_quality_score', true ),
				'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
				'view_url'  => get_permalink( $post->ID ),
				'preview_url' => get_preview_post_link( $post ),
				'modified'  => get_post_modified_time( 'Y-m-d H:i', false, $post ),
			);
		}
		return $rows;
	}

	/**
	 * Mark a post as approved for publishing (a review marker; does not publish).
	 *
	 * @param int  $post_id Post id.
	 * @param bool $on      On/off.
	 * @return bool
	 */
	public static function set_approved( $post_id, $on = true ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( $on ) {
			update_post_meta( $post_id, '_scc_approved', '1' );
		} else {
			delete_post_meta( $post_id, '_scc_approved' );
		}
		return true;
	}

	/**
	 * Publish a post now.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	public static function publish( $post_id ) {
		if ( ! current_user_can( 'publish_post', $post_id ) && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot publish this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}
		$result = wp_update_post( array( 'ID' => (int) $post_id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		SCC_Publishing::sync_plan_status( $post_id, 'published' );
		return true;
	}

	/**
	 * Schedule a post for a future date.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $datetime Site-time datetime (Y-m-d H:i:s).
	 * @return true|WP_Error
	 */
	public static function schedule( $post_id, $datetime ) {
		if ( ! current_user_can( 'publish_post', $post_id ) && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot schedule this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}
		$ts = strtotime( $datetime );
		if ( ! $ts || $ts <= time() ) {
			return new WP_Error( 'scc_bad_date', __( 'Provide a future date and time.', 'seo-command-center' ), array( 'status' => 400 ) );
		}
		$gmt   = gmdate( 'Y-m-d H:i:s', $ts - ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$local = gmdate( 'Y-m-d H:i:s', $ts );
		$result = wp_update_post(
			array(
				'ID'            => (int) $post_id,
				'post_status'   => 'future',
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Remove a generated draft from the queue.
	 *
	 * Sends the post to Trash (reversible from the WordPress Trash) rather than
	 * permanently deleting it, and detaches it from its content-plan entry so the
	 * plan no longer points at a trashed post. Published posts are never removed
	 * from here — un-publish them first.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	public static function remove( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot remove this post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'scc_missing', __( 'That draft no longer exists.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		if ( 'publish' === $post->post_status ) {
			return new WP_Error( 'scc_published', __( 'This page is published. Un-publish it before removing it from the queue.', 'seo-command-center' ), array( 'status' => 409 ) );
		}
		if ( ! wp_trash_post( $post_id ) ) {
			return new WP_Error( 'scc_trash_failed', __( 'Could not remove that draft.', 'seo-command-center' ), array( 'status' => 500 ) );
		}
		// Detach from the content-plan entry so it can be regenerated later.
		global $wpdb;
		$table = SCC_DB::table( 'content_plan' );
		$wpdb->update( $table, array( 'post_id' => 0, 'status' => 'planned' ), array( 'post_id' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Keep the linked content-plan entry status in sync.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  Plan status.
	 */
	protected static function sync_plan_status( $post_id, $status ) {
		global $wpdb;
		$table = SCC_DB::table( 'content_plan' );
		$wpdb->update( $table, array( 'status' => $status ), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
