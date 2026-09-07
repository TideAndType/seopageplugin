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
		// Existing pages must be indexed for there to be any link TARGETS. On a
		// site that has never run a link scan the index is empty, which is the most
		// common reason a fresh draft comes out with no internal links at all —
		// build it on demand (bounded) before looking for opportunities.
		if ( 0 === SCC_Content_Index::count() ) {
			SCC_Content_Index::reindex_all( 500 );
		}

		$indexed = SCC_Content_Index::count();
		$max     = (int) SCC_Settings::get( 'max_internal_links', 8 );
		$engine  = new SCC_Link_Engine();
		$links   = $engine->opportunities_for_content( $content, $max );

		self::dbg( 'internal links', array(
			'indexed_pages' => $indexed,
			'found'         => count( $links ),
			'anchors'       => array_slice( array_map(
				function ( $l ) {
					return $l['anchor'] . ' -> ' . $l['target_url'];
				},
				$links
			), 0, 8 ),
			'note'          => $indexed < 2 ? 'Few/zero indexed pages: run Optimize > Internal Links > Scan, or publish more pages to link to.' : '',
		) );

		if ( empty( $links ) ) {
			SCC_Logger::info(
				'generator',
				'No internal-link opportunities for this draft',
				array( 'indexed_pages' => $indexed )
			);
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

	/**
	 * Always-on debug tracer. Writes to the option scc_gen_debug (independent of
	 * WP_DEBUG or the log table), so the last generation attempt is always visible
	 * in the plugin's Debug panel — even if the request dies mid-way.
	 *
	 * @param string $step Step label.
	 * @param array  $data Small context payload.
	 * @return void
	 */
	public static function dbg( $step, array $data = array() ) {
		$log = get_option( 'scc_gen_debug', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			't'    => current_time( 'mysql' ),
			'step' => (string) $step,
			'data' => $data,
		);
		if ( count( $log ) > 80 ) {
			$log = array_slice( $log, -80 );
		}
		update_option( 'scc_gen_debug', $log, false );
	}

	public function generate( array $entry, $brief = null ) {
		self::dbg( '--- generate() start ---', array(
			'entry_id'     => (int) ( $entry['id'] ?? 0 ),
			'title'        => (string) ( $entry['title'] ?? '' ),
			'page_type'    => (string) ( $entry['page_type'] ?? '' ),
			'has_brief'    => null !== $brief,
			'current_user' => get_current_user_id(),
		) );
		if ( null === $brief ) {
			// Build the draft directly from the plan entry (one AI call). We do
			// NOT make a separate brief AI call here — that doubled the time and
			// was a common cause of timeouts on local models. Preview "Brief"
			// first if you want a full brief to guide generation.
			$brief = $this->synthesize_brief( $entry );
		}

		$body = $this->generate_body( $entry, $brief );
		if ( is_wp_error( $body ) ) {
			self::dbg( 'generate_body returned WP_Error', array(
				'code'    => $body->get_error_code(),
				'message' => $body->get_error_message(),
			) );
			return $body;
		}
		self::dbg( 'body ready', array(
			'title_len'   => strlen( (string) ( $body['title'] ?? '' ) ),
			'content_len' => strlen( (string) ( $body['content_html'] ?? '' ) ),
			'faqs'        => count( (array) ( $body['faqs'] ?? array() ) ),
		) );

		// --- Content + Template + Renderer layers (CMS-agnostic) -----------
		// Build the standardized, renderer-independent content object.
		$content = SCC_Content_Object::from_generation( $entry, $body, $brief );

		// Ensure the CTA the model produced is available to the {{CTA}} token.
		if ( empty( $content->cta ) && ! empty( $body['cta'] ) ) {
			$content->cta = (string) $body['cta'];
		}

		// Internal links: prefer the ones the model wove into the article from the
		// real link_targets (already in the body, validated against our whitelist).
		// Only fall back to the deterministic in-body weaver when the model added
		// none, so we never double-link.
		$ai_links = (array) ( $body['internal_links'] ?? array() );
		if ( ! empty( $ai_links ) ) {
			$content->internal_links = $ai_links;
			self::dbg( 'internal links: using AI-woven', array( 'count' => count( $ai_links ) ) );
		} else {
			$content->internal_links = $this->weave_internal_links( $content );
		}

		$manual_family = isset( $entry['template_family'] ) ? (string) $entry['template_family'] : '';

		// A content type the user has explicitly mapped to a real, active template
		// (via Templates → mapping table, or a manually chosen family) ALWAYS uses
		// TEMPLATE mode — even for native types like "blog". Otherwise the mapping
		// the user set up is silently ignored and they get a plain native draft.
		$has_mapped_template = self::has_mapped_template( $content->content_type, $manual_family );

		// Trace exactly why we chose native vs template, so a mapping that does not
		// take effect is diagnosable from the debug panel.
		$scc_map = class_exists( 'SCC_Template_Map' ) ? SCC_Template_Map::for_content_type( $content->content_type ) : array( 'family' => '', 'renderer' => '' );
		self::dbg( 'template decision', array(
			'content_type'         => $content->content_type,
			'manual_family'        => $manual_family,
			'mapped_family'        => (string) ( $scc_map['family'] ?? '' ),
			'mapped_renderer'      => (string) ( $scc_map['renderer'] ?? '' ),
			'active_for_family'    => ( ! empty( $scc_map['family'] ) && SCC_Template_Store::active_for_family( $scc_map['family'] ) ) ? 'yes' : 'no',
			'active_for_type'      => SCC_Template_Store::active_for_content_type( $content->content_type ) ? 'yes' : 'no',
			'has_mapped_template'  => $has_mapped_template ? 'yes' : 'no',
			'is_native_type'       => self::is_native_mode( $content->content_type, $manual_family ) ? 'yes' : 'no',
			'default_renderer'     => (string) SCC_Settings::get( 'default_renderer', 'gutenberg' ),
			'elementor_active'     => ( class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active() ) ? 'yes' : 'no',
		) );

		if ( ! $has_mapped_template && self::is_native_mode( $content->content_type, $manual_family ) ) {
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
			$renderer  = $this->renderers->pick( $preferred, $content->content_type, $template );

			self::dbg( 'template mode: renderer chosen', array(
				'template_family'    => $template ? $template->family : '',
				'template_source'    => (string) ( $selection['source'] ?? '' ),
				'elementor_source_id' => $template ? (int) $template->elementor_source_id : 0,
				'preferred_renderer' => $preferred,
				'picked_renderer'    => $renderer->get_id(),
			) );

			$rendered = $renderer->render( $content, $template );
			if ( is_wp_error( $rendered ) ) {
				// Safety net: never fail the whole run because a builder errored.
				SCC_Logger::error( 'generator', 'Renderer failed, using native WP: ' . $rendered->get_error_message() );
				self::dbg( 'renderer FAILED, falling back to WordPress', array(
					'renderer' => $renderer->get_id(),
					'error'    => $rendered->get_error_message(),
				) );
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

		// Explicitly set the author to the current user. In a REST context a post
		// created with no author (author 0) still exists but is hidden from the
		// "Mine" tab of the Posts/Pages screen — a common "it says it saved but I
		// can't find it" cause. Fall back to the first admin if there is no user.
		$author = get_current_user_id();
		if ( ! $author ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$author = ! empty( $admins ) ? (int) $admins[0] : 0;
		}

		self::dbg( 'about to wp_insert_post', array(
			'post_type'    => $post_type,
			'status'       => $status,
			'author'       => (int) $author,
			'title_len'    => strlen( (string) $content->title ),
			'content_len'  => strlen( (string) $rendered['post_content'] ),
			'renderer'     => $renderer_id,
		) );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $content->title,
				'post_content' => $rendered['post_content'],
				'post_status'  => $status,
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_author'  => $author,
			),
			true
		);

		// Diagnostics for "the draft is not under Posts": capture every fact about
		// the insert and echo it to the SEO Command log, the PHP error log
		// (debug.log), and the API response so it is visible without any log access.
		$debug = array(
			'content_type' => $content->content_type,
			'mode'         => $has_mapped_template ? 'template' : 'native',
			'post_type'    => $post_type,
			'want_status'  => $status,
			'author'       => (int) $author,
			'insert'       => is_wp_error( $post_id ) ? ( 'WP_Error: ' . $post_id->get_error_message() ) : (int) $post_id,
		);

		if ( is_wp_error( $post_id ) ) {
			SCC_Logger::error( 'generator', 'wp_insert_post failed: ' . $post_id->get_error_message(), $debug );
			self::debug_log( 'wp_insert_post FAILED', $debug );
			return $post_id;
		}

		// Read the row three ways: the WP cache (get_post), a direct DB read
		// (bypasses object cache), and whether the post type is registered/visible.
		$saved      = get_post( $post_id );
		$type_obj   = get_post_type_object( $post_type );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_author FROM {$wpdb->posts} WHERE ID = %d", (int) $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$debug['get_post']        = $saved ? 'found' : 'MISSING';
		$debug['db_row']          = $row ? $row : 'MISSING';
		$debug['saved_status']    = $saved ? $saved->post_status : '';
		$debug['saved_post_type'] = $saved ? $saved->post_type : '';
		$debug['type_registered'] = $type_obj ? 'yes' : 'NO';
		$debug['type_public']     = ( $type_obj && $type_obj->public ) ? 'yes' : 'no';
		$debug['type_show_ui']    = ( $type_obj && $type_obj->show_ui ) ? 'yes' : 'no';

		SCC_Logger::info( 'generator', 'Post insert diagnostics', $debug );
		self::debug_log( 'Post insert diagnostics', $debug );
		self::dbg( 'post insert diagnostics', $debug );

		if ( ! $saved && ! $row ) {
			// Genuinely never persisted — a caching/security plugin dropped it.
			SCC_Logger::error( 'generator', 'Post vanished immediately after insert', $debug );
			self::dbg( 'POST VANISHED after insert', $debug );
			return new WP_Error( 'scc_post_not_persisted', __( 'The draft was created but is not in the database immediately after saving — a caching or security plugin is likely blocking wp_insert_post. See the SEO Command log for the diagnostics.', 'seo-command-center' ), array( 'status' => 500 ) );
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

		// Index the new draft and compute internal-link recommendations (both
		// directions) so they appear under Optimize > Internal Links even when the
		// draft-time weave found no natural in-body anchor. Never fatal generation.
		try {
			SCC_Content_Index::index_post( $post_id );
			$link_recs = ( new SCC_Link_Engine() )->analyze( $post_id, true );
			self::dbg( 'internal-link recommendations stored', array(
				'outbound' => isset( $link_recs['outbound'] ) ? count( $link_recs['outbound'] ) : 0,
				'inbound'  => isset( $link_recs['inbound'] ) ? count( $link_recs['inbound'] ) : 0,
			) );
		} catch ( \Throwable $e ) {
			SCC_Logger::info( 'generator', 'Link recommendation pass failed', array( 'error' => $e->getMessage() ) );
		}

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

		$mode = $has_mapped_template ? 'template' : ( self::is_native_mode( $content->content_type, $manual_family ) ? 'native' : 'template' );

		self::dbg( '=== generate() SUCCESS ===', array(
			'post_id'   => (int) $post_id,
			'post_type' => $post_type,
			'status'    => $status,
			'mode'      => $mode,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
		) );

		return array(
			'post_id'   => $post_id,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'view_url'  => get_permalink( $post_id ),
			'status'    => $status,
			'post_type' => $post_type,
			'mode'      => $mode,
			'score'     => $score,
			'title'     => $content->title,
			'renderer'  => $used_renderer,
			'template'  => $template->name,
			'elementor' => $used_elementor,
			'links'     => count( (array) $content->internal_links ),
			'debug'     => $debug,
		);
	}

	/**
	 * Write a diagnostic line to the PHP error log (debug.log) when WP_DEBUG_LOG
	 * is on. Complements the in-plugin SEO Command log so problems are visible
	 * both places without exposing anything sensitive.
	 *
	 * @param string $label Message label.
	 * @param array  $data  Context.
	 * @return void
	 */
	protected static function debug_log( $label, array $data ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[SEO Command Center] ' . $label . ' ' . wp_json_encode( $data ) );
	}

	/**
	 * Gather relevant, already-published pages the AI may link to while writing —
	 * so internal links are woven into the article naturally (not bolted on after).
	 * Only real permalinks from the content index are offered; the model is told to
	 * use these URLs verbatim and never invent one.
	 *
	 * @param array $entry Content-plan entry.
	 * @param int   $limit Max candidates.
	 * @return array List of {title, url, keyword}.
	 */
	protected function link_candidates( array $entry, $limit = 10 ) {
		if ( ! class_exists( 'SCC_Content_Index' ) ) {
			return array();
		}
		if ( 0 === SCC_Content_Index::count() ) {
			SCC_Content_Index::reindex_all( 500 );
		}
		$rows = SCC_Content_Index::all( 3000 );
		if ( empty( $rows ) ) {
			return array();
		}

		$self_id  = (int) ( $entry['post_id'] ?? 0 );
		$subject  = array(
			'title'           => (string) ( $entry['title'] ?? '' ),
			'primary_keyword' => (string) ( $entry['primary_keyword'] ?? '' ),
			'intent'          => (string) ( $entry['intent'] ?? '' ),
			'url'             => '',
			'tokens'          => SCC_Content_Index::tokenize(
				(string) ( $entry['title'] ?? '' ) . ' '
				. (string) ( $entry['primary_keyword'] ?? '' ) . ' '
				. implode( ' ', array_map( 'strval', (array) ( $entry['secondary'] ?? array() ) ) )
			),
		);

		$scored = array();
		foreach ( $rows as $r ) {
			if ( $self_id > 0 && (int) $r['post_id'] === $self_id ) {
				continue;
			}
			if ( empty( $r['url'] ) ) {
				continue;
			}
			$scored[] = array(
				'rel'     => SCC_Content_Index::relevance( $subject, $r ),
				'title'   => (string) $r['title'],
				'url'     => (string) $r['url'],
				'keyword' => (string) ( $r['primary_keyword'] ?? '' ),
			);
		}
		usort(
			$scored,
			function ( $a, $b ) {
				return $b['rel'] <=> $a['rel'];
			}
		);

		$out = array();
		foreach ( $scored as $s ) {
			// A low relevance floor keeps the list on-topic; if nothing clears it we
			// still offer the strongest few so the page is not orphaned.
			$out[] = array( 'title' => $s['title'], 'url' => $s['url'], 'keyword' => $s['keyword'] );
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Remove any INTERNAL link the model added whose URL is not in the allowed set
	 * (the real permalinks we offered) — the AI can reference our pages but can
	 * never invent an internal URL. External links are left untouched. Returns the
	 * cleaned HTML and, by reference, the list of kept internal links.
	 *
	 * @param string $html         Content HTML.
	 * @param array  $allowed_urls Real permalinks the AI was allowed to use.
	 * @param array  $kept         Filled with kept {anchor, target_url}.
	 * @return string
	 */
	protected static function enforce_internal_links( $html, array $allowed_urls, array &$kept = array() ) {
		$set = array();
		foreach ( $allowed_urls as $u ) {
			$set[ self::norm_url( $u ) ] = true;
		}
		$home_host = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : '';
		$kept      = array();

		return (string) preg_replace_callback(
			'#<a\b([^>]*?)href=("|\')(.*?)\2([^>]*)>(.*?)</a>#is',
			function ( $m ) use ( $set, $home_host, &$kept ) {
				$href = trim( html_entity_decode( $m[3] ) );
				if ( '' === $href || 0 === strpos( $href, '#' ) ) {
					return $m[5]; // empty / bare anchor -> unwrap.
				}
				$host        = wp_parse_url( $href, PHP_URL_HOST );
				$is_internal = ( 0 === strpos( $href, '/' ) ) || ( ! $host ) || ( $host === $home_host );
				if ( ! $is_internal ) {
					return $m[0]; // external link: leave as the model wrote it.
				}
				$abs  = ( 0 === strpos( $href, '/' ) && function_exists( 'home_url' ) ) ? home_url( $href ) : $href;
				$norm = self::norm_url( $abs );
				if ( isset( $set[ $norm ] ) ) {
					$kept[] = array( 'anchor' => trim( wp_strip_all_tags( $m[5] ) ), 'target_url' => $abs );
					return $m[0]; // real internal page: keep.
				}
				return $m[5]; // invented internal URL: unwrap, keep the words.
			},
			$html
		);
	}

	/**
	 * Normalise a URL for comparison (scheme-insensitive host, no trailing slash,
	 * no fragment/query).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected static function norm_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( ! is_array( $p ) ) {
			return rtrim( strtolower( (string) $url ), '/' );
		}
		$host = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
		$path = isset( $p['path'] ) ? rtrim( $p['path'], '/' ) : '';
		return $host . $path;
	}

	/**
	 * Built-in writing personas the user can pick in Settings.
	 *
	 * @return array key => array{label, prompt}
	 */
	public static function personas() {
		return array(
			'seo_guru'          => array(
				'label'  => __( 'SEO Guru', 'seo-command-center' ),
				'prompt' => 'PERSONA: Act as a world-class SEO strategist with 15+ years ranking pages in competitive niches. Nail search intent, cover the topic with real semantic depth and strong E-E-A-T signals, structure for featured snippets, and write for humans first while satisfying on-page SEO best practices. ',
			),
			'friendly_expert'   => array(
				'label'  => __( 'Friendly expert', 'seo-command-center' ),
				'prompt' => 'PERSONA: Act as a friendly, approachable expert who explains things clearly and warmly to a non-technical reader, using plain language and helpful examples. ',
			),
			'conversion'        => array(
				'label'  => __( 'Conversion copywriter', 'seo-command-center' ),
				'prompt' => 'PERSONA: Act as a direct-response conversion copywriter. Lead with the reader\'s desired outcome, address objections, build trust with specifics, and drive one clear action, while staying honest and never over-promising. ',
			),
			'local_expert'      => array(
				'label'  => __( 'Local business expert', 'seo-command-center' ),
				'prompt' => 'PERSONA: Act as a seasoned local-marketing expert who understands local search, Google Business Profile, service-area targeting and genuine community relevance. ',
			),
			'technical'         => array(
				'label'  => __( 'Technical / authoritative', 'seo-command-center' ),
				'prompt' => 'PERSONA: Act as an authoritative technical subject-matter expert: precise, well-structured, correct terminology, concrete detail, no fluff. ',
			),
		);
	}

	/**
	 * Persona instruction prepended to the generation system prompt, from the
	 * chosen preset plus any custom instructions the user entered in Settings.
	 *
	 * @return string
	 */
	protected static function persona_prefix() {
		$out = '';
		$key = (string) SCC_Settings::get( 'content_persona', '' );
		if ( '' !== $key && 'custom' !== $key ) {
			$personas = self::personas();
			if ( isset( $personas[ $key ]['prompt'] ) ) {
				$out .= $personas[ $key ]['prompt'];
			}
		}
		$custom = trim( (string) SCC_Settings::get( 'content_persona_custom', '' ) );
		if ( '' !== $custom ) {
			$out .= 'ADDITIONAL STYLE INSTRUCTIONS: ' . $custom . ' ';
		}
		return $out;
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
		$link_targets = $this->link_candidates( $entry, 10 );
		$intent      = strtolower( (string) ( $entry['intent'] ?? ( $brief['search_intent'] ?? '' ) ) );
		$tone        = trim( (string) ( $brief['tone'] ?? '' ) );
		$location    = trim( (string) ( $brief['location'] ?? '' ) );
		$is_local    = ( 'location' === $page_type ) || ( false !== strpos( $intent, 'local' ) ) || ( '' !== $location );
		$commercial  = in_array( $intent, array( 'commercial', 'transactional', 'local' ), true )
			|| in_array( $page_type, array( 'pillar', 'service', 'location' ), true );
		$site_name   = get_bloginfo( 'name' );

		$system    = self::persona_prefix()
			. 'You are a senior SEO copywriter and subject-matter expert writing for "' . $site_name . '". '
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
			. 'Put FAQs ONLY as objects in the "faqs" array (each {"question":...,"answer":...}). NEVER write FAQs inside '
			. 'content_html and NEVER invent tags like <faq>. '
			. 'Use semantic HTML: <h2>/<h3> headings, <p>, <ul>. Do not include an <h1> (the theme renders the title). '
			. 'LENGTH: content_html must be a complete, in-depth article of AT LEAST ' . $words . ' words of real body copy '
			. '(multiple <h2> sections, each with several full paragraphs). Do not stop early or return a short stub. '
			. ( ! empty( $link_targets )
				? 'INTERNAL LINKS: A list of EXISTING pages on this same website is provided in the user message as "link_targets" '
				  . '(each with a title, url and keyword). Where it genuinely helps the reader, weave 2 to 5 of these topics into the '
				  . 'article naturally and link to them inside content_html using <a href="EXACT_URL">natural anchor text</a>, copying '
				  . 'the url VERBATIM from the list. Link each page at most once, use descriptive anchor text (not "click here"), and '
				  . 'never invent a URL or link to a page that is not in the list. Only add a link where it is truly relevant. '
				: '' )
			. 'CTA: also return a "cta" — one or two sentences telling the reader exactly what to do next. '
			. 'Return ONLY valid JSON, nothing else: {"title":str,"content_html":str,"faqs":[{"question":str,"answer":str}],'
			. '"cta":str,"meta_title":str(<=60 chars),"meta_description":str(140-160 chars),'
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

		// A global target word count (Settings → Content style) overrides everything
		// so every generation aims for exactly the length the user set.
		$target_words = (int) SCC_Settings::get( 'content_target_words', 0 );
		if ( $target_words > 0 ) {
			$words = $target_words;
		}
		$budget = (int) min( 5200, max( 1200, round( $words * 1.7 ) + 800 ) );

		// Optional override: a fixed token budget, or "unlimited" (-1). Unlimited
		// lets a local model run until the response is genuinely complete (no early
		// truncation), while hosted providers still apply their own high ceiling.
		$max_override = (int) SCC_Settings::get( 'generation_max_tokens', 0 );
		if ( SCC_Settings::get( 'generation_unlimited_tokens', false ) ) {
			$budget = -1;
		} elseif ( $max_override > 0 ) {
			$budget = $max_override;
		}

		self::dbg( 'about to call AI (content-generation)', array( 'budget' => $budget, 'words' => $words, 'page_type' => $page_type, 'persona' => (string) SCC_Settings::get( 'content_persona', '' ), 'link_targets' => count( $link_targets ) ) );

		$user_payload = "Approved brief (JSON):\n" . wp_json_encode( $brief );
		if ( ! empty( $link_targets ) ) {
			$user_payload .= "\n\nlink_targets (existing pages on this site you may link to; use the url verbatim, never invent one):\n"
				. wp_json_encode( $link_targets );
		}

		$response = $this->ai->complete(
			array(
				'system'      => $system,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => $user_payload
							. "\n\nWrite the FULL page now and return ONLY the JSON. content_html must be a complete, in-depth article of at least "
							. $words . ' words with multiple <h2> sections, each with several full paragraphs. Include a "cta", put every FAQ as an object in the "faqs" array (never inside content_html)'
							. ( ! empty( $link_targets ) ? ', and add 2 to 5 natural internal links to the provided link_targets using their exact urls.' : '.' ),
					),
				),
				'json'        => true,
				'max_tokens'  => $budget,
				'temperature' => 0.7,
			),
			'content-generation'
		);

		if ( $response->is_error() ) {
			self::dbg( 'AI call ERROR', array(
				'provider' => $response->provider,
				'model'    => $response->model,
				'message'  => $response->error->get_error_message(),
			) );
			return $response->error;
		}
		self::dbg( 'AI call OK', array(
			'provider'    => $response->provider,
			'model'       => $response->model,
			'content_len' => strlen( (string) $response->content ),
		) );

		$data = $response->json();
		if ( ! is_array( $data ) || empty( $data['content_html'] ) ) {
			// Salvage: smaller local models (LM Studio) often ignore the "return
			// JSON" instruction and answer with the article as prose/markdown/HTML.
			// Rather than failing with no draft, turn that raw text into a usable
			// body so a draft is always produced when the model wrote something.
			$raw = trim( (string) $response->content );

			// First: the model may have returned JSON the tolerant parser couldn't
			// fully decode (a truncated tail, an unescaped quote inside a value). We
			// must NEVER dump that raw JSON into the post body. Pull content_html
			// (and title/meta) straight out of the JSON text instead.
			if ( false !== strpos( $raw, '"content_html"' ) ) {
				$salvaged_html = self::extract_json_field( $raw, 'content_html' );
				if ( strlen( wp_strip_all_tags( $salvaged_html ) ) >= 100 ) {
					SCC_Logger::info( 'generator', 'AI returned malformed JSON; extracted content_html from it' );
					$data = array(
						'title'            => self::extract_json_field( $raw, 'title' ),
						'content_html'     => $salvaged_html,
						'faqs'             => array(),
						'meta_title'       => self::extract_json_field( $raw, 'meta_title' ),
						'meta_description' => self::extract_json_field( $raw, 'meta_description' ),
					);
					if ( '' === $data['title'] ) {
						$data['title'] = (string) ( $entry['title'] ?? '' );
					}
				}
			}

			$recovered = is_array( $data ) && ! empty( $data['content_html'] );
			if ( ! $recovered && strlen( wp_strip_all_tags( $raw ) ) >= 200 ) {
				SCC_Logger::info( 'generator', 'AI returned non-JSON; salvaging raw content into a draft' );
				$data = array(
					'title'            => (string) ( $entry['title'] ?? '' ),
					'content_html'     => self::text_to_html( $raw ),
					'faqs'             => array(),
					'meta_title'       => '',
					'meta_description' => '',
				);
			} elseif ( ! $recovered ) {
				SCC_Logger::error( 'generator', 'AI body output unparseable and too short to salvage' );
				return new WP_Error( 'scc_bad_ai_output', __( 'The model did not return usable content. Try again, or use a larger/faster model.', 'seo-command-center' ), array( 'status' => 502 ) );
			}
		}

		$faqs = array();
		foreach ( (array) ( $data['faqs'] ?? array() ) as $faq ) {
			$q = SCC_Security::sanitize_text( $faq['question'] ?? '' );
			$a = SCC_Security::sanitize_textarea( $faq['answer'] ?? '' );
			if ( '' !== $q && '' !== $a ) {
				$faqs[] = array( 'question' => $q, 'answer' => $a );
			}
		}

		// Fallback: some models ignore the "faqs" array and instead write FAQs as
		// question headings inside content_html. When we got no structured FAQs,
		// lift any "<h2/3>…?</h> + <p>…</p>" pairs out of the body into the FAQ
		// array (and remove them from the body) so a template's {{FAQ}} widget
		// fills and the questions are not duplicated in the main content.
		if ( empty( $faqs ) && ! empty( $data['content_html'] ) ) {
			$extracted = self::relocate_faqs_from_html( $data['content_html'] );
			if ( ! empty( $extracted ) ) {
				$faqs = $extracted;
				SCC_Generator::dbg( 'extracted FAQs from content_html', array( 'count' => count( $faqs ) ) );
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

		// CTA: prefer the model's, else the brief's. If neither produced one, fall
		// back to a generic (clearly non-fabricated) prompt so a template's CTA
		// widget/button is not left blank.
		$cta = SCC_Security::sanitize_textarea( $data['cta'] ?? ( $brief['cta'] ?? '' ) );
		if ( '' === trim( $cta ) ) {
			$bn  = trim( (string) get_bloginfo( 'name' ) );
			$cta = $bn
				? sprintf( /* translators: %s: business name */ __( 'Ready to get started? Contact %s today.', 'seo-command-center' ), $bn )
				: __( 'Ready to get started? Contact us today.', 'seo-command-center' );
		}

		// Sanitize the body, then keep only internal links that point at real pages
		// we offered (the model can reference our pages but never invent a URL).
		$clean_html = $this->sanitize_content_html( $data['content_html'] );
		$kept_links = array();
		if ( ! empty( $link_targets ) ) {
			$allowed = array();
			foreach ( $link_targets as $t ) {
				$allowed[] = (string) $t['url'];
			}
			$clean_html = self::enforce_internal_links( $clean_html, $allowed, $kept_links );
			self::dbg( 'internal links from AI (whitelisted)', array(
				'kept'    => count( $kept_links ),
				'anchors' => array_slice( array_map(
					function ( $l ) {
						return $l['anchor'] . ' -> ' . $l['target_url'];
					},
					$kept_links
				), 0, 8 ),
			) );
		}

		return array(
			'title'            => self::strip_dashes( SCC_Security::sanitize_text( $data['title'] ?? ( $entry['title'] ?? '' ) ) ),
			// No FAQ appended here — native rendering appends it; template mode uses
			// the {{FAQ}} widget. This prevents FAQs appearing twice in a template.
			'content_html'     => $clean_html,
			'faqs'             => $faqs,
			'internal_links'   => $kept_links,
			'cta'              => self::strip_dashes( $cta ),
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
	protected function sanitize_content_html( $html, array $faqs = array() ) {
		$allowed = wp_kses_allowed_html( 'post' );
		// Allow the native accordion elements for the FAQ section.
		$allowed['details'] = array( 'class' => true, 'open' => true );
		$allowed['summary'] = array( 'class' => true );

		// Small models sometimes invent FAQ markup like <faq question="…"> inside
		// the body. Those are not real HTML tags; strip them (and any orphaned
		// "<faq question=" fragment) so they never show as literal text.
		$html = preg_replace( '#</?faq\b[^>]*>#i', '', (string) $html );
		$html = preg_replace( '/&lt;\/?faq\b[^&]*?&gt;/i', '', (string) $html );

		$clean = wp_kses( self::strip_dashes( (string) $html ), $allowed );

		// Append the FAQ accordion only when asked (native posts). In template mode
		// the FAQs fill a dedicated {{FAQ}} widget, so the caller passes no FAQs
		// here to avoid showing them twice.
		if ( ! empty( $faqs ) ) {
			$clean .= self::faq_section_html( $faqs );
		}
		return $clean;
	}

	/**
	 * The FAQ accordion HTML block (used by native posts, and by the {{FAQ}}
	 * template token via the variable map).
	 *
	 * @param array $faqs FAQ list.
	 * @return string
	 */
	public static function faq_section_html( array $faqs ) {
		if ( empty( $faqs ) ) {
			return '';
		}
		$out  = "\n<h2 class=\"scc-faq-title\">" . esc_html__( 'Frequently asked questions', 'seo-command-center' ) . "</h2>\n";
		$out .= "<div class=\"scc-faq\">\n";
		foreach ( $faqs as $faq ) {
			$q = esc_html( self::strip_dashes( $faq['question'] ?? '' ) );
			$a = wp_kses_post( wpautop( self::strip_dashes( $faq['answer'] ?? '' ) ) );
			$out .= "<details class=\"scc-faq__item\">\n";
			$out .= '<summary class="scc-faq__q">' . $q . "</summary>\n";
			$out .= '<div class="scc-faq__a">' . $a . "</div>\n";
			$out .= "</details>\n";
		}
		$out .= "</div>\n";
		return $out;
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
	 * Turn raw model output (already-HTML, or plain/markdown prose) into usable
	 * article HTML. Used only to salvage a draft when a local model ignored the
	 * "return JSON" instruction. Kept deliberately simple; the result still goes
	 * through wp_kses in sanitize_content_html().
	 *
	 * @param string $raw Raw text from the model.
	 * @return string
	 */
	protected static function text_to_html( $raw ) {
		$raw = (string) $raw;
		// Drop a leading ```/```json fence and any wrapping code fences.
		$raw = preg_replace( '/```[a-z]*\s*/i', '', $raw );
		$raw = str_replace( '```', '', $raw );
		$raw = trim( $raw );

		// Defense in depth: never emit literal JSON into the post. If this text is
		// really a JSON object carrying content_html, extract that value and use it
		// (recurse once) rather than wrapping raw braces in <p>.
		if ( '{' === substr( $raw, 0, 1 ) && false !== strpos( $raw, '"content_html"' ) ) {
			$inner = self::extract_json_field( $raw, 'content_html' );
			if ( '' !== trim( $inner ) ) {
				return self::text_to_html( $inner );
			}
		}

		// Already HTML? Use as-is (an <h1> is downgraded so the theme title stays
		// the only H1).
		if ( preg_match( '/<(p|h[1-6]|ul|ol|div|section|article)\b/i', $raw ) ) {
			return preg_replace( array( '/<h1\b/i', '/<\/h1>/i' ), array( '<h2', '</h2>' ), $raw );
		}

		// Plain/markdown prose: convert headings + bullets, wrap the rest in <p>.
		$out    = array();
		$list   = array();
		$flush  = function () use ( &$list, &$out ) {
			if ( ! empty( $list ) ) {
				$out[] = '<ul>' . implode( '', $list ) . '</ul>';
				$list  = array();
			}
		};
		foreach ( preg_split( '/\n\s*\n/', $raw ) as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			if ( preg_match( '/^#{2,}\s+(.*)$/', $block, $m ) ) {
				$flush();
				$out[] = '<h3>' . esc_html( trim( $m[1] ) ) . '</h3>';
			} elseif ( preg_match( '/^#\s+(.*)$/', $block, $m ) ) {
				$flush();
				$out[] = '<h2>' . esc_html( trim( $m[1] ) ) . '</h2>';
			} elseif ( preg_match( '/^\s*[-*]\s+/', $block ) ) {
				foreach ( preg_split( '/\n/', $block ) as $li ) {
					$li = preg_replace( '/^\s*[-*]\s+/', '', trim( $li ) );
					if ( '' !== $li ) {
						$list[] = '<li>' . esc_html( $li ) . '</li>';
					}
				}
			} else {
				$flush();
				$out[] = '<p>' . esc_html( $block ) . '</p>';
			}
		}
		$flush();
		return implode( "\n", $out );
	}

	/**
	 * Extract a single string field's value out of JSON-shaped text, even when the
	 * JSON as a whole is malformed and won't decode. Used only to salvage a draft
	 * from a model that returned broken JSON, so raw braces never reach the post.
	 *
	 * @param string $raw JSON-ish text.
	 * @param string $key Field name (e.g. "content_html").
	 * @return string The unescaped value, or '' if not found.
	 */
	/**
	 * Pull FAQ-style "<h2/3>question?</h> followed by <p>answer</p>" pairs out of
	 * an HTML body and remove them from it, so they can fill a dedicated {{FAQ}}
	 * widget instead of being buried in the main content. Only acts when at least
	 * two genuine question/answer pairs are found (avoids false positives on a
	 * single rhetorical heading). Mutates $html by reference.
	 *
	 * @param string $html Content HTML (modified in place).
	 * @return array List of {question, answer}.
	 */
	protected static function relocate_faqs_from_html( &$html ) {
		$html = (string) $html;
		$re   = '#<h[2-4][^>]*>\s*([^<]*\?)\s*</h[2-4]>\s*(<p[^>]*>.*?</p>)#is';
		if ( ! preg_match_all( $re, $html, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		if ( count( $m ) < 2 ) {
			return array();
		}
		$faqs = array();
		foreach ( $m as $match ) {
			$q = trim( wp_strip_all_tags( $match[1] ) );
			$a = trim( wp_strip_all_tags( $match[2] ) );
			if ( '' !== $q && '' !== $a ) {
				$faqs[] = array(
					'question' => SCC_Security::sanitize_text( $q ),
					'answer'   => SCC_Security::sanitize_textarea( $a ),
				);
				// Remove this Q&A block from the body.
				$html = str_replace( $match[0], '', $html );
			}
		}
		$html = trim( $html );
		return $faqs;
	}

	protected static function extract_json_field( $raw, $key ) {
		$raw = (string) $raw;
		// Match "key": "....." capturing an escaped-JSON string body.
		if ( preg_match( '/"' . preg_quote( $key, '/' ) . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $raw, $m ) ) {
			$decoded = json_decode( '"' . $m[1] . '"' );
			if ( is_string( $decoded ) ) {
				return $decoded;
			}
			// Fall back to a manual unescape of the common sequences.
			return strtr( $m[1], array( '\\"' => '"', '\\n' => "\n", '\\t' => "\t", '\\/' => '/', '\\\\' => '\\' ) );
		}
		return '';
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
	 * Whether the user has explicitly mapped this content type to a real, active
	 * template — either by choosing a family manually, by a content-type → family
	 * rule in the template map, or by flagging a template for the content type.
	 *
	 * Only returns true when the mapped template actually EXISTS and is active, so
	 * a stale mapping pointing at a deleted family does not force template mode
	 * (which would just fall back to the built-in structure anyway).
	 *
	 * @param string $content_type  Content type.
	 * @param string $manual_family Explicitly chosen family (highest priority).
	 * @return bool
	 */
	public static function has_mapped_template( $content_type, $manual_family = '' ) {
		if ( '' !== trim( (string) $manual_family ) && SCC_Template_Store::active_for_family( $manual_family ) ) {
			return true;
		}
		$mapped = SCC_Template_Map::for_content_type( $content_type );
		if ( ! empty( $mapped['family'] ) && SCC_Template_Store::active_for_family( $mapped['family'] ) ) {
			return true;
		}
		if ( SCC_Template_Store::active_for_content_type( $content_type ) ) {
			return true;
		}
		return false;
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
		// Native posts have no {{FAQ}} widget, so append the FAQ accordion to the
		// body (template mode instead fills the {{FAQ}} token).
		if ( ! empty( $content->faq ) ) {
			$html .= self::faq_section_html( (array) $content->faq );
		}
		// Native posts also have no {{CTA}} widget — append a visually distinct CTA
		// block so the call to action does not read as just another paragraph.
		$cta = trim( wp_strip_all_tags( (string) $content->cta ) );
		if ( '' !== $cta ) {
			$url = trim( (string) $content->cta_url );
			$btn = '' !== trim( (string) $content->cta_text ) ? $content->cta_text : __( 'Get in touch', 'seo-command-center' );
			$html .= "\n<div class=\"scc-cta\"><p class=\"scc-cta__text\">" . esc_html( $cta ) . '</p>';
			if ( '' !== $url ) {
				$html .= '<a class="scc-cta__btn" href="' . esc_url( $url ) . '">' . esc_html( $btn ) . '</a>';
			}
			$html .= "</div>\n";
		}
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
