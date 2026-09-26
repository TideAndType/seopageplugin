<?php
/**
 * Open Graph + Twitter card output.
 *
 * Off by default. Turned on from Settings or by the SEO Doctor's one-click fix,
 * and only ever printed when no other SEO plugin is active — Yoast, Rank Math,
 * AIOSEO and The SEO Framework output their own social tags, and a second set
 * would conflict.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social sharing tags.
 */
class SCC_Social_Tags {

	/**
	 * Whether tags should be printed on this request.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) SCC_Settings::get( 'output_social_tags', false )
			&& SCC_SEO_Meta::PLUGIN_NONE === SCC_SEO_Meta::detect()
			&& ! SCC_SEO_Meta::other_seo_plugin_active();
	}

	/**
	 * wp_head callback.
	 */
	public static function output() {
		if ( is_admin() || ! self::enabled() || is_404() || is_search() ) {
			return;
		}
		$context = self::context();
		foreach ( self::build( $context ) as $tag ) {
			printf(
				'<meta %1$s="%2$s" content="%3$s" />' . "\n",
				esc_attr( $tag[0] ),
				esc_attr( $tag[1] ),
				esc_attr( $tag[2] )
			);
		}
	}

	/**
	 * The site-wide share image used when a page has no featured image: the
	 * theme's custom logo, else the site icon ('' when there is neither).
	 *
	 * @return string
	 */
	public static function fallback_image() {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		return $logo ? (string) $logo : (string) get_site_icon_url( 512 );
	}

	/**
	 * Gather the values for the current request.
	 *
	 * @return array
	 */
	protected static function context() {
		$context = array(
			'site_name'   => get_bloginfo( 'name' ),
			'type'        => 'website',
			'url'         => home_url( '/' ),
			'title'       => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'image'       => self::fallback_image(),
		);

		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			$current = class_exists( 'SCC_Metadata' ) ? SCC_Metadata::current( $post_id ) : array();
			$context['type']  = 'post' === get_post_type( $post_id ) ? 'article' : 'website';
			$context['url']   = (string) get_permalink( $post_id );
			$context['title'] = self::first_filled(
				get_post_meta( $post_id, '_scc_og_title', true ),
				$current['title'] ?? '',
				get_the_title( $post_id )
			);
			$context['description'] = self::first_filled(
				get_post_meta( $post_id, '_scc_og_description', true ),
				$current['description'] ?? '',
				has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( class_exists( 'SCC_Content_Index' ) ? SCC_Content_Index::lead_text_from_html( (string) get_post_field( 'post_content', $post_id ) ) : wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 30, '…' )
			);
			$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
			if ( $thumb ) {
				$context['image'] = (string) $thumb;
			}
		}
		return $context;
	}

	/**
	 * First non-empty string.
	 *
	 * @param string ...$values Candidates.
	 * @return string
	 */
	protected static function first_filled( ...$values ) {
		foreach ( $values as $value ) {
			$value = trim( wp_strip_all_tags( (string) $value ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * The tags to print, as [attribute, key, content] triples. Empty values are
	 * skipped rather than printed blank. Pure — unit-tested.
	 *
	 * @param array $c {site_name, type, url, title, description, image}.
	 * @return array[]
	 */
	public static function build( array $c ) {
		$tags = array(
			array( 'property', 'og:type', (string) ( $c['type'] ?? 'website' ) ),
			array( 'property', 'og:site_name', (string) ( $c['site_name'] ?? '' ) ),
			array( 'property', 'og:title', (string) ( $c['title'] ?? '' ) ),
			array( 'property', 'og:description', (string) ( $c['description'] ?? '' ) ),
			array( 'property', 'og:url', (string) ( $c['url'] ?? '' ) ),
			array( 'property', 'og:image', (string) ( $c['image'] ?? '' ) ),
			array( 'name', 'twitter:card', '' !== (string) ( $c['image'] ?? '' ) ? 'summary_large_image' : 'summary' ),
			array( 'name', 'twitter:title', (string) ( $c['title'] ?? '' ) ),
			array( 'name', 'twitter:description', (string) ( $c['description'] ?? '' ) ),
		);
		if ( '' !== (string) ( $c['image'] ?? '' ) ) {
			$tags[] = array( 'name', 'twitter:image', (string) $c['image'] );
		}
		return array_values(
			array_filter(
				$tags,
				function ( $tag ) {
					return '' !== trim( $tag[2] );
				}
			)
		);
	}
}
