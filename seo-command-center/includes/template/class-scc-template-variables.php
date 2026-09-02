<?php
/**
 * Template Variables — the single authoritative registry of dynamic fields.
 *
 * Template Mapping 2.0 centralizes every {{TOKEN}} the plugin understands here:
 * its label, description, category, data type, whether it is rich text / HTML /
 * a URL / a list / a repeater, whether it is safe for a visible Elementor text
 * field, whether it is SEO-critical, and its fallback behavior. Modules extend
 * the set through the `scc_template_variables` filter — token definitions are
 * never scattered across the builder or renderers.
 *
 * The class also RESOLVES tokens from a structured SCC_Content_Object (produced
 * once by the generator) plus a light context array, and ESCAPES each value for
 * its declared type. Schema-typed tokens never render into visible widgets, and
 * custom token values are stripped of executable content — no scripts, no
 * shortcodes, no PHP.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template variable registry + resolver.
 */
class SCC_Template_Variables {

	// Data types.
	const TYPE_TEXT   = 'text';
	const TYPE_RICH   = 'rich';   // Rich text (limited inline HTML).
	const TYPE_HTML   = 'html';   // Block HTML.
	const TYPE_URL    = 'url';
	const TYPE_LIST   = 'list';   // Comma-joined list.
	const TYPE_SCHEMA = 'schema'; // Structured data — never visible text.

	/** Tokens required for a template to be considered "ready". */
	const REQUIRED = array( 'H1', 'CONTENT' );

	/**
	 * The full, filtered variable registry, keyed by TOKEN.
	 *
	 * @return array<string,array>
	 */
	public static function registry() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$T = self::TYPE_TEXT;
		$R = self::TYPE_RICH;
		$H = self::TYPE_HTML;
		$U = self::TYPE_URL;
		$L = self::TYPE_LIST;
		$S = self::TYPE_SCHEMA;

