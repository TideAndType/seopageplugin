<?php
/**
 * Content-type → template + renderer mapping (deterministic rules).
 *
 * Stored in the option scc_template_map. The AI never selects a template.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template map.
 */
class SCC_Template_Map {

	const OPTION = 'scc_template_map';

	/**
	 * Full map: content_type => {family, renderer}, plus 'default_family'.
	 *
	 * @return array
	 */
	public static function all() {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * The mapping for one content type.
	 *
	 * @param string $content_type Content type.
	 * @return array {family, renderer}
	 */
	public static function for_content_type( $content_type ) {
		$map = self::all();
		$entry = isset( $map[ $content_type ] ) && is_array( $map[ $content_type ] ) ? $map[ $content_type ] : array();
		return array(
			'family'   => (string) ( $entry['family'] ?? '' ),
			'renderer' => (string) ( $entry['renderer'] ?? '' ),
		);
	}

	/**
	 * Save a content-type mapping.
	 *
	 * @param string $content_type Content type.
	 * @param string $family       Template family.
	 * @param string $renderer     Renderer id ('' = default).
	 * @return void
	 */
	public static function set( $content_type, $family, $renderer = '' ) {
		$content_type = sanitize_key( $content_type );
		if ( ! in_array( $content_type, SCC_Template::TYPES, true ) ) {
			return;
		}
		$map = self::all();
		$map[ $content_type ] = array(
			'family'   => sanitize_text_field( $family ),
			'renderer' => sanitize_key( $renderer ),
		);
		update_option( self::OPTION, $map );
	}

	/**
	 * The default template family (used when no content-type mapping matches).
	 *
	 * @return string
	 */
	public static function default_family() {
		$map = self::all();
		return (string) ( $map['default_family'] ?? '' );
	}

	/**
	 * Set the default template family.
	 *
	 * @param string $family Family.
	 * @return void
	 */
	public static function set_default_family( $family ) {
		$map = self::all();
		$map['default_family'] = sanitize_text_field( $family );
		update_option( self::OPTION, $map );
	}
}
