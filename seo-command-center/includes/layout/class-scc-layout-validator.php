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
			$seen = isset( $counts[ $id ] ) ? $counts[ $id ] : 0;
			// Non-repeatable → at most once. Repeatable → up to its max (0 = any).
			if ( ! $meta['repeatable'] && $seen >= 1 ) {
				continue;
			}
			if ( $meta['repeatable'] && $meta['max'] > 0 && $seen >= $meta['max'] ) {
				continue;
			}
			$clean[]        = $id;
			$counts[ $id ]  = $seen + 1;
			if ( count( $clean ) >= self::MAX_BLOCKS ) {
				break;
			}
		}

		// Guarantee required blocks exist.
		foreach ( SCC_Block_Registry::required_ids() as $req ) {
			if ( ! in_array( $req, $clean, true ) ) {
				$clean[] = $req;
			}
		}

		// Hero always leads; CTA always closes (when present).
		$clean = self::move_to_front( $clean, 'hero' );
		$clean = self::move_to_end( $clean, 'cta' );

		return array_values( $clean );
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
