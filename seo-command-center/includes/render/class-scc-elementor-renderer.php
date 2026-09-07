<?php
/**
 * Elementor renderer (OPTIONAL).
 *
 * Available only when Elementor is active AND a source Elementor template is
 * available for the content type. Duplicates the source _elementor_data
 * (never modifying the original), fills placeholders from the content object,
 * and also writes native post_content so the page degrades gracefully if
 * Elementor is later removed. Requires only Elementor Free — no Pro, no Theme
 * Builder, no Pro-only APIs.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor renderer.
 */
class SCC_Elementor_Renderer implements SCC_Renderer_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'elementor';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Elementor (Free)', 'seo-command-center' );
	}

	/**
	 * Resolve the source Elementor template id for a content type.
	 *
	 * @param string       $content_type Content type.
	 * @param SCC_Template $template     Selected template (may pin a source).
	 * @return int
	 */
	protected function source_id( $content_type, $template = null ) {
		if ( $template && (int) $template->elementor_source_id > 0 ) {
			return (int) $template->elementor_source_id;
		}
		// Backward-compatible: reuse the existing Elementor template mapping.
		if ( class_exists( 'SCC_Template_Mapping' ) ) {
			$mapping = SCC_Template_Mapping::for_content_type( $content_type );
			if ( $mapping && ! empty( $mapping['template_id'] ) ) {
				return (int) $mapping['template_id'];
			}
		}
		return 0;
	}

	/**
	 * @inheritDoc
	 */
	public function is_available( $content_type = '', $template = null ) {
		if ( ! class_exists( 'SCC_Elementor' ) || ! SCC_Elementor::is_active() ) {
			return false;
		}
		// Pass the selected template so a template that pins its own Elementor
		// source (the new template store) counts as available — not only the
		// legacy content-type → Elementor mapping.
		return $this->source_id( $content_type, $template ) > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function render( SCC_Content_Object $content, SCC_Template $template ) {
		$source = $this->source_id( $content->content_type, $template );
		if ( $source <= 0 ) {
			return new WP_Error( 'scc_no_elementor_source', __( 'No Elementor source template available.', 'seo-command-center' ) );
		}

		// Build the duplicated, placeholder-filled tree (original untouched).
		$tree = SCC_Elementor_Builder::build_tree( $source, $content->variables() );
		if ( is_wp_error( $tree ) ) {
			return $tree; // Manager will fall back to Gutenberg/native.
		}

		// Native fallback content so the page is readable without Elementor.
		$wp   = new SCC_WordPress_Renderer();
		$base = $wp->render( $content, $template );
		$post_content = is_wp_error( $base ) ? '' : $base['post_content'];
		$post_name    = is_wp_error( $base ) ? sanitize_title( $content->title ) : $base['post_name'];

		// Match Elementor's template type to the destination post type so Elementor
		// fully takes over the front end (otherwise the theme can render the native
		// post_content too, which looks like the content is posted twice).
		$target_type   = class_exists( 'SCC_Generator' ) ? SCC_Generator::post_type_for( $content->content_type ) : 'page';
		$template_type = ( 'page' === $target_type ) ? 'wp-page' : 'wp-post';

		$meta = array(
			'_elementor_data'          => wp_slash( wp_json_encode( $tree ) ),
			'_elementor_edit_mode'     => 'builder',
			'_elementor_template_type' => $template_type,
		);
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$meta['_elementor_version'] = ELEMENTOR_VERSION;
		}

		// Carry the template's PAGE settings/layout so the new post matches it:
		// the WordPress page template (Elementor Canvas / Full Width / theme) and
		// Elementor's page-level settings (content width, background, etc.).
		$src_page_template = get_post_meta( $source, '_wp_page_template', true );
		if ( is_string( $src_page_template ) && '' !== $src_page_template && 'default' !== $src_page_template ) {
			$meta['_wp_page_template'] = $src_page_template;
		}
		$src_page_settings = get_post_meta( $source, '_elementor_page_settings', true );
		if ( ! empty( $src_page_settings ) ) {
			$meta['_elementor_page_settings'] = $src_page_settings;
		}

		return array(
			'post_content' => $post_content,
			'post_meta'    => $meta,
			'post_name'    => $post_name,
		);
	}
}
