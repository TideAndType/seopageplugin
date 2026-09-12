<?php
/**
 * Layout Service — the façade that ties the AI Elementor Layout Engine together:
 * analyze → decide (AI optional / rules always) → validate → map → (preview or
 * render into an editable Elementor page). This is the single entry point the
 * REST layer and admin UI use.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout engine service.
 */
class SCC_Layout_Service {

	/** @var SCC_AI_Manager|null */
	protected $ai;

	/**
	 * @param SCC_AI_Manager|null $ai AI manager (optional — rules work without it).
	 */
	public function __construct( $ai = null ) {
		$this->ai = $ai;
	}

	/**
	 * Propose a layout for an existing generated post.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $use_ai  Whether to try the AI provider.
	 * @return array|WP_Error
	 */
	public function propose_for_post( $post_id, $use_ai = false ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		$result             = $this->propose_for_object( $obj, $use_ai );
		$result['post_id']  = (int) $post_id;
		$result['title']    = (string) $obj->title;
		return $result;
	}

	/**
	 * Propose a layout for a content object.
	 *
	 * @param SCC_Content_Object $obj    Content object.
	 * @param bool               $use_ai Whether to try the AI provider.
	 * @return array
	 */
	public function propose_for_object( SCC_Content_Object $obj, $use_ai = false ) {
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$engine   = new SCC_Layout_Engine( $this->ai );
		$decision = $engine->decide( $analysis, array( 'use_ai' => (bool) $use_ai ) );

		$context = $this->context();
		$blocks  = SCC_Content_Mapper::map( $decision['layout'], $analysis, $context );

		// The preview only needs id/name/empty per block (no heavy content).
		$preview = array();
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['empty'] ) ) {
				continue;
			}
			$preview[] = array(
				'id'    => $b['id'],
				'name'  => $b['name'],
				'render'=> $b['render'],
			);
		}

		return array(
			'layout'            => wp_list_pluck( $preview, 'id' ),
			'blocks'            => $preview,
			'source'            => $decision['source'],
			'content_type'      => $analysis['content_type'],
			'search_intent'     => $analysis['search_intent'],
			'elementor_active'  => class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active(),
			'ai_available'      => ( $this->ai instanceof SCC_AI_Manager ),
		);
	}

	/**
	 * Decide a layout for a post and build the Elementor page in one step (no UI).
	 * Used for auto-build at generation time. Deterministic by default.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $use_ai  Whether to try the AI provider for ordering.
	 * @return array|WP_Error
	 */
	public function build( $post_id, $use_ai = false ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		$analysis = SCC_Layout_Analyzer::analyze( $obj );
		$engine   = new SCC_Layout_Engine( $this->ai );
		$decision = $engine->decide( $analysis, array( 'use_ai' => (bool) $use_ai ) );
		return $this->apply( $post_id, $decision['layout'] );
	}

	/**
	 * Apply a (user-confirmed or engine) layout to a post as an Elementor page.
	 *
	 * @param int   $post_id Post id.
	 * @param array $layout  Ordered block ids (validated here).
	 * @return array|WP_Error
	 */
	public function apply( $post_id, array $layout ) {
		$obj = SCC_Layout_Analyzer::content_object_from_post( $post_id );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		$analysis = SCC_Layout_Analyzer::analyze( $obj );

		// Validate the incoming (possibly user-reordered) layout, then re-apply the
		// availability + conflict rules so a hand-edited order can't create empties
		// or duplication.
		$layout = SCC_Layout_Engine::gate_by_availability( $layout, $analysis );
		$layout = SCC_Layout_Engine::resolve_conflicts( $layout );
		$layout = SCC_Layout_Validator::validate( $layout );

		$blocks = SCC_Content_Mapper::map( $layout, $analysis, $this->context() );
		$applied = SCC_Block_Elementor_Renderer::apply_to_post( $post_id, $blocks, array( 'palette_css' => SCC_Design_Intel::css_vars() ) );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return array(
			'ok'        => true,
			'post_id'   => (int) $post_id,
			'layout'    => $layout,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'view_url'  => get_permalink( $post_id ),
			'elementor_url' => admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' ),
		);
	}

	/**
	 * Shared render context (business info for CTA fallbacks).
	 *
	 * @return array
	 */
	protected function context() {
		$business = class_exists( 'SCC_Schema_Engine' ) ? SCC_Schema_Engine::business() : array();
		return array( 'business' => is_array( $business ) ? $business : array() );
	}
}
