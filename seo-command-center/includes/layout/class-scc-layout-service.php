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

	public function propose_for_post( $post_id, $use_ai = false ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }

		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		$plan     = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();
		$layout   = $this->smart_layout( $analysis, $plan );
		$result   = $this->proposal_from( $obj, $analysis, $context, $layout, $plan );
		$result['post_id'] = (int) $post_id;
		$result['title']   = (string) $obj->title;
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
		$layout = $this->smart_layout( $analysis, $plan );
		return $this->proposal_from( $obj, $analysis, $context, $layout, $plan );
	}

	public function build( $post_id, $use_ai = false ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }

		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$plan     = class_exists( 'SCC_Page_Brain' ) ? SCC_Page_Brain::for_post( $post_id ) : array();
		$layout   = $this->smart_layout( $analysis, $plan );
		return $this->apply( $post_id, $layout );
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

	protected function proposal_from( SCC_Content_Object $obj, array $analysis, array $context, array $layout, array $plan ) {
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
			'source'           => class_exists( 'SCC_Page_Architect' ) ? 'page_architect' : 'design_composer',
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
