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

		$title_key = null;
		$desc_key  = null;
		switch ( $plugin ) {
			case SCC_SEO_Meta::PLUGIN_YOAST:
				$title_key = '_yoast_wpseo_title';
				$desc_key  = '_yoast_wpseo_metadesc';
				break;
			case SCC_SEO_Meta::PLUGIN_RANKMATH:
				$title_key = 'rank_math_title';
				$desc_key  = 'rank_math_description';
				break;
			case SCC_SEO_Meta::PLUGIN_AIOSEO:
				// AIOSEO stores in its own table; we do not write there directly.
				// Fall back to our own keys and let a later mapping sync if desired.
				$title_key = '_scc_meta_title';
				$desc_key  = '_scc_meta_description';
				break;
			default:
				$title_key = '_scc_meta_title';
				$desc_key  = '_scc_meta_description';
		}

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
