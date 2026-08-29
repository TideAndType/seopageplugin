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
				'id'     => (int) $post->ID,
				'name'   => get_the_title( $post ),
				'type'   => (string) get_post_meta( $post->ID, '_elementor_template_type', true ),
				'source' => 'library',
			);
		}

		// Elementor-built pages the user designated as SEO templates.
		$designated = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => '_scc_is_seo_template', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $designated as $post ) {
			$templates[] = array(
				'id'     => (int) $post->ID,
				'name'   => get_the_title( $post ),
				'type'   => 'page',
				'source' => 'designated',
			);
		}

		return $templates;
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
