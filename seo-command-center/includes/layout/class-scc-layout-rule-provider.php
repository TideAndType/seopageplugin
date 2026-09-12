<?php
/**
 * Deterministic layout provider — the always-available fallback that needs no
 * AI. Given the analyzed content structure it returns an ordered list of block
 * IDs based on the content type and search intent. The engine then filters this
 * to the blocks the content can actually fill and validates it.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-based layout provider.
 */
class SCC_Layout_Rule_Provider {

	/**
	 * Decide an ordered block list from the analysis using deterministic rules.
	 *
	 * @param array $analysis Output of SCC_Layout_Analyzer::analyze().
	 * @return string[] Ordered block IDs.
	 */
	public function decide( array $analysis ) {
		$type   = (string) ( $analysis['content_type'] ?? 'article' );
		$intent = (string) ( $analysis['search_intent'] ?? 'informational' );

		// Content-centric layouts: the generated article is rendered ONCE as the
		// "content" block, with discrete pieces (stats, process, FAQ, CTA, related
		// links) lifted into their own blocks. This keeps a single H1 (hero), the
		// full H2/H3 body crawlable, and never duplicates prose. The richer
		// card blocks (service-grid, benefits, …) remain in the registry and are
		// used when a future structured-generation mode provides discrete data or
		// when the AI provider selects them against discrete content.
		switch ( $type ) {
			case 'service':
			case 'landing':
				$layout = array( 'hero', 'content', 'stats', 'process-steps', 'faq', 'related-content', 'cta' );
				break;

			case 'local_service':
				$layout = array( 'hero', 'content', 'process-steps', 'service-area', 'faq', 'cta' );
				break;

			case 'location':
				$layout = array( 'hero', 'content', 'service-area', 'faq', 'cta' );
				break;

			case 'comparison':
				$layout = array( 'hero', 'content', 'faq', 'related-content', 'cta' );
				break;

			case 'blog_post':
			case 'informational':
			case 'article':
			default:
				$layout = array( 'hero', 'toc', 'content', 'faq', 'related-content', 'cta' );
				break;
		}

		return $layout;
	}

	/**
	 * Insert $insert immediately after $anchor (or append if anchor is absent).
	 *
	 * @param string[] $list   List.
	 * @param string   $anchor Anchor id.
	 * @param string   $insert Id to insert.
	 * @return string[]
	 */
	protected static function insert_after( array $list, $anchor, $insert ) {
		$out = array();
		$done = false;
		foreach ( $list as $id ) {
			$out[] = $id;
			if ( ! $done && $id === $anchor ) {
				$out[] = $insert;
				$done  = true;
			}
		}
		if ( ! $done ) {
			$out[] = $insert;
		}
		return $out;
	}
}
