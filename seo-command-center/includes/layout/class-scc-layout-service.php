<?php
/**
 * Elementor Design Service.
 *
 * This facade begins after content generation is finished. It converts the
 * completed article/page into a design-only handoff, composes a professional
 * visual plan, maps that content into controlled components and renders native
 * editable Elementor structures.
 *
 * SEO strategy remains upstream. Keywords, search intent and local targeting
 * are not inputs to visual composition.
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

		$result            = $this->propose_for_object( $obj, $use_ai );
		$result['post_id'] = (int) $post_id;
		$result['title']   = (string) $obj->title;
		return $result;
	}

	/**
	 * Build a preview from the finished content. $use_ai is intentionally
	 * ignored here: design composition is deterministic and safe.
	 */
	public function propose_for_object( SCC_Content_Object $obj, $use_ai = false ) {
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		$plan     = $context['design'];
		$blocks   = SCC_Content_Mapper::map( $plan['layout'], $analysis, $context );

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

		return array(
			'layout'           => wp_list_pluck( $preview, 'id' ),
			'blocks'           => $preview,
			'source'           => 'design_composer',
			'design_only'      => true,
			'elementor_active' => class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active(),
			'widget_catalog'   => class_exists( 'SCC_Elementor_Widget_Catalog' ) ? SCC_Elementor_Widget_Catalog::snapshot() : array(),
			// Retained only so older admin/API consumers do not break. These are
			// metadata about the content, not inputs to the design composer.
			'content_type'     => $analysis['content_type'],
			'search_intent'    => $analysis['search_intent'],
			'ai_available'     => ( $this->ai instanceof SCC_AI_Manager ),
		);
	}

	public function build( $post_id, $use_ai = false ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }

		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		return $this->apply( $post_id, (array) $context['design']['layout'] );
	}

	/**
	 * Apply an ordered component list. A user may still reorder blocks in the
	 * existing UI, but visual variants come from the design composer.
	 */
	public function apply( $post_id, array $layout ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) { return $obj; }

		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$context  = $this->design_context( $analysis );
		$layout   = SCC_Layout_Validator::validate( array_values( array_unique( array_map( 'sanitize_key', $layout ) ) ) );

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

		return array(
			'ok'            => true,
			'post_id'       => (int) $post_id,
			'layout'        => $layout,
			'design_source' => 'design_composer',
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'view_url'      => get_permalink( $post_id ),
			'elementor_url' => admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' ),
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

		return array(
			'business' => is_array( $business ) ? $business : array(),
			'handoff'  => $handoff,
			'design'   => $design,
			'profile'  => $profile,
		);
	}
}
