<?php
/**
 * SEO metadata writer.
 *
 * Writes generated metadata to the ACTIVE SEO plugin's meta keys, or to the
 * plugin's own _scc_* keys when none is active. Never overwrites an existing
 * value unless explicitly told to (overwrite=true), so it cannot clobber a
 * value a user already set in Yoast/Rank Math/AIOSEO.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metadata writer.
 */
class SCC_Metadata {

	/**
	 * Apply metadata to a post.
	 *
	 * @param int   $post_id   Post id.
	 * @param array $meta      {meta_title, meta_description, canonical, og_title, og_description, image_alt}.
	 * @param bool  $overwrite Overwrite existing values.
	 * @return void
	 */
	public static function apply( $post_id, array $meta, $overwrite = false ) {
		$post_id = (int) $post_id;
		$title   = SCC_Security::sanitize_text( $meta['meta_title'] ?? '' );
		$desc    = SCC_Security::sanitize_textarea( $meta['meta_description'] ?? '' );
		$plugin  = SCC_SEO_Meta::detect();
		$keys    = self::keys();
		$title_key = $keys['title'];
		$desc_key  = $keys['description'];

		if ( '' !== $title ) {
			self::maybe_set( $post_id, $title_key, $title, $overwrite );
		}
		if ( '' !== $desc ) {
			self::maybe_set( $post_id, $desc_key, $desc, $overwrite );
		}

		// Always store a plugin-owned copy for reference (non-destructive).
		update_post_meta( $post_id, '_scc_meta_title', $title );
		update_post_meta( $post_id, '_scc_meta_description', $desc );

		if ( ! empty( $meta['og_title'] ) ) {
			update_post_meta( $post_id, '_scc_og_title', SCC_Security::sanitize_text( $meta['og_title'] ) );
		}
		if ( ! empty( $meta['og_description'] ) ) {
			update_post_meta( $post_id, '_scc_og_description', SCC_Security::sanitize_textarea( $meta['og_description'] ) );
		}
		if ( ! empty( $meta['image_alt'] ) ) {
			update_post_meta( $post_id, '_scc_hero_image_alt', SCC_Security::sanitize_text( $meta['image_alt'] ) );
		}

		SCC_Logger::info( 'metadata', 'Metadata applied', array( 'post_id' => $post_id, 'seo_plugin' => $plugin, 'overwrite' => $overwrite ) );
	}

	/**
	 * Manually set metadata from the admin editor — writes EXACTLY what was typed
	 * (including clearing a field), records the change for revert, and returns the
	 * stored values. Unlike apply(), this always overwrites, because the user is
	 * explicitly editing.
	 *
	 * @param int    $post_id Post id.
	 * @param string $title   New meta title (may be empty to clear).
	 * @param string $desc    New meta description (may be empty to clear).
	 * @return array|WP_Error {title, description}
	 */
	public static function save_manual( $post_id, $title, $desc ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit this page.', 'seo-command-center' ), array( 'status' => 403 ) );
		}
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'scc_no_post', __( 'Page not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}

		$title = SCC_Security::sanitize_text( $title );
		$desc  = SCC_Security::sanitize_textarea( $desc );
		$keys  = self::keys();

		$before = self::current( $post_id );

		// Record history per changed field (for revert), before writing.
		if ( class_exists( 'SCC_Meta_History' ) ) {
			if ( (string) $before['title'] !== $title ) {
				SCC_Meta_History::record( array( 'post_id' => $post_id, 'field' => 'title', 'previous_value' => $before['title'], 'new_value' => $title, 'reason' => 'manual edit' ) );
			}
			if ( (string) $before['description'] !== $desc ) {
				SCC_Meta_History::record( array( 'post_id' => $post_id, 'field' => 'description', 'previous_value' => $before['description'], 'new_value' => $desc, 'reason' => 'manual edit' ) );
			}
		}

		update_post_meta( $post_id, $keys['title'], $title );
		update_post_meta( $post_id, '_scc_meta_title', $title );
		update_post_meta( $post_id, $keys['description'], $desc );
		update_post_meta( $post_id, '_scc_meta_description', $desc );

		SCC_Logger::info( 'metadata', 'Metadata manually edited', array( 'post_id' => $post_id ) );
		return self::current( $post_id );
	}

