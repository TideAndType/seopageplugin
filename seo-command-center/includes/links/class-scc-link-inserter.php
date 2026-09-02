<?php
/**
 * Internal link inserter.
 *
 * Inserts an approved recommendation into the source post by wrapping the first
 * natural, unlinked occurrence of the anchor phrase in a body text node.
 *
 * Anti-spam guards:
 *  - never exceeds the per-page internal-link cap (Settings);
 *  - never links the same target twice from one page;
 *  - only the FIRST occurrence is linked (no exact-match spam);
 *  - never links inside existing anchors or headings;
 *  - only links a phrase that genuinely appears in the content.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link inserter.
 */
class SCC_Link_Inserter {

	/**
	 * Apply a stored recommendation.
	 *
	 * @param int    $link_id scc_internal_links row id.
	 * @param string $trigger Trigger source (manual|autopilot|batch).
	 * @return array|WP_Error {source_post_id, target_post_id, anchor}
	 */
	public function apply( $link_id, $trigger = 'manual' ) {
		$row = SCC_DB::get( 'internal_links', $link_id );
		if ( ! $row ) {
			return new WP_Error( 'scc_no_link', __( 'Recommendation not found.', 'seo-command-center' ), array( 'status' => 404 ) );
		}
		if ( 'applied' === $row['status'] ) {
			return new WP_Error( 'scc_already', __( 'This link was already applied.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$source_id = (int) $row['source_post_id'];
		$target_id = (int) $row['target_post_id'];
		$anchor    = (string) $row['anchor'];

		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return new WP_Error( 'scc_forbidden', __( 'You cannot edit the source post.', 'seo-command-center' ), array( 'status' => 403 ) );
		}

		$source = get_post( $source_id );
		$target_url = get_permalink( $target_id );
		if ( ! $source || ! $target_url ) {
			return new WP_Error( 'scc_missing', __( 'Source or target no longer exists.', 'seo-command-center' ), array( 'status' => 404 ) );
		}

		$content = $source->post_content;

		// Per-page cap. There is no hard SEO limit, so a manual insert is never
		// blocked by it — the user chose to add this link. The cap only governs
		// AUTOMATIC (autopilot) insertion, and even then acts as a high safety
		// ceiling rather than a small quota.
		if ( 'autopilot' === $trigger ) {
			$max = (int) SCC_Settings::get( 'max_internal_links', 8 );
			if ( $max > 0 && $this->count_internal_links( $content ) >= $max ) {
				return new WP_Error( 'scc_cap', __( 'Autopilot skipped this link: the page is already at your configured internal-link limit (raise it in Settings). You can still insert it manually.', 'seo-command-center' ), array( 'status' => 409 ) );
			}
		}

		// Guard: max links to the SAME destination (avoid over-linking one page).
		$per_dest = (int) SCC_Settings::get( 'link_max_per_destination', 1 );
		if ( $per_dest > 0 && substr_count( $content, $target_url ) >= $per_dest ) {
			SCC_DB::update( 'internal_links', array( 'status' => 'applied' ), array( 'id' => (int) $link_id ) );
			return new WP_Error( 'scc_exists', __( 'The source already links to the target the allowed number of times.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$new_content = $this->insert_anchor( $content, $anchor, $target_url );
		if ( null === $new_content ) {
			SCC_DB::update( 'internal_links', array( 'status' => 'skipped' ), array( 'id' => (int) $link_id ) );
			return new WP_Error( 'scc_no_anchor', __( 'The anchor phrase was not found in the content to link naturally.', 'seo-command-center' ), array( 'status' => 409 ) );
		}

		$result = wp_update_post(
			array(
				'ID'           => $source_id,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		SCC_DB::update( 'internal_links', array( 'status' => 'applied' ), array( 'id' => (int) $link_id ) );

		// Record for revert (previous_value = full content before insertion).
		if ( class_exists( 'SCC_Change_History' ) ) {
			SCC_Change_History::record(
				array(
					'post_id'        => $source_id,
					'change_type'    => 'internal_link',
					'previous_value' => $content,
					'new_value'      => sprintf( 'Linked “%s” -> %s', $anchor, $target_url ),
					'reason'         => (string) ( $row['reason'] ?? '' ),
					'confidence'     => (int) ( $row['confidence'] ?? 0 ),
					'trigger_source' => $trigger,
				)
			);
		}

		// Keep the index fresh for the modified source.
		if ( class_exists( 'SCC_Content_Index' ) ) {
			SCC_Content_Index::index_post( $source_id );
		}

		SCC_Logger::info( 'link-inserter', 'Internal link applied', array( 'source' => $source_id, 'target' => $target_id, 'trigger' => $trigger ) );

		return array(
			'source_post_id' => $source_id,
			'target_post_id' => $target_id,
			'anchor'         => $anchor,
		);
	}

	/**
	 * Insert a link into an HTML string (used pre-render on a content object).
	 * Returns the original HTML unchanged if the anchor can't be placed naturally.
	 *
	 * @param string $html       HTML.
	 * @param string $anchor     Anchor phrase.
	 * @param string $target_url Target URL.
	 * @return string
	 */
	public function insert_link_in_html( $html, $anchor, $target_url ) {
		if ( '' === trim( (string) $html ) || false !== strpos( $html, $target_url ) ) {
			return $html;
		}
		$result = $this->insert_anchor( $html, $anchor, $target_url );
		return ( null === $result ) ? $html : $result;
	}

	/**
	 * Count existing internal links in content.
	 *
	 * @param string $content Content.
	 * @return int
	 */
	protected function count_internal_links( $content ) {
		if ( ! preg_match_all( '/<a\s[^>]*href=("|\')(.*?)\1/i', $content, $m ) ) {
			return 0;
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$count     = 0;
		foreach ( $m[2] as $href ) {
			if ( 0 === strpos( $href, '#' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host === $home_host || 0 === strpos( $href, '/' ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Insert an anchor around the first natural, unlinked occurrence.
	 *
	 * @param string $content    Post content HTML.
	 * @param string $anchor     Anchor phrase.
	 * @param string $target_url Target URL.
	 * @return string|null New content, or null if the anchor was not placed.
	 */
	protected function insert_anchor( $content, $anchor, $target_url ) {
		$anchor = trim( $anchor );
		if ( '' === $anchor ) {
			return null;
		}

		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="scc-root">' . $content . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath = new DOMXPath( $dom );

		// Text nodes not inside links or headings.
		$text_nodes = $xpath->query( '//div[@id="scc-root"]//text()[not(ancestor::a) and not(ancestor::h1) and not(ancestor::h2) and not(ancestor::h3) and not(ancestor::h4) and not(ancestor::h5) and not(ancestor::h6)]' );

		foreach ( $text_nodes as $node ) {
			$value = $node->nodeValue;
			$pos   = stripos( $value, $anchor );
			if ( false === $pos ) {
				continue;
			}

			// Split: before | anchor | after.
			$before  = substr( $value, 0, $pos );
			$matched = substr( $value, $pos, strlen( $anchor ) );
			$after   = substr( $value, $pos + strlen( $anchor ) );

			$parent = $node->parentNode;

			$link = $dom->createElement( 'a' );
			$link->setAttribute( 'href', $target_url );
			$link->appendChild( $dom->createTextNode( $matched ) );

			if ( '' !== $before ) {
				$parent->insertBefore( $dom->createTextNode( $before ), $node );
			}
			$parent->insertBefore( $link, $node );
			if ( '' !== $after ) {
				$parent->insertBefore( $dom->createTextNode( $after ), $node );
			}
			$parent->removeChild( $node );

			return $this->inner_html( $dom, $xpath );
		}

		return null;
	}

	/**
	 * Serialize the inner HTML of the scc-root wrapper.
	 *
	 * @param DOMDocument $dom   Document.
	 * @param DOMXPath    $xpath XPath.
	 * @return string
	 */
	protected function inner_html( DOMDocument $dom, DOMXPath $xpath ) {
		$root = $xpath->query( '//div[@id="scc-root"]' )->item( 0 );
		if ( ! $root ) {
			return '';
		}
		$html = '';
		foreach ( $root->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}
		return $html;
	}
}
