<?php
/**
 * Renderer registry + selection with graceful fallback.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer manager.
 */
class SCC_Renderer_Manager {

	/** @var array<string,SCC_Renderer_Interface> */
	protected $renderers = array();

	/** Fallback order when a preferred renderer is unavailable. */
	const FALLBACK = array( 'elementor', 'gutenberg', 'wordpress' );

	/**
	 * Constructor: register built-in renderers.
	 */
	public function __construct() {
		$this->register( new SCC_WordPress_Renderer() );
		$this->register( new SCC_Gutenberg_Renderer() );
		$this->register( new SCC_Elementor_Renderer() );

		/**
		 * Allow add-ons to register renderers (Bricks, Divi, …).
		 *
		 * @param SCC_Renderer_Manager $manager Manager.
		 */
		do_action( 'scc_register_renderers', $this );
	}

	/**
	 * Register a renderer.
	 *
	 * @param SCC_Renderer_Interface $renderer Renderer.
	 */
	public function register( SCC_Renderer_Interface $renderer ) {
		$this->renderers[ $renderer->get_id() ] = $renderer;
	}

	/**
	 * All registered renderers.
	 *
	 * @return array<string,SCC_Renderer_Interface>
	 */
	public function all() {
		return $this->renderers;
	}

	/**
	 * Get a renderer by id.
	 *
	 * @param string $id Id.
	 * @return SCC_Renderer_Interface|null
	 */
	public function get( $id ) {
		return isset( $this->renderers[ $id ] ) ? $this->renderers[ $id ] : null;
	}

	/**
	 * Pick a renderer, honoring the preference then falling back to an available
	 * one. Never returns null (wordpress is always available).
	 *
	 * @param string $preferred    Preferred renderer id.
	 * @param string $content_type Content type (for availability checks).
	 * @return SCC_Renderer_Interface
	 */
	public function pick( $preferred, $content_type = '' ) {
		$candidate = $this->get( $preferred );
		if ( $candidate && $candidate->is_available( $content_type ) ) {
			return $candidate;
		}

		foreach ( self::FALLBACK as $id ) {
			$r = $this->get( $id );
			if ( $r && $r->is_available( $content_type ) ) {
				if ( $id !== $preferred ) {
					SCC_Logger::info( 'renderer', 'Falling back', array( 'from' => $preferred, 'to' => $id ) );
				}
				return $r;
			}
		}

		// Guaranteed available.
		return $this->get( 'wordpress' );
	}
}
