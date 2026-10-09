<?php
/**
 * Smart Elementor Design Service.
 *
 * Content/SEO strategy remains upstream, but the page architecture may consume
 * the stored Page Brain plan so a service page, local page, landing page and
 * article do not all receive the same component sequence. Visual treatments
 * themselves remain deterministic and content-safe.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Layout_Service {

	/** @var SCC_AI_Manager|null Retained for backward-compatible construction. */
	protected $ai;

	public function __construct( $ai = null ) {
		$this->ai = $ai;
	}

	public function propose_for_post( $post_id, $use_ai = false, $design_prompt = '', array $visual_recipe = array() ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }

		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		$plan     = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();

		// Preferred AI path: schema-aware nested Elementor composition. The model
		// designs with real installed widgets, but the server validates/compiles it.
		$agent_error = '';
		$agent_attempted = false;
		if ( $use_ai && class_exists( 'SCC_Elementor_Design_Agent' ) ) {
			$agent = new SCC_Elementor_Design_Agent( $this->ai );
			if ( $agent->is_available() ) {
				$agent_attempted = true;
				$agent_result = $agent->propose( $analysis, $design_prompt, $visual_recipe );
				if ( ! is_wp_error( $agent_result ) ) {
					$result = $this->composition_proposal( $post_id, $obj, $analysis, $agent_result, $design_prompt );
					if ( ! is_wp_error( $result ) ) { return $result; }
					$agent_error = $result->get_error_message();
				} else {
					$agent_error = $agent_result->get_error_message();
				}
			}
		}

		// Existing page architect remains the guaranteed fallback. If the richer
		// agent actually ran and failed validation, do not spend a second AI call on
		// block ordering; fall back deterministically instead.
		$choice = $this->choose_layout( $analysis, $plan, $agent_attempted ? false : $use_ai );
		if ( $agent_attempted ) { $choice['source'] = 'schema_agent_fallback'; }
		$result = $this->proposal_from( $obj, $analysis, $context, $choice['layout'], $plan, $choice['source'] );
		$result['post_id'] = (int) $post_id;
		$result['title']   = (string) $obj->title;
		$result['composition_mode'] = false;
		if ( '' !== $agent_error ) { $result['agent_error'] = $agent_error; }
		return $result;
	}

	/**
	 * Preview for an object without requiring a stored post/Page Brain plan.
	 */
	public function propose_for_object( SCC_Content_Object $obj, $use_ai = false ) {
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		$plan = array(
			'page_type'      => (string) ( $analysis['content_type'] ?? 'article' ),
			'primary_intent' => (string) ( $analysis['search_intent'] ?? '' ),
		);
		$choice = $this->choose_layout( $analysis, $plan, $use_ai );
		return $this->proposal_from( $obj, $analysis, $context, $choice['layout'], $plan, $choice['source'] );
	}

	public function build( $post_id, $use_ai = false, $design_prompt = '' ) {
		if ( $use_ai ) {
			$proposal = $this->propose_for_post( $post_id, true, $design_prompt );
			if ( is_wp_error( $proposal ) ) { return $proposal; }
			if ( ! empty( $proposal['composition_mode'] ) && ! empty( $proposal['composition_token'] ) ) {
				return $this->apply_composition( $post_id, (string) $proposal['composition_token'] );
			}
			return $this->apply( $post_id, (array) ( $proposal['layout'] ?? array() ) );
		}

		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$plan     = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();
		$choice   = $this->choose_layout( $analysis, $plan, false );
		return $this->apply( $post_id, $choice['layout'] );
	}


	/**
	 * Build the browser preview and store the validated composition server-side.
	 * The browser receives only a short-lived opaque token, not executable JSON.
	 */
	protected function composition_proposal( $post_id, SCC_Content_Object $obj, array $analysis, array $agent_result, $design_prompt ) {
		$composition = (array) ( $agent_result['composition'] ?? array() );
		$bank = (array) ( $agent_result['bank'] ?? array() );
		if ( empty( $composition['nodes'] ) || empty( $bank ) ) {
			return new WP_Error( 'scc_empty_composition', __( 'The design agent produced no usable composition.', 'seo-command-center' ) );
		}

		$token = function_exists( 'wp_generate_uuid4' )
			? str_replace( '-', '', wp_generate_uuid4() )
			: md5( uniqid( 'scc-design-' . (int) $post_id, true ) );
		$draft = array(
			'token'       => $token,
			'created'     => time(),
			'composition' => $composition,
			'prompt'      => substr( sanitize_textarea_field( (string) $design_prompt ), 0, 2400 ),
			'repaired'    => ! empty( $agent_result['repaired'] ),
			'visual_recreation' => ! empty( $agent_result['visual_recreation'] ),
		);
		$written = update_post_meta( (int) $post_id, '_scc_elementor_composition_draft', $draft );
		if ( false === $written && get_post_meta( (int) $post_id, '_scc_elementor_composition_draft', true ) !== $draft ) {
			return new WP_Error( 'scc_composition_store', __( 'The proposed Elementor composition could not be saved safely.', 'seo-command-center' ) );
		}

		$preview = SCC_Elementor_Composition::preview_sections( $composition );
		return array(
			'post_id'           => (int) $post_id,
			'title'             => (string) $obj->title,
			'layout'            => wp_list_pluck( $preview, 'id' ),
			'blocks'            => $preview,
			'critique'          => array(),
			'source'            => 'ai_elementor_composition',
			'design_only'       => true,
			'composition_mode'  => true,
			'composition_token' => $token,
			'composition_nodes' => (int) ( $composition['meta']['node_count'] ?? 0 ),
			'composition_name'  => (string) ( $composition['name'] ?? 'AI Elementor composition' ),
			'repaired'          => ! empty( $agent_result['repaired'] ),
			'elementor_active'  => true,
			'widget_catalog'    => class_exists( 'SCC_Elementor_Widget_Catalog' ) ? SCC_Elementor_Widget_Catalog::snapshot() : array(),
			'widget_discovery'  => array(
				'available' => count( (array) ( $agent_result['catalog']['available'] ?? array() ) ),
				'schema_loaded' => count( (array) ( $agent_result['catalog']['schemas'] ?? array() ) ),
			),
			'content_type'      => (string) ( $analysis['content_type'] ?? '' ),
			'search_intent'     => (string) ( $analysis['search_intent'] ?? '' ),
			'ai_available'      => ( $this->ai instanceof SCC_AI_Manager ),
		);
	}

	/**
	 * Apply a previously proposed schema-aware composition. Content is rebuilt
	 * from the current post before compiling so an old proposal cannot overwrite
	 * newer copy with a stale model payload.
	 */
	public function apply_composition( $post_id, $token ) {
		$post_id = (int) $post_id;
		$token = sanitize_text_field( (string) $token );
		$draft = get_post_meta( $post_id, '_scc_elementor_composition_draft', true );
		if ( ! is_array( $draft ) || empty( $draft['token'] ) || ! hash_equals( (string) $draft['token'], $token ) ) {
			return new WP_Error( 'scc_composition_token', __( 'This Elementor design preview is missing or no longer matches the page. Regenerate the design first.', 'seo-command-center' ) );
		}
		if ( empty( $draft['created'] ) || time() - (int) $draft['created'] > DAY_IN_SECONDS ) {
			delete_post_meta( $post_id, '_scc_elementor_composition_draft' );
			return new WP_Error( 'scc_composition_expired', __( 'This Elementor design preview expired. Regenerate it before applying.', 'seo-command-center' ) );
		}

		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$bank = SCC_Elementor_Content_Bank::build( $analysis );
		$composition = SCC_Elementor_Composition::validate( (array) ( $draft['composition'] ?? array() ), $bank );
		if ( is_wp_error( $composition ) ) {
			return new WP_Error(
				'scc_composition_stale',
				__( 'The page content changed enough that this design preview is no longer safe to apply. Regenerate the design.', 'seo-command-center' ),
				array( 'reason' => $composition->get_error_message() )
			);
		}

		$profile = class_exists( 'SCC_Design_Intel' ) ? SCC_Design_Intel::profile() : array();
		$elements = SCC_Elementor_Composition::compile( $composition, $bank, $profile );
		if ( empty( $elements ) ) {
			return new WP_Error( 'scc_empty_composition', __( 'The validated design compiled to an empty Elementor page.', 'seo-command-center' ) );
		}

		$applied = SCC_Block_Elementor_Renderer::apply_tree_to_post( $post_id, $elements, null, 'schema-aware-agent' );
		if ( is_wp_error( $applied ) ) { return $applied; }

		$root_ids = wp_list_pluck( SCC_Elementor_Composition::preview_sections( $composition ), 'id' );
		update_post_meta( $post_id, '_scc_layout_plan', wp_json_encode( $root_ids ) );
		update_post_meta( $post_id, '_scc_ai_composition_last', array(
			'name' => (string) ( $composition['name'] ?? '' ),
			'created' => (int) ( $draft['created'] ?? time() ),
			'applied' => time(),
			'prompt' => (string) ( $draft['prompt'] ?? '' ),
			'node_count' => (int) ( $composition['meta']['node_count'] ?? 0 ),
			'visual_recreation' => ! empty( $draft['visual_recreation'] ),
		) );
		delete_post_meta( $post_id, '_scc_elementor_composition_draft' );

		return array(
			'ok'               => true,
			'post_id'          => $post_id,
			'layout'           => $root_ids,
			'critique'         => array(),
			'design_source'    => 'schema-aware-elementor-agent',
			'composition_mode' => true,
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
			'view_url'         => get_permalink( $post_id ),
			'preview_url'      => get_preview_post_link( $post_id ),
			'elementor_url'    => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
		);
	}


	/**
	 * Apply an ordered component list. User reordering is still supported.
	 */
	public function apply( $post_id, array $layout ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }

		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		$plan     = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();
		$layout   = SCC_Layout_Validator::validate( array_values( array_unique( array_map( 'sanitize_key', $layout ) ) ) );

		if ( class_exists( 'SCC_Page_Critic' ) ) {
			$layout = SCC_Page_Critic::autofix_layout( $layout, $analysis, $plan );
		}

		$blocks  = SCC_Content_Mapper::map( $layout, $analysis, $context );
		$applied = SCC_Block_Elementor_Renderer::apply_to_post(
			$post_id,
			$blocks,
			array(
				'palette_css' => SCC_Design_Intel::css_vars(),
				'design'      => $context['design'],
				'handoff'     => $context['handoff'],
			)
		);
		if ( is_wp_error( $applied ) ) { return $applied; }

		$critique = class_exists( 'SCC_Page_Critic' )
			? SCC_Page_Critic::critique_layout( $layout, $analysis, $plan )
			: array();
		update_post_meta( (int) $post_id, '_scc_layout_plan', wp_json_encode( $layout ) );
		update_post_meta( (int) $post_id, '_scc_layout_critique', wp_json_encode( $critique ) );

		return array(
			'ok'            => true,
			'post_id'       => (int) $post_id,
			'layout'        => $layout,
			'critique'      => $critique,
			'design_source' => class_exists( 'SCC_Page_Architect' ) ? 'page_architect+design_composer' : 'design_composer',
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'view_url'      => get_permalink( $post_id ),
			'elementor_url' => admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' ),
		);
	}

	/**
	 * Choose deterministic architecture by default; explicit manual AI mode may
	 * reorder/select only from the controlled component registry.
	 */
	protected function choose_layout( array $analysis, array $plan, $use_ai = false ) {
		$fallback = $this->smart_layout( $analysis, $plan );
		if ( ! $use_ai || ! ( $this->ai instanceof SCC_AI_Manager ) ) {
			return array( 'layout' => $fallback, 'source' => 'page_architect' );
		}

		$allowed = $this->available_components( $analysis );
		if ( empty( $allowed ) ) {
			return array( 'layout' => $fallback, 'source' => 'page_architect' );
		}

		$signal = array(
			'page_type'     => (string) ( $plan['page_type'] ?? $analysis['content_type'] ?? 'article' ),
			'intent'        => (string) ( $plan['primary_intent'] ?? $analysis['search_intent'] ?? '' ),
			'has_image'     => ! empty( $analysis['has_image'] ) || ! empty( $analysis['image'] ),
			'sections'      => array_slice( array_values( array_filter( array_map( function ( $s ) {
				return is_array( $s ) ? (string) ( $s['text'] ?? '' ) : '';
			}, (array) ( $analysis['sections'] ?? array() ) ) ) ), 0, 16 ),
			'content_counts' => array(
				'services' => count( (array) ( $analysis['services'] ?? array() ) ),
				'benefits' => count( (array) ( $analysis['benefits'] ?? array() ) ),
				'stats'    => count( (array) ( $analysis['stats'] ?? array() ) ),
				'process'  => count( (array) ( $analysis['process'] ?? array() ) ),
				'faqs'     => count( (array) ( $analysis['faqs'] ?? array() ) ),
				'related'  => count( (array) ( $analysis['related'] ?? array() ) ),
				'areas'    => count( (array) ( $analysis['areas'] ?? array() ) ),
			),
			'fallback_layout' => $fallback,
			'allowed_components' => $allowed,
		);

		$response = $this->ai->complete(
			array(
				'system' => 'You are TideOrbit Layout Architect. Choose a professional, varied page composition from ONLY the supplied component IDs. '
					. 'Never write page copy or Elementor JSON. Use editorial sections between card/grid sections so the page does not look like boxes stacked on boxes. '
					. 'Use exactly one hero and exactly one editorial-body. Put the hero first. Use a CTA only when it is available and appropriate. '
					. 'Do not choose proof, testimonial, process, FAQ, service, comparison, location, or stats components unless the allowed list contains them. '
					. 'Prefer 6 to 12 purposeful sections. Return JSON only: {"layout":["component-id",...]}.',
				'messages' => array( array( 'role' => 'user', 'content' => wp_json_encode( $signal ) ) ),
				'json' => true,
				'max_tokens' => SCC_AI_Manager::token_budget( 1200 ),
				'temperature' => 0.25,
			),
			'layout-design'
		);
		if ( $response->is_error() ) {
			return array( 'layout' => $fallback, 'source' => 'page_architect_ai_fallback' );
		}
		$data = $response->json();
		$layout = is_array( $data ) ? (array) ( $data['layout'] ?? array() ) : array();
		$allowed_ids = array_keys( $allowed );
		$layout = array_values( array_filter( array_map( 'sanitize_key', $layout ), function ( $id ) use ( $allowed_ids ) {
			return in_array( $id, $allowed_ids, true );
		} ) );
		if ( empty( $layout ) ) {
			return array( 'layout' => $fallback, 'source' => 'page_architect_ai_fallback' );
		}
		$layout = SCC_Layout_Validator::validate( $layout );
		if ( class_exists( 'SCC_Page_Critic' ) ) {
			$layout = SCC_Page_Critic::autofix_layout( $layout, $analysis, $plan );
		}
		return array( 'layout' => $layout, 'source' => 'ai_constrained_architect' );
	}

	/**
	 * Component allowlist derived only from content actually available to render.
	 */
	protected function available_components( array $analysis ) {
		$aliases = class_exists( 'SCC_Page_Architect' ) ? SCC_Page_Architect::aliases() : array();
		$brand   = class_exists( 'SCC_Brand_Brain' ) ? SCC_Brand_Brain::profile() : array();
		$out = array();

		foreach ( $aliases as $id => $cfg ) {
			$base = (string) ( $cfg['base'] ?? '' );
			$available = true;
			switch ( $base ) {
				case 'stats':
					$available = ! empty( $analysis['stats'] );
					break;
				case 'process-steps':
					$available = ! empty( $analysis['process'] );
					break;
				case 'faq':
					$available = ! empty( $analysis['faqs'] );
					break;
				case 'service-grid':
				case 'service-cards':
				case 'feature-grid':
					$available = ! empty( $analysis['services'] );
					break;
				case 'testimonial':
					$available = ! empty( $brand['testimonials'] );
					break;
				case 'related-content':
				case 'blog-grid':
					$available = ! empty( $analysis['related'] );
					break;
				case 'service-area':
				case 'location-grid':
					$available = ! empty( $analysis['related'] ) || ! empty( $analysis['areas'] );
					break;
				case 'comparison':
					$available = false !== stripos( (string) ( $analysis['content_html'] ?? '' ), '<table' );
					break;
				case 'cta':
					$available = ! empty( $analysis['cta'] ) || ! empty( $analysis['cta_text'] );
					break;
			}
			if ( ! $available ) { continue; }
			$out[ $id ] = array(
				'name' => (string) ( $cfg['name'] ?? $id ),
				'base' => $base,
			);
		}
		return $out;
	}

	protected function smart_layout( array $analysis, array $plan ) {
		if ( class_exists( 'SCC_Page_Architect' ) ) {
			$layout = SCC_Page_Architect::layout_for_plan( $plan, $analysis );
		} else {
			$context = $this->design_context( $analysis );
			$layout  = (array) ( $context['design']['layout'] ?? array( 'hero', 'content' ) );
		}
		if ( class_exists( 'SCC_Page_Critic' ) ) {
			$layout = SCC_Page_Critic::autofix_layout( $layout, $analysis, $plan );
		}
		return $layout;
	}

	protected function proposal_from( SCC_Content_Object $obj, array $analysis, array $context, array $layout, array $plan, $source = 'page_architect' ) {
		$blocks = SCC_Content_Mapper::map( $layout, $analysis, $context );
		$preview = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['empty'] ) ) { continue; }
			$preview[] = array(
				'id'      => $block['id'],
				'name'    => $block['name'],
				'render'  => $block['render'],
				'variant' => $block['variant'],
			);
		}
		$critique = class_exists( 'SCC_Page_Critic' )
			? SCC_Page_Critic::critique_layout( $layout, $analysis, $plan )
			: array();

		return array(
			'layout'           => wp_list_pluck( $preview, 'id' ),
			'blocks'           => $preview,
			'critique'         => $critique,
			'source'           => (string) $source,
			'design_only'      => false,
			'elementor_active' => class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active(),
			'widget_catalog'   => class_exists( 'SCC_Elementor_Widget_Catalog' ) ? SCC_Elementor_Widget_Catalog::snapshot() : array(),
			'content_type'     => $analysis['content_type'],
			'search_intent'    => $analysis['search_intent'],
			'ai_available'     => ( $this->ai instanceof SCC_AI_Manager ),
		);
	}

	protected function design_context( array $analysis ) {
		$handoff = class_exists( 'SCC_Design_Handoff' )
			? SCC_Design_Handoff::from_analysis( $analysis )
			: array();

		$profile = class_exists( 'SCC_Design_Intel' ) ? SCC_Design_Intel::profile() : array();
		$design  = class_exists( 'SCC_Design_Composer' )
			? SCC_Design_Composer::compose( $handoff, $profile )
			: array( 'layout' => array( 'hero', 'content' ), 'variants' => array(), 'source' => 'fallback' );

		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
		$brand    = class_exists( 'SCC_Brand_Brain' ) ? SCC_Brand_Brain::profile() : array();

		return array(
			'business' => is_array( $business ) ? $business : array(),
			'brand'    => is_array( $brand ) ? $brand : array(),
			'handoff'  => $handoff,
			'design'   => $design,
			'profile'  => $profile,
		);
	}
}