	/**
	 * Resolve the meta keys to write, honoring the meta_storage setting.
	 *
	 * meta_storage: 'auto' (active SEO plugin, else plugin keys), 'seo_plugin'
	 * (force active plugin keys), or 'plugin' (always _scc_* keys).
	 *
	 * @return array {title, description}
	 */
	public static function keys() {
		$storage = SCC_Settings::get( 'meta_storage', 'auto' );
		$plugin  = SCC_SEO_Meta::detect();

		if ( 'plugin' === $storage ) {
			return array( 'title' => '_scc_meta_title', 'description' => '_scc_meta_description' );
		}

		if ( SCC_SEO_Meta::PLUGIN_YOAST === $plugin ) {
			return array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc' );
		}
		if ( SCC_SEO_Meta::PLUGIN_RANKMATH === $plugin ) {
			return array( 'title' => 'rank_math_title', 'description' => 'rank_math_description' );
		}
		// AIOSEO uses its own table; write to our keys for safety.
		return array( 'title' => '_scc_meta_title', 'description' => '_scc_meta_description' );
	}

	/**
	 * List pages/posts with their current metadata for the bulk editor.
	 *
	 * @param array $args {search, post_type, paged, per_page}.
	 * @return array {items, total, pages, seo_plugin}
	 */
	public static function list_pages( array $args = array() ) {
		$search    = isset( $args['search'] ) ? SCC_Security::sanitize_text( $args['search'] ) : '';
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
		$paged     = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$per_page  = min( 100, max( 5, (int) ( $args['per_page'] ?? 25 ) ) );

		$types = class_exists( 'SCC_Analyzer' ) ? SCC_Analyzer::analyzable_post_types() : array( 'post', 'page' );
		if ( '' !== $post_type && in_array( $post_type, $types, true ) ) {
			$types = array( $post_type );
		}

		$q = new WP_Query( array(
			'post_type'      => $types,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			's'              => $search,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		) );

		$items = array();
		foreach ( $q->posts as $post ) {
			$meta = self::current( $post->ID );
			$items[] = array(
				'post_id'          => (int) $post->ID,
				'title'            => get_the_title( $post ),
				'post_type'        => $post->post_type,
				'status'           => $post->post_status,
				'url'              => get_permalink( $post->ID ),
				'edit_url'         => get_edit_post_link( $post->ID, 'raw' ),
				'meta_title'       => (string) $meta['title'],
				'meta_description' => (string) $meta['description'],
			);
		}

		return array(
			'items'      => $items,
			'total'      => (int) $q->found_posts,
			'pages'      => (int) $q->max_num_pages,
			'paged'      => $paged,
			'post_types' => $types,
			'seo_plugin' => class_exists( 'SCC_SEO_Meta' ) ? SCC_SEO_Meta::label( SCC_SEO_Meta::detect() ) : '',
		);
	}

	/**
	 * Character-count status for a meta field against SEO length norms. Pure.
	 *
	 * @param string $text Value.
	 * @param int    $min  Ideal minimum.
	 * @param int    $max  Ideal maximum.
	 * @return string 'empty' | 'short' | 'good' | 'long'
	 */
	public static function char_status( $text, $min, $max ) {
		$len = strlen( trim( (string) $text ) );
		if ( 0 === $len ) {
			return 'empty';
		}
		if ( $len < $min ) {
			return 'short';
		}
		if ( $len > $max ) {
			return 'long';
		}
		return 'good';
	}

	/**
	 * Current stored metadata for a post (reads via the SEO-plugin bridge).
	 *
	 * @param int $post_id Post id.
	 * @return array {title, description}
	 */
	public static function current( $post_id ) {
		return array(
			'title'       => SCC_SEO_Meta::get_title( $post_id ),
			'description' => SCC_SEO_Meta::get_description( $post_id ),
		);
	}

	/**
	 * Restore a single field value (used by revert). Writes the active key and
	 * the plugin-owned copy.
	 *
	 * @param int    $post_id Post id.
	 * @param string $field   'title' or 'description'.
	 * @param string $value   Previous value.
	 * @return void
	 */
	public static function restore_field( $post_id, $field, $value ) {
		$keys = self::keys();
		if ( 'title' === $field ) {
			update_post_meta( $post_id, $keys['title'], $value );
			update_post_meta( $post_id, '_scc_meta_title', $value );
		} else {
			update_post_meta( $post_id, $keys['description'], $value );
			update_post_meta( $post_id, '_scc_meta_description', $value );
		}
	}

	/**
	 * Set a meta value unless it already has one (respecting overwrite).
	 *
	 * @param int    $post_id   Post id.
	 * @param string $key       Meta key.
	 * @param string $value     Value.
	 * @param bool   $overwrite Overwrite existing.
	 * @return void
	 */
	protected static function maybe_set( $post_id, $key, $value, $overwrite ) {
		$existing = get_post_meta( $post_id, $key, true );
		if ( '' !== (string) $existing && ! $overwrite ) {
			return; // Do not clobber a value the user already set.
		}
		update_post_meta( $post_id, $key, $value );
	}
}