		// [label, description, category, type, seo_critical].
		$defs = array(
			// Core.
			'TITLE'        => array( 'Title', 'Page title.', 'core', $T, true ),
			'H1'           => array( 'H1', 'The single on-page H1 heading.', 'core', $T, true ),
			'INTRO'        => array( 'Intro', 'Opening paragraph.', 'core', $R, false ),
			'BODY'         => array( 'Body', 'Main body content (HTML).', 'core', $H, true ),
			'CONTENT'      => array( 'Content', 'Main body content (HTML).', 'core', $H, true ),
			'CONCLUSION'   => array( 'Conclusion', 'Closing paragraph.', 'core', $R, false ),
			// SEO / strategy.
			'PRIMARY_KEYWORD'    => array( 'Primary keyword', 'Primary target keyword from the SEO strategy.', 'seo', $T, true ),
			'SECONDARY_KEYWORDS' => array( 'Secondary keywords', 'Supporting keywords.', 'seo', $L, false ),
			'RELATED_KEYWORDS'   => array( 'Related keywords', 'Related terms / entities.', 'seo', $L, false ),
			'SEARCH_INTENT'      => array( 'Search intent', 'Dominant search intent.', 'seo', $T, false ),
			'TOPIC'              => array( 'Topic', 'Pillar topic.', 'seo', $T, false ),
			'SUBTOPIC'           => array( 'Subtopic', 'Supporting subtopic.', 'seo', $T, false ),
			'ENTITIES'           => array( 'Entities', 'Named entities for the page.', 'seo', $L, false ),
			// Local SEO.
			'SERVICE'         => array( 'Service', 'Service name.', 'local', $T, false ),
			'CITY'            => array( 'City', 'City.', 'local', $T, false ),
			'STATE'           => array( 'State', 'State / region.', 'local', $T, false ),
			'LOCATION'        => array( 'Location', 'City, State.', 'local', $T, false ),
			'SERVICE_AREA'    => array( 'Service area', 'Primary service area.', 'local', $T, false ),
			'NEARBY_LOCATIONS'=> array( 'Nearby locations', 'Nearby service areas.', 'local', $L, false ),
			// Content structure.
			'SECTION_HEADINGS'=> array( 'Section headings', 'H2 section headings.', 'content', $L, false ),
			'KEY_TAKEAWAYS'   => array( 'Key takeaways', 'Bullet takeaways (HTML).', 'content', $H, false ),
			'SUMMARY'         => array( 'Summary', 'Short summary.', 'content', $R, false ),
			'BENEFITS'        => array( 'Benefits', 'Benefit bullet list (HTML).', 'content', $H, false ),
			'PROCESS'         => array( 'Process', 'Process / steps (HTML).', 'content', $H, false ),
			'LOCAL_CONTENT'   => array( 'Local content', 'Locally-specific content (HTML).', 'content', $H, false ),
			// FAQ.
			'FAQ'          => array( 'FAQ', 'Full FAQ block (HTML accordion).', 'faq', $H, false ),
			'FAQ_1'        => array( 'FAQ 1', 'First FAQ (HTML).', 'faq', $H, false ),
			'FAQ_2'        => array( 'FAQ 2', 'Second FAQ (HTML).', 'faq', $H, false ),
			'FAQ_3'        => array( 'FAQ 3', 'Third FAQ (HTML).', 'faq', $H, false ),
			'FAQ_4'        => array( 'FAQ 4', 'Fourth FAQ (HTML).', 'faq', $H, false ),
			'FAQ_5'        => array( 'FAQ 5', 'Fifth FAQ (HTML).', 'faq', $H, false ),
			'QUESTIONS'    => array( 'Questions', 'FAQ questions only.', 'faq', $L, false ),
			'DIRECT_ANSWER'=> array( 'Direct answer', 'Concise answer to the primary question.', 'faq', $R, false ),
			// Conversion.
			'CTA'          => array( 'CTA', 'Call to action (HTML/text).', 'conversion', $R, false ),
			'CTA_TEXT'     => array( 'CTA text', 'Button label.', 'conversion', $T, false ),
			'CTA_URL'      => array( 'CTA URL', 'Button URL.', 'conversion', $U, false ),
			'PHONE'        => array( 'Phone', 'Business phone (verified data only).', 'conversion', $T, false ),
			'EMAIL'        => array( 'Email', 'Business email (verified data only).', 'conversion', $T, false ),
			'BUSINESS_NAME'=> array( 'Business name', 'Business / organization name.', 'conversion', $T, false ),
			// Internal linking.
			'INTERNAL_LINKS' => array( 'Internal links', 'Verified internal links (HTML).', 'links', $H, false ),
			'RELATED_CONTENT'=> array( 'Related content', 'Related pages (HTML).', 'links', $H, false ),
			'PARENT_PAGE'    => array( 'Parent page', 'Parent page link (HTML).', 'links', $H, false ),
			'CHILD_PAGES'    => array( 'Child pages', 'Child page links (HTML).', 'links', $H, false ),
			'RELATED_SERVICES'=> array( 'Related services', 'Related service links (HTML).', 'links', $H, false ),
			// Metadata.
			'META_TITLE'      => array( 'Meta title', 'SEO meta title.', 'metadata', $T, true ),
			'META_DESCRIPTION'=> array( 'Meta description', 'SEO meta description.', 'metadata', $T, true ),
			'SLUG'            => array( 'Slug', 'URL slug.', 'metadata', $T, false ),
			'CANONICAL_URL'   => array( 'Canonical URL', 'Canonical URL.', 'metadata', $U, false ),
			// Schema (never visible text).
			'SCHEMA'               => array( 'Schema', 'Full JSON-LD graph.', 'schema', $S, false ),
			'FAQ_SCHEMA'           => array( 'FAQ schema', 'FAQPage JSON-LD.', 'schema', $S, false ),
			'LOCAL_BUSINESS_SCHEMA'=> array( 'LocalBusiness schema', 'LocalBusiness JSON-LD.', 'schema', $S, false ),
			'SERVICE_SCHEMA'       => array( 'Service schema', 'Service JSON-LD.', 'schema', $S, false ),
			'ARTICLE_SCHEMA'       => array( 'Article schema', 'Article JSON-LD.', 'schema', $S, false ),
			'BREADCRUMB_SCHEMA'    => array( 'Breadcrumb schema', 'BreadcrumbList JSON-LD.', 'schema', $S, false ),
			// Trust / authority.
			'AUTHOR'       => array( 'Author', 'Author name.', 'authority', $T, false ),
			'AUTHOR_BIO'   => array( 'Author bio', 'Author biography (HTML).', 'authority', $H, false ),
			'CREDENTIALS'  => array( 'Credentials', 'Author / business credentials.', 'authority', $L, false ),
			'TRUST_SIGNALS'=> array( 'Trust signals', 'Trust signals (HTML).', 'authority', $H, false ),
			'LAST_UPDATED' => array( 'Last updated', 'Last-updated date.', 'authority', $T, false ),
			// Navigation.
			'BREADCRUMBS'      => array( 'Breadcrumbs', 'Breadcrumb trail (HTML).', 'navigation', $H, false ),
			'TABLE_OF_CONTENTS'=> array( 'Table of contents', 'On-page TOC (HTML).', 'navigation', $H, false ),
			'RELATED_PAGES'    => array( 'Related pages', 'Related page links (HTML).', 'navigation', $H, false ),
		);

