<?php
/**
 * Template renderer contract. The SEO/content engine talks only to this.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer interface.
 */
interface SCC_Renderer_Interface {

	/**
	 * Stable renderer id ('wordpress'|'gutenberg'|'elementor'|…).
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether this renderer can run for the given content type right now.
	 *
	 * @param string $content_type Content type.
	 * @return bool
	 */
	public function is_available( $content_type = '' );

	/**
	 * Render a content object + template into WordPress post data.
	 *
	 * @param SCC_Content_Object $content  Content object.
	 * @param SCC_Template       $template Template.
	 * @return array|WP_Error {post_content:string, post_meta:array, post_name:string}
	 */
	public function render( SCC_Content_Object $content, SCC_Template $template );
}
