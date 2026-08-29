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
	 * @return array TOKEN => value.
	 */
	public function variables() {
		$benefits_html = '';
		if ( ! empty( $this->benefits ) ) {
			$benefits_html = '<ul>' . implode( '', array_map( function ( $b ) {
				return '<li>' . esc_html( $b ) . '</li>';
			}, $this->benefits ) ) . '</ul>';
		}

		$faq_html = '';
		foreach ( $this->faq as $faq ) {
			$q = is_array( $faq ) ? ( $faq['question'] ?? '' ) : '';
			$a = is_array( $faq ) ? ( $faq['answer'] ?? '' ) : '';
			if ( '' !== $q ) {
				$faq_html .= '<h3>' . esc_html( $q ) . '</h3><p>' . esc_html( $a ) . '</p>';
			}
		}

		return array(
			'TITLE'              => $this->title,
			'H1'                 => $this->h1,
			'INTRO'              => $this->intro,
			'CONTENT'            => $this->content,
			'SERVICE'            => $this->service,
			'SERVICE_DESCRIPTION'=> $this->intro,
			'CITY'               => $this->city,
			'STATE'              => $this->state,
			'PRIMARY_KEYWORD'    => $this->primary_keyword,
			'SECONDARY_KEYWORDS' => implode( ', ', (array) $this->secondary_keywords ),
			'BENEFITS'           => $benefits_html,
			'PROCESS'            => $this->process_html(),
			'LOCAL_CONTENT'      => $this->local_content,
			'FAQ'                => $faq_html,
			'CTA'                => $this->cta,
			'AUTHOR'             => (string) ( $this->metadata['author'] ?? '' ),
			'DATE_PUBLISHED'     => gmdate( 'c' ),
			'DATE_MODIFIED'      => gmdate( 'c' ),
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
