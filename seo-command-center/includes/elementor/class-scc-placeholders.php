<?php
/**
 * Elementor placeholder system.
 *
 * Detects {{TOKEN}} placeholders inside an Elementor element tree and replaces
 * them with generated content, walking the decoded tree so the design
 * (sections, columns, widgets, styles, responsive settings) is fully preserved
 * — only text-bearing string settings are touched.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder engine.
 */
class SCC_Placeholders {

	/** Regex for a placeholder token, e.g. {{PRIMARY_KEYWORD}}. */
	const TOKEN_RE = '/\{\{\s*([A-Z0-9_]+)\s*\}\}/';

	/** Built-in placeholder names (custom ones are also allowed). */
	const BUILTIN = array(
		'TITLE', 'H1', 'INTRO', 'BODY', 'CONTENT', 'SERVICE', 'CITY',
		'PRIMARY_KEYWORD', 'CTA', 'FAQ', 'META_TITLE', 'META_DESCRIPTION',
	);

	/**
	 * Find all placeholder tokens present in an element tree.
	 *
	 * @param array $elements Decoded Elementor elements.
	 * @return string[] Unique token names (without braces).
	 */
	public static function detect( array $elements ) {
		$found = array();
		self::walk(
			$elements,
			function ( $value ) use ( &$found ) {
				if ( is_string( $value ) && preg_match_all( self::TOKEN_RE, $value, $m ) ) {
					foreach ( $m[1] as $token ) {
						$found[ $token ] = true;
					}
				}
				return $value;
			}
		);
		return array_keys( $found );
	}

	/**
	 * Replace tokens throughout an element tree.
	 *
	 * @param array $elements     Decoded Elementor elements.
	 * @param array $replacements TOKEN => replacement string (raw HTML allowed).
	 * @return array New element tree.
	 */
	public static function replace( array $elements, array $replacements ) {
		// Normalize keys to uppercase.
		$map = array();
		foreach ( $replacements as $k => $v ) {
			$map[ strtoupper( $k ) ] = (string) $v;
		}

		return self::walk(
			$elements,
			function ( $value ) use ( $map ) {
				if ( ! is_string( $value ) ) {
					return $value;
				}
				return preg_replace_callback(
					self::TOKEN_RE,
					function ( $matches ) use ( $map ) {
						$token = $matches[1];
						// Unknown tokens are removed (empty) rather than left visible.
						return array_key_exists( $token, $map ) ? $map[ $token ] : '';
					},
					$value
				);
			}
		);
	}

	/**
	 * Recursively walk an element tree, applying a callback to every string
	 * value inside 'settings'. Returns a new tree (does not mutate input).
	 *
	 * @param array    $elements Elements.
	 * @param callable $cb       Callback( string ) : string.
	 * @return array
	 */
	protected static function walk( array $elements, callable $cb ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				$out[] = $el;
				continue;
			}
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$el['settings'] = self::apply_to_settings( $el['settings'], $cb );
			}
			if ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = self::walk( $el['elements'], $cb );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * Apply the callback to string values in a settings array (recursively).
	 *
	 * @param array    $settings Settings.
	 * @param callable $cb       Callback.
	 * @return array
	 */
	protected static function apply_to_settings( array $settings, callable $cb ) {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $value ) ) {
				$settings[ $key ] = call_user_func( $cb, $value );
			} elseif ( is_array( $value ) ) {
				$settings[ $key ] = self::apply_to_settings( $value, $cb );
			}
		}
		return $settings;
	}
}
