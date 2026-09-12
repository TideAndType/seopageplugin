<?php
/**
 * Layout Engine — decides the ordered list of blocks for a page.
 *
 * Flow: optionally ask the AI provider (only when enabled AND available), else
 * use the deterministic rule provider; gate the result to blocks whose content
 * is actually available; then validate. The engine never fails: if AI is off or
 * errors, rules always produce a valid layout.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout decision engine.
 */
class SCC_Layout_Engine {

	/** @var SCC_AI_Manager|null */
	protected $ai;

	/**
	 * @param SCC_AI_Manager|null $ai AI manager (optional).
	 */
	public function __construct( $ai = null ) {
		$this->ai = $ai;
	}

	/**
	 * Decide the layout for an analyzed content structure.
	 *
	 * @param array $analysis Output of SCC_Layout_Analyzer::analyze().
	 * @param array $opts     { use_ai: bool }.
	 * @return array { layout: string[], source: 'ai'|'rules' }
	 */
	public function decide( array $analysis, array $opts = array() ) {
		$use_ai = ! empty( $opts['use_ai'] );
		$source = 'rules';
		$layout = null;

		if ( $use_ai ) {
			$provider = new SCC_Layout_AI_Provider( $this->ai );
			if ( $provider->is_available() ) {
				$ai_layout = $provider->decide( $analysis );
				if ( ! empty( $ai_layout ) ) {
					$layout = $ai_layout;
					$source = 'ai';
				}
			}
		}

		if ( null === $layout ) {
			$rules  = new SCC_Layout_Rule_Provider();
			$layout = $rules->decide( $analysis );
			$source = 'rules';
		}

		// Gate to blocks the content can actually fill, resolve duplication with
		// the full-body block, then validate.
		$layout = self::gate_by_availability( $layout, $analysis );
		$layout = self::resolve_conflicts( $layout );
		$layout = SCC_Layout_Validator::validate( $layout );

		return array( 'layout' => $layout, 'source' => $source );
	}

	/**
	 * Drop blocks whose content is not available, so we never render an empty
	 * section. Required blocks (hero/cta) are always kept. Pure given $analysis.
	 *
	 * @param string[] $layout   Ordered block ids.
	 * @param array    $analysis Analysis.
	 * @return string[]
	 */
	public static function gate_by_availability( array $layout, array $analysis ) {
		$out = array();
		foreach ( $layout as $id ) {
			$meta = SCC_Block_Registry::get( $id );
			if ( ! $meta ) {
				continue;
			}
			if ( ! empty( $meta['required'] ) || self::has_content( $id, $analysis ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * When the full-body "content" block is present, drop blocks that would
	 * duplicate prose already inside it (cards/benefits derived from the same
	 * body sections, and the intro that leads the body). Discrete blocks that
	 * are lifted out (stats, process-steps, faq, related, service-area, toc)
	 * are kept. Pure.
	 *
	 * @param string[] $layout Ordered block ids.
	 * @return string[]
	 */
	public static function resolve_conflicts( array $layout ) {
		if ( ! in_array( 'content', $layout, true ) ) {
			return $layout;
		}
		$dup = array( 'content-intro', 'service-grid', 'service-cards', 'feature-grid', 'benefits', 'split-content', 'image-content', 'comparison', 'highlight-box' );
		return array_values( array_filter( $layout, function ( $id ) use ( $dup ) {
			return ! in_array( $id, $dup, true );
		} ) );
	}

	/**
	 * Whether the analysis holds enough content to fill a given block.
	 *
	 * @param string $id       Block id.
	 * @param array  $analysis Analysis.
	 * @return bool
	 */
	public static function has_content( $id, array $analysis ) {
		$counts = (array) ( $analysis['counts'] ?? array() );
		$c = function ( $k ) use ( $counts ) {
			return (int) ( $counts[ $k ] ?? 0 );
		};

		switch ( $id ) {
			case 'hero':
			case 'cta':
				return true;
			case 'content-intro':
				return '' !== trim( (string) ( $analysis['intro'] ?? '' ) );
			case 'content':
				return '' !== trim( (string) ( $analysis['content_html'] ?? '' ) );
			case 'toc':
				return $c( 'sections' ) >= 3;
			case 'benefits':
				return $c( 'benefits' ) >= 3;
			case 'feature-grid':
			case 'service-grid':
			case 'service-cards':
				return $c( 'services' ) >= 2;
			case 'stats':
				return $c( 'stats' ) >= 2;
			case 'process-steps':
				return $c( 'process' ) >= 2;
			case 'faq':
				return $c( 'faqs' ) >= 1;
			case 'related-content':
			case 'blog-grid':
				return $c( 'related' ) >= 1;
			case 'service-area':
			case 'location-grid':
				return $c( 'related' ) >= 1 || ! empty( $analysis['areas'] ) || '' !== (string) ( $analysis['city'] ?? '' );
			case 'comparison':
				return false !== stripos( (string) ( $analysis['content_html'] ?? '' ), '<table' );
			case 'split-content':
			case 'image-content':
				return ! empty( $analysis['has_image'] );
			case 'highlight-box':
				return $c( 'benefits' ) >= 1 || '' !== trim( (string) ( $analysis['intro'] ?? '' ) );
			case 'testimonial':
				return false; // No structured testimonial content is generated today.
			default:
				return true;
		}
	}
}
