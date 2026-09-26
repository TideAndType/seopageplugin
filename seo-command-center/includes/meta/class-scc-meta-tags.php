<?php
/**
 * Meta title + description output for sites without an SEO plugin.
 *
 * With no SEO plugin active, TideOrbit stores each page's SEO title and meta
 * description itself (_scc_meta_title / _scc_meta_description). This prints
 * them: the saved title replaces the theme's document title and the saved
 * description becomes <meta name="description">. Nothing is printed for a page
 * with nothing saved, and nothing at all while another SEO plugin is active —
 * that plugin owns these tags and a second copy would conflict.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end meta tags.
 */
class SCC_Meta_Tags {

	/**
	 * Whether TideOrbit should print meta tags on this request.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = SCC_SEO_Meta::PLUGIN_NONE === SCC_SEO_Meta::detect() && ! SCC_SEO_Meta::other_seo_plugin_active();
		return (bool) apply_filters( 'scc_output_meta_tags', $enabled );
	}

	/**
	 * The saved values for the current singular request.
	 *
	 * @return array {title, description}
	 */
	protected static function current() {
		if ( is_admin() || ! is_singular() || ! self::enabled() ) {
			return array( 'title' => '', 'description' => '' );
		}
		$post_id = (int) get_queried_object_id();
		return array(
			'title'       => trim( (string) get_post_meta( $post_id, '_scc_meta_title', true ) ),
			'description' => trim( (string) get_post_meta( $post_id, '_scc_meta_description', true ) ),
		);
	}

	/**
	 * pre_get_document_title filter.
	 *
	 * @param string $title Title another filter may already have set.
	 * @return string
	 */
	public static function document_title( $title ) {
		$saved = self::current()['title'];
		return '' !== $saved ? wp_strip_all_tags( $saved ) : $title;
	}

	/**
	 * wp_head callback.
	 */
	public static function output() {
		$tag = self::description_tag( self::current()['description'] );
		if ( '' !== $tag ) {
			echo $tag . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in description_tag().
		}
	}

	/**
	 * The description tag for a saved value ('' when there is none). Pure — unit-tested.
	 *
	 * @param string $description Saved description.
	 * @return string
	 */
	public static function description_tag( $description ) {
		$description = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $description ) ) );
		if ( '' === $description ) {
			return '';
		}
		return '<meta name="description" content="' . esc_attr( $description ) . '" />';
	}
}
