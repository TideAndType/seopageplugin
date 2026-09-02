<?php
/**
 * Standardized, renderer-independent content object.
 *
 * The single hand-off between the content engine and any renderer. The SEO
 * engine produces one of these; a renderer consumes it. Nothing here references
 * a page builder.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content object.
 */
class SCC_Content_Object {

	/** @var string */
	public $title = '';

	/** @var string */
	public $h1 = '';

	/** @var string */
	public $slug = '';

	/** @var string */
	public $content_type = 'article';

	/** @var string */
	public $primary_keyword = '';

	/** @var string[] */
	public $secondary_keywords = array();

	/** @var string */
	public $search_intent = '';

	/** @var string Intro (HTML allowed). */
	public $intro = '';

	/** @var string Main body content (HTML). */
	public $content = '';

	/** @var array List of {heading, html} sections. */
	public $sections = array();

	/** @var string[] Benefit bullet points. */
	public $benefits = array();

	/** @var array Process steps [{title, description}] or strings. */
	public $process = array();

	/** @var string Locally-specific content (HTML). */
	public $local_content = '';

	/** @var array FAQ [{question, answer}]. */
	public $faq = array();

	/** @var string CTA (HTML/text). */
	public $cta = '';

	/** @var string City (location pages). */
	public $city = '';

	/** @var string State/region. */
	public $state = '';

	/** @var string Service name. */
	public $service = '';

	/** @var array metadata {meta_title, meta_description, canonical_url, og_title, og_description, image_alt}. */
	public $metadata = array();

	/** @var array Schema JSON-LD nodes (filled by the schema engine). */
	public $schema = array();

	/** @var array Internal-link recommendations to weave into the body. */
	public $internal_links = array();

	/** @var array Image recommendation. */
	public $image = array();

	/**
	 * Build a content object from an AI body + a content-plan entry + brief.
	 *
	 * @param array $entry Content-plan entry.
	 * @param array $body  Generated body (content_html, faqs, meta_*, image…).
	 * @param array $brief Brief.
	 * @return SCC_Content_Object
	 */
	public static function from_generation( array $entry, array $body, array $brief = array() ) {
		$obj = new self();

		$obj->title           = (string) ( $body['title'] ?? ( $entry['title'] ?? '' ) );
		$obj->h1              = (string) ( $brief['h1'] ?? $obj->title );
		$obj->slug            = (string) ( $entry['url'] ?? '' );
		$obj->content_type    = (string) ( $entry['page_type'] ?? 'article' );
		$obj->primary_keyword = (string) ( $entry['primary_keyword'] ?? '' );
		$obj->secondary_keywords = (array) ( $entry['secondary'] ?? array() );
		$obj->search_intent   = (string) ( $entry['intent'] ?? ( $brief['search_intent'] ?? '' ) );
		$obj->content         = (string) ( $body['content_html'] ?? '' );
		$obj->faq             = (array) ( $body['faqs'] ?? array() );
		$obj->cta             = (string) ( $brief['cta'] ?? '' );
		$obj->image           = (array) ( $body['image'] ?? array() );

		// Derive intro (first paragraph) if not otherwise present.
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $obj->content, $m ) ) {
			$obj->intro = trim( $m[1] );
		}

		// Service / city / state from the entry/title where discernible.
		$obj->service = (string) ( $entry['parent'] ?? '' );
		if ( false !== strpos( $obj->title, '—' ) ) {
			$parts = array_map( 'trim', explode( '—', $obj->title ) );
			$obj->service = $obj->service ? $obj->service : ( $parts[0] ?? '' );
			$obj->city    = $parts[1] ?? '';
		}

		$obj->metadata = array(
			'meta_title'       => (string) ( $body['meta_title'] ?? '' ),
			'meta_description' => (string) ( $body['meta_description'] ?? '' ),
			'canonical_url'    => '',
			'og_title'         => (string) ( $body['og_title'] ?? '' ),
			'og_description'   => (string) ( $body['og_description'] ?? '' ),
			'image_alt'        => (string) ( $obj->image['alt'] ?? '' ),
		);

		return $obj;
	}

	/**
	 * Field/variable map for template placeholder replacement.
	 *
	 * Delegates to the central SCC_Template_Variables registry (Template Mapping
	 * 2.0) for type-aware resolution and escaping — a single source of truth — and
	 * keeps a few legacy keys for backward compatibility with older templates.
	 *
	 * @param array $context Optional extra context (business, links, related…).
	 * @return array TOKEN => escaped value.
	 */
	public function variables( array $context = array() ) {
		if ( class_exists( 'SCC_Template_Variables' ) ) {
			if ( empty( $context['business'] ) && class_exists( 'SCC_Schema_Engine' ) ) {
				$context['business'] = SCC_Schema_Engine::business();
			}
			$map = SCC_Template_Variables::render_map( $this, $context );

			// Legacy tokens some existing templates may still use.
			$map['SERVICE_DESCRIPTION'] = esc_html( wp_strip_all_tags( (string) $this->intro ) );
			$map['DATE_PUBLISHED']      = gmdate( 'c' );
			$map['DATE_MODIFIED']       = gmdate( 'c' );
			return $map;
		}

		// Fallback (registry unavailable): minimal legacy map.
		return array(
			'TITLE'           => esc_html( $this->title ),
			'H1'              => esc_html( $this->h1 ),
			'INTRO'           => wp_kses_post( $this->intro ),
			'CONTENT'         => wp_kses_post( $this->content ),
			'PRIMARY_KEYWORD' => esc_html( $this->primary_keyword ),
			'CTA'             => wp_kses_post( $this->cta ),
		);
	}

	/**
	 * Process steps as HTML.
	 *
	 * @return string
	 */
	protected function process_html() {
		if ( empty( $this->process ) ) {
			return '';
		}
		$items = array();
		foreach ( $this->process as $step ) {
			if ( is_array( $step ) ) {
				$items[] = '<li><strong>' . esc_html( $step['title'] ?? '' ) . '</strong> ' . esc_html( $step['description'] ?? '' ) . '</li>';
			} else {
				$items[] = '<li>' . esc_html( $step ) . '</li>';
			}
		}
		return '<ol>' . implode( '', $items ) . '</ol>';
	}

	/**
	 * Export to a plain array (for storage / preview).
	 *
	 * @return array
	 */
	public function to_array() {
		return get_object_vars( $this );
	}
}
