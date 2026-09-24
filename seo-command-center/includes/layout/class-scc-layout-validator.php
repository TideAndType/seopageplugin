<?php
/**
 * Layout Validator — the last line of defence between a proposed layout (from AI
 * OR rules OR the user reordering blocks in the UI) and rendering. It guarantees
 * a well-formed, safe layout:
 *   - every id is a registered block (unknown ids dropped),
 *   - non-repeatable blocks appear at most once,
 *   - per-block max usage is respected,
 *   - required blocks (hero, cta) are always present,
 *   - hero leads and cta closes,
 *   - the total block count is capped.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layout validator.
 */
class SCC_Layout_Validator {

	/** Hard ceiling on blocks per page (defence against runaway AI output). */
	const MAX_BLOCKS = 16;

	/**
	 * Validate + normalize an ordered block list.
	 *
	 * @param array $layout Ordered block IDs (untrusted).
	 * @return string[] Clean, ordered block IDs.
	 */
	public static function validate( array $layout ) {
		$counts = array();
		$clean  = array();

		foreach ( $layout as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}
			$id   = sanitize_key( $id );
			$meta = SCC_Block_Registry::get( $id );
			if ( ! $meta ) {
				continue; // Unknown block — never render it.
			}
			$semantic_id = class_exists( 'SCC_Page_Architect' ) ? SCC_Page_Architect::base_block( $id ) : $id;
			$semantic_meta = SCC_Block_Registry::get( $semantic_id );
			if ( $semantic_meta ) { $meta = $semantic_meta; }
			$seen = isset( $counts[ $semantic_id ] ) ? $counts[ $semantic_id ] : 0;
			// Non-repeatable → at most once. Repeatable → up to its max (0 = any).
			if ( ! $meta['repeatable'] && $seen >= 1 ) {
				continue;
			}
			if ( $meta['repeatable'] && $meta['max'] > 0 && $seen >= $meta['max'] ) {
				continue;
			}
			$clean[] = $id;
			$counts[ $semantic_id ] = $seen + 1;
			if ( count( $clean ) >= self::MAX_BLOCKS ) {
				break;
			}
		}

		// Guarantee required blocks exist.
		foreach ( SCC_Block_Registry::required_ids() as $req ) {
			if ( ! self::contains_semantic( $clean, $req ) ) {
				$clean[] = $req;
			}
		}

		// Hero always leads; CTA always closes (including visual aliases).
		$clean = self::move_semantic_to_front( $clean, 'hero' );
		$clean = self::move_semantic_to_end( $clean, 'cta' );

		return array_values( $clean );
	}

	/**
	 * Whether a layout contains a semantic/base block or any of its aliases.
	 *
	 * @param string[] $list List.
	 * @param string   $base Base block id.
	 * @return bool
	 */
	protected static function contains_semantic( array $list, $base ) {
		foreach ( $list as $id ) {
			$semantic = class_exists( 'SCC_Page_Architect' ) ? SCC_Page_Architect::base_block( $id ) : $id;
			if ( $semantic === $base ) { return true; }
		}
		return false;
	}

	protected static function move_semantic_to_front( array $list, $base ) {
		foreach ( $list as $i => $id ) {
			$semantic = class_exists( 'SCC_Page_Architect' ) ? SCC_Page_Architect::base_block( $id ) : $id;
			if ( $semantic !== $base ) { continue; }
			unset( $list[ $i ] );
			array_unshift( $list, $id );
			break;
		}
		return array_values( $list );
	}

	protected static function move_semantic_to_end( array $list, $base ) {
		foreach ( $list as $i => $id ) {
			$semantic = class_exists( 'SCC_Page_Architect' ) ? SCC_Page_Architect::base_block( $id ) : $id;
			if ( $semantic !== $base ) { continue; }
			unset( $list[ $i ] );
			$list[] = $id;
			break;
		}
		return array_values( $list );
	}

	/**
	 * Move the first occurrence of $id to the front.
	 *
	 * @param string[] $list List.
	 * @param string   $id   Id.
	 * @return string[]
	 */
	protected static function move_to_front( array $list, $id ) {
		if ( ! in_array( $id, $list, true ) ) {
			return $list;
		}
		$list = array_values( array_diff( $list, array( $id ) ) );
		array_unshift( $list, $id );
		return $list;
	}

	/**
	 * Move the first occurrence of $id to the end.
	 *
	 * @param string[] $list List.
	 * @param string   $id   Id.
	 * @return string[]
	 */
	protected static function move_to_end( array $list, $id ) {
		if ( ! in_array( $id, $list, true ) ) {
			return $list;
		}
		$list   = array_values( array_diff( $list, array( $id ) ) );
		$list[] = $id;
		return $list;
	}
}
