<?php
/**
 * Multi-step content generator.
 *
 * Pipeline: brief (approve) -> draft body + metadata (AI) -> schema (validated)
 * -> WordPress draft (wp_insert_post) -> metadata applied -> quality score.
 *
 * Content is saved as a DRAFT by default. It is only published when the user
 * has explicitly enabled automatic publishing in Settings.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generator.
 */
class SCC_Generator {

	/**
	 * Content types that generate as a NORMAL, native WordPress post/page:
	 * plain post_content, no template, no tokens, no page builder. This is the
	 * default path — "normal WordPress, with an SEO layer" — and it is never
	 * pulled into Elementor even if a builder is the site default renderer.
	 *
	 * @var string[]
	 */
	const NATIVE_TYPES = array( 'article', 'blog', 'blog_post', 'post', '' );

	/** @var SCC_AI_Manager */
	protected $ai;

	/** @var SCC_Renderer_Manager */
	protected $renderers;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager            $ai        AI manager.
	 * @param SCC_Renderer_Manager|null $renderers Renderer manager (optional).
	 */
	public function __construct( SCC_AI_Manager $ai, $renderers = null ) {
		$this->ai        = $ai;
		$this->renderers = $renderers instanceof SCC_Renderer_Manager ? $renderers : new SCC_Renderer_Manager();
	}

	/**
	 * Weave a few high-confidence, naturally-placeable internal links into the
	 * content object's body before rendering (renderer-independent).
	 *
	 * @param SCC_Content_Object $content Content object.
	 * @return array The link opportunities used.
	 */
	protected function weave_internal_links( SCC_Content_Object $content ) {
		$max = (int) SCC_Settings::get( 'max_internal_links', 8 );
		$engine = new SCC_Link_Engine();
		$links  = $engine->opportunities_for_content( $content, $max );
		if ( empty( $links ) ) {
			return array();
		}
		$inserter = new SCC_Link_Inserter();
		foreach ( $links as $link ) {
			$content->content = $inserter->insert_link_in_html( $content->content, $link['anchor'], $link['target_url'] );
		}
		return $links;
	}

	/**
	 * Generate a draft for a content-plan entry.
	 *
	 * @param array      $entry Decoded content-plan row.
	 * @param array|null $brief Optional approved brief; generated if null.
	 * @return array|WP_Error {post_id, edit_url, score, status}
	 */
	/**
	 * Build a minimal brief from a content-plan entry, with no AI call. Used for
	 * one-click "Generate draft" so generation is a single, fast AI request.
	 *
	 * @param array $entry Content-plan entry.
	 * @return array Brief-shaped array.
	 */
	protected function synthesize_brief( array $entry ) {
		$words = (int) ( $entry['word_count'] ?? 0 );
		if ( $words < 300 ) {
			$words = (int) SCC_Settings::get( 'default_word_count', 1200 );
		}
		$secondary = $entry['secondary'] ?? array();
		if ( ! is_array( $secondary ) ) {
			$secondary = array();
		}
		return array(
			'h1'                      => (string) ( $entry['title'] ?? ( $entry['primary_keyword'] ?? '' ) ),
			'search_intent'           => (string) ( $entry['intent'] ?? 'informational' ),
			'summary'                 => '',
			'recommended_words'       => $words,
			'primary_keyword'         => (string) ( $entry['primary_keyword'] ?? '' ),
			'secondary'               => array_values( array_filter( array_map( 'strval', $secondary ) ) ),
			'tone'                    => (string) ( $entry['tone'] ?? '' ),
			'location'                => (string) ( $entry['location'] ?? ( $entry['city'] ?? '' ) ),
			'outline'                 => array(),
			'entities'                => array(),
			'questions'               => array(),
			'internal_link_targets'   => array(),
			'external_reference_types' => array(),
			'cta'                     => '',
		);
	}