		$registry = array();
		foreach ( $defs as $token => $d ) {
			$type = $d[3];
			$registry[ $token ] = array(
				'token'         => $token,
				'label'         => $d[0],
				'description'   => $d[1],
				'category'      => $d[2],
				'type'          => $type,
				'seo_critical'  => (bool) $d[4],
				'rich'          => self::TYPE_RICH === $type,
				'html'          => in_array( $type, array( self::TYPE_HTML, self::TYPE_RICH ), true ),
				'url'           => self::TYPE_URL === $type,
				'list'          => self::TYPE_LIST === $type,
				'repeater'      => in_array( $token, array( 'FAQ', 'INTERNAL_LINKS', 'RELATED_CONTENT', 'CHILD_PAGES', 'RELATED_SERVICES', 'RELATED_PAGES' ), true ),
				'schema'        => self::TYPE_SCHEMA === $type,
				'safe_for_text' => self::TYPE_SCHEMA !== $type && self::TYPE_URL !== $type,
				'safe_for_url'  => self::TYPE_URL === $type,
				'required'      => in_array( $token, self::REQUIRED, true ),
			);
		}

		/**
		 * Filter the template variable registry so modules can register tokens.
		 *
		 * @param array $registry Token => definition.
		 */
		$registry = apply_filters( 'scc_template_variables', $registry );
		$cache    = is_array( $registry ) ? $registry : array();
		return $cache;
	}

	/**
	 * Ordered category labels.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array(
			'core'       => __( 'Core', 'seo-command-center' ),
			'seo'        => __( 'SEO / Strategy', 'seo-command-center' ),
			'local'      => __( 'Local SEO', 'seo-command-center' ),
			'content'    => __( 'Content', 'seo-command-center' ),
			'faq'        => __( 'FAQ', 'seo-command-center' ),
			'conversion' => __( 'Conversion', 'seo-command-center' ),
			'links'      => __( 'Internal Links', 'seo-command-center' ),
			'metadata'   => __( 'Metadata', 'seo-command-center' ),
			'schema'     => __( 'Schema', 'seo-command-center' ),
			'authority'  => __( 'Authority', 'seo-command-center' ),
			'navigation' => __( 'Navigation', 'seo-command-center' ),
		);
	}

	/**
	 * A definition for one token (registry entry or a synthesized custom one).
	 *
	 * @param string $token Token name.
	 * @return array
	 */
	public static function definition( $token ) {
		$token = strtoupper( (string) $token );
		$reg   = self::registry();
		if ( isset( $reg[ $token ] ) ) {
			return $reg[ $token ];
		}
		// Unknown / custom token: safe text with an empty fallback.
		return array(
			'token'         => $token,
			'label'         => ucwords( strtolower( str_replace( '_', ' ', $token ) ) ),
			'description'   => __( 'Custom token.', 'seo-command-center' ),
			'category'      => 'custom',
			'type'          => self::TYPE_TEXT,
			'seo_critical'  => false,
			'rich'          => false,
			'html'          => false,
			'url'           => false,
			'list'          => false,
			'repeater'      => false,
			'schema'        => false,
			'safe_for_text' => true,
			'safe_for_url'  => false,
			'required'      => false,
			'custom'        => true,
		);
	}

	/**
	 * Detect tokens present in an Elementor element tree (delegates to the
	 * existing placeholder scanner so there is one detector).
	 *
	 * @param array $elements Decoded Elementor elements.
	 * @return string[]
	 */
	public static function detect( array $elements ) {
		return class_exists( 'SCC_Placeholders' ) ? SCC_Placeholders::detect( $elements ) : array();
	}

	/**
	 * Resolve every token to a RAW (unescaped) value from a content object plus
	 * an optional context (business info, internal links, related pages, schema).
	 *
	 * The generator builds the content object once; this consumes it. No token
	 * makes its own AI call.
	 *
	 * @param SCC_Content_Object $obj     Structured content.
	 * @param array              $context Extra context {business, internal_links, related, schema, author, ...}.
	 * @return array<string,mixed> TOKEN => raw value (string, or array for repeaters).
	 */
	public static function resolve( SCC_Content_Object $obj, array $context = array() ) {
		$business = isset( $context['business'] ) && is_array( $context['business'] ) ? $context['business'] : array();
		$meta     = is_array( $obj->metadata ) ? $obj->metadata : array();

		$faq_html   = self::faq_html( $obj->faq );
		$faqs       = self::normalize_faq( $obj->faq );
		$intro      = '' !== $obj->intro ? $obj->intro : '';
		$section_h  = self::section_headings( $obj );
		$location   = trim( implode( ', ', array_filter( array( $obj->city, $obj->state ) ) ), ', ' );

		$values = array(
			// Core.
			'TITLE'        => $obj->title,
			'H1'           => '' !== $obj->h1 ? $obj->h1 : $obj->title,
			'INTRO'        => $intro,
			'BODY'         => $obj->content,
			'CONTENT'      => $obj->content,
			'CONCLUSION'   => (string) ( $context['conclusion'] ?? '' ),
			// SEO.
			'PRIMARY_KEYWORD'    => $obj->primary_keyword,
			'SECONDARY_KEYWORDS' => (array) $obj->secondary_keywords,
			'RELATED_KEYWORDS'   => (array) ( $context['related_keywords'] ?? array() ),
			'SEARCH_INTENT'      => $obj->search_intent,
			'TOPIC'              => (string) ( $context['topic'] ?? $obj->service ),
			'SUBTOPIC'           => (string) ( $context['subtopic'] ?? '' ),
			'ENTITIES'           => (array) ( $context['entities'] ?? array() ),
			// Local.
			'SERVICE'         => $obj->service,
			'CITY'            => $obj->city,
			'STATE'           => $obj->state,
			'LOCATION'        => $location,
			'SERVICE_AREA'    => (string) ( $business['service_areas'][0] ?? '' ),
			'NEARBY_LOCATIONS'=> (array) ( $business['service_areas'] ?? array() ),
			// Structure.
			'SECTION_HEADINGS'=> $section_h,
			'KEY_TAKEAWAYS'   => self::list_html( (array) ( $context['takeaways'] ?? $obj->benefits ) ),
			'SUMMARY'         => (string) ( $context['summary'] ?? '' ),
			'BENEFITS'        => self::list_html( (array) $obj->benefits ),
			'PROCESS'         => self::process_html( (array) $obj->process ),
			'LOCAL_CONTENT'   => $obj->local_content,
			// FAQ.
			'FAQ'           => $faq_html,
			'FAQ_1'         => self::faq_one( $faqs, 0 ),
			'FAQ_2'         => self::faq_one( $faqs, 1 ),
			'FAQ_3'         => self::faq_one( $faqs, 2 ),
			'FAQ_4'         => self::faq_one( $faqs, 3 ),
			'FAQ_5'         => self::faq_one( $faqs, 4 ),
			'QUESTIONS'     => array_map( function ( $f ) { return $f['question']; }, $faqs ),
			'DIRECT_ANSWER' => isset( $faqs[0] ) ? $faqs[0]['answer'] : '',
			// Conversion.
			'CTA'           => $obj->cta,
			'CTA_TEXT'      => (string) ( $context['cta_text'] ?? '' ),
			'CTA_URL'       => (string) ( $context['cta_url'] ?? '' ),
			'PHONE'         => (string) ( $business['phone'] ?? '' ),
			'EMAIL'         => (string) ( $business['email'] ?? '' ),
			'BUSINESS_NAME' => (string) ( $business['organization_name'] ?? '' ),
			// Links (verified only — resolver never invents URLs).
			'INTERNAL_LINKS'  => self::links_html( (array) ( $context['internal_links'] ?? $obj->internal_links ) ),
			'RELATED_CONTENT' => self::links_html( (array) ( $context['related'] ?? array() ) ),
			'PARENT_PAGE'     => self::links_html( isset( $context['parent'] ) ? array( $context['parent'] ) : array() ),
			'CHILD_PAGES'     => self::links_html( (array) ( $context['children'] ?? array() ) ),
			'RELATED_SERVICES'=> self::links_html( (array) ( $context['related_services'] ?? array() ) ),
			'RELATED_PAGES'   => self::links_html( (array) ( $context['related'] ?? array() ) ),
			// Metadata.
			'META_TITLE'      => (string) ( $meta['meta_title'] ?? '' ),
			'META_DESCRIPTION'=> (string) ( $meta['meta_description'] ?? '' ),
			'SLUG'            => $obj->slug,
			'CANONICAL_URL'   => (string) ( $meta['canonical_url'] ?? '' ),
			// Schema — kept as data, never rendered into visible widgets.
			'SCHEMA'               => '',
			'FAQ_SCHEMA'           => '',
			'LOCAL_BUSINESS_SCHEMA'=> '',
			'SERVICE_SCHEMA'       => '',
			'ARTICLE_SCHEMA'       => '',
			'BREADCRUMB_SCHEMA'    => '',
			// Authority.
			'AUTHOR'       => (string) ( $meta['author'] ?? ( $context['author'] ?? '' ) ),
			'AUTHOR_BIO'   => (string) ( $context['author_bio'] ?? '' ),
			'CREDENTIALS'  => (array) ( $context['credentials'] ?? array() ),
			'TRUST_SIGNALS'=> (string) ( $context['trust_signals'] ?? '' ),
			'LAST_UPDATED' => (string) ( $context['last_updated'] ?? '' ),
			// Navigation.
			'BREADCRUMBS'      => (string) ( $context['breadcrumbs'] ?? '' ),
			'TABLE_OF_CONTENTS'=> (string) ( $context['toc'] ?? '' ),
		);

		/**
		 * Filter resolved raw token values before escaping.
		 *
		 * @param array              $values  TOKEN => raw value.
		 * @param SCC_Content_Object $obj     Content object.
		 * @param array              $context Context.
		 */
		return apply_filters( 'scc_template_resolved_values', $values, $obj, $context );
	}

	/**
	 * Build the final, type-escaped TOKEN => string map that is safe to drop into
	 * Elementor widgets. Feed the result to SCC_Placeholders::replace().
	 *
	 * @param SCC_Content_Object $obj     Content object.
	 * @param array              $context Context.
	 * @param string[]           $tokens  Tokens actually present in the template (optional; used to include custom tokens).
	 * @return array<string,string>
	 */
	public static function render_map( SCC_Content_Object $obj, array $context = array(), array $tokens = array() ) {
		$raw = self::resolve( $obj, $context );
		$map = array();

		foreach ( $raw as $token => $value ) {
			$map[ $token ] = self::escape_value( $token, $value );
		}

		// Custom tokens present in the template but not in the registry: pull a
		// value from context if provided, else fall back to empty (never leak the
		// raw token). Values are stripped of executable content.
		foreach ( $tokens as $token ) {
			$token = strtoupper( $token );
			if ( isset( $map[ $token ] ) ) {
				continue;
			}
			$provided     = $context['custom'][ $token ] ?? ( $context[ strtolower( $token ) ] ?? '' );
			$map[ $token ] = esc_html( self::sanitize_custom_value( (string) ( is_scalar( $provided ) ? $provided : '' ) ) );
		}

		return $map;
	}

	/**
	 * Escape a single resolved value for its declared type.
	 *
	 * @param string $token Token.
	 * @param mixed  $value Raw value.
	 * @return string
	 */
	public static function escape_value( $token, $value ) {
		$def  = self::definition( $token );
		$type = $def['type'];

		switch ( $type ) {
			case self::TYPE_SCHEMA:
				// Structured data must never render as visible page text.
				return '';
			case self::TYPE_URL:
				return esc_url( is_scalar( $value ) ? (string) $value : '' );
			case self::TYPE_LIST:
				$items = is_array( $value ) ? $value : preg_split( '/\s*,\s*/', (string) $value );
				$items = array_filter( array_map( 'trim', (array) $items ) );
				return implode( ', ', array_map( 'esc_html', $items ) );
			case self::TYPE_HTML:
			case self::TYPE_RICH:
				// Already-built internal HTML is trusted; still run through kses so
				// no script/iframe survives from any upstream value.
				return wp_kses_post( is_scalar( $value ) ? (string) $value : '' );
			case self::TYPE_TEXT:
			default:
				return esc_html( is_scalar( $value ) ? (string) $value : '' );
		}
	}

	/**
	 * Validate a template's tokens against the registry and content rules.
	 *
	 * @param array    $elements Decoded Elementor elements.
	 * @param string[] $required Extra required tokens (e.g. CTA for a service page).
	 * @return array {status, errors[], warnings[], detected[], required[], optional[]}
	 */
	public static function validate_template( array $elements, array $required = array() ) {
		$detected = self::detect( $elements );
		$required = array_values( array_unique( array_merge( self::REQUIRED, array_map( 'strtoupper', $required ) ) ) );

		$errors   = array();
		$warnings = array();

		$present = array_map( 'strtoupper', $detected );

		// Required tokens present?
		foreach ( $required as $req ) {
			// CONTENT and BODY are interchangeable for the "main content" rule.
			if ( 'CONTENT' === $req && ( in_array( 'CONTENT', $present, true ) || in_array( 'BODY', $present, true ) ) ) {
				continue;
			}
			if ( ! in_array( $req, $present, true ) ) {
				$errors[] = sprintf( /* translators: token */ __( 'Missing required field: %s', 'seo-command-center' ), $req );
			}
		}

		// Duplicate H1 placement.
		$h1_count = self::count_token( $elements, 'H1' );
		if ( $h1_count > 1 ) {
			$warnings[] = sprintf( /* translators: count */ __( 'H1 is placed %d times — a page should have exactly one H1.', 'seo-command-center' ), $h1_count );
		}

		// Schema tokens placed in a visible widget.
		foreach ( $present as $token ) {
			$def = self::definition( $token );
			if ( ! empty( $def['schema'] ) ) {
				$warnings[] = sprintf( /* translators: token */ __( '%s is structured data and will not render as visible text — schema is added to the page head automatically.', 'seo-command-center' ), $token );
			}
			if ( ! empty( $def['custom'] ) ) {
				$warnings[] = sprintf( /* translators: token */ __( 'Unknown/custom token %s — it will be filled from a custom value or cleared.', 'seo-command-center' ), $token );
			}
		}

		$optional = array_values( array_diff( $present, $required ) );

		return array(
			'status'   => empty( $errors ) ? 'ready' : 'attention',
			'errors'   => $errors,
			'warnings' => $warnings,
			'detected' => $present,
			'required' => $required,
			'optional' => $optional,
		);
	}

	/**
	 * Strip executable / unsafe content from a custom token value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_custom_value( $value ) {
		$value = (string) $value;
		// Remove shortcodes and any script/style/php-ish markup.
		$value = preg_replace( '/\[[^\]]*\]/', '', $value );
		$value = preg_replace( '#<\s*(script|style|iframe|object|embed|form)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $value );
		$value = preg_replace( '/<\?.*?\?>/s', '', $value );
		return trim( (string) $value );
	}

	// ---------------------------------------------------------------------
	// Value builders (produce trusted internal HTML from structured data).
	// ---------------------------------------------------------------------

	/**
	 * Normalize FAQ items to [{question, answer}].
	 *
	 * @param array $faq FAQ array.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function normalize_faq( array $faq ) {
		$out = array();
		foreach ( $faq as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = trim( (string) ( $item['question'] ?? '' ) );
			$a = trim( (string) ( $item['answer'] ?? '' ) );
			if ( '' !== $q ) {
				$out[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		return $out;
	}

	/**
	 * Full FAQ block as an accessible accordion (details/summary).
	 *
	 * @param array $faq FAQ array.
	 * @return string
	 */
	protected static function faq_html( array $faq ) {
		$faqs = self::normalize_faq( $faq );
		if ( empty( $faqs ) ) {
			return '';
		}
		$html = '<div class="scc-faq">';
		foreach ( $faqs as $f ) {
			$html .= '<details class="scc-faq__item"><summary>' . esc_html( $f['question'] ) . '</summary><div class="scc-faq__a"><p>' . esc_html( $f['answer'] ) . '</p></div></details>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * A single FAQ item as HTML.
	 *
	 * @param array $faqs Normalized FAQ.
	 * @param int   $i    Index.
	 * @return string
	 */
	protected static function faq_one( array $faqs, $i ) {
		if ( ! isset( $faqs[ $i ] ) ) {
			return '';
		}
		return '<h3>' . esc_html( $faqs[ $i ]['question'] ) . '</h3><p>' . esc_html( $faqs[ $i ]['answer'] ) . '</p>';
	}

	/**
	 * A bullet list as HTML.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	protected static function list_html( array $items ) {
		$items = array_filter( array_map( 'trim', array_map( 'strval', $items ) ) );
		if ( empty( $items ) ) {
			return '';
		}
		return '<ul>' . implode( '', array_map( function ( $i ) {
			return '<li>' . esc_html( $i ) . '</li>';
		}, $items ) ) . '</ul>';
	}

	/**
	 * Process steps as an ordered list.
	 *
	 * @param array $steps Steps.
	 * @return string
	 */
	protected static function process_html( array $steps ) {
		if ( empty( $steps ) ) {
			return '';
		}
		$items = array();
		foreach ( $steps as $step ) {
			if ( is_array( $step ) ) {
				$items[] = '<li><strong>' . esc_html( $step['title'] ?? '' ) . '</strong> ' . esc_html( $step['description'] ?? '' ) . '</li>';
			} else {
				$items[] = '<li>' . esc_html( (string) $step ) . '</li>';
			}
		}
		return '<ol>' . implode( '', $items ) . '</ol>';
	}

	/**
	 * Verified internal links as HTML. Only outputs entries that carry a real URL
	 * — never invents one.
	 *
	 * @param array $links Links [{url, anchor|title, description}].
	 * @return string
	 */
	protected static function links_html( array $links ) {
		$items = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = esc_url( (string) ( $link['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$text = trim( (string) ( $link['anchor'] ?? $link['title'] ?? '' ) );
			if ( '' === $text ) {
				$text = $url;
			}
			$desc = trim( (string) ( $link['description'] ?? '' ) );
			$li   = '<li><a href="' . $url . '">' . esc_html( $text ) . '</a>';
			if ( '' !== $desc ) {
				$li .= ' <span class="scc-rel-desc">' . esc_html( $desc ) . '</span>';
			}
			$li .= '</li>';
			$items[] = $li;
		}
		return empty( $items ) ? '' : '<ul class="scc-related">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * H2 section headings from the content object.
	 *
	 * @param SCC_Content_Object $obj Content object.
	 * @return string[]
	 */
	protected static function section_headings( SCC_Content_Object $obj ) {
		$heads = array();
		foreach ( (array) $obj->sections as $s ) {
			if ( is_array( $s ) && ! empty( $s['heading'] ) ) {
				$heads[] = (string) $s['heading'];
			}
		}
		if ( empty( $heads ) && '' !== $obj->content && preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $obj->content, $m ) ) {
			$heads = array_map( 'wp_strip_all_tags', $m[1] );
		}
		return $heads;
	}

	/**
	 * Count occurrences of a token across the tree.
	 *
	 * @param array  $elements Elements.
	 * @param string $token    Token.
	 * @return int
	 */
	protected static function count_token( array $elements, $token ) {
		$count = 0;
		$re    = '/\{\{\s*' . preg_quote( strtoupper( $token ), '/' ) . '\s*\}\}/';
		array_walk_recursive( $elements, function ( $value ) use ( &$count, $re ) {
			if ( is_string( $value ) ) {
				$count += preg_match_all( $re, $value );
			}
		} );
		return $count;
	}
}
