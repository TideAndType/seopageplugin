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
	public function generate( array $entry, $brief = null ) {
		if ( null === $brief ) {
			$brief_service = new SCC_Content_Brief( $this->ai );
			$brief         = $brief_service->generate( $entry );
			if ( is_wp_error( $brief ) ) {
				return $brief;
			}
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

		// Deterministic template + renderer selection (the AI never picks).
		$selection = SCC_Template_Selector::select(
			$content->content_type,
			isset( $entry['template_family'] ) ? (string) $entry['template_family'] : ''
		);
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
		update_post_meta( $post_id, '_scc_renderer', $renderer->get_id() );
		update_post_meta( $post_id, '_scc_template', $template->family );
		$used_renderer  = $renderer->get_id();
		$used_elementor = ( 'elementor' === $used_renderer );

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

		SCC_Logger::info( 'generator', 'Draft created', array( 'post_id' => $post_id, 'status' => $status, 'score' => $score['score'], 'renderer' => $used_renderer, 'template' => $template->family ) );

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
		$page_type = $entry['page_type'] ?? 'article';
		$system    = 'You are an expert SEO copywriter. Write genuinely useful, specific, original content that a knowledgeable human would value. '
			. 'Rules: natural language; no keyword stuffing; no padding to hit a word count; no repetition; avoid obvious AI filler and cliches. '
			. ( 'location' === $page_type
				? 'This is a LOCATION page: make it specifically, meaningfully local — reference real local context and needs, not a city-name find-and-replace. '
				: '' )
			. 'Use semantic HTML: <h2>/<h3> headings, <p>, <ul> where useful. Do not include an <h1> (the theme renders the title). '
			. 'Return JSON: {"title":str,"content_html":str,"faqs":[{"question":str,"answer":str}],'
			. '"meta_title":str(<=60 chars),"meta_description":str(140-160 chars),'
			. '"og_title":str,"og_description":str,'
			. '"image":{"concept":str,"prompt":str,"alt":str,"filename":str,"placement":str}}';

		// Size the token budget to the target length rather than a flat 6000
		// (which is ~4500 words and can take a local model far too long).
		$words  = (int) ( $brief['recommended_words'] ?? 0 );
		if ( $words < 300 ) {
			$words = (int) SCC_Settings::get( 'default_word_count', 1200 );
		}
		$budget = (int) min( 4000, max( 1200, round( $words * 1.7 ) + 700 ) );

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
			'title'            => SCC_Security::sanitize_text( $data['title'] ?? ( $entry['title'] ?? '' ) ),
			'content_html'     => $this->sanitize_content_html( $data['content_html'], $faqs ),
			'faqs'             => $faqs,
			'meta_title'       => SCC_Security::sanitize_text( $data['meta_title'] ?? '' ),
			'meta_description' => SCC_Security::sanitize_textarea( $data['meta_description'] ?? '' ),
			'og_title'         => SCC_Security::sanitize_text( $data['og_title'] ?? '' ),
			'og_description'   => SCC_Security::sanitize_textarea( $data['og_description'] ?? '' ),
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
		$clean   = wp_kses( (string) $html, $allowed );

		if ( ! empty( $faqs ) ) {
			$clean .= "\n<h2>" . esc_html__( 'Frequently asked questions', 'seo-command-center' ) . "</h2>\n";
			foreach ( $faqs as $faq ) {
				$clean .= '<h3>' . esc_html( $faq['question'] ) . "</h3>\n";
				$clean .= '<p>' . esc_html( $faq['answer'] ) . "</p>\n";
			}
		}
		return $clean;
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
	 * Map a page type to a WordPress post type.
	 *
	 * @param string $page_type Page type.
	 * @return string
	 */
	public static function post_type_for( $page_type ) {
		return ( 'article' === $page_type ) ? 'post' : 'page';
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
