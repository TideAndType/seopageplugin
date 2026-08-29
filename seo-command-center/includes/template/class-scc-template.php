<?php
/**
 * Native template model.
 *
 * A template is structure + fields, independent of any page builder. This class
 * wraps a stored row (or a default) and provides structure helpers.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template.
 */
class SCC_Template {

	/** Supported content/template types. */
	const TYPES = array(
		'article', 'service', 'location', 'location_service', 'landing',
		'case_study', 'about', 'faq', 'custom',
	);

	/** @var array The underlying row (decoded). */
	protected $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Row data (structure already decoded).
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Magic getter for row fields.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function __get( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	/**
	 * The ordered sections (each with fields).
	 *
	 * @return array
	 */
	public function sections() {
		$structure = isset( $this->data['structure'] ) ? $this->data['structure'] : array();
		if ( is_string( $structure ) ) {
			$structure = json_decode( $structure, true );
		}
		return isset( $structure['sections'] ) ? (array) $structure['sections'] : array();
	}

	/**
	 * All field keys referenced by the template.
	 *
	 * @return string[]
	 */
	public function field_keys() {
		$keys = array();
		foreach ( $this->sections() as $section ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( ! empty( $field['key'] ) ) {
					$keys[] = $field['key'];
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Default structure for a template/content type.
	 *
	 * @param string $type Content type.
	 * @return array {sections:[…]}
	 */
	public static function default_structure( $type ) {
		$f = function ( $key, $label, $ftype = 'richtext', $required = false, $variable = '' ) {
			return array( 'key' => $key, 'label' => $label, 'type' => $ftype, 'required' => (bool) $required, 'variable' => $variable );
		};

		switch ( $type ) {
			case 'location_service':
				$sections = array(
					array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array(
						$f( 'h1', 'H1', 'text', true, 'H1' ),
						$f( 'intro', 'Introduction', 'richtext', true, 'INTRO' ),
						$f( 'cta', 'CTA', 'richtext', false, 'CTA' ),
					) ),
					array( 'key' => 'service_overview', 'label' => 'Service Overview', 'fields' => array(
						$f( 'service_description', 'Service Description', 'richtext', false, 'SERVICE_DESCRIPTION' ),
						$f( 'benefits', 'Benefits', 'list', false, 'BENEFITS' ),
					) ),
					array( 'key' => 'why_us', 'label' => 'Why Choose Us', 'fields' => array( $f( 'content', 'Content', 'richtext', false, 'CONTENT' ) ) ),
					array( 'key' => 'process', 'label' => 'Process', 'fields' => array( $f( 'process', 'Process', 'list', false, 'PROCESS' ) ) ),
					array( 'key' => 'local', 'label' => 'Local Information', 'fields' => array( $f( 'local_content', 'Local Content', 'richtext', false, 'LOCAL_CONTENT' ) ) ),
					array( 'key' => 'faq', 'label' => 'FAQ', 'fields' => array( $f( 'faq', 'FAQ', 'faq', false, 'FAQ' ) ) ),
					array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array( $f( 'cta', 'CTA', 'richtext', false, 'CTA' ) ) ),
				);
				break;

			case 'service':
				$sections = array(
					array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array( $f( 'h1', 'H1', 'text', true, 'H1' ), $f( 'intro', 'Introduction', 'richtext', true, 'INTRO' ) ) ),
					array( 'key' => 'overview', 'label' => 'Service Overview', 'fields' => array( $f( 'content', 'Description', 'richtext', true, 'CONTENT' ), $f( 'benefits', 'Benefits', 'list', false, 'BENEFITS' ) ) ),
					array( 'key' => 'process', 'label' => 'Process', 'fields' => array( $f( 'process', 'Process', 'list', false, 'PROCESS' ) ) ),
					array( 'key' => 'faq', 'label' => 'FAQ', 'fields' => array( $f( 'faq', 'FAQ', 'faq', false, 'FAQ' ) ) ),
					array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array( $f( 'cta', 'CTA', 'richtext', false, 'CTA' ) ) ),
				);
				break;

			case 'location':
				$sections = array(
					array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array( $f( 'h1', 'H1', 'text', true, 'H1' ), $f( 'intro', 'Introduction', 'richtext', true, 'INTRO' ) ) ),
					array( 'key' => 'local', 'label' => 'Local Information', 'fields' => array( $f( 'local_content', 'Local Content', 'richtext', true, 'LOCAL_CONTENT' ) ) ),
					array( 'key' => 'faq', 'label' => 'FAQ', 'fields' => array( $f( 'faq', 'FAQ', 'faq', false, 'FAQ' ) ) ),
					array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array( $f( 'cta', 'CTA', 'richtext', false, 'CTA' ) ) ),
				);
				break;

			case 'landing':
				$sections = array(
					array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array( $f( 'h1', 'H1', 'text', true, 'H1' ), $f( 'intro', 'Introduction', 'richtext', true, 'INTRO' ), $f( 'cta', 'CTA', 'richtext', true, 'CTA' ) ) ),
					array( 'key' => 'benefits', 'label' => 'Benefits', 'fields' => array( $f( 'benefits', 'Benefits', 'list', false, 'BENEFITS' ) ) ),
					array( 'key' => 'cta2', 'label' => 'Closing CTA', 'fields' => array( $f( 'cta', 'CTA', 'richtext', false, 'CTA' ) ) ),
				);
				break;

			case 'faq':
				$sections = array(
					array( 'key' => 'hero', 'label' => 'Hero', 'fields' => array( $f( 'h1', 'H1', 'text', true, 'H1' ), $f( 'intro', 'Introduction', 'richtext', false, 'INTRO' ) ) ),
					array( 'key' => 'faq', 'label' => 'FAQ', 'fields' => array( $f( 'faq', 'FAQ', 'faq', true, 'FAQ' ) ) ),
				);
				break;

			case 'case_study':
			case 'about':
			case 'article':
			case 'custom':
			default:
				$sections = array(
					array( 'key' => 'body', 'label' => 'Body', 'fields' => array( $f( 'h1', 'H1', 'text', false, 'H1' ), $f( 'content', 'Content', 'richtext', true, 'CONTENT' ) ) ),
					array( 'key' => 'faq', 'label' => 'FAQ', 'fields' => array( $f( 'faq', 'FAQ', 'faq', false, 'FAQ' ) ) ),
					array( 'key' => 'cta', 'label' => 'CTA', 'fields' => array( $f( 'cta', 'CTA', 'richtext', false, 'CTA' ) ) ),
				);
		}

		return array( 'sections' => $sections );
	}

	/**
	 * A built-in fallback template (used when nothing is configured).
	 *
	 * @param string $content_type Content type.
	 * @return SCC_Template
	 */
	public static function fallback( $content_type = 'article' ) {
		return new self( array(
			'id'            => 0,
			'family'        => 'builtin-' . $content_type,
			'name'          => __( 'Built-in', 'seo-command-center' ),
			'content_type'  => $content_type,
			'template_type' => $content_type,
			'structure'     => self::default_structure( $content_type ),
			'renderer'      => '',
			'elementor_source_id' => 0,
			'status'        => 'active',
			'version'       => 1,
		) );
	}
}
