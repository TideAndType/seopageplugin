<?php
/**
 * Block Registry — the controlled library of Elementor building blocks the AI
 * Elementor Layout Engine is allowed to use.
 *
 * The AI (or the deterministic rules) may only choose block IDs that appear
 * here; nothing else is ever rendered. Each block carries the metadata the
 * layout engine, content mapper and renderer need: what content types and
 * search intents it suits, how many may appear, its declared variables, and a
 * render hint. The registry is filterable (`scc_layout_blocks`) so add-ons can
 * register more blocks without touching core.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor block registry.
 */
class SCC_Block_Registry {

	/** @var array<string,array>|null Cached, resolved registry. */
	protected static $cache = null;

	/**
	 * The full registry: block id => metadata. Cached per-request and filterable.
	 *
	 * Metadata keys:
	 *  - name, description, purpose : human-facing strings.
	 *  - content_types : array of content types this block suits, or ['*'].
	 *  - intents       : array of search intents this block suits, or ['*'].
	 *  - min, max      : recommended usage bounds per page (max 0 = unlimited).
	 *  - fields        : declared block variables (SERVICE_TITLE, QUESTION, …).
	 *  - repeatable    : may appear more than once on a page.
	 *  - supports_images / supports_cta / supports_links : capability flags.
	 *  - render        : renderer hint — 'text' | 'hero' | 'grid' | 'steps' |
	 *                    'stats' | 'faq' | 'cta' | 'html' | 'related'.
	 *  - required      : the layout validator guarantees at least one.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$b = array();

		$b['hero'] = array(
			'name'           => __( 'Hero', 'seo-command-center' ),
			'description'    => __( 'Page headline, supporting line and primary call to action.', 'seo-command-center' ),
			'purpose'        => 'open',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 1,
			'max'            => 1,
			'fields'         => array( 'HERO_TITLE', 'HERO_SUBTITLE', 'CTA_TEXT', 'CTA_URL' ),
			'repeatable'     => false,
			'supports_images'=> true,
			'supports_cta'   => true,
			'supports_links' => true,
			'render'         => 'hero',
			'required'       => true,
		);
		$b['content-intro'] = array(
			'name'           => __( 'Intro', 'seo-command-center' ),
			'description'    => __( 'Opening paragraph that frames the page and its keyword.', 'seo-command-center' ),
			'purpose'        => 'intro',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'INTRO' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'text',
			'required'       => false,
		);
		$b['toc'] = array(
			'name'           => __( 'Table of contents', 'seo-command-center' ),
			'description'    => __( 'Jump links to the main sections (articles).', 'seo-command-center' ),
			'purpose'        => 'navigation',
			'content_types'  => array( 'article', 'blog_post', 'informational' ),
			'intents'        => array( 'informational' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'TOC_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'html',
			'required'       => false,
		);
		$b['content'] = array(
			'name'           => __( 'Main content', 'seo-command-center' ),
			'description'    => __( 'The full article body with its H2/H3 hierarchy.', 'seo-command-center' ),
			'purpose'        => 'body',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'CONTENT' ),
			'repeatable'     => false,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'html',
			'required'       => false,
		);
		$b['split-content'] = array(
			'name'           => __( 'Split content', 'seo-command-center' ),
			'description'    => __( 'Text alongside a supporting image.', 'seo-command-center' ),
			'purpose'        => 'body',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 3,
			'fields'         => array( 'SPLIT_TITLE', 'SPLIT_TEXT', 'SPLIT_IMAGE' ),
			'repeatable'     => true,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'html',
			'required'       => false,
		);
		$b['image-content'] = array(
			'name'           => __( 'Image + content', 'seo-command-center' ),
			'description'    => __( 'A featured image with a block of explanatory text.', 'seo-command-center' ),
			'purpose'        => 'body',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 2,
			'fields'         => array( 'SPLIT_TITLE', 'SPLIT_TEXT', 'SPLIT_IMAGE' ),
			'repeatable'     => true,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'html',
			'required'       => false,
		);
		$b['benefits'] = array(
			'name'           => __( 'Benefits', 'seo-command-center' ),
			'description'    => __( 'A scannable list of the key benefits or value points.', 'seo-command-center' ),
			'purpose'        => 'value',
			'content_types'  => array( '*' ),
			'intents'        => array( 'commercial', 'transactional', 'local' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'BENEFIT_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => false,
			'render'         => 'list',
			'required'       => false,
		);
		$b['feature-grid'] = array(
			'name'           => __( 'Feature grid', 'seo-command-center' ),
			'description'    => __( 'A grid of features, each a title + short description.', 'seo-command-center' ),
			'purpose'        => 'features',
			'content_types'  => array( '*' ),
			'intents'        => array( 'commercial', 'transactional', 'local', 'informational' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'CARDS' ),
			'repeatable'     => false,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'grid',
			'required'       => false,
		);
		$b['service-grid'] = array(
			'name'           => __( 'Service grid', 'seo-command-center' ),
			'description'    => __( 'A grid of services, each a card with a title, blurb and link.', 'seo-command-center' ),
			'purpose'        => 'services',
			'content_types'  => array( 'service', 'local_service', 'landing' ),
			'intents'        => array( 'commercial', 'transactional', 'local' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'CARDS' ),
			'repeatable'     => false,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'grid',
			'required'       => false,
		);
		$b['service-cards'] = array(
			'name'           => __( 'Service cards', 'seo-command-center' ),
			'description'    => __( 'Prominent service cards (alias of the service grid).', 'seo-command-center' ),
			'purpose'        => 'services',
			'content_types'  => array( 'service', 'local_service', 'landing' ),
			'intents'        => array( 'commercial', 'transactional', 'local' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'CARDS' ),
			'repeatable'     => false,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'grid',
			'required'       => false,
		);
		$b['stats'] = array(
			'name'           => __( 'Stats', 'seo-command-center' ),
			'description'    => __( 'A row of headline numbers with labels.', 'seo-command-center' ),
			'purpose'        => 'proof',
			'content_types'  => array( '*' ),
			'intents'        => array( 'commercial', 'transactional', 'local' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'STATS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => false,
			'render'         => 'stats',
			'required'       => false,
		);
		$b['process-steps'] = array(
			'name'           => __( 'Process steps', 'seo-command-center' ),
			'description'    => __( 'A numbered, step-by-step process.', 'seo-command-center' ),
			'purpose'        => 'process',
			'content_types'  => array( 'service', 'local_service', 'landing', 'article' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'STEPS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => false,
			'render'         => 'steps',
			'required'       => false,
		);
		$b['testimonial'] = array(
			'name'           => __( 'Testimonial / proof', 'seo-command-center' ),
			'description'    => __( 'A trust/proof block — testimonial or credibility statement.', 'seo-command-center' ),
			'purpose'        => 'proof',
			'content_types'  => array( 'service', 'local_service', 'landing' ),
			'intents'        => array( 'commercial', 'transactional', 'local' ),
			'min'            => 0,
			'max'            => 2,
			'fields'         => array( 'QUOTE', 'ATTRIBUTION' ),
			'repeatable'     => true,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => false,
			'render'         => 'html',
			'required'       => false,
		);
		$b['comparison'] = array(
			'name'           => __( 'Comparison', 'seo-command-center' ),
			'description'    => __( 'A comparison table between options.', 'seo-command-center' ),
			'purpose'        => 'compare',
			'content_types'  => array( 'comparison', 'article', 'landing' ),
			'intents'        => array( 'commercial', 'informational' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'COMPARISON_HTML' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => false,
			'render'         => 'html',
			'required'       => false,
		);
		$b['highlight-box'] = array(
			'name'           => __( 'Highlight box', 'seo-command-center' ),
			'description'    => __( 'A callout that highlights a key takeaway.', 'seo-command-center' ),
			'purpose'        => 'callout',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 2,
			'fields'         => array( 'HIGHLIGHT_TITLE', 'HIGHLIGHT_TEXT' ),
			'repeatable'     => true,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'html',
			'required'       => false,
		);
		$b['faq'] = array(
			'name'           => __( 'FAQ', 'seo-command-center' ),
			'description'    => __( 'Accessible question/answer accordions (feeds FAQ schema).', 'seo-command-center' ),
			'purpose'        => 'faq',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'FAQ_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => false,
			'render'         => 'faq',
			'required'       => false,
		);
		$b['related-content'] = array(
			'name'           => __( 'Related content', 'seo-command-center' ),
			'description'    => __( 'Links to related pages/articles on the site.', 'seo-command-center' ),
			'purpose'        => 'related',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'RELATED_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'related',
			'required'       => false,
		);
		$b['blog-grid'] = array(
			'name'           => __( 'Blog grid', 'seo-command-center' ),
			'description'    => __( 'A grid of related blog posts.', 'seo-command-center' ),
			'purpose'        => 'related',
			'content_types'  => array( '*' ),
			'intents'        => array( 'informational' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'RELATED_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> true,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'related',
			'required'       => false,
		);
		$b['service-area'] = array(
			'name'           => __( 'Service area', 'seo-command-center' ),
			'description'    => __( 'The areas/cities served, as text or links.', 'seo-command-center' ),
			'purpose'        => 'local',
			'content_types'  => array( 'local_service', 'location', 'service' ),
			'intents'        => array( 'local', 'commercial', 'transactional' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'AREA_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'related',
			'required'       => false,
		);
		$b['location-grid'] = array(
			'name'           => __( 'Location grid', 'seo-command-center' ),
			'description'    => __( 'A grid of served locations, each linkable.', 'seo-command-center' ),
			'purpose'        => 'local',
			'content_types'  => array( 'local_service', 'location' ),
			'intents'        => array( 'local' ),
			'min'            => 0,
			'max'            => 1,
			'fields'         => array( 'SECTION_TITLE', 'AREA_ITEMS' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => false,
			'supports_links' => true,
			'render'         => 'related',
			'required'       => false,
		);
		$b['cta'] = array(
			'name'           => __( 'Call to action', 'seo-command-center' ),
			'description'    => __( 'A closing headline, supporting line and button.', 'seo-command-center' ),
			'purpose'        => 'close',
			'content_types'  => array( '*' ),
			'intents'        => array( '*' ),
			'min'            => 1,
			'max'            => 1,
			'fields'         => array( 'CTA_TITLE', 'CTA_TEXT', 'CTA_URL' ),
			'repeatable'     => false,
			'supports_images'=> false,
			'supports_cta'   => true,
			'supports_links' => true,
			'render'         => 'cta',
			'required'       => true,
		);

		/**
		 * Allow add-ons to register additional blocks (or override defaults).
		 * Each entry must be block_id => metadata array of the same shape.
		 *
		 * @param array $blocks The default registry.
		 */
		$b = apply_filters( 'scc_layout_blocks', $b );

