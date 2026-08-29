<?php
/**
 * Elementor page builder.
 *
 * Clones a template's Elementor data, regenerates element IDs (so the new page
 * is independent of the template), fills placeholders with generated content,
 * and writes the Elementor meta onto a target post — preserving the template's
 * layout, sections, columns, widgets, typography, colors, spacing, and
 * responsive settings.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor builder.
 */
class SCC_Elementor_Builder {

	/**
	 * Build a replacement map from an entry + generated body.
	 *
	 * @param array $entry Content-plan entry.
	 * @param array $body  Generated body (title, content_html, faqs, meta_*).
	 * @param array $brief Brief (for CTA).
	 * @return array TOKEN => value.
	 */
	public static function build_replacements( array $entry, array $body, array $brief = array() ) {
		$content_html = (string) ( $body['content_html'] ?? '' );

		// Intro = first paragraph text.
		$intro = '';
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content_html, $m ) ) {
			$intro = wp_strip_all_tags( $m[1] );
		}

		// FAQ HTML.
		$faq_html = '';
		foreach ( (array) ( $body['faqs'] ?? array() ) as $faq ) {
			$faq_html .= '<h3>' . esc_html( $faq['question'] ) . '</h3><p>' . esc_html( $faq['answer'] ) . '</p>';
		}

		// Derive service / city from the entry where possible.
		$title   = (string) ( $body['title'] ?? ( $entry['title'] ?? '' ) );
		$service = (string) ( $entry['parent'] ?? '' );
		$city    = '';
		if ( false !== strpos( $title, '—' ) ) {
			$parts   = array_map( 'trim', explode( '—', $title ) );
			$service = $service ? $service : ( $parts[0] ?? '' );
			$city    = $parts[1] ?? '';
		} elseif ( false !== strpos( $title, ' in ' ) ) {
			$parts = explode( ' in ', $title );
			$city  = trim( end( $parts ) );
		}

		return array(
			'TITLE'            => $title,
			'H1'               => $title,
			'INTRO'            => $intro,
			'BODY'             => $content_html,
			'CONTENT'          => $content_html,
			'SERVICE'          => $service,
			'CITY'             => $city,
			'PRIMARY_KEYWORD'  => (string) ( $entry['primary_keyword'] ?? '' ),
			'CTA'              => (string) ( $brief['cta'] ?? '' ),
			'FAQ'              => $faq_html,
			'META_TITLE'       => (string) ( $body['meta_title'] ?? '' ),
			'META_DESCRIPTION' => (string) ( $body['meta_description'] ?? '' ),
		);
	}

	/**
	 * Produce a new Elementor element tree from a template with placeholders
	 * filled and fresh element IDs.
	 *
	 * @param int   $template_id  Template post id.
	 * @param array $replacements TOKEN => value.
	 * @return array|WP_Error New element tree.
	 */
	public static function build_tree( $template_id, array $replacements ) {
		$data = SCC_Elementor::get_data( $template_id );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'scc_no_template_data', __( 'The selected template has no Elementor data.', 'seo-command-center' ) );
		}
		$filled = SCC_Placeholders::replace( $data, $replacements );
		return self::regenerate_ids( $filled );
	}

	/**
	 * Apply an Elementor template to a target post (writes Elementor meta).
	 *
	 * @param int   $post_id      Target post id.
	 * @param int   $template_id  Template post id.
	 * @param array $replacements TOKEN => value.
	 * @return true|WP_Error
	 */
	public static function apply_to_post( $post_id, $template_id, array $replacements ) {
		$tree = self::build_tree( $template_id, $replacements );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$json = wp_json_encode( $tree );
		// Elementor expects _elementor_data stored slashed.
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		// Ask Elementor to regenerate its CSS on next view if available.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		SCC_Logger::info( 'elementor', 'Template applied to post', array( 'post_id' => $post_id, 'template_id' => $template_id ) );
		return true;
	}

	/**
	 * Recursively assign fresh element IDs so the new page is independent.
	 *
	 * @param array $elements Elements.
	 * @return array
	 */
	protected static function regenerate_ids( array $elements ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) ) {
				$el['id'] = self::new_id();
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::regenerate_ids( $el['elements'] );
			}
		}
		return $elements;
	}

	/**
	 * Generate an Elementor-style 7-char hex element id.
	 *
	 * @return string
	 */
	protected static function new_id() {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
	}
}
