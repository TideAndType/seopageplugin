<?php
/**
 * Gutenberg (Block Editor) renderer.
 *
 * Produces valid block markup so the generated page is fully editable in the
 * Block Editor and remains standard WordPress content if the plugin is removed.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg renderer.
 */
class SCC_Gutenberg_Renderer implements SCC_Renderer_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id() {
		return 'gutenberg';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'Gutenberg (Block Editor)', 'seo-command-center' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available( $content_type = '' ) {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function render( SCC_Content_Object $content, SCC_Template $template ) {
		// Reuse the native HTML build, then convert to blocks.
		$wp   = new SCC_WordPress_Renderer();
		$base = $wp->render( $content, $template );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$blocks = self::html_to_blocks( $base['post_content'] );

		return array(
			'post_content' => $blocks,
			'post_meta'    => array(),
			'post_name'    => $base['post_name'],
		);
	}

	/**
	 * Convert semantic HTML into block-editor markup.
	 *
	 * Handles headings, paragraphs, and lists at the top level; anything else is
	 * wrapped in a Custom HTML block so it still round-trips.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_blocks( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="scc-root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//div[@id="scc-root"]' )->item( 0 );
		if ( ! $root ) {
			return self::html_block( $html );
		}

		$out = '';
		foreach ( $root->childNodes as $node ) {
			$out .= self::node_to_block( $dom, $node );
		}
		return trim( $out );
	}

	/**
	 * Convert a single DOM node to a block.
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	protected static function node_to_block( DOMDocument $dom, DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( $node->nodeValue );
			return '' === $text ? '' : self::paragraph_block( esc_html( $text ) );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag   = strtolower( $node->nodeName );
		$inner = self::inner_html( $dom, $node );

		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			$level = (int) $m[1];
			return "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level}>" . $inner . "</h{$level}>\n<!-- /wp:heading -->\n\n";
		}
		if ( 'p' === $tag ) {
			return self::paragraph_block( $inner );
		}
		if ( 'ul' === $tag ) {
			return "<!-- wp:list -->\n<ul>" . $inner . "</ul>\n<!-- /wp:list -->\n\n";
		}
		if ( 'ol' === $tag ) {
			return "<!-- wp:list {\"ordered\":true} -->\n<ol>" . $inner . "</ol>\n<!-- /wp:list -->\n\n";
		}

		// Fallback: preserve as a Custom HTML block.
		return self::html_block( $dom->saveHTML( $node ) );
	}

	/**
	 * Paragraph block.
	 *
	 * @param string $inner Inner HTML.
	 * @return string
	 */
	protected static function paragraph_block( $inner ) {
		$inner = trim( $inner );
		if ( '' === $inner ) {
			return '';
		}
		return "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	/**
	 * Custom HTML block.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	protected static function html_block( $html ) {
		return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->\n\n";
	}

	/**
	 * Serialize inner HTML of a node.
	 *
	 * @param DOMDocument $dom  Document.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	protected static function inner_html( DOMDocument $dom, DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}
}
