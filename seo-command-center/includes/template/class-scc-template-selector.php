<?php
/**
 * Deterministic template selector.
 *
 * Selection priority (first match wins) — the AI never decides:
 *   1. Manually selected template family.
 *   2. Exact rule (content-type entry in the template map with a family).
 *   3. Content-type mapping (an active template whose content_type matches).
 *   4. Default template family.
 *   5. Built-in WordPress fallback structure (generation never dies).
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template selector.
 */
class SCC_Template_Selector {

	/**
	 * Select a template for a content type.
	 *
	 * @param string $content_type  Content type.
	 * @param string $manual_family Explicitly chosen family (highest priority).
	 * @return array {template: SCC_Template, source: string}
	 */
	public static function select( $content_type, $manual_family = '' ) {
		$content_type = $content_type ? $content_type : 'article';

		// 1. Manual selection.
		if ( $manual_family ) {
			$tpl = SCC_Template_Store::active_for_family( $manual_family );
			if ( $tpl ) {
				return array( 'template' => $tpl, 'source' => 'manual' );
			}
		}

		// 2. Exact rule from the map.
		$mapped = SCC_Template_Map::for_content_type( $content_type );
		if ( ! empty( $mapped['family'] ) ) {
			$tpl = SCC_Template_Store::active_for_family( $mapped['family'] );
			if ( $tpl ) {
				return array( 'template' => $tpl, 'source' => 'rule' );
			}
		}

		// 3. Content-type mapping (a template flagged for this content type).
		$tpl = SCC_Template_Store::active_for_content_type( $content_type );
		if ( $tpl ) {
			return array( 'template' => $tpl, 'source' => 'content_type' );
		}

		// 4. Default family.
		$default = SCC_Template_Map::default_family();
		if ( $default ) {
			$tpl = SCC_Template_Store::active_for_family( $default );
			if ( $tpl ) {
				return array( 'template' => $tpl, 'source' => 'default' );
			}
		}

		// 5. Built-in fallback.
		return array( 'template' => SCC_Template::fallback( $content_type ), 'source' => 'fallback' );
	}

	/**
	 * The renderer id for a content type (mapping override or setting default).
	 *
	 * @param string        $content_type Content type.
	 * @param SCC_Template  $template     Selected template.
	 * @return string
	 */
	public static function renderer_for( $content_type, $template = null ) {
		// A template may pin a renderer.
		if ( $template && $template->renderer ) {
			return $template->renderer;
		}
		$mapped = SCC_Template_Map::for_content_type( $content_type );
		if ( ! empty( $mapped['renderer'] ) ) {
			return $mapped['renderer'];
		}
		return (string) SCC_Settings::get( 'default_renderer', 'gutenberg' );
	}
}