	public function generate( array $entry, $brief = null ) {
		if ( null === $brief ) {
			// Build the draft directly from the plan entry (one AI call). We do
			// NOT make a separate brief AI call here — that doubled the time and
			// was a common cause of timeouts on local models. Preview "Brief"
			// first if you want a full brief to guide generation.
			$brief = $this->synthesize_brief( $entry );
		}

		$body = $this->generate_body( $entry, $brief );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// --- Content + Template + Renderer layers (CMS-agnostic) -----------
		// Build the standardized, renderer-independent content object.
		$content = SCC_Content_Object::from_generation( $entry, $body, $brief );

		// Internal links operate on the content object BEFORE rendering.
		$content->internal_links = $this->weave_internal_links( $content );

		$manual_family = isset( $entry['template_family'] ) ? (string) $entry['template_family'] : '';

		if ( self::is_native_mode( $content->content_type, $manual_family ) ) {
			// NORMAL mode: a normal WordPress post. The AI body (already sanitized
			// with FAQs appended, and with NO in-body <h1> — the theme renders the
			// title as H1) becomes the post_content verbatim. No template, no
			// tokens, no page builder.
			$template = SCC_Template::fallback( $content->content_type );
			$rendered = $this->render_native( $content );
			$renderer_id   = 'wordpress';
			$used_elementor = false;
		} else {
			// TEMPLATE mode: structured page through the template + renderer layer.
			$selection = SCC_Template_Selector::select( $content->content_type, $manual_family );
			$template  = $selection['template'];
			$preferred = SCC_Template_Selector::renderer_for( $content->content_type, $template );
			$renderer  = $this->renderers->pick( $preferred, $content->content_type );

			$rendered = $renderer->render( $content, $template );
			if ( is_wp_error( $rendered ) ) {
				// Safety net: never fail the whole run because a builder errored.
				SCC_Logger::error( 'generator', 'Renderer failed, using native WP: ' . $rendered->get_error_message() );
				$renderer = new SCC_WordPress_Renderer();
				$rendered = $renderer->render( $content, $template );
			}
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$renderer_id   = $renderer->get_id();
			$used_elementor = ( 'elementor' === $renderer_id );
		}

		$post_type = self::post_type_for( $content->content_type );
		$status    = SCC_Settings::get( 'auto_publish', false ) ? 'publish' : 'draft';
		$slug      = ! empty( $rendered['post_name'] ) ? $rendered['post_name'] : $this->slug_from_url( $entry['url'] ?? '', $content->title );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $content->title,
				'post_content' => $rendered['post_content'],
				'post_status'  => $status,
				'post_type'    => $post_type,
				'post_name'    => $slug,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			SCC_Logger::error( 'generator', 'wp_insert_post failed: ' . $post_id->get_error_message() );
			return $post_id;
		}

