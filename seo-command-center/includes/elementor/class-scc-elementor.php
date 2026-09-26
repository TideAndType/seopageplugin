<?php
/**
 * Elementor integration helper: detection and template discovery.
 *
 * Works generically against Elementor's data model (post meta + the
 * elementor_library CPT) so it degrades gracefully when Elementor is absent.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor helper.
 */
class SCC_Elementor {

	/**
	 * Whether Elementor is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' );
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_elementor_post( $post_id ) {
		return 'builder' === get_post_meta( (int) $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Get the raw Elementor data array for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function get_data( $post_id ) {
		$raw = get_post_meta( (int) $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return null;
		}
		// Meta may be stored slashed; normalize before decoding.
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( null === $decoded ) {
				$decoded = json_decode( wp_unslash( $raw ), true );
			}
			return is_array( $decoded ) ? $decoded : null;
		}
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * List Elementor library templates (elementor_library CPT) + any Elementor
	 * page that has been flagged as a reusable SEO template.
	 *
	 * @return array List of {id, name, type, source}.
	 */
	public static function list_templates() {
		$templates = array();

		// Elementor saved templates.
		$library = get_posts(
			array(
				'post_type'      => 'elementor_library',
				'posts_per_page' => 100,
				'post_status'    => array( 'publish', 'draft' ),
				'no_found_rows'  => true,
			)
		);
		foreach ( $library as $post ) {
			$templates[] = array(
				'id'       => (int) $post->ID,
				'name'     => get_the_title( $post ),
				'type'     => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
				'source'   => 'library',
				'status'   => (string) $post->post_status,
				'is_live'  => false,
				'safe_source_only' => true,
			);
		}

		// Elementor-built pages the user designated as SEO templates.
		$designated = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => '_scc_is_seo_template', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $designated as $post ) {
			$templates[] = array(
				'id'       => (int) $post->ID,
				'name'     => get_the_title( $post ),
				'type'     => 'page',
				'source'   => 'designated',
				'status'   => (string) $post->post_status,
				'is_live'  => 'publish' === (string) $post->post_status,
				'safe_source_only' => true,
			);
		}

		return $templates;
	}

	/**
	 * Create a non-live working copy of an existing page/post for layout work.
	 *
	 * The original is never modified. Only the Elementor/layout meta TideOrbit
	 * needs is copied; unrelated plugin state is intentionally left behind.
	 *
	 * @param int $post_id Source post id.
	 * @return array|WP_Error
	 */
	public static function clone_to_draft( $post_id ) {
		$post_id = (int) $post_id;
		$source = get_post( $post_id );
		if ( ! $source ) {
			return new WP_Error( 'scc_no_source', __( 'The source page could not be found.', 'seo-command-center' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot copy this page.', 'seo-command-center' ), array( 'status' => 403 ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'    => (string) $source->post_type,
				'post_status'  => 'draft',
				'post_title'   => sprintf( __( '%s — TideOrbit Working Copy', 'seo-command-center' ), get_the_title( $source ) ),
				'post_content' => (string) $source->post_content,
				'post_excerpt' => (string) $source->post_excerpt,
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$copy_keys = array(
			'_elementor_data',
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_wp_page_template',
			'_thumbnail_id',
			'_scc_page_brain',
			'_scc_content_type',
			'_scc_search_intent',
		);
		foreach ( $copy_keys as $key ) {
			if ( ! metadata_exists( 'post', $post_id, $key ) ) {
				continue;
			}
			$value = get_post_meta( $post_id, $key, true );
			if ( '_elementor_data' === $key && is_string( $value ) ) {
				$value = wp_slash( $value );
			}
			update_post_meta( (int) $new_id, $key, $value );
		}
		update_post_meta( (int) $new_id, '_scc_layout_clone_of', $post_id );
		update_post_meta( (int) $new_id, '_scc_layout_working_copy', '1' );

		if ( class_exists( 'SCC_Content_Index' ) ) {
			SCC_Content_Index::index_post( (int) $new_id );
		}

		return array(
			'post_id'       => (int) $new_id,
			'source_post_id'=> $post_id,
			'edit_url'      => get_edit_post_link( (int) $new_id, 'raw' ),
			'layout_url'    => admin_url( 'admin.php?page=seo-command-center-layout&post=' . (int) $new_id ),
			'elementor_url' => admin_url( 'post.php?post=' . (int) $new_id . '&action=elementor' ),
		);
	}

	/**
	 * Designate (or undesignate) an Elementor page as an SEO template.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $on      On/off.
	 * @return bool
	 */
	public static function designate_template( $post_id, $on ) {
		$post_id = (int) $post_id;
		if ( ! self::is_elementor_post( $post_id ) ) {
			return false;
		}
		if ( $on ) {
			update_post_meta( $post_id, '_scc_is_seo_template', '1' );
		} else {
			delete_post_meta( $post_id, '_scc_is_seo_template' );
		}
		return true;
	}
}