		// Normalize: ensure every block has all keys with safe defaults, and a
		// stable 'id' mirroring its key.
		$out = array();
		foreach ( (array) $b as $id => $meta ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $meta ) ) {
				continue;
			}
			$out[ $id ] = self::normalize( $id, $meta );
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * Fill a block definition with safe defaults for any missing key.
	 *
	 * @param string $id   Block id.
	 * @param array  $meta Raw metadata.
	 * @return array
	 */
	protected static function normalize( $id, array $meta ) {
		return array(
			'id'              => $id,
			'name'            => (string) ( $meta['name'] ?? $id ),
			'description'     => (string) ( $meta['description'] ?? '' ),
			'purpose'         => (string) ( $meta['purpose'] ?? 'body' ),
			'content_types'   => self::str_list( $meta['content_types'] ?? array( '*' ) ),
			'intents'         => self::str_list( $meta['intents'] ?? array( '*' ) ),
			'min'             => max( 0, (int) ( $meta['min'] ?? 0 ) ),
			'max'             => max( 0, (int) ( $meta['max'] ?? 1 ) ),
			'fields'          => self::str_list( $meta['fields'] ?? array() ),
			'repeatable'      => ! empty( $meta['repeatable'] ),
			'supports_images' => ! empty( $meta['supports_images'] ),
			'supports_cta'    => ! empty( $meta['supports_cta'] ),
			'supports_links'  => ! empty( $meta['supports_links'] ),
			'render'          => (string) ( $meta['render'] ?? 'text' ),
			'required'        => ! empty( $meta['required'] ),
		);
	}

	/**
	 * Coerce a value to a list of non-empty strings.
	 *
	 * @param mixed $v Value.
	 * @return string[]
	 */
	protected static function str_list( $v ) {
		$v = is_array( $v ) ? $v : array( $v );
		return array_values( array_filter( array_map( 'strval', $v ), function ( $s ) { return '' !== $s; } ) );
	}

	/**
	 * All registered block IDs.
	 *
	 * @return string[]
	 */
	public static function ids() {
		return array_keys( self::all() );
	}

	/**
	 * Whether a block id is registered.
	 *
	 * @param string $id Block id.
	 * @return bool
	 */
	public static function exists( $id ) {
		$all = self::all();
		return isset( $all[ (string) $id ] );
	}

	/**
	 * Get one block definition, or null.
	 *
	 * @param string $id Block id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (string) $id ] ) ? $all[ (string) $id ] : null;
	}

	/**
	 * Block IDs the validator must guarantee are present (required blocks).
	 *
	 * @return string[]
	 */
	public static function required_ids() {
		$req = array();
		foreach ( self::all() as $id => $meta ) {
			if ( ! empty( $meta['required'] ) ) {
				$req[] = $id;
			}
		}
		return $req;
	}

	/**
	 * Reset the per-request cache (tests / after registering blocks late).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}
}