		// Apply renderer-provided post meta (e.g. duplicated _elementor_data).
		// The source template is never modified.
		foreach ( (array) $rendered['post_meta'] as $meta_key => $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value );
		}
		update_post_meta( $post_id, '_scc_renderer', $renderer_id );
		update_post_meta( $post_id, '_scc_template', $template->family );
		$used_renderer = $renderer_id;

		// Native WordPress taxonomy + excerpt (uses core taxonomies, never a new
		// storage system). Categories are matched to EXISTING terms only, so we
		// never spawn duplicate categories; tags come from the keywords.
		$this->apply_taxonomy_and_excerpt( $post_id, $post_type, $entry, $body, $content );

		// Metadata (non-destructive).
		SCC_Metadata::apply(
			$post_id,
			array(
				'meta_title'       => $body['meta_title'],
				'meta_description' => $body['meta_description'],
				'og_title'         => $body['og_title'],
				'og_description'   => $body['og_description'],
				'image_alt'        => $body['image']['alt'] ?? '',
			),
			false
		);

		// Schema (validated, non-duplicate).
		$has_schema = $this->maybe_attach_schema( $post_id, $entry, $body );

		// Image recommendation (never auto-downloads copyrighted media).
		if ( ! empty( $body['image'] ) ) {
			update_post_meta( $post_id, '_scc_image_recommendation', wp_json_encode( $body['image'] ) );
		}

		// Store the brief for reference / regeneration.
		update_post_meta( $post_id, '_scc_brief', wp_json_encode( $brief ) );
		update_post_meta( $post_id, '_scc_generated', current_time( 'mysql' ) );

		// Quality score (scored on the actual rendered content).
		$score = SCC_Quality_Score::score(
			array(
				'html'             => $rendered['post_content'],
				'brief'            => $brief,
				'meta_title'       => $body['meta_title'],
				'meta_description' => $body['meta_description'],
				'faqs'             => $body['faqs'],
				'has_schema'       => $has_schema,
				'cta'              => $brief['cta'] ?? '',
			)
		);
		update_post_meta( $post_id, '_scc_quality_score', (int) $score['score'] );
		update_post_meta( $post_id, '_scc_quality_factors', wp_json_encode( $score['factors'] ) );

		// Link plan entry to the post + advance status.
		if ( ! empty( $entry['id'] ) ) {
			SCC_DB::update(
				'content_plan',
				array(
					'post_id' => $post_id,
					'status'  => ( 'publish' === $status ) ? 'published' : 'draft',
				),
				array( 'id' => (int) $entry['id'] )
			);
		}

		SCC_Logger::info( 'generator', 'Draft created', array( 'post_id' => $post_id, 'status' => $status, 'score' => $score['score'], 'renderer' => $used_renderer, 'template' => $template->family, 'mode' => ( 'native' === $template->family || self::is_native_mode( $content->content_type, $manual_family ) ) ? 'native' : 'template' ) );

		return array(
			'post_id'   => $post_id,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'view_url'  => get_permalink( $post_id ),
			'status'    => $status,
			'score'     => $score,
			'title'     => $content->title,
			'renderer'  => $used_renderer,
			'template'  => $template->name,
			'elementor' => $used_elementor,
			'links'     => count( (array) $content->internal_links ),
		);
	}

	/**
	 * Generate the article body + metadata via the AI layer (one JSON call).
	 *
	 * @param array $entry Plan entry.
	 * @param array $brief Brief.
	 * @return array|WP_Error
	 */
	protected function generate_body( array $entry, array $brief ) {
		$page_type   = $entry['page_type'] ?? 'article';
		$intent      = strtolower( (string) ( $entry['intent'] ?? ( $brief['search_intent'] ?? '' ) ) );
		$tone        = trim( (string) ( $brief['tone'] ?? '' ) );
		$location    = trim( (string) ( $brief['location'] ?? '' ) );
		$is_local    = ( 'location' === $page_type ) || ( false !== strpos( $intent, 'local' ) ) || ( '' !== $location );
		$commercial  = in_array( $intent, array( 'commercial', 'transactional', 'local' ), true )
			|| in_array( $page_type, array( 'pillar', 'service', 'location' ), true );
		$site_name   = get_bloginfo( 'name' );

		$system    = 'You are a senior SEO copywriter and subject-matter expert writing for "' . $site_name . '". '
			. 'Produce genuinely useful, specific, original content a knowledgeable buyer would trust. '
			// Accuracy & E-E-A-T.
			. 'ACCURACY & E-E-A-T: write from real, practical expertise; use concrete specifics, numbers, steps and trade-offs; '
			. 'never invent facts, statistics, prices, awards, clients or testimonials. Use current, correct terminology '
			. '(for example "Google Business Profile", never "GMB" or "GBP"). Do NOT repeat SEO myths or folklore tactics '
			. '(for example, do not claim that geotagging images improves rankings). '
			// No overpromising.
			. 'NO OVERPROMISING: never promise or imply guaranteed results, number-one rankings, or "undeniable authority", and '
			. 'do not phrase anything as a guarantee. Instead describe how the work builds stronger signals of relevance, trust '
			. 'and authority, the process involved, and realistic expectations, with honest caveats. '
			// Style / no dashes / no cliches / no duplicate intro.
			. 'STYLE: natural, credible, plain language; short paragraphs; concrete over hypey. Do NOT use em or en dashes (— or –); '
			. 'use commas, periods or parentheses. Avoid marketing-bro or AI cliches such as "acquisition machine", "unlock", '
			. '"in today\'s digital landscape", "game-changer", "supercharge", "leverage". No keyword stuffing, no padding, no '
			. 'repeated sentences. Never restate the introduction or add a generic "Overview" section that duplicates the opening. '
			// Title / hierarchy.
			. 'TITLE: make "title" outcome-oriented and LEAD with the primary commercial keyword the page targets (see the brief) '
			. 'so the topic is unmistakable; keep any clever tagline as a short supporting line inside the opening, not as the title. '
			// Local.
			. ( $is_local
				? 'LOCAL: real local relevance means genuinely useful, locally-specific content and expertise, not swapping in '
				  . 'city or neighbourhood names. Where natural, use service-plus-location phrasing (for example "Emergency Plumbing '
				  . 'in Daytona Beach") and real local angles, not filler. '
				: '' )
			// Structure for service / money pages vs. articles.
			. ( $commercial
				? 'THIS IS A SERVICE / MONEY PAGE. Structure it to inform AND convert: '
				  . '(1) open by naming the reader\'s problem and the outcome they want (calls, foot traffic, leads, visibility, '
				  . 'local authority) and sell outcomes, not keywords; '
				  . '(2) present a clear multi-step PROCESS using <h2> sections (for example Audit, Optimization and Fixes, '
				  . 'Authority Building, Measurement and Growth) so it reads like a real engagement, not "we do SEO, call us"; '
				  . '(3) include a concrete "What is included" section that groups the specific things you optimise into labelled '
				  . '<h3> groups with <ul> bullet lists (only groups relevant to this topic), covering ongoing management and '
				  . 'competitor / market analysis as distinct items where they fit; '
				  . '(4) add real trust signals and answer the top objections a buyer has; '
				  . '(5) finish with ONE specific call to action tied to the offer in the brief (for example a free audit) telling '
				  . 'the reader exactly what to do next, never a weak "let\'s talk". '
				: 'Write a genuinely useful, well-structured article with clear <h2>/<h3> sections and a natural, helpful next step at the end. ' )
			// Optional caller-supplied tone + location focus (from the quick form).
			. ( '' !== $tone ? 'TONE: write in a ' . $tone . ' tone while staying credible and specific. ' : '' )
			. ( '' !== $location ? 'LOCATION FOCUS: make the content genuinely relevant to ' . $location . ' where natural, without keyword-stuffing place names. ' : '' )
			// FAQs.
			. ( $commercial
				? 'FAQs: include 4 to 7 buyer questions with honest answers, such as how long it takes, what it costs (explain what '
				  . 'drives price and give a realistic range without inventing a specific figure), whether results are guaranteed '
				  . '(answer honestly that no ethical provider guarantees rankings), how this differs from the alternative, serving '
				  . 'multiple locations or service-area businesses, and review management. '
				: 'FAQs: include 3 to 6 real questions searchers ask, with substantive answers. ' )
			. 'Put FAQs only in the "faqs" array, not in content_html. '
			. 'Use semantic HTML: <h2>/<h3> headings, <p>, <ul>. Do not include an <h1> (the theme renders the title). '
			. 'Return JSON: {"title":str,"content_html":str,"faqs":[{"question":str,"answer":str}],'
			. '"meta_title":str(<=60 chars),"meta_description":str(140-160 chars),'
			. '"og_title":str,"og_description":str,'
			. '"image":{"concept":str,"prompt":str,"alt":str,"filename":str,"placement":str}}';

		// Size the token budget to the target length. Service/pillar pages get
		// enough room for a proper 1,500-2,000 word page.
		$words  = (int) ( $brief['recommended_words'] ?? 0 );
		if ( $words < 300 ) {
			$words = (int) SCC_Settings::get( 'default_word_count', 1200 );
		}
		if ( $commercial && $words < 1500 ) {
			$words = 1500;
		}
		$budget = (int) min( 5200, max( 1200, round( $words * 1.7 ) + 800 ) );

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => "Approved brief (JSON):\n" . wp_json_encode( $brief ) . "\n\nWrite the page now and return the JSON.",
					),
				),
				'json'        => true,
				'max_tokens'  => $budget,
				'temperature' => 0.7,
			),
			'content-generation'
		);

		if ( $response->is_error() ) {
			return $response->error;
		}

		$data = $response->json();
		if ( ! is_array( $data ) || empty( $data['content_html'] ) ) {
			SCC_Logger::error( 'generator', 'AI body output unparseable' );
			return new WP_Error( 'scc_bad_ai_output', __( 'The generated content could not be parsed. Try again.', 'seo-command-center' ), array( 'status' => 502 ) );
		}

		$faqs = array();
		foreach ( (array) ( $data['faqs'] ?? array() ) as $faq ) {
			$q = SCC_Security::sanitize_text( $faq['question'] ?? '' );
			$a = SCC_Security::sanitize_textarea( $faq['answer'] ?? '' );
			if ( '' !== $q && '' !== $a ) {
				$faqs[] = array( 'question' => $q, 'answer' => $a );
			}
		}

		$image = array();
		if ( ! empty( $data['image'] ) && is_array( $data['image'] ) ) {
			$image = array(
				'concept'   => SCC_Security::sanitize_text( $data['image']['concept'] ?? '' ),
				'prompt'    => SCC_Security::sanitize_textarea( $data['image']['prompt'] ?? '' ),
				'alt'       => SCC_Security::sanitize_text( $data['image']['alt'] ?? '' ),
				'filename'  => sanitize_file_name( $data['image']['filename'] ?? '' ),
				'placement' => SCC_Security::sanitize_text( $data['image']['placement'] ?? '' ),
			);
		}

		return array(
			'title'            => self::strip_dashes( SCC_Security::sanitize_text( $data['title'] ?? ( $entry['title'] ?? '' ) ) ),
			'content_html'     => $this->sanitize_content_html( $data['content_html'], $faqs ),
			'faqs'             => $faqs,
			'meta_title'       => self::strip_dashes( SCC_Security::sanitize_text( $data['meta_title'] ?? '' ) ),
			'meta_description' => self::strip_dashes( SCC_Security::sanitize_textarea( $data['meta_description'] ?? '' ) ),
			'og_title'         => self::strip_dashes( SCC_Security::sanitize_text( $data['og_title'] ?? '' ) ),
			'og_description'   => self::strip_dashes( SCC_Security::sanitize_textarea( $data['og_description'] ?? '' ) ),
			'image'            => $image,
		);
	}

	/**
	 * Sanitize AI content HTML with wp_kses and append an FAQ section.
	 *
	 * @param string $html AI HTML.
	 * @param array  $faqs FAQ list.
	 * @return string
	 */
	protected function sanitize_content_html( $html, array $faqs ) {
		$allowed = wp_kses_allowed_html( 'post' );
		// Allow the native accordion elements for the FAQ section.
		$allowed['details'] = array( 'class' => true, 'open' => true );
		$allowed['summary'] = array( 'class' => true );

		$clean = wp_kses( self::strip_dashes( (string) $html ), $allowed );

		if ( ! empty( $faqs ) ) {
			$clean .= "\n<h2 class=\"scc-faq-title\">" . esc_html__( 'Frequently asked questions', 'seo-command-center' ) . "</h2>\n";
			$clean .= "<div class=\"scc-faq\">\n";
			foreach ( $faqs as $faq ) {
				$q = esc_html( self::strip_dashes( $faq['question'] ) );
				$a = wp_kses_post( wpautop( self::strip_dashes( $faq['answer'] ) ) );
				$clean .= "<details class=\"scc-faq__item\">\n";
				$clean .= '<summary class="scc-faq__q">' . $q . "</summary>\n";
				$clean .= '<div class="scc-faq__a">' . $a . "</div>\n";
				$clean .= "</details>\n";
			}
			$clean .= "</div>\n";
		}
		return $clean;
	}

	/**
	 * Replace em/en dashes (and the common " - " connector) with plain
	 * punctuation, per the house style. Safety net over the prompt instruction.
	 *
	 * @param string $text Text/HTML.
	 * @return string
	 */
	protected static function strip_dashes( $text ) {
		$text = (string) $text;
		// Em/en dashes and horizontal bar → comma (keeps clause flow).
		$text = str_replace( array( '—', '–', '―' ), ', ', $text );
		// " - " used as a dash connector → comma. Leave hyphens in words alone.
		$text = preg_replace( '/\s+-\s+/u', ', ', $text );
		// Tidy artifacts the replacement may create.
		$text = preg_replace( '/\s+,/', ',', $text );   // space before comma
		$text = preg_replace( '/,\s*,/', ',', $text );  // doubled commas
		$text = preg_replace( '/\s{2,}/', ' ', $text );  // doubled spaces
		return $text;
	}

	/**
	 * Build and attach validated schema unless already provided site-wide.
	 *
	 * @param int   $post_id Post id.
	 * @param array $entry   Plan entry.
	 * @param array $body    Generated body.
	 * @return bool Whether schema was attached.
	 */
	protected function maybe_attach_schema( $post_id, array $entry, array $body ) {
		$type = SCC_Schema::type_for( $entry['page_type'] ?? 'article' );

		$nodes = array();

		if ( ! SCC_Schema::already_provided( $type ) ) {
			$node = SCC_Schema::build(
				$type,
				array(
					'name'        => $body['title'],
					'description' => $body['meta_description'],
					'url'         => get_permalink( $post_id ),
					'author'      => get_bloginfo( 'name' ),
					'provider'    => get_bloginfo( 'name' ),
					'area'        => $entry['parent'] ?? '',
					'date'        => current_time( 'c' ),
				)
			);
			if ( ! is_wp_error( $node ) ) {
				$nodes[] = $node;
			}
		}

		// FAQ schema when there are FAQs and no SEO plugin already emits it.
		if ( ! empty( $body['faqs'] ) && ! SCC_Schema::already_provided( 'FAQPage' ) ) {
			$faq_node = SCC_Schema::build( 'FAQPage', array( 'faqs' => $body['faqs'] ) );
			if ( ! is_wp_error( $faq_node ) ) {
				$nodes[] = $faq_node;
			}
		}

		if ( empty( $nodes ) ) {
			return false;
		}

		update_post_meta( $post_id, '_scc_schema', wp_json_encode( $nodes ) );
		return true;
	}

	/**
	 * Regenerate a single section of an existing generated draft.
	 *
	 * @param int    $post_id Post id.
	 * @param string $section introduction|conclusion|faq|cta|meta_title|meta_description.
	 * @return array|WP_Error {section, value}
	 */
	public function regenerate_section( $post_id, $section ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'scc_no_post', __( 'Post not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		$allowed = array( 'introduction', 'conclusion', 'faq', 'cta', 'meta_title', 'meta_description' );
		if ( ! in_array( $section, $allowed, true ) ) {
			return new WP_Error( 'scc_bad_section', __( 'Unsupported section.', 'seo-command-center' ), array( 'status' => 400 ) );
		}

		$brief = json_decode( (string) get_post_meta( $post_id, '_scc_brief', true ), true );
		$brief = is_array( $brief ) ? $brief : array();

		$instruction = array(
			'introduction'     => 'Rewrite ONLY the opening introduction (1-2 paragraphs of HTML).',
			'conclusion'       => 'Write ONLY a strong closing conclusion (1 paragraph of HTML).',
			'faq'              => 'Write ONLY a fresh set of 3-5 FAQ items. Return JSON {"faqs":[{"question":str,"answer":str}]}.',
			'cta'              => 'Write ONLY a compelling call-to-action paragraph (HTML).',
			'meta_title'       => 'Write ONLY a new SEO meta title (<=60 chars, plain text).',
			'meta_description' => 'Write ONLY a new meta description (140-160 chars, plain text).',
		);

		$json = ( 'faq' === $section );
		$response = $this->ai->complete(
			array(
				'system'      => 'You are an SEO copywriter improving one part of an existing page. ' . $instruction[ $section ]
					. ' Keep it natural and specific; no keyword stuffing.',
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => 'Page title: ' . $post->post_title . "\nBrief: " . wp_json_encode( $brief ),
					),
				),
				'json'        => $json,
				'max_tokens'  => 1200,
				'temperature' => 0.7,
			),
			'regenerate-section'
		);

		if ( $response->is_error() ) {
			return $response->error;
		}

		$value = $json ? $response->json() : trim( $response->content );
		return array( 'section' => $section, 'value' => $value );
	}

	/**
	 * Whether a content type generates as a normal, native WordPress post.
	 *
	 * Native when the type is a blog/article type AND the user has not explicitly
	 * chosen a template family for it (choosing a template opts even a post into
	 * TEMPLATE mode — an advanced escape hatch).
	 *
	 * @param string $content_type  Content type.
	 * @param string $manual_family Explicitly chosen template family, if any.
	 * @return bool
	 */
	public static function is_native_mode( $content_type, $manual_family = '' ) {
		if ( '' !== trim( (string) $manual_family ) ) {
			return false;
		}
		return in_array( (string) $content_type, self::NATIVE_TYPES, true );
	}

	/**
	 * Render the content object as normal WordPress post content: the sanitized
	 * AI body (with FAQs already appended and NO in-body H1) becomes post_content
	 * as-is. No template, no tokens, no page builder.
	 *
	 * @param SCC_Content_Object $content Content object.
	 * @return array {post_content, post_meta, post_name}
	 */
	protected function render_native( SCC_Content_Object $content ) {
		$html = trim( (string) $content->content );
		return array(
			'post_content' => $html,
			'post_meta'    => array(),
			'post_name'    => $content->slug ? sanitize_title( $this->last_slug_segment( $content->slug ) ) : sanitize_title( $content->title ),
		);
	}

	/**
	 * Last path segment of a slug/URL.
	 *
	 * @param string $slug Slug or URL/path.
	 * @return string
	 */
	protected function last_slug_segment( $slug ) {
		$path = wp_parse_url( $slug, PHP_URL_PATH );
		$segs = array_filter( explode( '/', (string) ( $path ? $path : $slug ) ) );
		return $segs ? (string) end( $segs ) : (string) $slug;
	}

	/**
	 * Apply native WordPress excerpt + taxonomy to a generated post.
	 *
	 * - Excerpt: derived from the meta description when the post has none.
	 * - Categories (posts only): matched to an EXISTING category by name/slug so
	 *   we never create duplicate categories; a hint that matches nothing is left
	 *   alone (the site's default category applies).
	 * - Tags (posts only): the primary + secondary keywords, capped, via the core
	 *   tag taxonomy (WordPress de-duplicates by slug).
	 *
	 * Uses native WordPress taxonomies only — no parallel storage.
	 *
	 * @param int                $post_id   Post id.
	 * @param string             $post_type Resolved post type.
	 * @param array              $entry     Content-plan entry.
	 * @param array              $body      Generated body.
	 * @param SCC_Content_Object $content   Content object.
	 * @return void
	 */
	protected function apply_taxonomy_and_excerpt( $post_id, $post_type, array $entry, array $body, SCC_Content_Object $content ) {
		// Excerpt from the meta description (only if the post has none yet).
		$excerpt = trim( wp_strip_all_tags( (string) ( $body['meta_description'] ?? '' ) ) );
		if ( '' !== $excerpt ) {
			$existing = get_post_field( 'post_excerpt', $post_id );
			if ( '' === trim( (string) $existing ) ) {
				wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => $excerpt ) );
			}
		}

		if ( 'post' !== $post_type ) {
			return; // Pages have no categories/tags by default.
		}

		// Category: existing terms only. Accept a hint from the entry.
		$hint = SCC_Security::sanitize_text( $entry['category'] ?? ( $entry['parent'] ?? '' ) );
		$term_id = self::resolve_existing_category( $hint );
		if ( $term_id > 0 ) {
			wp_set_post_categories( $post_id, array( $term_id ), false );
		}

		// Tags from the primary + secondary keywords (capped).
		$tags = array();
		if ( '' !== (string) $content->primary_keyword ) {
			$tags[] = (string) $content->primary_keyword;
		}
		foreach ( (array) $content->secondary_keywords as $kw ) {
			$kw = SCC_Security::sanitize_text( $kw );
			if ( '' !== $kw ) {
				$tags[] = $kw;
			}
		}
		$tags = array_slice( array_values( array_unique( $tags ) ), 0, 8 );
		if ( ! empty( $tags ) ) {
			wp_set_post_tags( $post_id, $tags, true );
		}
	}

	/**
	 * Resolve a category hint to an EXISTING category term id, or 0 if none match.
	 * Never creates a category.
	 *
	 * @param string $hint Category name or slug.
	 * @return int
	 */
	public static function resolve_existing_category( $hint ) {
		$hint = trim( (string) $hint );
		if ( '' === $hint || ! function_exists( 'get_term_by' ) ) {
			return 0;
		}
		foreach ( array( 'name', 'slug' ) as $by ) {
			$needle = ( 'slug' === $by ) ? sanitize_title( $hint ) : $hint;
			$term   = get_term_by( $by, $needle, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}

	/**
	 * Map a page type to a WordPress post type.
	 *
	 * @param string $page_type Page type.
	 * @return string
	 */
	public static function post_type_for( $page_type ) {
		return in_array( (string) $page_type, self::NATIVE_TYPES, true ) ? 'post' : 'page';
	}

	/**
	 * Derive a slug from a recommended URL or fall back to the title.
	 *
	 * @param string $url   Recommended URL/path.
	 * @param string $title Title.
	 * @return string
	 */
	protected function slug_from_url( $url, $title ) {
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$segments = array_filter( explode( '/', (string) $path ) );
		$last     = end( $segments );
		return $last ? sanitize_title( $last ) : sanitize_title( $title );
	}
}
