<?php
/**
 * Deterministic block variant selector.
 *
 * AI chooses semantic blocks; TideOrbit chooses a safe visual variant from real
 * content/site context. This keeps layout output predictable and testable while
 * allowing many more page compositions than one hard-coded design.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Block_Variant_Selector {

	public static function select( $block_id, array $analysis = array(), array $context = array() ) {
		$id     = sanitize_key( (string) $block_id );
		$type   = sanitize_key( (string) ( $analysis['content_type'] ?? 'article' ) );
		$intent = sanitize_key( (string) ( $analysis['search_intent'] ?? 'informational' ) );
		$image  = ! empty( $analysis['has_image'] );

		switch ( $id ) {
			case 'hero':
				if ( $image && in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ) {
					return 'split-image';
				}
				if ( in_array( $type, array( 'article', 'blog_post', 'informational' ), true ) ) {
					return 'editorial';
				}
				return 'centered';

			case 'service-grid':
			case 'service-cards':
			case 'feature-grid':
			case 'blog-grid':
			case 'location-grid':
				return 'cards';

			case 'stats':
				return 'band';

			case 'process-steps':
				return 'numbered-cards';

			case 'faq':
				return 'stacked';

			case 'cta':
				return in_array( $intent, array( 'commercial', 'transactional', 'local' ), true ) ? 'split' : 'centered';

			case 'content':
				return 'editorial-bands';

			default:
				return 'default';
		}
	}
}
